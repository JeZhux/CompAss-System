<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\Classroom;
use App\Models\ClassroomEnrollment;
use App\Services\ClassroomService;
use App\Models\CompetencyReference;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ARCH-002 QA-002 (WU-5): the application clock is Asia/Manila (UTC+8).
 *
 * - `test_timestamped_payload_serializes_plus_0800_offset` — a real
 *   API-created resource timestamp must serialize with the +08:00 offset
 *   (never Z / +00:00).
 * - `test_app_timezone_is_asia_manila` — config + PHP default + Carbon offset.
 * - `test_br13_assignment_deadline_boundary_in_manila_local` — the ARCH-002 FR-013
 *   on-time/late boundary relative to a Manila-local deadline.
 * - `test_br53_availability_window_boundary_in_manila_local` — the ARCH-002 FR-017
 *   availability-window boundary (not-yet-open / open / closed) exercised
 *   against a Manila-local window.
 *
 * @Traced-To ARCH-002 FR-013, ARCH-002 FR-017, ARCH-002 QA-004, ARCH-002 QA-002 (implementation-plan WU-5)
 */
#[Group('phase5')]
class Phase5TimezoneTest extends TestCase
{
    use RefreshDatabase;

    private ?User $teacher = null;

    private ?User $student = null;

    
    private ?CompetencyReference $competencyTag = null;

    private ?Subject $subject = null;

    private ?Classroom $classroom = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = null;
        $this->student = null;
        $this->competencyTag = null;
        $this->classroom = null;
    }

    private function actAsTeacher(): User
    {
        if (! $this->teacher) {
            $this->teacher = User::factory()->create([
                'role' => 'Teacher',
                'must_change_password' => false,
            ]);
        }

        Sanctum::actingAs($this->teacher);

        return $this->teacher;
    }

    private function actAsStudent(): User
    {
        if (! $this->student) {
            $this->student = User::factory()->create([
                'role' => 'Student',
                'must_change_password' => false,
            ]);
        }

        Sanctum::actingAs($this->student);

        return $this->student;
    }

    private function setUpOrg(): void
    {
        $year = SchoolYear::create(['name' => 'SY 2026-TZ']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
        ]);
        $gradeLevel = GradeLevel::create([
            'semester_id' => $semester->id,
            'grade_level' => '7',
        ]);
        $section = Section::create([
            'grade_level_id' => $gradeLevel->id,
            'name' => '7A-TZ',
        ]);
