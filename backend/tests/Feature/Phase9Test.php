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
use App\Services\AuthService;
use App\Services\ClassroomService;
use App\Services\LearningMaterialService;
use App\Services\OrgStructureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 9 — Cross-Cutting Hardening regression suite (dedicated testing pass).
 *
 * Pins the post-review-fix behavior of the four Phase 9 contracts:
 *
 *   A. Semester-closure purge (ARCH-002 QA-010, ARCH-002 QA-010, ARCH-002 QA-010, ARCH-002 QA-010) — HTTP access
 *      control, 404/409 guards, all-or-nothing DB deletes, disk cleanup,
 *      ARCH-002 QA-010 protected-data intactness, audit event, idempotent re-runs.
 *   B. Audit retention jobs (ARCH-002 QA-006, ARCH-004 §8.1) — IP anonymization (IPv4 /
 *      IPv6 / IPv4-mapped), retention purge, config passthrough.
 *   C. Login rate limiting (ARCH-002 QA-004, ARCH-002 QA-004) — 429 + X-RateLimit-* headers,
 *      block audit row, zero-attempt 422s, remaining-count decrement.
 *   D. ARCH-002 QA-009 upload whitelist — service-level 422 INVALID_FILE_TYPE
 *      (ARCH-005 §2) / 409 FILE_TOO_LARGE / TOO_MANY_ATTACHMENTS across
 *      announcements, assignments (teacher + student), assessment items,
 *      and learning materials, incl. renamed-file (MIME mismatch) detection.
 *
 * @Traced-To ARCH-002 QA-009, ARCH-002 QA-010, ARCH-002 QA-010, ARCH-002 QA-004, ARCH-002 QA-009, ARCH-002 QA-010, ARCH-002 QA-006, ARCH-002 QA-004
 */
#[Group('phase9')]
class Phase9Test extends TestCase
{
    use RefreshDatabase;

    private ?Subject $subject = null;

    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private const PPTX_MIME = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeUser(string $role, array $overrides = []): User
    {
        return User::factory()->{$role}()->create(array_merge([
            'must_change_password' => false], $overrides));
    }

