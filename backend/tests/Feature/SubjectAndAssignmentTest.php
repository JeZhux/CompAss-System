<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 1 — Subject catalogue, teacher assignment, and student enrollment
 * (§3.1.4/§3.1.5/§3.1.6; ARCH-004 §10/ARCH-002 FR-005/ARCH-002 FR-005/ARCH-002 FR-013).
 */
#[Group('subject-and-assignment')]
class SubjectAndAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function actAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'Admin',
            'must_change_password' => false]));
    }

    /** Build a real section through the API (school year → semester → grade level → section). */
    private function makeSection(): array
    {
        $this->actAsAdmin();
        // school_years.name is UNIQUE — each helper call builds its own
        // school year so multiple calls per test never collide.
        $year = $this->post('/api/admin/school-years', ['name' => 'SY '.uniqid()])->json('data');
        $semester = $this->post('/api/admin/school-years/'.$year['id'].'/semesters', [
            'semester' => '1', 'name' => 'Semester 1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'])->json('data');
        $gl = $this->post('/api/admin/semesters/'.$semester['id'].'/grade-levels', ['grade_level' => 8])
            ->json('data');

        return $this->post('/api/admin/grade-levels/'.$gl['id'].'/sections', ['name' => '8A'])
            ->json('data');
    }

    /** Grade level id for subject creation (subjects hang off a grade level). */
    private function makeGradeLevelId(): int
    {
        $section = $this->makeSection();

        return (int) (\App\Models\Section::findOrFail($section['id'])->grade_level_id);
    }

    public function test_subject_crud(): void
    {
        $this->actAsAdmin();
        $gradeLevelId = $this->makeGradeLevelId();

        $subj = $this->post('/api/admin/subjects', [
            'name' => 'Mathematics', 'code' => 'MTH', 'description' => 'd', 'grade_level_id' => $gradeLevelId])->assertCreated()->json('data');

        $this->put('/api/admin/subjects/'.$subj['id'], ['name' => 'Maths'])
            ->assertOk()->assertJsonPath('data.name', 'Maths');

        $this->delete('/api/admin/subjects/'.$subj['id'])->assertOk();
        $this->assertDatabaseMissing('subjects', ['id' => $subj['id']]);
    }

    public function test_duplicate_subject_code_rejected(): void
    {
        $this->actAsAdmin();
        $gradeLevelId = $this->makeGradeLevelId();
        $this->post('/api/admin/subjects', ['name' => 'Math', 'code' => 'MTH', 'grade_level_id' => $gradeLevelId]);

        $this->post('/api/admin/subjects', ['name' => 'Math2', 'code' => 'MTH', 'grade_level_id' => $gradeLevelId])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_subject_locked_while_referenced(): void
    {
        // ARCH-004 §10: a subject with classroom references cannot be deleted.
        $this->actAsAdmin();
        $gradeLevelId = $this->makeGradeLevelId();
        $subj = $this->post('/api/admin/subjects', ['name' => 'Math', 'code' => 'MTH', 'grade_level_id' => $gradeLevelId])->json('data');
        $section = $this->makeSection();
        // Classrooms now own the teacher/subject/section scope (no assignment row).
        $teacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $sectionModel = \App\Models\Section::findOrFail($section['id']);
        $subjectModel = \App\Models\Subject::findOrFail($subj['id']);
        // Subject and section live under different grade levels (separate
        // makeSection chains); align the classroom to the subject's grade
        // level so the same-grade guard passes.
        $alignedSection = \App\Models\Section::where('grade_level_id', $subjectModel->grade_level_id)->firstOrFail();
        app(\App\Services\ClassroomService::class)->createClassroom($teacher->id, $subjectModel->id, $alignedSection->id, '2026-2027', null);

        $this->delete('/api/admin/subjects/'.$subj['id'])
            ->assertStatus(409)->assertJsonPath('error.code', 'SUBJECT_HAS_ACTIVE_ASSIGNMENTS');
    }

    public function test_assign_and_remove_teacher_per_classroom_is_gone(): void
    {
        $this->actAsAdmin();
        $gradeLevelId = $this->makeGradeLevelId();
        $subj = $this->post('/api/admin/subjects', ['name' => 'Math', 'code' => 'MTH', 'grade_level_id' => $gradeLevelId])->json('data');
        $section = $this->makeSection();

        $teacher = User::factory()->create([
            'role' => 'Teacher', 'must_change_password' => false]);

        // Legacy section-assignment writes are 410 GONE — classrooms own the scope now.
        $this->post('/api/admin/sections/'.$section['id'].'/assignments', [
            'subject_id' => $subj['id'], 'teacher_id' => $teacher->id])->assertStatus(410)->assertJsonPath('error.code', 'GONE');

        $list = $this->get('/api/admin/sections/'.$section['id'].'/assignments')->assertOk()->json('data');
        $this->assertCount(0, $list);

        // Assigning a second teacher via the legacy route is also gone.
        $teacher2 = User::factory()->create([
            'role' => 'Teacher', 'must_change_password' => false]);
        $this->post('/api/admin/sections/'.$section['id'].'/assignments', [
            'subject_id' => $subj['id'], 'teacher_id' => $teacher2->id])->assertStatus(410)->assertJsonPath('error.code', 'GONE');

        // Assigning a non-teacher via the legacy route is also gone.
        $admin = User::factory()->admin()->create(['must_change_password' => false]);
        $this->post('/api/admin/sections/'.$section['id'].'/assignments', [
            'subject_id' => $subj['id'],
            'teacher_id' => $admin->id])->assertStatus(410)->assertJsonPath('error.code', 'GONE');
    }

    /**
     * Hard-delete pin (#27/#28/#29): the legacy section/student enrollment
     * routes were removed. Every enrollment URL 404s with NOT_FOUND —
     * for existing sections/students as well as missing ones.
     */
    public function test_enroll_student_routes_return_404(): void
    {
        $section = $this->makeSection();

        $student = User::factory()->student()->create([
            'must_change_password' => false]);
        $this->post('/api/admin/sections/'.$section['id'].'/enrollments', [
            'student_id' => $student->id])->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');

        $this->get('/api/admin/sections/'.$section['id'].'/enrollments')
            ->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');

        $this->get('/api/admin/students/'.$student->id.'/enrollments')
            ->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_show_nonexistent_subject_returns_405_method_not_allowed(): void
    {
        $this->actAsAdmin();

        // No GET /subjects/{id} route exists; only PUT and DELETE match that
        // URL pattern, so a GET yields 405 Method Not Allowed.
        $this->get('/api/admin/subjects/999999')
            ->assertStatus(405)->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
    }

    public function test_update_nonexistent_subject_returns_404(): void
    {
        $this->actAsAdmin();

        $this->put('/api/admin/subjects/999999', ['name' => 'Updated'])
            ->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_delete_nonexistent_subject_returns_404(): void
    {
        $this->actAsAdmin();

        $this->delete('/api/admin/subjects/999999')
            ->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_create_semester_for_nonexistent_school_year_returns_404(): void
    {
        $this->actAsAdmin();

        $this->post('/api/admin/school-years/999999/semesters', [
            'semester' => '1', 'name' => 'Semester 1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'])->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_create_grade_level_for_nonexistent_semester_returns_404(): void
    {
        $this->actAsAdmin();

        $this->post('/api/admin/semesters/999999/grade-levels', ['grade_level' => 8])
            ->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_create_section_for_nonexistent_grade_level_returns_404(): void
    {
        $this->actAsAdmin();

        $this->post('/api/admin/grade-levels/999999/sections', ['name' => '8A'])
            ->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_assign_teacher_to_nonexistent_section_returns_404(): void
    {
        $this->actAsAdmin();
        $gradeLevelId = $this->makeGradeLevelId();
        $subj = $this->post('/api/admin/subjects', ['name' => 'Math', 'code' => 'MTH', 'grade_level_id' => $gradeLevelId])->json('data');
        $section = $this->makeSection();

        $teacher = User::factory()->create([
            'role' => 'Teacher', 'must_change_password' => false]);

        $this->post('/api/admin/sections/999999/assignments', [
            'subject_id' => $subj['id'], 'teacher_id' => $teacher->id])->assertStatus(410)->assertJsonPath('error.code', 'GONE');
    }

    public function test_assign_teacher_with_nonexistent_subject_id_returns_gone(): void
    {
        $section = $this->makeSection();

        $teacher = User::factory()->create([
            'role' => 'Teacher', 'must_change_password' => false]);

        $this->post('/api/admin/sections/'.$section['id'].'/assignments', [
            'subject_id' => 999999, 'teacher_id' => $teacher->id])->assertStatus(410)->assertJsonPath('error.code', 'GONE');
    }

    public function test_enroll_student_to_nonexistent_section_returns_404(): void
    {
        $this->actAsAdmin();
        $student = User::factory()->student()->create(['must_change_password' => false]);

        // Hard-delete pin: the route itself is gone, so this 404s with
        // NOT_FOUND regardless of section existence.
        $this->post('/api/admin/sections/999999/enrollments', [
            'student_id' => $student->id])->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_enroll_nonexistent_student_returns_404(): void
    {
        $section = $this->makeSection();

        // Hard-delete pin: was 422 VALIDATION_ERROR for an unknown student;
        // the route is gone so this 404s instead.
        $this->post('/api/admin/sections/'.$section['id'].'/enrollments', [
            'student_id' => 999999])->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_index_assignments_for_nonexistent_section_returns_200_empty(): void
    {
        $this->actAsAdmin();

        $response = $this->get('/api/admin/sections/999999/assignments')->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    public function test_index_enrollments_for_nonexistent_section_returns_404(): void
    {
        $this->actAsAdmin();

        // Hard-delete pin: was 200 empty; the route is gone so this 404s.
        $this->get('/api/admin/sections/999999/enrollments')
            ->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_delete_nonexistent_assignment_returns_gone(): void
    {
        $section = $this->makeSection();

        // Legacy section-assignment writes are 410 GONE (classrooms own the
        // scope now) — including for nonexistent assignment ids.
        $this->delete('/api/admin/sections/'.$section['id'].'/assignments/999999')
            ->assertStatus(410)->assertJsonPath('error.code', 'GONE');
    }

    public function test_student_enrollments_for_nonexistent_student_returns_404(): void
    {
        $this->actAsAdmin();

        // Hard-delete pin: was 200 empty; the route is gone so this 404s.
        $this->get('/api/admin/students/999999/enrollments')
            ->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    }
}
