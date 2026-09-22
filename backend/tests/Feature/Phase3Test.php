<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\Announcement;
use App\Models\Assessment;
use App\Models\AssessmentSubmission;
use App\Models\Assignment;
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

#[Group('phase3')]
class Phase3Test extends TestCase
{
    use RefreshDatabase;

    private ?User $teacher = null;

    private ?User $student = null;

    
    private ?Section $section = null;

    private ?Subject $subject = null;

    private ?Classroom $classroom = null;

    private ?CompetencyReference $competencyTag = null;

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

    /**
     * Build a full org hierarchy: School Year → Semester → Grade Level 7
     * → Section + Subject → Classroom + Student enrollment.
     */
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

        // Teacher scope derives from the classroom itself.
        $teacher = $this->actAsTeacher();

        // Student enrollment via classroom
        $student = $this->actAsStudent();
        $this->classroom = app(ClassroomService::class)->createClassroom($teacher->id, $this->subject->id, $this->section->id, '2026-2027', null);
        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $student->id,
            'joined_at' => now(),
        ]);
    }

    // ========================================================================
    // Announcements
    // ========================================================================

    public function test_teacher_can_create_announcement(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/announcements', [
                'subject_id' => $this->subject->id,
                'title' => 'Midterm Next Week',
                'body' => 'The midterm exam will be on Friday.',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.title', 'Midterm Next Week');
    }

    public function test_teacher_can_list_announcements(): void
    {
        $this->setUpOrg();

        $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/announcements', [
                'subject_id' => $this->subject->id,
                'title' => 'Test Announcement',
                'body' => 'Body text',
            ]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get('/api/teacher/announcements');

        $response->assertOk();
        $response->assertJsonPath('data.0.title', 'Test Announcement');
    }

    public function test_teacher_cannot_create_announcement_for_unassigned_section(): void
    {
        $this->setUpOrg();
        $otherTeacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $otherSubject = Subject::create(['grade_level_id' => $this->section->grade_level_id, 'name' => 'Science', 'code' => 'SCI7-X']);
        $otherClassroom = app(ClassroomService::class)->createClassroom(
            $otherTeacher->id,
            $otherSubject->id,
            $this->section->id,
            '2026-2027',
            null
        );

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$otherClassroom->id.'/announcements', [
                'title' => 'Test',
                'body' => 'Body',
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_student_can_list_announcements(): void
    {
        $this->setUpOrg();

        $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/announcements', [
                'subject_id' => $this->subject->id,
                'title' => 'Visible to Students',
                'body' => 'Hello students',
            ]);

        $response = $this->actingAs($this->student, 'sanctum')
            ->get('/api/student/announcements');

        $response->assertOk();
        $response->assertJsonPath('data.0.title', 'Visible to Students');
    }

    public function test_student_can_download_announcement_attachment(): void
    {
        Storage::fake('local');
        $this->setUpOrg();

        $announcement = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/announcements', [
                'subject_id' => $this->subject->id,
                'title' => 'With Attachment',
                'body' => 'See file',
                'attachments' => [
                    UploadedFile::fake()->create('note.pdf', 100, 'application/pdf'),
                ],
            ])
            ->json('data');

        $announcementModel = Announcement::find($announcement['id']);
        $attachmentId = $announcementModel->attachments->first()->id;

        $response = $this->actingAs($this->student, 'sanctum')
            ->get("/api/student/announcements/{$announcement['id']}/download/{$attachmentId}");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    // ========================================================================
    // Assignments — Teacher
    // ========================================================================

    public function test_teacher_can_create_assignment(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assignments', [
                'subject_id' => $this->subject->id,
                'title' => 'Homework 1',
                'description' => 'Complete problems 1-10',
                'due_date' => '2026-10-15 23:59:00',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.title', 'Homework 1');
    }

    public function test_teacher_can_list_assignments_with_submission_count(): void
    {
        $this->setUpOrg();

        $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assignments', [
                'subject_id' => $this->subject->id,
                'title' => 'HW 1',
                'description' => '',
                'due_date' => '2026-10-15 23:59:00',
            ]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get('/api/teacher/assignments');

        $response->assertOk();
        $response->assertJsonPath('data.0.title', 'HW 1');
    }

    public function test_teacher_can_update_assignment_metadata(): void
    {
        $this->setUpOrg();

        $assignment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assignments', [
                'subject_id' => $this->subject->id,
                'title' => 'Original Title',
                'description' => '',
                'due_date' => '2026-10-15 23:59:00',
            ])
            ->json('data');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->put("/api/teacher/assignments/{$assignment['id']}", [
                'title' => 'Updated Title',
                'description' => 'Updated description',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('assignments', [
            'id' => $assignment['id'],
            'title' => 'Updated Title',
        ]);
    }

    public function test_teacher_can_delete_draft_assignment(): void
    {
        $this->setUpOrg();

        $assignment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assignments', [
                'subject_id' => $this->subject->id,
                'title' => 'To Delete',
                'description' => '',
                'due_date' => '2026-10-15 23:59:00',
            ])
            ->json('data');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->delete("/api/teacher/assignments/{$assignment['id']}");

        $response->assertOk();
        $this->assertDatabaseMissing('assignments', ['id' => $assignment['id']]);
    }

    public function test_teacher_delete_with_submissions_requires_confirmation(): void
    {
        $this->setUpOrg();
        Storage::fake('local');

        $assignment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assignments', [
                'subject_id' => $this->subject->id,
                'title' => 'With Submissions',
                'description' => '',
                'due_date' => '2026-10-15 23:59:00',
            ])
            ->json('data');

        // Student submits
        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assignments/{$assignment['id']}/submit", [
                'files' => [
                    UploadedFile::fake()->create('submission.pdf', 100, 'application/pdf'),
                ],
            ]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->delete("/api/teacher/assignments/{$assignment['id']}");

        $response->assertOk();
        $response->assertJsonPath('data.confirmation_required', true);
        $response->assertJsonPath('data.submission_count', 1);
    }

    public function test_teacher_confirm_delete_hard_deletes(): void
    {
        $this->setUpOrg();
        Storage::fake('local');

        $assignment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assignments', [
                'subject_id' => $this->subject->id,
                'title' => 'Confirm Delete',
                'description' => '',
                'due_date' => '2026-10-15 23:59:00',
            ])
            ->json('data');

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assignments/{$assignment['id']}/submit", [
                'files' => [
                    UploadedFile::fake()->create('work.pdf', 100, 'application/pdf'),
                ],
            ]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assignments/{$assignment['id']}/confirm-delete");

        $response->assertOk();
        $this->assertDatabaseMissing('assignments', ['id' => $assignment['id']]);
        $this->assertDatabaseMissing('assignment_submissions', ['assignment_id' => $assignment['id']]);
    }

    public function test_teacher_can_list_submissions(): void
    {
        $this->setUpOrg();
        Storage::fake('local');

        $assignment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assignments', [
                'subject_id' => $this->subject->id,
                'title' => 'For Submissions',
                'description' => '',
                'due_date' => '2026-10-15 23:59:00',
            ])
            ->json('data');

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assignments/{$assignment['id']}/submit", [
                'files' => [
                    UploadedFile::fake()->create('answer.pdf', 100, 'application/pdf'),
                ],
            ]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get("/api/teacher/assignments/{$assignment['id']}/submissions");

        $response->assertOk();
        $response->assertJsonPath('data.0.student_name', $this->student->name);
    }

    public function test_teacher_can_provide_feedback(): void
    {
        $this->setUpOrg();
        Storage::fake('local');

        $assignment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assignments', [
                'subject_id' => $this->subject->id,
                'title' => 'Feedback Test',
                'description' => '',
                'due_date' => '2026-10-15 23:59:00',
            ])
            ->json('data');

        $submitResponse = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assignments/{$assignment['id']}/submit", [
                'files' => [
                    UploadedFile::fake()->create('ans.pdf', 100, 'application/pdf'),
                ],
            ]);

        $submissionId = $submitResponse->json('data.id');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/submissions/{$submissionId}/feedback", [
                'feedback' => 'Great work!',
            ]);

        $response->assertOk();

        $studentResponse = $this->actingAs($this->student, 'sanctum')
            ->get("/api/student/assignments/{$assignment['id']}/feedback");

        $studentResponse->assertOk();
        $studentResponse->assertJsonPath('data.feedback', 'Great work!');
    }

    // ========================================================================
    // Assignments — Student
    // ========================================================================

    public function test_student_can_list_assignments(): void
    {
        $this->setUpOrg();

        $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assignments', [
                'subject_id' => $this->subject->id,
                'title' => 'Available HW',
                'description' => 'Do it',
                'due_date' => '2026-10-15 23:59:00',
            ]);

        $response = $this->actingAs($this->student, 'sanctum')
            ->get('/api/student/assignments');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.title', 'Available HW');
        $response->assertJsonPath('data.0.is_submitted', false);
    }

    public function test_student_can_submit_assignment(): void
    {
        $this->setUpOrg();
        Storage::fake('local');

        $assignment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assignments', [
                'subject_id' => $this->subject->id,
                'title' => 'Submit Test',
                'description' => '',
                'due_date' => '2026-10-15 23:59:00',
            ])
            ->json('data');

        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assignments/{$assignment['id']}/submit", [
                'files' => [
                    UploadedFile::fake()->create('answer.pdf', 100, 'application/pdf'),
                ],
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.is_late', false);
        $response->assertJsonPath('data.file_count', 1);
    }

    public function test_student_submission_blocked_if_already_submitted(): void
    {
        $this->setUpOrg();
        Storage::fake('local');

        $assignment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assignments', [
                'subject_id' => $this->subject->id,
                'title' => 'Double Submit',
                'description' => '',
                'due_date' => '2026-10-15 23:59:00',
            ])
            ->json('data');

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assignments/{$assignment['id']}/submit", [
                'files' => [
                    UploadedFile::fake()->create('first.pdf', 100, 'application/pdf'),
                ],
            ]);

        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assignments/{$assignment['id']}/submit", [
                'files' => [
                    UploadedFile::fake()->create('second.pdf', 100, 'application/pdf'),
                ],
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'ALREADY_SUBMITTED');
    }

    public function test_student_not_enrolled_cannot_submit(): void
    {
        $this->setUpOrg();
        // Create a different section the student is NOT enrolled in.
        $year = SchoolYear::create(['name' => 'SY 2027']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
        ]);
        $gl = GradeLevel::create(['semester_id' => $semester->id, 'grade_level' => '8']);
        $otherSection = Section::create(['grade_level_id' => $gl->id, 'name' => '8B']);
