<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 8 — Audit read endpoints input-matrix gaps (ARCH-005 block 4.8, #96–#98).
 *
 * Complements Phase8AuditLogTest by pinning the input-matrix holes that pass
 * left open: invalid date strings, nonexistent user_id / entity ids (filters
 * apply against audit_logs, not the referenced tables), type-only
 * auditable_type filtering, the full EVENT_TYPES enum being filterable,
 * per-endpoint pagination and date windows on #97/#98, merged route-param +
 * query validation on #98, meta/item-shape parity across all three endpoints,
 * and the per_page=100 upper bound on #96.
 *
 * @Traced-To ARCH-002 QA-006 (ARCH-005 block 4.8)
 */
#[Group('phase8-audit-read-matrix')]
class Phase8AuditReadMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const INDEX_URL = '/api/admin/audit-logs';

    private const ENTITY_URL = '/api/admin/audit-logs/entity';

    private const USER_URL = '/api/admin/audit-logs/user';

    private ?User $admin = null;

    private ?AuditLogService $auditLogService = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = null;
        $this->auditLogService = $this->app->make(AuditLogService::class);
    }

    private function actAsAdmin(): User
    {
        if (! $this->admin) {
            $this->admin = User::factory()->create([
                'role' => 'Admin',
                'must_change_password' => false,
            ]);
        }

        Sanctum::actingAs($this->admin);

        return $this->admin;
    }

    /**
     * Append an audit row through the service (the sole writer of audit_logs).
     *
     * @return AuditLog
     */
    private function seedAuditRow(
        string $type,
        ?User $user = null,
        ?string $auditableType = null,
        ?int $auditableId = null,
        string $description = ''
    ): AuditLog {
        return $this->auditLogService->log(
            $type,
            $description !== '' ? $description : $type . '-entry',
            $user?->id,
            $auditableType,
            $auditableId,
        );
    }

    /**
     * Build an #97 entity-scoped URL with optional event_type filter.
     */
    private function entityUrl(string $auditableType, int $auditableId, string $eventType = ''): string
    {
        return self::ENTITY_URL . '?' . http_build_query(array_filter([
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'event_type' => $eventType,
        ]));
    }

    // ========================================================================
    // #96 — GET /api/admin/audit-logs
    // ========================================================================

    public function test_96_invalid_from_and_to_date_strings_return_422_with_fields(): void
    {
        $this->actAsAdmin();

        $badFrom = $this->get(self::INDEX_URL . '?from=not-a-date');
        $badFrom->assertStatus(422);
        $badFrom->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('from', $badFrom->json('error.fields'));

        $badTo = $this->get(self::INDEX_URL . '?to=not-a-date');
        $badTo->assertStatus(422);
        $badTo->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('to', $badTo->json('error.fields'));
    }

    public function test_96_user_id_of_nonexistent_user_returns_empty_data_not_404(): void
    {
        $this->actAsAdmin();
        $user = User::factory()->create();
        $this->seedAuditRow('login', $user);

        $response = $this->get(self::INDEX_URL . '?user_id=999999');

        // The filter is applied against audit_logs.user_id, not the users
        // table, so a dangling id is a plain no-match, not an error (pin).
        $response->assertOk();
        $response->assertJsonPath('data', []);
        $response->assertJsonPath('meta.total', 0);
        $response->assertJsonPath('meta.current_page', 1);
        $response->assertJsonPath('meta.last_page', 1);
        $response->assertJsonPath('meta.per_page', 15);
    }

    public function test_96_user_id_zero_skips_filter_and_returns_all_rows(): void
    {
        $this->actAsAdmin();
        $user = User::factory()->create();
        $this->seedAuditRow('login', $user);
        $this->seedAuditRow('logout', $user);

        // AuditLogService::auditQuery only applies user_id when truthy, so 0
        // is treated as "no filter" despite passing the `integer` rule (pin).
        $response = $this->get(self::INDEX_URL . '?user_id=0');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $response->assertJsonPath('meta.total', 2);
    }

    public function test_96_auditable_type_alone_filters_by_type_only(): void
    {
        $this->actAsAdmin();
        $user = User::factory()->create();
        $this->seedAuditRow('create', $user, User::class, $user->id);
        $this->seedAuditRow('update', $user, Announcement::class, 42);

        $response = $this->get(self::INDEX_URL . '?auditable_type=' . urlencode(User::class));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame(User::class, $response->json('data.0.auditable_type'));
        $this->assertSame($user->id, $response->json('data.0.auditable_id'));
    }

    public function test_96_error_report_download_and_purge_semesters_event_types_are_filterable(): void
    {
        $this->actAsAdmin();
        $user = User::factory()->create();
        $this->seedAuditRow('login', $user);

        // Both are valid EVENT_TYPES never written by the tests — they must
        // still be accepted by the `in` rule and yield a clean no-match.
        // 'export' was renamed to 'error_report_download' (ARCH-004 §4.1, ARCH-004 §4.1).
        foreach (['error_report_download', 'purge_semesters'] as $type) {
            $response = $this->get(self::INDEX_URL . '?event_type=' . $type);
            $response->assertOk();
            $response->assertJsonPath('data', []);
            $response->assertJsonPath('meta.total', 0);
        }
    }

    public function test_96_per_page_max_allowed_ok_and_over_limit_clamped(): void
    {
        $this->actAsAdmin();
        $user = User::factory()->create();
        for ($i = 0; $i < 3; $i++) {
            $this->seedAuditRow('login', $user);
        }

        $max = $this->get(self::INDEX_URL . '?per_page=100');
        $max->assertOk();
        $max->assertJsonPath('meta.per_page', 100);
        $max->assertJsonPath('meta.total', 3);
        $this->assertCount(3, $max->json('data'));

        // ARCH-005 §2: over-limit no longer 422s — clamps to the 100 ceiling.
        $over = $this->get(self::INDEX_URL . '?per_page=101');
        $over->assertOk();
        $over->assertJsonPath('meta.per_page', 100);
        $over->assertJsonPath('meta.total', 3);
        $this->assertCount(3, $over->json('data'));
    }

    // ========================================================================
    // #97 — GET /api/admin/audit-logs/entity
    // ========================================================================

    public function test_97_pagination_pages_meta_and_out_of_range_422(): void
    {
        $this->actAsAdmin();
        $user = User::factory()->create();
        $first = $this->seedAuditRow('create', $user, User::class, $user->id);
        $second = $this->seedAuditRow('update', $user, User::class, $user->id);
        $third = $this->seedAuditRow('delete', $user, User::class, $user->id);

        $page1 = $this->get($this->entityUrl(User::class, $user->id) . '&per_page=1');
        $page1->assertOk();
        $page1->assertJsonPath('meta.per_page', 1);
        $page1->assertJsonPath('meta.total', 3);
        $page1->assertJsonPath('meta.last_page', 3);
        $page1->assertJsonPath('meta.current_page', 1);
        $this->assertCount(1, $page1->json('data'));
        $this->assertSame($third->id, $page1->json('data.0.id'));

        $page2 = $this->get($this->entityUrl(User::class, $user->id) . '&per_page=1&page=2');
        $page2->assertOk();
        $page2->assertJsonPath('meta.current_page', 2);
        $page2->assertJsonPath('meta.from', 2);
        $page2->assertJsonPath('meta.to', 2);
        $this->assertCount(1, $page2->json('data'));
        $this->assertSame($second->id, $page2->json('data.0.id'));

        $beyond = $this->get($this->entityUrl(User::class, $user->id) . '&page=99');
        $beyond->assertOk();
        $beyond->assertJsonPath('data', []);
        $beyond->assertJsonPath('meta.current_page', 99);
        $beyond->assertJsonPath('meta.total', 3);
        // No per_page sent, so it defaults to 15 → last_page = ceil(3/15) = 1.
        $beyond->assertJsonPath('meta.last_page', 1);
        $this->assertNull($beyond->json('meta.from'));
        $this->assertNull($beyond->json('meta.to'));

        // ARCH-005 §2: over-limit no longer 422s — clamps to the 100 ceiling.
        $badPerPage = $this->get($this->entityUrl(User::class, $user->id) . '&per_page=101');
        $badPerPage->assertOk();
        $badPerPage->assertJsonPath('meta.per_page', 100);

        $badPage = $this->get($this->entityUrl(User::class, $user->id) . '&page=0');
        $badPage->assertStatus(422);
        $badPage->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('page', $badPage->json('error.fields'));
    }

    public function test_97_from_to_date_filters_with_end_of_day_to(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 2, 12, 0, 0));
        $this->actAsAdmin();
        try {
            $user = User::factory()->create();
            $rows = [];
            $rows['before'] = $this->seedAuditRow('create', $user, User::class, $user->id, 'before');
            $rows['boundary-morning'] = $this->seedAuditRow('create', $user, User::class, $user->id, 'boundary-morning');
            $rows['boundary-afternoon'] = $this->seedAuditRow('create', $user, User::class, $user->id, 'boundary-afternoon');
            $rows['after'] = $this->seedAuditRow('create', $user, User::class, $user->id, 'after');
            $rows['before']->update(['created_at' => Carbon::create(2026, 7, 30, 9, 0, 0)]);
            $rows['boundary-morning']->update(['created_at' => Carbon::create(2026, 7, 31, 11, 0, 0)]);
            $rows['boundary-afternoon']->update(['created_at' => Carbon::create(2026, 7, 31, 15, 0, 0)]);
            $rows['after']->update(['created_at' => Carbon::create(2026, 8, 1, 9, 0, 0)]);

            // `from` starts at midnight of the given day.
            $fromOnly = $this->get($this->entityUrl(User::class, $user->id) . '&from=2026-07-31');
            $fromOnly->assertOk();
            $fromDescriptions = array_column($fromOnly->json('data'), 'description');
            $this->assertCount(3, $fromDescriptions);
            $this->assertContains('boundary-afternoon', $fromDescriptions);
            $this->assertNotContains('before', $fromDescriptions);

            // Date-only `to` is end-of-day inclusive — the 15:00 row survives.
            $toOnly = $this->get($this->entityUrl(User::class, $user->id) . '&to=2026-07-31');
            $toOnly->assertOk();
            $toDescriptions = array_column($toOnly->json('data'), 'description');
            $this->assertCount(3, $toDescriptions);
            $this->assertContains('before', $toDescriptions);
            $this->assertContains('boundary-afternoon', $toDescriptions);
            $this->assertNotContains('after', $toDescriptions);

            // Same-day window resolves to the two boundary rows only.
            $window = $this->get($this->entityUrl(User::class, $user->id) . '&from=2026-07-31&to=2026-07-31');
            $window->assertOk();
            $this->assertCount(2, $window->json('data'));

            // Full datetime `to` passes through unchanged (mid-day cut).
            $fullDatetime = $this->get(
                $this->entityUrl(User::class, $user->id) . '&to=' . urlencode('2026-07-31 12:00:00')
            );
            $fullDatetime->assertOk();
            $fullDescriptions = array_column($fullDatetime->json('data'), 'description');
            $this->assertContains('boundary-morning', $fullDescriptions);
            $this->assertNotContains('boundary-afternoon', $fullDescriptions);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_97_invalid_event_type_422_and_delete_miss_empty(): void
    {
        $this->actAsAdmin();
        $user = User::factory()->create();
        $this->seedAuditRow('create', $user, User::class, $user->id);

        $invalid = $this->get($this->entityUrl(User::class, $user->id) . '&event_type=bogus_event');
        $invalid->assertStatus(422);
        $invalid->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('event_type', $invalid->json('error.fields'));

        $miss = $this->get($this->entityUrl(User::class, $user->id, 'delete'));
        $miss->assertOk();
        $miss->assertJsonPath('data', []);
        $miss->assertJsonPath('meta.total', 0);
    }

    public function test_97_nonexistent_entity_returns_empty_data_not_404(): void
    {
        $this->actAsAdmin();
        $user = User::factory()->create();
        $this->seedAuditRow('create', $user, User::class, $user->id);

        $response = $this->get($this->entityUrl(User::class, 999999));

        $response->assertOk();
        $response->assertJsonPath('data', []);
        $response->assertJsonPath('meta.total', 0);
        $response->assertJsonPath('meta.current_page', 1);
        $response->assertJsonPath('meta.last_page', 1);
    }

    // ========================================================================
    // #98 — GET /api/admin/audit-logs/user/{userId}
    // ========================================================================

    public function test_98_event_type_filter_hit_and_miss(): void
    {
        $this->actAsAdmin();
        $alice = User::factory()->create();
        $this->seedAuditRow('login', $alice);
        $this->seedAuditRow('logout', $alice);

        $hit = $this->get(self::USER_URL . '/' . $alice->id . '?event_type=login');
        $hit->assertOk();
        $hit->assertJsonPath('meta.total', 1);
        $this->assertCount(1, $hit->json('data'));
        $this->assertSame('login', $hit->json('data.0.event_type'));

        $miss = $this->get(self::USER_URL . '/' . $alice->id . '?event_type=delete');
        $miss->assertOk();
        $miss->assertJsonPath('data', []);
        $miss->assertJsonPath('meta.total', 0);
    }

    public function test_98_invalid_event_type_returns_422_with_fields(): void
    {
        $this->actAsAdmin();
        $alice = User::factory()->create();

        // Route param is merged into the validation data, so a bad query
        // value must still surface through the same envelope.
        $response = $this->get(self::USER_URL . '/' . $alice->id . '?event_type=bogus_event');

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('event_type', $response->json('error.fields'));
    }

    public function test_98_from_to_date_filters_with_end_of_day_to(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 2, 12, 0, 0));
        $this->actAsAdmin();
        try {
            $alice = User::factory()->create();
            $rows = [];
            $rows['before'] = $this->seedAuditRow('login', $alice, null, null, 'before');
            $rows['boundary'] = $this->seedAuditRow('login', $alice, null, null, 'boundary');
            $rows['after'] = $this->seedAuditRow('login', $alice, null, null, 'after');
            $rows['before']->update(['created_at' => Carbon::create(2026, 7, 30, 9, 0, 0)]);
            $rows['boundary']->update(['created_at' => Carbon::create(2026, 7, 31, 18, 0, 0)]);
            $rows['after']->update(['created_at' => Carbon::create(2026, 8, 1, 9, 0, 0)]);

            // Same-day window: date-only `to` is end-of-day inclusive, so the
            // late-evening 18:00 boundary row is included.
            $window = $this->get(self::USER_URL . '/' . $alice->id . '?from=2026-07-31&to=2026-07-31');
            $window->assertOk();
            $window->assertJsonPath('meta.total', 1);
            $this->assertSame('boundary', $window->json('data.0.description'));

            $toOnly = $this->get(self::USER_URL . '/' . $alice->id . '?to=2026-07-31');
            $toOnly->assertOk();
            $toDescriptions = array_column($toOnly->json('data'), 'description');
            $this->assertCount(2, $toDescriptions);
            $this->assertContains('before', $toDescriptions);
            $this->assertContains('boundary', $toDescriptions);
            $this->assertNotContains('after', $toDescriptions);

            $fromOnly = $this->get(self::USER_URL . '/' . $alice->id . '?from=2026-07-31');
            $fromOnly->assertOk();
            $fromDescriptions = array_column($fromOnly->json('data'), 'description');
            $this->assertCount(2, $fromDescriptions);
            $this->assertContains('boundary', $fromDescriptions);
            $this->assertContains('after', $fromDescriptions);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_98_pagination_pages_meta_and_out_of_range_422(): void
    {
        $this->actAsAdmin();
        $alice = User::factory()->create();
        $first = $this->seedAuditRow('login', $alice);
        $second = $this->seedAuditRow('login', $alice);
        $third = $this->seedAuditRow('login', $alice);

        $page1 = $this->get(self::USER_URL . '/' . $alice->id . '?per_page=1');
        $page1->assertOk();
        $page1->assertJsonPath('meta.per_page', 1);
        $page1->assertJsonPath('meta.total', 3);
        $page1->assertJsonPath('meta.last_page', 3);
        $page1->assertJsonPath('meta.current_page', 1);
        $page1->assertJsonPath('meta.from', 1);
        $page1->assertJsonPath('meta.to', 1);
        $this->assertCount(1, $page1->json('data'));
        $this->assertSame($third->id, $page1->json('data.0.id'));

        $page2 = $this->get(self::USER_URL . '/' . $alice->id . '?per_page=1&page=2');
        $page2->assertOk();
        $page2->assertJsonPath('meta.current_page', 2);
        $this->assertCount(1, $page2->json('data'));
        $this->assertSame($second->id, $page2->json('data.0.id'));

        $beyond = $this->get(self::USER_URL . '/' . $alice->id . '?page=99');
        $beyond->assertOk();
        $beyond->assertJsonPath('data', []);
        $beyond->assertJsonPath('meta.current_page', 99);
        $beyond->assertJsonPath('meta.total', 3);
        // No per_page sent, so it defaults to 15 → last_page = ceil(3/15) = 1.
        $beyond->assertJsonPath('meta.last_page', 1);
        $this->assertNull($beyond->json('meta.from'));
        $this->assertNull($beyond->json('meta.to'));

        // ARCH-005 §2: over-limit no longer 422s — clamps to the 100 ceiling.
        $badPerPage = $this->get(self::USER_URL . '/' . $alice->id . '?per_page=101');
        $badPerPage->assertOk();
        $badPerPage->assertJsonPath('meta.per_page', 100);

        $badPage = $this->get(self::USER_URL . '/' . $alice->id . '?page=0');
        $badPage->assertStatus(422);
        $badPage->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('page', $badPage->json('error.fields'));
    }

    public function test_98_user_id_zero_returns_404_not_found(): void
    {
        $this->actAsAdmin();

        // `integer` accepts 0, so validation passes; User::findOrFail(0)
        // then bubbles to the global 404 envelope (pin the boundary).
        $this->get(self::USER_URL . '/0')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    // ========================================================================
    // Parity — meta keys and item shape across #97/#98 vs #96
    // ========================================================================

    public function test_97_and_98_meta_keys_and_item_shape_match_96(): void
    {
        $this->actAsAdmin();
        $alice = User::factory()->create(['name' => 'Matrix Molly', 'must_change_password' => false]);
        $this->seedAuditRow('create', $alice, User::class, $alice->id, 'create-entity');
        $this->seedAuditRow('login', $alice, null, null, 'login-user');

        $expectedMetaKeys = ['current_page', 'from', 'last_page', 'per_page', 'to', 'total'];
        $expectedItemKeys = [
            'id', 'user_id', 'user_name', 'event_type', 'description',
            'auditable_type', 'auditable_id', 'ip_address', 'created_at',
        ];

        $entity = $this->get($this->entityUrl(User::class, $alice->id));
        $entity->assertOk();
        $this->assertSame($expectedMetaKeys, array_keys($entity->json('meta')));
        $this->assertSame($expectedItemKeys, array_keys($entity->json('data.0')));
        $this->assertSame('create-entity', $entity->json('data.0.description'));
        $this->assertSame('Matrix Molly', $entity->json('data.0.user_name'));
        $this->assertSame($alice->id, $entity->json('data.0.user_id'));
        $this->assertSame('create', $entity->json('data.0.event_type'));
        $this->assertSame(User::class, $entity->json('data.0.auditable_type'));

        $user = $this->get(self::USER_URL . '/' . $alice->id);
        $user->assertOk();
        $this->assertSame($expectedMetaKeys, array_keys($user->json('meta')));
        $this->assertSame($expectedItemKeys, array_keys($user->json('data.0')));
        $this->assertSame('login-user', $user->json('data.0.description'));
        $this->assertSame('Matrix Molly', $user->json('data.0.user_name'));
        $this->assertSame($alice->id, $user->json('data.0.user_id'));
        $this->assertSame('login', $user->json('data.0.event_type'));
        $this->assertNull($user->json('data.0.auditable_type'));
    }
}
