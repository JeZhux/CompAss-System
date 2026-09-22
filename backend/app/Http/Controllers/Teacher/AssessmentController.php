<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Requests\Student\SubmitAssessmentRequest;
use App\Http\Requests\Student\UpdateAttemptResponseRequest;
use App\Http\Requests\Teacher\AutoSaveRequest;
use App\Http\Requests\Teacher\ScoreSubjectiveItemsRequest;
use App\Http\Requests\Teacher\StoreAssessmentItemRequest;
use App\Http\Requests\Teacher\StoreAssessmentRequest;
use App\Http\Requests\Teacher\UpdateAssessmentItemRequest;
use App\Http\Requests\Teacher\UpdateAssessmentRequest;
use App\Services\AssessmentService;
use App\Support\Pagination;
use App\Support\SafeUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Controller;

/**
 * Assessment endpoints: ARCH-005 blocks 4.12, 4.4 and 4.5 (assessment CRUD / taking / results-release endpoints, #53–#63).
 *
 * Teacher: create/list/update/delete assessments, manage items,
 * release, release-results, view pending-grading, score subjective items.
 *
 * Student: start/list/get assessments, auto-save, update response,
 * submit, view results.
 *
 * @Traced-To ARCH-002 FR-015–ARCH-002 FR-022, ARCH-002 FR-015–ARCH-002 FR-019, ARCH-002 FR-029, ARCH-003 ADR-002, ARCH-002 FR-018, ARCH-002 FR-020, ARCH-002 FR-021,
 *   ARCH-002 QA-007, ARCH-002 QA-002, ARCH-002 QA-009, ARCH-002 QA-010
 */
class AssessmentController extends Controller
{
    public function __construct(private readonly AssessmentService $assessmentService)
    {
    }

    // ========================================================================
    // Teacher: Assessment CRUD
    // ========================================================================

    /** GET /api/teacher/assessments (#53) */
    public function teacherIndex(Request $request): JsonResponse
    {
        $subjectId = $request->query('subject_id') !== null
            ? (int) $request->query('subject_id')
            : ($request->query('section_id') ? (int) $request->query('section_id') : null);
        $assessments = $this->assessmentService->listAssessmentsForTeacher(
            (int) Auth::id(),
            $subjectId,
            $request->query('status') ?: null,
            (int) $request->query('page', 1),
            Pagination::perPage($request)
        );

        return response()->json(Pagination::response($assessments))->header('Warning', '299 - "Deprecated: Use classroom-scoped endpoints"');
    }

    /** GET /api/teacher/classrooms/{classroomId}/assessments */
    public function teacherIndexInClassroom(Request $request, int $classroomId): JsonResponse
    {
        $assessments = $this->assessmentService->listAssessmentsForTeacherInClassroom(
            (int) Auth::id(),
            $classroomId,
            $request->query('status') ?: null,
            (int) $request->query('page', 1),
            Pagination::perPage($request)
        );

        return response()->json(Pagination::response($assessments));
    }

    /** GET /api/teacher/assessments/{id} (#54) — full assessment with items (including correct_answer) */
    public function teacherShow(int $id): JsonResponse
    {
        $assessment = $this->assessmentService->getAssessmentDetail((int) Auth::id(), $id);

        return response()->json(['data' => $assessment]);
    }

