<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\Classroom\JoinClassroomRequest;
use App\Services\AnnouncementService;
use App\Services\AssignmentService;
use App\Services\AssessmentService;
use App\Services\ClassroomService;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ClassroomController extends Controller
{
    public function __construct(
        private readonly ClassroomService $classroomService,
        private readonly AnnouncementService $announcementService,
        private readonly AssignmentService $assignmentService,
        private readonly AssessmentService $assessmentService
    ) {
    }

    /** GET /api/student/classrooms — learner rooms index (ARCH-005 block 4.19) */
    public function index(Request $request): JsonResponse
    {
        $this->rejectRoomListParams($request);

        $classrooms = $this->classroomService->listStudentClassrooms((int) Auth::id());

        $data = $classrooms->map(fn ($c) => $this->classroomService->formatStudentRoom($c));

        return response()->json(['data' => $data->values()]);
    }

    /** GET /api/student/classrooms/{id} — learner room detail (ARCH-005 block 4.19) */
    public function show(Request $request, int $id): JsonResponse
    {
        if ($request->query->has('search')) {
            throw ValidationException::withMessages([
                'search' => ['Search is not available in this list. Please contact your teacher if you need help.'],
            ]);
        }

        $classroom = $this->classroomService->getStudentClassroom((int) Auth::id(), $id);

        return response()->json([
            'data' => ['classroom' => $this->classroomService->formatStudentRoomDetail($classroom)],
        ]);
    }

    /** POST /api/classrooms/join */
    public function join(JoinClassroomRequest $request): JsonResponse
    {
        $result = $this->classroomService->joinClassroom(
            (int) Auth::id(),
            (string) $request->input('key')
        );

        $classroom = $result['classroom'];
        $alreadyJoined = $result['already_joined'];
        $status = $result['status'];

        return response()->json([
            'data' => [
                'classroom' => $this->classroomService->formatClassroomForResponse($classroom),
                'already_joined' => $alreadyJoined,
            ],
        ], $status);
    }

    /** POST /api/student/classrooms/{id}/leave — learner leave (ARCH-005 block 4.20) */
    public function leave(int $id): JsonResponse
    {
        $alreadyLeft = $this->classroomService->leaveClassroom((int) Auth::id(), $id);

        return response()->json([
            'data' => ['message' => 'Left classroom.', 'already_left' => $alreadyLeft],
        ]);
    }

    /** GET /api/student/classrooms/{id}/people — learner people read (ARCH-005 block 4.18) */
    public function people(Request $request, int $id): JsonResponse
    {
        if ($request->query->has('search')) {
            throw ValidationException::withMessages([
                'search' => ['Search is not available in this list. Please contact your teacher if you need help.'],
            ]);
        }

        $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 15);

        $paginator = $this->classroomService->listStudentPeople((int) Auth::id(), $id, $page, $perPage);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn ($e) => $this->classroomService->formatStudentPerson($e))
        );

        return response()->json(Pagination::response($paginator));
    }

    /**
     * The rooms index defines no paging and no search parameters; any
     * page, per_page, or ?search= value is rejected (ARCH-005 block 4.19).
     */
    private function rejectRoomListParams(Request $request): void
    {
        $messages = [];

        if ($request->query->has('page')) {
            $messages['page'] = ['This list shows all your rooms at once and is not paged. '
                . 'Please contact your teacher if you need help.'];
        }

        if ($request->query->has('per_page')) {
            $messages['per_page'] = ['This list shows all your rooms at once and is not paged. '
                . 'Please contact your teacher if you need help.'];
        }

        if ($request->query->has('search')) {
            $messages['search'] = ['Search is not available in this list. '
                . 'Please contact your teacher if you need help.'];
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    /** GET /api/student/classrooms/{id}/stream */
    public function stream(Request $request, int $id): JsonResponse
    {
        $perPage = Pagination::perPage($request);
        $page = (int) $request->query('page', 1);

        $announcements = $this->announcementService->getAnnouncementsForStudentInClassroom((int) Auth::id(), $id);

        $total = count($announcements);
        $offset = ($page - 1) * $perPage;
        $paged = array_slice($announcements, $offset, $perPage);
        $paginator = new LengthAwarePaginator($paged, $total, $perPage, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return response()->json(Pagination::response($paginator));
    }

    /** GET /api/student/classrooms/{id}/classwork */
    public function classwork(Request $request, int $id): JsonResponse
    {
        $perPage = Pagination::perPage($request);
        $page = max(1, (int) $request->query('page', 1));

        $assignments = $this->assignmentService->listStudentAssignmentsInClassroom((int) Auth::id(), $id);
        $assessments = $this->assessmentService->listStudentAssessmentsInClassroom((int) Auth::id(), $id);

        $assignmentItems = collect($assignments)->map(fn ($a) => array_merge($a, ['kind' => 'assignment', 'type' => 'assignment']))->toArray();
        $assessmentItems = collect($assessments)->map(fn ($a) => array_merge($a, ['kind' => 'assessment']))->toArray();

        $combined = array_merge($assignmentItems, $assessmentItems);

        usort($combined, function ($a, $b) {
            $aDue = $a['due_date'] ?? null;
            $bDue = $b['due_date'] ?? null;
            $aHasDue = $aDue !== null && $aDue !== '';
            $bHasDue = $bDue !== null && $bDue !== '';

            if ($aHasDue && $bHasDue) {
                $cmp = strcmp((string) $aDue, (string) $bDue);
                if ($cmp !== 0) {
                    return $cmp;
                }
                $aCreated = $a['created_at'] ?? null;
                $bCreated = $b['created_at'] ?? null;
                if ($aCreated && $bCreated) {
                    $cmp2 = strcmp((string) $aCreated, (string) $bCreated);
                    if ($cmp2 !== 0) {
                        return $cmp2;
                    }
                } elseif ($aCreated && ! $bCreated) {
                    return -1;
                } elseif (! $aCreated && $bCreated) {
                    return 1;
                }

                return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
            }

            if ($aHasDue && ! $bHasDue) {
                return -1;
            }

            if (! $aHasDue && $bHasDue) {
                return 1;
            }

            $aCreated = $a['created_at'] ?? null;
            $bCreated = $b['created_at'] ?? null;
            if ($aCreated && $bCreated) {
                $cmp = strcmp((string) $aCreated, (string) $bCreated);
                if ($cmp !== 0) {
                    return $cmp;
                }
            } elseif ($aCreated && ! $bCreated) {
                return -1;
            } elseif (! $aCreated && $bCreated) {
                return 1;
            }

            return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
        });

        $total = count($combined);
        $offset = ($page - 1) * $perPage;
        $paged = array_slice($combined, $offset, $perPage);
        $paginator = new LengthAwarePaginator($paged, $total, $perPage, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return response()->json(Pagination::response($paginator));
    }
}
