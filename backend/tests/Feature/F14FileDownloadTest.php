<?php

namespace Tests\Feature;

use App\Models\AssessmentItem;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Classroom;
use App\Models\ClassroomEnrollment;
use App\Models\CompetencyReference;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use App\Services\ClassroomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * F-14: each stored file family has an authenticated, ownership-checked
 * download path serving exact stored bytes with a safe disposition.
 * Red-teamed: YES (IDOR / path-traversal / header-injection cases below).
 */
#[Group('f-14')]
class F14FileDownloadTest extends TestCase
{
    use RefreshDatabase;

    private ?Subject $subject = null;

    private function setUpOrg(): array
    {
        $year = SchoolYear::create(['name' => 'SY F14']);
        $semester = \App\Models\Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
        ]);
        $gradeLevel = GradeLevel::create(['semester_id' => $semester->id, 'grade_level' => '7']);
        $section = Section::create(['grade_level_id' => $gradeLevel->id, 'name' => '7A-F14']);
$this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-F14']);
        $competency = CompetencyReference::create(['semester' => '1', 'code' => 'M7-NUM-F14',
            'descriptor' => 'Solve multi-step rational numbers',
            'subject_id' => $this->subject->id,
            'grade_level' => '7',
        ]);
        $teacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $student = User::factory()->create(['role' => 'Student', 'must_change_password' => false]);
        $classroom = app(ClassroomService::class)->createClassroom($teacher->id, $this->subject->id, $section->id, '2026-2027', null);
        ClassroomEnrollment::create(['classroom_id' => $classroom->id, 'student_id' => $student->id, 'joined_at' => now()]);

        return [$teacher, $student, $section, $classroom, $competency];
    }

    private function createAssessmentWithItem(User $teacher, Classroom $classroom, CompetencyReference $competency, bool $release = false): array
    {
        $assessment = $this->actingAs($teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$classroom->id.'/assessments', [
                'title' => 'F14 assessment',
                'description' => '',
                'type' => 'Recorded',
            ])->assertCreated()->json('data');

        $item = $this->actingAs($teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 2+2?',
                'max_points' => 10,
                'correct_answer' => 'B',
                'competency_tag_id' => $competency->id,
                'sort_order' => 1,
                'attachments' => [UploadedFile::fake()->create('item.pdf', 10, 'application/pdf')],
            ])->assertCreated()->json('data');

        if ($release) {
            $this->actingAs($teacher, 'sanctum')
                ->post("/api/teacher/assessments/{$assessment['id']}/release")
                ->assertOk();
        }

        return [$assessment, $item];
    }

    public function test_teacher_item_attachment_download_byte_equal(): void
    {
        [$teacher, , , $classroom, $competency] = $this->setUpOrg();
        Storage::fake('local');

        [, $item] = $this->createAssessmentWithItem($teacher, $classroom, $competency);
        $model = AssessmentItem::find($item['id']);
        $attachment = $model->attachments->first();
        $expected = Storage::disk('local')->get($attachment->filename);

        $dl = $this->actingAs($teacher, 'sanctum')
            ->get("/api/teacher/items/{$model->id}/attachments/{$attachment->id}/download");
        $dl->assertOk();
        $this->assertStringContainsString('attachment', (string) $dl->headers->get('Content-Disposition'));
        $this->assertSame($expected, $dl->streamedContent());

        // Unauthenticated -> 401.
        Auth::guard('sanctum')->forgetUser();
        $this->get("/api/teacher/items/{$model->id}/attachments/{$attachment->id}/download")->assertUnauthorized();

        // Other teacher (not owner) -> 404.
        $other = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $this->actingAs($other, 'sanctum')
            ->get("/api/teacher/items/{$model->id}/attachments/{$attachment->id}/download")
            ->assertNotFound();

        // Unknown attachment -> 404.
        $this->actingAs($teacher, 'sanctum')
            ->get("/api/teacher/items/{$model->id}/attachments/999999/download")
            ->assertNotFound();
    }

    public function test_student_item_attachment_download_byte_equal(): void
    {
        [$teacher, $student, , $classroom, $competency] = $this->setUpOrg();
        Storage::fake('local');

        [, $item] = $this->createAssessmentWithItem($teacher, $classroom, $competency, true);
        $model = AssessmentItem::find($item['id']);
        $attachment = $model->attachments->first();
        $expected = Storage::disk('local')->get($attachment->filename);

        $dl = $this->actingAs($student, 'sanctum')
            ->get("/api/student/items/{$model->id}/attachments/{$attachment->id}/download");
        $dl->assertOk();
        $this->assertStringContainsString('attachment', (string) $dl->headers->get('Content-Disposition'));
        $this->assertSame($expected, $dl->streamedContent());

        // Unenrolled student -> 403.
        $outsider = User::factory()->create(['role' => 'Student', 'must_change_password' => false]);
        $this->actingAs($outsider, 'sanctum')
            ->get("/api/student/items/{$model->id}/attachments/{$attachment->id}/download")
            ->assertForbidden();

        // Student cannot reach a draft assessment's bytes -> 404.
        [$draft] = $this->createAssessmentWithItem($teacher, $classroom, $competency, false);
        $draftItem = AssessmentItem::where('assessment_id', $draft['id'])->first();
        $draftAttachment = $draftItem->attachments->first();
        $this->actingAs($student, 'sanctum')
            ->get("/api/student/items/{$draftItem->id}/attachments/{$draftAttachment->id}/download")
            ->assertNotFound();
    }

    public function test_assignment_attachment_download_teacher_and_student(): void
    {
        [$teacher, $student, , $classroom] = $this->setUpOrg();
        Storage::fake('local');

        $assignment = $this->actingAs($teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$classroom->id.'/assignments', [
                'title' => 'F14 HW',
                'description' => '',
                'due_date' => '2026-10-15 23:59:00',
                'attachments' => [UploadedFile::fake()->create('prompt.pdf', 10, 'application/pdf')],
            ])->assertCreated()->json('data');

        $model = Assignment::find($assignment['id']);
        $attachment = $model->attachments->first();
        $expected = Storage::disk('local')->get($attachment->filename);

        $teacherDl = $this->actingAs($teacher, 'sanctum')
            ->get("/api/teacher/assignments/{$model->id}/attachments/{$attachment->id}/download");
        $teacherDl->assertOk();
        $this->assertStringContainsString('attachment', (string) $teacherDl->headers->get('Content-Disposition'));
        $this->assertSame($expected, $teacherDl->streamedContent());

        $studentDl = $this->actingAs($student, 'sanctum')
            ->get("/api/student/assignments/{$model->id}/attachments/{$attachment->id}/download");
        $studentDl->assertOk();
        $this->assertSame($expected, $studentDl->streamedContent());

        // Unauthenticated -> 401.
        Auth::guard('sanctum')->forgetUser();
        $this->get("/api/student/assignments/{$model->id}/attachments/{$attachment->id}/download")->assertUnauthorized();

        // Unenrolled student -> 403.
        $outsider = User::factory()->create(['role' => 'Student', 'must_change_password' => false]);
        $this->actingAs($outsider, 'sanctum')
            ->get("/api/student/assignments/{$model->id}/attachments/{$attachment->id}/download")
            ->assertForbidden();

        // Other teacher -> 404.
        $other = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $this->actingAs($other, 'sanctum')
            ->get("/api/teacher/assignments/{$model->id}/attachments/{$attachment->id}/download")
            ->assertNotFound();
    }

    public function test_submission_file_download_teacher_and_owner_student(): void
    {
        [$teacher, $student, , $classroom] = $this->setUpOrg();
        Storage::fake('local');

        $assignment = $this->actingAs($teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$classroom->id.'/assignments', [
                'title' => 'F14 submit',
                'description' => '',
                'due_date' => '2026-10-15 23:59:00',
            ])->assertCreated()->json('data');

        $submission = $this->actingAs($student, 'sanctum')
            ->post("/api/student/assignments/{$assignment['id']}/submit", [
                'files' => [UploadedFile::fake()->create('work.pdf', 10, 'application/pdf')],
            ])->assertCreated()->json('data');

        $model = AssignmentSubmission::find($submission['id']);
        $file = $model->files->first();
        $expected = Storage::disk('local')->get($file->filename);

        $teacherDl = $this->actingAs($teacher, 'sanctum')
            ->get("/api/teacher/submissions/{$model->id}/files/{$file->id}/download");
        $teacherDl->assertOk();
        $this->assertStringContainsString('attachment', (string) $teacherDl->headers->get('Content-Disposition'));
        $this->assertSame($expected, $teacherDl->streamedContent());

        $studentDl = $this->actingAs($student, 'sanctum')
            ->get("/api/student/submissions/{$model->id}/files/{$file->id}/download");
        $studentDl->assertOk();
        $this->assertSame($expected, $studentDl->streamedContent());

        // Other student cannot read -> 404 (no existence leak).
        $otherStudent = User::factory()->create(['role' => 'Student', 'must_change_password' => false]);
        ClassroomEnrollment::create(['classroom_id' => $classroom->id, 'student_id' => $otherStudent->id, 'joined_at' => now()]);
        $this->actingAs($otherStudent, 'sanctum')
            ->get("/api/student/submissions/{$model->id}/files/{$file->id}/download")
            ->assertNotFound();

        // Other teacher cannot read -> 404.
        $otherTeacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $this->actingAs($otherTeacher, 'sanctum')
            ->get("/api/teacher/submissions/{$model->id}/files/{$file->id}/download")
            ->assertNotFound();

        // Unauthenticated -> 401.
        Auth::guard('sanctum')->forgetUser();
        $this->get("/api/teacher/submissions/{$model->id}/files/{$file->id}/download")->assertUnauthorized();
    }

    public function test_download_blocks_traversal_and_sanitizes_disposition(): void
    {
        [$teacher, $student, , $classroom, $competency] = $this->setUpOrg();
        Storage::fake('local');

        [, $item] = $this->createAssessmentWithItem($teacher, $classroom, $competency);
        $itemModel = AssessmentItem::find($item['id']);
        $itemAttachment = $itemModel->attachments->first();
        $realKey = $itemAttachment->filename;

        // Out-of-directory key never serves, even when the file exists.
        $itemAttachment->filename = 'assignments/1/other.pdf';
        $itemAttachment->save();
        Storage::disk('local')->put('assignments/1/other.pdf', 'secret');
        $this->actingAs($teacher, 'sanctum')
            ->get("/api/teacher/items/{$itemModel->id}/attachments/{$itemAttachment->id}/download")
            ->assertNotFound();

        // CRLF in the stored display name never reaches the header raw.
        $itemAttachment->filename = $realKey;
        $itemAttachment->original_filename = "evil\r\nHeader: injected.pdf";
        $itemAttachment->save();
        $dl = $this->actingAs($teacher, 'sanctum')
            ->get("/api/teacher/items/{$itemModel->id}/attachments/{$itemAttachment->id}/download");
        $dl->assertOk();
        $disp = (string) $dl->headers->get('Content-Disposition');
        $this->assertStringNotContainsString("\r", $disp);
        $this->assertStringNotContainsString("\n", $disp);
        $this->assertStringContainsString('evilHeader', $disp);
    }
}
