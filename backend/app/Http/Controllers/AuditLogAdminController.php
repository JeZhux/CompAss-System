<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\AuditLogEntityRequest;
use App\Http\Requests\Admin\AuditLogQueryRequest;
use App\Models\User;
use App\Services\AuditLogService;
use App\Support\Pagination;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * Admin audit-log read endpoints: ARCH-005 block 4.8 (audit-log read endpoints, #96–#98).
 *
 * Read-only, append-only audit trail (ARCH-002 QA-006). All responses share the same
 * item shape: id, user_id, user_name, event_type, description, auditable_type,
 * auditable_id, ip_address, created_at — NO user_agent / metadata.
 *
 * @Traced-To ARCH-002 QA-006 (ARCH-005 block 4.8)
 */
class AuditLogAdminController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLogService)
    {
    }

    /** GET /api/admin/audit-logs (#96) */
    public function index(AuditLogQueryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $logs = $this->auditLogService->getAuditLogs(
            eventType: $validated['event_type'] ?? null,
            userId: isset($validated['user_id']) ? (int) $validated['user_id'] : null,
            auditableType: $validated['auditable_type'] ?? null,
            auditableId: isset($validated['auditable_id']) ? (int) $validated['auditable_id'] : null,
            fromDate: $validated['from'] ?? null,
            toDate: $validated['to'] ?? null,
            page: (int) ($validated['page'] ?? 1),
            perPage: Pagination::perPage($request),
        );

        return $this->paginatedResponse($logs);
    }

    /** GET /api/admin/audit-logs/entity (#97) */
    public function entity(AuditLogEntityRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $logs = $this->auditLogService->getLogsForEntity(
            auditableType: $validated['auditable_type'],
            auditableId: (int) $validated['auditable_id'],
            eventType: $validated['event_type'] ?? null,
            fromDate: $validated['from'] ?? null,
            toDate: $validated['to'] ?? null,
            page: (int) ($validated['page'] ?? 1),
            perPage: Pagination::perPage($request),
        );

        return $this->paginatedResponse($logs);
    }

    /** GET /api/admin/audit-logs/user/{userId} (#98) */
    public function user(AuditLogQueryRequest $request, int $userId): JsonResponse
    {
        // ModelNotFoundException bubbles to the global 404 envelope.
        User::findOrFail($userId);

        $validated = $request->validated();

        $logs = $this->auditLogService->getLogsForUser(
            userId: $userId,
            eventType: $validated['event_type'] ?? null,
            fromDate: $validated['from'] ?? null,
            toDate: $validated['to'] ?? null,
            page: (int) ($validated['page'] ?? 1),
            perPage: Pagination::perPage($request),
        );

        return $this->paginatedResponse($logs);
    }

    /**
     * Shared mapper for all three endpoints (spec shape, ARCH-005 block 4.8).
     *
     * F-08: rows older than config('audit.ip_anonymization_days') must not
     * return reversible IPs — the read-time mask matches the batch
     * anonymization (IPv4 /24, IPv6 /48) so fresh rows keep raw IPs for the
     * 90-day abuse-response window while older rows do not.
     *
     * @param  LengthAwarePaginator<int, \App\Models\AuditLog>  $logs
     */
    private function paginatedResponse(LengthAwarePaginator $logs): JsonResponse
    {
        $windowDays = (int) config('audit.ip_anonymization_days', AuditLogService::IP_ANONYMIZATION_DAYS);
        $cutoff = now()->subDays($windowDays);

        return response()->json([
            'data' => collect($logs->items())->map(function ($item) use ($cutoff) {
                $ipAddress = $item->ip_address;
                $isOld = $item->created_at !== null && $item->created_at->lt($cutoff);

                if ($ipAddress !== null && $ipAddress !== '' && $isOld) {
                    $ipAddress = AuditLogService::anonymizeIpForDisplay($ipAddress);
                }

                return [
                    'id' => $item->id,
                    'user_id' => $item->user_id,
                    'user_name' => $item->user?->name,
                    'event_type' => $item->event_type,
                    'description' => $item->description,
                    'auditable_type' => $item->auditable_type,
                    'auditable_id' => $item->auditable_id,
                    'ip_address' => $ipAddress,
                    'created_at' => $item->created_at,
                ];
            })->values(),
            'meta' => Pagination::meta($logs),
        ]);
    }
}
