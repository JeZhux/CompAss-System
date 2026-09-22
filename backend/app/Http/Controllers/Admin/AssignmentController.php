<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Services\AuditLogService;
use App\Services\OrgStructureService;
use App\Support\HumanSearch;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Teacher-assignment reads (classroom-derived).
 *
 * The `subject_sections` / `teacher_subject_section_assignments` tables were
 * dropped in the Semester restructure (School Year > Semester > Grade Level >
 * Subject > Competencies). Teacher scope is now derived from Classrooms
 * (teacher_id + subject_id + section_id + school_year).
 *
 * Legacy section-assignment writes (POST/DELETE
 * /admin/sections/{id}/assignments and POST/DELETE /admin/teacher-assignments)
 * are 410 GONE stubs — use the Classroom endpoints instead (Agent 3 flow).
 * Filtered reads remain available via the classroom-derived view.
 *
 * @Traced-To ARCH-002 FR-005, ARCH-002 FR-005
 */
class AssignmentController extends Controller
{
    public function __construct(
        private readonly OrgStructureService $orgStructureService,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /** GET /api/admin/sections/{sectionId}/assignments (#24, classroom-derived) */
    public function index(int $sectionId): JsonResponse
    {
        return response()->json([
            'data' => $this->orgStructureService->getSectionAssignments($sectionId)->values(),
        ]);
    }

    /** POST /api/admin/sections/{sectionId}/assignments (#25, deprecated — 410) */
    public function store(Request $request, int $sectionId): JsonResponse
    {
        return response()->json([
            'error' => [
                'message' => 'Teacher assignments are now derived from classrooms. Create a classroom via the classroom endpoints instead.',
                'code' => 'GONE',
            ],
        ], 410);
    }

    /** DELETE /api/admin/sections/{sectionId}/assignments/{assignmentId} (#26, deprecated — 410) */
    public function destroy(int $sectionId, int $assignmentId): JsonResponse
    {
        return response()->json([
            'error' => [
                'message' => 'Teacher assignments are now derived from classrooms. Manage them via the classroom endpoints instead.',
                'code' => 'GONE',
            ],
        ], 410);
    }

    /** PATCH /api/admin/teacher-assignments/{id} — deprecated (410) */
    public function updateSchoolYear(Request $request, int $id): JsonResponse
    {
        return response()->json([
            'error' => [
                'message' => 'Teacher assignments are now derived from classrooms. Manage them via the classroom endpoints instead.',
                'code' => 'GONE',
            ],
        ], 410);
    }

    /** GET /api/admin/teacher-assignments — classroom-derived filtered paginated */
    public function indexTeacherAssignments(Request $request): JsonResponse
    {
        $query = Classroom::query()
            ->with(['teacher:id,name,school_id', 'subject', 'section.gradeLevel.semester.schoolYear']);

        if ($request->filled('teacher_id')) {
            $query->where('teacher_id', (int) $request->query('teacher_id'));
        }

        if ($request->filled('subject_id')) {
            $query->where('subject_id', (int) $request->query('subject_id'));
        }

        if ($request->filled('section_id')) {
            $query->where('section_id', (int) $request->query('section_id'));
        }

        if ($request->filled('school_year')) {
            $query->where('school_year', trim((string) $request->query('school_year')));
        }

        if ($request->filled('search')) {
            HumanSearch::rejectNumericSearch($request);
            $search = trim((string) $request->query('search'));
            self::rejectShortSearch($search);
            // Server owns LIKE safety: escape \, %, _ so wildcards match
            // literally (competency-tags pattern).
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $pattern = "%{$escaped}%";
            $like = DB::getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
            $query->where(function ($q) use ($pattern, $like) {
                $q->whereHas('teacher', function ($qq) use ($pattern, $like) {
                    $qq->whereRaw("name {$like} ? ESCAPE '\\'", [$pattern])
                        ->orWhereRaw("school_id {$like} ? ESCAPE '\\'", [$pattern]);
                })->orWhereHas('subject', function ($qq) use ($pattern, $like) {
                    $qq->whereRaw("name {$like} ? ESCAPE '\\'", [$pattern])
                        ->orWhereRaw("code {$like} ? ESCAPE '\\'", [$pattern]);
                })->orWhereHas('section', function ($qq) use ($pattern, $like) {
                    $qq->whereRaw("name {$like} ? ESCAPE '\\'", [$pattern]);
                });
            });
        }

        $query->orderByDesc('created_at')->orderByDesc('id');

        $page = (int) $request->query('page', 1);
        $perPage = Pagination::perPage($request);

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $paginator->setCollection(
            $paginator->getCollection()->map(function (Classroom $c) {
                $subject = $c->subject;
                $section = $c->section;
                $gradeLevel = $section?->gradeLevel;
                $semester = $gradeLevel?->semester;

                return [
                    'id' => $c->id,
                    'teacher_id' => $c->teacher_id,
                    'teacher_name' => $c->teacher?->name,
                    'teacher_school_id' => $c->teacher?->school_id,
                    'subject_id' => $subject?->id,
                    'subject_name' => $subject?->name,
                    'subject_code' => $subject?->code,
                    'section_id' => $section?->id,
                    'section_name' => $section?->name,
                    'grade_level' => $gradeLevel?->grade_level,
                    'semester' => $semester?->semester,
                    'semester_id' => $gradeLevel?->semester_id,
                    'term_id' => $gradeLevel?->semester_id,
                    'semester_name' => $semester?->name,
                    'school_year' => $c->school_year,
                    'created_at' => $c->created_at?->toIso8601String(),
                    'updated_at' => $c->updated_at?->toIso8601String(),
                ];
            })
        );

        return response()->json(Pagination::response($paginator));
    }

    /** POST /api/admin/teacher-assignments — deprecated (410) */
    public function storeTeacherAssignment(Request $request): JsonResponse
    {
        return response()->json([
            'error' => [
                'message' => 'Teacher assignments are now derived from classrooms. Create a classroom via the classroom endpoints instead.',
                'code' => 'GONE',
            ],
        ], 410);
    }

    /** DELETE /api/admin/teacher-assignments/{id} — deprecated (410) */
    public function destroyTeacherAssignment(int $id): JsonResponse
    {
        return response()->json([
            'error' => [
                'message' => 'Teacher assignments are now derived from classrooms. Manage them via the classroom endpoints instead.',
                'code' => 'GONE',
            ],
        ], 410);
    }

    /**
     * Server owns the min-2 search contract (ListCompetencyTagsRequest min:2
     * convention; frontend guards are bypassable direct-API). Throws the
     * shared 422 VALIDATION_ERROR envelope via ValidationException.
     */
    private static function rejectShortSearch(string $search): void
    {
        if ($search !== '' && mb_strlen($search) < 2) {
            throw ValidationException::withMessages([
                'search' => ['The search field must be at least 2 characters.'],
            ]);
        }
    }
}
