<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\Assessment;
use App\Models\AssessmentItem;
use App\Models\AssessmentSubmission;
use App\Models\Classroom;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 *
 * Edge-case and data-correctness tests for Phase 6 (Analytics Dashboard,
 * API Interface Spec §3.8, endpoints #77–#82).
 *
 * Complements Phase6AccessControlTest with:
 *  - multi-competency / multi-student aggregation
 *  - partial mastery-rate calculations (not just 100 %)
 *  - gap-report not_mastered_percent and student-level Mastered exclusion (ARCH-002 FR-020)
 *  - trends ordering across multiple assessments
 *  - drill-down Not-Competent flag inclusion (ARCH-002 FR-020, ARCH-002 FR-020)
 *  - school-wide aggregates following configured grade levels (ARCH-004 §10)
 *  - append-only mastery history and current-status derivation (ARCH-002 FR-020)
 *  - Unrecorded exclusion across every endpoint (ARCH-002 FR-021, ARCH-002 FR-021)
 *  - error-envelope structure consistency (ARCH-002 QA-007)
 */
#[Group('phase6-edge')]
class Phase6EdgeCaseTest extends TestCase
{
    use RefreshDatabase;

    private ?User $teacher = null;

    private ?User $student = null;

    private ?User $otherStudent = null;

    private ?User $admin = null;

    private ?User $otherTeacher = null;

    private ?User $mustChangeTeacher = null;

    private ?User $mustChangeAdmin = null;

    
    private ?Section $section = null;

    private ?Subject $subject = null;

    private ?CompetencyReference $competencyTag1 = null;

    private ?CompetencyReference $competencyTag2 = null;

    private ?Classroom $classroom = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = $this->student = $this->otherStudent = $this->admin = null;
        $this->otherTeacher = $this->mustChangeTeacher = $this->mustChangeAdmin = null;
        $this->section = $this->subject = null;
        $this->competencyTag1 = $this->competencyTag2 = null;
        $this->classroom = null;
    }

    /**
     * Build a full org hierarchy with TWO competency tags for grouping tests.
     */
    private function setUpOrg(): void
    {
        $year = SchoolYear::create(['name' => 'SY 2026-P6-EDGE']);
        $term = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-06-01',
            'end_date' => '2026-08-31']);
        $gradeLevel = GradeLevel::create(['semester_id' => $term->id, 'grade_level' => '7']);
        $this->section = Section::create([
            'grade_level_id' => $gradeLevel->id,
            'name' => '7A-P6-EDGE']);
        $this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-P6-EDGE']);
        
        $this->competencyTag1 = CompetencyReference::create(['semester' => '1', 'code' => 'M7-ALG-01',
            'descriptor' => 'Linear equations',
            'subject_id' => $this->subject->id,
            'grade_level' => '7']);
        $this->competencyTag2 = CompetencyReference::create(['semester' => '1', 'code' => 'M7-GEO-02',
            'descriptor' => 'Geometric figures',
            'subject_id' => $this->subject->id,
            'grade_level' => '7']);
    }

    private function ensureAllUsers(): void
    {
        $this->setUpOrg();

        $this->teacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $this->otherTeacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $this->student = User::factory()->create(['role' => 'Student']);
        $this->otherStudent = User::factory()->create(['role' => 'Student']);
        $this->admin = User::factory()->create(['role' => 'Admin', 'must_change_password' => false]);
        $this->mustChangeTeacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => true]);
        $this->mustChangeAdmin = User::factory()->create(['role' => 'Admin', 'must_change_password' => true]);


        $this->classroom = app(ClassroomService::class)->createClassroom($this->teacher->id, $this->subject->id, $this->section->id, '2026-2027', null);

        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->student->id]);
        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->otherStudent->id]);
    }

    /**
     * Create a classroom for a secondary subject + section and enroll one
     * student (pooled-aggregate fixtures: same-teacher, same-year, own room).
     */
    private function createSecondaryClassroom(Subject $subject, Section $section, User $teacher, User $student, ?string $suffix = null): Classroom
    {

        $classroom = app(ClassroomService::class)->createClassroom(
            $teacher->id,
            $subject->id,
            $section->id,
            '2026-2027',
            $suffix
        );

        ClassroomEnrollment::create([
            'classroom_id' => $classroom->id,
            'student_id' => $student->id]);

        return $classroom;
    }

    /**
     * Create a released Assessment with arbitrary items on specific competency
     * tags. Each $itemsConfig entry: ['competency_tag_id'=>int, 'max_points'=>int,
     * 'correct_answer'=>string|null, 'item_type'=>string].
     */
    private function createAssessmentWithItems(string $type, array $itemsConfig): Assessment
    {
        $assessment = Assessment::create([
            'teacher_id' => $this->teacher->id,
            'classroom_id' => $this->classroom->id,
            'subject_id' => $this->subject->id,
            'semester_id' => $this->section->gradeLevel->semester_id,
            'title' => 'P6-EDGE '.$type,
            'description' => '',
            'type' => $type,
            'status' => 'released']);

        $sortOrder = 1;
        foreach ($itemsConfig as $cfg) {
            AssessmentItem::create([
                'assessment_id' => $assessment->id,
                'item_type' => $cfg['item_type'] ?? 'multiple_choice',
                'prompt' => $cfg['prompt'] ?? "Q{$sortOrder}",
                'max_points' => $cfg['max_points'],
                'correct_answer' => $cfg['correct_answer'] ?? 'A',
                'competency_tag_id' => $cfg['competency_tag_id'],
                'sort_order' => $sortOrder++]);
        }

        return $assessment->fresh();
    }

    /**
     * Student starts and submits an assessment. $responses is a positional
     * array (in item sort-order) of answer strings. Returns the scored
     * AssessmentSubmission. Switches Sanctum context back to teacher afterward.
     *
     * @param  array<int, string>  $responses
     */
    private function studentSubmitAndScore(User $student, Assessment $assessment, array $responses): AssessmentSubmission
    {
        $this->actingAs($student, 'sanctum')
            ->post("/api/student/assessments/{$assessment->id}/start");

        $items = AssessmentItem::where('assessment_id', $assessment->id)
            ->orderBy('sort_order')
            ->get();

        $mapped = [];
        foreach ($responses as $i => $answer) {
            if (isset($items[$i])) {
                $mapped[(string) $items[$i]->id] = $answer;
            }
        }

        $this->actingAs($student, 'sanctum')
            ->post("/api/student/assessments/{$assessment->id}/submit", [
                'responses' => $mapped]);

        return AssessmentSubmission::query()
            ->where('assessment_id', $assessment->id)
            ->where('student_id', $student->id)
            ->where('status', 'scored')
            ->first();
    }

    /*
    |----------------------------------------------------------------------
    | #77 — Heatmap edge cases
    |----------------------------------------------------------------------
    */

    /** @test */
    public function test_77_heatmap_multiple_competencies(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A'],
            ['competency_tag_id' => $this->competencyTag2->id, 'max_points' => 10, 'correct_answer' => 'A']]);

        $this->studentSubmitAndScore($this->student, $assessment, ['A', 'A']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/heatmap?subject_id='.$this->subject->id);

        $response->assertOk();
        $competencies = $response->json('data.competencies');
        $this->assertCount(2, $competencies);
        $this->assertEquals('M7-ALG-01', $competencies[0]['code']);
        $this->assertEquals('M7-GEO-02', $competencies[1]['code']);
        $this->assertEquals(1, $competencies[0]['mastered_count']);
        $this->assertEquals(0, $competencies[0]['not_mastered_count']);
        $this->assertEquals(100.0, $competencies[0]['mastery_rate_percent']);
    }

    /** @test */
    public function test_77_heatmap_partial_mastery_rate(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);

        // Student 1 scores correct → Mastered.
        $this->studentSubmitAndScore($this->student, $assessment, ['A']);
        // Student 2 scores wrong → Not_Mastered.
        $this->studentSubmitAndScore($this->otherStudent, $assessment, ['B']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/heatmap?subject_id='.$this->subject->id);

        $response->assertOk();
        $comp = $response->json('data.competencies.0');
        $this->assertEquals(1, $comp['mastered_count']);
        $this->assertEquals(1, $comp['not_mastered_count']);
        $this->assertEquals(2, $comp['total_students_assessed']);
        $this->assertEquals(50.0, $comp['mastery_rate_percent']);
    }

    /** @test */
    public function test_77_heatmap_multiple_students(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);

        $this->studentSubmitAndScore($this->student, $assessment, ['A']);
        $this->studentSubmitAndScore($this->otherStudent, $assessment, ['A']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/heatmap?subject_id='.$this->subject->id);

        $response->assertOk();
        $comp = $response->json('data.competencies.0');
        $this->assertEquals(2, $comp['total_students_assessed']);
        $this->assertEquals(2, $comp['mastered_count']);
        $this->assertEquals(100.0, $comp['mastery_rate_percent']);
    }

    /** @test */
    public function test_77_heatmap_assessment_id_filter_excludes_other_assessments(): void
    {
        $this->ensureAllUsers();

        $assessment1 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment1, ['A']);

        $assessment2 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag2->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment2, ['A']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/heatmap?subject_id='.$this->subject->id.'&assessment_id='.$assessment1->id);

        $response->assertOk();
        $this->assertEquals($assessment1->id, $response->json('data.assessment_id'));
        $this->assertCount(1, $response->json('data.competencies'));
        $this->assertEquals('M7-ALG-01', $response->json('data.competencies.0.code'));
    }

    /** @test */
    public function test_77_heatmap_error_envelope_structure(): void
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/heatmap');

        $response->assertStatus(400);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertIsString($response->json('error.message'));
        $this->assertIsString($response->json('error.code'));
        $this->assertEquals('BAD_REQUEST', $response->json('error.code'));
    }

    /** @test */
    public function test_77_heatmap_mixed_mastery_counts(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);

        // Create a 3rd student.
        $student3 = User::factory()->create(['role' => 'Student']);
        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $student3->id]);

        $this->studentSubmitAndScore($this->student, $assessment, ['A']);
        $this->studentSubmitAndScore($this->otherStudent, $assessment, ['A']);
        $this->studentSubmitAndScore($student3, $assessment, ['B']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/heatmap?subject_id='.$this->subject->id);

        $response->assertOk();
        $comp = $response->json('data.competencies.0');
        $this->assertEquals(2, $comp['mastered_count']);
        $this->assertEquals(1, $comp['not_mastered_count']);
        $this->assertEquals(3, $comp['total_students_assessed']);
        $this->assertEquals(66.67, $comp['mastery_rate_percent']);
    }

    /*
    |----------------------------------------------------------------------
    | #78 — Gap report edge cases
    |----------------------------------------------------------------------
    */

    /** @test */
    public function test_78_gap_report_section_level_not_mastered_percent(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);

        $this->studentSubmitAndScore($this->student, $assessment, ['A']);
        $this->studentSubmitAndScore($this->otherStudent, $assessment, ['B']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/gap-report?subject_id='.$this->subject->id.'&group_by=section');

        $response->assertOk();
        $gap = $response->json('data.gaps.0');
        $this->assertEquals('M7-ALG-01', $gap['code']);
        $this->assertEquals(1, $gap['not_mastered_count']);
        $this->assertEquals(1, $gap['mastered_count']);
        $this->assertEquals(2, $gap['total_students_assessed']);
        $this->assertEquals(50.0, $gap['not_mastered_percent']);
    }

    /** @test */
    public function test_78_gap_report_student_level_excludes_mastered(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);

        $this->studentSubmitAndScore($this->student, $assessment, ['A']);
        $this->studentSubmitAndScore($this->otherStudent, $assessment, ['B']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/gap-report?subject_id='.$this->subject->id.'&group_by=student');

        $response->assertOk();
        // Only student2 (Not_Mastered) should appear; student1 (Mastered) should NOT.
        $this->assertCount(1, $response->json('data.gaps'));
        $this->assertEquals($this->otherStudent->id, $response->json('data.gaps.0.student_id'));
        $this->assertEquals('Not_Mastered', $response->json('data.gaps.0.mastery_status'));
    }

    /** @test */
    public function test_78_gap_report_student_level_multiple_students_gaps(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A'],
            ['competency_tag_id' => $this->competencyTag2->id, 'max_points' => 10, 'correct_answer' => 'A']]);

        // Student1: 0% on both (both Not_Mastered).
        $this->studentSubmitAndScore($this->student, $assessment, ['B', 'B']);
        // Student2: 100% on comp1 (Mastered), 0% on comp2 (Not_Mastered).
        $this->studentSubmitAndScore($this->otherStudent, $assessment, ['A', 'B']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/gap-report?subject_id='.$this->subject->id.'&group_by=student');

        $response->assertOk();
        // 3 gaps: student1:comp1, student1:comp2, student2:comp2 (student2:comp1 is Mastered → excluded).
        $this->assertCount(3, $response->json('data.gaps'));
    }

    /** @test */
    public function test_78_gap_report_section_mixed_mastery(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A'],
            ['competency_tag_id' => $this->competencyTag2->id, 'max_points' => 10, 'correct_answer' => 'A']]);

        // Student scores 100% on comp1, 0% on comp2.
        $this->studentSubmitAndScore($this->student, $assessment, ['A', 'B']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/gap-report?subject_id='.$this->subject->id.'&group_by=section');

        $response->assertOk();
        $gaps = $response->json('data.gaps');
        // Only comp2 (Not_Mastered) should appear as a gap, not comp1 (Mastered).
        $this->assertCount(1, $gaps);
        $this->assertEquals('M7-GEO-02', $gaps[0]['code']);
    }

    /** @test */
    public function test_78_gap_report_at_threshold_boundary_no_gap(): void
    {
        $this->ensureAllUsers();

        // 5 items, each worth 10 points. Student answers 4 correct (40/50 = 80% → Mastered).
        $items = [];
        for ($i = 0; $i < 5; $i++) {
            $items[] = ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A'];
        }
        $assessment = $this->createAssessmentWithItems('Recorded', $items);

        // 4 correct, 1 wrong.
        $this->studentSubmitAndScore($this->student, $assessment, ['A', 'A', 'A', 'A', 'B']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/gap-report?subject_id='.$this->subject->id.'&group_by=section');

        $response->assertOk();
        $this->assertCount(0, $response->json('data.gaps'));
    }

    /*
    |----------------------------------------------------------------------
    | #79 — Trends edge cases
    |----------------------------------------------------------------------
    */

    /** @test */
    public function test_79_trends_multiple_assessments_ordered_by_date(): void
    {
        $this->ensureAllUsers();

        $assessment1 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment1, ['A']);

        $assessment2 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment2, ['A']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/trends?subject_id='.$this->subject->id);

        $response->assertOk();
        $trends = $response->json('data.trends');
        $this->assertCount(2, $trends);
        // Ordered by assessment created_at ASC — assessment1 before assessment2.
        $this->assertEquals($assessment1->id, $trends[0]['assessment_id']);
        $this->assertEquals($assessment2->id, $trends[1]['assessment_id']);
        $this->assertEquals(100.0, $trends[0]['mastery_rate_percent']);
        $this->assertEquals(100.0, $trends[1]['mastery_rate_percent']);
    }

    /** @test */
    public function test_79_trends_multiple_students_same_assessment(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);

        $this->studentSubmitAndScore($this->student, $assessment, ['A']);
        $this->studentSubmitAndScore($this->otherStudent, $assessment, ['A']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/trends?subject_id='.$this->subject->id);

        $response->assertOk();
        $trends = $response->json('data.trends');
        $this->assertCount(1, $trends);
        $this->assertEquals(2, $trends[0]['total_students_assessed']);
        $this->assertEquals(2, $trends[0]['mastered_count']);
    }

    /** @test */
    public function test_79_trends_competency_code_partial_match(): void
    {
        $this->ensureAllUsers();

        $assessment1 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment1, ['A']);

        $assessment2 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag2->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment2, ['A']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/trends?subject_id='.$this->subject->id.'&competency_code=M7-ALG-01');

        $response->assertOk();
        // Only assessment1 (which has comp1) should appear, not assessment2 (comp2).
        $this->assertCount(1, $response->json('data.trends'));
        $this->assertEquals('M7-ALG-01', $response->json('data.competency_code'));
    }

    /** @test */
    public function test_79_trends_unrecorded_excluded(): void
    {
        $this->ensureAllUsers();

        $recorded = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $recorded, ['A']);

        $unrecorded = $this->createAssessmentWithItems('Unrecorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $unrecorded, ['A']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/trends?subject_id='.$this->subject->id);

        $response->assertOk();
        // Only the Recorded assessment's mastery record produces a trend point.
        $this->assertCount(1, $response->json('data.trends'));
    }

    /*
    |----------------------------------------------------------------------
    | #80 — Student drill-down edge cases
    |----------------------------------------------------------------------
    */

    /** @test */
    public function test_80_drill_down_multiple_competencies(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A'],
            ['competency_tag_id' => $this->competencyTag2->id, 'max_points' => 10, 'correct_answer' => 'A']]);

        $this->studentSubmitAndScore($this->student, $assessment, ['A', 'A']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/student-drill-down?student_id='.$this->student->id.'&subject_id='.$this->subject->id);

        $response->assertOk();
        $competencies = $response->json('data.competencies');
        $this->assertCount(2, $competencies);
        $this->assertEquals('M7-ALG-01', $competencies[0]['code']);
        $this->assertEquals('M7-GEO-02', $competencies[1]['code']);
        $this->assertEquals('Mastered', $competencies[0]['current_mastery_status']);
        $this->assertEquals('Mastered', $competencies[1]['current_mastery_status']);
        $this->assertGreaterThanOrEqual(1, count($competencies[0]['history']));
    }

    /** @test */
    public function test_80_drill_down_includes_not_competent_flags(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);

        // Student scores 0% → Not_Mastered → flag created.
        $this->studentSubmitAndScore($this->student, $assessment, ['B']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/student-drill-down?student_id='.$this->student->id.'&subject_id='.$this->subject->id);

        $response->assertOk();
        $competency = $response->json('data.competencies.0');
        $this->assertEquals('Not_Mastered', $competency['current_mastery_status']);
        $this->assertNotEmpty($competency['not_competent_flags']);
    }

    /** @test */
    public function test_80_drill_down_current_status_is_most_recent(): void
    {
        $this->ensureAllUsers();

        $assessment1 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment1, ['A']);

        $assessment2 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment2, ['B']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/student-drill-down?student_id='.$this->student->id.'&subject_id='.$this->subject->id);

        $response->assertOk();
        $competency = $response->json('data.competencies.0');
        // Latest record (assessment2) → Not_Mastered.
        $this->assertEquals('Not_Mastered', $competency['current_mastery_status']);
        $this->assertCount(2, $competency['history']);
        $this->assertEquals('Not_Mastered', $competency['history'][0]['mastery_status']);
    }

    /** @test */
    public function test_80_drill_down_history_ordered_newest_first(): void
    {
        $this->ensureAllUsers();

        $assessment1 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment1, ['A']);

        $assessment2 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment2, ['B']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/student-drill-down?student_id='.$this->student->id.'&subject_id='.$this->subject->id);

        $response->assertOk();
        $history = $response->json('data.competencies.0.history');
        // Newest first.
        $this->assertEquals($assessment2->id, $history[0]['assessment_id']);
        $this->assertEquals('Not_Mastered', $history[0]['mastery_status']);
        $this->assertEquals($assessment1->id, $history[1]['assessment_id']);
        $this->assertEquals('Mastered', $history[1]['mastery_status']);
    }

    /** @test */
    public function test_80_drill_down_competency_with_no_student_records(): void
    {
        $this->ensureAllUsers();

        // Student1 has a record for comp1 only.
        $assessment1 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment1, ['A']);

        // otherStudent has a record for comp2 (so comp2 appears in the subject-section competency list).
        $assessment2 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag2->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->otherStudent, $assessment2, ['A']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/student-drill-down?student_id='.$this->student->id.'&subject_id='.$this->subject->id);

        $response->assertOk();
        $competencies = $response->json('data.competencies');
        $this->assertCount(2, $competencies);

        $comp1 = collect($competencies)->firstWhere('code', 'M7-ALG-01');
        $comp2 = collect($competencies)->firstWhere('code', 'M7-GEO-02');

        $this->assertEquals('Mastered', $comp1['current_mastery_status']);
        $this->assertGreaterThanOrEqual(1, count($comp1['history']));

        // Student has no records for comp2 → defaults.
        $this->assertEquals('Not_Mastered', $comp2['current_mastery_status']);
        $this->assertEquals(0.0, $comp2['current_mastery_percent']);
        $this->assertCount(0, $comp2['history']);
    }

    /** @test */
    public function test_80_drill_down_unrecorded_excluded(): void
    {
        $this->ensureAllUsers();

        $recorded = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $recorded, ['A']);

        $unrecorded = $this->createAssessmentWithItems('Unrecorded', [
            ['competency_tag_id' => $this->competencyTag2->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $unrecorded, ['A']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/student-drill-down?student_id='.$this->student->id.'&subject_id='.$this->subject->id);

        $response->assertOk();
        // Only comp1 (from Recorded) appears; comp2 (Unrecorded) has no mastery record.
        $this->assertCount(1, $response->json('data.competencies'));
        $this->assertEquals('M7-ALG-01', $response->json('data.competencies.0.code'));
    }

    /*
    |----------------------------------------------------------------------
    | #81 — School-wide overview edge cases
    |----------------------------------------------------------------------
    */

    /** @test */
    public function test_81_school_wide_multiple_grade_levels(): void
    {
        $this->ensureAllUsers();

        // Grade 7 data (existing section).
        $assessment7 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment7, ['A']);

        // Grade 8 org hierarchy.
        $gradeLevel8 = GradeLevel::create([
            'semester_id' => $this->section->gradeLevel->semester_id,
            'grade_level' => '8']);
        $section8 = Section::create([
            'grade_level_id' => $gradeLevel8->id,
            'name' => '8A-P6-EDGE']);
        $subject8 = Subject::create(['grade_level_id' => $gradeLevel8->id, 'name' => 'Science', 'code' => 'SCI8-P6-EDGE']);
        $classroom8 = $this->createSecondaryClassroom($subject8, $section8, $this->teacher, $this->otherStudent, 'G8');

        $comp3 = CompetencyReference::create(['semester' => '1', 'code' => 'M8-GEN-01',
            'descriptor' => 'Grade 8 general',
            'subject_id' => $subject8->id,
            'grade_level' => '8']);

        $assessment8 = Assessment::create([
            'teacher_id' => $this->teacher->id,
            'classroom_id' => $classroom8->id,
            'subject_id' => $subject8->id,
            'semester_id' => $this->section->gradeLevel->semester_id,
            'title' => 'Grade 8 Test',
            'description' => '',
            'type' => 'Recorded',
            'status' => 'released']);
        $item8 = AssessmentItem::create([
            'assessment_id' => $assessment8->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $comp3->id,
            'sort_order' => 1]);

        $this->actingAs($this->otherStudent, 'sanctum')
            ->post("/api/student/assessments/{$assessment8->id}/start");
        $this->actingAs($this->otherStudent, 'sanctum')
            ->post("/api/student/assessments/{$assessment8->id}/submit", [
                'responses' => [(string) $item8->id => 'A']]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/dashboard/school-wide-overview');

        $response->assertOk();
        $gradeLevels = $response->json('data.grade_levels');
        $this->assertCount(2, $gradeLevels);
        $this->assertEquals('7', $gradeLevels[0]['grade_level']);
        $this->assertEquals('8', $gradeLevels[1]['grade_level']);
    }

    /** @test */
    public function test_81_school_wide_overall_rate_calculation(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment, ['A']);
        $this->studentSubmitAndScore($this->otherStudent, $assessment, ['B']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/dashboard/school-wide-overview');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertEquals(50.0, $data['overall_mastery_rate_percent']);
        $this->assertEquals(1, $data['mastered_students']);
        $this->assertEquals(2, $data['total_students_assessed']);
    }

    /** @test */
    public function test_81_school_wide_by_competency_breakdown(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A'],
            ['competency_tag_id' => $this->competencyTag2->id, 'max_points' => 10, 'correct_answer' => 'A']]);

        // Student scores 100% on comp1, 0% on comp2.
        $this->studentSubmitAndScore($this->student, $assessment, ['A', 'B']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/dashboard/school-wide-overview');

        $response->assertOk();
        $byComp = $response->json('data.by_competency');
        $this->assertCount(2, $byComp);

        $comp1 = collect($byComp)->firstWhere('code', 'M7-ALG-01');
        $comp2 = collect($byComp)->firstWhere('code', 'M7-GEO-02');

        $this->assertEquals(100.0, $comp1['mastery_rate_percent']);
        $this->assertEquals(0.0, $comp2['mastery_rate_percent']);
    }

    /** @test */
    public function test_81_school_wide_empty_grade_level_returns_zero(): void
    {
        $this->ensureAllUsers();
        // No submissions → no mastery records.

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/dashboard/school-wide-overview');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertEquals(0.0, $data['overall_mastery_rate_percent']);
        $this->assertIsArray($data['by_competency']);
        $this->assertCount(0, $data['by_competency']);
    }

    /** @test */
    public function test_81_school_wide_includes_grade_ten_aggregates(): void
    {
        $this->ensureAllUsers();

        // Grade 7 data (existing section).
        $assessment7 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment7, ['A']);

        // Grade 10 org hierarchy.
        $gradeLevel10 = GradeLevel::create([
            'semester_id' => $this->section->gradeLevel->semester_id,
            'grade_level' => '10']);
        $section10 = Section::create([
            'grade_level_id' => $gradeLevel10->id,
            'name' => '10A-P6-EDGE']);
        $subject10 = Subject::create(['grade_level_id' => $gradeLevel10->id, 'name' => 'Physics', 'code' => 'PHY10-P6-EDGE']);
        $classroom10 = $this->createSecondaryClassroom($subject10, $section10, $this->teacher, $this->otherStudent, 'G10');
        $comp10 = CompetencyReference::create(['semester' => '1', 'code' => 'P10-MOT-01',
            'descriptor' => 'Grade 10 motion',
            'subject_id' => $subject10->id,
            'grade_level' => '10']);
        $assessment10 = Assessment::create([
            'teacher_id' => $this->teacher->id,
            'classroom_id' => $classroom10->id,
            'subject_id' => $subject10->id,
            'semester_id' => $this->section->gradeLevel->semester_id,
            'title' => 'Grade 10 Test',
            'description' => '',
            'type' => 'Recorded',
            'status' => 'released']);
        AssessmentItem::create([
            'assessment_id' => $assessment10->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $comp10->id,
            'sort_order' => 1]);
        $this->studentSubmitAndScore($this->otherStudent, $assessment10, ['A']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/dashboard/school-wide-overview');

        $response->assertOk();
        $data = $response->json('data');

        // Enum order sorts '10' after '7'.
        $gradeLevels = $data['grade_levels'];
        $this->assertCount(2, $gradeLevels);
        $this->assertEquals('7', $gradeLevels[0]['grade_level']);
        $this->assertEquals('10', $gradeLevels[1]['grade_level']);

        $g10 = $gradeLevels[1];
        $this->assertCount(1, $g10['subjects']);
        $this->assertEquals('PHY10-P6-EDGE', $g10['subjects'][0]['subject_code']);
        $this->assertEquals(1, $g10['subjects'][0]['mastered_count']);
        $this->assertEquals(1, $g10['subjects'][0]['total_students_assessed']);
        $this->assertEquals(100.0, $g10['subjects'][0]['mastery_rate_percent']);

        // Grade-10 mastery feeds the school-wide totals.
        $this->assertEquals(2, $data['total_students_assessed']);
        $this->assertEquals(2, $data['mastered_students']);
        $this->assertEquals(100.0, $data['overall_mastery_rate_percent']);

        // The global competency breakdown includes grade-10 competencies too.
        $g10Comp = collect($data['by_competency'])->firstWhere('code', 'P10-MOT-01');
        $this->assertNotNull($g10Comp);
        $this->assertEquals(1, $g10Comp['total_students_assessed']);
    }

    /** @test */
    public function test_81_school_wide_configured_empty_grade_level_appears_with_no_subjects(): void
    {
        $this->ensureAllUsers();

        // Configured grade level with no sections or submissions.
        GradeLevel::create([
            'semester_id' => $this->section->gradeLevel->semester_id,
            'grade_level' => '12']);

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment, ['B']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/dashboard/school-wide-overview');

        $response->assertOk();
        $data = $response->json('data');

        $gradeLevels = $data['grade_levels'];
        $this->assertCount(2, $gradeLevels);
        $this->assertEquals('12', $gradeLevels[1]['grade_level']);
        // Empty configuration contributes nothing but still appears.
        $this->assertSame([], $gradeLevels[1]['subjects']);
        // Grade 7 carries the single data-bearing subject row.
        $this->assertCount(1, $gradeLevels[0]['subjects']);
        // Overall totals reflect only grades with data.
        $this->assertEquals(1, $data['total_students_assessed']);
        $this->assertEquals(0, $data['mastered_students']);
        $this->assertEquals(0.0, $data['overall_mastery_rate_percent']);
    }

    /*
    |----------------------------------------------------------------------
    | #82 — Mastery history edge cases
    |----------------------------------------------------------------------
    */

    /** @test */
    public function test_82_mastery_history_multiple_competencies(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A'],
            ['competency_tag_id' => $this->competencyTag2->id, 'max_points' => 10, 'correct_answer' => 'A']]);

        $this->studentSubmitAndScore($this->student, $assessment, ['A', 'A']);

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/student/dashboard/mastery-history');

        $response->assertOk();
        $competencies = $response->json('data.competencies');
        $this->assertCount(2, $competencies);
        $this->assertEquals('M7-ALG-01', $competencies[0]['code']);
        $this->assertEquals('M7-GEO-02', $competencies[1]['code']);
    }

    /** @test */
    public function test_82_mastery_history_append_only_multiple_records(): void
    {
        $this->ensureAllUsers();

        $assessment1 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment1, ['A']);

        $assessment2 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment2, ['B']);

        $this->assertEquals(2, MasteryRecord::where('student_id', $this->student->id)->count());

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/student/dashboard/mastery-history');

        $response->assertOk();
        $competency = $response->json('data.competencies.0');
        $this->assertCount(2, $competency['history']);
        $this->assertEquals('M7-ALG-01', $competency['code']);
    }

    /** @test */
    public function test_82_mastery_history_current_status_from_latest(): void
    {
        $this->ensureAllUsers();

        $assessment1 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment1, ['A']);

        $assessment2 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment2, ['B']);

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/student/dashboard/mastery-history');

        $response->assertOk();
        $competency = $response->json('data.competencies.0');
        // Latest record (assessment2) → Not_Mastered.
        $this->assertEquals('Not_Mastered', $competency['current_mastery_status']);
        $this->assertEquals(0.0, $competency['current_mastery_percent']);
    }

    /** @test */
    public function test_82_mastery_history_competency_id_filter(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A'],
            ['competency_tag_id' => $this->competencyTag2->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment, ['A', 'A']);

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/student/dashboard/mastery-history?competency_id='.$this->competencyTag1->id);

        $response->assertOk();
        $this->assertCount(1, $response->json('data.competencies'));
        $this->assertEquals($this->competencyTag1->id, $response->json('data.competencies.0.competency_id'));
        $this->assertEquals('M7-ALG-01', $response->json('data.competencies.0.code'));
    }

    /*
    |----------------------------------------------------------------------
    | General edge cases
    |----------------------------------------------------------------------
    */

    /** @test */
    public function test_error_envelope_structure_for_all_endpoints(): void
    {
        $this->ensureAllUsers();

        // #77 — 400 BAD_REQUEST (missing subject_id).
        $r77 = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/heatmap');
        $r77->assertStatus(400);
        $r77->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertIsString($r77->json('error.message'));
        $this->assertEquals('BAD_REQUEST', $r77->json('error.code'));

        // #78 — 401 UNAUTHENTICATED (clear Sanctum auth first).
        Auth::guard('sanctum')->forgetUser();
        $r78 = $this->getJson('/api/teacher/dashboard/gap-report?subject_id='.$this->subject->id);
        $r78->assertStatus(401);
        $r78->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('UNAUTHENTICATED', $r78->json('error.code'));

        // #79 — 400 BAD_REQUEST (missing subject_id).
        $r79 = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/trends');
        $r79->assertStatus(400);
        $r79->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('BAD_REQUEST', $r79->json('error.code'));

        // #80 — 400 BAD_REQUEST (missing student_id).
        $r80 = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/student-drill-down?subject_id='.$this->subject->id);
        $r80->assertStatus(400);
        $r80->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('BAD_REQUEST', $r80->json('error.code'));

        // #81 — 401 UNAUTHENTICATED (clear Sanctum auth first).
        Auth::guard('sanctum')->forgetUser();
        $r81 = $this->getJson('/api/admin/dashboard/school-wide-overview');
        $r81->assertStatus(401);
        $r81->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('UNAUTHENTICATED', $r81->json('error.code'));

        // #82 — 401 UNAUTHENTICATED.
        $r82 = $this->getJson('/api/student/dashboard/mastery-history');
        $r82->assertStatus(401);
        $r82->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('UNAUTHENTICATED', $r82->json('error.code'));
    }

    /** @test */
    public function test_rate_percent_rounding_two_decimals(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);

        // 3 students: 1 Mastered, 2 Not_Mastered → 1/3 * 100 = 33.33.
        $student3 = User::factory()->create(['role' => 'Student']);
        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $student3->id]);

        $this->studentSubmitAndScore($this->student, $assessment, ['A']);
        $this->studentSubmitAndScore($this->otherStudent, $assessment, ['B']);
        $this->studentSubmitAndScore($student3, $assessment, ['B']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/heatmap?subject_id='.$this->subject->id);

        $response->assertOk();
        $comp = $response->json('data.competencies.0');
        $this->assertEquals(33.33, $comp['mastery_rate_percent']);
    }

    /*
    |----------------------------------------------------------------------
    | Unverified response-field verification
    |----------------------------------------------------------------------
    */

    /** @test */
    public function test_77_heatmap_average_mastery_percent_and_total_records(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);

        // Student 1: 100% (Mastered), Student 2: 0% (Not_Mastered).
        $this->studentSubmitAndScore($this->student, $assessment, ['A']);
        $this->studentSubmitAndScore($this->otherStudent, $assessment, ['B']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/heatmap?subject_id='.$this->subject->id);

        $response->assertOk();
        $comp = $response->json('data.competencies.0');

        // average_mastery_percent = AVG(mastery_percent) = (100.0 + 0.0) / 2 = 50.0.
        $this->assertEquals(50.0, $comp['average_mastery_percent']);
        // total_records = COUNT(*) = 2 (one per student).
        $this->assertEquals(2, $comp['mastered_count'] + $comp['not_mastered_count']);
        $this->assertEquals(1, $comp['mastered_count']);
        $this->assertEquals(1, $comp['not_mastered_count']);
    }

    /** @test */
    public function test_77_heatmap_aggregation_without_assessment_id_combines_all(): void
    {
        $this->ensureAllUsers();

        // Two Recorded assessments on the same competency.
        $a1 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $a2 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        // Student scores 100% on a1, 0% on a2 → the LATEST-per-pair record
        // (ARCH-002 FR-020) is the 0% one, so average_mastery_percent over the current
        // set is 0.0 (ARCH-004 §4.4 — the aggregate resolves the latest record per
        // (student, competency) FIRST).
        $this->studentSubmitAndScore($this->student, $a1, ['A']);
        $this->studentSubmitAndScore($this->student, $a2, ['B']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/heatmap?subject_id='.$this->subject->id);

        $response->assertOk();
        $comp = $response->json('data.competencies.0');
        $this->assertEquals(0.0, $comp['average_mastery_percent']);
        $this->assertEquals(0, $comp['mastered_count']);
        $this->assertEquals(1, $comp['not_mastered_count']);
    }

    /** @test */
    public function test_77_heatmap_generated_at_is_valid_json_timestamp(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment, ['A']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/heatmap?subject_id='.$this->subject->id);

        $response->assertOk();
        $generatedAt = $response->json('data.generated_at');
        $this->assertNotNull($generatedAt);
        $this->assertNotEmpty($generatedAt);
    }

    /** @test */
    public function test_78_gap_report_student_level_flag_ids_and_mastery_percent(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);

        // Student scores 0% → Not_Mastered → NotCompetentFlag created.
        $this->studentSubmitAndScore($this->student, $assessment, ['B']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/gap-report?subject_id='.$this->subject->id.'&group_by=student');

        $response->assertOk();
        $gap = $response->json('data.gaps.0');
        $this->assertNotNull($gap['mastery_percent']);
        $this->assertNotEmpty($gap['not_competent_flag_ids']);
        $this->assertIsInt($gap['not_competent_flag_ids'][0]);
    }

    /** @test */
    public function test_78_gap_report_student_level_all_mastered_returns_empty(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);

        // Both students score 100% → all mastered → student-level gaps should be empty.
        $this->studentSubmitAndScore($this->student, $assessment, ['A']);
        $this->studentSubmitAndScore($this->otherStudent, $assessment, ['A']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/gap-report?subject_id='.$this->subject->id.'&group_by=student');

        $response->assertOk();
        $this->assertCount(0, $response->json('data.gaps'));
    }

    /** @test */
    public function test_78_gap_report_section_level_zero_percent_mastery(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);

        // Both students score 0%.
        $this->studentSubmitAndScore($this->student, $assessment, ['B']);
        $this->studentSubmitAndScore($this->otherStudent, $assessment, ['B']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/gap-report?subject_id='.$this->subject->id);

        $response->assertOk();
        $gap = $response->json('data.gaps.0');
        $this->assertEquals(0, $gap['mastered_count']);
        $this->assertEquals(2, $gap['not_mastered_count']);
        $this->assertEquals(2, $gap['total_students_assessed']);
        $this->assertEquals(100.0, $gap['not_mastered_percent']);
    }

    /** @test */
    public function test_79_trends_assessed_at_timestamp_ordering(): void
    {
        $this->ensureAllUsers();

        $assessment1 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment1, ['A']);

        $assessment2 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment2, ['A']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/trends?subject_id='.$this->subject->id);

        $response->assertOk();
        $trends = $response->json('data.trends');
        $this->assertCount(2, $trends);
        // assessed_at should be present and ordered ascending (by assessment created_at).
        $this->assertNotNull($trends[0]['assessed_at']);
        $this->assertNotNull($trends[1]['assessed_at']);
    }

    /** @test */
    public function test_79_trends_mastery_percent_aggregation_with_partial_mastery(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);

        // Student 1: 100%, Student 2: 0%.
        $this->studentSubmitAndScore($this->student, $assessment, ['A']);
        $this->studentSubmitAndScore($this->otherStudent, $assessment, ['B']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/trends?subject_id='.$this->subject->id);

        $response->assertOk();
        $trend = $response->json('data.trends.0');
        $this->assertEquals(50.0, $trend['mastery_rate_percent']);
        $this->assertEquals(1, $trend['mastered_count']);
        $this->assertEquals(2, $trend['total_students_assessed']);
    }

    /** @test */
    public function test_80_drill_down_history_item_structure(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment, ['A']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/student-drill-down?student_id='.$this->student->id.'&subject_id='.$this->subject->id);

        $response->assertOk();
        $history = $response->json('data.competencies.0.history');
        $this->assertGreaterThanOrEqual(1, count($history));

        $item = $history[0];
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('student_id', $item);
        $this->assertArrayHasKey('competency_id', $item);
        $this->assertArrayHasKey('competency_code', $item);
        $this->assertArrayHasKey('assessment_id', $item);
        $this->assertArrayHasKey('mastery_percent', $item);
        $this->assertArrayHasKey('mastery_status', $item);
        $this->assertArrayHasKey('created_at', $item);

        $this->assertEquals('M7-ALG-01', $item['competency_code']);
        $this->assertEquals('Mastered', $item['mastery_status']);
        $this->assertEquals($assessment->id, $item['assessment_id']);
    }

    /** @test */
    public function test_80_drill_down_last_assessed_at_populated(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment, ['A']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/student-drill-down?student_id='.$this->student->id.'&subject_id='.$this->subject->id);

        $response->assertOk();
        $competency = $response->json('data.competencies.0');
        $this->assertNotNull($competency['last_assessed_at']);
        $this->assertEquals('Mastered', $competency['current_mastery_status']);
        $this->assertEquals(100.0, $competency['current_mastery_percent']);
    }

    /** @test */
    public function test_80_drill_down_last_assessed_at_null_when_no_records(): void
    {
        $this->ensureAllUsers();

        // Competency exists in section (via other student) but not for this student.
        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->otherStudent, $assessment, ['A']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/student-drill-down?student_id='.$this->student->id.'&subject_id='.$this->subject->id);

        $response->assertOk();
        $competency = $response->json('data.competencies.0');
        // No records for this student → defaults.
        $this->assertNull($competency['last_assessed_at']);
        $this->assertEquals('Not_Mastered', $competency['current_mastery_status']);
        $this->assertEquals(0.0, $competency['current_mastery_percent']);
    }

    /** @test */
    public function test_81_school_wide_grade_levels_subject_structure(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment, ['A']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/dashboard/school-wide-overview');

        $response->assertOk();
        $gl = $response->json('data.grade_levels.0');
        $this->assertEquals('7', $gl['grade_level']);
        $this->assertIsInt($gl['grade_level_id']);
        $this->assertIsArray($gl['subjects']);
        $this->assertGreaterThanOrEqual(1, count($gl['subjects']));

        $subject = $gl['subjects'][0];
        $this->assertArrayHasKey('subject_id', $subject);
        $this->assertArrayHasKey('subject_code', $subject);
        $this->assertArrayHasKey('subject_name', $subject);
        $this->assertArrayHasKey('mastered_count', $subject);
        $this->assertArrayHasKey('total_students_assessed', $subject);
        $this->assertArrayHasKey('mastery_rate_percent', $subject);
    }

    /** @test */
    public function test_81_school_wide_zero_percent_mastery(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);

        // Both students score 0%.
        $this->studentSubmitAndScore($this->student, $assessment, ['B']);
        $this->studentSubmitAndScore($this->otherStudent, $assessment, ['B']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/dashboard/school-wide-overview');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertEquals(0.0, $data['overall_mastery_rate_percent']);
        $this->assertEquals(0, $data['mastered_students']);
        $this->assertEquals(2, $data['total_students_assessed']);
    }

    /** @test */
    public function test_82_mastery_history_history_item_structure(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment, ['A']);

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/student/dashboard/mastery-history');

        $response->assertOk();
        $competency = $response->json('data.competencies.0');
        $history = $competency['history'];
        $this->assertGreaterThanOrEqual(1, count($history));

        $item = $history[0];
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('student_id', $item);
        $this->assertArrayHasKey('competency_id', $item);
        $this->assertArrayHasKey('competency_code', $item);
        $this->assertArrayHasKey('assessment_id', $item);
        $this->assertArrayHasKey('mastery_percent', $item);
        $this->assertArrayHasKey('mastery_status', $item);
        $this->assertArrayHasKey('created_at', $item);

        $this->assertEquals($assessment->id, $item['assessment_id']);
        $this->assertEquals('Mastered', $item['mastery_status']);
    }

    /** @test */
    public function test_82_mastery_history_last_assessed_at_populated(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment, ['A']);

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/student/dashboard/mastery-history');

        $response->assertOk();
        $competency = $response->json('data.competencies.0');
        $this->assertNotNull($competency['last_assessed_at']);
    }

    /** @test */
    public function test_82_mastery_history_last_assessed_at_null_when_no_records(): void
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/student/dashboard/mastery-history');

        $response->assertOk();
        $this->assertCount(0, $response->json('data.competencies'));
        $this->assertNotNull($response->json('data.student_id'));
        $this->assertNotNull($response->json('data.generated_at'));
    }

    /** @test */
    public function test_82_mastery_history_nonexistent_competency_id_returns_empty(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment, ['A']);

        // A competency_id that doesn't exist → no mastery records → empty.
        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/student/dashboard/mastery-history?competency_id=999999');

        $response->assertOk();
        $this->assertCount(0, $response->json('data.competencies'));
    }

    /** @test */
    public function test_81_school_wide_generated_at_and_by_competency_structure(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A'],
            ['competency_tag_id' => $this->competencyTag2->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment, ['A', 'B']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/dashboard/school-wide-overview');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertNotNull($data['generated_at']);

        $byComp = collect($data['by_competency']);
        $this->assertCount(2, $byComp);

        $comp1 = $byComp->firstWhere('code', 'M7-ALG-01');
        $comp2 = $byComp->firstWhere('code', 'M7-GEO-02');

        $this->assertEquals(100.0, $comp1['mastery_rate_percent']);
        $this->assertEquals(0.0, $comp2['mastery_rate_percent']);
        $this->assertEquals(1, $comp1['mastered_count']);
        $this->assertEquals(1, $comp2['not_mastered_count']);
    }

    /*
    |----------------------------------------------------------------------
    | WU-9 — ARCH-002 FR-022 (competency-summary multi-section aggregation)
    |----------------------------------------------------------------------
    */

    /** @test */
    public function test_competency_summary_unfiltered_aggregates_all_sections(): void
    {
        $this->ensureAllUsers();

        // Second section + subject-section; the teacher is assigned to BOTH
        // sections. The unfiltered summary must never be silently partial
        // (ARCH-002 FR-022) — it aggregates across all assigned sections.
        $section2 = Section::create([
            'grade_level_id' => $this->section->grade_level_id,
            'name' => '7B-P6-EDGE']);
        $subject2 = Subject::create(['grade_level_id' => $this->section->grade_level_id, 'name' => 'Science', 'code' => 'SCI7-P6-EDGE']);
        $classroom2 = $this->createSecondaryClassroom($subject2, $section2, $this->teacher, $this->otherStudent, 'S2');

        // Section 1: comp1 — student Mastered.
        $a1 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $a1, ['A']);

        // Section 2: comp2 — otherStudent Not_Mastered.
        $a2 = Assessment::create([
            'teacher_id' => $this->teacher->id,
            'classroom_id' => $classroom2->id,
            'subject_id' => $subject2->id,
            'semester_id' => $this->section->gradeLevel->semester_id,
            'title' => 'P6-EDGE Section 2',
            'description' => '',
            'type' => 'Recorded',
            'status' => 'released']);
        AssessmentItem::create([
            'assessment_id' => $a2->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $this->competencyTag2->id,
            'sort_order' => 1]);
        $this->studentSubmitAndScore($this->otherStudent, $a2, ['X']);

        // Unfiltered — BOTH sections' competencies must appear (ARCH-002 FR-022).
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/competency-summary');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data);

        $byCode = collect($data)->keyBy('code');
        $this->assertEquals('M7-ALG-01', $byCode['M7-ALG-01']['code']);
        $this->assertEquals(1, $byCode['M7-ALG-01']['mastered_count']);
        $this->assertEquals('M7-GEO-02', $byCode['M7-GEO-02']['code']);
        $this->assertEquals(1, $byCode['M7-GEO-02']['not_mastered_count']);
        $this->assertEquals(100.0, $byCode['M7-ALG-01']['mastery_rate_percent']);
        $this->assertEquals(100.0, $byCode['M7-GEO-02']['remediation_frequency']);
    }

    /** @test */
    public function test_competency_summary_section_filter_scopes(): void
    {
        $this->ensureAllUsers();

        // Second section + subject-section; the teacher is assigned to BOTH
        // sections. The per-section filter must still scope to ONE section
        // (ARCH-002 FR-022).
        $section2 = Section::create([
            'grade_level_id' => $this->section->grade_level_id,
            'name' => '7B-P6-EDGE']);
        $subject2 = Subject::create(['grade_level_id' => $this->section->grade_level_id, 'name' => 'Science', 'code' => 'SCI7-P6-EDGE']);
        $classroom2 = $this->createSecondaryClassroom($subject2, $section2, $this->teacher, $this->otherStudent, 'S2');

        // Section 1: comp1 — student Mastered.
        $a1 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $a1, ['A']);

        // Section 2: comp2 — otherStudent Not_Mastered.
        $a2 = Assessment::create([
            'teacher_id' => $this->teacher->id,
            'classroom_id' => $classroom2->id,
            'subject_id' => $subject2->id,
            'semester_id' => $this->section->gradeLevel->semester_id,
            'title' => 'P6-EDGE Section 2',
            'description' => '',
            'type' => 'Recorded',
            'status' => 'released']);
        AssessmentItem::create([
            'assessment_id' => $a2->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $this->competencyTag2->id,
            'sort_order' => 1]);
        $this->studentSubmitAndScore($this->otherStudent, $a2, ['X']);

        // Filtered to section 1's subject-section → only comp1.
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/competency-summary?subject_id=' . $this->subject->id);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('M7-ALG-01', $data[0]['code']);
        $this->assertEquals(1, $data[0]['mastered_count']);
    }
}
