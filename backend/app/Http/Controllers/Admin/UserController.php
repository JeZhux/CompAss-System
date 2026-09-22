<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use App\Services\UserService;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Admin User Management endpoints: ARCH-005 block 4.9 (admin user-management endpoints, #5–#11).
 *
 * @Traced-To ARCH-002 FR-001, ARCH-002 FR-002, ARCH-002 FR-003, ARCH-002 FR-004, ARCH-002 FR-004, ARCH-002 FR-038, ARCH-002 FR-004, ARCH-002 QA-004, ARCH-002 FR-003
 */
class UserController extends Controller
{
    public function __construct(private readonly UserService $userService)
    {
    }

    /** GET /api/admin/users (#5) */
    public function index(Request $request): JsonResponse
    {
        \App\Support\HumanSearch::rejectNumericSearch($request);

        // Server owns the min-2 search contract (ListCompetencyTagsRequest
        // min:2 convention; frontend guards are bypassable direct-API).
        $rawSearch = $request->query('search');
        if ($rawSearch !== null && trim((string) $rawSearch) !== '' && mb_strlen(trim((string) $rawSearch)) < 2) {
            throw ValidationException::withMessages([
                'search' => ['The search field must be at least 2 characters.'],
            ]);
        }

        $search = $request->query('search') !== null && trim((string) $request->query('search')) !== ''
            ? trim((string) $request->query('search'))
            : null;

        $users = $this->userService->listUsers(
            $request->query('role') ?: null,
            (int) $request->query('page', 1),
            Pagination::perPage($request),
            $search
        );

        return response()->json(
            Pagination::response($users->through(fn (User $u) => $this->formatUser($u)))
        );
    }

    /** POST /api/admin/users (#6) */
    public function store(StoreUserRequest $request): JsonResponse
    {
        // Validated data only: prohibited rules above reject every extra key,
        // and validated() ensures nothing unlisted can slip into the service.
        $validated = $request->validated();
        $result = $this->userService->createAccount(
            $validated['name'],
            $validated['role']
        );

        $user = $result['user'];

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'school_id' => $user->school_id,
                'role' => $user->role,
                'status' => $user->status,
                'must_change_password' => true,
                'temporary_password' => $result['temporary_password'],
            ],
        ], 201);
    }

    /** GET /api/admin/users/{id} (#7) */
    public function show(int $id): JsonResponse
    {
        $user = $this->userService->getUser($id);

        return response()->json(['data' => $this->formatUser($user)]);
    }

    /** PUT /api/admin/users/{id} (#8) */
    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        // Validated data only (name-only contract; role/status/password keys
        // are prohibited with 422 above).
        $validated = $request->validated();
        $user = $this->userService->editAccountDetails(
            $id,
            $validated['name'] ?? null
        );

        return response()->json(['data' => $this->formatUser($user)]);
    }

    /** POST /api/admin/users/{id}/deactivate (#9) */
    public function deactivate(int $id): JsonResponse
    {
        $this->userService->deactivateAccount($id);

        return response()->json([
            'data' => ['message' => 'Account deactivated.'],
        ]);
    }

    /** POST /api/admin/users/{id}/reactivate (#10) */
    public function reactivate(int $id): JsonResponse
    {
        $this->userService->reactivateAccount($id);

        return response()->json([
            'data' => ['message' => 'Account reactivated.'],
        ]);
    }

    /** POST /api/admin/users/{id}/reset-password (#11) */
    public function resetPassword(int $id): JsonResponse
    {
        $result = $this->userService->resetPassword($id);

        return response()->json([
            'data' => [
                'message' => 'Password reset.',
                'temporary_password' => $result['temporary_password'],
                'must_change_password' => true,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'school_id' => $user->school_id,
            'role' => $user->role,
            'status' => $user->status,
            'must_change_password' => $user->must_change_password,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
