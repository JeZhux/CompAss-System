<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Exceptions\BusinessRuleConflictException;
use App\Models\AIExplanation;
use App\Models\Announcement;
use App\Models\AnnouncementAttachment;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentItem;
use App\Models\AssessmentItemAttachment;
use App\Models\AssessmentResponse;
use App\Models\AssessmentSubmission;
use App\Models\Assignment;
use App\Models\AssignmentAttachment;
use App\Models\AssignmentFeedback;
use App\Models\AssignmentSubmission;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\ClassroomEnrollment;
use App\Models\CompetencyReference;
use App\Models\GradeLevel;
use App\Models\LearningMaterial;
use App\Models\MasteryRecord;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\SubmissionFile;
use App\Models\Semester;
use App\Models\User;
use App\Services\AnnouncementService;
use App\Services\AssignmentService;
use App\Services\AssessmentService;
use App\Services\AuditLogService;
use App\Services\ClassroomService;
use App\Services\LearningMaterialService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 9 — Data retention & anonymization (ARCH-002 QA-006, ARCH-002 QA-006), closed-term
 * purge (ARCH-002 QA-010, ARCH-002 QA-010), login rate limiting (ARCH-002 QA-004, ARCH-002 QA-004) and the upload
 * whitelist (ARCH-002 FR-020, ARCH-002 FR-027, ARCH-002 QA-009).
 *
 * @Traced-To ARCH-002 FR-020, ARCH-002 FR-027, ARCH-002 QA-010, ARCH-002 QA-010, ARCH-002 QA-004, ARCH-002 QA-006, ARCH-002 QA-009, ARCH-002 QA-006, ARCH-002 QA-004
 */
#[Group('phase9-retention-and-purge')]
class Phase9RetentionAndPurgeTest extends TestCase
{
    use RefreshDatabase;

    private ?Subject $subject = null;

    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function makeUser(string $role, array $overrides = []): User
    {
        return User::factory()->{$role}()->create(array_merge([
            'must_change_password' => false], $overrides));
    }

    private function createAuditRow(array $overrides = []): AuditLog
    {
        return AuditLog::create(array_merge([
            'event_type' => 'login',
            'description' => 'Login activity.',
            'user_id' => null,
            'auditable_type' => null,
            'auditable_id' => null,
            'ip_address' => null,
            'metadata' => [],
            'created_at' => now()], $overrides));
    }

    /**
     * Seed the org hierarchy: School Year -> Semester -> GradeLevel -> Section ->
     * Classroom, plus admin/teacher/student users, classroom
     * and student enrollments.
     *
     * @return array{admin: User, teacher: User, students: User[], semester: Semester, grade_level: GradeLevel, section: Section, subject: Subject, classroom: Classroom}
     */
    private function seedOrg(array $overrides = []): array
    {
        $admin = $this->makeUser('admin', []);
        $teacher = $this->makeUser('teacher', []);
        $studentA = $this->makeUser('student', []);
        $studentB = $this->makeUser('student', []);

        $schoolYear = SchoolYear::create(['name' => 'SY 2025-2026']);

        $semester = Semester::create(['semester' => '1', 'school_year_id' => $schoolYear->id,
            'name' => $overrides['semester_name'] ?? 'Semester 1',
            'start_date' => $overrides['start_date'] ?? '2026-01-05',
            'end_date' => $overrides['end_date'] ?? '2026-06-30']);

        $gradeLevel = GradeLevel::create(['semester_id' => $semester->id, 'grade_level' => '7']);
        $section = Section::create(['grade_level_id' => $gradeLevel->id, 'name' => '7A']);
$this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH']);


        $classroom = app(ClassroomService::class)->createClassroom($teacher->id, $this->subject->id, $section->id, '2026-2027', null);

        ClassroomEnrollment::create([
            'classroom_id' => $classroom->id,
            'student_id' => $studentA->id,
            'joined_at' => now()]);
        ClassroomEnrollment::create([
            'classroom_id' => $classroom->id,
            'student_id' => $studentB->id,
            'joined_at' => now()]);

        return [
            'admin' => $admin,
            'teacher' => $teacher,
            'students' => [$studentA, $studentB],
            'semester' => $semester,
            'grade_level' => $gradeLevel,
            'section' => $section,
            'subject' => $this->subject,
            'classroom' => $classroom];
    }

    private function createAssignment(int $teacherId, int $subjectId, int $semesterId, int $classroomId, string $title, string $dueDate = '2026-02-01 23:59:59'): Assignment
    {
        return Assignment::create([
            'teacher_id' => $teacherId,
            'classroom_id' => $classroomId,
            'subject_id' => $subjectId,
            'semester_id' => $semesterId,
            'title' => $title,
            'description' => 'Assignment description.',
            'due_date' => $dueDate]);
    }

