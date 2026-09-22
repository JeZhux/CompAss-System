<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\ClassroomEnrollment;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Semester;
use App\Models\User;
use App\Services\ClassroomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F-12 — Student collection endpoints are paginated via the shared
 * {data, meta} contract (ARCH-005 §2, ARCH-005 §2).
 *
 * Covers GET /api/student/assignments (#49) and
 * GET /api/student/announcements (#38).
 */
class F12StudentCollectionPaginationTest extends TestCase
{
    use RefreshDatabase;

    private ?Subject $subject = null;

    private const META_KEYS = ['current_page', 'from', 'last_page', 'per_page', 'to', 'total'];

    private User $teacher;

    private User $student;

    
    private Classroom $classroom;

    private Semester $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $this->student = User::factory()->create(['role' => 'Student', 'must_change_password' => false]);

        $year = SchoolYear::create(['name' => 'SY-F12']);
        $this->term = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
        ]);
        $gradeLevel = GradeLevel::create(['semester_id' => $this->term->id, 'grade_level' => '7']);
        $section = Section::create(['grade_level_id' => $gradeLevel->id, 'name' => '7A-F12']);
$this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-F12']);
                $this->classroom = app(ClassroomService::class)->createClassroom($this->teacher->id, $this->subject->id, $section->id, '2026-2027', null);
        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->student->id,
            'joined_at' => now(),
        ]);
    }

    private function seedAssignments(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            Assignment::create([
                'teacher_id' => $this->teacher->id,
                'classroom_id' => $this->classroom->id,
                'subject_id' => $this->subject->id,
                'semester_id' => $this->term->id,
                'title' => 'HW '.$i,
                'description' => 'Desc '.$i,
                'due_date' => '2026-12-15 23:59:59',
            ]);
        }
    }

    private function seedAnnouncements(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            Announcement::create([
                'teacher_id' => $this->teacher->id,
                'classroom_id' => $this->classroom->id,
                'subject_id' => $this->subject->id,
                'title' => 'Note '.$i,
                'body' => 'Body '.$i,
            ]);
        }
    }

    private function assertSharedContract(string $url): array
    {
        Sanctum::actingAs($this->student);
        $json = $this->getJson($url)->assertOk()->json();
        $this->assertSame(['data', 'meta'], array_keys($json));
        $this->assertSame(self::META_KEYS, array_keys($json['meta']));

        return $json;
    }

    public function test_student_assignments_pagination_contract(): void
    {
        $this->seedAssignments(3);

        Sanctum::actingAs($this->student);
        $page1 = $this->getJson('/api/student/assignments?per_page=1')->assertOk();
        $this->assertCount(1, $page1->json('data'));
        $this->assertSame(3, $page1->json('meta.total'));
        $this->assertSame(3, $page1->json('meta.last_page'));
        $this->assertSame(1, $page1->json('meta.per_page'));

        $page2 = $this->getJson('/api/student/assignments?per_page=1&page=2')->assertOk();
        $this->assertCount(1, $page2->json('data'));
        $this->assertNotEquals($page1->json('data.0.id'), $page2->json('data.0.id'));

        $json = $this->assertSharedContract('/api/student/assignments');
        $this->assertSame(15, $json['meta']['per_page']);
        $this->assertStringNotContainsString('password', strtolower((string) json_encode($json)));
    }

    public function test_student_assignments_pagination_per_page_clamp(): void
    {
        $this->seedAssignments(1);

        Sanctum::actingAs($this->student);
        $this->assertSame(15, $this->getJson('/api/student/assignments?per_page=0')->assertOk()->json('meta.per_page'));
        $this->assertSame(15, $this->getJson('/api/student/assignments?per_page=abc')->assertOk()->json('meta.per_page'));
        $this->assertSame(100, $this->getJson('/api/student/assignments?per_page=1000000')->assertOk()->json('meta.per_page'));
    }

    public function test_student_announcements_pagination_contract(): void
    {
        $this->seedAnnouncements(3);

        Sanctum::actingAs($this->student);
        $page1 = $this->getJson('/api/student/announcements?per_page=1')->assertOk();
        $this->assertCount(1, $page1->json('data'));
        $this->assertSame(3, $page1->json('meta.total'));
        $this->assertSame(3, $page1->json('meta.last_page'));
        $this->assertSame(1, $page1->json('meta.per_page'));

        $page2 = $this->getJson('/api/student/announcements?per_page=1&page=2')->assertOk();
        $this->assertCount(1, $page2->json('data'));
        $this->assertNotEquals($page1->json('data.0.id'), $page2->json('data.0.id'));

        $json = $this->assertSharedContract('/api/student/announcements');
        $this->assertSame(15, $json['meta']['per_page']);
        $this->assertStringNotContainsString('password', strtolower((string) json_encode($json)));
    }

    public function test_student_announcements_pagination_per_page_clamp(): void
    {
        $this->seedAnnouncements(1);

        Sanctum::actingAs($this->student);
        $this->assertSame(15, $this->getJson('/api/student/announcements?per_page=0')->assertOk()->json('meta.per_page'));
        $this->assertSame(15, $this->getJson('/api/student/announcements?per_page=abc')->assertOk()->json('meta.per_page'));
        $this->assertSame(100, $this->getJson('/api/student/announcements?per_page=1000000')->assertOk()->json('meta.per_page'));
    }

    public function test_student_pagination_empty_enrollment(): void
    {
        $outsider = User::factory()->create(['role' => 'Student', 'must_change_password' => false]);

        Sanctum::actingAs($outsider);
        $this->assertSame([], $this->getJson('/api/student/assignments')->assertOk()->json('data'));
        $this->assertSame(0, $this->getJson('/api/student/assignments')->assertOk()->json('meta.total'));
        $this->assertSame([], $this->getJson('/api/student/announcements')->assertOk()->json('data'));
        $this->assertSame(0, $this->getJson('/api/student/announcements')->assertOk()->json('meta.total'));
    }
}
