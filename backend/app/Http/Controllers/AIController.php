<?php

namespace App\Http\Controllers;

use App\Exceptions\AIServiceException;
use App\Exceptions\BusinessRuleConflictException;
use App\Http\Requests\Student\ExplainFurtherRequest;
use App\Http\Requests\Teacher\StoreLearningMaterialRequest;
use App\Http\Requests\Teacher\UpdateLearningMaterialRequest;
use App\Models\Classroom;
use App\Models\Subject;
use App\Services\AIService;
use App\Services\LearningMaterialService;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * AI Post-Assessment Support endpoints: ARCH-005 block 4.7 (AI post-assessment support surface, #83–#95).
 *
 * Teacher (ARCH-002 FR-026): #83 list, #84 create, #85 delete, #86 update learning materials.
 * Teacher (ARCH-002 FR-030): #90 moderation-log list, #91 detail, #92 flag, #93 note, #94 disable.
 * Student (ARCH-002 FR-027, ARCH-002 FR-027, ARCH-002 FR-027, ARCH-002 FR-028): #87 list, #88 detail, #89 explain-further.
 * Any auth (ARCH-002 QA-002): #95 AI status.
 *
 * Role enforcement is on the route middleware; these handlers only resolve the
 * authenticated principal and delegate to the services (ARCH-002 QA-004).
 *
 * @Traced-To ARCH-002 FR-027, ARCH-002 FR-026, ARCH-002 FR-028, ARCH-002 FR-027, ARCH-002 FR-028, ARCH-002 FR-029, ARCH-002 FR-029, ARCH-002 FR-030,
 *   ARCH-002 QA-009, ARCH-002 FR-029, ARCH-002 QA-010, ARCH-002 FR-028, ARCH-002 QA-008, ARCH-002 FR-027, ARCH-002 FR-030, ARCH-002 FR-030,
 *   ARCH-002 QA-008, ARCH-002 FR-028, ARCH-002 QA-002, ARCH-002 QA-004, ARCH-002 QA-009, ARCH-002 QA-010 (ARCH-005 block 4.7)
 */
class AIController extends Controller
{
    public function __construct(
        private readonly AIService $aiService,
        private readonly LearningMaterialService $learningMaterialService,
    ) {
    }

    // ========================================================================
    // Teacher: Learning Materials (#83–#86)
    // ========================================================================

    /**
     * GET /api/teacher/learning-materials (#83)
     *
     * Paginated list of learning materials for a teacher's subject-section.
     * Optional ?competency_id scopes to one competency; ?page/&per_page for paging
     * (page/per_page clamped to 1..100 defensively).
     *
     * @Traced-To ARCH-002 FR-026, ARCH-002 QA-009, ARCH-002 QA-010, ARCH-002 QA-004 (ARCH-005 block 4.7)
     */
    public function indexLearningMaterials(Request $request): JsonResponse
    {
        // Semester vocabulary only: `subject_section_id` was removed with the
        // subject_sections table (410 GONE stub). It is intentionally ignored
        // here — callers must supply `subject_id`.
        $subjectId = (int) $request->query('subject_id');

        if ($subjectId <= 0) {
            throw new HttpException(400, 'subject_id is required.');
        }

        $this->ensureTeacherOwnsSubject((int) Auth::id(), $subjectId);

        $competencyId = $request->query('competency_id')
            ? (int) $request->query('competency_id')
            : null;
        $page = max(1, (int) $request->query('page', 1));

        $materials = $this->learningMaterialService->getLearningMaterials(
            $subjectId,
            $competencyId,
            $page,
            Pagination::perPage($request)
        );

        return response()->json(
            Pagination::response($materials->through(fn ($m) => [
                'id' => $m->id,
                'subject_id' => $m->subject_id,
                'competency_id' => $m->competency_id,
                'original_filename' => $m->original_filename,
                'mime_type' => $m->mime_type,
                'file_size' => $m->file_size,
                'created_at' => $m->created_at?->toIso8601String(),
            ]))
        );
    }

    /**
     * POST /api/teacher/learning-materials (#84)
     *
     * Create a new learning material (PDF or DOCX, max 15 MB).
     *
     * @Traced-To ARCH-002 FR-026, ARCH-002 QA-009, ARCH-002 QA-010, ARCH-002 QA-009, ARCH-002 QA-010 (ARCH-005 block 4.7)
     */
    public function storeLearningMaterial(StoreLearningMaterialRequest $request): JsonResponse
    {
        $teacherId = (int) Auth::id();
        // Semester vocabulary only: `subject_section_id` removed — must supply `subject_id`.
        $subjectId = (int) $request->input('subject_id');

        $this->ensureTeacherOwnsSubject($teacherId, $subjectId);

        $material = $this->learningMaterialService->storeLearningMaterial(
            $teacherId,
            $subjectId,
            (int) $request->input('competency_id'),
            $request->input('title'),
            $request->file('file')
        );

        return response()->json([
            'data' => [
                'id' => $material->id,
                'subject_id' => $material->subject_id,
                'competency_id' => $material->competency_id,
                'original_filename' => $material->original_filename,
                'mime_type' => $material->mime_type,
                'file_size' => $material->file_size,
                'created_at' => $material->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * DELETE /api/teacher/learning-materials/{id} (#85)
     *
     * @Traced-To ARCH-002 FR-026 (ARCH-005 block 4.7)
     */
    public function destroyLearningMaterial(int $id): JsonResponse
    {
        $this->learningMaterialService->deleteLearningMaterial((int) Auth::id(), $id);

        return response()->json([
            'data' => ['message' => 'Learning material deleted.'],
        ]);
    }

    /**
     * PUT /api/teacher/learning-materials/{id} (#86)
     *
     * Update title and/or file of an existing learning material.
     *
     * @Traced-To ARCH-002 FR-026, UC-46 (ARCH-005 block 4.7)
     */
    public function updateLearningMaterial(UpdateLearningMaterialRequest $request, int $id): JsonResponse
    {
        $material = $this->learningMaterialService->updateLearningMaterial(
            (int) Auth::id(),
            $id,
            $request->input('title'),
            $request->file('file')
        );

        return response()->json([
            'data' => [
                'id' => $material->id,
                'subject_id' => $material->subject_id,
                'competency_id' => $material->competency_id,
                'original_filename' => $material->original_filename,
                'mime_type' => $material->mime_type,
                'file_size' => $material->file_size,
                'created_at' => $material->created_at?->toIso8601String(),
            ],
        ]);
    }

    // ========================================================================
    // Student: AI Explanations (#87–#89)
    // ========================================================================

    /**
     * GET /api/student/assessments/{id}/explanations (#87)
     *
     * Pure read of the student's STORED AI explanations for a released
     * assessment (ARCH-002 FR-024): viewing never triggers generation, never calls
     * OpenRouter, and never writes (ARCH-002 FR-024 "reviewing never triggers", ARCH-002 FR-027).
     * Rows exist only if the student explicitly requested generation via the
     * POST .../explanations/generate endpoint; until then the list is empty.
     *
     * @Traced-To ARCH-002 FR-027, ARCH-002 FR-027, ARCH-002 FR-027, ARCH-002 FR-028, ARCH-002 FR-028, ARCH-002 FR-028, ARCH-002 QA-002,
     *   ARCH-002 FR-028, ARCH-002 QA-008 (ARCH-005 block 4.7)
     */
    public function listExplanations(int $assessmentId): JsonResponse
    {
        $explanations = $this->aiService->listExplanationsForAssessment(
            (int) Auth::id(),
            $assessmentId
        );

        return response()->json(['data' => $explanations]);
    }

    /**
     * POST /api/student/assessments/{id}/explanations/generate
     *
     * Explicit student-initiated generation of the student's AI explanations
     * for a released assessment — the ONLY production generation trigger
     * (ARCH-002 FR-024, post-release). Returns 200 with the full explanation list in
     * the exact #87 payload shape. Throttled by the dedicated per-student
     * 'ai-generate' limiter (ARCH-002 QA-003 — never keyed by IP).
     *
     * @Traced-To ARCH-002 FR-027, ARCH-002 FR-027, ARCH-002 FR-027, ARCH-002 QA-003, ARCH-002 FR-024 (ARCH-005 block 4.7)
     */
    public function generateExplanations(int $assessmentId): JsonResponse
    {
        $explanations = $this->aiService->generateExplanationsForAssessment(
            (int) Auth::id(),
            $assessmentId
        );

        return response()->json(['data' => $explanations]);
    }

    /**
     * GET /api/student/explanations/{id} (#88)
     *
     * Full explanation detail with follow-up eligibility, teacher note,
     * disclaimer flags, and remaining turn count.
     *
     * @Traced-To ARCH-002 FR-027, ARCH-002 FR-027, ARCH-002 FR-028, ARCH-002 FR-028, ARCH-002 FR-029, ARCH-002 FR-029, ARCH-002 FR-030, ARCH-002 FR-028,
     *   ARCH-002 QA-002, ARCH-002 FR-029, ARCH-002 FR-028, ARCH-002 QA-008, ARCH-002 FR-027, ARCH-002 FR-030, ARCH-002 FR-030 (ARCH-005 block 4.7)
     */
    public function showExplanation(int $explanationId): JsonResponse
    {
        $explanation = $this->aiService->getExplanation(
            (int) Auth::id(),
            $explanationId
        );

        return response()->json(['data' => $explanation]);
    }

    /**
     * POST /api/student/explanations/{id}/explain-further (#89)
     *
     * Request a simplified follow-up explanation for an objective item.
     * The service throws on rejection (subjective item, turn limit, or
     * disabled) and on AI service outage — these propagate to the
     * exception renderer.
     *
     * @Traced-To ARCH-002 FR-027, ARCH-002 FR-029, ARCH-002 FR-029, ARCH-002 QA-008, ARCH-002 QA-002, ARCH-002 FR-029, ARCH-002 FR-027,
     *   ARCH-002 FR-030 (ARCH-005 block 4.7)
     */
    public function explainFurther(ExplainFurtherRequest $request, int $explanationId): JsonResponse
    {
        $result = $this->aiService->explainFurther(
            (int) Auth::id(),
            $explanationId,
            (int) $request->input('item_id')
        );

        return response()->json(['data' => $result]);
    }

    // ========================================================================
    // Teacher: Moderation Log (#90–#94)
    // ========================================================================

    /**
     * GET /api/teacher/moderation-log (#90)
     *
     * Paginated list of AI explanations for moderation, with student
     * name, flagged status, teacher note, and explain-further-disabled flag.
     * Optional ?assessment_id and ?subject_id scope the results (?subject_id
     * matches subjects). ?page/&per_page for paging (page/per_page clamped
     * to 1..100 defensively).
     *
     * @Traced-To ARCH-002 FR-030 (ARCH-005 block 4.7)
     */
    public function moderationLog(Request $request): JsonResponse
    {
        $teacherId = (int) Auth::id();
        $subjectId = $request->query('subject_id') !== null
            ? (int) $request->query('subject_id')
            : ($request->query('section_id') !== null ? (int) $request->query('section_id') : null);
        $assessmentId = $request->query('assessment_id')
            ? (int) $request->query('assessment_id')
            : null;
        $page = max(1, (int) $request->query('page', 1));

        $log = $this->aiService->getModerationLog(
            $teacherId,
            $subjectId,
            $assessmentId,
            $page,
            Pagination::perPage($request)
        );

        return response()->json(Pagination::response($log));
    }

    /**
     * GET /api/teacher/moderation-log/{id} (#91)
     *
     * Single explanation detail with full student context and school_id.
     * Flagged explanations remain visible to students (ARCH-002 FR-030).
     *
     * @Traced-To ARCH-002 FR-030, ARCH-002 FR-030 (ARCH-005 block 4.7)
     */
    public function moderationLogShow(int $id): JsonResponse
    {
        $explanation = $this->aiService->getExplanation(
            (int) Auth::id(),
            $id
        );

        return response()->json(['data' => $explanation]);
    }

    /**
     * POST /api/teacher/moderation-log/{id}/flag (#92)
     *
     * Flag an explanation for review. The explanation remains visible
     * to the student (ARCH-002 FR-030). Optional note (JSON request body).
     *
     * @Traced-To ARCH-002 FR-030, ARCH-002 FR-030 (ARCH-005 block 4.7)
     */
    public function flagExplanation(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $note = $request->input('note');

        $this->aiService->flagExplanation(
            (int) Auth::id(),
            $id,
            $note
        );

        return response()->json([
            'data' => ['message' => 'Explanation flagged.'],
        ]);
    }

    /**
     * POST /api/teacher/moderation-log/{id}/note (#93)
     *
     * Append a teacher note to an explanation. ?note is required (422 if missing).
     *
     * @Traced-To ARCH-002 FR-030 (ARCH-005 block 4.7)
     */
    public function appendTeacherNote(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'note' => ['required', 'string', 'max:5000'],
        ]);

        $this->aiService->appendTeacherNote(
            (int) Auth::id(),
            $id,
            $request->input('note')
        );

        return response()->json([
            'data' => ['message' => 'Teacher note appended.'],
        ]);
    }

    /**
     * POST /api/teacher/moderation-log/{id}/disable-explain-further (#94)
     *
     * Disable further follow-up turns for this explanation. The original
     * explanation remains visible to the student (ARCH-002 FR-030).
     *
     * @Traced-To ARCH-002 FR-030, ARCH-002 FR-030 (ARCH-005 block 4.7)
     */
    public function disableExplainFurther(int $id): JsonResponse
    {
        $this->aiService->disableExplainFurther((int) Auth::id(), $id);

        return response()->json([
            'data' => ['message' => 'Explain-further disabled for this explanation.'],
        ]);
    }

    // ========================================================================
    // Any Authenticated: AI Status (#95)
    // ========================================================================

    /**
     * GET /api/ai/status (#95)
     *
     * Lightweight health check. Returns 200 with { status: "available" }
     * or throws 503 AI_SERVICE_UNAVAILABLE when unavailable (ARCH-002 QA-002, ARCH-002 QA-008).
     *
     * @Traced-To ARCH-002 QA-002 (ARCH-005 block 4.7)
     */
    public function aiStatus(): JsonResponse
    {
        $result = $this->aiService->aiStatus();

        if (($result['status'] ?? 'unavailable') !== 'available') {
            throw new AIServiceException(
                AIService::AI_UNAVAILABLE_MESSAGE,
                'AI_SERVICE_UNAVAILABLE',
                503
            );
        }

        return response()->json(['data' => $result]);
    }

    // ========================================================================
    // Private helpers
    // ========================================================================

    /**
     * Validate that the teacher owns the subject package via at least one
     * classroom for that subject (classroom-derived scope, ARCH-002 QA-004).
     */
    private function ensureTeacherOwnsSubject(int $teacherId, int $subjectId): void
    {
        Subject::findOrFail($subjectId);

        $owns = Classroom::query()
            ->where('teacher_id', $teacherId)
            ->where('subject_id', $subjectId)
            ->exists();

        if (! $owns) {
            throw new BusinessRuleConflictException(
                'You are not assigned to this subject.',
                'SUBJECT_NOT_ASSIGNED',
                403
            );
        }
    }
}
