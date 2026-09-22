<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentAttachment;
use App\Models\AssignmentFeedback;
use App\Models\AssignmentSubmission;
use App\Models\AuditLog;
use App\Models\ClassroomEnrollment;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\SubmissionFile;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 6 (ARCH-002 QA-012) — closed-term purge dry-run mode and fail-closed file
 * deletion (ARCH-002 QA-010, ARCH-002 QA-010, ARCH-002 QA-010, ARCH-002 QA-010).
 *
 * - `?dry_run=1` (literal "1" only) returns the same counts as a real purge
 *   but deletes nothing: no DB rows, no files, no `purge_semesters` audit entry.
 * - A real purge deletes submission files BEFORE the DB transaction; if any
 *   file that exists cannot be deleted the purge aborts with
 *   `PURGE_FILE_DELETION_FAILED` (409) and the DB stays untouched.
 *
 * @Traced-To ARCH-002 QA-010, ARCH-002 QA-010, ARCH-002 QA-010, ARCH-002 QA-006, ARCH-002 QA-012 (ARCH-002 QA-010, ARCH-002 QA-010)
 */
#[Group('phase6-purge-dry-run')]
class Phase6PurgeDryRunTest extends TestCase
{
    use RefreshDatabase;

    private ?Subject $subject = null;

