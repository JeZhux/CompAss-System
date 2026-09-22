<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 1 — Account lifecycle endpoints §3.1.2, refreshed for Phase A
 * CompAss-ID rework: POST /api/admin/users takes {name, role} only;
 * school_id (ADM/TEA/STU-XXXX-XXXXX) is server-generated. Update takes
 * {name} only. Login identifier is school_id for all roles.
 */
#[Group('user-management')]
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function actAsAdmin(): User
    {
        $admin = User::factory()->admin()->create([
            'must_change_password' => false,
        ]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_store_creates_teacher_with_temp_password(): void
    {
        $this->actAsAdmin();

        $resp = $this->post('/api/admin/users', [
            'name' => 'Jane Teacher',
            'role' => 'Teacher',
        ]);

        $resp->assertCreated()
            ->assertJsonPath('data.role', 'Teacher')
            ->assertJsonPath('data.must_change_password', true)
            ->assertJsonStructure(['data' => ['temporary_password', 'school_id']]);
        $this->assertNotEmpty($resp->json('data.temporary_password'));
        $schoolId = $resp->json('data.school_id');
        $this->assertMatchesRegularExpression('/^TEA-[0-9]{4}-[0-9]{5}$/', $schoolId);

        $this->assertDatabaseHas('users', [
            'school_id' => $schoolId,
            'role' => 'Teacher',
            'must_change_password' => true,
            'is_active' => true,
        ]);
    }

    public function test_store_rejects_invalid_role(): void
    {
        $this->actAsAdmin();

        $this->post('/api/admin/users', [
            'name' => 'x',
            'role' => 'Super',
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_store_rejects_manual_school_id_and_email(): void
    {
        $this->actAsAdmin();

        // Manual IDs / legacy email fields are not part of the contract:
        // StoreUserRequest prohibits them with a strict 422
        // VALIDATION_ERROR and creates zero rows.
        $usersBefore = User::count();

        $resp = $this->post('/api/admin/users', [
            'name' => 'Manual Attempt',
            'role' => 'Student',
            'school_id' => 'STU-0000-00000',
            'email' => 'manual@example.com',
            'identifier' => 'manual@example.com',
            'identifierType' => 'email',
        ]);

        $resp->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertSame($usersBefore, User::count());
        $this->assertDatabaseMissing('users', ['name' => 'Manual Attempt']);
    }

    public function test_list_and_show(): void
    {
        $this->actAsAdmin();
        User::factory()->teacher()->create(['must_change_password' => false]);

        $this->get('/api/admin/users')->assertOk()->assertJsonStructure(['data']);

        $teacher = User::query()->where('role', 'Teacher')->first();
        $this->get('/api/admin/users/'.$teacher->id)
            ->assertOk()
            ->assertJsonPath('data.role', 'Teacher')
            ->assertJsonPath('data.school_id', $teacher->school_id);
    }

    public function test_update_user_details(): void
    {
        $this->actAsAdmin();
        $user = User::factory()->teacher()->create([
            'must_change_password' => false,
        ]);
        $originalSchoolId = $user->school_id;

        $this->put('/api/admin/users/'.$user->id, [
            'name' => 'Renamed',
        ])->assertOk()->assertJsonPath('data.name', 'Renamed');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Renamed',
            'school_id' => $originalSchoolId,
        ]);
    }

    public function test_deactivate_reactivate_and_reset_password(): void
    {
        $this->actAsAdmin();
        $user = User::factory()->student()->create([
            'must_change_password' => false,
        ]);

        $this->post('/api/admin/users/'.$user->id.'/deactivate')->assertOk();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => false]);

        // ARCH-002 FR-004 stub: students have no pending-grading work, so deactivation
        // is permitted and reactivation is available.
        $this->post('/api/admin/users/'.$user->id.'/reactivate')->assertOk();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => true]);

        $resp = $this->post('/api/admin/users/'.$user->id.'/reset-password');
        $resp->assertOk()->assertJsonStructure(['data' => ['temporary_password']]);
        $this->assertNotEmpty($resp->json('data.temporary_password'));
        $this->assertTrue((bool) $resp->json('data.must_change_password'));
    }

    public function test_store_creates_student_with_generated_school_id(): void
    {
        $this->actAsAdmin();

        $resp = $this->post('/api/admin/users', [
            'name' => 'Sam Student',
            'role' => 'Student',
        ]);

        $resp->assertCreated()
            ->assertJsonPath('data.role', 'Student')
            ->assertJsonPath('data.must_change_password', true);
        $this->assertNotEmpty($resp->json('data.temporary_password'));
        $schoolId = $resp->json('data.school_id');
        $this->assertMatchesRegularExpression('/^STU-[0-9]{4}-[0-9]{5}$/', $schoolId);

        $this->assertDatabaseHas('users', [
            'school_id' => $schoolId,
            'role' => 'Student',
            'must_change_password' => true,
            'is_active' => true,
        ]);
    }

    public function test_temporary_password_is_bcrypt_hashed_in_db(): void
    {
        $this->actAsAdmin();

        $resp = $this->post('/api/admin/users', [
            'name' => 'Temp Teacher',
            'role' => 'Teacher',
        ]);

        $resp->assertCreated();
        $tempPassword = $resp->json('data.temporary_password');
        $this->assertNotEmpty($tempPassword);

        // ARCH-002 QA-004: only the bcrypt hash is persisted — the plain-text temp
        // password returned once must still authenticate against that hash.
        $user = User::find($resp->json('data.id'));
        $this->assertTrue(Hash::check($tempPassword, $user->password_hash));
    }

    public function test_store_generates_unique_ids_for_same_name(): void
    {
        $this->actAsAdmin();

        $first = $this->post('/api/admin/users', [
            'name' => 'Duplicate Name',
            'role' => 'Teacher',
        ])->assertCreated()->json('data.school_id');

        $second = $this->post('/api/admin/users', [
            'name' => 'Duplicate Name',
            'role' => 'Teacher',
        ])->assertCreated()->json('data.school_id');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, User::where('name', 'Duplicate Name')->count());
    }

    public function test_temporary_password_shown_once_contract(): void
    {
        $this->actAsAdmin();

        $resp = $this->post('/api/admin/users', [
            'name' => 'Once Only',
            'role' => 'Student',
        ])->assertCreated();

        // Temp password present on create...
        $this->assertNotEmpty($resp->json('data.temporary_password'));

        // ...but never on list/show reads.
        $id = $resp->json('data.id');
        $this->get('/api/admin/users/'.$id)->assertOk()->assertJsonMissingPath('data.temporary_password');
        $listJson = json_encode($this->get('/api/admin/users')->assertOk()->json('data'));
        $this->assertStringNotContainsString('temporary_password', $listJson);
    }

    public function test_deactivate_already_deactivated_account(): void
    {
        $this->actAsAdmin();

        $user = User::factory()->student()->create([
            'must_change_password' => false,
            'is_active' => false,
        ]);

        $this->post('/api/admin/users/'.$user->id.'/deactivate')
            ->assertOk()
            ->assertJsonPath('data.message', 'Account deactivated.');
    }

    public function test_reactivate_already_active_account(): void
    {
        $this->actAsAdmin();

        $user = User::factory()->student()->create([
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->post('/api/admin/users/'.$user->id.'/reactivate')
            ->assertOk()
            ->assertJsonPath('data.message', 'Account reactivated.');
    }

    public function test_show_nonexistent_user_returns_404(): void
    {
        $this->actAsAdmin();

        $this->get('/api/admin/users/999999')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_update_nonexistent_user_returns_404(): void
    {
        $this->actAsAdmin();

        $this->put('/api/admin/users/999999', [
            'name' => 'Ghost',
        ])->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_deactivate_nonexistent_user_returns_404(): void
    {
        $this->actAsAdmin();

        $this->post('/api/admin/users/999999/deactivate')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_reset_password_for_nonexistent_user_returns_404(): void
    {
        $this->actAsAdmin();

        $this->post('/api/admin/users/999999/reset-password')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_store_rejects_privilege_and_state_keys(): void
    {
        $this->actAsAdmin();
        $usersBefore = User::count();

        // Sensitive/state keys are not part of the {name, role} contract:
        // each must fail loudly with 422 and create zero rows (never
        // silently ignored).
        foreach ([
            ['name' => 'Priv Attempt', 'role' => 'Teacher', 'is_active' => false],
            ['name' => 'Priv Attempt', 'role' => 'Teacher', 'must_change_password' => false],
            ['name' => 'Priv Attempt', 'role' => 'Teacher', 'password' => 'secret123'],
            ['name' => 'Priv Attempt', 'role' => 'Teacher', 'status' => 'inactive'],
            ['name' => 'Priv Attempt', 'role' => 'Teacher', 'id' => 999],
            // Unlisted keys (typos) fail loudly too, never silently ignored.
            ['name' => 'Priv Attempt', 'role' => 'Teacher', 'nmae' => 'Typo Key'],
            ['name' => 'Priv Attempt', 'role' => 'Teacher', 'foo' => 'bar'],
        ] as $payload) {
            $this->post('/api/admin/users', $payload)
                ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        }

        $this->assertSame($usersBefore, User::count());
        $this->assertDatabaseMissing('users', ['name' => 'Priv Attempt']);
    }

    public function test_update_rejects_role_and_state_keys(): void
    {
        $this->actAsAdmin();
        $user = User::factory()->teacher()->create(['must_change_password' => false]);

        // Update is name-only: role/state/password keys fail loudly and the
        // row is untouched.
        foreach ([
            ['name' => 'Renamed', 'role' => 'Admin'],
            ['name' => 'Renamed', 'is_active' => false],
            ['name' => 'Renamed', 'must_change_password' => true],
            ['name' => 'Renamed', 'password' => 'secret123'],
            ['name' => 'Renamed', 'school_id' => 'TEA-0000-00000'],
            // Unlisted keys (typos) fail loudly too, never silently ignored.
            ['name' => 'Renamed', 'nickname' => 'Typo Key'],
            ['name' => 'Renamed', 'foo' => 'bar'],
        ] as $payload) {
            $this->put('/api/admin/users/' . $user->id, $payload)
                ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        }

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role' => 'Teacher',
            'is_active' => true,
        ]);
    }
}
