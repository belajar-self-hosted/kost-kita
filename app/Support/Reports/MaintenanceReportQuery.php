<?php

namespace App\Support\Reports;

use App\Models\MaintenanceJob;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

class MaintenanceReportQuery
{
    /** @return list<string> */
    public static function statuses(): array
    {
        return ['reported', 'scheduled', 'completed', 'cancelled'];
    }

    public function run(Tenant $tenant, ReportFilters $filters, ?ReportPage $page = null): ReportDataset
    {
        $query = MaintenanceJob::query()->where('tenant_id', $tenant->id)
            ->whereBetween('created_at', [$filters->startUtc(), $filters->endUtc()])
            ->with(['property', 'room', 'expenses.payments.cashTransaction']);
        if ($filters->propertyId !== null) {
            $query->where('property_id', $filters->propertyId);
        }
        if ($filters->roomId !== null) {
            $query->where('room_id', $filters->roomId);
        }
        if ($filters->type !== null) {
            $query->where('category', $filters->type);
        }
        if ($filters->status !== null) {
            $query->where('status', $filters->status);
        }

        $rows = [];
        $rowCount = 0;
        $paidTotal = 0;
        $obligationTotal = 0;
        $paidByCategory = [];
        foreach ($query->orderBy('created_at')->orderBy('id')->lazy(100) as $job) {
            $paidInPeriod = 0;
            $obligation = 0;
            foreach ($job->expenses as $expense) {
                $obligation += (int) $expense->amount_due_rupiah;
                foreach ($expense->payments as $payment) {
                    $paymentDate = $payment->cashTransaction->occurred_on->toDateString();
                    if ($payment->kind === 'allocation'
                        && $paymentDate >= $filters->from->toDateString()
                        && $paymentDate <= $filters->to->toDateString()) {
                        $paidInPeriod += (int) $payment->amount_rupiah;
                    }
                }
            }
            $paidTotal += $paidInPeriod;
            $obligationTotal += $obligation;
            $paidByCategory[$job->category] = ($paidByCategory[$job->category] ?? 0) + $paidInPeriod;
            $reportRow = [
                'reported_on' => $job->created_at->setTimezone('Asia/Jakarta')->toDateString(),
                'property' => $job->property->name,
                'room' => $job->room_id === null ? '—' : $job->room->code,
                'category' => $job->category,
                'description' => $job->description,
                'status' => $job->status,
                'blocks_room' => $job->blocks_room ? 'Ya' : 'Tidak',
                'cash_paid_in_period_rupiah' => $paidInPeriod,
                'expense_obligation_rupiah' => $obligation,
            ];
            if ($page === null || $page->contains($rowCount)) {
                $rows[] = $reportRow;
            }
            $rowCount++;
            if ($page?->shouldStopAfter($rowCount)) {
                break;
            }
        }

        $summary = ['job_count' => $rowCount, 'cash_paid_rupiah' => $paidTotal, 'expense_obligation_rupiah' => $obligationTotal];
        $summaryLabels = ['job_count' => 'Jumlah pekerjaan', 'cash_paid_rupiah' => 'Kas keluar periode ini', 'expense_obligation_rupiah' => 'Total kewajiban biaya'];
        ksort($paidByCategory);
        foreach (array_values($paidByCategory) as $index => $amount) {
            $category = array_keys($paidByCategory)[$index];
            $key = 'cash_paid_category_'.$index.'_rupiah';
            $summary[$key] = $amount;
            $summaryLabels[$key] = 'Kas maintenance · '.$category;
        }

        return new ReportDataset(
            'maintenance', 'Laporan Maintenance',
            [
                ['key' => 'reported_on', 'label' => 'Tanggal laporan', 'type' => 'date'],
                ['key' => 'property', 'label' => 'Properti'],
                ['key' => 'room', 'label' => 'Kamar'],
                ['key' => 'category', 'label' => 'Kategori'],
                ['key' => 'description', 'label' => 'Pekerjaan'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'blocks_room', 'label' => 'Blokir kamar'],
                ['key' => 'cash_paid_in_period_rupiah', 'label' => 'Kas keluar periode ini', 'type' => 'money'],
                ['key' => 'expense_obligation_rupiah', 'label' => 'Total kewajiban biaya', 'type' => 'money'],
            ],
            $rows,
            $summary,
            $summaryLabels,
            $filters->toArray(), $filters->period(), CarbonImmutable::now('Asia/Jakarta')->format('Y-m-d H:i:s T'),
            $page === null ? null : $rowCount,
        );
    }
}
