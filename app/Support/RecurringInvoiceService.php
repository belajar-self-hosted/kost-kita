<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Tenancy;
use App\Models\TenancyExtension;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RecurringInvoiceService
{
    public function __construct(
        private readonly MonthlyBillingCycle $cycles,
        private readonly AuditLogger $audit,
    ) {}

    public function generateFor(Tenancy $tenancy, CarbonImmutable $throughDate): int
    {
        return DB::transaction(function () use ($tenancy, $throughDate): int {
            $tenancy = Tenancy::query()
                ->where('tenant_id', $tenancy->tenant_id)
                ->whereKey($tenancy->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($tenancy->actual_start_date === null || $tenancy->billing_anchor_day === null) {
                return 0;
            }

            $anchorDay = $tenancy->billing_anchor_day;
            $checkIn = CarbonImmutable::parse($tenancy->actual_start_date->toDateString(), 'Asia/Jakarta')->startOfDay();
            $checkoutExclusive = $tenancy->actual_checkout_date?->addDay();
            $cycleStart = $checkIn;
            $created = 0;

            while ($cycleStart->lessThanOrEqualTo($throughDate->startOfDay())) {
                if ($checkoutExclusive !== null && $cycleStart->greaterThanOrEqualTo($checkoutExclusive)) {
                    break;
                }

                $cycle = $this->cycles->forStart($cycleStart->toDateString(), $anchorDay);
                $key = "tenancy:{$tenancy->id}:rent:{$cycle['start_date']}";
                if (! Invoice::query()->where('tenant_id', $tenancy->tenant_id)->where('idempotency_key', $key)->exists()) {
                    $created += $this->createInvoice($tenancy, $cycle, $key);
                }

                $cycleStart = CarbonImmutable::parse($cycle['end_exclusive'], 'Asia/Jakarta')->startOfDay();
            }

            return $created;
        });
    }

    /** @param array{start_date:string,end_exclusive:string} $cycle */
    private function createInvoice(Tenancy $tenancy, array $cycle, string $key): int
    {
        $alreadyExists = Invoice::query()->where('tenant_id', $tenancy->tenant_id)->where('idempotency_key', $key)->exists();
        if ($alreadyExists) {
            return 0;
        }

        $extension = TenancyExtension::query()
            ->where('tenant_id', $tenancy->tenant_id)
            ->where('tenancy_id', $tenancy->id)
            ->whereDate('effective_from', '<=', $cycle['start_date'])
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
        $rent = (int) ($extension === null ? $tenancy->agreed_monthly_rent_rupiah : $extension->new_monthly_rent_rupiah);
        $roomCode = $tenancy->room()->value('code') ?? 'Kamar';
        $invoice = Invoice::query()->create([
            'tenant_id' => $tenancy->tenant_id,
            'tenancy_id' => $tenancy->id,
            'invoice_number' => sprintf('KK-%d-%s', $tenancy->id, str_replace('-', '', $cycle['start_date'])),
            'idempotency_key' => $key,
            'issued_at' => now('UTC'),
            'due_date' => $cycle['start_date'],
            'cycle_start_date' => $cycle['start_date'],
            'cycle_end_exclusive' => $cycle['end_exclusive'],
            'billing_anchor_day' => $tenancy->billing_anchor_day,
            'created_by' => $tenancy->created_by,
        ]);

        InvoiceLine::query()->create([
            'tenant_id' => $tenancy->tenant_id,
            'invoice_id' => $invoice->id,
            'line_number' => 1,
            'kind' => 'rent',
            'description' => "Sewa kamar {$roomCode} {$cycle['start_date']}–{$cycle['end_exclusive']}",
            'amount_rupiah' => $rent,
            'monthly_rent_snapshot_rupiah' => $rent,
            'service_start_date' => $cycle['start_date'],
            'service_end_exclusive' => $cycle['end_exclusive'],
        ]);

        $this->audit->record(
            actor: $tenancy->creator()->firstOrFail(),
            tenant: $tenancy->tenant()->firstOrFail(),
            action: 'invoice.monthly_generated',
            subjectType: 'invoice',
            subjectId: $invoice->id,
            newValues: ['tenancy_id' => $tenancy->id, 'cycle_start_date' => $cycle['start_date'], 'amount_rupiah' => $rent],
        );

        return 1;
    }
}
