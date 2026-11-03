<?php

namespace App\Support\Reports;

use App\Models\DepositMovement;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\InvoiceCashAllocation;
use App\Models\InvoiceLine;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class InvoiceReportQuery
{
    /** @return list<string> */
    public static function statuses(): array
    {
        return ['unpaid', 'partial', 'paid', 'overdue'];
    }

    public function run(Tenant $tenant, ReportFilters $filters, ?ReportPage $page = null): ReportDataset
    {
        $lineTotals = InvoiceLine::query()->selectRaw('invoice_id, SUM(amount_rupiah) AS amount_rupiah')
            ->where('tenant_id', $tenant->id)->groupBy('invoice_id');
        $adjustmentTotals = InvoiceAdjustment::query()->selectRaw("invoice_id, SUM(CASE WHEN kind = 'debit' THEN amount_rupiah ELSE -amount_rupiah END) AS amount_rupiah")
            ->where('tenant_id', $tenant->id)->where('created_at', '<=', $filters->endUtc())->groupBy('invoice_id');
        $cashTotals = InvoiceCashAllocation::query()->selectRaw("invoice_cash_allocations.invoice_id, SUM(CASE WHEN invoice_cash_allocations.kind = 'allocation' THEN invoice_cash_allocations.amount_rupiah ELSE -invoice_cash_allocations.amount_rupiah END) AS amount_rupiah")
            ->join('cash_transactions', function ($join) use ($tenant, $filters): void {
                $join->on('cash_transactions.id', '=', 'invoice_cash_allocations.cash_transaction_id')
                    ->where('cash_transactions.tenant_id', $tenant->id)
                    ->whereDate('cash_transactions.occurred_on', '<=', $filters->to->toDateString());
            })
            ->where('invoice_cash_allocations.tenant_id', $tenant->id)->groupBy('invoice_cash_allocations.invoice_id');
        $depositTotals = DepositMovement::query()->selectRaw('invoice_id, SUM(amount_rupiah) AS amount_rupiah')
            ->where('tenant_id', $tenant->id)->where('kind', 'invoice_settlement')
            ->where('created_at', '<=', $filters->endUtc())->groupBy('invoice_id');

        $query = Invoice::query()
            ->where('invoices.tenant_id', $tenant->id)
            ->whereBetween('invoices.issued_at', [$filters->startUtc(), $filters->endUtc()])
            ->leftJoinSub($lineTotals, 'report_lines', fn ($join) => $join->on('report_lines.invoice_id', '=', 'invoices.id'))
            ->leftJoinSub($adjustmentTotals, 'report_adjustments', fn ($join) => $join->on('report_adjustments.invoice_id', '=', 'invoices.id'))
            ->leftJoinSub($cashTotals, 'report_cash', fn ($join) => $join->on('report_cash.invoice_id', '=', 'invoices.id'))
            ->leftJoinSub($depositTotals, 'report_deposit', fn ($join) => $join->on('report_deposit.invoice_id', '=', 'invoices.id'))
            ->select('invoices.*', 'report_lines.amount_rupiah AS line_total_rupiah', 'report_adjustments.amount_rupiah AS adjustment_net_rupiah', 'report_cash.amount_rupiah AS cash_paid_rupiah', 'report_deposit.amount_rupiah AS deposit_paid_rupiah')
            ->with(['booking.property', 'booking.room', 'booking.resident', 'tenancy.property', 'tenancy.room', 'tenancy.resident']);

        $this->applySourceFilters($query, $filters);

        $rows = [];
        $invoiceTotal = 0;
        $paidTotal = 0;
        $outstandingTotal = 0;
        $rowCount = 0;
        foreach ($query->orderBy('invoices.due_date')->orderBy('invoices.id')->lazy(100) as $invoice) {
            $source = $invoice->tenancy ?? $invoice->booking;
            $totalDue = max(0, (int) ($invoice->line_total_rupiah ?? 0) + (int) ($invoice->adjustment_net_rupiah ?? 0));
            $cashPaid = max(0, (int) ($invoice->cash_paid_rupiah ?? 0));
            $depositPaid = max(0, (int) ($invoice->deposit_paid_rupiah ?? 0));
            $paid = $cashPaid + $depositPaid;
            $outstanding = max(0, $totalDue - $paid);
            $status = $outstanding === 0
                ? 'paid'
                : ($invoice->due_date->toDateString() < $filters->to->toDateString()
                    ? 'overdue'
                    : ($paid > 0 ? 'partial' : 'unpaid'));

            if ($filters->status !== null && $status !== $filters->status) {
                continue;
            }

            $invoiceTotal += $totalDue;
            $paidTotal += $paid;
            $outstandingTotal += $outstanding;
            $reportRow = [
                'invoice_number' => $invoice->invoice_number,
                'resident' => $source->resident->full_name,
                'property' => $source->property->name,
                'room' => $source->room->code,
                'issued_on' => $invoice->issued_at->setTimezone('Asia/Jakarta')->toDateString(),
                'due_date' => $invoice->due_date->toDateString(),
                'amount_due_rupiah' => $totalDue,
                'paid_rupiah' => $paid,
                'outstanding_rupiah' => $outstanding,
                'status' => $status,
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
            'invoices',
            'Saldo Tagihan',
            [
                ['key' => 'invoice_number', 'label' => 'No. tagihan'],
                ['key' => 'resident', 'label' => 'Penyewa'],
                ['key' => 'property', 'label' => 'Properti'],
                ['key' => 'room', 'label' => 'Kamar'],
                ['key' => 'issued_on', 'label' => 'Tanggal terbit', 'type' => 'date'],
                ['key' => 'due_date', 'label' => 'Jatuh tempo', 'type' => 'date'],
                ['key' => 'amount_due_rupiah', 'label' => 'Tagihan', 'type' => 'money'],
                ['key' => 'paid_rupiah', 'label' => 'Dibayar', 'type' => 'money'],
                ['key' => 'outstanding_rupiah', 'label' => 'Sisa per tanggal akhir', 'type' => 'money'],
                ['key' => 'status', 'label' => 'Status'],
            ],
            $rows,
            ['amount_due_rupiah' => $invoiceTotal, 'paid_rupiah' => $paidTotal, 'outstanding_rupiah' => $outstandingTotal],
            ['amount_due_rupiah' => 'Total kewajiban', 'paid_rupiah' => 'Dibayar sampai akhir periode', 'outstanding_rupiah' => 'Outstanding per akhir periode'],
            $filters->toArray(),
            $filters->period(),
            CarbonImmutable::now('Asia/Jakarta')->format('Y-m-d H:i:s T'),
            $page === null ? null : $rowCount,
        );
    }

    /** @param Builder<Invoice> $query */
    private function applySourceFilters(Builder $query, ReportFilters $filters): void
    {
        if ($filters->propertyId === null && $filters->roomId === null) {
            return;
        }

        $apply = function (Builder $source) use ($filters): void {
            if ($filters->propertyId !== null) {
                $source->where('property_id', $filters->propertyId);
            }
            if ($filters->roomId !== null) {
                $source->where('room_id', $filters->roomId);
            }
        };

        $query->where(function (Builder $sources) use ($apply): void {
            $sources->whereHas('tenancy', $apply)->orWhereHas('booking', $apply);
        });
    }
}