    /**
     * Create a submission with the given file names, writing each file to the
     * local disk under the real submission-files path pattern. Returns the
     * submission plus the stored paths.
     *
     * @param  string[]  $names
     * @return array{submission: AssignmentSubmission, paths: string[]}
     */
    private function createSubmissionWithFiles(int $assignmentId, int $studentId, array $names): array
    {
        $submission = AssignmentSubmission::create([
            'assignment_id' => $assignmentId,
            'student_id' => $studentId,
            'submitted_at' => '2026-02-02 10:00:00',
            'status' => 'on_time']);

        $paths = [];
        foreach ($names as $name) {
            $path = 'assignments/submissions/' . $submission->id . '/' . $name;
            Storage::disk('local')->put($path, 'submission file bytes: ' . $name);
            SubmissionFile::create([
                'submission_id' => $submission->id,
                'filename' => $path,
                'original_filename' => $name,
                'mime_type' => 'application/pdf',
                'file_size' => 100]);
            $paths[] = $path;
        }

        return ['submission' => $submission, 'paths' => $paths];
    }

    private function assertBusinessRuleConflict(\Closure $callback, string $code, int $status = 409): void
    {
        try {
            $callback();

            $this->fail('Expected BusinessRuleConflictException with code ' . $code);
        } catch (BusinessRuleConflictException $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    private function auditLogService(): AuditLogService
    {
        return app(AuditLogService::class);
    }

    private function actAs(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    // ---------------------------------------------------------------------
    // A. Audit log retention & anonymization (ARCH-002 QA-006, ARCH-002 QA-006)
    // ---------------------------------------------------------------------

    public function test_anonymize_masks_old_ipv4_ipv6_and_mapped_addresses(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 2, 12, 0, 0));

        $oldIpv4 = $this->createAuditRow(['ip_address' => '192.168.1.10', 'created_at' => now()->subDays(91)]);
        $oldIpv6 = $this->createAuditRow(['ip_address' => '2001:db8::1', 'created_at' => now()->subDays(100)]);
        $oldMapped = $this->createAuditRow(['ip_address' => '::ffff:1.2.3.4', 'created_at' => now()->subDays(92)]);
        $fresh = $this->createAuditRow(['ip_address' => '10.0.0.5', 'created_at' => now()]);
        $nullIp = $this->createAuditRow(['ip_address' => null, 'created_at' => now()->subDays(91)]);

        $updated = $this->auditLogService()->anonymizeIpAddresses();

        $this->assertSame(3, $updated);
        $this->assertSame('192.168.1.0', $oldIpv4->fresh()->ip_address);
        $this->assertSame('2001:db8::', $oldIpv6->fresh()->ip_address);
        $this->assertSame('::', $oldMapped->fresh()->ip_address);
        $this->assertSame('10.0.0.5', $fresh->fresh()->ip_address);
        $this->assertNull($nullIp->fresh()->ip_address);
    }

    public function test_anonymize_is_idempotent(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 2, 12, 0, 0));

        $row = $this->createAuditRow(['ip_address' => '192.168.1.10', 'created_at' => now()->subDays(91)]);

        $this->assertSame(1, $this->auditLogService()->anonymizeIpAddresses());
        $this->assertSame('192.168.1.0', $row->fresh()->ip_address);

