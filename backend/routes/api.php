<?php

use App\Http\Controllers\Admin\AssignmentController as AdminAssignmentController;
use App\Http\Controllers\Admin\BatchImportController;
use App\Http\Controllers\Admin\OrgStructureController;
use App\Http\Controllers\Admin\SubjectController;
use App\Http\Controllers\Admin\SubjectSectionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\ProfileController;
use App\Http\Controllers\Admin\ClassroomController as AdminClassroomController;
use App\Http\Controllers\Admin\EnrollmentController as AdminEnrollmentController;
use App\Http\Controllers\AIController;
use App\Http\Controllers\GradingController;
use App\Http\Controllers\Student\ClassroomController as StudentClassroomController;
use App\Http\Controllers\Teacher\AnnouncementController;
use App\Http\Controllers\Teacher\AssessmentController;
use App\Http\Controllers\Teacher\AssignmentController as TeacherAssignmentController;
use App\Http\Controllers\Teacher\ClassroomController as TeacherClassroomController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Phase 0 scaffolding (retained as a regression harness — do not remove):
| proves the auth guard, role middleware, and error envelope all work.
|
*/

if (! app()->environment('production')) {
    Route::post('/test/login', function (Request $request) {
        $request->validate([
            'school_id' => ['required', 'string', 'regex:/^(ADM|TEA|STU)-[0-9]{4}-[0-9]{5}$/'],
            'password' => ['required'],
        ]);

        if (
            ! Auth::guard('web')->attempt(
                $request->only('school_id', 'password')
            )
        ) {
            return response()->json([
                'error' => [
                    'message' => 'These credentials do not match our records.',
                    'code' => 'UNAUTHENTICATED',
                ],
            ], 401);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json([
            'message' => 'Login successful.',
            'user' => $request->user(),
        ]);
    });

    Route::middleware(['auth:sanctum', 'role:admin'])->post('/test/logout', function (Request $request) {
        Auth::guard('web')->logout();
        // Clear the Sanctum RequestGuard's cached user so a subsequent request
        // re-resolves via the SessionGuard (which is now logged out). The
        // RequestGuard caches user() on first resolution and would otherwise
        // keep returning the stale user within the same container lifecycle.
        Auth::guard('sanctum')->forgetUser();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'message' => 'Logout successful.',
        ]);
    });

    Route::middleware(['auth:sanctum', 'role:admin'])->get('/test/protected', function (Request $request) {
        return response()->json([
            'message' => 'Authorized',
            'user' => $request->user(),
        ]);
    });

    Route::middleware(['auth:sanctum', 'role:admin'])->get('/test/error', function () {
        throw new RuntimeException('Intentional test error to verify error envelope.');
    });
}

/*
| Phase 1 — LMS Core API (ARCH-005 blocks 4.1 + 4.9)
| Auth #1–#3 + profile; admin #4–#29.
*/

