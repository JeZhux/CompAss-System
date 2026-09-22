<?php

namespace App\Services;

use App\Exceptions\BusinessRuleConflictException;
use App\Models\Classroom;
use App\Models\ClassroomEnrollment;
use App\Models\CompetencyReference;
use App\Models\GradeLevel;
use App\Models\MasteryRecord;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6 — Analytics Dashboard: read-only visualization layer over Phase 5
 * mastery data (ARCH-005 block 4.14 analytics endpoints, ARCH-001 §5.1). Endpoints #77–#82.
 *
 * All data derives exclusively from mastery_records (Recorded Assessments).
 * Unrecorded results are structurally absent from mastery_records, so no
 * separate "practice data exists" exclusion is required (ARCH-002 FR-021, ARCH-002 FR-021).
 * The service performs NO writes (ARCH-002 FR-022, ARCH-002 QA-010).
 *
 * @Traced-To ARCH-002 FR-022, ARCH-002 FR-022, ARCH-002 FR-022, ARCH-002 FR-022, ARCH-002 FR-023, ARCH-002 FR-022, ARCH-002 FR-025, ARCH-002 FR-024,
 *   ARCH-002 §2, ARCH-002 QA-001, ARCH-002 QA-003, ARCH-002 QA-007, ARCH-002 FR-022, ARCH-002 FR-018, ARCH-002 FR-020, ARCH-002 FR-021, ARCH-004 §10,
 *   UC-38, UC-39, UC-40, UC-47 (ARCH-001 §5.1, ARCH-004 §4.1, ARCH-005 block 4.14)
 */
class AnalyticsService
{
    /** Mastery threshold percentage (ARCH-002 FR-020, shared with CompetencyMappingService). */
    public const MASTERY_THRESHOLD_PERCENT = 80.0;

    public function __construct(
        private readonly OrgStructureService $orgStructure,
        private readonly CompetencyMappingService $competencyMapping,
    ) {
    }

    /*
    |----------------------------------------------------------------------
    | Scoping / authorization helpers (read-only)
    |----------------------------------------------------------------------
    */

