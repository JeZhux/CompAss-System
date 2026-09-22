<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GetImportTemplateRequest;
use App\Http\Requests\Admin\ImportXlsxRequest;
use App\Http\Requests\Admin\ListCompetencyTagsRequest;
use App\Models\CompetencyReference;
use App\Models\ImportJob;
use App\Services\AuditLogService;
use App\Services\BatchImportService;
use App\Support\HumanSearch;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Batch Import endpoints: ARCH-005 block 4.6 (import operations, #30–#33).
 *
 * Templates (#30), .xlsx competency-tag import (#32), and one-time
 * error-report download (#33/#99).
 *
 * @Traced-To ARCH-002 FR-031, ARCH-002 FR-031, ARCH-002 FR-031, ARCH-002 FR-031, ARCH-003 ADR-005, ARCH-004 §8.1, ARCH-003 ADR-008
 */
class BatchImportController extends Controller
{
    public function __construct(
        private readonly BatchImportService $batchImportService,
        private readonly AuditLogService $auditLogService
    ) {
    }

    /**
     * GET /api/admin/import/templates/{type} (#30)
     *
     * Download a blank .xlsx template.
     *
     * @Traced-To ARCH-002 FR-031 (ARCH-001 §5.1)
     */
    public function downloadTemplate(GetImportTemplateRequest $request): BinaryFileResponse
    {
        $type = $request->route('type');
        $path = $this->batchImportService->downloadTemplate($type);

        $headers = [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="import_template_' . $type . '.xlsx"',
        ];

        $response = response()->file($path, $headers);
        $response->deleteFileAfterSend(true);

        return $response;
    }

    /**
     * GET /api/admin/competency-tags (catalog read)
     *
     * Read-only list over the competency_reference rows populated by #32.
     * Supports ?page/?per_page (Pagination helper), ?search= (code +
     * descriptor LIKE; digits-only rejected like staff list reads),
     * ?subject_id=, ?grade_level=, ?semester=. Returns the shared { data, meta } envelope.
     *
     * @Traced-To ARCH-002 FR-031 (ARCH-005 block 4.6)
     */
    public function indexCompetencyTags(ListCompetencyTagsRequest $request): JsonResponse
    {
        HumanSearch::rejectNumericSearch($request);

        $validated = $request->validated();

        $like = DB::getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';

        $query = CompetencyReference::query()->with('subject');

        if (! empty($validated['search'])) {
            $search = trim((string) $validated['search']);
            // Server owns LIKE safety (frontend min-2/digits guards are
            // bypassable direct-API): escape \, %, _ so wildcards match
            // literally — e.g. '%%' must not degrade into a full-table scan.
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $pattern = "%{$escaped}%";
            $query->where(function ($q) use ($pattern, $like) {
                $q->whereRaw("code {$like} ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("descriptor {$like} ? ESCAPE '\\'", [$pattern]);
            });
        }

        if (! empty($validated['subject_id'])) {
            $query->where('subject_id', (int) $validated['subject_id']);
        }

        if (! empty($validated['grade_level'])) {
            $query->where('grade_level', (string) (int) $validated['grade_level']);
        }

        if (isset($validated['semester']) && $validated['semester'] !== null && $validated['semester'] !== '') {
            $query->where('semester', (string) (int) $validated['semester']);
        }

        $query->orderBy('id');

        $paginator = $query->paginate(
            Pagination::perPage($request),
            ['*'],
            'page',
            (int) ($validated['page'] ?? 1)
        );

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (CompetencyReference $tag) => [
                'id' => $tag->id,
                'code' => $tag->code,
                'descriptor' => $tag->descriptor,
                'subject_id' => $tag->subject_id,
                'subject_name' => $tag->subject?->name,
                'subject_code' => $tag->subject?->code,
                'grade_level' => $tag->grade_level,
                'semester' => $tag->semester,
            ])
        );

        return response()->json(Pagination::response($paginator));
    }

    /**
     * POST /api/admin/import/competency-tags (#32)
     *
     * Upload .xlsx for batch competency tag import. Partial success (ARCH-002 FR-031).
     *
     * @Traced-To ARCH-002 FR-031, ARCH-002 FR-031, ARCH-002 FR-031, ARCH-004 §8.1, ARCH-003 ADR-008
     */
    public function importCompetencyTags(ImportXlsxRequest $request): JsonResponse
    {
        $result = $this->batchImportService->importCompetencyTags(
            $request->file('file'),
            (int) Auth::id()
        );

        return response()->json(['data' => $result], 200);
    }

    /**
     * POST /api/admin/import/student-enrollments/preview
     *
     * Preview full_name-only bulk learner enrollment without writing rows.
     */
    public function previewStudentEnrollments(ImportXlsxRequest $request): JsonResponse
    {
        $result = $this->batchImportService->previewStudentEnrollments(
            $request->file('file'),
            (int) Auth::id()
        );

        return response()->json(['data' => $result], 200);
    }

    /**
     * POST /api/admin/import/student-enrollments/confirm
     *
     * Confirm a previewed enrollment. The preview token is single-use.
     */
    public function confirmStudentEnrollments(Request $request): JsonResponse
    {
        // Tokens are Str::random(32); bound the field so unbounded input
        // never reaches the cache lookup (min:32/max:128 keeps headroom
        // for longer future tokens while rejecting garbage).
        $validated = $request->validate([
            'preview_token' => ['required', 'string', 'min:32', 'max:128'],
        ]);

        $result = $this->batchImportService->confirmStudentEnrollments(
            (string) $validated['preview_token'],
            (int) Auth::id()
        );

        return response()->json(['data' => $result], 200);
    }

    /**
     * POST /api/admin/import/teacher-applications/preview
     *
     * Preview full_name-only bulk teacher creation without writing rows.
     */
    public function previewTeacherApplications(ImportXlsxRequest $request): JsonResponse
    {
        $result = $this->batchImportService->previewTeacherApplications(
            $request->file('file'),
            (int) Auth::id()
        );

        return response()->json(['data' => $result], 200);
    }

    /**
     * POST /api/admin/import/teacher-applications/confirm
     *
     * Confirm a previewed teacher bulk. The preview token is single-use.
     */
    public function confirmTeacherApplications(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preview_token' => ['required', 'string', 'min:32', 'max:128'],
        ]);

        $result = $this->batchImportService->confirmTeacherApplications(
            (string) $validated['preview_token'],
            (int) Auth::id()
        );

        return response()->json(['data' => $result], 200);
    }

    /**
     * GET /api/admin/import/error-reports/{token} (#33 / #99)
     *
     * Download a one-time error report (ARCH-004 §8.1). The token is single-use and
     * time-limited (ARCH-002 FR-031 / ARCH-002 FR-031): any download attempt that cannot
     * serve a fresh report — unknown token, already-consumed report, or expired
     * TTL — yields 410 GONE (was 404; superseded per the import error-report
     * contract). The physical file is deleted and the job marked used after a
     * successful download.
     *
     * @Traced-To ARCH-002 FR-031, ARCH-004 §8.1, ARCH-002 FR-031, ARCH-002 FR-031 (ARCH-001 §5.1)
     */
    public function downloadErrorReport(Request $request): StreamedResponse|JsonResponse
    {
        $token = (string) $request->route('token');

        // Same bounds as the preview-token format (Str::random(32)):
        // out-of-range tokens can never match a real report, so short-circuit
        // to GONE before the hash lookup. Keeps the 410 contract for probes.
        if (strlen($token) < 32 || strlen($token) > 128) {
            return $this->errorReportGone();
        }

        $tokenHash = hash('sha256', $token);
        $now = now();
        $job = ImportJob::where('error_report_token', $tokenHash)->first();

        if (
            $job === null
            || $job->error_report_used_at !== null
            || $job->error_report_expires_at === null
            || $job->error_report_expires_at->lt($now)
        ) {
            return $this->errorReportGone();
        }

        // Ownership check BEFORE the destructive consume: a different admin
        // downloading is a 403 FORBIDDEN (AuthorizationException, same
        // convention as the preview confirm) and the token must survive so
        // the rightful owner can still download.
        if ((int) $job->admin_id !== (int) Auth::id()) {
            throw new AuthorizationException();
        }

        $path = $job->error_report_path;
        $jobId = $job->id;

        // Read the file BEFORE consuming the token: a missing/unreadable file
        // must not burn the single-use token (retry stays possible for the
        // 7-day TTL). Any readability failure yields 410 with no UPDATE.
        try {
            if ($path === null || ! Storage::disk(config('filesystems.default', 'local'))->exists($path)) {
                return $this->errorReportGone();
            }

            $content = Storage::disk(config('filesystems.default', 'local'))->get($path);
        } catch (\Throwable $e) {
            return $this->errorReportGone();
        }

        // Atomic single-use consume: exactly one concurrent request wins the
        // row. The conditional UPDATE matches only a still-fresh row, so a
        // parallel request that passed the checks above gets 0 affected rows
        // and discards its already-read content with a 410.
        $consumed = ImportJob::where('id', $jobId)
            ->where('error_report_token', $tokenHash)
            ->whereNull('error_report_used_at')
            ->where('error_report_expires_at', '>', $now)
            ->update([
                'error_report_used_at' => $now,
                'error_report_token' => null,
            ]);

        if ($consumed === 0) {
            return $this->errorReportGone();
        }

        // Best-effort physical delete: the token is already consumed and the
        // content is in memory, so a delete failure must not fail the request.
        try {
            Storage::disk(config('filesystems.default', 'local'))->delete($path);
        } catch (\Throwable $e) {
            Log::warning('Failed to delete consumed import error report file', [
                'job_id' => $jobId,
                'token_hash' => $tokenHash,
                'exception' => $e->getMessage(),
            ]);
        }

        // Audit trail (ARCH-002 QA-006): error_report_download. Emitted only after the
        // report was actually consumed — 410 GONE paths above write no row.
        // The raw single-use bearer MUST NOT be persisted: audit metadata
        // carries only the sha256 hash (same as the job row pre-consume),
        // attributable via auditable job id + token hash.
        $this->auditLogService->log(
            'error_report_download',
            'Import error report downloaded (job ' . $job->id . ')',
            Auth::id(),
            ImportJob::class,
            $job->id,
            ['token_hash' => $tokenHash]
        );

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, 'import_errors.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Length' => strlen($content),
        ]);
    }

    private function errorReportGone(): JsonResponse
    {
        return response()->json([
            'error' => [
                'message' => 'The error report is unavailable or has already been used.',
                'code' => 'GONE',
            ],
        ], 410);
    }
}
