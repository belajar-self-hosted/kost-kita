<?php

namespace App\Support\Reports;

use App\Models\Tenancy;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class TenancyReportQuery
{
    /** @return list<string> */
    public static function statuses(): array
    {
        return ['scheduled', 'active', 'completed', 'terminated'];
    }

    public function run(Tenant $tenant, ReportFilters $filters, ?ReportPage $page = null): ReportDataset
    {
        $from = $filters->from->toDateString();
        $to = $filters->to->toDateString();
        $query = Tenancy::query()->where('tenant_id', $tenant->id)
            ->where(function (Builder $range) use ($from, $to): void {
                $range->where(function (Builder $scheduled) use ($from, $to): void {
                    $scheduled->where('status', 'scheduled')
                        ->whereDate('planned_start_date', '<=', $to)
                        ->whereDate('planned_end_exclusive', '>', $from);
                })->orWhere(function (Builder $occupied) use ($from, $to): void {
                    $occupied->whereNotNull('actual_start_date')
                        ->whereDate('actual_start_date', '<=', $to)
                        ->where(function (Builder $end) use ($from): void {
                            $end->whereNull('actual_checkout_date')->orWhereDate('actual_checkout_date', '>=', $from);
                        });
                });
            })
            ->with(['property', 'room', 'resident']);
        if ($filters->propertyId !== null) {
            $query->where('property_id', $filters->propertyId);
        }
        if ($filters->roomId !== null) {
            $query->where('room_id', $filters->roomId);
        }
        if ($filters->status !== null) {
            $query->where('status', $filters->status);
        }

        $rows = [];
        $rowCount = 0;
        $rentTotal = 0;
        foreach ($query->orderBy('planned_start_date')->orderBy('id')->lazy(100) as $tenancy) {
            $rent = (int) $tenancy->agreed_monthly_rent_rupiah;
            $rentTotal += $rent;
            $reportRow = [
                'resident' => $tenancy->resident->full_name,
                'property' => $tenancy->property->name,
                'room' => $tenancy->room->code,
                'status' => $tenancy->status,
                'actual_start' => $tenancy->actual_start_date?->toDateString() ?? '—',
                'planned_end_exclusive' => $tenancy->planned_end_exclusive->toDateString(),
                'actual_checkout' => $tenancy->actual_checkout_date?->toDateString() ?? '—',
                'monthly_rent_rupiah' => $rent,
            ];
            if ($page === null || $page->contains($rowCount)) {
                $rows[] = $reportRow;
            }
            $rowCount++;
            if ($page?->shouldStopAfter($rowCount)) {
                break;
            }
        }

        return new ReportDataset(
            'tenancies', 'Riwayat Masa Sewa',
            [
                ['key' => 'resident', 'label' => 'Penyewa'],
                ['key' => 'property', 'label' => 'Properti'],
                ['key' => 'room', 'label' => 'Kamar'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'actual_start', 'label' => 'Check-in aktual', 'type' => 'date'],
                ['key' => 'planned_end_exclusive', 'label' => 'Akhir rencana (eksklusif)', 'type' => 'date'],
                ['key' => 'actual_checkout', 'label' => 'Check-out aktual', 'type' => 'date'],
                ['key' => 'monthly_rent_rupiah', 'label' => 'Tarif bulanan', 'type' => 'money'],
            ],
            $rows,
            ['tenancy_count' => $rowCount, 'monthly_rent_rupiah' => $rentTotal],
            ['tenancy_count' => 'Jumlah masa sewa', 'monthly_rent_rupiah' => 'Total tarif bulanan'],
            $filters->toArray(), $filters->period(), CarbonImmutable::now('Asia/Jakarta')->format('Y-m-d H:i:s T'),
            $page === null ? null : $rowCount,
        );
    }
}
