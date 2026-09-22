<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Student + teacher bulk templates are full_name-only (Phase B):
 * every supported slug downloads a 200 .xlsx whose single header is
 * `full_name` (server auto-generates CompAss IDs).
 */
#[Group('import-template')]
class ImportTemplateTest extends TestCase
{
    use RefreshDatabase;

    public static function enrollmentSlugProvider(): array
    {
        return [
            ['student_enrollment'],
            ['student_enrollments'],
            ['student-enrollments'],
            ['teacher_application'],
            ['teacher_applications'],
            ['teacher-applications'],
        ];
    }

    #[DataProvider('enrollmentSlugProvider')]
    public function test_student_enrollment_template_downloads_with_expected_headers(string $slug): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'Admin',
            'must_change_password' => false,
        ]));

        $response = $this->get('/api/admin/import/templates/'.$slug);
        $response->assertOk();
        $response->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );

        $content = $response->streamedContent();
        $this->assertNotEmpty($content);
        $this->assertSame('PK', substr($content, 0, 2));

        $tempPath = tempnam(sys_get_temp_dir(), 'enr_tmpl_').'.xlsx';
        file_put_contents($tempPath, $content);
        $spreadsheet = IOFactory::load($tempPath);
        $sheet = $spreadsheet->getActiveSheet();

        // Phase B full_name-only: single column, no IDs / group / year.
        $this->assertSame('full_name', $sheet->getCell('A1')->getValue());
        $this->assertEmpty($sheet->getCell('B1')->getValue());

        $spreadsheet->disconnectWorksheets();
        @unlink($tempPath);
    }

    public function test_competency_tags_template_still_returns_xlsx(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'Admin',
            'must_change_password' => false,
        ]));

        $response = $this->get('/api/admin/import/templates/competency_tags');
        $response->assertOk();
        $response->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );

        $content = $response->streamedContent();
        $this->assertNotEmpty($content);
        $this->assertSame('PK', substr($content, 0, 2));

        $tempPath = tempnam(sys_get_temp_dir(), 'u14_tmpl_').'.xlsx';
        file_put_contents($tempPath, $content);
        $spreadsheet = IOFactory::load($tempPath);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('code', $sheet->getCell('A1')->getValue());

        $spreadsheet->disconnectWorksheets();
        @unlink($tempPath);
    }
}
