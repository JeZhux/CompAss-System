<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S-5 throttle-keying pin (ARCH-002 QA-003 per-student vs ARCH-002 QA-004 per-IP).
 *
 * ai-generate and ai-chat are per-student: two students sharing one IP each
 * get a full 10/min and 30/min budget respectively. import is per-admin
 * (same per-user convention as ai-chat/ai-generate): admins sharing one IP
 * each get a full 10/min budget.
 */
class ThrottleKeyingTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_generate_is_per_student_shared_ip(): void
    {
        $s1 = User::factory()->student()->create(['must_change_password' => false]);
        $s2 = User::factory()->student()->create(['must_change_password' => false]);
        $ip = '198.51.100.10';

        Sanctum::actingAs($s1);
        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/student/assessments/999999/explanations/generate')
                ->assertStatus(404);
        }

        Sanctum::actingAs($s2);
        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/student/assessments/999999/explanations/generate')
                ->assertStatus(404);
        }
    }

    public function test_ai_chat_is_per_student_shared_ip(): void
    {
        $s1 = User::factory()->student()->create(['must_change_password' => false]);
        $s2 = User::factory()->student()->create(['must_change_password' => false]);
        $ip = '198.51.100.11';

        Sanctum::actingAs($s1);
        for ($i = 0; $i < 30; $i++) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/student/explanations/1/explain-further', []);
            $this->assertNotSame(429, $response->getStatusCode(), 'Request ' . ($i + 1) . ' must not be throttled yet (limit is 30/min).');
        }
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/student/explanations/1/explain-further', [])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED')
            ->assertHeader('X-RateLimit-Limit', '30')
            ->assertHeader('X-RateLimit-Remaining', '0');

        // Same IP, different student: full budget untouched by s1's exhaustion.
        Sanctum::actingAs($s2);
        for ($i = 0; $i < 30; $i++) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/student/explanations/1/explain-further', []);
            $this->assertNotSame(429, $response->getStatusCode(), 's2 request ' . ($i + 1) . ' must not share s1 bucket.');
        }
    }

    public function test_import_is_per_user_shared_ip(): void
    {
        $a1 = User::factory()->create(['role' => 'Admin', 'must_change_password' => false]);
        $a2 = User::factory()->create(['role' => 'Admin', 'must_change_password' => false]);
        $ip = '198.51.100.12';

        Sanctum::actingAs($a1);
        for ($i = 0; $i < 10; $i++) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/admin/import/competency-tags', []);
            $this->assertNotSame(429, $response->getStatusCode(), 'Request ' . ($i + 1) . ' must not be throttled yet (limit is 10/min).');
        }

        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/admin/import/competency-tags', [])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED')
            ->assertHeader('X-RateLimit-Limit', '10')
            ->assertHeader('X-RateLimit-Remaining', '0');

        // Same IP, different admin: full budget untouched by a1's exhaustion.
        Sanctum::actingAs($a2);
        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/admin/import/competency-tags', []);
        $this->assertNotSame(429, $response->getStatusCode(), 'a2 must not share a1 bucket (per-user keying).');
    }
}
