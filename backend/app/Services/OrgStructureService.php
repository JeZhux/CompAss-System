<?php

namespace App\Services;

use App\Exceptions\BusinessRuleConflictException;
use App\Models\Assessment;
use App\Models\Assignment;
use App\Models\AssignmentFeedback;
use App\Models\AssignmentSubmission;
use App\Models\Classroom;
use App\Models\CompetencyReference;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Semester;
use App\Models\Subject;
use App\Models\SubmissionFile;
use App\Support\SafeUpload;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Manages the organizational hierarchy (School Year → Semester → Grade Level →
 * Subject → Competencies), Section class-groupings, and Semester-closure purge.
 *
 * Invariants (ARCH-001 §5.1 / ARCH-002 FR-005/ARCH-004 §10):
 *  - Semesters are '1', '2', or '3' within a School Year
 *    (UNIQUE(school_year_id, semester)).
 *  - Grade levels are restricted to grades 7 through 12 (ARCH-004 §10;
 *    Requirements Revision 2); one row per configured level per Semester.
 *  - Subjects hang directly off a Grade Level (subjects.grade_level_id);
 *    `code` is unique per Grade Level, not globally.
 *  - Subjects with active Classroom or Competency references cannot be
 *    edited/deleted while those references exist (ARCH-004 §10).
 *  - Teacher scope is derived from Classrooms
 *    (teacher_id + subject_id + section_id + school_year); there is no
 *    subject-section join table (dropped in the Semester restructure).
 */
class OrgStructureService
{
    public function __construct(
        private readonly AuditLogService $auditLogService
    ) {
    }

    /** Trash prefix for semester-closure purge staging (same default disk). */
    private const PURGE_TRASH_DIR = 'purge_trash';

    /** Default page size for paginated list operations. */
    public const PER_PAGE = 15;

    /** Grade levels in scope for this deployment (ARCH-004 §10). */
    public const ALLOWED_GRADE_LEVELS = [7, 8, 9, 10, 11, 12];

    /*
    |--------------------------------------------------------------------------
    | School Year
    |--------------------------------------------------------------------------
    */

    /**
     * @Traced-To ARCH-002 FR-005 (ARCH-001 §5.1)
     */
    public function createSchoolYear(string $name): SchoolYear
    {
        return SchoolYear::create(['name' => $name]);
    }

    /**
     * @return LengthAwarePaginator<int, SchoolYear>
     *
     * @Traced-To ARCH-002 FR-005 (ARCH-001 §5.1)
     */
    public function listSchoolYears(int $page = 1, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return SchoolYear::orderBy('name')->paginate($perPage, page: $page);
    }

    /*
    |--------------------------------------------------------------------------
    | Semester (School Year > Semester > Grade Level > Subject > Competencies)
    |--------------------------------------------------------------------------
    */

    /** Allowed Semester values (trimesters). */
    public const ALLOWED_SEMESTERS = ['1', '2', '3'];

    /**
     * Resolve a display name to a Semester value ('1', '2', or '3') for
     * backwards-compatible Semester creates that omit the explicit value.
     * First digit 1-3 found in the name wins; names without a 1-3 digit are
     * rejected with 422 (legacy silent default to '1' removed — spoof guard).
     */
    public static function semesterFromName(string $name): string
    {
        if (preg_match('/(^|[^0-9])3([^0-9]|$)/', $name) === 1) {
            return '3';
        }
        if (preg_match('/(^|[^0-9])2([^0-9]|$)/', $name) === 1) {
            return '2';
        }
        if (preg_match('/(^|[^0-9])1([^0-9]|$)/', $name) === 1) {
            return '1';
        }

        throw new BusinessRuleConflictException(
            'Could not determine the Semester from the name; supply semester explicitly (1, 2, or 3).',
            'INVALID_SEMESTER',
            422
        );
    }

