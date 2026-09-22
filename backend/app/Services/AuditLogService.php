<?php

namespace App\Services;

use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Centralized, append-only audit-trail writer (ARCH-002 QA-006, ARCH-002 QA-004; ARCH-001 §5.1).
 *
 * Other service classes call log() at key decision points (ARCH-001 §5.1,
 * ARCH-004 §4.1). The service classifies the event, captures the HTTP request
 * IP and User-Agent (NULL for CLI/scheduled execution), serializes metadata
 * to JSON via the model's `array` cast, and NEVER blocks the caller: any
 * persistence failure is logged server-side and log() returns null.
 *
 * The service owns no service-level collaborators — the AuditLog model is the
 * only collaborator, used as an insert prototype via newInstance().
 */
class AuditLogService
{
    /**
     * Valid PostgreSQL enum labels for `audit_logs.event_type`
     * (matches the `audit_event_type` enum, ARCH-004 §4.1 / ARCH-002 QA-006).
     *
     * @var list<string>
     */
    public const EVENT_TYPES = [
        'login',
        'logout',
        'create',
        'update',
        'delete',
        'error_report_download',
        'release_results',
        'flag_explanation',
        'disable_explain_further',
        'purge_semesters',
        'other',
    ];

    /** Days after which stored IP addresses are irreversibly anonymized (ARCH-002 QA-006, ARCH-004 §8.1). */
    public const IP_ANONYMIZATION_DAYS = 90;

    /** Default retention period in days for audit log rows (ARCH-002 QA-006, ARCH-004 §8.1). */
    public const DEFAULT_RETENTION_DAYS = 365;

    /**
     * Postgres POSIX regex for a syntactically valid dotted-quad IPv4 address.
     * Enforces 0-255 octets so the ::inet cast is only attempted on strings
     * that cannot fail — a single failed cast would abort the entire
     * anonymize statement and silently disable the daily job (ARCH-002 QA-006).
     *
     * @var string
     */
    private const IPV4_PATTERN = '^(25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])(\.(25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])){3}$';

    /**
     * Mask a login identifier (CompAss ID / school_id) to a non-reversible
     * form (F-08). The raw value is never returned: the masked form keeps
     * only the first and last characters around a mask.
     */
    public static function maskIdentifier(string $identifier): string
    {
        $trimmed = trim($identifier);

        if ($trimmed === '') {
            return '***';
        }

        if (mb_strlen($trimmed) <= 2) {
            return '***';
        }

        return mb_substr($trimmed, 0, 1).'***'.mb_substr($trimmed, -1, 1);
    }

    /**
     * Anonymize an IP for display (F-08): IPv4 zeroes the last octet, IPv6
     * zeroes the last 80 bits (/48). Matches the batch job so read-time
     * masking and stored anonymization agree. Null/empty pass through.
     */
    public static function anonymizeIpForDisplay(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return $ip;
        }

        if (preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/', $ip, $m)) {
            $octets = [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]];

            foreach ($octets as $octet) {
                if ($octet < 0 || $octet > 255) {
                    return $ip;
                }
            }

