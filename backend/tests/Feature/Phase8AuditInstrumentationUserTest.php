<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\ClassroomEnrollment;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use App\Services\ClassroomService;
use Tests\TestCase;

/**
 * Phase 8 — audit-trail WRITE instrumentation coverage for the Phase 1/Phase 3
 * services (ARCH-002 QA-006, ARCH-001 §5.1): UserService reactivate/reset-password,
 * AnnouncementService delete, and AssignmentService create/update/delete.
 *
 * Every test drives a REAL API flow and asserts the exact audit_logs rows
 * written (event_type, user_id, auditable_type, auditable_id, description).
 * Negative/atomicity paths (ownership 404s, submission-guarded 409s,
 * confirmation-gated deletes) must write NO audit row. DoD visibility is
 * verified through the admin read endpoints (#96–#98).
 *
 * @Traced-To ARCH-002 QA-006 (ARCH-001 §5.1)
 */
#[Group('phase8-audit-instrumentation-user')]
class Phase8AuditInstrumentationUserTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_REACTIVATE_URL = '/api/admin/users/%d/reactivate';

    private const ADMIN_RESET_PASSWORD_URL = '/api/admin/users/%d/reset-password';

    private const TEACHER_ANNOUNCEMENTS_URL = '/api/teacher/announcements';

    private const TEACHER_ASSIGNMENTS_URL = '/api/teacher/assignments';

    private const STUDENT_ASSIGNMENTS_URL = '/api/student/assignments';

    private const AUDIT_LOGS_URL = '/api/admin/audit-logs';

    private const DUE_DATE = '2026-10-15 23:59:00';

    private ?User $admin = null;

    private ?User $teacher = null;

    private ?User $student = null;

    private ?Subject $subject = null;

    private ?Section $section = null;

    private ?Classroom $classroom = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = null;
        $this->teacher = null;
        $this->student = null;
        $this->subject = null;
        $this->section = null;
        $this->classroom = null;
    }

    private function actAsAdmin(): User
    {
        if (! $this->admin) {
            $this->admin = User::factory()->create([
                'role' => 'Admin',
                'must_change_password' => false,
            ]);
        }

        Sanctum::actingAs($this->admin);

        return $this->admin;
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

    /**
     * Minimal org hierarchy (School Year → Semester → Grade Level → Section →
     * Subject) with the teacher assigned and a student
     * enrolled — enough for announcement/assignment/submission flows.
     */
    private function setUpOrg(): void
    {
        $this->actAsTeacher();

        $year = SchoolYear::create(['name' => 'SY 2026-P8I']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
        ]);
        $gradeLevel = GradeLevel::create([
            'semester_id' => $semester->id,
            'grade_level' => '7',
        ]);
        $this->section = Section::create([
            'grade_level_id' => $gradeLevel->id,
            'name' => '7A-P8I',
        ]);
$this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-P8I']);
        
        $this->classroom = app(ClassroomService::class)->createClassroom($this->teacher->id, $this->subject->id, $this->section->id, '2026-2027', null);
        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->actAsStudent()->id,
            'joined_at' => now(),
        ]);
    }

    private function createAnnouncementViaApi(string $title): int
    {
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/' . $this->classroom->id . '/announcements', [
                'subject_id' => $this->subject->id,
                'title' => $title,
                'body' => 'Body text.',
            ]);

        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    private function createAssignmentViaApi(string $title): int
    {
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/' . $this->classroom->id . '/assignments', [
                'subject_id' => $this->subject->id,
                'title' => $title,
                'description' => '',
                'due_date' => self::DUE_DATE,
            ]);

        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    private function submitAssignment(int $assignmentId): void
    {
        Storage::fake('local');
        $this->actingAs($this->student, 'sanctum')
            ->post(self::STUDENT_ASSIGNMENTS_URL . '/' . $assignmentId . '/submit', [
                'files' => [
                    UploadedFile::fake()->create('submission.pdf', 100, 'application/pdf'),
                ],
            ])
            ->assertCreated();
    }

    private function countAuditRows(string $eventType, string $auditableType, int $auditableId): int
    {
        return AuditLog::query()
            ->where('event_type', $eventType)
            ->where('auditable_type', $auditableType)
            ->where('auditable_id', $auditableId)
            ->count();
    }

    // ========================================================================
    // UserService — admin reactivate / reset-password (ARCH-002 QA-006)
    // ========================================================================

    public function test_admin_reactivate_writes_update_audit_row(): void
    {
        $admin = $this->actAsAdmin();
        $target = User::factory()->create([
            'role' => 'Student',
            'is_active' => false,
            'must_change_password' => false,
        ]);

        $this->post(sprintf(self::ADMIN_REACTIVATE_URL, $target->id))->assertOk();

        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => true]);
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'update',
            'user_id' => $admin->id,
            'auditable_type' => User::class,
            'auditable_id' => $target->id,
            'description' => 'User account reactivated',
        ]);
    }

    public function test_admin_reset_password_writes_update_row_and_forces_change(): void
    {
        $admin = $this->actAsAdmin();
        $target = User::factory()->create([
            'role' => 'Teacher',
            'must_change_password' => false,
        ]);

        $response = $this->post(sprintf(self::ADMIN_RESET_PASSWORD_URL, $target->id));

        $response->assertOk();
        $response->assertJsonPath('data.message', 'Password reset.');
        $this->assertIsString($response->json('data.temporary_password'));
        $this->assertNotSame('', $response->json('data.temporary_password'));

        $this->assertDatabaseHas('users', ['id' => $target->id, 'must_change_password' => true]);
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'update',
            'user_id' => $admin->id,
            'auditable_type' => User::class,
            'auditable_id' => $target->id,
            'description' => 'User password reset',
        ]);
    }

    public function test_admin_reactivate_nonexistent_user_returns_404_and_writes_no_audit_row(): void
    {
        $this->actAsAdmin();

        $this->post(sprintf(self::ADMIN_REACTIVATE_URL, 999999))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->assertDatabaseMissing('audit_logs', ['event_type' => 'update']);
    }

    // ========================================================================
    // AnnouncementService — teacher delete (ARCH-002 QA-006)
    // ========================================================================

    public function test_teacher_delete_announcement_writes_delete_row_and_no_extra_rows(): void
    {
        $this->setUpOrg();
        $announcementId = $this->createAnnouncementViaApi('Disposable notice');

        $this->actingAs($this->teacher, 'sanctum')
            ->delete(self::TEACHER_ANNOUNCEMENTS_URL . '/' . $announcementId)
            ->assertOk();

        $this->assertDatabaseMissing('announcements', ['id' => $announcementId]);
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'delete',
            'user_id' => $this->teacher->id,
            'auditable_type' => Announcement::class,
            'auditable_id' => $announcementId,
            'description' => 'Announcement deleted (id ' . $announcementId . ')',
        ]);
        // No update row, and no duplicate create row — the single create row
        // comes from the announcement creation itself (ARCH-002 QA-006 atomicity).
        $this->assertSame(0, $this->countAuditRows('update', Announcement::class, $announcementId));
        $this->assertSame(1, $this->countAuditRows('create', Announcement::class, $announcementId));
    }

    // ========================================================================
    // AssignmentService — teacher create/update/delete (ARCH-002 QA-006)
    // ========================================================================

    public function test_teacher_create_assignment_writes_create_row_with_title_metadata(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/' . $this->classroom->id . '/assignments', [
                'subject_id' => $this->subject->id,
                'title' => 'Homework 1',
                'description' => 'Complete problems 1-10',
                'due_date' => self::DUE_DATE,
            ]);

        $response->assertCreated();
        $assignmentId = (int) $response->json('data.id');

        $row = AuditLog::query()
            ->where('event_type', 'create')
            ->where('user_id', $this->teacher->id)
            ->where('auditable_type', Assignment::class)
            ->where('auditable_id', $assignmentId)
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('Assignment created: Homework 1', $row->description);
        $this->assertSame(['title' => 'Homework 1', 'classroom_id' => $this->classroom->id], $row->metadata);
    }

    public function test_teacher_update_assignment_title_writes_exactly_one_update_row(): void
    {
        $this->setUpOrg();
        $assignmentId = $this->createAssignmentViaApi('Original title');

        $this->actingAs($this->teacher, 'sanctum')
            ->put(self::TEACHER_ASSIGNMENTS_URL . '/' . $assignmentId, [
                'title' => 'Renamed title',
            ])
            ->assertOk();

        $this->assertDatabaseHas('assignments', ['id' => $assignmentId, 'title' => 'Renamed title']);
        $rows = AuditLog::query()
            ->where('event_type', 'update')
            ->where('auditable_type', Assignment::class)
            ->where('auditable_id', $assignmentId)
            ->get();
        $this->assertCount(1, $rows);
        $this->assertSame($this->teacher->id, $rows->first()->user_id);
        $this->assertSame('Assignment updated (id ' . $assignmentId . ')', $rows->first()->description);
    }

    public function test_teacher_delete_assignment_without_submissions_writes_exactly_one_delete_row(): void
    {
        $this->setUpOrg();
        $assignmentId = $this->createAssignmentViaApi('Draft assignment');

        $this->actingAs($this->teacher, 'sanctum')
            ->delete(self::TEACHER_ASSIGNMENTS_URL . '/' . $assignmentId)
            ->assertOk();

        $this->assertDatabaseMissing('assignments', ['id' => $assignmentId]);
        $rows = AuditLog::query()
            ->where('event_type', 'delete')
            ->where('auditable_type', Assignment::class)
            ->where('auditable_id', $assignmentId)
            ->get();
        $this->assertCount(1, $rows);
        $this->assertSame($this->teacher->id, $rows->first()->user_id);
        $this->assertSame('Assignment deleted (id ' . $assignmentId . ')', $rows->first()->description);
    }

    public function test_update_blocked_by_submissions_writes_no_audit_row(): void
    {
        $this->setUpOrg();
        $assignmentId = $this->createAssignmentViaApi('Locked title');
        $this->submitAssignment($assignmentId);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->put(self::TEACHER_ASSIGNMENTS_URL . '/' . $assignmentId, [
                'title' => 'Blocked rename',
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'SUBMISSIONS_EXIST_BLOCK_STRUCTURAL_EDIT');
        $this->assertDatabaseHas('assignments', ['id' => $assignmentId, 'title' => 'Locked title']);
        $this->assertDatabaseMissing('audit_logs', [
            'event_type' => 'update',
            'auditable_type' => Assignment::class,
            'auditable_id' => $assignmentId,
        ]);
    }

    public function test_delete_with_submissions_requires_confirmation_before_writing_delete_row(): void
    {
        $this->setUpOrg();
        $assignmentId = $this->createAssignmentViaApi('With submissions');
        $this->submitAssignment($assignmentId);

        $first = $this->actingAs($this->teacher, 'sanctum')
            ->delete(self::TEACHER_ASSIGNMENTS_URL . '/' . $assignmentId);

        $first->assertOk();
        $first->assertJsonPath('data.confirmation_required', true);
        $first->assertJsonPath('data.submission_count', 1);
        $this->assertDatabaseHas('assignments', ['id' => $assignmentId]);
        $this->assertDatabaseMissing('audit_logs', [
            'event_type' => 'delete',
            'auditable_type' => Assignment::class,
            'auditable_id' => $assignmentId,
        ]);

        $this->actingAs($this->teacher, 'sanctum')
            ->post(self::TEACHER_ASSIGNMENTS_URL . '/' . $assignmentId . '/confirm-delete')
            ->assertOk();

        $this->assertDatabaseMissing('assignments', ['id' => $assignmentId]);
        $rows = AuditLog::query()
            ->where('event_type', 'delete')
            ->where('auditable_type', Assignment::class)
            ->where('auditable_id', $assignmentId)
            ->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Assignment deleted (id ' . $assignmentId . ')', $rows->first()->description);
    }

    // ========================================================================
    // Ownership — non-owned resources (ARCH-002 FR-011) must not write audit rows
    // ========================================================================

    public function test_delete_non_owned_announcement_returns_404_and_writes_no_audit_row(): void
    {
        $this->setUpOrg();
        $announcementId = $this->createAnnouncementViaApi('Owner only');

        $intruder = User::factory()->create([
            'role' => 'Teacher',
            'must_change_password' => false,
        ]);
        Sanctum::actingAs($intruder);

        $this->delete(self::TEACHER_ANNOUNCEMENTS_URL . '/' . $announcementId)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->assertDatabaseHas('announcements', ['id' => $announcementId]);
        $this->assertDatabaseMissing('audit_logs', [
            'event_type' => 'delete',
            'auditable_type' => Announcement::class,
            'auditable_id' => $announcementId,
        ]);
    }

    public function test_delete_non_owned_assignment_returns_404_and_writes_no_audit_row(): void
    {
        $this->setUpOrg();
        $assignmentId = $this->createAssignmentViaApi('Owner only');

        $intruder = User::factory()->create([
            'role' => 'Teacher',
            'must_change_password' => false,
        ]);
        Sanctum::actingAs($intruder);

        $this->delete(self::TEACHER_ASSIGNMENTS_URL . '/' . $assignmentId)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->assertDatabaseHas('assignments', ['id' => $assignmentId]);
        $this->assertDatabaseMissing('audit_logs', [
            'event_type' => 'delete',
            'auditable_type' => Assignment::class,
            'auditable_id' => $assignmentId,
        ]);
    }

    // ========================================================================
    // Phase 8 DoD — write coverage verified via the admin read endpoints (#96)
    // ========================================================================

    public function test_write_rows_are_visible_via_admin_audit_log_read_endpoint(): void
    {
        $this->setUpOrg();
        $announcementId = $this->createAnnouncementViaApi('Visible notice');
        $this->actingAs($this->teacher, 'sanctum')
            ->delete(self::TEACHER_ANNOUNCEMENTS_URL . '/' . $announcementId)
            ->assertOk();
        $assignmentId = $this->createAssignmentViaApi('Visible assignment');

        $this->actAsAdmin();

        $response = $this->get(self::AUDIT_LOGS_URL . '?user_id=' . $this->teacher->id);

        $response->assertOk();
        $items = $response->json('data');
        $this->assertNotEmpty($items);
        $descriptions = array_column($items, 'description');
        $this->assertContains('Announcement created: Visible notice', $descriptions);
        $this->assertContains('Announcement deleted (id ' . $announcementId . ')', $descriptions);
        $this->assertContains('Assignment created: Visible assignment', $descriptions);
        foreach ($items as $item) {
            $this->assertSame($this->teacher->id, $item['user_id']);
        }
    }
}
