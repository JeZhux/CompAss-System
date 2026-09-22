<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Academic year helper — Aug 1 inclusive → Jul 31 inclusive.
 *
 * Aug 1 .. Dec 31  => YYYY-(YYYY+1)
 * Jan 1 .. Jul 31  => (YYYY-1)-YYYY
 *
 * @Traced-To U01 Backend migrations
 */
final class AcademicYear
{
    /**
     * Return the academic year string for the given date.
     *
     * Aug 1 (inclusive) .. Dec 31 => YYYY-(YYYY+1)
     * Jan 1 .. Jul 31 (inclusive) => (YYYY-1)-YYYY
     *
     * @param CarbonInterface|\DateTimeInterface|string|null $date Defaults to now() in app timezone (Asia/Manila).
     */
    public static function for(CarbonInterface|\DateTimeInterface|string|null $date = null): string
    {
        $carbon = match (true) {
            $date === null => Carbon::now(),
            $date instanceof CarbonInterface => Carbon::instance($date),
            $date instanceof \DateTimeInterface => Carbon::instance($date),
            is_string($date) => Carbon::parse($date),
            default => Carbon::parse($date),
        };

        $year = (int) $carbon->format('Y');
        $month = (int) $carbon->format('n');

        if ($month >= 8) {
            return $year . '-' . ($year + 1);
        }

        return ($year - 1) . '-' . $year;
    }

    /**
     * Alias of for() — kept for backwards compatibility with early U01 draft.
     */
    public static function academicYearFor(CarbonInterface|\DateTimeInterface|string|null $date = null): string
    {
        return self::for($date);
    }

    /**
     * Convenience alias — current academic year for now().
     */
    public static function current(): string
    {
        return self::for();
    }
}