            return $octets[0].'.'.$octets[1].'.'.$octets[2].'.0';
        }

        if (str_contains($ip, ':')) {
            $packed = @inet_pton($ip);

            if ($packed === false || strlen($packed) !== 16) {
                return $ip;
            }

            $masked = substr($packed, 0, 6).str_repeat("\x00", 10);
            $out = inet_ntop($masked);

            return $out === false ? $ip : $out;
        }

        return $ip;
    }

    /**
     * @param  AuditLog  $auditLog  Prototype model used via newInstance() for inserts.
     *                              It is never mutated across calls, so a singleton
     *                              binding is safe.
     */
    public function __construct(
        private AuditLog $auditLog
    ) {
    }

    /**
     * Record an audit-trail entry.
     *
     * Appends a row to `audit_logs`. Captures the current request IP and
     * User-Agent (NULL for CLI/scheduled execution). Metadata is serialized
     * to JSON by the model's `array` cast. On any failure the error is logged
     * server-side and null is returned — the caller is never blocked (ARCH-001 §5.1
     * invariants, ARCH-002 QA-006).
     *
     * @param  string  $eventType  One of the EVENT_TYPES enum values.
     * @param  string  $description  Human-readable summary (NOT NULL).
     * @param  int|null  $userId  FK to users.id (NULL for system-triggered).
     * @param  string|null  $auditableType  Polymorphic model class.
     * @param  int|null  $auditableId  Polymorphic model id.
     * @param  array  $metadata  Plain associative array; JSON-serialized by the model.
     * @return AuditLog|null The created record, or null on failure.
     *
     * @Traced-To ARCH-002 QA-006, ARCH-002 QA-004 (ARCH-001 §5.1)
     */
    public function log(
        string $eventType,
        string $description,
        ?int $userId = null,
        ?string $auditableType = null,
        ?int $auditableId = null,
        array $metadata = []
    ): ?AuditLog {
        if (! in_array($eventType, self::EVENT_TYPES, true)) {
            Log::error('AuditLogService::log rejected invalid event type', [
                'event_type' => $eventType,
                'description' => $description,
            ]);

            return null;
        }

        try {
            // ARCH-002 QA-006 invariant: CLI / scheduled execution has no real client
            // context — capture NULL rather than a synthetic 127.0.0.1.
            $ipAddress = $this->resolveIpAddress();
            $userAgent = $this->resolveUserAgent();
            // F-08 cross-cutting logging rule: metadata must never retain raw
            // identifiers or IPs (they live in indexed columns with a managed
            // lifecycle, or as a masked form only).
            $metadata = $this->sanitizeMetadata($metadata);

            $record = $this->auditLog->newInstance();
            $record->event_type = $eventType;
            $record->description = $description;
            $record->user_id = $userId;
            $record->auditable_type = $auditableType;
            $record->auditable_id = $auditableId;
            $record->ip_address = $ipAddress;
            $record->user_agent = $userAgent;
            $record->metadata = $metadata;
            $record->save();

            return $record;
        } catch (\Throwable $e) {
            Log::error('AuditLogService::log failed to persist audit entry', [
                'exception' => $e->getMessage(),
                'event_type' => $eventType,
                'description' => $description,
            ]);

            // Append-only, non-blocking: audit failures must never surface to
            // the caller (ARCH-002 QA-006).
            return null;
        }
    }

    /**
     * Audit log entries scoped to a specific entity (ARCH-002 QA-006, ARCH-001 §5.1).
     *
     * @param  int|null  $page  Page number; when non-null the result is a
     *                          LengthAwarePaginator instead of a Collection.
     * @param  int|null  $perPage  Rows per page (default 15), only used when
     *                             pagination is enabled.
     * @return Collection<int, AuditLog>|LengthAwarePaginator<int, AuditLog>
     *
     * @Traced-To ARCH-002 QA-006 (ARCH-001 §5.1)
     */
    public function getLogsForEntity(
        string $auditableType,
        int $auditableId,
        ?string $eventType = null,
        $fromDate = null,
        $toDate = null,
        ?int $page = null,
        ?int $perPage = null
    ): Collection|LengthAwarePaginator {
        $query = $this->auditQuery(
            eventType: $eventType,
            auditableType: $auditableType,
            auditableId: $auditableId,
            fromDate: $fromDate,
            toDate: $toDate,
        )->with('user:id,name')->orderByDesc('created_at')->orderByDesc('id');

        if ($page !== null) {
            return $query->paginate($perPage ?? 15, ['*'], 'page', $page);
        }

        return $query->get();
    }

    /**
     * Audit log entries for a specific user (ARCH-002 QA-006, ARCH-001 §5.1).
     *
     * @param  int|null  $page  Page number; when non-null the result is a
     *                          LengthAwarePaginator instead of a Collection.
     * @param  int|null  $perPage  Rows per page (default 15), only used when
     *                             pagination is enabled.
     * @return Collection<int, AuditLog>|LengthAwarePaginator<int, AuditLog>
     *
     * @Traced-To ARCH-002 QA-006 (ARCH-001 §5.1)
     */
    public function getLogsForUser(
        int $userId,
        ?string $eventType = null,
        $fromDate = null,
        $toDate = null,
        ?int $page = null,
        ?int $perPage = null
    ): Collection|LengthAwarePaginator {
        $query = $this->auditQuery(
            eventType: $eventType,
            userId: $userId,
            fromDate: $fromDate,
            toDate: $toDate,
        )->with('user:id,name')->orderByDesc('created_at')->orderByDesc('id');

        if ($page !== null) {
            return $query->paginate($perPage ?? 15, ['*'], 'page', $page);
        }

        return $query->get();
    }

    /**
     * Audit log entries matching all provided filters, most recent first
     * (ARCH-002 QA-006, ARCH-001 §5.1). With no filters, returns every log.
     *
     * @param  int|null  $page  Page number; when non-null the result is a
     *                          LengthAwarePaginator instead of a Collection.
     * @param  int|null  $perPage  Rows per page (default 15), only used when
     *                             pagination is enabled.
     * @return Collection<int, AuditLog>|LengthAwarePaginator<int, AuditLog>
     *
     * @Traced-To ARCH-002 QA-006 (ARCH-001 §5.1)
     */
    public function getAuditLogs(
        ?string $eventType = null,
        ?int $userId = null,
        ?string $auditableType = null,
        ?int $auditableId = null,
        $fromDate = null,
        $toDate = null,
        ?int $page = null,
        ?int $perPage = null
    ): Collection|LengthAwarePaginator {
        $query = $this->auditQuery(
            eventType: $eventType,
            userId: $userId,
            auditableType: $auditableType,
            auditableId: $auditableId,
            fromDate: $fromDate,
            toDate: $toDate,
        )->with('user:id,name')->orderByDesc('created_at')->orderByDesc('id');

        if ($page !== null) {
            return $query->paginate($perPage ?? 15, ['*'], 'page', $page);
        }

        return $query->get();
    }

    /**
     * Batch job: irreversibly anonymize stored IP addresses for audit rows
     * older than the configured window (ARCH-002 QA-006, ARCH-004 §8.1). Zeroes the last
     * octet of IPv4 addresses or the last 80 bits of IPv6 addresses. Only
     * rows whose IP is not already anonymized are touched (idempotent).
     *
     * @param  int|null  $ipAnonymizationDays  Rows older than this many days are
     *                                    anonymized. Null honors
     *                                    config('audit.ip_anonymization_days');
     *                                    the constant is the fallback default.
     * @return int Number of rows updated.
     *
     * @Traced-To ARCH-002 QA-006 (ARCH-001 §5.1, ARCH-004 §8.1)
     */
    public function anonymizeIpAddresses(?int $ipAnonymizationDays = null): int
    {
        $ipAnonymizationDays ??= (int) config('audit.ip_anonymization_days', self::IP_ANONYMIZATION_DAYS);

        try {
            // IPv4 → host(network(set_masklen(..., 24))) zeroes the last octet;
            // host() alone would keep the host bits, so network() is required.
            // IPv6 → host(network(set_masklen(..., 48))) zeroes the last 80 bits.
            // Each AND branch is short-circuit guarded by its address-family
            // regex (IPV4_PATTERN / '~ :') so the ::inet cast is only ever
            // attempted on castable strings: a failed cast would abort the
            // ENTIRE statement and silently disable the daily job. The IPv6
            // guard is deliberately loose: ip_address is only ever written
            // from request()->ip(), which always yields a parseable address.
            // An IPv4-mapped IPv6 (::ffff:1.2.3.4) anonymizes to "::" — fully
            // anonymized, acceptable.
            $sql = sprintf(
                "UPDATE audit_logs
                 SET ip_address = CASE
                     WHEN ip_address ~ '%s' THEN host(network(set_masklen(ip_address::inet, 24)))
                     WHEN ip_address ~ ':' THEN host(network(set_masklen(ip_address::inet, 48)))
                     ELSE ip_address
                 END
                 WHERE created_at < (NOW() - INTERVAL '%d days')
                   AND ip_address IS NOT NULL
                   AND ip_address <> ''
                   AND (
                       (ip_address ~ '%s'
                        AND host(network(set_masklen(ip_address::inet, 24))) IS DISTINCT FROM ip_address)
                       OR
                       (ip_address ~ ':'
                        AND host(network(set_masklen(ip_address::inet, 48))) IS DISTINCT FROM ip_address)
                   )",
                self::IPV4_PATTERN,
                $ipAnonymizationDays,
                self::IPV4_PATTERN,
            );

            return DB::affectingStatement($sql);
        } catch (\Throwable $e) {
            Log::error('AuditLogService::anonymizeIpAddresses failed', [
                'exception' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Batch job: hard-delete audit log rows older than the retention window
     * (ARCH-002 QA-006, ARCH-004 §8.1). This is the ONLY destructive path in the audit
     * module and is intended to be invoked by a scheduled task (ARCH-002 QA-002, ARCH-002 QA-005:
     * no queues, synchronous single attempt).
     *
     * @param  int|null  $retentionDays  Rows older than this many days are deleted.
     *                              Null honors config('audit.retention_days').
     *                              Values <= 0 run without error: 0 deletes
     *                              everything older than now, negatives delete
     *                              nothing (the bound lies in the future).
     * @return int Number of rows deleted.
     *
     * @Traced-To ARCH-002 QA-006 (ARCH-001 §5.1, ARCH-004 §8.1)
     */
    public function purgeOldLogs(?int $retentionDays = null): int
    {
        $retentionDays ??= (int) config('audit.retention_days', self::DEFAULT_RETENTION_DAYS);

        try {
            return DB::affectingStatement(
                "DELETE FROM audit_logs WHERE created_at < (NOW() - (INTERVAL '1 day' * :days))",
                ['days' => $retentionDays],
            );
        } catch (\Throwable $e) {
            Log::error('AuditLogService::purgeOldLogs failed', [
                'exception' => $e->getMessage(),
                'retention_days' => $retentionDays,
            ]);

            return 0;
        }
    }

    /**
     * Strip raw identifying data from audit metadata (F-08 cross-cutting
     * logging rule). Raw secrets, identifiers, and IPs must never persist in
     * the year-retained metadata JSON: identifiers survive only as
     * `identifier_masked`, IPs live only in the `ip_address` column under the
     * 90-day anonymization lifecycle.
     *
     * @param  array  $metadata
     * @return array
     */
    private function sanitizeMetadata(array $metadata): array
    {
        foreach (['password', 'temporary_password', 'token', 'current_password', 'new_password', 'ip', 'ip_address'] as $key) {
            unset($metadata[$key]);
        }

        $rawIdentifier = null;

        foreach (['identifier', 'school_id'] as $key) {
            if (array_key_exists($key, $metadata)) {
                if ($rawIdentifier === null && is_string($metadata[$key])) {
                    $rawIdentifier = $metadata[$key];
                }

                unset($metadata[$key]);
            }
        }

        if ($rawIdentifier !== null && ! array_key_exists('identifier_masked', $metadata)) {
            $metadata['identifier_masked'] = self::maskIdentifier($rawIdentifier);
        }

        return $metadata;
    }

    /**
     * Resolve the client IP from the current HTTP context.
     */    private function resolveIpAddress(): ?string
    {
        if (app()->runningInConsole()) {
            return null;
        }

        $ip = request()->ip();

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    /**
     * Resolve the client User-Agent from the current HTTP context.
     */
    private function resolveUserAgent(): ?string
    {
        if (app()->runningInConsole()) {
            return null;
        }

        $userAgent = request()->userAgent();

        return is_string($userAgent) && $userAgent !== '' ? $userAgent : null;
    }

    /**
     * Build the base query with any of the standard optional filters applied.
     * Each filter is only applied when truthy, so fully-optional callers get
     * the unfiltered table (ARCH-002 QA-006).
     *
     * @return Builder<AuditLog>
     */
    private function auditQuery(
        ?string $eventType = null,
        ?int $userId = null,
        ?string $auditableType = null,
        ?int $auditableId = null,
        $fromDate = null,
        $toDate = null
    ): Builder {
        return AuditLog::query()
            ->when($eventType, fn (Builder $q, string $type) => $q->where('event_type', $type))
            ->when($userId, fn (Builder $q, int $id) => $q->where('user_id', $id))
            ->when($auditableType, fn (Builder $q, string $type) => $q->where('auditable_type', $type))
            ->when($auditableId, fn (Builder $q, int $id) => $q->where('auditable_id', $id))
            ->when($fromDate, fn (Builder $q, string $date) => $q->where('created_at', '>=', $date))
            ->when($toDate, function (Builder $q, string $date): Builder {
                // A date-only `to` value (e.g. "2026-07-31", ARCH-005 block 4.8) must
                // include the entire final day — without endOfDay() PostgreSQL
                // compares against midnight and silently drops that day's rows.
                // Full datetime values pass through unchanged.
                $upperBound = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
                    ? Carbon::parse($date)->endOfDay()
                    : $date;

                return $q->where('created_at', '<=', $upperBound);
            });
    }
}
