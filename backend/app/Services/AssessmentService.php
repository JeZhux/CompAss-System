<?php

namespace App\Services;

use App\Exceptions\BusinessRuleConflictException;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentAutoSave;
use App\Models\AssessmentItem;
use App\Models\AssessmentItemAttachment;
use App\Models\AssessmentResponse;
use App\Models\AssessmentSubmission;
use App\Models\Classroom;
use App\Models\ClassroomEnrollment;
use App\Models\CompetencyReference;
use App\Support\SafeUpload;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Creates, edits, releasing, and deleting Assessments. Item creation with
 * competency tagging. Time-limit and availability-window configuration.
 * Recording student responses. Auto-save. Pending Grading gate enforcement.
 * Results release. Delegates manual grading operations to GradingService. (ARCH-001 §5.1)
 *
 * Collaborators: CompetencyMappingService (invoked by submitAssessment for
 * objective-only Assessments; invoked by releaseResults for unmastered data),
 * GradingService (receives delegation for manual grade recording — inert until
 * Phase 4 per Resolution Log §15.3), OrgStructureService (reads
 * TeacherSectionAssignment for validation, ClassroomEnrollment for enrollment checks).
 *
 * Invariants (ARCH-001 §5.1):
 *  - releaseAssessment MUST block if Assessment has zero items (ARCH-002 FR-016).
 *  - Post-release structural edits MUST be blocked (ARCH-002 FR-016, ARCH-002 FR-016).
 *  - submitAssessment MUST NOT invoke CompetencyMappingService when status is
 *    pending_grading (ARCH-002 FR-018, ARCH-002 FR-019).
 *  - releaseResults MUST block when status is pending_grading (ARCH-002 FR-019, ARCH-002 FR-019).
 *  - Auto-save MUST NOT trigger submission logic (ARCH-002 QA-007).
 *  - Time-limit auto-submit scores unanswered items as blank/zero (ARCH-002 FR-017).
 *  - Availability window is start-gated only (ARCH-002 FR-017); time limit is continuously
 *    enforced — auto-save returns 409 TIME_LIMIT_EXPIRED, late hand-in zero-scores blanks (ARCH-002 FR-017).
 *
 * Phase 3 stubs: computeMastery() call sites in submitAssessment and
 * releaseResults have been replaced by CompetencyMappingService (Phase 5).
 * scoreSubjectiveItems() delegates authoritative write-through to
 * GradingService.recordManualGrade() (Resolution Log §15.3).
 *
 * @Traced-To ARCH-002 FR-015–ARCH-002 FR-016, ARCH-002 FR-018
 * @Traced-To ARCH-002 QA-001, ARCH-002 QA-007, ARCH-002 QA-007, ARCH-002 QA-007, ARCH-002 FR-015–ARCH-002 FR-016, ARCH-002 FR-021, ARCH-002 FR-019, ARCH-002 QA-007, ARCH-002 FR-018, ARCH-002 QA-007, ARCH-002 FR-017, ARCH-002 FR-017, ARCH-002 FR-016, ARCH-002 FR-018, ARCH-002 FR-021
 */
class AssessmentService
{
    private const ITEM_ATTACHMENT_DIR = 'assessment_items';

    public const MAX_ITEM_ATTACHMENTS = 5;

    public const MAX_FILE_SIZE = 15 * 1024 * 1024;

    /** @var string[] Allowed item-attachment extensions (ARCH-002 QA-009 / UC-27). */
    public const ALLOWED_EXTENSIONS = ['pdf', 'docx', 'pptx', 'xlsx', 'jpg', 'jpeg', 'png', 'zip'];