Route::post('/auth/login', [AuthController::class, 'login']); // #1

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);            // #2
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']); // #3
    Route::get('/me', [ProfileController::class, 'me']);                        // #4

    Route::middleware(['role:admin', 'password.change.required'])->group(function () {
        Route::get('admin/users', [UserController::class, 'index']);            // #5
        Route::post('admin/users', [UserController::class, 'store']);         // #6
        Route::get('admin/users/{id}', [UserController::class, 'show'])        // #7
            ->whereNumber('id');
        Route::put('admin/users/{id}', [UserController::class, 'update'])      // #8
            ->whereNumber('id');
        Route::post('admin/users/{id}/deactivate', [UserController::class, 'deactivate']) // #9
            ->whereNumber('id');
        Route::post('admin/users/{id}/reactivate', [UserController::class, 'reactivate']) // #10
            ->whereNumber('id');
        Route::post('admin/users/{id}/reset-password', [UserController::class, 'resetPassword']) // #11
            ->whereNumber('id');

        Route::get('admin/school-years', [OrgStructureController::class, 'indexSchoolYears']);       // #12
        Route::post('admin/school-years', [OrgStructureController::class, 'storeSchoolYear']);       // #13
        // Semesters (Semester vocabulary; School Year > Semester > Grade Level > Subject > Competencies)
        Route::get('admin/school-years/{schoolYearId}/semesters', [OrgStructureController::class, 'indexSemesters'])    // #14
            ->whereNumber('schoolYearId');
        Route::post('admin/school-years/{schoolYearId}/semesters', [OrgStructureController::class, 'storeSemester'])    // #15
            ->whereNumber('schoolYearId');
        // Deprecated Term aliases (backwards compat — same Semester logic)
        Route::get('admin/school-years/{schoolYearId}/terms', [OrgStructureController::class, 'indexTerms'])    // #14 alias
            ->whereNumber('schoolYearId');
        Route::post('admin/school-years/{schoolYearId}/terms', [OrgStructureController::class, 'storeTerm'])    // #15 alias
            ->whereNumber('schoolYearId');
        Route::get('admin/semesters/{semesterId}/grade-levels', [OrgStructureController::class, 'indexGradeLevels'])    // #16
            ->whereNumber('semesterId');
        Route::post('admin/semesters/{semesterId}/grade-levels', [OrgStructureController::class, 'storeGradeLevel'])   // #17
            ->whereNumber('semesterId');
        Route::get('admin/terms/{termId}/grade-levels', [OrgStructureController::class, 'indexGradeLevels'])    // #16 alias
            ->whereNumber('termId');
        Route::post('admin/terms/{termId}/grade-levels', [OrgStructureController::class, 'storeGradeLevel'])   // #17 alias
            ->whereNumber('termId');
        Route::post('admin/semesters/{semesterId}/purge', [OrgStructureController::class, 'purgeSemester'])   // ARCH-002 QA-010
            ->whereNumber('semesterId');
        Route::post('admin/terms/{termId}/purge', [OrgStructureController::class, 'purgeTerm'])   // ARCH-002 QA-010 alias
            ->whereNumber('termId');
        Route::get('admin/grade-levels/{gradeLevelId}/sections', [OrgStructureController::class, 'indexSections']) // #18
            ->whereNumber('gradeLevelId');
        Route::post('admin/grade-levels/{gradeLevelId}/sections', [OrgStructureController::class, 'storeSection']) // #19
            ->whereNumber('gradeLevelId');

        Route::get('admin/subjects', [SubjectController::class, 'index']);          // #20
        Route::post('admin/subjects', [SubjectController::class, 'store']);         // #21
        Route::put('admin/subjects/{id}', [SubjectController::class, 'update'])     // #22
            ->whereNumber('id');
        Route::delete('admin/subjects/{id}', [SubjectController::class, 'destroy']) // #23
            ->whereNumber('id');

        Route::get('admin/sections/{sectionId}/assignments', [AdminAssignmentController::class, 'index'])           // #24
            ->whereNumber('sectionId');
        Route::post('admin/sections/{sectionId}/assignments', [AdminAssignmentController::class, 'store'])          // #25
            ->whereNumber('sectionId');
        Route::delete('admin/sections/{sectionId}/assignments/{assignmentId}', [AdminAssignmentController::class, 'destroy']) // #26
            ->whereNumber(['sectionId', 'assignmentId']);

        Route::patch('admin/teacher-assignments/{id}', [AdminAssignmentController::class, 'updateSchoolYear'])
            ->whereNumber('id');

        // Teacher assignments — filtered paginated + create/delete + subject-sections dropdown (U05)
        Route::get('admin/teacher-assignments', [AdminAssignmentController::class, 'indexTeacherAssignments']);
        Route::post('admin/teacher-assignments', [AdminAssignmentController::class, 'storeTeacherAssignment']);
        Route::delete('admin/teacher-assignments/{id}', [AdminAssignmentController::class, 'destroyTeacherAssignment'])
            ->whereNumber('id');
        Route::get('admin/subject-sections', [SubjectSectionController::class, 'index']);
    });

    /*
    | Phase 2 — Batch Import API (ARCH-005 block 4.6)
    | #30 template, #32 competency-tags, #33/#99 error-reports.
    */
    Route::middleware(['auth:sanctum', 'role:admin', 'password.change.required'])->group(function () {
        Route::get('admin/import/templates/{type}', [BatchImportController::class, 'downloadTemplate'])->middleware('throttle:import'); // #30
        Route::post('admin/import/competency-tags', [BatchImportController::class, 'importCompetencyTags'])->middleware('throttle:import'); // #32 (ARCH-002 QA-003)
        Route::get('admin/competency-tags', [BatchImportController::class, 'indexCompetencyTags']); // catalog read (ARCH-002 FR-031)
        Route::post('admin/import/student-enrollments/preview', [BatchImportController::class, 'previewStudentEnrollments'])->middleware('throttle:import');
        Route::post('admin/import/student-enrollments/confirm', [BatchImportController::class, 'confirmStudentEnrollments'])->middleware('throttle:import');
        Route::post('admin/import/teacher-applications/preview', [BatchImportController::class, 'previewTeacherApplications'])->middleware('throttle:import');
        Route::post('admin/import/teacher-applications/confirm', [BatchImportController::class, 'confirmTeacherApplications'])->middleware('throttle:import');
        Route::get('admin/import/error-reports/{token}', [BatchImportController::class, 'downloadErrorReport'])->middleware('throttle:import'); // #33 / #99
    });
});

