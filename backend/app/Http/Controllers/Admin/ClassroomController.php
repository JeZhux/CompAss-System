<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessRuleConflictException;
use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\MasteryRecord;
use App\Services\AuditLogService;
use App\Services\ClassroomService;
use App\Support\HumanSearch;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClassroomController extends Controller
{
    public function __construct(
        private readonly ClassroomService $classroomService,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /** GET /api/admin/classrooms — filtered, paginated, includes mastery per classroom */
    public function index(Request $request): JsonResponse
    {
        $query = Classroom::query()
            ->with(['teacher:id,name,school_id', 'subject', 'section.gradeLevel.semester.schoolYear'])
            ->withCount('enrollments');

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

        if ($request->has('archived') && $request->query('archived') !== null && $request->query('archived') !== '') {
            $val = strtolower(trim((string) $request->query('archived')));
            if (in_array($val, ['true', '1', 'yes'], true)) {
                $query->whereNotNull('archived_at');
            } elseif (in_array($val, ['false', '0', 'no'], true)) {
                $query->whereNull('archived_at');
            }
        } elseif ($request->filled('is_archived')) {
            $val = strtolower(trim((string) $request->query('is_archived')));
            if (in_array($val, ['true', '1', 'yes'], true)) {
                $query->whereNotNull('archived_at');
            } elseif (in_array($val, ['false', '0', 'no'], true)) {
                $query->whereNull('archived_at');
            }
        }

        if ($request->filled('search')) {
            HumanSearch::rejectNumericSearch($request);
            $search = trim((string) $request->query('search'));
            // Server owns the min-2 search contract (ListCompetencyTagsRequest
            // min:2 convention; frontend guards are bypassable direct-API).
            if ($search !== '' && mb_strlen($search) < 2) {
                throw ValidationException::withMessages([
                    'search' => ['The search field must be at least 2 characters.'],
                ]);
            }
            $query->where(function ($q) use ($search) {
                $like = DB::getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
                // Server owns LIKE safety: escape \, %, _ so wildcards match
                // literally (competency-tags pattern).
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
                $pattern = "%{$escaped}%";
                $q->whereRaw("classrooms.name {$like} ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("classrooms.school_year {$like} ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("classrooms.suffix {$like} ? ESCAPE '\\'", [$pattern])
                    ->orWhereHas('teacher', function ($qq) use ($like, $pattern) {
                        $qq->whereRaw("name {$like} ? ESCAPE '\\'", [$pattern])
                            ->orWhereRaw("school_id {$like} ? ESCAPE '\\'", [$pattern]);
                    })
                    ->orWhereHas('subject', function ($qq) use ($like, $pattern) {
                        $qq->whereRaw("name {$like} ? ESCAPE '\\'", [$pattern])
                            ->orWhereRaw("code {$like} ? ESCAPE '\\'", [$pattern]);
                    })
                    ->orWhereHas('section', function ($qq) use ($like, $pattern) {
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
                $gradeLevel = $section?->gradeLevel ?? $subject?->gradeLevel;
                $semester = $gradeLevel?->semester;
                $schoolYear = $semester?->schoolYear ?? null;

                $base = $this->classroomService->formatClassroomForResponse($c);

                // Extended admin shape: teacher identity comes from the base
                // contract above (single source); the rest enriches the list.
                $extended = [
                    'subject_id' => $subject?->id,
                    'subject_name' => $subject?->name,
                    'subject_code' => $subject?->code,
                    'section_id' => $section?->id,
                    'section_name' => $section?->name,
                    'grade_level' => $gradeLevel?->grade_level,
                    'semester_name' => $semester?->name,
                    'school_year_name' => $schoolYear?->name,
                    'enrollment_count' => $c->enrollments_count ?? 0,
                    'mastery' => $this->masteryStatsForClassroom($c),
                ];

                return array_merge($base, $extended);
            })
        );

        return response()->json(Pagination::response($paginator));
    }

    /** GET /api/admin/classrooms/{id} */
    public function show(int $id): JsonResponse
    {
        $classroom = $this->classroomService->getClassroomForActor((int) Auth::id(), $id, (string) Auth::user()->role);
        $classroom->loadMissing(['teacher:id,name,school_id', 'subject', 'section.gradeLevel.semester.schoolYear']);
        $classroom->loadCount('enrollments');

        // Same contract as the list: base shape (incl. teacher_name and
        // teacher_school_id) plus the admin enrichment, so detail never
        // drifts from list.
        $subject = $classroom->subject;
        $section = $classroom->section;
        $gradeLevel = $section?->gradeLevel ?? $subject?->gradeLevel;
        $semester = $gradeLevel?->semester;
        $schoolYear = $semester?->schoolYear ?? null;

        $base = $this->classroomService->formatClassroomForResponse($classroom);
        $extended = [
            'subject_code' => $subject?->code,
            'semester_name' => $semester?->name,
            'school_year_name' => $schoolYear?->name,
            'enrollment_count' => $classroom->enrollments_count ?? 0,
            'mastery' => $this->masteryStatsForClassroom($classroom),
        ];

        return response()->json([
            'data' => array_merge($base, $extended),
        ]);
    }

    /** POST /api/admin/classrooms/{id}/reset-key */
    public function resetKey(int $id): JsonResponse
    {
        $classroom = $this->classroomService->adminResetKey($id);

        return response()->json([
            'data' => $this->classroomService->formatClassroomForResponse($classroom),
        ]);
    }

    /** POST /api/admin/classrooms/{id}/archive */
    public function archive(int $id): JsonResponse
    {
        $classroom = $this->classroomService->adminArchive($id);

        return response()->json([
            'data' => $this->classroomService->formatClassroomForResponse($classroom),
        ]);
    }

    /** POST /api/admin/classrooms/{id}/unarchive */
    public function unarchive(int $id): JsonResponse
    {
        $classroom = $this->classroomService->adminUnarchive($id);

        return response()->json([
            'data' => $this->classroomService->formatClassroomForResponse($classroom),
        ]);
    }

    /** PATCH /api/admin/classrooms/{id} — U04 school_year editable */
    public function updateSchoolYear(Request $request, int $id): JsonResponse
    {
        $classroom = Classroom::findOrFail($id);

        $request->validate([
            'school_year' => ['required', 'string', 'max:9', 'regex:/^\d{4}-\d{4}$/'],
        ]);

        $schoolYear = trim((string) $request->input('school_year'));

        [$first, $second] = explode('-', $schoolYear);
        if ((int) $second !== (int) $first + 1) {
            throw ValidationException::withMessages([
                'school_year' => ['The school_year second year must be first year plus one.'],
            ]);
        }

        // Duplicate guard: UNIQUE(teacher_id, subject_id, section_id, school_year)
        $duplicate = Classroom::where('teacher_id', $classroom->teacher_id)
            ->where('subject_id', $classroom->subject_id)
            ->where('section_id', $classroom->section_id)
            ->where('school_year', $schoolYear)
            ->where('id', '!=', $classroom->id)
            ->exists();

        if ($duplicate) {
            throw new BusinessRuleConflictException(
                'You already have a classroom for this subject and section in this school year.',
                'DUPLICATE_CLASSROOM',
                409
            );
        }

        $old = $classroom->school_year;
        $classroom->school_year = $schoolYear;
        $classroom->save();

        $this->auditLogService->log(
            'update',
            'Classroom school_year updated: ' . $old . ' -> ' . $schoolYear,
            Auth::id(),
            Classroom::class,
            $classroom->id,
            ['old_school_year' => $old, 'new_school_year' => $schoolYear]
        );

        return response()->json([
            'data' => [
                'id' => $classroom->id,
                'teacher_id' => $classroom->teacher_id,
                'subject_id' => $classroom->subject_id,
                'section_id' => $classroom->section_id,
                'school_year' => $classroom->school_year,
            ],
        ]);
    }

    /**
     * Mastery stats for a classroom — scoped to mastery_records.classroom_id.
     *
     * Filters by classroom_id (strict equality) so each classroom reports
     * its own competency history. Handles missing/empty classroom id and
     * empty result sets without throwing so GET /admin/classrooms never
     * 500s on this field.
     *
     * @return array{average_mastery_percent: float|null, average: float|null, total_records: int, total: int, count: int, mastered_count: int, not_mastered_count: int, total_students_assessed: int, mastery_rate_percent: float}
     */
    private function masteryStatsForClassroom(Classroom $classroom): array
    {
        $classroomId = $classroom->id ?? null;

        if (empty($classroomId)) {
            return [
                'average_mastery_percent' => null,
                'average' => null,
                'total_records' => 0,
                'total' => 0,
                'count' => 0,
                'mastered_count' => 0,
                'not_mastered_count' => 0,
                'total_students_assessed' => 0,
                'mastery_rate_percent' => 0.0,
            ];
        }

        try {
            $row = MasteryRecord::query()
                ->where('classroom_id', $classroomId)
                ->selectRaw('COUNT(*) as total_records')
                ->selectRaw('AVG(mastery_percent) as avg_percent')
                ->selectRaw("SUM(CASE WHEN mastery_status = 'Mastered' THEN 1 ELSE 0 END) as mastered_count")
                ->selectRaw("SUM(CASE WHEN mastery_status = 'Not_Mastered' THEN 1 ELSE 0 END) as not_mastered_count")
                ->selectRaw('COUNT(DISTINCT student_id) as total_students_assessed')
                ->first();

            if (! $row) {
                return [
                    'average_mastery_percent' => null,
                    'average' => null,
                    'total_records' => 0,
                    'total' => 0,
                    'count' => 0,
                    'mastered_count' => 0,
                    'not_mastered_count' => 0,
                    'total_students_assessed' => 0,
                    'mastery_rate_percent' => 0.0,
                ];
            }

            $totalRecords = (int) ($row->total_records ?? 0);
            $avgRaw = $row->avg_percent;
            $average = $avgRaw !== null ? round((float) $avgRaw, 2) : null;
            $mastered = (int) ($row->mastered_count ?? 0);
            $notMastered = (int) ($row->not_mastered_count ?? 0);
            $distinctStudents = (int) ($row->total_students_assessed ?? 0);
            $masteryRate = $distinctStudents > 0 ? round(($mastered / $distinctStudents) * 100, 2) : 0.0;

            return [
                'average_mastery_percent' => $average,
                'average' => $average,
                'total_records' => $totalRecords,
                'total' => $totalRecords,
                'count' => $totalRecords,
                'mastered_count' => $mastered,
                'not_mastered_count' => $notMastered,
                'total_students_assessed' => $distinctStudents,
                'mastery_rate_percent' => $masteryRate,
            ];
        } catch (\Throwable $e) {
            return [
                'average_mastery_percent' => null,
                'average' => null,
                'total_records' => 0,
                'total' => 0,
                'count' => 0,
                'mastered_count' => 0,
                'not_mastered_count' => 0,
                'total_students_assessed' => 0,
                'mastery_rate_percent' => 0.0,
            ];
        }
    }
}
