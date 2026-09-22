<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

#[Group('phase3-negative-path')]
class Phase3NegativePathTest extends TestCase
{
    use RefreshDatabase;

    private ?User $teacher = null;

    private ?User $student = null;

    private ?User $teacherB = null;

    private ?User $studentB = null;

    
    private ?Subject $subject = null;

    private ?Section $section = null;

    private ?Classroom $classroom = null;

    private ?CompetencyReference $competencyTag = null;

    // ========================================================================
    // Helpers
    // ========================================================================

    private function actAsTeacher(): User
    {
        if (! $this->teacher) {
            $this->teacher = User::factory()->create([
                'role' => 'Teacher',
                'must_change_password' => false]);
        }

        Sanctum::actingAs($this->teacher);

        return $this->teacher;
    }

    private function actAsStudent(): User
    {
        if (! $this->student) {
            $this->student = User::factory()->create([
                'role' => 'Student',
                'must_change_password' => false]);
        }

        Sanctum::actingAs($this->student);

        return $this->student;
    }

    private function setUpOrg(): void
    {
        $year = SchoolYear::create(['name' => 'SY 2026']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31']);
        $gradeLevel = GradeLevel::create([
            'semester_id' => $semester->id,
            'grade_level' => '7']);
        $this->section = Section::create([
            'grade_level_id' => $gradeLevel->id,
            'name' => '7A']);
$this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7']);
                $this->competencyTag = CompetencyReference::create(['semester' => '1', 'code' => 'M7-NUM-001',
            'descriptor' => 'Solve multi-step rational numbers',
            'subject_id' => $this->subject->id,
            'grade_level' => '7']);

        $teacher = $this->actAsTeacher();

        $student = $this->actAsStudent();
        $this->classroom = app(ClassroomService::class)->createClassroom($teacher->id, $this->subject->id, $this->section->id, '2026-2027', null);
        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $student->id,
            'joined_at' => now()]);
    }

    /**
     * Create a second teacher (not assigned to any section).
     */
    private function createSecondTeacher(): User
    {
        if (! $this->teacherB) {
            $this->teacherB = User::factory()->create([
                'role' => 'Teacher',
                'must_change_password' => false]);
        }

        Sanctum::actingAs($this->teacherB);

        return $this->teacherB;
    }

    /**
     * Create a second student enrolled in the same classroom.
     */
    private function createSecondStudent(): User
    {
        if (! $this->studentB) {
            $this->studentB = User::factory()->create([
                'role' => 'Student',
                'must_change_password' => false]);

            ClassroomEnrollment::create([
                'classroom_id' => $this->classroom->id,
                'student_id' => $this->studentB->id,
                'joined_at' => now()]);
        }

        Sanctum::actingAs($this->studentB);

        return $this->studentB;
    }

    /**
     * Create a released assessment with a single item.
     * Returns ['assessment' => [...], 'item' => [...]] from JSON data.
     */
    private function createReleasedAssessmentWithItem(
        string $itemType = 'multiple_choice',
        ?string $correctAnswer = 'B'
    ): array {
        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'title' => 'Released Assessment',
                'description' => '',
                'type' => 'Recorded'])
            ->json('data');

        $item = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", [
                'item_type' => $itemType,
                'prompt' => 'Sample question',
                'max_points' => 10,
                'correct_answer' => $correctAnswer,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1])
            ->json('data');

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/release");

        return ['assessment' => $assessment, 'item' => $item];
    }

    /**
     * Helper: student starts an assessment and returns the attempt_id.
     */
    private function studentStartAssessment(int $assessmentId, ?User $student = null): int
    {
        $user = $student ?? $this->student;

        $response = $this->actingAs($user, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start");

        return $response->json('data.attempt_id');
    }

    /**
     * Helper: student submits an assessment.
     */
    private function studentSubmitAssessment(int $assessmentId, array $responses, ?User $student = null): void
    {
        $user = $student ?? $this->student;

        $this->actingAs($user, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/submit", [
                'responses' => $responses]);
    }

    /**
     * Get the most recent submission for an assessment by student ID.
     */
    private function getSubmissionForStudent(int $assessmentId, int $studentId): ?AssessmentSubmission
    {
        return AssessmentSubmission::query()
            ->where('assessment_id', $assessmentId)
            ->where('student_id', $studentId)
            ->latest('submitted_at')
            ->first();
    }

    // ========================================================================
    // 404 Tests — Ownership / Not Found
    // ========================================================================

    public function test_teacher_updates_non_existent_announcement_returns_404(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->put('/api/teacher/announcements/99999', [
                'title' => 'Updated Title',
                'body' => 'Updated body']);

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_teacher_deletes_non_existent_announcement_returns_404(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->delete('/api/teacher/announcements/99999');

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_teacher_updates_another_teacher_assessment_returns_404(): void
    {
        $this->setUpOrg();

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Original',
                'description' => '',
                'type' => 'Recorded'])
            ->json('data');

        $teacherB = $this->createSecondTeacher();

        $response = $this->actingAs($teacherB, 'sanctum')
            ->put("/api/teacher/assessments/{$assessment['id']}", [
                'title' => 'Hacked']);

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_teacher_deletes_another_teacher_assessment_returns_404(): void
    {
        $this->setUpOrg();

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Draft',
                'description' => '',
                'type' => 'Recorded'])
            ->json('data');

        $teacherB = $this->createSecondTeacher();

        $response = $this->actingAs($teacherB, 'sanctum')
            ->delete("/api/teacher/assessments/{$assessment['id']}");

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_teacher_views_another_teacher_assessment_returns_404(): void
    {
        $this->setUpOrg();

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Secret Assessment',
                'description' => '',
                'type' => 'Recorded'])
            ->json('data');

        $teacherB = $this->createSecondTeacher();

        $response = $this->actingAs($teacherB, 'sanctum')
            ->get("/api/teacher/assessments/{$assessment['id']}");

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_teacher_views_another_teacher_assignment_returns_404(): void
    {
        $this->setUpOrg();

        $assignment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assignments', [
                'subject_id' => $this->subject->id,
                'title' => 'Secret Assignment',
                'description' => '',
                'due_date' => '2026-10-15 23:59:00'])
            ->json('data');

        $teacherB = $this->createSecondTeacher();

        $response = $this->actingAs($teacherB, 'sanctum')
            ->get("/api/teacher/assignments/{$assignment['id']}");

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_teacher_views_another_teacher_assignment_submission_returns_404(): void
    {
        Storage::fake('local');
        $this->setUpOrg();

        $assignment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assignments', [
                'subject_id' => $this->subject->id,
                'title' => 'HW With Submissions',
                'description' => '',
                'due_date' => '2026-10-15 23:59:00'])
            ->json('data');

        $submitResponse = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assignments/{$assignment['id']}/submit", [
                'files' => [
                    UploadedFile::fake()->create('answer.pdf', 100, 'application/pdf')]]);

        $submissionId = $submitResponse->json('data.id');

        $teacherB = $this->createSecondTeacher();

        $response = $this->actingAs($teacherB, 'sanctum')
            ->get("/api/teacher/submissions/{$submissionId}");

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_teacher_views_pending_items_for_another_teacher_submission_returns_404(): void
    {
        $this->setUpOrg();

        $data = $this->createReleasedAssessmentWithItem('essay');

        $assessmentId = $data['assessment']['id'];
        $essayItemId = $data['item']['id'];

        // Student starts and submits (essay -> pending_grading).
        $this->studentStartAssessment($assessmentId);
        $this->studentSubmitAssessment($assessmentId, [$essayItemId => 'Essay response.']);

        $submission = $this->getSubmissionForStudent($assessmentId, $this->student->id);

        $teacherB = $this->createSecondTeacher();

        $response = $this->actingAs($teacherB, 'sanctum')
            ->get("/api/teacher/submissions/{$submission->id}/pending-items");

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_teacher_scores_submission_from_another_assessment_returns_404(): void
    {
        $this->setUpOrg();

        $data = $this->createReleasedAssessmentWithItem('essay');

        $assessmentId = $data['assessment']['id'];
        $essayItemId = $data['item']['id'];

        // Student starts and submits (essay -> pending_grading).
        $this->studentStartAssessment($assessmentId);
        $this->studentSubmitAssessment($assessmentId, [$essayItemId => 'Essay response.']);

        $submission = $this->getSubmissionForStudent($assessmentId, $this->student->id);

        $teacherB = $this->createSecondTeacher();

        $response = $this->actingAs($teacherB, 'sanctum')
            ->post("/api/teacher/submissions/{$submission->id}/score", [
                'scores' => [
                    (string) $essayItemId => 8]]);

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_teacher_releases_results_for_another_teacher_assessment_returns_404(): void
    {
        $this->setUpOrg();

        $data = $this->createReleasedAssessmentWithItem('multiple_choice', 'A');

        $assessmentId = $data['assessment']['id'];

        // Student starts and submits so release-results has a submission to check.
        $this->studentStartAssessment($assessmentId);
        $this->studentSubmitAssessment($assessmentId, [1 => 'A']);

        $teacherB = $this->createSecondTeacher();

        $response = $this->actingAs($teacherB, 'sanctum')
            ->post("/api/teacher/assessments/{$assessmentId}/release-results");

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_teacher_deletes_another_teacher_announcement_returns_404(): void
    {
        $this->setUpOrg();

        $announcement = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/announcements', [
                'subject_id' => $this->subject->id,
                'title' => 'My Announcement',
                'body' => 'Hello'])
            ->json('data');

        $teacherB = $this->createSecondTeacher();

        $response = $this->actingAs($teacherB, 'sanctum')
            ->delete("/api/teacher/announcements/{$announcement['id']}");

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_student_downloads_from_non_enrolled_section_returns_404(): void
    {
        Storage::fake('local');
        $this->setUpOrg();

        // Student is enrolled — try to download a non-existent announcement.
        $response = $this->actingAs($this->student, 'sanctum')
            ->get('/api/student/announcements/99999/download/99999');

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_student_views_results_for_assessment_not_taken_returns_404(): void
    {
        $this->setUpOrg();

        $data = $this->createReleasedAssessmentWithItem('multiple_choice', 'B');

        $assessmentId = $data['assessment']['id'];

        // Student never started — no submission exists.
        $response = $this->actingAs($this->student, 'sanctum')
            ->get("/api/student/assessments/{$assessmentId}/results");

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_student_views_another_student_results_returns_404(): void
    {
        $this->setUpOrg();

        $data = $this->createReleasedAssessmentWithItem('multiple_choice', 'B');

        $assessmentId = $data['assessment']['id'];
        $itemId = $data['item']['id'];

        // Student A starts and submits.
        $this->studentStartAssessment($assessmentId);
        $this->studentSubmitAssessment($assessmentId, [1 => 'B']);

        // Teacher releases results so they are visible.
        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessmentId}/release-results");

        // Student B (not taken) tries to view results.
        $studentB = $this->createSecondStudent();

        $response = $this->actingAs($studentB, 'sanctum')
            ->get("/api/student/assessments/{$assessmentId}/results");

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'NOT_FOUND');
    }

    // ========================================================================
    // 409 Tests — Business Rule Conflicts
    // ========================================================================

    public function test_delete_released_assessment_returns_409(): void
    {
        Storage::fake('local');
        $this->setUpOrg();

        $data = $this->createReleasedAssessmentWithItem('multiple_choice', 'B');
        $assessmentId = $data['assessment']['id'];

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->delete("/api/teacher/assessments/{$assessmentId}");

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'ASSESSMENT_RELEASED');
    }

    public function test_release_already_released_assessment_returns_409(): void
    {
        $this->setUpOrg();

        $data = $this->createReleasedAssessmentWithItem('multiple_choice', 'B');
        $assessmentId = $data['assessment']['id'];

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessmentId}/release");

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'ASSESSMENT_ALREADY_RELEASED');
    }

    public function test_delete_item_from_released_assessment_returns_409(): void
    {
        $this->setUpOrg();

        $data = $this->createReleasedAssessmentWithItem('multiple_choice', 'B');
        $itemId = $data['item']['id'];

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->delete("/api/teacher/items/{$itemId}");

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'POST_RELEASE_STRUCTURAL_EDIT_BLOCKED');
    }

    public function test_score_non_pending_grading_submission_returns_409(): void
    {
        Storage::fake('local');
        $this->setUpOrg();

        // Objective-only assessment → auto-scored → status: scored.
        $data = $this->createReleasedAssessmentWithItem('multiple_choice', 'B');

        $assessmentId = $data['assessment']['id'];
        $itemId = $data['item']['id'];

        $this->studentStartAssessment($assessmentId);
        $this->studentSubmitAssessment($assessmentId, [$itemId => 'B']);

        $submission = $this->getSubmissionForStudent($assessmentId, $this->student->id);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/submissions/{$submission->id}/score", [
                'scores' => [
                    (string) $itemId => 8]]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'NOT_PENDING_GRADING');
    }

    public function test_score_objective_item_returns_409(): void
    {
        Storage::fake('local');
        $this->setUpOrg();

        // Assessment with BOTH objective and essay items → pending_grading.
        // Add both items while in draft, THEN release.
        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Mixed Assessment',
                'description' => '',
                'type' => 'Recorded'])
            ->json('data');

        $assessmentId = $assessment['id'];

        // Add objective (multiple_choice) item.
        $objectiveItem = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessmentId}/items", [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 2+2?',
                'max_points' => 10,
                'correct_answer' => 'B',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1])
            ->json('data');

        $objectiveItemId = $objectiveItem['id'];

        // Add essay item (while still in draft).
        $essayItem = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessmentId}/items", [
                'item_type' => 'essay',
                'prompt' => 'Explain your reasoning.',
                'max_points' => 15,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 2])
            ->json('data');

        $essayItemId = $essayItem['id'];

        // Now release the assessment.
        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessmentId}/release");

        // Student starts and submits → pending_grading (because of essay item).
        $this->studentStartAssessment($assessmentId);
        $this->studentSubmitAssessment($assessmentId, [
            (int) $objectiveItemId => 'B',
            (int) $essayItemId => 'My essay answer.']);

        $submission = $this->getSubmissionForStudent($assessmentId, $this->student->id);

        // Teacher tries to score the objective item → 409 NOT_A_SUBJECTIVE_ITEM.
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/submissions/{$submission->id}/score", [
                'scores' => [
                    (string) $objectiveItemId => 8]]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'NOT_A_SUBJECTIVE_ITEM');
    }

    public function test_score_exceeds_maximum_returns_409(): void
    {
        Storage::fake('local');
        $this->setUpOrg();

        // Assessment with essay item (max_points = 10) → pending_grading.
        $data = $this->createReleasedAssessmentWithItem('essay');
        $assessmentId = $data['assessment']['id'];
        $essayItemId = $data['item']['id'];

        $this->studentStartAssessment($assessmentId);
        $this->studentSubmitAssessment($assessmentId, [$essayItemId => 'Essay response.']);

        $submission = $this->getSubmissionForStudent($assessmentId, $this->student->id);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/submissions/{$submission->id}/score", [
                'scores' => [
                    (string) $essayItemId => 15, // max_points is 10
                ]]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'SCORE_EXCEEDS_MAXIMUM');
    }

    public function test_release_results_on_unreleased_assessment_returns_409(): void
    {
        $this->setUpOrg();

        // Create an assessment with items but do NOT release it.
        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Unreleased',
                'description' => '',
                'type' => 'Recorded'])
            ->json('data');

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is the answer?',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1]);

        // Try to release results on a draft (unreleased) assessment.
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/release-results");

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'ASSESSMENT_NOT_RELEASED');
    }

    public function test_start_non_released_assessment_returns_404(): void
    {
        Storage::fake('local');
        $this->setUpOrg();

        // Create an assessment with items but do NOT release it (stays in draft).
        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Draft Assessment',
                'description' => '',
                'type' => 'Recorded'])
            ->json('data');

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 2+2?',
                'max_points' => 10,
                'correct_answer' => 'B',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1]);

        // Student tries to start a draft (unreleased) assessment → 404.
        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/start");

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_start_already_submitted_assessment_returns_409(): void
    {
        Storage::fake('local');
        $this->setUpOrg();

        $data = $this->createReleasedAssessmentWithItem('multiple_choice', 'B');
        $assessmentId = $data['assessment']['id'];
        $itemId = $data['item']['id'];

        // Student starts and submits.
        $this->studentStartAssessment($assessmentId);
        $this->studentSubmitAssessment($assessmentId, [$itemId => 'B']);

        // Student tries to start again → 409 ALREADY_SUBMITTED.
        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start");

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'ALREADY_SUBMITTED');
    }

    public function test_submit_without_in_progress_attempt_returns_404(): void
    {
        Storage::fake('local');
        $this->setUpOrg();

        $data = $this->createReleasedAssessmentWithItem('multiple_choice', 'B');
        $assessmentId = $data['assessment']['id'];

        // Student tries to submit without starting first → no in_progress attempt → 404.
        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/submit", [
                'responses' => [1 => 'B']]);

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_auto_save_without_in_progress_attempt_returns_404(): void
    {
        Storage::fake('local');
        $this->setUpOrg();

        $data = $this->createReleasedAssessmentWithItem('multiple_choice', 'B');
        $assessmentId = $data['assessment']['id'];

        // Student tries to auto-save without starting first → no in_progress attempt → 404.
        $response = $this->actingAs($this->student, 'sanctum')
            ->put("/api/student/assessments/{$assessmentId}/auto-save", [
                'responses' => [1 => 'B']]);

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'NOT_FOUND');
    }
}
