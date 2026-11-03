<?php

namespace App\Support\Reports;

final readonly class ReportDataset
{
    /**
     * @param  list<array{key: string, label: string, type?: string}>  $columns
     * @param  list<array<string, int|string|null>>  $rows
     * @param  array<string, int|string>  $summary
     * @param  array<string, string>  $summaryLabels
     * @param  array<string, mixed>  $filters
     * @param  array{from: string, to: string}  $period
     */
    public function __construct(
        public string $key,
        public string $title,
        public array $columns,
        public array $rows,
        public array $summary,
        public array $summaryLabels,
        public array $filters,
        public array $period,
        public string $generatedAt,
        public ?int $totalRows = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'columns' => $this->columns,
            'rows' => $this->rows,
            'summary' => $this->summary,
            'summary_labels' => $this->summaryLabels,
            'filters' => $this->filters,
            'period' => $this->period,
            'generated_at' => $this->generatedAt,
            'total_rows' => $this->totalRows ?? count($this->rows),
        ];
    }
}