/*
| Phase 3 — Classroom Content (ARCH-005 blocks 4.10 + 4.11 + 4.12 + 4.4 + 4.5)
| Announcements #34–#39; Assignments #40–#52; Assessments #53–#71.
*/

Route::middleware(['auth:sanctum', 'role:teacher', 'password.change.required'])->group(function () {
    // Announcements (#34–#39)
    Route::get('teacher/announcements', [AnnouncementController::class, 'teacherIndex']);         // #34
    Route::post('teacher/announcements', [AnnouncementController::class, 'teacherStore']);       // #35
    Route::put('teacher/announcements/{id}', [AnnouncementController::class, 'teacherUpdate'])   // #36
        ->whereNumber('id');
    Route::delete('teacher/announcements/{id}', [AnnouncementController::class, 'teacherDestroy']) // #37
        ->whereNumber('id');

    // Assignments (#40–#48)
    Route::get('teacher/assignments', [TeacherAssignmentController::class, 'teacherIndex']);                            // #40
    Route::post('teacher/assignments', [TeacherAssignmentController::class, 'teacherStore']);                            // #41
    Route::get('teacher/assignments/{id}', [TeacherAssignmentController::class, 'teacherShow'])                         // #42
        ->whereNumber('id');
    Route::put('teacher/assignments/{id}', [TeacherAssignmentController::class, 'teacherUpdate'])                       // #43
        ->whereNumber('id');
    Route::delete('teacher/assignments/{id}', [TeacherAssignmentController::class, 'teacherDestroy'])                     // #44
        ->whereNumber('id');
    Route::post('teacher/assignments/{id}/confirm-delete', [TeacherAssignmentController::class, 'teacherConfirmDelete'])  // #45
        ->whereNumber('id');
    Route::get('teacher/assignments/{id}/submissions', [TeacherAssignmentController::class, 'teacherSubmissions'])       // #46
        ->whereNumber('id');
    Route::get('teacher/submissions/{submissionId}', [TeacherAssignmentController::class, 'teacherShowSubmission'])      // #47
        ->whereNumber('submissionId');
    Route::post('teacher/submissions/{submissionId}/feedback', [TeacherAssignmentController::class, 'teacherStoreFeedback']) // #48
        ->whereNumber('submissionId');

    // Assessments (#53–#64)
    Route::get('teacher/assessments', [AssessmentController::class, 'teacherIndex']);                  // #53
    Route::get('teacher/assessments/{id}', [AssessmentController::class, 'teacherShow'])                // #54
        ->whereNumber('id');
    Route::post('teacher/assessments', [AssessmentController::class, 'teacherStore']);                  // #55
    Route::put('teacher/assessments/{id}', [AssessmentController::class, 'teacherUpdate'])              // #56
        ->whereNumber('id');
    Route::delete('teacher/assessments/{id}', [AssessmentController::class, 'teacherDestroy'])            // #57
        ->whereNumber('id');

    // Assessment Items (#58–#60)
    Route::post('teacher/assessments/{id}/items', [AssessmentController::class, 'teacherStoreItem'])     // #58
        ->whereNumber('id');
    Route::put('teacher/items/{id}', [AssessmentController::class, 'teacherUpdateItem'])                 // #59
        ->whereNumber('id');
    Route::delete('teacher/items/{id}', [AssessmentController::class, 'teacherDestroyItem'])             // #60
        ->whereNumber('id');

    // Assessment Flow (#61-#65)
    Route::post('teacher/assessments/{id}/release', [AssessmentController::class, 'teacherRelease'])             // #61
        ->whereNumber('id');
    Route::get('teacher/pending-grading', [AssessmentController::class, 'teacherPendingGrading']);                  // #62
    Route::get('teacher/submissions/{id}/pending-items', [AssessmentController::class, 'teacherShowSubmissionPendingItems']) // #63
        ->whereNumber('id');
    Route::post('teacher/submissions/{id}/score', [AssessmentController::class, 'teacherScoreSubmission'])        // #64
        ->whereNumber('id');
    Route::post('teacher/assessments/{id}/release-results', [AssessmentController::class, 'teacherReleaseResults']) // #65
        ->whereNumber('id');

    // F-14 — authorized file-binary retrieval (SafeUpload confinement + downloadName)
    Route::get('teacher/items/{itemId}/attachments/{attachmentId}/download', [AssessmentController::class, 'teacherDownloadItemAttachment'])
        ->whereNumber(['itemId', 'attachmentId']);
    Route::get('teacher/assignments/{id}/attachments/{attachmentId}/download', [TeacherAssignmentController::class, 'teacherDownloadAttachment'])
        ->whereNumber(['id', 'attachmentId']);
    Route::get('teacher/submissions/{submissionId}/files/{fileId}/download', [TeacherAssignmentController::class, 'teacherDownloadSubmissionFile'])
        ->whereNumber(['submissionId', 'fileId']);
});

