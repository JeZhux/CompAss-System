<?php

namespace App\Services;

use App\Exceptions\BusinessRuleConflictException;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentItem;
use App\Models\AssessmentResponse;
use App\Models\Classroom;
use App\Models\ClassroomEnrollment;
use App\Models\GradeEntry;
use App\Models\MasteryRecord;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Manual grading orchestration: recordManualGrade, saveGradeDraft,
 * regradeAttempt, mastery-stub queries, requestResubmission, bulkGrade.
 *
 * ARCH-001 §5.1 — GradingService invariants:
 *  - recordManualGrade MUST fail if attempt status is not 'pending_grading' (409).
 *  - All scores MUST be ≤ max_score (validated per GradeEntry).
 *  - Draft grades (is_draft = TRUE) MUST touch grade_entries only and MUST NOT
 *    trigger mastery recomputation.
 *  - requestResubmission MUST fail if student already has an in_progress attempt
 *    for the same assessment (409).
 *  - Bulk grade is NOT transactional across students — partial success is
 *    possible and reported.
 *  - ARCH-002 FR-018: recordManualGrade/saveGradeDraft/regradeAttempt reject items
 *    that do not belong to the attempt's assessment (ITEM_NOT_IN_ASSESSMENT)
 *    and non-essay items (NOT_A_SUBJECTIVE_ITEM); the manual/draft paths also
 *    reject already-scored responses (RESPONSE_ALREADY_SCORED) — regrade is
 *    the only overwrite path (ARCH-002 FR-020).
 *
 * Phase 4/Phase 5 transition: stubComputeMastery() calls in recordManualGrade
 * and regradeAttempt have been replaced by real
 * CompetencyMappingService.computeMastery() invocations (Phase 5). The former
 * stub methods (getMasteryRecord, getStudentMasterySummary,
 * computeMasteryLevel) now delegate to CompetencyMappingService.
 *
 * @Traced-To ARCH-002 FR-018, ARCH-002 FR-018, ARCH-002 FR-020, ARCH-002 FR-020, ARCH-002 FR-020, ARCH-002 FR-018, ARCH-002 FR-019, ARCH-002 FR-020
 * @Traced-To ARCH-002 QA-007, ARCH-002 QA-002, ARCH-002 QA-009 (ARCH-001 §5.1, ARCH-004 §4.1)
 */
class GradingService
{
    public function __construct(private readonly CompetencyMappingService $competencyMappingService)
    {
    }

    /**
     * F-05: ceiling on students per bulk grade request. Mirrors the
     * BulkGradeRequest `max:100` gate; enforced here as defense-in-depth for
     * direct service calls (422 before any grade write).
     */
    public const MAX_BULK_STUDENTS = 100;

    /**
     * F-05: ceiling on grade entries per student within one bulk request.
     * Mirrors the BulkGradeRequest per-student `max:100` gate (422 before any
     * grade write).
     */
    public const MAX_GRADE_ENTRIES_PER_STUDENT = 100;

    /*
    |--------------------------------------------------------------------------
    | Finalize (authoritative write-through)
    |--------------------------------------------------------------------------
    */

