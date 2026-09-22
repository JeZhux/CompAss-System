<?php

namespace Tests\Feature;

use App\Models\ClassroomEnrollment;
use App\Models\EnrollmentImportRow;
use App\Models\ImportJob;
use App\Models\User;
use App\Services\BatchImportService;
use App\Services\ClassroomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Phase B full_name-only staged bulk (replaces the obsolete U1R
 * group/year staged suite): sheets carry `full_name` only, server
 * auto-generates CompAss IDs, confirm creates accounts with zero
 * classroom placements. Staged rows retain learner_code (generated ID)
 * + full_name only — no group/year columns anywhere.
 */
class StudentEnrollmentImportStagedTest extends TestCase
{
    use RefreshDatabase;

    private function actAsAdmin(): User
    {
        $admin = User::factory()->admin()->create([
            'must_change_password' => false,
        ]);

        return Sanctum::actingAs($admin);
    }

    private function makeXlsx(array $rows): \Illuminate\Http\UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $rowIdx => $row) {
            foreach (array_values($row) as $colIdx => $val) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIdx + 1) . ($rowIdx + 1), $val);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'enr_') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        return new \Illuminate\Http\UploadedFile($path, 'enroll.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function readErrorReport(string $token): Spreadsheet
    {
        $content = Storage::disk('local')->get(BatchImportService::ERROR_REPORT_DIR . '/' . $token . '.xlsx');
        $tempPath = tempnam(sys_get_temp_dir(), 'enr_err_') . '.xlsx';
        file_put_contents($tempPath, $content);
        $spreadsheet = IOFactory::load($tempPath);
        @unlink($tempPath);

        return $spreadsheet;
    }

    public function test_full_name_rows_accepted_as_valid(): void
    {
        $this->actAsAdmin();

        $rows = [
            ['full_name'],
            ['No Group Learner'],
            ['Second Learner'],
        ];

        $preview = $this->post('/api/admin/import/student-enrollments/preview', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');

        $this->assertSame(2, $preview['total_rows']);
        $this->assertSame(2, $preview['valid_rows']);
        $this->assertSame(0, $preview['invalid_rows']);

        $confirm = $this->post('/api/admin/import/student-enrollments/confirm', [
            'preview_token' => $preview['preview_token'],
        ])->assertOk()->json('data');

        $this->assertSame(2, $confirm['imported_rows']);
        $this->assertSame(0, $confirm['updated_rows']);
        $this->assertSame(0, $confirm['failed_rows']);
        $this->assertFalse($confirm['has_error_report']);

        $this->assertDatabaseHas('users', ['name' => 'No Group Learner', 'role' => 'Student']);
        $this->assertDatabaseHas('users', ['name' => 'Second Learner', 'role' => 'Student']);

        $job = ImportJob::where('import_type', 'student_enrollment')->first();
        $this->assertNotNull($job);
        $row = EnrollmentImportRow::where('import_job_id', $job->id)->first();
        $this->assertNotNull($row);
        $this->assertMatchesRegularExpression('/^STU-[0-9]{4}-[0-9]{5}$/', $row->learner_code);
        $this->assertNull($row->error);
    }

    public function test_extra_legacy_columns_ignored(): void
    {
        $this->actAsAdmin();

        // Legacy multi-column sheets still parse: extras ignored, IDs never read.
        $rows = [
            ['full_name', 'learner_code', 'group', 'year_level', 'extra_note'],
            ['Legacy Learner', 'STU-LEGACY-1', 'Diamond', '7', 'ignored'],
        ];

        $preview = $this->post('/api/admin/import/student-enrollments/preview', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');

        $this->assertSame(1, $preview['total_rows']);
        $this->assertSame(1, $preview['valid_rows']);

        $confirm = $this->post('/api/admin/import/student-enrollments/confirm', [
            'preview_token' => $preview['preview_token'],
        ])->assertOk()->json('data');

        $this->assertSame(1, $confirm['imported_rows']);
        $learner = User::where('name', 'Legacy Learner')->firstOrFail();
        $this->assertNotSame('STU-LEGACY-1', $learner->school_id);
        $this->assertMatchesRegularExpression('/^STU-[0-9]{4}-[0-9]{5}$/', $learner->school_id);
    }

    public function test_staged_retention_and_canonical_schema_unchanged(): void
    {
        $this->actAsAdmin();

        $rows = [
            ['full_name'],
            ['Staged One'],
            ['Bare One'],
        ];

        $preview = $this->post('/api/admin/import/student-enrollments/preview', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');

        $this->post('/api/admin/import/student-enrollments/confirm', [
            'preview_token' => $preview['preview_token'],
        ])->assertOk();

        $job = ImportJob::where('import_type', 'student_enrollment')->first();
        $this->assertNotNull($job);

        $staged = EnrollmentImportRow::where('import_job_id', $job->id)->orderBy('row_number')->get();
        $this->assertCount(2, $staged);
        $this->assertMatchesRegularExpression('/^STU-[0-9]{4}-[0-9]{5}$/', $staged[0]->learner_code);
        $this->assertNull($staged[0]->error);
        $this->assertNotNull($staged[1]->import_job_id);
        $this->assertNotNull($staged[1]->created_at);

        // Phase B: no group/year columns anywhere canonical.
        $this->assertFalse(Schema::hasColumn('users', 'group_assignment'));
        $this->assertFalse(Schema::hasColumn('users', 'year_level'));
        $this->assertFalse(Schema::hasColumn('users', 'group'));
        $this->assertFalse(Schema::hasColumn('users', 'email'));
        $this->assertFalse(Schema::hasColumn('classroom_enrollments', 'group_assignment'));
        $this->assertFalse(Schema::hasColumn('classroom_enrollments', 'year_level'));
        $this->assertFalse(Schema::hasColumn('enrollment_import_rows', 'group_assignment'));
        $this->assertFalse(Schema::hasColumn('enrollment_import_rows', 'year_level'));
        $this->assertTrue(Schema::hasTable('enrollment_import_rows'));
        $this->assertTrue(Schema::hasColumn('enrollment_import_rows', 'learner_code'));
        $this->assertTrue(Schema::hasColumn('enrollment_import_rows', 'full_name'));
        $this->assertTrue(Schema::hasColumn('enrollment_import_rows', 'error'));
    }

    public function test_confirm_creates_accounts_without_placements(): void
    {
        $admin = $this->actAsAdmin();

        $this->assertSame(0, ClassroomEnrollment::count());

        $rows = [
            ['full_name'],
            ['Divergent Learner'],
        ];

        $preview = $this->post('/api/admin/import/student-enrollments/preview', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');

        $this->assertSame(1, $preview['valid_rows']);

        $confirm = $this->post('/api/admin/import/student-enrollments/confirm', [
            'preview_token' => $preview['preview_token'],
        ])->assertOk()->json('data');

        $this->assertSame(1, $confirm['total_rows']);
        $this->assertSame(1, $confirm['imported_rows']);
        $this->assertSame(0, $confirm['updated_rows']);
        $this->assertSame(0, $confirm['failed_rows']);
        $this->assertStringContainsString('No classroom placements were made', $confirm['message']);

        $this->assertSame(0, ClassroomEnrollment::count());
        $this->assertDatabaseHas('users', ['name' => 'Divergent Learner']);
    }

    public function test_zero_valid_failures_report_zeros_without_placements(): void
    {
        $this->actAsAdmin();

        $rows = [
            ['full_name', 'note'],
            ['', 'legacy-extra-1'],
            ['', ''],
            ['', 'legacy-extra-2'],
        ];

        $preview = $this->post('/api/admin/import/student-enrollments/preview', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');

        $this->assertSame(2, $preview['total_rows']);
        $this->assertSame(0, $preview['valid_rows']);
        $this->assertSame(2, $preview['invalid_rows']);

        $confirm = $this->post('/api/admin/import/student-enrollments/confirm', [
            'preview_token' => $preview['preview_token'],
        ])->assertOk()->json('data');

        $this->assertSame(2, $confirm['total_rows']);
        $this->assertSame(0, $confirm['imported_rows']);
        $this->assertSame(0, $confirm['updated_rows']);
        $this->assertSame(2, $confirm['failed_rows']);
        $this->assertTrue($confirm['has_error_report']);
        $this->assertStringContainsString('No classroom placements were made', $confirm['message']);

        $this->assertSame(0, ClassroomEnrollment::count());
    }

    public function test_error_help_states_accounts_only_with_contact(): void
    {
        $this->actAsAdmin();

        $rows = [
            ['full_name', 'note'],
            ['Good', ''],
            ['', 'legacy-extra-keeps-row-counted'],
        ];

        $preview = $this->post('/api/admin/import/student-enrollments/preview', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');

        $confirm = $this->post('/api/admin/import/student-enrollments/confirm', [
            'preview_token' => $preview['preview_token'],
        ])->assertOk()->json('data');

        $token = basename($confirm['error_report_url']);
        $report = $this->readErrorReport($token);
        $help = $report->getSheetByName('Help');
        $this->assertNotNull($help);
        $this->assertStringContainsString('No classroom placements were made', (string) $help->getCell('A1')->getValue());
        $this->assertStringContainsString('contact', strtolower((string) $help->getCell('A2')->getValue()));
        $report->disconnectWorksheets();
    }

    public function test_overlong_name_staged_truncated_but_error_sheet_keeps_full_value(): void
    {
        $this->actAsAdmin();

        $overlong = str_repeat('A', 300);
        $this->assertGreaterThan(255, mb_strlen($overlong));

        $rows = [
            ['full_name'],
            [$overlong],
        ];

        $preview = $this->post('/api/admin/import/student-enrollments/preview', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');

        $this->assertSame(1, $preview['total_rows']);
        $this->assertSame(0, $preview['valid_rows']);
        $this->assertSame(1, $preview['invalid_rows']);

        $confirm = $this->post('/api/admin/import/student-enrollments/confirm', [
            'preview_token' => $preview['preview_token'],
        ])->assertOk()->json('data');

        $this->assertSame(0, $confirm['imported_rows']);
        $this->assertSame(0, $confirm['updated_rows']);
        $this->assertSame(1, $confirm['failed_rows']);
        $this->assertTrue($confirm['has_error_report']);

        // Staged is truncated display only (varchar 255); the full reason is
        // stored unstripped.
        $job = ImportJob::where('import_type', 'student_enrollment')->latest('id')->first();
        $this->assertNotNull($job);
        $staged = EnrollmentImportRow::where('import_job_id', $job->id)->first();
        $this->assertNotNull($staged);
        $this->assertSame(255, mb_strlen($staged->full_name));
        $this->assertSame(mb_substr($overlong, 0, 255), $staged->full_name);
        $this->assertSame('Full name must not exceed 255 characters.', $staged->error);

        // The error sheet carries the full submitted value + full reason.
        $token = basename($confirm['error_report_url']);
        $report = $this->readErrorReport($token);
        $errors = $report->getSheetByName('Errors');
        $this->assertNotNull($errors);
        $this->assertSame($overlong, (string) $errors->getCell('B2')->getValue());
        $this->assertSame('Full name must not exceed 255 characters.', (string) $errors->getCell('C2')->getValue());
        $report->disconnectWorksheets();
    }
}