Route::middleware(['auth:sanctum', 'role:student', 'password.change.required'])->group(function () {
    // Announcements (#38–#39) — student-facing
    Route::get('student/announcements', [AnnouncementController::class, 'studentIndex']);                      // #38
    Route::get('student/announcements/{id}/download/{attachmentId}', [AnnouncementController::class, 'studentDownload']) // #39
        ->whereNumber(['id', 'attachmentId']);

    // Assignments (#49–#52)
    Route::get('student/assignments', [TeacherAssignmentController::class, 'studentIndex']);          // #49
    Route::get('student/assignments/{id}', [TeacherAssignmentController::class, 'studentShow'])       // #50
        ->whereNumber('id');
    Route::post('student/assignments/{id}/submit', [TeacherAssignmentController::class, 'studentSubmit']) // #51
        ->whereNumber('id');
    Route::get('student/assignments/{id}/feedback', [TeacherAssignmentController::class, 'studentFeedback'])       // #52
        ->whereNumber('id');

    // Assessments (#66-#72)
    Route::get('student/assessments', [AssessmentController::class, 'studentIndex']);                          // #66
    Route::get('student/assessments/{id}', [AssessmentController::class, 'studentShow'])                       // #67
        ->whereNumber('id');
    Route::post('student/assessments/{id}/start', [AssessmentController::class, 'studentStart'])               // #68
        ->whereNumber('id');
    Route::put('student/assessments/{id}/auto-save', [AssessmentController::class, 'studentAutoSave'])         // #69
        ->whereNumber('id');
    Route::patch('student/assessments/{id}/attempts/{attemptId}/response', [AssessmentController::class, 'studentPatchResponse']) // #70
        ->whereNumber(['id', 'attemptId']);
    Route::post('student/assessments/{id}/submit', [AssessmentController::class, 'studentSubmitAssessment'])   // #71
        ->whereNumber('id');
    Route::get('student/assessments/{id}/results', [AssessmentController::class, 'studentResults'])            // #72
        ->whereNumber('id');

    // F-14 — authorized file-binary retrieval (SafeUpload confinement + downloadName)
    Route::get('student/items/{itemId}/attachments/{attachmentId}/download', [AssessmentController::class, 'studentDownloadItemAttachment'])
        ->whereNumber(['itemId', 'attachmentId']);
    Route::get('student/assignments/{id}/attachments/{attachmentId}/download', [TeacherAssignmentController::class, 'studentDownloadAttachment'])
        ->whereNumber(['id', 'attachmentId']);
    Route::get('student/submissions/{submissionId}/files/{fileId}/download', [TeacherAssignmentController::class, 'studentDownloadSubmissionFile'])
        ->whereNumber(['submissionId', 'fileId']);
});

