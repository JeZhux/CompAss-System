<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 1 — Authentication flow endpoints §3.1.1, refreshed for Phase A:
 * login identifier is school_id (CompAss ID) for ALL roles, trimmed but
 * case-sensitive. Users are created via factory states or createAccount
 * and the generated school_id is read back.
 */
#[Group('auth-flow')]
class AuthFlowTest extends TestCase
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

    private function loginAs(User $user, string $password = 'password'): \Illuminate\Testing\TestResponse
    {
        return $this->post('/api/auth/login', [
            'identifier' => $user->school_id,
            'password' => $password,
        ]);
    }

    public function test_admin_can_login(): void
    {
        $admin = User::factory()->admin()->create([
            'must_change_password' => false,
        ]);

        $this->loginAs($admin)
            ->assertOk()
            ->assertJsonPath('data.id', $admin->id)
            ->assertJsonPath('data.school_id', $admin->school_id)
            ->assertJsonPath('data.role', 'Admin')
            ->assertJsonPath('data.must_change_password', false)
            ->assertJsonMissingPath('data.user')
            ->assertJsonMissingPath('data.message');
    }

    public function test_student_can_login_with_school_id(): void
    {
        $student = User::factory()->student()->create(['must_change_password' => false]);

        $this->loginAs($student)
            ->assertOk()->assertJsonPath('data.role', 'Student');
    }

    public function test_login_fails_on_invalid_credentials(): void
    {
        $admin = User::factory()->admin()->create();

        $this->post('/api/auth/login', [
            'identifier' => $admin->school_id,
            'password' => 'nope',
        ])->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS')
            ->assertJsonPath('error.message', 'Invalid credentials.');
    }

    public function test_deactivated_account_cannot_login(): void
    {
        $admin = User::factory()->admin()->create([
            'is_active' => false,
        ]);

        $this->loginAs($admin)->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_DEACTIVATED');
    }

    public function test_unauthenticated_admin_route_returns_401(): void
    {
        $this->get('/api/admin/users')->assertStatus(401);
    }

    public function test_must_change_password_blocks_admin_route(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create([
            'must_change_password' => true,
        ]));

        $this->get('/api/admin/users')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PASSWORD_CHANGE_REQUIRED');
    }

    public function test_me_endpoint_accessible_under_forced_reset(): void
    {
        $user = User::factory()->admin()->create([
            'must_change_password' => true,
        ]);
        Sanctum::actingAs($user);

        $this->get('/api/me')
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true);
    }

    public function test_change_password_clears_gate(): void
    {
        $user = User::factory()->admin()->create([
            'must_change_password' => true,
            'password_hash' => Hash::make('password'),
        ]);
        Sanctum::actingAs($user);

        $this->post('/api/auth/change-password', [
            'current_password' => 'password',
            'new_password' => 'NewPass123',
        ])->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'must_change_password' => false,
        ]);

        Sanctum::actingAs($user->fresh());
        $this->get('/api/admin/users')->assertOk();
        $this->assertTrue(Hash::check('NewPass123', $user->fresh()->password_hash));
    }

    public function test_change_password_rejects_wrong_current(): void
    {
        $user = User::factory()->admin()->create([
            'must_change_password' => true,
            'password_hash' => Hash::make('password'),
        ]);
        Sanctum::actingAs($user);

        $this->post('/api/auth/change-password', [
            'current_password' => 'wrong',
            'new_password' => 'NewPass123',
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_logout(): void
    {
        $this->actAsAdmin();
        $this->post('/api/auth/logout')->assertOk();
    }

    public function test_rate_limiting_blocks_after_five_failed_attempts(): void
    {
        $admin = User::factory()->admin()->create([
            'password_hash' => Hash::make('password'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/api/auth/login', [
                'identifier' => $admin->school_id,
                'password' => 'wrong',
            ])->assertStatus(401);
        }

        $response = $this->post('/api/auth/login', [
            'identifier' => $admin->school_id,
            'password' => 'wrong',
        ]);

        $response->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED')
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');
    }

    public function test_successful_login_resets_rate_limit_counter(): void
    {
        $admin = User::factory()->admin()->create([
            'password_hash' => Hash::make('password'),
            'must_change_password' => false,
        ]);

        for ($i = 0; $i < 4; $i++) {
            $this->post('/api/auth/login', [
                'identifier' => $admin->school_id,
                'password' => 'wrong',
            ])->assertStatus(401);
        }

        $response = $this->post('/api/auth/login', [
            'identifier' => $admin->school_id,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.role', 'Admin')
            ->assertHeader('X-RateLimit-Remaining', '5');

        for ($i = 0; $i < 5; $i++) {
            $this->post('/api/auth/login', [
                'identifier' => $admin->school_id,
                'password' => 'wrong',
            ])->assertStatus(401);
        }

        $this->post('/api/auth/login', [
            'identifier' => $admin->school_id,
            'password' => 'wrong',
        ])->assertStatus(429);
    }

    public function test_teacher_can_login(): void
    {
        $teacher = User::factory()->teacher()->create([
            'must_change_password' => false,
        ]);

        $this->loginAs($teacher)
            ->assertOk()
            ->assertJsonPath('data.id', $teacher->id)
            ->assertJsonPath('data.school_id', $teacher->school_id)
            ->assertJsonPath('data.role', 'Teacher')
            ->assertJsonPath('data.must_change_password', false)
            ->assertJsonMissingPath('data.user')
            ->assertJsonMissingPath('data.message');
    }

    public function test_login_trims_whitespace_but_is_case_sensitive(): void
    {
        $student = User::factory()->student()->create(['must_change_password' => false]);

        $this->post('/api/auth/login', [
            'identifier' => '  '.$student->school_id.'  ',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('data.role', 'Student');

        $this->post('/api/auth/login', [
            'identifier' => strtolower($student->school_id),
            'password' => 'password',
        ])->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_login_fails_on_nonexistent_identifier(): void
    {
        $this->post('/api/auth/login', [
            'identifier' => 'ADM-0000-00000',
            'password' => 'password',
        ])->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_login_fails_on_nonexistent_school_id(): void
    {
        $this->post('/api/auth/login', [
            'identifier' => 'STU-9999-99999',
            'password' => 'password',
        ])->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_rate_limit_window_decays_over_time(): void
    {
        $teacher = User::factory()->teacher()->create([
            'must_change_password' => false,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/api/auth/login', [
                'identifier' => $teacher->school_id,
                'password' => 'wrong',
            ])->assertStatus(401);
        }

        Carbon::setTestNow(now()->addSeconds(AuthService::RATE_LIMIT_DECAY_SECONDS + 1));

        $this->post('/api/auth/login', [
            'identifier' => $teacher->school_id,
            'password' => 'wrong',
        ])->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

        Carbon::setTestNow();
    }

    public function test_password_change_required_blocks_batch_import_routes(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create([
            'must_change_password' => true,
        ]));

        $this->get('/api/admin/import/templates/competency_tags')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PASSWORD_CHANGE_REQUIRED');

        $this->post('/api/admin/import/student-enrollments')
            ->assertStatus(404);

        $this->post('/api/admin/import/competency-tags')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PASSWORD_CHANGE_REQUIRED');

        $this->get('/api/admin/import/error-reports/test-token')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PASSWORD_CHANGE_REQUIRED');

        $this->post('/api/admin/import/students/csv')
            ->assertStatus(404);

        $this->post('/api/auth/change-password', [
            'current_password' => 'password',
            'new_password' => 'NewPass123',
        ])->assertOk();
    }
}
