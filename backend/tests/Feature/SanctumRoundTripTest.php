<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end cookie/session round-trip through the Phase 0 /test/* harness.
 *
 * Unlike TestRouteTest (which uses Sanctum::actingAs() to inject an API token),
 * this test exercises the real login → session-regenerate → Sanctum cookie
 * resolution → logout flow. It verifies the SPA cookie-based auth contract
 * (ARCH-002 QA-007, ARCH-002 QA-004) at the integration boundary, not just the middleware
 * in isolation.
 *
 * @Traced-To ARCH-002 QA-007 (SPA cookie auth), ARCH-002 QA-004 (session timeout gate),
 *      ARCH-002 QA-005 (PSR-12)
 */
#[Group('sanctum-round-trip')]
class SanctumRoundTripTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_round_trip_login_protected_logout_unprotected(): void
    {
        $user = User::factory()->admin()->create([
            'must_change_password' => false]);

        $this->post('/api/test/login', [
            'school_id' => $user->school_id,
            'password' => 'password'])->assertOk()->assertJsonPath('user.role', 'Admin');

        $this->get('/api/test/protected')
            ->assertOk()
            ->assertJsonPath('user.role', 'Admin');

        $this->post('/api/test/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logout successful.');

        $this->get('/api/test/protected')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }
}
