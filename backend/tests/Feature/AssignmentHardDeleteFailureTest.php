<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\Assignment;
use App\Models\AssignmentAttachment;
use App\Models\AssignmentFeedback;
use App\Models\AssignmentSubmission;
use App\Models\ClassroomEnrollment;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\SubmissionFile;
use App\Models\Semester;
use App\Models\User;
use App\Services\AssignmentService;
use App\Services\ClassroomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * AUD-016 / Unit U9 — records-authoritative ordering in assignment hard delete.
 * A post-first-row-delete DB failure must roll back ALL row deletions while
 * leaving every backing file on disk (no disk deletion inside the transaction).
 */
#[Group('assignment-hard-delete')]
class AssignmentHardDeleteFailureTest extends TestCase
{
    use RefreshDatabase;

    private ?Subject $subject = null;

    public function test_hard_delete_failure_after_first_row_delete_preserves_rows_and_files(): void
    {
        Storage::fake('local');

        // Org fixture (mirrors Phase3EdgeCaseTest::setUpOrg).
        $year = SchoolYear::create(['name' => 'SY 2026']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
        ]);
        $gradeLevel = GradeLevel::create(['semester_id' => $semester->id, 'grade_level' => '7']);
        $section = Section::create(['grade_level_id' => $gradeLevel->id, 'name' => '7A']);
$this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7']);
        $teacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $classroom = app(ClassroomService::class)->createClassroom($teacher->id, $this->subject->id, $section->id, '2026-2027', null);

        $assignment = Assignment::create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $this->subject->id,
            'semester_id' => $semester->id,
            'title' => 'Hard Delete Failure Sim',
            'description' => null,
            'due_date' => '2026-12-31 23:59:00',
        ]);

        // Two students (one submission per student per assignment), each with a
        // real file on the fake disk + file row + feedback row, so the simulated
        // failure lands AFTER the first submission's rows have been deleted.
        $students = [
            User::factory()->create(['role' => 'Student', 'must_change_password' => false]),
            User::factory()->create(['role' => 'Student', 'must_change_password' => false]),
        ];

        $submissions = [];
        $diskPaths = [];

        foreach ($students as $i => $student) {
            ClassroomEnrollment::create([
                'classroom_id' => $classroom->id,
                'student_id' => $student->id,
                'joined_at' => now(),
            ]);

            $submission = AssignmentSubmission::create([
                'assignment_id' => $assignment->id,
                'student_id' => $student->id,
                'submitted_at' => now(),
                'status' => 'on_time',
            ]);
            $path = 'assignments/submissions/' . $submission->id . '/work-' . ($i + 1) . '.pdf';
            Storage::disk('local')->put($path, 'submission bytes ' . ($i + 1));
            SubmissionFile::create([
                'submission_id' => $submission->id,
                'filename' => $path,
                'original_filename' => 'work-' . ($i + 1) . '.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 20,
            ]);
            AssignmentFeedback::create([
                'submission_id' => $submission->id,
                'teacher_id' => $teacher->id,
                'feedback_text' => 'fb-' . ($i + 1),
            ]);

            $submissions[] = $submission;
            $diskPaths[] = $path;
        }

        $attachmentPath = 'assignments/attachments/' . $assignment->id . '/sheet.pdf';
        Storage::disk('local')->put($attachmentPath, 'attachment bytes');
        AssignmentAttachment::create([
            'assignment_id' => $assignment->id,
            'filename' => $attachmentPath,
            'original_filename' => 'sheet.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 16,
        ]);
        $diskPaths[] = $attachmentPath;

        // Seam: throw from a query listener when the SECOND submission-row
        // DELETE statement is seen — i.e., after the first submission's rows
        // were deleted inside the transaction. The listener lives on the
        // per-test connection instance, so it cannot leak into other tests.
        $seenSubmissionDeletes = 0;
        DB::listen(function ($query) use (&$seenSubmissionDeletes): void {
            if (str_contains($query->sql, 'delete from "assignment_submissions"')) {
                $seenSubmissionDeletes++;

                if ($seenSubmissionDeletes === 2) {
                    throw new RuntimeException('Simulated post-first-row-delete DB failure.');
                }
            }
        });

        try {
            app(AssignmentService::class)->confirmDeleteAssignment($teacher->id, $assignment->id);
            $this->fail('Expected simulated post-first-row-delete DB failure was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated post-first-row-delete DB failure.', $e->getMessage());
        }

        $this->assertSame(2, $seenSubmissionDeletes, 'Failure did not land after the first submission delete.');

        // Rows survive: the transaction rolled everything back.
        $this->assertDatabaseHas('assignments', ['id' => $assignment->id]);
        foreach ($submissions as $submission) {
            $this->assertDatabaseHas('assignment_submissions', ['id' => $submission->id]);
            $this->assertDatabaseHas('assignment_feedback', ['submission_id' => $submission->id]);
        }
        $this->assertDatabaseHas('submission_files', ['filename' => $diskPaths[0]]);
        $this->assertDatabaseHas('submission_files', ['filename' => $diskPaths[1]]);
        $this->assertDatabaseHas('assignment_attachments', ['filename' => $attachmentPath]);

        // Files survive: no Storage call may have run before the rollback.
        foreach ($diskPaths as $path) {
            Storage::disk('local')->assertExists($path);
        }

        // Failure path writes no audit row (emission happens only on success).
        $this->assertDatabaseMissing('audit_logs', [
            'event_type' => 'delete',
            'auditable_type' => Assignment::class,
            'auditable_id' => $assignment->id,
        ]);
    }
}
