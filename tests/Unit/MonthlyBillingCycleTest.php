<?php

namespace Tests\Unit;

use App\Support\MonthlyBillingCycle;
use PHPUnit\Framework\TestCase;

class MonthlyBillingCycleTest extends TestCase
{
    public function test_anchor_day_does_not_drift_after_a_short_month(): void
    {
        $cycles = new MonthlyBillingCycle;

        $january = $cycles->forStart('2027-01-31', 31);
        $february = $cycles->forStart($january['end_exclusive'], 31);
        $march = $cycles->forStart($february['end_exclusive'], 31);

        $this->assertSame(['start_date' => '2027-01-31', 'end_exclusive' => '2027-02-28'], $january);
        $this->assertSame(['start_date' => '2027-02-28', 'end_exclusive' => '2027-03-31'], $february);
        $this->assertSame(['start_date' => '2027-03-31', 'end_exclusive' => '2027-04-30'], $march);
    }

    public function test_leap_year_cycle_uses_the_original_anchor_day(): void
    {
        $cycles = new MonthlyBillingCycle;

        $february = $cycles->forStart('2028-01-29', 29);
        $march = $cycles->forStart($february['end_exclusive'], 29);

        $this->assertSame(['start_date' => '2028-01-29', 'end_exclusive' => '2028-02-29'], $february);
        $this->assertSame(['start_date' => '2028-02-29', 'end_exclusive' => '2028-03-29'], $march);
    }

    public function test_rate_change_starts_at_the_next_cycle_boundary_or_the_current_boundary(): void
    {
        $cycles = new MonthlyBillingCycle;

        $this->assertSame('2027-02-28', $cycles->effectiveStartForDate('2027-02-10', '2027-01-31', 31));
        $this->assertSame('2027-02-28', $cycles->effectiveStartForDate('2027-02-28', '2027-01-31', 31));
    }

    public function test_current_cycle_start_uses_the_latest_calendar_boundary_not_the_next_boundary(): void
    {
        $cycles = new MonthlyBillingCycle;

        $this->assertSame('2026-10-31', $cycles->cycleStartForDate('2026-11-15', '2026-10-31', 31));
        $this->assertSame('2026-11-30', $cycles->cycleStartForDate('2026-12-01', '2026-10-31', 31));
        $this->assertSame('2026-10-31', $cycles->cycleStartForDate('2026-10-31', '2026-10-31', 31));
    }
}
