<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * AUD-001 regression guard: every integer-consumed route parameter in
 * routes/api.php carries ->whereNumber(...), so a non-numeric segment fails
 * route matching with the 404 NOT_FOUND envelope instead of reaching a
 * controller's int-typed signature and surfacing as a 500 INTERNAL_ERROR.
 * Layer 2 (ValidateNumericRouteParams middleware) additionally range-checks
 * audited integer params, because whereNumber's unbounded [0-9]+ still lets
 * digit-only segments above PHP_INT_MAX through to a TypeError → 500.
 *
 * One representative route is probed per parameter-bearing middleware group.
 * Deliberate exclusions from both layers, each pinned by a test:
 * - {type}: import-template slug, string-validated by GetImportTemplateRequest;
 * - {token}: opaque error-report token, consumed via (string) route param;
 * - {userId} on audit endpoint #98: merged into AuditLogQueryRequest's
 *   validation data ('userId' => integer rule), whose tested contract is a
 *   422 VALIDATION_ERROR for non-integer input — not a routing 404.
 * Full behavioural coverage for the error-report token lives in BatchImportTest;
 * the student_enrollment template was hard-deleted (422 VALIDATION_ERROR pin
 * lives in BatchImportTest/ImportTemplateTest).
 */
#[Group('route-constraints')]
class RouteParamConstraintTest extends TestCase
{
    use RefreshDatabase;

    private function actAsRole(string $role): User
    {
        return Sanctum::actingAs(User::factory()->create([
            'role' => $role,
            'must_change_password' => false,
        ]));
    }

    public function test_phase1_admin_group_rejects_non_numeric_param_with_404(): void
    {
        $this->actAsRole('Admin');

        $this->get('/api/admin/users/abc')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_phase3_teacher_group_rejects_non_numeric_param_with_404(): void
    {
        $this->actAsRole('Teacher');

        $this->get('/api/teacher/assignments/abc')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_phase3_student_group_rejects_non_numeric_param_with_404(): void
    {
        $this->actAsRole('Student');

        $this->get('/api/student/assessments/abc/results')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    /**
     * Phase 4 mixed-role group (role:teacher,student,admin): non-numeric
     * {studentId} must fail routing, not reach GradingController.
     */
    public function test_phase4_mixed_role_group_rejects_non_numeric_param_with_404(): void
    {
        $this->actAsRole('Teacher');

        $this->get('/api/mastery/records/abc/summary')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    /**
     * Phase 4 teacher-only group: multi-param route — either non-numeric
     * segment ({assessmentId} or {attemptId}) must fail routing.
     */
    public function test_phase4_teacher_group_rejects_non_numeric_params_with_404(): void
    {
        $this->actAsRole('Teacher');

        $this->post('/api/assessments/abc/attempts/12/resubmit')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->post('/api/assessments/12/attempts/xyz/resubmit')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_phase5_teacher_group_rejects_non_numeric_param_with_404(): void
    {
        $this->actAsRole('Teacher');

        $this->get('/api/teacher/sections/abc/class-level-report')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_phase7_groups_reject_non_numeric_params_with_404(): void
    {
        $this->actAsRole('Teacher');

        $this->get('/api/teacher/moderation-log/abc')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->actAsRole('Student');

        $this->get('/api/student/explanations/abc')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    /**
     * AUD-001 layer 2: digit-only segments exceeding PHP_INT_MAX pass
     * whereNumber's unbounded [0-9]+ but must be range-rejected with the
     * 404 NOT_FOUND envelope — one representative per middleware group,
     * mirroring the structure above. Never 500 (coercive TypeError).
     */
    public function test_oversized_numeric_segments_reject_with_404_on_every_group(): void
    {
        $huge = str_repeat('9', 25);

        $probes = [
            ['GET', '/api/admin/users/'.$huge, 'Admin'],
            ['GET', '/api/teacher/assignments/'.$huge, 'Teacher'],
            ['GET', '/api/student/assessments/'.$huge.'/results', 'Student'],
            ['GET', '/api/mastery/records/'.$huge.'/summary', 'Teacher'],
            ['POST', '/api/assessments/'.$huge.'/attempts/12/resubmit', 'Teacher'],
            ['POST', '/api/assessments/12/attempts/'.$huge.'/resubmit', 'Teacher'],
            ['GET', '/api/teacher/sections/'.$huge.'/class-level-report', 'Teacher'],
            ['GET', '/api/teacher/moderation-log/'.$huge, 'Teacher'],
            ['GET', '/api/student/explanations/'.$huge, 'Student'],
        ];

        foreach ($probes as [$method, $uri, $role]) {
            $this->actAsRole($role);

            $response = $this->json($method, $uri);

            $this->assertSame(
                404,
                $response->status(),
                "Expected routing-level 404 for [$method $uri], got ".$response->status()
            );
            $this->assertSame('NOT_FOUND', $response->json('error.code'), "[$method $uri]");
        }
    }

    /**
     * Boundary: a segment of exactly PHP_INT_MAX is a legal int and must flow
     * through the full stack to the business layer (UserService::show →
     * User::findOrFail → ModelNotFoundException), yielding the business-level
     * 404 NOT_FOUND envelope — never a 500.
     */
    public function test_exactly_php_int_max_segment_follows_business_flow(): void
    {
        $this->actAsRole('Admin');

        $response = $this->get('/api/admin/users/'.PHP_INT_MAX);

        $response->assertStatus(404);
        $this->assertSame('NOT_FOUND', $response->json('error.code'));
    }

    /**
     * Phase 8 admin group — EXCLUDED from both constraint layers: {userId} on
     * audit endpoint #98 flows through AuditLogQueryRequest::validationData()
     * ('userId' => integer rule), whose existing tested contract (see
     * Phase8AuditLogTest, unmodified) is 422 VALIDATION_ERROR with the field
     * named in error.fields — richer semantics than a routing-level 404.
     * The integer rule also range-rejects oversized digit strings as 422.
     */
    public function test_phase8_audit_user_id_keeps_formrequest_422_contract(): void
    {
        $this->actAsRole('Admin');

        $response = $this->get('/api/admin/audit-logs/user/abc');

        $response->assertStatus(422);
        $this->assertSame('VALIDATION_ERROR', $response->json('error.code'));
        $this->assertArrayHasKey('userId', $response->json('error.fields'));

        // Same contract for digit-shaped-but-out-of-range input.
        $this->get('/api/admin/audit-logs/user/'.str_repeat('9', 25))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    /**
     * AUD-001 exclusions: {type} and {token} are consumed as strings and are
     * intentionally NOT whereNumber-constrained. {token} still matches string
     * segments; both template slugs still match routing and download (200).
     */
    public function test_excluded_string_param_routes_still_route_normally(): void
    {
        $this->actAsRole('Admin');

        // {type}: the student_enrollment slug still matches routing and now
        // downloads the enrollment template (200).
        $this->get('/api/admin/import/templates/student_enrollment')->assertOk();

        // {type}: surviving competency_tags slug still downloads.
        $this->get('/api/admin/import/templates/competency_tags')->assertOk();

        // {token}: opaque single-use token still matches; an unknown token
        // reaches BatchImportController and yields the business-level
        // 410 GONE — proof the route was hit at all.
        $this->get('/api/admin/import/error-reports/route-probe-unknown-token')
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'GONE');

        // A digit-shaped token is a legitimate opaque value: the middleware
        // allow-list must not intercept it, so it still reaches the hash lookup.
        $this->get('/api/admin/import/error-reports/'.str_repeat('9', 25))
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'GONE');
    }
}
