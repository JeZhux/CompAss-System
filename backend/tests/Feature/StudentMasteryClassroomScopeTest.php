<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\CompetencyReference;
use App\Models\GradeLevel;
use App\Models\MasteryRecord;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Semester;
use App\Models\User;
use App\Services\ClassroomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentMasteryClassroomScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;
    private User $student;
            private Classroom $classA;
    private Classroom $classB;
    private CompetencyReference $compA;
    private CompetencyReference $compB;
    private Semester $semester;
    private Subject $subjectA;
    private Subject $subjectB;
    private Section $sectionA;
    private Section $sectionB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $this->student = User::factory()->create(['role' => 'Student', 'must_change_password' => false]);

        $year = SchoolYear::create(['name' => 'SY-SM']);
        $this->semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id, 'name' => 'Semester 1', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31']);
        $gl = GradeLevel::create(['semester_id' => $this->semester->id, 'grade_level' => '7']);
        $this->sectionA = Section::create(['grade_level_id' => $gl->id, 'name' => '7A-SM']);
        $this->sectionB = Section::create(['grade_level_id' => $gl->id, 'name' => '7B-SM']);
        $this->subjectA = Subject::create(['grade_level_id' => $gl->id, 'name' => 'Math-SM', 'code' => 'MATH-SM']);
        $this->subjectB = Subject::create(['grade_level_id' => $gl->id, 'name' => 'Science-SM', 'code' => 'SCI-SM']);

        $this->compA = CompetencyReference::create(['semester' => '1', 'code' => 'C-A-1', 'descriptor' => 'Comp A', 'grade_level' => '7', 'subject_id' => $this->subjectA->id]);
        $this->compB = CompetencyReference::create(['semester' => '1', 'code' => 'C-B-1', 'descriptor' => 'Comp B', 'grade_level' => '7', 'subject_id' => $this->subjectB->id]);

        $svc = app(ClassroomService::class);
        Sanctum::actingAs($this->teacher);
        $this->classA = $svc->createClassroom($this->teacher->id, $this->subjectA->id, $this->sectionA->id, '2026-2027', 'A');
        $this->classB = $svc->createClassroom($this->teacher->id, $this->subjectB->id, $this->sectionB->id, '2026-2027', 'B');

        Sanctum::actingAs($this->student);
        $this->postJson('/api/classrooms/join', ['key' => $this->classA->join_key])->assertStatus(201);
        $this->postJson('/api/classrooms/join', ['key' => $this->classB->join_key])->assertStatus(201);

        // Create assessments and mastery records disjoint by classroom
        $assessA = DB::table('assessments')->insertGetId([
            'teacher_id' => $this->teacher->id, 'subject_id' => $this->subjectA->id, 'classroom_id' => $this->classA->id, 'semester_id' => $this->semester->id, 'title' => 'Assess A', 'type' => 'Recorded', 'status' => 'released', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $assessB = DB::table('assessments')->insertGetId([
            'teacher_id' => $this->teacher->id, 'subject_id' => $this->subjectB->id, 'classroom_id' => $this->classB->id, 'semester_id' => $this->semester->id, 'title' => 'Assess B', 'type' => 'Recorded', 'status' => 'released', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $attemptA = DB::table('assessment_attempts')->insertGetId(['assessment_id' => $assessA, 'student_id' => $this->student->id, 'attempt_number' => 1, 'status' => 'scored', 'created_at' => now(), 'updated_at' => now()]);
        $attemptB = DB::table('assessment_attempts')->insertGetId(['assessment_id' => $assessB, 'student_id' => $this->student->id, 'attempt_number' => 1, 'status' => 'scored', 'created_at' => now(), 'updated_at' => now()]);
        $subA = DB::table('assessment_submissions')->insertGetId(['assessment_id' => $assessA, 'student_id' => $this->student->id, 'attempt_id' => $attemptA, 'semester_id' => $this->semester->id, 'section_id' => $this->sectionA->id, 'submitted_at' => now(), 'status' => 'scored', 'is_results_released' => true, 'created_at' => now(), 'updated_at' => now()]);
        $subB = DB::table('assessment_submissions')->insertGetId(['assessment_id' => $assessB, 'student_id' => $this->student->id, 'attempt_id' => $attemptB, 'semester_id' => $this->semester->id, 'section_id' => $this->sectionB->id, 'submitted_at' => now(), 'status' => 'scored', 'is_results_released' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('assessment_items')->insert(['assessment_id' => $assessA, 'competency_tag_id' => $this->compA->id, 'item_type' => 'multiple_choice', 'prompt' => 'Q A', 'max_points' => 10, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('assessment_items')->insert(['assessment_id' => $assessB, 'competency_tag_id' => $this->compB->id, 'item_type' => 'multiple_choice', 'prompt' => 'Q B', 'max_points' => 10, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);

        MasteryRecord::create(['student_id' => $this->student->id, 'subject_id' => $this->subjectA->id, 'classroom_id' => $this->classA->id, 'assessment_id' => $assessA, 'assessment_submission_id' => $subA, 'competency_id' => $this->compA->id, 'mastery_percent' => 85, 'mastery_status' => 'Mastered']);
        MasteryRecord::create(['student_id' => $this->student->id, 'subject_id' => $this->subjectB->id, 'classroom_id' => $this->classB->id, 'assessment_id' => $assessB, 'assessment_submission_id' => $subB, 'competency_id' => $this->compB->id, 'mastery_percent' => 60, 'mastery_status' => 'Not_Mastered']);
    }

    public function test_student_mastery_disjoint_by_classroom(): void
    {
        Sanctum::actingAs($this->student);
        // Via generic mastery endpoint filtered by subject
        $a = $this->getJson('/api/mastery/records?subject_id='.$this->subjectA->id)->assertOk()->json('data');
        $b = $this->getJson('/api/mastery/records?subject_id='.$this->subjectB->id)->assertOk()->json('data');
        $this->assertNotEmpty($a);
        $this->assertNotEmpty($b);
        $aCompIds = array_column($a, 'competency_id');
        $bCompIds = array_column($b, 'competency_id');
        $this->assertContains($this->compA->id, $aCompIds);
        $this->assertNotContains($this->compB->id, $aCompIds);
        $this->assertContains($this->compB->id, $bCompIds);
        $this->assertNotContains($this->compA->id, $bCompIds);

        // Teacher viewing student's mastery with subject filter
        Sanctum::actingAs($this->teacher);
        $ta = $this->getJson('/api/mastery/records?student_id='.$this->student->id.'&subject_id='.$this->subjectA->id)->assertOk()->json('data');
        $this->assertEquals(1, count($ta));
        $this->assertEquals($this->compA->id, $ta[0]['competency_id']);
    }

    public function test_same_subject_across_two_school_years_isolated(): void
    {
        // Second classroom: same subject + section, different year. Mastery is
        // scoped per classroom, so the new classroom starts empty.
        $svc = app(ClassroomService::class);
        Sanctum::actingAs($this->teacher);
        $classA2 = $svc->createClassroom($this->teacher->id, $this->subjectA->id, $this->sectionA->id, '2025-2026', 'A2');
        Sanctum::actingAs($this->student);
        $this->postJson('/api/classrooms/join', ['key' => $classA2->join_key])->assertStatus(201);

        // Subject filter still returns the original record only.
        Sanctum::actingAs($this->student);
        $records = $this->getJson('/api/mastery/records?subject_id='.$this->subjectA->id)->assertOk()->json('data');
        $this->assertEquals(1, count($records));
        // Admin stats are isolated per classroom: new classroom has zero records.
        $admin = User::factory()->create(['role' => 'Admin', 'must_change_password' => false]);
        Sanctum::actingAs($admin);
        $resp = $this->getJson('/api/admin/classrooms?per_page=100')->assertOk()->json('data');
        $c1 = collect($resp)->firstWhere('id', $this->classA->id);
        $c2 = collect($resp)->firstWhere('id', $classA2->id);
        $this->assertEquals(1, $c1['mastery']['total_records']);
        $this->assertEquals(0, $c2['mastery']['total_records']);
    }

    public function test_student_cannot_view_other_students_mastery_403(): void
    {
        $other = User::factory()->create(['role' => 'Student', 'must_change_password' => false]);
        Sanctum::actingAs($this->student);
        $this->getJson('/api/mastery/records?student_id='.$other->id)->assertStatus(403);
        $this->getJson('/api/mastery/records/'.$other->id.'/summary')->assertStatus(403);
    }

    public function test_teacher_can_view_enrolled_student_mastery_and_not_unenrolled(): void
    {
        $unenrolledStudent = User::factory()->create(['role' => 'Student', 'must_change_password' => false]);
        // teacher not assigned to unenrolled's section, should 403
        Sanctum::actingAs($this->teacher);
        $this->getJson('/api/mastery/records?student_id='.$unenrolledStudent->id)->assertStatus(403);
        // but can view enrolled student
        $this->getJson('/api/mastery/records?student_id='.$this->student->id)->assertOk();
    }

    public function test_mastery_via_classroom_admin_mastery_stats_scoped(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'must_change_password' => false]);
        Sanctum::actingAs($admin);
        $resp = $this->getJson('/api/admin/classrooms?per_page=100')->assertOk()->json('data');
        $cA = collect($resp)->firstWhere('id', $this->classA->id);
        $cB = collect($resp)->firstWhere('id', $this->classB->id);
        $this->assertEquals(1, $cA['mastery']['total_records']);
        $this->assertEquals(1, $cB['mastery']['total_records']);
        $this->assertEquals(85.0, $cA['mastery']['average_mastery_percent']);
        $this->assertEquals(60.0, $cB['mastery']['average_mastery_percent']);
        $this->assertNotEquals($cA['mastery']['average_mastery_percent'], $cB['mastery']['average_mastery_percent']);
    }
}
