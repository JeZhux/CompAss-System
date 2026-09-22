<?php

namespace Tests\Unit;

use App\Services\AssessmentService;
use App\Services\AssignmentService;
use App\Services\GradingService;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * F-11 narrowing: the duplicate-INSERT classifiers must require uniqueness
 * evidence. A bare 23000 alone (which some drivers report for FK/NOT-NULL/
 * CHECK failures) must NOT map to a 409 — only 23505, a named-constraint
 * match, or 23000/table-mention accompanied by unique/duplicate wording.
 * No DB I/O: synthetic QueryExceptions via reflection on the private
 * helpers.
 */
#[Group('f-11')]
class SubmitRaceClassifierTest extends TestCase
{
    private static function classify(string $service, string $method, string $message, int|string $code): bool
    {
        $previous = new \RuntimeException($message, (int) $code);
        $e = new QueryException('pgsql', 'insert into t (a) values (1)', [], $previous);
        $method = new \ReflectionMethod($service, $method);
        $method->setAccessible(true);

        return $method->invoke(null, $e);
    }

    public static function classifierProvider(): array
    {
        return [
            [AssignmentService::class, 'isDuplicateSubmissionError'],
            [AssessmentService::class, 'isDuplicateSubmissionError'],
            [GradingService::class, 'isDuplicateAttemptError'],
        ];
    }

    #[DataProvider('classifierProvider')]
    public function test_bare_23000_foreign_key_is_not_duplicate(string $service, string $method): void
    {
        $this->assertFalse(self::classify(
            $service,
            $method,
            'SQLSTATE[23000]: Integrity constraint violation: 19 FOREIGN KEY constraint failed',
            23000
        ));
    }

    #[DataProvider('classifierProvider')]
    public function test_bare_23000_check_is_not_duplicate(string $service, string $method): void
    {
        $this->assertFalse(self::classify(
            $service,
            $method,
            'SQLSTATE[23000]: Integrity constraint violation: 19 CHECK constraint failed: chk_score_non_negative',
            23000
        ));
    }

    #[DataProvider('classifierProvider')]
    public function test_23000_with_unique_wording_is_duplicate(string $service, string $method): void
    {
        $this->assertTrue(self::classify(
            $service,
            $method,
            'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: t.a',
            23000
        ));
    }

    #[DataProvider('classifierProvider')]
    public function test_23505_is_duplicate(string $service, string $method): void
    {
        $this->assertTrue(self::classify(
            $service,
            $method,
            'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "t_a_unique"',
            23505
        ));
    }

    public function test_assignment_named_constraint_is_duplicate(): void
    {
        $this->assertTrue(self::classify(
            AssignmentService::class,
            'isDuplicateSubmissionError',
            'duplicate key value violates unique constraint "assignment_submissions_assignment_id_student_id_unique"',
            0
        ));
    }

    public function test_assessment_named_constraint_is_duplicate(): void
    {
        $this->assertTrue(self::classify(
            AssessmentService::class,
            'isDuplicateSubmissionError',
            'duplicate key value violates unique constraint "assessment_submissions_attempt_id_unique"',
            0
        ));
    }

    public function test_grading_named_constraint_is_duplicate(): void
    {
        $this->assertTrue(self::classify(
            GradingService::class,
            'isDuplicateAttemptError',
            'duplicate key value violates unique constraint "assessment_attempts_assessment_id_student_id_attempt_number_unique"',
            0
        ));
    }
}
