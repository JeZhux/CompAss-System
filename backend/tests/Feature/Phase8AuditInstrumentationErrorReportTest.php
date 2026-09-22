<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\AuditLog;
use App\Models\GradeLevel;
use App\Models\ImportJob;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Semester;
use App\Models\Subject;
use App\Models\User;
use App\Services\BatchImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Phase 8 — audit-trail instrumentation for the admin batch-import one-time
 * error-report download (#33/#99, ARCH-004 §8.1, AUD-015): a successful consumption
 * (file deleted + job marked used) writes exactly one `error_report_download`
 * row scoped to the ImportJob, with the token hash in metadata (the raw
 * single-use bearer is never persisted). Every failed attempt — unknown
 * TTL (all 410 GONE) — writes NO audit row.
 *
 * @Traced-To ARCH-002 QA-006 (ARCH-001 §5.1), AUD-015
 */
#[Group('phase8-audit-instrumentation-error-report')]
class Phase8AuditInstrumentationErrorReportTest extends TestCase
{
    use RefreshDatabase;

    private ?Subject $subject = null;

    private const COMPETENCY_TAGS_URL = '/api/admin/import/competency-tags';

    private const ERROR_REPORTS_URL = '/api/admin/import/error-reports';

    private ?User $admin = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = null;
    }

    private function actAsAdmin(): User
    {
        if (! $this->admin) {
            $this->admin = User::factory()->create([
                'role' => 'Admin',
                'must_change_password' => false,
            ]);
        }

        Sanctum::actingAs($this->admin);

        return $this->admin;
    }

    /**
     * Build a real section via the API: school-year → semester → grade-level → section.
     */
    private function makeFullHierarchy(): int
    {
        $this->actAsAdmin();
        $year = $this->post('/api/admin/school-years', ['name' => 'SY 2026-AUD'])
            ->assertCreated()->json('data');
        $term = $this->post('/api/admin/school-years/'.$year['id'].'/semesters', [
            'semester' => '1', 'name' => 'Semester 1', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31',
        ])->assertCreated()->json('data');
        $gl = $this->post('/api/admin/semesters/'.$term['id'].'/grade-levels', ['grade_level' => 7])
            ->assertCreated()->json('data');
        $section = $this->post('/api/admin/grade-levels/'.$gl['id'].'/sections', ['name' => '7A-AUD'])
            ->assertCreated()->json('data');

        return (int) $section['id'];
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

    public function test_successful_error_report_download_writes_exactly_one_audit_row(): void
    {
        $admin = $this->actAsAdmin();

        // A competency-tags import with one duplicate code produces a downloadable error report.
        $year = \App\Models\SchoolYear::create(['name' => 'SY 2026-AUD-FIX']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31']);
        $gradeLevel = GradeLevel::create(['semester_id' => $semester->id, 'grade_level' => '7']);
        $this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Math-AUD', 'code' => 'MATH-AUD', 'description' => 'Math']);
        $subject = $this->subject;
        \App\Models\CompetencyReference::create(['semester' => '1', 'code' => 'EXISTING-AUDIT-015',
            'descriptor' => 'Existing',
            'subject_id' => $subject->id,
            'grade_level' => '7',
        ]);
        $data = $this->post(self::COMPETENCY_TAGS_URL, [
            'file' => $this->makeXlsx([
                ['code', 'descriptor', 'subject_id', 'grade_level'],
                ['NEW-AUDIT-015', 'New entry', $subject->id, 7],
                ['EXISTING-AUDIT-015', 'Duplicate existing', $subject->id, 7],
            ]),
        ])->assertOk()->json('data');

        $this->assertTrue($data['has_error_report']);
        $token = basename((string) $data['error_report_url']);
        $job = ImportJob::query()
            ->where('error_report_token', hash('sha256', $token))
            ->firstOrFail();

        // Successful download consumes the report.
        $this->get(self::ERROR_REPORTS_URL.'/'.$token)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $rows = AuditLog::query()
            ->where('event_type', 'error_report_download')
            ->where('auditable_type', ImportJob::class)
            ->where('auditable_id', $job->id)
            ->get();
        $this->assertCount(1, $rows);

        $row = $rows->first();
        $this->assertSame($admin->id, $row->user_id);
        $this->assertSame('Import error report downloaded (job '.$job->id.')', $row->description);
        $this->assertSame(['token_hash' => hash('sha256', $token)], $row->metadata);
        $this->assertSame($job->id, $row->auditable_id);
        $this->assertStringContainsString((string) $job->id, $row->description);
        $this->assertStringNotContainsString($token, json_encode($row->toArray()));

        // Re-attempting the consumed token yields 410 GONE and adds no second row.
        $this->get(self::ERROR_REPORTS_URL.'/'.$token)
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'GONE');
        $this->assertSame(
            1,
            AuditLog::query()->where('event_type', 'error_report_download')->count()
        );
    }

    public function test_failed_error_report_downloads_write_no_audit_row(): void
    {
        $admin = $this->actAsAdmin();

        // Unknown token → 410 GONE.
        $this->get(self::ERROR_REPORTS_URL.'/never-issued-token')
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'GONE');

        // Expired TTL → 410 GONE.
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
        $this->get(self::ERROR_REPORTS_URL.'/expired-token')
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'GONE');

        $this->assertDatabaseMissing('audit_logs', ['event_type' => 'error_report_download']);
    }
}
