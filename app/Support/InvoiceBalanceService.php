<?php

namespace App\Support;

use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InvoiceBalanceService
{
    public function totalDue(Invoice $invoice): int
    {
        $lines = (int) $invoice->lines()->sum('amount_rupiah');
        $debits = (int) $invoice->adjustments()->where('kind', 'debit')->sum('amount_rupiah');
        $credits = (int) $invoice->adjustments()->where('kind', 'credit')->sum('amount_rupiah');

        return max(0, $lines + $debits - $credits);
    }

    public function paidInCash(Invoice $invoice): int
    {
        $allocations = $invoice->cashAllocations();

        return (int) $allocations->where('kind', 'allocation')->sum('amount_rupiah')
            - (int) $allocations->where('kind', 'reversal')->sum('amount_rupiah');
    }

    public function paidFromDeposit(Invoice $invoice): int
    {
        return (int) DB::table('deposit_movements')
            ->where('tenant_id', $invoice->tenant_id)
            ->where('invoice_id', $invoice->id)
            ->where('kind', 'invoice_settlement')
            ->sum('amount_rupiah');
    }

    public function outstanding(Invoice $invoice): int
    {
        return max(0, $this->totalDue($invoice) - $this->paidInCash($invoice) - $this->paidFromDeposit($invoice));
    }

    public function status(Invoice $invoice, ?string $asOf = null): string
    {
        $outstanding = $this->outstanding($invoice);
        if ($outstanding === 0) {
            return 'paid';
        }

        $paid = $this->paidInCash($invoice) + $this->paidFromDeposit($invoice);
        $dueDate = $invoice->due_date->toDateString();
        if ($dueDate < ($asOf ?? Carbon::now('Asia/Jakarta')->toDateString())) {
            return 'overdue';
        }

        return $paid > 0 ? 'partial' : 'unpaid';
    }
}
