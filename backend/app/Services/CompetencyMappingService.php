<?php

namespace App\Services;

use App\Exceptions\BusinessRuleConflictException;
use App\Models\Assessment;
use App\Models\AssessmentItem;
use App\Models\AssessmentResponse;
use App\Models\AssessmentSubmission;
use App\Models\Classroom;
use App\Models\ClassroomEnrollment;
use App\Models\MasteryRecord;
use App\Models\Section;
use App\Models\Subject;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The deterministic mastery engine. Converts scored Assessment submissions
 * into per-competency mastery data. Enforces the 80% threshold (ARCH-002 FR-020),
 * the Recorded/Unrecorded persistence divergence (ARCH-002 FR-021, ARCH-002 FR-021), the
 * zero-point competency skip guard (ARCH-002 FR-020), and Not-Competent flagging (ARCH-002 FR-020)
 * as a DERIVED view over the latest Recorded mastery record per
 * (student, competency) (ARCH-004 §4.4, ARCH-004 §4.4 — no stored flag rows).
 * Produces aggregate summaries and class-level reports.
 *
 * ARCH-001 §5.1 — CompetencyMappingService invariants:
 *  - Mastery calculation MUST be deterministic (ARCH-002 FR-020).
 *  - Zero-point competencies MUST be skipped with a server-side data-integrity
 *    warning (ARCH-002 FR-020).
 *  - The most recent Recorded Assessment result for a competency MUST be
 *    treated as the current mastery status (ARCH-002 FR-020).
 *  - Unrecorded results MUST NOT be persisted to mastery_records, used for
 *    flagging, or included in aggregates (ARCH-002 FR-021, ARCH-002 FR-021).
 *  - The Pending Grading gate MUST prevent invocation until all subjective
 *    items are scored (ARCH-002 FR-018, ARCH-002 FR-019).
 *  - MasteryRecords MUST include subject_id + classroom_id; the derived
 *    view's scoping comes via the parent mastery_records row.
 *
 * @Traced-To ARCH-002 FR-020, ARCH-002 FR-020, ARCH-002 FR-020, ARCH-002 FR-022, ARCH-002 FR-018, ARCH-002 FR-022, ARCH-002 FR-020, ARCH-002 FR-020, ARCH-002 FR-020,
 *   ARCH-002 FR-021, ARCH-002 FR-019, ARCH-002 FR-020, ARCH-002 FR-020, ARCH-002 QA-001, ARCH-002 QA-010 (ARCH-001 §5.1, ARCH-004 §4.1)
 */
class CompetencyMappingService
{
    /** Mastery threshold: ≥80% = Mastered (ARCH-002 FR-020, ARCH-002 FR-020). */
    public const MASTERY_THRESHOLD_PERCENT = 80.0;

    /**
     * Computes per-competency mastery for a fully scored submission (ARCH-002 FR-020).
     *
     * Algorithm (ARCH-002 FR-020):
     *  1. Retrieve all AssessmentItems for the Assessment.
     *  2. Retrieve all scored responses for the submission.
     *  3. Group items by competency_tag_id → Map<competencyId, Item[]>.
     *  4. For each competency group:
     *     a. earned = sum of earned points for items in this group.
     *     b. total = sum of max points for items in this group.
     *     c. IF total = 0 → SKIP (ARCH-002 FR-020), log data-integrity warning.
     *     d. masteryPercent = (earned / total) × 100 (ARCH-002 FR-020).
     *     e. IF masteryPercent >= 80 → "Mastered" (ARCH-002 FR-020).
     *     f. ELSE → "Not_Mastered" (ARCH-002 FR-020).
     *  5. Check Assessment type (ARCH-002 FR-015):
     *     - Recorded → persist MasteryRecord per competency (ARCH-002 FR-020);
     *       Not-Competent is then surfaced by the derived view
     *       `v_not_competent_flags` (latest Recorded row per pair that is
     *       Not_Mastered — ARCH-004 §4.4, ARCH-004 §4.4).
     *     - Unrecorded → return results for display only, do NOT persist (ARCH-002 FR-021).
     *  6. Return competency results + unmastered competency IDs.
     *
     * Deterministic: same inputs always produce same output (ARCH-002 FR-020).
     *
     * @param  int  $submissionId  FK → assessment_submissions.id
     * @param  int  $subjectId  FK → subjects.id (for scoping)
     *
     * @return array{
     *     status: string,
     *     competency_results: array<int, array{competency_id: int, code: string, descriptor: string, earned: float, total: float, mastery_percent: float, mastery_status: string}>,
     *     unmastered_competency_ids: array<int>,
     *     overall_score: float,
     *     max_score: float,
     *     percentage: float
     * }
     *
     * @Traced-To ARCH-002 FR-020, ARCH-002 FR-020, ARCH-002 FR-020, ARCH-002 FR-021, ARCH-002 FR-020, ARCH-002 FR-020, ARCH-002 FR-020, ARCH-002 FR-021, ARCH-002 FR-020
     * @Traced-To ARCH-002 FR-020 (ARCH-002 FR-020, §4.2, §4.3)
     */
    public function computeMastery(int $submissionId, int $subjectId): array
    {
        $submission = AssessmentSubmission::findOrFail($submissionId);

        // Eager-load the assessment with its items and competency tags.
        $assessment = $submission->assessment()->with('items.competencyTag')->first();

        if (! $assessment) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException(
                'Assessment not found for submission.'
            );
        }

