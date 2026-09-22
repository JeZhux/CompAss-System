<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Exceptions\BusinessRuleConflictException;
use App\Models\CompetencyReference;
use App\Models\GradeLevel;
use App\Models\ImportJob;
use App\Models\ImportLog;
use App\Models\SchoolYear;
use App\Models\Semester;
use App\Models\Subject;
use App\Models\User;
use App\Services\BatchImportService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

#[Group('batch-import')]
class BatchImportTest extends TestCase
{
    use RefreshDatabase;

    private function actAsAdmin(): User
    {
        return Sanctum::actingAs(User::factory()->create([
            'role' => 'Admin',
            'must_change_password' => false,
        ]));
    }

    /**
     * Scoped subject fixture: School Year → Semester '1' → Grade Level →
     * Subject. Competency rows must match the subject's grade + semester.
     * Fresh rows per call (no static cache — RefreshDatabase wipes IDs
     * between tests, so cached IDs would dangle).
     */
    private function makeScopedSubject(string $code, string $grade, string $name = 'Math'): Subject
    {
        $year = SchoolYear::firstOrCreate(['name' => 'SY-BI']);
        $semester = Semester::firstOrCreate(
            ['school_year_id' => $year->id, 'semester' => '1'],
            ['name' => 'Semester 1', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31']
        );

        $gradeLevel = GradeLevel::firstOrCreate(
            ['semester_id' => $semester->id, 'grade_level' => $grade]
        );

        return Subject::firstOrCreate(
            ['grade_level_id' => $gradeLevel->id, 'code' => $code],
            ['name' => $name, 'description' => $name]
        );
    }

    /**
     * Helper: create an .xlsx file in a temp location with given rows.
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
        $path = tempnam(sys_get_temp_dir(), 'test_').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'test_import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /**
     * Helper: create a CSV file in a temp location with given rows.
     */
    private function makeCsv(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'test_').'.csv';
        $fh = fopen($path, 'w');
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        fclose($fh);

        return new UploadedFile($path, 'test_import.csv', 'text/csv', null, true);
    }

