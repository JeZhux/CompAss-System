<?php

namespace App\Services;

use App\Exceptions\BusinessRuleConflictException;
use App\Models\Announcement;
use App\Models\AnnouncementAttachment;
use App\Models\Classroom;
use App\Models\ClassroomEnrollment;
use App\Support\SafeUpload;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Creates, edits, deletes, and queries Announcements within a subject-section (ARCH-001 §5.1).
 *
 * Collaborators: OrgStructureService (reads TeacherSectionAssignment and
 * ClassroomEnrollment for scoping).
 *
 * Invariants (ARCH-001 §5.1 / ARCH-002 FR-011/ARCH-002 FR-011/ARCH-002 QA-009):
 *  - Announcements are scoped to a subject-section; no cross-section data leakage (ARCH-002 FR-011).
 *  - File attachment constraints follow ARCH-002 QA-009 uniformly (ARCH-002 QA-009).
 *
 * @Traced-To ARCH-002 FR-011, ARCH-002 FR-011, ARCH-002 FR-011, ARCH-002 FR-011, ARCH-002 FR-011, ARCH-002 FR-011, ARCH-002 QA-009, ARCH-002 QA-009, ARCH-002 QA-006
 */
class AnnouncementService
{
    /** @var string Root directory for announcement attachments. */
    private const ATTACHMENT_DIR = 'announcements';

    /** @var int Max attachment files per announcement (ARCH-002 QA-009). */
    public const MAX_ATTACHMENTS = 5;

    /** @var int Max file size in bytes — 15 MB (ARCH-002 QA-009). */
    public const MAX_FILE_SIZE = 15 * 1024 * 1024;

    /** @var string[] Allowed attachment extensions (ARCH-002 QA-009 / ARCH-002 QA-009). */
    public const ALLOWED_EXTENSIONS = ['pdf', 'docx', 'pptx', 'xlsx', 'jpg', 'jpeg', 'png', 'zip'];

    /** @var string[] Allowed attachment MIME types, mirrored from ALLOWED_EXTENSIONS (ARCH-002 QA-009 / ARCH-002 QA-009). */
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
     * Audit logging of Announcement CRUD (ARCH-001 §5.1 / ARCH-002 QA-006).
     */
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /**
     * Create an Announcement within the teacher's assigned subject-section (ARCH-002 FR-011, ARCH-002 FR-011).
     *
     * @param  UploadedFile[]|null  $attachments
     *
     * @Traced-To ARCH-002 FR-011, ARCH-002 FR-011, ARCH-002 QA-009, ARCH-002 QA-009, ARCH-002 QA-006 (ARCH-001 §5.1)
     */
    public function createAnnouncement(int $teacherId, int $subjectId, string $title, string $body, ?array $attachments = []): Announcement
    {
        // U-04 — legacy flat create is closed: content must be created via the
        // classroom-scoped endpoint so every grade path carries a classroom.
        // Thrown before any file/DB write, so a 422 never orphans attachments.
        throw ValidationException::withMessages([
            'classroom_id' => ['The classroom_id field is required. Use the classroom-scoped endpoint.'],
        ]);
    }

