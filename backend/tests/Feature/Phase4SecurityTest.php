<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Throwable;

/**
 * ARCH-003 ADR-014 (M-009): the /api/test/* group is dev/harness-only by intent and
 * must be production-gated (SEC-DEC-017) — routes stay registered under
 * APP_ENV=testing/dev, and the production route table contains zero of them.
 *
 * @Traced-To ARCH-003 ADR-014 (Pilot Blocker; SEC-DEC-017, REQ-40, BRG-16, SEC-21, API-02)
 */
#[Group('phase4-security')]
class Phase4SecurityTest extends TestCase
{
    use RefreshDatabase;
    public function test_test_routes_registered_under_testing_environment(): void
    {
        $uris = array_map(
            fn ($route) => $route->uri(),
            Route::getRoutes()->getRoutes()
        );

        $this->assertContains('api/test/login', $uris, 'Login route must stay registered outside production.');
        $this->assertContains('api/test/protected', $uris, 'Protected route must stay registered outside production.');
    }

    public function test_production_route_list_excludes_test_routes(): void
    {
        $lines = $this->routeList('production');

        $testRouteLines = array_values(array_filter(
            $lines,
            fn ($line) => str_contains($line, 'api/test/')
        ));

        $this->assertSame([], $testRouteLines, 'Production route table must contain zero /api/test/* routes.');
    }

    public function test_testing_route_list_includes_test_routes(): void
    {
        $lines = $this->routeList('testing');

        $testRouteLines = array_values(array_filter(
            $lines,
            fn ($line) => str_contains($line, 'api/test/')
        ));

        $this->assertGreaterThanOrEqual(
            4,
            count($testRouteLines),
            'Testing route table must keep /api/test/* routes (ARCH-003 ADR-014 gate, not delete).'
        );
    }

    private function routeList(string $appEnv): array
    {
        $command = sprintf(
            'set APP_ENV=%s&& %s artisan route:list 2>&1',
            $appEnv,
            escapeshellarg(PHP_BINARY)
        );

        $output = [];
        $exitCode = -1;
        exec($command, $output, $exitCode);

        $this->assertSame(0, $exitCode, sprintf('route:list subprocess failed under APP_ENV=%s.', $appEnv));

        return $output;
    }

    public function test_csrf_cookie_endpoint_reachable(): void
    {
        $response = $this->get('/sanctum/csrf-cookie');

        $response->assertStatus(204);

        $xsrfCookies = collect($response->headers->getCookies())
            ->filter(fn ($cookie) => $cookie->getName() === 'XSRF-TOKEN');

        $this->assertNotEmpty($xsrfCookies, '/sanctum/csrf-cookie must set the XSRF-TOKEN cookie.');
    }

    public function test_state_changing_request_without_token_returns_419(): void
    {
        $this->forceCsrfEnforcement();

        $user = User::factory()->admin()->create([
            'must_change_password' => false]);

        $this->csrfBootstrap();

        $this->statefulPost('/api/auth/login', [
            'identifier' => $user->school_id,
            'password' => 'password'], false)->assertStatus(419)
            ->assertJsonPath('error.code', 'CSRF_TOKEN_MISMATCH');
    }

    public function test_admin_state_changing_request_without_token_returns_419(): void
    {
        $this->forceCsrfEnforcement();
        $this->csrfBootstrap();

        $this->statefulPost('/api/admin/users', ['name' => 'Garbage'], false)
            ->assertStatus(419)
            ->assertJsonPath('error.code', 'CSRF_TOKEN_MISMATCH');
    }

    public function test_state_changing_request_with_valid_token_succeeds(): void
    {
        $this->forceCsrfEnforcement();

        $user = User::factory()->admin()->create([
            'must_change_password' => false]);

        $this->csrfBootstrap();

        $this->statefulPost('/api/auth/login', [
            'identifier' => $user->school_id,
            'password' => 'password'])->assertOk();
    }

    public function test_test_routes_remain_csrf_exempt(): void
    {
        $this->forceCsrfEnforcement();

        $user = User::factory()->create([
            'role' => 'Admin',
            'must_change_password' => false]);

        $this->csrfBootstrap();

        $response = $this->statefulPost('/api/test/login', [
            'password' => 'password'], false);

        $this->assertNotSame(419, $response->getStatusCode(), '/api/test/* must stay CSRF-exempt (ARCH-005 §2).');
    }

