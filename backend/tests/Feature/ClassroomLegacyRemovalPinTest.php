<?php

namespace Tests\Feature;

use App\Models\ClassroomEnrollment;
use App\Models\CompetencyReference;
use App\Models\GradeLevel;
use App\Models\MasteryRecord;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Semester;
use App\Models\User;
use App\Services\ClassroomService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * U-11 guardrail: the legacy section-enrollment path stays deleted and
 * classroom_id stays required. Reintroducing a StudentSectionEnrollment
 * query, the migrate-legacy command, a whereNull('classroom_id') branch in
 * backend/app, an enrollment admin route, or a nullable classroom_id column
 * reddens this file. Classroom-scoped analytics ownership (owner 200 /
 * non-owner 403 / logged-out 401) is pinned here as the non-regression floor.
 */
class ClassroomLegacyRemovalPinTest extends TestCase
{
    use RefreshDatabase;

    /** @return string[] absolute paths of *.php files under $dir */
    private function phpFiles(string $dir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /** @return string[] "path:line" hits for $pattern, excluding $exclude paths */
    private function scanHits(string $dir, string $pattern, array $exclude = []): array
    {
        $hits = [];
        foreach ($this->phpFiles($dir) as $path) {
            if (in_array($path, $exclude, true)) {
                continue;
            }
            foreach (file($path) as $i => $line) {
                if (preg_match($pattern, $line)) {
                    $hits[] = $path.':'.($i + 1);
                }
            }
        }

        return $hits;
    }

    public function test_legacy_enrollment_table_model_and_command_are_gone(): void
    {
        $this->assertFalse(Schema::hasTable('student_section_enrollments'), 'Legacy enrollment table must stay dropped.');
        $this->assertFileDoesNotExist(app_path('Models/StudentSectionEnrollment.php'), 'Legacy enrollment model must stay deleted.');
        $this->assertFileDoesNotExist(app_path('Console/Commands/MigrateLegacyToClassrooms.php'), 'Legacy migration command must stay deleted.');
        $this->assertArrayNotHasKey('compass:migrate-legacy-to-classrooms', Artisan::all(), 'Legacy migration command must stay unregistered.');
    }

    public function test_no_legacy_enrollment_references_in_sources(): void
    {
        // Exclude this file: the pin pattern itself names the deleted symbols.
        $exclude = [__FILE__];
        $hits = [];
        foreach (['app', 'database', 'routes', 'tests'] as $sub) {
            $hits = array_merge($hits, $this->scanHits(
                base_path($sub),
                '/StudentSectionEnrollment|MigrateLegacyToClassrooms|migrate-legacy-to-classrooms/',
                $exclude
            ));
        }
        $this->assertSame([], $hits, 'Legacy enrollment path reintroduced: '.implode(', ', array_slice($hits, 0, 20)));
    }

    public function test_no_null_classroom_id_queries_in_prod_sources(): void
    {
        $hits = $this->scanHits(base_path('app'), '/orWhereNull\(\s*[\'"]classroom_id[\'"]\s*\)|whereNull\(\s*[\'"]classroom_id[\'"]\s*\)/');
        $this->assertSame([], $hits, 'Null-classroom fallback reintroduced: '.implode(', ', array_slice($hits, 0, 20)));
    }

    public function test_enrollment_admin_routes_are_removed(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'must_change_password' => false]);
        $this->actingAs($admin, 'sanctum');

