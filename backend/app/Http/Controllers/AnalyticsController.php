<?php

namespace App\Http\Controllers;

use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Phase 6 — Analytics Dashboard endpoints (ARCH-005 block 4.14 analytics endpoints, #77–#82).
 *
 * #77 GET /api/teacher/dashboard/heatmap          — Teacher
 * #78 GET /api/teacher/dashboard/gap-report       — Teacher
 * #79 GET /api/teacher/dashboard/trends           — Teacher
 * #80 GET /api/teacher/dashboard/student-drill-down — Teacher
 * #81 GET /api/admin/dashboard/school-wide-overview — Admin
 * #82 GET /api/student/dashboard/mastery-history  — Student (self-scoped)
 *
 * Role enforcement is on the route middleware; these handlers only resolve the
 * authenticated principal and delegate to AnalyticsService (ARCH-002 QA-004).
 *
 * @Traced-To ARCH-002 FR-022, ARCH-002 FR-022, ARCH-002 FR-022, ARCH-002 FR-022, ARCH-002 FR-023, ARCH-002 FR-022, ARCH-002 FR-025, ARCH-002 FR-024,
 *   ARCH-002 FR-022, ARCH-002 FR-018, ARCH-002 FR-020, ARCH-002 FR-021, ARCH-004 §10, ARCH-002 QA-001, ARCH-002 QA-004 (ARCH-001 §5.1, ARCH-005 block 4.14)
 */
class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsService $analyticsService)
    {
    }

    /**
     * GET /api/teacher/dashboard/heatmap (#77)
     *
     * Per-competency mastery rates for a teacher's subject-section.
     * Optional ?assessment_id scopes to a single Assessment.
     */
    public function heatmap(Request $request): JsonResponse
    {
        // Semester vocabulary only: `subject_section_id` removed (subject_sections
        // table dropped) — callers must supply `subject_id`.
        $subjectId = (int) $request->query('subject_id');
        $assessmentId = $request->query('assessment_id')
            ? (int) $request->query('assessment_id')
            : null;

        if ($subjectId <= 0) {
            throw new HttpException(400, 'subject_id is required.');
        }

        $data = $this->analyticsService->getTeacherHeatmap(
            (int) Auth::id(),
            $subjectId,
            $assessmentId
        );

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/teacher/dashboard/gap-report (#78)
     *
     * Competencies below 80% mastery, grouped by student or section.
     * Optional ?group_by=student|section (default section).
     */
    public function gapReport(Request $request): JsonResponse
    {
        // Semester vocabulary only: `subject_section_id` removed — use `subject_id`.
        $subjectId = (int) $request->query('subject_id');

        if ($subjectId <= 0) {
            throw new HttpException(400, 'subject_id is required.');
        }

        $groupBy = $request->query('group_by', 'section');

        if (! in_array($groupBy, ['student', 'section'], true)) {
            throw ValidationException::withMessages([
                'group_by' => ['group_by must be "student" or "section".'],
            ]);
        }

        $data = $this->analyticsService->getCompetencyGapReport(
            (int) Auth::id(),
            $subjectId,
            $groupBy
        );

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/teacher/dashboard/trends (#79)
     *
     * Mastery rate over time across Released Recorded Assessments.
     * Optional ?competency_code filters by a single competency code.
     */
    public function trends(Request $request): JsonResponse
    {
        // Semester vocabulary only: `subject_section_id` removed — use `subject_id`.
        $subjectId = (int) $request->query('subject_id');

        if ($subjectId <= 0) {
            throw new HttpException(400, 'subject_id is required.');
        }

        $competencyCode = $request->query('competency_code')
            ? (string) $request->query('competency_code')
            : null;

        $data = $this->analyticsService->getPerformanceTrends(
            (int) Auth::id(),
            $subjectId,
            $competencyCode
        );

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/teacher/dashboard/student-drill-down (#80)
     *
     * Per-competency history + Not-Competent flags for one student.
     */
    public function studentDrillDown(Request $request): JsonResponse
    {
        $studentId = (int) $request->query('student_id');
        // Semester vocabulary only: `subject_section_id` removed — use `subject_id`.
        $subjectId = (int) $request->query('subject_id');

        if ($studentId <= 0 || $subjectId <= 0) {
            throw new HttpException(400, 'student_id and subject_id are required.');
        }

        $data = $this->analyticsService->getStudentDrillDown(
            (int) Auth::id(),
            $studentId,
            $subjectId
        );

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/admin/dashboard/school-wide-overview (#81)
     *
     * School-wide mastery overview across grade levels 7–12 and all subjects.
     */
    public function schoolWideOverview(Request $request): JsonResponse
    {
        $data = $this->analyticsService->getAdminSchoolWideOverview((int) Auth::id());

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/student/dashboard/mastery-history (#82)
     *
     * Self-scoped mastery history. Optional ?competency_id filter.
     */
    public function masteryHistory(Request $request): JsonResponse
    {
        $competencyId = $request->query('competency_id')
            ? (int) $request->query('competency_id')
            : null;

        $data = $this->analyticsService->getStudentMasteryHistory(
            (int) Auth::id(),
            $competencyId
        );

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/teacher/classrooms/{classroomId}/competency-summary (F-06 / U-07)
     * Also serves /heatmap alias — classroom-scoped heatmap.
     *
     * Per-competency mastery rates scoped to a single classroom (strict
     * classroom_id equality — legacy NULL rows excluded). Validates
     * ownership (403 FORBIDDEN if not owner). Optional ?assessment_id filter.
     */
    public function heatmapForClassroom(Request $request, int $classroomId): JsonResponse
    {
        $assessmentId = $request->query('assessment_id')
            ? (int) $request->query('assessment_id')
            : null;

        $data = $this->analyticsService->getTeacherHeatmapForClassroom(
            (int) Auth::id(),
            $classroomId,
            $assessmentId
        );

        return response()->json(['data' => $data]);
    }
}
