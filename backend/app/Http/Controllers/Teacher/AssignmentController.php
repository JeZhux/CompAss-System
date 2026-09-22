<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\SubmitAssignmentRequest;
use App\Http\Requests\Teacher\StoreAssignmentFeedbackRequest;
use App\Http\Requests\Teacher\StoreAssignmentRequest;
use App\Http\Requests\Teacher\UpdateAssignmentRequest;
use App\Services\AssignmentService;
use App\Support\Pagination;
use App\Support\SafeUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Assignment endpoints: ARCH-005 block 4.11 (assignments endpoints, #40–#52).
 *
 * Teacher: create/update/delete/list assignments, list submissions,
 * view a submission, provide/get feedback.
 * Student: list assignments, view assignment detail, submit files,
 * get feedback.
 *
 * @Traced-To ARCH-002 FR-012–ARCH-002 FR-013, ARCH-002 FR-011, ARCH-002 FR-013, ARCH-002 FR-013, ARCH-002 QA-009, ARCH-002 QA-009, ARCH-002 QA-010
 */
class AssignmentController extends Controller
{
    public function __construct(private readonly AssignmentService $assignmentService)
    {
    }

    // --- Teacher endpoints ---

    /** GET /api/teacher/assignments (#40) */
    public function teacherIndex(Request $request): JsonResponse
    {
        $subjectId = $request->query('subject_id') !== null
            ? (int) $request->query('subject_id')
            : ($request->query('section_id') ? (int) $request->query('section_id') : null);
        $assignments = $this->assignmentService->listTeacherAssignments(
            (int) Auth::id(),
            $subjectId,
            (int) $request->query('page', 1),
            Pagination::perPage($request)
        );

        return response()->json(
            Pagination::response($assignments->through(fn ($a) => [
                'id' => $a->id,
                'title' => $a->title,
                'description' => $a->description,
                'due_date' => $a->due_date?->toIso8601String(),
                'submission_count' => $a->submissions_count ?? 0,
                'created_at' => $a->created_at?->toIso8601String(),
            ]))
        )->header('Warning', '299 - "Deprecated: Use classroom-scoped endpoints"');
    }

    /** GET /api/teacher/classrooms/{classroomId}/assignments */
    public function teacherIndexInClassroom(Request $request, int $classroomId): JsonResponse
    {
        $assignments = $this->assignmentService->listTeacherAssignmentsInClassroom(
            (int) Auth::id(),
            $classroomId,
            (int) $request->query('page', 1),
            Pagination::perPage($request)
        );

        return response()->json(
            Pagination::response($assignments->through(fn ($a) => [
                'id' => $a->id,
                'classroom_id' => $a->classroom_id,
                'title' => $a->title,
                'description' => $a->description,
                'due_date' => $a->due_date?->toIso8601String(),
                'submission_count' => $a->submissions_count ?? 0,
                'created_at' => $a->created_at?->toIso8601String(),
            ]))
        );
    }

    /** POST /api/teacher/assignments (#41) */
    public function teacherStore(StoreAssignmentRequest $request): JsonResponse
    {
        $assignment = $this->assignmentService->createAssignment(
            (int) Auth::id(),
            (int) ($request->input('subject_id') ?? 0),
            $request->input('title'),
            $request->input('description'),
            $request->input('due_date'),
            $request->file('attachments') ?: []
        );

        return response()->json([
            'data' => [
                'id' => $assignment->id,
                'title' => $assignment->title,
                'description' => $assignment->description,
                'due_date' => $assignment->due_date?->toIso8601String(),
            ],
        ], 201);
    }

    /** POST /api/teacher/classrooms/{classroomId}/assignments */
    public function teacherStoreInClassroom(StoreAssignmentRequest $request, int $classroomId): JsonResponse
    {
        // Semester canonical: semester_id preferred, term_id transition alias.
        $clientSemesterId = $request->input('semester_id') !== null
            ? (int) $request->input('semester_id')
            : ($request->input('term_id') !== null ? (int) $request->input('term_id') : null);
        $assignment = $this->assignmentService->createAssignmentInClassroom(
            (int) Auth::id(),
            $classroomId,
            $request->input('title'),
            $request->input('description'),
            $request->input('due_date'),
            $request->file('attachments') ?: [],
            $request->input('subject_id') !== null ? (int) $request->input('subject_id') : null,
            $clientSemesterId
        );

        return response()->json([
            'data' => [
                'id' => $assignment->id,
                'classroom_id' => $assignment->classroom_id,
                'title' => $assignment->title,
                'description' => $assignment->description,
                'due_date' => $assignment->due_date?->toIso8601String(),
            ],
        ], 201);
    }

    /** GET /api/teacher/assignments/{id} (#42) */
    public function teacherShow(int $id): JsonResponse
    {
        $assignment = $this->assignmentService->getAssignmentDetail((int) Auth::id(), $id);

        return response()->json(['data' => $assignment]);
    }

    /** PUT /api/teacher/assignments/{id} (#43) */
    public function teacherUpdate(UpdateAssignmentRequest $request, int $id): JsonResponse
    {
        $this->assignmentService->updateAssignment(
            (int) Auth::id(),
            $id,
            $request->input('title'),
            $request->input('description'),
            $request->input('due_date')
        );

        return response()->json(['data' => ['message' => 'Assignment updated.']]);
    }

    /** DELETE /api/teacher/assignments/{id} (#44) */
    public function teacherDestroy(int $id): JsonResponse
    {
        $result = $this->assignmentService->deleteAssignment((int) Auth::id(), $id);

        if ($result['confirmation_required']) {
            return response()->json([
                'data' => [
                    'confirmation_required' => true,
                    'submission_count' => $result['submission_count'],
                ],
            ]);
        }

        return response()->json([
            'data' => ['message' => 'Assignment deleted.'],
        ]);
    }

    /** POST /api/teacher/assignments/{id}/confirm-delete (#45) */
    public function teacherConfirmDelete(int $id): JsonResponse
    {
        $this->assignmentService->confirmDeleteAssignment((int) Auth::id(), $id);

        return response()->json([
            'data' => ['message' => 'Assignment and all submissions permanently deleted.'],
        ]);
    }

    /** GET /api/teacher/assignments/{id}/submissions (#46) */
    public function teacherSubmissions(Request $request, int $id): JsonResponse
    {
        $submissions = $this->assignmentService->getSubmissions(
            (int) Auth::id(),
            $id,
            (int) $request->query('page', 1),
            Pagination::perPage($request)
        );

        return response()->json(
            Pagination::response($submissions->through(fn ($s) => [
                'id' => $s->id,
                'student_name' => $s->student->name,
                'student_id' => $s->student_id,
                'submitted_at' => $s->submitted_at?->toIso8601String(),
                'is_late' => $s->status === 'late',
                'has_feedback' => $s->feedback !== null,
            ]))
        );
    }

    /** GET /api/teacher/submissions/{submissionId} (#47) */
    public function teacherShowSubmission(int $submissionId): JsonResponse
    {
        $submission = $this->assignmentService->getSubmissionDetail((int) Auth::id(), $submissionId);

        return response()->json(['data' => $submission]);
    }

    /** POST /api/teacher/submissions/{submissionId}/feedback (#48) */
    public function teacherStoreFeedback(StoreAssignmentFeedbackRequest $request, int $submissionId): JsonResponse
    {
        $this->assignmentService->provideFeedback(
            (int) Auth::id(),
            $submissionId,
            $request->input('feedback')
        );

        return response()->json(['data' => ['message' => 'Feedback recorded.']]);
    }

    // --- Student endpoints ---

    /** GET /api/student/assignments (#49) */
    public function studentIndex(Request $request): JsonResponse
    {
        $assignments = $this->assignmentService->listStudentAssignments(
            (int) Auth::id(),
            (int) $request->query('page', 1),
            Pagination::perPage($request)
        );

        return response()->json(Pagination::response($assignments))->header('Warning', '299 - "Deprecated: Use classroom-scoped endpoints"');
    }

    /** GET /api/student/assignments/{id} (#50) */
    public function studentShow(int $id): JsonResponse
    {
        $assignment = $this->assignmentService->getStudentAssignment((int) Auth::id(), $id);

        return response()->json(['data' => $assignment]);
    }

    /** POST /api/student/assignments/{id}/submit (#51) */
    public function studentSubmit(SubmitAssignmentRequest $request, int $id): JsonResponse
    {
        $submission = $this->assignmentService->submitAssignment(
            (int) Auth::id(),
            $id,
            $request->file('files') ?: []
        );

        return response()->json([
            'data' => [
                'id' => $submission->id,
                'submitted_at' => $submission->submitted_at?->toIso8601String(),
                'is_late' => $submission->status === 'late',
                'file_count' => $submission->files->count(),
            ],
        ], 201);
    }

    /** GET /api/student/assignments/{id}/feedback (#52) */
    public function studentFeedback(int $id): JsonResponse
    {
        $feedback = $this->assignmentService->getFeedback((int) Auth::id(), $id);

        if ($feedback === null) {
            return response()->json([
                'error' => [
                    'message' => 'You have not submitted this assignment.',
                    'code' => 'NOT_FOUND',
                ],
            ], 404);
        }

        return response()->json(['data' => $feedback]);
    }

    /** GET /api/teacher/assignments/{id}/attachments/{attachmentId}/download (F-14) */
    public function teacherDownloadAttachment(int $id, int $attachmentId)
    {
        $attachment = $this->assignmentService->downloadTeacherAssignmentAttachment(
            (int) Auth::id(),
            $id,
            $attachmentId
        );

        return $this->downloadAssignmentFile($attachment->filename, $attachment->original_filename);
    }

    /** GET /api/student/assignments/{id}/attachments/{attachmentId}/download (F-14) */
    public function studentDownloadAttachment(int $id, int $attachmentId)
    {
        $attachment = $this->assignmentService->downloadStudentAssignmentAttachment(
            (int) Auth::id(),
            $id,
            $attachmentId
        );

        return $this->downloadAssignmentFile($attachment->filename, $attachment->original_filename);
    }

    /** GET /api/teacher/submissions/{submissionId}/files/{fileId}/download (F-14) */
    public function teacherDownloadSubmissionFile(int $submissionId, int $fileId)
    {
        $file = $this->assignmentService->downloadTeacherSubmissionFile(
            (int) Auth::id(),
            $submissionId,
            $fileId
        );

        return $this->downloadAssignmentFile($file->filename, $file->original_filename);
    }

    /** GET /api/student/submissions/{submissionId}/files/{fileId}/download (F-14) */
    public function studentDownloadSubmissionFile(int $submissionId, int $fileId)
    {
        $file = $this->assignmentService->downloadStudentSubmissionFile(
            (int) Auth::id(),
            $submissionId,
            $fileId
        );

        return $this->downloadAssignmentFile($file->filename, $file->original_filename);
    }

    /**
     * Serve exact stored bytes with a header-safe disposition (F-03/F-14).
     * Unconfined keys and missing files 404 without disclosing the path.
     *
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\JsonResponse
     */
    private function downloadAssignmentFile(string $storageKey, ?string $originalFilename)
    {
        $disk = Storage::disk(config('filesystems.default', 'local'));

        if (! SafeUpload::isConfined($storageKey, 'assignments')) {
            Log::warning('Assignment download blocked out-of-directory file key.', [
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

        $downloadName = SafeUpload::downloadName($originalFilename);

        if ($downloadName !== (string) $originalFilename) {
            Log::warning('Assignment download filename sanitized.', [
                'original' => SafeUpload::loggable((string) $originalFilename),
                'sanitized' => $downloadName,
            ]);
        }

        return $disk->download($storageKey, $downloadName);
    }
}