/*
| Phase 4 — Grading & Mastery (ARCH-005 block 4.5, inventory #100–#104)
| #100 manual grade, #101 mastery records, #102 mastery summary,
| #103 resubmit, #104 bulk grading.
| Roles: #100/#103/#104 = Teacher; #101/#102 = Teacher/Student/Admin.
*/

Route::middleware(['auth:sanctum', 'password.change.required'])->group(function () {
    // Teacher-only grading endpoints
    Route::middleware(['role:teacher'])->group(function () {
        Route::post('/grades/manual', [GradingController::class, 'storeManualGrade']);        // #100
        Route::post('/grades/bulk', [GradingController::class, 'bulkGrade']);                // #104
        Route::post('/assessments/{assessmentId}/attempts/{attemptId}/resubmit', [GradingController::class, 'resubmit']) // #103
            ->whereNumber(['assessmentId', 'attemptId']);
    });

    // Teacher, Student, Admin — mastery viewing
    Route::middleware(['role:teacher,student,admin'])->group(function () {
        Route::get('/mastery/records', [GradingController::class, 'getMasteryRecords']);       // #101
        Route::get('/mastery/records/{studentId}/summary', [GradingController::class, 'getStudentMasterySummary']) // #102
            ->whereNumber('studentId');
    });
});

/*
| Phase 5 — Competency Mapping Engine (ARCH-005 block 4.13)
| #73 not-competent-flags, #74 teacher competency-summary,
| #75 class-level-report, #76 admin competency-summary.
*/

Route::middleware(['auth:sanctum', 'password.change.required'])->group(function () {
    // Teacher: #73–#75
    Route::middleware(['role:teacher'])->group(function () {
        Route::get('/teacher/not-competent-flags', [\App\Http\Controllers\CompetencyMappingController::class, 'notCompetentFlags']);      // #73
        Route::get('/teacher/competency-summary', [\App\Http\Controllers\CompetencyMappingController::class, 'competencySummary']);        // #74
        Route::get('/teacher/sections/{sectionId}/class-level-report', [\App\Http\Controllers\CompetencyMappingController::class, 'classLevelReport']) // #75
            ->whereNumber('sectionId');
    });

    // Admin: #76
    Route::middleware(['role:admin'])->group(function () {
        Route::get('/admin/competency-summary', [\App\Http\Controllers\CompetencyMappingController::class, 'adminCompetencySummary']);       // #76
    });
});

