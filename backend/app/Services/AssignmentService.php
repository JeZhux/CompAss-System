<?php

namespace App\Services;

use App\Exceptions\BusinessRuleConflictException;
use App\Models\Assignment;
use App\Models\AssignmentAttachment;
use App\Models\AssignmentFeedback;
use App\Models\AssignmentSubmission;
use App\Models\Classroom;
use App\Models\ClassroomEnrollment;
use App\Models\SubmissionFile;
use App\Support\SafeUpload;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Creates, edits, deletes, and views Assignments. Receives student file
 * submissions. Provides written feedback. Late/on-time detection. Hard-delete logic. (ARCH-001 §5.1)
 *
 * Collaborators: OrgStructureService (reads TeacherSectionAssignment for scoping,
 * ClassroomEnrollment for validation).
 *
 * Invariants (ARCH-001 §5.1 / ARCH-002 FR-011/ARCH-002 FR-013/ARCH-002 FR-013/ARCH-002 QA-009):
 *  - Assignments are not competency-tagged and are fully separate from Assessments (ARCH-002 FR-012).
 *  - Assignments receive written feedback only — no grading (ARCH-002 FR-014, ARCH-002 FR-012).
 *  - Hard delete removes Assignment, submission files, and submission records (ARCH-002 FR-013).
 *  - Submission file constraints enforced server-side (ARCH-002 QA-009).
 *
 * @Traced-To ARCH-002 FR-012, ARCH-002 FR-012, ARCH-002 FR-013, ARCH-002 FR-013, ARCH-002 FR-014, ARCH-002 FR-014, ARCH-002 FR-012, ARCH-002 FR-013
 * @Traced-To ARCH-002 FR-011, ARCH-002 FR-013, ARCH-002 FR-013, ARCH-002 QA-009, ARCH-002 QA-009, ARCH-002 QA-010, ARCH-002 QA-006
 */
class AssignmentService
{
    private const ATTACHMENT_DIR = 'assignments';

    public const MAX_ATTACHMENTS = 5;

    public const MAX_FILE_SIZE = 15 * 1024 * 1024;

    /** @var int Max submission files per student (ARCH-002 QA-009). */
    public const MAX_SUBMISSION_FILES = 5;

    /** @var string[] Allowed attachment/submission extensions (ARCH-002 QA-009). */
    public const ALLOWED_EXTENSIONS = ['pdf', 'docx', 'pptx', 'xlsx', 'jpg', 'jpeg', 'png', 'zip'];

