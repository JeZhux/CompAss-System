<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\AIExplanation;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentItem;
use App\Models\AssessmentSubmission;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\CompetencyReference;
use App\Models\GradeLevel;
use App\Models\LearningMaterial;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 8 — Content-write audit instrumentation (ARCH-002 QA-006, ARCH-001 §5.1).
 *
 * Exercises the REAL teacher API flows for the Phase 3/7 content writers —
 * LearningMaterialService (#83–#86), AssessmentService (#55–#57), and the
 * AIService moderation actions (#92, #94) — and asserts the exact `audit_logs`
 * rows each service emits: event_type, user_id, auditable_type, auditable_id,
 * description (ARCH-001 §5.1 "sensitive-entity CRUD create/update/delete" and
 * "moderation" entries).
 *
 * Also asserts the Phase 8 DoD read-back: rows written by these flows are
 * visible via GET /api/admin/audit-logs (ARCH-005 block 4.8 #96).
 *
 * In Feature tests the app runs in console mode, so `ip_address` / `user_agent`
 * are captured as NULL (ARCH-002 QA-006) — IPs are not asserted.
 *
 * @Traced-To ARCH-002 QA-006 (ARCH-001 §5.1, ARCH-005 block 4.8)
 */
#[Group('phase8-audit-instrumentation-content')]
class Phase8AuditInstrumentationContentTest extends TestCase
{
    use RefreshDatabase;

    private const LEARNING_MATERIALS_URL = '/api/teacher/learning-materials';

    private const ASSESSMENTS_URL = '/api/teacher/assessments';

    private const MODERATION_LOG_URL = '/api/teacher/moderation-log';

    private const AUDIT_LOGS_URL = '/api/admin/audit-logs';

    private ?User $admin = null;

    private ?User $teacher = null;

    private ?User $otherTeacher = null;

    private ?User $student = null;

    private ?Section $section = null;

    private ?Subject $subject = null;

    private ?Classroom $classroom = null;

    private ?CompetencyReference $competencyTag = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = null;
        $this->teacher = null;
        $this->otherTeacher = null;
        $this->student = null;
        $this->section = null;
        $this->subject = null;
        $this->classroom = null;
        $this->competencyTag = null;
    }

    private function actAsAdmin(): User
    {
        if (! $this->admin) {
            $this->admin = User::factory()->create([
                'role' => 'Admin',
                'must_change_password' => false]);
        }

        Sanctum::actingAs($this->admin);

        return $this->admin;
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

    private function otherTeacher(): User
    {
        if (! $this->otherTeacher) {
            $this->otherTeacher = User::factory()->create([
                'role' => 'Teacher',
                'must_change_password' => false]);
        }

        return $this->otherTeacher;
    }

    private function student(): User
    {
        if (! $this->student) {
            $this->student = User::factory()->create([
                'role' => 'Student']);
        }

        return $this->student;
    }

    /**
     * Minimal org hierarchy (School Year → Semester → Grade Level → Section →
     * Subject) with the teacher assigned and one competency
     * tag — enough for learning-material, assessment, and moderation flows.
     */
    private function setUpOrg(): void
    {
        $year = SchoolYear::create(['name' => 'SY 2026-AUDIT']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31']);
        $gradeLevel = GradeLevel::create([
            'semester_id' => $semester->id,
            'grade_level' => '7']);
        $this->section = Section::create([
            'grade_level_id' => $gradeLevel->id,
            'name' => '7A-AUDIT']);
$this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-AUDIT']);
                $this->competencyTag = CompetencyReference::create(['semester' => '1', 'code' => 'M7-NUM-AUDIT',
            'descriptor' => 'Solve multi-step rational numbers',
            'subject_id' => $this->subject->id,
            'grade_level' => '7']);

        $this->actAsTeacher();
        $this->classroom = app(\App\Services\ClassroomService::class)->createClassroom($this->teacher->id, $this->subject->id, $this->section->id, '2026-2027', null);
    }

    private function auditRowCount(string $eventType, string $auditableType, int $auditableId): int
    {
        return AuditLog::where('event_type', $eventType)
            ->where('auditable_type', $auditableType)
            ->where('auditable_id', $auditableId)
            ->count();
    }

    /**
     * POST a valid learning material via the endpoint and return the
     * serialized `data` array (201 asserted inside).
     */
    private function storeMaterial(string $title = 'Test Material'): array
    {
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post(self::LEARNING_MATERIALS_URL, [
                'subject_id' => $this->subject->id,
                'competency_id' => $this->competencyTag->id,
                'title' => $title,
                'file' => File::fake()->create('guide.pdf', 100, 'application/pdf')]);

        $response->assertCreated();

        return $response->json('data');
    }

    /**
     * Seed an AIExplanation row (plus its full FK chain: assessment, item,
     * attempt, submission) scoped to the given teacher's subject —
     * enough for the #92/#94 moderation endpoints to accept it.
     */
    private function seedExplanation(
        User $teacher,
        Subject $subject,
        Section $section,
        CompetencyReference $competencyTag,
        User $student,
        ?int $classroomId = null
    ): AIExplanation {
        $assessment = Assessment::create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroomId ?? $this->classroom->id,
            'subject_id' => $subject->id,
            'semester_id' => $section->gradeLevel->semester_id,
            'title' => 'AI audit assessment',
            'description' => '',
            'type' => 'Recorded',
            'status' => 'released']);

        $item = AssessmentItem::create([
            'assessment_id' => $assessment->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $competencyTag->id,
            'sort_order' => 1]);

        $attempt = AssessmentAttempt::create([
            'assessment_id' => $assessment->id,
            'student_id' => $student->id,
            'attempt_number' => 1,
            'status' => 'scored',
            'response_history' => []]);

        $submission = AssessmentSubmission::create([
            'attempt_id' => $attempt->id,
            'assessment_id' => $assessment->id,
            'student_id' => $student->id,
            'section_id' => $section->id,
            'semester_id' => $assessment->semester_id,
            'submitted_at' => now(),
            'status' => 'scored']);

        return AIExplanation::create([
            'student_id' => $student->id,
            'assessment_id' => $assessment->id,
            'assessment_submission_id' => $submission->id,
            'item_id' => $item->id,
            'explanation_text' => 'AI-generated explanation for audit instrumentation.']);
    }

    private function seedMainExplanation(): AIExplanation
    {
        return $this->seedExplanation(
            $this->teacher,
            $this->subject,
            $this->section,
            $this->competencyTag,
            $this->student()
        );
    }

    /**
     * Build a second teacher's own org (section, subject, tag,
     * student) with one stored explanation — for ownership-403 assertions.
     */
    private function seedSecondTeacherExplanation(): AIExplanation
    {
        $secondTeacher = $this->otherTeacher();
        $secondSection = Section::create([
            'grade_level_id' => $this->section->gradeLevel->id,
            'name' => '7B-AUDIT']);
        $secondSubject = Subject::create(['grade_level_id' => $this->section->gradeLevel->id, 'name' => 'Science', 'code' => 'SCI7-AUDIT']);
        $secondTag = CompetencyReference::create(['semester' => '1', 'code' => 'S7-LIFE-AUDIT',
            'descriptor' => 'Describe cell structure',
            'subject_id' => $secondSubject->id,
            'grade_level' => '7']);
        $secondStudent = User::factory()->create([
            'role' => 'Student']);
        $secondClassroom = app(\App\Services\ClassroomService::class)->createClassroom($secondTeacher->id, $secondSubject->id, $secondSection->id, '2026-2027', null);

        return $this->seedExplanation(
            $secondTeacher,
            $secondSubject,
            $secondSection,
            $secondTag,
            $secondStudent,
            $secondClassroom->id
        );
    }

    // ========================================================================
    // Learning materials (#83–#86) — create/update/delete instrumentation
    // ========================================================================

    public function test_learning_material_create_update_delete_write_one_audit_row_each(): void
    {
        $this->setUpOrg();
        Storage::fake('local');

        $data = $this->storeMaterial('Fraction Guide');
        $materialId = (int) $data['id'];

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'create',
            'user_id' => $this->teacher->id,
            'auditable_type' => LearningMaterial::class,
            'auditable_id' => $materialId,
            'description' => 'Learning material created: Fraction Guide']);
        // CLI context captures no client IP (ARCH-002 QA-006).
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'create',
            'user_id' => $this->teacher->id,
            'auditable_type' => LearningMaterial::class,
            'auditable_id' => $materialId,
            'ip_address' => null]);

        $this->actingAs($this->teacher, 'sanctum')
            ->put(self::LEARNING_MATERIALS_URL . '/' . $materialId, [
                'title' => 'Updated Guide'])
            ->assertOk();

        $this->assertSame(1, $this->auditRowCount('update', LearningMaterial::class, $materialId));
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'update',
            'user_id' => $this->teacher->id,
            'auditable_type' => LearningMaterial::class,
            'auditable_id' => $materialId,
            'description' => 'Learning material updated (id ' . $materialId . ')']);

        $this->actingAs($this->teacher, 'sanctum')
            ->delete(self::LEARNING_MATERIALS_URL . '/' . $materialId)
            ->assertOk();

        $this->assertSame(1, $this->auditRowCount('delete', LearningMaterial::class, $materialId));
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'delete',
            'user_id' => $this->teacher->id,
            'auditable_type' => LearningMaterial::class,
            'auditable_id' => $materialId,
            'description' => 'Learning material deleted (id ' . $materialId . ')']);
    }

    public function test_learning_material_oversized_upload_returns_422_and_writes_no_audit_row(): void
    {
        $this->setUpOrg();
        Storage::fake('local');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post(self::LEARNING_MATERIALS_URL, [
                'subject_id' => $this->subject->id,
                'competency_id' => $this->competencyTag->id,
                'title' => 'Oversized Guide',
                'file' => File::fake()->create('big.pdf', 16000, 'application/pdf')]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('file', $response->json('error.fields'));
        $this->assertDatabaseCount('learning_materials', 0);
        $this->assertDatabaseMissing('audit_logs', [
            'event_type' => 'create',
            'auditable_type' => LearningMaterial::class]);
    }

    public function test_learning_material_update_oversized_file_is_atomic_and_writes_no_audit_row(): void
    {
        $this->setUpOrg();
        Storage::fake('local');

        $data = $this->storeMaterial('Original Guide');
        $materialId = (int) $data['id'];

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->put(self::LEARNING_MATERIALS_URL . '/' . $materialId, [
                'title' => 'New Title',
                'file' => File::fake()->create('huge.pdf', 16000, 'application/pdf')]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('file', $response->json('error.fields'));
        $this->assertDatabaseHas('learning_materials', [
            'id' => $materialId,
            'original_filename' => 'Original Guide']);
        $this->assertDatabaseMissing('audit_logs', [
            'event_type' => 'update',
            'auditable_type' => LearningMaterial::class,
            'auditable_id' => $materialId]);
    }

    // ========================================================================
    // Assessments (#55–#57) — create/update/delete instrumentation
    // ========================================================================

    public function test_assessment_create_and_update_write_one_create_and_one_update_audit_row(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'title' => 'Audit Quiz',
                'description' => '',
                'type' => 'Recorded']);
        $response->assertCreated();
        $assessmentId = (int) $response->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'create',
            'user_id' => $this->teacher->id,
            'auditable_type' => Assessment::class,
            'auditable_id' => $assessmentId,
            'description' => 'Assessment created (id ' . $assessmentId . ')']);

        $this->actingAs($this->teacher, 'sanctum')
            ->put(self::ASSESSMENTS_URL . '/' . $assessmentId, [
                'title' => 'Audit Quiz v2'])
            ->assertOk();

        $this->assertSame(1, $this->auditRowCount('update', Assessment::class, $assessmentId));
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'update',
            'user_id' => $this->teacher->id,
            'auditable_type' => Assessment::class,
            'auditable_id' => $assessmentId,
            'description' => 'Assessment updated (id ' . $assessmentId . ')']);
    }

    public function test_assessment_delete_draft_writes_one_delete_audit_row(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'title' => 'Doomed Quiz',
                'description' => '',
                'type' => 'Unrecorded']);
        $response->assertCreated();
        $assessmentId = (int) $response->json('data.id');

        $this->actingAs($this->teacher, 'sanctum')
            ->delete(self::ASSESSMENTS_URL . '/' . $assessmentId)
            ->assertOk();

        $this->assertSame(1, $this->auditRowCount('delete', Assessment::class, $assessmentId));
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'delete',
            'user_id' => $this->teacher->id,
            'auditable_type' => Assessment::class,
            'auditable_id' => $assessmentId,
            'description' => 'Assessment deleted (id ' . $assessmentId . ')']);
        $this->assertDatabaseMissing('assessments', ['id' => $assessmentId]);
    }

    public function test_assessment_delete_released_returns_409_and_writes_no_delete_audit_row(): void
    {
        $this->setUpOrg();
        $assessment = Assessment::create([
            'teacher_id' => $this->teacher->id,
            'classroom_id' => $this->classroom->id,
            'subject_id' => $this->subject->id,
            'semester_id' => $this->section->gradeLevel->semester_id,
            'title' => 'Released audit assessment',
            'description' => '',
            'type' => 'Recorded',
            'status' => 'released']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->delete(self::ASSESSMENTS_URL . '/' . $assessment->id);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'ASSESSMENT_RELEASED');
        $this->assertDatabaseHas('assessments', ['id' => $assessment->id]);
        $this->assertDatabaseMissing('audit_logs', [
            'event_type' => 'delete',
            'auditable_type' => Assessment::class,
            'auditable_id' => $assessment->id]);
    }

    public function test_assessment_update_not_owned_returns_404_and_writes_no_update_audit_row(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'title' => 'Private Quiz',
                'description' => '',
                'type' => 'Recorded']);
        $response->assertCreated();
        $assessmentId = (int) $response->json('data.id');

        $other = $this->otherTeacher();
        $response = $this->actingAs($other, 'sanctum')
            ->put(self::ASSESSMENTS_URL . '/' . $assessmentId, [
                'title' => 'Hijacked']);

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'NOT_FOUND');
        $this->assertDatabaseMissing('audit_logs', [
            'event_type' => 'update',
            'auditable_type' => Assessment::class,
            'auditable_id' => $assessmentId]);
    }

    // ========================================================================
    // AI moderation (#92, #94) — flag / disable-explain-further instrumentation
    // ========================================================================

    public function test_flag_explanation_writes_flag_explanation_audit_row(): void
    {
        $this->setUpOrg();
        $explanation = $this->seedMainExplanation();

        $this->actingAs($this->teacher, 'sanctum')
            ->post(self::MODERATION_LOG_URL . '/' . $explanation->id . '/flag', [
                'note' => 'Inappropriate content'])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'flag_explanation',
            'user_id' => $this->teacher->id,
            'auditable_type' => AIExplanation::class,
            'auditable_id' => $explanation->id,
            'description' => 'Explanation flagged (id ' . $explanation->id . ')']);
        $this->assertSame(1, $this->auditRowCount('flag_explanation', AIExplanation::class, (int) $explanation->id));
    }

    public function test_disable_explain_further_writes_disable_explain_further_audit_row(): void
    {
        $this->setUpOrg();
        $explanation = $this->seedMainExplanation();

        $this->actingAs($this->teacher, 'sanctum')
            ->post(self::MODERATION_LOG_URL . '/' . $explanation->id . '/disable-explain-further')
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'disable_explain_further',
            'user_id' => $this->teacher->id,
            'auditable_type' => AIExplanation::class,
            'auditable_id' => $explanation->id,
            'description' => 'Explain Further disabled (id ' . $explanation->id . ')']);
        $this->assertSame(
            1,
            $this->auditRowCount('disable_explain_further', AIExplanation::class, (int) $explanation->id)
        );
    }

    public function test_flag_nonexistent_explanation_returns_404_and_writes_no_audit_row(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post(self::MODERATION_LOG_URL . '/999999/flag');

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'NOT_FOUND');
        $this->assertDatabaseMissing('audit_logs', [
            'event_type' => 'flag_explanation']);
    }

    public function test_flag_other_teachers_explanation_returns_403_and_writes_no_audit_row(): void
    {
        $this->setUpOrg();
        $foreign = $this->seedSecondTeacherExplanation();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post(self::MODERATION_LOG_URL . '/' . $foreign->id . '/flag');

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
        $this->assertDatabaseMissing('audit_logs', [
            'event_type' => 'flag_explanation',
            'auditable_id' => $foreign->id]);
    }

    // ========================================================================
    // Phase 8 DoD read-back — rows visible via #96 GET /api/admin/audit-logs
    // ========================================================================

    public function test_admin_audit_logs_read_back_contains_instrumented_rows(): void
    {
        $this->setUpOrg();
        Storage::fake('local');

        $data = $this->storeMaterial('Read-back Guide');
        $materialId = (int) $data['id'];

        $this->actAsAdmin();
        $response = $this->get(self::AUDIT_LOGS_URL . '?event_type=create');
        $response->assertOk();

        $hit = collect($response->json('data'))->first(function (array $item) use ($materialId) {
            return $item['auditable_type'] === LearningMaterial::class
                && (int) $item['auditable_id'] === $materialId
                && $item['description'] === 'Learning material created: Read-back Guide';
        });

        $this->assertNotNull($hit);
        $this->assertSame($this->teacher->id, $hit['user_id']);
        $this->assertSame('create', $hit['event_type']);
        $this->assertSame(LearningMaterial::class, $hit['auditable_type']);
        $this->assertSame($materialId, (int) $hit['auditable_id']);
        $this->assertNull($hit['ip_address']);
    }

    public function test_admin_audit_logs_read_back_scopes_by_event_type(): void
    {
        $this->setUpOrg();
        Storage::fake('local');

        $data = $this->storeMaterial('Scoped Guide');
        $materialId = (int) $data['id'];

        $this->actAsAdmin();
        $response = $this->get(self::AUDIT_LOGS_URL . '?event_type=delete');
        $response->assertOk();

        $leaks = collect($response->json('data'))->filter(function (array $item) use ($materialId) {
            return $item['auditable_type'] === LearningMaterial::class
                && (int) $item['auditable_id'] === $materialId;
        });
        $this->assertCount(0, $leaks);
    }
}
