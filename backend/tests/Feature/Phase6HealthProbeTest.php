<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Phase 6 — `/up` health endpoint probing (ARCH-006 §12, ARC-04).
 *
 * The framework health route (`health: '/up'` in bootstrap/app.php) is the
 * only monitoring surface (minimal-monitoring posture, ARCH-006 §10). These
 * tests pin the contract the process-manager probe and the CI health-probe
 * job consume: 200 from an unauthenticated caller, no DB dependency.
 *
 * @Traced-To ARCH-006 §12, ARC-04, ARCH-006 §10 (BASELINE §23 limitation 7)
 */
#[Group('phase6-health-probe')]
class Phase6HealthProbeTest extends TestCase
{
    public function test_up_health_route_returns_200(): void
    {
        $this->get('/up')->assertStatus(200);
    }

    public function test_up_health_route_is_unauthenticated(): void
    {
        $this->get('/up')->assertStatus(200);
    }

    public function test_up_health_route_returns_up_status_for_json_callers(): void
    {
        $this->getJson('/up')
            ->assertStatus(200)
            ->assertJson(['status' => 'up']);
    }
}
