<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreGradeLevelRequest;
use App\Http\Requests\Admin\StoreSchoolYearRequest;
use App\Http\Requests\Admin\StoreSectionRequest;
use App\Http\Requests\Admin\StoreSemesterRequest;
use App\Http\Requests\Admin\StoreTermRequest;
use App\Services\OrgStructureService;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin Organizational Structure endpoints: ARCH-005 block 4.9 (org-structure endpoints, #12–#19).
 *
 * School Year → Semester (1, 2, 3) → Grade Level (7–12) → Section (ARCH-004 §10).
 * Grade Levels own Subjects; Subjects own Competencies.
 *
 * Backwards compatibility: legacy `/terms` routes keep working and delegate
 * to the Semester logic. Responses prefer `semester` / `semester_id` and
 * include a `term_id` alias for transition.
 *
 * @Traced-To ARCH-002 FR-005, ARCH-002 FR-005, ARCH-002 FR-005, ARCH-004 §10
 */
class OrgStructureController extends Controller
{
    public function __construct(private readonly OrgStructureService $orgStructureService)
    {
    }

    /** GET /api/admin/school-years (#12) */
    public function indexSchoolYears(Request $request): JsonResponse
    {
        return response()->json(
            Pagination::response($this->orgStructureService->listSchoolYears(
                (int) $request->query('page', 1),
                Pagination::perPage($request)
            ))
        );
    }

    /** POST /api/admin/school-years (#13) */
    public function storeSchoolYear(StoreSchoolYearRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->orgStructureService->createSchoolYear($request->input('name')),
        ], 201);
    }

    /** GET /api/admin/school-years/{schoolYearId}/semesters (#14, Semester vocabulary) */
    public function indexSemesters(Request $request, int $schoolYearId): JsonResponse
    {
        return response()->json(
            Pagination::response($this->orgStructureService->listSemesters(
                $schoolYearId,
                (int) $request->query('page', 1),
                Pagination::perPage($request)
            ))
        );
    }

    /** GET /api/admin/school-years/{schoolYearId}/terms (#14, deprecated alias) */
    public function indexTerms(Request $request, int $schoolYearId): JsonResponse
    {
        return $this->indexSemesters($request, $schoolYearId);
    }

    /** POST /api/admin/school-years/{schoolYearId}/semesters (#15, Semester vocabulary) */
    public function storeSemester(StoreSemesterRequest $request, int $schoolYearId): JsonResponse
    {
        return response()->json([
            'data' => $this->orgStructureService->createSemester(
                $schoolYearId,
                (string) $request->input('semester'),
                $request->input('name'),
                $request->input('start_date'),
                $request->input('end_date')
            ),
        ], 201);
    }

    /** POST /api/admin/school-years/{schoolYearId}/terms (#15, deprecated alias) */
    public function storeTerm(StoreTermRequest $request, int $schoolYearId): JsonResponse
    {
        // Tightened to the same Semester validation as the canonical route:
        // `semester` is required (422 otherwise). Legacy name-parsing spoof
        // removed — unknown names no longer silently default.
        return response()->json([
            'data' => $this->orgStructureService->createSemester(
                $schoolYearId,
                (string) $request->input('semester'),
                $request->input('name'),
                $request->input('start_date'),
                $request->input('end_date')
            ),
        ], 201);
    }

    /** GET /api/admin/semesters/{semesterId}/grade-levels (#16, Semester vocabulary) */
    public function indexGradeLevels(Request $request, int $semesterId): JsonResponse
    {
        return response()->json(
            Pagination::response($this->orgStructureService->listGradeLevels(
                $semesterId,
                (int) $request->query('page', 1),
                Pagination::perPage($request)
            ))
        );
    }

    /** POST /api/admin/semesters/{semesterId}/grade-levels (#17, Semester vocabulary) */
    public function storeGradeLevel(StoreGradeLevelRequest $request, int $semesterId): JsonResponse
    {
        return response()->json([
            'data' => $this->orgStructureService->createGradeLevel(
                $semesterId,
                (int) $request->input('grade_level')
            ),
        ], 201);
    }

    /** GET /api/admin/grade-levels/{gradeLevelId}/sections (#18) */
    public function indexSections(Request $request, int $gradeLevelId): JsonResponse
    {
        return response()->json(
            Pagination::response($this->orgStructureService->listSections(
                $gradeLevelId,
                (int) $request->query('page', 1),
                Pagination::perPage($request)
            ))
        );
    }

    /** POST /api/admin/grade-levels/{gradeLevelId}/sections (#19) */
    public function storeSection(StoreSectionRequest $request, int $gradeLevelId): JsonResponse
    {
        return response()->json([
            'data' => $this->orgStructureService->createSection(
                $gradeLevelId,
                $request->input('name')
            ),
        ], 201);
    }

    /** POST /api/admin/semesters/{semesterId}/purge (ARCH-002 QA-010, Semester vocabulary) */
    public function purgeSemester(Request $request, int $semesterId): JsonResponse
    {
        $dryRun = $request->query('dry_run') === '1';

        $result = $this->orgStructureService->purgeClosedSemester($semesterId, $dryRun);

        $data = [
            'message' => $dryRun ? 'Dry-run: no data was deleted.' : 'Semester data purged.',
            'semester_id' => $result['semester_id'],
            'term_id' => $result['term_id'],
            'semester' => $result['semester'] ?? null,
            'assignments_purged' => $result['assignments_purged'],
            'submissions_purged' => $result['submissions_purged'],
            'files_purged' => $result['files_purged'],
            'trash_remaining' => $result['trash_remaining'] ?? [],
        ];

        if ($dryRun) {
            $data['dry_run'] = true;
        }

        return response()->json([
            'data' => $data,
        ]);
    }

    /** POST /api/admin/terms/{termId}/purge (ARCH-002 QA-010, deprecated alias) */
    public function purgeTerm(Request $request, int $termId): JsonResponse
    {
        return $this->purgeSemester($request, $termId);
    }
}
