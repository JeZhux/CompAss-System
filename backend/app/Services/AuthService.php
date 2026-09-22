<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Manages login, session establishment, the forced-password gate, and logout
 * (ARCH-001 §5.1).
 *
 * Collaborator note (ARCH-001 §5.1 lists UserService as a collaborator for
 * "credential verification"). ARCH-001 §5.1 does not expose a user-lookup method
 * on UserService, so credential verification reads the User model directly —
 * the same self-contained pattern AuditLogService uses for its model. This is a
 * conceptual collaboration with UserService (which owns the user lifecycle);
 * both services operate on the same `users` table.
 *
 * Invariants (ARCH-001 §5.1 / ARCH-002 QA-004/ARCH-002 FR-002):
 *  - The `identifier` is the CompAss ID (school_id) for ALL roles —
 *    a single field, case-sensitive match on the trimmed value.
 *  - Deactivated accounts are rejected at authentication.
 *  - Sessions expire after 30 minutes of inactivity (SESSION_LIFETIME=30),
 *    enforced by Laravel's session guard — no custom code here (ARCH-002 QA-004).
 *  - Concurrent logins are permitted — no single-session restriction (ARCH-002 FR-002).
 */
class AuthService
{
    /** @var int Maximum login attempts before rate-limiting (ARCH-002 QA-004, ARCH-002 QA-004). */
    public const RATE_LIMIT_MAX_ATTEMPTS = 5;

    /** @var int Rate-limit decay window in seconds (15 minutes, ARCH-002 QA-004, ARCH-002 QA-004). */
    public const RATE_LIMIT_DECAY_SECONDS = 900;

    /** @var string|null Memoized dummy bcrypt hash for user-miss timing parity (SEC-DEC-016). */
    private static ?string $timingDummyHash = null;

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /**
     * Dummy hash verified on user-miss so a failed lookup costs the same as a
     * real credential check (SEC-DEC-016). Built once per process with the
     * configured BCRYPT_ROUNDS (4 in tests, 12 in production).
     */
    private function timingDummyHash(): string
    {
        return self::$timingDummyHash ??= Hash::make('compass-timing-dummy-password');
    }

    /**
     * Authenticate by CompAss ID (school_id, all roles) + password,
     * establishing a Sanctum/SPA session.
     *
     * Enforces ARCH-002 QA-004/ARCH-002 QA-004: 5 attempts per 15-minute sliding window per
     * IP+account pair via Laravel RateLimiter (default cache driver, no Redis
     * per ARCH-002 QA-005), keyed on the trimmed identifier (case-sensitive).
     * AuditLogService::log() fires on every rate-limit block;
     * a successful login clears the counter (ARCH-001 §5.1). A dummy bcrypt
     * verify runs on user-miss for response-time parity (SEC-DEC-016).
     *
     * The bucket is case-sensitive (trim only, no upper-casing): CompAss IDs
     * are case-sensitive, so each exact ID variant gets its own bucket. This
     * prevents cross-account denial of service where hammering a lower-case
     * variant would otherwise lock the victim's exact upper-case ID, while
     * exact-ID hammering still exhausts the victim's own bucket. Spacing
     * variants share the bucket via trim so padding cannot split the counter.
     *
     * @return array{success: bool, user: User|null, role: string|null,
     *               force_reset_flag: bool, reason: string|null,
     *               rate_limited: bool, rate_limit_limit: int,
     *               rate_limit_remaining: int, rate_limit_reset: int}
     *
     * @Traced-To ARCH-002 FR-001, ARCH-002 FR-002, ARCH-002 FR-002, ARCH-002 QA-004, ARCH-002 QA-004, ARCH-002 FR-002, ARCH-002 QA-004, ARCH-002 QA-005, ARCH-002 QA-004 (ARCH-001 §5.1)
     */
    public function authenticate(string $identifier, string $password): array
    {
        // ARCH-002 QA-004, ARCH-002 QA-004: 5 attempts per 15-min sliding window per IP+account
        // pair via Laravel RateLimiter (default cache driver, no Redis — ARCH-002 QA-005).
        $maxAttempts = self::RATE_LIMIT_MAX_ATTEMPTS;
        $decaySeconds = self::RATE_LIMIT_DECAY_SECONDS;
        $ipAddress = request()->ip() ?? '0.0.0.0';
        // Trim the identifier before keying so spacing variants cannot split
        // the per-IP+account counter. The bucket stays case-sensitive (trim
        // only, no upper-casing) to match the case-sensitive DB lookup below:
        // distinct case variants are distinct IDs with distinct buckets, so a
        // lower-variant flood cannot lock the victim's exact ID (cross-account
        // DoS). Exact-ID hammering still exhausts its own bucket.
        $canonicalIdentifier = trim($identifier);
        $rateLimitKey = 'login:' . sha1($ipAddress . '|' . $canonicalIdentifier);

        if (RateLimiter::tooManyAttempts($rateLimitKey, $maxAttempts)) {
            $this->auditLogService->log(
                'login',
                'Rate limit exceeded: blocked login attempt',
                null,
                null,
                null,
                ['identifier_masked' => AuditLogService::maskIdentifier($identifier), 'attempts' => RateLimiter::attempts($rateLimitKey)]
            );

            return [
                'success' => false,
                'user' => null,
                'role' => null,
                'force_reset_flag' => false,
                'reason' => 'rate_limited',
                'rate_limited' => true,
                'rate_limit_limit' => $maxAttempts,
                'rate_limit_remaining' => 0,
                'rate_limit_reset' => now()->timestamp + RateLimiter::availableIn($rateLimitKey),
            ];
        }

        // Record this attempt on the sliding-window counter.
        RateLimiter::hit($rateLimitKey, $decaySeconds);
        $remaining = RateLimiter::remaining($rateLimitKey, $maxAttempts);
        $resetAt = now()->timestamp + RateLimiter::availableIn($rateLimitKey);

        // CompAss ID only: a single identifier field resolves all roles via
        // school_id (UNIQUE, case-sensitive on the trimmed value).
        $trimmedIdentifier = trim($identifier);
        $user = User::query()
            ->where('school_id', $trimmedIdentifier)
            ->first();

        // SEC-DEC-016: exactly one bcrypt verify per attempt — the real hash
        // on user-hit, a memoized dummy hash on user-miss so the response time
        // reveals no account-existence oracle (ARCH-002 QA-004: never plaintext).
        $hashToCheck = $user ? $user->password_hash : $this->timingDummyHash();

        if (! $user || ! Hash::check($password, $hashToCheck)) {
            return [
                'success' => false,
                'user' => null,
                'role' => null,
                'force_reset_flag' => false,
                'reason' => 'invalid_credentials',
                'rate_limited' => false,
                'rate_limit_limit' => $maxAttempts,
                'rate_limit_remaining' => $remaining,
                'rate_limit_reset' => $resetAt,
            ];
        }

        // ARCH-002 FR-004: deactivated accounts may never authenticate.
        if (! $user->is_active) {
            return [
                'success' => false,
                'user' => null,
                'role' => null,
                'force_reset_flag' => false,
                'reason' => 'deactivated',
                'rate_limited' => false,
                'rate_limit_limit' => $maxAttempts,
                'rate_limit_remaining' => $remaining,
                'rate_limit_reset' => $resetAt,
            ];
        }

        // Successful login resets the rate-limit counter (ARCH-002 QA-004, ARCH-002 QA-004).
        RateLimiter::clear($rateLimitKey);

        // Establish the SPA session (Sanctum cookie). login() binds the user to
        // the session guard; regenerate() prevents session fixation (ARCH-002 QA-004 /
        // ARCH-002 FR-002 — multiple sessions are permitted).
        Auth::guard('web')->login($user);

        // regenerate() requires a session store; skip it for stateless Sanctum
        // token requests that carry no session (e.g. tests with actingAs).
        $request = app('request');
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $this->auditLogService->log(
            'login',
            'User logged in',
            $user->id,
            null,
            null,
            ['role' => $user->role]
        );

        return [
            'success' => true,
            'user' => $user,
            'role' => $user->role,
            'force_reset_flag' => (bool) $user->must_change_password,
            'reason' => null,
            'rate_limited' => false,
            'rate_limit_limit' => $maxAttempts,
            'rate_limit_remaining' => $maxAttempts,
            'rate_limit_reset' => now()->timestamp,
        ];
    }

