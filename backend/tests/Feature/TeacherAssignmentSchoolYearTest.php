<?php

namespace Tests\Feature;

use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use App\Support\AcademicYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherAssignmentSchoolYearTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $teacher;
    private User $teacher2;
    private Subject $subject;
    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'Admin', 'must_change_password' => false]);
        $this->teacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $this->teacher2 = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $year = SchoolYear::create(['name' => 'SY-TA']);
        $semester = \App\Models\Semester::create(['semester' => '1', 'school_year_id' => $year->id, 'name' => 'Semester 1', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31']);
        $gl = GradeLevel::create(['semester_id' => $semester->id, 'grade_level' => '7']);
        $this->section = Section::create(['grade_level_id' => $gl->id, 'name' => '7A-TA']);
        $this->subject = Subject::create(['grade_level_id' => $gl->id, 'name' => 'Math-TA', 'code' => 'MATH-TA']);
    }

    public function test_legacy_teacher_assignment_writes_are_gone(): void
    {
        Sanctum::actingAs($this->admin);
        // Teacher scope is derived from classrooms now; legacy writes are 410.
        $this->postJson('/api/admin/teacher-assignments', [
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
        ])->assertStatus(410)->assertJsonPath('error.code', 'GONE');
        $this->postJson('/api/admin/teacher-assignments', [
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
            'school_year' => '2026-2027',
        ])->assertStatus(410)->assertJsonPath('error.code', 'GONE');
        $this->patchJson('/api/admin/teacher-assignments/1', ['school_year' => '2024-2025'])->assertStatus(410);
        $this->deleteJson('/api/admin/teacher-assignments/1')->assertStatus(410);
    }

    public function test_teacher_assignments_read_is_classroom_derived(): void
    {
        $svc = app(\App\Services\ClassroomService::class);
        Sanctum::actingAs($this->teacher);
        $svc->createClassroom($this->teacher->id, $this->subject->id, $this->section->id, '2026-2027', null);
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/admin/teacher-assignments?teacher_id='.$this->teacher->id)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/teacher-assignments?school_year=2026-2027')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/teacher-assignments?school_year=2025-2026')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_classroom_school_year_validation_mirrors(): void
    {
        $svc = app(\App\Services\ClassroomService::class);
        Sanctum::actingAs($this->teacher);
        $classroom = $svc->createClassroom($this->teacher->id, $this->subject->id, $this->section->id, '2026-2027', null);
        Sanctum::actingAs($this->admin);
        $this->patchJson('/api/admin/classrooms/'.$classroom->id, ['school_year' => '2026'])->assertStatus(422);
        $this->patchJson('/api/admin/classrooms/'.$classroom->id, ['school_year' => '2026-2028'])->assertStatus(422);
        $this->patchJson('/api/admin/classrooms/'.$classroom->id, ['school_year' => '2027-2028'])->assertStatus(200);
    }
}
