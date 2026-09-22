<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * F-01 — Deactivated accounts keep no usable sessions.
 *
 * Covers: login → deactivate via second admin session → reuse first
 * session → expect rejection; plus reactivation fresh login. Also pins the
 * cross-cutting logging rule (no raw tokens/passwords in audit metadata).
 */
#[Group('f-01')]
class DeactivationSessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivation_revokes_all_sessions_and_rejects_reuse(): void
    {
        $admin = User::factory()->admin()->create([
            'must_change_password' => false,
            'password_hash' => Hash::make('AdminPass123'),
        ]);

        $victim = User::factory()->teacher()->create([
            'must_change_password' => false,
            'password_hash' => Hash::make('VictimPass123'),
        ]);

        // Login succeeds before deactivation (first live session).
        $this->post('/api/auth/login', [
            'identifier' => $victim->school_id,
            'password' => 'VictimPass123',
        ])->assertOk()->assertJsonPath('data.id', $victim->id);

        // Two live sessions rows for the victim plus one admin row.
        DB::table('sessions')->insert([
            [
                'id' => 'f01-session-a',
                'user_id' => $victim->id,
                'ip_address' => null,
                'user_agent' => null,
                'payload' => base64_encode('victim-session-a'),
                'last_activity' => time(),
            ],
            [
                'id' => 'f01-session-b',
                'user_id' => $victim->id,
                'ip_address' => null,
                'user_agent' => null,
                'payload' => base64_encode('victim-session-b'),
                'last_activity' => time(),
            ],
            [
                'id' => 'f01-session-admin',
                'user_id' => $admin->id,
                'ip_address' => null,
                'user_agent' => null,
                'payload' => base64_encode('admin-session'),
                'last_activity' => time(),
            ],
        ]);

        $this->assertSame(2, DB::table('sessions')->where('user_id', $victim->id)->count());

        // Stale in-memory copy simulates the pre-deactivation session object.
        $staleVictim = $victim;

        // Gated route is reachable before deactivation.
        Sanctum::actingAs($staleVictim);
        $this->get('/api/teacher/announcements')->assertOk();

        // Deactivate via a second (admin) session.
        Sanctum::actingAs($admin);
        $this->post('/api/admin/users/'.$victim->id.'/deactivate')->assertOk();
        $this->assertDatabaseHas('users', ['id' => $victim->id, 'is_active' => false]);

        // Zero usable session rows remain for the victim; admin row survives.
        $this->assertSame(0, DB::table('sessions')->where('user_id', $victim->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $admin->id)->count());

        // Reuse of either victim session is rejected, even with the stale object.
        Sanctum::actingAs($staleVictim);
        $firstReuse = $this->get('/api/teacher/announcements');
        $this->assertContains($firstReuse->status(), [401, 403]);

        Sanctum::actingAs(clone $staleVictim);
        $secondReuse = $this->get('/api/teacher/announcements');
        $this->assertContains($secondReuse->status(), [401, 403]);

        // Logging rule: deactivation audit write carries no raw secrets.
        $log = AuditLog::query()
            ->where('description', 'User account deactivated')
            ->where('auditable_id', $victim->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $metadata = $log->metadata ?? [];
        $this->assertIsArray($metadata);
        foreach (['password', 'temporary_password', 'token', 'identifier', 'email', 'school_id', 'ip', 'ip_address'] as $key) {
            $this->assertArrayNotHasKey($key, $metadata);
        }
        $this->assertStringNotContainsStringIgnoringCase('VictimPass123', (string) $log->description);
        $this->assertStringNotContainsStringIgnoringCase('VictimPass123', json_encode($metadata));

        // Reactivation allows a fresh login to succeed.
        Sanctum::actingAs($admin);
        $this->post('/api/admin/users/'.$victim->id.'/reactivate')->assertOk();
        $this->assertDatabaseHas('users', ['id' => $victim->id, 'is_active' => true]);

        $this->post('/api/auth/login', [
            'identifier' => $victim->school_id,
            'password' => 'VictimPass123',
        ])->assertOk()->assertJsonPath('data.id', $victim->id);
    }
}
