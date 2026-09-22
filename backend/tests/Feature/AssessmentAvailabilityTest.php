<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentItem;
use App\Models\Classroom;
use App\Models\ClassroomEnrollment;
use App\Models\CompetencyReference;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Semester;
use App\Models\User;
use App\Services\ClassroomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F-04 / ARCH-002 FR-017: the availability window is enforced only at start.
 * A student who starts inside the window may still submit after the
 * window closes. No prod change — pins AssessmentService behavior.
 *
 * Also pins ARCH-004 §4.1: auto-save after the time limit is a hard 409.
 */
#[Group('assessment-availability')]
class AssessmentAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private ?User $teacher = null;

    private ?User $student = null;

    
    private ?Subject $subject = null;

    private ?Section $section = null;

    private ?CompetencyReference $competencyTag = null;

    private ?Classroom $classroom = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = null;
        $this->student = null;
        $this->section = null;
        $this->competencyTag = null;
        $this->classroom = null;
    }

    private function actAsTeacher(): User
    {
        if (! $this->teacher) {
            $this->teacher = User::factory()->create([
                'role' => 'Teacher',
                'must_change_password' => false]);
        }

        Sanctum::actingAs($this->teacher);

        return $this->teacher;
    }

    private function actAsStudent(): User
    {
        if (! $this->student) {
            $this->student = User::factory()->create([
                'role' => 'Student',
                'must_change_password' => false]);
        }

        Sanctum::actingAs($this->student);

        return $this->student;
    }

    private function setUpOrg(): void
    {
        $year = SchoolYear::create(['name' => 'SY 2026-AVAIL']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31']);
        $gradeLevel = GradeLevel::create([
            'semester_id' => $semester->id,
            'grade_level' => '7']);
        $this->section = Section::create([
            'grade_level_id' => $gradeLevel->id,
            'name' => '7A-AVAIL']);
$this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-AVAIL']);
                $this->competencyTag = CompetencyReference::create(['semester' => '1', 'code' => 'M7-NUM-AVAIL',
            'descriptor' => 'Solve multi-step rational numbers',
            'subject_id' => $this->subject->id,
            'grade_level' => '7']);

        $teacher = $this->actAsTeacher();

        $student = $this->actAsStudent();
        $this->classroom = app(ClassroomService::class)->createClassroom($teacher->id, $this->subject->id, $this->section->id, '2026-2027', null);
        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $student->id,
            'joined_at' => now()]);
    }

    private function basicItem(): array
    {
        return [
            'item_type' => 'multiple_choice',
            'prompt' => 'What is 2+2?',
            'max_points' => 10,
            'correct_answer' => 'B',
            'competency_tag_id' => $this->competencyTag->id,
            'sort_order' => 1];
    }

    public function test_submit_after_window_closed_succeeds_when_started_inside_window(): void
    {
        $this->setUpOrg();

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'title' => 'Window Test',
                'description' => '',
                'type' => 'Recorded',
                'availability_starts_at' => now()->subHour()->toDateTimeString(),
                'availability_ends_at' => now()->addHour()->toDateTimeString()])->json('data');

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", $this->basicItem());

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/release");

        // Start inside the window.
        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/start")
            ->assertOk();

        // Close the window after the start (ARCH-002 FR-017: window gates start only).
        Assessment::where('id', $assessment['id'])
            ->update(['availability_ends_at' => now()->subMinute()]);

        $itemId = (int) AssessmentItem::where('assessment_id', $assessment['id'])->value('id');

        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/submit", [
                'responses' => [$itemId => 'B']]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'scored');
    }

    public function test_auto_save_after_time_limit_returns_409(): void
    {
        $this->setUpOrg();

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'title' => 'Time Limit Test',
                'description' => '',
                'type' => 'Recorded',
                'time_limit' => 1])->json('data');

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", $this->basicItem());

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/release");

        $startData = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment['id']}/start")
            ->json('data');

        // Push the attempt past its 1-minute limit (ARCH-004 §4.1).
        AssessmentAttempt::where('id', $startData['attempt_id'])
            ->update(['started_at' => now()->subMinutes(10)]);

        $itemId = (int) AssessmentItem::where('assessment_id', $assessment['id'])->value('id');

        $response = $this->actingAs($this->student, 'sanctum')
            ->put("/api/student/assessments/{$assessment['id']}/auto-save", [
                'responses' => [$itemId => 'C']]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'TIME_LIMIT_EXPIRED');
    }
}
