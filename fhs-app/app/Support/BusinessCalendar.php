<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Calendar boundaries in the timezone the business actually operates in.
 *
 * Timestamps are stored in UTC but the business runs on Dhaka time, six hours
 * ahead. A plain `now()->startOfMonth()` yields midnight UTC, which is 6am in
 * Dhaka — so every sale in the first six hours of a month would be counted in
 * the previous one. Deliveries do happen early morning, so that is a real
 * error and not a theoretical one.
 *
 * Ranges are half-open: `[start, end)`. Query with `>= $start` and `< $end`,
 * never `whereBetween`, which is inclusive at both ends and would count a sale
 * landing exactly on a boundary in two months at once.
 *
 * The arithmetic is deliberately done here in PHP rather than in SQL. Postgres
 * `AT TIME ZONE` has no SQLite equivalent, and the tests run on SQLite, so
 * pushing this into a query would leave the most error-prone part of the
 * application untested.
 */
final class BusinessCalendar
{
    /** Mirrors BUSINESS_TIME_ZONE in resources/js/lib/datetime.ts. */
    public const TIME_ZONE = 'Asia/Dhaka';

    /** Now, on the business clock rather than the server's. */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now()->setTimezone(self::TIME_ZONE);
    }

    /**
     * The calendar month containing $at, as UTC bounds.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function monthRange(?CarbonImmutable $at = null): array
    {
        $start = ($at ?? self::now())
            ->setTimezone(self::TIME_ZONE)
            ->startOfMonth();

        // addMonthNoOverflow, so 31 January plus a month is 28 February rather
        // than skidding into March.
        return [$start->utc(), $start->addMonthNoOverflow()->utc()];
    }

    /**
     * The month before the one containing $at.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function previousMonthRange(?CarbonImmutable $at = null): array
    {
        $start = ($at ?? self::now())
            ->setTimezone(self::TIME_ZONE)
            ->startOfMonth();

        return self::monthRange($start->subMonthNoOverflow());
    }

    /** "August 2026", for labelling a figure with the month it covers. */
    public static function monthLabel(?CarbonImmutable $at = null): string
    {
        return ($at ?? self::now())
            ->setTimezone(self::TIME_ZONE)
            ->format('F Y');
    }

    /**
     * The last $count calendar months, oldest first.
     *
     * Each entry carries its own label and UTC bounds, so a caller can bucket
     * rows without doing any timezone arithmetic of its own.
     *
     * @return array<int, array{label: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    public static function recentMonths(int $count): array
    {
        $thisMonth = self::now()->startOfMonth();

        $months = [];

        // Counted backwards from this month, then reversed, so the series ends
        // with the month in progress.
        for ($ago = $count - 1; $ago >= 0; $ago--) {
            $at = $thisMonth->subMonthsNoOverflow($ago);
            [$start, $end] = self::monthRange($at);

            $months[] = [
                'label' => $at->format('M'),
                'start' => $start,
                'end'   => $end,
            ];
        }

        return $months;
    }

    /**
     * The month a "YYYY-MM" string names, as UTC bounds.
     *
     * Parsed on the business clock, so the bounds are Dhaka month boundaries
     * like every other range here rather than UTC ones.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function monthRangeFor(string $month): array
    {
        return self::monthRange(self::parseMonth($month));
    }

    /** "2026-08" as the first instant of that month, on the business clock. */
    public static function parseMonth(string $month): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $month.'-01 00:00:00', self::TIME_ZONE)
            ->startOfMonth();
    }

    /** Whether a string names a real calendar month. */
    public static function isValidMonth(string $month): bool
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            return false;
        }

        // Not in the future: a month that has not happened has nothing to
        // report, and offering it would only produce a page of zeroes.
        return self::parseMonth($month) <= self::now()->startOfMonth();
    }

    /**
     * Calendar months from $earliest through the month in progress, newest first.
     *
     * Newest first because a report is almost always wanted for the month just
     * gone, which should be at the top of the list rather than the bottom.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function monthsSince(?CarbonImmutable $earliest): array
    {
        $current = self::now()->startOfMonth();

        // Nothing recorded yet, so the only month worth offering is this one.
        $cursor = $earliest?->setTimezone(self::TIME_ZONE)->startOfMonth() ?? $current;

        // A clock skew or a backdated record from the future would otherwise
        // run this loop backwards forever.
        if ($cursor > $current) {
            $cursor = $current;
        }

        $months = [];

        while ($cursor <= $current) {
            $months[] = [
                'value' => $cursor->format('Y-m'),
                'label' => $cursor->format('F Y'),
            ];

            $cursor = $cursor->addMonthNoOverflow();
        }

        return array_reverse($months);
    }

    /**
     * Every day of the month containing $at, oldest first.
     *
     * Days are Dhaka days, so each runs 18:00–18:00 in UTC. Bucketing on the
     * UTC date instead would push every sale before 6am onto the day before.
     *
     * @return array<int, array{label: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    public static function daysInMonth(?CarbonImmutable $at = null): array
    {
        $cursor = ($at ?? self::now())
            ->setTimezone(self::TIME_ZONE)
            ->startOfMonth();

        $monthEnd = $cursor->addMonthNoOverflow();

        $days = [];

        while ($cursor < $monthEnd) {
            $next = $cursor->addDay();

            $days[] = [
                'label' => $cursor->format('j'),
                'start' => $cursor->utc(),
                'end'   => $next->utc(),
            ];

            $cursor = $next;
        }

        return $days;
    }
}
