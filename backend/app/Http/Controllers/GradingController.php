<?php

namespace App\Http\Controllers;

use App\Http\Requests\Teacher\BulkGradeRequest;
use App\Http\Requests\Teacher\ResubmitRequest;
use App\Http\Requests\Teacher\StoreManualGradeRequest;
use App\Models\User;
use App\Services\GradingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Grading & Mastery endpoints: ARCH-005 block 4.5 (bulk-grading endpoints, #100–#104).
 *
 * #100 manual grade, #101 mastery records, #102 mastery summary,
 * #103 resubmit, #104 bulk grading.
 *
 * Roles: #100/#103/#104 = Teacher; #101/#102 = Teacher/Student/Admin.
 *
 * @Traced-To ARCH-002 FR-018, ARCH-002 FR-018, ARCH-002 FR-019, ARCH-002 FR-020, UC-50, UC-52, UC-53,
 *   ARCH-002 QA-007, ARCH-002 QA-002, ARCH-002 QA-009
 */
class GradingController extends Controller
{
    public function __construct(private readonly GradingService $gradingService)
    {
    }

    /**
     * Convert request grade_entries to the service's questionGrades format.
     *
     * Maps assessment_item_id → questionId, max_score → maxScore
     * per the GradingService contract (ARCH-001 §5.1).
     *
     * @param  array<int, array{
     *              assessment_item_id: int,
     *              score: float,
     *              max_score: float,
     *              feedback?: string|null
     *          }>  $entries
     * @return array<int, array{
     *              questionId: int,
     *              score: float,
     *              maxScore: float,
     *              feedback?: string|null
     *          }>
     */
    private function convertGradeEntries(array $entries): array
    {
        return array_map(fn (array $entry): array => [
            'questionId' => $entry['assessment_item_id'],
            'score' => $entry['score'],
            'maxScore' => $entry['max_score'],
            'feedback' => $entry['feedback'] ?? null,
        ], $entries);
    }

    // ========================================================================
    // Teacher: Manual Grading
    // ========================================================================

    /** POST /api/grades/manual (#100) */
    public function storeManualGrade(StoreManualGradeRequest $request): JsonResponse
    {
        $attemptId = (int) $request->input('attempt_id');
        $graderId = (int) Auth::id();
        $questionGrades = $this->convertGradeEntries($request->input('grade_entries'));

        if ($request->boolean('is_draft')) {
            $result = $this->gradingService->saveGradeDraft($attemptId, $questionGrades, $graderId);
        } else {
            $result = $this->gradingService->recordManualGrade($attemptId, $questionGrades, $graderId);
        }

        return response()->json([
            'data' => [
                'attempt_id' => $result['attempt_id'],
                'grader_id' => $result['grader_id'],
                'graded_at' => $result['graded_at'],
                'status' => $result['status'],
            ],
        ]);
    }

    /** POST /api/grades/bulk (#104) */
    public function bulkGrade(BulkGradeRequest $request): JsonResponse
    {
        $assessmentId = (int) $request->input('assessment_id');
        $teacherId = (int) Auth::id();

        $grades = array_map(fn (array $entry): array => [
            'student_id' => $entry['student_id'],
            'question_grades' => $this->convertGradeEntries($entry['grade_entries']),
        ], $request->input('grades'));

        $result = $this->gradingService->bulkGrade($assessmentId, $grades, $teacherId);

        return response()->json(['data' => $result]);
    }

    /** POST /api/assessments/{assessmentId}/attempts/{attemptId}/resubmit (#103) */
    public function resubmit(ResubmitRequest $request, int $assessmentId, int $attemptId): JsonResponse
    {
        $teacherId = (int) Auth::id();
        $reason = $request->input('reason');

        $result = $this->gradingService->requestResubmission($attemptId, $reason, $teacherId);

        return response()->json(['data' => $result]);
    }

    // ========================================================================
    // Teacher, Student, Admin: Mastery Viewing
    // ========================================================================

    /** GET /api/mastery/records (#101) */
    public function getMasteryRecords(Request $request): JsonResponse
    {
        $studentId = $request->query('student_id')
            ? (int) $request->query('student_id')
            : (int) Auth::id();
        $competencyId = $request->query('competency_id')
            ? (int) $request->query('competency_id')
            : null;
        $subjectId = $request->query('subject_id') !== null
            ? (int) $request->query('subject_id')
            : null;

        // Students may only view their own mastery records (ARCH-002 QA-004, ARCH-002 QA-004).
        $role = strtolower((string) Auth::user()->role);
        if ($role === 'student' && $studentId !== (int) Auth::id()) {
            throw new AuthorizationException();
        }

        $result = $this->gradingService->getMasteryRecord($studentId, $competencyId, $subjectId);

        return response()->json(['data' => $result]);
    }

    /** GET /api/mastery/records/{studentId}/summary (#102) */
    public function getStudentMasterySummary(int $studentId): JsonResponse
    {
        // Students may only view their own mastery summary (ARCH-002 QA-004, ARCH-002 QA-004).
        $role = strtolower((string) Auth::user()->role);
        if ($role === 'student' && $studentId !== (int) Auth::id()) {
            throw new AuthorizationException();
        }

        User::findOrFail($studentId);

        $summary = $this->gradingService->getStudentMasterySummary($studentId);

        return response()->json(['data' => $summary]);
    }
}
