<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\Assignment;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Semester;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 5 — API Alignment (ARCH-005 §2, ARCH-005 §2): every list endpoint returns
 * { data, meta } with the shared per_page clamp (1..100, out-of-range → 15).
 *
 * Sweeps all 16 list endpoints (admin #5/#12/#14/#16/#18/#20/#96/#97/#98,
 * teacher #34/#40/#46/#53/#62/#83/#90): asserts the ONLY-allowed top-level
 * keys are data + meta, the meta keys are exactly the 6 helper keys, no raw
 * paginator keys leak to the top level, and per_page clamps on one endpoint
 * per role surface (admin users, teacher assessments, learning materials).
 *
 * @Traced-To ARCH-005 §2, ARCH-005 §2 (ARCH-005 §4x list shapes)
 */
#[Group('phase5')]
class Phase5ContractSweepTest extends TestCase
{
    use RefreshDatabase;

    private const META_KEYS = ['current_page', 'from', 'last_page', 'per_page', 'to', 'total'];

    private const RAW_PAGINATOR_KEYS = [
        'next_page_url',
        'first_page_url',
        'last_page_url',
        'prev_page_url',
        'path',
        'links',
        'current_page',
        'per_page',
        'total',
        'from',
        'to',
    ];

    private ?User $admin = null;

    private ?User $teacher = null;

    
    private ?Subject $subject = null;

    private ?Section $section = null;

    private ?Semester $term = null;

    private ?GradeLevel $gradeLevel = null;

    private ?AuditLogService $auditLogService = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = null;
        $this->teacher = null;
        $this->section = null;
        $this->term = null;
        $this->gradeLevel = null;
        $this->auditLogService = $this->app->make(AuditLogService::class);
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

    private function actAsTeacher(): User
    {
        if (! $this->teacher) {
            $this->teacher = User::factory()->create([
                'role' => 'Teacher',
                'must_change_password' => false,
            ]);
        }

        Sanctum::actingAs($this->teacher);

        return $this->teacher;
    }

    /**
     * Full org hierarchy: School Year → Semester → GradeLevel 7 → Section →
     * SubjectSection → Teacher assignment (mirrors Phase3/Phase7 fixtures).
     */
    private function setUpOrg(): void
    {
        $year = SchoolYear::create(['name' => 'SY 2026-P5S']);
        $this->term = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
        ]);
        $this->gradeLevel = GradeLevel::create([
            'semester_id' => $this->term->id,
            'grade_level' => '7',
        ]);
        $this->section = Section::create([
            'grade_level_id' => $this->gradeLevel->id,
            'name' => '7A-P5S',
        ]);
$this->subject = Subject::create(['grade_level_id' => $this->gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-P5S']);
        
        $teacher = $this->actAsTeacher();
    }

    /**
     * @return array<int, array{0: string, 1: string}>  [spec-id label, URL]
     */
    private function allListUrls(): array
    {
        $this->setUpOrg();
        $admin = $this->actAsAdmin();

        $classroom = app(\App\Services\ClassroomService::class)->createClassroom($this->teacher->id, $this->subject->id, $this->section->id, '2026-2027', null);

        $assignment = Assignment::create([
            'teacher_id' => $this->teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $this->subject->id,
            'semester_id' => $this->term->id,
            'title' => 'Sweep Assignment',
            'description' => null,
            'due_date' => '2026-12-15 23:59:59',
        ]);

        return [
            '#5' => ['GET', '/api/admin/users'],
            '#12' => ['GET', '/api/admin/school-years'],
            '#14' => ['GET', '/api/admin/school-years/' . $this->term->school_year_id . '/semesters'],
            '#16' => ['GET', '/api/admin/semesters/' . $this->term->id . '/grade-levels'],
            '#18' => ['GET', '/api/admin/grade-levels/' . $this->gradeLevel->id . '/sections'],
            '#20' => ['GET', '/api/admin/subjects'],
            '#34' => ['GET', '/api/teacher/announcements'],
            '#40' => ['GET', '/api/teacher/assignments'],
            '#46' => ['GET', '/api/teacher/assignments/' . $assignment->id . '/submissions'],
            '#53' => ['GET', '/api/teacher/assessments'],
            '#62' => ['GET', '/api/teacher/pending-grading'],
            '#83' => ['GET', '/api/teacher/learning-materials?subject_id=' . $this->subject->id],
            '#90' => ['GET', '/api/teacher/moderation-log'],
            '#96' => ['GET', '/api/admin/audit-logs'],
            '#97' => ['GET', '/api/admin/audit-logs/entity?auditable_type='
                . urlencode(Assignment::class) . '&auditable_id=' . $assignment->id],
            '#98' => ['GET', '/api/admin/audit-logs/user/' . $admin->id],
        ];
    }

    public function testAll16ListEndpointsReturnOnlyDataAndMeta(): void
    {
        $urls = $this->allListUrls();

        $this->assertCount(16, $urls);

        foreach ($urls as $label => [$method, $url]) {
            Sanctum::actingAs(str_starts_with($url, '/api/admin/') ? $this->admin : $this->teacher);

            $response = $this->getJson($url);

            $response->assertOk();
            $this->assertIsArray($response->json('data'), "$label data must be present");
            $this->assertIsArray($response->json('meta'), "$label meta must be present");

            $json = $response->json();
            $topLevel = ['data', 'meta'];
            $this->assertSame($topLevel, array_keys($json), "$label top level must be ONLY data + meta");

            foreach (self::RAW_PAGINATOR_KEYS as $forbidden) {
                $this->assertArrayNotHasKey($forbidden, $json, "$label must not expose raw-paginator key '$forbidden'");
            }

            $this->assertSame(
                self::META_KEYS,
                array_keys($json['meta']),
                "$label meta keys must be exactly the 6 helper keys"
            );
        }
    }

    public function testPerPageClampsOnRepresentativeEndpoints(): void
    {
        $this->setUpOrg();
        $this->actAsAdmin();

        // Teacher-scoped reads (#53/#83) require classroom-derived ownership:
        // give the teacher a classroom for the sweep subject (Semester canonical).
        app(\App\Services\ClassroomService::class)->createClassroom($this->teacher->id, $this->subject->id, $this->section->id, '2026-2027', null);

        $cases = [
            ['#5 admin users', '/api/admin/users'],
            ['#53 teacher assessments', '/api/teacher/assessments'],
            [
                '#83 learning materials',
                '/api/teacher/learning-materials?subject_id=' . $this->subject->id,
            ],
        ];

        foreach ($cases as [$label, $url]) {
            Sanctum::actingAs(str_starts_with($url, '/api/admin/') ? $this->admin : $this->teacher);

            $joiner = str_contains($url, '?') ? '&' : '?';

            $over = $this->getJson($url . $joiner . 'per_page=1000000');
            $over->assertOk();
            $clamped = $over->json('meta.per_page');
            $this->assertSame(100, $clamped, "$label per_page=1000000 must clamp to 100");

            foreach (['0', '-3', 'abc'] as $invalid) {
                $response = $this->getJson($url . $joiner . 'per_page=' . $invalid);
                $response->assertOk();
                $this->assertSame(15, $response->json('meta.per_page'), "$label per_page=$invalid must clamp to 15");
            }
        }
    }
}
