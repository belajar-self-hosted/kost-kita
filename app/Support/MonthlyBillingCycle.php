<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

class MonthlyBillingCycle
{
    /** @return array{start_date:string,end_exclusive:string} */
    public function forStart(string $startDate, int $anchorDay): array
    {
        $start = $this->date($startDate);
        $this->assertAnchorDay($anchorDay);

        return [
            'start_date' => $start->toDateString(),
            'end_exclusive' => $this->nextCycleStart($start, $anchorDay)->toDateString(),
        ];
    }

    public function effectiveStartForDate(string $effectiveDate, string $billingStartDate, int $anchorDay): string
    {
        $effective = $this->date($effectiveDate);
        $billingStart = $this->date($billingStartDate);
        $this->assertAnchorDay($anchorDay);

        if ($effective->lessThan($billingStart)) {
            throw new InvalidArgumentException('The effective date cannot precede the billing start date.');
        }

        $candidate = $this->dateInMonth($effective, $anchorDay);
        if ($candidate->greaterThan($effective)) {
            $candidate = $this->dateInMonth($effective->subMonthNoOverflow(), $anchorDay);
        }

        if ($candidate->lessThan($billingStart)) {
            $candidate = $billingStart;
        }

        if ($candidate->equalTo($effective)) {
            return $candidate->toDateString();
        }

        return $this->nextCycleStart($candidate, $anchorDay)->toDateString();
    }

    public function cycleStartForDate(string $date, string $billingStartDate, int $anchorDay): string
    {
        $effective = $this->date($date);
        $billingStart = $this->date($billingStartDate);
        $this->assertAnchorDay($anchorDay);

        if ($effective->lessThan($billingStart)) {
            throw new InvalidArgumentException('The date cannot precede the billing start date.');
        }

        $candidate = $this->dateInMonth($effective, $anchorDay);
        if ($candidate->greaterThan($effective)) {
            $candidate = $this->dateInMonth($effective->subMonthNoOverflow(), $anchorDay);
        }

        if ($candidate->lessThan($billingStart)) {
            $candidate = $billingStart;
        }

        return $candidate->toDateString();
    }

    private function nextCycleStart(CarbonImmutable $start, int $anchorDay): CarbonImmutable
    {
        $nextMonth = $start->startOfMonth()->addMonthNoOverflow();

        return $this->dateInMonth($nextMonth, $anchorDay);
    }

    private function dateInMonth(CarbonImmutable $month, int $anchorDay): CarbonImmutable
    {
        $monthStart = $month->startOfMonth();

        return $monthStart->setDay(min($anchorDay, $monthStart->daysInMonth));
    }

    private function date(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'Asia/Jakarta')->startOfDay();
    }

    private function assertAnchorDay(int $anchorDay): void
    {
        if ($anchorDay < 1 || $anchorDay > 31) {
            throw new InvalidArgumentException('The billing anchor day must be between 1 and 31.');
        }
    }
}