        // Former #27 / #28 / #29: removed entirely (hard-delete per plan default).
        $this->getJson('/api/admin/sections/1/enrollments')->assertStatus(404);
        $this->postJson('/api/admin/sections/1/enrollments', ['student_id' => 1])->assertStatus(404);
        $this->getJson('/api/admin/students/1/enrollments')->assertStatus(404);
    }

    public function test_classroom_id_is_required_on_content_and_mastery(): void
    {
        foreach (['assessments', 'assignments', 'announcements', 'mastery_records'] as $table) {
            $col = DB::selectOne(
                "SELECT is_nullable FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = 'classroom_id'",
                [$table]
            );
            $this->assertNotNull($col, "{$table}.classroom_id column must exist.");
            $this->assertSame('NO', $col->is_nullable, "{$table}.classroom_id must be NOT NULL.");
        }

        $teacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $year = SchoolYear::create(['name' => 'SY-PIN']);
        $term = Semester::create(['semester' => '1', 'school_year_id' => $year->id, 'name' => 'Semester 1', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31']);
        $gl = GradeLevel::create(['semester_id' => $term->id, 'grade_level' => '7']);
        $section = Section::create(['grade_level_id' => $gl->id, 'name' => '7A-PIN']);
        $subject = Subject::create(['grade_level_id' => $gl->id, 'name' => 'Math-PIN', 'code' => 'MATH-PIN']);

        try {
            DB::table('assessments')->insert([
                'teacher_id' => $teacher->id,
                'subject_id' => $subject->id,
                'semester_id' => $term->id,
                'classroom_id' => null,
                'title' => 'Null classroom must fail',
                'description' => '',
                'type' => 'Recorded',
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected QueryException: assessments.classroom_id is NOT NULL.');
        } catch (QueryException $e) {
            $this->assertInstanceOf(QueryException::class, $e);
        }
    }

    public function test_classroom_analytics_ownership_pin(): void
    {
        $owner = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $nonOwner = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);

        $year = SchoolYear::create(['name' => 'SY-PIN2']);
        $term = Semester::create(['semester' => '1', 'school_year_id' => $year->id, 'name' => 'Semester 1', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31']);
        $gl = GradeLevel::create(['semester_id' => $term->id, 'grade_level' => '7']);
        $section = Section::create(['grade_level_id' => $gl->id, 'name' => '7A-PIN2']);
        $subject = Subject::create(['grade_level_id' => $gl->id, 'name' => 'Math-PIN2', 'code' => 'MATH-PIN2']);

        $classroom = app(ClassroomService::class)->createClassroom($owner->id, $subject->id, $section->id, '2026-2027', 'PIN');

        $student = User::factory()->create(['role' => 'Student', 'must_change_password' => false]);
        ClassroomEnrollment::create(['classroom_id' => $classroom->id, 'student_id' => $student->id, 'joined_at' => now()]);

        $comp = CompetencyReference::create(['semester' => '1', 'code' => 'C-PIN-1', 'descriptor' => 'Comp PIN', 'grade_level' => '7', 'subject_id' => $subject->id]);
        $assessmentId = DB::table('assessments')->insertGetId([
            'teacher_id' => $owner->id, 'subject_id' => $subject->id, 'classroom_id' => $classroom->id,
            'semester_id' => $term->id, 'title' => 'Assess PIN', 'type' => 'Recorded', 'status' => 'released',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $attemptId = DB::table('assessment_attempts')->insertGetId([
            'assessment_id' => $assessmentId, 'student_id' => $student->id, 'attempt_number' => 1,
            'status' => 'scored', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $submissionId = DB::table('assessment_submissions')->insertGetId([
            'assessment_id' => $assessmentId, 'student_id' => $student->id, 'attempt_id' => $attemptId,
            'semester_id' => $term->id, 'section_id' => $section->id, 'submitted_at' => now(),
            'status' => 'scored', 'is_results_released' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        MasteryRecord::create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'classroom_id' => $classroom->id,
            'assessment_id' => $assessmentId, 'assessment_submission_id' => $submissionId,
            'competency_id' => $comp->id, 'mastery_percent' => 100, 'mastery_status' => 'Mastered',
        ]);

        $routes = [
            '/api/teacher/classrooms/'.$classroom->id.'/competency-summary',
            '/api/teacher/classrooms/'.$classroom->id.'/heatmap',
        ];

        foreach ($routes as $route) {
            $this->getJson($route)->assertStatus(401);
        }

        $this->actingAs($nonOwner, 'sanctum');
        foreach ($routes as $route) {
            $this->getJson($route)->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        }

        $this->actingAs($owner, 'sanctum');
        foreach ($routes as $route) {
            $resp = $this->getJson($route);
            $resp->assertStatus(200);
            $this->assertEquals(1, $resp->json('data.competencies.0.total_students_assessed'));
            $this->assertEquals(1, $resp->json('data.competencies.0.mastered_count'));
        }
    }
}
