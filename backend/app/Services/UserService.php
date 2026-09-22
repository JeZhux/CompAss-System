<?php

namespace App\Services;

use App\Exceptions\BusinessRuleConflictException;
use App\Models\AssessmentSubmission;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Manages the full user account lifecycle — creation, deactivation,
 * reactivation, password reset, and account-detail editing (ARCH-001 §5.1).
 *
 * Invariants (ARCH-001 §5.1 / ARCH-002 QA-004–ARCH-002 FR-038, ARCH-002 QA-004/ARCH-002 FR-003):
 *  - Temporary passwords are generated here, displayed to the Admin once,
 *    then NEVER persisted in plain text — only the bcrypt hash is stored
 *    (ARCH-002 QA-004).
 *  - createAccount() and resetPassword() ALWAYS set must_change_password = true
 *    (ARCH-002 FR-003).
 *  - Accounts are NEVER hard-deleted (ARCH-002 FR-004); deactivation is a flag flip.
 *  - deactivateAccount() enforces the Pending-Grading guard (ARCH-002 FR-004, ARCH-002 FR-004,
 *    ARCH-002 QA-008): teachers with unscored pending-grading submissions throw a
 *    409 BusinessRuleConflictException (PENDING_GRADING_BLOCKS_DEACTIVATION)
 *    rendered through the canonical error envelope.
 */
