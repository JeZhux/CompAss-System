<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for AuditLogService (ARCH-002 QA-006 / ARCH-002 QA-004; ARCH-001 §5.1).
 *
 * Reconstructs the baseline AuditLogServiceTest referenced in the task context.
 * The Phase 1 FK backfill (`audit_logs.user_id` → users.id, ON DELETE SET NULL)
 * makes the previous "insert with a fictional user_id" assertion fail at the
 * INSERT. The service's non-blocking contract (log() returns null on error)
 * is itself the invariant under test here (ARCH-002 QA-006), so the tests assert that
 * behavior rather than a persisted row for a non-existent user.
 *
 * @Traced-To ARCH-002 QA-006, ARCH-002 QA-004 (ARCH-001 §5.1, ARCH-004 §4.1, ARCH-004 §8.1)
 */
#[Group('audit-log')]
class AuditLogServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_creates_audit_record_with_real_user(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);

        $log = $this->app->make(AuditLogService::class)->log(
            'login',
            'Admin logged in',
            $user->id,
            User::class,
            $user->id,
            ['source' => 'spa']
        );

        $this->assertNotNull($log);
        $this->assertInstanceOf(AuditLog::class, $log);
        $this->assertSame(['source' => 'spa'], $log->metadata);
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'login',
            'user_id' => $user->id,
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'description' => 'Admin logged in',
        ]);
    }

    public function test_invalid_event_type_is_rejected_and_not_persisted(): void
    {
        $log = $this->app->make(AuditLogService::class)->log(
            'does_not_exist',
            'should not persist'
        );

        $this->assertNull($log);
        $this->assertDatabaseMissing('audit_logs', ['description' => 'should not persist']);
    }

    public function test_log_with_nonexistent_user_is_non_blocking(): void
    {
        $svc = $this->app->make(AuditLogService::class);

        // The Phase 1 FK backfill (audit_logs.user_id → users.id) means an insert
        // for id 999999 now violates the constraint. ARCH-002 QA-006 requires log() to
        // swallow the failure and return null (never propagate). We assert only
        // the return value: in PostgreSQL a failed statement aborts the
        // surrounding transaction, so issuing a follow-up SELECT here would raise
        // "current transaction is aborted" rather than prove the invariant.
        $result = $svc->log('create', 'created a user', 999999);

        $this->assertNull($result);
    }

    public function test_get_logs_for_user(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $svc = $this->app->make(AuditLogService::class);
        $svc->log('login', 'a', $user->id);
        $svc->log('logout', 'b', $user->id);

        $logs = $svc->getLogsForUser($user->id);

        $this->assertCount(2, $logs);
        $types = $logs->pluck('event_type')->sort()->values()->all();
        $this->assertSame(['login', 'logout'], $types);
    }

    public function test_get_audit_logs_supports_event_type_filter(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $svc = $this->app->make(AuditLogService::class);
        $svc->log('login', 'a', $user->id);
        $svc->log('create', 'b', $user->id);

        $this->assertCount(1, $svc->getAuditLogs('login'));
        $this->assertCount(2, $svc->getAuditLogs());
    }

    public function test_get_logs_for_entity_filters_by_auditable_type_and_id(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $svc = $this->app->make(AuditLogService::class);
        $svc->log('create', 'created user', $user->id, User::class, $user->id);
        $svc->log('update', 'updated other', $user->id, 'SomeOtherModel', 1);

        $logs = $svc->getLogsForEntity(User::class, $user->id);

        $this->assertCount(1, $logs);
        $this->assertSame('create', $logs->first()->event_type);
    }

    public function test_get_logs_for_entity_supports_event_type_filter(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $svc = $this->app->make(AuditLogService::class);
        $svc->log('create', 'a', $user->id, User::class, $user->id);
        $svc->log('update', 'b', $user->id, User::class, $user->id);

        $logs = $svc->getLogsForEntity(User::class, $user->id, 'update');

        $this->assertCount(1, $logs);
        $this->assertSame('update', $logs->first()->event_type);
    }

    public function test_anonymize_ip_addresses_masks_old_records(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 2));

        $svc = $this->app->make(AuditLogService::class);
        $svc->log('login', 'recent', null, null, null, ['ip' => '192.168.1.10'])
            ->update([
                'ip_address' => '192.168.1.10',
                'created_at' => Carbon::now(),
            ]);
        $svc->log('login', 'old', null, null, null, ['ip' => '10.0.0.5'])
            ->update([
                'ip_address' => '10.0.0.5',
                'created_at' => Carbon::now()->subDays(100),
            ]);

        $updated = $svc->anonymizeIpAddresses();

        $this->assertSame(1, $updated);
        $this->assertDatabaseHas('audit_logs', [
            'description' => 'recent',
            'ip_address' => '192.168.1.10',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'description' => 'old',
            'ip_address' => '10.0.0.0',
        ]);

        Carbon::setTestNow();
    }

    public function test_purge_old_logs_deletes_expired_entries(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 2));

        $svc = $this->app->make(AuditLogService::class);

        $svc->log('login', 'recent')->update([
            'created_at' => Carbon::now()->subDays(10),
        ]);
        $svc->log('login', 'expire_me')->update([
            'created_at' => Carbon::now()->subDays(400),
        ]);

        $deleted = $svc->purgeOldLogs(365);

        $this->assertSame(1, $deleted);
        $this->assertDatabaseHas('audit_logs', ['description' => 'recent']);
        $this->assertDatabaseMissing('audit_logs', ['description' => 'expire_me']);

        Carbon::setTestNow();
    }

    public function test_log_captures_null_ip_and_user_agent_in_console_context(): void
    {
        $svc = $this->app->make(AuditLogService::class);

        $log = $svc->log('login', 'console execution');

        $this->assertNotNull($log);
        $this->assertNull($log->ip_address);
        $this->assertNull($log->user_agent);
    }

    public function test_log_is_append_only_never_updates_existing_rows(): void
    {
        $svc = $this->app->make(AuditLogService::class);

        $first = $svc->log('login', 'first entry');
        $firstId = $first->id;

        $svc->log('logout', 'second entry', $first->user_id);

        $fresh = AuditLog::find($firstId);
        $this->assertSame('first entry', $fresh->description);
        $this->assertSame('login', $fresh->event_type);
    }

    public function test_get_audit_logs_supports_user_id_filter(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $otherUser = User::factory()->create(['must_change_password' => false]);
        $svc = $this->app->make(AuditLogService::class);

        $svc->log('login', 'user log 1', $user->id);
        $svc->log('login', 'user log 2', $user->id);
        $svc->log('login', 'other user log', $otherUser->id);
        $svc->log('login', 'no user log', null);

        $logs = $svc->getAuditLogs(null, $user->id);

        $this->assertCount(2, $logs);
        $descriptions = $logs->pluck('description')->sort()->values()->all();
        $this->assertSame(['user log 1', 'user log 2'], $descriptions);
    }

    public function test_get_audit_logs_supports_date_range_filter(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 2));

        $svc = $this->app->make(AuditLogService::class);

        $svc->log('login', 'oldest')->update([
            'created_at' => Carbon::now()->subDays(30),
        ]);
        $svc->log('login', 'in_range')->update([
            'created_at' => Carbon::now()->subDays(10),
        ]);
        $svc->log('login', 'newest')->update([
            'created_at' => Carbon::now(),
        ]);

        $fromDate = Carbon::now()->subDays(20)->toDateTimeString();
        $toDate = Carbon::now()->toDateTimeString();

        $logs = $svc->getAuditLogs(null, null, null, null, $fromDate, $toDate);

        $this->assertCount(2, $logs);
        $descriptions = $logs->pluck('description')->sort()->values()->all();
        $this->assertSame(['in_range', 'newest'], $descriptions);

        Carbon::setTestNow();
    }

    public function test_get_audit_logs_supports_combined_filters(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $otherUser = User::factory()->create(['must_change_password' => false]);
        $svc = $this->app->make(AuditLogService::class);

        $svc->log('login', 'both match', $user->id);
        $svc->log('login', 'event type only', $otherUser->id);
        $svc->log('create', 'user only', $user->id);

        $logs = $svc->getAuditLogs('login', $user->id);

        $this->assertCount(1, $logs);
        $this->assertSame('both match', $logs->first()->description);
    }

    public function test_get_audit_logs_with_no_filters_returns_all(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 2, 12, 0, 0));

        $user = User::factory()->create(['must_change_password' => false]);
        $otherUser = User::factory()->create(['must_change_password' => false]);
        $svc = $this->app->make(AuditLogService::class);

        $svc->log('login', 'first', $user->id)->update([
            'created_at' => Carbon::now()->subDays(5),
        ]);
        $svc->log('create', 'second', $otherUser->id)->update([
            'created_at' => Carbon::now()->subDays(3),
        ]);
        $svc->log('delete', 'third', null)->update([
            'created_at' => Carbon::now(),
        ]);

        $logs = $svc->getAuditLogs();

        $this->assertCount(3, $logs);
        $this->assertSame(['third', 'second', 'first'], $logs->pluck('description')->all());

        Carbon::setTestNow();
    }
}
