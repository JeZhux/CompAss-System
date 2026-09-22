<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSubjectRequest;
use App\Http\Requests\Admin\UpdateSubjectRequest;
use App\Services\OrgStructureService;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin Subject Catalogue endpoints: ARCH-005 block 4.9 (subject-management endpoints, #20–#23).
 *
 * School Year > Semester > Grade Level > Subject > Competencies: every
 * Subject hangs off a Grade Level (`grade_level_id`); `code` is unique per
 * Grade Level, not globally.
 *
 * @Traced-To ARCH-002 FR-005, ARCH-004 §10
 */
class SubjectController extends Controller
{
    public function __construct(private readonly OrgStructureService $orgStructureService)
    {
    }

    /** GET /api/admin/subjects (#20) — optional ?grade_level_id= filter */
    public function index(Request $request): JsonResponse
    {
        $gradeLevelId = $request->filled('grade_level_id')
            ? (int) $request->query('grade_level_id')
            : null;

        return response()->json(
            Pagination::response($this->orgStructureService->listSubjects(
                (int) $request->query('page', 1),
                Pagination::perPage($request),
                $gradeLevelId
            ))
        );
    }

    /** POST /api/admin/subjects (#21) */
    public function store(StoreSubjectRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->orgStructureService->createSubject(
                $request->input('name'),
                $request->input('code'),
                $request->input('description'),
                (int) $request->input('grade_level_id')
            ),
        ], 201);
    }

    /** PUT /api/admin/subjects/{id} (#22) */
    public function update(UpdateSubjectRequest $request, int $id): JsonResponse
    {
        return response()->json([
            'data' => $this->orgStructureService->updateSubject(
                $id,
                $request->input('name'),
                $request->input('code'),
                $request->input('description'),
                $request->filled('grade_level_id') ? (int) $request->input('grade_level_id') : null
            ),
        ]);
    }

    /** DELETE /api/admin/subjects/{id} (#23) */
    public function destroy(int $id): JsonResponse
    {
        $this->orgStructureService->deleteSubject($id);

        return response()->json([
            'data' => ['message' => 'Subject deleted.'],
        ]);
    }
}
