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

class AdminClassroomMasteryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $teacher;
        private Classroom $classroom;
    private Subject $subject;
    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'Admin', 'must_change_password' => false]);
        $this->teacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);

        $year = SchoolYear::create(['name' => 'SY-Mastery']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id, 'name' => 'Semester 1', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31']);
        $gradeLevel = GradeLevel::create(['semester_id' => $semester->id, 'grade_level' => '7']);
        $this->section = Section::create(['grade_level_id' => $gradeLevel->id, 'name' => '7A-Mastery']);
        $this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Math-Mastery', 'code' => 'MATH-M']);

        // Teacher classroom owns the (teacher, subject, section, school year) scope.
        $service = app(ClassroomService::class);
        Sanctum::actingAs($this->teacher);
        $this->classroom = $service->createClassroom($this->teacher->id, $this->subject->id, $this->section->id, '2026-2027', null);
    }

    public function test_admin_classrooms_index_returns_200_with_mastery_and_pagination(): void
    {
        Sanctum::actingAs($this->admin);
        $resp = $this->getJson('/api/admin/classrooms?per_page=15&page=1');
        $resp->assertStatus(200);
        $resp->assertJsonStructure(['data', 'meta' => ['current_page', 'from', 'last_page', 'per_page', 'to', 'total']]);
        $data = $resp->json('data');
        $this->assertIsArray($data);
        $this->assertGreaterThanOrEqual(1, count($data));
        $first = $data[0];
        $this->assertArrayHasKey('mastery', $first);
        $mastery = $first['mastery'];
        $this->assertIsArray($mastery);
        // empty should have defaults
        $this->assertArrayHasKey('average_mastery_percent', $mastery);
        $this->assertArrayHasKey('total_records', $mastery);
        $this->assertArrayHasKey('mastered_count', $mastery);
        $this->assertEquals(0, $mastery['total_records']);
        $this->assertNull($mastery['average_mastery_percent']);
        echo "Empty mastery defaults verified\n";
    }

    public function test_admin_classrooms_mastery_computes_average_and_counts_filtered_by_classroom_only(): void
    {
        // Create a second subject + section + classroom to ensure filtering is isolated
        $subject2 = Subject::create(['grade_level_id' => $this->section->grade_level_id, 'name' => 'Science-Mastery', 'code' => 'SCI-M']);
        $section2 = Section::create(['grade_level_id' => $this->section->grade_level_id, 'name' => '7B-Mastery']);
        $service = app(ClassroomService::class);
        Sanctum::actingAs($this->teacher);
        $classroom2 = $service->createClassroom($this->teacher->id, $subject2->id, $section2->id, '2026-2027', 'Second');

        // Create competencies
        $comp1 = CompetencyReference::create(['semester' => '1', 'code' => 'C-M-1', 'descriptor' => 'Comp 1', 'grade_level' => '7', 'subject_id' => Subject::first()->id]);
        $comp2 = CompetencyReference::create(['semester' => '1', 'code' => 'C-M-2', 'descriptor' => 'Comp 2', 'grade_level' => '7', 'subject_id' => Subject::first()->id]);
        $student = User::factory()->create(['role' => 'Student', 'must_change_password' => false]);
        $student2 = User::factory()->create(['role' => 'Student', 'must_change_password' => false]);

        // Need assessment and submission for FKs
        $semesterId = Semester::first()->id;
        $assessmentId = DB::table('assessments')->insertGetId([
            'teacher_id' => $this->teacher->id,
            'subject_id' => $this->subject->id,
            'classroom_id' => $this->classroom->id,
            'semester_id' => $semesterId,
            'title' => 'Assessment Mastery 1',
            'type' => 'Recorded',
            'status' => 'released',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $attemptId = DB::table('assessment_attempts')->insertGetId([
            'assessment_id' => $assessmentId,
            'student_id' => $student->id,
            'attempt_number' => 1,
            'status' => 'scored',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $submissionId = DB::table('assessment_submissions')->insertGetId([
            'assessment_id' => $assessmentId,
            'student_id' => $student->id,
            'attempt_id' => $attemptId,
            'semester_id' => $semesterId,
            'section_id' => $this->section->id,
            'submitted_at' => now(),
            'status' => 'scored',
            'is_results_released' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Need at least one item for each competency? Not required for mastery_records FKs but we need valid competency and assessment
        $item1 = DB::table('assessment_items')->insertGetId([
            'assessment_id' => $assessmentId,
            'competency_tag_id' => $comp1->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $item2 = DB::table('assessment_items')->insertGetId([
            'assessment_id' => $assessmentId,
            'competency_tag_id' => $comp2->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q2',
            'max_points' => 10,
            'sort_order' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create mastery records for classroom 1: two records 80 and 60 => avg 70, mastered 1, not 1, distinct students 1
        MasteryRecord::create([
            'student_id' => $student->id,
            'subject_id' => $this->subject->id,
            'classroom_id' => $this->classroom->id,
            'assessment_id' => $assessmentId,
            'assessment_submission_id' => $submissionId,
            'competency_id' => $comp1->id,
            'mastery_percent' => 80.00,
            'mastery_status' => 'Mastered',
        ]);
        // second record for same student different competency 60
        // Need second submission? Use same submission but different competency still unique constraint includes competency_id distinct so okay, but submission_id same would conflict unique? Unique is (student, competency, assessment, submission) so different competency is okay same submission
        MasteryRecord::create([
            'student_id' => $student->id,
            'subject_id' => $this->subject->id,
            'classroom_id' => $this->classroom->id,
            'assessment_id' => $assessmentId,
            'assessment_submission_id' => $submissionId,
            'competency_id' => $comp2->id,
            'mastery_percent' => 60.00,
            'mastery_status' => 'Not_Mastered',
        ]);

        // Add a record for the OTHER classroom to ensure filtering isolates (should not affect first classroom)
        $assessmentId2 = DB::table('assessments')->insertGetId([
            'teacher_id' => $this->teacher->id,
            'subject_id' => $subject2->id,
            'classroom_id' => $classroom2->id,
            'semester_id' => $semesterId,
            'title' => 'Assessment Other',
            'type' => 'Recorded',
            'status' => 'released',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $attemptId2 = DB::table('assessment_attempts')->insertGetId([
            'assessment_id' => $assessmentId2,
            'student_id' => $student->id,
            'attempt_number' => 1,
            'status' => 'scored',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $submissionId2 = DB::table('assessment_submissions')->insertGetId([
            'assessment_id' => $assessmentId2,
            'student_id' => $student->id,
            'attempt_id' => $attemptId2,
            'semester_id' => $semesterId,
            'section_id' => $section2->id,
            'submitted_at' => now(),
            'status' => 'scored',
            'is_results_released' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('assessment_items')->insertGetId([
            'assessment_id' => $assessmentId2,
            'competency_tag_id' => $comp1->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q other',
            'max_points' => 10,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        MasteryRecord::create([
            'student_id' => $student->id,
            'subject_id' => $subject2->id,
            'classroom_id' => $classroom2->id,
            'assessment_id' => $assessmentId2,
            'assessment_submission_id' => $submissionId2,
            'competency_id' => $comp1->id,
            'mastery_percent' => 100.00,
            'mastery_status' => 'Mastered',
        ]);

        Sanctum::actingAs($this->admin);
        $resp = $this->getJson('/api/admin/classrooms?per_page=100&page=1');
        $resp->assertStatus(200);
        $data = $resp->json('data');
        // Find classroom 1
        $c1 = collect($data)->firstWhere('id', $this->classroom->id);
        $this->assertNotNull($c1, 'Classroom 1 not found in response');
        $m1 = $c1['mastery'];
        $this->assertEquals(2, $m1['total_records']);
        $this->assertEquals(70.0, $m1['average_mastery_percent']);
        $this->assertEquals(70.0, $m1['average']);
        $this->assertEquals(1, $m1['mastered_count']);
        $this->assertEquals(1, $m1['not_mastered_count']);
        $this->assertEquals(1, $m1['total_students_assessed']);
        // The other classroom should have 1 record avg 100
        $c2 = collect($data)->firstWhere('id', $classroom2->id);
        $this->assertNotNull($c2);
        $m2 = $c2['mastery'];
        $this->assertEquals(1, $m2['total_records']);
        $this->assertEquals(100.0, $m2['average_mastery_percent']);
        $this->assertEquals(1, $m2['mastered_count']);
        echo "Filtered mastery verified\n";
    }

    public function test_classrooms_second_year_same_subject_isolated_mastery(): void
    {
        // Create another classroom with the same subject + section but a different school year.
        // Mastery is scoped per classroom (classroom_id), so the new classroom starts empty.
        $service = app(ClassroomService::class);
        Sanctum::actingAs($this->teacher);
        $classroomNextYear = $service->createClassroom($this->teacher->id, $this->subject->id, $this->section->id, '2025-2026', 'NextYear');

        $comp = CompetencyReference::create(['semester' => '1', 'code' => 'C-M-3', 'descriptor' => 'Comp 3', 'grade_level' => '7', 'subject_id' => Subject::first()->id]);
        $student = User::factory()->create(['role' => 'Student', 'must_change_password' => false]);
        $semesterId = Semester::first()->id;
        $assessmentId = DB::table('assessments')->insertGetId([
            'teacher_id' => $this->teacher->id,
            'subject_id' => $this->subject->id,
            'classroom_id' => $this->classroom->id,
            'semester_id' => $semesterId,
            'title' => 'Assessment Shared',
            'type' => 'Recorded',
            'status' => 'released',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $attemptId = DB::table('assessment_attempts')->insertGetId([
            'assessment_id' => $assessmentId,
            'student_id' => $student->id,
            'attempt_number' => 1,
            'status' => 'scored',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $submissionId = DB::table('assessment_submissions')->insertGetId([
            'assessment_id' => $assessmentId,
            'student_id' => $student->id,
            'attempt_id' => $attemptId,
            'semester_id' => $semesterId,
            'section_id' => $this->section->id,
            'submitted_at' => now(),
            'status' => 'scored',
            'is_results_released' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('assessment_items')->insertGetId([
            'assessment_id' => $assessmentId,
            'competency_tag_id' => $comp->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q shared',
            'max_points' => 10,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        MasteryRecord::create([
            'student_id' => $student->id,
            'subject_id' => $this->subject->id,
            'classroom_id' => $this->classroom->id,
            'assessment_id' => $assessmentId,
            'assessment_submission_id' => $submissionId,
            'competency_id' => $comp->id,
            'mastery_percent' => 90.00,
            'mastery_status' => 'Mastered',
        ]);

        Sanctum::actingAs($this->admin);
        $resp = $this->getJson('/api/admin/classrooms?per_page=100&page=1');
        $resp->assertStatus(200);
        $data = $resp->json('data');
        $orig = collect($data)->firstWhere('id', $this->classroom->id);
        $next = collect($data)->firstWhere('id', $classroomNextYear->id);
        $this->assertNotNull($orig);
        $this->assertNotNull($next);
        // Mastery is isolated per classroom: the new classroom has no records.
        $this->assertEquals(1, $orig['mastery']['total_records']);
        $this->assertEquals(0, $next['mastery']['total_records']);
        $this->assertNull($next['mastery']['average_mastery_percent']);
        echo "Isolated classroom mastery across years verified\n";
    }

    public function test_pagination_kept(): void
    {
        Sanctum::actingAs($this->admin);
        // per_page clamping and page handling
        $resp = $this->getJson('/api/admin/classrooms?per_page=1&page=1');
        $resp->assertStatus(200);
        $meta = $resp->json('meta');
        $this->assertEquals(1, $meta['per_page']);
        $this->assertEquals(1, $meta['current_page']);
        $resp2 = $this->getJson('/api/admin/classrooms?per_page=1&page=2');
        $resp2->assertStatus(200);
        $this->assertEquals(2, $resp2->json('meta.current_page'));
        echo "Pagination kept verified\n";
    }

    public function test_handles_missing_subject_id_gracefully(): void
    {
        // The stats helper scopes by classroom_id, so a classroom without a
        // persisted id (or with an unknown subject) yields empty stats.
        // We'll manually craft Classroom instances for a direct unit call via reflection
        $controller = app(\App\Http\Controllers\Admin\ClassroomController::class);
        $ref = new \ReflectionMethod($controller, 'masteryStatsForClassroom');
        $ref->setAccessible(true);
        $fake = new Classroom(['subject_id' => null]);
        $result = $ref->invoke($controller, $fake);
        $this->assertIsArray($result);
        $this->assertEquals(0, $result['total_records']);
        $this->assertNull($result['average_mastery_percent']);

        $fake2 = new Classroom(['subject_id' => 999999]);
        $result2 = $ref->invoke($controller, $fake2);
        $this->assertEquals(0, $result2['total_records']);
        $this->assertNull($result2['average_mastery_percent']);
        echo "Missing handling verified\n";
    }
}
