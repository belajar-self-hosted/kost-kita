<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\InvoiceCashAllocation;
use App\Support\AuditLogger;
use App\Support\InvoiceBalanceService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext, InvoiceBalanceService $balances): Response
    {
        $this->authorize('viewAny', Invoice::class);
        $tenant = $tenantContext->current();
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['unpaid', 'partial', 'paid', 'overdue'])],
            'property_id' => ['nullable', 'integer'],
            'tenancy_id' => ['nullable', 'integer'],
        ]);

        $invoices = Invoice::query()->where('tenant_id', $tenant->id)
            ->when(isset($filters['property_id']), fn ($query) => $query->where(function ($nested) use ($filters): void {
                $nested->whereHas('tenancy', fn ($tenancy) => $tenancy->where('property_id', $filters['property_id']))
                    ->orWhereHas('booking', fn ($booking) => $booking->where('property_id', $filters['property_id']));
            }))
            ->when(isset($filters['tenancy_id']), fn ($query) => $query->where('tenancy_id', $filters['tenancy_id']))
            ->with(['tenancy.property:id,name', 'tenancy.room:id,code', 'tenancy.resident:id,full_name', 'booking.property:id,name', 'booking.room:id,code', 'booking.resident:id,full_name', 'lines', 'adjustments'])
            ->orderByDesc('due_date')->orderByDesc('id')->get();

        $rows = $invoices->map(function (Invoice $invoice) use ($balances): array {
            $status = $balances->status($invoice);

            return [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'due_date' => $invoice->due_date->toDateString(),
                'cycle_start_date' => $invoice->cycle_start_date?->toDateString(),
                'cycle_end_exclusive' => $invoice->cycle_end_exclusive?->toDateString(),
                'property' => $invoice->tenancy?->property?->only(['id', 'name']) ?? $invoice->booking?->property?->only(['id', 'name']),
                'room' => $invoice->tenancy?->room?->only(['id', 'code']) ?? $invoice->booking?->room?->only(['id', 'code']),
                'resident' => $invoice->tenancy?->resident?->only(['id', 'full_name']) ?? $invoice->booking?->resident?->only(['id', 'full_name']),
                'tenancy_id' => $invoice->tenancy_id,
                'total_due_rupiah' => $balances->totalDue($invoice),
                'paid_rupiah' => $balances->totalDue($invoice) - $balances->outstanding($invoice),
                'balance_rupiah' => $balances->outstanding($invoice),
                'status' => $status,
            ];
        })->filter(function (array $invoice) use ($filters): bool {
            return ! isset($filters['status']) || $invoice['status'] === $filters['status'];
        })->values();
        $page = LengthAwarePaginator::resolveCurrentPage();
        $invoices = new LengthAwarePaginator(
            $rows->forPage($page, 20)->values(),
            $rows->count(),
            20,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'query' => $request->query()],
        );

        return Inertia::render('Owner/Invoices/Index', [
            'invoices' => $invoices,
            'properties' => $tenant->properties()->orderBy('name')->get(['id', 'name']),
            'filters' => ['status' => $filters['status'] ?? '', 'property_id' => $filters['property_id'] ?? '', 'tenancy_id' => $filters['tenancy_id'] ?? ''],
        ]);
    }

    public function show(string $invoice, TenantContext $tenantContext, InvoiceBalanceService $balances): Response
    {
        $tenant = $tenantContext->current();
        $record = Invoice::query()->where('tenant_id', $tenant->id)
            ->with(['tenancy.property:id,name', 'tenancy.room:id,code', 'tenancy.resident:id,full_name,whatsapp_number', 'booking.property:id,name', 'booking.room:id,code', 'booking.resident:id,full_name,whatsapp_number', 'lines', 'adjustments.creator:id,name', 'cashAllocations.cashTransaction'])
            ->whereKey((int) $invoice)->firstOrFail();
        $this->authorize('view', $record);

        return Inertia::render('Owner/Invoices/Show', [
            'invoice' => [
                'id' => $record->id,
                'invoice_number' => $record->invoice_number,
                'issued_at' => $record->issued_at->setTimezone('Asia/Jakarta')->toIso8601String(),
                'due_date' => $record->due_date->toDateString(),
                'cycle_start_date' => $record->cycle_start_date?->toDateString(),
                'cycle_end_exclusive' => $record->cycle_end_exclusive?->toDateString(),
                'property' => $record->tenancy?->property?->only(['id', 'name']) ?? $record->booking?->property?->only(['id', 'name']),
                'room' => $record->tenancy?->room?->only(['id', 'code']) ?? $record->booking?->room?->only(['id', 'code']),
                'resident' => $record->tenancy?->resident?->only(['id', 'full_name', 'whatsapp_number']) ?? $record->booking?->resident?->only(['id', 'full_name', 'whatsapp_number']),
                'total_due_rupiah' => $balances->totalDue($record),
                'paid_rupiah' => $balances->totalDue($record) - $balances->outstanding($record),
                'balance_rupiah' => $balances->outstanding($record),
                'status' => $balances->status($record),
                'lines' => $record->lines->map(fn ($line) => ['id' => $line->id, 'kind' => $line->kind, 'description' => $line->description, 'amount_rupiah' => (int) $line->amount_rupiah])->values(),
                'adjustments' => $record->adjustments->map(fn ($adjustment) => ['id' => $adjustment->id, 'kind' => $adjustment->kind, 'amount_rupiah' => (int) $adjustment->amount_rupiah, 'reason' => $adjustment->reason, 'created_at' => $adjustment->created_at->setTimezone('Asia/Jakarta')->toIso8601String()])->values(),
                'cash_allocations' => $record->cashAllocations->where('kind', 'allocation')->map(fn (InvoiceCashAllocation $allocation): array => ['id' => $allocation->id, 'kind' => $allocation->kind, 'amount_rupiah' => (int) $allocation->amount_rupiah, 'occurred_on' => $allocation->cashTransaction->occurred_on->toDateString(), 'method' => $allocation->cashTransaction->method, 'cash_transaction_id' => $allocation->cash_transaction_id])->values(),
                'deposit_settlements_rupiah' => $balances->paidFromDeposit($record),
            ],
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    public function adjust(string $invoice, Request $request, TenantContext $tenantContext, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['debit', 'credit'])],
            'amount_rupiah' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
            'reference_adjustment_id' => ['nullable', 'integer'],
        ]);
        $tenant = $tenantContext->current();
        $record = Invoice::query()->where('tenant_id', $tenant->id)->whereKey((int) $invoice)->firstOrFail();
        $this->authorize('view', $record);

        DB::transaction(function () use ($record, $tenant, $request, $data, $audit): void {
            $locked = Invoice::query()->where('tenant_id', $tenant->id)->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $existing = InvoiceAdjustment::query()->where('tenant_id', $tenant->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                return;
            }
            $referenceId = $data['reference_adjustment_id'] ?? null;
            if ($referenceId !== null) {
                InvoiceAdjustment::query()->where('tenant_id', $tenant->id)->where('invoice_id', $locked->id)->whereKey($referenceId)->firstOrFail();
            }
            $adjustment = InvoiceAdjustment::query()->create([
                'tenant_id' => $tenant->id,
                'invoice_id' => $locked->id,
                'kind' => $data['kind'],
                'amount_rupiah' => $data['amount_rupiah'],
                'reason' => trim($data['reason']),
                'reference_adjustment_id' => $referenceId,
                'idempotency_key' => $data['idempotency_key'],
                'created_by' => $request->user()->id,
                'created_at' => now('UTC'),
            ]);
            $audit->record($request->user(), $tenant, 'invoice.adjusted', 'invoice_adjustment', $adjustment->id, null, ['invoice_id' => $locked->id, 'kind' => $adjustment->kind, 'amount_rupiah' => $adjustment->amount_rupiah, 'reason' => $adjustment->reason]);
        });

        return to_route('owner.invoices.show', $record);
    }
}
