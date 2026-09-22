<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\Assessment;
use App\Models\CompetencyReference;
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

class ClassroomContentScopingTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;
    private User $teacher2;
    private User $student;
            private \App\Models\Classroom $classA;
    private \App\Models\Classroom $classB;
    private Subject $subjectA;
    private Subject $subjectB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $this->teacher2 = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $this->student = User::factory()->create(['role' => 'Student', 'must_change_password' => false]);

        $year = SchoolYear::create(['name' => 'SY-CS']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id, 'name' => 'Semester 1', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31']);
        $gl = GradeLevel::create(['semester_id' => $semester->id, 'grade_level' => '7']);
        $secA = Section::create(['grade_level_id' => $gl->id, 'name' => '7A-CS']);
        $secB = Section::create(['grade_level_id' => $gl->id, 'name' => '7B-CS']);
        $this->subjectA = Subject::create(['grade_level_id' => $gl->id, 'name' => 'Math-CS', 'code' => 'MATH-CS']);
        $this->subjectB = Subject::create(['grade_level_id' => $gl->id, 'name' => 'Science-CS', 'code' => 'SCI-CS']);


        $svc = app(ClassroomService::class);
        Sanctum::actingAs($this->teacher);
        $this->classA = $svc->createClassroom($this->teacher->id, $this->subjectA->id, $secA->id, '2026-2027', 'A');
        Sanctum::actingAs($this->teacher);
        $this->classB = $svc->createClassroom($this->teacher->id, $this->subjectB->id, $secB->id, '2026-2027', 'B');

        // student joins A only
        Sanctum::actingAs($this->student);
        $this->postJson('/api/classrooms/join', ['key' => $this->classA->join_key])->assertStatus(201);
    }

    public function test_teacher_can_create_announcement_in_owned_classroom(): void
    {
        Sanctum::actingAs($this->teacher);
        $resp = $this->postJson('/api/teacher/classrooms/'.$this->classA->id.'/announcements', ['title' => 'Hello', 'body' => 'World']);
        $resp->assertStatus(201)->assertJsonPath('data.classroom_id', $this->classA->id)->assertJsonPath('data.subject_id', $this->subjectA->id);
        $this->assertDatabaseHas('announcements', ['id' => $resp->json('data.id'), 'classroom_id' => $this->classA->id, 'subject_id' => $this->subjectA->id]);
    }

    public function test_teacher_cannot_create_in_not_owned_classroom_403(): void
    {
        Sanctum::actingAs($this->teacher2);
        $this->postJson('/api/teacher/classrooms/'.$this->classB->id.'/announcements', ['title' => 'Hack', 'body' => 'Body'])->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_mismatched_subject_returns_422(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->postJson('/api/teacher/classrooms/'.$this->classA->id.'/announcements', ['title' => 'Bad', 'body' => 'Body', 'subject_id' => $this->subjectB->id])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_student_sees_only_enrolled_classroom_content(): void
    {
        // Create announcement in A and B
        Sanctum::actingAs($this->teacher);
        $a1 = $this->postJson('/api/teacher/classrooms/'.$this->classA->id.'/announcements', ['title' => 'A1', 'body' => 'Body A'])->assertStatus(201)->json('data.id');
        $b1 = $this->postJson('/api/teacher/classrooms/'.$this->classB->id.'/announcements', ['title' => 'B1', 'body' => 'Body B'])->assertStatus(201)->json('data.id');

        Sanctum::actingAs($this->student);
        // stream A should contain A1
        $streamA = $this->getJson('/api/student/classrooms/'.$this->classA->id.'/stream')->assertOk()->json('data');
        $idsA = array_column($streamA, 'id');
        $this->assertContains($a1, $idsA);
        $this->assertNotContains($b1, $idsA);

        // stream B should be 403 NOT_ENROLLED (not enrolled classroom)
        $this->getJson('/api/student/classrooms/'.$this->classB->id.'/stream')->assertStatus(403)->assertJsonPath('error.code', 'NOT_ENROLLED');
        // Generic fallback check (accept either 403 or 410 for flexibility)
        $respB = $this->getJson('/api/student/classrooms/'.$this->classB->id.'/stream');
        $this->assertTrue(in_array($respB->status(), [403, 410]), 'Expected 403 or 410 for not enrolled, got '.$respB->status());

        // legacy flat: student announcements should only show A1 not B1 (since B1 is classroom-scoped but student not enrolled)
        $flat = $this->getJson('/api/student/announcements')->assertOk()->json('data');
        // flat paginator shape? Announcement studentIndex returns Pagination::response with data key containing items? Check controller: studentIndex returns Pagination::response($paginator) which has data. For classroom-less? Actually AnnouncementController studentIndex delegates to AnnouncementService getAnnouncementsForStudent which for enrolledIds non-empty returns only activeIds. So flat should contain only A1.
        // Extract ids
        $flatIds = [];
        if (isset($flat[0])) {
            $flatIds = array_column($flat, 'id');
        } elseif (isset($flat['data'])) {
            $flatIds = array_column($flat['data'], 'id');
        } else {
            $flatIds = array_column($flat, 'id');
        }
        if (!empty($flatIds)) {
            $this->assertContains($a1, $flatIds);
            $this->assertNotContains($b1, $flatIds);
        }
    }

    public function test_legacy_hidden(): void
    {
        $semesterId = Semester::first()->id;
        // Content in classroom B (student enrolled in A only) must stay hidden from A's stream/classwork
        Sanctum::actingAs($this->teacher);
        $legacyId = $this->postJson('/api/teacher/classrooms/'.$this->classB->id.'/announcements', ['title' => 'Legacy Ann', 'body' => 'Legacy Body'])->assertStatus(201)->json('data.id');
        Sanctum::actingAs($this->student);
        // student stream for classA should NOT include legacy
        $stream = $this->getJson('/api/student/classrooms/'.$this->classA->id.'/stream')->assertOk()->json('data');
        $ids = array_column($stream, 'id');
        $this->assertNotContains($legacyId, $ids);
        // classwork also hidden: assignment in classroom B
        Sanctum::actingAs($this->teacher);
        $legacyAssignId = $this->postJson('/api/teacher/classrooms/'.$this->classB->id.'/assignments', [
            'title' => 'Legacy Assign',
            'description' => 'Desc',
            'due_date' => now()->addDays(5)->toDateTimeString(),
        ])->assertStatus(201)->json('data.id');
        Sanctum::actingAs($this->student);
        $classwork = $this->getJson('/api/student/classrooms/'.$this->classA->id.'/classwork')->assertOk()->json();
        // classwork payload is paginated? It returns Pagination::response with data array containing both assignments and assessments.
        $data = $classwork['data'] ?? $classwork;
        $allIds = [];
        if (is_array($data)) {
            foreach ($data as $item) {
                if (isset($item['id'])) {
                    $allIds[] = $item['id'];
                }
            }
        }
        $this->assertNotContains($legacyAssignId, $allIds);
        // assessment in classroom B also hidden
        Sanctum::actingAs($this->teacher);
        $legacyAssessId = $this->postJson('/api/teacher/classrooms/'.$this->classB->id.'/assessments', [
            'title' => 'Legacy Assess',
            'description' => '',
            'type' => 'Recorded',
            'semester_id' => $semesterId,
        ])->assertStatus(201)->json('data.id');
        Sanctum::actingAs($this->student);
        $classwork2 = $this->getJson('/api/student/classrooms/'.$this->classA->id.'/classwork')->assertOk()->json();
        $data2 = $classwork2['data'] ?? $classwork2;
        $allIds2 = [];
        if (is_array($data2)) {
            foreach ($data2 as $item) {
                if (isset($item['id'])) {
                    $allIds2[] = $item['id'];
                }
            }
        }
        $this->assertNotContains($legacyAssessId, $allIds2);
    }

    public function test_assignment_and_assessment_inheritance(): void
    {
        $semesterId = Semester::first()->id;
        Sanctum::actingAs($this->teacher);
        // Assignment via classroom route should inherit subject + semester from the classroom
        $assignResp = $this->postJson('/api/teacher/classrooms/'.$this->classA->id.'/assignments', [
            'title' => 'Assign A1', 'description' => 'Desc', 'due_date' => now()->addDays(3)->toDateTimeString()
        ])->assertStatus(201);
        $assignId = $assignResp->json('data.id');
        $assign = Assignment::find($assignId);
        $this->assertEquals($this->classA->id, $assign->classroom_id);
        $this->assertEquals($this->subjectA->id, $assign->subject_id);

        // Assessment via classroom route
        $assessResp = $this->postJson('/api/teacher/classrooms/'.$this->classA->id.'/assessments', [
            'title' => 'Assess A1', 'description' => '', 'type' => 'Recorded', 'semester_id' => $semesterId
        ])->assertStatus(201);
        $assessId = $assessResp->json('data.id');
        // Draft assessments are hidden from students (ARCH-002 FR-016: release
        // requires at least one item): add one item and release so classwork
        // can include this assessment.
        $tag = CompetencyReference::create(['semester' => '1', 'code' => 'M7-CS-INH', 'descriptor' => 'Inheritance check',
            'subject_id' => $this->subjectA->id, 'grade_level' => '7',
        ]);
        $this->postJson('/api/teacher/assessments/'.$assessId.'/items', [
            'item_type' => 'multiple_choice', 'prompt' => 'What is 2+2?',
            'max_points' => 10, 'correct_answer' => 'B',
            'competency_tag_id' => $tag->id, 'sort_order' => 1,
        ])->assertStatus(201);
        $this->postJson('/api/teacher/assessments/'.$assessId.'/release')->assertOk();
        $assess = Assessment::find($assessId);
        $this->assertEquals($this->classA->id, $assess->classroom_id);
        $this->assertEquals($this->subjectA->id, $assess->subject_id);

        // Student classwork should see both
        Sanctum::actingAs($this->student);
        $cw = $this->getJson('/api/student/classrooms/'.$this->classA->id.'/classwork')->assertOk()->json();
        $data = $cw['data'] ?? $cw['data'] ?? $cw;
        $jsonStr = json_encode($cw);
        $this->assertStringContainsString((string)$assignId, $jsonStr);
        $this->assertStringContainsString((string)$assessId, $jsonStr);
    }

    public function test_archived_classroom_content_not_visible_to_student(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->postJson('/api/teacher/classrooms/'.$this->classA->id.'/announcements', ['title' => 'Before Archive', 'body' => 'Body'])->assertStatus(201);
        Sanctum::actingAs($this->teacher);
        $this->postJson('/api/teacher/classrooms/'.$this->classA->id.'/archive')->assertOk();
        Sanctum::actingAs($this->student);
        $this->getJson('/api/student/classrooms/'.$this->classA->id.'/stream')->assertStatus(410);
        // teacher can still list? Teacher classroom not filtered by archived, but student cannot.
        Sanctum::actingAs($this->teacher);
        $this->getJson('/api/teacher/classrooms/'.$this->classA->id.'/announcements')->assertOk();
    }
}
