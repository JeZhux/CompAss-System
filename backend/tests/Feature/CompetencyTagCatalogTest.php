<?php

namespace Tests\Feature;

use App\Models\CompetencyReference;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompetencyTagCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function actAsAdmin(): User
    {
        return Sanctum::actingAs(User::factory()->create([
            'role' => 'Admin',
            'must_change_password' => false]));
    }

    private function seedTags(): array
    {
        $math = Subject::create(['name' => 'Mathematics', 'code' => 'MTH', 'description' => 'Math']);
        $sci = Subject::create(['name' => 'Science', 'code' => 'SCI', 'description' => 'Science']);

        CompetencyReference::create(['semester' => '1', 'code' => 'M7NS-Ia-1', 'descriptor' => 'Number sense basics',
            'subject_id' => $math->id, 'grade_level' => '7']);
        CompetencyReference::create(['semester' => '1', 'code' => 'M8AL-Px-5', 'descriptor' => 'Algebra patterns',
            'subject_id' => $math->id, 'grade_level' => '8']);
        CompetencyReference::create(['semester' => '1', 'code' => 'S7FE-Px-1', 'descriptor' => 'Force and energy',
            'subject_id' => $sci->id, 'grade_level' => '7']);

        return [$math, $sci];
    }

    public function test_admin_sees_seeded_tags_with_envelope(): void
    {
        $this->actAsAdmin();
        [$math] = $this->seedTags();

        $response = $this->get('/api/admin/competency-tags')->assertOk();

        $data = $response->json('data');
        $this->assertCount(3, $data);
        $this->assertSame('M7NS-Ia-1', $data[0]['code']);
        $this->assertSame('Number sense basics', $data[0]['descriptor']);
        $this->assertSame($math->id, $data[0]['subject_id']);
        $this->assertSame('Mathematics', $data[0]['subject_name']);
        $this->assertSame('MTH', $data[0]['subject_code']);
        $this->assertSame('7', (string) $data[0]['grade_level']);

        $response->assertJsonPath('meta.total', 3);
        $response->assertJsonPath('meta.current_page', 1);
    }

    public function test_search_filters_code_and_descriptor(): void
    {
        $this->actAsAdmin();
        $this->seedTags();

        $byCode = $this->get('/api/admin/competency-tags?search=M8AL')->assertOk()->json('data');
        $this->assertCount(1, $byCode);
        $this->assertSame('M8AL-Px-5', $byCode[0]['code']);

        $byDescriptor = $this->get('/api/admin/competency-tags?search=energy')->assertOk()->json('data');
        $this->assertCount(1, $byDescriptor);
        $this->assertSame('S7FE-Px-1', $byDescriptor[0]['code']);

        $none = $this->get('/api/admin/competency-tags?search=zzz-no-match')->assertOk()->json('data');
        $this->assertCount(0, $none);
    }

    public function test_digits_only_search_rejected_422(): void
    {
        $this->actAsAdmin();
        $this->seedTags();

        $this->get('/api/admin/competency-tags?search=12345')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_short_search_rejected_422_server_owns_min2(): void
    {
        $this->actAsAdmin();
        $this->seedTags();

        // Frontend min-2 guards are bypassable direct-API: single-char
        // searches must 422 server-side.
        $this->get('/api/admin/competency-tags?search=a')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_per_page_out_of_range_clamps_instead_of_422(): void
    {
        $this->actAsAdmin();
        $this->seedTags();

        // REM-036 convention: Pagination::perPage is the backstop (1..100),
        // so per_page=500 clamps to 100 rather than 422ing.
        $this->get('/api/admin/competency-tags?per_page=500')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonPath('meta.total', 3);
    }

    public function test_search_wildcards_match_literally_not_everything(): void
    {
        $this->actAsAdmin();
        $this->seedTags();

        // Unescaped %/_ would turn '%%' into a match-all; escaped, it must
        // match zero rows (no code/descriptor contains a literal '%%').
        $wild = $this->get('/api/admin/competency-tags?search=' . urlencode('%%'))->assertOk()->json('data');
        $this->assertCount(0, $wild);

        $underscore = $this->get('/api/admin/competency-tags?search=' . urlencode('M_NS'))->assertOk()->json('data');
        $this->assertCount(0, $underscore);
    }

    public function test_subject_and_grade_filters(): void
    {
        $this->actAsAdmin();
        [$math, $sci] = $this->seedTags();

        $bySubject = $this->get("/api/admin/competency-tags?subject_id={$sci->id}")->assertOk()->json('data');
        $this->assertCount(1, $bySubject);
        $this->assertSame('S7FE-Px-1', $bySubject[0]['code']);

        $byGrade = $this->get('/api/admin/competency-tags?grade_level=7')->assertOk()->json('data');
        $this->assertCount(2, $byGrade);

        $both = $this->get("/api/admin/competency-tags?subject_id={$math->id}&grade_level=8")->assertOk()->json('data');
        $this->assertCount(1, $both);
        $this->assertSame('M8AL-Px-5', $both[0]['code']);
    }

    public function test_pagination_works(): void
    {
        $this->actAsAdmin();
        $this->seedTags();

        $page1 = $this->get('/api/admin/competency-tags?per_page=2&page=1')->assertOk();
        $this->assertCount(2, $page1->json('data'));
        $page1->assertJsonPath('meta.total', 3);
        $page1->assertJsonPath('meta.per_page', 2);
        $page1->assertJsonPath('meta.last_page', 2);

        $page2 = $this->get('/api/admin/competency-tags?per_page=2&page=2')->assertOk();
        $this->assertCount(1, $page2->json('data'));
        $this->assertSame('S7FE-Px-1', $page2->json('data')[0]['code']);
    }

    public function test_invalid_filters_return_422(): void
    {
        $this->actAsAdmin();
        $this->seedTags();

        $this->get('/api/admin/competency-tags?grade_level=13')->assertStatus(422);
        $this->get('/api/admin/competency-tags?grade_level=abc')->assertStatus(422);
        $this->get('/api/admin/competency-tags?subject_id=abc')->assertStatus(422);
    }

    public function test_catalog_forbidden_for_teacher_and_student(): void
    {
        $this->seedTags();

        Sanctum::actingAs(User::factory()->create([
            'role' => 'Teacher', 'must_change_password' => false]));
        $this->get('/api/admin/competency-tags')->assertStatus(403);

        Sanctum::actingAs(User::factory()->student()->create(['must_change_password' => false]));
        $this->get('/api/admin/competency-tags')->assertStatus(403);
    }

    public function test_catalog_requires_authentication(): void
    {
        $this->get('/api/admin/competency-tags')->assertStatus(401);
    }
}
