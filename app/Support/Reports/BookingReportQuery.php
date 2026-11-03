<?php

namespace App\Support\Reports;

use App\Models\Booking;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

class BookingReportQuery
{
    /** @return list<string> */
    public static function statuses(): array
    {
        return ['pending', 'confirmed', 'checked_in', 'cancelled', 'no_show'];
    }

    public function run(Tenant $tenant, ReportFilters $filters, ?ReportPage $page = null): ReportDataset
    {
        $query = Booking::query()->where('tenant_id', $tenant->id)
            ->whereBetween('planned_start_date', [$filters->from->toDateString(), $filters->to->toDateString()])
            ->with(['property', 'room', 'resident', 'depositAccount.movements.cashTransaction']);
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
        $depositTotal = 0;
        $statusCounts = array_fill_keys(self::statuses(), 0);
        foreach ($query->orderBy('planned_start_date')->orderBy('id')->lazy(100) as $booking) {
            $depositReceived = (int) ($booking->depositAccount?->movements
                ->filter(fn ($movement): bool => $movement->kind === 'receipt'
                    && $movement->cashTransaction !== null
                    && $movement->cashTransaction->occurred_on->toDateString() <= $filters->to->toDateString())
                ->sum('amount_rupiah') ?? 0);
            $rentTotal += (int) $booking->agreed_monthly_rent_rupiah;
            $depositTotal += $depositReceived;
            $statusCounts[$booking->status]++;
            $reportRow = [
                'resident' => $booking->resident->full_name,
                'property' => $booking->property->name,
                'room' => $booking->room->code,
                'planned_start' => $booking->planned_start_date->toDateString(),
                'planned_end_exclusive' => $booking->planned_end_exclusive->toDateString(),
                'monthly_rent_rupiah' => (int) $booking->agreed_monthly_rent_rupiah,
                'deposit_received_rupiah' => $depositReceived,
                'status' => $booking->status,
            ];
            if ($page === null || $page->contains($rowCount)) {
                $rows[] = $reportRow;
            }
            $rowCount++;
            if ($page?->shouldStopAfter($rowCount)) {
                break;
            }
        }

        $summary = [
            'booking_count' => $rowCount,
            'monthly_rent_rupiah' => $rentTotal,
            'deposit_received_rupiah' => $depositTotal,
        ];
        $summaryLabels = [
            'booking_count' => 'Jumlah booking',
            'monthly_rent_rupiah' => 'Total tarif bulanan',
            'deposit_received_rupiah' => 'Deposit diterima',
        ];
        $statusLabels = [
            'pending' => 'Menunggu',
            'confirmed' => 'Dikonfirmasi',
            'checked_in' => 'Sudah check-in',
            'cancelled' => 'Dibatalkan',
            'no_show' => 'Tidak datang',
        ];
        foreach ($statusCounts as $status => $count) {
            $key = 'status_'.$status.'_count';
            $summary[$key] = $count;
            $summaryLabels[$key] = 'Booking · '.$statusLabels[$status];
        }

        return new ReportDataset(
            'bookings', 'Laporan Booking',
            [
                ['key' => 'resident', 'label' => 'Calon penyewa'],
                ['key' => 'property', 'label' => 'Properti'],
                ['key' => 'room', 'label' => 'Kamar'],
                ['key' => 'planned_start', 'label' => 'Rencana check-in', 'type' => 'date'],
                ['key' => 'planned_end_exclusive', 'label' => 'Akhir periode (eksklusif)', 'type' => 'date'],
                ['key' => 'monthly_rent_rupiah', 'label' => 'Tarif bulanan', 'type' => 'money'],
                ['key' => 'deposit_received_rupiah', 'label' => 'Deposit diterima s.d. akhir periode', 'type' => 'money'],
                ['key' => 'status', 'label' => 'Status'],
            ],
            $rows,
            $summary,
            $summaryLabels,
            $filters->toArray(), $filters->period(), CarbonImmutable::now('Asia/Jakarta')->format('Y-m-d H:i:s T'),
            $page === null ? null : $rowCount,
        );
    }
}
