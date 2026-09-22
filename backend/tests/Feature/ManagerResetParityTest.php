<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * U4X-4 — Admin-only temporary-password handoff parity (ADR-018).
 *
 * The public self-service flow is gone; the manager leg
 * (POST /api/admin/users/{id}/reset-password) is the sole issuance path.
 * Pins the temp-strength contract, the deactivation 4-part, the
 * forced-change gate matrix for student + teacher, and the issuing
 * response shape (message + temporary_password + must_change_password).
 */
#[Group('user-management')]
class ManagerResetParityTest extends TestCase
{
    use RefreshDatabase;

    private function actAsAdmin(): User
    {
        return Sanctum::actingAs(User::factory()->create([
            'role' => 'Admin',
            'must_change_password' => false]));
    }

    private function issueTemp(int $userId): string
    {
        $response = $this->post('/api/admin/users/' . $userId . '/reset-password');

        $response->assertOk()
            ->assertJsonPath('data.message', 'Password reset.')
            ->assertJsonPath('data.must_change_password', true);

        $temporary = $response->json('data.temporary_password');
        $this->assertIsString($temporary);
        $this->assertNotSame('', $temporary);

        return $temporary;
    }

    public function test_manager_temp_is_strong_single_display_and_never_persisted(): void
    {
        $admin = $this->actAsAdmin();
        $target = User::factory()->create([
            'role' => 'Teacher',
            'must_change_password' => false]);

        // Bound used: Str::password(16) — every issuance meets length ≥ 16.
        $first = $this->issueTemp($target->id);
        $this->assertGreaterThanOrEqual(16, strlen($first));
        $this->assertTrue(Hash::check($first, $target->fresh()->password_hash));

        // Uniqueness: a second issuance rotates to a different credential.
        $second = $this->issueTemp($target->id);
        $this->assertGreaterThanOrEqual(16, strlen($second));
        $this->assertNotSame($first, $second);
        $this->assertTrue(Hash::check($second, $target->fresh()->password_hash));
        $this->assertFalse(Hash::check($first, $target->fresh()->password_hash));

        // Bcrypt-hash-only: no column of the raw row holds either plaintext.
        $row = (array) DB::table('users')->where('id', $target->id)->first();
        $this->assertNotContains($first, $row);
        $this->assertNotContains($second, $row);
        $this->assertNotSame($second, $target->fresh()->password_hash);

        // Single display: later reads never re-surface the credential.
        $show = $this->get('/api/admin/users/' . $target->id)->assertOk();
        $this->assertStringNotContainsString($first, $show->getContent());
        $this->assertStringNotContainsString($second, $show->getContent());
        $index = $this->get('/api/admin/users')->assertOk();
        $this->assertStringNotContainsString($first, $index->getContent());
        $this->assertStringNotContainsString($second, $index->getContent());

        // Never logged: the issuing audit rows carry no metadata payload.
        $auditRows = AuditLog::where('description', 'User password reset')
            ->where('auditable_id', $target->id)
            ->get();
        $this->assertNotEmpty($auditRows);
        foreach ($auditRows as $auditRow) {
            $this->assertSame($admin->id, (int) $auditRow->user_id);
            $this->assertEmpty($auditRow->metadata);
        }
    }

    public function test_manager_reset_on_deactivated_account_keeps_flag_and_refuses_login_with_audit(): void
    {
        $this->actAsAdmin();
        $target = User::factory()->student()->create(['must_change_password' => false]);

        $this->post('/api/admin/users/' . $target->id . '/deactivate')->assertOk();

        $temporary = $this->issueTemp($target->id);

        // (1) flag set + (2) still deactivated — the reset never flips is_active.
        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'must_change_password' => true,
            'is_active' => false]);

        // (3) login refused even with the fresh temporary credential.
        $this->post('/api/auth/login', [
            'identifier' => $target->fresh()->school_id,
            'password' => $temporary])->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCOUNT_DEACTIVATED');

        // (4) the issuance is audited.
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'update',
            'auditable_id' => $target->id,
            'description' => 'User password reset']);
    }

    public function test_forced_temp_gate_matrix_for_student_and_teacher(): void
    {
        $admin = $this->actAsAdmin();

        $teacher = User::factory()->teacher()->create([
            'must_change_password' => false]);
        $student = User::factory()->student()->create(['must_change_password' => false]);

        $cases = [
            'teacher' => [$teacher, $teacher->school_id],
            'student' => [$student, $student->school_id]];

        foreach ($cases as [$user, $identifier]) {
            // The previous iteration ends in logout, which clears the guard —
            // re-bind the admin before issuing the next temporary password.
            Sanctum::actingAs($admin);

            $temporary = $this->issueTemp($user->id);

            // Login-with-temp authenticates and reports the forced flag.
            $this->post('/api/auth/login', [
                'identifier' => $identifier,
                'password' => $temporary])->assertOk()
                ->assertJsonPath('data.must_change_password', true);

            Sanctum::actingAs($user->fresh());

            // Exemptions stay reachable under the forced flag…
            $this->get('/api/me')->assertOk()
                ->assertJsonPath('data.must_change_password', true);
            // …including the status probe: it carries no password gate, so it
            // is never PASSWORD_CHANGE_REQUIRED (it 503s here only because no
            // AI provider is configured in the test environment).
            $status = $this->get('/api/ai/status');
            $this->assertNotSame('PASSWORD_CHANGE_REQUIRED', $status->json('error.code'));

            // …while gated surface stays blocked (read + write each).
            if ($user->role === 'Teacher') {
                $this->get('/api/teacher/classrooms')
                    ->assertStatus(403)
                    ->assertJsonPath('error.code', 'PASSWORD_CHANGE_REQUIRED');
                $this->post('/api/teacher/classrooms')
                    ->assertStatus(403)
                    ->assertJsonPath('error.code', 'PASSWORD_CHANGE_REQUIRED');
            } else {
                $this->get('/api/student/classrooms')
                    ->assertStatus(403)
                    ->assertJsonPath('error.code', 'PASSWORD_CHANGE_REQUIRED');
                $this->post('/api/classrooms/join')
                    ->assertStatus(403)
                    ->assertJsonPath('error.code', 'PASSWORD_CHANGE_REQUIRED');
            }

            // Escape hatches last: change clears the flag, logout signs out.
            $this->post('/api/auth/change-password', [
                'current_password' => $temporary,
                'new_password' => 'NewPass123'])->assertOk();
            $this->post('/api/auth/logout')->assertOk();
        }
    }
}
