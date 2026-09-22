<?php

namespace App\Services;

use App\Exceptions\BusinessRuleConflictException;
use Illuminate\Auth\Access\AuthorizationException;
use App\Models\CompetencyReference;
use App\Models\EnrollmentImportRow;
use App\Models\ImportJob;
use App\Models\Subject;
use App\Models\User;
use App\Support\BoundedZip;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\OLERead;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Parses .xlsx files for batch competency-tag import (ARCH-001 §5.1).
 * Performs row-level validation, imports valid rows
 * while skipping invalid rows (partial success, ARCH-002 FR-031), and generates
 * one-time downloadable error reports (ARCH-004 §8.1).
 *
 * Invariants (ARCH-001 §5.1 / ARCH-002 FR-031 / row-count ceilings per ARCH-003 ADR-005 / ARCH-004 §8.1 / ARCH-003 ADR-008):
 *  - Failed rows MUST NOT block valid row import (ARCH-002 FR-031).
 *  - Error reports MUST be one-time downloadable responses (ARCH-004 §8.1).
 *  - Processing MUST be synchronous; no queuing (ARCH-003 ADR-008).
 *  - Data rows are capped at MAX_DATA_ROWS (5000) non-blank rows per file
 *    (F-05): a streaming pre-count rejects exceeding files before
 *    PhpSpreadsheet materializes the workbook, and the loaded rows are
 *    recounted as a safety net before any write. Decompression bombs are
 *    rejected up front with 413 FILE_EXPANDED_TOO_LARGE: OOXML entries are
 *    streamed through a byte-capped temp file (never held as strings) and
 *    legacy .xls uploads get a Workbook-stream size pre-check.
 *  - Files over 15 MB MUST be rejected up front (ARCH-002 QA-009); the
 *    HTTP gate (FormRequest `max:15360`) yields 422 VALIDATION_ERROR and the
 *    service guard below is defense-in-depth for direct calls (FILE_TOO_LARGE).
 *  - Blank-row semantics are unified across .xlsx and CSV (ARCH-002 QA-009): a row
 *    whose every cell is empty is skipped entirely — never counted in
 *    total_rows, never reported as imported or failed.
 *
 * @Traced-To ARCH-002 FR-033, ARCH-002 FR-031, ARCH-003 ADR-005, ARCH-004 §8.1, ARCH-003 ADR-008,
 *            ARCH-002 QA-009
 */
class BatchImportService
{
    /** @var int Max file size in bytes — 15 MB per ARCH-002 QA-009 (row-count ceilings per ARCH-003 ADR-005). */
    public const MAX_FILE_SIZE = 15 * 1024 * 1024;

    /**
     * F-05: ceiling on data rows per import file — 5000 non-blank rows,
     * excluding the header row and fully-blank skipped rows. Exceeding files
     * are rejected with 422 TOO_MANY_ROWS before any ImportJob row or
     * competency row is written.
     */
    public const MAX_DATA_ROWS = 5000;

    /**
     * F-05 hardening: cap on the decompressed Workbook stream of legacy
     * .xls uploads, checked before PhpSpreadsheet builds any cell objects
     * (413 FILE_EXPANDED_TOO_LARGE). BIFF cells serialize to roughly
     * 10–20 bytes each, so a 5000-row import stays far under 1 MB; 4 MB
     * only trips on files whose content vastly exceeds the row ceiling.
     */
    public const MAX_OLE_WORKBOOK_BYTES = 4 * 1024 * 1024;

    public const TYPE_COMPETENCY_TAGS = 'competency_tags';

    public const TYPE_STUDENT_ENROLLMENT = 'student_enrollment';

    public const TYPE_TEACHER_APPLICATION = 'teacher_application';

    /** Directory under storage/app for one-time error reports (ARCH-004 §8.1). */
    public const ERROR_REPORT_DIR = 'import_errors';

    public const PREVIEW_TTL_MINUTES = 60;

    private const PREVIEW_CACHE_PREFIX = 'enrollment_preview:';

    /** Required enrollment sheet header (single column, extras ignored). */
    private const ENROLLMENT_HEADERS = ['full_name'];

    /**
     * Error-report validity window in days (ARCH-002 FR-031). Single-use
     * and time-limited: the token expires after this TTL even if never opened.
     */
    public const ERROR_REPORT_TTL_DAYS = 7;

    /**
     * Return a blank .xlsx template with the correct column headers (ARCH-002 FR-031).
     *
     * @param  string  $type  'competency_tags', a student-enrollment slug
     *   ('student_enrollment', 'student_enrollments', 'student-enrollments'),
     *   or a teacher-application slug ('teacher_application',
     *   'teacher_applications', 'teacher-applications').
     *   Enrollment templates are full_name-only (single column); the server
     *   auto-generates CompAss IDs (STU- for students, TEA- for teachers).
     * @return string Absolute path to a temporary .xlsx file.
     *
     * @Traced-To ARCH-002 FR-031 (ARCH-001 §5.1)
     */
    public function downloadTemplate(string $type): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        if ($type === self::TYPE_COMPETENCY_TAGS) {
            $sheet->setCellValue('A1', 'code');
            $sheet->setCellValue('B1', 'descriptor');
            $sheet->setCellValue('C1', 'subject_id');
            $sheet->setCellValue('D1', 'grade_level');
            $sheet->setCellValue('E1', 'semester');
        } elseif (in_array($type, [self::TYPE_STUDENT_ENROLLMENT, 'student_enrollments', 'student-enrollments'], true)) {
            // Student enrollment is full_name-only; IDs are server-generated.
            $sheet->setCellValue('A1', 'full_name');
        } elseif (in_array($type, [self::TYPE_TEACHER_APPLICATION, 'teacher_applications', 'teacher-applications'], true)) {
            // Teacher bulk is full_name-only; IDs are server-generated (TEA-).
            $sheet->setCellValue('A1', 'full_name');
        } else {
            throw new BusinessRuleConflictException(
                'Invalid template type. Use "competency_tags", "student-enrollments", or "teacher-applications".',
                'INVALID_TEMPLATE_TYPE'
            );
        }

