<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the first-login forced-password gate (ARCH-002 FR-003, ARCH-002 FR-003).
 *
 * Applied to every authenticated route EXCEPT POST /api/auth/change-password
 * (which is the escape hatch). Any authenticated request whose user carries
 * must_change_password = true is short-circuited with 403 / PASSWORD_CHANGE_REQUIRED
 * before the controller runs, so no other navigation succeeds until the password
 * is changed. This is a server-side gate (ARCH-002 QA-004) — client-side checks are UX
 * only and never authoritative.
 *
 * @Traced-To ARCH-002 FR-003, ARCH-002 FR-003, ARCH-002 QA-004 (ARCH-005 block 4.1 forced-password gate)
 */
class PasswordChangeRequired
{
    public function __construct(private readonly AuthService $authService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        // F-01: deactivated accounts keep no usable session — reject before
        // the password gate so a disabled account cannot use any session.
        if ($user !== null && ! (bool) $user->fresh()?->is_active) {
            return response()->json([
                'error' => [
                    'message' => 'This account has been deactivated.',
                    'code' => 'ACCOUNT_DEACTIVATED',
                ],
            ], 403);
        }

        if ($user !== null && $this->authService->isPasswordChangeRequired($user->id)) {
            return response()->json([
                'error' => [
                    'message' => 'A password change is required before continuing.',
                    'code' => 'PASSWORD_CHANGE_REQUIRED',
                ],
            ], 403);
        }

        return $next($request);
    }
}