        $items = $assessment->items->sortBy('sort_order');

        // Gather all scored responses for this submission.
        $responses = AssessmentResponse::query()
            ->where('submission_id', $submission->id)
            ->get()
            ->keyBy('item_id');

        // Group items by competency tag (ARCH-002 FR-015 — every item has exactly one tag).
        $competencyGroups = [];
        foreach ($items as $item) {
            $competencyId = $item->competency_tag_id;
            if (! isset($competencyGroups[$competencyId])) {
                $competencyGroups[$competencyId] = [];
            }
            $competencyGroups[$competencyId][] = $item;
        }

        $competencyResults = [];
        $unmasteredCompetencyIds = [];
        $totalEarned = 0.0;
        $totalMax = 0.0;

        foreach ($competencyGroups as $competencyId => $groupItems) {
            $earned = 0.0;
            $total = 0.0;
            $competency = null;

            foreach ($groupItems as $item) {
                $maxPoints = (float) $item->max_points;
                $total += $maxPoints;

                $response = $responses[$item->id] ?? null;
                $earnedPoints = $response && $response->earned_points !== null
                    ? (float) $response->earned_points
                    : 0.0;

                $earned += $earnedPoints;

                $totalEarned += $earnedPoints;
                $totalMax += $maxPoints;

                if ($competency === null) {
                    $competency = $item->competencyTag;
                }
            }

            // ARCH-002 FR-020: zero-point competency skip guard.
            if ($total == 0.0) {
                Log::warning("Competency [{$competencyId}] has zero total max points in Assessment [{$assessment->id}]; skipping mastery computation.", [
                    'competency_id' => $competencyId,
                    'assessment_id' => $assessment->id,
                    'submission_id' => $submissionId,
                ]);
                continue;
            }

            $masteryPercent = round(($earned / $total) * 100, 2);
            $masteryStatus = $masteryPercent >= self::MASTERY_THRESHOLD_PERCENT
                ? 'Mastered'
                : 'Not_Mastered';

            $competencyResults[] = [
                'competency_id' => $competencyId,
                'code' => $competency ? $competency->code : null,
                'descriptor' => $competency ? $competency->descriptor : null,
                'earned' => $earned,
                'total' => $total,
                'mastery_percent' => $masteryPercent,
                'mastery_status' => $masteryStatus,
            ];

            if ($masteryStatus === 'Not_Mastered') {
                $unmasteredCompetencyIds[] = $competencyId;
            }
        }

        // ARCH-002 FR-021: Recorded vs. Unrecorded persistence divergence.
        if ($assessment->type === 'Recorded') {
            $this->persistMasteryRecords(
                $submission,
                $assessment,
                $subjectId,
                $competencyResults,
                $competencyGroups
            );
        }

