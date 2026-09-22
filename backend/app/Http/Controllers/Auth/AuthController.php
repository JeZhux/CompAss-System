<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\AuthService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Authentication session endpoints: ARCH-005 block 4.1 (authentication endpoints, #1–#3).
 *
 * These routes are exempt from PasswordChangeRequired and (for login) from
 * the auth guard itself.
 *
 * @Traced-To ARCH-002 FR-001, ARCH-002 FR-002, ARCH-002 FR-002, ARCH-002 FR-003, ARCH-002 QA-004, ARCH-002 FR-003, ARCH-002 QA-004
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly UserService $userService,
    ) {
    }

    /** POST /api/auth/login (#1) */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->authenticate(
            $request->input('identifier'),
            $request->input('password')
        );

        $headers = $this->rateLimitHeaders($result);

        if (! $result['success']) {
            if ($result['reason'] === 'rate_limited') {
                return response()->json([
                    'error' => [
                        'message' => 'Too many login attempts. Please try again later.',
                        'code' => 'RATE_LIMIT_EXCEEDED',
                    ],
                ], 429, $headers);
            }

            if ($result['reason'] === 'deactivated') {
                return response()->json([
                    'error' => [
                        'message' => 'This account has been deactivated.',
                        'code' => 'ACCOUNT_DEACTIVATED',
                    ],
                ], 403, $headers);
            }

            return response()->json([
                'error' => [
                    'message' => 'Invalid credentials.',
                    'code' => 'INVALID_CREDENTIALS',
                ],
            ], 401, $headers);
        }

        $user = $result['user'];

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'school_id' => $user->school_id,
                'role' => $user->role,
                'must_change_password' => $user->must_change_password,
            ],
        ], 200, $headers);
    }

    /**
     * Build the X-RateLimit-* headers from the authenticate() result
     * (ARCH-002 QA-004, ARCH-002 QA-004, ARCH-005 block 4.1).
     *
     * @return array<string, int>
     */
    private function rateLimitHeaders(array $result): array
    {
        return [
            'X-RateLimit-Limit' => $result['rate_limit_limit'],
            'X-RateLimit-Remaining' => $result['rate_limit_remaining'],
            'X-RateLimit-Reset' => $result['rate_limit_reset'],
        ];
    }

    /** POST /api/auth/logout (#2) */
    public function logout(): JsonResponse
    {
        $this->authService->logout();

        return response()->json([
            'data' => ['message' => 'Logged out.'],
        ]);
    }

    /** POST /api/auth/change-password (#3) */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->userService->changePassword(
            (int) Auth::id(),
            $request->input('current_password'),
            $request->input('new_password')
        );

        // Reload the user model so the Sanctum AuthenticateSession middleware
        // stores the updated password hash in the session via
        // storePasswordHashInSession(). Without this, the guard's cached user
        // still holds the old hash, causing a mismatch on the next request and
        // a 401 (ARCH-002 QA-004 — session integrity after credential change).
        // @Traced-To ARCH-002 FR-003, ARCH-002 QA-004
        Auth::guard('web')->setUser(User::findOrFail(Auth::id()));

        return response()->json([
            'data' => ['message' => 'Password changed.'],
        ]);
    }
}
