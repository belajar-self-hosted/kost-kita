<?php

namespace App\Support;

use App\Models\CashTransaction;
use App\Models\DepositAccount;
use App\Models\DepositClaimResolution;
use App\Models\DepositMovement;
use App\Models\DepositSettlement;
use App\Models\DepositSettlementLine;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DepositSettlementService
{
    public function __construct(
        private readonly DepositLedgerService $ledger,
        private readonly InvoiceBalanceService $invoiceBalances,
        private readonly DepositEvidenceService $evidence,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string,mixed> $input */
    public function open(Tenant $tenant, User $actor, int $depositAccountId, array $input): DepositSettlement
    {
        return DB::transaction(function () use ($tenant, $actor, $depositAccountId, $input): DepositSettlement {
            $account = DepositAccount::query()->where('tenant_id', $tenant->id)->whereKey($depositAccountId)->lockForUpdate()->firstOrFail();
            if ($account->settlements()->where('status', '!=', 'cancelled')->exists()) {
                throw ValidationException::withMessages(['deposit' => 'Masih ada settlement aktif untuk akun deposit ini. Batalkan draft sebelum membuat proposal pengganti.']);
            }

            $openedAt = $this->settlementStart($account);
            $baseDate = $openedAt->setTimezone('Asia/Jakarta')->startOfDay();
            $lines = $input['lines'] ?? [];
            if ($lines === []) {
                throw ValidationException::withMessages(['lines' => 'Tambahkan setidaknya satu item settlement.']);
            }

            $total = 0;
            foreach ($lines as $line) {
                $kind = (string) ($line['kind'] ?? '');
                $amount = (int) ($line['amount_rupiah'] ?? 0);
                $total += $amount;
                if ($amount < 1 || ! in_array($kind, ['refund', 'invoice_deduction', 'damage_deduction', 'held_claim'], true)) {
                    throw ValidationException::withMessages(['lines' => 'Jenis item settlement atau nominal tidak valid.']);
                }
                $this->validateLine($tenant, $account, $line);
            }
            if ($total !== $this->ledger->balance($account)) {
                throw ValidationException::withMessages(['lines' => 'Item settlement harus mencakup seluruh saldo deposit. Tambahkan item refund eksplisit untuk sisanya.']);
            }

            $day7 = $baseDate->addDays(8)->startOfDay()->subMicrosecond();
            $day30 = $baseDate->addDays(31)->startOfDay()->subMicrosecond();
            $settlement = DepositSettlement::query()->create([
                'tenant_id' => $tenant->id,
                'deposit_account_id' => $account->id,
                'status' => 'draft',
                'exit_inspection_notes' => trim((string) $input['exit_inspection_notes']),
                'opened_at' => $openedAt,
                'statement_issued_at' => now('UTC'),
                'day7_deadline_at' => $day7->setTimezone('UTC'),
                'day30_deadline_at' => $day30->setTimezone('UTC'),
            ]);

            foreach (array_values($lines) as $index => $lineInput) {
                $key = (string) ($lineInput['idempotency_key'] ?? "settlement:{$settlement->id}:line:{$index}");
                $line = DepositSettlementLine::query()->create([
                    'tenant_id' => $tenant->id,
                    'settlement_id' => $settlement->id,
                    'kind' => $lineInput['kind'],
                    'amount_rupiah' => (int) $lineInput['amount_rupiah'],
                    'reason' => trim((string) $lineInput['reason']),
                    'agreement_basis' => isset($lineInput['agreement_basis']) ? trim((string) $lineInput['agreement_basis']) : null,
                    'invoice_id' => isset($lineInput['invoice_id']) ? (int) $lineInput['invoice_id'] : null,
                    'idempotency_key' => $key,
                    'created_at' => now('UTC'),
                ]);
                $files = $this->normalizeEvidence($lineInput['evidence'] ?? []);
                if ($files !== []) {
                    $this->evidence->attachToLine($tenant, $actor, $line->id, $files);
                }
            }

            $this->audit->record($actor, $tenant, 'deposit.settlement_opened', 'deposit_settlement', $settlement->id, null, [
                'deposit_account_id' => $account->id, 'line_count' => count($lines), 'opened_at' => $openedAt->toISOString(),
                'day7_deadline_at' => $day7->toISOString(), 'day30_deadline_at' => $day30->toISOString(),
            ]);

            return $settlement->load('lines.evidence');
        });
    }

    public function recordWhatsAppNotification(Tenant $tenant, User $actor, int $settlementId): DepositSettlement
    {
        return DB::transaction(function () use ($tenant, $actor, $settlementId): DepositSettlement {
            $settlement = DepositSettlement::query()->where('tenant_id', $tenant->id)->whereKey($settlementId)->lockForUpdate()->firstOrFail();
            if (in_array($settlement->status, ['finalized', 'cancelled'], true)) {
                throw ValidationException::withMessages(['settlement' => 'Settlement yang sudah selesai tidak dapat diubah.']);
            }
            if ($settlement->whatsapp_notified_at !== null) {
                return $settlement;
            }

            $settlement->update(['whatsapp_notified_at' => now('UTC')]);
            $this->audit->record($actor, $tenant, 'deposit.settlement_whatsapp_notified', 'deposit_settlement', $settlement->id, null, ['recorded_at' => now('UTC')->toISOString()]);

            return $settlement->refresh();
        });
    }

    public function cancelDraft(Tenant $tenant, User $actor, int $settlementId, string $reason): DepositSettlement
    {
        return DB::transaction(function () use ($tenant, $actor, $settlementId, $reason): DepositSettlement {
            $settlement = DepositSettlement::query()->where('tenant_id', $tenant->id)->whereKey($settlementId)->lockForUpdate()->firstOrFail();
            $account = DepositAccount::query()->where('tenant_id', $tenant->id)->whereKey($settlement->deposit_account_id)->lockForUpdate()->firstOrFail();
            if ($settlement->status !== 'draft' || $settlement->approved_at !== null
                || $settlement->lines()->whereNotNull('approved_at')->exists()
                || DepositMovement::query()->where('tenant_id', $tenant->id)->whereIn('settlement_line_id', $settlement->lines()->select('id'))->exists()) {
                throw ValidationException::withMessages(['settlement' => 'Hanya draft tanpa movement finansial yang dapat dibatalkan.']);
            }

            $reason = trim($reason);
            $oldValues = ['status' => $settlement->status, 'whatsapp_notified_at' => $settlement->whatsapp_notified_at?->toISOString()];
            $settlement->update(['status' => 'cancelled']);
            $this->audit->record($actor, $tenant, 'deposit.settlement_draft_cancelled', 'deposit_settlement', $settlement->id, $oldValues, [
                'status' => 'cancelled', 'deposit_account_id' => $account->id, 'reason' => $reason,
            ]);

            return $settlement->refresh();
        });
    }

    public function approve(Tenant $tenant, User $actor, int $settlementId): DepositSettlement
    {
        return DB::transaction(function () use ($tenant, $actor, $settlementId): DepositSettlement {
            $settlement = DepositSettlement::query()
                ->where('tenant_id', $tenant->id)->whereKey($settlementId)
                ->with('lines')
                ->lockForUpdate()->firstOrFail();
            if (in_array($settlement->status, ['approved', 'partially_settled', 'finalized', 'late'], true)
                && (int) $settlement->approved_by === $actor->id) {
                return $settlement;
            }
            if ($settlement->status !== 'draft' || $settlement->whatsapp_notified_at === null) {
                throw ValidationException::withMessages(['settlement' => 'Settlement harus berupa draft dan pemberitahuan WhatsApp manual harus dicatat sebelum disetujui.']);
            }
            $approvedTotal = (int) $settlement->lines->sum('amount_rupiah');
            $deductionAmounts = $settlement->lines->where('kind', 'invoice_deduction')
                ->groupBy('invoice_id')
                ->map(fn ($lines): int => (int) $lines->sum('amount_rupiah'));
            $invoices = Invoice::query()->where('tenant_id', $tenant->id)
                ->whereIn('id', $deductionAmounts->keys())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $account = DepositAccount::query()->where('tenant_id', $tenant->id)->whereKey($settlement->deposit_account_id)->lockForUpdate()->firstOrFail();
            if ($approvedTotal !== $this->ledger->balance($account)) {
                throw ValidationException::withMessages(['settlement' => 'Item settlement harus mencakup seluruh saldo deposit yang masih tersisa. Buka ulang proposal dengan item refund yang benar.']);
            }

            foreach ($settlement->lines as $line) {
                $this->validateLine($tenant, $account, $line->only(['kind', 'amount_rupiah', 'reason', 'agreement_basis', 'invoice_id']) + ['evidence' => $line->evidence()->get()->all()]);
            }
            foreach ($deductionAmounts as $invoiceId => $deductionAmount) {
                $invoice = $invoices->get($invoiceId);
                if ($invoice === null || $invoice->due_date->toDateString() > now('Asia/Jakarta')->toDateString()
                    || $deductionAmount > $this->invoiceBalances->outstanding($invoice)) {
                    throw ValidationException::withMessages(['settlement' => 'Tagihan berubah, belum jatuh tempo, atau total pengurangannya melebihi saldo tagihan.']);
                }
            }

            foreach ($settlement->lines as $line) {
                $line->update(['approved_by' => $actor->id, 'approved_at' => now('UTC')]);
                if (in_array($line->kind, ['invoice_deduction', 'damage_deduction'], true)) {
                    DepositMovement::query()->create([
                        'tenant_id' => $tenant->id,
                        'deposit_account_id' => $account->id,
                        'kind' => $line->kind === 'invoice_deduction' ? 'invoice_settlement' : 'damage_deduction',
                        'amount_rupiah' => $line->amount_rupiah,
                        'settlement_line_id' => $line->id,
                        'invoice_id' => $line->invoice_id,
                        'reason' => $line->reason,
                        'actor_user_id' => $actor->id,
                        'approved_by' => $actor->id,
                        'idempotency_key' => "settlement:{$settlement->id}:movement:{$line->id}",
                        'created_at' => now('UTC'),
                    ]);
                }
            }

            $settlement->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now('UTC')]);
            $this->audit->record($actor, $tenant, 'deposit.settlement_approved', 'deposit_settlement', $settlement->id, ['status' => 'draft'], ['status' => 'approved', 'deposit_account_id' => $account->id]);
            $this->refreshStatus($settlement, $account);

            return $settlement->refresh();
        });
    }

    /** @param array<string,mixed> $input */
    public function recordRefund(Tenant $tenant, User $actor, int $depositAccountId, array $input): CashTransaction
    {
        return DB::transaction(function () use ($tenant, $actor, $depositAccountId, $input): CashTransaction {
            $key = (string) $input['idempotency_key'];
            $existing = CashTransaction::query()->where('tenant_id', $tenant->id)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                $movement = $existing->depositMovements()->where('kind', 'refund')->first();
                if ($movement === null || (int) $existing->amount_rupiah !== (int) $input['amount_rupiah']
                    || $existing->category !== 'deposit_refund'
                    || (int) $movement->deposit_account_id !== $depositAccountId
                    || (isset($input['settlement_line_id']) && (int) $movement->settlement_line_id !== (int) $input['settlement_line_id'])) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Kunci idempotensi sudah digunakan untuk nominal lain.']);
                }

                return $existing;
            }

            $settlement = DepositSettlement::query()->where('tenant_id', $tenant->id)->where('deposit_account_id', $depositAccountId)
                ->where('status', '!=', 'cancelled')->lockForUpdate()->firstOrFail();
            $account = DepositAccount::query()->where('tenant_id', $tenant->id)->whereKey($depositAccountId)->lockForUpdate()->firstOrFail();
            $line = DepositSettlementLine::query()
                ->where('tenant_id', $tenant->id)->where('settlement_id', $settlement->id)
                ->where('kind', 'refund')->whereNotNull('approved_by')
                ->when(isset($input['settlement_line_id']), fn ($query) => $query->whereKey($input['settlement_line_id']))
                ->orderBy('id')->lockForUpdate()->first();
            $amount = (int) $input['amount_rupiah'];
            $evidence = $this->normalizeEvidence($input['evidence'] ?? []);
            if ($line === null || $settlement->approved_at === null || $amount < 1 || $amount > $this->remainingRefundLine($line) || $amount > $this->ledger->balance($account)
                || empty($input['occurred_on']) || empty($input['method']) || empty($input['external_reference']) || $evidence === []) {
                throw ValidationException::withMessages(['amount_rupiah' => 'Refund harus sesuai jumlah yang disetujui dan saldo deposit aktual.']);
            }

            $cash = $this->createRefundCash($tenant, $actor, $input, $key, 'deposit_refund');
            DepositMovement::query()->create([
                'tenant_id' => $tenant->id,
                'deposit_account_id' => $account->id,
                'kind' => 'refund',
                'amount_rupiah' => $amount,
                'cash_transaction_id' => $cash->id,
                'settlement_line_id' => $line->id,
                'reason' => $input['reason'] ?? 'Pengembalian deposit sesuai settlement yang disetujui.',
                'actor_user_id' => $actor->id,
                'approved_by' => $actor->id,
                'idempotency_key' => $key.':deposit-movement',
                'created_at' => now('UTC'),
            ]);
            $this->evidence->attachToLine($tenant, $actor, $line->id, $evidence);
            $this->refreshStatus($settlement, $account);
            $this->audit->record($actor, $tenant, 'deposit.refund_recorded', 'deposit_settlement', $settlement->id, null, ['cash_transaction_id' => $cash->id, 'amount_rupiah' => $amount]);

            return $cash;
        });
    }

    /** @param array<string,mixed> $input */
    public function finalizeHeldClaim(Tenant $tenant, User $actor, int $settlementId, int $lineId, array $input): DepositClaimResolution
    {
        return DB::transaction(function () use ($tenant, $actor, $settlementId, $lineId, $input): DepositClaimResolution {
            $settlement = DepositSettlement::query()->where('tenant_id', $tenant->id)->whereKey($settlementId)->lockForUpdate()->firstOrFail();
            $account = DepositAccount::query()->where('tenant_id', $tenant->id)->whereKey($settlement->deposit_account_id)->lockForUpdate()->firstOrFail();
            $line = DepositSettlementLine::query()->where('tenant_id', $tenant->id)->where('settlement_id', $settlement->id)->whereKey($lineId)->lockForUpdate()->firstOrFail();
            $damage = (int) ($input['actual_damage_amount_rupiah'] ?? 0);
            $refund = (int) $line->amount_rupiah - $damage;
            $resolution = DepositClaimResolution::query()->where('tenant_id', $tenant->id)->where('held_claim_line_id', $line->id)->first();
            if ($resolution !== null) {
                if ((int) $resolution->actual_damage_amount_rupiah === $damage
                    && (int) $resolution->refund_amount_rupiah === $refund
                    && $resolution->resolution_basis === trim((string) ($input['resolution_basis'] ?? ''))) {
                    return $resolution;
                }
                throw ValidationException::withMessages(['claim' => 'Klaim ini sudah difinalisasi dengan nilai yang berbeda.']);
            }
            if ($line->kind !== 'held_claim' || $line->approved_at === null || $settlement->approved_at === null) {
                throw ValidationException::withMessages(['claim' => 'Klaim harus sudah disetujui, belum pernah difinalisasi, dan berasal dari settlement ini.']);
            }
            $files = $this->normalizeEvidence($input['evidence'] ?? []);
            if ($damage < 0 || $damage > (int) $line->amount_rupiah || $refund < 0 || trim((string) ($input['resolution_basis'] ?? '')) === '' || ($damage > 0 && $files === [])) {
                throw ValidationException::withMessages(['claim' => 'Biaya final harus dalam batas hold, memiliki dasar penyelesaian, dan bukti aktual.']);
            }
            if ($refund > 0 && (empty($input['occurred_on']) || empty($input['method']) || empty($input['external_reference']) || $files === [])) {
                throw ValidationException::withMessages(['claim' => 'Refund klaim memerlukan tanggal, metode, referensi, dan bukti transfer aktual.']);
            }

            $damageMovement = null;
            if ($damage > 0) {
                $damageMovement = DepositMovement::query()->create([
                    'tenant_id' => $tenant->id, 'deposit_account_id' => $account->id, 'kind' => 'damage_deduction',
                    'amount_rupiah' => $damage, 'settlement_line_id' => $line->id,
                    'reason' => trim((string) $input['resolution_basis']), 'actor_user_id' => $actor->id, 'approved_by' => $actor->id,
                    'idempotency_key' => "claim:{$line->id}:damage", 'created_at' => now('UTC'),
                ]);
            }
            $refundMovement = null;
            if ($refund > 0) {
                $cash = $this->createRefundCash($tenant, $actor, [...$input, 'amount_rupiah' => $refund, 'reason' => $input['resolution_basis']], (string) $input['idempotency_key'], 'deposit_refund');
                $refundMovement = DepositMovement::query()->create([
                    'tenant_id' => $tenant->id, 'deposit_account_id' => $account->id, 'kind' => 'refund',
                    'amount_rupiah' => $refund, 'cash_transaction_id' => $cash->id, 'settlement_line_id' => $line->id,
                    'reason' => trim((string) $input['resolution_basis']), 'actor_user_id' => $actor->id, 'approved_by' => $actor->id,
                    'idempotency_key' => $input['idempotency_key'].':refund-movement', 'created_at' => now('UTC'),
                ]);
            }
            $resolution = DepositClaimResolution::query()->create([
                'tenant_id' => $tenant->id,
                'settlement_id' => $settlement->id,
                'held_claim_line_id' => $line->id,
                'actual_damage_amount_rupiah' => $damage,
                'refund_amount_rupiah' => $refund,
                'damage_movement_id' => $damageMovement?->id,
                'refund_movement_id' => $refundMovement?->id,
                'resolution_basis' => trim((string) $input['resolution_basis']),
                'approved_by' => $actor->id,
                'approved_at' => now('UTC'),
                'created_at' => now('UTC'),
            ]);
            if ($files !== []) {
                $this->evidence->attachToResolution($tenant, $actor, $resolution->id, $files);
            }
            $this->refreshStatus($settlement, $account);
            $this->audit->record($actor, $tenant, 'deposit.claim_finalized', 'deposit_claim_resolution', $resolution->id, null, [
                'held_claim_line_id' => $line->id, 'damage_amount_rupiah' => $damage, 'refund_amount_rupiah' => $refund,
            ]);

            return $resolution->refresh();
        });
    }

    public function markLateClaims(Tenant $tenant, User $actor): int
    {
        $changed = 0;
        $candidateIds = DepositSettlement::query()->where('tenant_id', $tenant->id)
            ->whereIn('status', ['approved', 'partially_settled'])
            ->where('day30_deadline_at', '<', now('UTC'))
            ->orderBy('id')->pluck('id');
        foreach ($candidateIds as $candidateId) {
            DB::transaction(function () use ($candidateId, $tenant, $actor, &$changed): void {
                $settlement = DepositSettlement::query()->where('tenant_id', $tenant->id)->whereKey($candidateId)->lockForUpdate()->first();
                if ($settlement === null || ! in_array($settlement->status, ['approved', 'partially_settled'], true) || $settlement->day30_deadline_at->greaterThanOrEqualTo(now('UTC'))) {
                    return;
                }
                $unresolved = $settlement->lines()->where('kind', 'held_claim')->whereDoesntHave('claimResolution')->exists();
                if (! $unresolved) {
                    return;
                }
                $oldStatus = $settlement->status;
                $settlement->update(['status' => 'late']);
                $this->audit->record($actor, $tenant, 'deposit.settlement_claim_late', 'deposit_settlement', $settlement->id, ['status' => $oldStatus], ['status' => 'late']);
                $changed++;
            });
        }

        return $changed;
    }

    private function settlementStart(DepositAccount $account): CarbonImmutable
    {
        $account->loadMissing(['booking', 'tenancy']);
        if ($account->tenancy !== null && in_array($account->tenancy->status, ['completed', 'terminated'], true)) {
            if ($account->tenancy->actual_checkout_date !== null) {
                return CarbonImmutable::parse($account->tenancy->actual_checkout_date->toDateString(), 'Asia/Jakarta')->endOfDay()->setTimezone('UTC');
            }
            if ($account->tenancy->terminated_at !== null) {
                return $account->tenancy->terminated_at->setTimezone('Asia/Jakarta')->endOfDay()->setTimezone('UTC');
            }
        }
        if ($account->booking !== null && in_array($account->booking->status, ['cancelled', 'no_show'], true)) {
            $instant = $account->booking->cancelled_at ?? $account->booking->no_show_at ?? $account->booking->updated_at;

            return $instant->setTimezone('UTC');
        }

        throw ValidationException::withMessages(['deposit' => 'Settlement hanya dapat dimulai setelah check-out, pembatalan, atau no-show.']);
    }

    /** @param array<string,mixed> $line */
    private function validateLine(Tenant $tenant, DepositAccount $account, array $line): void
    {
        $kind = (string) ($line['kind'] ?? '');
        $basis = trim((string) ($line['agreement_basis'] ?? ''));
        $reason = trim((string) ($line['reason'] ?? ''));
        $evidence = $line['evidence'] ?? [];
        if ($reason === '') {
            throw ValidationException::withMessages(['lines' => 'Setiap item settlement memerlukan alasan.']);
        }
        if (in_array($kind, ['damage_deduction', 'held_claim'], true)
            && preg_match('/keausan\s+wajar|wear(?:\s+and)?\s+tear|maintenance\s+rutin|perawatan\s+rutin|biaya\s+administratif\s+arbitrer|kerugian\s+masa\s+depan|future\s+loss/i', $reason) === 1) {
            throw ValidationException::withMessages(['lines' => 'Keausan wajar, maintenance rutin, biaya administratif arbitrer, dan kerugian masa depan tidak dapat dipotong dari deposit.']);
        }
        if ($kind === 'invoice_deduction') {
            if ($basis === '' || empty($line['invoice_id'])) {
                throw ValidationException::withMessages(['lines' => 'Pengurangan tagihan memerlukan invoice tercatat dan dasar perjanjian tertulis.']);
            }
            $invoice = Invoice::query()->where('tenant_id', $tenant->id)->whereKey($line['invoice_id'])->firstOrFail();
            if ($account->tenancy_id === null || $invoice->tenancy_id !== $account->tenancy_id
                || $invoice->due_date->toDateString() > now('Asia/Jakarta')->toDateString()
                || (int) $line['amount_rupiah'] > $this->invoiceBalances->outstanding($invoice)) {
                throw ValidationException::withMessages(['lines' => 'Invoice bukan kewajiban tenancy ini, belum jatuh tempo, atau nominalnya melebihi saldo.']);
            }
        }
        if (in_array($kind, ['damage_deduction', 'held_claim'], true) && ($basis === '' || $evidence === [])) {
            throw ValidationException::withMessages(['lines' => 'Pengurangan atau klaim kerusakan memerlukan dasar kesepakatan dan bukti privat.']);
        }
        if ($kind !== 'invoice_deduction' && ! empty($line['invoice_id'])) {
            throw ValidationException::withMessages(['lines' => 'Hanya item pengurangan invoice yang boleh merujuk tagihan.']);
        }
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

        $files = [];
        foreach ($input as $file) {
            if (! $file instanceof UploadedFile) {
                throw ValidationException::withMessages(['evidence' => 'Bukti harus berupa satu atau lebih berkas yang diunggah.']);
            }
            $files[] = $file;
        }

        return $files;
    }

    private function remainingRefundLine(DepositSettlementLine $line): int
    {
        $refunded = DepositMovement::query()->where('tenant_id', $line->tenant_id)->where('settlement_line_id', $line->id)->where('kind', 'refund')->sum('amount_rupiah');

        return max(0, (int) $line->amount_rupiah - (int) $refunded);
    }

    /** @param array<string,mixed> $input */
    private function createRefundCash(Tenant $tenant, User $actor, array $input, string $key, string $category): CashTransaction
    {
        return CashTransaction::query()->create([
            'tenant_id' => $tenant->id, 'direction' => 'out', 'category' => $category,
            'amount_rupiah' => (int) $input['amount_rupiah'], 'occurred_on' => $input['occurred_on'],
            'method' => $input['method'], 'external_reference' => $input['external_reference'] ?? null,
            'description' => $input['reason'] ?? 'Refund deposit aktual.', 'recorded_by' => $actor->id,
            'idempotency_key' => $key, 'created_at' => now('UTC'),
        ]);
    }

    private function refreshStatus(DepositSettlement $settlement, DepositAccount $account): void
    {
        $unresolvedClaims = $settlement->lines()->where('kind', 'held_claim')
            ->whereDoesntHave('claimResolution')->exists();
        $balance = $this->ledger->balance($account);
        $status = $unresolvedClaims && now('UTC')->greaterThan($settlement->day30_deadline_at)
            ? 'late'
            : ($balance === 0 && ! $unresolvedClaims ? 'finalized' : 'partially_settled');
        $settlement->update([
            'status' => $status,
            'finalized_at' => $status === 'finalized' ? now('UTC') : null,
        ]);
    }
}