    /**
     * @return array<int>
     */
    private function teacherSubjectIds(int $teacherId): array
    {
        return $this->orgStructure->getTeacherAssignments($teacherId)
            ->pluck('subject_id')
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Ensure the teacher owns the subject package via at least one classroom
     * (ARCH-002 QA-004).
     *
     * Throws ModelNotFoundException (404) if the subject does not exist,
     * BusinessRuleConflictException (403 SUBJECT_NOT_ASSIGNED, ARCH-002 FR-005)
     * if the teacher owns no classroom for it.
     */
    private function ensureTeacherOwnsSubject(int $teacherId, int $subjectId): void
    {
        Subject::findOrFail($subjectId);

        if (! in_array($subjectId, $this->teacherSubjectIds($teacherId), true)) {
            throw new BusinessRuleConflictException(
                'You are not assigned to this subject.',
                'SUBJECT_NOT_ASSIGNED',
                403
            );
        }
    }

    /**
     * Ensure the student is enrolled in the requested subject via a classroom
     * (classroom-only enrollment proof, ARCH-002 FR-022).
     *
     * Passes only if a ClassroomEnrollment exists via a non-archived
     * classroom whose subject_id equals the requested subjectId (via
     * classrooms join). Membership in a classroom for any other subject is
     * insufficient.
     */
    private function ensureStudentEnrolledInSubject(int $studentId, int $subjectId): void
    {
        // 404 if either the subject or the student does not exist.
        User::findOrFail($studentId);
        Subject::findOrFail($subjectId);

        $enrolledViaClassroom = ClassroomEnrollment::query()
            ->where('classroom_enrollments.student_id', $studentId)
            ->join('classrooms', 'classrooms.id', '=', 'classroom_enrollments.classroom_id')
            ->where('classrooms.subject_id', $subjectId)
            ->whereNull('classrooms.archived_at')
            ->exists();

        if ($enrolledViaClassroom) {
            return;
        }

        throw new AuthorizationException();
    }

    /**
     * Distinct competency IDs assessed for a subject (Recorded only —
     * Unrecorded assessments never reach mastery_records, so this is implicit).
     *
     * @return array<int>
     */
    private function competencyIdsForSubject(int $subjectId): array
    {
        return MasteryRecord::query()
            ->where('subject_id', $subjectId)
            ->distinct()
            ->pluck('competency_id')
            ->all();
    }

    /*
    |----------------------------------------------------------------------
    #77 — Teacher — Dashboard Heatmap (getTeacherHeatmap)
    |----------------------------------------------------------------------
     */

    /**
     * Per-competency mastery rates for a teacher's subject-section.
     *
     * Without `$assessmentId`, aggregates across all assessments that produced
     * mastery_records for the section (all of which come from Released Recorded
     * Assessments, per the submission/release gating invariant). With
     * `$assessmentId`, scoped to that single assessment.
     *
     * @return array{subject_id: int, assessment_id: int|null, generated_at: string, competencies: array<int, array<string, mixed>>}
     *
     * @Traced-To ARCH-002 FR-022, ARCH-002 FR-022, ARCH-002 QA-001 (ARCH-001 §5.1)
     */
    public function getTeacherHeatmap(int $teacherId, int $subjectId, ?int $assessmentId = null): array
    {
        $this->ensureTeacherOwnsSubject($teacherId, $subjectId);

        $rows = $this->baseAggregateQuery($subjectId, $assessmentId)->get();

        $competencies = $rows->map(function ($row): array {
            $totalStudentsAssessed = (int) $row->total_students_assessed;
            $masteredCount = (int) $row->mastered_count;

            return [
                'competency_id' => (int) $row->competency_id,
                'code' => $row->code,
                'descriptor' => $row->descriptor,
                'total_students_assessed' => $totalStudentsAssessed,
                'mastered_count' => $masteredCount,
                'not_mastered_count' => (int) $row->not_mastered_count,
                'mastery_rate_percent' => $this->ratePercent($masteredCount, $totalStudentsAssessed),
                'average_mastery_percent' => $totalStudentsAssessed > 0
                    ? round((float) $row->average_mastery_percent, 2)
                    : 0.0,
            ];
        })->values()->toArray();

        return [
            'subject_id' => $subjectId,
            'assessment_id' => $assessmentId,
            'generated_at' => now()->toIso8601String(),
            'competencies' => $competencies,
        ];
    }

    /*
    |----------------------------------------------------------------------
    #78 — Teacher — Competency Gap Report (getCompetencyGapReport)
    |----------------------------------------------------------------------
     */

    /**
     * Competencies below the 80% mastery threshold (ARCH-002 FR-020), grouped by
     * student or section. Only Recorded data is consulted (ARCH-002 FR-022, ARCH-002 FR-021).
     *
     * @param  'student'|'section'  $groupBy
     * @return array{subject_id: int, group_by: string, generated_at: string, gaps: array<int, array<string, mixed>>}
     *
     * @Traced-To ARCH-002 FR-022, ARCH-002 FR-022 (ARCH-001 §5.1)
     */
    public function getCompetencyGapReport(int $teacherId, int $subjectId, string $groupBy = 'section'): array
    {
        $this->ensureTeacherOwnsSubject($teacherId, $subjectId);

        $gaps = $groupBy === 'student'
            ? $this->gapReportByStudent($subjectId)
            : $this->gapReportBySection($subjectId);

        return [
            'subject_id' => $subjectId,
            'group_by' => $groupBy,
            'generated_at' => now()->toIso8601String(),
            'gaps' => $gaps,
        ];
    }

    /**
     * Section-level gaps: per competency aggregated across the section,
     * reporting the not-mastered population (mastery_rate_percent < 80).
     *
     * @return array<int, array<string, mixed>>
     */
    private function gapReportBySection(int $subjectId): array
    {
        $rows = $this->baseAggregateQuery($subjectId)->get();

        return $rows->filter(function ($row): bool {
            $totalStudentsAssessed = (int) $row->total_students_assessed;
            if ($totalStudentsAssessed === 0) {
                return false;
            }
            $masteredCount = (int) $row->mastered_count;

            return $this->ratePercent($masteredCount, $totalStudentsAssessed) < self::MASTERY_THRESHOLD_PERCENT;
        })->map(function ($row): array {
            $totalStudentsAssessed = (int) $row->total_students_assessed;
            $masteredCount = (int) $row->mastered_count;
            $notMasteredCount = (int) $row->not_mastered_count;

            return [
                'competency_id' => (int) $row->competency_id,
                'code' => $row->code,
                'descriptor' => $row->descriptor,
                'mastered_count' => $masteredCount,
                'not_mastered_count' => $notMasteredCount,
                'total_students_assessed' => $totalStudentsAssessed,
                'mastery_rate_percent' => $this->ratePercent($masteredCount, $totalStudentsAssessed),
                'not_mastered_percent' => $this->ratePercent($notMasteredCount, $totalStudentsAssessed),
            ];
        })->values()->toArray();
    }

    /**
     * Student-level gaps: the most recent Recorded status (ARCH-002 FR-020) per student
     * per competency, listing each student's not-mastered competencies.
     *
     * @return array<int, array<string, mixed>>
     */
    private function gapReportByStudent(int $subjectId): array
    {
        // Load all records newest-first, then keep the latest per
        // (student, competency) — mirrors CompetencyMappingService::getClassLevelReport
        // (ARCH-002 FR-020). Laravel's `distinct()` is not PostgreSQL DISTINCT ON, so the
        // latest-per-group is resolved in PHP to avoid ambiguity.
        $allRecords = MasteryRecord::query()
            ->with('competency')
            ->where('subject_id', $subjectId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $latestByStudent = [];
        foreach ($allRecords as $record) {
            $key = $record->student_id . '|' . $record->competency_id;
            if (! isset($latestByStudent[$key])) {
                $latestByStudent[$key] = $record;
            }
        }

        $flags = $this->competencyMapping->getNotCompetentFlags([$subjectId]);

        $gaps = [];
        foreach ($latestByStudent as $record) {
            if ($record->mastery_status === 'Mastered') {
                continue;
            }

            $gaps[] = [
                'student_id' => (int) $record->student_id,
                'competency_id' => (int) $record->competency_id,
                'competency_code' => $record->competency ? $record->competency->code : null,
                'descriptor' => $record->competency ? $record->competency->descriptor : null,
                'mastery_percent' => (float) $record->mastery_percent,
                'mastery_status' => $record->mastery_status,
                'not_competent_flag_ids' => $this->flagIdsForStudent($flags, (int) $record->student_id, (int) $record->competency_id),
            ];
        }

        return $gaps;
    }

    /**
     * @param  array<int, array<string, mixed>>  $flags
     * @return array<int>
     */
    private function flagIdsForStudent(array $flags, int $studentId, int $competencyId): array
    {
        $ids = [];
        foreach ($flags as $flag) {
            if (($flag['student_id'] ?? null) === $studentId && ($flag['competency_id'] ?? null) === $competencyId) {
                $ids[] = $flag['id'];
            }
        }

        return $ids;
    }

    /*
    |----------------------------------------------------------------------
    #79 — Teacher — Performance Trends (getPerformanceTrends)
    |----------------------------------------------------------------------
     */

    /**
     * Mastery rate over time, one data point per Assessment (ordered by the
     * assessment's release/creation event). Optional competency filter via
     * the MATATAG competency code. The latest-per-pair set is resolved per
     * (assessment, student, competency) so each student contributes at most
     * one record per assessment snapshot and rates stay ≤ 100% (ARCH-004 §4.4).
     *
     * @return array{subject_id: int, competency_code: string|null, generated_at: string, trends: array<int, array<string, mixed>>}
     *
     * @Traced-To ARCH-002 FR-022, ARCH-002 FR-022, ARCH-004 §4.4 (ARCH-001 §5.1)
     */
    public function getPerformanceTrends(int $teacherId, int $subjectId, ?string $competencyCode = null): array
    {
        $this->ensureTeacherOwnsSubject($teacherId, $subjectId);

        $competencyId = null;
        if ($competencyCode !== null) {
            $competencyId = CompetencyReference::query()
                ->where('code', $competencyCode)
                ->value('id');

            // A supplied code that matches no competency yields no data points.
            if ($competencyId === null) {
                return [
                    'subject_id' => $subjectId,
                    'competency_code' => $competencyCode,
                    'generated_at' => now()->toIso8601String(),
                    'trends' => [],
                ];
            }
        }

        $query = MasteryRecord::query()
            ->latestPerPair(partitionByAssessment: true)
            ->join('assessments as a', 'mastery_records.assessment_id', '=', 'a.id')
            ->join('competency_reference as c', 'mastery_records.competency_id', '=', 'c.id')
            ->where('mastery_records.subject_id', $subjectId)
            ->when($competencyId !== null, function ($q) use ($competencyId): void {
                $q->where('mastery_records.competency_id', $competencyId);
            })
            ->groupBy('mastery_records.assessment_id', 'a.title', 'a.created_at')
            ->select([
                'mastery_records.assessment_id',
                'a.title',
                'a.created_at',
                DB::raw('COUNT(DISTINCT mastery_records.student_id) as total_students_assessed'),
                DB::raw("SUM(CASE WHEN mastery_records.mastery_status = 'Mastered' THEN 1 ELSE 0 END) as mastered_count"),
            ])
            ->orderBy('a.created_at');

        $rows = $query->get();

        $trends = $rows->map(function ($row): array {
            $totalStudentsAssessed = (int) $row->total_students_assessed;
            $masteredCount = (int) $row->mastered_count;

            return [
                'assessment_id' => (int) $row->assessment_id,
                'assessment_title' => $row->title,
                'assessed_at' => $row->created_at,
                'mastery_rate_percent' => $this->ratePercent($masteredCount, $totalStudentsAssessed),
                'mastered_count' => $masteredCount,
                'total_students_assessed' => $totalStudentsAssessed,
            ];
        })->values()->toArray();

        return [
            'subject_id' => $subjectId,
            'competency_code' => $competencyCode,
            'generated_at' => now()->toIso8601String(),
            'trends' => $trends,
        ];
    }

    /*
    |----------------------------------------------------------------------
    #80 — Teacher — Student Drill-Down (getStudentDrillDown)
    |----------------------------------------------------------------------
     */

    /**
     * Per-competency history + Not-Competent flags for a single student within
     * a teacher's subject-section (ARCH-002 FR-022, ARCH-002 FR-020). Validates teacher assignment
     * and student enrollment.
     *
     * @return array{student_id: int, subject_id: int, generated_at: string, competencies: array<int, array<string, mixed>>}
     *
     * @Traced-To ARCH-002 FR-022, ARCH-002 FR-020, ARCH-002 FR-020 (ARCH-001 §5.1)
     */
    public function getStudentDrillDown(int $teacherId, int $studentId, int $subjectId): array
    {
        $this->ensureTeacherOwnsSubject($teacherId, $subjectId);
        $this->ensureStudentEnrolledInSubject($studentId, $subjectId);

        $competencyIds = $this->competencyIdsForSubject($subjectId);
        $flags = $this->competencyMapping->getNotCompetentFlags([$subjectId]);

        $competencyReports = [];
        foreach ($competencyIds as $competencyId) {
            $current = $this->competencyMapping->getCurrentMasteryStatus($studentId, $subjectId, $competencyId);
            $history = $this->competencyMapping->getMasteryHistory($studentId, $subjectId, $competencyId);

            $competency = CompetencyReference::query()->find($competencyId);

            $competencyReports[] = [
                'competency_id' => (int) $competencyId,
                'code' => $competency ? $competency->code : null,
                'descriptor' => $competency ? $competency->descriptor : null,
                'current_mastery_percent' => $current['mastery_percent'],
                'current_mastery_status' => $current['mastery_status'],
                'last_assessed_at' => $current['last_assessed_at'],
                'history' => $history,
                'not_competent_flags' => $this->flagIdsForStudent($flags, $studentId, $competencyId),
            ];
        }

        return [
            'student_id' => $studentId,
            'subject_id' => $subjectId,
            'generated_at' => now()->toIso8601String(),
            'competencies' => $competencyReports,
        ];
    }

    /*
    |----------------------------------------------------------------------
    #81 — Admin — School-Wide Overview (getAdminSchoolWideOverview)
    |----------------------------------------------------------------------
     */

    /**
     * School-wide mastery overview across the admin-managed subjects catalog
     * (ARCH-002 FR-023, ARCH-004 §10): aggregates follow whatever grade levels are configured
     * — no additional grade filtering beyond that configuration. Per-grade
     * aggregates resolve the latest-per-(student, competency) Recorded record
     * FIRST (ARCH-004 §4.4).
     *
     * @return array{generated_at: string, overall_mastery_rate_percent: float, mastered_students: int, total_students_assessed: int, grade_levels: array<int, array<string, mixed>>, by_competency: array<int, array<string, mixed>>}
     *
     * @Traced-To ARCH-002 FR-023, ARCH-002 FR-022, ARCH-004 §10, ARCH-002 QA-004, ARCH-004 §4.4 (ARCH-001 §5.1)
     */
    public function getAdminSchoolWideOverview(int $adminId): array
    {
        if (! $this->isAdmin($adminId)) {
            throw new AuthorizationException();
        }

        $gradeLevels = GradeLevel::query()
            ->orderBy('grade_level')
            ->get();

        $gradeLevelReports = [];
        $overallMastered = 0;
        $overallTotal = 0;

        foreach ($gradeLevels as $gradeLevel) {
            $sections = Section::query()
                ->where('grade_level_id', $gradeLevel->id)
                ->pluck('id')
                ->all();

            $rows = MasteryRecord::query()
                ->latestPerPair()
                ->join('assessments as a', 'mastery_records.assessment_id', '=', 'a.id')
                ->join('competency_reference as c', 'mastery_records.competency_id', '=', 'c.id')
                ->join('subjects as s', 'mastery_records.subject_id', '=', 's.id')
                ->join('classrooms as cl', 'mastery_records.classroom_id', '=', 'cl.id')
                ->join('sections as sec', 'cl.section_id', '=', 'sec.id')
                ->whereIn('sec.id', $sections)
                ->groupBy('s.id', 's.code', 's.name', 'c.id', 'c.code', 'c.descriptor')
                ->select([
                    's.id as subject_id',
                    's.code as subject_code',
                    's.name as subject_name',
                    'c.id as competency_id',
                    'c.code as competency_code',
                    'c.descriptor as competency_descriptor',
                    DB::raw('COUNT(DISTINCT mastery_records.student_id) as total_students_assessed'),
                    DB::raw("SUM(CASE WHEN mastery_records.mastery_status = 'Mastered' THEN 1 ELSE 0 END) as mastered_count"),
                ])
                ->orderBy('s.name')
                ->orderBy('c.code')
                ->get();

            $subjects = [];
            foreach ($rows->groupBy('subject_id') as $subjectId => $subjectRows) {
                $mastered = (int) $subjectRows->sum('mastered_count');
                $total = (int) $subjectRows->sum('total_students_assessed');
                $overallMastered += $mastered;
                $overallTotal += $total;

                $subjects[] = [
                    'subject_id' => (int) $subjectId,
                    'subject_code' => $subjectRows->first()->subject_code,
                    'subject_name' => $subjectRows->first()->subject_name,
                    'mastered_count' => $mastered,
                    'total_students_assessed' => $total,
                    'mastery_rate_percent' => $this->ratePercent($mastered, $total),
                ];
            }

            $gradeLevelReports[] = [
                'grade_level' => $gradeLevel->grade_level,
                'grade_level_id' => $gradeLevel->id,
                'subjects' => $subjects,
            ];
        }

        $byCompetency = $this->baseAggregateQuery(null, null, true)->get();

        $competencyBreakdown = $byCompetency->map(function ($row): array {
            $total = (int) $row->total_students_assessed;
            $mastered = (int) $row->mastered_count;

            return [
                'competency_id' => (int) $row->competency_id,
                'code' => $row->code,
                'descriptor' => $row->descriptor,
                'mastered_count' => $mastered,
                'not_mastered_count' => (int) $row->not_mastered_count,
                'total_students_assessed' => $total,
                'mastery_rate_percent' => $this->ratePercent($mastered, $total),
            ];
        })->values()->toArray();

        return [
            'generated_at' => now()->toIso8601String(),
            'overall_mastery_rate_percent' => $this->ratePercent($overallMastered, $overallTotal),
            'mastered_students' => $overallMastered,
            'total_students_assessed' => $overallTotal,
            'grade_levels' => $gradeLevelReports,
            'by_competency' => $competencyBreakdown,
        ];
    }

    /**
     * @return bool
     */
    private function isAdmin(int $userId): bool
    {
        return User::query()->where('id', $userId)->value('role') === 'Admin';
    }

    /*
    |----------------------------------------------------------------------
    #82 — Student — Mastery History (getStudentMasteryHistory)
    |----------------------------------------------------------------------
     */

    /**
     * Self-scoped mastery history across all Recorded Assessments (ARCH-002 FR-024,
     * ARCH-002 FR-018). Optional competency code filter.
     *
     * @return array{student_id: int, generated_at: string, competencies: array<int, array<string, mixed>>}
     *
     * @Traced-To ARCH-002 FR-024, ARCH-002 FR-021, ARCH-002 FR-021, ARCH-002 FR-018, ARCH-002 FR-020 (ARCH-001 §5.1)
     */
    public function getStudentMasteryHistory(int $studentId, ?int $competencyId = null): array
    {
        $competencyIds = $competencyId !== null
            ? [$competencyId]
            : MasteryRecord::query()
                ->where('student_id', $studentId)
                ->distinct()
                ->pluck('competency_id')
                ->all();

        $competencyReports = [];
        foreach ($competencyIds as $competencyId) {
            // getMasteryHistory orders most-recent-first (id DESC tiebreak), so
            // the first entry is the student's current mastery status (ARCH-002 FR-020).
            // Derive "current" from it rather than calling getCurrentMasteryStatus,
            // which requires a single subject_id and cannot span subjects.
            $history = $this->competencyMapping->getMasteryHistory($studentId, null, $competencyId);

            // Skip competency IDs that have no mastery history (e.g. a nonexistent
            // competency_id filter or a competency the student has never been assessed on).
            if (empty($history)) {
                continue;
            }

            $latest = $history[0];
            $competency = CompetencyReference::query()->find($competencyId);

            $competencyReports[] = [
                'competency_id' => (int) $competencyId,
                'code' => $competency ? $competency->code : null,
                'descriptor' => $competency ? $competency->descriptor : null,
                'current_mastery_percent' => (float) $latest['mastery_percent'],
                'current_mastery_status' => (string) $latest['mastery_status'],
                'last_assessed_at' => (string) ($latest['created_at'] ?? null),
                'history' => $history,
            ];
        }

        return [
            'student_id' => $studentId,
            'generated_at' => now()->toIso8601String(),
            'competencies' => $competencyReports,
        ];
    }

    /**
     * Aggregate competency summary, scoping by section(s) / grade-level / term
     * to the same semantics as CompetencyMappingService::getAggregateSummaries
     * (ARCH-002 FR-022, ARCH-002 FR-022). Ratios aggregate over the latest-per-(student, competency)
     * Recorded record set (ARCH-004 §4.4); multiple section ids aggregate across ALL
     * of them (ARCH-002 FR-022 — never silently partial). Provided as an
     * AnalyticsService method per ARCH-001 §5.1
     *
     * @param  array<int>|null  $sectionIds
     * @return array<int, array<string, mixed>>
     *
     * @Traced-To ARCH-002 FR-022, ARCH-002 FR-022, ARCH-004 §4.4, ARCH-002 FR-022 (ARCH-001 §5.1)
     */
    public function getAggregateCompetencySummary(?array $sectionIds = null, ?int $gradeLevelId = null, ?int $semesterId = null): array
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
                DB::raw("SUM(CASE WHEN mastery_records.mastery_status = 'Mastered' THEN 1 ELSE 0 END) as mastered_count"),
                DB::raw("SUM(CASE WHEN mastery_records.mastery_status = 'Not_Mastered' THEN 1 ELSE 0 END) as not_mastered_count"),
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
                'mastery_rate_percent' => $this->ratePercent($masteredCount, $totalStudentsAssessed),
                'average_mastery_percent' => $totalRecords > 0
                    ? round((float) $row->average_mastery_percent, 2)
                    : 0.0,
                'remediation_frequency' => $this->ratePercent($notMasteredCount, $totalStudentsAssessed),
            ];
        })->values()->toArray();
    }

    /*
    |----------------------------------------------------------------------
    | Shared query + math helpers (read-only)
    |----------------------------------------------------------------------
    */

    /**
     * Per-competency aggregate counts for a subject (optionally a single
     * assessment). Mirrors CompetencyMappingService::getAggregateSummaries
     * but scoped by subject_id. When $global is true, aggregates across all
     * subjects (used by the admin school-wide by_competency breakdown).
     *
     * Ratios are computed over the LATEST Recorded record per
     * (student, competency), resolved FIRST by the shared latest-per-pair
     * subquery with the (created_at DESC, id DESC) tiebreak (ARCH-002 FR-020, ARCH-002 FR-020),
     * so mastery_rate_percent / remediation_frequency can never exceed 100%
     * (ARCH-004 §4.4).
     *
     * When $classroomId is provided, filters mastery_records.classroom_id = $classroomId
     * (strict equality — legacy rows with NULL classroom_id are excluded from
     * classroom-scoped results but remain in pooled queries).
     *
     * @return \Illuminate\Database\Eloquent\Builder
     *
     * @Traced-To ARCH-004 §4.4, ARCH-002 FR-020, ARCH-002 FR-020 (BASELINE v1.2 §15.4)
     */
    private function baseAggregateQuery(?int $subjectId, ?int $assessmentId = null, bool $global = false, ?int $classroomId = null)
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
                DB::raw("SUM(CASE WHEN mastery_records.mastery_status = 'Mastered' THEN 1 ELSE 0 END) as mastered_count"),
                DB::raw("SUM(CASE WHEN mastery_records.mastery_status = 'Not_Mastered' THEN 1 ELSE 0 END) as not_mastered_count"),
                DB::raw('AVG(mastery_records.mastery_percent) as average_mastery_percent'),
            ]);

        if (! $global) {
            $query->where('mastery_records.subject_id', $subjectId);
        }

        if ($assessmentId !== null) {
            $query->where('mastery_records.assessment_id', $assessmentId);
        }

        if ($classroomId !== null) {
            $query->where('mastery_records.classroom_id', $classroomId);
        }

        return $query->groupBy(
            'mastery_records.competency_id',
            'c.code',
            'c.descriptor'
        );
    }

    /**
     * Per-competency mastery rates for a teacher's classroom (F-06 / U-07).
     *
     * Validates ownership via direct classroom.teacher_id check (mirrors
     * ClassroomService::getTeacherClassroom pattern). Then filters
     * mastery_records.classroom_id = classroomId (strict — NULL legacy rows
     * excluded).
     *
     * @return array{classroom_id: int, subject_id: int, assessment_id: int|null, generated_at: string, competencies: array<int, array<string, mixed>>}
     */
    public function getTeacherHeatmapForClassroom(int $teacherId, int $classroomId, ?int $assessmentId = null): array
    {
        $classroom = Classroom::findOrFail($classroomId);

        if ((int) $classroom->teacher_id !== $teacherId) {
            throw new BusinessRuleConflictException(
                'You do not own this classroom.',
                'FORBIDDEN',
                403
            );
        }

        $subjectId = (int) $classroom->subject_id;

        $rows = $this->baseAggregateQuery($subjectId, $assessmentId, false, $classroomId)->get();

        $competencies = $rows->map(function ($row): array {
            $totalStudentsAssessed = (int) $row->total_students_assessed;
            $masteredCount = (int) $row->mastered_count;

            return [
                'competency_id' => (int) $row->competency_id,
                'code' => $row->code,
                'descriptor' => $row->descriptor,
                'total_students_assessed' => $totalStudentsAssessed,
                'mastered_count' => $masteredCount,
                'not_mastered_count' => (int) $row->not_mastered_count,
                'mastery_rate_percent' => $this->ratePercent($masteredCount, $totalStudentsAssessed),
                'average_mastery_percent' => $totalStudentsAssessed > 0
                    ? round((float) $row->average_mastery_percent, 2)
                    : 0.0,
            ];
        })->values()->toArray();

        return [
            'classroom_id' => $classroomId,
            'subject_id' => $subjectId,
            'assessment_id' => $assessmentId,
            'generated_at' => now()->toIso8601String(),
            'competencies' => $competencies,
        ];
    }

    /**
     * master_count / total * 100, rounded to 2 decimals; 0.0 when total is 0.
     */
    private function ratePercent(int $numerator, int $denominator): float
    {
        return $denominator > 0
            ? round(($numerator / $denominator) * 100, 2)
            : 0.0;
    }

    /*
    |----------------------------------------------------------------------
    | F-01 / U-03 — Classroom enrollment as first-class student source
    |----------------------------------------------------------------------
    */

    /**
     * Distinct student IDs enrolled in a single classroom (U-03 helper,
     * used in U-07 per-classroom heatmap filtering).
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
     * Distinct student IDs for a subject, pooled across all non-archived
     * classrooms for that subject (classroom-only, U-06 — no legacy roster
     * union). DISTINCT ensures no double-counting across pooled classrooms.
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
}
