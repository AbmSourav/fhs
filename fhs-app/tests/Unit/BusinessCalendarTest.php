<?php

namespace Tests\Unit;

use App\Support\BusinessCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Month boundaries, in the timezone the business runs in.
 *
 * Asserted as raw UTC instants and kept apart from the query layer, so a
 * boundary error names itself here rather than surfacing as a figure that is
 * quietly six hours out.
 */
class BusinessCalendarTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** Freeze the clock at a UTC instant. */
    private function freezeAt(string $utc): void
    {
        Carbon::setTestNow($utc);
        CarbonImmutable::setTestNow($utc);
    }

    public function test_a_month_starts_at_midnight_in_dhaka_not_utc(): void
    {
        $this->freezeAt('2026-08-15 12:00:00');

        [$start, $end] = BusinessCalendar::monthRange();

        // Midnight on 1 August in Dhaka is 18:00 on 31 July in UTC. Taking the
        // UTC month start instead would miss the first six hours of business.
        $this->assertSame('2026-07-31 18:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-31 18:00:00', $end->format('Y-m-d H:i:s'));
    }

    public function test_a_sale_just_after_midnight_dhaka_falls_inside_the_month(): void
    {
        $this->freezeAt('2026-08-15 12:00:00');

        [$start, $end] = BusinessCalendar::monthRange();

        // 02:00 on 1 August in Dhaka — still 31 July by UTC, but August's
        // trading by any measure the business would recognise.
        $sale = CarbonImmutable::parse('2026-07-31 20:00:00');

        $this->assertTrue($sale >= $start && $sale < $end);
    }

    public function test_a_sale_late_on_the_last_day_falls_in_the_old_month(): void
    {
        $this->freezeAt('2026-08-15 12:00:00');

        [$previousStart, $previousEnd] = BusinessCalendar::previousMonthRange();

        // 23:30 on 31 July in Dhaka is 17:30 UTC — the mirror case, which
        // catches a correction applied in the wrong direction.
        $sale = CarbonImmutable::parse('2026-07-31 17:30:00');

        $this->assertTrue($sale >= $previousStart && $sale < $previousEnd);
    }

    public function test_the_boundary_instant_belongs_to_exactly_one_month(): void
    {
        $this->freezeAt('2026-08-15 12:00:00');

        [$start] = BusinessCalendar::monthRange();
        [, $previousEnd] = BusinessCalendar::previousMonthRange();

        // The ranges meet rather than overlap: one month's end is the next
        // month's start, and being half-open counts it once.
        $this->assertTrue($previousEnd->equalTo($start));
    }

    public function test_the_previous_month_is_the_whole_month_before(): void
    {
        $this->freezeAt('2026-08-15 12:00:00');

        [$start, $end] = BusinessCalendar::previousMonthRange();

        $this->assertSame('2026-06-30 18:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-31 18:00:00', $end->format('Y-m-d H:i:s'));
    }

    public function test_a_long_month_does_not_overflow_into_the_month_after_next(): void
    {
        // 31 January plus one month is 28 February, not 3 March.
        $this->freezeAt('2026-01-31 12:00:00');

        [$start, $end] = BusinessCalendar::monthRange();

        $this->assertSame('2025-12-31 18:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-31 18:00:00', $end->format('Y-m-d H:i:s'));
    }

    public function test_the_previous_month_of_january_is_december(): void
    {
        $this->freezeAt('2026-01-15 12:00:00');

        [$start, $end] = BusinessCalendar::previousMonthRange();

        // Crossing the year boundary backwards.
        $this->assertSame('2025-11-30 18:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2025-12-31 18:00:00', $end->format('Y-m-d H:i:s'));
    }

    public function test_the_month_is_labelled_in_business_time(): void
    {
        // 21:00 UTC on 31 July is already 1 August in Dhaka, so the label must
        // say August.
        $this->freezeAt('2026-07-31 21:00:00');

        $this->assertSame('August 2026', BusinessCalendar::monthLabel());
    }
}
