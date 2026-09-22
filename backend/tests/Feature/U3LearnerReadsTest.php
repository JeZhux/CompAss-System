<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Classroom;
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
 * U3 — Backend learner reads: people (ARCH-005 block 4.18), rooms
 * (block 4.19), leave (block 4.20), and the human-name search / ID rule
 * (block 4.22) for reads.
 */
class U3LearnerReadsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private User $student;

    private User $outsider;

    
    private Section $section;

    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'Admin', 'must_change_password' => false]);
        $this->teacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $this->student = User::factory()->create(['role' => 'Student', 'must_change_password' => false, 'name' => 'Zed Learner']);
        $this->outsider = User::factory()->create(['role' => 'Student', 'must_change_password' => false]);

        $year = SchoolYear::create(['name' => 'SY-U3']);
        $term = Semester::create(['semester' => '1', 'school_year_id' => $year->id, 'name' => 'Semester 1', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31']);
        $gradeLevel = GradeLevel::create(['semester_id' => $term->id, 'grade_level' => '7']);
        $this->section = Section::create(['grade_level_id' => $gradeLevel->id, 'name' => '7A-U3']);
        $this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Math-U3', 'code' => 'MATH-U3']);
        
    }

    private function createClassroom(?string $suffix = null): Classroom
    {
        $service = app(ClassroomService::class);
        Sanctum::actingAs($this->teacher);

        return $service->createClassroom($this->teacher->id, $this->subject->id, $this->section->id, '2026-2027', $suffix);
    }

    private function join(User $student, Classroom $classroom, int $status = 201): void
    {
        Sanctum::actingAs($student);
        $this->postJson('/api/classrooms/join', ['key' => $classroom->join_key])->assertStatus($status);
    }

    public function test_people_unenrolled_403_and_unknown_404(): void
    {
        $classroom = $this->createClassroom();

        Sanctum::actingAs($this->outsider);
        $this->getJson('/api/student/classrooms/'.$classroom->id.'/people')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'NOT_ENROLLED');

        $this->getJson('/api/student/classrooms/999999/people')->assertStatus(404);
    }

    public function test_people_paged_alphabetical_names_only_no_pii(): void
    {
        $classroom = $this->createClassroom();

        $anna = User::factory()->create(['role' => 'Student', 'must_change_password' => false, 'name' => 'Anna Alpha']);
        $mid = User::factory()->create(['role' => 'Student', 'must_change_password' => false, 'name' => 'Mona Middle']);
        $this->join($anna, $classroom);
        $this->join($mid, $classroom);
        $this->join($this->student, $classroom);

        Sanctum::actingAs($this->student);
        $res = $this->getJson('/api/student/classrooms/'.$classroom->id.'/people?per_page=2&page=1')->assertOk();

        $this->assertSame(['Anna Alpha', 'Mona Middle'], array_column($res->json('data'), 'display_name'));
        $this->assertSame(3, $res->json('meta.total'));
        $this->assertSame(2, $res->json('meta.per_page'));
        $this->assertSame(1, $res->json('meta.current_page'));

        $res2 = $this->getJson('/api/student/classrooms/'.$classroom->id.'/people?per_page=2&page=2')->assertOk();
        $this->assertSame(['Zed Learner'], array_column($res2->json('data'), 'display_name'));

        foreach ([$res, $res2] as $page) {
            foreach ($page->json('data') as $row) {
                $this->assertEqualsCanonicalizing(['display_name'], array_keys($row));
            }
        }

        // Default page size is 15 and the payload carries no PII anywhere.
        $default = $this->getJson('/api/student/classrooms/'.$classroom->id.'/people')->assertOk();
        $this->assertSame(15, $default->json('meta.per_page'));
        $this->assertStringNotContainsString('@', $default->getContent());
        $this->assertStringNotContainsString('school_id', $default->getContent());
        $this->assertStringNotContainsString('joined_at', $default->getContent());
    }

    public function test_people_photo_opt_in_only(): void
    {
        $classroom = $this->createClassroom();

        $optIn = User::factory()->create(['role' => 'Student', 'must_change_password' => false, 'name' => 'Amy Optin', 'photo_opt_in' => true, 'photo_url' => 'https://photos.example/amy.jpg']);
        $optOut = User::factory()->create(['role' => 'Student', 'must_change_password' => false, 'name' => 'Ben Optout', 'photo_opt_in' => false, 'photo_url' => 'https://photos.example/ben.jpg']);
        $noUrl = User::factory()->create(['role' => 'Student', 'must_change_password' => false, 'name' => 'Cat Nourl', 'photo_opt_in' => true, 'photo_url' => null]);
        $this->join($optIn, $classroom);
        $this->join($optOut, $classroom);
        $this->join($noUrl, $classroom);
        $this->join($this->student, $classroom);

        Sanctum::actingAs($this->student);
        $res = $this->getJson('/api/student/classrooms/'.$classroom->id.'/people?per_page=100')->assertOk();

        $byName = [];
        foreach ($res->json('data') as $row) {
            $byName[$row['display_name']] = $row;
        }

        $this->assertSame('https://photos.example/amy.jpg', $byName['Amy Optin']['photo_url']);
        $this->assertArrayNotHasKey('photo_url', $byName['Ben Optout']);
        $this->assertArrayNotHasKey('photo_url', $byName['Cat Nourl']);
        $this->assertArrayNotHasKey('photo_url', $byName['Zed Learner']);
    }

    public function test_people_any_search_422_and_malformed_paging_422(): void
    {
        $classroom = $this->createClassroom();
        $this->join($this->student, $classroom);

        Sanctum::actingAs($this->student);
        $this->getJson('/api/student/classrooms/'.$classroom->id.'/people?search=an')
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->getJson('/api/student/classrooms/'.$classroom->id.'/people?search=123')
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->getJson('/api/student/classrooms/'.$classroom->id.'/people?page=0')->assertStatus(422);
        $this->getJson('/api/student/classrooms/'.$classroom->id.'/people?page=abc')->assertStatus(422);
        $this->getJson('/api/student/classrooms/'.$classroom->id.'/people?per_page=0')->assertStatus(422);
        $this->getJson('/api/student/classrooms/'.$classroom->id.'/people?per_page=101')->assertStatus(422);
    }

    public function test_people_archived_410(): void
    {
        $classroom = $this->createClassroom();
        $this->join($this->student, $classroom);

        Sanctum::actingAs($this->teacher);
        $this->postJson('/api/teacher/classrooms/'.$classroom->id.'/archive')->assertOk();

        Sanctum::actingAs($this->student);
        $this->getJson('/api/student/classrooms/'.$classroom->id.'/people')
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'CLASSROOM_ARCHIVED');
    }

    public function test_rooms_index_unpaginated_ordered_and_excludes_archived(): void
    {
        // One classroom per teacher/subject-section/year triple, so each
        // room needs its own subject-section.
        $suffixes = ['Zebra', 'Mango', 'Apple'];
        $rooms = [];
        foreach ($suffixes as $i => $suffix) {
            $subj = Subject::create(['grade_level_id' => $this->section->grade_level_id, 'name' => "Sub-U3-{$i}", 'code' => "SUB-U3-{$i}"]);
            Sanctum::actingAs($this->teacher);
            $rooms[] = app(ClassroomService::class)->createClassroom($this->teacher->id, $subj->id, $this->section->id, '2026-2027', $suffix);
        }
        [$zebra, $mango, $apple] = $rooms;

        // Join order is zebra, mango, apple; the index must order by name.
        $this->join($this->student, $zebra);
        $this->join($this->student, $mango);
        $this->join($this->student, $apple);

        Sanctum::actingAs($this->student);
        $res = $this->getJson('/api/student/classrooms')->assertOk();

        $this->assertArrayNotHasKey('meta', $res->json());
        $names = array_column($res->json('data'), 'name');
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);

        foreach ($res->json('data') as $row) {
            $this->assertEqualsCanonicalizing(['id', 'name', 'subject_name', 'section_name', 'group_name', 'school_year', 'archived_at'], array_keys($row));
        }

        // Archived rooms are excluded from the index.
        Sanctum::actingAs($this->teacher);
        $this->postJson('/api/teacher/classrooms/'.$zebra->id.'/archive')->assertOk();

        Sanctum::actingAs($this->student);
        $after = $this->getJson('/api/student/classrooms')->assertOk();
        $this->assertCount(2, $after->json('data'));
        $this->assertNotContains($zebra->name, array_column($after->json('data'), 'name'));
    }

    public function test_rooms_index_rejects_paging_and_search(): void
    {
        $classroom = $this->createClassroom();
        $this->join($this->student, $classroom);

        Sanctum::actingAs($this->student);
        $this->getJson('/api/student/classrooms?page=1')->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->getJson('/api/student/classrooms?per_page=15')->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->getJson('/api/student/classrooms?search=math')->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_rooms_detail_shape_and_archived_state(): void
    {
        $classroom = $this->createClassroom('Detail');

        Sanctum::actingAs($this->outsider);
        $this->getJson('/api/student/classrooms/'.$classroom->id)
            ->assertStatus(403)->assertJsonPath('error.code', 'NOT_ENROLLED');
        $this->getJson('/api/student/classrooms/999999')->assertStatus(404);

        $this->join($this->student, $classroom);

        Sanctum::actingAs($this->student);
        $res = $this->getJson('/api/student/classrooms/'.$classroom->id)->assertOk();

        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'subject_name', 'section_name', 'group_name', 'school_year', 'archived_at'],
            array_keys($res->json('data.classroom'))
        );
        $this->assertSame('Math-U3', $res->json('data.classroom.subject_name'));
        $this->assertSame('7A-U3', $res->json('data.classroom.group_name'));
        $this->assertSame('7A-U3', $res->json('data.classroom.section_name'));
        $this->assertSame('2026-2027', $res->json('data.classroom.school_year'));

        $this->getJson('/api/student/classrooms/'.$classroom->id.'?search=math')
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');

        // Archived rooms stay readable on detail and report the archived state.
        Sanctum::actingAs($this->teacher);
        $this->postJson('/api/teacher/classrooms/'.$classroom->id.'/archive')->assertOk();

        Sanctum::actingAs($this->student);
        $archived = $this->getJson('/api/student/classrooms/'.$classroom->id)->assertOk();
        $this->assertNotNull($archived->json('data.classroom.archived_at'));
    }

    public function test_leave_converges_without_second_write(): void
    {
        $classroom = $this->createClassroom();
        $this->join($this->student, $classroom);

        // Leave is reachable directly, without touching the people read.
        Sanctum::actingAs($this->student);
        $first = $this->postJson('/api/student/classrooms/'.$classroom->id.'/leave')->assertOk();
        $first->assertJsonPath('data.message', 'Left classroom.');
        $first->assertJsonPath('data.already_left', false);
        $this->assertDatabaseMissing('classroom_enrollments', ['classroom_id' => $classroom->id, 'student_id' => $this->student->id]);

        $deletes = AuditLog::where('event_type', 'delete')
            ->where('auditable_type', Classroom::class)
            ->where('auditable_id', $classroom->id)
            ->count();

        $second = $this->postJson('/api/student/classrooms/'.$classroom->id.'/leave')->assertOk();
        $second->assertJsonPath('data.already_left', true);

        $this->assertSame($deletes, AuditLog::where('event_type', 'delete')
            ->where('auditable_type', Classroom::class)
            ->where('auditable_id', $classroom->id)
            ->count());
    }

    public function test_leave_never_enrolled_403(): void
    {
        $classroom = $this->createClassroom();

        Sanctum::actingAs($this->outsider);
        $this->postJson('/api/student/classrooms/'.$classroom->id.'/leave')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'NOT_ENROLLED');

        $this->postJson('/api/student/classrooms/999999/leave')->assertStatus(404);
    }

    public function test_staff_number_only_search_422(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/admin/classrooms?search=12345')
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->getJson('/api/admin/teacher-assignments?search=777')
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        // /admin/subject-sections was intentionally removed (Semester restructure):
        // always 410 GONE, even for numeric search (deletion intentional).
        $this->getJson('/api/admin/subject-sections?search=42')
            ->assertStatus(410)->assertJsonPath('error.code', 'GONE');

        // Human-name search keeps working.
        $this->getJson('/api/admin/classrooms?search=Math-U3')->assertOk();
        $this->getJson('/api/admin/teacher-assignments?search=Math-U3')->assertOk();
        $this->getJson('/api/admin/subject-sections?search=Math-U3')->assertStatus(410)->assertJsonPath('error.code', 'GONE');
    }
}