    /**
     * ARCH-005 §2: unit-test run skips CSRF by default (runningUnitTests); replace
     * both the api-group ValidateCsrfToken and the web-group PreventRequestForgery
     * singletons with subclasses that force enforcement so the 419 path is
     * actually exercised.
     */
    private function forceCsrfEnforcement(): void
    {
        $this->app->singleton(ValidateCsrfToken::class, function ($app) {
            return new class ($app, $app->make(Encrypter::class)) extends ValidateCsrfToken {
                protected function runningUnitTests(): bool
                {
                    return false;
                }
            };
        });

        $this->app->singleton(PreventRequestForgery::class, function ($app) {
            return new class ($app, $app->make(Encrypter::class)) extends PreventRequestForgery {
                protected function runningUnitTests(): bool
                {
                    return false;
                }
            };
        });
    }

    /**
     * ARCH-002 QA-004 (SEC-DEC-006): the rate-limit key must be built from the
     * trimmed identifier (trim only, no upper-casing) so spacing variants of
     * the same account share one 5/15-min bucket. Case variants stay distinct
     * (CompAss IDs are case-sensitive) to prevent cross-account DoS where
     * hammering a lower-case variant would otherwise lock the victim's exact
     * upper-case ID. The DB lookup itself stays case-sensitive on the trimmed
     * CompAss ID.
     */
    public function test_rate_limit_key_is_canonicalized(): void
    {
        $user = User::factory()->admin()->create([
            'must_change_password' => false]);
        $id = $user->school_id;

        // Trim variants share one counter (case-sensitive IDs: case variants differ).
        $variants = [
            $id.' ',
            $id,
            '  '.$id,
            $id.' ',
            $id];

        foreach ($variants as $i => $identifier) {
            $this->post('/api/auth/login', [
                'identifier' => $identifier,
                'password' => 'wrong-password'])->assertStatus(401)
                ->assertHeader('X-RateLimit-Remaining', (string) (4 - $i));
        }

        // 6th attempt — any trim variant — hits the shared counter at its max.
        $this->post('/api/auth/login', [
            'identifier' => ' '.$id,
            'password' => 'wrong-password'])->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED')
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');
    }

    /**
     * ARCH-002 QA-004 (SEC-DEC-006): a successful login clears the trimmed
     * counter, so a fresh budget is available to every spacing variant of the
     * exact ID. Case variants own distinct buckets (case-sensitive IDs), so a
     * lower-variant flood cannot lock the victim's exact ID (no cross-account
     * DoS).
     */
    public function test_success_resets_rate_limit_after_canonicalized_failures(): void
    {
        $user = User::factory()->admin()->create([
            'must_change_password' => false]);
        $id = $user->school_id;

        foreach ([$id.' ', '  '.$id, $id] as $identifier) {
            $this->post('/api/auth/login', [
                'identifier' => $identifier,
                'password' => 'wrong-password'])->assertStatus(401);
        }

        $this->post('/api/auth/login', [
            'identifier' => $id,
            'password' => 'password'])->assertOk()
            ->assertJsonPath('data.role', 'Admin')
            ->assertHeader('X-RateLimit-Remaining', '5');

        foreach ([$id, '  '.$id.' ', $id, $id.' '] as $i => $identifier) {
            $this->post('/api/auth/login', [
                'identifier' => $identifier,
                'password' => 'wrong-password'])->assertStatus(401)
                ->assertHeader('X-RateLimit-Remaining', (string) (4 - $i));
        }
    }

