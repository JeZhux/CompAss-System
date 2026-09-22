<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Edge-case coverage for AuditLogService beyond the baseline AuditLogServiceTest:
 * IPv6 anonymization, idempotency, null/empty-IP handling, strict day boundaries,
 * default retention, strict event-type validation, metadata round-trips, date-range
 * filters on the entity/user readers, pagination gating, and the HTTP-context
 * IP/User-Agent capture branch.
 *
 * @Traced-To ARCH-002 QA-006, ARCH-002 QA-004 (ARCH-004 §8.1, ARCH-001 §5.1)
 */
#[Group('phase8-audit-service-edge')]
class Phase8AuditServiceEdgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymize_ip_addresses_masks_old_ipv6_records_to_slash_48(): void
    {
        $svc = $this->app->make(AuditLogService::class);

        $svc->log('login', 'v6_old', null, null, null, ['ip' => '2001:db8::1234'])
            ->update([
                'ip_address' => '2001:db8::1234',
                'created_at' => Carbon::now()->subDays(100),
            ]);

        $updated = $svc->anonymizeIpAddresses();

        $this->assertSame(1, $updated);
        $this->assertDatabaseHas('audit_logs', [
            'description' => 'v6_old',
            'ip_address' => '2001:db8::',
        ]);
        $this->assertDatabaseMissing('audit_logs', ['ip_address' => '2001:db8::1234']);
    }

    public function test_anonymize_ip_addresses_is_idempotent_on_already_masked_rows(): void
    {
        $svc = $this->app->make(AuditLogService::class);

        $svc->log('login', 'already_masked', null, null, null, ['ip' => '10.0.0.0'])
            ->update([
                'ip_address' => '10.0.0.0',
                'created_at' => Carbon::now()->subDays(100),
            ]);

        $updated = $svc->anonymizeIpAddresses();

        $this->assertSame(0, $updated);
        $this->assertDatabaseHas('audit_logs', [
            'description' => 'already_masked',
            'ip_address' => '10.0.0.0',
        ]);
    }

    public function test_anonymize_ip_addresses_skips_null_and_empty_ip_rows(): void
    {
        $svc = $this->app->make(AuditLogService::class);

        $svc->log('login', 'null_ip')->update([
            'ip_address' => null,
            'created_at' => Carbon::now()->subDays(100),
        ]);
        $svc->log('login', 'empty_ip')->update([
            'ip_address' => '',
            'created_at' => Carbon::now()->subDays(100),
        ]);

        $updated = $svc->anonymizeIpAddresses();

        $this->assertSame(0, $updated);
        $this->assertDatabaseHas('audit_logs', [
            'description' => 'null_ip',
            'ip_address' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'description' => 'empty_ip',
            'ip_address' => '',
        ]);
    }

    public function test_anonymize_ip_addresses_90_day_boundary_is_strict(): void
    {
        $svc = $this->app->make(AuditLogService::class);

        $exact = $svc->log('login', 'exactly_90_days', null, null, null, ['ip' => '172.16.1.10']);
        // +1s keeps the row strictly inside the boundary: created_at is a
        // timestamp(0) column (whole-second rounding), while NOW() in the
        // anonymize WHERE carries microseconds — an exact-NOW-relative value
        // could round below the strict `<` cut.
        DB::table('audit_logs')->where('id', $exact->id)->update([
            'ip_address' => '172.16.1.10',
            'created_at' => DB::raw("NOW() - INTERVAL '90 days' + INTERVAL '1 second'"),
        ]);

        $svc->log('login', 'older_than_90', null, null, null, ['ip' => '172.16.2.20'])
            ->update([
                'ip_address' => '172.16.2.20',
                'created_at' => Carbon::now()->subDays(91),
            ]);

        $updated = $svc->anonymizeIpAddresses();

        $this->assertSame(1, $updated);
        $this->assertDatabaseHas('audit_logs', [
            'description' => 'exactly_90_days',
            'ip_address' => '172.16.1.10',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'description' => 'older_than_90',
            'ip_address' => '172.16.2.0',
        ]);
    }

    public function test_purge_old_logs_defaults_to_365_day_retention(): void
    {
        $svc = $this->app->make(AuditLogService::class);

        $svc->log('login', 'older_than_default')->update([
            'created_at' => Carbon::now()->subDays(400),
        ]);
        $svc->log('login', 'within_default')->update([
            'created_at' => Carbon::now()->subDays(300),
        ]);

        $deleted = $svc->purgeOldLogs();

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('audit_logs', ['description' => 'older_than_default']);
        $this->assertDatabaseHas('audit_logs', ['description' => 'within_default']);
    }

    public function test_purge_old_logs_365_day_boundary_is_strict(): void
    {
        $svc = $this->app->make(AuditLogService::class);

        $kept = $svc->log('login', 'exactly_365_days');
        // +1s guard: a row at the exact NOW()-relative cut survives the strict
        // `<` check, but timestamp(0) rounding could push an exact value below
        // it; +1s keeps the row deterministically just inside the boundary.
        DB::table('audit_logs')->where('id', $kept->id)->update([
            'created_at' => DB::raw("NOW() - INTERVAL '365 days' + INTERVAL '1 second'"),
        ]);

        $expired = $svc->log('login', 'older_than_365_days');
        DB::table('audit_logs')->where('id', $expired->id)->update([
            'created_at' => DB::raw("NOW() - INTERVAL '366 days'"),
        ]);

        $deleted = $svc->purgeOldLogs(365);

        $this->assertSame(1, $deleted);
        $this->assertDatabaseHas('audit_logs', ['description' => 'exactly_365_days']);
        $this->assertDatabaseMissing('audit_logs', ['description' => 'older_than_365_days']);
    }

    public function test_log_rejects_mixed_case_and_whitespace_event_types(): void
    {
        $svc = $this->app->make(AuditLogService::class);

        $this->assertNull($svc->log('Login', 'mixed case rejected'));
        $this->assertNull($svc->log(' CREATE', 'leading whitespace rejected'));
        $this->assertDatabaseMissing('audit_logs', ['description' => 'mixed case rejected']);
        $this->assertDatabaseMissing('audit_logs', ['description' => 'leading whitespace rejected']);
    }

    public function test_log_accepts_less_common_valid_event_types(): void
    {
        $svc = $this->app->make(AuditLogService::class);

        // 'export' was renamed to 'error_report_download' (ARCH-004 §4.1, ARCH-004 §4.1).
        $download = $svc->log('error_report_download', 'error report downloaded');
        $other = $svc->log('other', 'misc event');

        $this->assertNotNull($download);
        $this->assertNotNull($other);
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'error_report_download',
            'description' => 'error report downloaded',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'other',
            'description' => 'misc event',
        ]);
    }

    public function test_log_round_trips_nested_metadata(): void
    {
        $svc = $this->app->make(AuditLogService::class);
        $metadata = [
            'attempts' => ['ip' => 'x', 'count' => 3],
            'flag' => true,
        ];

        $log = $svc->log('login', 'nested metadata', null, null, null, $metadata);

        $this->assertSame($metadata, $log->metadata);

        $fresh = AuditLog::find($log->id);
        $this->assertSame(['ip' => 'x', 'count' => 3], $fresh->metadata['attempts']);
        $this->assertTrue($fresh->metadata['flag']);
    }

    public function test_log_round_trips_null_metadata_values_and_empty_arrays(): void
    {
        $svc = $this->app->make(AuditLogService::class);

        $withNulls = $svc->log('create', 'null values', null, null, null, ['a' => null, 'b' => 'x', 'c' => 2]);
        $empty = $svc->log('create', 'empty metadata', null, null, null, []);

        $this->assertSame(['a' => null, 'b' => 'x', 'c' => 2], $withNulls->metadata);
        $this->assertSame([], $empty->metadata);
        $this->assertSame(['a' => null, 'b' => 'x', 'c' => 2], AuditLog::find($withNulls->id)->metadata);
        $this->assertSame([], AuditLog::find($empty->id)->metadata);
    }

    public function test_get_logs_for_user_supports_date_range_with_date_only_to(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $svc = $this->app->make(AuditLogService::class);

        $svc->log('login', 'user_old', $user->id)->update([
            'created_at' => Carbon::now()->subDays(30),
        ]);
        $svc->log('login', 'user_mid', $user->id)->update([
            'created_at' => Carbon::now()->subDays(10),
        ]);
        $svc->log('login', 'user_today', $user->id)->update([
            'created_at' => Carbon::now(),
        ]);

        $from = Carbon::now()->subDays(20)->toDateTimeString();
        $to = Carbon::now()->toDateString();

        $logs = $svc->getLogsForUser($user->id, null, $from, $to);

        $this->assertCount(2, $logs);
        $this->assertSame(['user_today', 'user_mid'], $logs->pluck('description')->all());
    }

    public function test_get_logs_for_entity_supports_date_range_with_date_only_to(): void
    {
        $svc = $this->app->make(AuditLogService::class);

        $svc->log('create', 'entity_old', null, User::class, 7)->update([
            'created_at' => Carbon::now()->subDays(30),
        ]);
        $svc->log('update', 'entity_recent', null, User::class, 7)->update([
            'created_at' => Carbon::now()->subDays(5),
        ]);
        $svc->log('delete', 'entity_today', null, User::class, 7)->update([
            'created_at' => Carbon::now(),
        ]);

        $from = Carbon::now()->subDays(10)->toDateTimeString();
        $to = Carbon::now()->toDateString();

        $logs = $svc->getLogsForEntity(User::class, 7, null, $from, $to);

        $this->assertCount(2, $logs);
        $this->assertSame(['entity_today', 'entity_recent'], $logs->pluck('description')->all());
    }

    public function test_per_page_is_ignored_when_page_is_null(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $svc = $this->app->make(AuditLogService::class);

        $svc->log('login', 'all_1', $user->id);
        $svc->log('login', 'all_2', $user->id);
        $svc->log('login', 'all_3', $user->id);

        $all = $svc->getAuditLogs(null, null, null, null, null, null, null, 1);
        $forUser = $svc->getLogsForUser($user->id, null, null, null, null, 1);

        $this->assertInstanceOf(Collection::class, $all);
        $this->assertCount(3, $all);
        $this->assertInstanceOf(Collection::class, $forUser);
        $this->assertCount(3, $forUser);
    }

    public function test_pagination_activates_only_when_page_is_provided(): void
    {
        $svc = $this->app->make(AuditLogService::class);

        $svc->log('login', 'paged_1');
        $svc->log('login', 'paged_2');
        $svc->log('login', 'paged_3');

        $page = $svc->getAuditLogs(null, null, null, null, null, null, 1, 2);

        $this->assertInstanceOf(LengthAwarePaginator::class, $page);
        $this->assertSame(3, $page->total());
        $this->assertSame(2, $page->perPage());
        $this->assertCount(2, $page->items());
    }

    public function test_get_audit_logs_supports_auditable_type_and_id_filters(): void
    {
        $svc = $this->app->make(AuditLogService::class);

        $svc->log('create', 'for_user_7', null, User::class, 7);
        $svc->log('update', 'for_user_9', null, User::class, 9);
        $svc->log('delete', 'for_other', null, 'SomeOtherModel', 7);

        $byType = $svc->getAuditLogs(null, null, User::class);
        $byTypeAndId = $svc->getAuditLogs(null, null, User::class, 7);

        $this->assertCount(2, $byType);
        $this->assertCount(1, $byTypeAndId);
        $this->assertSame('for_user_7', $byTypeAndId->first()->description);
    }

    public function test_log_captures_ip_and_user_agent_in_http_context(): void
    {
        $this->app->instance('request', Request::create(
            '/api/test',
            'GET',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => '192.168.5.7',
                'HTTP_USER_AGENT' => 'Phase8EdgeAgent/1.0',
            ]
        ));

        (new ReflectionProperty($this->app, 'isRunningInConsole'))->setValue($this->app, false);

        $log = $this->app->make(AuditLogService::class)->log('login', 'http context');

        $this->assertSame('192.168.5.7', $log->ip_address);
        $this->assertSame('Phase8EdgeAgent/1.0', $log->user_agent);
        $this->assertDatabaseHas('audit_logs', [
            'description' => 'http context',
            'ip_address' => '192.168.5.7',
            'user_agent' => 'Phase8EdgeAgent/1.0',
        ]);
    }
}