    /** @var string[] Allowed attachment/submission MIME types, mirrored from ALLOWED_EXTENSIONS (ARCH-002 QA-009). */
    public const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'image/jpeg',
        'image/png',
        'application/zip',
    ];

    /**
     * Audit logging of Assignment CRUD (ARCH-001 §5.1 / ARCH-002 QA-006).
     */
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /**
     * Create an Assignment within the teacher's assigned subject-section (ARCH-002 FR-012, ARCH-002 FR-012).
     *
     * @Traced-To ARCH-002 FR-012, ARCH-002 FR-012, ARCH-002 QA-009, ARCH-002 QA-006 (ARCH-001 §5.1)
     */
    public function createAssignment(int $teacherId, int $subjectId, string $title, ?string $description, string $dueDate, ?array $attachments = []): Assignment
    {
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
    public function createAssignmentInClassroom(int $teacherId, int $classroomId, string $title, ?string $description, string $dueDate, ?array $attachments = [], ?int $clientSubjectId = null, ?int $clientSemesterId = null): Assignment
    {
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

        // Stale-param guard: a supplied semester_id/term_id that mismatches the
        // classroom's Semester is a 422 (Semester canonical).
        if ($clientSemesterId !== null && $gradeLevel?->semester_id !== null
            && (int) $clientSemesterId !== (int) $gradeLevel->semester_id) {
            throw ValidationException::withMessages([
                'semester_id' => ['The semester_id does not match the classroom\'s semester.'],
            ]);
        }

        $attachments = $attachments ?? [];
        $this->validateAttachments($attachments);

        $assignment = DB::transaction(function () use ($teacherId, $classroomId, $classroom, $gradeLevel, $title, $description, $dueDate, $attachments): Assignment {
            $assignment = Assignment::create([
                'teacher_id' => $teacherId,
                'classroom_id' => $classroomId,
                'subject_id' => $classroom->subject_id,
                'semester_id' => $gradeLevel?->semester_id,
                'title' => $title,
                'description' => $description,
                'due_date' => $dueDate,
            ]);

            if (! empty($attachments)) {
                $this->storeAttachments($assignment, $attachments);
            }

            return $assignment->load('attachments');
        });

        $this->auditLogService->log(
            'create',
            'Assignment created: ' . $assignment->title,
            Auth::id(),
            Assignment::class,
            $assignment->id,
            ['title' => $assignment->title, 'classroom_id' => $classroomId]
        );

        return $assignment;
    }

    /**
     * U03 — Teacher list scoped to a single classroom (paginated)
     */
    public function listTeacherAssignmentsInClassroom(int $teacherId, int $classroomId, int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        $classroom = Classroom::findOrFail($classroomId);
        if ((int) $classroom->teacher_id !== $teacherId) {
            throw new BusinessRuleConflictException(
                'You do not own this classroom.',
                'FORBIDDEN',
                403
            );
        }

        return Assignment::query()
            ->where('teacher_id', $teacherId)
            ->where('classroom_id', $classroomId)
            ->withCount('submissions')
            ->orderByDesc('created_at')
            ->paginate($perPage, page: $page);
    }

    /**
     * U03 — Student list scoped to a single classroom (enrolled)
     *
     * @return Collection<int, array>
     */
    public function listStudentAssignmentsInClassroom(int $studentId, int $classroomId): Collection
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

        $assignments = Assignment::query()
            ->where('classroom_id', $classroomId)
            ->orderByDesc('created_at')
            ->get();

        return $assignments->map(function (Assignment $a) use ($studentId): array {
            $submitted = AssignmentSubmission::query()
                ->where('assignment_id', $a->id)
                ->where('student_id', $studentId)
                ->exists();

            $hasFeedback = AssignmentSubmission::query()
                ->where('assignment_id', $a->id)
                ->where('student_id', $studentId)
                ->whereHas('feedback')
                ->exists();

            return [
                'id' => $a->id,
                'classroom_id' => $a->classroom_id,
                'title' => $a->title,
                'description' => $a->description,
                'due_date' => $a->due_date?->toIso8601String(),
                'is_submitted' => $submitted,
                'has_feedback' => $hasFeedback,
                'created_at' => $a->created_at?->toIso8601String(),
            ];
        });
    }

    /**
     * Update Assignment details. If submissions exist, only description is editable;
     * title and due_date are blocked (ARCH-002 FR-013, ARCH-002 QA-007).
     *
     * @Traced-To ARCH-002 FR-013, ARCH-002 QA-007, ARCH-002 QA-006 (ARCH-001 §5.1)
     */
    public function updateAssignment(int $teacherId, int $assignmentId, ?string $title, ?string $description, ?string $dueDate): void
    {
        $assignment = $this->findOwnedByTeacher($teacherId, $assignmentId);

        $hasSubmissions = $assignment->submissions()->exists();

        if ($hasSubmissions) {
            if ($title !== null) {
                throw new BusinessRuleConflictException(
                    'Cannot change the title after submissions have been made.',
                    'SUBMISSIONS_EXIST_BLOCK_STRUCTURAL_EDIT'
                );
            }

            if ($dueDate !== null) {
                throw new BusinessRuleConflictException(
                    'Cannot change the due date after submissions have been made.',
                    'SUBMISSIONS_EXIST_BLOCK_STRUCTURAL_EDIT'
                );
            }
        }

        if ($title !== null) {
            $assignment->title = $title;
        }

        if ($description !== null) {
            $assignment->description = $description;
        }

        if ($dueDate !== null) {
            $assignment->due_date = $dueDate;
        }

        $assignment->save();

        $this->auditLogService->log(
            'update',
            'Assignment updated (id ' . $assignment->id . ')',
            Auth::id(),
            Assignment::class,
            $assignment->id,
            ['title' => $assignment->title]
        );
    }

    /**
     * Delete an Assignment. If no submissions exist: hard-deletes immediately.
     * If submissions exist: returns confirmation-required flag + count (ARCH-002 FR-013, ARCH-002 FR-013).
     *
     * @return array{success: bool, confirmation_required: bool, submission_count: int|null}
     *
     * @Traced-To ARCH-002 FR-013, ARCH-002 FR-013 (ARCH-001 §5.1)
     */
    public function deleteAssignment(int $teacherId, int $assignmentId): array
    {
        $assignment = $this->findOwnedByTeacher($teacherId, $assignmentId);

        $submissionCount = $assignment->submissions()->count();

        if ($submissionCount === 0) {
            $this->hardDeleteAssignment($assignment);

            return ['success' => true, 'confirmation_required' => false, 'submission_count' => null];
        }

        return ['success' => false, 'confirmation_required' => true, 'submission_count' => $submissionCount];
    }

    /**
     * Execute the confirmed hard delete of an Assignment and all associated data (ARCH-002 FR-013, ARCH-002 FR-013).
     *
     * @Traced-To ARCH-002 FR-013, ARCH-002 FR-013 (ARCH-001 §5.1)
     */
    public function confirmDeleteAssignment(int $teacherId, int $assignmentId): void
    {
        $assignment = $this->findOwnedByTeacher($teacherId, $assignmentId);

        $this->hardDeleteAssignment($assignment);
    }

    /**
     * Student submits files for an assignment (ARCH-002 FR-013, ARCH-002 FR-013, ARCH-002 QA-009).
     * Late submissions are NOT blocked; status is computed vs due_date (ARCH-002 FR-013).
     *
     * U-09: classroom-only — verifies enrollment in the assignment's classroom.
     * @Traced-To ARCH-002 FR-013, ARCH-002 FR-013, ARCH-002 FR-013, ARCH-002 QA-009 (ARCH-001 §5.1)
     */
    public function submitAssignment(int $studentId, int $assignmentId, array $files): AssignmentSubmission
    {
        $assignment = Assignment::findOrFail($assignmentId);

        // U-09 classroom-only enrollment check (archived classrooms excluded).
        if ($assignment->classroom_id === null) {
            throw new BusinessRuleConflictException(
                'You are not enrolled in the classroom for this assignment.',
                'NOT_ENROLLED',
                403
            );
        }
        $activeIds = Classroom::where('id', $assignment->classroom_id)->whereNull('archived_at')->pluck('id')->toArray();
        $enrolled = ClassroomEnrollment::query()
            ->where('student_id', $studentId)
            ->whereIn('classroom_id', $activeIds)
            ->where('classroom_id', $assignment->classroom_id)
            ->exists();
        if (! $enrolled) {
            throw new BusinessRuleConflictException(
                'You are not enrolled in the classroom for this assignment.',
                'NOT_ENROLLED',
                403
            );
        }

        // ARCH-002 FR-013 / spec: one submission per student per assignment.
        $existing = AssignmentSubmission::query()
            ->where('assignment_id', $assignmentId)
            ->where('student_id', $studentId)
            ->exists();

        if ($existing) {
            throw new BusinessRuleConflictException(
                'You have already submitted this assignment.',
                'ALREADY_SUBMITTED'
            );
        }

        if (count($files) > 0 && count($files) > self::MAX_SUBMISSION_FILES) {
            throw new BusinessRuleConflictException(
                'A maximum of ' . self::MAX_SUBMISSION_FILES . ' files can be submitted.',
                'TOO_MANY_FILES'
            );
        }

        foreach ($files as $file) {
            $this->assertAllowedFileType($file);

            if ($file->getSize() > self::MAX_FILE_SIZE) {
                throw new BusinessRuleConflictException(
                    'Each file must be at most 15 MB.',
                    'FILE_TOO_LARGE'
                );
            }
        }

        return DB::transaction(function () use ($assignmentId, $studentId, $assignment, $files): AssignmentSubmission {
            $isLate = now()->gt($assignment->due_date);

            try {
                $submission = AssignmentSubmission::create([
                    'assignment_id' => $assignmentId,
                    'student_id' => $studentId,
                    'submitted_at' => now(),
                    'status' => $isLate ? 'late' : 'on_time',
                ]);
            } catch (UniqueConstraintViolationException $e) {
                Log::warning('AssignmentService::submitAssignment duplicate suppressed', [
                    'assignment_id' => $assignmentId,
                    'student_id' => $studentId,
                ]);

                throw new BusinessRuleConflictException(
                    'You have already submitted this assignment.',
                    'ALREADY_SUBMITTED'
                );
            } catch (QueryException $e) {
                if (! self::isDuplicateSubmissionError($e)) {
                    throw $e;
                }

                Log::warning('AssignmentService::submitAssignment duplicate suppressed', [
                    'assignment_id' => $assignmentId,
                    'student_id' => $studentId,
                ]);

                throw new BusinessRuleConflictException(
                    'You have already submitted this assignment.',
                    'ALREADY_SUBMITTED'
                );
            }

            if (! empty($files)) {
                $dir = self::ATTACHMENT_DIR . '/submissions/' . $submission->id;

                // Generate all keys before writing any file: a FILENAME_TOO_LONG
                // reject must never leave an earlier batch file orphaned on disk.
                $keys = [];

                foreach ($files as $file) {
                    $keys[] = SafeUpload::storageKey($dir, $file);
                }

                foreach ($files as $i => $file) {
                    $filename = $keys[$i];
                    SafeUpload::disk()->put($filename, file_get_contents($file->getRealPath()));

                    SubmissionFile::create([
                        'submission_id' => $submission->id,
                        'filename' => $filename,
                        'original_filename' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                    ]);
                }
            }

            return $submission->load('files');
        });
    }

    /**
     * Return all student submissions for an assignment, scoped to the teacher's section (ARCH-002 FR-014).
     *
     * @return LengthAwarePaginator<int, AssignmentSubmission>
     *
     * @Traced-To ARCH-002 FR-014 (ARCH-001 §5.1)
     */
    public function getSubmissions(int $teacherId, int $assignmentId, int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        $assignment = $this->findOwnedByTeacher($teacherId, $assignmentId);

        return AssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->with('student')
            ->orderByDesc('submitted_at')
            ->paginate($perPage, page: $page);
    }

    /**
     * Store written feedback on a student's submission (ARCH-002 FR-014, ARCH-002 FR-012).
     * No grade/points assigned.
     *
     * @Traced-To ARCH-002 FR-014 (ARCH-001 §5.1)
     */
    public function provideFeedback(int $teacherId, int $submissionId, string $feedbackText): void
    {
        $submission = $this->findSubmissionForTeacher($teacherId, $submissionId);

        AssignmentFeedback::updateOrCreate(
            ['submission_id' => $submission->id],
            ['teacher_id' => $teacherId, 'feedback_text' => $feedbackText]
        );
    }

    /**
     * Return the teacher's written feedback for a student's submission (ARCH-002 FR-014).
     *
     * @Traced-To ARCH-002 FR-014, ARCH-002 FR-014 (ARCH-001 §5.1)
     */
    public function getFeedback(int $studentId, int $assignmentId): ?array
    {
        $assignment = Assignment::findOrFail($assignmentId);

        // Validate enrollment: classroom-scoped (archived classrooms excluded).
        // Unknown id 404s via findOrFail above; unenrolled reads are NOT_ENROLLED 403.
        if ($assignment->classroom_id === null) {
            throw new BusinessRuleConflictException(
                'You are not enrolled in this classroom.',
                'NOT_ENROLLED',
                403
            );
        }
        $activeIds = Classroom::where('id', $assignment->classroom_id)->whereNull('archived_at')->pluck('id')->toArray();
        $enrolled = ClassroomEnrollment::query()
            ->where('student_id', $studentId)
            ->whereIn('classroom_id', $activeIds)
            ->where('classroom_id', $assignment->classroom_id)
            ->exists();
        if (! $enrolled) {
            throw new BusinessRuleConflictException(
                'You are not enrolled in this classroom.',
                'NOT_ENROLLED',
                403
            );
        }

        $submission = AssignmentSubmission::query()
            ->where('assignment_id', $assignmentId)
            ->where('student_id', $studentId)
            ->first();

        if (! $submission) {
            return null;
        }

        $feedback = $submission->feedback;

        if (! $feedback) {
            return ['has_feedback' => false];
        }

        return [
            'has_feedback' => true,
            'feedback' => $feedback->feedback_text,
            'created_at' => $feedback->created_at?->toIso8601String(),
        ];
    }

    /**
     * List assignments for a teacher, optionally filtered by section, with submission counts (ARCH-002 FR-012, ARCH-002 FR-014).
     * U-09: classroom-only — every assignment carries a classroom_id (U-04 NOT NULL).
     *
     * @return LengthAwarePaginator<int, Assignment>
     *
     * @Traced-To ARCH-002 FR-012, ARCH-002 FR-014 (ARCH-001 §5.1)
     */
    public function listTeacherAssignments(int $teacherId, ?int $sectionId, int $page, int $perPage): LengthAwarePaginator
    {
        $query = Assignment::query()
            ->where('teacher_id', $teacherId)
            ->withCount('submissions');

        if ($sectionId !== null) {
            $query->where('subject_id', $sectionId);
        }

        return $query->orderByDesc('created_at')->paginate($perPage, page: $page);
    }

    /**
     * Get detailed view of an assignment the teacher owns (ARCH-002 FR-012, ARCH-002 FR-014).
     *
     * @Traced-To ARCH-002 FR-012, ARCH-002 FR-014 (ARCH-001 §5.1)
     */
    public function getAssignmentDetail(int $teacherId, int $assignmentId): array
    {
        $assignment = $this->findOwnedByTeacher($teacherId, $assignmentId);

        $assignment->load('attachments', 'submissions.student');

        $enrolled_students = $assignment->classroom_id !== null
            ? ClassroomEnrollment::query()
                ->where('classroom_id', $assignment->classroom_id)
                ->count()
            : 0;

        return [
            'id' => $assignment->id,
            'classroom_id' => $assignment->classroom_id,
            'title' => $assignment->title,
            'description' => $assignment->description,
            'due_date' => $assignment->due_date?->toIso8601String(),
            'created_at' => $assignment->created_at?->toIso8601String(),
            'attachments' => $assignment->attachments->map(fn ($a) => [
                'id' => $a->id,
                'original_filename' => $a->original_filename,
                'mime_type' => $a->mime_type,
                'file_size' => $a->file_size,
            ]),
            'enrolled_students' => $enrolled_students,
            'submissions' => $assignment->submissions->map(fn ($s) => [
                'id' => $s->id,
                'student_id' => $s->student_id,
                'student_name' => $s->student->name,
                'submitted_at' => $s->submitted_at?->toIso8601String(),
                'is_late' => $s->status === 'late',
                'file_count' => $s->files->count(),
            ]),
        ];
    }

    /**
     * List all assignments for the student's enrolled classrooms (ARCH-002 FR-013, ARCH-002 FR-013).
     * U-09: classroom-only — students with no classroom enrollment see an empty list.
     * F-12: paginated via the shared {data, meta} contract.
     *
     * @return LengthAwarePaginator<int, array>
     *
     * @Traced-To ARCH-002 FR-013, ARCH-002 FR-013 (ARCH-001 §5.1)
     */
    public function listStudentAssignments(int $studentId, int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        $enrolledIds = ClassroomEnrollment::where('student_id', $studentId)->pluck('classroom_id')->toArray();
        if (empty($enrolledIds)) {
            return Assignment::query()->whereRaw('1 = 0')->paginate($perPage, page: $page);
        }
        $activeIds = Classroom::whereIn('id', $enrolledIds)->whereNull('archived_at')->pluck('id')->toArray();
        if (empty($activeIds)) {
            return Assignment::query()->whereRaw('1 = 0')->paginate($perPage, page: $page);
        }
        $assignments = Assignment::query()
            ->whereIn('classroom_id', $activeIds)
            ->orderByDesc('created_at')
            ->paginate($perPage, page: $page);

        return $assignments->through(function (Assignment $a) use ($studentId): array {
            $submitted = AssignmentSubmission::query()
                ->where('assignment_id', $a->id)
                ->where('student_id', $studentId)
                ->exists();

            $hasFeedback = AssignmentSubmission::query()
                ->where('assignment_id', $a->id)
                ->where('student_id', $studentId)
                ->whereHas('feedback')
                ->exists();

            return [
                'id' => $a->id,
                'classroom_id' => $a->classroom_id,
                'title' => $a->title,
                'description' => $a->description,
                'due_date' => $a->due_date?->toIso8601String(),
                'is_submitted' => $submitted,
                'has_feedback' => $hasFeedback,
                'created_at' => $a->created_at?->toIso8601String(),
            ];
        });
    }

    /**
     * Get detailed assignment view for a student (ARCH-002 FR-013, ARCH-002 FR-013).
     *
     * @Traced-To ARCH-002 FR-013, ARCH-002 FR-013 (ARCH-001 §5.1)
     */
    public function getStudentAssignment(int $studentId, int $assignmentId): array
    {
        $assignment = Assignment::findOrFail($assignmentId);

        // Unknown id 404s via findOrFail above; unenrolled reads are NOT_ENROLLED 403.
        if ($assignment->classroom_id === null) {
            throw new BusinessRuleConflictException(
                'You are not enrolled in this classroom.',
                'NOT_ENROLLED',
                403
            );
        }
        $activeIds = Classroom::where('id', $assignment->classroom_id)->whereNull('archived_at')->pluck('id')->toArray();
        $enrolled = ClassroomEnrollment::query()
            ->where('student_id', $studentId)
            ->whereIn('classroom_id', $activeIds)
            ->where('classroom_id', $assignment->classroom_id)
            ->exists();
        if (! $enrolled) {
            throw new BusinessRuleConflictException(
                'You are not enrolled in this classroom.',
                'NOT_ENROLLED',
                403
            );
        }

        $assignment->load('attachments');

        $existingSubmission = AssignmentSubmission::query()
            ->where('assignment_id', $assignmentId)
            ->where('student_id', $studentId)
            ->with('files')
            ->first();

        return [
            'id' => $assignment->id,
            'classroom_id' => $assignment->classroom_id,
            'title' => $assignment->title,
            'description' => $assignment->description,
            'due_date' => $assignment->due_date?->toIso8601String(),
            'created_at' => $assignment->created_at?->toIso8601String(),
            'attachments' => $assignment->attachments->map(fn ($a) => [
                'id' => $a->id,
                'original_filename' => $a->original_filename,
                'mime_type' => $a->mime_type,
                'file_size' => $a->file_size,
            ]),
            'existing_submission' => $existingSubmission ? [
                'id' => $existingSubmission->id,
                'submitted_at' => $existingSubmission->submitted_at?->toIso8601String(),
                'is_late' => $existingSubmission->status === 'late',
                'files' => $existingSubmission->files->map(fn ($f) => [
                    'id' => $f->id,
                    'original_filename' => $f->original_filename,
                ]),
            ] : null,
        ];
    }

    /**
     * Retrieve a single student's submission detail (ARCH-002 FR-014).
     *
     * @Traced-To ARCH-002 FR-014 (ARCH-001 §5.1)
     */
    public function getSubmissionDetail(int $teacherId, int $submissionId): array
    {
        $submission = $this->findSubmissionForTeacher($teacherId, $submissionId);

        $submission->load('student', 'files');

        return [
            'id' => $submission->id,
            'assignment_id' => $submission->assignment_id,
            'student_id' => $submission->student_id,
            'student_name' => $submission->student->name,
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'is_late' => $submission->status === 'late',
            'files' => $submission->files->map(fn ($f) => [
                'id' => $f->id,
                'original_filename' => $f->original_filename,
                'mime_type' => $f->mime_type,
                'file_size' => $f->file_size,
            ]),
        ];
    }

    // -----------------------------------------------------------------------
    // F-14 — Attachment / submission-file downloads (ownership-checked)
    // -----------------------------------------------------------------------
    //
    // Orphan inventory (all under assignments/): attachments/{assignmentId}/*
    // (<=5 x 15 MB per assignment) and submissions/{submissionId}/*
    // (<=5 x 15 MB per submission, ARCH-002 QA-009). Growth bounded by
    // MAX_ATTACHMENTS / MAX_SUBMISSION_FILES x MAX_FILE_SIZE; keys
    // pre-generated via SafeUpload so a 422 never orphans a file.
    // Controllers confine with SafeUpload::isConfined + downloadName (F-03).

    /**
     * Resolve an assignment attachment the teacher owns (ARCH-002 FR-011 scoping).
     *
     * @Traced-To ARCH-002 FR-012, ARCH-002 FR-011, ARCH-002 QA-009 (ARCH-001 §5.1)
     */
    public function downloadTeacherAssignmentAttachment(int $teacherId, int $assignmentId, int $attachmentId): AssignmentAttachment
    {
        $assignment = $this->findOwnedByTeacher($teacherId, $assignmentId);

        return AssignmentAttachment::query()
            ->where('id', $attachmentId)
            ->where('assignment_id', $assignment->id)
            ->firstOrFail();
    }

    /**
     * Resolve an assignment attachment for an enrolled student.
     *
     * @Traced-To ARCH-002 FR-013, ARCH-002 FR-011, ARCH-002 QA-009 (ARCH-001 §5.1)
     */
    public function downloadStudentAssignmentAttachment(int $studentId, int $assignmentId, int $attachmentId): AssignmentAttachment
    {
        $assignment = Assignment::findOrFail($assignmentId);

        $this->ensureStudentEnrolled($studentId, $assignment);

        return AssignmentAttachment::query()
            ->where('id', $attachmentId)
            ->where('assignment_id', $assignment->id)
            ->firstOrFail();
    }

    /**
     * Resolve a submission file via the teacher's assignment ownership (ARCH-002 FR-011).
     *
     * @Traced-To ARCH-002 FR-014, ARCH-002 FR-011, ARCH-002 QA-009 (ARCH-001 §5.1)
     */
    public function downloadTeacherSubmissionFile(int $teacherId, int $submissionId, int $fileId): SubmissionFile
    {
        $submission = $this->findSubmissionForTeacher($teacherId, $submissionId);

        return SubmissionFile::query()
            ->where('id', $fileId)
            ->where('submission_id', $submission->id)
            ->firstOrFail();
    }

    /**
     * Resolve a submission file the student owns. Other students' rows 404;
     * unenrolled reads are NOT_ENROLLED 403.
     *
     * @Traced-To ARCH-002 FR-013, ARCH-002 FR-011, ARCH-002 QA-009 (ARCH-001 §5.1)
     */
    public function downloadStudentSubmissionFile(int $studentId, int $submissionId, int $fileId): SubmissionFile
    {
        $submission = AssignmentSubmission::query()
            ->where('id', $submissionId)
            ->where('student_id', $studentId)
            ->firstOrFail();

        $assignment = Assignment::findOrFail($submission->assignment_id);

        $this->ensureStudentEnrolled($studentId, $assignment);

        return SubmissionFile::query()
            ->where('id', $fileId)
            ->where('submission_id', $submission->id)
            ->firstOrFail();
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    /**
     * Classroom ownership guard: the teacher must own the classroom that
     * scopes the assignment (teacher_id on classrooms).
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

    private function findOwnedByTeacher(int $teacherId, int $assignmentId): Assignment
    {
        return Assignment::query()
            ->where('id', $assignmentId)
            ->where('teacher_id', $teacherId)
            ->firstOrFail();
    }

    /**
     * Resolve a submission via the teacher's assignment ownership (ARCH-002 FR-011).
     */
    private function findSubmissionForTeacher(int $teacherId, int $submissionId): AssignmentSubmission
    {
        $submission = AssignmentSubmission::findOrFail($submissionId);

        $assignment = Assignment::query()
            ->where('id', $submission->assignment_id)
            ->where('teacher_id', $teacherId)
            ->firstOrFail();

        return $submission;
    }

    /**
     * Classroom-scoped enrollment gate for student file reads (ARCH-002 FR-011).
     * Unknown id 404s via the caller's findOrFail; unenrolled reads are
     * NOT_ENROLLED 403 (archived classrooms excluded).
     */
    private function ensureStudentEnrolled(int $studentId, Assignment $assignment): void
    {
        if ($assignment->classroom_id === null) {
            throw new BusinessRuleConflictException(
                'You are not enrolled in this classroom.',
                'NOT_ENROLLED',
                403
            );
        }

        $activeIds = Classroom::where('id', $assignment->classroom_id)->whereNull('archived_at')->pluck('id')->toArray();
        $enrolled = ClassroomEnrollment::query()
            ->where('student_id', $studentId)
            ->whereIn('classroom_id', $activeIds)
            ->where('classroom_id', $assignment->classroom_id)
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
     * Hard-delete an Assignment and all associated data (ARCH-002 FR-013, ARCH-002 FR-013).
     * Shared by deleteAssignment (no-submissions fast path) and
     * confirmDeleteAssignment (confirmed hard delete). Audit-logged once
     * per successful deletion (ARCH-001 §5.1 / ARCH-002 QA-006).
     *
     * Records-authoritative ordering (AUD-016): every row deletion happens
     * inside the transaction; disk files are collected during iteration and
     * removed best-effort AFTER commit, so a post-first-row-delete DB failure
     * rolls back cleanly with all backing files still on disk.
     *
     * @Traced-To ARCH-002 FR-013, ARCH-002 FR-013, ARCH-002 QA-006 (ARCH-001 §5.1)
     */
    private function hardDeleteAssignment(Assignment $assignment): void
    {
        $diskPaths = [];

        DB::transaction(function () use ($assignment, &$diskPaths): void {
            // Delete feedback + file records + submissions; collect file paths.
            foreach ($assignment->submissions as $submission) {
                foreach ($submission->files as $file) {
                    $diskPaths[] = $file->filename;
                }

                AssignmentFeedback::where('submission_id', $submission->id)->delete();
                SubmissionFile::where('submission_id', $submission->id)->delete();
                $submission->delete();
            }

            // Delete attachment records; collect file paths.
            foreach ($assignment->attachments as $attachment) {
                $diskPaths[] = $attachment->filename;
            }
            AssignmentAttachment::where('assignment_id', $assignment->id)->delete();

            $assignment->delete();
        });

        // Best-effort post-commit file cleanup: the row deletions are already
        // committed, so a missing or unremovable file is logged server-side,
        // never fatal.
        $disk = SafeUpload::disk();

        foreach ($diskPaths as $path) {
            try {
                if (! SafeUpload::isConfined($path, self::ATTACHMENT_DIR)) {
                    Log::warning('Assignment hard delete skipped out-of-directory file key.', [
                        'path' => SafeUpload::loggable($path),
                    ]);

                    continue;
                }

                if (! $disk->exists($path)) {
                    Log::warning('Assignment hard delete found no file to remove.', ['path' => $path]);

                    continue;
                }

                if (! $disk->delete($path)) {
                    Log::warning('Assignment hard delete could not remove file.', ['path' => $path]);
                }
            } catch (\Throwable $e) {
                Log::warning('Assignment hard delete file removal failed.', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->auditLogService->log(
            'delete',
            'Assignment deleted (id ' . $assignment->id . ')',
            Auth::id(),
            Assignment::class,
            $assignment->id,
            ['title' => $assignment->title]
        );
    }

    private function validateAttachments(array $attachments): void
    {
        if (count($attachments) > self::MAX_ATTACHMENTS) {
            throw new BusinessRuleConflictException(
                'A maximum of ' . self::MAX_ATTACHMENTS . ' attachments is allowed.',
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
    }

    private function storeAttachments(Assignment $assignment, array $attachments): void
    {
        $dir = self::ATTACHMENT_DIR . '/attachments/' . $assignment->id;

        // Generate all keys before writing any file: a FILENAME_TOO_LONG
        // reject must never leave an earlier batch file orphaned on disk.
        $keys = [];

        foreach ($attachments as $file) {
            $keys[] = SafeUpload::storageKey($dir, $file);
        }

        foreach ($attachments as $i => $file) {
            $filename = $keys[$i];
            SafeUpload::disk()->put($filename, file_get_contents($file->getRealPath()));

            AssignmentAttachment::create([
                'assignment_id' => $assignment->id,
                'filename' => $filename,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
            ]);
        }

        $assignment->refresh('attachments');
    }

    /**
     * Reject files whose extension or detected MIME type is not in the
     * ARCH-002 QA-009 whitelist. Both the extension AND the byte-detected MIME must
     * be allowed, so a renamed file is rejected even when its name looks
     * valid (ARCH-002 QA-009).
     *
     * @Traced-To ARCH-002 QA-009 (ARCH-001 §5.1)
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
     * F-11: duplicate-INSERT classifier for the assignment_submissions
     * UNIQUE(assignment_id, student_id) guard. In this narrow scope the only
     * INSERT is the submission row itself, but a bare 23000 alone is NOT
     * enough — some drivers report FK/NOT-NULL/CHECK failures as 23000, so
     * uniqueness evidence (named constraint or unique/duplicate wording) is
     * required; anything else propagates instead of masking as 409.
     */
    private static function isDuplicateSubmissionError(QueryException $e): bool
    {
        $code = (string) $e->getCode();
        $message = strtolower($e->getMessage());

        if ($code === '23505') {
            return true;
        }

        if (str_contains($message, 'assignment_submissions_assignment_id_student_id_unique')) {
            return true;
        }

        $hasUniqueEvidence = str_contains($message, 'unique')
            || str_contains($message, 'duplicate');

        if (! $hasUniqueEvidence) {
            return false;
        }

        return $code === '23000'
            || str_contains($message, 'assignment_submissions');
    }
}
