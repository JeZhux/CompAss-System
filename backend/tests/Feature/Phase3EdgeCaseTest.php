<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Exceptions\BusinessRuleConflictException;
use App\Models\Announcement;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentAutoSave;
use App\Models\AssessmentItem;
use App\Models\AssessmentResponse;
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
use App\Services\AnnouncementService;
use App\Services\AssessmentService;
use App\Services\AssignmentService;
use App\Services\ClassroomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

#[Group('phase3-edge')]
class Phase3EdgeCaseTest extends TestCase
{
    use RefreshDatabase;

    private ?User $teacher = null;

    private ?User $student = null;

    
    private ?Subject $subject = null;

    private ?Section $section = null;

    private ?Classroom $classroom = null;

    private ?CompetencyReference $competencyTag = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = null;
        $this->student = null;
        $this->section = null;
        $this->classroom = null;
        $this->competencyTag = null;
    }

    private function firstItemId(int $assessmentId): int
    {
        return AssessmentItem::where('assessment_id', $assessmentId)->value('id');
    }

    private function actAsTeacher(): User
    {
        if (! $this->teacher) {
            $this->teacher = User::factory()->create([
                'role' => 'Teacher',
                'must_change_password' => false,
            ]);
        }

        Sanctum::actingAs($this->teacher);

        return $this->teacher;
    }

    private function actAsStudent(): User
    {
        if (! $this->student) {
            $this->student = User::factory()->create([
                'role' => 'Student',
                'must_change_password' => false,
            ]);
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
            'end_date' => '2026-12-31',
        ]);
        $gradeLevel = GradeLevel::create([
            'semester_id' => $semester->id,
            'grade_level' => '7',
        ]);
        $this->section = Section::create([
            'grade_level_id' => $gradeLevel->id,
            'name' => '7A',
        ]);
