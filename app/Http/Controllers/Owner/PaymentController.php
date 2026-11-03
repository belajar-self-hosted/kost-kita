<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\CashTransaction;
use App\Models\DepositAccount;
use App\Models\Invoice;
use App\Models\InvoiceCashAllocation;
use App\Support\DepositLedgerService;
use App\Support\InvoiceBalanceService;
use App\Support\InvoicePaymentService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): Response
    {
        $this->authorize('viewAny', CashTransaction::class);
        $tenant = $tenantContext->current();
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'method' => ['nullable', Rule::in(['cash', 'bank_transfer', 'e_wallet', 'other'])],
        ]);
        $payments = CashTransaction::query()->where('tenant_id', $tenant->id)->where('direction', 'in')
            ->when(isset($filters['from']), fn ($query) => $query->whereDate('occurred_on', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($query) => $query->whereDate('occurred_on', '<=', $filters['to']))
            ->when(isset($filters['method']), fn ($query) => $query->where('method', $filters['method']))
            ->with(['recorder:id,name', 'invoiceAllocations.invoice.tenancy.resident:id,full_name', 'depositMovements.account.tenancy.resident:id,full_name', 'depositMovements.account.booking.resident:id,full_name'])
            ->orderByDesc('occurred_on')->orderByDesc('id')->paginate(20)->withQueryString();

        return Inertia::render('Owner/Payments/Index', [
            'payments' => $payments->through(fn (CashTransaction $payment) => [
                'id' => $payment->id,
                'amount_rupiah' => (int) $payment->amount_rupiah,
                'occurred_on' => $payment->occurred_on->toDateString(),
                'method' => $payment->method,
                'category' => $payment->category,
                'description' => $payment->description,
                'external_reference' => $payment->external_reference,
                'allocations' => $payment->invoiceAllocations->where('kind', 'allocation')->map(fn ($allocation) => ['target' => 'Sewa', 'amount_rupiah' => (int) $allocation->amount_rupiah, 'resident' => $allocation->invoice->tenancy?->resident->full_name])->concat($payment->depositMovements->where('kind', 'receipt')->map(fn ($movement) => ['target' => 'Deposit', 'amount_rupiah' => (int) $movement->amount_rupiah, 'resident' => $movement->account->tenancy?->resident->full_name ?? $movement->account->booking?->resident->full_name]))->values(),
            ]),
            'filters' => ['from' => $filters['from'] ?? '', 'to' => $filters['to'] ?? '', 'method' => $filters['method'] ?? ''],
        ]);
    }

    public function create(Request $request, TenantContext $tenantContext, InvoiceBalanceService $invoiceBalances, DepositLedgerService $depositLedger): Response
    {
        $this->authorize('viewAny', CashTransaction::class);
        $tenant = $tenantContext->current();
        $selected = $request->validate(['invoice_id' => ['nullable', 'integer'], 'deposit_id' => ['nullable', 'integer']]);
        $selectedInvoice = isset($selected['invoice_id']) ? (int) $selected['invoice_id'] : null;
        $selectedDeposit = isset($selected['deposit_id']) ? (int) $selected['deposit_id'] : null;
        $invoices = Invoice::query()->where('tenant_id', $tenant->id)->with(['tenancy.resident:id,full_name', 'tenancy.room:id,code', 'booking.resident:id,full_name', 'booking.room:id,code', 'lines', 'adjustments'])
            ->orderBy('due_date')->get()->map(function (Invoice $invoice) use ($invoiceBalances): array {
                $balance = $invoiceBalances->outstanding($invoice);

                return ['id' => $invoice->id, 'invoice_number' => $invoice->invoice_number, 'resident' => $invoice->tenancy?->resident->full_name ?? $invoice->booking?->resident->full_name, 'room' => $invoice->tenancy?->room->code ?? $invoice->booking?->room->code, 'balance_rupiah' => $balance, 'due_date' => $invoice->due_date->toDateString()];
            })->filter(fn (array $invoice) => $invoice['balance_rupiah'] > 0)->values();
        $deposits = DepositAccount::query()->where('tenant_id', $tenant->id)->with(['tenancy.resident:id,full_name', 'booking.resident:id,full_name', 'movements'])
            ->orderBy('id')->get()->map(fn (DepositAccount $deposit) => ['id' => $deposit->id, 'resident' => $deposit->tenancy?->resident->full_name ?? $deposit->booking?->resident->full_name, 'required_amount_rupiah' => (int) $deposit->required_amount_rupiah, 'balance_rupiah' => $depositLedger->balance($deposit)])->filter(fn (array $deposit) => $deposit['balance_rupiah'] < $deposit['required_amount_rupiah'])->values();

        return Inertia::render('Owner/Payments/Create', [
            'invoices' => $invoices,
            'deposits' => $deposits,
            'selected_invoice_id' => $selectedInvoice,
            'selected_deposit_id' => $selectedDeposit,
            'initial' => ['occurred_on' => now('Asia/Jakarta')->toDateString(), 'method' => 'bank_transfer'],
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, TenantContext $tenantContext, InvoicePaymentService $payments): RedirectResponse
    {
        $data = $request->validate([
            'amount_rupiah' => ['required', 'integer', 'min:1'],
            'occurred_on' => ['required', 'date_format:Y-m-d'],
            'method' => ['required', Rule::in(['cash', 'bank_transfer', 'e_wallet', 'other'])],
            'external_reference' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.invoice_id' => ['nullable', 'integer'],
            'allocations.*.deposit_account_id' => ['nullable', 'integer'],
            'allocations.*.amount_rupiah' => ['required', 'integer', 'min:1'],
        ]);
        $cash = $payments->recordReceipt($tenantContext->current(), $request->user(), $data);

        return to_route('owner.payments.show', $cash);
    }

    public function reconcileExternalOverpayment(Request $request, TenantContext $tenantContext, InvoicePaymentService $payments): RedirectResponse
    {
        $data = $request->validate([
            'deposit_account_id' => ['required', 'integer'], 'amount_rupiah' => ['required', 'integer', 'min:1'],
            'deposit_allocation_rupiah' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'occurred_on' => ['required', 'date_format:Y-m-d'], 'method' => ['required', Rule::in(['cash', 'bank_transfer', 'e_wallet', 'other'])],
            'external_reference' => ['required', 'string', 'max:160'], 'idempotency_key' => ['required', 'uuid'],
            'evidence' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);
        $cash = $payments->reconcileExternalDepositOverpayment($tenantContext->current(), $request->user(), $data);

        return to_route('owner.payments.show', $cash);
    }

    public function refund(string $payment, Request $request, TenantContext $tenantContext, InvoicePaymentService $payments): RedirectResponse
    {
        $data = $request->validate([
            'amount_rupiah' => ['required', 'integer', 'min:1'], 'allocation_id' => ['required', 'integer'],
            'credit_adjustment_id' => ['required', 'integer'], 'occurred_on' => ['required', 'date_format:Y-m-d'],
            'method' => ['required', Rule::in(['cash', 'bank_transfer', 'e_wallet', 'other'])],
            'external_reference' => ['required', 'string', 'max:160'], 'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'evidence' => ['required', 'array', 'min:1'],
            'evidence.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $record = CashTransaction::query()->where('tenant_id', $tenantContext->current()->id)->whereKey((int) $payment)->firstOrFail();
        $this->authorize('view', $record);
        $refund = $payments->recordRentRefund($tenantContext->current(), $request->user(), $record->id, [
            ...$data,
            'evidence' => array_values($request->file('evidence', [])),
            'allocations' => [[
                'allocation_id' => (int) $data['allocation_id'],
                'amount_rupiah' => (int) $data['amount_rupiah'],
                'credit_adjustment_id' => (int) $data['credit_adjustment_id'],
            ]],
        ]);

        return to_route('owner.payments.show', $refund);
    }

    public function show(string $payment, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->current();
        $record = CashTransaction::query()->where('tenant_id', $tenant->id)->with(['recorder:id,name', 'evidence:id,cash_transaction_id,original_filename,mime_type', 'invoiceAllocations.invoice.tenancy.resident:id,full_name', 'invoiceAllocations.invoice.adjustments', 'invoiceAllocations.reversesAllocation', 'depositMovements.account.tenancy.resident:id,full_name', 'depositMovements.account.booking.resident:id,full_name', 'reversalOf'])->whereKey((int) $payment)->firstOrFail();
        $this->authorize('view', $record);

        return Inertia::render('Owner/Payments/Show', ['payment' => [
            'id' => $record->id, 'direction' => $record->direction, 'category' => $record->category, 'amount_rupiah' => (int) $record->amount_rupiah,
            'occurred_on' => $record->occurred_on->toDateString(), 'method' => $record->method, 'external_reference' => $record->external_reference,
            'description' => $record->description, 'recorded_by' => $record->recorder->name, 'reversal_of_id' => $record->reversal_of_id,
            'invoice_allocations' => $record->invoiceAllocations->map(function ($allocation): array {
                $reversed = (int) InvoiceCashAllocation::query()->where('tenant_id', $allocation->tenant_id)->where('reverses_allocation_id', $allocation->id)->sum('amount_rupiah');
                $creditTotal = (int) $allocation->invoice->adjustments->where('kind', 'credit')->sum('amount_rupiah');
                $invoiceRefunded = (int) InvoiceCashAllocation::query()->where('tenant_id', $allocation->tenant_id)->where('invoice_id', $allocation->invoice_id)->where('kind', 'reversal')
                    ->whereHas('cashTransaction', fn ($query) => $query->where('category', 'rent_refund'))
                    ->sum('amount_rupiah');

                return [
                    'id' => $allocation->id,
                    'invoice_id' => $allocation->invoice_id,
                    'invoice_number' => $allocation->invoice->invoice_number,
                    'kind' => $allocation->kind,
                    'amount_rupiah' => (int) $allocation->amount_rupiah,
                    'refundable_rupiah' => max(0, (int) $allocation->amount_rupiah - $reversed),
                    'available_credit_rupiah' => max(0, $creditTotal - $invoiceRefunded),
                    'credit_adjustments' => $allocation->invoice->adjustments->where('kind', 'credit')->map(fn ($adjustment) => ['id' => $adjustment->id, 'amount_rupiah' => (int) $adjustment->amount_rupiah, 'reason' => $adjustment->reason])->values(),
                ];
            })->values(),
            'deposit_movements' => $record->depositMovements->map(fn ($movement) => ['id' => $movement->id, 'deposit_account_id' => $movement->deposit_account_id, 'resident' => $movement->account->tenancy?->resident->full_name ?? $movement->account->booking?->resident->full_name, 'kind' => $movement->kind, 'amount_rupiah' => (int) $movement->amount_rupiah])->values(),
            'evidence' => $record->evidence->map(fn ($evidence) => ['id' => $evidence->id, 'filename' => $evidence->original_filename, 'mime_type' => $evidence->mime_type])->values(),
        ], 'idempotency_key' => (string) Str::uuid()]);
    }
}
