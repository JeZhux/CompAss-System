<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleConflictException;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentItem;
use App\Models\AssessmentSubmission;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
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
use App\Services\AssessmentService;
use App\Services\AssignmentService;
use App\Services\ClassroomService;
use App\Services\GradingService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * F-11: check-then-act races admit duplicate submissions/attempts.
 *
 * Each test reproduces the concurrent double-submit with a deterministic
 * seam: the winner row commits on a second connection (or via a `creating`
 * listener for the lock-free paths) after the loser's dedupe read but
 * before its physical INSERT. The loser must degrade to the documented
 * conflict outcome with exactly one persisted row and no duplicate
 * files/attempts/mastery.
 *
 * DatabaseMigrations (migrate:fresh per test, no wrapping transaction) is
 * deliberate: the race recovery runs under autocommit in production, and a
 * failed INSERT under RefreshDatabase's wrapping transaction would raise
 * 25P02 on post-violation statements (test-harness artifact, not the
 * production contract).
 */
#[Group('f-11')]
class SubmitRaceGuardTest extends TestCase
{
    use DatabaseMigrations;

    private function setUpOrg(): array
    {
        // F-11: a second, independently-committing connection to the same
        // test database. Winner rows seeded through it commit instantly —
        // exactly like a winner request on another connection — instead of
        // joining (and rolling back with) the loser's write transaction.
        config(['database.connections.race' => config('database.connections.pgsql')]);
        DB::purge('race');

        $year = SchoolYear::create(['name' => 'SY F11']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
        ]);
        $gradeLevel = GradeLevel::create(['semester_id' => $semester->id, 'grade_level' => '7']);
        $section = Section::create(['grade_level_id' => $gradeLevel->id, 'name' => '7A-F11']);
        $subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-F11']);
        $competency = CompetencyReference::create(['semester' => '1', 'code' => 'M7-F11-01',
            'descriptor' => 'F11 competency',
            'subject_id' => $subject->id,
            'grade_level' => '7',
        ]);

        $teacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $student = User::factory()->create(['role' => 'Student', 'must_change_password' => false]);
        $classroom = app(ClassroomService::class)->createClassroom($teacher->id, $subject->id, $section->id, '2026-2027', null);
        ClassroomEnrollment::create([
            'classroom_id' => $classroom->id,
            'student_id' => $student->id,
            'joined_at' => now(),
        ]);

