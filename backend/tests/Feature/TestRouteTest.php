<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 0 regression harness for the /test/* routes retained in routes/api.php.
 *
 * Phase D refresh: /test/login now takes {school_id, password} (CompAss ID,
 * ADM/TEA/STU-XXXX-XXXXX) — email removed.
 */
#[Group('test-route')]
class TestRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_success(): void
    {
        $user = User::factory()->admin()->create([
            'must_change_password' => false]);

        $this->post('/api/test/login', [
            'school_id' => $user->school_id,
            'password' => 'password'])->assertOk()->assertJsonPath('user.role', 'Admin');
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $user = User::factory()->admin()->create([
            'must_change_password' => false]);

        $this->post('/api/test/login', [
            'school_id' => $user->school_id,
            'password' => 'wrong-password'])->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_protected_route_requires_authentication(): void
    {
        $this->get('/api/test/protected')->assertStatus(401);
    }

    public function test_admin_can_access_protected_route(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create([
            'must_change_password' => false]));

        $this->get('/api/test/protected')->assertOk()->assertJsonPath('user.role', 'Admin');
    }

    public function test_non_admin_gets_forbidden_on_protected_route(): void
    {
        Sanctum::actingAs(User::factory()->teacher()->create([
            'must_change_password' => false]));

        $this->get('/api/test/protected')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_error_route_returns_envelope(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create([
            'must_change_password' => false]));

        $this->get('/api/test/error')
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'INTERNAL_ERROR');
    }

    public function test_login_missing_school_id_returns_422(): void
    {
        $this->post('/api/test/login', [
            'password' => 'password'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.fields.school_id.0', function ($message) {
                return is_string($message) && strlen($message) > 0;
            });
    }

    public function test_login_missing_password_returns_422(): void
    {
        $user = User::factory()->admin()->create([
            'must_change_password' => false]);

        $this->post('/api/test/login', [
            'school_id' => $user->school_id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.fields.password.0', function ($message) {
                return is_string($message) && strlen($message) > 0;
            });
    }

    public function test_login_with_invalid_school_id_format_returns_422(): void
    {
        $this->post('/api/test/login', [
            'school_id' => 'not-an-id',
            'password' => 'pass'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.fields.school_id.0', function ($message) {
                return is_string($message) && strlen($message) > 0;
            });
    }

    public function test_login_with_malformed_json_does_not_crash(): void
    {
        $body = 'not valid json';

        $response = $this->call(
            'POST',
            '/api/test/login',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_CONTENT_TYPE' => 'application/json',
                'CONTENT_LENGTH' => strlen($body)],
            $body
        );

        $this->assertNotEquals(500, $response->getStatusCode());

        $decoded = json_decode($response->getContent(), true);
        $this->assertNotNull($decoded, 'Response must be valid JSON.');
    }

    public function test_login_with_empty_body_returns_422(): void
    {
        $this->post('/api/test/login', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.fields.school_id.0', function ($message) {
                return is_string($message) && strlen($message) > 0;
            })
            ->assertJsonPath('error.fields.password.0', function ($message) {
                return is_string($message) && strlen($message) > 0;
            });
    }
}
