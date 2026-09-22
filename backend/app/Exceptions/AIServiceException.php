<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Thrown for AI-service-specific error conditions that require a stable,
 * machine-readable error code in the 422 or 503 error envelope.
 *
 * Carries a stable `code` string (e.g. EXPLAIN_FURTHER_SUBJECTIVE_ITEM,
 * EXPLAIN_FURTHER_TURN_LIMIT, EXPLAIN_FURTHER_DISABLED, AI_SERVICE_UNAVAILABLE)
 * so the API error envelope (ARCH-002 QA-007) can surface the exact reason defined in
 * ARCH-005 block 4.7 error-convention table.
 *
 * Rendered in bootstrap/app.php `withExceptions` render callback.
 *
 * @Traced-To ARCH-002 QA-007 (actionable error messages, no tech detail),
 *   ARCH-002 FR-028 (disclaimer on every AI explanation),
 *   ARCH-005 block 4.7 (AI-explanation endpoints)
 */
class AIServiceException extends HttpException
{
    /**
     * @param  string  $message      Human-readable, client-safe message.
     * @param  string  $errorCode    Stable error code surfaced in the envelope.
     * @param  int  $statusCode      HTTP status (422 for rejections, 503 for outage).
     * @param  \Throwable|null  $previous
     * @param  array  $headers
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        int $statusCode,
        ?\Throwable $previous = null,
        array $headers = []
    ) {
        parent::__construct($statusCode, $message, $previous, $headers);
    }
}
