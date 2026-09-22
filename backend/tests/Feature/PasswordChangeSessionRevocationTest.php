<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * F-02 — Password change preserves only the performing session.
 *
 * Covers: two concurrent database sessions for one user → one session
 * changes the password → performing session stays valid, every other
 * session is revoked (next request 401, at most the performer row
 * survives in `sessions`). Also pins the cross-cutting logging rule
 * (no raw passwords/tokens in audit metadata).
 */
#[Group('f-02')]
class PasswordChangeSessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_change_revokes_other_sessions_and_preserves_performer(): void
    {
        config(['session.driver' => 'database']);

        $user = User::factory()->teacher()->create([
            'must_change_password' => false,
            'password_hash' => Hash::make('OldPass123'),
        ]);

        $deviceA = $this->loginAndCaptureSessionId($user->school_id, 'OldPass123');
        $deviceB = $this->loginAndCaptureSessionId($user->school_id, 'OldPass123');

        $this->assertNotSame($deviceA, $deviceB);
        $this->assertSame(2, DB::table('sessions')->where('user_id', $user->id)->count());

        $this->app->singleton(EncryptCookies::class, function ($app) {
            $middleware = new EncryptCookies($app->make(Encrypter::class));
            $middleware->disableFor('compass-session');

            return $middleware;
        });
        config(['sanctum.middleware.authenticate_session' => null]);
        $this->app->singleton(\Illuminate\Contracts\Auth\Guard::class, fn ($app) => $app['auth']->guard('web'));

        $this->app['auth']->forgetGuards();
        $this->app['session']->driver()->flush();

        $this->withUnencryptedCookie('compass-session', $deviceA)
            ->get('/api/me', ['Referer' => 'http://localhost/'])
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->app['session']->driver()->flush();

        $this->withUnencryptedCookie('compass-session', $deviceB)
            ->get('/api/me', ['Referer' => 'http://localhost/'])
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->app['session']->driver()->flush();

        $this->withUnencryptedCookie('compass-session', $deviceA)
            ->post('/api/auth/change-password', [
                'current_password' => 'OldPass123',
                'new_password' => 'NewPass123',
            ], ['Referer' => 'http://localhost/'])
            ->assertOk()
            ->assertJsonPath('data.message', 'Password changed.');

        $this->assertTrue(Hash::check('NewPass123', $user->fresh()->password_hash));
        $this->assertFalse((bool) $user->fresh()->must_change_password);

        $remaining = DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all();
        $this->assertCount(1, $remaining);
        $this->assertSame([$deviceA], $remaining);

        $this->app['auth']->forgetGuards();
        $this->app['session']->driver()->flush();

        $this->withUnencryptedCookie('compass-session', $deviceA)
            ->get('/api/me', ['Referer' => 'http://localhost/'])
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->app['session']->driver()->flush();

        $this->withUnencryptedCookie('compass-session', $deviceB)
            ->get('/api/me', ['Referer' => 'http://localhost/'])
            ->assertStatus(401);

        foreach (AuditLog::all() as $log) {
            $metadata = $log->metadata ?? [];
            $this->assertIsArray($metadata);
            foreach (['password', 'temporary_password', 'token', 'current_password', 'new_password', 'identifier', 'email', 'school_id', 'ip', 'ip_address'] as $key) {
                $this->assertArrayNotHasKey($key, $metadata);
            }
            $this->assertStringNotContainsStringIgnoringCase('OldPass123', (string) $log->description);
            $this->assertStringNotContainsStringIgnoringCase('NewPass123', (string) $log->description);
            $this->assertStringNotContainsStringIgnoringCase('OldPass123', json_encode($metadata));
            $this->assertStringNotContainsStringIgnoringCase('NewPass123', json_encode($metadata));
        }
    }

    private function loginAndCaptureSessionId(string $identifier, string $password): string
    {
        $response = $this->post('/api/auth/login', [
            'identifier' => $identifier,
            'password' => $password,
        ], ['Referer' => 'http://localhost/'])->assertOk();

        $cookies = collect($response->headers->getCookies())
            ->filter(fn ($cookie) => $cookie->getName() === 'compass-session');

        $this->assertNotEmpty($cookies, 'Login must set a compass-session cookie.');

        return Str::after(app('encrypter')->decrypt($cookies->last()->getValue(), false), '|');
    }
}
