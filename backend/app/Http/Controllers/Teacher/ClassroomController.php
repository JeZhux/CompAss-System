<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teacher\StoreClassroomRequest;
use App\Http\Requests\Teacher\ToggleJoinRequest;
use App\Services\ClassroomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ClassroomController extends Controller
{
    public function __construct(private readonly ClassroomService $classroomService)
    {
    }

    /** GET /api/teacher/classrooms */
    public function index(Request $request): JsonResponse
    {
        // Semester-partitioned index: ?semester_id (canonical) with ?term_id
        // as a transition alias. Both map to the Semester filter.
        $semesterId = $request->query('semester_id') !== null
            ? (int) $request->query('semester_id')
            : ($request->query('term_id') !== null ? (int) $request->query('term_id') : null);

        $classrooms = $this->classroomService->listTeacherClassrooms(
            (int) Auth::id(),
            $request->query('school_year'),
            $semesterId
        );

        $data = $classrooms->map(fn ($c) => $this->classroomService->formatClassroomForResponse($c));

        return response()->json(['data' => $data->values()]);
    }

    /** POST /api/teacher/classrooms */
    public function store(StoreClassroomRequest $request): JsonResponse
    {
        $classroom = $this->classroomService->createClassroom(
            (int) Auth::id(),
            (int) $request->input('subject_id'),
            (int) $request->input('section_id'),
            $request->input('school_year'),
            $request->input('suffix')
        );

        return response()->json([
            'data' => $this->classroomService->formatClassroomForResponse($classroom),
        ], 201);
    }

    /**
     * GET /api/teacher/classrooms/{id}/competency-context
     *
     * Returns the (subject_id, grade_level, semester) triple the frontend
     * competency picker needs for this classroom:
     * GET /api/admin/competency-tags?subject_id&grade_level&semester.
     */
    public function competencyContext(int $id): JsonResponse
    {
        $context = $this->classroomService->getCompetencyContext((int) Auth::id(), $id);

        return response()->json(['data' => $context]);
    }

    /** GET /api/teacher/classrooms/{id} */
    public function show(int $id): JsonResponse
    {
        $user = Auth::user();
        $role = strtolower((string) $user->role);
        if ($role === 'admin') {
            $classroom = $this->classroomService->getClassroomForActor((int) Auth::id(), $id, $user->role);
        } else {
            $classroom = $this->classroomService->getTeacherClassroom((int) Auth::id(), $id);
        }

        return response()->json([
            'data' => $this->classroomService->formatClassroomForResponse($classroom),
        ]);
    }

    /** POST /api/teacher/classrooms/{id}/reset-key */
    public function resetKey(int $id): JsonResponse
    {
        $user = Auth::user();
        $role = strtolower((string) $user->role);

        if ($role === 'admin') {
            $classroom = $this->classroomService->adminResetKey($id);
        } else {
            $classroom = $this->classroomService->resetKey((int) Auth::id(), $id);
        }

        return response()->json([
            'data' => $this->classroomService->formatClassroomForResponse($classroom),
        ]);
    }

    /** POST /api/teacher/classrooms/{id}/toggle-join */
    public function toggleJoin(ToggleJoinRequest $request, int $id): JsonResponse
    {
        $enabled = $request->input('is_join_enabled');
        if ($enabled === null) {
            $enabled = $request->input('enabled');
        }
        // If neither provided, toggle current value?
        if ($enabled === null) {
            $classroom = $this->classroomService->getTeacherClassroom((int) Auth::id(), $id);
            $enabled = ! $classroom->is_join_enabled;
        } else {
            $enabled = (bool) $enabled;
        }

        $classroom = $this->classroomService->toggleJoin((int) Auth::id(), $id, $enabled);

        return response()->json([
            'data' => $this->classroomService->formatClassroomForResponse($classroom),
        ]);
    }

    /** POST /api/teacher/classrooms/{id}/archive */
    public function archive(int $id): JsonResponse
    {
        $user = Auth::user();
        $role = strtolower((string) $user->role);
        if ($role === 'admin') {
            $classroom = $this->classroomService->adminArchive($id);
        } else {
            $classroom = $this->classroomService->archive((int) Auth::id(), $id);
        }

        return response()->json([
            'data' => $this->classroomService->formatClassroomForResponse($classroom),
        ]);
    }

    /** POST /api/teacher/classrooms/{id}/unarchive */
    public function unarchive(int $id): JsonResponse
    {
        $user = Auth::user();
        $role = strtolower((string) $user->role);
        if ($role === 'admin') {
            $classroom = $this->classroomService->adminUnarchive($id);
        } else {
            $classroom = $this->classroomService->unarchive((int) Auth::id(), $id);
        }

        return response()->json([
            'data' => $this->classroomService->formatClassroomForResponse($classroom),
        ]);
    }

    /** GET /api/teacher/classrooms/{id}/people */
    public function people(int $id): JsonResponse
    {
        $enrollments = $this->classroomService->getPeople((int) Auth::id(), $id);

        $data = $enrollments->map(fn ($e) => [
            'id' => $e->id,
            'student_id' => $e->student_id,
            'student_name' => $e->student->name ?? null,
            'school_id' => $e->student->school_id ?? null,
            'joined_at' => $e->joined_at?->toIso8601String(),
        ]);

        return response()->json(['data' => $data->values()]);
    }

    /** DELETE /api/teacher/classrooms/{id}/people/{studentId} */
    public function removeStudent(int $id, int $studentId): JsonResponse
    {
        $user = Auth::user();
        $isAdmin = strtolower((string) $user->role) === 'admin';
        $this->classroomService->removeStudent((int) Auth::id(), $id, $studentId, $isAdmin);

        return response()->json([
            'data' => ['message' => 'Student removed.'],
        ]);
    }
}