$this->subject = Subject::create(['grade_level_id' => $gl->id, 'name' => 'Science', 'code' => 'SCI8']);
        $otherClassroom = app(ClassroomService::class)->createClassroom($this->teacher->id, $this->subject->id, $otherSection->id, '2026-2027', null);

        $otherAssignment = Assignment::create([
            'teacher_id' => $this->teacher->id,
            'classroom_id' => $otherClassroom->id,
            'subject_id' => $this->subject->id,
            'semester_id' => $semester->id,
            'title' => 'Other Section',
            'description' => '',
            'due_date' => '2026-10-15 23:59:00',
        ]);

        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assignments/{$otherAssignment->id}/submit", [
                'files' => [
                    UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
                ],
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'NOT_ENROLLED');
    }

    // ========================================================================
    // Assessments — Teacher CRUD
    // ========================================================================

    public function test_teacher_can_create_assessment(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Midterm Test',
                'description' => 'Chapters 1-3',
                'type' => 'Recorded',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.title', 'Midterm Test');
        $response->assertJsonPath('data.type', 'Recorded');
    }

    public function test_teacher_can_list_assessments(): void
    {
        $this->setUpOrg();

        $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Test Assessment',
                'description' => '',
                'type' => 'Recorded',
            ]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get('/api/teacher/assessments');

        $response->assertOk();
        $response->assertJsonPath('data.0.title', 'Test Assessment');
        $response->assertJsonPath('meta.current_page', 1);
    }

    public function test_teacher_can_view_assessment_detail_with_items_and_correct_answer(): void
    {
        $this->setUpOrg();

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Detail Test',
                'description' => '',
                'type' => 'Recorded',
            ])
            ->json('data');

        $item = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 2+2?',
                'max_points' => 10,
                'correct_answer' => 'B',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ])
            ->json('data');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get("/api/teacher/assessments/{$assessment['id']}");

        $response->assertOk();
        $response->assertJsonPath('data.items.0.correct_answer', 'B');
        $response->assertJsonPath('data.items.0.prompt', 'What is 2+2?');
        $response->assertJsonPath('data.items.0.item_type', 'multiple_choice');
    }

    public function test_teacher_can_update_assessment_title_description(): void
    {
        $this->setUpOrg();

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Original',
                'description' => 'Desc',
                'type' => 'Recorded',
            ])
            ->json('data');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->put("/api/teacher/assessments/{$assessment['id']}", [
                'title' => 'Updated',
                'description' => 'New Desc',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('assessments', [
            'id' => $assessment['id'],
            'title' => 'Updated',
        ]);
    }

    public function test_teacher_can_update_title_description_post_release(): void
    {
        $this->setUpOrg();

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Original',
                'description' => 'Desc',
                'type' => 'Recorded',
            ])
            ->json('data');

        // Add an item and release.
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

        // Post-release: title and description ARE editable (ARCH-002 FR-016).
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->put("/api/teacher/assessments/{$assessment['id']}", [
                'title' => 'Updated Post-Release',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('assessments', [
            'id' => $assessment['id'],
            'title' => 'Updated Post-Release',
        ]);
    }

    public function test_teacher_can_delete_draft_assessment(): void
    {
        $this->setUpOrg();

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Draft',
                'description' => '',
                'type' => 'Recorded',
            ])
            ->json('data');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->delete("/api/teacher/assessments/{$assessment['id']}");

        $response->assertOk();
        $this->assertDatabaseMissing('assessments', ['id' => $assessment['id']]);
    }

    // ========================================================================
    // Assessment Items
    // ========================================================================

    public function test_teacher_can_add_items_to_assessment(): void
    {
        $this->setUpOrg();

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'With Items',
                'description' => '',
                'type' => 'Recorded',
            ])
            ->json('data');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 2+2?',
                'max_points' => 10,
                'correct_answer' => 'B',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.item_type', 'multiple_choice');
    }

    public function test_teacher_can_update_item(): void
    {
        $this->setUpOrg();

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Update Item Test',
                'description' => '',
                'type' => 'Recorded',
            ])
            ->json('data');

        $item = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is the answer?',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ])
            ->json('data');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->put("/api/teacher/items/{$item['id']}", [
                'max_points' => 15,
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('assessment_items', [
            'id' => $item['id'],
            'max_points' => 15,
        ]);
    }

    public function test_teacher_can_delete_item(): void
    {
        $this->setUpOrg();

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Delete Item Test',
                'description' => '',
                'type' => 'Recorded',
            ])
            ->json('data');

        $item = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", [
                'item_type' => 'true_false',
                'prompt' => 'True or false: 2+2=4',
                'max_points' => 5,
                'correct_answer' => 'true',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ])
            ->json('data');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->delete("/api/teacher/items/{$item['id']}");

        $response->assertOk();
        $this->assertDatabaseMissing('assessment_items', ['id' => $item['id']]);
    }

    // ========================================================================
    // Assessment Release
    // ========================================================================

    public function test_teacher_can_release_assessment_with_items(): void
    {
        $this->setUpOrg();

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Release Test',
                'description' => '',
                'type' => 'Recorded',
            ])
            ->json('data');

        // Add an item first (release blocked if zero items).
        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is the answer?',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/release");

        $response->assertOk();
        $this->assertDatabaseHas('assessments', [
            'id' => $assessment['id'],
            'status' => 'released',
        ]);
    }

    public function test_teacher_cannot_release_assessment_without_items(): void
    {
        $this->setUpOrg();

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Empty Assessment',
                'description' => '',
                'type' => 'Recorded',
            ])
            ->json('data');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/release");

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'ASSESSMENT_HAS_NO_ITEMS');
    }

    public function test_teacher_cannot_edit_released_assessment_items(): void
    {
        $this->setUpOrg();

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Cannot Edit',
                'description' => '',
                'type' => 'Recorded',
            ])
            ->json('data');

        $item = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is the answer?',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ])
            ->json('data');

        // Release the assessment.
        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/release");

        // Try to update the item — should fail.
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->put("/api/teacher/items/{$item['id']}", [
                'max_points' => 20,
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'POST_RELEASE_STRUCTURAL_EDIT_BLOCKED');
    }

    // ========================================================================
    // Assessments — Student
    // ========================================================================

    public function test_student_can_list_available_assessments(): void
    {
        $this->setUpOrg();

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Available to Students',
                'description' => '',
                'type' => 'Recorded',
            ])
            ->json('data');

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is the answer?',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ]);

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/release");

        $response = $this->actingAs($this->student, 'sanctum')
            ->get('/api/student/assessments');

        $response->assertOk();
        $response->assertJsonPath('data.0.title', 'Available to Students');
        $response->assertJsonPath('data.0.status', 'available');
    }

    public function test_student_can_start_and_submit_objective_assessment(): void
    {
        $this->setUpOrg();

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Objective Only',
                'description' => '',
                'type' => 'Recorded',
            ])
            ->json('data');

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 2+2?',
                'max_points' => 10,
                'correct_answer' => 'B',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ]);

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/release");

        // Student starts the assessment.
        $startResponse = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/start");

        $startResponse->assertOk();
        $attemptId = $startResponse->json('data.attempt_id');
        $this->assertNotEmpty($attemptId);
        $startResponse->assertJsonPath('data.assessment_id', $assessment['id']);
        $startResponse->assertJsonPath('data.title', 'Objective Only');
        $this->assertNotEmpty($startResponse->json('data.items'));

        // Student submits with correct answer.
        $submitResponse = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/submit", [
                'responses' => [1 => 'B'],
            ]);

        $submitResponse->assertOk();
        $submitResponse->assertJsonPath('data.status', 'scored');
        $this->assertDatabaseHas('assessment_submissions', [
            'assessment_id' => $assessment['id'],
            'student_id' => $this->student->id,
            'status' => 'scored',
        ]);
    }

    public function test_student_submission_with_essay_goes_to_pending_grading(): void
    {
        $this->setUpOrg();

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'With Essay',
                'description' => '',
                'type' => 'Recorded',
            ])
            ->json('data');

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", [
                'item_type' => 'essay',
                'prompt' => 'Explain your reasoning.',
                'max_points' => 15,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ]);

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/release");

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/start");

        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/submit", [
                'responses' => [1 => 'This is my essay answer.'],
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('assessment_submissions', [
            'assessment_id' => $assessment['id'],
            'student_id' => $this->student->id,
            'status' => 'pending_grading',
        ]);
    }

    public function test_release_results_blocked_when_pending_grading(): void
    {
        $this->setUpOrg();

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Pending Grading',
                'description' => '',
                'type' => 'Recorded',
            ])
            ->json('data');

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", [
                'item_type' => 'essay',
                'prompt' => 'Explain your reasoning.',
                'max_points' => 15,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ]);

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/release");

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/start");

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/submit", [
                'responses' => [1 => 'Essay response.'],
            ]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/release-results");

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'PENDING_GRADING_BLOCK');
    }

    public function test_student_cannot_view_results_before_release(): void
    {
        $this->setUpOrg();

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Unreleased Results',
                'description' => '',
                'type' => 'Recorded',
            ])
            ->json('data');

        // Add objective item.
        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is the answer?',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ]);

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/release");

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/start");

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/submit", [
                'responses' => [1 => 'A'],
            ]);

        // Results not released yet.
        $response = $this->actingAs($this->student, 'sanctum')
            ->get("/api/student/assessments/{$assessment['id']}/results");

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'RESULTS_NOT_RELEASED');
    }

    public function test_student_can_view_results_after_release(): void
    {
        $this->setUpOrg();

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Released Results',
                'description' => '',
                'type' => 'Recorded',
            ])
            ->json('data');

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is the answer?',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ]);

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/release");

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/start");

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/submit", [
                'responses' => [1 => 'A'],
            ]);

        // Teacher releases results.
        $releaseResponse = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/release-results");

        $releaseResponse->assertOk();
        $releaseResponse->assertJsonPath('data.message', 'Results released.');
        // ARCH-005 block 4.5: ai_explanations_triggered semantics are deleted entirely — the
        // HTTP payload must not carry the key anywhere.
        $this->assertArrayNotHasKey('ai_explanations_triggered', $releaseResponse->json());
        $this->assertArrayNotHasKey('ai_explanations_triggered', $releaseResponse->json('data'));
        // results_released_at mirrors the persisted submission timestamp.
        $persistedAt = AssessmentSubmission::query()
            ->where('assessment_id', $assessment['id'])
            ->max('results_released_at');
        $this->assertTrue(
            \Illuminate\Support\Carbon::parse($releaseResponse->json('data.results_released_at'))
                ->equalTo(\Illuminate\Support\Carbon::parse($persistedAt))
        );

        $response = $this->actingAs($this->student, 'sanctum')
            ->get("/api/student/assessments/{$assessment['id']}/results");

        $response->assertOk();
        $response->assertJsonPath('data.title', 'Released Results');
    }

    public function test_anonymous_user_cannot_access_teacher_routes(): void
    {
        $response = $this->get('/api/teacher/announcements');
        $response->assertStatus(401);
    }

    public function test_teacher_cannot_access_student_routes(): void
    {
        $this->setUpOrg();
        $this->actAsTeacher();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get('/api/student/assessments');

        $response->assertStatus(403);
    }

    public function test_student_cannot_access_teacher_routes(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $response = $this->actingAs($this->student, 'sanctum')
            ->get('/api/teacher/assessments');

        $response->assertStatus(403);
    }

    public function test_teacher_can_score_subjective_items(): void
    {
        $this->setUpOrg();

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Scoring Test',
                'description' => '',
                'type' => 'Recorded',
            ])
            ->json('data');

        $item = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", [
                'item_type' => 'essay',
                'prompt' => 'Write about the topic.',
                'max_points' => 10,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ])
            ->json('data');

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/release");

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/start");

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/submit", [
                'responses' => [(int) $item['id'] => 'Essay.'],
            ]);

        // Find the pending submission.
        $submission = AssessmentSubmission::where('assessment_id', $assessment['id'])
            ->where('status', 'pending_grading')
            ->first();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/submissions/{$submission->id}/score", [
                'scores' => [
                    (string) $item['id'] => 8,
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'scored');
        $response->assertJsonPath('data.mastery_results.status', 'computed');
        $this->assertDatabaseHas('assessment_responses', [
            'submission_id' => $submission->id,
            'item_id' => $item['id'],
            'earned_points' => 8,
        ]);
        $this->assertDatabaseHas('assessment_submissions', [
            'id' => $submission->id,
            'status' => 'scored',
        ]);
    }
}
