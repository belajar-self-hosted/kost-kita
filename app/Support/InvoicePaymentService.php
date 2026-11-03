<?php

namespace App\Support;

use App\Models\CashTransaction;
use App\Models\DepositAccount;
use App\Models\DepositMovement;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\InvoiceCashAllocation;
use App\Models\TemporaryLiability;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoicePaymentService
{
    public function __construct(
        private readonly InvoiceBalanceService $balances,
        private readonly DepositLedgerService $depositLedger,
        private readonly DepositEvidenceService $evidence,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string,mixed> $input */
    public function recordReceipt(Tenant $tenant, User $actor, array $input): CashTransaction
    {
        return DB::transaction(function () use ($tenant, $actor, $input): CashTransaction {
            $idempotencyKey = (string) $input['idempotency_key'];
            $allocations = $this->normalizeAllocations($input['allocations'] ?? []);
            $existing = CashTransaction::query()->where('tenant_id', $tenant->id)->where('idempotency_key', $idempotencyKey)
                ->with(['invoiceAllocations', 'depositMovements'])
                ->first();
            if ($existing !== null) {
                $savedAllocations = [
                    ...$existing->invoiceAllocations->where('kind', 'allocation')->map(fn ($row): array => [
                        'invoice_id' => (int) $row->invoice_id, 'amount_rupiah' => (int) $row->amount_rupiah,
                    ])->all(),
                    ...$existing->depositMovements->where('kind', 'receipt')->map(fn ($row): array => [
                        'deposit_account_id' => (int) $row->deposit_account_id, 'amount_rupiah' => (int) $row->amount_rupiah,
                    ])->all(),
                ];
                $expectedOccurredOn = CarbonImmutable::parse((string) $input['occurred_on'])->toDateString();
                $expectedExternalReference = isset($input['external_reference']) ? (string) $input['external_reference'] : null;
                $expectedDescription = isset($input['description']) ? (string) $input['description'] : null;
                $hasInvoiceAllocation = count(array_filter($allocations, fn (array $allocation): bool => isset($allocation['invoice_id']))) > 0;
                $hasDepositAllocation = count(array_filter($allocations, fn (array $allocation): bool => isset($allocation['deposit_account_id']))) > 0;
                $expectedCategory = $hasInvoiceAllocation && $hasDepositAllocation
                    ? 'mixed_receipt'
                    : ($hasInvoiceAllocation ? 'rent_receipt' : 'deposit_receipt');
                if ((int) $existing->amount_rupiah !== (int) $input['amount_rupiah']
                    || $existing->direction !== 'in'
                    || $existing->category !== $expectedCategory
                    || $existing->occurred_on->toDateString() !== $expectedOccurredOn
                    || $existing->method !== (string) $input['method']
                    || $existing->external_reference !== $expectedExternalReference
                    || $existing->description !== $expectedDescription
                    || $this->allocationFingerprint($savedAllocations) !== $this->allocationFingerprint($allocations)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Kunci idempotensi telah digunakan untuk transaksi lain.']);
                }

                $files = $this->normalizeEvidence($input['evidence'] ?? []);
                if ($files !== []) {
                    $this->evidence->attachToCashTransaction($tenant, $actor, $existing->id, $files);
                }

                return $existing->load(['invoiceAllocations', 'depositMovements', 'evidence']);
            }

            $invoiceAllocations = array_values(array_filter($allocations, fn (array $allocation): bool => isset($allocation['invoice_id'])));
            $depositAllocations = array_values(array_filter($allocations, fn (array $allocation): bool => isset($allocation['deposit_account_id'])));
            if ($invoiceAllocations === [] && $depositAllocations === []) {
                throw ValidationException::withMessages(['allocations' => 'Alokasikan penerimaan ke tagihan sewa atau deposit.']);
            }

            $allocatedTotal = array_sum(array_column($allocations, 'amount_rupiah'));
            $amount = (int) $input['amount_rupiah'];
            if ($amount < 1 || $allocatedTotal !== $amount) {
                throw ValidationException::withMessages(['allocations' => 'Jumlah alokasi harus sama dengan nominal transfer aktual.']);
            }

            $invoices = Invoice::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('id', array_column($invoiceAllocations, 'invoice_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $deposits = DepositAccount::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('id', array_column($depositAllocations, 'deposit_account_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($invoices->count() !== count($invoiceAllocations) || $deposits->count() !== count($depositAllocations)) {
                throw ValidationException::withMessages(['allocations' => 'Salah satu tujuan alokasi tidak ditemukan.']);
            }

            foreach ($invoiceAllocations as $allocation) {
                $invoice = $invoices->get($allocation['invoice_id']);
                if ($this->balances->outstanding($invoice) < $allocation['amount_rupiah']) {
                    throw ValidationException::withMessages(['allocations' => 'Alokasi melebihi saldo tagihan terbaru.']);
                }
            }
            foreach ($depositAllocations as $allocation) {
                $deposit = $deposits->get($allocation['deposit_account_id']);
                if ($deposit->settlements()->exists() || $this->depositLedger->received($deposit) + $allocation['amount_rupiah'] > (int) $deposit->required_amount_rupiah) {
                    throw ValidationException::withMessages(['allocations' => 'Alokasi melebihi kewajiban deposit atau deposit sudah dalam settlement.']);
                }
            }

            $category = $invoiceAllocations !== [] && $depositAllocations !== []
                ? 'mixed_receipt'
                : ($invoiceAllocations !== [] ? 'rent_receipt' : 'deposit_receipt');
            $cash = $this->createCash($tenant, $actor, $input, 'in', $category);
            foreach ($invoiceAllocations as $index => $allocation) {
                InvoiceCashAllocation::query()->create([
                    'tenant_id' => $tenant->id,
                    'cash_transaction_id' => $cash->id,
                    'invoice_id' => $allocation['invoice_id'],
                    'kind' => 'allocation',
                    'amount_rupiah' => $allocation['amount_rupiah'],
                    'idempotency_key' => $idempotencyKey.':invoice:'.$allocation['invoice_id'].':'.$index,
                    'created_at' => now('UTC'),
                ]);
            }
            foreach ($depositAllocations as $index => $allocation) {
                $deposit = $deposits->get($allocation['deposit_account_id']);
                DepositMovement::query()->create([
                    'tenant_id' => $tenant->id,
                    'deposit_account_id' => $deposit->id,
                    'kind' => 'receipt',
                    'amount_rupiah' => $allocation['amount_rupiah'],
                    'cash_transaction_id' => $cash->id,
                    'reason' => 'Penerimaan deposit jaminan aktual.',
                    'actor_user_id' => $actor->id,
                    'idempotency_key' => $idempotencyKey.':deposit:'.$deposit->id.':'.$index,
                    'created_at' => now('UTC'),
                ]);
            }
            $this->audit->record($actor, $tenant, 'cash.receipt_recorded', 'cash_transaction', $cash->id, null, [
                'amount_rupiah' => $amount, 'category' => $category,
                'invoice_ids' => array_column($invoiceAllocations, 'invoice_id'),
                'deposit_account_ids' => array_column($depositAllocations, 'deposit_account_id'),
            ]);

            $files = $this->normalizeEvidence($input['evidence'] ?? []);
            if ($files !== []) {
                $this->evidence->attachToCashTransaction($tenant, $actor, $cash->id, $files);
            }

            return $cash->load(['invoiceAllocations', 'depositMovements', 'evidence']);
        });
    }

    /** @param array<string,mixed> $input */
    public function reconcileExternalDepositOverpayment(Tenant $tenant, User $actor, array $input): CashTransaction
    {
        return DB::transaction(function () use ($tenant, $actor, $input): CashTransaction {
            $key = (string) $input['idempotency_key'];
            $files = $this->normalizeEvidence($input['evidence'] ?? []);
            if ($files === [] || trim((string) ($input['reason'] ?? '')) === ''
                || trim((string) ($input['external_reference'] ?? '')) === ''
                || empty($input['occurred_on']) || empty($input['method'])) {
                throw ValidationException::withMessages(['evidence' => 'Rekonsiliasi memerlukan bukti transfer, alasan, tanggal, metode, dan referensi aktual.']);
            }
            $existing = CashTransaction::query()->where('tenant_id', $tenant->id)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                $movement = $existing->depositMovements()->where('kind', 'receipt')->first();
                $liabilityAmount = TemporaryLiability::query()->where('tenant_id', $tenant->id)->where('source_cash_transaction_id', $existing->id)->value('initial_amount_rupiah');
                if ((int) $existing->amount_rupiah !== (int) $input['amount_rupiah']
                    || $existing->category !== 'deposit_receipt'
                    || CarbonImmutable::parse($existing->occurred_on)->toDateString() !== CarbonImmutable::parse((string) $input['occurred_on'])->toDateString()
                    || $existing->method !== (string) $input['method']
                    || $existing->external_reference !== (string) $input['external_reference']
                    || $existing->description !== trim((string) $input['reason'])
                    || ((int) $input['deposit_allocation_rupiah'] > 0 && ($movement === null
                        || (int) $movement->deposit_account_id !== (int) $input['deposit_account_id']
                        || (int) $movement->amount_rupiah !== (int) $input['deposit_allocation_rupiah']))
                    || ((int) $input['deposit_allocation_rupiah'] === 0 && $movement !== null)
                    || (int) $liabilityAmount !== ((int) $input['amount_rupiah'] - (int) $input['deposit_allocation_rupiah'])) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Kunci idempotensi telah digunakan untuk transaksi lain.']);
                }

                $this->evidence->attachToCashTransaction($tenant, $actor, $existing->id, $files);

                return $existing->load(['depositMovements', 'evidence']);
            }

            $deposit = DepositAccount::query()->where('tenant_id', $tenant->id)->whereKey($input['deposit_account_id'])->lockForUpdate()->firstOrFail();
            $amount = (int) $input['amount_rupiah'];
            $depositAllocation = (int) $input['deposit_allocation_rupiah'];
            $remaining = (int) $deposit->required_amount_rupiah - $this->depositLedger->received($deposit);
            $expectedDepositAllocation = min($amount, max(0, $remaining));
            if ($amount <= $remaining || $amount < 1 || $depositAllocation !== $expectedDepositAllocation || $deposit->settlements()->exists()) {
                throw ValidationException::withMessages(['deposit_allocation_rupiah' => 'Pembagian dana eksternal harus memenuhi sisa kewajiban deposit dan mencatat kelebihan sebagai liability sementara.']);
            }
            $surplus = $amount - $depositAllocation;

            $cash = $this->createCash($tenant, $actor, [
                ...$input,
                'amount_rupiah' => $amount,
                'idempotency_key' => $key,
                'description' => trim((string) $input['reason']),
            ], 'in', 'deposit_receipt');
            if ($depositAllocation > 0) {
                DepositMovement::query()->create([
                    'tenant_id' => $tenant->id,
                    'deposit_account_id' => $deposit->id,
                    'kind' => 'receipt',
                    'amount_rupiah' => $depositAllocation,
                    'cash_transaction_id' => $cash->id,
                    'reason' => 'Bagian deposit dari penerimaan dana eksternal.',
                    'actor_user_id' => $actor->id,
                    'idempotency_key' => $key.':deposit',
                    'created_at' => now('UTC'),
                ]);
            }
            if ($surplus > 0) {
                $account = $deposit->load(['booking', 'tenancy.resident']);
                TemporaryLiability::query()->create([
                    'tenant_id' => $tenant->id,
                    'source_cash_transaction_id' => $cash->id,
                    'resident_id' => $account->tenancy !== null ? $account->tenancy->resident_id : $account->booking?->resident_id,
                    'booking_id' => $account->booking_id,
                    'tenancy_id' => $account->tenancy_id,
                    'initial_amount_rupiah' => $surplus,
                    'created_at' => now('UTC'),
                ]);
            }
            $this->audit->record($actor, $tenant, 'deposit.external_overpayment_reconciled', 'cash_transaction', $cash->id, null, [
                'deposit_account_id' => $deposit->id, 'deposit_allocation_rupiah' => $depositAllocation,
                'temporary_liability_rupiah' => $surplus,
            ]);

            $this->evidence->attachToCashTransaction($tenant, $actor, $cash->id, $files);

            return $cash->load(['depositMovements', 'evidence']);
        });
    }

    /** @param array<string,mixed> $input */
    public function recordRentRefund(Tenant $tenant, User $actor, int $paymentId, array $input): CashTransaction
    {
        return DB::transaction(function () use ($tenant, $actor, $paymentId, $input): CashTransaction {
            $key = (string) $input['idempotency_key'];
            $amount = (int) $input['amount_rupiah'];
            $evidenceFiles = $this->normalizeEvidence($input['evidence'] ?? []);
            if ($evidenceFiles === []) {
                throw ValidationException::withMessages(['evidence' => 'Bukti transfer aktual wajib dilampirkan untuk refund sewa.']);
            }
            $existing = CashTransaction::query()->where('tenant_id', $tenant->id)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                $savedReversals = $existing->invoiceAllocations()->where('kind', 'reversal')->get()
                    ->map(fn (InvoiceCashAllocation $row): array => ['allocation_id' => (int) $row->reverses_allocation_id, 'amount_rupiah' => (int) $row->amount_rupiah])
                    ->all();
                $requestedReversals = array_map(fn (array $row): array => [
                    'allocation_id' => (int) ($row['allocation_id'] ?? $row['invoice_allocation_id'] ?? 0),
                    'amount_rupiah' => (int) ($row['amount_rupiah'] ?? 0),
                ], $input['allocations'] ?? []);
                usort($savedReversals, fn (array $a, array $b): int => [$a['allocation_id'], $a['amount_rupiah']] <=> [$b['allocation_id'], $b['amount_rupiah']]);
                usort($requestedReversals, fn (array $a, array $b): int => [$a['allocation_id'], $a['amount_rupiah']] <=> [$b['allocation_id'], $b['amount_rupiah']]);
                if ((int) $existing->amount_rupiah !== $amount || (int) $existing->reversal_of_id !== $paymentId || $existing->category !== 'rent_refund' || $savedReversals !== $requestedReversals) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Kunci idempotensi sudah digunakan untuk transaksi refund lain.']);
                }
                $this->evidence->attachToCashTransaction($tenant, $actor, $existing->id, $evidenceFiles);

                return $existing->load('evidence');
            }

            $requested = $input['allocations'] ?? [];
            $sourceAllocationIds = array_values(array_unique(array_map(
                fn (array $row): int => (int) ($row['allocation_id'] ?? $row['invoice_allocation_id'] ?? 0),
                $requested,
            )));
            $invoiceIds = InvoiceCashAllocation::query()->where('tenant_id', $tenant->id)
                ->where('cash_transaction_id', $paymentId)->where('kind', 'allocation')
                ->whereIn('id', $sourceAllocationIds)->orderBy('invoice_id')->pluck('invoice_id')->unique()->values();
            if ($sourceAllocationIds === [] || $invoiceIds->count() === 0) {
                throw ValidationException::withMessages(['allocations' => 'Refund harus memilih alokasi sewa dari receipt asal.']);
            }
            $lockedInvoices = Invoice::query()->where('tenant_id', $tenant->id)
                ->whereIn('id', $invoiceIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($lockedInvoices->count() !== $invoiceIds->count()) {
                throw ValidationException::withMessages(['allocations' => 'Invoice dari receipt tidak ditemukan.']);
            }

            $receipt = CashTransaction::query()->where('tenant_id', $tenant->id)->whereKey($paymentId)->lockForUpdate()->firstOrFail();
            if ($receipt->direction !== 'in' || ! in_array($receipt->category, ['rent_receipt', 'mixed_receipt'], true)) {
                throw ValidationException::withMessages(['payment' => 'Refund sewa hanya dapat merujuk receipt yang memiliki alokasi invoice.']);
            }
            if ($requested === [] || $amount < 1 || array_sum(array_map(fn (array $row): int => (int) ($row['amount_rupiah'] ?? 0), $requested)) !== $amount) {
                throw ValidationException::withMessages(['allocations' => 'Alokasi reversal harus berjumlah sama dengan refund aktual.']);
            }

            $reversals = [];
            $perInvoice = [];
            $perAllocation = [];
            $allocationAmounts = [];
            foreach ($requested as $row) {
                $allocationId = (int) ($row['allocation_id'] ?? $row['invoice_allocation_id'] ?? 0);
                $refundAmount = (int) ($row['amount_rupiah'] ?? 0);
                $allocation = InvoiceCashAllocation::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('cash_transaction_id', $receipt->id)
                    ->where('kind', 'allocation')
                    ->whereKey($allocationId)
                    ->lockForUpdate()
                    ->first();
                if ($allocation === null || $refundAmount < 1) {
                    throw ValidationException::withMessages(['allocations' => 'Refund harus memilih alokasi sewa dari receipt asal.']);
                }
                $alreadyReversed = (int) InvoiceCashAllocation::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('reverses_allocation_id', $allocation->id)
                    ->sum('amount_rupiah');
                if ($alreadyReversed + $refundAmount > (int) $allocation->amount_rupiah) {
                    throw ValidationException::withMessages(['allocations' => 'Refund melebihi saldo alokasi receipt yang masih aktif.']);
                }
                $perAllocation[$allocation->id] = ($perAllocation[$allocation->id] ?? 0) + $refundAmount;
                $allocationAmounts[$allocation->id] = (int) $allocation->amount_rupiah;

                $creditId = (int) ($row['credit_adjustment_id'] ?? $input['credit_adjustment_id'] ?? 0);
                $credit = InvoiceAdjustment::query()->where('tenant_id', $tenant->id)->where('invoice_id', $allocation->invoice_id)
                    ->where('kind', 'credit')->whereKey($creditId)->first();
                if ($credit === null || trim((string) ($input['reason'] ?? '')) === '') {
                    throw ValidationException::withMessages(['credit_adjustment_id' => 'Refund memerlukan credit adjustment invoice dan alasan pemilik.']);
                }

                $perInvoice[$allocation->invoice_id] = ($perInvoice[$allocation->invoice_id] ?? 0) + $refundAmount;
                $reversals[] = ['allocation' => $allocation, 'amount' => $refundAmount];
            }

            foreach ($perAllocation as $allocationId => $allocationRefundAmount) {
                $alreadyReversed = (int) InvoiceCashAllocation::query()
                    ->where('tenant_id', $tenant->id)->where('reverses_allocation_id', $allocationId)
                    ->sum('amount_rupiah');
                if ($alreadyReversed + $allocationRefundAmount > $allocationAmounts[$allocationId]) {
                    throw ValidationException::withMessages(['allocations' => 'Refund melebihi saldo alokasi receipt yang masih aktif.']);
                }
            }

            foreach ($perInvoice as $invoiceId => $invoiceRefundAmount) {
                $credited = (int) InvoiceAdjustment::query()->where('tenant_id', $tenant->id)->where('invoice_id', $invoiceId)->where('kind', 'credit')->sum('amount_rupiah');
                $alreadyRefunded = (int) InvoiceCashAllocation::query()->where('tenant_id', $tenant->id)->where('invoice_id', $invoiceId)->where('kind', 'reversal')
                    ->whereHas('cashTransaction', fn ($query) => $query->where('category', 'rent_refund'))
                    ->sum('amount_rupiah');
                if ($alreadyRefunded + $invoiceRefundAmount > $credited) {
                    throw ValidationException::withMessages(['allocations' => 'Refund sewa melebihi nilai credit adjustment yang belum digunakan.']);
                }
            }

            $cash = $this->createCash($tenant, $actor, [
                ...$input,
                'amount_rupiah' => $amount,
                'description' => trim((string) $input['reason']),
                'idempotency_key' => $key,
            ], 'out', 'rent_refund', $receipt->id);
            foreach ($reversals as $index => $reversal) {
                /** @var InvoiceCashAllocation $allocation */
                $allocation = $reversal['allocation'];
                InvoiceCashAllocation::query()->create([
                    'tenant_id' => $tenant->id,
                    'cash_transaction_id' => $cash->id,
                    'invoice_id' => $allocation->invoice_id,
                    'kind' => 'reversal',
                    'amount_rupiah' => $reversal['amount'],
                    'reverses_allocation_id' => $allocation->id,
                    'idempotency_key' => $key.':allocation-reversal:'.$allocation->id.':'.$index,
                    'created_at' => now('UTC'),
                ]);
            }
            $this->audit->record($actor, $tenant, 'cash.rent_refund_recorded', 'cash_transaction', $cash->id, null, [
                'source_cash_transaction_id' => $receipt->id,
                'amount_rupiah' => $amount,
                'reason' => trim((string) $input['reason']),
                'credit_adjustment_ids' => array_values(array_unique(array_map(fn (array $row): int => (int) ($row['credit_adjustment_id'] ?? $input['credit_adjustment_id']), $requested))),
            ]);
            $this->evidence->attachToCashTransaction($tenant, $actor, $cash->id, $evidenceFiles);

            return $cash->load(['invoiceAllocations', 'evidence']);
        });
    }

    /** @param array<string,mixed> $input */
    private function createCash(Tenant $tenant, User $actor, array $input, string $direction, string $category, ?int $reversalOfId = null): CashTransaction
    {
        return CashTransaction::query()->create([
            'tenant_id' => $tenant->id,
            'direction' => $direction,
            'category' => $category,
            'amount_rupiah' => (int) $input['amount_rupiah'],
            'occurred_on' => $input['occurred_on'],
            'method' => $input['method'],
            'external_reference' => $input['external_reference'] ?? null,
            'description' => $input['description'] ?? null,
            'recorded_by' => $actor->id,
            'idempotency_key' => (string) $input['idempotency_key'],
            'reversal_of_id' => $reversalOfId,
            'created_at' => now('UTC'),
        ]);
    }

    /** @param list<array{invoice_id?:int,deposit_account_id?:int,amount_rupiah:int}> $input
     * @return list<array{invoice_id?:int,deposit_account_id?:int,amount_rupiah:int}>
     */
    private function normalizeAllocations(array $input): array
    {
        $result = [];
        foreach ($input as $allocation) {
            $invoiceId = isset($allocation['invoice_id']) ? (int) $allocation['invoice_id'] : null;
            $depositId = isset($allocation['deposit_account_id']) ? (int) $allocation['deposit_account_id'] : null;
            $amount = (int) $allocation['amount_rupiah'];
            if (($invoiceId === null) === ($depositId === null) || $amount < 1) {
                throw ValidationException::withMessages(['allocations' => 'Setiap alokasi harus memiliki satu tujuan dan nominal positif.']);
            }

            $result[] = $invoiceId !== null
                ? ['invoice_id' => $invoiceId, 'amount_rupiah' => $amount]
                : ['deposit_account_id' => $depositId, 'amount_rupiah' => $amount];
        }

        $keys = array_map(fn (array $allocation): string => isset($allocation['invoice_id']) ? 'i'.$allocation['invoice_id'] : 'd'.$allocation['deposit_account_id'], $result);
        if (count($keys) !== count(array_unique($keys))) {
            throw ValidationException::withMessages(['allocations' => 'Tujuan alokasi hanya boleh dicantumkan satu kali per transfer.']);
        }

        return $result;
    }

    /** @param list<array{invoice_id?:int,deposit_account_id?:int,amount_rupiah:int}> $allocations
     * @return list<array{0:string,1:int,2:int}>
     */
    private function allocationFingerprint(array $allocations): array
    {
        $fingerprint = array_map(fn (array $allocation): array => [
            isset($allocation['invoice_id']) ? 'invoice' : 'deposit',
            (int) ($allocation['invoice_id'] ?? $allocation['deposit_account_id'] ?? 0),
            (int) $allocation['amount_rupiah'],
        ], $allocations);
        sort($fingerprint);

        return $fingerprint;
    }

    /** @return list<UploadedFile> */
    private function normalizeEvidence(mixed $input): array
    {
        if ($input instanceof UploadedFile) {
            return [$input];
        }
        if (! is_array($input)) {
            throw ValidationException::withMessages(['evidence' => 'Bukti harus berupa satu atau lebih berkas yang diunggah.']);
        }

        $files = array_values($input);
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                throw ValidationException::withMessages(['evidence' => 'Bukti harus berupa satu atau lebih berkas yang diunggah.']);
            }
        }

        return $files;
    }
}