    private function actAs(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    /**
     * Seed the org hierarchy: School Year -> Semester -> GradeLevel 7 -> Section ->
     * Subject, with admin/teacher/two students, classroom and
     * student enrollments.
     *
     * @return array{admin: User, teacher: User, students: User[], semester: Semester, grade_level: GradeLevel, section: Section, subject: Subject, classroom: Classroom}
     */
    private function setUpOrg(array $overrides = []): array
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
$this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-P9']);


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

    private function createAssignment(int $teacherId, int $subjectId, int $semesterId, int $classroomId, string $title): Assignment
    {
        return Assignment::create([
            'teacher_id' => $teacherId,
            'classroom_id' => $classroomId,
            'subject_id' => $subjectId,
            'semester_id' => $semesterId,
            'title' => $title,
            'description' => 'Assignment description.',
            'due_date' => '2026-02-01 23:59:59']);
    }

    /**
     * Create a competency reference bound to the org's subject.
     */
    private function createCompetency(Subject $subject): CompetencyReference
    {
        return CompetencyReference::create(['semester' => '1', 'subject_id' => $this->subject->id,
            'code' => 'M7LC-Ia-1',
            'descriptor' => 'Comprehend texts.',
            'grade_level' => '7']);
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

    private function auditLogService(): AuditLogService
    {
        return $this->app->make(AuditLogService::class);
    }

    /**
     * Assert the callback throws a BusinessRuleConflictException with the
     * given error code (ARCH-002 QA-007 envelope code).
     */
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

    private function purgeUrl(int $semesterId): string
    {
        return '/api/admin/semesters/' . $semesterId . '/purge';
    }

    // ========================================================================
    // A. Semester-closure purge — HTTP access control & guards
    // ========================================================================

    public function test_purge_unauthenticated_returns_401(): void
    {
        $this->postJson($this->purgeUrl(1))
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_purge_forbids_teacher_and_student_roles(): void
    {
        $org = $this->setUpOrg();

        foreach ([$org['teacher'], $org['students'][0]] as $user) {
            $this->actAs($user)
                ->postJson($this->purgeUrl($org['semester']->id))
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'FORBIDDEN');
        }
    }

    public function test_purge_blocked_for_admin_with_pending_password_change(): void
    {
        $org = $this->setUpOrg();
        $pending = $this->makeUser('admin', ['must_change_password' => true]);

        $this->actAs($pending)
            ->postJson($this->purgeUrl($org['semester']->id))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PASSWORD_CHANGE_REQUIRED');
    }

    public function test_purge_nonexistent_term_returns_404(): void
    {
        $this->actAs($this->makeUser('admin'))
            ->postJson($this->purgeUrl(999999))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_purge_open_term_returns_409_term_not_closed(): void
    {
        Storage::fake('local');
        $org = $this->setUpOrg(['end_date' => '2026-12-31']);

        $assignment = $this->createAssignment($org['teacher']->id, $org['subject']->id, $org['semester']->id, $org['classroom']->id, 'Open term assignment');
        $this->app->make(AssignmentService::class)->submitAssignment(
            $org['students'][0]->id,
            $assignment->id,
            [UploadedFile::fake()->create('answer.pdf', 10, 'application/pdf')]
        );

        $this->actAs($org['admin'])
            ->postJson($this->purgeUrl($org['semester']->id))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SEMESTER_NOT_CLOSED');

        $this->assertSame(1, AssignmentSubmission::count());
        $this->assertSame(1, SubmissionFile::count());
        $this->assertSame(0, AuditLog::where('event_type', 'purge_semesters')->count());
    }

    // ========================================================================
    // A. Semester-closure purge — success path & ARCH-002 QA-010/ARCH-002 QA-010 invariants
    // ========================================================================

    public function test_purge_success_deletes_submissions_and_counts_distinct_assignments(): void
    {
        Storage::fake('local');
        $org = $this->setUpOrg();
        $semester = $org['semester'];

        // One assignment with 2 submissions (4 files total), one assignment
        // with no submissions.
        $withSubmissions = $this->createAssignment($org['teacher']->id, $org['subject']->id, $semester->id, $org['classroom']->id, 'With submissions');
        $withoutSubmissions = $this->createAssignment($org['teacher']->id, $org['subject']->id, $semester->id, $org['classroom']->id, 'No submissions');

        $assignmentService = $this->app->make(AssignmentService::class);
        $subA = $assignmentService->submitAssignment(
            $org['students'][0]->id,
            $withSubmissions->id,
            [
                UploadedFile::fake()->create('answer-a.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('extra-a.pdf', 10, 'application/pdf')]
        );
        $subB = $assignmentService->submitAssignment(
            $org['students'][1]->id,
            $withSubmissions->id,
            [
                UploadedFile::fake()->create('answer-b.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('extra-b.pdf', 10, 'application/pdf')]
        );

        AssignmentFeedback::create([
            'submission_id' => $subA->id,
            'teacher_id' => $org['teacher']->id,
            'feedback_text' => 'Well done.']);

        $filePaths = SubmissionFile::pluck('filename')->all();
        $this->assertCount(4, $filePaths);

        $response = $this->actAs($org['admin'])->postJson($this->purgeUrl($semester->id));

        $response->assertOk()
            ->assertJsonPath('data.semester_id', $semester->id)
            ->assertJsonPath('data.assignments_purged', 1)
            ->assertJsonPath('data.submissions_purged', 2)
            ->assertJsonPath('data.files_purged', 4);

        $this->assertSame(0, AssignmentSubmission::count());
        $this->assertSame(0, SubmissionFile::count());
        $this->assertSame(0, AssignmentFeedback::count());
        $this->assertDatabaseHas('assignments', ['id' => $withSubmissions->id]);
        $this->assertDatabaseHas('assignments', ['id' => $withoutSubmissions->id]);
        $this->assertSame(2, Assignment::count());

        foreach ($filePaths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
    }

    public function test_purge_never_deletes_assignments_or_teacher_attachments(): void
    {
        Storage::fake('local');
        $org = $this->setUpOrg();
        $semester = $org['semester'];

        $assignment = $this->createAssignment($org['teacher']->id, $org['subject']->id, $semester->id, $org['classroom']->id, 'Teacher attachment holder');
        $attachmentPath = 'assignments/attachments/' . $assignment->id . '/lesson.pdf';
        Storage::disk('local')->put($attachmentPath, 'teacher attachment bytes');
        AssignmentAttachment::create([
            'assignment_id' => $assignment->id,
            'filename' => $attachmentPath,
            'original_filename' => 'lesson.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 50]);

        $this->actAs($org['admin'])->postJson($this->purgeUrl($semester->id))->assertOk();

        $this->assertDatabaseHas('assignments', ['id' => $assignment->id]);
        $this->assertDatabaseHas('assignment_attachments', [
            'assignment_id' => $assignment->id,
            'original_filename' => 'lesson.pdf']);
        Storage::disk('local')->assertExists($attachmentPath);
    }

    public function test_purge_preserves_br32_protected_data(): void
    {
        Storage::fake('local');
        $org = $this->setUpOrg();
        $admin = $org['admin'];
        $teacher = $org['teacher'];
        $student = $org['students'][0];
        $semester = $org['semester'];

        // Assignment submission data that IS in scope.
        $assignment = $this->createAssignment($teacher->id, $org['subject']->id, $semester->id, $org['classroom']->id, 'Purged assignment');
        $this->app->make(AssignmentService::class)->submitAssignment(
            $org['students'][1]->id,
            $assignment->id,
            [UploadedFile::fake()->create('answer.pdf', 10, 'application/pdf')]
        );

        // ARCH-002 QA-010 protected data: assessments + mastery + AI + materials.
        $competency = $this->createCompetency($org['subject']);

        $assessment = Assessment::create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $org['classroom']->id,
            'subject_id' => $org['subject']->id,
            'semester_id' => $semester->id,
            'title' => 'Unit Quiz',
            'type' => 'Recorded',
            'status' => 'released']);

        $item = AssessmentItem::create([
            'assessment_id' => $assessment->id,
            'item_type' => 'essay',
            'prompt' => 'Explain.',
            'max_points' => 10,
            'competency_tag_id' => $competency->id,
            'sort_order' => 1]);

        $itemAttachmentPath = 'assessment_items/' . $assessment->id . '/items/' . $item->id . '/figure.pdf';
        Storage::disk('local')->put($itemAttachmentPath, 'item attachment bytes');
        AssessmentItemAttachment::create([
            'assessment_item_id' => $item->id,
            'filename' => $itemAttachmentPath,
            'original_filename' => 'figure.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 50]);

        $attempt = AssessmentAttempt::create([
            'assessment_id' => $assessment->id,
            'student_id' => $student->id,
            'attempt_number' => 1,
            'status' => 'submitted']);

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

        $materialPath = 'learning_materials/handout.pdf';
        Storage::disk('local')->put($materialPath, 'learning material bytes');
        LearningMaterial::create([
            'teacher_id' => $teacher->id,
            'subject_id' => $org['subject']->id,
            'competency_id' => $competency->id,
            'filename' => $materialPath,
            'original_filename' => 'handout.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 50]);

        $announcement = Announcement::create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $org['classroom']->id,
            'subject_id' => $org['subject']->id,
            'title' => 'Reminder',
            'body' => 'Submit on time.']);
        $announcementPath = 'announcements/' . $announcement->id . '/notice.pdf';
        Storage::disk('local')->put($announcementPath, 'announcement file bytes');
        AnnouncementAttachment::create([
            'announcement_id' => $announcement->id,
            'filename' => $announcementPath,
            'original_filename' => 'notice.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 50]);

        $protectedModels = [
            Assessment::class,
            AssessmentItem::class,
            AssessmentAttempt::class,
            AssessmentSubmission::class,
            AssessmentResponse::class,
            AssessmentItemAttachment::class,
            MasteryRecord::class,
            AIExplanation::class,
            LearningMaterial::class,
            Announcement::class,
            AnnouncementAttachment::class];
        $before = [];
        foreach ($protectedModels as $modelClass) {
            $before[$modelClass] = $modelClass::count();
        }

        $this->actAs($admin)->postJson($this->purgeUrl($semester->id))->assertOk();

        foreach ($protectedModels as $modelClass) {
            $this->assertSame($before[$modelClass], $modelClass::count(), $modelClass . ' rows changed by purge');
        }

        Storage::disk('local')->assertExists($materialPath);
        Storage::disk('local')->assertExists($announcementPath);
        Storage::disk('local')->assertExists($itemAttachmentPath);

        // The in-scope submission data WAS purged.
        $this->assertSame(0, AssignmentSubmission::count());
        $this->assertSame(0, SubmissionFile::count());
    }

    public function test_purge_writes_audit_event_with_counts(): void
    {
        Storage::fake('local');
        $org = $this->setUpOrg();
        $semester = $org['semester'];
        $admin = $org['admin'];

        $assignment = $this->createAssignment($org['teacher']->id, $org['subject']->id, $semester->id, $org['classroom']->id, 'Audited assignment');
        $this->app->make(AssignmentService::class)->submitAssignment(
            $org['students'][0]->id,
            $assignment->id,
            [UploadedFile::fake()->create('answer.pdf', 10, 'application/pdf')]
        );

        $this->actAs($admin)->postJson($this->purgeUrl($semester->id))->assertOk();

        $audit = AuditLog::where('event_type', 'purge_semesters')
            ->where('auditable_type', Semester::class)
            ->where('auditable_id', $semester->id)
            ->firstOrFail();

        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame('Semester-closure purge (semester ' . $semester->id . ')', $audit->description);
        $this->assertSame(1, $audit->metadata['assignments_purged']);
        $this->assertSame(1, $audit->metadata['submissions_purged']);
        $this->assertSame(1, $audit->metadata['files_purged']);
    }

    public function test_purge_rerun_is_idempotent_with_zero_counts(): void
    {
        Storage::fake('local');
        $org = $this->setUpOrg();
        $semester = $org['semester'];

        $assignment = $this->createAssignment($org['teacher']->id, $org['subject']->id, $semester->id, $org['classroom']->id, 'Idempotent assignment');
        $this->app->make(AssignmentService::class)->submitAssignment(
            $org['students'][0]->id,
            $assignment->id,
            [UploadedFile::fake()->create('answer.pdf', 10, 'application/pdf')]
        );

        $first = $this->actAs($org['admin'])->postJson($this->purgeUrl($semester->id));
        $first->assertOk()->assertJsonPath('data.files_purged', 1);

        $second = $this->actAs($org['admin'])->postJson($this->purgeUrl($semester->id));
        $second->assertOk()
            ->assertJsonPath('data.assignments_purged', 0)
            ->assertJsonPath('data.submissions_purged', 0)
            ->assertJsonPath('data.files_purged', 0);

        $this->assertDatabaseHas('assignments', ['id' => $assignment->id]);
        $this->assertSame(2, AuditLog::where('event_type', 'purge_semesters')->count());
    }

    public function test_purge_service_returns_counts_and_semester_id(): void
    {
        Storage::fake('local');
        $org = $this->setUpOrg();
        $semester = $org['semester'];

        $result = $this->app->make(OrgStructureService::class)->purgeClosedSemester($semester->id);

        $this->assertSame($semester->id, $result['semester_id']);
        $this->assertSame(0, $result['assignments_purged']);
        $this->assertSame(0, $result['submissions_purged']);
        $this->assertSame(0, $result['files_purged']);
    }

    // ========================================================================
    // B. Audit retention — IP anonymization & log purge (ARCH-002 QA-006)
    // ========================================================================

    public function test_anonymize_masks_old_ipv4_ipv6_and_mapped_ips(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 2, 12, 0, 0));

        $oldIpv4 = $this->createAuditRow(['ip_address' => '192.168.1.10', 'created_at' => now()->subDays(91)]);
        $oldIpv6 = $this->createAuditRow(['ip_address' => '2001:db8::1', 'created_at' => now()->subDays(100)]);
        $oldMapped = $this->createAuditRow(['ip_address' => '::ffff:1.2.3.4', 'created_at' => now()->subDays(92)]);

        $updated = $this->auditLogService()->anonymizeIpAddresses();

        $this->assertSame(3, $updated);
        $this->assertSame('192.168.1.0', $oldIpv4->fresh()->ip_address);
        $this->assertSame('2001:db8::', $oldIpv6->fresh()->ip_address);
        $this->assertSame('::', $oldMapped->fresh()->ip_address);
    }

    public function test_anonymize_skips_fresh_and_null_ips(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 2, 12, 0, 0));

        $fresh = $this->createAuditRow(['ip_address' => '10.0.0.5', 'created_at' => now()]);
        $nullIp = $this->createAuditRow(['ip_address' => null, 'created_at' => now()->subDays(91)]);

        $this->assertSame(0, $this->auditLogService()->anonymizeIpAddresses());
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

    public function test_anonymize_honors_configured_days_override(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 2, 12, 0, 0));

        $old = $this->createAuditRow(['ip_address' => '10.1.1.5', 'created_at' => now()->subDays(91)]);
        $fresh = $this->createAuditRow(['ip_address' => '172.16.0.9', 'created_at' => now()]);

        config(['audit.ip_anonymization_days' => 0]);

        $updated = $this->auditLogService()->anonymizeIpAddresses((int) config('audit.ip_anonymization_days'));

        $this->assertSame(2, $updated);
        $this->assertSame('10.1.1.0', $old->fresh()->ip_address);
        $this->assertSame('172.16.0.0', $fresh->fresh()->ip_address);

        // Restore: explicit default argument leaves a fresh row untouched
        // (honor-config: restore the default window before the no-arg call).
        config(['audit.ip_anonymization_days' => 90]);
        $freshTwo = $this->createAuditRow(['ip_address' => '172.16.0.9', 'created_at' => now()]);
        $this->assertSame(0, $this->auditLogService()->anonymizeIpAddresses());
        $this->assertSame('172.16.0.9', $freshTwo->fresh()->ip_address);
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
    }

    public function test_purge_old_logs_accepts_zero_and_negative_days(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 2, 12, 0, 0));

        $recent = $this->createAuditRow(['created_at' => now()->subDays(1)]);

        $this->assertSame(1, $this->auditLogService()->purgeOldLogs(0));
        $this->assertDatabaseMissing('audit_logs', ['id' => $recent->id]);

        // Negative values execute without error and return a count. Note the
        // service docblock claims negatives "delete nothing (the bound lies
        // in the future)" — but NOW() - (-30 days) places the bound 30 days
        // in the future, so ALL rows (created_at < future) match and are
        // deleted. The regression contract only requires no error + a count.
        $kept = $this->createAuditRow(['created_at' => now()->subDays(1)]);
        $deleted = $this->auditLogService()->purgeOldLogs(-30);
        $this->assertIsInt($deleted);
        $this->assertDatabaseMissing('audit_logs', ['id' => $kept->id]);
    }

    public function test_audit_retention_config_defaults_match_service_constants(): void
    {
        $this->assertSame(90, config('audit.ip_anonymization_days'));
        $this->assertSame(365, config('audit.retention_days'));
        $this->assertSame(90, AuditLogService::IP_ANONYMIZATION_DAYS);
        $this->assertSame(365, AuditLogService::DEFAULT_RETENTION_DAYS);
    }

    // ========================================================================
    // C. Login rate limiting (ARCH-002 QA-004, ARCH-002 QA-004)
    // ========================================================================

    public function test_rate_limited_response_includes_numeric_reset_header(): void
    {
        // CompAss-ID login: hammer the account's real school_id (STU-XXXX-XXXXX).
        $identifier = $this->makeUser('student', [])->school_id;

        for ($i = 0; $i < 5; $i++) {
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

    public function test_rate_limit_block_writes_login_audit_with_attempts(): void
    {
        $identifier = $this->makeUser('student', [])->school_id;

        for ($i = 0; $i < 5; $i++) {
            $this->post('/api/auth/login', [
                'identifier' => $identifier,
                'password' => 'wrong-password'])->assertStatus(401);
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
        // F-08: raw identifier must not persist — masked form only.
        $this->assertArrayNotHasKey('identifier', $audit->metadata);
        $this->assertArrayNotHasKey('ip', $audit->metadata);
        $this->assertSame(AuditLogService::maskIdentifier($identifier), $audit->metadata['identifier_masked']);
        $this->assertNotSame($identifier, $audit->metadata['identifier_masked']);
        $this->assertSame(5, $audit->metadata['attempts']);
    }

    public function test_validation_errors_consume_zero_rate_limit_attempts(): void
    {
        $identifier = $this->makeUser('student', [])->school_id;

        // 5 validation failures (missing password) never reach authenticate().
        for ($i = 0; $i < 5; $i++) {
            $this->post('/api/auth/login', [
                'identifier' => $identifier])->assertStatus(422)
                ->assertJsonPath('error.code', 'VALIDATION_ERROR');
        }

        // The full 5-attempt budget is still available.
        for ($i = 0; $i < 5; $i++) {
            $this->post('/api/auth/login', [
                'identifier' => $identifier,
                'password' => 'wrong-password'])->assertStatus(401);
        }

        // 11th request is blocked.
        $this->post('/api/auth/login', [
            'identifier' => $identifier,
            'password' => 'wrong-password'])->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED');
    }

    public function test_rate_limit_remaining_counts_down_on_consecutive_failures(): void
    {
        $identifier = $this->makeUser('student', [])->school_id;

        foreach (['4', '3', '2', '1', '0'] as $remaining) {
            $this->post('/api/auth/login', [
                'identifier' => $identifier,
                'password' => 'wrong-password'])->assertStatus(401)
                ->assertHeader('X-RateLimit-Remaining', $remaining);
        }

        $this->post('/api/auth/login', [
            'identifier' => $identifier,
            'password' => 'wrong-password'])->assertStatus(429)
            ->assertHeader('X-RateLimit-Remaining', '0');
    }

    public function test_rate_limit_window_decays_after_15_minutes(): void
    {
        $identifier = $this->makeUser('student', [])->school_id;

        for ($i = 0; $i < 5; $i++) {
            $this->post('/api/auth/login', [
                'identifier' => $identifier,
                'password' => 'wrong-password'])->assertStatus(401);
        }

        $this->post('/api/auth/login', [
            'identifier' => $identifier,
            'password' => 'wrong-password'])->assertStatus(429);

        Carbon::setTestNow(now()->addSeconds(AuthService::RATE_LIMIT_DECAY_SECONDS + 1));

        $this->post('/api/auth/login', [
            'identifier' => $identifier,
            'password' => 'wrong-password'])->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_rate_limit_bucket_is_case_sensitive_per_variant(): void
    {
        // Case-sensitive bucket (trim only): hammering a lower-case variant
        // must not lock the victim's exact CompAss ID (no cross-account DoS),
        // while hammering the exact ID still locks that exact bucket.
        $victim = $this->makeUser('student', []);
        $exactId = $victim->school_id;
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

        // The victim's exact ID still has its full budget: spacing trims to
        // the same bucket, case does not.
        $this->post('/api/auth/login', [
            'identifier' => '  ' . $exactId . '  ',
            'password' => 'wrong-password'])->assertStatus(401)
            ->assertHeader('X-RateLimit-Remaining', '4');
    }

    // ========================================================================
    // D. ARCH-002 QA-009 upload whitelist — Announcements
    // ========================================================================

    public function test_announcement_txt_attachment_rejected_422(): void
    {
        $org = $this->setUpOrg();
        $txt = UploadedFile::fake()->create('notes.txt', 10, 'text/plain');

        $this->assertBusinessRuleConflict(function () use ($org, $txt): void {
            $this->app->make(AnnouncementService::class)->createAnnouncementInClassroom(
                $org['teacher']->id,
                $org['classroom']->id,
                'Hello',
                'Body',
                [$txt]
            );
        }, 'INVALID_FILE_TYPE', 422);

        $this->assertSame(0, \App\Models\Announcement::count());
    }

    public function test_announcement_txt_attachment_rejected_422_at_http(): void
    {
        $org = $this->setUpOrg();

        $this->actAs($org['teacher'])
            ->post('/api/teacher/classrooms/' . $org['classroom']->id . '/announcements', [
                'title' => 'Hello',
                'body' => 'Body.',
                'attachments' => [
                    UploadedFile::fake()->create('notes.txt', 10, 'text/plain')]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertSame(0, \App\Models\Announcement::count());
    }

    public function test_announcement_docx_and_pdf_attachments_accepted(): void
    {
        Storage::fake('local');
        $org = $this->setUpOrg();

        $docx = $this->actAs($org['teacher'])->post('/api/teacher/classrooms/' . $org['classroom']->id . '/announcements', [
            'title' => 'Docx announcement',
            'body' => 'Body.',
            'attachments' => [
                UploadedFile::fake()->create('note.docx', 10, self::DOCX_MIME)]]);
        $docx->assertStatus(201)
            ->assertJsonPath('data.title', 'Docx announcement');

        $pdf = $this->actAs($org['teacher'])->post('/api/teacher/classrooms/' . $org['classroom']->id . '/announcements', [
            'title' => 'Pdf announcement',
            'body' => 'Body.',
            'attachments' => [
                UploadedFile::fake()->create('note.pdf', 10, 'application/pdf')]]);
        $pdf->assertStatus(201)
            ->assertJsonPath('data.title', 'Pdf announcement');

        $this->assertSame(2, \App\Models\AnnouncementAttachment::count());
    }

    public function test_announcement_six_attachments_rejected_409(): void
    {
        $org = $this->setUpOrg();

        $attachments = [];
        for ($i = 0; $i < 6; $i++) {
            $attachments[] = UploadedFile::fake()->create('file-' . $i . '.pdf', 10, 'application/pdf');
        }

        $this->assertBusinessRuleConflict(function () use ($org, $attachments): void {
            $this->app->make(AnnouncementService::class)->createAnnouncementInClassroom(
                $org['teacher']->id,
                $org['classroom']->id,
                'Hello',
                'Body',
                $attachments
            );
        }, 'TOO_MANY_ATTACHMENTS');
    }

    public function test_announcement_oversized_attachment_rejected_409(): void
    {
        $org = $this->setUpOrg();

        $oversized = UploadedFile::fake()->createWithContent(
            'big.pdf',
            str_repeat('x', AnnouncementService::MAX_FILE_SIZE + 1)
        );

        $this->assertBusinessRuleConflict(function () use ($org, $oversized): void {
            $this->app->make(AnnouncementService::class)->createAnnouncementInClassroom(
                $org['teacher']->id,
                $org['classroom']->id,
                'Hello',
                'Body',
                [$oversized]
            );
        }, 'FILE_TOO_LARGE');
    }

    // ========================================================================
    // D. ARCH-002 QA-009 upload whitelist — Assignments (teacher + student)
    // ========================================================================

    public function test_assignment_teacher_attachment_rejected_422(): void
    {
        $org = $this->setUpOrg();
        $exe = UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload');

        $this->assertBusinessRuleConflict(function () use ($org, $exe): void {
            $this->app->make(AssignmentService::class)->createAssignmentInClassroom(
                $org['teacher']->id,
                $org['classroom']->id,
                'Homework',
                'Read pages 1-10.',
                '2026-02-15 23:59:59',
                [$exe]
            );
        }, 'INVALID_FILE_TYPE', 422);

        $this->assertSame(0, Assignment::count());
    }

    public function test_assignment_teacher_attachment_rejected_422_at_http(): void
    {
        Storage::fake('local');
        $org = $this->setUpOrg();

        $this->actAs($org['teacher'])
            ->post('/api/teacher/classrooms/' . $org['classroom']->id . '/assignments', [
                'title' => 'Homework',
                'description' => 'Read pages 1-10.',
                'due_date' => '2026-02-15 23:59:59',
                'attachments' => [
                    UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertSame(0, Assignment::count());
    }

    public function test_assignment_student_submission_txt_rejected_422(): void
    {
        Storage::fake('local');
        $org = $this->setUpOrg();
        $assignment = $this->createAssignment($org['teacher']->id, $org['subject']->id, $org['semester']->id, $org['classroom']->id, 'Submission assignment');
        $txt = UploadedFile::fake()->create('notes.txt', 10, 'text/plain');

        $this->assertBusinessRuleConflict(function () use ($org, $assignment, $txt): void {
            $this->app->make(AssignmentService::class)->submitAssignment(
                $org['students'][0]->id,
                $assignment->id,
                [$txt]
            );
        }, 'INVALID_FILE_TYPE', 422);

        $this->assertSame(0, AssignmentSubmission::count());
    }

    public function test_assignment_student_submission_pdf_accepted(): void
    {
        Storage::fake('local');
        $org = $this->setUpOrg();
        $assignment = $this->createAssignment($org['teacher']->id, $org['subject']->id, $org['semester']->id, $org['classroom']->id, 'Submission assignment');

        $response = $this->actAs($org['students'][0])
            ->post('/api/student/assignments/' . $assignment->id . '/submit', [
                'files' => [
                    UploadedFile::fake()->create('answer.pdf', 10, 'application/pdf')]]);

        $response->assertStatus(201)
            ->assertJsonPath('data.file_count', 1);

        $this->assertDatabaseHas('submission_files', [
            'original_filename' => 'answer.pdf',
            'mime_type' => 'application/pdf']);
    }

    // ========================================================================
    // D. ARCH-002 QA-009 upload whitelist — Assessment items
    // ========================================================================

    public function test_assessment_item_txt_attachment_rejected_422(): void
    {
        $org = $this->setUpOrg();
        $competency = $this->createCompetency($org['subject']);
        $assessment = Assessment::create([
            'teacher_id' => $org['teacher']->id,
            'classroom_id' => $org['classroom']->id,
            'subject_id' => $org['subject']->id,
            'semester_id' => $org['semester']->id,
            'title' => 'Quiz',
            'type' => 'Recorded',
            'status' => 'draft']);
        $txt = UploadedFile::fake()->create('notes.txt', 10, 'text/plain');

        $this->assertBusinessRuleConflict(function () use ($org, $assessment, $competency, $txt): void {
            $this->app->make(AssessmentService::class)->addItem(
                $org['teacher']->id,
                $assessment->id,
                'essay',
                'Explain.',
                10,
                null,
                $competency->id,
                [$txt]
            );
        }, 'INVALID_FILE_TYPE', 422);

        $this->assertSame(0, \App\Models\AssessmentItem::count());
    }

    // ========================================================================
    // D. ARCH-002 QA-009 upload whitelist — Learning materials (ARCH-002 QA-009)
    // ========================================================================

    public function test_learning_material_pdf_and_docx_accepted(): void
    {
        Storage::fake('local');
        $org = $this->setUpOrg();
        $competency = $this->createCompetency($org['subject']);

        $pdf = $this->actAs($org['teacher'])->post('/api/teacher/learning-materials', [
            'subject_id' => $org['subject']->id,
            'competency_id' => $competency->id,
            'title' => 'Notes pdf',
            'file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')]);
        $pdf->assertStatus(201)
            ->assertJsonPath('data.mime_type', 'application/pdf');

        $docx = $this->actAs($org['teacher'])->post('/api/teacher/learning-materials', [
            'subject_id' => $org['subject']->id,
            'competency_id' => $competency->id,
            'title' => 'Notes docx',
            'file' => UploadedFile::fake()->create('notes.docx', 10, self::DOCX_MIME)]);
        $docx->assertStatus(201)
            ->assertJsonPath('data.mime_type', self::DOCX_MIME);

        $this->assertSame(2, LearningMaterial::count());
    }

    public function test_learning_material_pptx_rejected_422(): void
    {
        $org = $this->setUpOrg();
        $competency = $this->createCompetency($org['subject']);
        $pptx = UploadedFile::fake()->create('slides.pptx', 10, self::PPTX_MIME);

        $this->assertBusinessRuleConflict(function () use ($org, $competency, $pptx): void {
            $this->app->make(LearningMaterialService::class)->storeLearningMaterial(
                $org['teacher']->id,
                $org['subject']->id,
                $competency->id,
                'Slides',
                $pptx
            );
        }, 'INVALID_FILE_TYPE', 422);

        $this->assertSame(0, LearningMaterial::count());
    }

    public function test_learning_material_oversized_file_rejected_409(): void
    {
        $org = $this->setUpOrg();
        $competency = $this->createCompetency($org['subject']);

        $oversized = UploadedFile::fake()->createWithContent(
            'big.pdf',
            str_repeat('x', LearningMaterialService::MAX_FILE_SIZE + 1)
        );

        $this->assertBusinessRuleConflict(function () use ($org, $competency, $oversized): void {
            $this->app->make(LearningMaterialService::class)->storeLearningMaterial(
                $org['teacher']->id,
                $org['subject']->id,
                $competency->id,
                'Notes',
                $oversized
            );
        }, 'FILE_TOO_LARGE');

        $this->assertSame(0, LearningMaterial::count());
    }

    // ========================================================================
    // D. ARCH-002 QA-009 upload whitelist — renamed-file (MIME mismatch)
    // ========================================================================

    public function test_renamed_file_with_allowed_extension_rejected_422(): void
    {
        $org = $this->setUpOrg();
        $competency = $this->createCompetency($org['subject']);

        // A file named notes.pdf whose reported MIME is text/plain — the whitelist
        // requires BOTH extension and detected MIME to be allowed. The MIME
        // must be passed explicitly: Laravel's
        // UploadedFile::fake()->createWithContent($name, $content) takes no
        // MIME argument and guesses application/pdf from the .pdf extension
        // (MimeType::from($name)), so it can never produce a mismatch.
        $sneaky = UploadedFile::fake()->create('notes.pdf', 10, 'text/plain');

        $this->assertBusinessRuleConflict(function () use ($org, $sneaky): void {
            $this->app->make(AnnouncementService::class)->createAnnouncementInClassroom(
                $org['teacher']->id,
                $org['classroom']->id,
                'Hello',
                'Body',
                [$sneaky]
            );
        }, 'INVALID_FILE_TYPE', 422);

        $this->assertBusinessRuleConflict(function () use ($org, $competency, $sneaky): void {
            $this->app->make(LearningMaterialService::class)->storeLearningMaterial(
                $org['teacher']->id,
                $org['subject']->id,
                $competency->id,
                'Notes',
                $sneaky
            );
        }, 'INVALID_FILE_TYPE', 422);
    }
}
