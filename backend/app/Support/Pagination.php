<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Shared pagination contract (ARCH-005 §2): every list endpoint returns
 * { data, meta } with per_page clamped to 1..100 (out-of-range → default).
 */
final class Pagination
{
    public static function perPage(?Request $request, int $default = 15): int
    {
        $perPage = $request?->query('per_page');

        if ($perPage === null || $perPage === '') {
            return $default;
        }

        $perPage = (int) $perPage;

        if ($perPage < 1) {
            return $default;
        }

        return min($perPage, 100);
    }

    public static function meta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'from' => $paginator->firstItem(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'to' => $paginator->lastItem(),
            'total' => $paginator->total(),
        ];
    }

    public static function response(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => $paginator->items(),
            'meta' => self::meta($paginator),
        ];
    }
}