/*
| Phase 6 — Analytics Dashboard (ARCH-005 block 4.14)
| #77 teacher heatmap, #78 teacher gap-report, #79 teacher trends,
| #80 teacher student-drill-down, #81 admin school-wide-overview,
| #82 student mastery-history.
| CSRF posture: bootstrap/app.php exempts only api/test/* +
| sanctum/csrf-cookie (ARCH-005 §2); these routes are
| CSRF-protected via the statefulApi/X-XSRF-TOKEN flow.
*/

Route::middleware(['auth:sanctum', 'password.change.required'])->group(function () {
    // Teacher: #77–#80
    Route::middleware(['role:teacher'])->group(function () {
        Route::get('/teacher/dashboard/heatmap', [\App\Http\Controllers\AnalyticsController::class, 'heatmap']);                  // #77
        Route::get('/teacher/dashboard/gap-report', [\App\Http\Controllers\AnalyticsController::class, 'gapReport']);             // #78
        Route::get('/teacher/dashboard/trends', [\App\Http\Controllers\AnalyticsController::class, 'trends']);                   // #79
        Route::get('/teacher/dashboard/student-drill-down', [\App\Http\Controllers\AnalyticsController::class, 'studentDrillDown']); // #80
    });

    // Admin: #81
    Route::middleware(['role:admin'])->group(function () {
        Route::get('/admin/dashboard/school-wide-overview', [\App\Http\Controllers\AnalyticsController::class, 'schoolWideOverview']); // #81
    });

    // Student: #82 (self-scoped)
    Route::middleware(['role:student'])->group(function () {
        Route::get('/student/dashboard/mastery-history', [\App\Http\Controllers\AnalyticsController::class, 'masteryHistory']);     // #82
    });
});

/*
| Phase 7 — AI Post-Assessment Support (ARCH-005 block 4.7)
| #83–#86 teacher learning materials, #87–#89 student AI explanations,
| #90–#94 teacher moderation log, #95 AI status.
| CSRF posture: bootstrap/app.php exempts only api/test/* +
| sanctum/csrf-cookie (ARCH-005 §2); these routes are
| CSRF-protected via the statefulApi/X-XSRF-TOKEN flow.
*/

Route::middleware(['auth:sanctum', 'password.change.required'])->group(function () {
    // Teacher: #83–#86 Learning Materials, #90–#94 Moderation Log
    Route::middleware(['role:teacher'])->group(function () {
        Route::get('/teacher/learning-materials', [AIController::class, 'indexLearningMaterials']);                       // #83
        Route::post('/teacher/learning-materials', [AIController::class, 'storeLearningMaterial']);                       // #84
        Route::put('/teacher/learning-materials/{id}', [AIController::class, 'updateLearningMaterial'])                   // #86
            ->whereNumber('id');
        Route::delete('/teacher/learning-materials/{id}', [AIController::class, 'destroyLearningMaterial'])              // #85
            ->whereNumber('id');

        Route::get('/teacher/moderation-log', [AIController::class, 'moderationLog']);                                  // #90
        Route::get('/teacher/moderation-log/{id}', [AIController::class, 'moderationLogShow'])                          // #91
            ->whereNumber('id');
        Route::post('/teacher/moderation-log/{id}/flag', [AIController::class, 'flagExplanation'])                      // #92
            ->middleware('throttle:moderation-write')
            ->whereNumber('id');
        Route::post('/teacher/moderation-log/{id}/note', [AIController::class, 'appendTeacherNote'])                    // #93
            ->middleware('throttle:moderation-write')
            ->whereNumber('id');
        Route::post('/teacher/moderation-log/{id}/disable-explain-further', [AIController::class, 'disableExplainFurther']) // #94
            ->middleware('throttle:moderation-write')
            ->whereNumber('id');
    });

    // Student: #87–#89 AI Explanations
    Route::middleware(['role:student'])->group(function () {
        Route::get('/student/assessments/{id}/explanations', [AIController::class, 'listExplanations'])       // #87
            ->whereNumber('id');
        // Explicit student-initiated generation (ARCH-002 FR-027 control on the results view):
        // dedicated per-student limiter — NEVER keyed by IP (ARCH-002 QA-003).
        Route::post('/student/assessments/{id}/explanations/generate', [AIController::class, 'generateExplanations'])
            ->middleware('throttle:ai-generate')
            ->whereNumber('id');
        Route::get('/student/explanations/{id}', [AIController::class, 'showExplanation'])                    // #88
            ->whereNumber('id');
        Route::post('/student/explanations/{id}/explain-further', [AIController::class, 'explainFurther'])->middleware('throttle:ai-chat') // #89 (ARCH-002 QA-003)
            ->whereNumber('id');
    });
});

