<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F-08 — Audit reads and login-block logs retain identifying data.
 *
 * - Admin audit reads keep raw IPs inside the 90-day window but MUST NOT
 *   return reversible IPs for rows older than
 *   config('audit.ip_anonymization_days').
 * - Login rate-limit block entries MUST mask/omit the raw identifier
 *   (masked form only) while retaining fact/time/outcome.
 *
 * @Traced-To ARCH-002 QA-006 (F-08)
 */
#[Group('f08-audit-privacy')]
class F08AuditPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private const INDEX_URL = '/api/admin/audit-logs';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function actAsAdmin(): User
    {
        $admin = User::factory()->create([
            'role' => 'Admin',
            'must_change_password' => false,
        ]);

        Sanctum::actingAs($admin);

        return $admin;
    }

    private function createAuditRow(string $ip, Carbon $createdAt): AuditLog
    {
        return AuditLog::create([
            'event_type' => 'login',
            'description' => 'Login activity.',
            'user_id' => null,
            'auditable_type' => null,
            'auditable_id' => null,
            'ip_address' => $ip,
            'metadata' => [],
            'created_at' => $createdAt,
        ]);
    }

    public function test_admin_read_returns_raw_ip_within_window(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 2, 12, 0, 0));
        $this->actAsAdmin();
        $this->createAuditRow('192.168.1.77', now()->subDays(10));

        $response = $this->get(self::INDEX_URL);

        $response->assertOk();
        $this->assertSame('192.168.1.77', $response->json('data.0.ip_address'));
    }

    public function test_admin_read_masks_ip_older_than_window(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 2, 12, 0, 0));
        $this->actAsAdmin();
        $this->createAuditRow('192.168.1.77', now()->subDays(91));

        $response = $this->get(self::INDEX_URL);

        $response->assertOk();
        $this->assertSame('192.168.1.0', $response->json('data.0.ip_address'));
        $this->assertNotSame('192.168.1.77', $response->json('data.0.ip_address'));
    }

    public function test_admin_read_honors_configured_window(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 2, 12, 0, 0));
        $this->actAsAdmin();
        config(['audit.ip_anonymization_days' => 5]);
        $this->createAuditRow('10.1.2.3', now()->subDays(10));
        $this->createAuditRow('10.1.2.4', now()->subDays(2));

        $response = $this->get(self::INDEX_URL);

        $response->assertOk();
        // Newest first: 2-day-old row keeps its raw IP, 10-day-old row is masked.
        $this->assertSame('10.1.2.4', $response->json('data.0.ip_address'));
        $this->assertSame('10.1.2.0', $response->json('data.1.ip_address'));
    }

    public function test_rate_limit_block_masks_identifier_but_keeps_fact_time_outcome(): void
    {
        $student = User::factory()->student()->create([
            'must_change_password' => false,
        ]);
        $identifier = $student->school_id;

        for ($i = 0; $i < 5; $i++) {
            $this->post('/api/auth/login', [
                'identifier' => $identifier,
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $this->post('/api/auth/login', [
            'identifier' => $identifier,
            'password' => 'wrong-password',
        ])->assertStatus(429);

        $audit = AuditLog::where('event_type', 'login')
            ->where('metadata->identifier_masked', AuditLogService::maskIdentifier($identifier))
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        // No raw identifier beyond the abuse-response need.
        $this->assertArrayNotHasKey('identifier', $audit->metadata);
        $this->assertArrayNotHasKey('email', $audit->metadata);
        $this->assertArrayNotHasKey('school_id', $audit->metadata);
        $this->assertArrayNotHasKey('ip', $audit->metadata);
        $this->assertArrayNotHasKey('ip_address', $audit->metadata);
        $this->assertNotSame($identifier, $audit->metadata['identifier_masked']);
        // Fact / time / outcome retained.
        $this->assertSame('login', $audit->event_type);
        $this->assertSame('Rate limit exceeded: blocked login attempt', $audit->description);
        $this->assertNotNull($audit->created_at);
        $this->assertSame(5, $audit->metadata['attempts']);
    }
}