        $path = tempnam(sys_get_temp_dir(), 'template_');
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /**
     * Parse .xlsx Competency file, validate rows, bulk-import valid rows
     * into competency_reference (ARCH-002 FR-031, ARCH-004 §8.1, ARCH-004 §10).
     *
     * Template headers: code, descriptor, subject_id, grade_level, semester.
     *
     * Row validation: code non-empty and unique; subject_id exists and, when
     * the Subject is scoped to a Grade Level, its Grade Level and Semester
     * match the row; grade_level is a whole number within the grades 7–12
     * range (ARCH-004 §10; Requirements Revision 2); semester is required
     * and must be 1, 2, or 3.
     *
     * @param  UploadedFile  $file
     * @return array{imported_rows: int, failed_rows: int, has_error_report: bool, error_report_url: string|null}
     *
     * @Traced-To ARCH-002 FR-031, ARCH-004 §8.1, ARCH-003 ADR-008, ARCH-004 §10, ARCH-002 QA-009
     */
    public function importCompetencyTags($file, int $adminId): array
    {
        $this->assertWithinSizeLimit($file);

        $rows = $this->readSpreadsheetRows($file);

        if (empty($rows)) {
            ImportJob::create([
                'import_type' => self::TYPE_COMPETENCY_TAGS,
                'admin_id' => $adminId,
                'total_rows' => 0, 'imported_rows' => 0, 'failed_rows' => 0,
            ]);

            return ['imported_rows' => 0, 'failed_rows' => 0, 'has_error_report' => false, 'error_report_url' => null];
        }

        $failedRows = [];
        $validRows = [];
        $seenCodes = [];
        $blankRows = 0;

        foreach ($rows as $rowIndex => $row) {
            $rowNumber = $rowIndex + 1;
            $code = trim((string) $row[0]);
            $descriptor = trim((string) ($row[1] ?? ''));
            $subjectId = trim((string) ($row[2] ?? ''));
            $gradeLevel = trim((string) ($row[3] ?? ''));
            $semester = trim((string) ($row[4] ?? ''));

            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) {
                $blankRows++;

                continue;
            }

            if ($code === '') {
                $failedRows[] = ['row' => $rowNumber, 'reason' => 'Missing code.'];

                continue;
            }

            if (isset($seenCodes[$code])) {
                $failedRows[] = ['row' => $rowNumber, 'reason' => sprintf('Duplicate competency code in batch: %s.', $code)];

                continue;
            }

            $exists = CompetencyReference::where('code', $code)->exists();
            if ($exists) {
                $failedRows[] = ['row' => $rowNumber, 'reason' => sprintf('Competency code already exists: %s.', $code)];

                continue;
            }

            if ($subjectId === '') {
                $failedRows[] = ['row' => $rowNumber, 'reason' => 'Missing subject_id.'];

                continue;
            }

            if (! is_numeric($subjectId)) {
                $failedRows[] = ['row' => $rowNumber, 'reason' => sprintf('Invalid subject_id: %s.', $subjectId)];

                continue;
            }

            $subject = Subject::with('gradeLevel.semester')->find((int) $subjectId);
            if (! $subject) {
                $failedRows[] = ['row' => $rowNumber, 'reason' => sprintf('Subject not found: %s.', $subjectId)];

                continue;
            }

            if ($gradeLevel === '') {
                $failedRows[] = ['row' => $rowNumber, 'reason' => 'Missing grade_level.'];

                continue;
            }

            if (! ctype_digit($gradeLevel)) {
                $failedRows[] = ['row' => $rowNumber, 'reason' => sprintf(
                    'Invalid grade_level: %s. Must be a whole number between 7 and 12.',
                    $gradeLevel
                )];

                continue;
            }

            $gradeLevelInt = (int) $gradeLevel;
            if (! in_array($gradeLevelInt, OrgStructureService::ALLOWED_GRADE_LEVELS, true)) {
                $failedRows[] = ['row' => $rowNumber, 'reason' => sprintf('Invalid grade_level: %s. Must be 7 through 12.', $gradeLevel)];

                continue;
            }

            if ($semester === '') {
                $failedRows[] = ['row' => $rowNumber, 'reason' => 'Missing semester.'];

                continue;
            }

            if (! ctype_digit($semester)) {
                $failedRows[] = ['row' => $rowNumber, 'reason' => sprintf(
                    'Invalid semester: %s. Must be 1, 2, or 3.',
                    $semester
                )];

                continue;
            }

            $semesterInt = (int) $semester;
            if (! in_array((string) $semesterInt, OrgStructureService::ALLOWED_SEMESTERS, true)) {
                $failedRows[] = ['row' => $rowNumber, 'reason' => sprintf('Invalid semester: %s. Must be 1, 2, or 3.', $semester)];

                continue;
            }

            // Consistency: when the Subject is scoped to a Grade Level, the
            // row's grade level and semester must match the Subject's scope
            // (School Year > Semester > Grade Level > Subject > Competencies).
            if ($subject->grade_level_id !== null && $subject->gradeLevel !== null) {
                $subjectGrade = (string) $subject->gradeLevel->grade_level;
                if ($subjectGrade !== (string) $gradeLevelInt) {
                    $failedRows[] = ['row' => $rowNumber, 'reason' => sprintf(
                        'Subject belongs to grade level %s; row grade_level is %s.',
                        $subjectGrade,
                        (string) $gradeLevelInt
                    )];

                    continue;
                }

                $subjectSemester = $subject->gradeLevel->semester !== null
                    ? (string) $subject->gradeLevel->semester->semester
                    : null;
                if ($subjectSemester !== null && $subjectSemester !== (string) $semesterInt) {
                    $failedRows[] = ['row' => $rowNumber, 'reason' => sprintf(
                        'Subject belongs to semester %s; row semester is %s.',
                        $subjectSemester,
                        (string) $semesterInt
                    )];

                    continue;
                }
            }

            $seenCodes[$code] = true;
            $validRows[] = [
                'code' => $code,
                'descriptor' => $descriptor,
                'subject_id' => (int) $subjectId,
                'grade_level' => (string) $gradeLevelInt,
                'semester' => (string) $semesterInt,
            ];
        }

        $importJob = ImportJob::create([
            'import_type' => self::TYPE_COMPETENCY_TAGS,
            'admin_id' => $adminId,
            'total_rows' => count($rows) - $blankRows,
            'imported_rows' => count($validRows),
            'failed_rows' => count($failedRows),
        ]);

        if (! empty($validRows)) {
            $now = now();
            $insertRows = array_map(function ($row) use ($now) {
                $row['created_at'] = $now;
                $row['updated_at'] = $now;

                return $row;
            }, $validRows);
            CompetencyReference::insert($insertRows);
        }

        $errorReportUrl = null;
        if (! empty($failedRows)) {
            $token = $this->generateErrorReport($failedRows, $rows);
            $importJob->update([
                'error_report_path' => self::ERROR_REPORT_DIR . '/' . $token . '.xlsx',
                'error_report_token' => hash('sha256', $token),
                'error_report_expires_at' => now()->addDays(self::ERROR_REPORT_TTL_DAYS),
            ]);
            $errorReportUrl = '/api/admin/import/error-reports/' . $token;
        }

