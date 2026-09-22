<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 1 — Role authorization (ARCH-002 QA-004/ARCH-002 QA-004): server-side only.
 */
#[Group('role-authorization')]
class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_forbidden_on_admin_route(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'Teacher',
            'must_change_password' => false,
        ]));

        $this->get('/api/admin/users')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_student_forbidden_on_admin_route(): void
    {
        Sanctum::actingAs(User::factory()->student()->create(['must_change_password' => false]));

        $this->get('/api/admin/users')->assertStatus(403);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->post('/api/admin/users', ['name' => 'x'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    /**
     * Every admin route must reject non-admin roles with FORBIDDEN.
     *
     * Intentionally excluded (hard-deleted legacy enrollment/import paths —
     * they 404 before role middleware runs, pinned in
     * SubjectAndAssignmentTest/BatchImportTest): #27/#28
     * admin/sections/{id}/enrollments, #29 admin/students/{id}/enrollments,
     * POST admin/import/student-enrollments, POST admin/import/students/csv.
     * The student_enrollment template slug stays listed below: the route
     * still exists and role middleware (403) runs before FormRequest
     * validation (422 for admins).
     */
    public static function adminRouteProvider(): array
    {
        return [
            ['GET', 'admin/users'],
            ['POST', 'admin/users'],
            ['GET', 'admin/users/1'],
            ['PUT', 'admin/users/1'],
            ['POST', 'admin/users/1/deactivate'],
            ['POST', 'admin/users/1/reactivate'],
            ['POST', 'admin/users/1/reset-password'],
            ['GET', 'admin/school-years'],
            ['POST', 'admin/school-years'],
            ['GET', 'admin/school-years/1/semesters'],
            ['POST', 'admin/school-years/1/semesters'],
            ['GET', 'admin/semesters/1/grade-levels'],
            ['POST', 'admin/semesters/1/grade-levels'],
            ['GET', 'admin/grade-levels/1/sections'],
            ['POST', 'admin/grade-levels/1/sections'],
            ['GET', 'admin/subjects'],
            ['POST', 'admin/subjects'],
            ['PUT', 'admin/subjects/1'],
            ['DELETE', 'admin/subjects/1'],
            ['GET', 'admin/sections/1/assignments'],
            ['POST', 'admin/sections/1/assignments'],
            ['DELETE', 'admin/sections/1/assignments/1'],
            ['GET', 'admin/import/templates/student_enrollment'],
            ['POST', 'admin/import/competency-tags'],
            ['GET', 'admin/import/error-reports/abc123'],
        ];
    }

    #[DataProvider('adminRouteProvider')]
    public function test_teacher_forbidden_on_all_admin_routes(string $method, string $uri): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'Teacher',
            'must_change_password' => false,
        ]));

        $this->call($method, '/api/'.$uri)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    #[DataProvider('adminRouteProvider')]
    public function test_student_forbidden_on_all_admin_routes(string $method, string $uri): void
    {
        Sanctum::actingAs(User::factory()->student()->create([
            'must_change_password' => false,
        ]));

        $this->call($method, '/api/'.$uri)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_teacher_can_access_me_endpoint(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'Teacher',
            'must_change_password' => false,
        ]));

        $this->get('/api/me')
            ->assertStatus(200)
            ->assertJsonPath('data.role', 'Teacher');
    }

    public function test_student_can_access_me_endpoint(): void
    {
        Sanctum::actingAs(User::factory()->student()->create([
            'must_change_password' => false,
        ]));

        $this->get('/api/me')
            ->assertStatus(200)
            ->assertJsonPath('data.role', 'Student');
    }

    public function test_admin_can_access_all_admin_routes(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'Admin',
            'must_change_password' => false,
        ]));

        // Read endpoints return 200 on an empty (refreshed) database.
        $this->get('/api/admin/users')->assertStatus(200);
        $this->get('/api/admin/subjects')->assertStatus(200);

        // Create endpoint returns 201 with a valid, minimal payload (ARCH-002 FR-005).
        $this->post('/api/admin/school-years', ['name' => '2026-2027'])
            ->assertStatus(201);
    }
}