    /**
     * ARCH-002 QA-004 (SEC-DEC-016): a user-miss must cost the same as a user-hit
     * (dummy bcrypt verify on miss) — the response-time ratio must stay within
     * a robust tolerance. Distinct identifiers per warm-up/measured phase
     * because each identifier owns a 5-attempt rate-limit budget.
     */
    public function test_login_timing_parity_user_miss_vs_hit(): void
    {
        $warmupHit = User::factory()->admin()->create([
            'must_change_password' => false]);

        $hitUser = User::factory()->admin()->create([
            'must_change_password' => false]);

        // Warm-up: load the bcrypt cost tables and build the memoized dummy
        // hash (one login each way; timings discarded; distinct identifiers
        // so measurement budgets stay fresh).
        $this->post('/api/auth/login', [
            'identifier' => 'ADM-0000-00001',
            'password' => 'wrong-password'])->assertStatus(401);

        $this->post('/api/auth/login', [
            'identifier' => $warmupHit->school_id,
            'password' => 'wrong-password'])->assertStatus(401);

        $iterations = 5;
        $missTotal = 0.0;
        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);
            $this->post('/api/auth/login', [
                'identifier' => 'ADM-0000-00002',
                'password' => 'wrong-password'])->assertStatus(401);
            $missTotal += microtime(true) - $start;
        }

        $hitTotal = 0.0;
        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);
            $this->post('/api/auth/login', [
                'identifier' => $hitUser->school_id,
                'password' => 'wrong-password'])->assertStatus(401);
            $hitTotal += microtime(true) - $start;
        }

        $missMean = $missTotal / $iterations;
        $hitMean = $hitTotal / $iterations;

        $this->assertGreaterThanOrEqual(
            $hitMean * 0.4,
            $missMean,
            'user-miss must not short-circuit the bcrypt verify (timing oracle).'
        );
        $this->assertLessThanOrEqual(
            $hitMean * 3,
            $missMean,
            'user-miss must not be dramatically slower than user-hit.'
        );

        // Wrong password on a deactivated account behaves like a normal miss
        // (401), while the right password still reaches 403 (SEC-DEC-016).
        $deactivated = User::factory()->admin()->create([
            'is_active' => false]);

        $this->post('/api/auth/login', [
            'identifier' => $deactivated->school_id,
            'password' => 'wrong-password'])->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

        $this->post('/api/auth/login', [
            'identifier' => $deactivated->school_id,
            'password' => 'password'])->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCOUNT_DEACTIVATED');
    }

    /**
     * ARCH-003 ADR-003 (ARCH-002 FR-038, ARCH-003 ADR-003(4)): an admin password reset must revoke
     * the target user's OTHER sessions — every `sessions` row for the target
     * is deleted while the admin's own session row survives.
     */
    public function test_password_reset_revokes_target_users_other_sessions(): void
    {
        $admin = User::factory()->create([
            'role' => 'Admin',
            'must_change_password' => false]);
        Sanctum::actingAs($admin);

        $target = User::factory()->create([
            'role' => 'Teacher',
            'must_change_password' => false]);

        // Two logged-in devices for the target + one admin device.
        $now = now()->timestamp;
        DB::table('sessions')->insert([
            ['id' => 'target-device-a', 'user_id' => $target->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'Agent-A', 'payload' => 'test', 'last_activity' => $now],
            ['id' => 'target-device-b', 'user_id' => $target->id, 'ip_address' => '10.0.0.2', 'user_agent' => 'Agent-B', 'payload' => 'test', 'last_activity' => $now],
            ['id' => 'admin-device', 'user_id' => $admin->id, 'ip_address' => '10.0.0.3', 'user_agent' => 'Agent-Admin', 'payload' => 'test', 'last_activity' => $now]]);

        $this->post('/api/admin/users/'.$target->id.'/reset-password')
            ->assertOk()
            ->assertJsonStructure(['data' => ['temporary_password']]);

        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $admin->id)->count());
    }

    /**
     * ARCH-003 ADR-003 end-to-end probe: with the database session driver, two real
     * logins create two `sessions` rows for the target (plus one for the
     * admin); after the admin reset both target rows are gone, a fresh
     * request lifecycle (guards re-resolved, as in production's per-request
     * process) rejects either device's cookie with 401, and the admin's own
     * session survives.
     */
    public function test_revoked_session_request_returns_401(): void
    {
        config(['session.driver' => 'database']);

        $target = User::factory()->teacher()->create([
            'must_change_password' => false]);

        $admin = User::factory()->admin()->create([
            'must_change_password' => false]);

        // Stateful logins (Referer) so the DB session driver actually persists
        // each session to `sessions`; capture the three session ids.
        $deviceA = $this->loginAndCaptureSessionId($target->school_id);
        $deviceB = $this->loginAndCaptureSessionId($target->school_id);
        $adminSession = $this->loginAndCaptureSessionId($admin->school_id);

        $this->assertSame(2, DB::table('sessions')->where('user_id', $target->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $admin->id)->count());

        // Replay the raw session id without app-level cookie encryption, and
        // drop AuthenticateSession: its viaRemember() lookup explodes on the
        // sanctum RequestGuard once actingAs switches the default auth driver.
        $this->app->singleton(EncryptCookies::class, function ($app) {
            $middleware = new EncryptCookies($app->make(Encrypter::class));
            $middleware->disableFor('compass-session');

            return $middleware;
        });
        config(['sanctum.middleware.authenticate_session' => null]);

        // auth:sanctum switches the default auth driver to 'sanctum' via
        // shouldUse(), so the Guard contract (which the session handler uses
        // to stamp session rows with a user_id) would resolve the RequestGuard
        // with a stale user. Bind it to the web guard so session rows always
        // track the session's actual user.
        $this->app->singleton(\Illuminate\Contracts\Auth\Guard::class, fn ($app) => $app['auth']->guard('web'));

        // Fresh guards + clean session store so the pre-reset replay
        // authenticates purely via the DB session row, never via caches.
        $this->app['auth']->forgetGuards();
        $this->app['session']->driver()->flush();

        $this->withUnencryptedCookie('compass-session', $deviceA)
            ->get('/api/me', ['Referer' => 'http://localhost/'])
            ->assertOk();

        Sanctum::actingAs($admin);
        $this->post('/api/admin/users/'.$target->id.'/reset-password')
            ->assertOk()
            ->assertJsonStructure(['data' => ['temporary_password']]);

        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $admin->id)->count());

        // Production re-creates guards per request; emulate that so the replay
        // observes the revoked session instead of the in-test guard cache.
        $this->app['auth']->forgetGuards();
        $this->app['session']->driver()->flush();

        // Both target devices are forced out...
        $this->withUnencryptedCookie('compass-session', $deviceA)
            ->get('/api/me', ['Referer' => 'http://localhost/'])
            ->assertStatus(401);

        $this->withUnencryptedCookie('compass-session', $deviceB)
            ->get('/api/me', ['Referer' => 'http://localhost/'])
            ->assertStatus(401);

        // ...while the admin's own session is untouched.
        $this->withUnencryptedCookie('compass-session', $adminSession)
            ->get('/api/me', ['Referer' => 'http://localhost/'])
            ->assertOk();
    }

    /**
     * ARCH-005 block 4.7 (ARCH-003 ADR-003(3)): the forced-password gate's exemption set is
     * exactly change-password, logout, /api/me, /api/ai/status. A user with
     * must_change_password=true must reach #95 (200) while every other gated
     * endpoint still returns 403 PASSWORD_CHANGE_REQUIRED.
     */
    public function test_ai_status_exempt_from_forced_password_gate(): void
    {
        config()->set('services.openrouter.mock', true);

        $user = User::factory()->create([
            'role' => 'Teacher',
            'must_change_password' => true]);

        Sanctum::actingAs($user);

        $this->get('/api/ai/status')
            ->assertOk()
            ->assertJsonPath('data.status', 'available')
            ->assertJsonStructure(['data' => ['status', 'checked_at']]);

        $this->get('/api/teacher/learning-materials')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PASSWORD_CHANGE_REQUIRED');
    }

    /**
     * ARCH-006 §8: the User fillable list must not admit password_hash, role,
     * must_change_password, or is_active — an attacker-supplied payload can
     * never mass-assign them.
     *
     * Pre-ARCH-006 §8 (broad fillable) the create succeeds and stores the attacker
     * values, so every assertion below fails (RED). Post-ARCH-006 §8 the sensitive
     * keys are silently dropped by mass assignment; because role and
     * password_hash are NOT NULL with NO database default (migration
     * 0001_01_01_000000_create_users_table.php; ARCH-004 §4.1 role has no default)
     * the INSERT fails CLOSED with a QueryException and no row persists — the
     * guard holds either way.
     */
    public function test_mass_assignment_cannot_set_sensitive_user_fields(): void
    {
        $exception = null;
        $user = null;

        try {
            // Savepointed so the fail-closed QueryException aborts only the
            // inner transaction and the outer RefreshDatabase transaction
            // stays usable for the post-insert assertions below.
            DB::transaction(function () use (&$user): void {
                $user = User::create([
                    'name' => 'Hacker',
                    'school_id' => null,
                    'password_hash' => 'pwned-hash',
                    'role' => 'Admin',
                    'must_change_password' => false,
                    'is_active' => true]);
            });
        } catch (Throwable $thrown) {
            $exception = $thrown;
        }

        if ($exception !== null) {
            // Fail-closed path: the narrowed fillable dropped role and
            // password_hash, so the NOT-NULL/no-default columns abort the
            // INSERT — no attacker-controlled row may exist.
            $this->assertInstanceOf(QueryException::class, $exception);
            $this->assertSame(
                0,
                User::where('name', 'Hacker')->count(),
                'A mass-assigned create must not persist a user row.'
            );

            return;
        }

        // Pre-ARCH-006 §8 path: the create succeeded with attacker values —
        // the sensitive-field assertions below must all fail.
        $fresh = $user->fresh();

        $this->assertSame('Hacker', $fresh->name);
        $this->assertNotSame('Admin', $fresh->role, 'role must not be mass-assignable.');
        $this->assertNull($fresh->password_hash, 'password_hash must not be mass-assignable.');
        $this->assertTrue($fresh->must_change_password, 'must_change_password must not be mass-assignable.');
        $this->assertTrue($fresh->is_active, 'is_active must not be mass-assignable.');
    }

    /**
     * ARCH-002 QA-003 (spec §23 limitation 12): POST /api/student/explanations/{id}/explain-further
     * is throttled to 30 requests/minute per student via the named 'ai-chat' limiter
     * (mirrors ai-generate: shared NAT must not exhaust classmates' budgets).
     * The throttle middleware runs before the controller (and its FormRequest),
     * so the 31st request returns 429 even though the first 30 are controller-level
     * 422s (missing item_id).
     *
     * ARCH-002 QA-008: the canonical renderer (bootstrap/app.php HttpException branch)
     * passes the throttle exception's transport headers through, so the
     * X-RateLimit-* headers are asserted ON the 429 response itself, alongside
     * the canonical RATE_LIMIT_EXCEEDED envelope code.
     */
    public function test_ai_chat_explain_further_is_throttled_per_student(): void
    {
        $student = User::factory()->student()->create([
            'must_change_password' => false]);

        Sanctum::actingAs($student);

        for ($i = 0; $i < 30; $i++) {
            $response = $this->postJson('/api/student/explanations/1/explain-further', []);
            $this->assertNotSame(
                429,
                $response->getStatusCode(),
                'Request ' . ($i + 1) . ' must not be throttled yet (limit is 30/min).'
            );
        }

        $response = $this->postJson('/api/student/explanations/1/explain-further', []);
        $response->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED')
            ->assertHeader('X-RateLimit-Limit', '30')
            ->assertHeader('X-RateLimit-Remaining', '0');
        $this->assertTrue(
            $response->headers->has('X-RateLimit-Reset'),
            'The rendered 429 must carry X-RateLimit-Reset (ARCH-002 QA-008 header passthrough).'
        );
    }

    /**
     * ARCH-002 QA-003 (spec §23 limitation 12): the batch-import routes are
     * throttled to 10 requests/minute per admin via the named 'import' limiter
     * (per-user convention, same as ai-chat/ai-generate — shared NAT must not
     * exhaust a colleague's budget; the global per-IP api backstop still
     * bounds abusive IPs). Same ARCH-002 QA-008 header-placement rationale
     * as the ai-chat test: the 11th request is a canonical 429 carrying the
     * X-RateLimit-* transport headers.
     */
    public function test_import_upload_is_throttled_per_user(): void
    {
        $admin = User::factory()->create([
            'role' => 'Admin',
            'must_change_password' => false]);

        Sanctum::actingAs($admin);

        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/admin/import/competency-tags', []);
            $this->assertNotSame(
                429,
                $response->getStatusCode(),
                'Request ' . ($i + 1) . ' must not be throttled yet (limit is 10/min).'
            );
        }

        $response = $this->postJson('/api/admin/import/competency-tags', []);
        $response->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED')
            ->assertHeader('X-RateLimit-Limit', '10')
            ->assertHeader('X-RateLimit-Remaining', '0');
        $this->assertTrue(
            $response->headers->has('X-RateLimit-Reset'),
            'The rendered 429 must carry X-RateLimit-Reset (ARCH-002 QA-008 header passthrough).'
        );
    }

    /**
     * ARCH-002 QA-003 per-user isolation: one admin exhausting the 10/min
     * import budget must not throttle a different admin (shared NAT fairness).
     */
    public function test_import_throttle_is_isolated_per_user(): void
    {
        $adminA = User::factory()->create([
            'role' => 'Admin',
            'must_change_password' => false]);
        $adminB = User::factory()->create([
            'role' => 'Admin',
            'must_change_password' => false]);

        Sanctum::actingAs($adminA);
        for ($i = 0; $i < 10; $i++) {
            $this->assertNotSame(
                429,
                $this->postJson('/api/admin/import/competency-tags', [])->getStatusCode(),
                'Admin A request ' . ($i + 1) . ' must not be throttled yet (limit is 10/min).'
            );
        }
        $this->postJson('/api/admin/import/competency-tags', [])->assertStatus(429);

        // Same IP, different admin: admin B still has a full budget.
        Sanctum::actingAs($adminB);
        $this->assertNotSame(
            429,
            $this->postJson('/api/admin/import/competency-tags', [])->getStatusCode(),
            'Admin B must not be throttled by admin A\'s budget (per-user keying).'
        );
    }

    /**
     * ARCH-002 QA-003: the new named throttles must not disturb the login limiter
     * (AuthService, ARCH-002 QA-004 — untouched by this work unit). The limiters
     * must be registered and callable (ai-chat per-student, import per-user);
     * and the login flow
     * must still 401 for 5 wrong attempts, 429 on the 6th, and succeed with a
     * correct password afterwards (counter cleared, no interference).
     */
    public function test_throttles_do_not_affect_login_contract(): void
    {
        $aiChatLimiter = RateLimiter::limiter('ai-chat');
        $importLimiter = RateLimiter::limiter('import');

        $this->assertIsCallable($aiChatLimiter, "Named limiter 'ai-chat' must be registered.");
        $this->assertIsCallable($importLimiter, "Named limiter 'import' must be registered.");

        $aiLimit = $aiChatLimiter(Request::create('/api/student/explanations/1/explain-further', 'POST'));
        $this->assertSame(30, $aiLimit->maxAttempts);
        $this->assertStringNotContainsString('127.0.0.1', $aiLimit->key, 'ai-chat limiter must not key by client IP (per-student).');

        $importLimit = $importLimiter(Request::create('/api/admin/import/competency-tags', 'POST'));
        $this->assertSame(10, $importLimit->maxAttempts);
        $this->assertStringNotContainsString('127.0.0.1', $importLimit->key, 'import limiter must not key by client IP (per-user).');

        $smoke = User::factory()->admin()->create([
            'must_change_password' => false]);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/api/auth/login', [
                'identifier' => $smoke->school_id,
                'password' => 'wrong-password'])->assertStatus(401);
        }

        $this->post('/api/auth/login', [
            'identifier' => $smoke->school_id,
            'password' => 'wrong-password'])->assertStatus(429)
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');

        // Independent login-flow smoke on a fresh identifier: the login path
        // (AuthService, ARCH-002 QA-004) still authenticates normally — the new
        // named throttles share no state with the login limiter's key.
        $smokeOk = User::factory()->admin()->create([
            'must_change_password' => false]);

        $this->post('/api/auth/login', [
            'identifier' => $smokeOk->school_id,
            'password' => 'password'])->assertOk()
            ->assertHeader('X-RateLimit-Remaining', '5');
    }

    /**
     * Login through the real endpoint (stateful, so the database session
     * driver persists a `sessions` row) and return the raw session id carried
     * by the login's encrypted `compass-session` cookie.
     */
    private function loginAndCaptureSessionId(string $identifier): string
    {
        $response = $this->post('/api/auth/login', [
            'identifier' => $identifier,
            'password' => 'password'], ['Referer' => 'http://localhost/'])->assertOk();

        $cookies = collect($response->headers->getCookies())
            ->filter(fn ($cookie) => $cookie->getName() === 'compass-session');

        $this->assertNotEmpty($cookies, 'Login must set a compass-session cookie.');

        return Str::after(app('encrypter')->decrypt($cookies->last()->getValue(), false), '|');
    }
}
