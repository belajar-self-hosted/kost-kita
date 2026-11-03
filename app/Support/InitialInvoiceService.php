<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\Tenancy;
use App\Models\User;

class InitialInvoiceService
{
    public function __construct(private readonly MonthlyBillingCycle $cycles) {}

    public function createForActualCheckIn(Tenancy $tenancy, User $actor): Invoice
    {
        if ($tenancy->status !== 'active' || $tenancy->actual_start_date === null || $tenancy->billing_anchor_day === null) {
            throw new \LogicException('An initial rent invoice requires an active tenancy with an actual check-in date and billing anchor.');
        }

        $cycle = $this->cycles->forStart($tenancy->actual_start_date->toDateString(), $tenancy->billing_anchor_day);
        $idempotencyKey = "tenancy:{$tenancy->id}:rent:{$cycle['start_date']}";
        $existing = Invoice::query()
            ->where('tenant_id', $tenancy->tenant_id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing) {
            return $existing->load('lines');
        }

        $roomCode = $tenancy->room()->value('code') ?? 'Kamar';
        $invoice = Invoice::query()->create([
            'tenant_id' => $tenancy->tenant_id,
            'tenancy_id' => $tenancy->id,
            'invoice_number' => sprintf('KK-%d-%s', $tenancy->id, str_replace('-', '', $cycle['start_date'])),
            'idempotency_key' => $idempotencyKey,
            'issued_at' => now('UTC'),
            'due_date' => $cycle['start_date'],
            'cycle_start_date' => $cycle['start_date'],
            'cycle_end_exclusive' => $cycle['end_exclusive'],
            'billing_anchor_day' => $tenancy->billing_anchor_day,
            'created_by' => $actor->id,
        ]);
        $invoice->lines()->create([
            'tenant_id' => $tenancy->tenant_id,
            'line_number' => 1,
            'kind' => 'rent',
            'description' => "Sewa kamar {$roomCode} {$cycle['start_date']}–{$cycle['end_exclusive']}",
            'amount_rupiah' => $tenancy->agreed_monthly_rent_rupiah,
            'monthly_rent_snapshot_rupiah' => $tenancy->agreed_monthly_rent_rupiah,
            'service_start_date' => $cycle['start_date'],
            'service_end_exclusive' => $cycle['end_exclusive'],
        ]);

        return $invoice->load('lines');
    }
}
