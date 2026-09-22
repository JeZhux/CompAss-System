<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a business rule blocks an operation (409 Conflict by default).
 *
 * Carries a stable, machine-readable `code` (e.g. SUBJECT_SECTION_ALREADY_ASSIGNED)
 * so the API error envelope can surface the exact reason defined in
 * ARCH-005 §2 error-convention and per-endpoint error cases.
 *
 * Rendered in the error-envelope renderer (bootstrap/app.php) using the
 * optional status override when provided — 409 by default, 403 for
 * SUBJECT_SECTION_NOT_ASSIGNED and 422 for INVALID_FILE_TYPE variants.
 *
 * @Traced-To ARCH-002 QA-007 (clear specific actionable error messages, no tech detail)
 */
class BusinessRuleConflictException extends RuntimeException
{
    /**
     * @param  string  $message  Human-readable, client-safe message.
     * @param  string  $errorCode  Stable error code surfaced in the envelope.
     * @param  int|null  $statusCode  Optional HTTP status override (defaults to 409).
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        private readonly ?int $statusCode = null
    ) {
        parent::__construct($message);
    }

    /**
     * HTTP status for the rendered envelope: the override when provided,
     * 409 otherwise (the historical Conflict semantics).
     */
    public function getStatusCode(): int
    {
        return $this->statusCode ?? 409;
    }
}
