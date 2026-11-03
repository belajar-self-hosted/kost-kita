<?php

namespace App\Support\Reports;

use App\Models\MaintenanceJob;
use App\Models\Tenant;
use Illuminate\Validation\ValidationException;

class ReportQueryService
{
    public function __construct(
        private readonly CashFlowReportQuery $cashFlow,
        private readonly InvoiceReportQuery $invoices,
        private readonly BookingReportQuery $bookings,
        private readonly TenancyReportQuery $tenancies,
        private readonly MaintenanceReportQuery $maintenance,
    ) {}

    /** @return list<string> */
    public function types(string $report, Tenant $tenant): array
    {
        return match ($report) {
            'cash-flow' => CashFlowReportQuery::types(),
            'maintenance' => array_values(MaintenanceJob::query()->where('tenant_id', $tenant->id)->distinct()->orderBy('category')
                ->pluck('category')->map(static fn ($category): string => (string) $category)->values()->all()),
            'invoices', 'bookings', 'tenancies' => [],
            default => throw ValidationException::withMessages(['report' => 'Jenis laporan tidak tersedia.']),
        };
    }

    /** @return list<string> */
    public function statuses(string $report): array
    {
        return match ($report) {
            'invoices' => InvoiceReportQuery::statuses(),
            'bookings' => BookingReportQuery::statuses(),
            'tenancies' => TenancyReportQuery::statuses(),
            'maintenance' => MaintenanceReportQuery::statuses(),
            'cash-flow' => [],
            default => throw ValidationException::withMessages(['report' => 'Jenis laporan tidak tersedia.']),
        };
    }

    public function run(string $report, Tenant $tenant, ReportFilters $filters, ?ReportPage $page = null): ReportDataset
    {
        return match ($report) {
            'cash-flow' => $this->cashFlow->run($tenant, $filters, $page),
            'invoices' => $this->invoices->run($tenant, $filters, $page),
            'bookings' => $this->bookings->run($tenant, $filters, $page),
            'tenancies' => $this->tenancies->run($tenant, $filters, $page),
            'maintenance' => $this->maintenance->run($tenant, $filters, $page),
            default => throw ValidationException::withMessages(['report' => 'Jenis laporan tidak tersedia.']),
        };
    }
}