// #95 AI Status — exempt from password.change.required (ARCH-003 ADR-003(3), ARCH-005 block 4.7)
Route::middleware(['auth:sanctum', 'role:teacher,student,admin'])->get('/ai/status', [AIController::class, 'aiStatus']);

/*
| Phase 8 — Audit Logging (ARCH-005 block 4.8)
| #96–#98 Admin audit-log read endpoints. Append-only (ARCH-002 QA-006).
| CSRF posture: bootstrap/app.php exempts only api/test/* +
| sanctum/csrf-cookie (ARCH-005 §2); these routes are
| CSRF-protected via the statefulApi/X-XSRF-TOKEN flow.
*/

Route::middleware(['auth:sanctum', 'password.change.required'])->group(function () {
    // Admin: #96–#98
    Route::middleware(['role:admin'])->group(function () {
        Route::get('/admin/audit-logs', [\App\Http\Controllers\AuditLogAdminController::class, 'index']);        // #96
        Route::get('/admin/audit-logs/entity', [\App\Http\Controllers\AuditLogAdminController::class, 'entity']); // #97
        Route::get('/admin/audit-logs/user/{userId}', [\App\Http\Controllers\AuditLogAdminController::class, 'user']); // #98: no whereNumber — the FormRequest integer rule owns the 422 contract (overflow guard skips this route)
    });
});

/*
| Classroom — Teacher / Student / Admin (U02 — Classroom lifecycle)
| Teacher 9, Student 4 (including POST classrooms/join with throttle:join), Admin 5
| All routes: auth:sanctum + password.change.required, role-specific, whereNumber for ids, throttle where needed.
*/