    /** @var string[] Allowed item-attachment MIME types, mirrored from ALLOWED_EXTENSIONS (ARCH-002 QA-009 / UC-27). */
    public const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'image/jpeg',
        'image/png',
        'application/zip',
    ];

    public function __construct(
        private readonly GradingService $gradingService,
        private readonly CompetencyMappingService $competencyMappingService,
        private readonly AuditLogService $auditLogService
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Teacher — Assessment CRUD
    |--------------------------------------------------------------------------
    */

    /**
     * Create an Assessment in draft status (ARCH-002 FR-015, ARCH-002 FR-015).
     *
     * @param  int  $subjectId
     * @param  string  $title
     * @param  string|null  $description
     * @param  string  $type  'Recorded' or 'Unrecorded' (ARCH-002 FR-015)
     * @param  int|null  $timeLimit  minutes (ARCH-002 FR-015)
     * @param  array{start: string|null, end: string|null}|null  $availabilityWindow
     *
     * @Traced-To ARCH-002 FR-015, ARCH-002 FR-015 (ARCH-001 §5.1)
     */
    /**
     * List the teacher's assessments with pagination and status filter (ARCH-002 FR-015).
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     *
     * @Traced-To ARCH-002 FR-015 (ARCH-005 block 4.12)
     */
    public function listAssessmentsForTeacher(int $teacherId, ?int $subjectId = null, ?string $status = null, int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        $query = Assessment::query()
            ->where('teacher_id', $teacherId)
            ->when($subjectId !== null, fn ($q) => $q->where('subject_id', $subjectId))
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->withCount('items')
            ->orderByDesc('created_at');

        $paginator = $query->paginate($perPage, page: $page);

        return $paginator->through(fn ($a) => [
            'id' => $a->id,
            'title' => $a->title,
            'type' => $a->type,
            'status' => $a->status,
            'item_count' => $a->items_count,
            'created_at' => $a->created_at?->toIso8601String(),
        ]);
    }

    /**
     * Get detailed assessment view for the teacher (ARCH-002 FR-015, ARCH-002 FR-015–ARCH-002 FR-018).
     *
     * @return array<string, mixed>
     *
     * @Traced-To ARCH-002 FR-015, ARCH-002 FR-015, ARCH-002 FR-015, ARCH-002 FR-015, ARCH-002 FR-018 (ARCH-005 block 4.12)
     */
    public function getAssessmentDetail(int $teacherId, int $assessmentId): array
    {
        $assessment = $this->findOwnedByTeacher($assessmentId, $teacherId);

        $assessment->load('items.attachments');

        return [
            'id' => $assessment->id,
            'title' => $assessment->title,
            'description' => $assessment->description,
            'type' => $assessment->type,
            'status' => $assessment->status,
            'time_limit' => $assessment->time_limit,
            'availability_starts_at' => $assessment->availability_starts_at?->toIso8601String(),
            'availability_ends_at' => $assessment->availability_ends_at?->toIso8601String(),
            'item_count' => $assessment->items->count(),
            'items' => $assessment->items->map(fn ($i) => [
                'id' => $i->id,
                'item_type' => $i->item_type,
                'prompt' => $i->prompt,
                'max_points' => (float) $i->max_points,
                'sort_order' => $i->sort_order,
                'competency_tag_id' => $i->competency_tag_id,
                'correct_answer' => $i->correct_answer,
                'has_attachments' => $i->attachments->isNotEmpty(),
            ]),
            'created_at' => $assessment->created_at?->toIso8601String(),
            'updated_at' => $assessment->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Update Assessment metadata (title, description). Adapter for controller (ARCH-002 FR-016, ARCH-002 FR-016).
     *
     * @Traced-To ARCH-002 FR-016, ARCH-002 FR-016, ARCH-002 FR-016 (ARCH-001 §5.1)
     */
    public function updateAssessment(int $teacherId, int $assessmentId, ?string $title, ?string $description): void
    {
        $this->updateAssessmentMetadata($teacherId, $assessmentId, $title, $description);
    }

    /**
     * Delete a draft Assessment. Adapter for controller (ARCH-002 FR-016).
     *
     * @Traced-To ARCH-002 FR-016 (ARCH-001 §5.1)
     */
    public function deleteDraftAssessment(int $teacherId, int $assessmentId): void
    {
        $this->deleteAssessment($teacherId, $assessmentId);
    }

    public function createAssessment(
        int $teacherId,
        int $subjectId,
        string $title,
        ?string $description,
        string $type,
        ?int $timeLimit = null,
        ?array $availabilityWindow = null
    ): Assessment {
        // U-04 — legacy flat create is closed: content must be created via the
        // classroom-scoped endpoint so every grade path carries a classroom.
        // Thrown before any file/DB write, so a 422 never orphans attachments.
        throw ValidationException::withMessages([
            'classroom_id' => ['The classroom_id field is required. Use the classroom-scoped endpoint.'],
        ]);
    }

    /**
     * U03 — Classroom-scoped create. Sets classroom_id, subject_id, and semester_id server-side.
     * Rejects mismatched client subject_id/semester_id with 422.
     */
    public function createAssessmentInClassroom(
        int $teacherId,
        int $classroomId,
        string $title,
        ?string $description,
        string $type,
        ?int $timeLimit = null,
        ?array $availabilityWindow = null,
        ?int $clientSubjectId = null,
        ?int $clientSemesterId = null
    ): Assessment {
        $classroom = Classroom::with(['subject.gradeLevel', 'section.gradeLevel.semester'])->findOrFail($classroomId);

        if ((int) $classroom->teacher_id !== $teacherId) {
            throw new BusinessRuleConflictException(
                'You do not own this classroom.',
                'FORBIDDEN',
                403
            );
        }

        if ($classroom->archived_at !== null) {
            throw new BusinessRuleConflictException(
                'This classroom has been archived.',
                'CLASSROOM_ARCHIVED',
                409
            );
        }

        if ($clientSubjectId !== null && (int) $clientSubjectId !== (int) $classroom->subject_id) {
            throw ValidationException::withMessages([
                'subject_id' => ['The subject_id does not match the classroom\'s subject.'],
            ]);
        }

        $gradeLevel = $classroom->section?->gradeLevel ?? $classroom->subject?->gradeLevel;

        // Stale-param guard: supplied semester_id/term_id must match the
        // classroom's Semester or it 422s (Semester canonical).
        if ($clientSemesterId !== null && $gradeLevel?->semester_id !== null
            && (int) $clientSemesterId !== (int) $gradeLevel->semester_id) {
            throw ValidationException::withMessages([
                'semester_id' => ['The semester_id does not match the classroom\'s semester.'],
            ]);
        }

        if (! in_array($type, ['Recorded', 'Unrecorded'], true)) {
            throw new BusinessRuleConflictException(
                'The assessment type must be "Recorded" or "Unrecorded".',
                'INVALID_TYPE'
            );
        }

        $assessment = Assessment::create([
            'teacher_id' => $teacherId,
            'classroom_id' => $classroomId,
            'subject_id' => $classroom->subject_id,
            'semester_id' => $gradeLevel?->semester_id,
            'title' => $title,
            'description' => $description,
            'type' => $type,
            'status' => 'draft',
            'time_limit' => $timeLimit,
            'availability_starts_at' => $availabilityWindow['start'] ?? null,
            'availability_ends_at' => $availabilityWindow['end'] ?? null,
        ]);

        $this->auditLogService->log(
            'create',
            'Assessment created (id ' . $assessment->id . ')',
            Auth::id(),
            Assessment::class,
            $assessment->id,
            ['teacher_id' => $teacherId, 'classroom_id' => $classroomId]
        );

        return $assessment;
    }

    /**
     * U03 — Teacher list scoped to a single classroom (paginated)
     */
    public function listAssessmentsForTeacherInClassroom(int $teacherId, int $classroomId, ?string $status = null, int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        $classroom = Classroom::findOrFail($classroomId);
        if ((int) $classroom->teacher_id !== $teacherId) {
            throw new BusinessRuleConflictException(
                'You do not own this classroom.',
                'FORBIDDEN',
                403
            );
        }

        $query = Assessment::query()
            ->where('teacher_id', $teacherId)
            ->where('classroom_id', $classroomId)
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->withCount('items')
            ->orderByDesc('created_at');

        $paginator = $query->paginate($perPage, page: $page);

        return $paginator->through(fn ($a) => [
            'id' => $a->id,
            'classroom_id' => $a->classroom_id,
            'title' => $a->title,
            'type' => $a->type,
            'status' => $a->status,
            'item_count' => $a->items_count,
            'created_at' => $a->created_at?->toIso8601String(),
        ]);
    }

    /**
     * U03 — Student list scoped to a single classroom (enrolled)
     *
     * @return array<int, array<string, mixed>>
     */
    public function listStudentAssessmentsInClassroom(int $studentId, int $classroomId): array
    {
        $classroom = Classroom::findOrFail($classroomId);
        if ($classroom->archived_at !== null) {
            throw new BusinessRuleConflictException(
                'This classroom has been archived.',
                'CLASSROOM_ARCHIVED',
                410
            );
        }
        $enrolled = ClassroomEnrollment::where('classroom_id', $classroomId)->where('student_id', $studentId)->exists();
        if (! $enrolled) {
            throw new BusinessRuleConflictException(
                'You are not enrolled in this classroom.',
                'NOT_ENROLLED',
                403
            );
        }

        $assessments = Assessment::query()
            ->where('status', 'released')
            ->where('classroom_id', $classroomId)
            ->withCount('items')
            ->get();

        $results = [];
        foreach ($assessments as $assessment) {
            $submission = AssessmentSubmission::query()
                ->whereHas('attempt', function ($q) use ($studentId, $assessment): void {
                    $q->where('assessment_id', $assessment->id)
                        ->where('student_id', $studentId);
                })
                ->latest()
                ->first();

            $status = 'available';
            if ($submission) {
                if ($submission->is_results_released) {
                    $status = 'results_released';
                } elseif ($submission->status === 'scored') {
                    $status = 'results_pending';
                } else {
                    $status = 'taken';
                }
            }

            $results[] = [
                'id' => $assessment->id,
                'classroom_id' => $assessment->classroom_id,
                'title' => $assessment->title,
                'type' => $assessment->type,
                'status' => $status,
                'item_count' => $assessment->items_count,
                'time_limit' => $assessment->time_limit,
                'created_at' => $assessment->created_at?->toIso8601String(),
                'due_date' => null,
            ];
        }

        return $results;
    }

    /**
     * List the authenticated teacher's assessments (ARCH-002 FR-015).
     *
     * @return LengthAwarePaginator<int, Assessment>
     *
     * @Traced-To ARCH-002 FR-015 (ARCH-005 block 4.12)
     */
    public function listAssessments(int $teacherId, ?int $subjectId, ?int $sectionId, ?string $status, int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        $query = Assessment::query()
            ->where('teacher_id', $teacherId)
            ->withCount('items');

        if ($subjectId !== null) {
            $query->where('subject_id', $subjectId);
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->orderByDesc('created_at')->paginate($perPage, page: $page);
    }

    /**
     * Get a single assessment with its items (ARCH-002 FR-015, ARCH-002 FR-015–ARCH-002 FR-018).
     *
     * @Traced-To ARCH-002 FR-015, ARCH-002 FR-015, ARCH-002 FR-015, ARCH-002 FR-015, ARCH-002 FR-018 (ARCH-005 block 4.12)
     */
    public function getAssessment(int $teacherId, int $assessmentId): Assessment
    {
        $assessment = Assessment::query()
            ->where('id', $assessmentId)
            ->where('teacher_id', $teacherId)
            ->firstOrFail();

        return $assessment->load('items.attachments', 'itemAttachments');
    }

    /**
     * Update metadata (title, description) on an Assessment.
     * Post-release structural edits are blocked (ARCH-002 FR-016, ARCH-002 FR-016).
     *
     * @Traced-To ARCH-002 FR-016, ARCH-002 FR-016, ARCH-002 FR-016 (ARCH-001 §5.1)
     */
    public function updateAssessmentMetadata(int $teacherId, int $assessmentId, ?string $title, ?string $description): void
    {
        $assessment = $this->findOwnedByTeacher($assessmentId, $teacherId);

        if ($assessment->status === 'released') {
            // Post-release, title and description ARE editable (ARCH-002 FR-016).
            // Only structural edits (items, points, competency tags) are blocked
            // and handled at the item endpoints via POST_RELEASE_STRUCTURAL_EDIT_BLOCKED.
            // This method only touches title and description, so no block is needed here.
        }

        if ($title !== null) {
            $assessment->title = $title;
        }

        if ($description !== null) {
            $assessment->description = $description;
        }

        $assessment->save();

        // Audit trail (ARCH-001 §5.1): sensitive-entity CRUD update.
        $this->auditLogService->log(
            'update',
            'Assessment updated (id ' . $assessment->id . ')',
            Auth::id(),
            Assessment::class,
            $assessment->id,
            ['teacher_id' => $teacherId]
        );
    }

    /**
     * Delete a draft Assessment (ARCH-002 FR-016). Blocked if released.
     *
     * @Traced-To ARCH-002 FR-016 (ARCH-001 §5.1)
     */
    public function deleteAssessment(int $teacherId, int $assessmentId): void
    {
        $assessment = $this->findOwnedByTeacher($assessmentId, $teacherId);

        if ($assessment->status !== 'draft') {
            throw new BusinessRuleConflictException(
                'Only draft assessments can be deleted.',
                'ASSESSMENT_RELEASED'
            );
        }

        // Draft assessments have no submissions (items are restricted from delete
        // on release), so cascade is safe at the DB level via RESTRICT FK.
        // We still delete attachments from disk.
        DB::transaction(function () use ($assessment): void {
            foreach ($assessment->items as $item) {
                foreach ($item->attachments as $attachment) {
                    if (! SafeUpload::isConfined($attachment->filename, self::ITEM_ATTACHMENT_DIR)) {
                        Log::warning('Assessment delete skipped out-of-directory file key.', [
                            'path' => SafeUpload::loggable($attachment->filename),
                        ]);
                    } else {
                        SafeUpload::disk()->delete($attachment->filename);
                    }
                }
            }

            $assessment->delete();
        });

        // Audit trail (ARCH-001 §5.1): sensitive-entity CRUD delete.
        $this->auditLogService->log(
            'delete',
            'Assessment deleted (id ' . $assessmentId . ')',
            Auth::id(),
            Assessment::class,
            $assessmentId,
            ['teacher_id' => $teacherId]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Teacher — Assessment Items
    |--------------------------------------------------------------------------
    */

    /**
     * Add an item to a draft Assessment (ARCH-002 FR-015, ARCH-002 FR-015, ARCH-002 FR-015).
     *
     * @param  string  $itemType  'multiple_choice', 'true_false', or 'essay'
     * @param  string|null  $correctAnswer  required for objective items
     * @param  int  $competencyTagId  FK → competency_reference (ARCH-002 FR-015)
     * @param  UploadedFile[]|null  $attachments
     *
     * @Traced-To ARCH-002 FR-015, ARCH-002 FR-015, ARCH-002 FR-015, ARCH-002 FR-018 (ARCH-001 §5.1)
     */
    public function addItem(
        int $teacherId,
        int $assessmentId,
        string $itemType,
        string $prompt,
        float $maxPoints,
        ?string $correctAnswer,
        int $competencyTagId,
        ?array $attachments = []
    ): AssessmentItem {
        $assessment = $this->findDraftOwnedByTeacher($assessmentId, $teacherId);

        if (! in_array($itemType, ['multiple_choice', 'true_false', 'essay'], true)) {
            throw new BusinessRuleConflictException(
                'Invalid item type.',
                'INVALID_ITEM_TYPE'
            );
        }

        $isObjective = in_array($itemType, ['multiple_choice', 'true_false'], true);

        if ($isObjective && $correctAnswer === null) {
            throw new BusinessRuleConflictException(
                'Objective items require a correct_answer.',
                'MISSING_CORRECT_ANSWER'
            );
        }

        // ARCH-002 FR-015: max_points must be finite and positive. The FormRequest
        // gates the HTTP path (min:0.01); this service check is the gate for
        // direct calls, where INF/NAN would otherwise corrupt the numeric(8,2)
        // column or fail at the DB layer instead of a 422.
        if (! is_finite($maxPoints) || $maxPoints <= 0) {
            throw ValidationException::withMessages([
                'max_points' => ['The max_points must be a finite number greater than 0.'],
            ]);
        }

        // ARCH-002 FR-015: every item must reference a valid competency tag.
        $competency = CompetencyReference::findOrFail($competencyTagId);

        // Teacher = Semester + Grade Level + Subject package → Classroom →
        // filtered competencies. The tag must match the classroom's
        // subject + grade level + semester triple, else 422 COMPETENCY_MISMATCH.
        $this->assertCompetencyMatchesAssessment($assessment, $competency);

        $attachments = $attachments ?? [];
        if (count($attachments) > self::MAX_ITEM_ATTACHMENTS) {
            throw new BusinessRuleConflictException(
                'A maximum of ' . self::MAX_ITEM_ATTACHMENTS . ' attachments is allowed per item.',
                'TOO_MANY_ATTACHMENTS'
            );
        }
        foreach ($attachments as $file) {
            $this->assertAllowedFileType($file);

            if ($file->getSize() > self::MAX_FILE_SIZE) {
                throw new BusinessRuleConflictException(
                    'Each attachment must be at most 15 MB.',
                    'FILE_TOO_LARGE'
                );
            }
        }

        $sortOrder = AssessmentItem::where('assessment_id', $assessmentId)->max('sort_order') + 1;

        return DB::transaction(function () use ($assessmentId, $itemType, $prompt, $maxPoints, $correctAnswer, $competencyTagId, $sortOrder, $attachments): AssessmentItem {
            $item = AssessmentItem::create([
                'assessment_id' => $assessmentId,
                'item_type' => $itemType,
                'prompt' => $prompt,
                'max_points' => $maxPoints,
                'correct_answer' => $correctAnswer,
                'competency_tag_id' => $competencyTagId,
                'sort_order' => $sortOrder,
            ]);

            if (! empty($attachments)) {
                $this->storeItemAttachments($item, $attachments);
            }

            return $item;
        });
    }

    /**
     * Update an Assessment item (pre-release only — draft status required).
     *
     * @Traced-To ARCH-002 FR-018, ARCH-002 FR-016, ARCH-002 FR-016 (ARCH-001 §5.1)
     */
    public function updateItem(int $teacherId, int $itemId, ?string $itemType, ?string $prompt, ?float $maxPoints, ?string $correctAnswer, ?int $competencyTagId): void
    {
        $item = $this->findItemOwnedByTeacher($itemId, $teacherId);
        $assessment = $item->assessment;

        // Only draft assessments can be structurally edited (ARCH-002 FR-016, ARCH-002 FR-016).
        if ($assessment->status !== 'draft') {
            throw new BusinessRuleConflictException(
                'Cannot edit items of a released assessment.',
                'POST_RELEASE_STRUCTURAL_EDIT_BLOCKED'
            );
        }

        // ARCH-002 FR-015: item_type allow-list enforced in the service (the
        // FormRequest gates the HTTP path; direct calls must not reach the
        // Postgres enum with an invalid value).
        if ($itemType !== null && ! in_array($itemType, ['multiple_choice', 'true_false', 'essay'], true)) {
            throw ValidationException::withMessages([
                'item_type' => ['The selected item_type is invalid.'],
            ]);
        }

        // ARCH-002 FR-015: max_points must stay finite and positive.
        if ($maxPoints !== null && (! is_finite($maxPoints) || $maxPoints <= 0)) {
            throw ValidationException::withMessages([
                'max_points' => ['The max_points must be a finite number greater than 0.'],
            ]);
        }

        // ARCH-002 FR-015: essay items must not carry a correct_answer — an explicit
        // one in the payload is rejected (never silently dropped).
        if ($itemType === 'essay' && $correctAnswer !== null) {
            throw ValidationException::withMessages([
                'correct_answer' => ['An essay item cannot have a correct_answer.'],
            ]);
        }

        // ARCH-002 FR-015: a type switch re-validates the correct_answer invariant —
        // objective items need one (a null correct_answer is a silent
        // corruption risk for auto-scoring), and essay items must not carry one.
        $effectiveType = $itemType ?? $item->item_type;
        $effectiveAnswer = $correctAnswer !== null ? $correctAnswer : $item->correct_answer;

        if (
            in_array($effectiveType, ['multiple_choice', 'true_false'], true)
            && ($effectiveAnswer === null || trim($effectiveAnswer) === '')
        ) {
            throw ValidationException::withMessages([
                'correct_answer' => ['Objective items require a correct_answer.'],
            ]);
        }

        if ($itemType !== null) {
            $item->item_type = $itemType;
        }

        if ($prompt !== null) {
            $item->prompt = $prompt;
        }

        if ($maxPoints !== null) {
            $item->max_points = $maxPoints;
        }

        if ($correctAnswer !== null) {
            $item->correct_answer = $correctAnswer;
        }

        if ($competencyTagId !== null) {
            $competency = CompetencyReference::findOrFail($competencyTagId);

            $this->assertCompetencyMatchesAssessment($assessment, $competency);

            $item->competency_tag_id = $competencyTagId;
        }

        // ARCH-002 FR-015: essay invariant — a switched-to-essay item must not retain
        // a correct_answer (auto-scoring must never see one for an essay).
        if ($item->item_type === 'essay') {
            $item->correct_answer = null;
        }

        $item->save();
    }

    /**
     * Delete an Assessment item (draft only — ARCH-002 FR-016).
     *
     * @Traced-To ARCH-002 FR-016 (ARCH-001 §5.1)
     */
    public function deleteItem(int $teacherId, int $itemId): void
    {
        $item = $this->findItemOwnedByTeacher($itemId, $teacherId);

        if ($item->assessment->status !== 'draft') {
            throw new BusinessRuleConflictException(
                'Cannot delete items of a released assessment.',
                'POST_RELEASE_STRUCTURAL_EDIT_BLOCKED'
            );
        }

        DB::transaction(function () use ($item): void {
            foreach ($item->attachments as $attachment) {
                if (! SafeUpload::isConfined($attachment->filename, self::ITEM_ATTACHMENT_DIR)) {
                    Log::warning('Assessment item delete skipped out-of-directory file key.', [
                        'path' => SafeUpload::loggable($attachment->filename),
                    ]);
                } else {
                    SafeUpload::disk()->delete($attachment->filename);
                }
            }

            $item->delete();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Teacher — Assessment Flow
    |--------------------------------------------------------------------------
    */

    /**
     * Publish a draft Assessment to students (ARCH-002 FR-016).
     * Blocks release if the Assessment has zero items (ARCH-002 FR-016).
     *
     * @Traced-To ARCH-002 FR-016, ARCH-002 FR-016 (ARCH-001 §5.1)
     */
    public function releaseAssessment(int $teacherId, int $assessmentId): void
    {
        $assessment = $this->findOwnedByTeacher($assessmentId, $teacherId);

        if ($assessment->status !== 'draft') {
            throw new BusinessRuleConflictException(
                'This assessment has already been released.',
                'ASSESSMENT_ALREADY_RELEASED'
            );
        }

        $itemCount = $assessment->items()->count();

        if ($itemCount === 0) {
            throw new BusinessRuleConflictException(
                'An assessment with no items cannot be released.',
                'ASSESSMENT_HAS_NO_ITEMS'
            );
        }

        $assessment->status = 'released';
        $assessment->save();
    }

    /*
    |--------------------------------------------------------------------------
    | Teacher — Pending Grading (built Phase 3, inert until Phase 4 for #64)
    |--------------------------------------------------------------------------
    */

    /**
     * List submissions pending grading for the teacher's assessments (ARCH-002 FR-018, ARCH-002 FR-018).
     *
     * @return LengthAwarePaginator<int, AssessmentSubmission>
     *
     * @Traced-To ARCH-002 FR-018, ARCH-002 FR-018, ARCH-002 FR-018, ARCH-002 FR-019 (ARCH-005 block 4.4)
     */
    public function pendingGrading(int $teacherId, ?int $assessmentId, int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        $query = AssessmentSubmission::query()
            ->where('status', 'pending_grading')
            ->whereHas('assessment', function ($q) use ($teacherId, $assessmentId): void {
                $q->where('teacher_id', $teacherId);

                if ($assessmentId !== null) {
                    $q->where('id', $assessmentId);
                }
            })
            ->with(['student', 'assessment'])
            ->orderByDesc('created_at');

        return $query->paginate($perPage, page: $page);
    }

    /**
     * Get a submission's unscored subjective items (ARCH-002 FR-018, ARCH-002 FR-018).
     *
     * @return array<string, mixed>
     *
     * @Traced-To ARCH-002 FR-018, ARCH-002 FR-018, ARCH-002 FR-018 (ARCH-005 block 4.4)
     */
    public function getPendingItems(int $teacherId, int $submissionId): array
    {
        $submission = $this->findSubmissionForTeacher($teacherId, $submissionId);

        $unscaled = AssessmentResponse::query()
            ->where('submission_id', $submission->id)
            ->where('is_auto_scored', false)
            ->whereNull('earned_points')
            ->with('item')
            ->get();

        $items = $unscaled->map(function (AssessmentResponse $response): array {
            $item = $response->item;

            return [
                'item_id' => $item->id,
                'item_type' => $item->item_type,
                'question_text' => $item->prompt ?? '',
                'max_points' => (float) $item->max_points,
                'student_response' => $response->response_text,
                'competency_tag_id' => $item->competency_tag_id,
            ];
        })->values();

        return [
            'submission_id' => $submission->id,
            'assessment_id' => $submission->assessment_id,
            'attempt_id' => $submission->attempt_id,
            'status' => $submission->status,
            'pending_items' => $items,
        ];
    }

    /**
     * Orchestration entry point for teacher manual grading (ARCH-002 FR-018).
     * Validates teacher ownership (ARCH-002 QA-004) and item-level invariants
     * (NOT_A_SUBJECTIVE_ITEM, SCORE_EXCEEDS_MAXIMUM, NOT_PENDING_GRADING),
     * then delegates the authoritative write-through to
     * GradingService.recordManualGrade() (Resolution Log §15.3).
     *
     * @param  array<int, float>  $scores  Keyed by item_id, value is the score.
     *
     * @Traced-To ARCH-002 FR-018, ARCH-002 QA-004 (ARCH-002 FR-019 exit path, ARCH-001 §5.1)
     */
    public function scoreSubjectiveItems(int $teacherId, int $submissionId, array $scores): array
    {
        $submission = $this->findSubmissionForTeacher($teacherId, $submissionId);

        if ($submission->status !== 'pending_grading') {
            throw new BusinessRuleConflictException(
                'Only submissions in pending_grading status can be scored.',
                'NOT_PENDING_GRADING'
            );
        }

        // Validate each scored item: must be subjective, score <= max_score.
        $questionGrades = [];
        foreach ($scores as $itemId => $score) {
            $item = AssessmentItem::findOrFail((int) $itemId);

            if (! in_array($item->item_type, ['essay'], true)) {
                throw new BusinessRuleConflictException(
                    'Only subjective (essay) items can be scored via this endpoint; item ' . $item->id . ' is objective.',
                    'NOT_A_SUBJECTIVE_ITEM'
                );
            }

            if ((float) $score > (float) $item->max_points) {
                throw new BusinessRuleConflictException(
                    'Score for item ' . $item->id . ' exceeds the maximum of ' . $item->max_points . '.',
                    'SCORE_EXCEEDS_MAXIMUM'
                );
            }

            $questionGrades[] = [
                'questionId' => (int) $itemId,
                'score' => (float) $score,
                'maxScore' => (float) $item->max_points,
                'feedback' => null,
            ];
        }

        // Resolve submission → attempt (UC-50).
        $attempt = AssessmentAttempt::query()
            ->where('id', $submission->attempt_id)
            ->firstOrFail();

        // Delegate authoritative write-through to GradingService (Resolution Log §15.3).
        $result = $this->gradingService->recordManualGrade(
            $attempt->id,
            $questionGrades,
            $teacherId
        );

        if ($result['status'] === 'scored') {
            return [
                'message' => 'Scores recorded.',
                'status' => 'scored',
                'mastery_results' => $result['mastery_results'],
            ];
        }

        return [
            'message' => 'Partial scores recorded.',
            'status' => 'pending_grading',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Teacher — Results Release
    |--------------------------------------------------------------------------
    */

    /**
     * Release results to students (ARCH-002 FR-019, ARCH-002 FR-024).
     * Blocks release if the Assessment is still in Pending Grading (ARCH-002 FR-019, ARCH-002 FR-019).
     * Fires an AuditLogService release_results event on success (ARCH-001 §5.1).
     * AI explanations are generated ONLY on the student's explicit POST
     * generate request, after results are released (ARCH-002 FR-024): release itself
     * triggers nothing — it persists the released-at timestamp and returns it
     * as the only release-time artifact; no batch AI trigger, no
     * ai_explanations_triggered semantics (ARCH-005 block 4.5).
     *
     * @Traced-To ARCH-002 FR-019, ARCH-002 FR-019, ARCH-002 FR-024, ARCH-002 QA-006 (ARCH-005 block 4.5); ARCH-005 block 4.5, ARCH-002 FR-024
     */
    public function releaseResults(int $teacherId, int $assessmentId): array
    {
        $assessment = $this->findOwnedByTeacher($assessmentId, $teacherId);

        if ($assessment->status !== 'released') {
            throw new BusinessRuleConflictException(
                'Only released assessments can have results released.',
                'ASSESSMENT_NOT_RELEASED'
            );
        }

        // ARCH-002 FR-019 / ARCH-002 FR-019: block if any submission is still in pending_grading.
        $pendingCount = AssessmentSubmission::query()
            ->where('assessment_id', $assessmentId)
            ->where('status', 'pending_grading')
            ->count();

        if ($pendingCount > 0) {
            throw new BusinessRuleConflictException(
                'Results cannot be released while submissions are pending grading.',
                'PENDING_GRADING_BLOCK'
            );
        }

        // All submissions are scored — release results. The timestamp persisted
        // on assessment_submissions.results_released_at is the service-authored
        // release time returned to the caller (ARCH-005 block 4.5 — no fabricated now()).
        $releasedCount = DB::transaction(function () use ($assessmentId): int {
            return AssessmentSubmission::query()
                ->where('assessment_id', $assessmentId)
                ->update([
                    'is_results_released' => true,
                    'results_released_at' => now(),
                ]);
        });

        // Phase 5: query real unmastered competencies from mastery records —
        // the AI-eligibility read retained under ARCH-002 FR-024 (generation itself
        // runs only on the student's explicit generate request, never here).
        $unmastered = $this->getUnmasteredStudentCompetencies($assessmentId);

        // Audit trail (ARCH-001 §5.1): release_results.
        $this->auditLogService->log(
            'release_results',
            'Assessment results released (id ' . $assessmentId . ')',
            Auth::id(),
            Assessment::class,
            $assessmentId,
            ['teacher_id' => $teacherId, 'submissions_released' => $releasedCount]
        );

        return [
            'unmastered_competencies' => $unmastered,
            'results_released_at' => Carbon::parse(
                AssessmentSubmission::query()
                    ->where('assessment_id', $assessmentId)
                    ->max('results_released_at')
            )->toIso8601String(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Student — Assessment Taking
    |--------------------------------------------------------------------------
    */

    /**
     * List assessments available to the student (ARCH-002 FR-017).
     * Classroom-only: released assessments in the student's active (non-archived)
     * classrooms via ClassroomEnrollment.
     *
     * @return array<int, array<string, mixed>>
     *
     * @Traced-To ARCH-002 FR-017, ARCH-002 FR-017 (ARCH-005 block 4.4)
     */
    public function listStudentAssessments(int $studentId): array
    {
        $activeClassroomIds = Classroom::query()
            ->whereIn('id', ClassroomEnrollment::where('student_id', $studentId)->pluck('classroom_id'))
            ->whereNull('archived_at')
            ->pluck('id')
            ->toArray();

        if (empty($activeClassroomIds)) {
            return [];
        }

        $assessments = Assessment::query()
            ->where('status', 'released')
            ->whereIn('classroom_id', $activeClassroomIds)
            ->withCount('items')
            ->get();

        if ($assessments->isEmpty()) {
            return [];
        }

        $results = [];

        foreach ($assessments as $assessment) {
            $submission = AssessmentSubmission::query()
                ->whereHas('attempt', function ($q) use ($studentId, $assessment): void {
                    $q->where('assessment_id', $assessment->id)
                        ->where('student_id', $studentId);
                })
                ->latest()
                ->first();

            $status = 'available';

            if ($submission) {
                if ($submission->is_results_released) {
                    $status = 'results_released';
                } elseif ($submission->status === 'scored') {
                    $status = 'results_pending';
                } else {
                    $status = 'taken';
                }
            }

            $results[] = [
                'id' => $assessment->id,
                'classroom_id' => $assessment->classroom_id,
                'title' => $assessment->title,
                'type' => $assessment->type,
                'status' => $status,
                'item_count' => $assessment->items_count,
                'time_limit' => $assessment->time_limit,
            ];
        }

        return $results;
    }

    /**
     * Get assessment details for a student before starting (ARCH-002 FR-017).
     * Returns metadata + item count only, no item content.
     *
     * @Traced-To ARCH-002 FR-017 (ARCH-005 block 4.4)
     */
    public function getStudentAssessment(int $studentId, int $assessmentId): array
    {
        $assessment = Assessment::query()
            ->where('id', $assessmentId)
            ->where('status', 'released')
            ->firstOrFail();

        $this->ensureStudentCanAccessAssessment($studentId, $assessment);

        $assessment->loadCount('items');

        return [
            'id' => $assessment->id,
            'title' => $assessment->title,
            'description' => $assessment->description,
            'type' => $assessment->type,
            'time_limit' => $assessment->time_limit,
            'availability_starts_at' => $assessment->availability_starts_at?->toIso8601String(),
            'availability_ends_at' => $assessment->availability_ends_at?->toIso8601String(),
            'item_count' => $assessment->items_count,
            'created_at' => $assessment->created_at?->toIso8601String(),
            'updated_at' => $assessment->updated_at?->toIso8601String(),
        ];
    }

    private function ensureStudentCanAccessAssessment(int $studentId, Assessment $assessment): void
    {
        if ($assessment->classroom_id === null) {
            throw new BusinessRuleConflictException(
                'You are not enrolled in this classroom.',
                'NOT_ENROLLED',
                403
            );
        }

        $classroom = Classroom::findOrFail($assessment->classroom_id);

        if ($classroom->archived_at !== null) {
            throw new BusinessRuleConflictException(
                'This classroom has been archived.',
                'CLASSROOM_ARCHIVED',
                410
            );
        }

        $enrolled = ClassroomEnrollment::where('classroom_id', $assessment->classroom_id)
            ->where('student_id', $studentId)
            ->exists();

        if (! $enrolled) {
            throw new BusinessRuleConflictException(
                'You are not enrolled in this classroom.',
                'NOT_ENROLLED',
                403
            );
        }
    }

    /**
     * Begin an Assessment session for a student (ARCH-002 FR-017, ARCH-002 FR-017, ARCH-002 FR-017).
     * Temporal rules: availability window is start-gated only (ARCH-002 FR-017); time limit
     * is continuously enforced — auto-save returns 409 TIME_LIMIT_EXPIRED, late
     * hand-in zero-scores blanks (ARCH-002 FR-017).
     * Returns Assessment items for IAE rendering; correct_answer NOT included.
     *
     * @return array<string, mixed>
     *
     * @Traced-To ARCH-002 FR-017, ARCH-002 FR-017, ARCH-002 FR-017 (ARCH-001 §5.1)
     */
    public function startAssessment(int $studentId, int $assessmentId): array
    {
        $assessment = Assessment::query()
            ->where('id', $assessmentId)
            ->where('status', 'released')
            ->firstOrFail();

        $this->ensureStudentCanAccessAssessment($studentId, $assessment);

        // Temporal rules: ARCH-002 FR-017 availability window is start-gated only; ARCH-002 FR-017 time
        // limit is continuously enforced (auto-save 409 TIME_LIMIT_EXPIRED,
        // late hand-in zero-scores blanks).
        if ($assessment->availability_ends_at !== null && now()->gt($assessment->availability_ends_at)) {
            throw new BusinessRuleConflictException(
                'The assessment availability window has closed.',
                'ASSESSMENT_NOT_AVAILABLE'
            );
        }

        if ($assessment->availability_starts_at !== null && now()->lt($assessment->availability_starts_at)) {
            throw new BusinessRuleConflictException(
                'The assessment is not yet available.',
                'ASSESSMENT_NOT_AVAILABLE'
            );
        }

        // Check if already submitted — ALREADY_SUBMITTED (ARCH-002 FR-018).
        $existing = AssessmentSubmission::query()
            ->whereHas('attempt', function ($q) use ($assessmentId, $studentId): void {
                $q->where('assessment_id', $assessmentId)
                    ->where('student_id', $studentId);
            })
            ->first();

        if ($existing) {
            // Resubmission admission (ARCH-002 FR-018): the teacher's
            // GradingService::requestResubmission created an in_progress
            // resubmission attempt. The student's start is admitted and
            // returns that attempt itself — no orphan attempt is created.
            $resubmissionAttempt = AssessmentAttempt::query()
                ->where('assessment_id', $assessmentId)
                ->where('student_id', $studentId)
                ->where('is_resubmission', true)
                ->where('status', 'in_progress')
                ->latest('id')
                ->first();

            if ($resubmissionAttempt === null) {
                throw new BusinessRuleConflictException(
                    'You have already submitted this assessment.',
                    'ALREADY_SUBMITTED'
                );
            }

            return $this->buildStartResponse($assessment, $resubmissionAttempt);
        }

        // One active attempt at a time (ARCH-002 FR-017): a second start while an
        // attempt is in_progress must not orphan the first attempt. The
        // client resumes by holding the attempt_id from the first start.
        $activeAttempt = AssessmentAttempt::query()
            ->where('assessment_id', $assessmentId)
            ->where('student_id', $studentId)
            ->where('status', 'in_progress')
            ->latest('id')
            ->first();

        if ($activeAttempt !== null) {
            throw new BusinessRuleConflictException(
                'An attempt is already in progress for this assessment.',
                'STUDENT_HAS_ACTIVE_ATTEMPT'
            );
        }

        // Determine the attempt number (incremental).
        $attemptNumber = (int) AssessmentAttempt::query()
            ->where('assessment_id', $assessmentId)
            ->where('student_id', $studentId)
            ->max('attempt_number') + 1;

        $attempt = AssessmentAttempt::create([
            'assessment_id' => $assessmentId,
            'student_id' => $studentId,
            'attempt_number' => $attemptNumber,
            'status' => 'in_progress',
            'started_at' => now(),
            'response_history' => [],
        ]);

        return $this->buildStartResponse($assessment, $attempt);
    }

    /**
     * Build the start payload: items WITHOUT correct_answer (ARCH-002 FR-017) plus
     * resumption pre-fill. Records started_at on first admission (ARCH-004 §4.1).
     *
     * @return array<string, mixed>
     */
    private function buildStartResponse(Assessment $assessment, AssessmentAttempt $attempt): array
    {
        if ($attempt->started_at === null) {
            $attempt->started_at = now();
            $attempt->save();
        }

        // Load items WITHOUT correct_answer (ARCH-002 FR-017 — never sent to client).
        $items = $assessment->items()
            ->orderBy('sort_order')
            ->get()
            ->map(fn (AssessmentItem $i) => [
                'id' => $i->id,
                'item_type' => $i->item_type,
                'prompt' => $i->prompt,
                'max_points' => (float) $i->max_points,
                'sort_order' => $i->sort_order,
            ]);

        return [
            'assessment_id' => $assessment->id,
            'title' => $assessment->title,
            'time_limit' => $assessment->time_limit,
            'attempt_id' => $attempt->id,
            'attempt_number' => (int) $attempt->attempt_number,
            'items' => $items,
            'responses' => $this->getResubmissionPrefillResponses($attempt),
        ];
    }

    /**
     * Pre-fill responses for a resubmission start (ARCH-002 FR-017): the attempt's
     * own autosave row wins; otherwise fall back to the previous attempt's
     * autosave row. Normally empty, because submit cleans autosaves.
     *
     * @return array<int|string, string>
     */
    private function getResubmissionPrefillResponses(AssessmentAttempt $attempt): array
    {
        if (! $attempt->is_resubmission) {
            return [];
        }

        $ownSave = AssessmentAutoSave::query()
            ->where('student_id', $attempt->student_id)
            ->where('assessment_id', $attempt->assessment_id)
            ->where('attempt_id', $attempt->id)
            ->first();

        if ($ownSave !== null) {
            return $ownSave->responses ?? [];
        }

        if ($attempt->resubmission_of_attempt_id === null) {
            return [];
        }

        $previousSave = AssessmentAutoSave::query()
            ->where('student_id', $attempt->student_id)
            ->where('assessment_id', $attempt->assessment_id)
            ->where('attempt_id', $attempt->resubmission_of_attempt_id)
            ->first();

        return $previousSave ? ($previousSave->responses ?? []) : [];
    }

    /**
     * Auto-save in-progress responses (ARCH-002 QA-007, ARCH-002 QA-007). UPSERT. Does NOT trigger submission.
     *
     * @param  array<int|string, string>  $responses  map of item_id => answer
     *
     * @Traced-To ARCH-002 QA-007, ARCH-002 QA-007 (ARCH-001 §5.1)
     */
    public function autoSave(int $studentId, int $assessmentId, array $responses): void
    {
        $assessment = Assessment::findOrFail($assessmentId);
        $this->ensureStudentCanAccessAssessment($studentId, $assessment);

        $attempt = $this->findActiveAttempt($studentId, $assessmentId);

        if ($attempt->status !== 'in_progress') {
            throw new BusinessRuleConflictException(
                'Cannot auto-save for a submission that has already been submitted.',
                'ALREADY_SUBMITTED'
            );
        }

        // ARCH-004 §4.1: after the time limit, auto-save is a hard 409; the
        // submission must happen via the late-submit auto-submit path.
        if ($this->attemptExpired($attempt)) {
            throw new BusinessRuleConflictException(
                'The assessment time limit has expired.',
                'TIME_LIMIT_EXPIRED'
            );
        }

        // ARCH-002 FR-017: autosave rows are keyed per attempt — a resubmission
        // attempt must never overwrite the original attempt's autosave.
        AssessmentAutoSave::updateOrCreate(
            ['student_id' => $studentId, 'assessment_id' => $assessmentId, 'attempt_id' => $attempt->id],
            ['responses' => $responses]
        );
    }

    /**
     * Update a single response during an active attempt (ARCH-002 FR-017).
     * Appends the change to the attempt's response_history (ARCH-002 FR-018).
     * Does NOT trigger auto-save or submission.
     *
     * @Traced-To ARCH-002 FR-017 (ARCH-001 §5.1)
     */
    public function updateAttemptResponse(int $studentId, int $attemptId, int $questionId, string $response): void
    {
        $attempt = AssessmentAttempt::query()
            ->where('id', $attemptId)
            ->where('student_id', $studentId)
            ->firstOrFail();

        $this->ensureStudentCanAccessAssessment($studentId, Assessment::findOrFail($attempt->assessment_id));

        if ($attempt->status !== 'in_progress') {
            throw new BusinessRuleConflictException(
                'Cannot update responses for a submitted attempt.',
                'ALREADY_SUBMITTED'
            );
        }

        // ARCH-004 §4.1: after the time limit, response updates are a hard 409.
        if ($this->attemptExpired($attempt)) {
            throw new BusinessRuleConflictException(
                'The assessment time limit has expired.',
                'TIME_LIMIT_EXPIRED'
            );
        }

        // Append to response_history (ARCH-002 FR-018 / ARCH-002 FR-017 change log).
        $history = $attempt->response_history ?? [];

        $priorValue = $history ? collect($history)->where('question_id', $questionId)->last()['new_value'] ?? null : null;

        $history[] = [
            'timestamp' => now()->toISOString(),
            'question_id' => $questionId,
            'priorValue' => $priorValue,
            'new_value' => $response,
        ];

        $attempt->response_history = $history;
        $attempt->save();
    }

    /**
     * Submit an Assessment (ARCH-002 FR-017, ARCH-002 FR-018, ARCH-002 FR-019, ARCH-002 FR-017).
     * Objective-only → auto-score + stubbed computeMastery → scored.
     * Subjective → pending_grading, no mastery computation.
     *
     * @return array{status: string, mastery_results?: array<string, mixed>}
     *
     * @Traced-To ARCH-002 FR-017, ARCH-002 FR-018, ARCH-002 FR-019, ARCH-002 FR-017, ARCH-003 ADR-004 (ARCH-002 FR-019)
     */
    public function submitAssessment(int $studentId, int $assessmentId, ?array $responses = []): array
    {
        $assessment = Assessment::findOrFail($assessmentId);
        $this->ensureStudentCanAccessAssessment($studentId, $assessment);

        try {
            $attempt = $this->findActiveAttempt($studentId, $assessmentId);
        } catch (ModelNotFoundException $e) {
            // F-11: sequential retry after success must degrade to the
            // documented ALREADY_SUBMITTED outcome (not 404) when a
            // submission already exists. A never-started assessment still
            // 404s. Only IDs are logged, never response content.
            $already = AssessmentSubmission::query()
                ->whereHas('attempt', function ($q) use ($assessmentId, $studentId): void {
                    $q->where('assessment_id', $assessmentId)
                        ->where('student_id', $studentId);
                })
                ->exists();

            if (! $already) {
                throw $e;
            }

            Log::warning('AssessmentService::submitAssessment duplicate suppressed', [
                'assessment_id' => $assessmentId,
                'student_id' => $studentId,
            ]);

            throw new BusinessRuleConflictException(
                'This assessment has already been submitted.',
                'ALREADY_SUBMITTED'
            );
        }

        if ($attempt->status !== 'in_progress') {
            throw new BusinessRuleConflictException(
                'This assessment has already been submitted.',
                'ALREADY_SUBMITTED'
            );
        }

        // ARCH-004 §4.1: a late submit is admitted as a time-limit auto-submit
        // (ARCH-002 FR-017): the submitted responses are scored, autosave is not
        // merged, and every unanswered item is scored blank/zero.
        if ($this->attemptExpired($attempt)) {
            return $this->autoSubmitExpired($attempt->id, $responses);
        }

        return $this->performSubmit($attempt, $responses);
    }

    /**
     * Time-limit expiry auto-submit (ARCH-002 FR-017, ARCH-004 §4.1). The attempt is not
     * rejected with 409 — unanswered items are scored as blank/zero instead,
     * mirroring the ARCH-002 FR-017/ARCH-002 FR-018 submission flow.
     *
     * @return array{status: string, mastery_results?: array<string, mixed>}
     */
    private function autoSubmitExpired(int $attemptId, ?array $responses = []): array
    {
        $attempt = AssessmentAttempt::findOrFail($attemptId);

        return $this->performSubmit($attempt, $responses, true);
    }

    /**
     * Shared submission pipeline for on-time and expired (auto-submit)
     * attempts (ARCH-004 §4.1). With $autoSubmitBlanks the autosave merge is
     * skipped and essays are recorded as 0-point auto-scored blanks.
     *
     * @return array{status: string, mastery_results?: array<string, mixed>}
     */
    private function performSubmit(AssessmentAttempt $attempt, ?array $responses = [], bool $autoSubmitBlanks = false): array
    {
        $studentId = $attempt->student_id;
        $assessmentId = $attempt->assessment_id;

        $assessment = $attempt->assessment;
        $items = $assessment->items()->orderBy('sort_order')->get();

        // Merge any final responses with auto-saved data.
        // Use array_replace (not spread operator) to preserve integer-keyed
        // item IDs — the spread operator re-indexes numeric keys, breaking
        // Arr::get lookups by item->id.
        $savedResponses = $this->getAutoSavedResponses($studentId, $assessmentId, $attempt->id);

        $finalResponses = $autoSubmitBlanks
            ? ($responses ?? [])
            : array_replace($savedResponses ?? [], $responses ?? []);

        return DB::transaction(function () use ($attempt, $assessment, $items, $finalResponses, $studentId, $assessmentId, $autoSubmitBlanks): array {
            // F-11: re-check the attempt under a row lock. A concurrent
            // submit that won the race has already flipped status away from
            // in_progress; the loser degrades to ALREADY_SUBMITTED here
            // without writing rows/files/attempts/mastery. Only IDs are
            // logged, never response content.
            $locked = AssessmentAttempt::whereKey($attempt->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== 'in_progress') {
                Log::warning('AssessmentService::performSubmit duplicate suppressed', [
                    'attempt_id' => $attempt->id,
                    'assessment_id' => $assessmentId,
                    'student_id' => $studentId,
                ]);

                throw new BusinessRuleConflictException(
                    'This assessment has already been submitted.',
                    'ALREADY_SUBMITTED'
                );
            }

            $attempt = $locked;

            // Create the submission record. Semester + section snapshot from
            // the parent assessment's classroom (Semester scope).
            $assessment->loadMissing('classroom.section');
            $sectionId = $assessment->classroom?->section_id;

            try {
                $submission = AssessmentSubmission::create([
                    'attempt_id' => $locked->id,
                    'assessment_id' => $assessment->id,
                    'student_id' => $studentId,
                    'section_id' => $sectionId,
                    'semester_id' => $assessment->semester_id,
                    'submitted_at' => now(),
                    'status' => 'pending_grading',
                ]);
            } catch (UniqueConstraintViolationException $e) {
                Log::warning('AssessmentService::performSubmit duplicate suppressed', [
                    'attempt_id' => $attempt->id,
                    'assessment_id' => $assessmentId,
                    'student_id' => $studentId,
                ]);

                throw new BusinessRuleConflictException(
                    'This assessment has already been submitted.',
                    'ALREADY_SUBMITTED'
                );
            } catch (QueryException $e) {
                if (! self::isDuplicateSubmissionError($e)) {
                    throw $e;
                }

                Log::warning('AssessmentService::performSubmit duplicate suppressed', [
                    'attempt_id' => $attempt->id,
                    'assessment_id' => $assessmentId,
                    'student_id' => $studentId,
                ]);

                throw new BusinessRuleConflictException(
                    'This assessment has already been submitted.',
                    'ALREADY_SUBMITTED'
                );
            }

            // Write all responses.
            foreach ($items as $item) {
                $responseText = Arr::get($finalResponses, (string) $item->id);
                $hasResponse = $responseText !== null;

                if (in_array($item->item_type, ['multiple_choice', 'true_false'], true)) {
                    // Objective items: auto-score (ARCH-003 ADR-004).
                    $isCorrect = $hasResponse
                        ? strtolower(trim($responseText)) === strtolower(trim($item->correct_answer))
                        : false;

                    $earned = $isCorrect ? (float) $item->max_points : 0.0;

                    AssessmentResponse::create([
                        'submission_id' => $submission->id,
                        'item_id' => $item->id,
                        'response_text' => $responseText,
                        'earned_points' => $earned,
                        'is_auto_scored' => true,
                    ]);
                } else {
                    // Subjective items (essay): store response, leave earned_points NULL.
                    // ARCH-002 FR-017: a time-limit auto-submit records unanswered
                    // essays as 0-point auto-scored blanks.
                    AssessmentResponse::create([
                        'submission_id' => $submission->id,
                        'item_id' => $item->id,
                        'response_text' => $responseText,
                        'earned_points' => $autoSubmitBlanks ? 0.0 : null,
                        'is_auto_scored' => $autoSubmitBlanks,
                    ]);
                }
            }

            $attempt->status = 'submitted';
            $attempt->save();

            // Check: does the assessment contain any subjective items?
            $hasSubjective = $items->contains(fn (AssessmentItem $i) => $i->item_type === 'essay');

            if ($hasSubjective) {
                // ARCH-002 FR-019 / ARCH-002 FR-018: subjective items → pending_grading, no mastery.
                $attempt->status = 'pending_grading';
                $attempt->save();

                // Clean up auto-save (ephemeral per ARCH-004 §8.1) — per attempt.
                AssessmentAutoSave::where('student_id', $studentId)
                    ->where('assessment_id', $assessmentId)
                    ->where('attempt_id', $attempt->id)
                    ->delete();

                return [
                    'status' => 'pending_grading',
                    'grader_id' => null,
                    'graded_at' => null,
                ];
            }

            // Objective-only: auto-scored → scored → invoke computeMastery (Phase 3 stub).
            $attempt->status = 'scored';
            $attempt->save();

            $submission->status = 'scored';
            $submission->save();

            // Clean up auto-save — per attempt.
            AssessmentAutoSave::where('student_id', $studentId)
                ->where('assessment_id', $assessmentId)
                ->where('attempt_id', $attempt->id)
                ->delete();

            // Phase 5: invoke real CompetencyMappingService.computeMastery().
            $masteryResults = $this->competencyMappingService->computeMastery(
                $submission->id,
                (int) $assessment->subject_id
            );

            // ARCH-002 FR-024: no submit-time batch AI trigger. Explanation generation
            // runs only on the student's explicit POST generate request after
            // results are released — never from the submit path.
            return [
                'status' => 'scored',
                'mastery_results' => $masteryResults,
            ];
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Student — Results Viewing
    |--------------------------------------------------------------------------
    */

    /**
     * Get assessment results for a student (ARCH-002 FR-024).
     * Blocked until is_results_released = true (ARCH-002 FR-019).
     *
     * @return array<string, mixed>
     *
     * @Traced-To ARCH-002 FR-019, ARCH-002 FR-024 (ARCH-001 §5.1)
     */
    public function getAssessmentResults(int $studentId, int $assessmentId): array
    {
        $assessment = Assessment::findOrFail($assessmentId);
        $this->ensureStudentCanAccessAssessment($studentId, $assessment);

        $submission = AssessmentSubmission::query()
            ->whereHas('attempt', function ($q) use ($assessmentId, $studentId): void {
                $q->where('assessment_id', $assessmentId)
                    ->where('student_id', $studentId);
            })
            ->with('assessment.items')
            ->latest('submitted_at')
            ->firstOrFail();

        if (! $submission->is_results_released) {
            throw new BusinessRuleConflictException(
                'Results have not been released yet.',
                'RESULTS_NOT_RELEASED'
            );
        }

        return $this->buildResultsResponse($submission);
    }

    /**
     * Adapter: get pending-grading submissions for a specific assessment.
     *
     * @return array<int, array<string, mixed>>
     *
     * @Traced-To ARCH-002 FR-018, ARCH-002 FR-018 (ARCH-005 block 4.4)
     */
    public function getPendingGradingSubmissions(int $teacherId, int $assessmentId): array
    {
        $assessment = $this->findOwnedByTeacher($assessmentId, $teacherId);

        $submissions = AssessmentSubmission::query()
            ->where('assessment_id', $assessmentId)
            ->where('status', 'pending_grading')
            ->with('student', 'attempt')
            ->get();

        return $submissions->map(fn ($s) => [
            'id' => $s->id,
            'assessment_id' => $s->assessment_id,
            'student_id' => $s->student_id,
            'student_name' => $s->student->name,
            'attempt_id' => $s->attempt_id,
            'submitted_at' => $s->submitted_at?->toIso8601String(),
            'created_at' => $s->created_at?->toIso8601String(),
        ])->values()->toArray();
    }

    /**
     * Adapter: get available assessments for a student.
     *
     * @Traced-To ARCH-002 FR-017, ARCH-002 FR-017 (ARCH-005 block 4.4)
     */
    public function getAvailableAssessments(int $studentId): array
    {
        return $this->listStudentAssessments($studentId);
    }

    /**
     * Adapter: start an assessment attempt. Returns the model (ARCH-002 FR-017, ARCH-002 FR-017).
     *
     * @Traced-To ARCH-002 FR-017, ARCH-002 FR-017 (ARCH-005 block 4.4)
     */
    public function startAttempt(int $studentId, int $assessmentId): AssessmentAttempt
    {
        $result = $this->startAssessment($studentId, $assessmentId);

        return AssessmentAttempt::findOrFail($result['attempt_id']);
    }

    /**
     * Adapter: save a single response on an active attempt (ARCH-002 FR-017).
     *
     * @Traced-To ARCH-002 FR-017 (ARCH-005 block 4.4)
     */
    public function saveResponse(int $studentId, int $attemptId, int $questionId, string $response): void
    {
        $this->updateAttemptResponse($studentId, $attemptId, $questionId, $response);
    }

    /**
     * Adapter: get student results. Delegates to getAssessmentResults (ARCH-002 FR-019, ARCH-002 FR-024).
     *
     * @Traced-To ARCH-002 FR-019, ARCH-002 FR-024 (ARCH-005 block 4.4)
     */
    public function getStudentResults(int $studentId, int $assessmentId): array
    {
        return $this->getAssessmentResults($studentId, $assessmentId);
    }

    // -----------------------------------------------------------------------
    // Phase 5: real mastery computation (replaces Phase 3 stubs)
    // -----------------------------------------------------------------------

    /**
     * Returns the list of unmastered (student_id, competency_id) pairs for
     * an assessment, handling both Recorded and Unrecorded types.
     *
     * - Recorded assessments: queries the derived Not-Competent view
     *   `v_not_competent_flags` scoped to the assessment's scored submissions
     *   (ARCH-002 FR-021, ARCH-002 FR-020, ARCH-004 §4.4 — the view resolves the latest Recorded row per
     *   (student, competency); stale flags after regrades cannot re-trigger AI).
     * - Unrecorded assessments: computes in-memory via
     *   CompetencyMappingService::computeMastery() (safe — does not persist).
     *
     * @return array<int, array{student_id: int, competency_id: int}>
     *
     * @Traced-To ARCH-002 FR-019, ARCH-002 FR-021, ARCH-002 FR-027, ARCH-002 FR-021, ARCH-004 §4.4 (ARCH-001 §5.1 — Phase 7)
     */
    private function getUnmasteredStudentCompetencies(int $assessmentId): array
    {
        $assessment = Assessment::findOrFail($assessmentId);

        $pairs = $this->getUnmasteredCompetenciesForAssessment($assessmentId);

        // For Unrecorded assessments, the derived view is structurally empty
        // (Unrecorded never reaches mastery_records — ARCH-002 FR-021, ARCH-002 FR-021) — compute
        // in-memory from the most recent scored submission per student
        // (ARCH-002 FR-021, ARCH-002 FR-021). computeMastery is a no-op for persistence on
        // Unrecorded (Phase 5 invariant), so re-invocation is safe.
        if (empty($pairs) && $assessment->type === 'Unrecorded') {
            $pairs = $this->getUnmasteredForUnrecorded($assessment);
        }

        // Deduplicate (student_id, competency_id) pairs.
        $seen = [];
        $unique = [];
        foreach ($pairs as $pair) {
            $key = $pair['student_id'] . '|' . $pair['competency_id'];
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $pair;
            }
        }

        return $unique;
    }

    /**
     * Query the derived Not-Competent view for scored submissions of this
     * assessment (Recorded Assessments only — Unrecorded is structurally
     * absent, ARCH-002 FR-021). The view guarantees each pair reflects its LATEST
     * Recorded mastery status (ARCH-004 §4.4).
     *
     * @return array<int, array{student_id: int, competency_id: int}>
     *
     * @Traced-To ARCH-002 FR-019, ARCH-002 FR-021, ARCH-002 FR-027, ARCH-004 §4.4 (ARCH-001 §5.1)
     */
    private function getUnmasteredCompetenciesForAssessment(int $assessmentId): array
    {
        return DB::table('v_not_competent_flags as v')
            ->whereIn('v.assessment_submission_id', function ($query) use ($assessmentId): void {
                $query->select('id')
                    ->from('assessment_submissions')
                    ->where('assessment_id', $assessmentId)
                    ->where('status', 'scored');
            })
            ->select('v.student_id', 'v.competency_id')
            ->distinct()
            ->get()
            ->map(fn ($flag) => [
                'student_id' => (int) $flag->student_id,
                'competency_id' => (int) $flag->competency_id,
            ])
            ->values()
            ->toArray();
    }

    /**
     * In-memory unmastered competency computation for Unrecorded assessments
     * (ARCH-002 FR-021, ARCH-002 FR-021). Calls computeMastery per scored submission — this is safe
     * because Unrecorded Assessments do not persist mastery records.
     *
     * @return array<int, array{student_id: int, competency_id: int}>
     *
     * @Traced-To ARCH-002 FR-021, ARCH-002 FR-021 (ARCH-001 §5.1 — Phase 7)
     */
    private function getUnmasteredForUnrecorded(Assessment $assessment): array
    {
        $pairs = [];

        $submissions = AssessmentSubmission::query()
            ->where('assessment_id', $assessment->id)
            ->where('status', 'scored')
            ->get();

        foreach ($submissions as $submission) {
            try {
                $results = $this->competencyMappingService->computeMastery(
                    $submission->id,
                    (int) $assessment->subject_id
                );

                foreach ($results['unmastered_competency_ids'] as $competencyId) {
                    $pairs[] = [
                        'student_id' => (int) $submission->student_id,
                        'competency_id' => (int) $competencyId,
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to compute mastery for Unrecorded AI eligibility', [
                    'assessment_id' => $assessment->id,
                    'submission_id' => $submission->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $pairs;
    }

    // -----------------------------------------------------------------------
    // F-14 — Item-attachment downloads (ownership-checked, metadata only)
    // -----------------------------------------------------------------------
    //
    // Orphan inventory: assessment_items/{assessmentId}/items/{itemId}/*
    // (<=5 files x 15 MB per item, ARCH-002 QA-009). Growth bounded by
    // MAX_ITEM_ATTACHMENTS x MAX_FILE_SIZE; keys pre-generated via
    // SafeUpload so a 422 never orphans a file. Controllers confine with
    // SafeUpload::isConfined + SafeUpload::downloadName (F-03).

    /**
     * Resolve an item attachment the teacher owns (ARCH-002 FR-011 scoping).
     *
     * @Traced-To ARCH-002 FR-015, ARCH-002 FR-011, ARCH-002 QA-009 (ARCH-001 §5.1)
     */
    public function downloadTeacherItemAttachment(int $teacherId, int $itemId, int $attachmentId): AssessmentItemAttachment
    {
        $item = $this->findItemOwnedByTeacher($itemId, $teacherId);

        return AssessmentItemAttachment::query()
            ->where('id', $attachmentId)
            ->where('assessment_item_id', $item->id)
            ->firstOrFail();
    }

    /**
     * Resolve an item attachment for an enrolled student on a released
     * assessment. Draft assessments 404 (no existence leak).
     *
     * @Traced-To ARCH-002 FR-017, ARCH-002 FR-011, ARCH-002 QA-009 (ARCH-001 §5.1)
     */
    public function downloadStudentItemAttachment(int $studentId, int $itemId, int $attachmentId): AssessmentItemAttachment
    {
        $attachment = AssessmentItemAttachment::query()
            ->where('id', $attachmentId)
            ->where('assessment_item_id', $itemId)
            ->firstOrFail();

        $item = AssessmentItem::findOrFail($itemId);
        $assessment = $item->assessment;

        if ($assessment === null || $assessment->status !== 'released') {
            throw new ModelNotFoundException();
        }

        $this->ensureStudentCanAccessAssessment($studentId, $assessment);

        return $attachment;
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    /**
     * Classroom ownership guard: the teacher must own the classroom that
     * scopes the assessment (teacher_id on classrooms).
     */
    private function ensureTeacherOwnsClassroom(int $teacherId, int $classroomId): void
    {
        $classroom = Classroom::findOrFail($classroomId);

        if ((int) $classroom->teacher_id !== $teacherId) {
            throw new BusinessRuleConflictException(
                'You do not own this classroom.',
                'FORBIDDEN',
                403
            );
        }
    }

    /**
     * Competency filter guard: the tag's (subject_id, grade_level, semester)
     * triple must match the assessment classroom's subject + grade + semester.
     * Mismatches are 422 COMPETENCY_MISMATCH.
     */
    private function assertCompetencyMatchesAssessment(Assessment $assessment, CompetencyReference $competency): void
    {
        $assessment->loadMissing(['classroom.section.gradeLevel.semester', 'classroom.subject.gradeLevel.semester']);

        $classroom = $assessment->classroom;
        $expectedSubjectId = $classroom ? (int) $classroom->subject_id : (int) $assessment->subject_id;
        $gradeLevel = $classroom?->section?->gradeLevel ?? $classroom?->subject?->gradeLevel;
        $expectedGrade = $gradeLevel ? (string) $gradeLevel->grade_level : null;
        $expectedSemester = $gradeLevel?->semester ? (string) $gradeLevel->semester->semester : null;

        $mismatch = (int) $competency->subject_id !== $expectedSubjectId
            || ($expectedGrade !== null && (string) $competency->grade_level !== $expectedGrade)
            || ($expectedSemester !== null && (string) $competency->semester !== $expectedSemester);

        if ($mismatch) {
            throw new BusinessRuleConflictException(
                'The competency tag does not belong to the classroom subject, grade level, and semester.',
                'COMPETENCY_MISMATCH',
                422
            );
        }
    }

    private function findOwnedByTeacher(int $assessmentId, int $teacherId): Assessment
    {
        return Assessment::query()
            ->where('id', $assessmentId)
            ->where('teacher_id', $teacherId)
            ->firstOrFail();
    }

    private function findDraftOwnedByTeacher(int $assessmentId, int $teacherId): Assessment
    {
        $assessment = Assessment::query()
            ->where('id', $assessmentId)
            ->where('teacher_id', $teacherId)
            ->firstOrFail();

        if ($assessment->status !== 'draft') {
            throw new BusinessRuleConflictException(
                'This action is only available while the assessment is in draft status.',
                'ASSESSMENT_RELEASED'
            );
        }

        return $assessment;
    }

    private function findItemOwnedByTeacher(int $itemId, int $teacherId): AssessmentItem
    {
        $item = AssessmentItem::query()
            ->where('id', $itemId)
            ->whereHas('assessment', function ($q) use ($teacherId): void {
                $q->where('teacher_id', $teacherId);
            })
            ->firstOrFail();

        return $item->load('assessment');
    }

    /**
     * Resolve a submission via the teacher's assessment ownership (ARCH-002 FR-011 scoping).
     */
    private function findSubmissionForTeacher(int $teacherId, int $submissionId): AssessmentSubmission
    {
        return AssessmentSubmission::query()
            ->where('id', $submissionId)
            ->whereHas('assessment', function ($q) use ($teacherId): void {
                $q->where('teacher_id', $teacherId);
            })
            ->firstOrFail();
    }

    private function findActiveAttempt(int $studentId, int $assessmentId): AssessmentAttempt
    {
        return AssessmentAttempt::query()
            ->where('student_id', $studentId)
            ->where('assessment_id', $assessmentId)
            ->where('status', 'in_progress')
            ->latest('id')
            ->firstOrFail();
    }

    /**
     * Time-limit expiry check (ARCH-004 §4.1). Attempts without a time limit, with
     * a non-positive limit, or without a recorded start are never expired.
     */
    private function attemptExpired(AssessmentAttempt $attempt): bool
    {
        $timeLimit = $attempt->assessment->time_limit;

        if ($timeLimit === null || $timeLimit <= 0 || $attempt->started_at === null) {
            return false;
        }

        return now()->gt($attempt->started_at->copy()->addMinutes((int) $timeLimit));
    }

    /**
     * @return array<int, string>|null
     */
    private function getAutoSavedResponses(int $studentId, int $assessmentId, int $attemptId): ?array
    {
        $autoSave = AssessmentAutoSave::query()
            ->where('student_id', $studentId)
            ->where('assessment_id', $assessmentId)
            ->where('attempt_id', $attemptId)
            ->first();

        return $autoSave ? $autoSave->responses : null;
    }

    private function storeItemAttachments(AssessmentItem $item, array $attachments): void
    {
        $dir = self::ITEM_ATTACHMENT_DIR . '/' . $item->assessment_id . '/items/' . $item->id;

        // Generate all keys before writing any file: a FILENAME_TOO_LONG
        // reject must never leave an earlier batch file orphaned on disk.
        $keys = [];

        foreach ($attachments as $file) {
            $keys[] = SafeUpload::storageKey($dir, $file);
        }

        foreach ($attachments as $i => $file) {
            $filename = $keys[$i];
            SafeUpload::disk()->put($filename, file_get_contents($file->getRealPath()));

            AssessmentItemAttachment::create([
                'assessment_item_id' => $item->id,
                'filename' => $filename,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
            ]);
        }

        $item->refresh();
    }

    /**
     * Build the results response for getAssessmentResults (ARCH-002 FR-024).
     */
    private function buildResultsResponse(AssessmentSubmission $submission): array
    {
        $assessment = $submission->assessment;
        $responses = AssessmentResponse::query()
            ->where('submission_id', $submission->id)
            ->with('item')
            ->get();

        $items = $assessment->items->map(function (AssessmentItem $item) use ($responses): array {
            $response = $responses->firstWhere('item_id', $item->id);

            return [
                'item_id' => $item->id,
                'item_type' => $item->item_type,
                'prompt' => $item->prompt,
                'max_points' => (float) $item->max_points,
                'earned_points' => $response?->earned_points !== null ? (float) $response->earned_points : null,
                'response_text' => $response?->response_text,
                'is_auto_scored' => (bool) ($response?->is_auto_scored ?? false),
            ];
        });

        $totalMax = $responses->sum('item.max_points');
        $totalEarned = $responses->sum('earned_points');

        return [
            'assessment_id' => $assessment->id,
            'title' => $assessment->title,
            'type' => $assessment->type,
            'status' => $submission->status,
            'overall_score' => $totalEarned,
            'max_score' => (float) $totalMax,
            'percentage' => $totalMax > 0 ? round(($totalEarned / $totalMax) * 100, 2) : 0,
            'items' => $items,
            'released_at' => $submission->results_released_at?->toIso8601String(),
        ];
    }

    /**
     * Reject files whose extension or detected MIME type is not in the
     * ARCH-002 QA-009 whitelist. Both the extension AND the byte-detected MIME must
     * be allowed, so a renamed file is rejected even when its name looks
     * valid (ARCH-002 QA-009).
     *
     * @Traced-To ARCH-002 QA-009, UC-27 (ARCH-001 §5.1)
     */
    private function assertAllowedFileType(UploadedFile $file): void
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $mime = strtolower((string) $file->getMimeType());

        if (
            ! in_array($extension, self::ALLOWED_EXTENSIONS, true)
            || ! in_array($mime, self::ALLOWED_MIME_TYPES, true)
        ) {
            throw new BusinessRuleConflictException(
                'File type not allowed. Allowed types: PDF, DOCX, PPTX, XLSX, JPG, PNG, ZIP.',
                'INVALID_FILE_TYPE',
                422
            );
        }
    }

    /**
     * F-11: duplicate-INSERT classifier for the assessment_submissions
     * UNIQUE(attempt_id) guard. In this narrow scope the only INSERT is the
     * submission row itself, but a bare 23000 alone is NOT enough — some
     * drivers report FK/NOT-NULL/CHECK failures as 23000, so uniqueness
     * evidence (named constraint or unique/duplicate wording) is required;
     * anything else propagates instead of masking as 409.
     */
    private static function isDuplicateSubmissionError(QueryException $e): bool
    {
        $code = (string) $e->getCode();
        $message = strtolower($e->getMessage());

        if ($code === '23505') {
            return true;
        }

        if (str_contains($message, 'assessment_submissions_attempt_id_unique')) {
            return true;
        }

        $hasUniqueEvidence = str_contains($message, 'unique')
            || str_contains($message, 'duplicate');

        if (! $hasUniqueEvidence) {
            return false;
        }

        return $code === '23000'
            || str_contains($message, 'assessment_submissions');
    }
}
