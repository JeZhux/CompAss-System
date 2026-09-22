<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleConflictException;
use App\Models\ImportJob;
use App\Models\User;
use App\Services\BatchImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Phase B full_name-only student bulk: single `full_name` column,
 * server-generated STU- CompAss IDs, always creates new accounts
 * (updated always 0). Mirrored by teacher bulk (teacher-applications).
 */
class StudentEnrollmentImportTest extends TestCase
{
    use RefreshDatabase;

    private function actAsAdmin(): User
    {
        $admin = User::factory()->admin()->create([
            'must_change_password' => false,
        ]);

        return Sanctum::actingAs($admin);
    }

    private function makeXlsx(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $rowIdx => $row) {
            foreach (array_values($row) as $colIdx => $val) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIdx + 1).($rowIdx + 1), $val);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'enr_').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'enroll.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function makeXls(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $rowIdx => $row) {
            foreach (array_values($row) as $colIdx => $val) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIdx + 1).($rowIdx + 1), $val);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'enr_').'.xls';
        $writer = new Xls($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'enroll.xls', 'application/vnd.ms-excel', null, true);
    }

    private function readErrorReport(string $token): Spreadsheet
    {
        $content = Storage::disk('local')->get(BatchImportService::ERROR_REPORT_DIR.'/'.$token.'.xlsx');
        $tempPath = tempnam(sys_get_temp_dir(), 'enr_err_').'.xlsx';
        file_put_contents($tempPath, $content);
        $spreadsheet = IOFactory::load($tempPath);
        @unlink($tempPath);

        return $spreadsheet;
    }

    public function test_preview_returns_counts_with_zero_writes(): void
    {
        $this->actAsAdmin();
        $usersBefore = User::count();

        $rows = [
            ['full_name', 'note'],
            ['New Learner', ''],
            ['Second Learner', ''],
            ['', ''],
            ['', 'legacy-extra-keeps-row-counted'],
            ['New Learner', ''],
        ];

        $data = $this->post('/api/admin/import/student-enrollments/preview', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');

        $this->assertArrayHasKey('preview_token', $data);
        $this->assertArrayHasKey('expires_at', $data);
        // Blank rows skipped: 4 data rows (2 valid new + 1 too-long + 1 dup-name allowed).
        $this->assertSame(4, $data['total_rows']);
        $this->assertSame(3, $data['valid_rows']);
        $this->assertSame(1, $data['invalid_rows']);
        // Exact repeats allowed as distinct accounts, counted informationally.
        $this->assertSame(1, $data['duplicate_matches']);

        $this->assertSame($usersBefore, User::count());
        $this->assertSame(0, ImportJob::count());
    }

    public function test_confirm_creates_new_with_reconciled_counts(): void
    {
        $this->actAsAdmin();

        $rows = [
            ['full_name', 'note'],
            ['Brand New', ''],
            ['Second New', ''],
            ['', 'legacy-extra-keeps-row-counted'],
        ];

        $preview = $this->post('/api/admin/import/student-enrollments/preview', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');

        $confirm = $this->post('/api/admin/import/student-enrollments/confirm', [
            'preview_token' => $preview['preview_token'],
        ])->assertOk()->json('data');

        $this->assertSame(3, $confirm['total_rows']);
        $this->assertSame(2, $confirm['imported_rows']);
        $this->assertSame(0, $confirm['updated_rows']);
        $this->assertSame(1, $confirm['failed_rows']);
        $this->assertSame(
            $confirm['total_rows'],
            $confirm['imported_rows'] + $confirm['updated_rows'] + $confirm['failed_rows']
        );
        $this->assertTrue($confirm['has_error_report']);
        $this->assertNotNull($confirm['error_report_url']);
        $this->assertStringContainsString('No classroom placements were made', $confirm['message']);

        $this->assertDatabaseHas('users', ['name' => 'Brand New', 'role' => 'Student']);
        $this->assertDatabaseHas('users', ['name' => 'Second New', 'role' => 'Student']);
        $newUser = User::where('name', 'Brand New')->firstOrFail();
        $this->assertMatchesRegularExpression('/^STU-[0-9]{4}-[0-9]{5}$/', $newUser->school_id);

        $this->assertDatabaseHas('import_jobs', [
            'import_type' => 'student_enrollment',
            'total_rows' => 3,
            'failed_rows' => 1,
        ]);
    }

    public function test_teacher_bulk_mirrors_student(): void
    {
        $this->actAsAdmin();

        $rows = [
            ['full_name', 'note'],
            ['New Teacher', ''],
            ['', 'legacy-extra-keeps-row-counted'],
        ];

        $preview = $this->post('/api/admin/import/teacher-applications/preview', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');

        $this->assertSame(2, $preview['total_rows']);
        $this->assertSame(1, $preview['valid_rows']);
        $this->assertSame(1, $preview['invalid_rows']);

        $confirm = $this->post('/api/admin/import/teacher-applications/confirm', [
            'preview_token' => $preview['preview_token'],
        ])->assertOk()->json('data');

        $this->assertSame(2, $confirm['total_rows']);
        $this->assertSame(1, $confirm['imported_rows']);
        $this->assertSame(0, $confirm['updated_rows']);
        $this->assertSame(1, $confirm['failed_rows']);
        $this->assertTrue($confirm['has_error_report']);

        $teacher = User::where('name', 'New Teacher')->firstOrFail();
        $this->assertSame('Teacher', $teacher->role);
        $this->assertMatchesRegularExpression('/^TEA-[0-9]{4}-[0-9]{5}$/', $teacher->school_id);
    }

    public function test_confirm_token_single_use_and_unknown_gone(): void
    {
        $this->actAsAdmin();

        $rows = [
            ['full_name'],
            ['Learner Ten'],
        ];

        $preview = $this->post('/api/admin/import/student-enrollments/preview', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');

        $this->post('/api/admin/import/student-enrollments/confirm', [
            'preview_token' => $preview['preview_token'],
        ])->assertOk();

        $this->post('/api/admin/import/student-enrollments/confirm', [
            'preview_token' => $preview['preview_token'],
        ])->assertStatus(410)->assertJsonPath('error.code', 'GONE');

        $this->post('/api/admin/import/student-enrollments/confirm', [
            'preview_token' => str_repeat('a', 32),
        ])->assertStatus(410)->assertJsonPath('error.code', 'GONE');

        Cache::flush();
        $this->post('/api/admin/import/student-enrollments/confirm', [
            'preview_token' => $preview['preview_token'],
        ])->assertStatus(410)->assertJsonPath('error.code', 'GONE');
    }

    public function test_rapid_double_confirm_exactly_one_succeeds(): void
    {
        $this->actAsAdmin();

        $rows = [
            ['full_name'],
            ['Race Learner'],
        ];

        $preview = $this->post('/api/admin/import/student-enrollments/preview', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');

        $this->post('/api/admin/import/student-enrollments/confirm', [
            'preview_token' => $preview['preview_token'],
        ])->assertOk();

        $this->post('/api/admin/import/student-enrollments/confirm', [
            'preview_token' => $preview['preview_token'],
        ])->assertStatus(410)->assertJsonPath('error.code', 'GONE');

        $this->assertSame(1, User::where('name', 'Race Learner')->count());
        $this->assertSame(1, ImportJob::where('import_type', 'student_enrollment')->count());
    }

    public function test_confirm_while_claim_lock_held_is_gone(): void
    {
        $this->actAsAdmin();

        $rows = [
            ['full_name'],
            ['Locked Learner'],
        ];

        $preview = $this->post('/api/admin/import/student-enrollments/preview', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');

        $claimLock = Cache::lock(
            'enrollment_preview:' . hash('sha256', $preview['preview_token']) . ':claim',
            10
        );
        $this->assertTrue($claimLock->acquire());

        $this->post('/api/admin/import/student-enrollments/confirm', [
            'preview_token' => $preview['preview_token'],
        ])->assertStatus(410)->assertJsonPath('error.code', 'GONE');

        $claimLock->release();

        $this->post('/api/admin/import/student-enrollments/confirm', [
            'preview_token' => $preview['preview_token'],
        ])->assertOk();
        $this->assertDatabaseHas('users', ['name' => 'Locked Learner']);
    }

    public function test_confirm_zero_valid_empty_file_succeeds_with_zeros_no_report(): void
    {
        $this->actAsAdmin();

        $rows = [
            ['full_name'],
        ];

        $preview = $this->post('/api/admin/import/student-enrollments/preview', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');

        $this->assertSame(0, $preview['total_rows']);
        $this->assertSame(0, $preview['valid_rows']);
        $this->assertSame(0, $preview['invalid_rows']);

        $confirm = $this->post('/api/admin/import/student-enrollments/confirm', [
            'preview_token' => $preview['preview_token'],
        ])->assertOk()->json('data');

        $this->assertSame(0, $confirm['total_rows']);
        $this->assertSame(0, $confirm['imported_rows']);
        $this->assertSame(0, $confirm['updated_rows']);
        $this->assertSame(0, $confirm['failed_rows']);
        $this->assertFalse($confirm['has_error_report']);
        $this->assertNull($confirm['error_report_url']);
    }

    public function test_error_sheet_single_use_with_reasons_and_guidance(): void
    {
        $this->actAsAdmin();

        $rows = [
            ['full_name'],
            ['Good Learner'],
            [''],
        ];
        // Second data row is blank -> skipped, so add an invalid long name.
        $rows = [
            ['full_name', 'note'],
            ['Good Learner', ''],
            ['', 'legacy-extra-keeps-row-counted'],
        ];

        $preview = $this->post('/api/admin/import/student-enrollments/preview', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');

        $confirm = $this->post('/api/admin/import/student-enrollments/confirm', [
            'preview_token' => $preview['preview_token'],
        ])->assertOk()->json('data');

        $this->assertTrue($confirm['has_error_report']);
        $token = basename($confirm['error_report_url']);

        $report = $this->readErrorReport($token);
        $sheet = $report->getActiveSheet();
        $this->assertSame('Row', $sheet->getCell('A1')->getValue());
        $this->assertSame('full_name', $sheet->getCell('B1')->getValue());
        $this->assertSame('Reason', $sheet->getCell('C1')->getValue());

        $help = $report->getSheetByName('Help');
        $this->assertNotNull($help);
        $this->assertStringContainsString('contact', strtolower((string) $help->getCell('A2')->getValue()));
        $report->disconnectWorksheets();

        $this->get('/api/admin/import/error-reports/'.$token)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->get('/api/admin/import/error-reports/'.$token)
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'GONE');

        $this->get('/api/admin/import/error-reports/never-issued-token-000000000000')
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'GONE');
    }

    public function test_preview_and_confirm_manager_only(): void
    {
        $rows = [
            ['full_name'],
            ['Learner'],
        ];

        $this->post('/api/admin/import/student-enrollments/preview', [
            'file' => $this->makeXlsx($rows),
        ])->assertStatus(401);

        $this->post('/api/admin/import/student-enrollments/confirm', [
            'preview_token' => 'x',
        ])->assertStatus(401);

        $teacher = User::factory()->teacher()->create(['must_change_password' => false]);
        Sanctum::actingAs($teacher);
        $this->post('/api/admin/import/student-enrollments/preview')->assertStatus(403);
        $this->post('/api/admin/import/student-enrollments/confirm', ['preview_token' => str_repeat('x', 32)])->assertStatus(403);
        $this->get('/api/admin/import/error-reports/'.str_repeat('x', 32))->assertStatus(403);

        $student = User::factory()->student()->create(['must_change_password' => false]);
        Sanctum::actingAs($student);
        $this->post('/api/admin/import/student-enrollments/preview')->assertStatus(403);
        $this->post('/api/admin/import/student-enrollments/confirm', ['preview_token' => str_repeat('y', 32)])->assertStatus(403);
    }

    public function test_forced_password_gate_blocks_enrollment_paths(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create(['must_change_password' => true]));

        $this->post('/api/admin/import/student-enrollments/preview')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PASSWORD_CHANGE_REQUIRED');

        $this->post('/api/admin/import/student-enrollments/confirm', ['preview_token' => str_repeat('z', 32)])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PASSWORD_CHANGE_REQUIRED');
    }

    public function test_oversize_and_over_row_refused_pre_write(): void
    {
        $admin = $this->actAsAdmin();

        $largeFile = UploadedFile::fake()->create(
            'bulk.xlsx',
            15361,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
        $this->assertGreaterThan(BatchImportService::MAX_FILE_SIZE, $largeFile->getSize());

        $this->post('/api/admin/import/student-enrollments/preview', ['file' => $largeFile])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertSame(0, ImportJob::count());

        try {
            app(BatchImportService::class)->previewStudentEnrollments($largeFile, $admin->id);
            $this->fail('Expected BusinessRuleConflictException for oversized file.');
        } catch (BusinessRuleConflictException $e) {
            $this->assertSame('FILE_TOO_LARGE', $e->errorCode);
        }
        $this->assertSame(0, ImportJob::count());

        $rows = [['full_name']];
        for ($i = 1; $i <= BatchImportService::MAX_DATA_ROWS + 1; $i++) {
            $rows[] = ['Learner '.$i];
        }

        $this->post('/api/admin/import/student-enrollments/preview', [
            'file' => $this->makeXlsx($rows),
        ])->assertStatus(422)->assertJsonPath('error.code', 'TOO_MANY_ROWS');

        $this->assertSame(0, ImportJob::count());
        $this->assertSame(1, User::count());
    }

    public function test_responses_and_error_sheet_hide_system_ids(): void
    {
        $this->actAsAdmin();

        $rows = [
            ['full_name', 'note'],
            ['Shown Learner', ''],
            ['', 'legacy-extra-keeps-row-counted'],
        ];

        $previewResponse = $this->post('/api/admin/import/student-enrollments/preview', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk();

        $previewJson = json_encode($previewResponse->json('data'));
        $this->assertStringNotContainsString('"id"', $previewJson);

        $confirmResponse = $this->post('/api/admin/import/student-enrollments/confirm', [
            'preview_token' => $previewResponse->json('data.preview_token'),
        ])->assertOk();

        $confirmJson = json_encode($confirmResponse->json('data'));
        $this->assertStringNotContainsString('"id"', $confirmJson);

        $token = basename($confirmResponse->json('data.error_report_url'));
        $report = $this->readErrorReport($token);
        $sheet = $report->getActiveSheet();
        $headers = [
            $sheet->getCell('A1')->getValue(),
            $sheet->getCell('B1')->getValue(),
            $sheet->getCell('C1')->getValue(),
        ];
        foreach ($headers as $header) {
            $this->assertNotSame('id', strtolower((string) $header));
        }
        $report->disconnectWorksheets();
    }

    public function test_no_partial_half_save_on_mixed_file(): void
    {
        $this->actAsAdmin();

        $rows = [
            ['full_name', 'note'],
            ['Good One', ''],
            ['', 'legacy-extra-keeps-row-counted'],
        ];

        $preview = $this->post('/api/admin/import/student-enrollments/preview', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');

        $confirm = $this->post('/api/admin/import/student-enrollments/confirm', [
            'preview_token' => $preview['preview_token'],
        ])->assertOk()->json('data');

        $this->assertSame($confirm['total_rows'], $confirm['imported_rows'] + $confirm['updated_rows'] + $confirm['failed_rows']);
        $this->assertDatabaseHas('users', ['name' => 'Good One']);
    }

    public function test_preview_accepts_xls_and_rejects_non_spreadsheet(): void
    {
        $this->actAsAdmin();

        $rows = [
            ['full_name'],
            ['Xls Learner'],
        ];

        $data = $this->post('/api/admin/import/student-enrollments/preview', [
            'file' => $this->makeXls($rows),
        ])->assertOk()->json('data');

        $this->assertSame(1, $data['total_rows']);
        $this->assertSame(1, $data['valid_rows']);

        $txt = new UploadedFile(tempnam(sys_get_temp_dir(), 't_'), 't.txt', 'text/plain', null, true);
        $this->post('/api/admin/import/student-enrollments/preview', ['file' => $txt])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_confirm_requires_preview_token_validation_envelope(): void
    {
        $this->actAsAdmin();

        $this->post('/api/admin/import/student-enrollments/confirm', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_confirm_preview_token_length_is_bounded(): void
    {
        $this->actAsAdmin();

        $this->post('/api/admin/import/student-enrollments/confirm', ['preview_token' => 'short'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->post('/api/admin/import/student-enrollments/confirm', ['preview_token' => str_repeat('b', 129)])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->post('/api/admin/import/student-enrollments/confirm', ['preview_token' => str_repeat('c', 32)])
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'GONE');
    }

    public function test_confirm_rejects_other_admins_preview_token(): void
    {
        $adminA = User::factory()->admin()->create(['must_change_password' => false]);
        $adminB = User::factory()->admin()->create(['must_change_password' => false]);

        $rows = [
            ['full_name'],
            ['Learner Ninety'],
        ];

        Sanctum::actingAs($adminA);
        $preview = $this->post('/api/admin/import/student-enrollments/preview', [
            'file' => $this->makeXlsx($rows),
        ])->assertOk()->json('data');

        Sanctum::actingAs($adminB);
        $this->post('/api/admin/import/student-enrollments/confirm', [
            'preview_token' => $preview['preview_token'],
        ])->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertDatabaseMissing('users', ['name' => 'Learner Ninety']);

        Sanctum::actingAs($adminA);
        $confirm = $this->post('/api/admin/import/student-enrollments/confirm', [
            'preview_token' => $preview['preview_token'],
        ])->assertOk()->json('data');

        $this->assertSame(1, $confirm['total_rows']);
        $this->assertSame(1, $confirm['imported_rows']);
        $this->assertDatabaseHas('users', ['name' => 'Learner Ninety']);
    }

    public function test_deleted_legacy_enrollment_route_still_404(): void
    {
        $this->actAsAdmin();

        $xlsx = $this->makeXlsx([['school_id', 'section_id'], ['STU-1', '1']]);
        $this->post('/api/admin/import/student-enrollments', ['file' => $xlsx])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }
}