    /** POST /api/teacher/assessments (#55) */
    public function teacherStore(StoreAssessmentRequest $request): JsonResponse
    {
        $assessment = $this->assessmentService->createAssessment(
            (int) Auth::id(),
            (int) ($request->input('subject_id') ?? 0),
            $request->input('title'),
            $request->input('description'),
            $request->input('type'),
            $request->input('time_limit'),
            $request->input('availability_starts_at') || $request->input('availability_ends_at')
                ? [
                    'start' => $request->input('availability_starts_at'),
                    'end' => $request->input('availability_ends_at'),
                ]
                : null
        );

        return response()->json([
            'data' => [
                'id' => $assessment->id,
                'title' => $assessment->title,
                'type' => $assessment->type,
                'item_count' => 0,
                'created_at' => $assessment->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    /** POST /api/teacher/classrooms/{classroomId}/assessments */
    public function teacherStoreInClassroom(StoreAssessmentRequest $request, int $classroomId): JsonResponse
    {
        $availabilityWindow = $request->input('availability_starts_at') || $request->input('availability_ends_at')
            ? [
                'start' => $request->input('availability_starts_at'),
                'end' => $request->input('availability_ends_at'),
            ]
            : null;

        // Semester canonical: semester_id preferred, term_id transition alias.
        $clientSemesterId = $request->input('semester_id') !== null
            ? (int) $request->input('semester_id')
            : ($request->input('term_id') !== null ? (int) $request->input('term_id') : null);
        $assessment = $this->assessmentService->createAssessmentInClassroom(
            (int) Auth::id(),
            $classroomId,
            $request->input('title'),
            $request->input('description'),
            $request->input('type'),
            $request->input('time_limit'),
            $availabilityWindow,
            $request->input('subject_id') !== null ? (int) $request->input('subject_id') : null,
            $clientSemesterId
        );

        return response()->json([
            'data' => [
                'id' => $assessment->id,
                'classroom_id' => $assessment->classroom_id,
                'title' => $assessment->title,
                'type' => $assessment->type,
                'item_count' => 0,
                'created_at' => $assessment->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    /** PUT /api/teacher/assessments/{id} (#56) */
    public function teacherUpdate(UpdateAssessmentRequest $request, int $id): JsonResponse
    {
        $this->assessmentService->updateAssessment(
            (int) Auth::id(),
            $id,
            $request->input('title'),
            $request->input('description')
        );

        return response()->json(['data' => ['message' => 'Assessment updated.']]);
    }

    /** DELETE /api/teacher/assessments/{id} (#57) */
    public function teacherDestroy(int $id): JsonResponse
    {
        $this->assessmentService->deleteDraftAssessment((int) Auth::id(), $id);

        return response()->json(['data' => ['message' => 'Draft assessment deleted.']]);
    }

    // ========================================================================
    // Teacher: Assessment Items
    // ========================================================================

    /** POST /api/teacher/assessments/{id}/items (#58) */
    public function teacherStoreItem(StoreAssessmentItemRequest $request, int $id): JsonResponse
    {
        $item = $this->assessmentService->addItem(
            (int) Auth::id(),
            $id,
            $request->input('item_type'),
            $request->input('prompt'),
            $request->input('max_points'),
            $request->input('correct_answer'),
            (int) $request->input('competency_tag_id'),
            $request->file('attachments') ?: []
        );

        return response()->json([
            'data' => [
                'id' => $item->id,
                'assessment_id' => $item->assessment_id,
                'item_type' => $item->item_type,
                'prompt' => $item->prompt,
                'max_points' => $item->max_points,
                'sort_order' => $item->sort_order,
                'has_attachments' => $item->attachments->isNotEmpty(),
            ],
        ], 201);
    }

    /** PUT /api/teacher/items/{id} (#59) */
    public function teacherUpdateItem(UpdateAssessmentItemRequest $request, int $id): JsonResponse
    {
        $this->assessmentService->updateItem(
            (int) Auth::id(),
            $id,
            $request->input('item_type'),
            $request->input('prompt'),
            $request->input('max_points'),
            $request->input('correct_answer'),
            $request->input('competency_tag_id')
        );

        return response()->json(['data' => ['message' => 'Item updated.']]);
    }

    /** DELETE /api/teacher/items/{id} (#60) */
    public function teacherDestroyItem(int $id): JsonResponse
    {
        $this->assessmentService->deleteItem((int) Auth::id(), $id);

        return response()->json(['data' => ['message' => 'Item deleted.']]);
    }

    // ========================================================================
    // Teacher: Release flow
    // ========================================================================

    /** POST /api/teacher/assessments/{id}/release (#61) — returns status: "released" */
    public function teacherRelease(int $id): JsonResponse
    {
        $this->assessmentService->releaseAssessment((int) Auth::id(), $id);

        return response()->json([
            'data' => [
                'message' => 'Assessment released.',
                'status' => 'released',
            ],
        ]);
    }

    /** GET /api/teacher/pending-grading (#62) */
    public function teacherPendingGrading(Request $request): JsonResponse
    {
        $submissions = $this->assessmentService->pendingGrading(
            (int) Auth::id(),
            $request->query('assessment_id') ? (int) $request->query('assessment_id') : null,
            (int) $request->query('page', 1),
            Pagination::perPage($request)
        );

        return response()->json(
            Pagination::response($submissions->through(fn ($s) => [
                'id' => $s->id,
                'assessment_id' => $s->assessment_id,
                'student_id' => $s->student_id,
                'student_name' => $s->student->name,
                'attempt_id' => $s->attempt_id,
                'submitted_at' => $s->submitted_at?->toIso8601String(),
                'created_at' => $s->created_at?->toIso8601String(),
            ]))
        );
    }

    /** GET /api/teacher/submissions/{id}/pending-items (#63) */
    public function teacherShowSubmissionPendingItems(int $id): JsonResponse
    {
        $result = $this->assessmentService->getPendingItems((int) Auth::id(), $id);

        return response()->json(['data' => $result]);
    }

    /** POST /api/teacher/submissions/{id}/score (#64) */
    public function teacherScoreSubmission(ScoreSubjectiveItemsRequest $request, int $id): JsonResponse
    {
        $result = $this->assessmentService->scoreSubjectiveItems(
            (int) Auth::id(),
            $id,
            $request->input('scores')
        );

        return response()->json(['data' => $result]);
    }

    // ========================================================================
    // Teacher: Release Results
    // ========================================================================

    /** POST /api/teacher/assessments/{id}/release-results (#65) */
    public function teacherReleaseResults(int $id): JsonResponse
    {
        $result = $this->assessmentService->releaseResults((int) Auth::id(), $id);

        return response()->json([
            'data' => [
                'message' => 'Results released.',
                // ARCH-005 block 4.5: service always returns the persisted
                // results_released_at; ai_explanations_triggered semantics
                // are deleted entirely and must not appear in the payload.
                'results_released_at' => $result['results_released_at'],
            ],
        ]);
    }

    // ========================================================================
    // Student: Assessment taking
    // ========================================================================

    /** GET /api/student/assessments (#66) */
    public function studentIndex(): JsonResponse
    {
        $assessments = $this->assessmentService->getAvailableAssessments((int) Auth::id());

        return response()->json(['data' => $assessments])->header('Warning', '299 - "Deprecated: Use classroom-scoped endpoints"');
    }

    /** GET /api/student/assessments/{id} (#67) */
    public function studentShow(int $id): JsonResponse
    {
        $assessment = $this->assessmentService->getStudentAssessment((int) Auth::id(), $id);

        return response()->json(['data' => $assessment]);
    }

    /** POST /api/student/assessments/{id}/start (#68) */
    public function studentStart(int $id): JsonResponse
    {
        $result = $this->assessmentService->startAssessment((int) Auth::id(), $id);

        return response()->json(['data' => $result]);
    }

    /** PUT /api/student/assessments/{id}/auto-save (#69) */
    public function studentAutoSave(AutoSaveRequest $request, int $id): JsonResponse
    {
        $this->assessmentService->autoSave((int) Auth::id(), $id, $request->input('responses'));

        return response()->json(['data' => ['message' => 'Auto-save successful.']]);
    }

    /** PATCH /api/student/assessments/{id}/attempts/{attemptId}/response (#70) */
    public function studentPatchResponse(UpdateAttemptResponseRequest $request, int $id, int $attemptId): JsonResponse
    {
        $questionId = (int) $request->input('questionId');
        $response = $request->input('response');

        $this->assessmentService->saveResponse((int) Auth::id(), $attemptId, $questionId, $response);

        return response()->json(['data' => ['message' => 'Response saved.']]);
    }

    /** POST /api/student/assessments/{id}/submit (#71) */
    public function studentSubmitAssessment(SubmitAssessmentRequest $request, int $id): JsonResponse
    {
        $result = $this->assessmentService->submitAssessment(
            (int) Auth::id(),
            $id,
            $request->input('responses')
        );

        return response()->json(['data' => $result]);
    }

    // ========================================================================
    // Student: Results
    // ========================================================================

    /** GET /api/student/assessments/{id}/results (#72) */
    public function studentResults(int $id): JsonResponse
    {
        $results = $this->assessmentService->getStudentResults((int) Auth::id(), $id);

        return response()->json(['data' => $results]);
    }

    /** GET /api/teacher/items/{itemId}/attachments/{attachmentId}/download (F-14) */
    public function teacherDownloadItemAttachment(int $itemId, int $attachmentId)
    {
        $attachment = $this->assessmentService->downloadTeacherItemAttachment(
            (int) Auth::id(),
            $itemId,
            $attachmentId
        );

        return $this->downloadItemAttachment($attachment->filename, $attachment->original_filename);
    }

    /** GET /api/student/items/{itemId}/attachments/{attachmentId}/download (F-14) */
    public function studentDownloadItemAttachment(int $itemId, int $attachmentId)
    {
        $attachment = $this->assessmentService->downloadStudentItemAttachment(
            (int) Auth::id(),
            $itemId,
            $attachmentId
        );

        return $this->downloadItemAttachment($attachment->filename, $attachment->original_filename);
    }

    /**
     * Serve exact stored bytes with a header-safe disposition (F-03/F-14).
     * Unconfined keys and missing files 404 without disclosing the path.
     *
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\JsonResponse
     */
    private function downloadItemAttachment(string $storageKey, ?string $originalFilename)
    {
        $disk = Storage::disk(config('filesystems.default', 'local'));

        if (! SafeUpload::isConfined($storageKey, 'assessment_items')) {
            Log::warning('Assessment item download blocked out-of-directory file key.', [
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
            Log::warning('Assessment item download filename sanitized.', [
                'original' => SafeUpload::loggable((string) $originalFilename),
                'sanitized' => $downloadName,
            ]);
        }

        return $disk->download($storageKey, $downloadName);
    }
}
