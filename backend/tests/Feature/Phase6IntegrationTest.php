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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

#[Group('phase6-integration')]
class Phase6IntegrationTest extends TestCase
{
    use RefreshDatabase;

    private ?User $teacher = null;

    private ?User $otherTeacher = null;

    private ?User $student = null;

    private ?User $otherStudent = null;

    private ?User $admin = null;

    
    private ?Section $section = null;

    private ?Subject $subject = null;

    private ?CompetencyReference $competencyTag1 = null;

    private ?CompetencyReference $competencyTag2 = null;

    private ?Classroom $classroom = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = $this->otherTeacher = $this->student = $this->otherStudent = $this->admin = null;
        $this->section = $this->subject = null;
        $this->competencyTag1 = $this->competencyTag2 = null;
        $this->classroom = null;
    }

    private function setUpOrg(): void
    {
        $year = SchoolYear::create(['name' => 'SY 2026-P6-INT']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-06-01',
            'end_date' => '2026-08-31']);
        $gradeLevel = GradeLevel::create(['semester_id' => $semester->id, 'grade_level' => '7']);
        $this->section = Section::create([
            'grade_level_id' => $gradeLevel->id,
            'name' => '7A-P6-INT']);
        $this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-P6-INT']);
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


        $this->classroom = app(ClassroomService::class)->createClassroom($this->teacher->id, $this->subject->id, $this->section->id, '2026-2027', null);

        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->student->id]);
        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->otherStudent->id]);
    }

    /**
     * Create a classroom for a secondary subject-section and enroll one
     * student (pooled-aggregate fixtures: same-year, own room).
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

    private function createAssessmentWithItems(string $type, array $itemsConfig): Assessment
    {
        $assessment = Assessment::create([
            'teacher_id' => $this->teacher->id,
            'classroom_id' => $this->classroom->id,
            'subject_id' => $this->subject->id,
            'semester_id' => $this->section->gradeLevel->semester_id,
            'title' => 'P6-INT '.$type,
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
     * @param  array<int, string>  $responses  positional in item sort-order
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
            ->post("/api/student/assessments/{$assessment->id}/submit", ['responses' => $mapped]);

        return AssessmentSubmission::query()
            ->where('assessment_id', $assessment->id)
            ->where('student_id', $student->id)
            ->where('status', 'scored')
            ->first();
    }

    /*
    |----------------------------------------------------------------------
    | Integration tests
    |----------------------------------------------------------------------
    */

    /** @test */
    public function test_full_pipeline_recorded_assessment_appears_in_all_dashboards(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment, ['A']);

        // #77 Heatmap.
        $heatmap = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/heatmap?subject_id='.$this->subject->id);
        $heatmap->assertOk();
        $this->assertCount(1, $heatmap->json('data.competencies'));
        $this->assertEquals('M7-ALG-01', $heatmap->json('data.competencies.0.code'));
        $this->assertEquals(1, $heatmap->json('data.competencies.0.mastered_count'));
        $this->assertEquals(100.0, $heatmap->json('data.competencies.0.mastery_rate_percent'));

        // #78 Gap report (section-level) — no gaps when all mastered.
        $gapSection = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/gap-report?subject_id='.$this->subject->id.'&group_by=section');
        $gapSection->assertOk();
        $this->assertCount(0, $gapSection->json('data.gaps'));

        // #79 Trends.
        $trends = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/trends?subject_id='.$this->subject->id);
        $trends->assertOk();
        $this->assertCount(1, $trends->json('data.trends'));
        $this->assertEquals(100.0, $trends->json('data.trends.0.mastery_rate_percent'));

        // #80 Student drill-down.
        $drillDown = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/student-drill-down?student_id='.$this->student->id.'&subject_id='.$this->subject->id);
        $drillDown->assertOk();
        $this->assertEquals('M7-ALG-01', $drillDown->json('data.competencies.0.code'));
        $this->assertEquals('Mastered', $drillDown->json('data.competencies.0.current_mastery_status'));

        // #81 School-wide overview.
        $schoolWide = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/dashboard/school-wide-overview');
        $schoolWide->assertOk();
        $this->assertGreaterThan(0, $schoolWide->json('data.overall_mastery_rate_percent'));
        $this->assertGreaterThanOrEqual(1, count($schoolWide->json('data.by_competency')));

        // #82 Mastery history (student self-view).
        $history = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/student/dashboard/mastery-history');
        $history->assertOk();
        $this->assertCount(1, $history->json('data.competencies'));
        $this->assertEquals('Mastered', $history->json('data.competencies.0.current_mastery_status'));
    }

    /** @test */
    public function test_unrecorded_excluded_from_all_endpoints(): void
    {
        $this->ensureAllUsers();

        $recorded = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $recorded, ['A']);

        $unrecorded = $this->createAssessmentWithItems('Unrecorded', [
            ['competency_tag_id' => $this->competencyTag2->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $unrecorded, ['A']);

        // Only 1 mastery record should exist (from the Recorded assessment).
        $this->assertEquals(1, MasteryRecord::query()->count());

        $ssId = $this->subject->id;
        $studentId = $this->student->id;

        // #77 Heatmap — only comp1.
        $heatmap = $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/teacher/dashboard/heatmap?subject_id={$ssId}");
        $heatmap->assertOk();
        $codes = collect($heatmap->json('data.competencies'))->pluck('code')->all();
        $this->assertContains('M7-ALG-01', $codes);
        $this->assertNotContains('M7-GEO-02', $codes);

        // #78 Gap report — only comp1 references.
        $gapSection = $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/teacher/dashboard/gap-report?subject_id={$ssId}&group_by=section");
        $gapSection->assertOk();
        $codes = collect($gapSection->json('data.gaps'))->pluck('code')->all();
        $this->assertNotContains('M7-GEO-02', $codes);

        $gapStudent = $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/teacher/dashboard/gap-report?subject_id={$ssId}&group_by=student");
        $gapStudent->assertOk();
        $this->assertCount(0, $gapStudent->json('data.gaps'));

        // #79 Trends — only 1 trend (Recorded).
        $trends = $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/teacher/dashboard/trends?subject_id={$ssId}");
        $trends->assertOk();
        $this->assertCount(1, $trends->json('data.trends'));

        // #80 Drill-down — only comp1.
        $drillDown = $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/teacher/dashboard/student-drill-down?student_id={$studentId}&subject_id={$ssId}");
        $drillDown->assertOk();
        $codes = collect($drillDown->json('data.competencies'))->pluck('code')->all();
        $this->assertContains('M7-ALG-01', $codes);
        $this->assertNotContains('M7-GEO-02', $codes);

        // #81 School-wide by_competency — only comp1.
        $schoolWide = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/dashboard/school-wide-overview');
        $schoolWide->assertOk();
        $codes = collect($schoolWide->json('data.by_competency'))->pluck('code')->all();
        $this->assertContains('M7-ALG-01', $codes);
        $this->assertNotContains('M7-GEO-02', $codes);

        // #82 Mastery history — only comp1.
        $history = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/student/dashboard/mastery-history');
        $history->assertOk();
        $codes = collect($history->json('data.competencies'))->pluck('code')->all();
        $this->assertContains('M7-ALG-01', $codes);
        $this->assertNotContains('M7-GEO-02', $codes);
    }

    /** @test */
    public function test_multi_student_gap_report_consistency(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);

        $this->studentSubmitAndScore($this->student, $assessment, ['A']);
        $this->studentSubmitAndScore($this->otherStudent, $assessment, ['B']);

        $ssId = $this->subject->id;

        // Section-level: 1 gap for comp1 (1 mastered, 1 not mastered).
        $gapSection = $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/teacher/dashboard/gap-report?subject_id={$ssId}&group_by=section");
        $gapSection->assertOk();
        $this->assertCount(1, $gapSection->json('data.gaps'));
        $this->assertEquals(1, $gapSection->json('data.gaps.0.mastered_count'));
        $this->assertEquals(1, $gapSection->json('data.gaps.0.not_mastered_count'));
        $this->assertEquals(50.0, $gapSection->json('data.gaps.0.not_mastered_percent'));

        // Student-level: only the Not_Mastered student appears.
        $gapStudent = $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/teacher/dashboard/gap-report?subject_id={$ssId}&group_by=student");
        $gapStudent->assertOk();
        $this->assertCount(1, $gapStudent->json('data.gaps'));
        $this->assertEquals($this->otherStudent->id, $gapStudent->json('data.gaps.0.student_id'));
    }

    /** @test */
    public function test_heatmap_assessment_filter_matches_trends_data(): void
    {
        $this->ensureAllUsers();

        $assessment1 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment1, ['A']);

        $assessment2 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag2->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment2, ['A']);

        $ssId = $this->subject->id;

        // Heatmap with assessment_id filter — only assessment1's competency.
        $filtered = $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/teacher/dashboard/heatmap?subject_id={$ssId}&assessment_id={$assessment1->id}");
        $filtered->assertOk();
        $this->assertCount(1, $filtered->json('data.competencies'));
        $this->assertEquals('M7-ALG-01', $filtered->json('data.competencies.0.code'));

        // Heatmap without filter — both competencies.
        $all = $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/teacher/dashboard/heatmap?subject_id={$ssId}");
        $all->assertOk();
        $this->assertCount(2, $all->json('data.competencies'));

        // Trends shows both assessments (2 trend points).
        $trends = $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/teacher/dashboard/trends?subject_id={$ssId}");
        $trends->assertOk();
        $this->assertCount(2, $trends->json('data.trends'));
    }

    /** @test */
    public function test_drill_down_flags_match_gap_report(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);

        // Student scores 0% → Not_Mastered → flag created.
        $this->studentSubmitAndScore($this->student, $assessment, ['B']);

        $ssId = $this->subject->id;
        $studentId = $this->student->id;

        // Drill-down — extract flag IDs.
        $drillDown = $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/teacher/dashboard/student-drill-down?student_id={$studentId}&subject_id={$ssId}");
        $drillDown->assertOk();
        $drillFlags = $drillDown->json('data.competencies.0.not_competent_flags');
        $this->assertNotEmpty($drillFlags);

        // Gap report student-level — extract flag IDs.
        $gap = $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/teacher/dashboard/gap-report?subject_id={$ssId}&group_by=student");
        $gap->assertOk();
        $gapFlags = $gap->json('data.gaps.0.not_competent_flag_ids');
        $this->assertNotEmpty($gapFlags);

        // The flag IDs from both endpoints must match.
        sort($drillFlags);
        sort($gapFlags);
        $this->assertEquals($drillFlags, $gapFlags);
        // ARCH-004 §4.4: flags are the derived view — one row for the Not_Mastered pair.
        $this->assertEquals(1, DB::table('v_not_competent_flags')->count());
    }

    /** @test */
    public function test_school_wide_aggregates_multiple_grade_levels(): void
    {
        $this->ensureAllUsers();

        // Grade 7: student scores correct on comp1.
        $assessment7 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment7, ['A']);

        // Grade 8: otherStudent scores correct on comp1.
        $gradeLevel8 = GradeLevel::create([
            'semester_id' => $this->section->gradeLevel->semester_id,
            'grade_level' => '8']);
        $section8 = Section::create([
            'grade_level_id' => $gradeLevel8->id,
            'name' => '8A-P6-INT']);
        $subject8 = Subject::create(['grade_level_id' => $gradeLevel8->id, 'name' => 'Science', 'code' => 'SCI8-P6-INT']);
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
        AssessmentItem::create([
            'assessment_id' => $assessment8->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $comp3->id,
            'sort_order' => 1]);
        $this->studentSubmitAndScore($this->otherStudent, $assessment8, ['A']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/dashboard/school-wide-overview');
        $response->assertOk();

        $gradeLevels = $response->json('data.grade_levels');
        $this->assertCount(2, $gradeLevels);
        $this->assertEquals('7', $gradeLevels[0]['grade_level']);
        $this->assertEquals('8', $gradeLevels[1]['grade_level']);
        $this->assertGreaterThan(0, $response->json('data.overall_mastery_rate_percent'));
    }

    /** @test */
    public function test_student_history_matches_teacher_drill_down(): void
    {
        $this->ensureAllUsers();

        $assessment1 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment1, ['A']);

        $assessment2 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment2, ['B']);

        $ssId = $this->subject->id;
        $studentId = $this->student->id;

        // Student view (mastery-history).
        $history = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/student/dashboard/mastery-history');
        $history->assertOk();
        $hComp = $history->json('data.competencies.0');

        // Teacher view (drill-down).
        $drillDown = $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/teacher/dashboard/student-drill-down?student_id={$studentId}&subject_id={$ssId}");
        $drillDown->assertOk();
        $dComp = $drillDown->json('data.competencies.0');

        // Both must agree on code, current status, and history length.
        $this->assertEquals($hComp['code'], $dComp['code']);
        $this->assertEquals($hComp['current_mastery_status'], $dComp['current_mastery_status']);
        $this->assertEquals('Not_Mastered', $dComp['current_mastery_status']);
        $this->assertEquals(
            count($hComp['history'] ?? []),
            count($dComp['history'] ?? [])
        );
    }

    /** @test */
    public function test_multi_competency_all_endpoints(): void
    {
        $this->ensureAllUsers();

        $assessment = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A'],
            ['competency_tag_id' => $this->competencyTag2->id, 'max_points' => 10, 'correct_answer' => 'A']]);

        // Student: 100% on comp1, 0% on comp2.
        $this->studentSubmitAndScore($this->student, $assessment, ['A', 'B']);

        $ssId = $this->subject->id;

        // Heatmap — 2 competencies with correct rates.
        $heatmap = $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/teacher/dashboard/heatmap?subject_id={$ssId}");
        $heatmap->assertOk();
        $comps = collect($heatmap->json('data.competencies'));
        $comp1 = $comps->firstWhere('code', 'M7-ALG-01');
        $comp2 = $comps->firstWhere('code', 'M7-GEO-02');
        $this->assertEquals(100.0, $comp1['mastery_rate_percent']);
        $this->assertEquals(0.0, $comp2['mastery_rate_percent']);

        // Gap report section — only comp2 (Not_Mastered).
        $gap = $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/teacher/dashboard/gap-report?subject_id={$ssId}&group_by=section");
        $gap->assertOk();
        $this->assertCount(1, $gap->json('data.gaps'));
        $this->assertEquals('M7-GEO-02', $gap->json('data.gaps.0.code'));

        // School-wide by_competency — 2 entries.
        $schoolWide = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/dashboard/school-wide-overview');
        $schoolWide->assertOk();
        $this->assertCount(2, $schoolWide->json('data.by_competency'));
    }

    /** @test */
    public function test_teacher_scoped_to_own_subjects(): void
    {
        $this->ensureAllUsers();

        // Second subject with otherTeacher assigned.
        $gradeLevel = $this->section->gradeLevel;
        $section2 = Section::create(['grade_level_id' => $gradeLevel->id, 'name' => '7B-P6-INT']);
        $subject2 = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Science', 'code' => 'SCI7-P6-INT']);
        $classroom2 = $this->createSecondaryClassroom($subject2, $section2, $this->otherTeacher, $this->student, 'S2');

        $comp3 = CompetencyReference::create(['semester' => '1', 'code' => 'S7-GEN-01',
            'descriptor' => 'Science general',
            'subject_id' => $subject2->id,
            'grade_level' => '7']);
        $assessment2 = Assessment::create([
            'teacher_id' => $this->otherTeacher->id,
            'classroom_id' => $classroom2->id,
            'subject_id' => $subject2->id,
            'semester_id' => $gradeLevel->semester_id,
            'title' => 'Test 2',
            'description' => '',
            'type' => 'Recorded',
            'status' => 'released']);
        AssessmentItem::create([
            'assessment_id' => $assessment2->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $comp3->id,
            'sort_order' => 1]);
        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment2->id}/start");
        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment2->id}/submit", [
                'responses' => [(string) AssessmentItem::where('assessment_id', $assessment2->id)->first()->id => 'A']]);

        // Teacher (not assigned to subject2) → 403.
        $denied = $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/teacher/dashboard/heatmap?subject_id={$subject2->id}");
        $denied->assertStatus(403);

        // otherTeacher (assigned) → 200 with data.
        $allowed = $this->actingAs($this->otherTeacher, 'sanctum')
            ->getJson("/api/teacher/dashboard/heatmap?subject_id={$subject2->id}");
        $allowed->assertOk();
        $this->assertCount(1, $allowed->json('data.competencies'));
    }

    /** @test */
    public function test_mastery_history_current_status_consistency(): void
    {
        $this->ensureAllUsers();

        $assessment1 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment1, ['A']);

        $assessment2 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment2, ['B']);

        $ssId = $this->subject->id;
        $studentId = $this->student->id;

        // Mastery history — current status = Not_Mastered (latest).
        $history = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/student/dashboard/mastery-history');
        $history->assertOk();
        $this->assertEquals('Not_Mastered', $history->json('data.competencies.0.current_mastery_status'));

        // Drill-down — current status = Not_Mastered (latest, ARCH-002 FR-020).
        $drillDown = $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/teacher/dashboard/student-drill-down?student_id={$studentId}&subject_id={$ssId}");
        $drillDown->assertOk();
        $this->assertEquals('Not_Mastered', $drillDown->json('data.competencies.0.current_mastery_status'));
        $this->assertCount(2, $drillDown->json('data.competencies.0.history'));
    }

    /** @test */
    public function test_gap_report_excludes_unrecorded_while_showing_recorded(): void
    {
        $this->ensureAllUsers();

        $recorded = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $recorded, ['B']);

        $unrecorded = $this->createAssessmentWithItems('Unrecorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $unrecorded, ['A']);

        // Only the Recorded submission creates a mastery record.
        $this->assertEquals(1, MasteryRecord::query()->count());

        $ssId = $this->subject->id;

        $gapSection = $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/teacher/dashboard/gap-report?subject_id={$ssId}&group_by=section");
        $gapSection->assertOk();
        $this->assertCount(1, $gapSection->json('data.gaps'));
        $this->assertEquals('M7-ALG-01', $gapSection->json('data.gaps.0.code'));
        $this->assertEquals(1, $gapSection->json('data.gaps.0.not_mastered_count'));
    }

    /** @test */
    public function test_multiple_assessments_trends_ordering_and_aggregation(): void
    {
        $this->ensureAllUsers();

        // Assessment 1 — both students Mastered.
        $assessment1 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment1, ['A']);
        $this->studentSubmitAndScore($this->otherStudent, $assessment1, ['A']);

        // Assessment 2 — both students Not_Mastered.
        $assessment2 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment2, ['B']);
        $this->studentSubmitAndScore($this->otherStudent, $assessment2, ['B']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/trends?subject_id='.$this->subject->id);

        $response->assertOk();
        $trends = $response->json('data.trends');
        $this->assertCount(2, $trends);
        // Ordered by assessment created_at ASC.
        $this->assertEquals($assessment1->id, $trends[0]['assessment_id']);
        $this->assertEquals($assessment2->id, $trends[1]['assessment_id']);

        $this->assertEquals(100.0, $trends[0]['mastery_rate_percent']);
        $this->assertEquals(2, $trends[0]['mastered_count']);
        $this->assertEquals(0.0, $trends[1]['mastery_rate_percent']);
        $this->assertEquals(0, $trends[1]['mastered_count']);
    }

    /** @test */
    public function test_student_mastery_history_aggregates_across_multiple_subjects(): void
    {
        $this->ensureAllUsers();

        // Section 1 (existing): student scores Mastered on comp1.
        $assessment1 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment1, ['A']);

        // Section 2: same student enrolled, scores Not_Mastered on comp2.
        $gradeLevel = $this->section->gradeLevel;
        $section2 = Section::create(['grade_level_id' => $gradeLevel->id, 'name' => '7B-P6-INT-X']);
        $subject2 = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Science', 'code' => 'SCI7-P6-INT-X']);
        $classroom2 = $this->createSecondaryClassroom($subject2, $section2, $this->teacher, $this->student, 'X');

        $comp3 = CompetencyReference::create(['semester' => '1', 'code' => 'S7-GEN-01',
            'descriptor' => 'Science general',
            'subject_id' => $subject2->id,
            'grade_level' => '7']);
        $assessment2 = Assessment::create([
            'teacher_id' => $this->teacher->id,
            'classroom_id' => $classroom2->id,
            'subject_id' => $subject2->id,
            'semester_id' => $gradeLevel->semester_id,
            'title' => 'Science Test',
            'description' => '',
            'type' => 'Recorded',
            'status' => 'released']);
        AssessmentItem::create([
            'assessment_id' => $assessment2->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $comp3->id,
            'sort_order' => 1]);
        $this->studentSubmitAndScore($this->student, $assessment2, ['B']);

        // Student mastery history should show entries from both sections.
        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/student/dashboard/mastery-history');

        $response->assertOk();
        $competencies = $response->json('data.competencies');
        $this->assertCount(2, $competencies);

        $codes = collect($competencies)->pluck('code')->all();
        $this->assertContains('M7-ALG-01', $codes);
        $this->assertContains('S7-GEN-01', $codes);

        $comp1 = collect($competencies)->firstWhere('code', 'M7-ALG-01');
        $compSci = collect($competencies)->firstWhere('code', 'S7-GEN-01');

        $this->assertEquals('Mastered', $comp1['current_mastery_status']);
        $this->assertEquals('Not_Mastered', $compSci['current_mastery_status']);
        $this->assertEquals(1, count($comp1['history']));
        $this->assertEquals(1, count($compSci['history']));
    }

    /** @test */
    public function test_school_wide_spans_grades_8_and_9(): void
    {
        $this->ensureAllUsers();

        // Grade 7 record.
        $assessment7 = $this->createAssessmentWithItems('Recorded', [
            ['competency_tag_id' => $this->competencyTag1->id, 'max_points' => 10, 'correct_answer' => 'A']]);
        $this->studentSubmitAndScore($this->student, $assessment7, ['A']);

        $semester = $this->section->gradeLevel->semester;

        // Grade 8.
        $gl8 = GradeLevel::create(['semester_id' => $semester->id, 'grade_level' => '8']);
        $sec8 = Section::create(['grade_level_id' => $gl8->id, 'name' => '8A-P6-SW']);
        $subj8 = Subject::create(['grade_level_id' => $gl8->id, 'name' => 'Science', 'code' => 'SCI8-P6-SW']);
        $classroom8 = $this->createSecondaryClassroom($subj8, $sec8, $this->teacher, $this->otherStudent, 'SW8');
        $comp8 = CompetencyReference::create(['semester' => '1', 'code' => 'S8-GEN-01', 'descriptor' => 'Grade 8 science', 'subject_id' => $subj8->id, 'grade_level' => '8']);
        $a8 = Assessment::create([
            'teacher_id' => $this->teacher->id, 'classroom_id' => $classroom8->id, 'subject_id' => $subj8->id, 'semester_id' => $semester->id,
            'title' => 'G8 test', 'description' => '', 'type' => 'Recorded', 'status' => 'released']);
        AssessmentItem::create([
            'assessment_id' => $a8->id, 'item_type' => 'multiple_choice', 'prompt' => 'Q1',
            'max_points' => 10, 'correct_answer' => 'A', 'competency_tag_id' => $comp8->id, 'sort_order' => 1]);
        $this->studentSubmitAndScore($this->otherStudent, $a8, ['A']);

        // Grade 9.
        $gl9 = GradeLevel::create(['semester_id' => $semester->id, 'grade_level' => '9']);
        $sec9 = Section::create(['grade_level_id' => $gl9->id, 'name' => '9A-P6-SW']);
        $subj9 = Subject::create(['grade_level_id' => $gl9->id, 'name' => 'English', 'code' => 'ENG9-P6-SW']);
        $classroom9 = $this->createSecondaryClassroom($subj9, $sec9, $this->teacher, $this->student, 'SW9');
        $comp9 = CompetencyReference::create(['semester' => '1', 'code' => 'E9-RDG-01', 'descriptor' => 'Grade 9 reading', 'subject_id' => $subj9->id, 'grade_level' => '9']);
        $a9 = Assessment::create([
            'teacher_id' => $this->teacher->id, 'classroom_id' => $classroom9->id, 'subject_id' => $subj9->id, 'semester_id' => $semester->id,
            'title' => 'G9 test', 'description' => '', 'type' => 'Recorded', 'status' => 'released']);
        AssessmentItem::create([
            'assessment_id' => $a9->id, 'item_type' => 'multiple_choice', 'prompt' => 'Q1',
            'max_points' => 10, 'correct_answer' => 'A', 'competency_tag_id' => $comp9->id, 'sort_order' => 1]);
        // Student already joined classroom9 above, so they can take the assessment.
        $this->studentSubmitAndScore($this->student, $a9, ['B']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/dashboard/school-wide-overview');

        $response->assertOk();
        $gradeLevels = $response->json('data.grade_levels');
        // All three grade levels should appear.
        $this->assertCount(3, $gradeLevels);
        $this->assertEquals('7', $gradeLevels[0]['grade_level']);
        $this->assertEquals('8', $gradeLevels[1]['grade_level']);
        $this->assertEquals('9', $gradeLevels[2]['grade_level']);
    }
}