$this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7']);
                $this->competencyTag = CompetencyReference::create(['semester' => '1', 'code' => 'M7-NUM-001',
            'descriptor' => 'Solve multi-step rational numbers',
            'subject_id' => $this->subject->id,
            'grade_level' => '7',
        ]);

        $teacher = $this->actAsTeacher();

        $student = $this->actAsStudent();
        $this->classroom = app(ClassroomService::class)->createClassroom($teacher->id, $this->subject->id, $this->section->id, '2026-2027', null);
        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $student->id,
            'joined_at' => now(),
        ]);
    }

    private function createAssignment(array $overrides = []): array
    {
        $defaults = [
            'title' => 'Test Assignment',
            'description' => '',
            'due_date' => '2026-12-31 23:59:00',
        ];

        return $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assignments', array_merge($defaults, $overrides))
            ->json('data');
    }

    private function createAndReleaseAssessment(array $overrides = [], array $items = []): array
    {
        $defaults = [
            'title' => 'Test Assessment',
            'description' => '',
            'type' => 'Recorded',
        ];

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', array_merge($defaults, $overrides))
            ->json('data');

        foreach ($items as $itemData) {
            $this->actingAs($this->teacher, 'sanctum')
                ->post("/api/teacher/assessments/{$assessment['id']}/items", $itemData)
                ->json('data');
        }

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/release");

        return $assessment;
    }

    // ========================================================================
    // Assignment edge cases
    // ========================================================================

    public function test_late_submission_marks_is_late_and_status_late(): void
    {
        Storage::fake('local');
        $this->setUpOrg();

        $assignment = $this->createAssignment([
            'title' => 'Past-Due Assignment',
            'due_date' => '2020-01-01 00:00:00',
        ]);

        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assignments/{$assignment['id']}/submit", [
                'files' => [
                    UploadedFile::fake()->create('answer.pdf', 100, 'application/pdf'),
                ],
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.is_late', true);
        $this->assertDatabaseHas('assignment_submissions', [
            'assignment_id' => $assignment['id'],
            'student_id' => $this->student->id,
            'status' => 'late',
        ]);
    }

    public function test_submission_too_many_files_service_level(): void
    {
        Storage::fake('local');
        $this->setUpOrg();

        $assignment = $this->createAssignment();

        $files = array_map(
            fn ($i) => UploadedFile::fake()->create("file{$i}.pdf", 100, 'application/pdf'),
            range(1, 6)
        );

        $service = app(AssignmentService::class);

        try {
            $service->submitAssignment($this->student->id, $assignment['id'], $files);
            $this->fail('Expected BusinessRuleConflictException was not thrown.');
        } catch (BusinessRuleConflictException $e) {
            $this->assertEquals('TOO_MANY_FILES', $e->errorCode);
            $this->assertDatabaseMissing('assignment_submissions', [
                'assignment_id' => $assignment['id'],
                'student_id' => $this->student->id,
            ]);
        }
    }

    public function test_submission_file_too_large_service_level(): void
    {
        Storage::fake('local');
        $this->setUpOrg();

        $assignment = $this->createAssignment();

        $files = [
            UploadedFile::fake()->create('huge.pdf', 16000, 'application/pdf'),
        ];

        $service = app(AssignmentService::class);

        try {
            $service->submitAssignment($this->student->id, $assignment['id'], $files);
            $this->fail('Expected BusinessRuleConflictException was not thrown.');
        } catch (BusinessRuleConflictException $e) {
            $this->assertEquals('FILE_TOO_LARGE', $e->errorCode);
        }
    }

    // ========================================================================
    // Announcement attachment edge cases
    // ========================================================================

    public function test_announcement_too_many_attachments(): void
    {
        Storage::fake('local');
        $this->setUpOrg();

        $attachments = array_map(
            fn ($i) => UploadedFile::fake()->create("att{$i}.pdf", 100, 'application/pdf'),
            range(1, 6)
        );

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/announcements', [
                'title' => 'Too Many Attachments',
                'body' => 'Body text',
                'attachments' => $attachments,
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'TOO_MANY_ATTACHMENTS');
        $this->assertDatabaseMissing('announcements', [
            'title' => 'Too Many Attachments',
        ]);
    }

    public function test_announcement_attachment_too_large_service_level(): void
    {
        $this->setUpOrg();

        $attachment = UploadedFile::fake()->create('huge.pdf', 16000, 'application/pdf');

        $service = app(AnnouncementService::class);

        try {
            $service->createAnnouncementInClassroom(
                $this->teacher->id,
                $this->classroom->id,
                'Large Attachment',
                'Body text',
                [$attachment]
            );
            $this->fail('Expected BusinessRuleConflictException was not thrown.');
        } catch (BusinessRuleConflictException $e) {
            $this->assertEquals('FILE_TOO_LARGE', $e->errorCode);
        }
    }

    public function test_announcement_update_with_attachments_replaces_existing(): void
    {
        Storage::fake('local');
        $this->setUpOrg();

        $oldAttachments = [
            UploadedFile::fake()->create('old1.pdf', 100, 'application/pdf'),
            UploadedFile::fake()->create('old2.pdf', 100, 'application/pdf'),
        ];

        $announcement = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/announcements', [
                'subject_id' => $this->subject->id,
                'title' => 'Original',
                'body' => 'Original body',
                'attachments' => $oldAttachments,
            ])->json('data');

        $announcementModel = Announcement::find($announcement['id']);
        $this->assertCount(2, $announcementModel->attachments);
        $oldAttachmentIds = $announcementModel->attachments->pluck('id')->toArray();

        $newAttachments = [
            UploadedFile::fake()->create('new1.pdf', 100, 'application/pdf'),
            UploadedFile::fake()->create('new2.pdf', 100, 'application/pdf'),
            UploadedFile::fake()->create('new3.pdf', 100, 'application/pdf'),
        ];

        $this->actingAs($this->teacher, 'sanctum')
            ->put("/api/teacher/announcements/{$announcement['id']}", [
                'title' => 'Updated',
                'body' => 'Updated body',
                'attachments' => $newAttachments,
            ])->assertOk();

        $announcementModel->refresh('attachments');
        $this->assertCount(3, $announcementModel->attachments);

        $newAttachmentIds = $announcementModel->attachments->pluck('id')->toArray();
        foreach ($oldAttachmentIds as $oldId) {
            $this->assertNotContains($oldId, $newAttachmentIds);
        }
    }

    public function test_announcement_update_without_attachments_keeps_existing(): void
    {
        Storage::fake('local');
        $this->setUpOrg();

        $announcement = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/announcements', [
                'subject_id' => $this->subject->id,
                'title' => 'Original',
                'body' => 'Original body',
                'attachments' => [
                    UploadedFile::fake()->create('old.pdf', 100, 'application/pdf'),
                ],
            ])->json('data');

        $announcementModel = Announcement::find($announcement['id']);
        $originalAttachmentId = $announcementModel->attachments->first()->id;
        $this->assertCount(1, $announcementModel->attachments);

        $this->actingAs($this->teacher, 'sanctum')
            ->put("/api/teacher/announcements/{$announcement['id']}", [
                'title' => 'Updated Title Only',
            ])->assertOk();

        $announcementModel->refresh('attachments');
        $this->assertCount(1, $announcementModel->attachments);
        $this->assertEquals($originalAttachmentId, $announcementModel->attachments->first()->id);
    }

    // ========================================================================
    // Assessment item attachment edge cases
    // ========================================================================

    public function test_assessment_item_too_many_attachments(): void
    {
        Storage::fake('local');
        $this->setUpOrg();

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Too Many Attachments',
                'description' => '',
                'type' => 'Recorded',
            ])->json('data');

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", [
                'item_type' => 'multiple_choice',
                'prompt' => 'First question here',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ]);

        $attachments = array_map(
            fn ($i) => UploadedFile::fake()->create("att{$i}.pdf", 100, 'application/pdf'),
            range(1, 6)
        );

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", [
                'item_type' => 'multiple_choice',
                'prompt' => 'Second question here',
                'max_points' => 10,
                'correct_answer' => 'B',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 2,
                'attachments' => $attachments,
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'TOO_MANY_ATTACHMENTS');
    }

    public function test_assessment_item_attachment_too_large_service_level(): void
    {
        $this->setUpOrg();

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Item Large Att Test',
                'description' => '',
                'type' => 'Recorded',
            ])->json('data');

        $attachment = UploadedFile::fake()->create('huge.pdf', 16000, 'application/pdf');

        $service = app(AssessmentService::class);

        try {
            $service->addItem(
                $this->teacher->id,
                $assessment['id'],
                'multiple_choice',
                'What is 2+2?',
                10,
                'B',
                $this->competencyTag->id,
                [$attachment]
            );
            $this->fail('Expected BusinessRuleConflictException was not thrown.');
        } catch (BusinessRuleConflictException $e) {
            $this->assertEquals('FILE_TOO_LARGE', $e->errorCode);
        }
    }

    // ========================================================================
    // Scoring edge cases
    // ========================================================================

    public function test_partial_subjective_scoring_stays_pending_grading(): void
    {
        $this->setUpOrg();

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Partial Scoring',
                'description' => '',
                'type' => 'Recorded',
            ])->json('data');

        $item1 = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", [
                'item_type' => 'essay',
                'prompt' => 'Essay 1',
                'max_points' => 10,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ])->json('data');

        $item2 = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", [
                'item_type' => 'essay',
                'prompt' => 'Essay 2',
                'max_points' => 10,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 2,
            ])->json('data');

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/release");

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/start");

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/submit", [
                'responses' => [
                    $item1['id'] => 'Answer one.',
                    $item2['id'] => 'Answer two.',
                ],
            ]);

        $submission = AssessmentSubmission::where('assessment_id', $assessment['id'])
            ->where('status', 'pending_grading')
            ->first();

        $this->assertNotNull($submission);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/submissions/{$submission->id}/score", [
                'scores' => [
                    (string) $item1['id'] => 8,
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'pending_grading');
        $this->assertDatabaseHas('assessment_submissions', [
            'id' => $submission->id,
            'status' => 'pending_grading',
        ]);
    }

    public function test_case_insensitive_objective_matching(): void
    {
        $this->setUpOrg();

        $assessment = $this->createAndReleaseAssessment([], [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 2+2?',
                'max_points' => 10,
                'correct_answer' => 'B',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ],
        ]);

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/start");

        $itemId = $this->firstItemId($assessment['id']);

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/submit", [
                'responses' => [$itemId => 'b'],
            ]);

        $response = AssessmentResponse::query()
            ->whereHas('item', fn ($q) => $q->where('assessment_id', $assessment['id']))
            ->first();

        $this->assertNotNull($response);
        $this->assertEquals(10.0, (float) $response->earned_points);
        $this->assertTrue($response->is_auto_scored);
    }

    public function test_unanswered_objective_item_earned_points_zero(): void
    {
        $this->setUpOrg();

        $assessment = $this->createAndReleaseAssessment([], [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 4+4?',
                'max_points' => 10,
                'correct_answer' => 'C',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ],
        ]);

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/start");

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/submit", [
                'responses' => [],
            ]);

        $response = AssessmentResponse::query()
            ->whereHas('item', fn ($q) => $q->where('assessment_id', $assessment['id']))
            ->first();

        $this->assertNotNull($response);
        $this->assertEquals(0.0, (float) $response->earned_points);
        $this->assertNull($response->response_text);
        $this->assertTrue($response->is_auto_scored);
    }

    public function test_true_false_auto_scoring(): void
    {
        $this->setUpOrg();

        $assessment = $this->createAndReleaseAssessment([], [
            [
                'item_type' => 'true_false',
                'prompt' => 'True or false: 2+2=4',
                'max_points' => 5,
                'correct_answer' => 'true',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ],
        ]);

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/start");

        $itemId = $this->firstItemId($assessment['id']);

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/submit", [
                'responses' => [$itemId => 'true'],
            ]);

        $response = AssessmentResponse::query()
            ->whereHas('item', fn ($q) => $q->where('assessment_id', $assessment['id']))
            ->first();

        $this->assertNotNull($response);
        $this->assertEquals(5.0, (float) $response->earned_points);
        $this->assertTrue($response->is_auto_scored);
    }

    // ========================================================================
    // Auto-save and response history edge cases
    // ========================================================================

    public function test_restart_while_active_attempt_rejected(): void
    {
        $this->setUpOrg();

        $assessment = $this->createAndReleaseAssessment([], [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 2+2?',
                'max_points' => 10,
                'correct_answer' => 'B',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ],
        ]);

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/start");

        $this->actingAs($this->student, 'sanctum')
            ->put("/api/student/assessments/{$assessment['id']}/auto-save", [
                'responses' => [1 => 'B'],
            ]);

        // ARCH-002 FR-017: a restart while the first attempt is in_progress is
        // rejected — the client resumes by holding the attempt_id from the
        // first start instead of creating an orphan attempt.
        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/start");

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'STUDENT_HAS_ACTIVE_ATTEMPT');

        $this->assertSame(1, AssessmentAttempt::query()
            ->where('assessment_id', $assessment['id'])
            ->where('student_id', $this->student->id)
            ->count());
    }

    public function test_response_history_multi_patch_array_grows(): void
    {
        $this->setUpOrg();

        $assessment = $this->createAndReleaseAssessment([], [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ],
        ]);

        $startResponse = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/start");
        $attemptId = $startResponse->json('data.attempt_id');

        $itemId = $this->firstItemId($assessment['id']);

        $this->actingAs($this->student, 'sanctum')
            ->patch("/api/student/assessments/{$assessment['id']}/attempts/{$attemptId}/response", [
                'questionId' => $itemId,
                'response' => 'A',
            ])->assertOk();

        $this->actingAs($this->student, 'sanctum')
            ->patch("/api/student/assessments/{$assessment['id']}/attempts/{$attemptId}/response", [
                'questionId' => $itemId,
                'response' => 'B',
            ])->assertOk();

        $this->actingAs($this->student, 'sanctum')
            ->patch("/api/student/assessments/{$assessment['id']}/attempts/{$attemptId}/response", [
                'questionId' => $itemId,
                'response' => 'C',
            ])->assertOk();

        $attempt = AssessmentAttempt::find($attemptId);
        $history = $attempt->response_history;

        $this->assertIsArray($history);
        $this->assertCount(3, $history);
        $this->assertEquals('A', $history[0]['new_value']);
        $this->assertEquals('B', $history[1]['new_value']);
        $this->assertEquals('C', $history[2]['new_value']);
        $this->assertEquals(null, $history[0]['priorValue']);
        $this->assertEquals('A', $history[1]['priorValue']);
        $this->assertEquals('B', $history[2]['priorValue']);
    }

    public function test_auto_save_upsert_second_overwrites_first(): void
    {
        $this->setUpOrg();

        $assessment = $this->createAndReleaseAssessment([], [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ],
        ]);

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/start");

        $this->actingAs($this->student, 'sanctum')
            ->put("/api/student/assessments/{$assessment['id']}/auto-save", [
                'responses' => [1 => 'A'],
            ])->assertOk();

        $this->actingAs($this->student, 'sanctum')
            ->put("/api/student/assessments/{$assessment['id']}/auto-save", [
                'responses' => [1 => 'B'],
            ])->assertOk();

        $autoSave = AssessmentAutoSave::query()
            ->where('student_id', $this->student->id)
            ->where('assessment_id', $assessment['id'])
            ->first();

        $this->assertNotNull($autoSave);
        $this->assertIsArray($autoSave->responses);
        $this->assertEquals('B', $autoSave->responses['1'] ?? $autoSave->responses[1] ?? null);
    }

    // ========================================================================
    // Assessment type / availability edge cases
    // ========================================================================

    public function test_unrecorded_assessment_submit_creates_mastery_record(): void
    {
        $this->setUpOrg();

        $assessment = $this->createAndReleaseAssessment(['type' => 'Unrecorded'], [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 2+2?',
                'max_points' => 10,
                'correct_answer' => 'B',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ],
        ]);

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/start");

        $itemId = $this->firstItemId($assessment['id']);

        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/submit", [
                'responses' => [$itemId => 'B'],
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'scored');
        $response->assertJsonPath('data.mastery_results.status', 'computed');

        $this->assertDatabaseHas('assessment_submissions', [
            'assessment_id' => $assessment['id'],
            'student_id' => $this->student->id,
            'status' => 'scored',
        ]);
    }

    public function test_availability_not_yet_open_cannot_start(): void
    {
        $this->setUpOrg();

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Future Start',
                'description' => '',
                'type' => 'Recorded',
                'availability_starts_at' => now()->addDay()->toDateTimeString(),
            ])->json('data');

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ]);

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/release");

        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/start");

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'ASSESSMENT_NOT_AVAILABLE');
    }

    public function test_availability_window_closed_cannot_start(): void
    {
        $this->setUpOrg();

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Closed Window',
                'description' => '',
                'type' => 'Recorded',
                'availability_starts_at' => now()->subDays(2)->toDateTimeString(),
                'availability_ends_at' => now()->subHour()->toDateTimeString(),
            ])->json('data');

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ]);

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/release");

        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/start");

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'ASSESSMENT_NOT_AVAILABLE');
    }
}