    /**
     * @Traced-To ARCH-002 FR-005 (ARCH-001 §5.1)
     */
    public function createSemester(int $schoolYearId, string $semester, string $name, $startDate, $endDate): Semester
    {
        SchoolYear::findOrFail($schoolYearId);

        $semester = (string) $semester;
        if (! in_array($semester, self::ALLOWED_SEMESTERS, true)) {
            throw new BusinessRuleConflictException(
                'Semester must be 1, 2, or 3.',
                'INVALID_SEMESTER',
                422
            );
        }

        $exists = Semester::query()
            ->where('school_year_id', $schoolYearId)
            ->where('semester', $semester)
            ->exists();

        if ($exists) {
            throw new BusinessRuleConflictException(
                'This semester already exists under the selected school year.',
                'SEMESTER_ALREADY_EXISTS'
            );
        }

        return Semester::create([
            'school_year_id' => $schoolYearId,
            'semester' => $semester,
            'name' => $name,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
    }

    /**
     * Deprecated alias of createSemester() for pre-restructure call sites.
     * Old callers pass (schoolYearId, name, start, end) without an explicit
     * semester value; the semester is derived from the display name
     * (first digit 1-3 wins, else '1') so legacy creates keep working.
     *
     * @deprecated Use createSemester() instead.
     */
    public function createTerm(int $schoolYearId, string $name, $startDate, $endDate, ?string $semester = null): Semester
    {
        return $this->createSemester(
            $schoolYearId,
            $semester ?? self::semesterFromName($name),
            $name,
            $startDate,
            $endDate
        );
    }

    /**
     * @return LengthAwarePaginator<int, Semester>
     *
     * @Traced-To ARCH-002 FR-005 (ARCH-001 §5.1)
     */
    public function listSemesters(int $schoolYearId, int $page = 1, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return Semester::query()
            ->where('school_year_id', $schoolYearId)
            ->orderBy('semester')
            ->orderBy('start_date')
            ->paginate($perPage, page: $page);
    }

    /**
     * Deprecated alias of listSemesters().
     *
     * @deprecated Use listSemesters() instead.
     *
     * @return LengthAwarePaginator<int, Semester>
     */
    public function listTerms(int $schoolYearId, int $page = 1, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $this->listSemesters($schoolYearId, $page, $perPage);
    }

    /**
     * Semester-closure purge (ARCH-002 QA-010, ARCH-002 QA-010): delete a closed Semester's
     * assignment submission data — submission records, their feedback, and
     * the submission files on disk (ARCH-002 QA-010, ARCH-002 QA-010).
     *
     * The purge is DB-scoped: only files belonging to the Semester's assignment
     * submissions are deleted. Assignment rows, teacher attachments,
     * announcements, and assessment/mastery data are never touched (ARCH-002 QA-010).
     *
     * Trash-rename atomicity, fail before touching final state (ARCH-002 QA-012,
     * ARCH-002 QA-010): every existing submission file is moved (same-disk
     * rename, no bytes in RAM) to a unique trash path under
     * `purge_trash/{semester_id}/` BEFORE any DB row is touched. Any move
     * failure moves already-moved files back and aborts with
     * `PURGE_FILE_DELETION_FAILED`, leaving all files and all DB rows
     * unchanged. Only after ALL moves succeed do the DB deletes run, inside
     * a single transaction (ARCH-002 QA-010); a DB failure moves the trash files back
     * before the exception propagates.
     *
     * "Closed" means the Semester's `end_date` is strictly before today — a Semester
     * ending today is still in session (the date cast is midnight, so a bare
     * `isPast()` check would wrongly pass on the end day itself).
     *
     * No backing file is touched in final state unless all DB rows will
     * commit: move failures abort before the DB is touched, mid-purge move
     * failures rename back what was moved, and DB failures rename back the
     * trash while the transaction rolls back. Read-path faults (e.g. an
     * unreadable file) cannot strand data — staging never reads bytes. The
     * 409 message lists the failed paths (redacted via `SafeUpload::loggable`),
     * and a retry is safe — it converges to the same counts as a single
     * success.
     *
     * Non-atomic tail (documented, inventoried): once the DB transaction has
     * committed, the trash files are deleted best-effort. A trash-delete
     * failure does NOT fail the purge (the data loss already happened by
     * design — rows are gone); leftovers are logged server-side and returned
     * in `trash_remaining` for retry/cleanup. Every non-dry-run purge first
     * sweeps `purge_trash/{semester_id}/` leftovers, so a retry converges the
     * manifest to zero.
     *
     * Dry-run mode (`$dryRun === true`): computes the exact same counts via
     * the same traversal but deletes nothing — no DB rows, no files, and no
     * `purge_semesters` audit entry (ARCH-002 QA-012, ARCH-002 QA-010). The `SEMESTER_NOT_CLOSED`
     * guard applies in dry-run mode too.
     *
     * Re-running the purge on the same Semester is idempotent: it deletes nothing
     * (zero counts) and still records a `purge_semesters` audit entry. There is no
     * "already purged" guard — the ARCH-002 QA-010 contract defines no such state.
     *
     * `assignments_purged` counts the distinct Assignments from which at least
     * one submission was purged — NOT the number of Assignments in the Semester
     * (Assignment rows themselves are never deleted; ARCH-002 QA-010).
     *
     * @param  bool  $dryRun  Counts-only mode: compute and return the counts without deleting anything.
     * @return array{semester_id: int, term_id: int, semester: string, assignments_purged: int, submissions_purged: int, files_purged: int,
     *               trash_remaining: list<string>}
     *
     * @Traced-To ARCH-002 QA-010, ARCH-002 QA-010, ARCH-002 QA-010, ARCH-002 QA-006, ARCH-002 QA-012 (ARCH-002 QA-010, ARCH-002 QA-010)
     */
    public function purgeClosedSemester(int $semesterId, bool $dryRun = false): array
    {
        $semester = Semester::findOrFail($semesterId);

        // Closed means the Semester's end_date is strictly before today: a Semester
        // ending today is still in session. The date cast is midnight, so a
        // bare isPast() check would wrongly pass on the end day itself.
        if (! $semester->end_date->lt(today())) {
            throw new BusinessRuleConflictException(
                'Semester is not closed; only closed Semesters can be purged.',
                'SEMESTER_NOT_CLOSED'
            );
        }

        $assignments = Assignment::query()
            ->where('semester_id', $semesterId)
            ->with('submissions.files')
            ->get();

        $submissionIds = [];
        $assignmentIds = [];
        $filePaths = [];

        foreach ($assignments as $assignment) {
            foreach ($assignment->submissions as $submission) {
                $submissionIds[] = $submission->id;
                $assignmentIds[] = $assignment->id;

                foreach ($submission->files as $file) {
                    $filePaths[] = $file->filename;
                }
            }
        }

        $counts = [
            'assignments_purged' => count(array_unique($assignmentIds)),
            'submissions_purged' => count($submissionIds),
        ];

        $disk = SafeUpload::disk();

        if ($dryRun) {
            $counts['files_purged'] = count(array_filter(
                $filePaths,
                fn (string $path): bool => $disk->exists($path)
            ));

            return $counts + ['semester_id' => $semesterId, 'term_id' => $semesterId, 'semester' => (string) $semester->semester, 'trash_remaining' => []];
        }

        $trashDir = self::PURGE_TRASH_DIR . '/' . $semesterId;

        // Best-effort sweep of orphans left by an earlier purge whose DB
        // commit succeeded but final trash cleanup did not. Sweep misses are
        // inventoried in the manifest, never fatal.
        $remaining = [];
        try {
            $leftovers = $disk->exists($trashDir) ? $disk->files($trashDir) : [];
        } catch (\Throwable $e) {
            Log::warning('Semester-closure purge could not list leftover trash; continuing.', [
                'semester_id' => $semesterId,
                'error' => $e->getMessage(),
            ]);
            $leftovers = [];
        }

        foreach ($leftovers as $leftover) {
            $sweepError = 'delete returned false';

            try {
                $swept = $disk->delete($leftover);
            } catch (\Throwable $e) {
                $swept = false;
                $sweepError = $e->getMessage();
            }

            if (! $swept) {
                $remaining[] = $leftover;
                Log::warning('Semester-closure purge could not remove leftover trash file.', [
                    'semester_id' => $semesterId,
                    'path' => SafeUpload::loggable($leftover),
                    'error' => $sweepError,
                ]);
            }
        }

        // Trash-rename staging: move each existing file to a unique trash
        // path on the SAME disk (rename, no bytes in RAM). Any move failure
        // moves already-moved files back and aborts before the DB is touched.
        $moved = [];
        foreach ($filePaths as $path) {
            if (! $disk->exists($path)) {
                continue;
            }

            $trashPath = $trashDir . '/' . SafeUpload::uniquePrefix() . '_' . basename(str_replace('\\', '/', $path));
            $moveError = 'move returned false';

            try {
                $staged = $disk->move($path, $trashPath);
            } catch (\Throwable $e) {
                $staged = false;
                $moveError = $e->getMessage();
            }

            if (! $staged) {
                $this->moveTrashBack($disk, $moved, $semesterId);
                Log::warning('Semester-closure purge trash staging failed; all files and rows left unchanged.', [
                    'semester_id' => $semesterId,
                    'path' => SafeUpload::loggable($path),
                    'error' => $moveError,
                ]);

                throw new BusinessRuleConflictException(
                    'File deletion failed; purge aborted with no database changes and no files removed. Failed: '
                    . SafeUpload::loggable($path) . '. Retry is safe: nothing was deleted.',
                    'PURGE_FILE_DELETION_FAILED'
                );
            }

            $moved[] = ['original' => $path, 'trash' => $trashPath];
        }

        try {
            DB::transaction(function () use ($submissionIds): void {
                if ($submissionIds !== []) {
                    SubmissionFile::whereIn('submission_id', $submissionIds)->delete();
                    AssignmentFeedback::whereIn('submission_id', $submissionIds)->delete();
                    AssignmentSubmission::whereIn('id', $submissionIds)->delete();
                }
            });
        } catch (\Throwable $e) {
            $this->moveTrashBack($disk, $moved, $semesterId);
            Log::warning('Semester-closure purge database deletion failed; trash files were moved back.', [
                'semester_id' => $semesterId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        // Documented non-atomic tail: rows are committed; trash files are
        // deleted best-effort. Leftovers are inventoried in trash_remaining
        // for retry/cleanup — never a purge failure, never silent.
        foreach ($moved as $pair) {
            $deleteError = 'delete returned false';

            try {
                $removed = $disk->delete($pair['trash']);
            } catch (\Throwable $e) {
                $removed = false;
                $deleteError = $e->getMessage();
            }

            if (! $removed) {
                $remaining[] = $pair['trash'];
                Log::warning('Semester-closure purge trash cleanup failed; orphan inventoried for retry.', [
                    'semester_id' => $semesterId,
                    'path' => SafeUpload::loggable($pair['trash']),
                    'error' => $deleteError,
                ]);
            }
        }

        $counts['files_purged'] = count($moved);

        $this->auditLogService->log(
            'purge_semesters',
            'Semester-closure purge (semester ' . $semesterId . ')',
            Auth::id(),
            Semester::class,
            $semesterId,
            $counts
        );

        return $counts + ['semester_id' => $semesterId, 'term_id' => $semesterId, 'semester' => (string) $semester->semester, 'trash_remaining' => array_values(array_unique($remaining))];
    }

    /**
     * Deprecated alias of purgeClosedSemester() for pre-restructure call sites.
     *
     * @deprecated Use purgeClosedSemester() instead.
     */
    public function purgeClosedTerm(int $termId, bool $dryRun = false): array
    {
        return $this->purgeClosedSemester($termId, $dryRun);
    }

    /*
    |--------------------------------------------------------------------------
    | Grade Level
    |--------------------------------------------------------------------------
    */

    /**
     * Validates the grade level is within the grades 7–12 scope (ARCH-004 §10;
     * Requirements Revision 2).
     *
     * @Traced-To ARCH-002 FR-005, ARCH-004 §10 (ARCH-001 §5.1)
     */
    public function createGradeLevel(int $semesterId, int $gradeLevel): GradeLevel
    {
        if (! in_array($gradeLevel, self::ALLOWED_GRADE_LEVELS, true)) {
            throw new BusinessRuleConflictException(
                'Only grade levels 7 through 12 are supported by this deployment.',
                'INVALID_GRADE_LEVEL'
            );
        }

        Semester::findOrFail($semesterId);

        // AUD-009 / ARCH-004 §10 precondition: one row per configured level per
        // Semester — duplicates distort the admin school-wide overview.
        $exists = GradeLevel::query()
            ->where('semester_id', $semesterId)
            ->where('grade_level', (string) $gradeLevel)
            ->exists();

        if ($exists) {
            throw new BusinessRuleConflictException(
                'This grade level already exists under the selected semester.',
                'GRADE_LEVEL_ALREADY_EXISTS'
            );
        }

        return GradeLevel::create([
            'semester_id' => $semesterId,
            'grade_level' => (string) $gradeLevel,
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, GradeLevel>
     *
     * @Traced-To ARCH-002 FR-005 (ARCH-001 §5.1)
     */
    public function listGradeLevels(int $semesterId, int $page = 1, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return GradeLevel::query()
            ->where('semester_id', $semesterId)
            ->orderBy('grade_level')
            ->paginate($perPage, page: $page);
    }

    /*
    |--------------------------------------------------------------------------
    | Section
    |--------------------------------------------------------------------------
    */

    /**
     * @Traced-To ARCH-002 FR-005 (ARCH-001 §5.1)
     */
    public function createSection(int $gradeLevelId, string $name): Section
    {
        GradeLevel::findOrFail($gradeLevelId);

        return Section::create([
            'grade_level_id' => $gradeLevelId,
            'name' => $name,
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Section>
     *
     * @Traced-To ARCH-002 FR-005 (ARCH-001 §5.1)
     */
    public function listSections(int $gradeLevelId, int $page = 1, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return Section::query()
            ->where('grade_level_id', $gradeLevelId)
            ->orderBy('name')
            ->paginate($perPage, page: $page);
    }

    /*
    |--------------------------------------------------------------------------
    | Subject (admin-managed repository — ARCH-004 §10)
    | School Year > Semester > Grade Level > Subject > Competencies
    |--------------------------------------------------------------------------
    */

    /**
     * Subjects are scoped under a Grade Level (which already implies one
     * Semester). `code` is unique per Grade Level, not globally.
     *
     * @Traced-To ARCH-004 §10 (ARCH-001 §5.1)
     */
    public function createSubject(string $name, string $code, ?string $description, int $gradeLevelId): Subject
    {
        $gradeLevel = GradeLevel::findOrFail($gradeLevelId);

        $exists = Subject::query()
            ->where('grade_level_id', $gradeLevel->id)
            ->where('code', $code)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'code' => ['The code is already in use for this grade level.'],
            ]);
        }

        return Subject::create([
            'name' => $name,
            'code' => $code,
            'description' => $description,
            'grade_level_id' => $gradeLevel->id,
        ]);
    }

    /**
     * List subjects (ARCH-005 block 4.9 GET /admin/subjects).
     *
     * NOTE: ARCH-001 §5.1's method table lists create/update/deleteSubject but
     * not a list helper; the ARCH-005 block 4.9 GET traces to
     * OrgStructureService, so this read is a Phase 1 extension to serve it.
     *
     * @return LengthAwarePaginator<int, Subject>
     *
     * @Traced-To ARCH-004 §10 (ARCH-005 block 4.9)
     */
    public function listSubjects(int $page = 1, int $perPage = self::PER_PAGE, ?int $gradeLevelId = null): LengthAwarePaginator
    {
        return Subject::query()
            ->with('gradeLevel')
            ->when($gradeLevelId !== null, fn ($q) => $q->where('grade_level_id', $gradeLevelId))
            ->orderBy('name')
            ->paginate($perPage, page: $page);
    }

    /**
     * @Traced-To ARCH-004 §10 (ARCH-001 §5.1)
     */
    public function updateSubject(int $id, ?string $name, ?string $code, ?string $description, ?int $gradeLevelId = null): Subject
    {
        $subject = Subject::findOrFail($id);

        $this->guardSubjectNotReferenced($subject->id);

        $targetGradeLevelId = $subject->grade_level_id;
        if ($gradeLevelId !== null) {
            GradeLevel::findOrFail($gradeLevelId);
            $targetGradeLevelId = $gradeLevelId;
        }

        $subject->fill([
            'name' => $name ?? $subject->name,
            'code' => $code ?? $subject->code,
            'description' => $description ?? $subject->description,
            'grade_level_id' => $targetGradeLevelId,
        ]);

        $effectiveCode = $code ?? $subject->getOriginal('code');
        $effectiveGradeLevelId = $targetGradeLevelId;
        if ($code !== null || $gradeLevelId !== null) {
            $this->guardCodeUniquePerGradeLevel($effectiveCode, $effectiveGradeLevelId, $id);
        }

        $subject->save();

        return $subject;
    }

    /**
     * @Traced-To ARCH-004 §10 (ARCH-001 §5.1)
     */
    public function deleteSubject(int $id): void
    {
        $subject = Subject::findOrFail($id);

        // ARCH-004 §10: block while active Classroom/Competency references exist.
        $this->guardSubjectNotReferenced($subject->id);

        $subject->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Teacher scope (classroom-derived — subject_sections dropped)
    |--------------------------------------------------------------------------
    |
    * Teacher scope is derived from Classrooms
    * (teacher_id + subject_id + section_id + school_year). The old
    * subject-section join tables were dropped in the Semester restructure;
    * see Admin Classroom endpoints for the current ownership flow (Agent 3).
    * These helpers expose the classroom-derived view for admin reads.
    */

    /**
     * Classroom-derived assignments for one class grouping section.
     *
     * @return Collection<int, array<string, mixed>>
     *
     * @Traced-To ARCH-002 FR-005, ARCH-005 block 4.1 (ARCH-001 §5.1)
     */
    public function getSectionAssignments(int $sectionId): Collection
    {
        return Classroom::query()
            ->where('section_id', $sectionId)
            ->with(['subject', 'teacher', 'section.gradeLevel.semester.schoolYear'])
            ->get()
            ->map(fn (Classroom $c) => [
                'id' => $c->id,
                'subject_id' => $c->subject_id,
                'section_id' => $c->section_id,
                'subject' => $c->subject?->name,
                'subject_name' => $c->subject?->name,
                'subject_code' => $c->subject?->code,
                'section_name' => $c->section?->name,
                'teacher' => $c->teacher?->name,
                'teacher_name' => $c->teacher?->name,
                'teacher_school_id' => $c->teacher?->school_id,
                'teacher_id' => $c->teacher_id,
                'school_year' => $c->school_year,
                'created_at' => $c->created_at,
            ]);
    }

    /**
     * Classroom-derived assignments for one teacher.
     *
     * Teacher-self scope (Profile + teacher analytics): the caller's identity
     * is already known, so unlike the admin shapes (classroom list/detail,
     * section/teacher assignments) this omits teacher_name/teacher_school_id
     * by design — no drift, just a narrower contract.
     *
     * @return Collection<int, array<string, mixed>>
     *
     * @Traced-To ARCH-002 FR-005, ARCH-005 block 4.1 (ARCH-001 §5.1)
     */
    public function getTeacherAssignments(int $teacherId): Collection
    {
        return Classroom::query()
            ->where('teacher_id', $teacherId)
            ->with(['subject', 'section.gradeLevel.semester.schoolYear'])
            ->get()
            ->map(function (Classroom $c) {
                $gradeLevel = $c->section?->gradeLevel;
                $semester = $gradeLevel?->semester;

                return [
                    'id' => $c->id,
                    'subject_id' => $c->subject_id,
                    'section_id' => $c->section_id,
                    'subject' => $c->subject?->name,
                    'section' => $c->section?->name,
                    'grade_level' => $gradeLevel?->grade_level,
                    'semester' => $semester?->semester,
                    'semester_id' => $gradeLevel?->semester_id,
                    'term_id' => $gradeLevel?->semester_id,
                    'semester_name' => $semester?->name,
                    'school_year' => $c->school_year,
                    'created_at' => $c->created_at,
                ];
            });
    }

    /**
     * Best-effort move of trash-staged purge files back to their original
     * paths (same-disk rename back). Used only on the abort paths so a
     * staging or DB failure leaves all files+rows unchanged; a move-back
     * miss is logged server-side and never masks the original failure.
     *
     * @param  list<array{original: string, trash: string}>  $moved  Staged pairs, in move order.
     */
    private function moveTrashBack(Filesystem $disk, array $moved, int $semesterId): void
    {
        foreach (array_reverse($moved) as $pair) {
            $restoreError = 'move returned false';

            try {
                $restored = $disk->move($pair['trash'], $pair['original']);
            } catch (\Throwable $e) {
                $restored = false;
                $restoreError = $e->getMessage();
            }

            if (! $restored) {
                Log::warning('Semester-closure purge trash move-back failed after abort.', [
                    'semester_id' => $semesterId,
                    'path' => SafeUpload::loggable($pair['trash']),
                    'error' => $restoreError,
                ]);
            }
        }
    }

    /**
     * ARCH-004 §10: subjects with active Classroom or Competency references
     * cannot be structurally changed or removed.
     */
    private function guardSubjectNotReferenced(int $subjectId): void
    {
        $referenced = Classroom::query()->where('subject_id', $subjectId)->exists()
            || CompetencyReference::query()->where('subject_id', $subjectId)->exists()
            || Assignment::query()->where('subject_id', $subjectId)->exists()
            || Assessment::query()->where('subject_id', $subjectId)->exists();

        if ($referenced) {
            throw new BusinessRuleConflictException(
                'Cannot modify a subject that has active classroom or competency references.',
                'SUBJECT_HAS_ACTIVE_ASSIGNMENTS'
            );
        }
    }

    /**
     * Defense-in-depth uniqueness check for subject code per Grade Level
     * (ARCH-002 QA-004 integrity). Form Requests also validate uniqueness,
     * but this guards direct service use.
     */
    private function guardCodeUniquePerGradeLevel(string $code, ?int $gradeLevelId, int $exceptId): void
    {
        if ($gradeLevelId === null) {
            return;
        }

        if (Subject::where('code', $code)->where('grade_level_id', $gradeLevelId)->where('id', '!=', $exceptId)->exists()) {
            throw ValidationException::withMessages([
                'code' => ['The code is already in use for this grade level.'],
            ]);
        }
    }
}
