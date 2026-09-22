<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentSubmission;
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
 * Phase 3 — ARCH-002 FR-004: ARCH-002 FR-004 Pending-Grading deactivation guard.
 *
 * A teacher with a pending-grading submission (submission status
 * 'pending_grading' on an assessment the teacher owns) cannot be deactivated:
 * the deactivate endpoint returns 409 PENDING_GRADING_BLOCKS_DEACTIVATION.
 * Deactivation otherwise succeeds (ARCH-002 FR-004 wind-down: blocks future logins only).
 */
#[Group('phase3-deactivation-guard')]
class Phase3DeactivationGuardTest extends TestCase
{
    use RefreshDatabase;

    private function actAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    /**
     * Build the org chain (School Year -> Semester ->
     * Grade Level 7 -> Section + Subject).
     *
     * @return array{subject: Subject, section: Section, semester: Semester, gradeLevel: GradeLevel}
     */
    private function setUpOrg(): array
    {
        $year = SchoolYear::create(['name' => 'SY 2026']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
        ]);
        $gradeLevel = GradeLevel::create([
            'semester_id' => $semester->id,
            'grade_level' => '7',
        ]);
        $section = Section::create([
            'grade_level_id' => $gradeLevel->id,
            'name' => '7A',
        ]);
        $subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7']);

        // Teacher scope derives from the classroom itself (teacher_id +
        // subject_id + section_id + school year) — no assignment row.
        return ['subject' => $subject, 'section' => $section, 'semester' => $semester, 'gradeLevel' => $gradeLevel];
    }

    /**
     * Create a teacher-owned assessment with one student attempt + submission
     * in the given states, all inside the org chain.
     *
     * @param  array{subject: Subject, section: Section, semester: Semester}  $org
     * @return array{teacher: User, assessment: Assessment, attempt: AssessmentAttempt, submission: AssessmentSubmission}
     */
    private function createSubmission(
        array $org,
        string $attemptStatus,
        string $submissionStatus
    ): array {
        $teacher = User::factory()->teacher()->create();
        $student = User::factory()->student()->create();


        $classroom = app(ClassroomService::class)->createClassroom(
            $teacher->id,
            $org['subject']->id,
            $org['section']->id,
            '2026-2027',
            null
        );

        $assessment = Assessment::create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $org['subject']->id,
            'semester_id' => $org['semester']->id,
            'title' => 'Deactivation guard assessment',
            'description' => '',
            'type' => 'Recorded',
            'status' => 'released',
        ]);

        $attempt = AssessmentAttempt::create([
            'assessment_id' => $assessment->id,
            'student_id' => $student->id,
            'attempt_number' => 1,
            'status' => $attemptStatus,
        ]);

        $submission = AssessmentSubmission::create([
            'attempt_id' => $attempt->id,
            'assessment_id' => $assessment->id,
            'student_id' => $student->id,
            'section_id' => $org['section']->id,
            'semester_id' => $assessment->semester_id,
            'submitted_at' => now(),
            'status' => $submissionStatus,
        ]);

        return [
            'teacher' => $teacher,
            'assessment' => $assessment,
            'attempt' => $attempt,
            'submission' => $submission,
        ];
    }

    // ========================================================================
    // ARCH-002 FR-004 — ARCH-002 FR-004 Pending-Grading deactivation guard
    // ========================================================================

    public function test_deactivation_teacher_with_pending_grading_returns_409(): void
    {
        $this->actAsAdmin();
        $org = $this->setUpOrg();
        $fixture = $this->createSubmission($org, 'pending_grading', 'pending_grading');

        $response = $this->post('/api/admin/users/'.$fixture['teacher']->id.'/deactivate');

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'PENDING_GRADING_BLOCKS_DEACTIVATION')
            ->assertJsonMissingPath('error.blocking_assessments');

        // ARCH-002 FR-004: the account is NOT deactivated — only future logins are ever blocked.
        $this->assertDatabaseHas('users', [
            'id' => $fixture['teacher']->id,
            'is_active' => true,
        ]);
    }

    public function test_deactivation_teacher_without_pending_grading_succeeds(): void
    {
        $this->actAsAdmin();
        $org = $this->setUpOrg();

        // Another teacher's pending grading must NOT block this teacher
        // (ownership scoping via assessments.teacher_id, ARCH-002 FR-011).
        $this->createSubmission($org, 'pending_grading', 'pending_grading');

        $teacher = User::factory()->teacher()->create();

        $this->post('/api/admin/users/'.$teacher->id.'/deactivate')
            ->assertOk()
            ->assertJsonPath('data.message', 'Account deactivated.');

        $this->assertDatabaseHas('users', [
            'id' => $teacher->id,
            'is_active' => false,
        ]);
    }

    public function test_deactivation_teacher_with_scored_submission_not_blocked(): void
    {
        $this->actAsAdmin();
        $org = $this->setUpOrg();
        $fixture = $this->createSubmission($org, 'scored', 'scored');

        $this->post('/api/admin/users/'.$fixture['teacher']->id.'/deactivate')
            ->assertOk()
            ->assertJsonPath('data.message', 'Account deactivated.');

        $this->assertDatabaseHas('users', [
            'id' => $fixture['teacher']->id,
            'is_active' => false,
        ]);
    }

    public function test_deactivation_teacher_with_in_progress_resubmission_attempt_not_blocked(): void
    {
        $this->actAsAdmin();
        $org = $this->setUpOrg();

        // ARCH-002 FR-018 mid-state: original attempt fully scored, then the teacher
        // requested a resubmission — a new in_progress attempt exists but the
        // student has NOT submitted, so there is no assessment_submissions row.
        $original = $this->createSubmission($org, 'scored', 'scored');

        AssessmentAttempt::create([
            'assessment_id' => $original['assessment']->id,
            'student_id' => $original['submission']->student_id,
            'attempt_number' => 2,
            'status' => 'in_progress',
            'is_resubmission' => true,
            'resubmission_of_attempt_id' => $original['attempt']->id,
            'resubmission_reason' => 'Revise your answer.',
            'resubmission_requested_at' => now(),
        ]);

        $this->assertSame(
            0,
            AssessmentSubmission::query()
                ->where('assessment_id', $original['assessment']->id)
                ->where('status', 'pending_grading')
                ->count()
        );

        $this->post('/api/admin/users/'.$original['teacher']->id.'/deactivate')
            ->assertOk()
            ->assertJsonPath('data.message', 'Account deactivated.');

        $this->assertDatabaseHas('users', [
            'id' => $original['teacher']->id,
            'is_active' => false,
        ]);
    }
}
