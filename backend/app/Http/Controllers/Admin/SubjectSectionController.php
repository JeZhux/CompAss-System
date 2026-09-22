<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Deprecated Admin Subject-Section dropdown: GET /api/admin/subject-sections.
 *
 * The `subject_sections` table was dropped in the Semester restructure
 * (School Year > Semester > Grade Level > Subject > Competencies).
 * Teacher scope is now derived from Classrooms
 * (teacher_id + subject_id + section_id + school_year).
 *
 * Kept as a 410 GONE stub so legacy callers get a clear Competency /
 * Semester-vocabulary message instead of a 500. Use
 * GET /api/admin/subjects and GET /api/admin/classrooms instead.
 */
class SubjectSectionController extends Controller
{
    /** GET /api/admin/subject-sections (deprecated — always 410) */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'error' => [
                'message' => 'Subject sections are no longer available. Teacher scope is derived from classrooms; list subjects via GET /api/admin/subjects and classrooms via GET /api/admin/classrooms.',
                'code' => 'GONE',
            ],
        ], 410);
    }
}
