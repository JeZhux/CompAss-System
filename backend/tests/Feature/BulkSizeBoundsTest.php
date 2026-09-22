<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleConflictException;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentSubmission;
use App\Models\ClassroomEnrollment;
use App\Models\CompetencyReference;
use App\Models\GradeEntry;
use App\Models\GradeLevel;
use App\Models\ImportJob;
use App\Models\LearningMaterial;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Semester;
use App\Models\User;
use App\Services\BatchImportService;
use App\Services\ClassroomService;
use App\Services\GradingService;
use App\Services\LearningMaterialService;
use App\Support\BoundedZip;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * F-05: unbounded bulk/size expansion ceilings — three independently
 * testable bounds.
 *
 *  1. Bulk grades: at most 100 students per request and at most 100 grade
 *     entries per student (BulkGradeRequest + GradingService guard).
 *  2. Import rows: at most 5000 data rows per file, excluding the header
 *     and fully-blank skipped rows (BatchImportService bounded read).
 *  3. Extraction: at most EXTRACTED_TEXT_MAX_CHARS (200000) chars stored,
 *     accumulated with early termination (LearningMaterialService).
 *
 * Every bound rejects (or truncates, for extraction) before any grade
 * write / before full materialization, and logs only counts — never
 * payload content.
 */
#[Group('f-05')]
class BulkSizeBoundsTest extends TestCase
{
    use RefreshDatabase;

    // ========================================================================
    // Shared org setup (bulk + extraction)
    // ========================================================================

    /**
     * Build School Year -> Semester -> Grade Level 7 -> Section + Subject with one
     * assigned teacher, one enrolled student, and one competency tag.
     *
     * @return array{teacher: User, student: User, subject: Subject, competency: CompetencyReference, classroom: Classroom}
     */
    private function setUpOrg(): array
    {
        $year = SchoolYear::create(['name' => 'SY F05']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
        ]);
        $gradeLevel = GradeLevel::create(['semester_id' => $semester->id, 'grade_level' => '7']);
        $section = Section::create(['grade_level_id' => $gradeLevel->id, 'name' => '7A-F05']);
        $subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-F05']);
        $competency = CompetencyReference::create(['semester' => '1', 'code' => 'M7-F05-01',
            'descriptor' => 'F05 competency',
            'subject_id' => $subject->id,
            'grade_level' => '7',
        ]);

        $teacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $student = User::factory()->create(['role' => 'Student', 'must_change_password' => false]);
        $classroom = app(ClassroomService::class)->createClassroom($teacher->id, $subject->id, $section->id, '2026-2027', null);
        ClassroomEnrollment::create([
            'classroom_id' => $classroom->id,
            'student_id' => $student->id,
            'joined_at' => now(),
        ]);