        return [
            'status' => 'computed',
            'competency_results' => $competencyResults,
            'unmastered_competency_ids' => array_values(array_unique($unmasteredCompetencyIds)),
            'overall_score' => $totalEarned,
            'max_score' => $totalMax,
            'percentage' => $totalMax > 0 ? round(($totalEarned / $totalMax) * 100, 2) : 0.0,
        ];
    }

    /**
     * Persist MasteryRecord for Recorded Assessments (ARCH-002 FR-020). For each
     * competency result that was NOT skipped (zero-point guard), insert a row
     * into mastery_records. Not-Competent flags are NOT written — they derive
     * from `v_not_competent_flags` over the latest Recorded row per
     * (student, competency) (ARCH-002 FR-020, ARCH-004 §4.4, ARCH-004 §4.4).
     *
     * @param  array<int, array{competency_id: int, code: string|null, descriptor: string|null, earned: float, total: float, mastery_percent: float, mastery_status: string}>  $competencyResults
     * @param  array<int, array<int, AssessmentItem>>  $competencyGroups
     *
     * @Traced-To ARCH-002 FR-020, ARCH-002 FR-020, ARCH-002 FR-021, ARCH-002 FR-020, ARCH-002 QA-010 (ARCH-002 FR-021, ARCH-004 §4.1)
     */
    private function persistMasteryRecords(
        AssessmentSubmission $submission,
        Assessment $assessment,
        int $subjectId,
        array $competencyResults,
        array $competencyGroups
    ): void {
        // U-05: derive classroom_id from the assessment that produced this
        // submission — throw (never silently null) when absent.
        $classroomId = Assessment::find($submission->assessment_id)?->classroom_id
            ?? $assessment->classroom_id ?? null;

        if ($classroomId === null) {
            throw new BusinessRuleConflictException(
                'Assessment '.$assessment->id.' is not classroom-scoped; mastery requires a classroom assessment.',
                'ASSESSMENT_NOT_CLASSROOM_SCOPED'
            );
        }

        DB::transaction(function () use ($submission, $assessment, $subjectId, $competencyResults, $classroomId): void {
            foreach ($competencyResults as $result) {
                MasteryRecord::create([
                    'student_id' => $submission->student_id,
                    'subject_id' => $subjectId,
                    'classroom_id' => $classroomId,
                    'assessment_id' => $assessment->id,
                    'assessment_submission_id' => $submission->id,
                    'competency_id' => $result['competency_id'],
                    'mastery_percent' => $result['mastery_percent'],
                    'mastery_status' => $result['mastery_status'],
                ]);
            }
        });
    }

    /**
     * Returns the student's per-competency mastery history from Recorded
     * Assessments, scoped to a subject (ARCH-002 FR-020, ARCH-002 FR-022).
     *
     * If $competencyId is provided, returns history for that single competency;
     * otherwise returns all. Each record includes the assessment ID, competency
     * code, mastery percentage, mastery status, and timestamp.
     *
     * If $subjectId is null, returns all mastery history for the student
     * across all subjects.
     *
     * @return array<int, array<string, mixed>>
     *
     * @Traced-To ARCH-002 FR-020, ARCH-002 FR-022 (ARCH-001 §5.1)
     */
    public function getMasteryHistory(int $studentId, ?int $subjectId, ?int $competencyId = null): array
    {
        $query = MasteryRecord::query()
            ->with('competency')
            ->where('student_id', $studentId)
            ->when($subjectId !== null, fn ($q) => $q->where('subject_id', $subjectId))
            ->when($competencyId !== null, fn ($q) => $q->where('competency_id', $competencyId))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return $query->get()->map(fn (MasteryRecord $record) => [
            'id' => $record->id,
            'student_id' => $record->student_id,
            'competency_id' => $record->competency_id,
            'competency_code' => $record->competency ? $record->competency->code : null,
            'assessment_id' => $record->assessment_id,
            'mastery_percent' => (float) $record->mastery_percent,
            'mastery_status' => $record->mastery_status,
            'created_at' => $record->created_at?->toIso8601String(),
        ])->values()->toArray();
    }

    /**
     * Returns the student's current mastery status for a competency within
     * a subject, derived from the MOST RECENT Recorded Assessment result
     * for that competency (ARCH-002 FR-020, ARCH-002 FR-020).
     *
     * @return array{mastery_percent: float, mastery_status: string, last_assessed_at: string|null}
     *
     * @Traced-To ARCH-002 FR-020, ARCH-002 FR-020 (ARCH-001 §5.1)
     */
    public function getCurrentMasteryStatus(int $studentId, int $subjectId, int $competencyId): array
    {
        $record = MasteryRecord::query()
           ->where('student_id', $studentId)
           ->where('subject_id', $subjectId)
           ->where('competency_id', $competencyId)
           ->orderByDesc('created_at')
           ->orderByDesc('id')
           ->first();

        if (! $record) {
            return [
                'mastery_percent' => 0.0,
                'mastery_status' => 'Not_Mastered',
                'last_assessed_at' => null,
            ];
        }

        return [
            'mastery_percent' => (float) $record->mastery_percent,
            'mastery_status' => $record->mastery_status,
            'last_assessed_at' => $record->created_at?->toIso8601String(),
        ];
    }

    /**
     * Returns Not-Competent flags for students below the 80% threshold (ARCH-002 FR-020),
     * read from the derived view `v_not_competent_flags` (ARCH-004 §4.4, ARCH-004 §4.4):
     * one row per (student, competency) whose latest Recorded mastery record is
     * Not_Mastered — a view cannot go stale after regrades. If $subjectIds
     * is provided, scoped to those subjects via the parent mastery_records
     * row (which carries subject_id); otherwise all.
     *
     * @param  array<int>|null  $subjectIds
     * @return array<int, array<string, mixed>>
     *
     * @Traced-To ARCH-002 FR-020, ARCH-004 §4.4, ARCH-004 §4.4 (ARCH-001 §5.1)
     */
    public function getNotCompetentFlags(?array $subjectIds = null): array
    {
        $query = DB::table('v_not_competent_flags as v')
            ->join('competency_reference as c', 'v.competency_id', '=', 'c.id')
            ->when($subjectIds !== null, function ($q) use ($subjectIds): void {
                $q->whereIn('v.mastery_record_id', function ($sub) use ($subjectIds): void {
                    $sub->select('id')
                        ->from('mastery_records')
                        ->whereIn('subject_id', $subjectIds);
                });
            })
            ->select([
                'v.id',
                'v.student_id',
                'v.competency_id',
                'c.code as competency_code',
                'c.descriptor as competence_descriptor',
                'v.assessment_submission_id',
                'v.mastery_record_id',
                'v.created_at',
            ])
            ->orderByDesc('v.created_at')
            ->orderByDesc('v.id');

        return $query->get()->map(fn ($flag) => [
            'id' => (int) $flag->id,
            'student_id' => (int) $flag->student_id,
            'competency_id' => (int) $flag->competency_id,
            'competency_code' => $flag->competency_code,
            'competence_descriptor' => $flag->competence_descriptor,
            'assessment_submission_id' => (int) $flag->assessment_submission_id,
            'mastery_record_id' => (int) $flag->mastery_record_id,
            'created_at' => $flag->created_at !== null
                ? Carbon::parse($flag->created_at)->toIso8601String()
                : null,
        ])->values()->toArray();
    }

    /**
     * Returns aggregate competency summaries (ARCH-002 FR-022):
     * - number of students who reached mastery threshold per competency,
     * - average mastery scores across Assessments,
     * - remediation frequency per competency.
     *
     * Ratios are computed over the LATEST Recorded record per
     * (student, competency) — resolved FIRST by the shared latest-per-pair
     * subquery (ARCH-002 FR-020, ARCH-002 FR-020 tiebreak) — so mastery_rate_percent and
     * remediation_frequency can never exceed 100% (ARCH-004 §4.4).
     *
     * Supports section-level (one or more section ids — ARCH-002 FR-022 aggregates
     * across ALL assigned sections), grade-level, or school-wide aggregation.
     * All queries read only from mastery_records (Recorded Assessments only,
     * ARCH-002 FR-022, ARCH-002 FR-022 — Unrecorded data is structurally absent).
     *
     * @param  array<int>|null  $sectionIds
     * @return array<int, array<string, mixed>>
     *
     * @Traced-To ARCH-002 FR-022, ARCH-002 FR-022, ARCH-002 QA-001, ARCH-004 §4.4, ARCH-002 FR-022 (ARCH-001 §5.1)
     */
    public function getAggregateSummaries(?array $sectionIds = null, ?int $gradeLevelId = null, ?int $semesterId = null): array
    {
        $query = MasteryRecord::query()
            ->latestPerPair()
            ->join('competency_reference as c', 'mastery_records.competency_id', '=', 'c.id')
            ->select([
                'mastery_records.competency_id',
                'c.code',
                'c.descriptor',
                DB::raw('COUNT(DISTINCT mastery_records.student_id) as total_students_assessed'),
                DB::raw('COUNT(*) as total_records'),
                DB::raw('SUM(CASE WHEN mastery_records.mastery_status = \'Mastered\' THEN 1 ELSE 0 END) as mastered_count'),
                DB::raw('SUM(CASE WHEN mastery_records.mastery_status = \'Not_Mastered\' THEN 1 ELSE 0 END) as not_mastered_count'),
                DB::raw('AVG(mastery_records.mastery_percent) as average_mastery_percent'),
            ]);

        if ($sectionIds !== null && $sectionIds !== []) {
            $query->whereHas('classroom', function ($q) use ($sectionIds): void {
                $q->whereIn('section_id', $sectionIds);
            });
        }

        if ($gradeLevelId !== null) {
            $query->whereHas('classroom.section.gradeLevel', function ($q) use ($gradeLevelId): void {
                $q->where('grade_levels.id', $gradeLevelId);
            });
        }

        if ($semesterId !== null) {
            $query->whereHas('assessment.semester', function ($q) use ($semesterId): void {
                $q->where('semesters.id', $semesterId);
            });
        }

        $results = $query->groupBy(
            'mastery_records.competency_id',
            'c.code',
            'c.descriptor'
        )->get();

        return $results->map(function ($row): array {
            $totalRecords = (int) $row->total_records;
            $masteredCount = (int) $row->mastered_count;
            $notMasteredCount = (int) $row->not_mastered_count;
            $totalStudentsAssessed = (int) $row->total_students_assessed;

            return [
                'competency_id' => (int) $row->competency_id,
                'code' => $row->code,
                'descriptor' => $row->descriptor,
                'total_students_assessed' => $totalStudentsAssessed,
                'mastered_count' => $masteredCount,
                'not_mastered_count' => $notMasteredCount,
                'mastery_rate_percent' => $totalStudentsAssessed > 0
                    ? round(($masteredCount / $totalStudentsAssessed) * 100, 2)
                    : 0.0,
                'average_mastery_percent' => $totalRecords > 0
                    ? round((float) $row->average_mastery_percent, 2)
                    : 0.0,
                'remediation_frequency' => $totalStudentsAssessed > 0
                    ? round(($notMasteredCount / $totalStudentsAssessed) * 100, 2)
                    : 0.0,
            ];
        })->values()->toArray();
    }

    /**
     * Produces a class-level mastery report showing the distribution of
     * mastery outcomes across all students in a section for each assessed
     * competency (ARCH-002 FR-022). For each competency: count of students who mastered
     * vs. not mastered, based on the MOST RECENT Recorded result per student
     * per competency (ARCH-002 FR-020), resolved with the (created_at DESC, id DESC)
     * tiebreak — identical-created_at pairs deterministically resolve to the
     * HIGHER id (ARCH-002 FR-020), consistent with every other derivation.
     *
     * @return array<string, mixed>
     *
     * @Traced-To ARCH-002 FR-022, ARCH-002 FR-020, ARCH-002 FR-020 (ARCH-001 §5.1)
     */
    public function getClassLevelReport(int $sectionId): array
    {
        $section = Section::findOrFail($sectionId);

        // Classrooms for this section (a section can have multiple subjects).
        $classroomIds = Classroom::query()
            ->where('section_id', $sectionId)
            ->pluck('id')
            ->all();

        // For each competency assessed in this section, find the most recent
        // Recorded mastery result per student per competency (ARCH-002 FR-020).
        // Use a subquery to get the latest record per student+competency.
        $latestRecords = MasteryRecord::query()
            ->with('competency')
            ->whereIn('classroom_id', $classroomIds)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');

        $allRecords = $latestRecords->get();

        // Group by competency → for each competency, track latest per student.
        $competencyData = [];
        $studentCount = $this->studentCountForSectionWithClassroomFallback($sectionId);

        foreach ($allRecords as $record) {
            $key = $record->competency_id;
            $studentKey = $record->student_id;

            if (! isset($competencyData[$key])) {
                $competencyData[$key] = [
                    'competency_id' => $record->competency_id,
                    'competency_code' => $record->competency ? $record->competency->code : null,
                    'descriptor' => $record->competency ? $record->competency->descriptor : null,
                    'mastered_count' => 0,
                    'not_mastered_count' => 0,
                    'total_assessed' => 0,
                    'latest_per_student' => [],
                ];
            }

            // Only count the most recent record per student (ARCH-002 FR-020), with the
            // explicit (created_at, id) tiebreak: identical timestamps resolve
            // to the higher id (ARCH-002 FR-020).
            if (! isset($competencyData[$key]['latest_per_student'][$studentKey])) {
                $competencyData[$key]['latest_per_student'][$studentKey] = $record;
            } else {
                $existing = $competencyData[$key]['latest_per_student'][$studentKey];
                if (
                    $record->created_at > $existing->created_at
                    || ($record->created_at->equalTo($existing->created_at) && $record->id > $existing->id)
                ) {
                    $competencyData[$key]['latest_per_student'][$studentKey] = $record;
                }
            }
        }

        $competencyReports = [];
        foreach ($competencyData as $data) {
            $masteredCount = 0;
            $notMasteredCount = 0;
            foreach ($data['latest_per_student'] as $record) {
                if ($record->mastery_status === 'Mastered') {
                    $masteredCount++;
                } else {
                    $notMasteredCount++;
                }
            }

            $totalAssessed = $masteredCount + $notMasteredCount;

            $competencyReports[] = [
                'competency_id' => $data['competency_id'],
                'competency_code' => $data['competency_code'],
                'descriptor' => $data['descriptor'],
                'mastered_count' => $masteredCount,
                'not_mastered_count' => $notMasteredCount,
                'total_assessed' => $totalAssessed,
                'not_mastered_percent' => $totalAssessed > 0
                    ? round(($notMasteredCount / $totalAssessed) * 100, 2)
                    : 0.0,
            ];
        }

        return [
            'section_id' => $sectionId,
            'section_name' => $section->name,
            'total_students' => $studentCount,
            'generated_at' => now()->toIso8601String(),
            'competency_reports' => $competencyReports,
        ];
    }

    /**
     * Auto-scores objective items (multiple choice, true/false) by comparing
     * student responses against defined correct answers (ARCH-003 ADR-004, ARCH-002 FR-018).
     * Returns a map of item_id → earned_points. Full points for correct,
     * zero for incorrect/blank. This is a pure function with no side effects.
     *
     * @return array<int, float>  Map of item_id → earned_points
     *
     * @Traced-To ARCH-003 ADR-004, ARCH-002 FR-018 (ARCH-001 §5.1)
     */
    public function autoScoreObjectiveItems(int $submissionId): array
    {
        $submission = AssessmentSubmission::findOrFail($submissionId);

        $objectiveItems = AssessmentItem::query()
            ->where('assessment_id', $submission->assessment_id)
            ->whereIn('item_type', ['multiple_choice', 'true_false'])
            ->get();

        $responses = AssessmentResponse::query()
            ->where('submission_id', $submissionId)
            ->get()
            ->keyBy('item_id');

        $scores = [];

        foreach ($objectiveItems as $item) {
            $response = $responses[$item->id] ?? null;
            $responseText = $response ? $response->response_text : null;
            $correctAnswer = $item->correct_answer;

            $earned = 0.0;
            if ($responseText !== null && $correctAnswer !== null) {
                // Case-insensitive comparison (ARCH-002 FR-020).
                if (strtolower(trim($responseText)) === strtolower(trim($correctAnswer))) {
                    $earned = (float) $item->max_points;
                }
            }

            $scores[$item->id] = $earned;
        }

        return $scores;
    }

    /*
    |----------------------------------------------------------------------
    | F-01 / U-03 — Classroom enrollment as first-class student source
    |----------------------------------------------------------------------
    */

    /**
     * Return distinct student IDs enrolled in a section via ClassroomEnrollment
     * through non-archived classrooms for the section (classroom-only, U-06 —
     * no legacy roster union). DISTINCT ensures no double-counting across
     * pooled classrooms.
     *
     * @return array<int>
     */
    private function studentIdsForSectionWithClassroomFallback(int $sectionId): array
    {
        $classroomIds = Classroom::query()
            ->where('section_id', $sectionId)
            ->whereNull('archived_at')
            ->pluck('id')
            ->all();

        if (empty($classroomIds)) {
            return [];
        }

        return ClassroomEnrollment::query()
            ->whereIn('classroom_id', $classroomIds)
            ->distinct()
            ->pluck('student_id')
            ->all();
    }

    private function studentCountForSectionWithClassroomFallback(int $sectionId): int
    {
        return count($this->studentIdsForSectionWithClassroomFallback($sectionId));
    }

    /**
     * Return distinct student IDs for a subject via non-archived classrooms
     * for that subject (classroom-only, U-06). Used by aggregate-style
     * queries that need the enrollment denominator without coupling the
     * mastery COUNT DISTINCT.
     *
     * @return array<int>
     */
    private function studentIdsForSubjectWithClassroomFallback(int $subjectId): array
    {
        $classroomIds = Classroom::query()
            ->where('subject_id', $subjectId)
            ->whereNull('archived_at')
            ->pluck('id')
            ->all();

        if (empty($classroomIds)) {
            return [];
        }

        return ClassroomEnrollment::query()
            ->whereIn('classroom_id', $classroomIds)
            ->distinct()
            ->pluck('student_id')
            ->all();
    }

    /**
     * Distinct student IDs for a single classroom (U-03 helper, used in
     * U-07 per-classroom heatmap / aggregate filtering).
     * Returns empty when the classroom is archived.
     *
     * @return array<int>
     */
    private function classroomStudentIds(int $classroomId): array
    {
        $isArchived = Classroom::query()
            ->where('id', $classroomId)
            ->whereNotNull('archived_at')
            ->exists();

        if ($isArchived) {
            return [];
        }

        return ClassroomEnrollment::query()
            ->where('classroom_id', $classroomId)
            ->distinct()
            ->pluck('student_id')
            ->all();
    }

    /**
     * Aggregate summaries filtered to a single classroom (U-03 helper).
     * Keeps the existing getAggregateSummaries signature backward-compatible;
     * callers that need a classroom filter use this method which adds
     * `where classroom_id = ?` before the latest-per-pair derivation.
     *
     * @param  array<int>|null  $sectionIds
     * @return array<int, array<string, mixed>>
     */
    public function getAggregateSummariesForClassroom(int $classroomId, ?array $sectionIds = null, ?int $gradeLevelId = null, ?int $semesterId = null): array
    {
        $query = MasteryRecord::query()
            ->latestPerPair()
            ->where('mastery_records.classroom_id', $classroomId)
            ->join('competency_reference as c', 'mastery_records.competency_id', '=', 'c.id')
            ->select([
                'mastery_records.competency_id',
                'c.code',
                'c.descriptor',
                DB::raw('COUNT(DISTINCT mastery_records.student_id) as total_students_assessed'),
                DB::raw('COUNT(*) as total_records'),
                DB::raw('SUM(CASE WHEN mastery_records.mastery_status = \'Mastered\' THEN 1 ELSE 0 END) as mastered_count'),
                DB::raw('SUM(CASE WHEN mastery_records.mastery_status = \'Not_Mastered\' THEN 1 ELSE 0 END) as not_mastered_count'),
                DB::raw('AVG(mastery_records.mastery_percent) as average_mastery_percent'),
            ]);

        if ($sectionIds !== null && $sectionIds !== []) {
            $query->whereHas('classroom', function ($q) use ($sectionIds): void {
                $q->whereIn('section_id', $sectionIds);
            });
        }

        if ($gradeLevelId !== null) {
            $query->whereHas('classroom.section.gradeLevel', function ($q) use ($gradeLevelId): void {
                $q->where('grade_levels.id', $gradeLevelId);
            });
        }

        if ($semesterId !== null) {
            $query->whereHas('assessment.semester', function ($q) use ($semesterId): void {
                $q->where('semesters.id', $semesterId);
            });
        }

        $results = $query->groupBy(
            'mastery_records.competency_id',
            'c.code',
            'c.descriptor'
        )->get();

        return $results->map(function ($row): array {
            $totalRecords = (int) $row->total_records;
            $masteredCount = (int) $row->mastered_count;
            $notMasteredCount = (int) $row->not_mastered_count;
            $totalStudentsAssessed = (int) $row->total_students_assessed;

            return [
                'competency_id' => (int) $row->competency_id,
                'code' => $row->code,
                'descriptor' => $row->descriptor,
                'total_students_assessed' => $totalStudentsAssessed,
                'mastered_count' => $masteredCount,
                'not_mastered_count' => $notMasteredCount,
                'mastery_rate_percent' => $totalStudentsAssessed > 0
                    ? round(($masteredCount / $totalStudentsAssessed) * 100, 2)
                    : 0.0,
                'average_mastery_percent' => $totalRecords > 0
                    ? round((float) $row->average_mastery_percent, 2)
                    : 0.0,
                'remediation_frequency' => $totalStudentsAssessed > 0
                    ? round(($notMasteredCount / $totalStudentsAssessed) * 100, 2)
                    : 0.0,
            ];
        })->values()->toArray();
    }
}
