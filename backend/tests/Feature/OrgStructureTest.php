<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Exceptions\BusinessRuleConflictException;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Semester;
use App\Models\User;
use App\Services\OrgStructureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 1 — Organizational structure endpoints §3.1.3 (ARCH-002 FR-005/ARCH-002 FR-005/ARCH-004 §10).
 */
#[Group('org-structure')]
class OrgStructureTest extends TestCase
{
    use RefreshDatabase;

    private function actAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'Admin',
            'must_change_password' => false,
        ]));
    }

    public function test_full_hierarchy_crud(): void
    {
        $this->actAsAdmin();

        $year = $this->post('/api/admin/school-years', ['name' => '2026-27'])
            ->assertCreated()->json('data');

        $semester = $this->post('/api/admin/school-years/'.$year['id'].'/semesters', [
            'semester' => '1',
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
        ])->assertCreated()->json('data');

        $gl = $this->post('/api/admin/semesters/'.$semester['id'].'/grade-levels', ['grade_level' => 7])
            ->assertCreated()->json('data');

        $section = $this->post('/api/admin/grade-levels/'.$gl['id'].'/sections', ['name' => '7A'])
            ->assertCreated()->json('data');

        $this->assertSame('2026-27', SchoolYear::find($year['id'])->name);
        $this->assertSame(7, (int) GradeLevel::find($gl['id'])->grade_level);
        $this->assertSame('7A', Section::find($section['id'])->name);

        $this->get('/api/admin/school-years')->assertOk();
        $this->get('/api/admin/school-years/'.$year['id'].'/semesters')->assertOk();
        $this->get('/api/admin/semesters/'.$semester['id'].'/grade-levels')->assertOk();
        $this->get('/api/admin/grade-levels/'.$gl['id'].'/sections')->assertOk();
    }

    public function test_invalid_grade_level_rejected(): void
    {
        $this->actAsAdmin();
        $year = $this->post('/api/admin/school-years', ['name' => 'Y'])->json('data');
        $semester = $this->post('/api/admin/school-years/'.$year['id'].'/semesters', [
            'semester' => '1', 'name' => 'Semester 1', 'start_date' => '2026-01-01', 'end_date' => '2026-06-30',
        ])->json('data');

        $this->post('/api/admin/semesters/'.$semester['id'].'/grade-levels', ['grade_level' => 6])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_GRADE_LEVEL');

        $this->assertDatabaseMissing('grade_levels', ['semester_id' => $semester['id']]);
    }

    public function test_semester_date_validation_enforced(): void
    {
        $this->actAsAdmin();
        $year = $this->post('/api/admin/school-years', ['name' => 'Y2'])->json('data');

        $this->post('/api/admin/school-years/'.$year['id'].'/semesters', [
            'semester' => '1', 'name' => 'Semester 1',
            'start_date' => '2026-12-31',
            'end_date' => '2026-01-01',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('semesters', ['school_year_id' => $year['id']]);
    }

    public function test_grade_levels_seven_through_twelve_all_supported(): void
    {
        $this->actAsAdmin();
        $year = $this->post('/api/admin/school-years', ['name' => 'Y3'])->json('data');
        $semester = $this->post('/api/admin/school-years/'.$year['id'].'/semesters', [
            'semester' => '1', 'name' => 'Semester 1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ])->json('data');

        foreach ([7, 8, 9, 10, 11, 12] as $level) {
            $this->post('/api/admin/semesters/'.$semester['id'].'/grade-levels', ['grade_level' => $level])
                ->assertCreated();
        }

        $this->assertDatabaseHas('grade_levels', ['semester_id' => $semester['id']]);
    }

    public function test_out_of_range_grade_levels_rejected(): void
    {
        $this->actAsAdmin();
        $year = $this->post('/api/admin/school-years', ['name' => 'Y4'])->json('data');
        $semester = $this->post('/api/admin/school-years/'.$year['id'].'/semesters', [
            'semester' => '1', 'name' => 'Semester 1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ])->json('data');

        foreach ([6, 13] as $level) {
            $this->post('/api/admin/semesters/'.$semester['id'].'/grade-levels', ['grade_level' => $level])
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'INVALID_GRADE_LEVEL');
        }

        $this->assertDatabaseMissing('grade_levels', ['semester_id' => $semester['id']]);
    }

    public function test_duplicate_grade_level_same_term_conflict(): void
    {
        $this->actAsAdmin();
        $year = $this->post('/api/admin/school-years', ['name' => 'Y5'])->json('data');
        $semester = $this->post('/api/admin/school-years/'.$year['id'].'/semesters', [
            'semester' => '1', 'name' => 'Semester 1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ])->json('data');

        $this->post('/api/admin/semesters/'.$semester['id'].'/grade-levels', ['grade_level' => 7])
            ->assertCreated();

        $this->post('/api/admin/semesters/'.$semester['id'].'/grade-levels', ['grade_level' => 7])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'GRADE_LEVEL_ALREADY_EXISTS');

        $this->assertSame(1, GradeLevel::where('semester_id', $semester['id'])->count());
    }

    public function test_same_grade_level_under_different_semester_succeeds(): void
    {
        $this->actAsAdmin();
        $year = $this->post('/api/admin/school-years', ['name' => 'Y6'])->json('data');
        $firstSemester = $this->post('/api/admin/school-years/'.$year['id'].'/semesters', [
            'semester' => '1', 'name' => 'Semester 1', 'start_date' => '2026-01-01', 'end_date' => '2026-06-30',
        ])->json('data');
        $secondSemester = $this->post('/api/admin/school-years/'.$year['id'].'/semesters', [
            'semester' => '2', 'name' => 'Semester 2', 'start_date' => '2026-07-01', 'end_date' => '2026-12-31',
        ])->json('data');

        $this->post('/api/admin/semesters/'.$firstSemester['id'].'/grade-levels', ['grade_level' => 7])
            ->assertCreated();
        $this->post('/api/admin/semesters/'.$secondSemester['id'].'/grade-levels', ['grade_level' => 7])
            ->assertCreated();

        $this->assertSame(2, GradeLevel::whereIn('semester_id', [$firstSemester['id'], $secondSemester['id']])->count());
    }

    public function test_direct_service_duplicate_insert_surfaces_conflict(): void
    {
        $year = SchoolYear::create(['name' => 'Y7']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);
        GradeLevel::create(['semester_id' => $semester->id, 'grade_level' => '7']);

        try {
            $this->app->make(OrgStructureService::class)->createGradeLevel($semester->id, 7);

            $this->fail('Expected BusinessRuleConflictException was not thrown.');
        } catch (BusinessRuleConflictException $e) {
            $this->assertSame('GRADE_LEVEL_ALREADY_EXISTS', $e->errorCode);
            $this->assertSame(409, $e->getStatusCode());
        }
    }
}
