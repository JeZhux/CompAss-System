<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Phase D replacement for the obsolete EmailIdentityNormalizationTest.
 *
 * CompAss-ID only (Phase A): the single login identifier is school_id
 * (ADM/TEA/STU-XXXX-XXXXX), trimmed but case-sensitive. Creation takes
 * {name, role} only — IDs are server-generated, so there is no
 * create-duplicate matrix. This pins the login-variant matrix plus the
 * audit logging rule (no raw secrets in metadata).
 */
#[Group('school-id-normalization')]
class SchoolIdIdentityNormalizationTest extends TestCase
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

    private function assertNoSecretsInLog(AuditLog $log, array $forbiddenKeys): void
    {
        $metadata = $log->metadata ?? [];
        $this->assertIsArray($metadata);
        foreach ($forbiddenKeys as $key) {
            $this->assertArrayNotHasKey($key, $metadata);
        }
        $this->assertStringNotContainsStringIgnoringCase('password', json_encode($metadata));
    }

    public function test_created_accounts_get_role_prefixed_compass_ids(): void
    {
        $admin = $this->actAsAdmin();

        foreach (['Admin' => 'ADM-', 'Teacher' => 'TEA-', 'Student' => 'STU-'] as $role => $prefix) {
            $resp = $this->post('/api/admin/users', [
                'name' => $role.' User',
                'role' => $role,
            ])->assertCreated();

            $schoolId = $resp->json('data.school_id');
            $this->assertStringStartsWith($prefix, $schoolId);
            $this->assertMatchesRegularExpression('/^(ADM|TEA|STU)-[0-9]{4}-[0-9]{5}$/', $schoolId);
        }

        $createCount = AuditLog::query()->where('event_type', 'create')->count();
        $this->assertSame(3, $createCount);
        $createRow = AuditLog::query()->where('event_type', 'create')->latest('id')->first();
        $this->assertNotNull($createRow);
        $this->assertNoSecretsInLog($createRow, ['password', 'temporary_password', 'token']);
    }

    public function test_school_id_login_trims_but_is_case_sensitive(): void
    {
        $student = User::factory()->student()->create([
            'must_change_password' => false,
        ]);

        $this->post('/api/auth/login', [
            'identifier' => $student->school_id,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('data.role', 'Student');

        $this->post('/api/auth/login', [
            'identifier' => '  '.$student->school_id.'  ',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('data.role', 'Student');

        $loginCount = AuditLog::query()->where('event_type', 'login')->where('user_id', $student->id)->count();
        $this->assertSame(2, $loginCount);

        // Case variant must not resolve (IDs are case-sensitive).
        $this->post('/api/auth/login', [
            'identifier' => strtolower($student->school_id),
            'password' => 'password',
        ])->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

        $this->assertSame($loginCount, AuditLog::query()->where('event_type', 'login')->where('user_id', $student->id)->count());
    }

    public function test_successful_logins_write_audit_rows_without_secrets(): void
    {
        $teacher = User::factory()->teacher()->create([
            'must_change_password' => false,
        ]);

        foreach ([$teacher->school_id, '  '.$teacher->school_id.'  '] as $variant) {
            $this->post('/api/auth/login', [
                'identifier' => $variant,
                'password' => 'password',
            ])->assertOk()->assertJsonPath('data.role', 'Teacher');
        }

        $userId = $teacher->id;
        $this->assertSame(2, AuditLog::query()->where('event_type', 'login')->where('user_id', $userId)->count());
        foreach (AuditLog::query()->where('event_type', 'login')->where('user_id', $userId)->get() as $log) {
            $this->assertSame('User logged in', $log->description);
            $this->assertNoSecretsInLog($log, ['password', 'temporary_password', 'token', 'current_password', 'new_password']);
        }
    }
}
