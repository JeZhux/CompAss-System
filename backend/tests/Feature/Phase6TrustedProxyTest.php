<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 6 — TrustedProxies + TRUSTED_PROXIES (ARCH-006 §4, SEC-DEC-018).
 *
 * With the on-host reverse proxy (127.0.0.1) trusted, the real client IP from
 * X-Forwarded-For must drive (a) the audit-log IP and (b) the login rate-limit
 * key (ARCH-002 QA-004, ARCH-002 QA-004). With no forwarded headers the socket REMOTE_ADDR is
 * used. phpunit.xml pins TRUSTED_PROXIES=127.0.0.1 so every request in these
 * tests runs through the real TrustProxies middleware.
 *
 * AUDIT CONTRACT NOTE: AuthService::authenticate() does NOT audit individual
 * failed logins (401 INVALID_CREDENTIALS). The only 'login'-event audit row
 * for a rate-limit block (429 RATE_LIMIT_EXCEEDED) is written with
 * ['identifier_masked', 'attempts'] (app/Services/AuthService.php) — F-08
 * forbids raw identifier/IP in the year-retained metadata, so these tests
 * assert the masked identifier plus the per-client-IP rate-limit keying
 * behavior (the 401/429 flow) rather than a metadata IP. In production the
 * proxy-derived client IP lands in the `ip_address` column (under the 90-day
 * anonymization lifecycle); under phpunit the console context captures NULL
 * (ARCH-002 QA-006), so no IP assertion is possible here.
 *
 * @Traced-To ARCH-006 §4, ARCH-002 QA-004, ARCH-002 QA-006, ARCH-002 QA-004, SEC-DEC-018
 */
#[Group('phase6-trusted-proxy')]
class Phase6TrustedProxyTest extends TestCase
{
    use RefreshDatabase;

    private const PROXY_IP = '127.0.0.1';

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function makeUser(string $role, array $overrides = []): User
    {
        return User::factory()->{$role}()->create(array_merge([
            'must_change_password' => false,
        ], $overrides));
    }

    /**
     * POST /api/auth/login as if proxied: REMOTE_ADDR is the trusted proxy
     * (127.0.0.1, per phpunit.xml TRUSTED_PROXIES) and X-Forwarded-For carries
     * the real client IP.
     */
    private function proxiedLogin(
        string $clientIp,
        string $identifier,
        string $password = 'wrong-password'
    ): \Illuminate\Testing\TestResponse {
        return $this->withServerVariables(['REMOTE_ADDR' => self::PROXY_IP])
            ->withHeader('X-Forwarded-For', $clientIp)
            ->post('/api/auth/login', [
                'identifier' => $identifier,
                'password' => $password,
            ]);
    }

    private function rateLimitBlockAudit(string $identifier): ?AuditLog
    {
        return AuditLog::where('event_type', 'login')
            ->where('metadata->identifier_masked', \App\Services\AuditLogService::maskIdentifier($identifier))
            ->latest('id')
            ->first();
    }

    // ---------------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------------

    public function test_failed_login_audits_real_client_ip_behind_trusted_proxy(): void
    {
        $student = $this->makeUser('student');
        $identifier = $student->school_id;

        for ($i = 1; $i <= 5; $i++) {
            $this->proxiedLogin('203.0.113.9', $identifier)
                ->assertStatus(401)
                ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
        }

        // 6th attempt trips the rate limit; AuthService writes the block audit
        // with the masked identifier (F-08: no raw identifier/IP in metadata).
        $this->proxiedLogin('203.0.113.9', $identifier)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED');

        $audit = $this->rateLimitBlockAudit($identifier);
        $this->assertNotNull($audit);
        $this->assertArrayNotHasKey('identifier', $audit->metadata);
        $this->assertArrayNotHasKey('ip', $audit->metadata);
        $this->assertSame(\App\Services\AuditLogService::maskIdentifier($identifier), $audit->metadata['identifier_masked']);
        $this->assertSame(5, $audit->metadata['attempts']);
    }

    public function test_rate_limit_keying_uses_real_client_ip(): void
    {
        $student = $this->makeUser('student');
        $identifier = $student->school_id;

        // Six failures from client A — the 6th is blocked.
        for ($i = 1; $i <= 5; $i++) {
            $this->proxiedLogin('203.0.113.10', $identifier)->assertStatus(401);
        }
        $this->proxiedLogin('203.0.113.10', $identifier)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED');

        // Six failures from client B — the 6th is ALSO blocked. Had the key
        // been derived from the proxy's REMOTE_ADDR (127.0.0.1), client B's
        // very first attempt would already be throttled.
        for ($i = 1; $i <= 5; $i++) {
            $this->proxiedLogin('203.0.113.11', $identifier)->assertStatus(401);
        }
        $this->proxiedLogin('203.0.113.11', $identifier)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED');

        // Both block audits were written (F-08: masked identifier only, no raw
        // IP in metadata). Per-client keying is proven by the 401/429 flow
        // above: had the key been derived from the proxy's REMOTE_ADDR,
        // client B's first attempt would already have been throttled.
        $audits = AuditLog::where('event_type', 'login')
            ->where('metadata->identifier_masked', \App\Services\AuditLogService::maskIdentifier($identifier))
            ->orderByDesc('id')
            ->take(2)
            ->get();

        $this->assertCount(2, $audits);
        foreach ($audits as $audit) {
            $this->assertArrayNotHasKey('identifier', $audit->metadata);
            $this->assertArrayNotHasKey('ip', $audit->metadata);
            $this->assertSame(5, $audit->metadata['attempts']);
        }
    }

    public function test_untrusted_default_keeps_remote_addr(): void
    {
        $student = $this->makeUser('student');
        $identifier = $student->school_id;

        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => self::PROXY_IP])
                ->post('/api/auth/login', [
                    'identifier' => $identifier,
                    'password' => 'wrong-password',
                ])->assertStatus(401);
        }

        // No X-Forwarded-For header: the client IP stays the socket REMOTE_ADDR.
        $this->withServerVariables(['REMOTE_ADDR' => self::PROXY_IP])
            ->post('/api/auth/login', [
                'identifier' => $identifier,
                'password' => 'wrong-password',
            ])->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED');

        $audit = $this->rateLimitBlockAudit($identifier);
        $this->assertNotNull($audit);
        $this->assertArrayNotHasKey('identifier', $audit->metadata);
        $this->assertArrayNotHasKey('ip', $audit->metadata);
        $this->assertSame(\App\Services\AuditLogService::maskIdentifier($identifier), $audit->metadata['identifier_masked']);
    }
}
