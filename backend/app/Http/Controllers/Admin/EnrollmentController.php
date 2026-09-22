<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MoveEnrollmentRequest;
use App\Http\Requests\Admin\PlaceEnrollmentRequest;
use App\Services\ClassroomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Admin hand place/move of a single learner (ARCH-005 block 4.17).
 *
 * Manager-only: the routes sit behind role:admin. Shared enrollment tables
 * are owned by ClassroomService; this controller only handles transport.
 */
class EnrollmentController extends Controller
{
    public function __construct(private readonly ClassroomService $classroomService)
    {
    }

    /** POST /api/admin/enrollments/place */
    public function place(PlaceEnrollmentRequest $request): JsonResponse
    {
        // Validated data only (full_name + classroom_id; every other key is
        // prohibited with 422 so typos fail loudly).
        $validated = $request->validated();
        $data = $this->classroomService->placeLearner(
            (int) Auth::id(),
            (string) $validated['full_name'],
            (int) $validated['classroom_id']
        );

        return response()->json(['data' => $data], 201);
    }

    /** POST /api/admin/enrollments/{id}/move */
    public function move(MoveEnrollmentRequest $request, int $id): JsonResponse
    {
        // Validated data only (destination classroom_id; body id and every
        // legacy/ambiguous key is prohibited with 422).
        $validated = $request->validated();
        $data = $this->classroomService->moveEnrollment(
            (int) Auth::id(),
            $id,
            (int) $validated['classroom_id']
        );

        $data['message'] = 'Learner moved.';

        return response()->json(['data' => $data]);
    }
}
