<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ClassroomEnrollment;
use App\Services\OrgStructureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Authenticated session endpoint: GET /api/me.
 *
 * Exempt from PasswordChangeRequired so the UI can read `must_change_password`.
 *
 * @Traced-To ARCH-002 FR-003, ARCH-002 FR-003, ARCH-002 QA-009
 */
class ProfileController extends Controller
{
    public function __construct(private readonly OrgStructureService $orgStructureService)
    {
    }

    /** GET /api/me */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = [
            'id' => $user->id,
            'name' => $user->name,
            'school_id' => $user->school_id,
            'role' => $user->role,
            'must_change_password' => $user->must_change_password,
            'assignments' => [],
            'enrollments' => [],
        ];

        if ($user->role === 'Teacher') {
            $data['assignments'] = $this->orgStructureService->getTeacherAssignments($user->id);
        } elseif ($user->role === 'Student') {
            // Classroom-only roster: a student's enrollments are their
            // ClassroomEnrollment rows (section-level enrollment removed).
            $data['enrollments'] = ClassroomEnrollment::query()
                ->where('student_id', $user->id)
                ->with('classroom')
                ->get()
                ->map(fn (ClassroomEnrollment $e) => [
                    'classroom_id' => $e->classroom_id,
                    'classroom_name' => $e->classroom->name,
                    'subject_id' => $e->classroom->subject_id,
                    'section_id' => $e->classroom->section_id,
                    'school_year' => $e->classroom->school_year,
                    'joined_at' => $e->joined_at,
                ])
                ->values();
        }

        return response()->json(['data' => $data]);
    }
}
