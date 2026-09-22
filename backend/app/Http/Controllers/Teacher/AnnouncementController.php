<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teacher\StoreAnnouncementRequest;
use App\Http\Requests\Teacher\UpdateAnnouncementRequest;
use App\Services\AnnouncementService;
use App\Support\Pagination;
use App\Support\SafeUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Announcement endpoints: ARCH-005 block 4.10 (announcements endpoints, #34–#39).
 *
 * Teacher: create/update/delete/list announcements with optional attachments.
 * Student: list announcements from enrolled sections, download attachment.
 *
 * @Traced-To ARCH-002 FR-011, ARCH-002 FR-011, ARCH-002 FR-011, ARCH-002 FR-011, ARCH-002 FR-011, ARCH-002 FR-011, ARCH-002 QA-009, ARCH-002 QA-009
 */
class AnnouncementController extends Controller
{
    public function __construct(private readonly AnnouncementService $announcementService)
    {
    }

    /** GET /api/teacher/announcements (#34) */
    public function teacherIndex(Request $request): JsonResponse
    {
        $announcements = $this->announcementService->getAnnouncementsForTeacher(
            (int) Auth::id(),
            (int) $request->query('page', 1),
            Pagination::perPage($request)
        );

        return response()->json(
            Pagination::response($announcements->through(fn ($a) => [
                'id' => $a->id,
                'subject_id' => $a->subject_id,
                'title' => $a->title,
                'body' => $a->body,
                'has_attachments' => $a->attachments->isNotEmpty(),
                'created_at' => $a->created_at?->toIso8601String(),
            ]))
        )->header('Warning', '299 - "Deprecated: Use classroom-scoped endpoints"');
    }

    /** GET /api/teacher/classrooms/{classroomId}/announcements */
    public function teacherIndexInClassroom(Request $request, int $classroomId): JsonResponse
    {
        $announcements = $this->announcementService->getAnnouncementsForTeacherInClassroom(
            (int) Auth::id(),
            $classroomId,
            (int) $request->query('page', 1),
            Pagination::perPage($request)
        );

        return response()->json(
            Pagination::response($announcements->through(fn ($a) => [
                'id' => $a->id,
                'subject_id' => $a->subject_id,
                'classroom_id' => $a->classroom_id,
                'title' => $a->title,
                'body' => $a->body,
                'has_attachments' => $a->attachments->isNotEmpty(),
                'created_at' => $a->created_at?->toIso8601String(),
            ]))
        );
    }

    /** POST /api/teacher/announcements (#35) */
    public function teacherStore(StoreAnnouncementRequest $request): JsonResponse
    {
        $announcement = $this->announcementService->createAnnouncement(
            (int) Auth::id(),
            (int) ($request->input('subject_id') ?? 0),
            $request->input('title'),
            $request->input('body'),
            $request->file('attachments') ?: []
        );

        return response()->json([
            'data' => [
                'id' => $announcement->id,
                'subject_id' => $announcement->subject_id,
                'title' => $announcement->title,
                'body' => $announcement->body,
                'has_attachments' => $announcement->attachments->isNotEmpty(),
                'created_at' => $announcement->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    /** POST /api/teacher/classrooms/{classroomId}/announcements */
    public function teacherStoreInClassroom(StoreAnnouncementRequest $request, int $classroomId): JsonResponse
    {
        // Semester canonical: semester_id preferred, term_id transition alias.
        $clientSemesterId = $request->input('semester_id') !== null
            ? (int) $request->input('semester_id')
            : ($request->input('term_id') !== null ? (int) $request->input('term_id') : null);
        $announcement = $this->announcementService->createAnnouncementInClassroom(
            (int) Auth::id(),
            $classroomId,
            $request->input('title'),
            $request->input('body'),
            $request->file('attachments') ?: [],
            $request->input('subject_id') !== null ? (int) $request->input('subject_id') : null,
            $clientSemesterId
        );

        return response()->json([
            'data' => [
                'id' => $announcement->id,
                'subject_id' => $announcement->subject_id,
                'classroom_id' => $announcement->classroom_id,
                'title' => $announcement->title,
                'body' => $announcement->body,
                'has_attachments' => $announcement->attachments->isNotEmpty(),
                'created_at' => $announcement->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    /** PUT /api/teacher/announcements/{id} (#36) */
    public function teacherUpdate(UpdateAnnouncementRequest $request, int $id): JsonResponse
    {
        $this->announcementService->updateAnnouncement(
            (int) Auth::id(),
            $id,
            $request->input('title'),
            $request->input('body'),
            $request->hasFile('attachments') ? $request->file('attachments') : null
        );

        return response()->json([
            'data' => ['message' => 'Announcement updated.'],
        ]);
    }

    /** DELETE /api/teacher/announcements/{id} (#37) */
    public function teacherDestroy(int $id): JsonResponse
    {
        $this->announcementService->deleteAnnouncement((int) Auth::id(), $id);

        return response()->json([
            'data' => ['message' => 'Announcement deleted.'],
        ]);
    }

    /** GET /api/student/announcements (#38) */
    public function studentIndex(Request $request): JsonResponse
    {
        $announcements = $this->announcementService->getAnnouncementsForStudent(
            (int) Auth::id(),
            (int) $request->query('page', 1),
            Pagination::perPage($request)
        );

        return response()->json(Pagination::response($announcements))->header('Warning', '299 - "Deprecated: Use classroom-scoped endpoints"');
    }

    /** GET /api/student/announcements/{id}/download/{attachmentId} (#39) */
    public function studentDownload(int $announcementId, int $attachmentId)
    {
        $attachment = $this->announcementService->downloadStudentAttachment(
            (int) Auth::id(),
            $announcementId,
            $attachmentId
        );

        $disk = Storage::disk(config('filesystems.default', 'local'));
        $storageKey = (string) $attachment->filename;

        if (! SafeUpload::isConfined($storageKey, 'announcements')) {
            Log::warning('Announcement download blocked out-of-directory file key.', [
                'path' => SafeUpload::loggable($storageKey),
            ]);

            return response()->json([
                'error' => [
                    'message' => 'The requested file was not found.',
                    'code' => 'NOT_FOUND',
                ],
            ], 404);
        }

        if (! $disk->exists($storageKey)) {
            return response()->json([
                'error' => [
                    'message' => 'The requested file was not found.',
                    'code' => 'NOT_FOUND',
                ],
            ], 404);
        }

        $downloadName = SafeUpload::downloadName($attachment->original_filename);

        if ($downloadName !== (string) $attachment->original_filename) {
            Log::warning('Announcement download filename sanitized.', [
                'original' => SafeUpload::loggable((string) $attachment->original_filename),
                'sanitized' => $downloadName,
            ]);
        }

        return $disk->download($storageKey, $downloadName);
    }
}
