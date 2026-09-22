<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Services\AuditLogService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Phase 6 — ARCH-002 QA-006: the daily audit retention jobs (365-day purge +
 * 90-day IP anonymization, registered via withSchedule() in
 * bootstrap/app.php) execute through the scheduler, and IP anonymization
 * is irreversible on already-anonymized rows (ARCH-002 QA-006, ARCH-004 §8.1).
 *
 * Phase 9 covers the same jobs at the SERVICE level; this class proves
 * execution through `php artisan schedule:run` and the irreversibility
 * invariant that no un-masking path exists.
 *
 * @Traced-To ARCH-002 QA-006, ARCH-002 QA-006 (ARCH-002 QA-006)
 */
#[Group('phase6-scheduler')]
class Phase6SchedulerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function createAuditRow(array $overrides = []): AuditLog
    {
        return AuditLog::create(array_merge([
            'event_type' => 'login',
            'description' => 'Login activity.',
            'user_id' => null,
            'auditable_type' => null,
            'auditable_id' => null,
            'ip_address' => null,
            'metadata' => [],
            'created_at' => now(),
        ], $overrides));
    }

    // ---------------------------------------------------------------------
    // Scheduler execution & anonymization irreversibility (ARCH-002 QA-006)
    // ---------------------------------------------------------------------

    public function test_schedule_run_executes_both_retention_jobs(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 2, 0, 0, 0));

        $dueForAnonymization = $this->createAuditRow(['ip_address' => '192.168.1.10', 'created_at' => now()->subDays(91)]);
        $dueForPurge = $this->createAuditRow(['created_at' => now()->subDays(400)]);
        $fresh = $this->createAuditRow(['ip_address' => '10.0.0.5', 'created_at' => now()]);

        $this->artisan('schedule:run')->assertExitCode(0);

        $this->assertSame('192.168.1.0', $dueForAnonymization->fresh()->ip_address);
        $this->assertDatabaseMissing('audit_logs', ['id' => $dueForPurge->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $fresh->id, 'ip_address' => '10.0.0.5']);
    }

    public function test_anonymization_is_irreversible_on_already_anonymized_rows(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 2, 0, 0, 0));

        $masked = $this->createAuditRow(['ip_address' => '192.168.1.0', 'created_at' => now()->subDays(91)]);

        $this->assertSame(0, app(AuditLogService::class)->anonymizeIpAddresses());
        $this->assertSame('192.168.1.0', $masked->fresh()->ip_address);
        $this->assertDatabaseHas('audit_logs', ['id' => $masked->id]);

        $this->artisan('schedule:run')->assertExitCode(0);

        $this->assertSame('192.168.1.0', $masked->fresh()->ip_address);
        $this->assertDatabaseHas('audit_logs', ['id' => $masked->id]);
    }

    public function test_scheduler_is_registered_with_daily_frequency(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 2, 0, 0, 0));

        $this->artisan('list');

        $schedule = app(Schedule::class);

        // Two audit retention jobs (Phase 9) + AUD-013 import error-report
        // cleanup — all daily.
        $this->assertCount(3, $schedule->events());
        $this->assertCount(3, $schedule->dueEvents($this->app));
    }
}