    /**
     * The authenticated user from the current session (ARCH-002 FR-002).
     *
     * The SDS signature takes a sessionId; under Laravel's session guard the
     * current request's session IS the session context, so the resolved user
     * is read from it (Auth::user()).
     *
     * @Traced-To ARCH-002 FR-002 (ARCH-001 §5.1)
     */
    public function getCurrentUser(): ?User
    {
        return Auth::user();
    }

    /**
     * Whether the account is under a forced first-login password change
     * (ARCH-002 FR-003, ARCH-002 FR-003). Used by the PasswordChangeRequired middleware.
     *
     * @Traced-To ARCH-002 FR-003, ARCH-002 FR-003 (ARCH-001 §5.1)
     */
    public function isPasswordChangeRequired(int $userId): bool
    {
        $user = User::find($userId);

        return $user !== null && (bool) $user->must_change_password;
    }

    /**
     * Destroy the current session (ARCH-002 FR-002).
     *
     * @Traced-To ARCH-002 FR-002 (ARCH-001 §5.1)
     */
    public function logout(): void
    {
        $userId = Auth::id();

        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
        }

        // Clear the Sanctum RequestGuard's cached user so that subsequent
        // requests do not see a stale user within the same container lifecycle
        // (relevant in testing; harmless in production where the app is
        // re-created per request). (ARCH-002 QA-002/ARCH-002 QA-005 — no queues, synchronous)
        // @Traced-To ARCH-002 FR-002, ARCH-002 QA-004
        Auth::guard('sanctum')->forgetUser();

        // Invalidate/regenerate only when a session store is bound (SPA flow).
        // Stateless Sanctum token requests carry no session. (ARCH-002 QA-002/ARCH-002 QA-005)
        $request = app('request');
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $this->auditLogService->log('logout', 'User logged out', $userId);
    }

    /**
     * Revoke every session held by a user (ARCH-003 ADR-003 / ARCH-003 ADR-003(4)).
     *
     * Deletes all `sessions` rows for the user — e.g. when an admin resets the
     * password, so every device the user is logged into is forced out. The
     * caller's own session is preserved by design: the admin's row belongs to
     * the admin's user_id, never the target's, so it is untouched here.
     *
     * F-02: self-service password changes pass the performing session id via
     * $exceptSessionId so ONLY that row survives. F-01/deactivation and admin
     * resets pass null to revoke ALL rows for the target user.
     *
     * @Traced-To ARCH-002 FR-038, ARCH-003 ADR-003 (ARCH-003 ADR-003(4))
     */
    public function revokeOtherSessions(int $userId, ?string $exceptSessionId = null): void
    {
        // ARCH-003 ADR-003: drop every session row for the user; each device must
        // re-authenticate with the new password (or the admin-issued temp one).
        $query = DB::table('sessions')->where('user_id', $userId);

        if ($exceptSessionId !== null && $exceptSessionId !== '') {
            $query->where('id', '!=', $exceptSessionId);
        }

        $query->delete();
    }
}