        return [
            'imported_rows' => count($validRows),
            'failed_rows' => count($failedRows),
            'has_error_report' => $errorReportUrl !== null,
            'error_report_url' => $errorReportUrl,
        ];
    }

    /**
     * Preview a bulk learner-enrollment sheet without writing any rows.
     *
     * Full_name-only contract (Phase B): the sheet needs a single `full_name`
     * column (case-insensitive, order-insignificant, extras ignored so legacy
     * multi-column sheets still parse — extra columns are ignored and IDs are
     * never read from the file). Every non-blank name is valid; blank names
     * and names over 255 chars are invalid. The server auto-generates a fresh
     * STU- CompAss ID per row at confirm time — always creates new accounts,
     * never matches existing users by name (names are not unique). In-file
     * exact full_name repeats are ALLOWED as distinct accounts; valid rows
     * repeating a name already seen in the sheet are counted as
     * duplicate_matches (in-file repeats, informational only). Zero DB
     * writes; the parsed payload is held in cache under a single-use
     * 60-minute token.
     *
     * @return array{preview_token: string, total_rows: int, valid_rows: int, invalid_rows: int, duplicate_matches: int, expires_at: string}
     *
     * duplicate_matches counts in-file exact full_name repeats only
     * (informational — never a database match; each repeat still creates its
     * own account).
     */
    public function previewStudentEnrollments($file, int $adminId): array
    {
        return $this->previewFullNameBulk($file, $adminId, self::TYPE_STUDENT_ENROLLMENT);
    }

    /**
     * Preview a bulk teacher-application sheet (parallel to student bulk).
     *
     * Same full_name-only contract; confirm creates Teacher accounts with
     * fresh TEA- CompAss IDs. Always creates new accounts; in-file exact
     * name repeats are allowed and valid repeats are counted as
     * duplicate_matches (in-file repeats, informational only).
     *
     * @return array{preview_token: string, total_rows: int, valid_rows: int, invalid_rows: int, duplicate_matches: int, expires_at: string}
     */
    public function previewTeacherApplications($file, int $adminId): array
    {
        return $this->previewFullNameBulk($file, $adminId, self::TYPE_TEACHER_APPLICATION);
    }

    /**
     * Shared full_name-only preview (student + teacher bulk).
     *
     * @return array{preview_token: string, total_rows: int, valid_rows: int, invalid_rows: int, duplicate_matches: int, expires_at: string}
     */
    private function previewFullNameBulk($file, int $adminId, string $importType): array
    {
        $this->assertWithinSizeLimit($file);

        [$header, $rows] = $this->readEnrollmentSpreadsheet($file);
        $indexes = $this->mapEnrollmentHeader($header);

        $payloadRows = [];
        $seenNames = [];
        $validCount = 0;
        $invalidCount = 0;
        $duplicateMatches = 0;
        $totalRows = 0;

        foreach ($rows as $rowIndex => $row) {
            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $totalRows++;
            $rowNumber = $rowIndex + 1;
            $fields = $this->extractEnrollmentFields($row, $indexes);

            $reason = $this->validateFullNameField($fields['full_name']);
            if ($reason === null) {
                $validCount++;
                if (isset($seenNames[$fields['full_name']])) {
                    $duplicateMatches++;
                } else {
                    $seenNames[$fields['full_name']] = true;
                }
                $payloadRows[] = $fields + ['row_number' => $rowNumber, 'valid' => true, 'reason' => null];
            } else {
                $invalidCount++;
                // Track the name so a later identical valid row still counts
                // as an informational in-file repeat. Invalid rows never
                // increment the counter: duplicate_matches counts valid
                // repeats only.
                if ($fields['full_name'] !== '' && ! isset($seenNames[$fields['full_name']])) {
                    $seenNames[$fields['full_name']] = true;
                }
                $payloadRows[] = $fields + ['row_number' => $rowNumber, 'valid' => false, 'reason' => $reason];
            }
        }

        $token = Str::random(32);
        $expiresAt = now()->addMinutes(self::PREVIEW_TTL_MINUTES);
        Cache::put($this->previewCacheKey($token), [
            'admin_id' => $adminId,
            'import_type' => $importType,
            'rows' => $payloadRows,
        ], $expiresAt);

        return [
            'preview_token' => $token,
            'total_rows' => $totalRows,
            'valid_rows' => $validCount,
            'invalid_rows' => $invalidCount,
            'duplicate_matches' => $duplicateMatches,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * Confirm a previewed student enrollment: accounts only, no placements.
     *
     * Per-row atomic writes; every valid row creates a new Student account
     * with a fresh STU- CompAss ID. imported counts new accounts, updated is
     * always 0 (no matching in full_name-only mode; kept for contract
     * stability), failed counts rejected + save-failure rows. Valid rows
     * land, failed rows go to a single-use 7-day error sheet. Token single-use.
     *
     * @return array{total_rows: int, imported_rows: int, updated_rows: int, failed_rows: int, has_error_report: bool, error_report_url: string|null, message: string}
     */
    public function confirmStudentEnrollments(string $token, int $adminId): array
    {
        return $this->confirmFullNameBulk($token, $adminId, self::TYPE_STUDENT_ENROLLMENT, 'Student');
    }

    /**
     * Confirm a previewed teacher bulk: accounts only.
     *
     * Parallel to student confirm; creates Teacher accounts with fresh TEA-
     * CompAss IDs. Same counts contract (updated always 0).
     *
     * @return array{total_rows: int, imported_rows: int, updated_rows: int, failed_rows: int, has_error_report: bool, error_report_url: string|null, message: string}
     */
    public function confirmTeacherApplications(string $token, int $adminId): array
    {
        return $this->confirmFullNameBulk($token, $adminId, self::TYPE_TEACHER_APPLICATION, 'Teacher');
    }

    /**
     * Shared full_name-only confirm (student + teacher bulk).
     *
     * @return array{total_rows: int, imported_rows: int, updated_rows: int, failed_rows: int, has_error_report: bool, error_report_url: string|null, message: string}
     */
    private function confirmFullNameBulk(string $token, int $adminId, string $expectedType, string $role): array
    {
        $key = $this->previewCacheKey($token);

        // Atomic single-use claim: serialize parallel confirms on a short
        // per-token lock so exactly one wins the Cache::pull below (pull is
        // get+delete, not atomic on most drivers). A loser — lock busy or
        // payload already gone — sees the same expired/used GONE.
        $lock = Cache::lock($key . ':claim', 10);
        if (! $lock->acquire()) {
            throw new BusinessRuleConflictException(
                'The preview has expired or was already used.',
                'GONE',
                410
            );
        }

        try {
            $payload = Cache::get($key);

            if (! is_array($payload) || ! isset($payload['rows']) || ! is_array($payload['rows'])) {
                throw new BusinessRuleConflictException(
                    'The preview has expired or was already used.',
                    'GONE',
                    410
                );
            }

            // Type pin: a student token cannot confirm as teacher and vice
            // versa — treated as GONE so cross-type replays reveal nothing.
            if (($payload['import_type'] ?? $expectedType) !== $expectedType) {
                throw new BusinessRuleConflictException(
                    'The preview has expired or was already used.',
                    'GONE',
                    410
                );
            }

            // Ownership check BEFORE the destructive pull: a different
            // admin confirming is a 403 FORBIDDEN (AuthorizationException,
            // same convention as the ownership-scoped services) and the
            // token must survive so the rightful owner can still confirm.
            if (($payload['admin_id'] ?? null) !== $adminId) {
                throw new AuthorizationException();
            }

            $payload = Cache::pull($key);
        } finally {
            $lock->release();
        }

        if (! is_array($payload) || ! isset($payload['rows']) || ! is_array($payload['rows'])) {
            throw new BusinessRuleConflictException(
                'The preview has expired or was already used.',
                'GONE',
                410
            );
        }

        $storedRows = $payload['rows'];
        $totalRows = count($storedRows);
        $imported = 0;
        $updated = 0;
        $failedRows = [];
        $stagedRows = [];

        foreach ($storedRows as $stored) {
            $rowNumber = (int) ($stored['row_number'] ?? 0);
            $fields = [
                'full_name' => (string) ($stored['full_name'] ?? ''),
            ];

            $reason = $this->validateFullNameField($fields['full_name']);
            if ($reason !== null) {
                $failedRows[] = ['row' => $rowNumber, 'reason' => $reason, 'fields' => $fields];
                $stagedRows[] = $this->stagedRow($rowNumber, '', $fields['full_name'], $reason);

                continue;
            }

            try {
                $schoolId = null;
                $attempts = 0;
                $lastConflict = null;
                // Per-row 3x retry on generated-ID collision: the
                // generate-then-insert check races under concurrency, so a
                // users.school_id unique hit is retried with a fresh ID
                // before the row is marked failed.
                while ($schoolId === null && $attempts < 3) {
                    $attempts++;
                    try {
                        $schoolId = DB::transaction(function () use ($fields, $role) {
                            $schoolId = User::generateUniqueCompassId($role);

                            $user = new User();
                            $user->name = $fields['full_name'];
                            $user->school_id = $schoolId;
                            $user->password_hash = Hash::make(Str::password(16));
                            $user->role = $role;
                            $user->must_change_password = true;
                            $user->is_active = true;
                            $user->save();

                            return $schoolId;
                        });
                    } catch (QueryException $e) {
                        if ($this->isSchoolIdConflict($e) && $attempts < 3) {
                            $lastConflict = $e;
                            continue;
                        }
                        throw $e;
                    }
                }

                if ($schoolId === null) {
                    throw $lastConflict ?? new \RuntimeException('Could not save this row after retries.');
                }

                $imported++;
                $stagedRows[] = $this->stagedRow($rowNumber, $schoolId, $fields['full_name'], null);
            } catch (\Throwable $e) {
                Log::warning('Full-name bulk confirm row failed; no partial write', [
                    'row' => $rowNumber,
                    'import_type' => $expectedType,
                ]);
                $saveReason = $expectedType === self::TYPE_TEACHER_APPLICATION
                    ? 'Could not save this teacher. Please try again or contact your administrator.'
                    : 'Could not save this learner. Please try again or contact your administrator.';
                $failedRows[] = [
                    'row' => $rowNumber,
                    'reason' => $saveReason,
                    'fields' => $fields,
                ];
                $stagedRows[] = $this->stagedRow($rowNumber, '', $fields['full_name'], $saveReason);
            }
        }

        $failedCount = count($failedRows);

        $importJob = ImportJob::create([
            'import_type' => $expectedType,
            'admin_id' => $adminId,
            'total_rows' => $totalRows,
            'imported_rows' => $imported + $updated,
            'failed_rows' => $failedCount,
        ]);

        if (! empty($stagedRows)) {
            $now = now();
            foreach ($stagedRows as &$stagedRow) {
                $stagedRow['import_job_id'] = $importJob->id;
                $stagedRow['created_at'] = $now;
                $stagedRow['updated_at'] = $now;
            }
            unset($stagedRow);
            EnrollmentImportRow::insert($stagedRows);
        }

        $errorReportUrl = null;
        if ($failedCount > 0) {
            $errorToken = $this->generateEnrollmentErrorReport($failedRows, $expectedType);
            $importJob->update([
                'error_report_path' => self::ERROR_REPORT_DIR . '/' . $errorToken . '.xlsx',
                'error_report_token' => hash('sha256', $errorToken),
                'error_report_expires_at' => now()->addDays(self::ERROR_REPORT_TTL_DAYS),
            ]);
            $errorReportUrl = '/api/admin/import/error-reports/' . $errorToken;
        }

        $message = $expectedType === self::TYPE_TEACHER_APPLICATION
            ? 'Teacher accounts created. If you need help, contact your school administrator or support team.'
            : 'Learner accounts created. No classroom placements were made. If you need help, contact your school administrator or support team.';

        return [
            'total_rows' => $totalRows,
            'imported_rows' => $imported,
            'updated_rows' => $updated,
            'failed_rows' => $failedCount,
            'has_error_report' => $errorReportUrl !== null,
            'error_report_url' => $errorReportUrl,
            'message' => $message,
        ];
    }

    /**
     * Build one staged-retention row: generated CompAss ID ('' when the row
     * failed before generation) plus the raw full_name and row error.
     * The `learner_code` column stores the generated school_id for audit
     * continuity (STU-/TEA-); import_type on the parent job distinguishes
     * student vs teacher bulk.
     *
     * Truncation fidelity: overlong names never reach the database —
     * validation rejects full_name over 255 chars in both preview and
     * confirm, and the staged display value below is additionally truncated
     * to 255 chars so a failed row can never overflow the
     * enrollment_import_rows.full_name varchar (no 500). The staged value is
     * truncated display only; the error sheet carries the full submitted
     * value. The full reason is always stored unstripped (the `error` column
     * is text) so staged and sheet reasons stay consistent.
     *
     * @return array{row_number: int, learner_code: string, full_name: string, error: string|null}
     */
    private function stagedRow(int $rowNumber, string $schoolId, string $fullName, ?string $error): array
    {
        return [
            'row_number' => $rowNumber,
            'learner_code' => $schoolId,
            'full_name' => mb_substr($fullName, 0, 255),
            'error' => $error,
        ];
    }

    /**
     * Whether a query failure is a generated-ID race (concurrent CompAss ID
     * generation colliding on users.school_id). Retried per-row up to 3x
     * before the row is marked failed.
     */
    private function isSchoolIdConflict(QueryException $e): bool
    {
        $message = $e->getMessage();
        $sqlState = $e->errorInfo[0] ?? null;

        return str_contains($message, 'users_school_id_unique')
            || (($sqlState === '23505' || str_contains($message, '23505')) && str_contains($message, 'school_id'))
            || (str_contains($message, 'UNIQUE constraint failed') && str_contains($message, 'users.school_id'));
    }

    /**
     * Reject files over the 15 MB per-file cap (row-count ceilings per ARCH-003 ADR-005, ARCH-002 QA-009).
     *
     * The HTTP gate (FormRequest `max:15360`) rejects oversized uploads with
     * 422 VALIDATION_ERROR before this service runs; this guard covers direct
     * service calls and mirrors the sibling services (FILE_TOO_LARGE).
     *
     * @Traced-To ARCH-003 ADR-005, ARCH-002 QA-009
     */
    private function assertWithinSizeLimit($file): void
    {
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new BusinessRuleConflictException(
                'Each file must be at most 15 MB.',
                'FILE_TOO_LARGE'
            );
        }
    }

    /**
     * Read an .xlsx file and return data rows (excluding header row 0).
     *
     * F-05 bounded read: a streaming pre-count of the sheet XML rejects
     * files with more than MAX_DATA_ROWS non-blank data rows before
     * PhpSpreadsheet builds any cell objects, and aborts its scan as soon
     * as the overflow is proven — even a million-row file costs only a
     * bounded scan. The sheet and string-table XML are decompressed
     * incrementally into byte-capped temp files (BoundedZip), so no
     * decompressed XML is ever held in memory as a string; breaching the
     * cap rejects with 413 FILE_EXPANDED_TOO_LARGE before any parse.
     * Fully-blank rows are skipped by the counter exactly like the import
     * loop below (trimmed cell values; FALSE booleans and whitespace-only
     * shared strings count as blank), so blanks never push real rows out
     * of a window and no accepted row is ever dropped.
     *
     * The loaded rows are recounted as a safety net (covers legacy .xls,
     * where the OOXML streaming counter is unavailable but a Workbook-stream
     * size pre-check runs first, and any counter divergence), so the verdict
     * is always exact before any ImportJob row or competency row is written.
     * Only counts/sizes are logged, never content.
     *
     * @param  UploadedFile  $file
     * @return array<int, array<int, mixed>>
     */
    private function readSpreadsheetRows($file): array
    {
        $realPath = $file->getRealPath();
        $fileBytes = (int) $file->getSize();

        try {
            $streamedCount = $this->countXlsxDataRowsStreaming($realPath, $fileBytes);
        } catch (\RuntimeException $e) {
            if ($e->getCode() !== BoundedZip::E_TOO_LARGE) {
                throw $e;
            }
            $this->rejectExpandedImport($fileBytes);
        }

        if ($streamedCount === null) {
            // Not a parseable OOXML workbook (e.g. legacy .xls): run the
            // expansion pre-check before the post-load net below.
            $this->assertOleWorkbookWithinCap($realPath);
        } elseif ($streamedCount > self::MAX_DATA_ROWS) {
            $this->rejectOversizedImport($streamedCount);
        }

        $spreadsheet = IOFactory::load($realPath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();
        $spreadsheet->disconnectWorksheets();

        $rows = array_values(array_slice($rows, 1));

        $dataRows = 0;
        foreach ($rows as $row) {
            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) !== 0) {
                $dataRows++;
            }
        }

        if ($dataRows > self::MAX_DATA_ROWS) {
            $this->rejectOversizedImport($dataRows);
        }

        return $rows;
    }

    /**
     * Reject an import file exceeding MAX_DATA_ROWS with 422 TOO_MANY_ROWS.
     */
    private function rejectOversizedImport(int $dataRows): void
    {
        Log::warning('BatchImportService rejected oversized import file', [
            'data_rows' => $dataRows,
        ]);

        throw new BusinessRuleConflictException(
            'A maximum of ' . self::MAX_DATA_ROWS . ' data rows is allowed per import file.',
            'TOO_MANY_ROWS',
            422
        );
    }

    /**
     * Reject an import file breaching the decompression caps with 413
     * FILE_EXPANDED_TOO_LARGE, before anything is parsed or written.
     */
    private function rejectExpandedImport(int $fileBytes): void
    {
        Log::warning('BatchImportService rejected import file breaching decompression cap', [
            'file_bytes' => $fileBytes,
        ]);

        throw new BusinessRuleConflictException(
            'The import file expands beyond the allowed size.',
            'FILE_EXPANDED_TOO_LARGE',
            413
        );
    }

    /**
     * Expansion pre-check for legacy .xls uploads (F-05 hardening): confirm
     * the OLE Workbook stream fits MAX_OLE_WORKBOOK_BYTES before
     * PhpSpreadsheet builds cell objects from it.
     *
     * OLERead walks the FAT only (no cell objects); the ≤15 MB input file
     * itself is the only transient allocation. Non-OLE files return
     * silently so IOFactory::load keeps its existing behavior (including
     * its existing errors for corrupt uploads).
     */
    private function assertOleWorkbookWithinCap(string $realPath): void
    {
        $magic = @file_get_contents($realPath, false, null, 0, 8);
        if ($magic !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            return;
        }

        $ole = new OLERead();
        $ole->read($realPath);
        $workbook = $ole->getStream($ole->wrkbook);
        $workbookBytes = $workbook === null ? 0 : strlen($workbook);

        if ($workbookBytes > self::MAX_OLE_WORKBOOK_BYTES) {
            Log::warning('BatchImportService rejected oversized legacy import payload', [
                'workbook_bytes' => $workbookBytes,
            ]);

            throw new BusinessRuleConflictException(
                'The import file expands beyond the allowed size.',
                'FILE_EXPANDED_TOO_LARGE',
                413
            );
        }
    }

    /**
     * Stream-count the non-blank data rows (excluding the header row) of the
     * active sheet of an .xlsx file without materializing any cell objects
     * or decompressed XML strings.
     *
     * The sheet and shared-string XML entries are decompressed incrementally
     * into byte-capped temp files (BoundedZip) and walked with XMLReader;
     * the scan aborts as soon as MAX_DATA_ROWS + 1 non-blank rows are
     * proven. Breaching a decompression cap propagates as a RuntimeException
     * with code BoundedZip::E_TOO_LARGE (the caller maps it to 413).
     * Shared-string cells are resolved through the workbook's string table
     * so whitespace-only strings count as blank, mirroring the import
     * loop's trim check (FALSE booleans likewise count as blank, since
     * (string) false === '').
     *
     * @return int|null Non-blank data-row count (possibly MAX_DATA_ROWS + 1
     *   on early abort), or null when the file is not a parseable OOXML
     *   workbook (e.g. legacy .xls) — callers run the OLE pre-check and
     *   fall back to counting rows after load.
     *
     * @throws \RuntimeException BoundedZip::E_TOO_LARGE on cap breach.
     */
    private function countXlsxDataRowsStreaming(string $realPath, int $compressedBytes): ?int
    {
        $zip = new \ZipArchive();
        if ($zip->open($realPath) !== true) {
            return null;
        }

        try {
            $sheetPath = $this->activeXlsxSheetPath($zip);
            if ($sheetPath === null) {
                return null;
            }
        } finally {
            $zip->close();
        }

        try {
            $sheetTmp = BoundedZip::copyEntryToTempFile($realPath, $sheetPath, $compressedBytes);
            $blankShared = $this->blankSharedStringIndexes($realPath, $compressedBytes);
        } catch (\RuntimeException $e) {
            if ($e->getCode() === BoundedZip::E_TOO_LARGE) {
                throw $e;
            }

            return null;
        }

        $reader = new \XMLReader();
        $previousErrors = libxml_use_internal_errors(true);
        try {
            if (! $reader->open($sheetTmp, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return null;
            }

            $rowsSeen = 0;
            $dataRows = 0;
            $inSheetData = false;
            $inRow = false;
            $rowNonBlank = false;
            $cellType = null;
            $cellText = null;
            $inValue = false;
            $inInlineString = false;
            $inInlineText = false;

            while ($reader->read()) {
                $nodeType = $reader->nodeType;

                if ($nodeType === \XMLReader::ELEMENT) {
                    $name = $reader->localName;

                    if ($name === 'sheetData') {
                        $inSheetData = true;

                        continue;
                    }

                    if (! $inSheetData) {
                        continue;
                    }

                    if ($name === 'row') {
                        $inRow = true;
                        $rowNonBlank = false;
                        if ($reader->isEmptyElement) {
                            $inRow = false;
                            $rowsSeen++;
                        }
                    } elseif ($name === 'c' && $inRow) {
                        $cellType = $reader->getAttribute('t');
                        $cellText = '';
                        $inValue = false;
                        $inInlineString = false;
                        $inInlineText = false;
                        if ($reader->isEmptyElement) {
                            $cellText = null;
                            $cellType = null;
                        }
                    } elseif ($name === 'v' && $cellText !== null) {
                        $inValue = true;
                    } elseif ($name === 'is' && $cellText !== null) {
                        $inInlineString = true;
                    } elseif ($name === 't' && $cellText !== null && $inInlineString) {
                        $inInlineText = true;
                    }
                } elseif ($nodeType === \XMLReader::END_ELEMENT) {
                    $name = $reader->localName;

                    if ($name === 'sheetData') {
                        $inSheetData = false;
                    } elseif ($name === 'row' && $inRow) {
                        $inRow = false;
                        $rowsSeen++;
                        if ($rowsSeen > 1 && $rowNonBlank && ++$dataRows > self::MAX_DATA_ROWS) {
                            return $dataRows;
                        }
                    } elseif ($name === 'c' && $cellText !== null) {
                        if ($this->isNonBlankXlsxCell($cellType, $cellText, $blankShared)) {
                            $rowNonBlank = true;
                        }
                        $cellText = null;
                        $cellType = null;
                    } elseif ($name === 'v') {
                        $inValue = false;
                    } elseif ($name === 'is') {
                        $inInlineString = false;
                    } elseif ($name === 't') {
                        $inInlineText = false;
                    }
                } elseif (
                    ($nodeType === \XMLReader::TEXT || $nodeType === \XMLReader::CDATA)
                    && $cellText !== null && ($inValue || $inInlineText)
                ) {
                    $cellText .= $reader->value;
                }
            }

            return $dataRows;
        } finally {
            $reader->close();
            libxml_use_internal_errors($previousErrors);
            libxml_clear_errors();
            @unlink($sheetTmp);
        }
    }

    /**
     * Blank verdict for one streamed cell, mirroring the import loop's
     * `trim((string) $value) !== ''` check on the resolved value.
     *
     * @param  array<int, bool>  $blankShared  Shared-string indexes whose
     *   resolved text is blank.
     */
    private function isNonBlankXlsxCell(?string $type, string $text, array $blankShared): bool
    {
        $trimmed = trim($text);

        if ($type === 's') {
            // Shared-string cell: blank iff the resolved string is blank.
            // An unresolvable index fails safe as non-blank (counted, and the
            // post-load recount keeps the final verdict exact).
            if (! ctype_digit($trimmed)) {
                return true;
            }

            return ! ($blankShared[(int) $trimmed] ?? false);
        }

        if ($type === 'b') {
            // (string) false === '', so FALSE booleans are blank downstream.
            return $trimmed !== '' && $trimmed !== '0';
        }

        return $trimmed !== '';
    }

    /**
     * Map each shared-string table index to whether its resolved text is
     * blank (single streaming pass over a byte-capped temp copy; only
     * booleans are retained, so the table itself is never materialized).
     *
     * A breaching table propagates BoundedZip::E_TOO_LARGE (a string table
     * bigger than the entry cap in a ≤5000-row import is bomb-shaped); a
     * missing table yields [] since there is then nothing to resolve.
     *
     * @return array<int, bool>
     *
     * @throws \RuntimeException BoundedZip::E_TOO_LARGE on cap breach.
     */
    private function blankSharedStringIndexes(string $realPath, int $compressedBytes): array
    {
        try {
            $sstTmp = BoundedZip::copyEntryToTempFile($realPath, 'xl/sharedStrings.xml', $compressedBytes);
        } catch (\RuntimeException $e) {
            if ($e->getCode() === BoundedZip::E_TOO_LARGE) {
                throw $e;
            }

            return [];
        }

        $blank = [];
        $reader = new \XMLReader();
        $previousErrors = libxml_use_internal_errors(true);
        try {
            if (! $reader->open($sstTmp, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return [];
            }

            $index = -1;
            $inItem = false;
            $text = '';

            while ($reader->read()) {
                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'si') {
                    $inItem = true;
                    $text = '';
                    if ($reader->isEmptyElement) {
                        $inItem = false;
                        $blank[++$index] = true;
                    }
                } elseif ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->localName === 'si' && $inItem) {
                    $inItem = false;
                    $blank[++$index] = trim($text) === '';
                } elseif (
                    ($reader->nodeType === \XMLReader::TEXT || $reader->nodeType === \XMLReader::CDATA) && $inItem
                ) {
                    $text .= $reader->value;
                }
            }
        } finally {
            $reader->close();
            libxml_use_internal_errors($previousErrors);
            libxml_clear_errors();
            @unlink($sstTmp);
        }

        return $blank;
    }

    /**
     * Resolve the zip path of the workbook's active sheet
     * (xl/worksheets/sheetN.xml) via workbook.xml (activeTab) and its rels.
     * Null on any parse failure — callers fall back to counting after load.
     */
    private function activeXlsxSheetPath(\ZipArchive $zip): ?string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        if (! is_string($workbookXml)) {
            return null;
        }

        $previousErrors = libxml_use_internal_errors(true);
        try {
            $workbook = simplexml_load_string($workbookXml, 'SimpleXMLElement', LIBXML_NONET);
            if ($workbook === false) {
                return null;
            }

            $namespaces = $workbook->getNamespaces(true);
            $mainNs = $namespaces[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
            $relNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

            $workbook->registerXPathNamespace('m', $mainNs);
            $workbook->registerXPathNamespace('r', $relNs);

            $activeTab = 0;
            $views = $workbook->xpath('//m:bookViews/m:workbookView[1]/@activeTab');
            if ($views !== false && isset($views[0])) {
                $activeTab = max(0, (int) (string) $views[0]);
            }

            $sheets = $workbook->xpath('//m:sheets/m:sheet');
            if ($sheets === false || ! isset($sheets[$activeTab])) {
                return null;
            }

            $relId = (string) ($sheets[$activeTab]->attributes($relNs)['id'] ?? '');
            if ($relId === '') {
                return null;
            }

            $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
            if (! is_string($relsXml)) {
                return null;
            }

            $rels = simplexml_load_string($relsXml, 'SimpleXMLElement', LIBXML_NONET);
            if ($rels === false) {
                return null;
            }

            foreach ($rels->Relationship as $rel) {
                if ((string) ($rel['Id'] ?? '') === $relId) {
                    $target = ltrim((string) ($rel['Target'] ?? ''), '/');
                    if ($target === '' || str_contains($target, '..')) {
                        return null;
                    }

                    return 'xl/' . $target;
                }
            }

            return null;
        } finally {
            libxml_use_internal_errors($previousErrors);
            libxml_clear_errors();
        }
    }

    /**
     * Batch job: delete error-report files whose validity window has elapsed
     * without being claimed (Requirements §8.2: reports are kept only
     * temporarily — about ERROR_REPORT_TTL_DAYS days — then cleaned up
     * automatically; fixes AUD-013). Intended to be invoked by a scheduled
     * task alongside the audit retention jobs.
     *
     * Only jobs whose error_report_expires_at has passed AND whose
     * error_report_path is still set are touched: the file is deleted from
     * disk and the path/token columns are cleared, so a re-run finds nothing
     * to revisit (idempotent by construction). Rows whose report was already
     * consumed by a successful download may also match here — their file is
     * already gone and the exists() guard skips the redundant delete.
     *
     * Fail-closed per job: if a report file cannot be deleted (delete returns
     * false or throws) the file is left on disk AND the token columns are
     * left intact, so the next daily run retries instead of orphaning the
     * file with no reference — or orphaning a DB token row that points at a
     * file the sweep gave up on. One bad job never aborts the sweep for the
     * remaining jobs.
     *
     * @return int Number of expired error-report job rows cleaned up.
     *
     * @Traced-To ARCH-002 FR-031, ARCH-004 §8.1 (ARCH-001 §5.1)
     */
    public function cleanupExpiredErrorReports(): int
    {
        $expiredJobs = ImportJob::query()
            ->where('error_report_expires_at', '<', now())
            ->whereNotNull('error_report_path')
            ->get();

        $cleaned = 0;

        foreach ($expiredJobs as $job) {
            try {
                if (Storage::disk(config('filesystems.default', 'local'))->exists($job->error_report_path)) {
                    $deleted = Storage::disk(config('filesystems.default', 'local'))->delete($job->error_report_path);

                    if (! $deleted && Storage::disk(config('filesystems.default', 'local'))->exists($job->error_report_path)) {
                        Log::warning('BatchImportService::cleanupExpiredErrorReports file deletion failed; token retained for retry', [
                            'import_job_id' => $job->id,
                            'error_report_path' => $job->error_report_path,
                        ]);

                        continue;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('BatchImportService::cleanupExpiredErrorReports failed for job; token retained for retry', [
                    'import_job_id' => $job->id,
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            $job->update([
                'error_report_path' => null,
                'error_report_token' => null,
            ]);
            $cleaned++;
        }

        return $cleaned;
    }

    /**
     * Generate a one-time downloadable .xlsx error report, write it to
     * storage/app/import_errors/{token}.xlsx, and return the token (ARCH-004 §8.1).
     *
     * @param  array<int, array{row: int, reason: string}>  $failedRows
     * @param  array<int, array<int, mixed>>  $allRows
     *
     * @Traced-To ARCH-002 FR-031, ARCH-004 §8.1 (ARCH-001 §5.1)
     */
    private function generateErrorReport(array $failedRows, array $allRows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('A1', 'Row');
        $sheet->setCellValue('B1', 'code');
        $sheet->setCellValue('C1', 'descriptor');
        $sheet->setCellValue('D1', 'subject_id');
        $sheet->setCellValue('E1', 'grade_level');
        $sheet->setCellValue('F1', 'semester');
        $sheet->setCellValue('G1', 'Reason');

        $rowNum = 2;
        $neutralized = 0;
        foreach ($failedRows as $failedRow) {
            $originalRow = $allRows[$failedRow['row'] - 1] ?? [];

            $sheet->setCellValue('A' . $rowNum, $failedRow['row']);
            $col = 2;
            foreach ($originalRow as $cellValue) {
                $text = (string) $cellValue;
                $safe = $this->neutralizeFormulaPrefix($text);
                $coordinate = $this->columnLetter($col) . $rowNum;
                if ($safe !== $text) {
                    $neutralized++;
                    $sheet->setCellValueExplicit($coordinate, $safe, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue($coordinate, $text);
                }
                $col++;
            }
            $reason = $this->neutralizeFormulaPrefix($failedRow['reason']);
            $reasonCoordinate = $this->columnLetter($col) . $rowNum;
            if ($reason !== $failedRow['reason']) {
                $neutralized++;
                $sheet->setCellValueExplicit($reasonCoordinate, $reason, DataType::TYPE_STRING);
            } else {
                $sheet->setCellValue($reasonCoordinate, $failedRow['reason']);
            }
            $rowNum++;
        }

        if ($neutralized > 0) {
            Log::warning('BatchImportService::generateErrorReport neutralized formula-prefixed cells', [
                'count' => $neutralized,
            ]);
        }

        $token = Str::random(32);
        $path = self::ERROR_REPORT_DIR . '/' . $token . '.xlsx';

        $writer = new Xlsx($spreadsheet);
        $tempPath = tempnam(sys_get_temp_dir(), 'error_report_');
        $writer->save($tempPath);
        $spreadsheet->disconnectWorksheets();

        Storage::disk(config('filesystems.default', 'local'))->put($path, file_get_contents($tempPath));
        @unlink($tempPath);

        return $token;
    }

    /**
     * Neutralize spreadsheet formula-injection prefixes so error-report cells
     * render as inert text in Excel/LibreOffice (F-04).
     *
     * Cells whose first non-whitespace character is =, +, -, or @ — plus
     * cells beginning with a tab/CR control character — are prefixed with a
     * single quote and stored as explicit strings. Benign values are returned
     * unchanged. Raw cell contents are never logged.
     */
    private function neutralizeFormulaPrefix(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $trimmed = ltrim($value, " \t\r\n");
        if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true)) {
            return "'" . $value;
        }

        if ($value[0] === "\t" || $value[0] === "\r" || $value[0] === "\n") {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * Convert a 1-based column number to a spreadsheet column letter.
     */
    private function columnLetter(int $num): string
    {
        $letter = '';
        while ($num > 0) {
            $letter = chr(65 + ($num - 1) % 26) . $letter;
            $num = intdiv($num - 1, 26);
        }

        return $letter;
    }

    private function previewCacheKey(string $token): string
    {
        return self::PREVIEW_CACHE_PREFIX . hash('sha256', $token);
    }

    /**
     * Bounded enrollment read: same F-05 ceilings as competency imports
     * (streaming pre-count, OLE pre-check, post-load recount) but returns
     * the header row plus data rows so columns map order-insensitively.
     *
     * @return array{0: array<int, mixed>, 1: array<int, array<int, mixed>>}
     */
    private function readEnrollmentSpreadsheet($file): array
    {
        $realPath = $file->getRealPath();
        $fileBytes = (int) $file->getSize();

        try {
            $streamedCount = $this->countXlsxDataRowsStreaming($realPath, $fileBytes);
        } catch (\RuntimeException $e) {
            if ($e->getCode() !== BoundedZip::E_TOO_LARGE) {
                throw $e;
            }
            $this->rejectExpandedImport($fileBytes);
        }

        if ($streamedCount === null) {
            $this->assertOleWorkbookWithinCap($realPath);
        } elseif ($streamedCount > self::MAX_DATA_ROWS) {
            $this->rejectOversizedImport($streamedCount);
        }

        $spreadsheet = IOFactory::load($realPath);
        $worksheet = $spreadsheet->getActiveSheet();
        $all = $worksheet->toArray();
        $spreadsheet->disconnectWorksheets();

        if (empty($all)) {
            return [[], []];
        }

        $header = array_values(array_shift($all));
        $rows = array_values($all);

        $dataRows = 0;
        foreach ($rows as $row) {
            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) !== 0) {
                $dataRows++;
            }
        }

        if ($dataRows > self::MAX_DATA_ROWS) {
            $this->rejectOversizedImport($dataRows);
        }

        return [$header, $rows];
    }

    /**
     * Map full_name-only headers (case-insensitive, trimmed, extras ignored).
     * Only `full_name` is required; missing yields 422 VALIDATION_ERROR.
     *
     * @param  array<int, mixed>  $header
     * @return array<string, int>
     */
    private function mapEnrollmentHeader(array $header): array
    {
        $normalized = [];
        foreach ($header as $index => $cell) {
            $key = strtolower(trim((string) $cell));
            if ($key !== '' && ! isset($normalized[$key])) {
                $normalized[$key] = $index;
            }
        }

        if (! isset($normalized['full_name'])) {
            throw ValidationException::withMessages([
                'full_name' => ['The full_name column is required.'],
            ]);
        }

        return [
            'full_name' => $normalized['full_name'],
        ];
    }

    /**
     * Extract the full_name field from a raw row by header index.
     *
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $indexes
     * @return array{full_name: string}
     */
    private function extractEnrollmentFields(array $row, array $indexes): array
    {
        return [
            'full_name' => trim((string) ($row[$indexes['full_name']] ?? '')),
        ];
    }

    /**
     * Validate one full_name-only row.
     *
     * Blank names fail; names over 255 chars fail (guarded in both preview
     * and confirm; staged rows additionally truncate so an overlong value
     * can never overflow the varchar). In-file duplicates are allowed
     * (distinct accounts) and valid repeats are counted informationally by
     * the caller. Returns a plain-words reason or null when valid.
     */
    private function validateFullNameField(string $fullName): ?string
    {
        if ($fullName === '') {
            return 'Enter the full name.';
        }

        if (mb_strlen($fullName) > 255) {
            return 'Full name must not exceed 255 characters.';
        }

        return null;
    }

    /**
     * Generate a one-time full_name-only error sheet with plain-words reasons
     * plus contact guidance on a Help sheet. Served via the existing
     * single-use 7-day error-report download path.
     *
     * @param  array<int, array{row: int, reason: string, fields: array{full_name: string}}>  $failedRows
     */
    private function generateEnrollmentErrorReport(array $failedRows, string $importType = self::TYPE_STUDENT_ENROLLMENT): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Errors');

        $sheet->setCellValue('A1', 'Row');
        $sheet->setCellValue('B1', 'full_name');
        $sheet->setCellValue('C1', 'Reason');

        $rowNum = 2;
        foreach ($failedRows as $failedRow) {
            $fields = $failedRow['fields'] ?? [];
            $values = [
                $failedRow['row'],
                $fields['full_name'] ?? '',
                $failedRow['reason'],
            ];

            $col = 1;
            foreach ($values as $value) {
                $text = (string) $value;
                $safe = $this->neutralizeFormulaPrefix($text);
                $coordinate = $this->columnLetter($col) . $rowNum;
                if ($safe !== $text) {
                    $sheet->setCellValueExplicit($coordinate, $safe, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue($coordinate, $text);
                }
                $col++;
            }
            $rowNum++;
        }

        $help = $spreadsheet->createSheet();
        $help->setTitle('Help');
        $helpText = $importType === self::TYPE_TEACHER_APPLICATION
            ? 'Teacher accounts only: imported means new accounts created, failed means rejected rows. Please correct each Reason and ask your administrator to upload again.'
            : 'Learner accounts only: imported means new accounts created, failed means rejected rows. No classroom placements were made. Please correct each Reason and ask your administrator to upload again.';
        $help->setCellValue('A1', $helpText);
        $help->setCellValue('A2', 'If you need help, contact your school administrator or support team.');

        $token = Str::random(32);
        $path = self::ERROR_REPORT_DIR . '/' . $token . '.xlsx';

        $writer = new Xlsx($spreadsheet);
        $tempPath = tempnam(sys_get_temp_dir(), 'error_report_');
        $writer->save($tempPath);
        $spreadsheet->disconnectWorksheets();

        Storage::disk(config('filesystems.default', 'local'))->put($path, file_get_contents($tempPath));
        @unlink($tempPath);

        return $token;
    }
}
