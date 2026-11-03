<?php

namespace App\Support\Reports;

final readonly class ReportPage
{
    public function __construct(public int $offset, public int $limit, public bool $stopAfterLimit = false)
    {
        if ($offset < 0 || $limit < 1) {
            throw new \InvalidArgumentException('A report page needs a non-negative offset and a positive limit.');
        }
    }

    public function contains(int $rowIndex): bool
    {
        return $rowIndex >= $this->offset && $rowIndex < $this->offset + $this->limit;
    }

    public function shouldStopAfter(int $rowCount): bool
    {
        return $this->stopAfterLimit && $rowCount >= $this->offset + $this->limit;
    }
}