    /**
     * U03 — Classroom-scoped create. Sets classroom_id and subject_id server-side from classroom.
     * Rejects mismatched client subject_id/semester_id with 422, enforces ownership 403, archived 409.
     *
     * @param  UploadedFile[]|null  $attachments
     */
    public function createAnnouncementInClassroom(int $teacherId, int $classroomId, string $title, string $body, ?array $attachments = [], ?int $clientSubjectId = null, ?int $clientSemesterId = null): Announcement
    {
        $classroom = Classroom::findOrFail($classroomId);

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

        // Semester is derived from the classroom's grade level; a supplied
        // value that mismatches is a 422 (stale-param guard, Semester canonical).
        if ($clientSemesterId !== null) {
            $classroom->loadMissing(['section.gradeLevel', 'subject.gradeLevel']);
            $expectedSemesterId = $classroom->section?->gradeLevel?->semester_id ?? $classroom->subject?->gradeLevel?->semester_id;
            if ($expectedSemesterId !== null && (int) $clientSemesterId !== (int) $expectedSemesterId) {
                throw ValidationException::withMessages([
                    'semester_id' => ['The semester_id does not match the classroom\'s semester.'],
                ]);
            }
        }

        $subjectId = $classroom->subject_id;

        // Classroom ownership is authoritative — no separate assignment check.

        $attachments = $attachments ?? [];

        if (count($attachments) > self::MAX_ATTACHMENTS) {
            throw new BusinessRuleConflictException(
                'A maximum of ' . self::MAX_ATTACHMENTS . ' attachments is allowed per announcement.',
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

        $announcement = DB::transaction(function () use ($teacherId, $classroomId, $subjectId, $title, $body, $attachments): Announcement {
            $announcement = Announcement::create([
                'teacher_id' => $teacherId,
                'classroom_id' => $classroomId,
                'subject_id' => $subjectId,
                'title' => $title,
                'body' => $body,
            ]);

            if (! empty($attachments)) {
                $this->storeAttachments($announcement, $attachments);
            }

            return $announcement->load('attachments');
        });

        $this->auditLogService->log(
            'create',
            'Announcement created: ' . $announcement->title,
            Auth::id(),
            Announcement::class,
            $announcement->id,
            ['title' => $announcement->title, 'classroom_id' => $classroomId]
        );

        return $announcement;
    }

    /**
     * U03 — Teacher scoped list by classroom (paginated, owned only)
     */
    public function getAnnouncementsForTeacherInClassroom(int $teacherId, int $classroomId, int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        $classroom = Classroom::findOrFail($classroomId);
        if ((int) $classroom->teacher_id !== $teacherId) {
            throw new BusinessRuleConflictException(
                'You do not own this classroom.',
                'FORBIDDEN',
                403
            );
        }

        return Announcement::query()
            ->where('classroom_id', $classroomId)
            ->where('teacher_id', $teacherId)
            ->with('attachments')
            ->orderByDesc('created_at')
            ->paginate($perPage, page: $page);
    }

    /**
     * U03 — Student stream by classroom (enrolled only, archived filtered)
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAnnouncementsForStudentInClassroom(int $studentId, int $classroomId): array
    {
        $classroom = Classroom::findOrFail($classroomId);

        if ($classroom->archived_at !== null) {
            throw new BusinessRuleConflictException(
                'This classroom has been archived.',
                'CLASSROOM_ARCHIVED',
                410
            );
        }

        $enrolled = ClassroomEnrollment::query()
            ->where('classroom_id', $classroomId)
            ->where('student_id', $studentId)
            ->exists();

        if (! $enrolled) {
            throw new BusinessRuleConflictException(
                'You are not enrolled in this classroom.',
                'NOT_ENROLLED',
                403
            );
        }

        $announcements = Announcement::query()
            ->where('classroom_id', $classroomId)
            ->with(['teacher', 'attachments'])
            ->orderByDesc('created_at')
            ->get();

        return $announcements->map(fn (Announcement $a) => [
            'id' => $a->id,
            'classroom_id' => $a->classroom_id,
            'subject_id' => $a->subject_id,
            'teacher_name' => $a->teacher->name,
            'title' => $a->title,
            'body' => $a->body,
            'has_attachments' => $a->attachments->isNotEmpty(),
            'attachments' => $a->attachments->map(fn (AnnouncementAttachment $att) => [
                'id' => $att->id,
                'original_filename' => $att->original_filename,
                'mime_type' => $att->mime_type,
                'file_size' => $att->file_size,
            ]),
            'created_at' => $a->created_at?->toIso8601String(),
        ])->toArray();
    }

    /**
     * Update an existing Announcement, validating ownership (ARCH-002 FR-011).
     *
     * @param  UploadedFile[]|null  $attachments
     *
     * @Traced-To ARCH-002 FR-011, ARCH-002 QA-009, ARCH-002 QA-006 (ARCH-001 §5.1)
     */
    public function updateAnnouncement(int $teacherId, int $announcementId, ?string $title, ?string $body, ?array $attachments = null): void
    {
        $announcement = $this->findOwnedByTeacher($teacherId, $announcementId);

        if ($attachments !== null) {
            $attachments = $attachments ?? [];

            if (count($attachments) > self::MAX_ATTACHMENTS) {
                throw new BusinessRuleConflictException(
                    'A maximum of ' . self::MAX_ATTACHMENTS . ' attachments is allowed per announcement.',
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

        // Attachment validation is complete before any DB write, so a
        // validation failure can never leave the announcement persisted
        // without a matching audit event (ARCH-002 QA-006).
        DB::transaction(function () use ($announcement, $title, $body, $attachments): void {
            if ($title !== null) {
                $announcement->title = $title;
            }

            if ($body !== null) {
                $announcement->body = $body;
            }

            $announcement->save();

            if ($attachments !== null) {
                foreach ($announcement->attachments as $existing) {
                    if (! SafeUpload::isConfined($existing->filename, self::ATTACHMENT_DIR)) {
                        Log::warning('Announcement update skipped out-of-directory file key.', [
                            'path' => SafeUpload::loggable($existing->filename),
                        ]);
                    } else {
                        SafeUpload::disk()->delete($existing->filename);
                    }
                    $existing->delete();
                }

                if (! empty($attachments)) {
                    $this->storeAttachments($announcement, $attachments);
                }
            }
        });

        $this->auditLogService->log(
            'update',
            'Announcement updated (id ' . $announcement->id . ')',
            Auth::id(),
            Announcement::class,
            $announcement->id,
            ['title' => $announcement->title]
        );
    }

    /**
     * Delete an Announcement, validating ownership (ARCH-002 FR-011).
     *
     * @Traced-To ARCH-002 FR-011, ARCH-002 QA-006 (ARCH-001 §5.1)
     */
    public function deleteAnnouncement(int $teacherId, int $announcementId): void
    {
        $announcement = $this->findOwnedByTeacher($teacherId, $announcementId);

        DB::transaction(function () use ($announcement): void {
            foreach ($announcement->attachments as $existing) {
                if (! SafeUpload::isConfined($existing->filename, self::ATTACHMENT_DIR)) {
                    Log::warning('Announcement delete skipped out-of-directory file key.', [
                        'path' => SafeUpload::loggable($existing->filename),
                    ]);
                } else {
                    SafeUpload::disk()->delete($existing->filename);
                }
            }

            $announcement->delete();
        });

        $this->auditLogService->log(
            'delete',
            'Announcement deleted (id ' . $announcement->id . ')',
            Auth::id(),
            Announcement::class,
            $announcement->id,
            ['title' => $announcement->title]
        );
    }

    /**
     * Return all Announcements posted by the teacher across their classrooms
     * (ARCH-002 FR-011). Paginated.
     * U-09: classroom-only — every announcement carries a classroom_id (U-04 NOT NULL).
     *
     * @return LengthAwarePaginator<int, Announcement>
     *
     * @Traced-To ARCH-002 FR-011 (ARCH-001 §5.1)
     */
    public function getAnnouncementsForTeacher(int $teacherId, int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        $query = Announcement::query()
            ->where('teacher_id', $teacherId)
            ->with('attachments')
            ->orderByDesc('created_at');

        return $query->paginate($perPage, page: $page);
    }

    /**
     * Return all Announcements visible to the student from their enrolled
     * classrooms only (ARCH-002 FR-011, ARCH-002 FR-011, ARCH-002 FR-011). No cross-classroom leakage.
     * U-09: classroom-only — students with no classroom enrollment see an empty list.
     * F-12: paginated via the shared {data, meta} contract.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     *
     * @Traced-To ARCH-002 FR-011, ARCH-002 FR-011, ARCH-002 FR-011 (ARCH-001 §5.1)
     */
    public function getAnnouncementsForStudent(int $studentId, int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        // U-09 classroom-scoped: student sees only announcements in enrolled non-archived classrooms.
        $enrolledIds = ClassroomEnrollment::where('student_id', $studentId)->pluck('classroom_id')->toArray();

        if (empty($enrolledIds)) {
            return Announcement::query()->whereRaw('1 = 0')->paginate($perPage, page: $page);
        }

        // Filter archived classrooms
        $activeIds = Classroom::whereIn('id', $enrolledIds)->whereNull('archived_at')->pluck('id')->toArray();

        if (empty($activeIds)) {
            return Announcement::query()->whereRaw('1 = 0')->paginate($perPage, page: $page);
        }

        $announcements = Announcement::query()
            ->whereIn('classroom_id', $activeIds)
            ->with(['teacher', 'attachments'])
            ->orderByDesc('created_at')
            ->paginate($perPage, page: $page);

        return $announcements->through(fn (Announcement $a) => [
            'id' => $a->id,
            'classroom_id' => $a->classroom_id,
            'subject_id' => $a->subject_id,
            'teacher_name' => $a->teacher->name,
            'title' => $a->title,
            'body' => $a->body,
            'has_attachments' => $a->attachments->isNotEmpty(),
            'attachments' => $a->attachments->map(fn (AnnouncementAttachment $att) => [
                'id' => $att->id,
                'original_filename' => $att->original_filename,
                'mime_type' => $att->mime_type,
                'file_size' => $att->file_size,
            ]),
            'created_at' => $a->created_at?->toIso8601String(),
        ]);
    }

    /**
     * Download a specific attachment for a student (ARCH-002 FR-011, ARCH-002 FR-011).
     * Validates that the announcement belongs to one of the student's
     * enrolled classrooms (no cross-classroom leakage).
     * U-09: classroom-only.
     *
     * @Traced-To ARCH-002 FR-011, ARCH-002 FR-011, ARCH-002 QA-009 (ARCH-001 §5.1)
     */
    public function downloadStudentAttachment(int $studentId, int $announcementId, int $attachmentId): AnnouncementAttachment
    {
        $announcement = Announcement::findOrFail($announcementId);

        // U-09: student must be enrolled in the announcement's classroom.
        // Unknown id 404s via findOrFail above; unenrolled reads are NOT_ENROLLED 403.
        if ($announcement->classroom_id === null) {
            throw new BusinessRuleConflictException(
                'You are not enrolled in this classroom.',
                'NOT_ENROLLED',
                403
            );
        }
        $activeIds = Classroom::where('id', $announcement->classroom_id)->whereNull('archived_at')->pluck('id')->toArray();
        $enrolled = ClassroomEnrollment::where('student_id', $studentId)->whereIn('classroom_id', $activeIds)->where('classroom_id', $announcement->classroom_id)->exists();
        if (! $enrolled) {
            throw new BusinessRuleConflictException(
                'You are not enrolled in this classroom.',
                'NOT_ENROLLED',
                403
            );
        }

        return AnnouncementAttachment::query()
            ->where('announcement_id', $announcement->id)
            ->where('id', $attachmentId)
            ->firstOrFail();
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    /**
     * Classroom ownership guard: the teacher must own the classroom that
     * scopes the announcement (teacher_id on classrooms).
     *
     * @Traced-To ARCH-002 FR-011 (ARCH-001 §5.1)
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
     * Fetch an announcement that belongs to the teacher (ARCH-002 FR-011 — ownership-scoped).
     *
     * @Traced-To ARCH-002 FR-011 (ARCH-001 §5.1)
     */
    private function findOwnedByTeacher(int $teacherId, int $announcementId): Announcement
    {
        return Announcement::query()
            ->where('id', $announcementId)
            ->where('teacher_id', $teacherId)
            ->firstOrFail();
    }

    /**
     * Persist attachment records + files to storage (ARCH-002 QA-009).
     *
     * @param  UploadedFile[]  $attachments
     */
    private function storeAttachments(Announcement $announcement, array $attachments): void
    {
        $dir = self::ATTACHMENT_DIR . '/' . $announcement->id;

        // Generate all keys before writing any file: a FILENAME_TOO_LONG
        // reject must never leave an earlier batch file orphaned on disk.
        $keys = [];

        foreach ($attachments as $file) {
            $keys[] = SafeUpload::storageKey($dir, $file);
        }

        foreach ($attachments as $i => $file) {
            $filename = $keys[$i];
            SafeUpload::disk()->put($filename, file_get_contents($file->getRealPath()));

            AnnouncementAttachment::create([
                'announcement_id' => $announcement->id,
                'filename' => $filename,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'created_at' => now(),
            ]);
        }

        $announcement->refresh('attachments');
    }

    /**
     * Reject files whose extension or detected MIME type is not in the
     * ARCH-002 QA-009/ARCH-002 QA-009 whitelist. Both the extension AND the byte-detected MIME
     * must be allowed, so a renamed file is rejected even when its name
     * looks valid (ARCH-002 QA-009).
     *
     * @Traced-To ARCH-002 QA-009, ARCH-002 QA-009 (ARCH-001 §5.1)
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
}