// Teacher 9 — role:teacher
Route::middleware(['auth:sanctum', 'role:teacher', 'password.change.required'])->group(function () {
    Route::get('teacher/classrooms', [TeacherClassroomController::class, 'index']);
    Route::post('teacher/classrooms', [TeacherClassroomController::class, 'store'])->middleware('throttle:classroom-key');
    Route::get('teacher/classrooms/{id}', [TeacherClassroomController::class, 'show'])->whereNumber('id');
    Route::post('teacher/classrooms/{id}/reset-key', [TeacherClassroomController::class, 'resetKey'])->middleware('throttle:classroom-key')->whereNumber('id');
    Route::post('teacher/classrooms/{id}/toggle-join', [TeacherClassroomController::class, 'toggleJoin'])->whereNumber('id');
    Route::post('teacher/classrooms/{id}/archive', [TeacherClassroomController::class, 'archive'])->whereNumber('id');
    Route::post('teacher/classrooms/{id}/unarchive', [TeacherClassroomController::class, 'unarchive'])->whereNumber('id');
    Route::get('teacher/classrooms/{id}/people', [TeacherClassroomController::class, 'people'])->whereNumber('id');
    Route::get('teacher/classrooms/{id}/competency-context', [TeacherClassroomController::class, 'competencyContext'])->whereNumber('id');
    Route::delete('teacher/classrooms/{id}/people/{studentId}', [TeacherClassroomController::class, 'removeStudent'])->whereNumber(['id', 'studentId']);

    // Classroom-scoped content — Teacher (U03)
    Route::get('teacher/classrooms/{classroomId}/announcements', [AnnouncementController::class, 'teacherIndexInClassroom'])->whereNumber('classroomId');
    Route::post('teacher/classrooms/{classroomId}/announcements', [AnnouncementController::class, 'teacherStoreInClassroom'])->whereNumber('classroomId');
    Route::get('teacher/classrooms/{classroomId}/assignments', [TeacherAssignmentController::class, 'teacherIndexInClassroom'])->whereNumber('classroomId');
    Route::post('teacher/classrooms/{classroomId}/assignments', [TeacherAssignmentController::class, 'teacherStoreInClassroom'])->whereNumber('classroomId');
    Route::get('teacher/classrooms/{classroomId}/assessments', [AssessmentController::class, 'teacherIndexInClassroom'])->whereNumber('classroomId');
    Route::post('teacher/classrooms/{classroomId}/assessments', [AssessmentController::class, 'teacherStoreInClassroom'])->whereNumber('classroomId');

    // Classroom-scoped analytics — Teacher (F-06 / U-07): heatmap/competency-summary per classroom
    Route::get('teacher/classrooms/{classroomId}/competency-summary', [\App\Http\Controllers\AnalyticsController::class, 'heatmapForClassroom'])->whereNumber('classroomId');
    Route::get('teacher/classrooms/{classroomId}/heatmap', [\App\Http\Controllers\AnalyticsController::class, 'heatmapForClassroom'])->whereNumber('classroomId');
});

// Student 4 — role:student (join is POST classrooms/join with throttle:join)
Route::middleware(['auth:sanctum', 'role:student', 'password.change.required'])->group(function () {
    Route::get('student/classrooms', [StudentClassroomController::class, 'index']);
    Route::get('student/classrooms/{id}', [StudentClassroomController::class, 'show'])->whereNumber('id');
    Route::get('student/classrooms/{id}/people', [StudentClassroomController::class, 'people'])->whereNumber('id');
    Route::post('classrooms/join', [StudentClassroomController::class, 'join'])->middleware('throttle:join');
    Route::post('student/classrooms/{id}/leave', [StudentClassroomController::class, 'leave'])->whereNumber('id');

    // Classroom-scoped content — Student (U03)
    Route::get('student/classrooms/{id}/stream', [StudentClassroomController::class, 'stream'])->whereNumber('id');
    Route::get('student/classrooms/{id}/classwork', [StudentClassroomController::class, 'classwork'])->whereNumber('id');
});

// Admin 5 — role:admin
Route::middleware(['auth:sanctum', 'role:admin', 'password.change.required'])->group(function () {
    Route::get('admin/classrooms', [AdminClassroomController::class, 'index']);
    Route::get('admin/classrooms/{id}', [AdminClassroomController::class, 'show'])->whereNumber('id');
    Route::patch('admin/classrooms/{id}', [AdminClassroomController::class, 'updateSchoolYear'])->whereNumber('id');
    Route::post('admin/classrooms/{id}/reset-key', [AdminClassroomController::class, 'resetKey'])->middleware('throttle:classroom-key')->whereNumber('id');
    Route::post('admin/classrooms/{id}/archive', [AdminClassroomController::class, 'archive'])->whereNumber('id');
    Route::post('admin/classrooms/{id}/unarchive', [AdminClassroomController::class, 'unarchive'])->whereNumber('id');

    // Hand place/move of a single learner (ARCH-005 block 4.17) — manager-only
    Route::post('admin/enrollments/place', [AdminEnrollmentController::class, 'place']);
    Route::post('admin/enrollments/{id}/move', [AdminEnrollmentController::class, 'move'])->whereNumber('id');
});