        return compact('teacher', 'student', 'subject', 'competency', 'classroom', 'semester', 'section');
    }

    private function createAssignment(array $org): Assignment
    {
        return Assignment::create([
            'teacher_id' => $org['teacher']->id,
            'classroom_id' => $org['classroom']->id,
            'subject_id' => $org['subject']->id,
            'semester_id' => $org['semester']->id,
            'title' => 'F11 Assignment',
            'description' => '',
            'due_date' => now()->addDay(),
        ]);
    }

    private function createReleasedAssessment(array $org, string $itemType = 'multiple_choice'): Assessment
    {
        $assessment = Assessment::create([
            'teacher_id' => $org['teacher']->id,
            'classroom_id' => $org['classroom']->id,
            'subject_id' => $org['subject']->id,
            'semester_id' => $org['semester']->id,
            'title' => 'F11 Assessment',
            'description' => '',
            'type' => 'Recorded',
            'status' => 'released',
        ]);

        AssessmentItem::create([
            'assessment_id' => $assessment->id,
            'item_type' => $itemType,
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => $itemType === 'essay' ? null : 'A',
            'competency_tag_id' => $org['competency']->id,
            'sort_order' => 1,
        ]);

        return $assessment->fresh();
    }

    private function startAttempt(array $org, Assessment $assessment): AssessmentAttempt
    {
        return app(AssessmentService::class)->startAssessment($org['student']->id, $assessment->id)
            ? AssessmentAttempt::query()
                ->where('assessment_id', $assessment->id)
                ->where('student_id', $org['student']->id)
                ->where('status', 'in_progress')
                ->latest('id')
                ->firstOrFail()
            : throw new \RuntimeException('Start failed.');
    }

    public function test_assignment_concurrent_duplicate_degrades_to_already_submitted(): void
    {
        Storage::fake('local');
        $org = $this->setUpOrg();
        $assignment = $this->createAssignment($org);

        $interfered = false;
        AssignmentSubmission::creating(function () use (&$interfered, $org, $assignment): void {
            if ($interfered) {
                return;
            }
            $interfered = true;

            DB::connection('race')->table('assignment_submissions')->insert([
                'assignment_id' => $assignment->id,
                'student_id' => $org['student']->id,
                'submitted_at' => now(),
                'status' => 'on_time',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            app(AssignmentService::class)->submitAssignment(
                $org['student']->id,
                $assignment->id,
                [UploadedFile::fake()->create('answer.pdf', 100, 'application/pdf')]
            );
            $this->fail('Expected ALREADY_SUBMITTED for the concurrent loser.');
        } catch (BusinessRuleConflictException $e) {
            $this->assertSame('ALREADY_SUBMITTED', $e->errorCode);
        }

        $this->assertSame(1, AssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->where('student_id', $org['student']->id)
            ->count());
        $this->assertDatabaseCount('submission_files', 0);
    }

    public function test_assignment_sequential_retry_after_success_returns_already_submitted(): void
    {
        Storage::fake('local');
        $org = $this->setUpOrg();
        $assignment = $this->createAssignment($org);
        $service = app(AssignmentService::class);

        $service->submitAssignment(
            $org['student']->id,
            $assignment->id,
            [UploadedFile::fake()->create('answer.pdf', 100, 'application/pdf')]
        );

        try {
            $service->submitAssignment(
                $org['student']->id,
                $assignment->id,
                [UploadedFile::fake()->create('answer2.pdf', 100, 'application/pdf')]
            );
            $this->fail('Expected ALREADY_SUBMITTED on sequential retry.');
        } catch (BusinessRuleConflictException $e) {
            $this->assertSame('ALREADY_SUBMITTED', $e->errorCode);
        }

        $this->assertSame(1, AssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->where('student_id', $org['student']->id)
            ->count());
        $this->assertSame(1, DB::table('submission_files')->count());
    }

    public function test_assessment_concurrent_duplicate_submit_degrades_to_already_submitted(): void
    {
        $org = $this->setUpOrg();
        $assessment = $this->createReleasedAssessment($org);
        $attempt = $this->startAttempt($org, $assessment);
        $item = AssessmentItem::query()->where('assessment_id', $assessment->id)->firstOrFail();
        $sectionId = Classroom::findOrFail($assessment->classroom_id)->section_id;

        // Deterministic race seam without holding a row lock: the winner's
        // submission commits BEFORE the loser starts (no FK deadlock — the
        // loser holds no lock yet). The loser's outer status pre-check still
        // passes (attempt is in_progress; the winner row alone does not flip
        // it), so the loser exercises the in-transaction UNIQUE(attempt_id)
        // fallback and must degrade to ALREADY_SUBMITTED.
        DB::table('assessment_submissions')->insert([
            'attempt_id' => $attempt->id,
            'assessment_id' => $assessment->id,
            'student_id' => $org['student']->id,
            'section_id' => $sectionId,
            'semester_id' => $assessment->semester_id,
            'submitted_at' => now(),
            'status' => 'pending_grading',
            'is_results_released' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(AssessmentService::class)->submitAssessment(
                $org['student']->id,
                $assessment->id,
                [(string) $item->id => 'A']
            );
            $this->fail('Expected ALREADY_SUBMITTED for the concurrent loser.');
        } catch (BusinessRuleConflictException $e) {
            $this->assertSame('ALREADY_SUBMITTED', $e->errorCode);
        }

        $this->assertSame(1, AssessmentSubmission::query()
            ->where('assessment_id', $assessment->id)
            ->where('student_id', $org['student']->id)
            ->count());
        // Loser rolled back: no responses or mastery from the losing attempt.
        $this->assertSame(0, DB::table('assessment_responses')->count());
        $this->assertSame(0, MasteryRecord::query()
            ->where('assessment_id', $assessment->id)
            ->where('student_id', $org['student']->id)
            ->count());
    }

    public function test_assessment_sequential_retry_after_success_returns_already_submitted(): void
    {
        $org = $this->setUpOrg();
        $assessment = $this->createReleasedAssessment($org);
        $this->startAttempt($org, $assessment);
        $item = AssessmentItem::query()->where('assessment_id', $assessment->id)->firstOrFail();
        $service = app(AssessmentService::class);

        $result = $service->submitAssessment($org['student']->id, $assessment->id, [(string) $item->id => 'A']);
        $this->assertSame('scored', $result['status']);

        try {
            $service->submitAssessment($org['student']->id, $assessment->id, [(string) $item->id => 'A']);
            $this->fail('Expected ALREADY_SUBMITTED on sequential retry.');
        } catch (BusinessRuleConflictException $e) {
            $this->assertSame('ALREADY_SUBMITTED', $e->errorCode);
        }

        $this->assertSame(1, AssessmentSubmission::query()
            ->where('assessment_id', $assessment->id)
            ->where('student_id', $org['student']->id)
            ->count());
        $this->assertSame(1, MasteryRecord::query()
            ->where('assessment_id', $assessment->id)
            ->where('student_id', $org['student']->id)
            ->count());
    }

    public function test_resubmission_sequential_retry_after_success_returns_active_attempt(): void
    {
        $org = $this->setUpOrg();
        $assessment = $this->createReleasedAssessment($org, 'essay');
        $item = AssessmentItem::query()->where('assessment_id', $assessment->id)->firstOrFail();

        // Score the first attempt so it is resubmission-eligible.
        $this->startAttempt($org, $assessment);
        app(AssessmentService::class)->submitAssessment($org['student']->id, $assessment->id, [(string) $item->id => 'Essay.']);
        $scored = AssessmentAttempt::query()
            ->where('assessment_id', $assessment->id)
            ->where('student_id', $org['student']->id)
            ->where('status', 'pending_grading')
            ->latest('id')
            ->firstOrFail();
        app(GradingService::class)->recordManualGrade($scored->id, [
            ['questionId' => $item->id, 'score' => 8, 'maxScore' => 10],
        ], $org['teacher']->id);

        app(GradingService::class)->requestResubmission($scored->id, 'Second chance', $org['teacher']->id);

        try {
            app(GradingService::class)->requestResubmission($scored->id, 'Second chance', $org['teacher']->id);
            $this->fail('Expected STUDENT_HAS_ACTIVE_ATTEMPT on sequential retry.');
        } catch (BusinessRuleConflictException $e) {
            $this->assertSame('STUDENT_HAS_ACTIVE_ATTEMPT', $e->errorCode);
        }

        $this->assertSame(1, AssessmentAttempt::query()
            ->where('assessment_id', $assessment->id)
            ->where('student_id', $org['student']->id)
            ->where('status', 'in_progress')
            ->count());
    }
}
