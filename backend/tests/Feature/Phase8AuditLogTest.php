<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Exceptions\BusinessRuleConflictException;
use App\Models\Announcement;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentItem;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\ClassroomEnrollment;
use App\Models\CompetencyReference;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Semester;
use App\Models\User;
use App\Services\AnnouncementService;
use App\Services\AuditLogService;
use App\Services\ClassroomService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 8 — Audit Logging regression + verification coverage (ARCH-005 block 4.8,
 * #96–#98; ARCH-002 QA-006, ARCH-001 §5.1).
 *
 * The Phase 8 feature shipped without regression tests (the CHANGELOG
 * deferred the dedicated testing pass to this file). Coverage pins the
 * post-ship fixes: end-of-day `to` inclusivity, deterministic
 * `created_at desc, id desc` ordering, cross-field `to after_or_equal:from`
 * validation, Collection-vs-LengthAwarePaginator branching, and
 * instrumentation smoke tests over real flows.
 *
 * @Traced-To ARCH-002 QA-006 (ARCH-005 block 4.8, ARCH-001 §5.1)
 */
#[Group('phase8-audit-log')]
class Phase8AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private const INDEX_URL = '/api/admin/audit-logs';

    private const ENTITY_URL = '/api/admin/audit-logs/entity';

    private const USER_URL = '/api/admin/audit-logs/user';

    private ?User $admin = null;

    private ?User $teacher = null;

    
    private ?Section $section = null;

    private ?Classroom $classroom = null;

    private ?AuditLogService $auditLogService = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = null;
        $this->teacher = null;
        $this->section = null;
        $this->classroom = null;
        $this->auditLogService = $this->app->make(AuditLogService::class);
    }

    private function actAsAdmin(): User
    {
        if (! $this->admin) {
            $this->admin = User::factory()->create([
                'role' => 'Admin',
                'must_change_password' => false]);
        }

        Sanctum::actingAs($this->admin);

        return $this->admin;
    }

    private function actAsTeacher(): User
    {
        if (! $this->teacher) {
            $this->teacher = User::factory()->create([
                'role' => 'Teacher',
                'must_change_password' => false]);
        }

        Sanctum::actingAs($this->teacher);

        return $this->teacher;
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
     * Minimal org hierarchy (School Year → Semester → Grade Level → Section →
     * Subject) with the teacher assigned, mirroring the
     * Phase7Test::setUpOrg pattern — enough for announcement/assessment flows.
     */
    private function setUpOrg(): void
    {
        $year = SchoolYear::create(['name' => 'SY 2026-P8']);
        $term = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31']);
        $gradeLevel = GradeLevel::create([
            'semester_id' => $term->id,
            'grade_level' => '7']);
        $this->section = Section::create([
            'grade_level_id' => $gradeLevel->id,
            'name' => '7A-P8']);
        $subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-P8']);
        
        $this->actAsTeacher();
        $this->classroom = app(ClassroomService::class)->createClassroom($this->teacher->id, $subject->id, $this->section->id, '2026-2027', null);
    }

    /**
     * Build an #97 entity-scoped URL with optional event_type filter.
     */
    private function entityUrl(string $auditableType, int $auditableId, string $eventType = ''): string
    {
        return self::ENTITY_URL . '?' . http_build_query(array_filter([
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'event_type' => $eventType]));
    }

    private function createAnnouncement(string $title): Announcement
    {
        return $this->app->make(AnnouncementService::class)->createAnnouncementInClassroom(
            $this->teacher->id,
            $this->classroom->id,
            $title,
            'Body text.',
            []
        );
    }

    // ========================================================================
    // Access control — 401 / 403 / PASSWORD_CHANGE_REQUIRED on #96–#98
    // ========================================================================

    public function test_96_97_98_unauthenticated_returns_401(): void
    {
        foreach ([self::INDEX_URL, self::ENTITY_URL, self::USER_URL . '/1'] as $url) {
            $this->get($url)
                ->assertStatus(401)
                ->assertJsonPath('error.code', 'UNAUTHENTICATED');
        }
    }

    public static function nonAdminRoleProvider(): array
    {
        return [
            'Teacher on #96' => ['Teacher', self::INDEX_URL],
            'Teacher on #97' => ['Teacher', self::ENTITY_URL],
            'Teacher on #98' => ['Teacher', self::USER_URL . '/1'],
            'Student on #96' => ['Student', self::INDEX_URL],
            'Student on #97' => ['Student', self::ENTITY_URL],
            'Student on #98' => ['Student', self::USER_URL . '/1']];
    }

    #[DataProvider('nonAdminRoleProvider')]
    public function test_96_97_98_forbid_non_admin_roles(string $role, string $url): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => $role,
            'must_change_password' => false]));

        $this->get($url)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_96_97_98_password_change_required_gate(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'Admin',
            'must_change_password' => true]));

        foreach ([self::INDEX_URL, self::ENTITY_URL, self::USER_URL . '/1'] as $url) {
            $this->get($url)
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'PASSWORD_CHANGE_REQUIRED');
        }
    }

    // ========================================================================
    // #96 — GET /api/admin/audit-logs
    // ========================================================================

    public function test_96_empty_table_returns_empty_data_and_zero_meta(): void
    {
        $this->actAsAdmin();

        $response = $this->get(self::INDEX_URL);

        $response->assertOk();
        $response->assertJsonPath('data', []);
        $response->assertJsonPath('meta.total', 0);
        $response->assertJsonPath('meta.current_page', 1);
        $response->assertJsonPath('meta.last_page', 1);
        $response->assertJsonPath('meta.per_page', 15);
        $this->assertNull($response->json('meta.from'));
        $this->assertNull($response->json('meta.to'));
    }

    public function test_96_seeded_rows_return_spec_item_shape(): void
    {
        $this->actAsAdmin();
        $user = User::factory()->create(['name' => 'Audit Alice', 'must_change_password' => false]);
        $withUser = $this->seedAuditRow('login', $user, null, null, 'User logged in');
        $withoutUser = $this->seedAuditRow('create', null, User::class, $user->id, 'System event');

        $response = $this->get(self::INDEX_URL);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertSame($withoutUser->id, $data[0]['id']);

        foreach ($data as $item) {
            $this->assertSame(
                [
                    'id', 'user_id', 'user_name', 'event_type', 'description',
                    'auditable_type', 'auditable_id', 'ip_address', 'created_at'],
                array_keys($item)
            );
            $this->assertArrayNotHasKey('user_agent', $item);
            $this->assertArrayNotHasKey('metadata', $item);
        }

        $this->assertNull($data[0]['user_name']);
        $this->assertSame(User::class, $data[0]['auditable_type']);
        $this->assertSame($user->id, $data[0]['auditable_id']);
        $this->assertSame('Audit Alice', $data[1]['user_name']);
        $this->assertSame($user->id, $data[1]['user_id']);
        $this->assertSame('login', $data[1]['event_type']);
        $this->assertNull($data[1]['auditable_type']);
        $this->assertNull($data[1]['ip_address']);
        $this->assertIsString($data[1]['created_at']);
    }

    public function test_96_event_type_filter_hit_and_miss(): void
    {
        $this->actAsAdmin();
        $user = User::factory()->create();
        $this->seedAuditRow('login', $user, null, null, 'User logged in');
        $this->seedAuditRow('create', $user, User::class, $user->id, 'User account created');

        $hit = $this->get(self::INDEX_URL . '?event_type=login');
        $hit->assertOk();
        $this->assertCount(1, $hit->json('data'));
        $this->assertSame('login', $hit->json('data.0.event_type'));

        $miss = $this->get(self::INDEX_URL . '?event_type=delete');
        $miss->assertOk();
        $miss->assertJsonPath('data', []);
        $miss->assertJsonPath('meta.total', 0);
    }

    public function test_96_invalid_event_type_returns_422_with_fields(): void
    {
        $this->actAsAdmin();

        $response = $this->get(self::INDEX_URL . '?event_type=bogus_event');

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('event_type', $response->json('error.fields'));
    }

    public function test_96_user_id_filter(): void
    {
        $this->actAsAdmin();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $this->seedAuditRow('login', $alice);
        $this->seedAuditRow('login', $alice);
        $this->seedAuditRow('login', $bob);

        $response = $this->get(self::INDEX_URL . '?user_id=' . $alice->id);

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertSame($alice->id, $response->json('data.0.user_id'));
    }

    public function test_96_auditable_type_and_id_filter(): void
    {
        $this->actAsAdmin();
        $user = User::factory()->create();
        $this->seedAuditRow('create', $user, User::class, $user->id);
        $this->seedAuditRow('update', $user, User::class, 7);
        $this->seedAuditRow('update', $user, Announcement::class, 42);

        $response = $this->get($this->entityUrl(User::class, $user->id));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame(User::class, $response->json('data.0.auditable_type'));
        $this->assertSame($user->id, $response->json('data.0.auditable_id'));
    }

    public function test_96_from_to_date_filters_with_end_of_day_to(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 2, 12, 0, 0));
        $this->actAsAdmin();
        try {
            $rows = [];
            $rows['july-30'] = $this->seedAuditRow('login', null, null, null, 'july-30');
            $rows['boundary-morning'] = $this->seedAuditRow('login', null, null, null, 'boundary-morning');
            $rows['boundary-afternoon'] = $this->seedAuditRow('login', null, null, null, 'boundary-afternoon');
            $rows['aug-1'] = $this->seedAuditRow('login', null, null, null, 'aug-1');
            $rows['july-30']->update(['created_at' => Carbon::create(2026, 7, 30, 10, 0, 0)]);
            $rows['boundary-morning']->update(['created_at' => Carbon::create(2026, 7, 31, 11, 0, 0)]);
            $rows['boundary-afternoon']->update(['created_at' => Carbon::create(2026, 7, 31, 15, 0, 0)]);
            $rows['aug-1']->update(['created_at' => Carbon::create(2026, 8, 1, 9, 0, 0)]);

            // `from` starts at midnight of the given day.
            $fromOnly = $this->get(self::INDEX_URL . '?from=2026-07-31');
            $fromOnly->assertOk();
            $fromDescriptions = array_column($fromOnly->json('data'), 'description');
            $this->assertCount(3, $fromDescriptions);
            $this->assertContains('boundary-afternoon', $fromDescriptions);
            $this->assertNotContains('july-30', $fromDescriptions);

            // Date-only `to` is end-of-day inclusive — the 15:00 row survives.
            $toOnly = $this->get(self::INDEX_URL . '?to=2026-07-31');
            $toOnly->assertOk();
            $toDescriptions = array_column($toOnly->json('data'), 'description');
            $this->assertCount(3, $toDescriptions);
            $this->assertContains('july-30', $toDescriptions);
            $this->assertContains('boundary-afternoon', $toDescriptions);
            $this->assertNotContains('aug-1', $toDescriptions);

            // Same-day window resolves to the two boundary rows only.
            $window = $this->get(self::INDEX_URL . '?from=2026-07-31&to=2026-07-31');
            $window->assertOk();
            $this->assertCount(2, $window->json('data'));

            // `to` on the previous day excludes the boundary rows entirely.
            $prevDay = $this->get(self::INDEX_URL . '?to=2026-07-30');
            $prevDay->assertOk();
            $this->assertCount(1, $prevDay->json('data'));
            $this->assertSame('july-30', $prevDay->json('data.0.description'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_96_combined_filters(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 2, 12, 0, 0));
        $this->actAsAdmin();
        try {
            $alice = User::factory()->create();
            $bob = User::factory()->create();
            $hit = $this->seedAuditRow('login', $alice, User::class, $alice->id, 'hit');
            $hit->update(['created_at' => Carbon::create(2026, 7, 15, 10, 0, 0)]);
            $this->seedAuditRow('login', $alice, User::class, $alice->id, 'wrong-date')
                ->update(['created_at' => Carbon::create(2026, 6, 1, 10, 0, 0)]);
            $this->seedAuditRow('create', $alice, User::class, $alice->id, 'wrong-event')
                ->update(['created_at' => Carbon::create(2026, 7, 15, 11, 0, 0)]);
            $this->seedAuditRow('login', $bob, User::class, $alice->id, 'wrong-user')
                ->update(['created_at' => Carbon::create(2026, 7, 15, 12, 0, 0)]);

            $response = $this->get(self::INDEX_URL . '?event_type=login&user_id=' . $alice->id
                . '&auditable_type=' . urlencode(User::class) . '&auditable_id=' . $alice->id
                . '&from=2026-07-01&to=2026-07-31');

            $response->assertOk();
            $this->assertCount(1, $response->json('data'));
            $this->assertSame('hit', $response->json('data.0.description'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_96_pagination_per_page_and_page(): void
    {
        $this->actAsAdmin();
        $user = User::factory()->create();
        $first = $this->seedAuditRow('login', $user);
        $second = $this->seedAuditRow('login', $user);
        $third = $this->seedAuditRow('login', $user);

        $page1 = $this->get(self::INDEX_URL . '?per_page=1');
        $page1->assertOk();
        $page1->assertJsonPath('meta.per_page', 1);
        $page1->assertJsonPath('meta.total', 3);
        $page1->assertJsonPath('meta.last_page', 3);
        $page1->assertJsonPath('meta.current_page', 1);
        $this->assertCount(1, $page1->json('data'));
        $this->assertSame($third->id, $page1->json('data.0.id'));

        $page2 = $this->get(self::INDEX_URL . '?per_page=1&page=2');
        $page2->assertOk();
        $page2->assertJsonPath('meta.current_page', 2);
        $this->assertCount(1, $page2->json('data'));
        $this->assertSame($second->id, $page2->json('data.0.id'));

        $page3 = $this->get(self::INDEX_URL . '?per_page=1&page=3');
        $page3->assertOk();
        $this->assertSame($first->id, $page3->json('data.0.id'));
    }

    public function test_96_page_out_of_range_returns_422_and_per_page_is_clamped(): void
    {
        $this->actAsAdmin();

        $pageZero = $this->get(self::INDEX_URL . '?page=0');
        $pageZero->assertStatus(422);
        $pageZero->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('page', $pageZero->json('error.fields'));

        // ARCH-005 §2: per_page no longer 422s — out-of-range clamps (0 → default 15).
        $perPageZero = $this->get(self::INDEX_URL . '?per_page=0');
        $perPageZero->assertOk();
        $this->assertSame(15, $perPageZero->json('meta.per_page'));

        $perPageLarge = $this->get(self::INDEX_URL . '?per_page=101');
        $perPageLarge->assertOk();
        $this->assertSame(100, $perPageLarge->json('meta.per_page'));
    }

    public function test_96_page_beyond_last_returns_empty_data_with_correct_meta(): void
    {
        $this->actAsAdmin();
        $user = User::factory()->create();
        $this->seedAuditRow('login', $user);
        $this->seedAuditRow('login', $user);
        $this->seedAuditRow('login', $user);

        $response = $this->get(self::INDEX_URL . '?page=99');

        $response->assertOk();
        $response->assertJsonPath('data', []);
        $response->assertJsonPath('meta.current_page', 99);
        $response->assertJsonPath('meta.total', 3);
        $response->assertJsonPath('meta.last_page', 1);
        $this->assertNull($response->json('meta.from'));
        $this->assertNull($response->json('meta.to'));
    }

    public function test_96_to_before_from_returns_422_with_fields_to(): void
    {
        $this->actAsAdmin();

        $reversed = $this->get(self::INDEX_URL . '?from=2026-07-31&to=2026-01-01');

        $reversed->assertStatus(422);
        $reversed->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('to', $reversed->json('error.fields'));

        $this->get(self::INDEX_URL . '?from=2026-01-01&to=2026-07-31')->assertOk();
    }

    // ========================================================================
    // #97 — GET /api/admin/audit-logs/entity
    // ========================================================================

    public function test_97_missing_required_entity_params_return_422(): void
    {
        $this->actAsAdmin();

        $missingType = $this->get(self::ENTITY_URL . '?auditable_id=5');
        $missingType->assertStatus(422);
        $missingType->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('auditable_type', $missingType->json('error.fields'));

        $missingId = $this->get(self::ENTITY_URL . '?auditable_type=' . urlencode(User::class));
        $missingId->assertStatus(422);
        $this->assertArrayHasKey('auditable_id', $missingId->json('error.fields'));
    }

    public function test_97_entity_filter_returns_only_matching_rows(): void
    {
        $this->actAsAdmin();
        $user = User::factory()->create();
        $this->seedAuditRow('create', $user, User::class, $user->id);
        $this->seedAuditRow('update', $user, User::class, $user->id);
        $this->seedAuditRow('update', $user, Announcement::class, 99);

        $response = $this->get($this->entityUrl(User::class, $user->id));

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertSame(User::class, $response->json('data.0.auditable_type'));
    }

    public function test_97_entity_filter_with_event_type(): void
    {
        $this->actAsAdmin();
        $user = User::factory()->create();
        $this->seedAuditRow('create', $user, User::class, $user->id);
        $this->seedAuditRow('update', $user, User::class, $user->id);

        $response = $this->get($this->entityUrl(User::class, $user->id, 'create'));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('create', $response->json('data.0.event_type'));
    }

    // ========================================================================
    // #98 — GET /api/admin/audit-logs/user/{userId}
    // ========================================================================

    public function test_98_nonexistent_user_returns_404(): void
    {
        $this->actAsAdmin();

        $this->get(self::USER_URL . '/999999')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_98_non_integer_user_id_returns_422_not_500(): void
    {
        $this->actAsAdmin();

        $response = $this->get(self::USER_URL . '/abc');

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('userId', $response->json('error.fields'));
    }

    public function test_98_existing_user_returns_only_their_rows(): void
    {
        $this->actAsAdmin();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $this->seedAuditRow('login', $alice);
        $this->seedAuditRow('logout', $alice);
        $this->seedAuditRow('login', $bob);

        $response = $this->get(self::USER_URL . '/' . $alice->id);

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        foreach ($response->json('data') as $item) {
            $this->assertSame($alice->id, $item['user_id']);
        }
    }

    // ========================================================================
    // AuditLogService — pagination branching, ordering, end-of-day `to`
    // ========================================================================

    public function test_get_audit_logs_collection_vs_paginator_branching(): void
    {
        $user = User::factory()->create();
        foreach (['login', 'logout', 'create'] as $type) {
            $this->seedAuditRow($type, $user);
        }

        $unpaginated = $this->auditLogService->getAuditLogs();
        $this->assertInstanceOf(Collection::class, $unpaginated);
        $this->assertNotInstanceOf(LengthAwarePaginator::class, $unpaginated);
        $this->assertCount(3, $unpaginated);

        $paginated = $this->auditLogService->getAuditLogs(page: 1);
        $this->assertInstanceOf(LengthAwarePaginator::class, $paginated);
        $this->assertSame(15, $paginated->perPage());
        $this->assertSame(1, $paginated->currentPage());
        $this->assertSame(3, $paginated->total());

        $smallPage = $this->auditLogService->getAuditLogs(page: 1, perPage: 2);
        $this->assertSame(2, $smallPage->perPage());
        $this->assertSame(2, $smallPage->lastPage());
        $this->assertCount(2, $smallPage->items());
    }

    public function test_get_logs_for_entity_collection_vs_paginator_branching(): void
    {
        $user = User::factory()->create();
        foreach (['create', 'update', 'delete'] as $type) {
            $this->seedAuditRow($type, $user, User::class, $user->id);
        }

        $unpaginated = $this->auditLogService->getLogsForEntity(User::class, $user->id);
        $this->assertInstanceOf(Collection::class, $unpaginated);
        $this->assertNotInstanceOf(LengthAwarePaginator::class, $unpaginated);
        $this->assertCount(3, $unpaginated);

        $paginated = $this->auditLogService->getLogsForEntity(User::class, $user->id, page: 1);
        $this->assertInstanceOf(LengthAwarePaginator::class, $paginated);
        $this->assertSame(15, $paginated->perPage());
        $this->assertSame(1, $paginated->currentPage());
        $this->assertSame(3, $paginated->total());
    }

    public function test_get_logs_for_user_collection_vs_paginator_branching(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->seedAuditRow('login', $user);
        $this->seedAuditRow('logout', $user);
        $this->seedAuditRow('login', $other);

        $unpaginated = $this->auditLogService->getLogsForUser($user->id);
        $this->assertInstanceOf(Collection::class, $unpaginated);
        $this->assertNotInstanceOf(LengthAwarePaginator::class, $unpaginated);
        $this->assertCount(2, $unpaginated);

        $paginated = $this->auditLogService->getLogsForUser($user->id, page: 1);
        $this->assertInstanceOf(LengthAwarePaginator::class, $paginated);
        $this->assertSame(15, $paginated->perPage());
        $this->assertSame(1, $paginated->currentPage());
        $this->assertSame(2, $paginated->total());
    }

    public function test_date_only_to_is_end_of_day_inclusive_full_datetime_unchanged(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 2, 12, 0, 0));
        try {
            $rows = [];
            $rows['previous-day'] = $this->seedAuditRow('login', null, null, null, 'previous-day');
            $rows['on-boundary-day'] = $this->seedAuditRow('login', null, null, null, 'on-boundary-day');
            $rows['mid-day-to-boundary'] = $this->seedAuditRow('login', null, null, null, 'mid-day-to-boundary');
            $rows['after-datetime-to'] = $this->seedAuditRow('login', null, null, null, 'after-datetime-to');
            $rows['previous-day']->update(['created_at' => Carbon::create(2026, 7, 30, 23, 59, 59)]);
            $rows['on-boundary-day']->update(['created_at' => Carbon::create(2026, 7, 31, 15, 0, 0)]);
            $rows['mid-day-to-boundary']->update(['created_at' => Carbon::create(2026, 7, 31, 11, 0, 0)]);
            $rows['after-datetime-to']->update(['created_at' => Carbon::create(2026, 7, 31, 14, 0, 0)]);

            // Date-only `to` (YYYY-MM-DD) is normalized to end-of-day, so the
            // full final day is included (the 15:00 row survives).
            $inclusive = $this->auditLogService->getAuditLogs(toDate: '2026-07-31');
            $this->assertCount(4, $inclusive);
            $descriptions = $inclusive->pluck('description')->all();
            $this->assertContains('on-boundary-day', $descriptions);
            $this->assertContains('mid-day-to-boundary', $descriptions);
            $this->assertContains('after-datetime-to', $descriptions);

            // `to` on the previous day excludes the boundary-day rows.
            $previous = $this->auditLogService->getAuditLogs(toDate: '2026-07-30');
            $this->assertCount(1, $previous);
            $this->assertSame('previous-day', $previous->first()->description);

            // Full datetime `to` passes through unchanged (mid-day boundary).
            $full = $this->auditLogService->getAuditLogs(toDate: '2026-07-31 12:00:00');
            $fullDescriptions = $full->pluck('description')->all();
            $this->assertContains('mid-day-to-boundary', $fullDescriptions);
            $this->assertNotContains('on-boundary-day', $fullDescriptions);
            $this->assertNotContains('after-datetime-to', $fullDescriptions);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_read_methods_order_by_created_at_desc_then_id_desc(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 1, 9, 0, 0));
        try {
            $user = User::factory()->create();
            $first = $this->seedAuditRow('login', $user, User::class, $user->id);
            $second = $this->seedAuditRow('logout', $user, User::class, $user->id);
            $third = $this->seedAuditRow('create', $user, User::class, $user->id);

            // Force the identical timestamp on all three rows (same-second tie).
            foreach ([$first, $second, $third] as $row) {
                $row->update(['created_at' => Carbon::create(2026, 8, 1, 9, 0, 0)]);
            }

            $this->assertSame(
                [$third->id, $second->id, $first->id],
                $this->auditLogService->getAuditLogs()->pluck('id')->all()
            );
            $this->assertSame(
                [$third->id, $second->id, $first->id],
                $this->auditLogService->getLogsForEntity(User::class, $user->id)->pluck('id')->all()
            );
            $this->assertSame(
                [$third->id, $second->id, $first->id],
                $this->auditLogService->getLogsForUser($user->id)->pluck('id')->all()
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    // ========================================================================
    // Instrumentation smoke — real flows writing audit rows
    // ========================================================================

    public function test_login_flow_writes_login_audit_row(): void
    {
        $user = User::factory()->admin()->create([
            'must_change_password' => false]);

        $this->post('/api/auth/login', [
            'identifier' => $user->school_id,
            'password' => 'password'])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'login',
            'user_id' => $user->id,
            'description' => 'User logged in',
            'auditable_type' => null,
            'auditable_id' => null]);
        // CLI context captures no client IP (ARCH-002 QA-006).
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'login',
            'user_id' => $user->id,
            'ip_address' => null]);
    }

    public function test_logout_flow_writes_logout_audit_row(): void
    {
        $user = User::factory()->create([
            'role' => 'Admin',
            'must_change_password' => false]);

        $this->actingAs($user, 'sanctum')
            ->post('/api/auth/logout')
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'logout',
            'user_id' => $user->id,
            'description' => 'User logged out']);
    }

    public function test_admin_create_user_writes_create_audit_row(): void
    {
        $admin = $this->actAsAdmin();

        $response = $this->post('/api/admin/users', [
            'name' => 'New Teacher',
            'role' => 'Teacher']);

        $response->assertCreated();
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'create',
            'user_id' => $admin->id,
            'auditable_type' => User::class,
            'auditable_id' => $response->json('data.id'),
            'description' => 'User account created']);
    }

    public function test_admin_deactivate_user_writes_update_audit_row(): void
    {
        $admin = $this->actAsAdmin();
        $target = User::factory()->create([
            'role' => 'Student',
            'must_change_password' => false]);

        $this->post('/api/admin/users/' . $target->id . '/deactivate')->assertOk();

        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => false]);
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'update',
            'user_id' => $admin->id,
            'auditable_type' => User::class,
            'auditable_id' => $target->id,
            'description' => 'User account deactivated']);
    }

    public function test_announcement_create_writes_create_audit_row(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/' . $this->classroom->id . '/announcements', [
                'title' => 'Field trip',
                'body' => 'Bring permission slips.']);

        $response->assertCreated();
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'create',
            'user_id' => $this->teacher->id,
            'auditable_type' => Announcement::class,
            'auditable_id' => $response->json('data.id'),
            'description' => 'Announcement created: Field trip']);
    }

    public function test_announcement_update_too_many_attachments_is_atomic(): void
    {
        $this->setUpOrg();
        $announcement = $this->createAnnouncement('Original title');

        $attachments = [];
        for ($i = 0; $i < 6; $i++) {
            $attachments[] = File::fake()->create('a' . $i . '.pdf', 100, 'application/pdf');
        }

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->put('/api/teacher/announcements/' . $announcement->id, [
                'title' => 'New title',
                'attachments' => $attachments]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'TOO_MANY_ATTACHMENTS');
        $this->assertDatabaseHas('announcements', ['id' => $announcement->id, 'title' => 'Original title']);
        $this->assertDatabaseMissing('audit_logs', [
            'event_type' => 'update',
            'auditable_type' => Announcement::class,
            'auditable_id' => $announcement->id]);
    }

    public function test_announcement_update_oversized_file_is_atomic(): void
    {
        $this->setUpOrg();
        $announcement = $this->createAnnouncement('Original title');
        $this->actingAs($this->teacher, 'sanctum');

        $oversizedKb = (int) ceil(AnnouncementService::MAX_FILE_SIZE / 1024) + 1;
        $oversized = File::fake()->create('huge.pdf', $oversizedKb, 'application/pdf');

        try {
            $this->app->make(AnnouncementService::class)->updateAnnouncement(
                $this->teacher->id,
                $announcement->id,
                'New title',
                null,
                [$oversized]
            );
            $this->fail('Expected BusinessRuleConflictException to be thrown.');
        } catch (BusinessRuleConflictException $e) {
            $this->assertSame('FILE_TOO_LARGE', $e->errorCode);
        }

        $this->assertDatabaseHas('announcements', ['id' => $announcement->id, 'title' => 'Original title']);
        $this->assertDatabaseMissing('audit_logs', [
            'event_type' => 'update',
            'auditable_type' => Announcement::class,
            'auditable_id' => $announcement->id]);
    }

    public function test_announcement_update_success_writes_exactly_one_update_row(): void
    {
        $this->setUpOrg();
        $announcement = $this->createAnnouncement('Original title');

        $this->actingAs($this->teacher, 'sanctum')
            ->put('/api/teacher/announcements/' . $announcement->id, [
                'title' => 'Updated title'])
            ->assertOk();

        $this->assertDatabaseHas('announcements', ['id' => $announcement->id, 'title' => 'Updated title']);

        $rows = AuditLog::where('event_type', 'update')
            ->where('auditable_type', Announcement::class)
            ->where('auditable_id', $announcement->id)
            ->get();
        $this->assertCount(1, $rows);
        $this->assertSame($this->teacher->id, $rows->first()->user_id);
        $this->assertSame('Announcement updated (id ' . $announcement->id . ')', $rows->first()->description);
    }

    public function test_release_results_writes_release_results_audit_row(): void
    {
        $this->setUpOrg();
        $subject = Subject::query()->where('code', 'MATH7-P8')->firstOrFail();
        $assessment = Assessment::create([
            'teacher_id' => $this->teacher->id,
            'classroom_id' => $this->classroom->id,
            'subject_id' => $subject->id,
            'semester_id' => $this->section->gradeLevel->semester_id,
            'title' => 'Released audit assessment',
            'description' => '',
            'type' => 'Recorded',
            'status' => 'released']);

        $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/assessments/' . $assessment->id . '/release-results')
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'release_results',
            'user_id' => $this->teacher->id,
            'auditable_type' => Assessment::class,
            'auditable_id' => $assessment->id]);
    }

    public function test_manual_grade_does_not_write_audit_row(): void
    {
        // R-15 (ARCH-002 FR-032 adjudication): grading operations are
        // record-not-implement — only result-release events are audit-logged,
        // so a manual grade must NOT write an audit row (write-budget cap).
        $this->setUpOrg();

        $student = User::factory()->create([
            'role' => 'Student',
            'must_change_password' => false]);
        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $student->id,
            'joined_at' => now()]);

        $subject = Subject::query()->where('code', 'MATH7-P8')->firstOrFail();
        $competency = CompetencyReference::create(['semester' => '1', 'code' => 'M7-NUM-001',
            'descriptor' => 'Solve multi-step rational numbers',
            'subject_id' => $subject->id,
            'grade_level' => '7']);

        $assessment = Assessment::create([
            'teacher_id' => $this->teacher->id,
            'classroom_id' => $this->classroom->id,
            'subject_id' => $subject->id,
            'semester_id' => $this->section->gradeLevel->semester_id,
            'title' => 'Grading audit assessment',
            'description' => '',
            'type' => 'Recorded',
            'status' => 'released']);

        $item = AssessmentItem::create([
            'assessment_id' => $assessment->id,
            'item_type' => 'essay',
            'prompt' => 'Write an essay.',
            'max_points' => 10,
            'correct_answer' => null,
            'competency_tag_id' => $competency->id,
            'sort_order' => 1]);

        Sanctum::actingAs($student);
        $this->post("/api/student/assessments/{$assessment->id}/start");
        $this->post("/api/student/assessments/{$assessment->id}/submit", [
            'responses' => [(string) $item->id => 'My essay answer.']]);

        $attemptId = AssessmentAttempt::where('assessment_id', $assessment->id)->value('id');

        $auditBefore = AuditLog::count();

        $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $attemptId,
                'is_draft' => false,
                'grade_entries' => [
                    [
                        'assessment_item_id' => $item->id,
                        'score' => 8,
                        'max_score' => 10]]])
            ->assertOk();

        // Classroom creation writes a `create` audit row, so assert no NEW rows
        // from grading (delta) rather than absolute zero — preserves R-15 intent.
        $this->assertSame($auditBefore, AuditLog::count());
    }
}
