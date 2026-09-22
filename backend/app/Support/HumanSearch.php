<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Human-name search rule (ARCH-005 block 4.22 / ADR-013).
 *
 * Staff list reads match human names and human codes only; a search
 * holding only internal-number digits is rejected with a validation
 * error. Learner-facing reads define no search parameter at all and
 * reject any ?search= value at the controller level.
 */
final class HumanSearch
{
    public static function rejectNumericSearch(Request $request): void
    {
        if (! $request->filled('search')) {
            return;
        }

        $search = trim((string) $request->query('search'));

        if ($search !== '' && preg_match('/^\d+$/', $search) === 1) {
            throw ValidationException::withMessages([
                'search' => ['Search by name or code only. Numbers cannot be used for search. '
                    . 'Please contact your administrator if you need help.'],
            ]);
        }
    }
}
