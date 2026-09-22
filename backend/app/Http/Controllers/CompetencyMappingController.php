<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleConflictException;
use App\Models\Classroom;
use App\Models\Section;
use App\Models\Subject;
use App\Services\CompetencyMappingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Competency Mapping Engine endpoints: ARCH-005 block 4.13 (competency-mapping endpoints,
 * inventory #73–#76).
 *
 * #73 GET /api/teacher/not-competent-flags
 * #74 GET /api/teacher/competency-summary
 * #75 GET /api/teacher/sections/{sectionId}/class-level-report
 * #76 GET /api/admin/competency-summary
 *
 * Roles: #73–#75 = Teacher; #76 = Admin.
 *
 * @Traced-To ARCH-002 FR-020, ARCH-002 FR-020, ARCH-002 FR-020, ARCH-002 FR-022, ARCH-002 FR-022, ARCH-002 FR-020, ARCH-002 FR-020, ARCH-002 FR-020, ARCH-002 FR-021,
 *   ARCH-002 FR-020, ARCH-002 QA-001 (ARCH-005 block 4.13, ARCH-001 §5.1, ARCH-004 §4.1)
 */
class CompetencyMappingController extends Controller
{
    public function __construct(private readonly CompetencyMappingService $competencyMappingService)
    {
    }

    /**
     * GET /api/teacher/not-competent-flags (#73)
     *
     * Returns Not-Competent flags for students <80% mastery on Recorded
     * Assessments. Optional ?classroom_id or ?subject_id scopes the flags.
     * Teacher-scoped via classroom ownership (ARCH-002 QA-004).
     */
    public function notCompetentFlags(Request $request): JsonResponse
    {
        $teacherId = (int) Auth::id();
        $classroomId = $request->query('classroom_id') ? (int) $request->query('classroom_id') : null;
        $subjectId = $request->query('subject_id') ? (int) $request->query('subject_id') : null;

        if ($classroomId !== null) {
            $classroom = Classroom::findOrFail($classroomId);
            $this->ensureTeacherOwnsClassroom($teacherId, $classroom);
            $subjectIds = [(int) $classroom->subject_id];
        } elseif ($subjectId !== null) {
            $this->ensureTeacherOwnsSubject($teacherId, $subjectId);
            $subjectIds = [$subjectId];
        } else {
            $subjectIds = Classroom::query()
                ->where('teacher_id', $teacherId)
                ->pluck('subject_id')
                ->map(fn ($v) => (int) $v)
                ->unique()
                ->values()
                ->all();

            if (empty($subjectIds)) {
                return response()->json(['data' => []]);
            }
        }

        $flags = $this->competencyMappingService->getNotCompetentFlags($subjectIds);

        return response()->json(['data' => $flags]);
    }

    /**
     * GET /api/teacher/competency-summary (#74)
     *
     * Returns per-competency aggregate summaries (mastered count, average
     * mastery percentage, remediation frequency) for the teacher's
     * classrooms. Optional ?classroom_id scopes to one classroom;
     * ?subject_id scopes to one subject. The unfiltered call aggregates
     * across ALL owned classrooms — never silently first-section-only
     * (ARCH-002 FR-022).
     */
    public function competencySummary(Request $request): JsonResponse
    {
        $teacherId = (int) Auth::id();
        $classroomId = $request->query('classroom_id') ? (int) $request->query('classroom_id') : null;
        $subjectId = $request->query('subject_id') ? (int) $request->query('subject_id') : null;

        if ($classroomId !== null) {
            $classroom = Classroom::findOrFail($classroomId);
            $this->ensureTeacherOwnsClassroom($teacherId, $classroom);
            $summaries = $this->competencyMappingService->getAggregateSummariesForClassroom($classroomId, null, null, null);

            return response()->json(['data' => $summaries]);
        }

        if ($subjectId !== null) {
            $this->ensureTeacherOwnsSubject($teacherId, $subjectId);
            $sectionIds = Classroom::query()
                ->where('teacher_id', $teacherId)
                ->where('subject_id', $subjectId)
                ->pluck('section_id')
                ->map(fn ($v) => (int) $v)
                ->unique()
                ->values()
                ->all();
        } else {
            // Aggregate across all the teacher's classroom sections (ARCH-002 FR-022).
            $sectionIds = Classroom::query()
                ->where('teacher_id', $teacherId)
                ->pluck('section_id')
                ->map(fn ($v) => (int) $v)
                ->unique()
                ->values()
                ->all();

            if (empty($sectionIds)) {
                return response()->json(['data' => []]);
            }
        }

        $summaries = $this->competencyMappingService->getAggregateSummaries($sectionIds, null, null);

        return response()->json(['data' => $summaries]);
    }

    /**
     * GET /api/teacher/sections/{sectionId}/class-level-report (#75)
     *
     * Returns a class-level mastery report for all students in a section:
     * per-competency distribution of mastered vs. not-mastered (ARCH-002 FR-020).
     */
    public function classLevelReport(Request $request, int $sectionId): JsonResponse
    {
        $teacherId = (int) Auth::id();

        // Validate the section exists (ARCH-002 QA-004).
        Section::findOrFail($sectionId);

        // Ensure the teacher owns at least one classroom in this section (ARCH-002 QA-004).
        $ownsInSection = Classroom::query()
            ->where('teacher_id', $teacherId)
            ->where('section_id', $sectionId)
            ->exists();

        if (! $ownsInSection) {
            throw new BusinessRuleConflictException(
                'You are not assigned to any classroom in this section.',
                'SECTION_NOT_ASSIGNED',
                403
            );
        }

        $report = $this->competencyMappingService->getClassLevelReport($sectionId);

        return response()->json(['data' => $report]);
    }

    /**
     * GET /api/admin/competency-summary (#76)
     *
     * Admin-wide aggregate mastery summary across all classrooms,
     * optionally filtered by subject_id, grade_level_id, or semester_id
     * (term_id accepted as a transition alias of semester_id).
     */
    public function adminCompetencySummary(Request $request): JsonResponse
    {
        $subjectId = $request->query('subject_id')
            ? (int) $request->query('subject_id')
            : null;
        $gradeLevelId = $request->query('grade_level_id')
            ? (int) $request->query('grade_level_id')
            : null;
        $semesterId = $request->query('semester_id') !== null
            ? (int) $request->query('semester_id')
            : ($request->query('term_id') !== null ? (int) $request->query('term_id') : null);
        $classroomId = $request->query('classroom_id')
            ? (int) $request->query('classroom_id')
            : null;

        if ($classroomId !== null) {
            $summaries = $this->competencyMappingService->getAggregateSummariesForClassroom($classroomId, null, $gradeLevelId, $semesterId);
        } elseif ($subjectId !== null) {
            $classroomIds = Classroom::query()->where('subject_id', $subjectId)->pluck('id')->all();
            if (empty($classroomIds)) {
                return response()->json(['data' => []]);
            }
            // Scope via sections of those classrooms to reuse the aggregate path.
            $sectionIds = Classroom::query()->where('subject_id', $subjectId)->pluck('section_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
            $summaries = $this->competencyMappingService->getAggregateSummaries($sectionIds, $gradeLevelId, $semesterId);
        } else {
            $summaries = $this->competencyMappingService->getAggregateSummaries(null, $gradeLevelId, $semesterId);
        }

        return response()->json(['data' => $summaries]);
    }

    /**
     * Validate classroom ownership (ARCH-002 QA-004).
     */
    private function ensureTeacherOwnsClassroom(int $teacherId, Classroom $classroom): void
    {
        if ((int) $classroom->teacher_id !== $teacherId) {
            throw new BusinessRuleConflictException(
                'You do not own this classroom.',
                'FORBIDDEN',
                403
            );
        }
    }

    /**
     * Validate subject-package ownership via at least one classroom (ARCH-002 QA-004).
     */
    private function ensureTeacherOwnsSubject(int $teacherId, int $subjectId): void
    {
        Subject::findOrFail($subjectId);

        $owns = Classroom::query()
            ->where('teacher_id', $teacherId)
            ->where('subject_id', $subjectId)
            ->exists();

        if (! $owns) {
            throw new BusinessRuleConflictException(
                'You are not assigned to this subject.',
                'SUBJECT_NOT_ASSIGNED',
                403
            );
        }
    }
}