    /**
     * Helper: create an .xlsx file whose cells are written as explicit strings,
     * bypassing the default value binder's numeric coercion. The grade-guard
     * rejection pins use this to reproduce hostile hand-crafted files that
     * carry literal '+7' / '7.0' / whitespace-padded grades as string cells.
     */
    private function makeXlsxPreservingStrings(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $rowIdx => $row) {
            foreach ($row as $colIdx => $val) {
                $sheet->getCell(Coordinate::stringFromColumnIndex($colIdx + 1).($rowIdx + 1))
                    ->setValueExplicit($val, DataType::TYPE_STRING);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'test_').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'test_import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /**
     * The student-enrollment template is full_name-only (Phase B, single
     * column); every supported enrollment slug yields 200 with that header.
     */
    public function test_template_download_student_enrollment_slugs(): void
    {
        $this->actAsAdmin();

        foreach (['student_enrollment', 'student_enrollments', 'student-enrollments', 'teacher_application', 'teacher_applications', 'teacher-applications'] as $slug) {
            $response = $this->get('/api/admin/import/templates/'.$slug);
            $response->assertOk();
            $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

            $content = $response->streamedContent();
            $this->assertNotEmpty($content);

            $tempPath = tempnam(sys_get_temp_dir(), 'enr_tmpl_').'.xlsx';
            file_put_contents($tempPath, $content);
            $spreadsheet = IOFactory::load($tempPath);
            $sheet = $spreadsheet->getActiveSheet();

            $this->assertSame('full_name', $sheet->getCell('A1')->getValue());
            $this->assertEmpty($sheet->getCell('B1')->getValue());

            $spreadsheet->disconnectWorksheets();
            @unlink($tempPath);
        }
    }

    public function test_template_download_competency_tags(): void
    {
        $this->actAsAdmin();
        $response = $this->get('/api/admin/import/templates/competency_tags');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_template_download_invalid_type(): void
    {
        $this->actAsAdmin();
        $this->get('/api/admin/import/templates/invalid_type')
            ->assertStatus(422);
    }

    public function test_template_download_requires_admin(): void
    {
        // Unauthenticated.
        $this->get('/api/admin/import/templates/student_enrollment')->assertStatus(401);

        // Teacher cannot download (only Admin).
        $teacher = User::factory()->teacher()->create(['must_change_password' => false]);
        Sanctum::actingAs($teacher);
        $this->get('/api/admin/import/templates/student_enrollment')->assertStatus(403);
    }

    /**
     * Hard-delete pins: POST /api/admin/import/student-enrollments and POST
     * /api/admin/import/students/csv were removed with the legacy enrollment
     * path. Both 404 with NOT_FOUND (authenticated or not — routing runs
     * before auth).
     */
    public function test_deleted_student_enrollment_import_routes_return_404(): void
    {
        $this->actAsAdmin();

        $xlsx = $this->makeXlsx([['school_id', 'section_id'], ['STU-1', '1']]);
        $this->post('/api/admin/import/student-enrollments', ['file' => $xlsx])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $csv = $this->makeCsv([['school_id', 'section_id'], ['STU-1', '1']]);
        $this->post('/api/admin/import/students/csv', ['file' => $csv])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->assertSame(0, ImportJob::count());
        $this->assertSame(0, ImportLog::count());
    }

    public function test_import_competency_tags_partial_success(): void
    {
        $this->actAsAdmin();

        $subject7 = $this->makeScopedSubject('MTH1-7', '7', 'Mathematics');
        $subject8 = $this->makeScopedSubject('MTH1-8', '8', 'Mathematics');

        // Valid existing code (should fail — duplicate).
        CompetencyReference::create(['semester' => '1', 'code' => 'M7NS-Ia-1', 'descriptor' => 'Existing', 'subject_id' => $subject7->id, 'grade_level' => '7',
        ]);

        $rows = [
            ['code', 'descriptor', 'subject_id', 'grade_level', 'semester'],
            ['M7NS-Ia-2', 'Grade 7 Math Number Sense', $subject7->id, 7, 1],
            ['M7NS-Ia-1', 'Duplicate code', $subject7->id, 7, 1],
            ['M8AL-Px-5', 'Grade 8 Algebra', $subject8->id, 8, 1],
            ['M9GM-Ge-1', 'Grade 9 Geometry', 99999, 9, 1],
        ];

        $xlsx = $this->makeXlsx($rows);
        $response = $this->post('/api/admin/import/competency-tags', [
            'file' => $xlsx,
        ])->assertOk()->json('data');

        $this->assertSame(2, $response['imported_rows']);
        $this->assertSame(2, $response['failed_rows']);
        $this->assertTrue($response['has_error_report']);

        $this->assertDatabaseHas('competency_reference', ['code' => 'M7NS-Ia-2', 'grade_level' => '7']);
        $this->assertDatabaseHas('competency_reference', ['code' => 'M8AL-Px-5', 'grade_level' => '8']);
        $this->assertDatabaseMissing('competency_reference', ['code' => 'M9GM-Ge-1']);
    }

    public function test_import_competency_tags_invalid_grade_level(): void
    {
        $this->actAsAdmin();
        $subject7 = $this->makeScopedSubject('SCI1-7', '7', 'Science');
        $subject10 = $this->makeScopedSubject('SCI1-10', '10', 'Science');

        $rows = [
            ['code', 'descriptor', 'subject_id', 'grade_level', 'semester'],
            ['S7FE-Px-1', 'Grade 7', $subject7->id, 7, 1],
            ['S10FE-x-1', 'Grade 10 in scope', $subject10->id, 10, 1],
            ['S6BAD-x-1', 'Grade 6 out of scope', $subject7->id, 6, 1],
            ['S13BAD-x-1', 'Grade 13 out of scope', $subject7->id, 13, 1],
            ['S7ABC-x-1', 'Non-numeric grade', $subject7->id, '7abc', 1],
            ['S75-x-1', 'Decimal grade', $subject7->id, '7.5', 1],
            ['SEMPTY-x-1', 'Empty grade', $subject7->id, '', 1],
        ];

        $xlsx = $this->makeXlsx($rows);
        $response = $this->post('/api/admin/import/competency-tags', [
            'file' => $xlsx,
        ])->assertOk()->json('data');

        // Grades 7–12 all import (ARCH-004 §10, Requirements Revision 2).
        $this->assertSame(2, $response['imported_rows']);
        $this->assertSame(5, $response['failed_rows']);
        $this->assertTrue($response['has_error_report']);

        $this->assertDatabaseHas('competency_reference', ['code' => 'S7FE-Px-1', 'grade_level' => '7']);
        $this->assertDatabaseHas('competency_reference', ['code' => 'S10FE-x-1', 'grade_level' => '10']);
        $this->assertDatabaseMissing('competency_reference', ['code' => 'S6BAD-x-1']);
        $this->assertDatabaseMissing('competency_reference', ['code' => 'S13BAD-x-1']);
        $this->assertDatabaseMissing('competency_reference', ['code' => 'S7ABC-x-1']);
        $this->assertDatabaseMissing('competency_reference', ['code' => 'S75-x-1']);
        $this->assertDatabaseMissing('competency_reference', ['code' => 'SEMPTY-x-1']);

        // Out-of-range rows land in the error report with a reason naming
        // the valid Grades 7–12 range.
        $token = basename($response['error_report_url']);
        $report = $this->readErrorReportFromStorage($token);
        $sheet = $report->getActiveSheet();

        $this->assertSame(3, $sheet->getCell('A2')->getValue());
        $this->assertSame('S6BAD-x-1', $sheet->getCell('B2')->getValue());
        $this->assertSame('Invalid grade_level: 6. Must be 7 through 12.', $sheet->getCell('G2')->getValue());

        $this->assertSame(4, $sheet->getCell('A3')->getValue());
        $this->assertSame('S13BAD-x-1', $sheet->getCell('B3')->getValue());
        $this->assertSame('Invalid grade_level: 13. Must be 7 through 12.', $sheet->getCell('G3')->getValue());

        // Malformed cells must be reported, never truncated to their leading digits.
        $this->assertSame(5, $sheet->getCell('A4')->getValue());
        $this->assertSame('S7ABC-x-1', $sheet->getCell('B4')->getValue());
        $this->assertSame('Invalid grade_level: 7abc. Must be a whole number between 7 and 12.', $sheet->getCell('G4')->getValue());

        $this->assertSame(6, $sheet->getCell('A5')->getValue());
        $this->assertSame('S75-x-1', $sheet->getCell('B5')->getValue());
        $this->assertSame('Invalid grade_level: 7.5. Must be a whole number between 7 and 12.', $sheet->getCell('G5')->getValue());

        $this->assertSame(7, $sheet->getCell('A6')->getValue());
        $this->assertSame('SEMPTY-x-1', $sheet->getCell('B6')->getValue());
        $this->assertSame('Missing grade_level.', $sheet->getCell('G6')->getValue());
        $report->disconnectWorksheets();
    }

    public function test_competency_tags_import_rejects_binder_coercion_grade_shapes(): void
    {
        // AUD-010: hostile files can carry '+7', '7.0', and whitespace-padded
        // grades as literal string cells (the default value binder would coerce
        // them on write; a hand-crafted xlsx bypasses that). The guard must
        // reject every non-ctype-digit shape with the malformed-cell reason and
        // persist nothing — a loosened check (e.g. is_numeric) would silently
        // re-import them truncated to their leading digits.
        $this->actAsAdmin();
        $subject = $this->makeScopedSubject('SCI1', '7');

        $rows = [
            ['code', 'descriptor', 'subject_id', 'grade_level', 'semester'],
            ['SPLUS-x-1', 'Plus-signed grade', $subject->id, '+7'],
            ['SDOTZERO-x-1', 'Trailing-zero decimal grade', $subject->id, '7.0'],
            ['SWSPAD-x-1', 'Whitespace-padded junk grade', $subject->id, ' 7abc', 1],
        ];

        $xlsx = $this->makeXlsxPreservingStrings($rows);
        $response = $this->post('/api/admin/import/competency-tags', [
            'file' => $xlsx,
        ])->assertOk()->json('data');

        $this->assertSame(0, $response['imported_rows']);
        $this->assertSame(3, $response['failed_rows']);
        $this->assertTrue($response['has_error_report']);
        $this->assertSame(0, CompetencyReference::count());
        $this->assertDatabaseMissing('competency_reference', ['code' => 'SPLUS-x-1']);
        $this->assertDatabaseMissing('competency_reference', ['code' => 'SDOTZERO-x-1']);
        $this->assertDatabaseMissing('competency_reference', ['code' => 'SWSPAD-x-1']);

        // Each shape must be reported verbatim (whitespace form after the
        // service's own trim), never truncated to its leading digits.
        $token = basename($response['error_report_url']);
        $report = $this->readErrorReportFromStorage($token);
        $sheet = $report->getActiveSheet();

        $this->assertSame(1, $sheet->getCell('A2')->getValue());
        $this->assertSame('SPLUS-x-1', $sheet->getCell('B2')->getValue());
        $this->assertSame('Invalid grade_level: +7. Must be a whole number between 7 and 12.', $sheet->getCell('G2')->getValue());

        $this->assertSame(2, $sheet->getCell('A3')->getValue());
        $this->assertSame('SDOTZERO-x-1', $sheet->getCell('B3')->getValue());
        $this->assertSame('Invalid grade_level: 7.0. Must be a whole number between 7 and 12.', $sheet->getCell('G3')->getValue());

        $this->assertSame(3, $sheet->getCell('A4')->getValue());
        $this->assertSame('SWSPAD-x-1', $sheet->getCell('B4')->getValue());
        $this->assertSame('Invalid grade_level: 7abc. Must be a whole number between 7 and 12.', $sheet->getCell('G4')->getValue());
        $report->disconnectWorksheets();
    }

    public function test_competency_tags_import_accepts_leading_zero_grade_normalized(): void
    {
        // AUD-003 disposition: a leading-zero grade cell ('07') is benign —
        // ctype_digit accepts it, it lands in range, and it is stored
        // normalized as '7'. Pinned so the normalization stays deliberate.
        $this->actAsAdmin();

        $subject = $this->makeScopedSubject('CSM-ZERO', '7');

        $rows = [
            ['code', 'descriptor', 'subject_id', 'grade_level', 'semester'],
            ['MZ07-x-1', 'Leading-zero grade', $subject->id, '07', 1],
        ];

        $response = $this->post('/api/admin/import/competency-tags', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');

        $this->assertSame(1, $response['imported_rows']);
        $this->assertSame(0, $response['failed_rows']);
        $this->assertFalse($response['has_error_report']);

        $this->assertDatabaseHas('competency_reference', ['code' => 'MZ07-x-1', 'grade_level' => '7']);
    }

    public function test_error_report_is_single_use(): void
    {
        // Error report generated via the surviving competency-tags import.
        $this->actAsAdmin();

        $subject = $this->makeScopedSubject('SCI-SU', '7');
        CompetencyReference::create(['semester' => '1', 'code' => 'EXISTING-SU', 'descriptor' => 'Existing',
            'subject_id' => $subject->id, 'grade_level' => '7',
        ]);

        $rows = [
            ['code', 'descriptor', 'subject_id', 'grade_level', 'semester'],
            ['NEW-SU-1', 'New entry', $subject->id, 7, 1],
            ['EXISTING-SU', 'Duplicate existing', $subject->id, 7, 1],
        ];

        $xlsx = $this->makeXlsx($rows);
        $response = $this->post('/api/admin/import/competency-tags', [
            'file' => $xlsx,
        ])->assertOk()->json('data');

        $this->assertTrue($response['has_error_report']);
        $url = $response['error_report_url'];
        $this->assertNotNull($url);
        $token = basename($url);

        // First download should succeed.
        $this->get('/api/admin/import/error-reports/'.$token)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // Second download should fail — report consumed (ARCH-004 §8.1, ARCH-002 FR-031): 410.
        $this->get('/api/admin/import/error-reports/'.$token)
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'GONE');
    }

    public function test_import_rejects_non_xlsx_file(): void
    {
        $this->actAsAdmin();

        $txtFile = new UploadedFile(
            tempnam(sys_get_temp_dir(), 'test_'),
            'test.txt',
            'text/plain',
            null,
            true
        );

        $this->post('/api/admin/import/competency-tags', ['file' => $txtFile])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_import_requires_authentication(): void
    {
        $this->post('/api/admin/import/competency-tags')
            ->assertStatus(401);
        $this->get('/api/admin/import/templates/competency_tags')
            ->assertStatus(401);
    }

    public function test_import_endpoints_forbidden_for_teacher(): void
    {
        $teacher = User::factory()->teacher()->create(['must_change_password' => false]);
        Sanctum::actingAs($teacher);

        $this->get('/api/admin/import/templates/competency_tags')->assertStatus(403);
        $this->post('/api/admin/import/competency-tags')->assertStatus(403);
        $this->get('/api/admin/import/error-reports/some-token')->assertStatus(403);
    }

    public function test_competency_reference_table_structure(): void
    {
        $subject = $this->makeScopedSubject('MATH7', '7');
        CompetencyReference::create(['semester' => '1', 'code' => 'M7NS-Ia-1',
            'descriptor' => 'Describes the competency',
            'subject_id' => $subject->id,
            'grade_level' => '7',
        ]);

        $this->assertDatabaseHas('competency_reference', [
            'code' => 'M7NS-Ia-1',
            'descriptor' => 'Describes the competency',
            'grade_level' => '7',
        ]);
    }

    private function readErrorReportFromStorage(string $token): Spreadsheet
    {
        $content = Storage::disk('local')->get(BatchImportService::ERROR_REPORT_DIR.'/'.$token.'.xlsx');
        $tempPath = tempnam(sys_get_temp_dir(), 'err_').'.xlsx';
        file_put_contents($tempPath, $content);
        $spreadsheet = IOFactory::load($tempPath);
        @unlink($tempPath);

        return $spreadsheet;
    }

    public function test_xlsx_import_rejects_file_over_15mb(): void
    {
        $this->actAsAdmin();

        $largeFile = UploadedFile::fake()->create(
            'test.xlsx',
            16000,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );

        $this->post('/api/admin/import/competency-tags', ['file' => $largeFile])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertSame(0, ImportJob::count());
    }

    public function test_oversized_upload_rejected_422(): void
    {
        $this->actAsAdmin();

        $largeFile = UploadedFile::fake()->create(
            'bulk.xlsx',
            15361,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );

        // The faked file must actually exceed the 15 MB cap in bytes
        // (15361 KB = 15,729,664 B > 15,728,640 B), not just its declared size.
        $this->assertGreaterThan(BatchImportService::MAX_FILE_SIZE, $largeFile->getSize());

        $this->post('/api/admin/import/competency-tags', ['file' => $largeFile])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertSame(0, ImportJob::count());
    }

    public function test_service_rejects_oversized_file_with_file_too_large(): void
    {
        $admin = $this->actAsAdmin();
        $service = app(BatchImportService::class);
        $largeFile = UploadedFile::fake()->create('bulk.xlsx', 15361, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        try {
            $service->importCompetencyTags($largeFile, $admin->id);
            $this->fail('Expected BusinessRuleConflictException for an oversized file.');
        } catch (BusinessRuleConflictException $exception) {
            $this->assertSame('FILE_TOO_LARGE', $exception->errorCode);
        }

        $this->assertSame(0, ImportJob::count());
    }

    public function test_xlsx_import_missing_file_field(): void
    {
        $this->actAsAdmin();

        $this->post('/api/admin/import/competency-tags')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_xlsx_endpoint_rejects_csv_file(): void
    {
        $this->actAsAdmin();

        $csv = $this->makeCsv([['code', 'descriptor', 'subject_id', 'grade_level', 'semester'], ['X-1', 'd', '1', '7', '1']]);

        $this->post('/api/admin/import/competency-tags', ['file' => $csv])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_template_download_competency_tags_content_disposition(): void
    {
        $this->actAsAdmin();

        $response = $this->get('/api/admin/import/templates/competency_tags');
        $response->assertOk();
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('import_template_competency_tags.xlsx', $disposition);
    }

    public function test_template_download_http_content_is_valid_xlsx(): void
    {
        $this->actAsAdmin();

        $response = $this->get('/api/admin/import/templates/competency_tags')->assertOk();

        $content = $response->streamedContent();

        $this->assertNotEmpty($content);
        $this->assertSame('PK', substr($content, 0, 2));

        $tempPath = tempnam(sys_get_temp_dir(), 'http_tmpl_').'.xlsx';
        file_put_contents($tempPath, $content);
        $spreadsheet = IOFactory::load($tempPath);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('code', $sheet->getCell('A1')->getValue());
        $this->assertSame('descriptor', $sheet->getCell('B1')->getValue());
        $this->assertSame('subject_id', $sheet->getCell('C1')->getValue());
        $this->assertSame('grade_level', $sheet->getCell('D1')->getValue());

        $spreadsheet->disconnectWorksheets();
        @unlink($tempPath);
    }

    public function test_download_template_supports_student_enrollment_types(): void
    {
        $this->actAsAdmin();

        $service = app(BatchImportService::class);

        foreach (['student_enrollment', 'student_enrollments', 'student-enrollments', 'teacher_application', 'teacher_applications', 'teacher-applications'] as $slug) {
            $path = $service->downloadTemplate($slug);
            $spreadsheet = IOFactory::load($path);
            $sheet = $spreadsheet->getActiveSheet();

            $this->assertSame('full_name', $sheet->getCell('A1')->getValue());
            $this->assertEmpty($sheet->getCell('B1')->getValue());

            $spreadsheet->disconnectWorksheets();
            @unlink($path);
        }

        try {
            $service->downloadTemplate('not_a_template');
            $this->fail('Expected BusinessRuleConflictException for an unknown template type.');
        } catch (BusinessRuleConflictException $exception) {
            $this->assertSame('INVALID_TEMPLATE_TYPE', $exception->errorCode);
        }
    }

    public function test_template_download_competency_tags_columns(): void
    {
        $this->actAsAdmin();

        $service = app(BatchImportService::class);
        $path = $service->downloadTemplate(BatchImportService::TYPE_COMPETENCY_TAGS);

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('code', $sheet->getCell('A1')->getValue());
        $this->assertSame('descriptor', $sheet->getCell('B1')->getValue());
        $this->assertSame('subject_id', $sheet->getCell('C1')->getValue());
        $this->assertSame('grade_level', $sheet->getCell('D1')->getValue());

        $spreadsheet->disconnectWorksheets();
        @unlink($path);
    }

    public function test_competency_tags_import_all_valid(): void
    {
        $this->actAsAdmin();

        $subject7 = $this->makeScopedSubject('CSM-ALL-7', '7');
        $subject8 = $this->makeScopedSubject('CSM-ALL-8', '8');

        $rows = [
            ['code', 'descriptor', 'subject_id', 'grade_level', 'semester'],
            ['M7NS-Ia-2', 'Grade 7 Math Number Sense', $subject7->id, 7, 1],
            ['M8AL-Px-5', 'Grade 8 Algebra', $subject8->id, 8, 1],
        ];

        $response = $this->post('/api/admin/import/competency-tags', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');

        $this->assertSame(2, $response['imported_rows']);
        $this->assertSame(0, $response['failed_rows']);
        $this->assertFalse($response['has_error_report']);
        $this->assertNull($response['error_report_url']);

        $this->assertDatabaseHas('competency_reference', ['code' => 'M7NS-Ia-2', 'grade_level' => '7']);
        $this->assertDatabaseHas('competency_reference', ['code' => 'M8AL-Px-5', 'grade_level' => '8']);
    }

    public function test_competency_tags_import_empty_file(): void
    {
        $this->actAsAdmin();

        $rows = [
            ['code', 'descriptor', 'subject_id', 'grade_level', 'semester'],
        ];

        $response = $this->post('/api/admin/import/competency-tags', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');

        $this->assertSame(0, $response['imported_rows']);
        $this->assertSame(0, $response['failed_rows']);
        $this->assertFalse($response['has_error_report']);
    }

    public function test_competency_tags_import_missing_code(): void
    {
        $this->actAsAdmin();

        $subject = $this->makeScopedSubject('C-MISS-C', '7');

        $rows = [
            ['code', 'descriptor', 'subject_id', 'grade_level', 'semester'],
            ['', 'Missing code test', $subject->id, 7, 1],
        ];

        $response = $this->post('/api/admin/import/competency-tags', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');

        $this->assertSame(0, $response['imported_rows']);
        $this->assertSame(1, $response['failed_rows']);
        $this->assertDatabaseMissing('competency_reference', ['descriptor' => 'Missing code test']);
    }

    public function test_competency_tags_import_missing_subject_id(): void
    {
        $this->actAsAdmin();

        $subject = $this->makeScopedSubject('C-MISS-S', '7');

        $rows = [
            ['code', 'descriptor', 'subject_id', 'grade_level', 'semester'],
            ['CODE-001', 'Missing subject', '', 7],
        ];

        $response = $this->post('/api/admin/import/competency-tags', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');

        $this->assertSame(0, $response['imported_rows']);
        $this->assertSame(1, $response['failed_rows']);
        $this->assertDatabaseMissing('competency_reference', ['code' => 'CODE-001']);
    }

    public function test_competency_tags_import_duplicate_within_batch(): void
    {
        $this->actAsAdmin();

        $subject = $this->makeScopedSubject('C-DUP-BATCH', '7');

        $rows = [
            ['code', 'descriptor', 'subject_id', 'grade_level', 'semester'],
            ['DUP-001', 'First entry', $subject->id, 7, 1],
            ['DUP-001', 'Duplicate entry', $subject->id, 7, 1],
        ];

        $response = $this->post('/api/admin/import/competency-tags', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');

        $this->assertSame(1, $response['imported_rows']);
        $this->assertSame(1, $response['failed_rows']);
        $this->assertSame('DUP-001', $this->app['db']->table('competency_reference')->where('code', 'DUP-001')->first()->code);
    }

    public function test_competency_tags_import_nonexistent_subject(): void
    {
        $this->actAsAdmin();

        $rows = [
            ['code', 'descriptor', 'subject_id', 'grade_level', 'semester'],
            ['CODE-X', 'Nonexistent subject', '99999', 7],
        ];

        $response = $this->post('/api/admin/import/competency-tags', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');

        $this->assertSame(0, $response['imported_rows']);
        $this->assertSame(1, $response['failed_rows']);
        $this->assertDatabaseMissing('competency_reference', ['code' => 'CODE-X']);
    }

    public function test_competency_import_job_for_import_type(): void
    {
        $this->actAsAdmin();

        $subject = $this->makeScopedSubject('C-TYPE', '7');

        $rows = [
            ['code', 'descriptor', 'subject_id', 'grade_level', 'semester'],
            ['SCI-001', 'Science entry', $subject->id, 7, 1],
        ];

        $this->post('/api/admin/import/competency-tags', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk();

        $this->assertDatabaseHas('import_jobs', [
            'import_type' => 'competency_tags',
            'total_rows' => 1,
            'imported_rows' => 1,
            'failed_rows' => 0,
        ]);
    }

    public function test_competency_import_job_error_report_path_set(): void
    {
        $this->actAsAdmin();

        $subject = $this->makeScopedSubject('C-JOB-ERR', '7');
        CompetencyReference::create(['semester' => '1', 'code' => 'EXISTING-001', 'descriptor' => 'Existing',
            'subject_id' => $subject->id, 'grade_level' => '7',
        ]);

        $rows = [
            ['code', 'descriptor', 'subject_id', 'grade_level', 'semester'],
            ['NEW-001', 'New entry', $subject->id, 7, 1],
            ['EXISTING-001', 'Duplicate existing', $subject->id, 7, 1],
        ];

        $response = $this->post('/api/admin/import/competency-tags', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');

        $this->assertTrue($response['has_error_report']);

        $job = ImportJob::first();
        $this->assertNotNull($job->error_report_path);
        $this->assertStringContainsString('import_errors/', $job->error_report_path);
        $this->assertSame('competency_tags', $job->import_type);
    }

    public function test_error_report_invalid_token_returns_410(): void
    {
        $this->actAsAdmin();

        $this->get('/api/admin/import/error-reports/nonexistent-token-123')
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'GONE');
    }

    public function test_error_report_requires_authentication(): void
    {
        $this->get('/api/admin/import/error-reports/some-token')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_error_report_forbidden_for_teacher(): void
    {
        $teacher = User::factory()->teacher()->create([
            'must_change_password' => false,
        ]);
        Sanctum::actingAs($teacher);

        $this->get('/api/admin/import/error-reports/some-token')
            ->assertStatus(403);
    }

    public function test_error_report_forbidden_for_student(): void
    {
        $this->actAsAdmin();

        $subject = $this->makeScopedSubject('C-STU403', '7');
        CompetencyReference::create(['semester' => '1', 'code' => 'EXISTING-STU403', 'descriptor' => 'Existing',
            'subject_id' => $subject->id, 'grade_level' => '7',
        ]);

        $rows = [
            ['code', 'descriptor', 'subject_id', 'grade_level', 'semester'],
            ['NEW-STU403', 'New entry', $subject->id, 7, 1],
            ['EXISTING-STU403', 'Duplicate existing', $subject->id, 7, 1],
        ];
        $response = $this->post('/api/admin/import/competency-tags', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');
        $this->assertTrue($response['has_error_report']);

        $token = basename($response['error_report_url']);

        $student = User::factory()->student()->create(['must_change_password' => false]);
        Sanctum::actingAs($student);

        $this->get('/api/admin/import/error-reports/'.$token)
            ->assertStatus(403);
    }

    public function test_error_report_contains_failed_rows_and_reasons(): void
    {
        $this->actAsAdmin();

        $subject = $this->makeScopedSubject('C-ERR-CT', '7');
        CompetencyReference::create(['semester' => '1', 'code' => 'EXISTING-CT', 'descriptor' => 'Existing',
            'subject_id' => $subject->id, 'grade_level' => '7',
        ]);

        $rows = [
            ['code', 'descriptor', 'subject_id', 'grade_level', 'semester'],
            ['NEW-CT-1', 'New entry', $subject->id, 7, 1],
            ['EXISTING-CT', 'Duplicate existing', $subject->id, 7, 1],
        ];

        $response = $this->post('/api/admin/import/competency-tags', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');

        $this->assertTrue($response['has_error_report']);

        $token = basename($response['error_report_url']);
        $report = $this->readErrorReportFromStorage($token);
        $sheet = $report->getActiveSheet();

        $this->assertSame('Row', $sheet->getCell('A1')->getValue());
        $this->assertSame('code', $sheet->getCell('B1')->getValue());
        $this->assertSame('descriptor', $sheet->getCell('C1')->getValue());
        $this->assertSame('subject_id', $sheet->getCell('D1')->getValue());
        $this->assertSame('grade_level', $sheet->getCell('E1')->getValue());
        $this->assertSame('semester', $sheet->getCell('F1')->getValue());
        $this->assertSame('Reason', $sheet->getCell('G1')->getValue());

        $this->assertSame(2, $sheet->getCell('A2')->getValue());
        $this->assertSame('EXISTING-CT', $sheet->getCell('B2')->getValue());
        $this->assertStringContainsString('already exists', $sheet->getCell('G2')->getValue());
        $report->disconnectWorksheets();
    }

    public function test_error_report_competency_tags_content(): void
    {
        $this->actAsAdmin();

        $subject = $this->makeScopedSubject('C-ERR-REP', '7');
        CompetencyReference::create(['semester' => '1', 'code' => 'EXISTING-002', 'descriptor' => 'Existing',
            'subject_id' => $subject->id, 'grade_level' => '7',
        ]);

        $rows = [
            ['code', 'descriptor', 'subject_id', 'grade_level', 'semester'],
            ['NEW-002', 'New entry', $subject->id, 7, 1],
            ['EXISTING-002', 'Duplicate existing', $subject->id, 7, 1],
        ];

        $response = $this->post('/api/admin/import/competency-tags', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');

        $this->assertTrue($response['has_error_report']);

        $token = basename($response['error_report_url']);
        $report = $this->readErrorReportFromStorage($token);
        $sheet = $report->getActiveSheet();

        $this->assertSame('Row', $sheet->getCell('A1')->getValue());
        $this->assertSame('code', $sheet->getCell('B1')->getValue());
        $this->assertSame('descriptor', $sheet->getCell('C1')->getValue());
        $this->assertSame('subject_id', $sheet->getCell('D1')->getValue());
        $this->assertSame('grade_level', $sheet->getCell('E1')->getValue());
        $this->assertSame('semester', $sheet->getCell('F1')->getValue());
        $this->assertSame('Reason', $sheet->getCell('G1')->getValue());

        $this->assertSame(2, $sheet->getCell('A2')->getValue());
        $this->assertSame('EXISTING-002', $sheet->getCell('B2')->getValue());
        $this->assertStringContainsString('already exists', $sheet->getCell('G2')->getValue());
        $report->disconnectWorksheets();
    }

    public function test_error_report_content_disposition_header(): void
    {
        $this->actAsAdmin();

        $subject = $this->makeScopedSubject('C-DISP', '7');
        CompetencyReference::create(['semester' => '1', 'code' => 'EXISTING-DISP', 'descriptor' => 'Existing',
            'subject_id' => $subject->id, 'grade_level' => '7',
        ]);

        $rows = [
            ['code', 'descriptor', 'subject_id', 'grade_level', 'semester'],
            ['NEW-DISP', 'New entry', $subject->id, 7, 1],
            ['EXISTING-DISP', 'Duplicate existing', $subject->id, 7, 1],
        ];

        $response = $this->post('/api/admin/import/competency-tags', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');

        $url = $response['error_report_url'];

        $downloadResponse = $this->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $disposition = $downloadResponse->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('import_errors.xlsx', $disposition);
    }

    public function test_password_change_required_blocks_phase2_endpoints(): void
    {
        $user = User::factory()->create([
            'role' => 'Admin',
            'must_change_password' => true,
        ]);
        Sanctum::actingAs($user);

        $this->get('/api/admin/import/templates/competency_tags')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PASSWORD_CHANGE_REQUIRED');

        $this->post('/api/admin/import/competency-tags')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PASSWORD_CHANGE_REQUIRED');

        $this->get('/api/admin/import/error-reports/test-token')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PASSWORD_CHANGE_REQUIRED');
    }

    public function test_phase2_endpoints_forbidden_for_student(): void
    {
        Sanctum::actingAs(User::factory()->student()->create([
            'must_change_password' => false,
        ]));

        $this->get('/api/admin/import/templates/competency_tags')->assertStatus(403);
        $this->post('/api/admin/import/competency-tags')->assertStatus(403);
        $this->get('/api/admin/import/error-reports/test')->assertStatus(403);
    }

    public function test_competency_reference_rejects_invalid_grade_level_enum(): void
    {
        $this->expectException(QueryException::class);

        $subject = $this->makeScopedSubject('C-ENUM', '7');

        CompetencyReference::create(['semester' => '1', 'code' => 'BAD-GRADE',
            'descriptor' => 'Should fail at DB enum',
            'subject_id' => $subject->id,
            'grade_level' => '13',
        ]);
    }

    // ========================================================================
    // Phase 2 RIDERS batch (WU-C) — ARCH-002 FR-031 error-report single-use contract
    // ========================================================================

    public function test_error_report_download_single_use_410(): void
    {
        // ARCH-002 FR-031 / ARCH-002 FR-031: an error report is single-use. Reuse, unknown
        // tokens, and expired tokens all yield 410 GONE (BASELINE v1.2 §4.3.2).
        // Report generated via the surviving competency-tags import.
        $admin = $this->actAsAdmin();

        $subject = $this->makeScopedSubject('SCI-410', '7');
        CompetencyReference::create(['semester' => '1', 'code' => 'EXISTING-410', 'descriptor' => 'Existing',
            'subject_id' => $subject->id, 'grade_level' => '7',
        ]);

        $rows = [
            ['code', 'descriptor', 'subject_id', 'grade_level', 'semester'],
            ['NEW-410', 'New entry', $subject->id, 7, 1],
            ['EXISTING-410', 'Duplicate existing', $subject->id, 7, 1],
        ];

        $response = $this->post('/api/admin/import/competency-tags', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');

        $this->assertTrue($response['has_error_report']);
        $url = $response['error_report_url'];
        $this->assertNotNull($url);
        $token = basename($url);

        // First download succeeds and consumes the report.
        $this->get('/api/admin/import/error-reports/'.$token)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // Reuse of the same token → 410 GONE (was 404; superseded per WU-C).
        $this->get('/api/admin/import/error-reports/'.$token)
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'GONE');

        // Unknown token → 410 GONE.
        $this->get('/api/admin/import/error-reports/never-issued-token')
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'GONE');

        // Expired token (TTL elapsed) → 410 GONE.
        ImportJob::create([
            'import_type' => 'competency_tags',
            'admin_id' => $admin->id,
            'total_rows' => 1,
            'imported_rows' => 0,
            'failed_rows' => 1,
            'error_report_path' => BatchImportService::ERROR_REPORT_DIR.'/expired.xlsx',
            'error_report_token' => hash('sha256', 'expired-token'),
            'error_report_expires_at' => now()->subDay(),
        ]);
        $this->get('/api/admin/import/error-reports/expired-token')
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'GONE');
    }

    // ========================================================================
    // Unit U6 (fixes AUD-013) — scheduled cleanup of expired error reports
    // Requirements §8.2: reports are kept only temporarily (~7 days) and
    // then cleaned up automatically; the TTL previously gated downloads only.
    // ========================================================================

    /**
     * Helper: create an import job row pointing at a report file on the local
     * disk. Returns the created ImportJob.
     */
    private function makeErrorReportJob(User $admin, string $token, string $filename, $expiresAt): ImportJob
    {
        $path = BatchImportService::ERROR_REPORT_DIR.'/'.$filename;
        Storage::disk('local')->put($path, 'error-report-content:'.$token);

        return ImportJob::create([
            'import_type' => BatchImportService::TYPE_COMPETENCY_TAGS,
            'admin_id' => $admin->id,
            'total_rows' => 1,
            'imported_rows' => 0,
            'failed_rows' => 1,
            'error_report_path' => $path,
            'error_report_token' => hash('sha256', $token),
            'error_report_expires_at' => $expiresAt,
        ]);
    }

    public function test_cleanup_expired_error_reports_deletes_file_and_clears_columns(): void
    {
        $admin = $this->actAsAdmin();
        $service = app(BatchImportService::class);
        $disk = Storage::disk('local');

        // Expired: file present, columns set — the one job the cleanup must act on.
        $expiredJob = $this->makeErrorReportJob(
            $admin,
            'cleanup-expired-token',
            'cleanup-expired.xlsx',
            now()->subDay()
        );
        // Unexpired: still inside the 7-day TTL — must be untouched.
        $this->makeErrorReportJob(
            $admin,
            'cleanup-fresh-token',
            'cleanup-fresh.xlsx',
            now()->addDays(BatchImportService::ERROR_REPORT_TTL_DAYS)
        );

        // Unrelated storage content (inside and outside ERROR_REPORT_DIR) — untouched.
        $disk->put(BatchImportService::ERROR_REPORT_DIR.'/cleanup-unrelated.txt', 'keep me');
        $outsideDir = 'cleanup-outside/keep-me.txt';
        $disk->put($outsideDir, 'keep me too');

        $this->assertSame(1, $service->cleanupExpiredErrorReports());

        $this->assertFalse($disk->exists(BatchImportService::ERROR_REPORT_DIR.'/cleanup-expired.xlsx'));
        $this->assertNull($expiredJob->fresh()->error_report_path);
        $this->assertNull($expiredJob->fresh()->error_report_token);

        $fresh = ImportJob::where('error_report_token', hash('sha256', 'cleanup-fresh-token'))->first();
        $this->assertNotNull($fresh);
        $this->assertNotNull($fresh->error_report_path);
        $this->assertTrue($disk->exists($fresh->error_report_path));

        $this->assertTrue($disk->exists(BatchImportService::ERROR_REPORT_DIR.'/cleanup-unrelated.txt'));
        $this->assertTrue($disk->exists($outsideDir));

        $disk->delete([
            BatchImportService::ERROR_REPORT_DIR.'/cleanup-fresh.xlsx',
            BatchImportService::ERROR_REPORT_DIR.'/cleanup-unrelated.txt',
            $outsideDir,
        ]);
    }

    public function test_cleanup_expired_error_reports_is_idempotent(): void
    {
        $admin = $this->actAsAdmin();
        $service = app(BatchImportService::class);
        $disk = Storage::disk('local');

        $this->makeErrorReportJob(
            $admin,
            'cleanup-idem-token',
            'cleanup-idem.xlsx',
            now()->subHour()
        );

        $this->assertSame(1, $service->cleanupExpiredErrorReports());
        $this->assertFalse($disk->exists(BatchImportService::ERROR_REPORT_DIR.'/cleanup-idem.xlsx'));

        // Columns were cleared on the first pass, so the second pass finds
        // nothing to revisit and raises no errors.
        $this->assertSame(0, $service->cleanupExpiredErrorReports());
        $this->assertSame(0, ImportJob::whereNotNull('error_report_path')->count());
    }

    public function test_download_after_cleanup_yields_410_gone(): void
    {
        $admin = $this->actAsAdmin();

        $this->makeErrorReportJob(
            $admin,
            'cleanup-gone-token',
            'cleanup-gone.xlsx',
            now()->subHour()
        );

        $this->assertSame(1, app(BatchImportService::class)->cleanupExpiredErrorReports());
        $this->assertFalse(
            Storage::disk('local')->exists(BatchImportService::ERROR_REPORT_DIR.'/cleanup-gone.xlsx')
        );

        // The existing download contract is intact: expired/cleaned reports
        // yield 410 GONE (ARCH-002 FR-031 / ARCH-002 FR-031).
        $this->get('/api/admin/import/error-reports/cleanup-gone-token')
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'GONE');
    }

    public function test_error_report_download_rejects_other_admin_with_403_and_preserves_token(): void
    {
        // Owner check precedes consume: admin B downloading admin A's error
        // report yields 403 FORBIDDEN (AuthorizationException, same convention
        // as the preview confirm) with the single-use token unconsumed, so
        // admin A can still download successfully afterwards.
        $adminA = $this->actAsAdmin();
        $adminB = User::factory()->create([
            'role' => 'Admin',
            'must_change_password' => false,
        ]);

        $token = 'owner-check-token-a1b2c3d4e5f60718293';
        $job = $this->makeErrorReportJob(
            $adminA,
            $token,
            'owner-check.xlsx',
            now()->addDay()
        );

        Sanctum::actingAs($adminB);
        $this->get('/api/admin/import/error-reports/'.$token)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        // Token unconsumed: hash retained, used_at still null, file intact.
        $fresh = $job->fresh();
        $this->assertSame(hash('sha256', $token), $fresh->error_report_token);
        $this->assertNull($fresh->error_report_used_at);
        $this->assertTrue(Storage::disk('local')->exists($fresh->error_report_path));

        Sanctum::actingAs($adminA);
        $this->get('/api/admin/import/error-reports/'.$token)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}