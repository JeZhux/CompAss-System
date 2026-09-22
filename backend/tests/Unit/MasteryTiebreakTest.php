<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentItem;
use App\Models\AssessmentResponse;
use App\Models\AssessmentSubmission;
use App\Models\ClassroomEnrollment;
use App\Models\CompetencyReference;
use App\Models\GradeLevel;
use App\Models\MasteryRecord;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Semester;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\ClassroomService;
use App\Services\CompetencyMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F-05: identical-created_at tiebreak (ARCH-002 FR-020, ARCH-002 FR-020). Two mastery
 * records for the same (student, competency) with the SAME created_at
 * and opposite statuses resolve to the HIGHER id in every derivation:
 * heatmap, aggregate summary, and class-level report. No prod change
 * unless a mismatch is found — scopeLatestPerPair
 * (ORDER BY created_at DESC, id DESC) is preserved.
 */
#[Group('mastery-tiebreak')]
class MasteryTiebreakTest extends TestCase
{
    use RefreshDatabase;

    public function test_identical_created_at_higher_id_wins_in_heatmap_summary_and_class_report(): void
    {
        $year = SchoolYear::create(['name' => 'SY 2025-TB']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2025-06-01',
            'end_date' => '2025-08-31']);
        $gradeLevel = GradeLevel::create(['semester_id' => $semester->id, 'grade_level' => '7']);
        $section = Section::create(['grade_level_id' => $gradeLevel->id, 'name' => '7A-TB']);

        $teacher = User::factory()->create(['role' => 'Teacher']);
        $student = User::factory()->create([
            'role' => 'Student']);

        $subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-TB']);


        $classroom = app(ClassroomService::class)->createClassroom($teacher->id, $subject->id, $section->id, '2026-2027', null);
        ClassroomEnrollment::create([
            'classroom_id' => $classroom->id,
            'student_id' => $student->id,
            'joined_at' => now()]);

        $competency = CompetencyReference::create(['semester' => '1', 'code' => 'M7-TB-01',
            'descriptor' => 'Tiebreak competency',
            'subject_id' => $subject->id,
            'grade_level' => '7']);

        $makeAssessmentWithItem = function (string $title) use ($teacher, $subject, $semester, $competency, $classroom): array {
            $assessment = Assessment::create([
                'teacher_id' => $teacher->id,
                'subject_id' => $subject->id,
                'classroom_id' => $classroom->id,
                'semester_id' => $semester->id,
                'title' => $title,
                'type' => 'Recorded',
                'status' => 'released']);
            $item = AssessmentItem::create([
                'assessment_id' => $assessment->id,
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $competency->id,
                'sort_order' => 1]);

            return [$assessment, $item];
        };

        [$assessment1, $item1] = $makeAssessmentWithItem('TB Test 1');
        [$assessment2, $item2] = $makeAssessmentWithItem('TB Test 2');

        $service = app(CompetencyMappingService::class);

        // Lower id → Mastered; higher id → Not_Mastered.
        $service->computeMastery(
            $this->createObjectiveSubmission($assessment1, $student, $section, [$item1->id => 'A'])->id,
            $subject->id
        );
        $service->computeMastery(
            $this->createObjectiveSubmission($assessment2, $student, $section, [$item2->id => 'X'])->id,
            $subject->id
        );

        $records = MasteryRecord::query()
            ->where('student_id', $student->id)
            ->where('competency_id', $competency->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $records);
        $this->assertSame('Mastered', $records->first()->mastery_status);
        $this->assertSame('Not_Mastered', $records->last()->mastery_status);
        $higherId = $records->last()->id;

        // Force IDENTICAL created_at — only the id tiebreak may decide.
        MasteryRecord::query()->update(['created_at' => '2026-08-16 10:00:00']);

        // The shared scope resolves to the higher id only.
        $this->assertSame(
            [$higherId],
            MasteryRecord::query()->latestPerPair()->pluck('id')->all()
        );

        // Heatmap counts the higher id only.
        $heatmap = app(AnalyticsService::class)->getTeacherHeatmap($teacher->id, $subject->id);
        $this->assertCount(1, $heatmap['competencies']);
        $this->assertSame(0, $heatmap['competencies'][0]['mastered_count']);
        $this->assertSame(1, $heatmap['competencies'][0]['not_mastered_count']);
        $this->assertSame(1, $heatmap['competencies'][0]['total_students_assessed']);

        // Aggregate summary counts the higher id only.
        $summary = $service->getAggregateSummaries([$section->id]);
        $this->assertCount(1, $summary);
        $this->assertSame(0, $summary[0]['mastered_count']);
        $this->assertSame(1, $summary[0]['not_mastered_count']);
        $this->assertSame(1, $summary[0]['total_students_assessed']);

        // Class-level report counts the higher id only.
        $report = $service->getClassLevelReport($section->id);
        $this->assertCount(1, $report['competency_reports']);
        $this->assertSame(0, $report['competency_reports'][0]['mastered_count']);
        $this->assertSame(1, $report['competency_reports'][0]['not_mastered_count']);
        $this->assertSame(1, $report['competency_reports'][0]['total_assessed']);
    }

    /**
     * Mirror of Phase5ServiceTest::createObjectiveSubmission (minimal).
     */
    private function createObjectiveSubmission(
        Assessment $assessment,
        User $student,
        Section $section,
        array $responses,
    ): AssessmentSubmission {
        $attempt = AssessmentAttempt::create([
            'assessment_id' => $assessment->id,
            'student_id' => $student->id,
            'attempt_number' => 1,
            'status' => 'in_progress',
            'response_history' => []]);

        $submission = AssessmentSubmission::create([
            'attempt_id' => $attempt->id,
            'assessment_id' => $assessment->id,
            'student_id' => $student->id,
            'section_id' => $section->id,
            'semester_id' => $assessment->semester_id,
            'submitted_at' => now(),
            'status' => 'scored']);

        foreach ($assessment->items()->orderBy('sort_order')->get() as $item) {
            $responseText = $responses[$item->id] ?? null;
            $isCorrect = $responseText !== null
                && strtolower(trim($responseText)) === strtolower(trim($item->correct_answer));

            AssessmentResponse::create([
                'submission_id' => $submission->id,
                'item_id' => $item->id,
                'response_text' => $responseText,
                'earned_points' => $isCorrect ? (float) $item->max_points : 0.0,
                'is_auto_scored' => true]);
        }

        $attempt->status = 'scored';
        $attempt->save();

        return $submission;
    }
}
