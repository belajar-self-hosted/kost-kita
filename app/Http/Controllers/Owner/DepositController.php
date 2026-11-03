<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DepositAccount;
use App\Models\DepositEvidence;
use App\Models\DepositMovement;
use App\Models\DepositSettlement;
use App\Models\Invoice;
use App\Models\TemporaryLiability;
use App\Support\DepositEvidenceService;
use App\Support\DepositLedgerService;
use App\Support\DepositSettlementService;
use App\Support\InvoiceBalanceService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DepositController extends Controller
{
    public function index(TenantContext $tenantContext, DepositLedgerService $ledger): Response
    {
        $this->authorize('viewAny', DepositAccount::class);
        $tenant = $tenantContext->current();
        $deposits = DepositAccount::query()->where('tenant_id', $tenant->id)
            ->with(['booking.resident:id,full_name,whatsapp_number', 'booking.room:id,code', 'booking.property:id,name', 'tenancy.resident:id,full_name,whatsapp_number', 'tenancy.room:id,code', 'tenancy.property:id,name', 'movements', 'settlements'])
            ->orderByDesc('id')->paginate(20)->withQueryString();

        return Inertia::render('Owner/Deposits/Index', [
            'deposits' => $deposits->through(fn (DepositAccount $account) => $this->accountSummary($account, $ledger)),
        ]);
    }

    public function show(string $deposit, TenantContext $tenantContext, DepositLedgerService $ledger, InvoiceBalanceService $invoiceBalances): Response
    {
        $tenant = $tenantContext->current();
        $account = DepositAccount::query()->where('tenant_id', $tenant->id)
            ->with(['booking.resident:id,full_name,whatsapp_number', 'booking.room:id,code', 'booking.property:id,name', 'tenancy.resident:id,full_name,whatsapp_number', 'tenancy.room:id,code', 'tenancy.property:id,name', 'movements.cashTransaction', 'movements.invoice', 'settlements.lines.evidence', 'settlements.lines.claimResolution'])
            ->whereKey((int) $deposit)->firstOrFail();
        $this->authorize('view', $account);
        $tenancyId = $account->tenancy_id;
        $bookingId = $account->booking_id;
        $liabilities = TemporaryLiability::query()->where('tenant_id', $tenant->id)
            ->where(function ($query) use ($tenancyId, $bookingId): void {
                if ($tenancyId !== null) {
                    $query->where('tenancy_id', $tenancyId);
                }
                if ($bookingId !== null) {
                    $query->orWhere('booking_id', $bookingId);
                }
            })->with('movements')->orderByDesc('id')->get();
        $invoices = $tenancyId === null ? collect() : Invoice::query()->where('tenant_id', $tenant->id)->where('tenancy_id', $tenancyId)
            ->with(['lines', 'adjustments'])->orderByDesc('due_date')->get()->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'due_date' => $invoice->due_date->toDateString(),
                'balance_rupiah' => $invoiceBalances->outstanding($invoice),
                'status' => $invoiceBalances->status($invoice),
            ])->values();
        $activeSettlement = $account->settlements->where('status', '!=', 'cancelled')->sortByDesc('id')->first();
        $settlement = $activeSettlement ?? $account->settlements->sortByDesc('id')->first();

        return Inertia::render('Owner/Deposits/Show', [
            'deposit' => [
                ...$this->accountSummary($account, $ledger),
                'booking_status' => $account->booking?->status,
                'tenancy_status' => $account->tenancy?->status,
                'settlement_id' => $activeSettlement?->id,
                'settlement_status' => $settlement?->status,
                'can_reconcile_external_overpayment' => $account->settlements->isEmpty(),
                'invoices' => $invoices,
                'movements' => $account->movements->map(fn ($movement) => [
                    'id' => $movement->id, 'kind' => $movement->kind, 'amount_rupiah' => (int) $movement->amount_rupiah,
                    'reason' => $movement->reason, 'created_at' => $this->timestamp($movement->getRawOriginal('created_at')),
                    'cash_transaction_id' => $movement->cash_transaction_id, 'invoice_number' => $movement->invoice?->invoice_number,
                ])->values(),
                'temporary_liabilities' => $liabilities->map(fn (TemporaryLiability $liability) => [
                    'id' => $liability->id, 'initial_amount_rupiah' => (int) $liability->initial_amount_rupiah,
                    'balance_rupiah' => $ledger->temporaryLiabilityBalance($liability),
                    'idempotency_key' => (string) Str::uuid(),
                ])->values(),
            ],
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    public function updateRequirement(string $deposit, Request $request, TenantContext $tenantContext, DepositLedgerService $ledger): RedirectResponse
    {
        $data = $request->validate(['required_amount_rupiah' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $account = DepositAccount::query()->where('tenant_id', $tenantContext->current()->id)->whereKey((int) $deposit)->firstOrFail();
        $this->authorize('view', $account);
        $ledger->updateRequirement($tenantContext->current(), $request->user(), $account->id, (int) $data['required_amount_rupiah'], trim($data['reason']));

        return to_route('owner.deposits.show', $account);
    }

    public function createSettlement(string $deposit, TenantContext $tenantContext, DepositLedgerService $ledger, InvoiceBalanceService $invoiceBalances): Response
    {
        $tenant = $tenantContext->current();
        $account = DepositAccount::query()->where('tenant_id', $tenant->id)->with(['booking.resident:id,full_name', 'tenancy.resident:id,full_name'])->whereKey((int) $deposit)->firstOrFail();
        $this->authorize('view', $account);
        $invoices = $account->tenancy_id === null ? collect() : Invoice::query()->where('tenant_id', $tenant->id)->where('tenancy_id', $account->tenancy_id)->with(['lines', 'adjustments'])->orderBy('due_date')->get()
            ->filter(fn (Invoice $invoice) => $invoice->due_date->toDateString() <= now('Asia/Jakarta')->toDateString() && $invoiceBalances->outstanding($invoice) > 0)
            ->map(fn (Invoice $invoice) => ['id' => $invoice->id, 'invoice_number' => $invoice->invoice_number, 'due_date' => $invoice->due_date->toDateString(), 'balance_rupiah' => $invoiceBalances->outstanding($invoice)])->values();

        return Inertia::render('Owner/Deposits/SettlementCreate', [
            'deposit' => ['id' => $account->id, 'resident' => $account->tenancy?->resident->full_name ?? $account->booking?->resident->full_name, 'required_amount_rupiah' => (int) $account->required_amount_rupiah, 'balance_rupiah' => $ledger->balance($account)],
            'invoices' => $invoices,
        ]);
    }

    public function storeSettlement(string $deposit, Request $request, TenantContext $tenantContext, DepositSettlementService $settlements): RedirectResponse
    {
        $data = $request->validate([
            'exit_inspection_notes' => ['required', 'string', 'min:3', 'max:4000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.kind' => ['required', Rule::in(['refund', 'invoice_deduction', 'damage_deduction', 'held_claim'])],
            'lines.*.amount_rupiah' => ['required', 'integer', 'min:1'],
            'lines.*.reason' => ['required', 'string', 'min:3', 'max:1000'],
            'lines.*.agreement_basis' => ['nullable', 'string', 'max:2000'],
            'lines.*.invoice_id' => ['nullable', 'integer'],
            'lines.*.evidence' => ['nullable', 'array', 'max:5'],
            'lines.*.evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);
        $account = DepositAccount::query()->where('tenant_id', $tenantContext->current()->id)->whereKey((int) $deposit)->firstOrFail();
        $this->authorize('view', $account);
        $settlement = $settlements->open($tenantContext->current(), $request->user(), $account->id, $data);

        return to_route('owner.deposit-settlements.show', $settlement);
    }

    public function showSettlement(string $settlement, TenantContext $tenantContext): Response
    {
        $record = DepositSettlement::query()->where('tenant_id', $tenantContext->current()->id)
            ->with(['account.booking.resident:id,full_name', 'account.tenancy.resident:id,full_name', 'lines.invoice', 'lines.evidence', 'lines.claimResolution'])
            ->whereKey((int) $settlement)->firstOrFail();
        $this->authorize('view', $record);
        $cancellation = $record->status === 'cancelled'
            ? AuditLog::query()->where('tenant_id', $record->tenant_id)->where('action', 'deposit.settlement_draft_cancelled')
                ->where('subject_type', 'deposit_settlement')->where('subject_id', $record->id)->orderByDesc('occurred_at')->first()
            : null;
        $cancellationValues = json_decode((string) $cancellation?->getRawOriginal('new_values'), true);
        $cancellationReason = is_array($cancellationValues) ? ($cancellationValues['reason'] ?? null) : null;

        return Inertia::render('Owner/Deposits/SettlementShow', ['settlement' => [
            'id' => $record->id, 'deposit_account_id' => $record->deposit_account_id, 'status' => $record->status,
            'resident' => $record->account->tenancy?->resident->full_name ?? $record->account->booking?->resident->full_name,
            'exit_inspection_notes' => $record->exit_inspection_notes,
            'opened_at' => $this->timestamp($record->getRawOriginal('opened_at')),
            'day7_deadline_at' => $this->timestamp($record->getRawOriginal('day7_deadline_at')),
            'day30_deadline_at' => $this->timestamp($record->getRawOriginal('day30_deadline_at')),
            'whatsapp_notified_at' => $this->timestamp($record->getRawOriginal('whatsapp_notified_at')),
            'approved_at' => $this->timestamp($record->getRawOriginal('approved_at')),
            'cancelled_at' => $this->timestamp($cancellation?->getRawOriginal('occurred_at')),
            'cancellation_reason' => $cancellationReason,
            'lines' => $record->lines->map(fn ($line) => [
                'id' => $line->id, 'kind' => $line->kind, 'amount_rupiah' => (int) $line->amount_rupiah,
                'reason' => $line->reason, 'agreement_basis' => $line->agreement_basis,
                'invoice' => $line->invoice?->only(['id', 'invoice_number']), 'approved_at' => $this->timestamp($line->getRawOriginal('approved_at')),
                'evidence' => $line->evidence->map(fn ($evidence) => ['id' => $evidence->id, 'filename' => $evidence->original_filename, 'mime_type' => $evidence->mime_type])->values(),
                'resolution' => $line->claimResolution->first()?->only(['actual_damage_amount_rupiah', 'refund_amount_rupiah', 'resolution_basis']),
                'refund_remaining_rupiah' => max(0, (int) $line->amount_rupiah - (int) DepositMovement::query()->where('tenant_id', $record->tenant_id)->where('settlement_line_id', $line->id)->where('kind', 'refund')->sum('amount_rupiah')),
                'can_finalize_claim' => $line->kind === 'held_claim' && $line->approved_at !== null && $line->claimResolution->isEmpty(),
            ])->values(),
            'actions' => [
                'can_notify' => $record->status === 'draft' && $record->whatsapp_notified_at === null,
                'can_approve' => $record->status === 'draft' && $record->whatsapp_notified_at !== null,
                'can_cancel' => $record->status === 'draft',
            ],
        ],
            'refund_idempotency_keys' => $record->lines->where('kind', 'refund')->mapWithKeys(fn ($line) => [$line->id => (string) Str::uuid()]),
            'claim_idempotency_keys' => $record->lines->where('kind', 'held_claim')->mapWithKeys(fn ($line) => [$line->id => (string) Str::uuid()]),
        ]);
    }

    public function recordWhatsApp(string $settlement, Request $request, TenantContext $tenantContext, DepositSettlementService $settlements): RedirectResponse
    {
        $record = DepositSettlement::query()->where('tenant_id', $tenantContext->current()->id)->whereKey((int) $settlement)->firstOrFail();
        $this->authorize('view', $record);
        $request->validate(['confirmation' => ['accepted']]);
        $settlements->recordWhatsAppNotification($tenantContext->current(), $request->user(), $record->id);

        return to_route('owner.deposit-settlements.show', $record);
    }

    public function approveSettlement(string $settlement, Request $request, TenantContext $tenantContext, DepositSettlementService $settlements): RedirectResponse
    {
        $record = DepositSettlement::query()->where('tenant_id', $tenantContext->current()->id)->whereKey((int) $settlement)->firstOrFail();
        $this->authorize('view', $record);
        $request->validate(['confirmation' => ['accepted']]);
        $settlements->approve($tenantContext->current(), $request->user(), $record->id);

        return to_route('owner.deposit-settlements.show', $record);
    }

    public function cancelSettlement(string $settlement, Request $request, TenantContext $tenantContext, DepositSettlementService $settlements): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $record = DepositSettlement::query()->where('tenant_id', $tenantContext->current()->id)->whereKey((int) $settlement)->firstOrFail();
        $this->authorize('view', $record);
        $settlements->cancelDraft($tenantContext->current(), $request->user(), $record->id, $data['reason']);

        return to_route('owner.deposits.show', $record->deposit_account_id);
    }

    public function refund(string $deposit, Request $request, TenantContext $tenantContext, DepositSettlementService $settlements): RedirectResponse
    {
        $data = $request->validate([
            'settlement_line_id' => ['required', 'integer'], 'amount_rupiah' => ['required', 'integer', 'min:1'],
            'occurred_on' => ['required', 'date_format:Y-m-d'], 'method' => ['required', Rule::in(['cash', 'bank_transfer', 'e_wallet', 'other'])],
            'external_reference' => ['required', 'string', 'max:160'], 'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'], 'evidence' => ['required', 'array', 'min:1', 'max:5'],
            'evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);
        $account = DepositAccount::query()->where('tenant_id', $tenantContext->current()->id)->whereKey((int) $deposit)->firstOrFail();
        $this->authorize('view', $account);
        $settlements->recordRefund($tenantContext->current(), $request->user(), $account->id, $data);

        return to_route('owner.deposits.show', $account);
    }

    public function finalizeClaim(string $settlement, string $line, Request $request, TenantContext $tenantContext, DepositSettlementService $settlements): RedirectResponse
    {
        $data = $request->validate([
            'actual_damage_amount_rupiah' => ['required', 'integer', 'min:0'], 'resolution_basis' => ['required', 'string', 'min:3', 'max:2000'],
            'occurred_on' => ['nullable', 'date_format:Y-m-d'], 'method' => ['nullable', Rule::in(['cash', 'bank_transfer', 'e_wallet', 'other'])],
            'external_reference' => ['nullable', 'string', 'max:160'], 'idempotency_key' => ['required', 'uuid'],
            'evidence' => ['nullable', 'array', 'max:5'], 'evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);
        $record = DepositSettlement::query()->where('tenant_id', $tenantContext->current()->id)->whereKey((int) $settlement)->firstOrFail();
        $this->authorize('view', $record);
        $settlements->finalizeHeldClaim($tenantContext->current(), $request->user(), $record->id, (int) $line, $data);

        return to_route('owner.deposit-settlements.show', $record);
    }

    public function downloadEvidence(string $evidence, Request $request, TenantContext $tenantContext, DepositEvidenceService $files): StreamedResponse
    {
        $record = DepositEvidence::query()->where('tenant_id', $tenantContext->current()->id)->whereKey((int) $evidence)->firstOrFail();

        return $files->download($tenantContext->current(), $request->user(), $record->id);
    }

    public function refundTemporaryLiability(string $liability, Request $request, TenantContext $tenantContext, DepositLedgerService $ledger): RedirectResponse
    {
        $data = $request->validate([
            'amount_rupiah' => ['required', 'integer', 'min:1'], 'occurred_on' => ['required', 'date_format:Y-m-d'],
            'method' => ['required', Rule::in(['cash', 'bank_transfer', 'e_wallet', 'other'])],
            'external_reference' => ['nullable', 'string', 'max:160'], 'description' => ['required', 'string', 'min:3', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $record = TemporaryLiability::query()->where('tenant_id', $tenantContext->current()->id)->whereKey((int) $liability)->firstOrFail();
        $cash = $ledger->refundTemporaryLiability($tenantContext->current(), $request->user(), $record->id, $data);

        return to_route('owner.payments.show', $cash);
    }

    /** @return array<string,mixed> */
    private function accountSummary(DepositAccount $account, DepositLedgerService $ledger): array
    {
        return [
            'id' => $account->id,
            'required_amount_rupiah' => (int) $account->required_amount_rupiah,
            'received_rupiah' => $ledger->received($account),
            'balance_rupiah' => $ledger->balance($account),
            'resident' => $account->tenancy?->resident?->only(['id', 'full_name', 'whatsapp_number']) ?? $account->booking?->resident?->only(['id', 'full_name', 'whatsapp_number']),
            'property' => $account->tenancy?->property?->only(['id', 'name']) ?? $account->booking?->property?->only(['id', 'name']),
            'room' => $account->tenancy?->room?->only(['id', 'code']) ?? $account->booking?->room?->only(['id', 'code']),
            'booking_id' => $account->booking_id,
            'tenancy_id' => $account->tenancy_id,
            'settlement_status' => $account->settlements->sortByDesc('id')->first()?->status,
        ];
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value, 'UTC')->setTimezone('Asia/Jakarta')->toIso8601String();
    }
}
