<?php

namespace App\Http\Middleware;

use App\Http\Controllers\AuditLogAdminController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * AUD-001 (layer 2): whereNumber compiles to unbounded [0-9]+, so a pure-digit
 * segment exceeding PHP_INT_MAX still matches routing and reaches int-typed
 * controller signatures, where coercive conversion throws TypeError → 500.
 *
 * For every route parameter whose name is in the audited integer-param list,
 * a digit-only value is rejected with 404 NOT_FOUND (same envelope as
 * ModelNotFound rendering) unless it parses losslessly via FILTER_VALIDATE_INT
 * — i.e., it must be canonical decimal form within PHP_INT_MAX range.
 * Leading-zero or signed forms are rejected as non-canonical; this is
 * intended canonical-URL discipline (auto-increment ids are never zero-padded)
 * — in practice only zero-padded values reach this check, since routing-level
 * whereNumber already restricts matching segments to bare digits. Non-numeric
 * strings are left to routing-level constraints; parameters outside the list
 * (e.g. the {type}/{token} exclusions) pass through untouched
 * regardless of shape, so digit-shaped opaque tokens keep reaching their hash
 * lookup.
 *
 * Exclusion: the audit #98 route (AuditLogAdminController@user) is skipped
 * entirely — its {userId} flows through AuditLogQueryRequest's integer rule,
 * whose tested contract is 422 VALIDATION_ERROR (including for digit-shaped
 * overflow, which the integer rule range-rejects). Routing-level and
 * middleware-level 404s would mask that contract.
 */
class ValidateNumericRouteParams
{
    /**
     * Route parameter names consumed as PHP ints somewhere on api.php routes,
     * per the U1 inventory (controller signatures verified). A new
     * integer-consumed param must be added here to gain overflow protection.
     */
    private const INTEGER_PARAM_NAMES = [
        'assessmentId',
        'assignmentId',
        'attachmentId',
        'attemptId',
        'classroomId',
        'fileId',
        'gradeLevelId',
        'id',
        'itemId',
        'schoolYearId',
        'sectionId',
        'studentId',
        'submissionId',
        'termId',
        'userId',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Audit #98 owns its {userId} contract via FormRequest (422) — skip
        // both the digit-overflow 404 and any other interference here.
        if ($request->route()?->getActionName() === AuditLogAdminController::class . '@user') {
            return $next($request);
        }

        $params = $request->route()?->parameters() ?? [];

        foreach ($params as $name => $value) {
            if (
                in_array($name, self::INTEGER_PARAM_NAMES, true)
                && is_string($value)
                && preg_match('/^[0-9]+$/', $value) === 1
                && filter_var($value, FILTER_VALIDATE_INT) === false
            ) {
                throw new NotFoundHttpException('The requested resource was not found.');
            }
        }

        return $next($request);
    }
}
