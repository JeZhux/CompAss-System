<?php

namespace Tests\Feature;

use App\Models\Assignment;
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
use App\Services\OrgStructureService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * F-06 — term-closure purge uses trash-rename atomicity (ARCH-002 QA-010, ARCH-002 QA-010,
 * ARCH-002 QA-010).
 *
 * - Files are moved (same-disk rename, no bytes in RAM) to
 *   `purge_trash/{term}/` before the DB transaction. Any move failure moves
 *   already-moved files back and aborts 409 with files+rows unchanged; a DB
 *   failure moves the trash back and rethrows (transaction rolls back).
 * - Read-path faults cannot strand data: staging never reads bytes.
 * - The only non-atomic tail is final trash deletion after commit:
 *   leftovers are logged and returned in `trash_remaining`, and every retry
 *   sweeps them so the manifest converges to zero.
 * - Dry-run counts equal real-run deletions; rerun is idempotent.
 *
 * @Traced-To ARCH-002 QA-010, ARCH-002 QA-010, ARCH-002 QA-010, ARCH-002 QA-006, ARCH-002 QA-012 (ARCH-002 QA-010, ARCH-002 QA-010)
 */
#[Group('term-purge-atomicity')]
class TermPurgeAtomicityTest extends TestCase
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

    private function makeUser(string $role, array $overrides = []): User
    {
        return User::factory()->{$role}()->create(array_merge([
            'must_change_password' => false], $overrides));
    }

    /**
     * @return array{admin: User, teacher: User, students: User[], semester: Semester, classroom: \App\Models\Classroom}
     */
    private function seedOrg(): array
    {
        $admin = $this->makeUser('admin', []);
        $teacher = $this->makeUser('teacher', []);
        $studentA = $this->makeUser('student', []);
        $studentB = $this->makeUser('student', []);

        $schoolYear = SchoolYear::create(['name' => 'SY 2025-2026']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $schoolYear->id,
            'name' => 'Semester 1',
            'start_date' => '2026-01-05',
            'end_date' => '2026-06-30']);

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
            'subject' => $this->subject,
            'classroom' => $classroom];
    }

    /**
     * @param  string[]  $names
     * @return array{submission: AssignmentSubmission, paths: string[], contents: array<string, string>}
     */
    private function createSubmissionWithFiles(int $assignmentId, int $studentId, array $names): array
    {
        $submission = AssignmentSubmission::create([
            'assignment_id' => $assignmentId,
            'student_id' => $studentId,
            'submitted_at' => '2026-02-02 10:00:00',
            'status' => 'on_time']);

        $paths = [];
        $contents = [];
        foreach ($names as $name) {
            $path = 'assignments/submissions/' . $submission->id . '/' . $name;
            $body = 'submission file bytes: ' . $name;
            Storage::disk('local')->put($path, $body);
            SubmissionFile::create([
                'submission_id' => $submission->id,
                'filename' => $path,
                'original_filename' => $name,
                'mime_type' => 'application/pdf',
                'file_size' => 100]);
            $paths[] = $path;
            $contents[$path] = $body;
        }

        return ['submission' => $submission, 'paths' => $paths, 'contents' => $contents];
    }

    private function createAssignmentWithSubmission(array $org): array
    {
        $assignment = Assignment::create([
            'teacher_id' => $org['teacher']->id,
            'subject_id' => $org['subject']->id,
            'classroom_id' => $org['classroom']->id,
            'semester_id' => $org['semester']->id,
            'title' => 'Assignment A',
            'description' => 'Assignment description.',
            'due_date' => '2026-02-01 23:59:59']);
        $sub = $this->createSubmissionWithFiles($assignment->id, $org['students'][0]->id, ['answer-a.pdf', 'answer-b.pdf']);
        AssignmentFeedback::create([
            'submission_id' => $sub['submission']->id,
            'teacher_id' => $org['teacher']->id,
            'feedback_text' => 'Well done.']);

        return [$assignment, $sub];
    }

    private function actAs(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    /**
     * Leftover trash paths for a term; never throws (missing dir → []).
     *
     * @return string[]
     */
    private function trashLeftovers($disk, int $semesterId): array
    {
        try {
            return array_values($disk->allFiles('purge_trash/' . $semesterId));
        } catch (\Throwable) {
            return [];
        }
    }

    private function assertNoTrashLeft($disk, int $semesterId): void
    {
        $this->assertSame([], $this->trashLeftovers($disk, $semesterId));
    }

    public function test_mid_move_failure_restores_moved_file_and_retry_converges(): void
    {
        $realDisk = Storage::fake('local');
        $org = $this->seedOrg();
        [$assignment, $sub] = $this->createAssignmentWithSubmission($org);

        // Dry-run first: its counts must equal the real-run deletions below.
        $dry = $this->actAs($org['admin'])
            ->postJson('/api/admin/semesters/' . $org['semester']->id . '/purge?dry_run=1');
        $dry->assertOk()
            ->assertJsonPath('data.assignments_purged', 1)
            ->assertJsonPath('data.submissions_purged', 1)
            ->assertJsonPath('data.files_purged', 2)
            ->assertJsonPath('data.trash_remaining', []);

        // Fault injection: the first file moves fine, the second move fails
        // (returns false). All other calls delegate to the real disk.
        $failPath = $sub['paths'][1];
        $faultingDisk = Mockery::mock(Filesystem::class);
        $faultingDisk->shouldReceive('exists')->andReturnUsing(fn ($p): bool => $realDisk->exists($p));
        $faultingDisk->shouldReceive('files')->andReturnUsing(fn ($d): array => $realDisk->files($d));
        $faultingDisk->shouldReceive('move')->andReturnUsing(function ($from, $to) use ($realDisk, $failPath) {
            if ($from === $failPath) {
                return false;
            }

            return $realDisk->move($from, $to);
        });
        $faultingDisk->shouldReceive('delete')->andReturnUsing(fn ($p): bool => $realDisk->delete($p));

        Storage::partialMock()->shouldReceive('disk')
            ->with('local')
            ->andReturnValues([$faultingDisk, $realDisk]);

        $response = $this->actAs($org['admin'])
            ->postJson('/api/admin/semesters/' . $org['semester']->id . '/purge');

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'PURGE_FILE_DELETION_FAILED');
        $this->assertStringContainsString($failPath, (string) $response->json('error.message'));

        // All files+rows unchanged: the moved file was renamed back, and no
        // trash is left behind.
        $this->assertSame(2, SubmissionFile::count());
        $this->assertSame(1, AssignmentSubmission::count());
        $this->assertSame(1, AssignmentFeedback::count());
        $this->assertDatabaseHas('assignments', ['id' => $assignment->id]);
        foreach ($sub['paths'] as $path) {
            $realDisk->assertExists($path);
            $this->assertSame($sub['contents'][$path], $realDisk->get($path));
        }
        $this->assertNoTrashLeft($realDisk, $org['semester']->id);
        $this->assertSame(0, AuditLog::where('event_type', 'purge_semesters')->count());

        // Retry converges to the same counts as a single success.
        $retry = $this->actAs($org['admin'])
            ->postJson('/api/admin/semesters/' . $org['semester']->id . '/purge');

        $retry->assertOk()
            ->assertJsonMissingPath('data.dry_run')
            ->assertJsonPath('data.assignments_purged', $dry->json('data.assignments_purged'))
            ->assertJsonPath('data.submissions_purged', $dry->json('data.submissions_purged'))
            ->assertJsonPath('data.files_purged', $dry->json('data.files_purged'))
            ->assertJsonPath('data.trash_remaining', []);

        $this->assertSame(0, SubmissionFile::count());
        $this->assertSame(0, AssignmentSubmission::count());
        $this->assertSame(0, AssignmentFeedback::count());
        $this->assertDatabaseHas('assignments', ['id' => $assignment->id]);
        foreach ($sub['paths'] as $path) {
            $realDisk->assertMissing($path);
        }
        $this->assertNoTrashLeft($realDisk, $org['semester']->id);
        $this->assertSame(1, AuditLog::where('event_type', 'purge_semesters')->count());

        // Idempotent: a further rerun deletes nothing but still audits.
        $rerun = $this->actAs($org['admin'])
            ->postJson('/api/admin/semesters/' . $org['semester']->id . '/purge');
        $rerun->assertOk()
            ->assertJsonPath('data.assignments_purged', 0)
            ->assertJsonPath('data.submissions_purged', 0)
            ->assertJsonPath('data.files_purged', 0)
            ->assertJsonPath('data.trash_remaining', []);
        $this->assertSame(2, AuditLog::where('event_type', 'purge_semesters')->count());
    }

    public function test_first_move_failure_leaves_all_unchanged_with_no_trash(): void
    {
        $realDisk = Storage::fake('local');
        $org = $this->seedOrg();
        [$assignment, $sub] = $this->createAssignmentWithSubmission($org);

        // Fault injection: every move throws before anything is staged.
        $faultingDisk = Mockery::mock(Filesystem::class);
        $faultingDisk->shouldReceive('exists')->andReturnUsing(fn ($p): bool => $realDisk->exists($p));
        $faultingDisk->shouldReceive('files')->andReturnUsing(fn ($d): array => $realDisk->files($d));
        $faultingDisk->shouldReceive('move')->andThrow(new RuntimeException('Simulated rename failure'));
        $faultingDisk->shouldReceive('delete')->andReturnUsing(fn ($p): bool => $realDisk->delete($p));

        Storage::partialMock()->shouldReceive('disk')
            ->with('local')
            ->andReturnValues([$faultingDisk, $realDisk]);

        $response = $this->actAs($org['admin'])
            ->postJson('/api/admin/semesters/' . $org['semester']->id . '/purge');

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'PURGE_FILE_DELETION_FAILED');
        $this->assertStringContainsString($sub['paths'][0], (string) $response->json('error.message'));

        $this->assertSame(2, SubmissionFile::count());
        $this->assertSame(1, AssignmentSubmission::count());
        $this->assertSame(1, AssignmentFeedback::count());
        $this->assertDatabaseHas('assignments', ['id' => $assignment->id]);
        foreach ($sub['paths'] as $path) {
            $realDisk->assertExists($path);
            $this->assertSame($sub['contents'][$path], $realDisk->get($path));
        }
        $this->assertNoTrashLeft($realDisk, $org['semester']->id);
        $this->assertSame(0, AuditLog::where('event_type', 'purge_semesters')->count());

        // Retry without the fault succeeds with full counts.
        $retry = $this->actAs($org['admin'])
            ->postJson('/api/admin/semesters/' . $org['semester']->id . '/purge');
        $retry->assertOk()
            ->assertJsonPath('data.assignments_purged', 1)
            ->assertJsonPath('data.submissions_purged', 1)
            ->assertJsonPath('data.files_purged', 2)
            ->assertJsonPath('data.trash_remaining', []);
        $this->assertSame(0, SubmissionFile::count());
        $this->assertNoTrashLeft($realDisk, $org['semester']->id);
        $this->assertSame(1, AuditLog::where('event_type', 'purge_semesters')->count());
    }

    public function test_correlated_read_fault_does_not_block_purge(): void
    {
        $realDisk = Storage::fake('local');
        $org = $this->seedOrg();
        [$assignment, $sub] = $this->createAssignmentWithSubmission($org);

        // Correlated fault: reads always fail (as with the old byte-staging
        // design's restore path), but renames work. Staging never reads, so
        // the purge must succeed without any memory dependence.
        $faultingDisk = Mockery::mock(Filesystem::class);
        $faultingDisk->shouldReceive('exists')->andReturnUsing(fn ($p): bool => $realDisk->exists($p));
        $faultingDisk->shouldReceive('files')->andReturnUsing(fn ($d): array => $realDisk->files($d));
        $faultingDisk->shouldReceive('get')->andThrow(new RuntimeException('Simulated read failure'));
        $faultingDisk->shouldReceive('put')->andThrow(new RuntimeException('Simulated write failure'));
        $faultingDisk->shouldReceive('move')->andReturnUsing(fn ($from, $to): bool => $realDisk->move($from, $to));
        $faultingDisk->shouldReceive('delete')->andReturnUsing(fn ($p): bool => $realDisk->delete($p));

        Storage::partialMock()->shouldReceive('disk')
            ->with('local')
            ->andReturn($faultingDisk);

        $response = $this->actAs($org['admin'])
            ->postJson('/api/admin/semesters/' . $org['semester']->id . '/purge');

        $response->assertOk()
            ->assertJsonPath('data.assignments_purged', 1)
            ->assertJsonPath('data.submissions_purged', 1)
            ->assertJsonPath('data.files_purged', 2)
            ->assertJsonPath('data.trash_remaining', []);

        $this->assertSame(0, SubmissionFile::count());
        $this->assertSame(0, AssignmentSubmission::count());
        $this->assertSame(0, AssignmentFeedback::count());
        $this->assertDatabaseHas('assignments', ['id' => $assignment->id]);
        foreach ($sub['paths'] as $path) {
            $realDisk->assertMissing($path);
        }
        $this->assertNoTrashLeft($realDisk, $org['semester']->id);
        $this->assertSame(1, AuditLog::where('event_type', 'purge_semesters')->count());
    }

    public function test_db_failure_moves_trash_back_and_retry_succeeds(): void
    {
        Storage::fake('local');
        $org = $this->seedOrg();
        [$assignment, $sub] = $this->createAssignmentWithSubmission($org);

        // Fault injection: the first DB transaction throws; later ones run
        // for real via the captured manager (no recursion: the mock is not
        // the delegation target).
        $realDb = app('db');
        $attempts = 0;
        DB::partialMock()->shouldReceive('transaction')->andReturnUsing(function (\Closure $callback) use ($realDb, &$attempts) {
            $attempts++;

            if ($attempts === 1) {
                throw new RuntimeException('Simulated database failure');
            }

            return $realDb->transaction($callback);
        });

        try {
            app(OrgStructureService::class)->purgeClosedSemester($org['semester']->id);

            $this->fail('Expected RuntimeException from the failing transaction');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated database failure', $e->getMessage());
        }

        // partialMock() rebinds the container 'db' to the mock: swap the
        // real manager back before any container-based DB assertions.
        DB::swap($realDb);

        // Transaction rolled back AND every trash file was renamed back.
        $this->assertSame(2, SubmissionFile::count());
        $this->assertSame(1, AssignmentSubmission::count());
        $this->assertSame(1, AssignmentFeedback::count());
        $this->assertDatabaseHas('assignments', ['id' => $assignment->id]);
        foreach ($sub['paths'] as $path) {
            Storage::disk('local')->assertExists($path);
            $this->assertSame($sub['contents'][$path], Storage::disk('local')->get($path));
        }
        $this->assertNoTrashLeft(Storage::disk('local'), $org['semester']->id);
        $this->assertSame(0, AuditLog::where('event_type', 'purge_semesters')->count());

        // Retry (transaction now delegates for real) succeeds with full counts.
        $retry = $this->actAs($org['admin'])
            ->postJson('/api/admin/semesters/' . $org['semester']->id . '/purge');
        $retry->assertOk()
            ->assertJsonPath('data.assignments_purged', 1)
            ->assertJsonPath('data.submissions_purged', 1)
            ->assertJsonPath('data.files_purged', 2)
            ->assertJsonPath('data.trash_remaining', []);

        $this->assertSame(0, SubmissionFile::count());
        $this->assertSame(0, AssignmentSubmission::count());
        foreach ($sub['paths'] as $path) {
            Storage::disk('local')->assertMissing($path);
        }
        $this->assertNoTrashLeft(Storage::disk('local'), $org['semester']->id);
        $this->assertSame(1, AuditLog::where('event_type', 'purge_semesters')->count());
    }

    public function test_final_trash_delete_failure_returns_manifest_and_retry_cleans_trash(): void
    {
        $realDisk = Storage::fake('local');
        $org = $this->seedOrg();
        [$assignment, $sub] = $this->createAssignmentWithSubmission($org);

        // Fault injection: trash deletes fail (orphans strand), everything
        // else delegates to the real disk.
        $failTrashDelete = true;
        $faultingDisk = Mockery::mock(Filesystem::class);
        $faultingDisk->shouldReceive('exists')->andReturnUsing(fn ($p): bool => $realDisk->exists($p));
        $faultingDisk->shouldReceive('files')->andReturnUsing(fn ($d): array => $realDisk->files($d));
        $faultingDisk->shouldReceive('move')->andReturnUsing(fn ($from, $to): bool => $realDisk->move($from, $to));
        $faultingDisk->shouldReceive('delete')->andReturnUsing(function ($p) use ($realDisk, &$failTrashDelete) {
            if ($failTrashDelete && str_starts_with($p, 'purge_trash/')) {
                return false;
            }

            return $realDisk->delete($p);
        });

        Storage::partialMock()->shouldReceive('disk')
            ->with('local')
            ->andReturn($faultingDisk);

        $response = $this->actAs($org['admin'])
            ->postJson('/api/admin/semesters/' . $org['semester']->id . '/purge');

        // The purge succeeds (rows committed) but inventories the orphans.
        $response->assertOk()
            ->assertJsonPath('data.assignments_purged', 1)
            ->assertJsonPath('data.submissions_purged', 1)
            ->assertJsonPath('data.files_purged', 2);

        $manifest = $response->json('data.trash_remaining');
        $this->assertIsArray($manifest);
        $this->assertCount(2, $manifest);
        foreach ($manifest as $orphan) {
            $this->assertStringStartsWith('purge_trash/' . $org['semester']->id . '/', $orphan);
            $realDisk->assertExists($orphan);
        }

        $this->assertSame(0, SubmissionFile::count());
        $this->assertSame(0, AssignmentSubmission::count());
        $this->assertSame(0, AssignmentFeedback::count());
        $this->assertDatabaseHas('assignments', ['id' => $assignment->id]);
        foreach ($sub['paths'] as $path) {
            $realDisk->assertMissing($path);
        }
        $this->assertSame(1, AuditLog::where('event_type', 'purge_semesters')->count());

        // Retry sweeps the orphans and converges the manifest to zero.
        $failTrashDelete = false;

        $retry = $this->actAs($org['admin'])
            ->postJson('/api/admin/semesters/' . $org['semester']->id . '/purge');
        $retry->assertOk()
            ->assertJsonPath('data.assignments_purged', 0)
            ->assertJsonPath('data.submissions_purged', 0)
            ->assertJsonPath('data.files_purged', 0)
            ->assertJsonPath('data.trash_remaining', []);

        $this->assertNoTrashLeft($realDisk, $org['semester']->id);
        $this->assertSame(2, AuditLog::where('event_type', 'purge_semesters')->count());
    }

    public function test_dry_run_counts_equal_real_run_deletions(): void
    {
        Storage::fake('local');
        $org = $this->seedOrg();

        $assignment = Assignment::create([
            'teacher_id' => $org['teacher']->id,
            'subject_id' => $org['subject']->id,
            'classroom_id' => $org['classroom']->id,
            'semester_id' => $org['semester']->id,
            'title' => 'Assignment A',
            'description' => 'Assignment description.',
            'due_date' => '2026-02-01 23:59:59']);
        $subA = $this->createSubmissionWithFiles($assignment->id, $org['students'][0]->id, ['answer-a.pdf']);
        $subB = $this->createSubmissionWithFiles($assignment->id, $org['students'][1]->id, ['answer-b.pdf', 'extra-b.pdf']);

        $dry = $this->actAs($org['admin'])
            ->postJson('/api/admin/semesters/' . $org['semester']->id . '/purge?dry_run=1');

        $dry->assertOk()
            ->assertJsonPath('data.dry_run', true)
            ->assertJsonPath('data.assignments_purged', 1)
            ->assertJsonPath('data.submissions_purged', 2)
            ->assertJsonPath('data.files_purged', 3)
            ->assertJsonPath('data.trash_remaining', []);

        $this->assertSame(3, SubmissionFile::count());
        $this->assertSame(2, AssignmentSubmission::count());
        foreach (array_merge($subA['paths'], $subB['paths']) as $path) {
            Storage::disk('local')->assertExists($path);
        }
        $this->assertSame(0, AuditLog::where('event_type', 'purge_semesters')->count());

        $real = $this->actAs($org['admin'])
            ->postJson('/api/admin/semesters/' . $org['semester']->id . '/purge');

        $real->assertOk()
            ->assertJsonMissingPath('data.dry_run')
            ->assertJsonPath('data.assignments_purged', $dry->json('data.assignments_purged'))
            ->assertJsonPath('data.submissions_purged', $dry->json('data.submissions_purged'))
            ->assertJsonPath('data.files_purged', $dry->json('data.files_purged'))
            ->assertJsonPath('data.trash_remaining', []);

        $this->assertSame(0, SubmissionFile::count());
        $this->assertSame(0, AssignmentSubmission::count());
        foreach (array_merge($subA['paths'], $subB['paths']) as $path) {
            Storage::disk('local')->assertMissing($path);
        }
        $this->assertNoTrashLeft(Storage::disk('local'), $org['semester']->id);
        $this->assertSame(1, AuditLog::where('event_type', 'purge_semesters')->count());
    }
}