class UserService
{
    /** Allowed roles (ARCH-002 FR-001). */
    public const ROLES = ['Admin', 'Teacher', 'Student'];

    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly AuthService $authService,
    ) {
    }

    /**
     * Create a new user account with a server-generated CompAss ID
     * (<ROLE>-<4 digits>-<5 digits>) and a system-generated temporary password.
     *
     * @return array{user: User, temporary_password: string, school_id: string}
     *
     * @Traced-To ARCH-002 FR-002, ARCH-002 FR-003, ARCH-005 block 4.1, ARCH-002 QA-004, ARCH-002 FR-003 (ARCH-001 §5.1)
     */
    public function createAccount(string $name, string $role): array
    {
        if (! in_array($role, self::ROLES, true)) {
            throw ValidationException::withMessages(['role' => 'The selected role is invalid.']);
        }

        // Server-side CompAss ID generation only — no manual school_id or
        // email input. The generate-then-insert uniqueness check races under
        // concurrency, so a users.school_id unique hit is retried with a
        // fresh ID (up to 3 attempts), mirroring ClassroomService::placeLearner.
        $user = null;
        $schoolId = '';
        $temporaryPassword = Str::password(16);
        $attempts = 0;

        while ($user === null && $attempts < 3) {
            $attempts++;
            $candidate = User::generateUniqueCompassId($role);

            try {
                // ARCH-006 §8: sensitive fields are never mass-assigned — the User
                // fillable list admits only name/school_id. Set every other
                // attribute explicitly.
                $candidate_user = new User();
                $candidate_user->name = $name;
                $candidate_user->school_id = $candidate;
                $candidate_user->password_hash = Hash::make($temporaryPassword);
                $candidate_user->role = $role;
                $candidate_user->must_change_password = true;
                $candidate_user->is_active = true;
                $candidate_user->save();
                $user = $candidate_user;
                $schoolId = $candidate;
            } catch (QueryException $e) {
                if ($this->isSchoolIdConflict($e) && $attempts < 3) {
                    continue;
                }
                throw $e;
            }
        }

        if ($user === null) {
            throw new BusinessRuleConflictException(
                'Could not save this account. Please try again or contact your administrator.',
                'CREATE_FAILED',
                500
            );
        }

        // ARCH-002 QA-004: the plain-text temp password is returned for one-time Admin
        // display only; it is never persisted — only the bcrypt hash above is.
        $this->auditLogService->log(
            'create',
            'User account created',
            Auth::id(),
            User::class,
            $user->id,
            ['role' => $role]
        );

        return ['user' => $user, 'temporary_password' => $temporaryPassword, 'school_id' => $schoolId];
    }

    /**
     * Deactivate a user account, enforcing the ARCH-002 FR-004 Pending-Grading guard.
     *
     * F-10: self-preservation — an admin cannot deactivate their own account,
     * and the last remaining active admin cannot be deactivated. Both reject
     * with a 403 BusinessRuleConflictException before any state change (no
     * flag flip, no session revocation, no audit row).
     *
     * @return array{success: bool, blocking_assessments: null}
     *
     * @throws BusinessRuleConflictException When pending grading blocks
     *   deactivation (ARCH-002 QA-008: the guard surfaces through the canonical 409
     *   error envelope instead of a result contract).
     *
     * @Traced-To ARCH-002 FR-004, ARCH-002 FR-004, ARCH-002 FR-004 (ARCH-001 §5.1)
     */
    public function deactivateAccount(int $userId): array
    {
        $user = User::findOrFail($userId);

        // F-10: self-deactivation is always rejected, even when other active
        // admins exist — checked first so no state changes occur.
        $actorId = Auth::id();
        if ($actorId !== null && (int) $actorId === (int) $user->id) {
            throw new BusinessRuleConflictException(
                'You cannot deactivate your own account.',
                'SELF_DEACTIVATION_BLOCKED',
                403
            );
        }

        // F-10: deactivating the last remaining active admin would remove the
        // only admin access — rejected before any mutation. Only active Admin
        // targets are guarded; inactive or non-Admin targets fall through to
        // the idempotent ARCH-002 FR-004 flag flip below.
        if (
            $user->role === 'Admin' && (bool) $user->is_active
            && User::where('role', 'Admin')->where('is_active', true)->count() <= 1
        ) {
            throw new BusinessRuleConflictException(
                'Deactivation would remove the last remaining active admin.',
                'LAST_ADMIN_DEACTIVATION_BLOCKED',
                403
            );
        }

        // ARCH-002 FR-004 (ARCH-002 FR-004): Teachers with unscored Pending-Grading submissions
        // cannot be deactivated — blockingPendingGradingAssessments() resolves
        // the blocking assessment IDs (real query since WU-10).
        $blockingAssessments = $this->blockingPendingGradingAssessments($user);

        if (! empty($blockingAssessments)) {
            throw new BusinessRuleConflictException(
                'This teacher has pending grading work that must be scored before deactivation.',
                'PENDING_GRADING_BLOCKS_DEACTIVATION'
            );
        }

        // ARCH-002 FR-004: accounts are never hard-deleted — flip the active flag.
        $user->is_active = false;
        $user->save();

        // F-01: deactivation revokes ALL sessions of the target user
        // immediately — no grace period. Mirrors resetPassword()'s ARCH-003 ADR-003
        // revocation so a disabled account keeps no usable session.
        $this->authService->revokeOtherSessions($user->id);

        $this->auditLogService->log(
            'update',
            'User account deactivated',
            Auth::id(),
            User::class,
            $user->id
        );

        return ['success' => true, 'blocking_assessments' => null];
    }

    /**
     * Reactivate a previously deactivated account.
     *
     * Does NOT trigger a forced password reset (ARCH-002 FR-004).
     *
     *
     * @Traced-To ARCH-002 FR-004, ARCH-002 FR-004 (ARCH-001 §5.1)
     */
    public function reactivateAccount(int $userId): void
    {
        $user = User::findOrFail($userId);

        $user->is_active = true;
        $user->save();

        $this->auditLogService->log(
            'update',
            'User account reactivated',
            Auth::id(),
            User::class,
            $user->id
        );
    }

    /**
     * List user accounts with optional role filtering (ARCH-005 block 4.9).
     *
     * NOTE: ARCH-001 §5.1's method table omits this read helper, but ARCH-005
     * block 4.9 traces the list endpoint to UserService — a Phase 1
     * extension of the stated method set, added here to serve it.
     *
     * @return LengthAwarePaginator<int, User>
     *
     * @Traced-To ARCH-002 FR-001 (ARCH-005 block 4.9)
     */
    public function listUsers(?string $role, int $page = 1, int $perPage = 15, ?string $search = null): LengthAwarePaginator
    {
        $query = User::query();

        if ($role !== null) {
            $query->where('role', $role);
        }

        if ($search !== null && trim($search) !== '') {
            $search = trim($search);
            $driver = \Illuminate\Support\Facades\DB::getDriverName();
            $like = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';
            // Server owns LIKE safety: escape \, %, _ so wildcards match
            // literally (competency-tags pattern).
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $pattern = "%{$escaped}%";
            $query->where(function ($q) use ($like, $pattern) {
                $q->whereRaw("name {$like} ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("school_id {$like} ? ESCAPE '\\'", [$pattern]);
            });
        }

        return $query->orderBy('name')->paginate($perPage, page: $page);
    }

    /**
     * Fetch a single account (ARCH-005 block 4.9 GET /admin/users/{id}).
     *
     * NOTE: extension of the ARCH-001 §5.1 method table (traces to UserService
     * per ARCH-005 block 4.9).
     *
     * @Traced-To ARCH-002 FR-001 (ARCH-005 block 4.9)
     */
    public function getUser(int $userId): User
    {
        return User::findOrFail($userId);
    }

    /**
     * Issue a new temporary password for the account.
     *
     * Sets must_change_password = true so first login forces a change (ARCH-002 FR-038).
     * All of the user's other sessions are revoked (ARCH-003 ADR-003 / ARCH-003 ADR-003(4)):
     * every device must re-authenticate with the new credentials.
     *
     * F-10: an admin cannot reset their own password via this endpoint (use
     * the self-service change-password flow instead) — rejected with a 403
     * before any state change (no hash write, no session revocation, no audit
     * row). Resetting any other account is unchanged.
     *
     * @return array{temporary_password: string}
     *
     * @Traced-To ARCH-002 FR-038, ARCH-002 QA-004, ARCH-002 FR-003, ARCH-002 FR-038 (ARCH-001 §5.1, ARCH-003 ADR-003)
     */
    public function resetPassword(int $userId): array
    {
        $user = User::findOrFail($userId);

        // F-10: self-reset via the admin endpoint would revoke the caller's
        // own sessions — rejected before any mutation.
        $actorId = Auth::id();
        if ($actorId !== null && (int) $actorId === (int) $user->id) {
            throw new BusinessRuleConflictException(
                'You cannot reset your own password via the admin endpoint.',
                'SELF_PASSWORD_RESET_BLOCKED',
                403
            );
        }

        $temporaryPassword = Str::password(16);

        // ARCH-002 QA-004: store only the bcrypt hash.
        $user->password_hash = Hash::make($temporaryPassword);
        $user->must_change_password = true;
        $user->save();

        // ARCH-003 ADR-003 (ARCH-003 ADR-003(4)): the reset invalidates every other session
        // of the target user — only the admin's own session row survives.
        $this->authService->revokeOtherSessions($user->id);

        $this->auditLogService->log(
            'update',
            'User password reset',
            Auth::id(),
            User::class,
            $user->id
        );

        $plain = $temporaryPassword;
        unset($temporaryPassword);

        return ['temporary_password' => $plain];
    }

    /**
     * Edit basic account details (name only — school_id is immutable).
     *
     *
     * @Traced-To ARCH-002 FR-004, ARCH-002 FR-002 (ARCH-001 §5.1)
     */
    public function editAccountDetails(int $userId, ?string $name): User
    {
        $user = User::findOrFail($userId);

        if ($name !== null) {
            $user->name = $name;
        }

        $user->save();

        return $user;
    }

    /**
     * Change the current user's password, clearing the forced-reset flag.
     *
     * F-02: preserves ONLY the performing session — every other `sessions`
     * row for the user is revoked immediately so attacker sessions cannot
     * survive compromise recovery.
     *
     * @Traced-To ARCH-002 FR-003, ARCH-002 QA-004, ARCH-002 FR-003 (ARCH-001 §5.1)
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): void
    {
        $user = User::findOrFail($userId);

        // ARCH-002 QA-004: verify against the stored bcrypt hash, never plaintext.
        if (! Hash::check($currentPassword, $user->password_hash)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        // F-13: forced-reset must rotate the credential — reusing the
        // current password is rejected before any state change.
        if (Hash::check($newPassword, $user->password_hash)) {
            throw ValidationException::withMessages([
                'new_password' => ['The new password must be different from the current password.'],
            ]);
        }

        $user->password_hash = Hash::make($newPassword);
        $user->must_change_password = false;
        $user->save();

        // F-02: revoke every other session, preserving only the performing
        // one. The current session id is excluded; when no session store is
        // bound (e.g. stateless token call) null revokes ALL rows for the
        // user, which still satisfies "at most the performer survives".
        $exceptSessionId = null;
        $request = app('request');
        if ($request->hasSession()) {
            $sessionId = $request->session()->getId();
            if (is_string($sessionId) && $sessionId !== '') {
                $exceptSessionId = $sessionId;
            }
        }

        $this->authService->revokeOtherSessions($user->id, $exceptSessionId);
    }

    /**
     * ARCH-002 FR-004 guard — real query (ARCH-002 FR-004).
     *
     * Returns the distinct assessment IDs that block a Teacher's deactivation:
     * every assessment owned by that teacher (assessments.teacher_id) that has
     * a submission in the 'pending_grading' state. The FK chain is
     * assessment_submissions.attempt_id -> assessment_attempts.id ->
     * assessment_attempts.assessment_id -> assessments.id (all RESTRICT);
     * assessments.teacher_id is the authoritative ownership link used by the
     * grading services (ARCH-002 FR-011). assessment_submissions.assessment_id is
     * denormalized from the attempt's assessment at submit time
     * (AssessmentService::performSubmit), so the two hops agree.
     *
     * In-progress resubmission attempts (ARCH-002 FR-018) do NOT count: a submission
     * row only exists after the student submits, so the join against
     * assessment_submissions naturally excludes attempts that have not been
     * submitted (decided, WU-10 — the wind-down only guards pending grading).
     *
     * Returns an empty array for non-Teacher accounts / when no blocking
     * assessments exist.
     *
     * @return array<int, int>
     *
     * @Traced-To ARCH-002 FR-004, ARCH-002 FR-011 (ARCH-002 FR-004)
     */
    private function blockingPendingGradingAssessments(User $user): array
    {
        if ($user->role !== 'Teacher') {
            return [];
        }

        return AssessmentSubmission::query()
            ->where('status', 'pending_grading')
            ->whereHas('attempt.assessment', function ($query) use ($user): void {
                $query->where('teacher_id', $user->id);
            })
            ->distinct()
            ->pluck('assessment_id')
            ->all();
    }

    /**
     * Whether a query failure is a generated-ID race (concurrent CompAss ID
     * generation colliding on users.school_id).
     */
    private function isSchoolIdConflict(QueryException $e): bool
    {
        $message = $e->getMessage();
        $sqlState = $e->errorInfo[0] ?? null;

        return str_contains($message, 'users_school_id_unique')
            || (($sqlState === '23505' || str_contains($message, '23505')) && str_contains($message, 'school_id'))
            || (str_contains($message, 'UNIQUE constraint failed') && str_contains($message, 'users.school_id'));
    }
}