$this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-TZ']);
                $this->competencyTag = CompetencyReference::create(['semester' => '1', 'code' => 'M7-TZ-001',
            'descriptor' => 'Timezone boundary probe',
            'subject_id' => $this->subject->id,
            'grade_level' => '7',
        ]);

        $this->classroom = app(ClassroomService::class)->createClassroom(
            $this->actAsTeacher()->id,
            $this->subject->id,
            $section->id,
            '2026-2027',
            null
        );
        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->actAsStudent()->id,
        ]);
    }

    private function createAssignmentViaApi(string $title, string $dueDate): int
    {
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assignments', [
                'title' => $title,
                'description' => '',
                'due_date' => $dueDate,
            ]);

        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    private function createAndReleaseAssessment(string $title, ?string $startsAt, ?string $endsAt): int
    {
        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'title' => $title,
                'description' => '',
                'type' => 'Recorded',
                'availability_starts_at' => $startsAt,
                'availability_ends_at' => $endsAt,
            ])
            ->assertCreated()
            ->json('data');

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ])
            ->assertCreated();

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/release")
            ->assertOk();

        return (int) $assessment['id'];
    }

    public function test_timestamped_payload_serializes_plus_0800_offset(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/announcements', [
                'title' => 'Timezone probe',
                'body' => 'Created at Manila clock.',
            ]);

        $response->assertCreated();
        $createdAt = $response->json('data.created_at');
        $this->assertIsString($createdAt);
        $this->assertNotEmpty($createdAt);

        // ISO-8601 with the +08:00 offset — NOT Z, NOT +00:00 (ARCH-002 QA-002).
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+08:00$/', $createdAt);
        $this->assertStringEndsWith('+08:00', $createdAt);
        $this->assertStringNotContainsString('Z', $createdAt);
        $this->assertStringNotContainsString('+00:00', $createdAt);
    }

    public function test_app_timezone_is_asia_manila(): void
    {
        $this->assertSame('Asia/Manila', config('app.timezone'));
        $this->assertSame('Asia/Manila', date_default_timezone_get());
        $this->assertSame('+08:00', now()->format('P'));
    }

    public function test_br13_assignment_deadline_boundary_in_manila_local(): void
    {
        Storage::fake('local');
        $this->setUpOrg();

        // Deadline is 16:00 Manila-local (naive string, parsed in app tz).
        $due = '2026-08-16 16:00:00';

        try {
            // On-time: 15:59:59 Manila — the school clock is still inside the day.
            Carbon::setTestNow(Carbon::parse('2026-08-16 15:59:59', 'Asia/Manila'));
            $onTimeAssignment = $this->createAssignmentViaApi('On-Time HW', $due);

            $onTime = $this->actingAs($this->student, 'sanctum')
                ->post("/api/student/assignments/{$onTimeAssignment}/submit", [
                    'files' => [
                        UploadedFile::fake()->create('on-time.pdf', 100, 'application/pdf'),
                    ],
                ]);

            $onTime->assertCreated();
            $onTime->assertJsonPath('data.is_late', false);
            $this->assertDatabaseHas('assignment_submissions', [
                'assignment_id' => $onTimeAssignment,
                'student_id' => $this->student->id,
                'status' => 'on_time',
            ]);

            // Late: 16:00:01 Manila — the deadline has passed (ARCH-002 FR-013).
            Carbon::setTestNow(Carbon::parse('2026-08-16 16:00:01', 'Asia/Manila'));
            $lateAssignment = $this->createAssignmentViaApi('Late HW', $due);

            $late = $this->actingAs($this->student, 'sanctum')
                ->post("/api/student/assignments/{$lateAssignment}/submit", [
                    'files' => [
                        UploadedFile::fake()->create('late.pdf', 100, 'application/pdf'),
                    ],
                ]);

            $late->assertCreated();
            $late->assertJsonPath('data.is_late', true);
            $this->assertDatabaseHas('assignment_submissions', [
                'assignment_id' => $lateAssignment,
                'student_id' => $this->student->id,
                'status' => 'late',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_br53_availability_window_boundary_in_manila_local(): void
    {
        $this->setUpOrg();

        try {
            // Not yet open: 12:59 Manila, window opens 13:00.
            Carbon::setTestNow(Carbon::parse('2026-08-16 12:59:00', 'Asia/Manila'));
            $notYetOpen = $this->createAndReleaseAssessment(
                'Not Yet Open TZ',
                '2026-08-16 13:00:00',
                '2026-08-16 16:00:00'
            );

            $this->actingAs($this->student, 'sanctum')
                ->post("/api/student/assessments/{$notYetOpen}/start")
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'ASSESSMENT_NOT_AVAILABLE');

            // Open: 13:01 Manila, window 12:00–16:00.
            Carbon::setTestNow(Carbon::parse('2026-08-16 13:01:00', 'Asia/Manila'));
            $open = $this->createAndReleaseAssessment(
                'Open TZ',
                '2026-08-16 12:00:00',
                '2026-08-16 16:00:00'
            );

            $this->actingAs($this->student, 'sanctum')
                ->post("/api/student/assessments/{$open}/start")
                ->assertOk();

            // Closed: 16:01 Manila, window closed at 16:00.
            Carbon::setTestNow(Carbon::parse('2026-08-16 16:01:00', 'Asia/Manila'));
            $closed = $this->createAndReleaseAssessment(
                'Closed TZ',
                '2026-08-16 12:00:00',
                '2026-08-16 16:00:00'
            );

            $this->actingAs($this->student, 'sanctum')
                ->post("/api/student/assessments/{$closed}/start")
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'ASSESSMENT_NOT_AVAILABLE');
        } finally {
            Carbon::setTestNow();
        }
    }
}