    private ?FilesystemManager $originalFilesystemManager = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalFilesystemManager = app('filesystem');
    }

    protected function tearDown(): void
    {
        if ($this->originalFilesystemManager !== null) {
            Storage::swap($this->originalFilesystemManager);
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function makeUser(string $role, array $overrides = []): User
    {
        return User::factory()->{$role}()->create(array_merge([
            'must_change_password' => false], $overrides));
    }

    /**
     * Seed the org hierarchy: School Year -> Semester -> GradeLevel -> Section ->
     * Classroom, plus admin/teacher/student users, classroom
     * and student enrollments.
     *
     * @return array{admin: User, teacher: User, students: User[], semester: Semester, grade_level: GradeLevel, section: Section, subject: Subject, classroom: \App\Models\Classroom}
     */
    private function seedOrg(array $overrides = []): array
    {
        $admin = $this->makeUser('admin', []);
        $teacher = $this->makeUser('teacher', []);
        $studentA = $this->makeUser('student', []);
        $studentB = $this->makeUser('student', []);

        $schoolYear = SchoolYear::create(['name' => 'SY 2025-2026']);

        $semester = Semester::create(['semester' => '1', 'school_year_id' => $schoolYear->id,
            'name' => $overrides['semester_name'] ?? 'Semester 1',
            'start_date' => $overrides['start_date'] ?? '2026-01-05',
            'end_date' => $overrides['end_date'] ?? '2026-06-30']);

        $gradeLevel = GradeLevel::create(['semester_id' => $semester->id, 'grade_level' => '7']);
        $section = Section::create(['grade_level_id' => $gradeLevel->id, 'name' => '7A']);
$this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH']);


        $classroom = app(\App\Services\ClassroomService::class)->createClassroom($teacher->id, $this->subject->id, $section->id, '2026-2027', null);

        ClassroomEnrollment::create([
            'classroom_id' => $classroom->id,
            'student_id' => $studentA->id]);
        ClassroomEnrollment::create([
            'classroom_id' => $classroom->id,
            'student_id' => $studentB->id]);

        return [
            'admin' => $admin,
            'teacher' => $teacher,
            'students' => [$studentA, $studentB],
            'semester' => $semester,
            'grade_level' => $gradeLevel,
            'section' => $section,
            'subject' => $this->subject,
            'classroom' => $classroom];
    }

    private function createAssignment(int $teacherId, int $subjectId, int $semesterId, int $classroomId, string $title, string $dueDate = '2026-02-01 23:59:59'): Assignment
    {
        return Assignment::create([
            'teacher_id' => $teacherId,
            'subject_id' => $subjectId,
            'classroom_id' => $classroomId,
            'semester_id' => $semesterId,
            'title' => $title,
            'description' => 'Assignment description.',
            'due_date' => $dueDate]);
    }

    /**
     * Create a submission with the given file names, writing each file to the
     * local disk under the real submission-files path pattern. Returns the
     * submission plus the stored paths.
     *
     * @param  string[]  $names
     * @return array{submission: AssignmentSubmission, paths: string[]}
     */
    private function createSubmissionWithFiles(int $assignmentId, int $studentId, array $names): array
    {
        $submission = AssignmentSubmission::create([
            'assignment_id' => $assignmentId,
            'student_id' => $studentId,
            'submitted_at' => '2026-02-02 10:00:00',
            'status' => 'on_time']);

        $paths = [];
        foreach ($names as $name) {
            $path = 'assignments/submissions/' . $submission->id . '/' . $name;
            Storage::disk('local')->put($path, 'submission file bytes: ' . $name);
            SubmissionFile::create([
                'submission_id' => $submission->id,
                'filename' => $path,
                'original_filename' => $name,
                'mime_type' => 'application/pdf',
                'file_size' => 100]);
            $paths[] = $path;
        }

        return ['submission' => $submission, 'paths' => $paths];
    }

    private function actAs(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    // ---------------------------------------------------------------------
    // Dry-run mode (ARCH-002 QA-012)
    // ---------------------------------------------------------------------

    public function test_dry_run_returns_counts_and_deletes_nothing(): void
    {
        Storage::fake('local');
        $org = $this->seedOrg();
        $admin = $org['admin'];
        $semester = $org['semester'];

        $assignmentWithSubmissions = $this->createAssignment($org['teacher']->id, $org['subject']->id, $semester->id, $org['classroom']->id, 'Assignment A');
        $this->createAssignment($org['teacher']->id, $org['subject']->id, $semester->id, $org['classroom']->id, 'Assignment B');

        $attachmentPath = 'assignments/attachments/' . $assignmentWithSubmissions->id . '/lesson.pdf';
        Storage::disk('local')->put($attachmentPath, 'teacher attachment bytes');
        AssignmentAttachment::create([
            'assignment_id' => $assignmentWithSubmissions->id,
            'filename' => $attachmentPath,
            'original_filename' => 'lesson.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 50]);

        $subA = $this->createSubmissionWithFiles($assignmentWithSubmissions->id, $org['students'][0]->id, ['answer-a.pdf', 'extra-a.pdf']);
        $subB = $this->createSubmissionWithFiles($assignmentWithSubmissions->id, $org['students'][1]->id, ['answer-b.pdf', 'extra-b.pdf']);

        AssignmentFeedback::create([
            'submission_id' => $subA['submission']->id,
            'teacher_id' => $org['teacher']->id,
            'feedback_text' => 'Well done.']);

        $response = $this->actAs($admin)->postJson('/api/admin/semesters/' . $semester->id . '/purge?dry_run=1');

        $response->assertOk()
            ->assertJsonPath('data.dry_run', true)
            ->assertJsonPath('data.message', 'Dry-run: no data was deleted.')
            ->assertJsonPath('data.semester_id', $semester->id)
            ->assertJsonPath('data.assignments_purged', 1)
            ->assertJsonPath('data.submissions_purged', 2)
            ->assertJsonPath('data.files_purged', 4);

        $this->assertSame(4, SubmissionFile::count());
        $this->assertSame(2, AssignmentSubmission::count());
        $this->assertSame(1, AssignmentFeedback::count());

        foreach (array_merge($subA['paths'], $subB['paths']) as $path) {
            Storage::disk('local')->assertExists($path);
        }
        Storage::disk('local')->assertExists($attachmentPath);

        $this->assertSame(0, AuditLog::where('event_type', 'purge_semesters')->count());
    }

    public function test_dry_run_still_requires_closed_term(): void
    {
        Storage::fake('local');
        $org = $this->seedOrg(['end_date' => '2026-12-31']);
        $semester = $org['semester'];

        $assignment = $this->createAssignment($org['teacher']->id, $org['subject']->id, $semester->id, $org['classroom']->id, 'Assignment A');
        $sub = $this->createSubmissionWithFiles($assignment->id, $org['students'][0]->id, ['answer-a.pdf']);

        $response = $this->actAs($org['admin'])->postJson('/api/admin/semesters/' . $semester->id . '/purge?dry_run=1');

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'SEMESTER_NOT_CLOSED');

        $this->assertSame(1, AssignmentSubmission::count());
        $this->assertSame(1, SubmissionFile::count());
        Storage::disk('local')->assertExists($sub['paths'][0]);
        $this->assertSame(0, AuditLog::where('event_type', 'purge_semesters')->count());
    }

    public function test_dry_run_ignores_non_one_values(): void
    {
        Storage::fake('local');
        $org = $this->seedOrg();

        foreach (['0', 'true', 'yes'] as $i => $value) {
            // Each purge target needs its own School Year: semester '1'
            // already exists under the seed org's year (UNIQUE(school_year_id, semester)).
            $loopYear = SchoolYear::create(['name' => 'SY 2025-2026-' . $value]);
            $semester = Semester::create(['semester' => '1', 'school_year_id' => $loopYear->id,
                'name' => 'Semester ' . $value,
                'start_date' => '2026-01-05',
                'end_date' => '2026-06-30']);
            $assignment = $this->createAssignment($org['teacher']->id, $org['subject']->id, $semester->id, $org['classroom']->id, 'Assignment ' . $value);
            $sub = $this->createSubmissionWithFiles($assignment->id, $org['students'][0]->id, ['answer-' . $value . '.pdf']);

            $response = $this->actAs($org['admin'])->postJson('/api/admin/semesters/' . $semester->id . '/purge?dry_run=' . $value);

            $response->assertOk()
                ->assertJsonPath('data.message', 'Semester data purged.')
                ->assertJsonPath('data.assignments_purged', 1)
                ->assertJsonPath('data.submissions_purged', 1)
                ->assertJsonPath('data.files_purged', 1)
                ->assertJsonMissingPath('data.dry_run');

            $this->assertSame(0, SubmissionFile::count());
            $this->assertSame(0, AssignmentSubmission::count());
            Storage::disk('local')->assertMissing($sub['paths'][0]);
            $this->assertDatabaseHas('assignments', ['id' => $assignment->id]);
        }

        $this->assertSame(3, AuditLog::where('event_type', 'purge_semesters')->count());
    }

    // ---------------------------------------------------------------------
    // Fail-closed file deletion (ARCH-002 QA-012)
    // ---------------------------------------------------------------------

    public function test_purge_fails_closed_when_file_deletion_fails(): void
    {
        $realDisk = Storage::fake('local');
        $org = $this->seedOrg();
        $semester = $org['semester'];

        $assignment = $this->createAssignment($org['teacher']->id, $org['subject']->id, $semester->id, $org['classroom']->id, 'Assignment A');
        $sub = $this->createSubmissionWithFiles($assignment->id, $org['students'][0]->id, ['answer-a.pdf', 'extra-a.pdf']);

        $throwingDisk = Mockery::mock(Filesystem::class);
        $throwingDisk->shouldReceive('exists')->andReturn(true);
        $throwingDisk->shouldReceive('delete')->andThrow(new RuntimeException('Simulated disk failure'));

        Storage::partialMock()->shouldReceive('disk')
            ->with('local')
            ->andReturnValues([$throwingDisk, $realDisk]);

        $response = $this->actAs($org['admin'])->postJson('/api/admin/semesters/' . $semester->id . '/purge');

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'PURGE_FILE_DELETION_FAILED');
        $this->assertStringContainsString($sub['paths'][0], (string) $response->json('error.message'));

        $this->assertSame(2, SubmissionFile::count());
        $this->assertSame(1, AssignmentSubmission::count());
        $this->assertSame(0, AssignmentFeedback::count());
        $this->assertDatabaseHas('assignments', ['id' => $assignment->id]);
        $this->assertSame(0, AuditLog::where('event_type', 'purge_semesters')->count());

        foreach ($sub['paths'] as $path) {
            $realDisk->assertExists($path);
        }

        $retry = $this->actAs($org['admin'])->postJson('/api/admin/semesters/' . $semester->id . '/purge');

        $retry->assertOk()
            ->assertJsonMissingPath('data.dry_run')
            ->assertJsonPath('data.assignments_purged', 1)
            ->assertJsonPath('data.submissions_purged', 1)
            ->assertJsonPath('data.files_purged', 2);

        $this->assertSame(0, SubmissionFile::count());
        $this->assertSame(0, AssignmentSubmission::count());
        $this->assertSame(0, AssignmentFeedback::count());
        $this->assertDatabaseHas('assignments', ['id' => $assignment->id]);
        $this->assertSame(1, AuditLog::where('event_type', 'purge_semesters')->count());

        foreach ($sub['paths'] as $path) {
            $realDisk->assertMissing($path);
        }
    }

    public function test_real_purge_after_dry_run_succeeds(): void
    {
        Storage::fake('local');
        $org = $this->seedOrg();
        $admin = $org['admin'];
        $semester = $org['semester'];

        $assignment = $this->createAssignment($org['teacher']->id, $org['subject']->id, $semester->id, $org['classroom']->id, 'Assignment A');
        $sub = $this->createSubmissionWithFiles($assignment->id, $org['students'][0]->id, ['answer-a.pdf', 'extra-a.pdf']);

        $dry = $this->actAs($admin)->postJson('/api/admin/semesters/' . $semester->id . '/purge?dry_run=1');

        $dry->assertOk()
            ->assertJsonPath('data.dry_run', true)
            ->assertJsonPath('data.assignments_purged', 1)
            ->assertJsonPath('data.submissions_purged', 1)
            ->assertJsonPath('data.files_purged', 2);

        $this->assertSame(2, SubmissionFile::count());
        $this->assertSame(1, AssignmentSubmission::count());
        foreach ($sub['paths'] as $path) {
            Storage::disk('local')->assertExists($path);
        }
        $this->assertSame(0, AuditLog::where('event_type', 'purge_semesters')->count());

        $real = $this->actAs($admin)->postJson('/api/admin/semesters/' . $semester->id . '/purge');

        $real->assertOk()
            ->assertJsonMissingPath('data.dry_run')
            ->assertJsonPath('data.message', 'Semester data purged.')
            ->assertJsonPath('data.assignments_purged', 1)
            ->assertJsonPath('data.submissions_purged', 1)
            ->assertJsonPath('data.files_purged', 2);

        $this->assertSame(0, SubmissionFile::count());
        $this->assertSame(0, AssignmentSubmission::count());
        $this->assertSame(0, AssignmentFeedback::count());
        $this->assertDatabaseHas('assignments', ['id' => $assignment->id]);
        foreach ($sub['paths'] as $path) {
            Storage::disk('local')->assertMissing($path);
        }

        $audit = AuditLog::where('event_type', 'purge_semesters')
            ->where('auditable_type', Semester::class)
            ->where('auditable_id', $semester->id)
            ->firstOrFail();
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame('Semester-closure purge (semester ' . $semester->id . ')', $audit->description);
        $this->assertSame(1, $audit->metadata['assignments_purged']);
        $this->assertSame(1, $audit->metadata['submissions_purged']);
        $this->assertSame(2, $audit->metadata['files_purged']);
    }
}