    /**
     * Record a manual grade for an attempt's subjective questions — finalize.
     *
     * Validates attempt is in `pending_grading` status and teacher owns the
     * assessment, validates all scores ≤ max_score. On finalize performs a
     * single write-through in one transaction:
     *  - Writes the canonical response score to assessment_responses.earned_points
     *  - Writes to grade_entries.score (authoritative ledger)
     *  - Sets grader_id, graded_at on the attempt
     *  - Transitions attempt status to 'scored'
     *  - Sets linked submission.status = 'scored'
     *  - Triggers mastery recomputation (Phase 5 stub now)
     *
     * @param  array<int, array{questionId: int, score: float, maxScore: float, feedback?: string|null}>  $grades
     * @return array{attempt_id: int, grader_id: int, graded_at: string,
     *     grade_count: int, status: string, mastery_results?: array}
     *
     * @throws ModelNotFoundException 404 — attempt not found
     * @throws AuthorizationException 403 — teacher does not own the assessment
     * @throws BusinessRuleConflictException 409 — attempt not in pending_grading
     * @throws BusinessRuleConflictException 409 ASSESSMENT_NOT_CLASSROOM_SCOPED — assessment has no classroom_id
     * @throws ValidationException 422 — score exceeds max_score
     *
     * @Traced-To ARCH-002 FR-018, ARCH-002 FR-018, ARCH-002 FR-019, ARCH-002 QA-007, ARCH-002 FR-018 (ARCH-001 §5.1)
     */
    public function recordManualGrade(int $attemptId, array $questionGrades, int $graderId): array
    {
        $attempt = AssessmentAttempt::findOrFail($attemptId);

        if ($attempt->status !== 'pending_grading') {
            throw new BusinessRuleConflictException(
                'Only attempts in pending_grading status can be graded.',
                'NOT_PENDING_GRADING'
            );
        }

        $attempt->load('assessment');

        if ($attempt->assessment->teacher_id !== $graderId) {
            throw new AuthorizationException();
        }

        // U-05: grade-via-classroom invariant — no grade without a
        // classroom-scoped assessment. Before any GradeEntry write so a
        // rejection leaves zero ledger/mastery rows. Bulk inherits via its
        // per-student delegation to recordManualGrade.
        if ($attempt->assessment->classroom_id === null) {
            throw new BusinessRuleConflictException(
                'Assessment '.$attempt->assessment->id.' is not classroom-scoped; grading requires a classroom assessment.',
                'ASSESSMENT_NOT_CLASSROOM_SCOPED'
            );
        }

        $now = now();

        return DB::transaction(function () use ($attempt, $questionGrades, $graderId, $now): array {
            // F-11: re-check the attempt under a row lock. A concurrent
            // grade that won the race has already flipped status away from
            // pending_grading; the loser degrades to the documented
            // NOT_PENDING_GRADING outcome without a second ledger/mastery
            // write. Only IDs are logged.
            $locked = AssessmentAttempt::whereKey($attempt->id)->lockForUpdate()->first();

            if ($locked === null) {
                throw new ModelNotFoundException('Submission not found for attempt.');
            }

            if ($locked->status !== 'pending_grading') {
                Log::warning('GradingService::recordManualGrade duplicate suppressed', [
                    'attempt_id' => $attempt->id,
                ]);

                throw new BusinessRuleConflictException(
                    'Only attempts in pending_grading status can be graded.',
                    'NOT_PENDING_GRADING'
                );
            }

            $attempt = $locked;
            $attempt->load('assessment');

            $submission = $attempt->submission()->first();

            if (! $submission) {
                throw new ModelNotFoundException('Submission not found for attempt.');
            }

            // Validate scores ≤ max_score for each GradeEntry (UC-50 step 5).
            $items = AssessmentItem::whereIn('id', Arr::pluck($questionGrades, 'questionId'))
                ->get()
                ->keyBy('id');

            // ARCH-002 FR-018: scored-state map for the already-scored guard — a
            // response whose earned_points is non-NULL is already scored.
            $responses = AssessmentResponse::query()
                ->where('submission_id', $submission->id)
                ->whereIn('item_id', Arr::pluck($questionGrades, 'questionId'))
                ->get()
                ->keyBy('item_id');

            foreach ($questionGrades as $grade) {
                $questionId = $grade['questionId'];
                $item = $items[$questionId] ?? null;

                if (! $item) {
                    throw new ModelNotFoundException('Assessment item not found: ' . $questionId);
                }

                // ARCH-002 FR-018: item→assessment membership — no orphan grade_entries.
                if ($item->assessment_id !== $attempt->assessment_id) {
                    throw new BusinessRuleConflictException(
                        'Item ' . $item->id . ' does not belong to this attempt\'s assessment.',
                        'ITEM_NOT_IN_ASSESSMENT'
                    );
                }

                // ARCH-002 FR-018: essay-only grading path (REQ-17 "essay-only wrapper").
                if ($item->item_type !== 'essay') {
                    throw new BusinessRuleConflictException(
                        'Only subjective (essay) items can be graded; item ' . $item->id . ' is not an essay.',
                        'NOT_A_SUBJECTIVE_ITEM'
                    );
                }

                // ARCH-002 FR-018: no double-grade — the non-regrade path must not
                // overwrite an already-scored response.
                $response = $responses[$questionId] ?? null;

                if ($response && $response->earned_points !== null) {
                    throw new BusinessRuleConflictException(
                        'Item ' . $item->id . ' has already been scored for this submission.',
                        'RESPONSE_ALREADY_SCORED'
                    );
                }

                // ARCH-004 §4.1: service-layer lower bound. Zero is LEGAL (Phase 3
                // ARCH-004 §4.1 needs it); only negative scores are rejected. The DB
                // CHECK is score >= 0 (ARCH-004 §4.1 prose "negative/zero" is
                // imprecise; decision recorded in open-questions).
                if ((float) $grade['score'] < 0) {
                    throw ValidationException::withMessages([
                        "grade_entries.{$questionId}.score" => [
                            'The score for item ' . $item->id . ' may not be negative.',
                        ],
                    ]);
                }

                if ((float) $grade['score'] > (float) $item->max_points) {
                    throw ValidationException::withMessages([
                        "grade_entries.{$questionId}.score" => [
                            'The score for item ' . $item->id . ' may not be greater than ' . $item->max_points . '.',
                        ],
                    ]);
                }
            }

            // Write grade_entries (authoritative ledger). Upsert on unique(submission_id, item_id).
            foreach ($questionGrades as $grade) {
                GradeEntry::updateOrCreate(
                    [
                        'assessment_submission_id' => $submission->id,
                        'assessment_item_id' => $grade['questionId'],
                    ],
                    [
                        'score' => (float) $grade['score'],
                        'max_score' => (float) $grade['maxScore'],
                        'feedback' => $grade['feedback'] ?? null,
                        'graded_by' => $graderId,
                        'graded_at' => $now,
                        'is_draft' => false,
                    ]
                );
            }

            // Write canonical earned_points to assessment_responses (UC-50 step 6/7).
            foreach ($questionGrades as $grade) {
                AssessmentResponse::query()
                    ->where('submission_id', $submission->id)
                    ->where('item_id', $grade['questionId'])
                    ->update([
                        'earned_points' => (float) $grade['score'],
                        'is_auto_scored' => false,
                        'scored_by_teacher_id' => $graderId,
                    ]);
            }

            // UC-50 step 7: determine if ALL subjective items are now scored.
            $allSubjectiveItemIds = $attempt->assessment->items()
                ->where('item_type', 'essay')
                ->pluck('id')
                ->all();

            $allScored = true;
            foreach ($allSubjectiveItemIds as $itemId) {
                $response = AssessmentResponse::query()
                    ->where('submission_id', $submission->id)
                    ->where('item_id', $itemId)
                    ->first();

                if ($response && $response->earned_points === null) {
                    $allScored = false;
                    break;
                }
            }

            $result = [
                'attempt_id' => $attempt->id,
                'grader_id' => $graderId,
                'graded_at' => null,
                'grade_count' => count($questionGrades),
                'status' => 'pending_grading',
            ];

            if ($allScored) {
                // Transition attempt → scored (ARCH-002 FR-020, UC-50 step 7).
                $attempt->status = 'scored';
                $attempt->grader_id = $graderId;
                $attempt->graded_at = $now;
                $attempt->save();

                // Transition submission → scored (UC-50 step 7).
                $submission->status = 'scored';
                $submission->save();

                $result['graded_at'] = $now->toIso8601String();
                $result['status'] = 'scored';

                // Trigger mastery recomputation (Phase 5).
                $result['mastery_results'] = $this->competencyMappingService->computeMastery(
                    $submission->id,
                    (int) $attempt->assessment->subject_id
                );
            }

            return $result;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Draft (progressive grading)
    |--------------------------------------------------------------------------
    */

    /**
     * Save grades as a draft (is_draft = true).
     *
     * Touches grade_entries only — earned_points is NOT written to
     * assessment_responses. The attempt remains pending_grading.
     * Allows partial/progressive grading. Does NOT trigger mastery recomputation.
     *
     * @param  array  $questionGrades  See GradeEntry interface (ARCH-001 §5.1)
     * @return array{attempt_id: int, grader_id: int, graded_at: string|null,
     *     grade_count: int, status: string}
     *
     * @throws ModelNotFoundException 404 — attempt or item not found
     * @throws AuthorizationException 403 — not the teacher's assessment
     * @throws BusinessRuleConflictException 409 — attempt not in pending_grading
     * @throws ValidationException 422 — score exceeds max_score
     *
     * @Traced-To ARCH-002 FR-019, UC-50 draft alt flow, ARCH-002 FR-018 (ARCH-001 §5.1)
     */
    public function saveGradeDraft(int $attemptId, array $questionGrades, int $graderId): array
    {
        $attempt = AssessmentAttempt::findOrFail($attemptId);

        if ($attempt->status !== 'pending_grading') {
            throw new BusinessRuleConflictException(
                'Only attempts in pending_grading status can be graded.',
                'NOT_PENDING_GRADING'
            );
        }

        $attempt->load('assessment');

        if ($attempt->assessment->teacher_id !== $graderId) {
            throw new AuthorizationException();
        }

        $now = now();

        return DB::transaction(function () use ($attempt, $questionGrades, $graderId, $now): array {
            $submission = $attempt->submission()->first();

            if (! $submission) {
                throw new ModelNotFoundException('Submission not found for attempt.');
            }

            // Validate scores ≤ max_score.
            $items = AssessmentItem::whereIn('id', Arr::pluck($questionGrades, 'questionId'))
                ->get()
                ->keyBy('id');

            // ARCH-002 FR-018: scored-state map for the already-scored guard.
            $responses = AssessmentResponse::query()
                ->where('submission_id', $submission->id)
                ->whereIn('item_id', Arr::pluck($questionGrades, 'questionId'))
                ->get()
                ->keyBy('item_id');

            foreach ($questionGrades as $grade) {
                $questionId = $grade['questionId'];
                $item = $items[$questionId] ?? null;

                if (! $item) {
                    throw new ModelNotFoundException('Assessment item not found: ' . $questionId);
                }

                // ARCH-002 FR-018: item→assessment membership — no orphan grade_entries.
                if ($item->assessment_id !== $attempt->assessment_id) {
                    throw new BusinessRuleConflictException(
                        'Item ' . $item->id . ' does not belong to this attempt\'s assessment.',
                        'ITEM_NOT_IN_ASSESSMENT'
                    );
                }

                // ARCH-002 FR-018: essay-only grading path (REQ-17 "essay-only wrapper").
                if ($item->item_type !== 'essay') {
                    throw new BusinessRuleConflictException(
                        'Only subjective (essay) items can be graded; item ' . $item->id . ' is not an essay.',
                        'NOT_A_SUBJECTIVE_ITEM'
                    );
                }

                // ARCH-002 FR-018: no double-grade on an already-scored response.
                $response = $responses[$questionId] ?? null;

                if ($response && $response->earned_points !== null) {
                    throw new BusinessRuleConflictException(
                        'Item ' . $item->id . ' has already been scored for this submission.',
                        'RESPONSE_ALREADY_SCORED'
                    );
                }

                // ARCH-004 §4.1: service-layer lower bound (same contract as
                // recordManualGrade). Zero is legal; negative is rejected.
                if ((float) $grade['score'] < 0) {
                    throw ValidationException::withMessages([
                        "grade_entries.{$questionId}.score" => [
                            'The score for item ' . $item->id . ' may not be negative.',
                        ],
                    ]);
                }

                if ((float) $grade['score'] > (float) $item->max_points) {
                    throw ValidationException::withMessages([
                        "grade_entries.{$questionId}.score" => [
                            'The score for item ' . $item->id . ' may not be greater than ' . $item->max_points . '.',
                        ],
                    ]);
                }
            }

            // Write grade_entries only — NOT assessment_responses.
            foreach ($questionGrades as $grade) {
                GradeEntry::updateOrCreate(
                    [
                        'assessment_submission_id' => $submission->id,
                        'assessment_item_id' => $grade['questionId'],
                    ],
                    [
                        'score' => (float) $grade['score'],
                        'max_score' => (float) $grade['maxScore'],
                        'feedback' => $grade['feedback'] ?? null,
                        'graded_by' => $graderId,
                        'graded_at' => $now,
                        'is_draft' => true,
                    ]
                );
            }

            // Attempt remains pending_grading — no mastery trigger.
            return [
                'attempt_id' => $attempt->id,
                'grader_id' => $graderId,
                'graded_at' => null,
                'grade_count' => count($questionGrades),
                'status' => 'pending_grading',
            ];
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Regrade
    |--------------------------------------------------------------------------
    */

    /**
     * Overwrite existing grades for an already-graded attempt.
     * Does NOT use response_history for regrade storage (overwrite, per ARCH-002 FR-020).
     *
     * @param  array<int, array{questionId: int, score: float, maxScore: float, feedback?: string|null}>  $grades
     * @return array{attempt_id: int, grader_id: int, graded_at: string,
     *     grade_count: int, status: string, mastery_results?: array}
     *
     * @throws ModelNotFoundException 404 — attempt or item not found
     * @throws AuthorizationException 403 — not the teacher's assessment
     * @throws BusinessRuleConflictException 409 — attempt not in scored status
     * @throws ValidationException 422 — score exceeds max_score
     *
     * @Traced-To ARCH-002 FR-018, ARCH-002 FR-018, ARCH-002 FR-020, ARCH-002 FR-018 (ARCH-001 §5.1)
     */
    public function regradeAttempt(int $attemptId, array $questionGrades, int $graderId): array
    {
        $attempt = AssessmentAttempt::findOrFail($attemptId);

        if ($attempt->status !== 'scored') {
            throw new BusinessRuleConflictException(
                'Only scored attempts can be regraded.',
                'NOT_SCORED'
            );
        }

        $attempt->load('assessment');

        if ($attempt->assessment->teacher_id !== $graderId) {
            throw new AuthorizationException();
        }

        $now = now();

        return DB::transaction(function () use ($attempt, $questionGrades, $graderId, $now): array {
            $submission = $attempt->submission()->first();

            if (! $submission) {
                throw new ModelNotFoundException('Submission not found for attempt.');
            }

            // Validate scores ≤ max_score.
            $items = AssessmentItem::whereIn('id', Arr::pluck($questionGrades, 'questionId'))
                ->get()
                ->keyBy('id');

            foreach ($questionGrades as $grade) {
                $questionId = $grade['questionId'];
                $item = $items[$questionId] ?? null;

                if (! $item) {
                    throw new ModelNotFoundException('Assessment item not found: ' . $questionId);
                }

                // ARCH-002 FR-018: item→assessment membership — no orphan grade_entries.
                if ($item->assessment_id !== $attempt->assessment_id) {
                    throw new BusinessRuleConflictException(
                        'Item ' . $item->id . ' does not belong to this attempt\'s assessment.',
                        'ITEM_NOT_IN_ASSESSMENT'
                    );
                }

                // ARCH-002 FR-018: essay-only grading path (REQ-17 "essay-only wrapper").
                // No already-scored guard here: regrade overwrites by design
                // (ARCH-002 FR-020) — ARCH-002 FR-018's double-write guard is for the
                // manual/draft paths only.
                if ($item->item_type !== 'essay') {
                    throw new BusinessRuleConflictException(
                        'Only subjective (essay) items can be graded; item ' . $item->id . ' is not an essay.',
                        'NOT_A_SUBJECTIVE_ITEM'
                    );
                }

                // ARCH-004 §4.1: service-layer lower bound (same contract as
                // recordManualGrade). Zero is legal; negative is rejected.
                if ((float) $grade['score'] < 0) {
                    throw ValidationException::withMessages([
                        "grade_entries.{$questionId}.score" => [
                            'The score for item ' . $item->id . ' may not be negative.',
                        ],
                    ]);
                }

                if ((float) $grade['score'] > (float) $item->max_points) {
                    throw ValidationException::withMessages([
                        "grade_entries.{$questionId}.score" => [
                            'The score for item ' . $item->id . ' may not be greater than ' . $item->max_points . '.',
                        ],
                    ]);
                }
            }

            // Overwrite grade_entries.
            foreach ($questionGrades as $grade) {
                GradeEntry::updateOrCreate(
                    [
                        'assessment_submission_id' => $submission->id,
                        'assessment_item_id' => $grade['questionId'],
                    ],
                    [
                        'score' => (float) $grade['score'],
                        'max_score' => (float) $grade['maxScore'],
                        'feedback' => $grade['feedback'] ?? null,
                        'graded_by' => $graderId,
                        'graded_at' => $now,
                        'is_draft' => false,
                    ]
                );
            }

            // Overwrite assessment_responses.earned_points.
            foreach ($questionGrades as $grade) {
                AssessmentResponse::query()
                    ->where('submission_id', $submission->id)
                    ->where('item_id', $grade['questionId'])
                    ->update([
                        'earned_points' => (float) $grade['score'],
                        'is_auto_scored' => false,
                        'scored_by_teacher_id' => $graderId,
                    ]);
            }

            // Refresh grading metadata on the attempt.
            $attempt->grader_id = $graderId;
            $attempt->graded_at = $now;
            $attempt->save();

            $result = [
                'attempt_id' => $attempt->id,
                'grader_id' => $graderId,
                'graded_at' => $now->toIso8601String(),
                'grade_count' => count($questionGrades),
                'status' => 'scored',
            ];

            // Trigger mastery recomputation (Phase 5).
            $result['mastery_results'] = $this->competencyMappingService->computeMastery(
                $submission->id,
                (int) $attempt->assessment->subject_id
            );

            return $result;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Mastery record queries (Phase 5 — delegates to CompetencyMappingService)
    |--------------------------------------------------------------------------
    */

    /**
     * Returns mastery records for a student, optionally filtered by competency
     * and/or subject (ARCH-002 FR-020, ARCH-002 FR-022). Delegates to
     * CompetencyMappingService::getMasteryHistory().
     *
     * @param  int|null  $studentId  When null, uses the authenticated user.
     * @param  int|null  $competencyId  When null, returns all competencies.
     * @param  int|null  $subjectId  When null, returns all subjects.
     * @return array<int, array<string, mixed>>
     *
     * @throws AuthorizationException 403 — teacher/student scoping violated (ARCH-002 QA-004)
     *
     * @Traced-To ARCH-002 FR-020, ARCH-002 FR-022 (ARCH-001 §5.1, ARCH-005 block 4.5 — Phase 5)
     */
    public function getMasteryRecord(int $studentId, ?int $competencyId = null, ?int $subjectId = null): array
    {
        $this->ensureCanViewStudentMastery($studentId, $subjectId);

        return $this->competencyMappingService->getMasteryHistory(
            $studentId,
            $subjectId,
            $competencyId
        );
    }

    /**
     * Returns a student's current mastery summary across all competencies (ARCH-002 FR-020).
     * Queries mastery_records for the most recent Recorded result per competency
     * and aggregates into the summary structure expected by the API (#102).
     *
     * @return array{student_id: int, competencies: array,
     *     overall_level: string, last_updated: string|null}
     *
     * @throws AuthorizationException 403 — teacher/student scoping violated (ARCH-002 QA-004)
     *
     * @Traced-To ARCH-002 FR-020, ARCH-002 FR-020 (ARCH-001 §5.1, ARCH-005 block 4.5 — Phase 5)
     */
    public function getStudentMasterySummary(int $studentId): array
    {
        $this->ensureCanViewStudentMastery($studentId);

        $records = MasteryRecord::query()
            ->where('student_id', $studentId)
            ->orderBy('competency_id')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        // Build per-competency current status (most recent record per competency, ARCH-002 FR-020).
        $currentPerCompetency = [];
        $latestTimestamp = null;

        foreach ($records as $record) {
            $compId = $record->competency_id;
            if (! isset($currentPerCompetency[$compId])) {
                $currentPerCompetency[$compId] = $record;

                if ($latestTimestamp === null || $record->created_at > $latestTimestamp) {
                    $latestTimestamp = $record->created_at;
                }
            }
        }

        $competencies = [];
        foreach ($currentPerCompetency as $compId => $record) {
            $competencies[] = [
                'competency_id' => $compId,
                'competency_code' => $record->competency ? $record->competency->code : null,
                'mastery_percent' => (float) $record->mastery_percent,
                'mastery_status' => $record->mastery_status,
                'last_assessed_at' => $record->created_at?->toIso8601String(),
            ];
        }

        $overallLevel = empty($competencies)
            ? 'Not Yet Computed'
            : (count(array_filter($competencies, fn ($c) => $c['mastery_status'] === 'Not_Mastered')) > 0
                ? 'In Progress'
                : 'Mastered');

        return [
            'student_id' => $studentId,
            'competencies' => $competencies,
            'overall_level' => $overallLevel,
            'last_updated' => $latestTimestamp?->toIso8601String(),
        ];
    }

    /**
     * Enforce role-based ownership scoping for mastery reads (#101/#102,
     * ARCH-002 QA-004): an Admin may view any student; a Student may only view
     * themselves; a Teacher may only view students enrolled via a classroom
     * the teacher owns — and, when a subject-section filter is supplied,
     * via a classroom the teacher owns for that subject-section (ARCH-002 QA-004,
     * ARCH-002 QA-004). A self-request (requester asking about their own id — e.g.
     * #101 without a student_id query, which defaults to the authenticated
     * user) always passes, and a nonexistent student passes the guard so
     * the caller keeps its existing empty-result semantics (#101 with
     * student_id=999999 → 200 []).
     *
     * @throws AuthorizationException 403 — role or ownership scoping violated
     *
     * @Traced-To ARCH-002 QA-004, ARCH-002 QA-004, ARCH-002 QA-004 (ARCH-001 §5.1)
     */
    private function ensureCanViewStudentMastery(int $studentId, ?int $subjectId = null): void
    {
        $user = Auth::user();

        if ($user === null || $user->role === 'Admin') {
            return;
        }

        if ($studentId === (int) $user->id) {
            return;
        }

        if ($user->role === 'Student') {
            throw new AuthorizationException();
        }

        if ($user->role !== 'Teacher') {
            throw new AuthorizationException();
        }

        if (! User::query()->whereKey($studentId)->exists()) {
            return;
        }

        if ($subjectId !== null) {
            $ownsViaClassroom = Classroom::where('teacher_id', $user->id)
                ->where('subject_id', $subjectId)
                ->exists();
            if (! $ownsViaClassroom) {
                throw new AuthorizationException();
            }
        }

        // Classroom-only enrollment proof (U-07): the legacy section-enrollment
        // path is removed. When a subject filter is supplied, enrollment must
        // be via a classroom the teacher owns for that subject — membership
        // in any other classroom is insufficient. Without a filter,
        // enrollment in any classroom the teacher owns suffices.
        if ($subjectId !== null) {
            $scopedClassroomIds = Classroom::where('teacher_id', $user->id)
                ->where('subject_id', $subjectId)
                ->pluck('id')
                ->toArray();

            $enrolled = ! empty($scopedClassroomIds)
                && ClassroomEnrollment::where('student_id', $studentId)
                    ->whereIn('classroom_id', $scopedClassroomIds)
                    ->exists();
        } else {
            $teacherClassroomIds = Classroom::where('teacher_id', $user->id)->pluck('id')->toArray();

            $enrolled = ! empty($teacherClassroomIds)
                && ClassroomEnrollment::where('student_id', $studentId)
                    ->whereIn('classroom_id', $teacherClassroomIds)
                    ->exists();
        }

        if (! $enrolled) {
            throw new AuthorizationException();
        }
    }

    /**
     * Computes the current mastery status for a student-competency pair based
     * on all graded attempts. Delegates to
     * CompetencyMappingService::getCurrentMasteryStatus().
     *
     * @Traced-To ARCH-002 FR-020, ARCH-002 FR-020 (ARCH-001 §5.1, ARCH-005 block 4.5 — Phase 5)
     */
    public function computeMasteryLevel(int $studentId, int $competencyId): array
    {
        $history = $this->competencyMappingService->getMasteryHistory($studentId, null, $competencyId);

        if (empty($history)) {
            return [
                'student_id' => $studentId,
                'competency_id' => $competencyId,
                'mastery_percent' => 0.0,
                'mastery_status' => 'Not_Mastered',
                'last_updated' => null,
            ];
        }

        // getMasteryHistory returns most recent first.
        $latest = $history[0];

        return [
            'student_id' => $studentId,
            'competency_id' => $competencyId,
            'mastery_percent' => (float) $latest['mastery_percent'],
            'mastery_status' => $latest['mastery_status'],
            'last_updated' => $latest['created_at'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Resubmission
    |--------------------------------------------------------------------------
    */

    /**
     * Create a new in_progress attempt for resubmission (UC-52).
     * The old attempt is preserved as scored for audit. The resubmission
     * request state is persisted on the new attempt row (ARCH-002 FR-018):
     * is_resubmission, resubmission_of_attempt_id, resubmission_reason,
     * resubmission_requested_at; started_at stays null until the student
     * starts the attempt (ARCH-004 §4.1).
     *
     * @return array{new_attempt_id: int, old_attempt_id: int, reason: string, created_at: string}
     *
     * @throws ModelNotFoundException 404 — attempt not found
     * @throws AuthorizationException 403 — teacher does not own the assessment
     * @throws BusinessRuleConflictException 409 — old attempt not scored, or
     *                                       student already has an in_progress attempt for the same assessment
     *
     * @Traced-To ARCH-002 FR-018, ARCH-002 FR-018, ARCH-002 FR-020, ARCH-002 FR-018 (ARCH-001 §5.1, UC-52)
     */
    public function requestResubmission(int $attemptId, string $reason, int $teacherId): array
    {
        $attempt = AssessmentAttempt::findOrFail($attemptId);
        $attempt->load('assessment');

        // Validate teacher ownership (403 if not the assessment owner).
        if ($attempt->assessment->teacher_id !== $teacherId) {
            throw new AuthorizationException();
        }

        // Preconditions: old attempt must be scored (UC-52).
        if ($attempt->status !== 'scored') {
            throw new BusinessRuleConflictException(
                'Only scored attempts are eligible for resubmission.',
                'NOT_SCORED'
            );
        }

        // Invariant: student must NOT already have an in_progress attempt
        // for the same assessment (UC-52).
        $existingInProgress = AssessmentAttempt::query()
            ->where('assessment_id', $attempt->assessment_id)
            ->where('student_id', $attempt->student_id)
            ->where('status', 'in_progress')
            ->exists();

        if ($existingInProgress) {
            throw new BusinessRuleConflictException(
                'The student already has an in-progress attempt for this assessment.',
                'STUDENT_HAS_ACTIVE_ATTEMPT'
            );
        }

        $createdAt = now();

        $newAttempt = DB::transaction(function () use ($attempt, $reason, $createdAt): AssessmentAttempt {
            // F-11: serialize concurrent resubmissions on the parent
            // assessment row (a phantom-safe mutex — locking only the
            // in_progress subset cannot block a not-yet-existing row), then
            // re-check. The loser degrades to STUDENT_HAS_ACTIVE_ATTEMPT
            // without writing a second attempt. Only IDs are logged. The
            // pre-existing UNIQUE(assessment_id, student_id, attempt_number)
            // remains the DB backstop for a same-number collision.
            Assessment::whereKey($attempt->assessment_id)->lockForUpdate()->first();

            $conflict = AssessmentAttempt::query()
                ->where('assessment_id', $attempt->assessment_id)
                ->where('student_id', $attempt->student_id)
                ->where('status', 'in_progress')
                ->lockForUpdate()
                ->exists();

            if ($conflict) {
                Log::warning('GradingService::requestResubmission duplicate suppressed', [
                    'assessment_id' => $attempt->assessment_id,
                    'student_id' => $attempt->student_id,
                ]);

                throw new BusinessRuleConflictException(
                    'The student already has an in-progress attempt for this assessment.',
                    'STUDENT_HAS_ACTIVE_ATTEMPT'
                );
            }

            $nextAttemptNumber = (int) AssessmentAttempt::query()
                ->where('assessment_id', $attempt->assessment_id)
                ->where('student_id', $attempt->student_id)
                ->max('attempt_number') + 1;

            try {
                return AssessmentAttempt::create([
                    'assessment_id' => $attempt->assessment_id,
                    'student_id' => $attempt->student_id,
                    'attempt_number' => $nextAttemptNumber,
                    'status' => 'in_progress',
                    'response_history' => [],
                    'is_resubmission' => true,
                    'resubmission_of_attempt_id' => $attempt->id,
                    'resubmission_reason' => $reason,
                    'resubmission_requested_at' => $createdAt,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                Log::warning('GradingService::requestResubmission duplicate suppressed', [
                    'assessment_id' => $attempt->assessment_id,
                    'student_id' => $attempt->student_id,
                ]);

                throw new BusinessRuleConflictException(
                    'The student already has an in-progress attempt for this assessment.',
                    'STUDENT_HAS_ACTIVE_ATTEMPT'
                );
            } catch (QueryException $e) {
                if (! self::isDuplicateAttemptError($e)) {
                    throw $e;
                }

                Log::warning('GradingService::requestResubmission duplicate suppressed', [
                    'assessment_id' => $attempt->assessment_id,
                    'student_id' => $attempt->student_id,
                ]);

                throw new BusinessRuleConflictException(
                    'The student already has an in-progress attempt for this assessment.',
                    'STUDENT_HAS_ACTIVE_ATTEMPT'
                );
            }
        });

        return [
            'new_attempt_id' => $newAttempt->id,
            'old_attempt_id' => $attempt->id,
            'reason' => $reason,
            'created_at' => $createdAt->toIso8601String(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Bulk Grading
    |--------------------------------------------------------------------------
    */

    /**
     * Grade multiple student submissions for the same assessment.
     *
     * NOT transactional across students — partial success is possible.
     * Each student's recordManualGrade call is wrapped in its own transaction;
     * failures are collected per-student.
     *
     * @param  array  $grades  See BulkGradeEntry[] (ARCH-001 §5.1)
     * @return array{total_graded: int, success_count: int, error_count: int,
     *     errors: array<int, array{student_id: int, reason: string}>}
     *
     * @Traced-To ARCH-002 FR-018, ARCH-002 FR-018 (ARCH-001 §5.1, UC-53)
     */
    public function bulkGrade(int $assessmentId, array $grades, int $teacherId): array
    {
        // F-05: reject oversized bulk payloads before any grade write. The
        // HTTP gate (BulkGradeRequest `max:100`) yields 422 VALIDATION_ERROR
        // before this service runs; this guard covers direct service calls
        // with the same 422 contract. Only counts are logged, never payloads.
        if (count($grades) > self::MAX_BULK_STUDENTS) {
            Log::warning('GradingService::bulkGrade rejected oversized bulk payload', [
                'assessment_id' => $assessmentId,
                'grade_count' => count($grades),
            ]);

            throw new BusinessRuleConflictException(
                'A maximum of ' . self::MAX_BULK_STUDENTS . ' students is allowed per bulk grade request.',
                'TOO_MANY_GRADES',
                422
            );
        }

        foreach ($grades as $entry) {
            if (count($entry['question_grades'] ?? []) > self::MAX_GRADE_ENTRIES_PER_STUDENT) {
                Log::warning('GradingService::bulkGrade rejected oversized per-student entries', [
                    'assessment_id' => $assessmentId,
                    'entry_count' => count($entry['question_grades']),
                ]);

                throw new BusinessRuleConflictException(
                    'A maximum of ' . self::MAX_GRADE_ENTRIES_PER_STUDENT . ' grade entries is allowed per student.',
                    'TOO_MANY_GRADE_ENTRIES',
                    422
                );
            }
        }

        // Validate teacher owns the assessment.
        $assessment = Assessment::query()
            ->where('id', $assessmentId)
            ->where('teacher_id', $teacherId)
            ->first();

        if (! $assessment) {
            throw new AuthorizationException();
        }

        $successCount = 0;
        $errorCount = 0;
        $errors = [];

        foreach ($grades as $entry) {
            try {
                // Resolve the student's attempt for this assessment.
                $attempt = AssessmentAttempt::query()
                    ->where('assessment_id', $assessmentId)
                    ->where('student_id', $entry['student_id'])
                    ->where('status', 'pending_grading')
                    ->latest('id')
                    ->firstOrFail();

                $this->recordManualGrade(
                    $attempt->id,
                    $entry['question_grades'],
                    $teacherId
                );

                $successCount++;
            } catch (ModelNotFoundException $e) {
                $errorCount++;
                $errors[] = [
                    'student_id' => $entry['student_id'],
                    'reason' => 'No pending_grading attempt found for this student.',
                ];
            } catch (BusinessRuleConflictException $e) {
                $errorCount++;
                $errors[] = [
                    'student_id' => $entry['student_id'],
                    'reason' => $e->getMessage(),
                ];
            } catch (ValidationException $e) {
                $errorCount++;
                $flatErrors = collect($e->errors())->flatten();
                $reason = 'Validation error: ' . ($flatErrors->first() ?? 'Score exceeds maximum.');
                $errors[] = [
                    'student_id' => $entry['student_id'],
                    'reason' => $reason,
                ];
            } catch (AuthorizationException $e) {
                $errorCount++;
                $errors[] = [
                    'student_id' => $entry['student_id'],
                    'reason' => 'You do not own this assessment.',
                ];
            }
        }

        return [
            'total_graded' => count($grades),
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'errors' => $errors,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Internal helpers
    |--------------------------------------------------------------------------
    */

    /**
     * F-11: duplicate-INSERT classifier for the assessment_attempts
     * UNIQUE(assessment_id, student_id, attempt_number) backstop. A bare
     * 23000 alone is NOT enough — some drivers report FK/NOT-NULL/CHECK
     * failures as 23000, so uniqueness evidence (named constraint or
     * unique/duplicate wording) is required; anything else propagates
     * instead of masking as 409.
     */
    private static function isDuplicateAttemptError(QueryException $e): bool
    {
        $code = (string) $e->getCode();
        $message = strtolower($e->getMessage());

        if ($code === '23505') {
            return true;
        }

        if (str_contains($message, 'assessment_attempts_assessment_id_student_id_attempt_number_unique')) {
            return true;
        }

        $hasUniqueEvidence = str_contains($message, 'unique')
            || str_contains($message, 'duplicate');

        if (! $hasUniqueEvidence) {
            return false;
        }

        return $code === '23000'
            || str_contains($message, 'assessment_attempts');
    }
}
