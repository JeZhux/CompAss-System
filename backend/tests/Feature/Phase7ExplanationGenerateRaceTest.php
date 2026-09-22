<?php

namespace Tests\Feature;

use App\Models\AIExplanation;
use App\Models\Assessment;
use App\Models\AssessmentItem;
use App\Models\AssessmentSubmission;
use App\Models\Classroom;
use App\Models\ClassroomEnrollment;
use App\Models\CompetencyReference;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Semester;
use App\Models\User;
use App\Services\ClassroomService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Concurrency regression for the explicit generate endpoint:
 *
 *   POST /api/student/assessments/{id}/explanations/generate
 *
 * Two overlapping requests can both pass every dedupe read; the loser's
 * INSERT then hits uq_ae_stu_asg_item_turn_attempt. The service must absorb
 * the unique-constraint violation and serve the SURVIVING row (ARCH-002 FR-027
 * idempotency) instead of surfacing a 500.
 *
 * This class deliberately uses DatabaseMigrations (migrate:fresh per test,
 * NO wrapping transaction): production never runs generation inside a
 * transaction (ARCH-002 FR-027), so the race must be reproduced under autocommit —
 * where a failed INSERT aborts only its own statement and the recovery read
 * runs cleanly. Under RefreshDatabase's wrapping transaction PostgreSQL would
 * instead raise 25P02 on any post-violation statement, which is a test-harness
 * artifact, not the production contract.
 */
#[Group('phase7-explanation-generate')]
class Phase7ExplanationGenerateRaceTest extends TestCase
{
    use DatabaseMigrations;

    private ?User $teacher = null;
    private ?User $student = null;
    private ?Subject $subject = null;
    private ?Section $section = null;
    private ?Classroom $classroom = null;
    private ?CompetencyReference $competencyTag1 = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = $this->student = null;
        $this->subject = $this->section = null;
        $this->classroom = null;
        $this->competencyTag1 = null;

        // ARCH-006 §6: AI generation is mock-gated in tests — no wire traffic.
        config()->set('services.openrouter.mock', true);
    }

    /**
     * Build a minimal org hierarchy with one competency tag and one student.
     */
    private function setUpOrg(): void
    {
        $year = SchoolYear::create(['name' => 'SY 2026-P7RACE']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31']);
        $gradeLevel = GradeLevel::create([
            'semester_id' => $semester->id,
            'grade_level' => '7']);
        $this->section = Section::create([
            'grade_level_id' => $gradeLevel->id,
            'name' => '7A-P7RACE']);
$this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-P7RACE']);
        
        $this->competencyTag1 = CompetencyReference::create(['semester' => '1', 'code' => 'M7-NUM-01R',
            'descriptor' => 'Solve multi-step rational numbers',
            'subject_id' => $this->subject->id,
            'grade_level' => '7']);

        $this->teacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $this->student = User::factory()->create([
            'role' => 'Student',
            'must_change_password' => false,
            'name' => 'Alice Student']);


        $this->classroom = app(ClassroomService::class)->createClassroom($this->teacher->id, $this->subject->id, $this->section->id, '2026-2027', null);

        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->student->id,
            'joined_at' => now()]);
    }

    /**
     * Create a released-status assessment with the given items.
     */
    private function createReleasedAssessment(array $items): Assessment
    {
        $assessment = Assessment::create([
            'teacher_id' => $this->teacher->id,
            'classroom_id' => $this->classroom->id,
            'subject_id' => $this->subject->id,
            'semester_id' => $this->section->gradeLevel->semester_id,
            'title' => 'Phase 7 Generate Race Test',
            'description' => '',
            'type' => 'Recorded',
            'status' => 'draft']);

        $sortOrder = 1;
        foreach ($items as $itemData) {
            AssessmentItem::create([
                'assessment_id' => $assessment->id,
                'item_type' => $itemData['item_type'],
                'prompt' => $itemData['prompt'],
                'max_points' => $itemData['max_points'],
                'correct_answer' => $itemData['correct_answer'] ?? null,
                'competency_tag_id' => $itemData['competency_tag_id'],
                'sort_order' => $sortOrder++]);
        }

        $assessment->status = 'released';
        $assessment->save();

        return $assessment->fresh();
    }

    /**
     * Start + submit as the student, then teacher releases results.
     */
    private function startAndSubmitAndRelease(User $student, int $assessmentId, array $responses): void
    {
        $this->actingAs($student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start");

        $items = AssessmentItem::where('assessment_id', $assessmentId)
            ->orderBy('sort_order')
            ->get();
        $mappedResponses = [];
        foreach ($responses as $i => $responseText) {
            if (isset($items[$i])) {
                $mappedResponses[(string) $items[$i]->id] = $responseText;
            }
        }

        $this->actingAs($student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/submit", [
                'responses' => $mappedResponses]);

        Sanctum::actingAs($this->teacher);
        $this->postJson("/api/teacher/assessments/{$assessmentId}/release-results")
            ->assertOk();
    }

    /**
     * Deterministic seam reproducing the double-submit race: an Eloquent
     * `creating` listener fires exactly once — AFTER both dedupe reads but
     * BEFORE the loser's physical INSERT — seeding the concurrent "winner"
     * row via the query builder (no model events → no recursion). Under
     * autocommit the winner commits instantly, exactly like a winner request
     * on another connection in production.
     */
    public function test_generate_concurrent_duplicate_insert_degrades_to_serving_survivor_row(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment([
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->startAndSubmitAndRelease($this->student, $assessment->id, ['X']);

        $item = AssessmentItem::query()->where('assessment_id', $assessment->id)->firstOrFail();
        $submission = AssessmentSubmission::query()
            ->where('assessment_id', $assessment->id)
            ->where('student_id', $this->student->id)
            ->firstOrFail();

        $winnerText = 'Surviving row from the concurrent request.';
        $interfered = false;
        AIExplanation::creating(function () use (&$interfered, $assessment, $submission, $item, $winnerText): void {
            if ($interfered) {
                return;
            }
            $interfered = true;

            DB::table('ai_explanations')->insert([
                'student_id' => $this->student->id,
                'assessment_id' => $assessment->id,
                'assessment_submission_id' => $submission->id,
                'assessment_attempt_id' => $submission->attempt_id,
                'item_id' => $item->id,
                'explanation_text' => $winnerText,
                'is_ungrounded' => false,
                'is_follow_up' => false,
                'turn_number' => 0,
                'flagged' => false,
                'explain_further_disabled' => false,
                'moderation_status' => 'none']);
        });

        // Pre-fix: the loser's uncaught UniqueConstraintViolationException
        // surfaced as 500 INTERNAL_ERROR despite the stored row.
        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/student/assessments/{$assessment->id}/explanations/generate");

        // The loser degrades to success and serves the WINNER's stored text.
        $response->assertOk();
        $data = collect($response->json('data'));

        $this->assertCount(1, $data);
        $this->assertEquals($item->id, $data[0]['item_id']);
        $this->assertSame($winnerText, $data[0]['explanation_text']);
        $this->assertSame($submission->attempt_id, $data[0]['assessment_attempt_id']);

        // Exactly ONE row for the item — no duplicate was written.
        $this->assertDatabaseCount('ai_explanations', 1);
        $this->assertDatabaseHas('ai_explanations', [
            'student_id' => $this->student->id,
            'assessment_id' => $assessment->id,
            'item_id' => $item->id,
            'is_follow_up' => false,
            'turn_number' => 0,
            'assessment_attempt_id' => $submission->attempt_id]);
    }
}
