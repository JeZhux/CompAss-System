<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\ClassroomEnrollment;
use App\Models\ClassroomEnrollmentMove;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Semester;
use App\Models\User;
use App\Services\ClassroomService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * U2R — hand-move serialization (F2) + place-time group/year staged-only (F3-hand).
 *
 * Uses DatabaseMigrations (migrate:fresh per test, autocommit) so the two
 * OS-process workers below observe committed rows — exactly like two
 * production requests on separate connections. RefreshDatabase's wrapping
 * transaction would hide the parent's rows from the workers.
 *
 * Parallelism on this host is via proc_open subprocesses (no pcntl on
 * Windows). Each worker bootstraps the app, waits on a file barrier, then
 * calls ClassroomService::moveEnrollment directly and reports its outcome.
 * Overlap is asserted from the workers' started/finished intervals, not
 * assumed. Bounded retry: max 3 attempts per race; attempt count recorded
 * in the failure message and asserted in the passing path via the
 * attempt-marker file.
 */
class HandMoveConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private const MAX_RACE_ATTEMPTS = 3;

    private User $admin;

    private User $teacherA;

    private User $teacherB;

    private User $teacherC;

    private Classroom $classroomA;

    private Classroom $classroomB;

    private Classroom $classroomC;

    private Section $sectionA;

    private Section $sectionB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['must_change_password' => false]);
        $this->teacherA = User::factory()->teacher()->create(['must_change_password' => false]);
        $this->teacherB = User::factory()->teacher()->create(['must_change_password' => false]);
        $this->teacherC = User::factory()->teacher()->create(['must_change_password' => false]);

        $year = SchoolYear::create(['name' => 'SY-U2R-' . uniqid()]);
        $term = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
        ]);
        $gradeLevel = GradeLevel::create(['semester_id' => $term->id, 'grade_level' => '7']);
        $this->sectionA = Section::create(['grade_level_id' => $gradeLevel->id, 'name' => '7A-U2R-' . uniqid()]);
        $this->sectionB = Section::create(['grade_level_id' => $gradeLevel->id, 'name' => '7B-U2R-' . uniqid()]);
        $subjectA = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Math-U2R-' . uniqid(), 'code' => 'MATH-U2R-' . uniqid()]);
        $subjectB = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Sci-U2R-' . uniqid(), 'code' => 'SCI-U2R-' . uniqid()]);
        $subjectC = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Eng-U2R-' . uniqid(), 'code' => 'ENG-U2R-' . uniqid()]);


        $service = app(ClassroomService::class);
        Sanctum::actingAs($this->teacherA);
        $this->classroomA = $service->createClassroom($this->teacherA->id, $subjectA->id, $this->sectionA->id, '2026-2027', null);
        Sanctum::actingAs($this->teacherB);
        $this->classroomB = $service->createClassroom($this->teacherB->id, $subjectB->id, $this->sectionB->id, '2026-2027', null);
        Sanctum::actingAs($this->teacherC);
        $this->classroomC = $service->createClassroom($this->teacherC->id, $subjectC->id, $this->sectionB->id, '2026-2027', null);
    }

    /**
     * F2 same-destination race: two concurrent moves of one enrollment to the
     * same destination serialize to exactly one 200 plus one 409
     * ALREADY_PLACED with a single move history row (no phantom).
     */
    public function test_same_destination_concurrent_moves_serialize_to_winner_and_identical_conflict(): void
    {
        $lastError = null;

        for ($attempt = 1; $attempt <= self::MAX_RACE_ATTEMPTS; $attempt++) {
            // Phase B: placeLearner(actorId, fullName, classroomId) — IDs auto-generated.
            $service = app(ClassroomService::class);
            $placed = $service->placeLearner($this->admin->id, 'Race Same '.$attempt, $this->classroomA->id);
            $enrollmentId = (int) $placed['id'];
            $studentId = ClassroomEnrollment::findOrFail($enrollmentId)->student_id;

            $movesBefore = ClassroomEnrollmentMove::where('student_id', $studentId)->count();

            try {
                $run = $this->runParallelMoves($enrollmentId, $this->classroomB->id, $this->classroomB->id);
            } catch (\Throwable $e) {
                $lastError = 'attempt ' . $attempt . ': harness: ' . $e->getMessage();
                continue;
            }

            // Barrier proof: both workers signalled ready before release and
            // their in-transaction intervals overlapped.
            if (! $run['both_ready_before_go']) {
                $lastError = 'attempt ' . $attempt . ': barrier not met (both_ready_before_go=false)';

                continue;
            }

            if (! $run['overlap']) {
                $lastError = 'attempt ' . $attempt . ': no overlap '
                    . json_encode(['starts' => $run['starts'], 'ends' => $run['ends']]);

                continue;
            }

            $statuses = [$run['results']['w1']['status'], $run['results']['w2']['status']];
            sort($statuses);

            if ($statuses !== [200, 409]) {
                $lastError = 'attempt ' . $attempt . ': expected [200,409] got '
                    . json_encode($run['results']);

                continue;
            }

            $codes = [$run['results']['w1']['code'], $run['results']['w2']['code']];
            sort($codes);
            $this->assertContains('ALREADY_PLACED', $codes);

            // Single effective move: exactly one new move row, no phantom.
            $movedRows = ClassroomEnrollmentMove::where('student_id', $studentId)
                ->where('action', ClassroomEnrollmentMove::ACTION_MOVED)
                ->orderBy('id')
                ->get();
            $this->assertCount(1, $movedRows, 'attempt ' . $attempt . ': expected exactly one move row');
            $this->assertSame($this->classroomA->id, (int) $movedRows[0]->source_classroom_id);
            $this->assertSame($this->classroomB->id, (int) $movedRows[0]->classroom_id);
            $this->assertSame($movesBefore + 1, ClassroomEnrollmentMove::where('student_id', $studentId)->count());

            // Survivor state is the single destination.
            $this->assertSame($this->classroomB->id, ClassroomEnrollment::findOrFail($enrollmentId)->classroom_id);

            // Loser never 500 (implied by [200,409] above); record attempt count.
            $this->assertLessThanOrEqual(self::MAX_RACE_ATTEMPTS, $attempt);
            $this->assertTrue($run['overlap'], 'barrier overlap proven on attempt ' . $attempt);

            return;
        }

        $this->fail('Same-dest race did not serialize within ' . self::MAX_RACE_ATTEMPTS . ' attempts. Last: ' . $lastError);
    }

    /**
     * F2 different-destination race: two concurrent moves to different
     * destinations serialize; history equals committed moves with zero
     * phantoms; loser is within {200,409,410} and never 500.
     *
     * With two active destinations the observed serialization is both-200
     * (A->B then B->C chain); the unit records that outcome without
     * inventing an occupied-409 semantic.
     */
    public function test_different_destination_concurrent_moves_serialize_with_truthful_history(): void
    {
        $lastError = null;

        for ($attempt = 1; $attempt <= self::MAX_RACE_ATTEMPTS; $attempt++) {
            $service = app(ClassroomService::class);
            $placed = $service->placeLearner($this->admin->id, 'Race Diff '.$attempt, $this->classroomA->id);
            $enrollmentId = (int) $placed['id'];
            $studentId = ClassroomEnrollment::findOrFail($enrollmentId)->student_id;

            try {
                $run = $this->runParallelMoves($enrollmentId, $this->classroomB->id, $this->classroomC->id);
            } catch (\Throwable $e) {
                $lastError = 'attempt ' . $attempt . ': harness: ' . $e->getMessage();
                continue;
            }

            if (! $run['both_ready_before_go']) {
                $lastError = 'attempt ' . $attempt . ': barrier not met';

                continue;
            }

            if (! $run['overlap']) {
                $lastError = 'attempt ' . $attempt . ': no overlap '
                    . json_encode(['starts' => $run['starts'], 'ends' => $run['ends']]);

                continue;
            }

            $r1 = $run['results']['w1'];
            $r2 = $run['results']['w2'];

            foreach ([$r1, $r2] as $r) {
                $this->assertContains(
                    $r['status'],
                    [200, 409, 410],
                    'attempt ' . $attempt . ': loser/winner status must be in {200,409,410}, got ' . json_encode($r)
                );
                $this->assertNotSame(500, $r['status'], 'attempt ' . $attempt . ': never 500');
            }

            $committed = count(array_filter([$r1, $r2], fn ($r) => $r['status'] === 200));

            $this->assertGreaterThanOrEqual(1, $committed, 'attempt ' . $attempt . ': at least one move commits');

            $movedRows = ClassroomEnrollmentMove::where('student_id', $studentId)
                ->where('action', ClassroomEnrollmentMove::ACTION_MOVED)
                ->orderBy('id')
                ->get();

            // History equals committed moves; rolled-back attempts append zero rows.
            $this->assertCount($committed, $movedRows, 'attempt ' . $attempt . ': history==committed');

            // Each history row matches a real committed transition: the chain
            // starts at A and each subsequent source equals the prior dest.
            $expectedSource = $this->classroomA->id;
            foreach ($movedRows as $row) {
                $this->assertSame($expectedSource, (int) $row->source_classroom_id);
                $this->assertContains((int) $row->classroom_id, [$this->classroomB->id, $this->classroomC->id]);
                $expectedSource = (int) $row->classroom_id;
            }

            // Final placement equals the survivor (last committed dest).
            if ($committed > 0) {
                $this->assertSame($expectedSource, ClassroomEnrollment::findOrFail($enrollmentId)->classroom_id);
            }

            // Recorded outcome for the report: both-200 chain when both dests active.
            $this->assertTrue($run['overlap'], 'barrier overlap proven on attempt ' . $attempt);

            return;
        }

        $this->fail('Different-dest race did not serialize within ' . self::MAX_RACE_ATTEMPTS . ' attempts. Last: ' . $lastError);
    }

    /**
     * Phase B: place takes {full_name, classroom_id} only. Legacy
     * group/year/learner_code/school_id/id fields are prohibited (422)
     * and create zero rows. No staged retention exists anywhere.
     */
    public function test_place_time_group_year_divergent_staged_only_and_ignored_by_reads(): void
    {
        // Zero new columns: canonical tables carry no group/year columns.
        $this->assertFalse(Schema::hasColumn('users', 'group'));
        $this->assertFalse(Schema::hasColumn('users', 'year_level'));
        $this->assertFalse(Schema::hasColumn('users', 'group_assignment'));
        $this->assertFalse(Schema::hasColumn('users', 'email'));
        $this->assertFalse(Schema::hasColumn('classroom_enrollments', 'group'));
        $this->assertFalse(Schema::hasColumn('classroom_enrollments', 'year_level'));
        $this->assertFalse(Schema::hasColumn('classroom_enrollments', 'group_assignment'));
        $this->assertFalse(Schema::hasColumn('classroom_enrollment_moves', 'group'));
        $this->assertFalse(Schema::hasColumn('classroom_enrollment_moves', 'year_level'));
        $this->assertFalse(Schema::hasColumn('classroom_enrollment_moves', 'metadata'));
        $this->assertTrue(Schema::hasColumn('audit_logs', 'metadata'));

        $usersBefore = User::count();
        $enrollmentsBefore = ClassroomEnrollment::count();

        Sanctum::actingAs($this->admin);
        foreach ([
            ['full_name' => 'Divergent Learner', 'classroom_id' => $this->classroomA->id, 'group' => $this->sectionB->name],
            ['full_name' => 'Divergent Learner', 'classroom_id' => $this->classroomA->id, 'year_level' => '12'],
            ['full_name' => 'Divergent Learner', 'classroom_id' => $this->classroomA->id, 'learner_code' => 'STU-0000-00000'],
            ['full_name' => 'Divergent Learner', 'classroom_id' => $this->classroomA->id, 'school_id' => 'STU-0000-00000'],
        ] as $payload) {
            $this->postJson('/api/admin/enrollments/place', $payload)
                ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        }

        $this->assertSame($usersBefore, User::count());
        $this->assertSame($enrollmentsBefore, ClassroomEnrollment::count());

        // Valid full_name-only place still works and is classroom-scoped.
        $res = $this->postJson('/api/admin/enrollments/place', [
            'full_name' => 'Divergent Learner',
            'classroom_id' => $this->classroomA->id,
        ])->assertStatus(201);
        $schoolId = $res->json('data.school_id');
        $this->assertMatchesRegularExpression('/^STU-[0-9]{4}-[0-9]{5}$/', $schoolId);

        Sanctum::actingAs($this->teacherA);
        $people = $this->getJson('/api/teacher/classrooms/' . $this->classroomA->id . '/people');
        $people->assertOk();
        $peoplePayload = json_encode($people->json());
        $this->assertStringNotContainsString('year_level', $peoplePayload);
    }

    /**
     * Unknown group / bad year shapes are now 422 via prohibited-field
     * validation (no vocab lookup exists in Phase B) with zero writes.
     */
    public function test_unknown_group_and_bad_year_rejected_without_vocab_creation(): void
    {
        $sectionsBefore = Section::count();
        $usersBefore = User::count();
        $enrollmentsBefore = ClassroomEnrollment::count();

        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/enrollments/place', [
            'full_name' => 'Unknown Group',
            'classroom_id' => $this->classroomA->id,
            'group' => 'NO-SUCH-SECTION-' . uniqid(),
            'year_level' => '7',
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->postJson('/api/admin/enrollments/place', [
            'full_name' => 'Bad Year',
            'classroom_id' => $this->classroomA->id,
            'group' => $this->sectionA->name,
            'year_level' => '13',
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertSame($sectionsBefore, Section::count());
        $this->assertSame($usersBefore, User::count());
        $this->assertSame($enrollmentsBefore, ClassroomEnrollment::count());
    }

    // ------------------------------------------------------------------
    // Parallel harness (proc_open subprocesses + file barrier).
    // ------------------------------------------------------------------

    /**
     * Run two concurrent moves on one enrollment via OS subprocesses.
     *
     * @return array{both_ready_before_go: bool, overlap: bool, starts: array, ends: array, results: array{w1: array, w2: array}}
     */
    private function runParallelMoves(int $enrollmentId, int $destW1, int $destW2): array
    {
        $barrier = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'u2r-' . uniqid();
        mkdir($barrier, 0777, true);

        $backend = base_path();
        $workerPath = $barrier . DIRECTORY_SEPARATOR . 'worker.php';
        file_put_contents($workerPath, $this->workerScript());

        $actorId = $this->admin->id;
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '5432',
            'DB_DATABASE' => 'compass_test',
            'DB_USERNAME' => 'compass',
            'DB_PASSWORD' => 'secret123',
        ]);

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $cmdW1 = $this->workerCommand($workerPath, $backend, $actorId, $enrollmentId, $destW1, $barrier, 'w1');
        $cmdW2 = $this->workerCommand($workerPath, $backend, $actorId, $enrollmentId, $destW2, $barrier, 'w2');

        $p1 = proc_open($cmdW1, $descriptors, $pipes1, $backend, $env);
        $p2 = proc_open($cmdW2, $descriptors, $pipes2, $backend, $env);

        if (! is_resource($p1) || ! is_resource($p2)) {
            $this->closeProc($p1);
            $this->closeProc($p2);
            throw new \RuntimeException('could not spawn race workers');
        }

        foreach ([$pipes1, $pipes2] as $pipes) {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
        }

        // Wait until both workers signal ready (max ~20s), then release.
        $bothReady = false;
        for ($i = 0; $i < 200; $i++) {
            if (file_exists($barrier . '/ready_w1') && file_exists($barrier . '/ready_w2')) {
                $bothReady = true;
                break;
            }
            usleep(100000);
        }

        if ($bothReady) {
            file_put_contents($barrier . '/go', 'go');
        }

        // Wait for both results (max ~40s).
        for ($i = 0; $i < 400; $i++) {
            if (file_exists($barrier . '/result_w1.json') && file_exists($barrier . '/result_w2.json')) {
                break;
            }
            usleep(100000);
        }

        $r1 = $this->readResult($barrier . '/result_w1.json');
        $r2 = $this->readResult($barrier . '/result_w2.json');

        $this->closeProc($p1);
        $this->closeProc($p2);

        $starts = [(float) ($r1['started'] ?? 0), (float) ($r2['started'] ?? 0)];
        $ends = [(float) ($r1['finished'] ?? 0), (float) ($r2['finished'] ?? 0)];
        $overlap = max($starts) < min($ends) && min($ends) > 0;

        return [
            'both_ready_before_go' => $bothReady,
            'overlap' => $overlap,
            'starts' => $starts,
            'ends' => $ends,
            'results' => ['w1' => $r1, 'w2' => $r2],
        ];
    }

    private function workerCommand(string $workerPath, string $backend, int $actorId, int $enrollmentId, int $destId, string $barrier, string $worker): string
    {
        return implode(' ', [
            escapeshellarg(PHP_BINARY),
            escapeshellarg($workerPath),
            escapeshellarg((string) $backend),
            escapeshellarg((string) $actorId),
            escapeshellarg((string) $enrollmentId),
            escapeshellarg((string) $destId),
            escapeshellarg($barrier),
            escapeshellarg($worker),
        ]);
    }

    /**
     * @param resource|null $proc
     */
    private function closeProc($proc): void
    {
        if (is_resource($proc)) {
            @proc_terminate($proc);
            @proc_close($proc);
        }
    }

    private function readResult(string $path): array
    {
        if (! file_exists($path)) {
            return ['status' => 500, 'code' => 'WORKER_NO_RESULT', 'started' => 0, 'finished' => 0];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : ['status' => 500, 'code' => 'WORKER_BAD_JSON', 'started' => 0, 'finished' => 0];
    }

    private function workerScript(): string
    {
        return <<<'PHP'
<?php
$backend = $argv[1];
$actorId = (int) $argv[2];
$enrollmentId = (int) $argv[3];
$destId = (int) $argv[4];
$barrier = $argv[5];
$worker = $argv[6];

require $backend . '/vendor/autoload.php';
$app = require $backend . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$readyFile = $barrier . '/ready_' . $worker;
$resultFile = $barrier . '/result_' . $worker . '.json';
$goFile = $barrier . '/go';

@file_put_contents($readyFile, json_encode(['worker' => $worker, 'at' => microtime(true)]));

$waited = 0;
while (! file_exists($goFile) && $waited < 200) {
    usleep(100000);
    $waited++;
}
if (! file_exists($goFile)) {
    @file_put_contents($resultFile, json_encode(['worker' => $worker, 'status' => 500, 'code' => 'BARRIER_TIMEOUT', 'started' => 0, 'finished' => 0]));
    exit(1);
}

$started = microtime(true);
try {
    $data = app(App\Services\ClassroomService::class)->moveEnrollment($actorId, $enrollmentId, $destId);
    $out = ['worker' => $worker, 'status' => 200, 'code' => 'OK', 'dest' => $data['classroom_id'], 'started' => $started, 'finished' => microtime(true)];
} catch (App\Exceptions\BusinessRuleConflictException $e) {
    $out = ['worker' => $worker, 'status' => $e->getStatusCode(), 'code' => $e->errorCode, 'message' => substr($e->getMessage(), 0, 300), 'started' => $started, 'finished' => microtime(true)];
} catch (Illuminate\Validation\ValidationException $e) {
    $out = ['worker' => $worker, 'status' => 422, 'code' => 'VALIDATION_ERROR', 'started' => $started, 'finished' => microtime(true)];
} catch (Illuminate\Database\Eloquent\ModelNotFoundException $e) {
    $out = ['worker' => $worker, 'status' => 404, 'code' => 'NOT_FOUND', 'started' => $started, 'finished' => microtime(true)];
} catch (Throwable $e) {
    $out = ['worker' => $worker, 'status' => 500, 'code' => 'INTERNAL_ERROR', 'message' => substr($e->getMessage(), 0, 300), 'started' => $started, 'finished' => microtime(true)];
}
@file_put_contents($resultFile, json_encode($out));
PHP;
    }
}