        $this->assertSame(0, $this->auditLogService()->anonymizeIpAddresses());
        $this->assertSame('192.168.1.0', $row->fresh()->ip_address);
    }

    public function test_purge_old_logs_deletes_only_expired_rows(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 2, 12, 0, 0));

        $recent = $this->createAuditRow(['created_at' => now()->subDays(10)]);
        $expired = $this->createAuditRow(['created_at' => now()->subDays(400)]);

        $deleted = $this->auditLogService()->purgeOldLogs();

        $this->assertSame(1, $deleted);
        $this->assertDatabaseHas('audit_logs', ['id' => $recent->id]);
        $this->assertDatabaseMissing('audit_logs', ['id' => $expired->id]);

        $this->assertSame(1, $this->auditLogService()->purgeOldLogs(0));
        $this->assertDatabaseMissing('audit_logs', ['id' => $recent->id]);
    }

    public function test_anonymize_respects_configured_days_override(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 2, 12, 0, 0));

        $old = $this->createAuditRow(['ip_address' => '10.1.1.5', 'created_at' => now()->subDays(91)]);
        $fresh = $this->createAuditRow(['ip_address' => '172.16.0.9', 'created_at' => now()]);

        config(['audit.ip_anonymization_days' => 0]);

        $updated = $this->auditLogService()->anonymizeIpAddresses((int) config('audit.ip_anonymization_days'));

        $this->assertSame(2, $updated);
        $this->assertSame('10.1.1.0', $old->fresh()->ip_address);
        $this->assertSame('172.16.0.0', $fresh->fresh()->ip_address);

        $freshTwo = $this->createAuditRow(['ip_address' => '172.16.0.9', 'created_at' => now()]);
        // Honor-config: restore the default window before the no-arg call so
        // it leaves the fresh row untouched.
        config(['audit.ip_anonymization_days' => 90]);
        $this->assertSame(0, $this->auditLogService()->anonymizeIpAddresses());
        $this->assertSame('172.16.0.9', $freshTwo->fresh()->ip_address);
    }

    public function test_audit_config_matches_service_defaults(): void
    {
        $this->assertSame(90, config('audit.ip_anonymization_days'));
        $this->assertSame(365, config('audit.retention_days'));
        $this->assertSame(90, AuditLogService::IP_ANONYMIZATION_DAYS);
        $this->assertSame(365, AuditLogService::DEFAULT_RETENTION_DAYS);
    }

    // ---------------------------------------------------------------------
    // B. Closed-term purge (ARCH-002 QA-010, ARCH-002 QA-010, ARCH-002 QA-006)
    // ---------------------------------------------------------------------

    public function test_purge_deletes_submissions_and_files_but_keeps_assignments(): void
    {
        Storage::fake('local');
        $org = $this->seedOrg();
        $admin = $org['admin'];
        $semester = $org['semester'];

        $assignmentWithSubmissions = $this->createAssignment($org['teacher']->id, $org['subject']->id, $semester->id, $org['classroom']->id, 'Assignment A');
        $assignmentWithout = $this->createAssignment($org['teacher']->id, $org['subject']->id, $semester->id, $org['classroom']->id, 'Assignment B');

        $attachmentPath = 'assignments/attachments/' . $assignmentWithSubmissions->id . '/lesson.pdf';
        Storage::disk('local')->put($attachmentPath, 'teacher attachment bytes');
        AssignmentAttachment::create([
            'assignment_id' => $assignmentWithSubmissions->id,
            'filename' => $attachmentPath,
            'original_filename' => 'lesson.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 50]);

        $subA = $this->createSubmissionWithFiles($assignmentWithSubmissions->id, $org['students'][0]->id, ['answer-a.pdf', 'extra-a.pdf']);
        $subB = $this->createSubmissionWithFiles($assignmentWithSubmissions->id, $org['students'][1]->id, ['answer-b.pdf', 'extra-b.pdf']);

        AssignmentFeedback::create([
            'submission_id' => $subA['submission']->id,
            'teacher_id' => $org['teacher']->id,
            'feedback_text' => 'Well done.']);

        $response = $this->actAs($admin)->postJson('/api/admin/semesters/' . $semester->id . '/purge');

        $response->assertOk()
            ->assertJsonPath('data.semester_id', $semester->id)
            ->assertJsonPath('data.assignments_purged', 1)
            ->assertJsonPath('data.submissions_purged', 2)
            ->assertJsonPath('data.files_purged', 4);

        $this->assertSame(0, SubmissionFile::count());
        $this->assertSame(0, AssignmentFeedback::count());
        $this->assertSame(0, AssignmentSubmission::count());
        $this->assertDatabaseHas('assignments', ['id' => $assignmentWithSubmissions->id]);
        $this->assertDatabaseHas('assignments', ['id' => $assignmentWithout->id]);

        foreach (array_merge($subA['paths'], $subB['paths']) as $path) {
            Storage::disk('local')->assertMissing($path);
        }
        Storage::disk('local')->assertExists($attachmentPath);

        $audit = AuditLog::where('event_type', 'purge_semesters')
            ->where('auditable_type', Semester::class)
            ->where('auditable_id', $semester->id)
            ->firstOrFail();
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame('Semester-closure purge (semester ' . $semester->id . ')', $audit->description);
        $this->assertSame(1, $audit->metadata['assignments_purged']);
        $this->assertSame(2, $audit->metadata['submissions_purged']);
        $this->assertSame(4, $audit->metadata['files_purged']);
    }

    public function test_purge_returns_zero_counts_for_term_without_submissions(): void
    {
        Storage::fake('local');
        $org = $this->seedOrg();
        $semester = $org['semester'];

        $this->createAssignment($org['teacher']->id, $org['subject']->id, $semester->id, $org['classroom']->id, 'Assignment A');
        $this->createAssignment($org['teacher']->id, $org['subject']->id, $semester->id, $org['classroom']->id, 'Assignment B');

        $response = $this->actAs($org['admin'])->postJson('/api/admin/semesters/' . $semester->id . '/purge');

        $response->assertOk()
            ->assertJsonPath('data.assignments_purged', 0)
            ->assertJsonPath('data.submissions_purged', 0)
            ->assertJsonPath('data.files_purged', 0);

        $this->assertSame(2, Assignment::count());
        $this->assertSame(1, AuditLog::where('event_type', 'purge_semesters')->count());
    }

    public function test_purge_is_idempotent(): void
    {
        Storage::fake('local');
        $org = $this->seedOrg();
        $semester = $org['semester'];

        $assignment = $this->createAssignment($org['teacher']->id, $org['subject']->id, $semester->id, $org['classroom']->id, 'Assignment A');
        $sub = $this->createSubmissionWithFiles($assignment->id, $org['students'][0]->id, ['answer-a.pdf']);

        $first = $this->actAs($org['admin'])->postJson('/api/admin/semesters/' . $semester->id . '/purge');
        $first->assertOk()->assertJsonPath('data.files_purged', 1);

        $second = $this->actAs($org['admin'])->postJson('/api/admin/semesters/' . $semester->id . '/purge');
        $second->assertOk()
            ->assertJsonPath('data.assignments_purged', 0)
            ->assertJsonPath('data.submissions_purged', 0)
            ->assertJsonPath('data.files_purged', 0);

        Storage::disk('local')->assertMissing($sub['paths'][0]);
        $this->assertDatabaseHas('assignments', ['id' => $assignment->id]);
        $this->assertSame(2, AuditLog::where('event_type', 'purge_semesters')->count());
    }

    public function test_purge_requires_closed_term(): void
    {
        Storage::fake('local');
        $org = $this->seedOrg(['end_date' => '2026-12-31']);
        $semester = $org['semester'];

        $assignment = $this->createAssignment($org['teacher']->id, $org['subject']->id, $semester->id, $org['classroom']->id, 'Assignment A');
        $sub = $this->createSubmissionWithFiles($assignment->id, $org['students'][0]->id, ['answer-a.pdf']);

        $response = $this->actAs($org['admin'])->postJson('/api/admin/semesters/' . $semester->id . '/purge');

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'SEMESTER_NOT_CLOSED');

        $this->assertSame(1, AssignmentSubmission::count());
        $this->assertSame(1, SubmissionFile::count());
        Storage::disk('local')->assertExists($sub['paths'][0]);
        $this->assertSame(0, AuditLog::where('event_type', 'purge_semesters')->count());
    }

    public function test_purge_returns_404_for_missing_term(): void
    {
        $response = $this->actAs($this->makeUser('admin'))->postJson('/api/admin/semesters/999999/purge');

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_purge_requires_authenticated_admin(): void
    {
        $org = $this->seedOrg();

        $this->postJson('/api/admin/semesters/' . $org['semester']->id . '/purge')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $pendingAdmin = $this->makeUser('admin', ['must_change_password' => true]);
        $this->actAs($pendingAdmin)
            ->postJson('/api/admin/semesters/' . $org['semester']->id . '/purge')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PASSWORD_CHANGE_REQUIRED');

        $this->actAs($org['teacher'])
            ->postJson('/api/admin/semesters/' . $org['semester']->id . '/purge')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->actAs($org['students'][0])
            ->postJson('/api/admin/semesters/' . $org['semester']->id . '/purge')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_purge_preserves_unrelated_data(): void
    {
        Storage::fake('local');
        $org = $this->seedOrg();
        $admin = $org['admin'];
        $teacher = $org['teacher'];
        $student = $org['students'][0];
        $semester = $org['semester'];

        $assignment = $this->createAssignment($org['teacher']->id, $org['subject']->id, $semester->id, $org['classroom']->id, 'Assignment A');
        $this->createSubmissionWithFiles($assignment->id, $org['students'][1]->id, ['answer-b.pdf']);

        $competency = CompetencyReference::create(['semester' => '1', 'subject_id' => $org['subject']->id,
            'code' => 'M7LC-Ia-1',
            'descriptor' => 'Comprehend texts.',
            'grade_level' => '7']);

        $assessment = Assessment::create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $org['classroom']->id,
            'subject_id' => $org['subject']->id,
            'semester_id' => $semester->id,
            'title' => 'Unit Quiz',
            'type' => 'Recorded',
            'status' => 'draft']);

        $item = AssessmentItem::create([
            'assessment_id' => $assessment->id,
            'item_type' => 'essay',
            'prompt' => 'Explain.',
            'max_points' => 10,
            'competency_tag_id' => $competency->id,
            'sort_order' => 1]);

        $attempt = AssessmentAttempt::create([
            'assessment_id' => $assessment->id,
            'student_id' => $student->id,
            'attempt_number' => 1,
            'status' => 'in_progress']);

        $assessmentSubmission = AssessmentSubmission::create([
            'attempt_id' => $attempt->id,
            'assessment_id' => $assessment->id,
            'student_id' => $student->id,
            'section_id' => $org['section']->id,
            'semester_id' => $semester->id,
            'submitted_at' => '2026-02-10 08:00:00',
            'status' => 'pending_grading']);

        AssessmentResponse::create([
            'submission_id' => $assessmentSubmission->id,
            'item_id' => $item->id,
            'response_text' => 'Because.',
            'score' => 8,
            'is_correct' => true]);

        MasteryRecord::create([
            'student_id' => $student->id,
            'classroom_id' => $org['classroom']->id,
            'subject_id' => $org['subject']->id,
            'assessment_id' => $assessment->id,
            'assessment_submission_id' => $assessmentSubmission->id,
            'competency_id' => $competency->id,
            'mastery_percent' => 95.5,
            'mastery_status' => 'Mastered']);

        AIExplanation::create([
            'student_id' => $student->id,
            'assessment_id' => $assessment->id,
            'assessment_submission_id' => $assessmentSubmission->id,
            'item_id' => $item->id,
            'explanation_text' => 'Your response covered the key idea.']);

        $materialPath = 'learning_materials/material.pdf';
        Storage::disk('local')->put($materialPath, 'learning material bytes');
        LearningMaterial::create([
            'teacher_id' => $teacher->id,
            'subject_id' => $org['subject']->id,
            'competency_id' => $competency->id,
            'title' => 'Handout 1',
            'filename' => $materialPath,
            'original_filename' => 'material.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 50]);

        $announcement = Announcement::create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $org['classroom']->id,
            'subject_id' => $org['subject']->id,
            'title' => 'Reminder',
            'body' => 'Submit on time.']);
        $announcementAttachmentPath = 'announcements/' . $announcement->id . '/notice.pdf';
        Storage::disk('local')->put($announcementAttachmentPath, 'announcement file bytes');
        AnnouncementAttachment::create([
            'announcement_id' => $announcement->id,
            'filename' => $announcementAttachmentPath,
            'original_filename' => 'notice.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 50]);

        $itemAttachmentPath = 'assessment_item_attachments/' . $item->id . '/figure.pdf';
        Storage::disk('local')->put($itemAttachmentPath, 'item attachment bytes');
        AssessmentItemAttachment::create([
            'assessment_item_id' => $item->id,
            'filename' => $itemAttachmentPath,
            'original_filename' => 'figure.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 50]);

        $counts = [
            Assessment::class,
            AssessmentItem::class,
            AssessmentSubmission::class,
            AssessmentResponse::class,
            MasteryRecord::class,
            AIExplanation::class,
            LearningMaterial::class,
            Announcement::class,
            AnnouncementAttachment::class,
            AssessmentItemAttachment::class];
        $before = [];
        foreach ($counts as $modelClass) {
            $before[$modelClass] = $modelClass::count();
        }

        $this->actAs($admin)->postJson('/api/admin/semesters/' . $semester->id . '/purge')->assertOk();

        foreach ($counts as $modelClass) {
            $this->assertSame($before[$modelClass], $modelClass::count(), $modelClass . ' rows changed');
        }
        Storage::disk('local')->assertExists($materialPath);
        Storage::disk('local')->assertExists($announcementAttachmentPath);
        Storage::disk('local')->assertExists($itemAttachmentPath);
        Storage::disk('local')->assertMissing('assignments/submissions/1/answer-b.pdf');
    }

    // ---------------------------------------------------------------------
    // C. Login rate limiting (ARCH-002 QA-004, ARCH-002 QA-004)
    // ---------------------------------------------------------------------

    public function test_sixth_failed_login_is_rate_limited_with_headers(): void
    {
        // CompAss-ID login: hammer the account's real school_id (STU-XXXX-XXXXX).
        $identifier = $this->makeUser('student', [])->school_id;

        for ($i = 1; $i <= 5; $i++) {
            $this->post('/api/auth/login', [
                'identifier' => $identifier,
                'password' => 'wrong-password'])->assertStatus(401);
        }

        $response = $this->post('/api/auth/login', [
            'identifier' => $identifier,
            'password' => 'wrong-password']);

        $response->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED')
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $reset = $response->headers->get('X-RateLimit-Reset');
        $this->assertNotNull($reset);
        $this->assertIsNumeric($reset);
        $this->assertGreaterThan(0, (int) $reset);
    }

    public function test_rate_limit_remaining_decrements_and_block_is_audited(): void
    {
        $identifier = $this->makeUser('student', [])->school_id;

        $expected = ['4', '3', '2', '1', '0'];
        foreach ($expected as $remaining) {
            $this->post('/api/auth/login', [
                'identifier' => $identifier,
                'password' => 'wrong-password'])->assertStatus(401)
                ->assertHeader('X-RateLimit-Remaining', $remaining);
        }

        $this->post('/api/auth/login', [
            'identifier' => $identifier,
            'password' => 'wrong-password'])->assertStatus(429);

        $audit = AuditLog::where('event_type', 'login')
            ->where('metadata->identifier_masked', AuditLogService::maskIdentifier($identifier))
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertNull($audit->user_id);
        // F-08: raw identifier/IP must not persist in the year-retained
        // metadata — masked form only, while fact/time/outcome are kept.
        $this->assertArrayNotHasKey('identifier', $audit->metadata);
        $this->assertArrayNotHasKey('email', $audit->metadata);
        $this->assertArrayNotHasKey('school_id', $audit->metadata);
        $this->assertArrayNotHasKey('ip', $audit->metadata);
        $this->assertArrayNotHasKey('ip_address', $audit->metadata);
        $this->assertSame(AuditLogService::maskIdentifier($identifier), $audit->metadata['identifier_masked']);
        $this->assertNotSame($identifier, $audit->metadata['identifier_masked']);
        $this->assertSame(5, $audit->metadata['attempts']);
        $this->assertSame('login', $audit->event_type);
        $this->assertNotNull($audit->created_at);
    }

    public function test_validation_errors_do_not_consume_rate_limit_attempts(): void
    {
        $identifier = $this->makeUser('student', [])->school_id;

        for ($i = 0; $i < 5; $i++) {
            $this->post('/api/auth/login', [
                'identifier' => $identifier])->assertStatus(422)
                ->assertJsonPath('error.code', 'VALIDATION_ERROR');
        }

        // Case-sensitive bucket (trim only, no upper-casing) matches AuthService.
        $key = 'login:' . sha1('127.0.0.1|' . trim($identifier));
        $this->assertSame(0, RateLimiter::attempts($key));

        for ($i = 0; $i < 5; $i++) {
            $this->post('/api/auth/login', [
                'identifier' => $identifier,
                'password' => 'wrong-password'])->assertStatus(401);
        }

        $this->assertSame(5, RateLimiter::attempts($key));
        $this->post('/api/auth/login', [
            'identifier' => $identifier,
            'password' => 'wrong-password'])->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED');
    }

    public function test_rate_limit_bucket_is_case_sensitive_per_variant(): void
    {
        // CompAss-ID login (STU-XXXX-XXXXX), case-sensitive bucket (trim
        // only, no upper-casing) matching AuthService: hammering a lower-case
        // variant must not lock the victim's exact ID (no cross-account
        // DoS), while spacing variants share the exact-ID bucket via trim.
        $victim = $this->makeUser('student', []);
        $exactId = $victim->school_id;
        $this->assertMatchesRegularExpression('/^STU-[0-9]{4}-[0-9]{5}$/', $exactId);
        $lowerVariant = strtolower($exactId);
        $this->assertNotSame($exactId, $lowerVariant);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/api/auth/login', [
                'identifier' => $lowerVariant,
                'password' => 'wrong-password'])->assertStatus(401);
        }
        $this->post('/api/auth/login', [
            'identifier' => $lowerVariant,
            'password' => 'wrong-password'])->assertStatus(429);

        // The victim's exact ID still has its full budget.
        $this->post('/api/auth/login', [
            'identifier' => '  ' . $exactId . '  ',
            'password' => 'wrong-password'])->assertStatus(401)
            ->assertHeader('X-RateLimit-Remaining', '4');
    }

    public function test_successful_login_resets_rate_limit(): void
    {
        // Phase A: school_id-only login (CompAss ID, trimmed case-sensitive).
        $user = $this->makeUser('student', []);
        $schoolId = $user->school_id;

        $this->post('/api/auth/login', [
            'identifier' => $schoolId,
            'password' => 'wrong-password'])->assertStatus(401);

        $this->post('/api/auth/login', [
            'identifier' => $schoolId,
            'password' => 'password'])->assertOk()
            ->assertJsonPath('data.role', 'Student');

        $key = 'login:' . sha1('127.0.0.1|' . trim($schoolId));
        $this->assertSame(0, RateLimiter::attempts($key));

        foreach ($expected = ['4', '3', '2', '1', '0'] as $remaining) {
            $this->post('/api/auth/login', [
                'identifier' => $schoolId,
                'password' => 'wrong-password'])->assertStatus(401)
                ->assertHeader('X-RateLimit-Remaining', $remaining);
        }

        $this->post('/api/auth/login', [
            'identifier' => $schoolId,
            'password' => 'wrong-password'])->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED');
    }

    // ---------------------------------------------------------------------
    // D. Upload whitelist (ARCH-002 FR-020, ARCH-002 FR-027, ARCH-002 QA-009)
    // ---------------------------------------------------------------------

    public function test_announcement_with_docx_attachment_creates(): void
    {
        Storage::fake('local');
        $org = $this->seedOrg();
        $teacher = $org['teacher'];

        $response = $this->actAs($teacher)->post('/api/teacher/classrooms/' . $org['classroom']->id . '/announcements', [
            'title' => 'Hello',
            'body' => 'Welcome to class.',
            'attachments' => [
                UploadedFile::fake()->create('note.docx', 100, self::DOCX_MIME)]]);

        $response->assertStatus(201)
            ->assertJsonPath('data.title', 'Hello')
            ->assertJsonPath('data.has_attachments', true);

        $this->assertDatabaseHas('announcement_attachments', [
            'original_filename' => 'note.docx',
            'mime_type' => self::DOCX_MIME]);
    }

    public function test_announcement_rejects_six_attachments(): void
    {
        $org = $this->seedOrg();
        $teacher = $org['teacher'];

        $attachments = [];
        for ($i = 0; $i < 6; $i++) {
            $attachments[] = UploadedFile::fake()->create('file-' . $i . '.pdf', 10, 'application/pdf');
        }

        $this->assertBusinessRuleConflict(function () use ($teacher, $org, $attachments): void {
            app(AnnouncementService::class)->createAnnouncementInClassroom(
                $teacher->id,
                $org['classroom']->id,
                'Hello',
                'Body',
                $attachments
            );
        }, 'TOO_MANY_ATTACHMENTS');
    }

    public function test_announcement_rejects_oversized_attachment(): void
    {
        $org = $this->seedOrg();
        $teacher = $org['teacher'];

        $oversized = UploadedFile::fake()->createWithContent(
            'big.pdf',
            str_repeat('x', (15 * 1024 * 1024) + 1)
        );

        $this->assertBusinessRuleConflict(function () use ($teacher, $org, $oversized): void {
            app(AnnouncementService::class)->createAnnouncementInClassroom(
                $teacher->id,
                $org['classroom']->id,
                'Hello',
                'Body',
                [$oversized]
            );
        }, 'FILE_TOO_LARGE');
    }

    public function test_announcement_rejects_disallowed_extension(): void
    {
        $org = $this->seedOrg();
        $teacher = $org['teacher'];

        $exe = UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload');

        $this->assertBusinessRuleConflict(function () use ($teacher, $org, $exe): void {
            app(AnnouncementService::class)->createAnnouncementInClassroom(
                $teacher->id,
                $org['classroom']->id,
                'Hello',
                'Body',
                [$exe]
            );
        }, 'INVALID_FILE_TYPE', 422);
    }

    public function test_assignment_with_docx_attachment_creates(): void
    {
        Storage::fake('local');
        $org = $this->seedOrg();
        $teacher = $org['teacher'];

        $response = $this->actAs($teacher)->post('/api/teacher/classrooms/' . $org['classroom']->id . '/assignments', [
            'title' => 'Homework 1',
            'description' => 'Read pages 1-10.',
            'due_date' => '2026-02-15 23:59:59',
            'attachments' => [
                UploadedFile::fake()->create('worksheet.docx', 100, self::DOCX_MIME)]]);

        $response->assertStatus(201)
            ->assertJsonPath('data.title', 'Homework 1');

        $this->assertDatabaseHas('assignment_attachments', [
            'original_filename' => 'worksheet.docx',
            'mime_type' => self::DOCX_MIME]);
    }

    public function test_assignment_rejects_disallowed_extension_at_http(): void
    {
        Storage::fake('local');
        $org = $this->seedOrg();
        $teacher = $org['teacher'];

        $this->actAs($teacher)
            ->post('/api/teacher/classrooms/' . $org['classroom']->id . '/assignments', [
                'title' => 'Homework 1',
                'description' => 'Read pages 1-10.',
                'due_date' => '2026-02-15 23:59:59',
                'attachments' => [
                    UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertSame(0, Assignment::count());
    }

    public function test_student_submits_pdf_successfully(): void
    {
        Storage::fake('local');
        $org = $this->seedOrg();
        $assignment = $this->createAssignment($org['teacher']->id, $org['subject']->id, $org['semester']->id, $org['classroom']->id, 'Assignment A');
        $student = $org['students'][0];

        $response = $this->actAs($student)->post('/api/student/assignments/' . $assignment->id . '/submit', [
            'files' => [
                UploadedFile::fake()->create('answer.pdf', 100, 'application/pdf')]]);

        $response->assertStatus(201)
            ->assertJsonPath('data.file_count', 1);

        $this->assertDatabaseHas('submission_files', [
            'original_filename' => 'answer.pdf',
            'mime_type' => 'application/pdf']);
    }

    public function test_student_submission_rejects_disallowed_extension(): void
    {
        Storage::fake('local');
        $org = $this->seedOrg();
        $assignment = $this->createAssignment($org['teacher']->id, $org['subject']->id, $org['semester']->id, $org['classroom']->id, 'Assignment A');
        $student = $org['students'][0];

        $txt = UploadedFile::fake()->create('notes.txt', 10, 'text/plain');

        $this->assertBusinessRuleConflict(function () use ($student, $assignment, $txt): void {
            app(AssignmentService::class)->submitAssignment($student->id, $assignment->id, [$txt]);
        }, 'INVALID_FILE_TYPE', 422);

        $this->assertSame(0, AssignmentSubmission::count());
    }

    public function test_learning_material_with_docx_creates(): void
    {
        Storage::fake('local');
        $org = $this->seedOrg();
        $teacher = $org['teacher'];
        $competency = CompetencyReference::create(['semester' => '1', 'subject_id' => $org['subject']->id,
            'code' => 'M7AL-Ia-1',
            'descriptor' => 'Algebra basics.',
            'grade_level' => '7']);

        $response = $this->actAs($teacher)->post('/api/teacher/learning-materials', [
            'subject_id' => $org['subject']->id,
            'competency_id' => $competency->id,
            'title' => 'Notes',
            'file' => UploadedFile::fake()->create('notes.docx', 100, self::DOCX_MIME)]);

        $response->assertStatus(201)
            ->assertJsonPath('data.mime_type', self::DOCX_MIME);

        $this->assertDatabaseHas('learning_materials', [
            'original_filename' => 'Notes',
            'mime_type' => self::DOCX_MIME]);
    }

    public function test_learning_material_rejects_oversized_file(): void
    {
        $org = $this->seedOrg();
        $teacher = $org['teacher'];
        $competency = CompetencyReference::create(['semester' => '1', 'subject_id' => $org['subject']->id,
            'code' => 'M7AL-Ia-1',
            'descriptor' => 'Algebra basics.',
            'grade_level' => '7']);

        $oversized = UploadedFile::fake()->createWithContent(
            'big.pdf',
            str_repeat('x', (15 * 1024 * 1024) + 1)
        );

        $this->assertBusinessRuleConflict(function () use ($teacher, $org, $competency, $oversized): void {
            app(LearningMaterialService::class)->storeLearningMaterial(
                $teacher->id,
                $org['subject']->id,
                $competency->id,
                'Notes',
                $oversized
            );
        }, 'FILE_TOO_LARGE');
    }

    public function test_learning_material_rejects_disallowed_extension(): void
    {
        $org = $this->seedOrg();
        $teacher = $org['teacher'];
        $competency = CompetencyReference::create(['semester' => '1', 'subject_id' => $org['subject']->id,
            'code' => 'M7AL-Ia-1',
            'descriptor' => 'Algebra basics.',
            'grade_level' => '7']);

        $txt = UploadedFile::fake()->create('notes.txt', 10, 'text/plain');

        $this->assertBusinessRuleConflict(function () use ($teacher, $org, $competency, $txt): void {
            app(LearningMaterialService::class)->storeLearningMaterial(
                $teacher->id,
                $org['subject']->id,
                $competency->id,
                'Notes',
                $txt
            );
        }, 'INVALID_FILE_TYPE', 422);
    }

    public function test_assessment_item_with_docx_attachment_creates(): void
    {
        Storage::fake('local');
        $org = $this->seedOrg();
        $teacher = $org['teacher'];
        $competency = CompetencyReference::create(['semester' => '1', 'subject_id' => $org['subject']->id,
            'code' => 'M7LC-Ia-1',
            'descriptor' => 'Reading comprehension.',
            'grade_level' => '7']);
        $assessment = Assessment::create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $org['classroom']->id,
            'subject_id' => $org['subject']->id,
            'semester_id' => $org['semester']->id,
            'title' => 'Quiz',
            'type' => 'Recorded',
            'status' => 'draft']);

        $response = $this->actAs($teacher)->post('/api/teacher/assessments/' . $assessment->id . '/items', [
            'item_type' => 'essay',
            'prompt' => 'Explain the theme.',
            'max_points' => 10,
            'competency_tag_id' => $competency->id,
            'sort_order' => 1,
            'attachments' => [
                UploadedFile::fake()->create('reading.docx', 100, self::DOCX_MIME)]]);

        $response->assertStatus(201)
            ->assertJsonPath('data.has_attachments', true);

        $this->assertDatabaseHas('assessment_item_attachments', [
            'original_filename' => 'reading.docx',
            'mime_type' => self::DOCX_MIME]);
    }

    public function test_assessment_item_rejects_oversized_attachment(): void
    {
        $org = $this->seedOrg();
        $teacher = $org['teacher'];
        $competency = CompetencyReference::create(['semester' => '1', 'subject_id' => $org['subject']->id,
            'code' => 'M7LC-Ia-1',
            'descriptor' => 'Reading comprehension.',
            'grade_level' => '7']);
        $assessment = Assessment::create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $org['classroom']->id,
            'subject_id' => $org['subject']->id,
            'semester_id' => $org['semester']->id,
            'title' => 'Quiz',
            'type' => 'Recorded',
            'status' => 'draft']);

        $oversized = UploadedFile::fake()->createWithContent(
            'big.pdf',
            str_repeat('x', (15 * 1024 * 1024) + 1)
        );

        $this->assertBusinessRuleConflict(function () use ($teacher, $assessment, $competency, $oversized): void {
            app(AssessmentService::class)->addItem(
                $teacher->id,
                $assessment->id,
                'essay',
                'Explain.',
                10,
                null,
                $competency->id,
                [$oversized]
            );
        }, 'FILE_TOO_LARGE');
    }

    public function test_assessment_item_rejects_disallowed_extension(): void
    {
        $org = $this->seedOrg();
        $teacher = $org['teacher'];
        $competency = CompetencyReference::create(['semester' => '1', 'subject_id' => $org['subject']->id,
            'code' => 'M7LC-Ia-1',
            'descriptor' => 'Reading comprehension.',
            'grade_level' => '7']);
        $assessment = Assessment::create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $org['classroom']->id,
            'subject_id' => $org['subject']->id,
            'semester_id' => $org['semester']->id,
            'title' => 'Quiz',
            'type' => 'Recorded',
            'status' => 'draft']);

        $txt = UploadedFile::fake()->create('notes.txt', 10, 'text/plain');

        $this->assertBusinessRuleConflict(function () use ($teacher, $assessment, $competency, $txt): void {
            app(AssessmentService::class)->addItem(
                $teacher->id,
                $assessment->id,
                'essay',
                'Explain.',
                10,
                null,
                $competency->id,
                [$txt]
            );
        }, 'INVALID_FILE_TYPE', 422);
    }

    public function test_renamed_extension_files_are_rejected(): void
    {
        $org = $this->seedOrg();
        $teacher = $org['teacher'];
        $competency = CompetencyReference::create(['semester' => '1', 'subject_id' => $org['subject']->id,
            'code' => 'M7AL-Ia-1',
            'descriptor' => 'Algebra basics.',
            'grade_level' => '7']);

        $sneaky = UploadedFile::fake()->create('notes.pdf', 10, 'text/plain');

        $this->assertBusinessRuleConflict(function () use ($teacher, $org, $sneaky): void {
            app(AnnouncementService::class)->createAnnouncementInClassroom(
                $teacher->id,
                $org['classroom']->id,
                'Hello',
                'Body',
                [$sneaky]
            );
        }, 'INVALID_FILE_TYPE', 422);

        $this->assertBusinessRuleConflict(function () use ($teacher, $org, $competency, $sneaky): void {
            app(LearningMaterialService::class)->storeLearningMaterial(
                $teacher->id,
                $org['subject']->id,
                $competency->id,
                'Notes',
                $sneaky
            );
        }, 'INVALID_FILE_TYPE', 422);
    }
}