        return [
            'teacher' => $teacher,
            'student' => $student,
            'subject' => $subject,
            'competency' => $competency,
            'classroom' => $classroom,
        ];
    }

    /**
     * Release a classroom assessment with one essay item and leave the
     * student's attempt in pending_grading.
     *
     * @return array{assessment: Assessment, itemId: int, attempt: AssessmentAttempt}
     */
    private function setUpPendingBulkAssessment(User $teacher, User $student, $classroom, int $competencyId): array
    {
        $assessmentData = $this->actingAs($teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$classroom->id.'/assessments', [
                'title' => 'F05 Bulk Test',
                'description' => '',
                'type' => 'Recorded',
            ])
            ->json('data');

        $this->actingAs($teacher, 'sanctum')
            ->post('/api/teacher/assessments/'.$assessmentData['id'].'/items', [
                'item_type' => 'essay',
                'prompt' => 'Question 1',
                'max_points' => 10,
                'competency_tag_id' => $competencyId,
                'sort_order' => 1,
            ]);

        $this->actingAs($teacher, 'sanctum')
            ->post('/api/teacher/assessments/'.$assessmentData['id'].'/release');

        $assessment = Assessment::findOrFail($assessmentData['id']);
        $itemId = $assessment->items()->orderBy('sort_order')->first()->id;

        $this->actingAs($student, 'sanctum')
            ->post("/api/student/assessments/{$assessment->id}/start");
        $this->actingAs($student, 'sanctum')
            ->post("/api/student/assessments/{$assessment->id}/submit", [
                'responses' => [(string) $itemId => 'Student essay response.'],
            ]);

        $submission = AssessmentSubmission::where('assessment_id', $assessment->id)
            ->where('status', 'pending_grading')
            ->firstOrFail();

        return [
            'assessment' => $assessment,
            'itemId' => $itemId,
            'attempt' => AssessmentAttempt::findOrFail($submission->attempt_id),
        ];
    }

    // ========================================================================
    // Bound 1 — bulk grades: max 100 students, max 100 entries per student
    // ========================================================================

    public function test_bulk_rejects_101_students_with_422_before_any_write(): void
    {
        $org = $this->setUpOrg();
        $bulk = $this->setUpPendingBulkAssessment(
            $org['teacher'],
            $org['student'],
            $org['classroom'],
            $org['competency']->id
        );

        $grades = [];
        for ($i = 0; $i < 101; $i++) {
            $grades[] = [
                'student_id' => $org['student']->id,
                'grade_entries' => [
                    [
                        'assessment_item_id' => $bulk['itemId'],
                        'score' => 8,
                        'max_score' => 10,
                    ],
                ],
            ];
        }

        $this->actingAs($org['teacher'], 'sanctum')
            ->post('/api/grades/bulk', [
                'assessment_id' => $bulk['assessment']->id,
                'grades' => $grades,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        // Rejected before any grade write: no entries, attempt untouched.
        $this->assertSame(0, GradeEntry::count());
        $this->assertSame('pending_grading', $bulk['attempt']->fresh()->status);
    }

    public function test_bulk_rejects_101_entries_per_student_with_422_before_any_write(): void
    {
        $org = $this->setUpOrg();
        $bulk = $this->setUpPendingBulkAssessment(
            $org['teacher'],
            $org['student'],
            $org['classroom'],
            $org['competency']->id
        );

        $entries = [];
        for ($i = 0; $i < 101; $i++) {
            $entries[] = [
                'assessment_item_id' => $bulk['itemId'],
                'score' => 8,
                'max_score' => 10,
            ];
        }

        $this->actingAs($org['teacher'], 'sanctum')
            ->post('/api/grades/bulk', [
                'assessment_id' => $bulk['assessment']->id,
                'grades' => [
                    [
                        'student_id' => $org['student']->id,
                        'grade_entries' => $entries,
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertSame(0, GradeEntry::count());
        $this->assertSame('pending_grading', $bulk['attempt']->fresh()->status);
    }

    public function test_bulk_service_guard_rejects_101_students_with_422(): void
    {
        $service = app(GradingService::class);

        $grades = array_fill(0, 101, ['student_id' => 1, 'question_grades' => []]);

        try {
            $service->bulkGrade(1, $grades, 1);
            $this->fail('Expected BusinessRuleConflictException for 101 students.');
        } catch (BusinessRuleConflictException $e) {
            $this->assertSame('TOO_MANY_GRADES', $e->errorCode);
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(0, GradeEntry::count());
    }

    public function test_bulk_service_guard_rejects_101_entries_per_student_with_422(): void
    {
        $service = app(GradingService::class);

        $questionGrades = array_fill(0, 101, ['questionId' => 1, 'score' => 8, 'maxScore' => 10]);
        $grades = [['student_id' => 1, 'question_grades' => $questionGrades]];

        try {
            $service->bulkGrade(1, $grades, 1);
            $this->fail('Expected BusinessRuleConflictException for 101 entries.');
        } catch (BusinessRuleConflictException $e) {
            $this->assertSame('TOO_MANY_GRADE_ENTRIES', $e->errorCode);
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(0, GradeEntry::count());
    }

    public function test_bulk_service_guard_accepts_exactly_100(): void
    {
        $service = app(GradingService::class);

        // Exactly 100 students passes the size guard and reaches the
        // ownership check (unknown assessment → 403 AuthorizationException).
        $grades = array_fill(0, 100, ['student_id' => 1, 'question_grades' => []]);
        try {
            $service->bulkGrade(999999, $grades, 1);
            $this->fail('Expected AuthorizationException past the size guard.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        // Exactly 100 entries for one student likewise passes the guard.
        $questionGrades = array_fill(0, 100, ['questionId' => 1, 'score' => 8, 'maxScore' => 10]);
        try {
            $service->bulkGrade(999999, [['student_id' => 1, 'question_grades' => $questionGrades]], 1);
            $this->fail('Expected AuthorizationException past the size guard.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    // ========================================================================
    // Bound 2 — import rows: max 5000 data rows (header + blanks excluded)
    // ========================================================================

    /**
     * Helper: build an .xlsx upload with the given rows (row 0 = header).
     */
    private function makeXlsx(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $rowIdx => $row) {
            foreach ($row as $colIdx => $val) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIdx + 1).($rowIdx + 1), $val);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'f05_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'f05_import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /**
     * Helper: N valid competency-tag data rows with distinct codes.
     * 5 columns: code, descriptor, subject_id, grade_level, semester.
     */
    private function makeImportRows(int $subjectId, int $count, string $prefix): array
    {
        $rows = [['code', 'descriptor', 'subject_id', 'grade_level', 'semester']];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [sprintf('%s-%05d', $prefix, $i), 'Descriptor '.$i, $subjectId, 7, 1];
        }

        return $rows;
    }

    /** Scoped grade-7 subject for import ceiling tests. */
    private function makeImportSubject(string $code): Subject
    {
        $year = SchoolYear::firstOrCreate(['name' => 'SY F05-IMP']);
        $semester = Semester::firstOrCreate(
            ['school_year_id' => $year->id, 'semester' => '1'],
            ['name' => 'Semester 1', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31']
        );
        $gradeLevel = GradeLevel::firstOrCreate(['semester_id' => $semester->id, 'grade_level' => '7']);

        return Subject::firstOrCreate(
            ['grade_level_id' => $gradeLevel->id, 'code' => $code],
            ['name' => 'Math F05', 'description' => 'Math']
        );
    }

    public function test_import_rejects_5001_data_rows_with_422_before_any_write(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'Admin', 'must_change_password' => false]));
        $subject = $this->makeImportSubject('MATH-F05X');

        $response = $this->post('/api/admin/import/competency-tags', [
            'file' => $this->makeXlsx($this->makeImportRows($subject->id, 5001, 'F05OVER')),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'TOO_MANY_ROWS');

        // Rejected before any write: no job row, no competency rows.
        $this->assertSame(0, ImportJob::count());
        $this->assertSame(0, CompetencyReference::count());
    }

    public function test_import_accepts_5000_data_rows_with_blank_rows_skipped(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'Admin', 'must_change_password' => false]));
        $subject = $this->makeImportSubject('MATH-F05X');

        // Exactly 5000 non-blank data rows plus interleaved fully-blank rows
        // (skipped, never counted toward the ceiling) must be accepted.
        $rows = [['code', 'descriptor', 'subject_id', 'grade_level', 'semester']];
        for ($i = 1; $i <= 5000; $i++) {
            $rows[] = [sprintf('F05OK-%05d', $i), 'Descriptor '.$i, $subject->id, 7, 1];
            if ($i % 100 === 0) {
                $rows[] = ['', '', '', ''];
            }
        }

        $response = $this->post('/api/admin/import/competency-tags', [
            'file' => $this->makeXlsx($rows),
        ]);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame(5000, $data['imported_rows']);
        $this->assertSame(0, $data['failed_rows']);
        $this->assertSame(5000, CompetencyReference::count());
    }

    // ========================================================================
    // Bound 2b — import decompression guard: 413 before parse, no OOM
    // ========================================================================

    /**
     * Helper: swap the sheet of a genuine one-row .xlsx for caller-built
     * sheet XML. The container (content types, workbook, rels, styles)
     * stays byte-identical to a real PhpSpreadsheet file, so MIME sniffing
     * and sheet resolution behave exactly as in production.
     */
    private function makeSwappedSheetXlsx(string $sheetXml): UploadedFile
    {
        $base = $this->makeXlsx([
            ['code', 'descriptor', 'subject_id', 'grade_level', 'semester'],
            ['BOMB-1', 'd', 1, 7],
        ]);
        $path = $base->getRealPath();

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $this->assertTrue($zip->deleteName('xl/worksheets/sheet1.xml'));
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();

        return new UploadedFile($path, 'f05_bomb.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /**
     * Helper: overwrite the central-directory uncompressed sizes of one
     * entry (simulating a lying-metadata zip bomb). Local headers and data
     * stay intact, so streaming reads still yield the true bytes.
     */
    private function patchCentralUncompSize(string $path, string $entry, int $fakeSize): void
    {
        $bin = (string) file_get_contents($path);
        $eocd = strrpos($bin, "PK\x05\x06");
        $this->assertNotFalse($eocd);
        $cdOff = unpack('V', substr($bin, $eocd + 16, 4))[1];
        $pos = $cdOff;
        $patched = false;
        while (substr($bin, $pos, 4) === "PK\x01\x02") {
            $fnLen = unpack('v', substr($bin, $pos + 28, 2))[1];
            $exLen = unpack('v', substr($bin, $pos + 30, 2))[1];
            $coLen = unpack('v', substr($bin, $pos + 32, 2))[1];
            if (substr($bin, $pos + 46, $fnLen) === $entry) {
                $bin = substr_replace($bin, pack('V', $fakeSize), $pos + 24, 4);
                $patched = true;
            }
            $pos += 46 + $fnLen + $exLen + $coLen;
        }
        $this->assertTrue($patched, 'Central-directory entry not found for patching.');
        file_put_contents($path, $bin);
    }

    public function test_import_rejects_honest_zip_bomb_with_413_before_any_write(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'Admin', 'must_change_password' => false]));
        $this->makeImportSubject('MATH-F05C');

        // ~12 MB of dense, highly-repetitive sheet XML (tiny on disk):
        // the stated-size pre-check must reject before any parse. A
        // row-count-only implementation would answer differently (6000
        // dense rows would hit 422/200 instead), so 413 pins the
        // expansion-guard path specifically.
        $pad = str_repeat('X', 2000);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1"><c r="A1" t="inlineStr"><is><t>code</t></is></c></row>';
        for ($i = 2; $i < 6002; $i++) {
            $xml .= '<row r="'.$i.'"><c r="A'.$i.'" t="inlineStr"><is><t>'.$pad.'</t></is></c></row>';
        }
        $xml .= '</sheetData></worksheet>';

        $response = $this->post('/api/admin/import/competency-tags', [
            'file' => $this->makeSwappedSheetXlsx($xml),
        ]);

        $response->assertStatus(413);
        $response->assertJsonPath('error.code', 'FILE_EXPANDED_TOO_LARGE');
        $this->assertSame(0, ImportJob::count());
        $this->assertSame(0, CompetencyReference::count());
    }

    public function test_import_rejects_ratio_bomb_with_413_before_any_write(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'Admin', 'must_change_password' => false]));
        $this->makeImportSubject('MATH-F05D');

        // ~5 MB of blank rows from a ~40 KB file (>100x expansion, under
        // the 8 MB absolute cap): only the ratio guard can fire. Blank
        // rows never trip the row-count abort, so 413 pins the ratio
        // branch specifically.
        $chunk = '';
        for ($i = 0; $i < 1000; $i++) {
            $chunk .= '<row r="'.$i.'"/>';
        }
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1"><c r="A1" t="inlineStr"><is><t>code</t></is></c></row>'
            . str_repeat($chunk, 340)
            . '</sheetData></worksheet>';

        $response = $this->post('/api/admin/import/competency-tags', [
            'file' => $this->makeSwappedSheetXlsx($xml),
        ]);

        $response->assertStatus(413);
        $response->assertJsonPath('error.code', 'FILE_EXPANDED_TOO_LARGE');
        $this->assertSame(0, ImportJob::count());
        $this->assertSame(0, CompetencyReference::count());
    }

    public function test_import_rejects_lying_central_directory_bomb_with_413(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'Admin', 'must_change_password' => false]));
        $this->makeImportSubject('MATH-F05E');

        // Same dense bomb as above, but the central directory claims 64
        // bytes: the stated-size pre-check passes, so only the streaming
        // byte count (which sees the true decompressed bytes) can reject.
        $pad = str_repeat('X', 2000);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1"><c r="A1" t="inlineStr"><is><t>code</t></is></c></row>';
        for ($i = 2; $i < 6002; $i++) {
            $xml .= '<row r="'.$i.'"><c r="A'.$i.'" t="inlineStr"><is><t>'.$pad.'</t></is></c></row>';
        }
        $xml .= '</sheetData></worksheet>';

        $file = $this->makeSwappedSheetXlsx($xml);
        $this->patchCentralUncompSize($file->getRealPath(), 'xl/worksheets/sheet1.xml', 64);

        // Self-check: the patch is active, so the test genuinely exercises
        // the streaming path rather than the stated-size pre-check.
        $probe = new \ZipArchive();
        $probe->open($file->getRealPath());
        $stated = $probe->statName('xl/worksheets/sheet1.xml')['size'];
        $probe->close();
        $this->assertLessThan(BoundedZip::MAX_ENTRY_BYTES, $stated);

        $response = $this->post('/api/admin/import/competency-tags', [
            'file' => $file,
        ]);

        $response->assertStatus(413);
        $response->assertJsonPath('error.code', 'FILE_EXPANDED_TOO_LARGE');
        $this->assertSame(0, ImportJob::count());
        $this->assertSame(0, CompetencyReference::count());
    }

    // ========================================================================
    // Bound 3 — extraction: max 200000 chars stored, bounded accumulation
    // ========================================================================

    /**
     * Minimal real DOCX built at runtime; each entry becomes a w:t run.
     */
    private function minimalDocx(array $paragraphs): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'f05docx');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('Unable to create DOCX fixture zip.');
        }

        $body = '';
        foreach ($paragraphs as $paragraph) {
            $body .= '<w:p><w:r><w:t>'.htmlspecialchars((string) $paragraph, ENT_XML1).'</w:t></w:r></w:p>';
        }

        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body>'.$body.'</w:body></w:document>');
        $zip->close();

        $content = (string) file_get_contents($tmp);
        unlink($tmp);

        return $content;
    }

    /**
     * Minimal single-page PDF whose content stream shows each chunk in its
     * own BT/ET block; the xref table is byte-accurate.
     */
    private function minimalPdfFromChunks(array $chunks): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        ];
        $stream = '';
        foreach ($chunks as $text) {
            $escaped = str_replace(['(', ')', '\\'], ['\\(', '\\)', '\\\\'], $text);
            $stream .= "BT\n/F1 12 Tf\n72 720 Td\n(".$escaped.") Tj\nET\n";
        }
        $objects[4] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream.'endstream';
        $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number." 0 obj\n".$body."\nendobj\n";
        }

        $xrefStart = strlen($pdf);
        $pdf .= "xref\n0 6\n";
        $pdf .= "0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xrefStart."\n%%EOF";

        return $pdf;
    }

    /**
     * Minimal multi-page PDF sharing one content stream; the xref table is
     * byte-accurate.
     */
    private function minimalPdfMultiPage(int $pages, string $text): string
    {
        $kids = [];
        for ($i = 0; $i < $pages; $i++) {
            $kids[] = (3 + $i).' 0 R';
        }
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$pages.' >>',
        ];
        $escaped = str_replace(['(', ')', '\\'], ['\\(', '\\)', '\\\\'], $text);
        $stream = "BT\n/F1 12 Tf\n72 720 Td\n(".$escaped.") Tj\nET\n";
        $contentNum = 3 + $pages;
        $fontNum = 4 + $pages;
        for ($i = 0; $i < $pages; $i++) {
            $objects[3 + $i] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents '.$contentNum.' 0 R /Resources << /Font << /F1 '.$fontNum.' 0 R >> >> >>';
        }
        $objects[$contentNum] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream.'endstream';
        $objects[$fontNum] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number." 0 obj\n".$body."\nendobj\n";
        }

        $xrefStart = strlen($pdf);
        $count = max(array_keys($offsets)) + 1;
        $pdf .= "xref\n0 ".$count."\n";
        $pdf .= "0000000000 65535 f \n";
        for ($n = 1; $n < $count; $n++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$n] ?? 0);
        }
        $pdf .= "trailer\n<< /Size ".$count." /Root 1 0 R >>\nstartxref\n".$xrefStart."\n%%EOF";

        return $pdf;
    }

    public function test_docx_bomb_stores_null_without_failing_upload(): void
    {
        $org = $this->setUpOrg();
        Storage::fake('local');

        // ~10 MB of near-uniform document.xml (tiny on disk): breaching
        // the decompression cap stores NULL text — the upload itself still
        // succeeds, and nothing unbounded is ever held in memory.
        $docx = $this->minimalDocx(array_fill(0, 10000, str_repeat('A', 1000)));

        $response = $this->actingAs($org['teacher'], 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $org['subject']->id,
                'competency_id' => $org['competency']->id,
                'title' => 'F05 Bomb Notes',
                'file' => File::fake()->createWithContent(
                    'bomb.docx',
                    $docx,
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                ),
            ]);

        $response->assertCreated();

        $material = LearningMaterial::findOrFail($response->json('data.id'));
        $this->assertNull($material->extracted_text);
    }

    public function test_pdf_with_too_many_pages_stores_null(): void
    {
        $org = $this->setUpOrg();
        Storage::fake('local');

        $pdf = $this->minimalPdfMultiPage(LearningMaterialService::MAX_PDF_PAGES + 1, 'Paged text');

        $response = $this->actingAs($org['teacher'], 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $org['subject']->id,
                'competency_id' => $org['competency']->id,
                'title' => 'F05 Paged Guide',
                'file' => File::fake()->createWithContent('paged.pdf', $pdf),
            ]);

        $response->assertCreated();

        $material = LearningMaterial::findOrFail($response->json('data.id'));
        $this->assertNull($material->extracted_text);
    }

    public function test_pdf_with_few_pages_still_extracts(): void
    {
        $org = $this->setUpOrg();
        Storage::fake('local');

        $pdf = $this->minimalPdfMultiPage(3, 'Three page text');

        $response = $this->actingAs($org['teacher'], 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $org['subject']->id,
                'competency_id' => $org['competency']->id,
                'title' => 'F05 Short Guide',
                'file' => File::fake()->createWithContent('short.pdf', $pdf),
            ]);

        $response->assertCreated();

        $material = LearningMaterial::findOrFail($response->json('data.id'));
        $this->assertNotNull($material->extracted_text);
        $this->assertStringContainsString('Three page text', $material->extracted_text);
    }

    public function test_docx_text_over_bound_is_truncated_to_200000_chars(): void
    {
        $org = $this->setUpOrg();
        Storage::fake('local');

        // ~220000 chars of varied text across many runs — over the char
        // bound but ordinary compression (deliberately NOT bomb-shaped:
        // near-uniform runs would trip the expansion-ratio guard).
        $paragraphs = [str_repeat('A', 1000)];
        for ($i = 1; $i < 210; $i++) {
            $paragraphs[] = 'Section '.$i.': '.str_repeat(hash('sha256', 'f05-para-'.$i), 16);
        }
        $docx = $this->minimalDocx($paragraphs);

        $response = $this->actingAs($org['teacher'], 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $org['subject']->id,
                'competency_id' => $org['competency']->id,
                'title' => 'F05 Big Notes',
                'file' => File::fake()->createWithContent(
                    'big.docx',
                    $docx,
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                ),
            ]);

        // Oversized text truncates — it never fails the upload.
        $response->assertCreated();

        $material = LearningMaterial::findOrFail($response->json('data.id'));
        $this->assertNotNull($material->extracted_text);
        $this->assertSame(LearningMaterialService::EXTRACTED_TEXT_MAX_CHARS, mb_strlen($material->extracted_text));
        $this->assertSame(200000, mb_strlen($material->extracted_text));
        $this->assertStringStartsWith('AAAA', $material->extracted_text);
    }

    public function test_pdf_text_over_bound_is_truncated_to_200000_chars(): void
    {
        $org = $this->setUpOrg();
        Storage::fake('local');

        // ~210000 chars of text across many show-ops in one content stream.
        $chunks = array_fill(0, 210, str_repeat('B', 1000));
        $pdf = $this->minimalPdfFromChunks($chunks);

        $response = $this->actingAs($org['teacher'], 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $org['subject']->id,
                'competency_id' => $org['competency']->id,
                'title' => 'F05 Big Guide',
                'file' => File::fake()->createWithContent('big.pdf', $pdf),
            ]);

        $response->assertCreated();

        $material = LearningMaterial::findOrFail($response->json('data.id'));
        $this->assertNotNull($material->extracted_text);
        $this->assertSame(LearningMaterialService::EXTRACTED_TEXT_MAX_CHARS, mb_strlen($material->extracted_text));
        $this->assertSame(200000, mb_strlen($material->extracted_text));
        $this->assertStringStartsWith('BBBB', $material->extracted_text);
    }
}
