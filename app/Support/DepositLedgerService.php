<?php

namespace App\Support;

use App\Models\CashTransaction;
use App\Models\DepositAccount;
use App\Models\TemporaryLiability;
use App\Models\TemporaryLiabilityMovement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DepositLedgerService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function updateRequirement(Tenant $tenant, User $actor, int $depositAccountId, int $requiredAmount, string $reason): DepositAccount
    {
        return DB::transaction(function () use ($tenant, $actor, $depositAccountId, $requiredAmount, $reason): DepositAccount {
            $account = DepositAccount::query()->where('tenant_id', $tenant->id)->whereKey($depositAccountId)->lockForUpdate()->firstOrFail();
            if ($requiredAmount < 0 || trim($reason) === '' || $account->movements()->where('kind', 'receipt')->exists()) {
                throw ValidationException::withMessages(['required_amount_rupiah' => 'Nominal hanya dapat diubah dengan alasan sebelum receipt deposit pertama dicatat.']);
            }

            $oldAmount = (int) $account->required_amount_rupiah;
            $account->update(['required_amount_rupiah' => $requiredAmount]);
            $this->audit->record($actor, $tenant, 'deposit.requirement_updated', 'deposit_account', $account->id, [
                'required_amount_rupiah' => $oldAmount,
            ], [
                'required_amount_rupiah' => $requiredAmount,
                'reason' => trim($reason),
            ]);

            return $account->refresh();
        });
    }

    public function received(DepositAccount $account): int
    {
        $movements = $account->movements()->with('reversalOf')->get();
        $received = 0;
        foreach ($movements as $movement) {
            $received += match ($movement->kind) {
                'receipt' => (int) $movement->amount_rupiah,
                'reversal' => $movement->reversalOf?->kind === 'receipt' ? -(int) $movement->amount_rupiah : 0,
                default => 0,
            };
        }

        return max(0, $received);
    }

    public function balance(DepositAccount $account): int
    {
        $movements = $account->movements()->with('reversalOf')->get();
        $balance = 0;
        foreach ($movements as $movement) {
            $sign = match ($movement->kind) {
                'receipt' => 1,
                'refund', 'invoice_settlement', 'damage_deduction' => -1,
                'reversal' => match ($movement->reversalOf?->kind) {
                    'receipt' => -1,
                    'refund', 'invoice_settlement', 'damage_deduction' => 1,
                    default => 0,
                },
                default => 0,
            };
            $balance += $sign * (int) $movement->amount_rupiah;
        }

        return max(0, $balance);
    }

    public function temporaryLiabilityBalance(TemporaryLiability $liability): int
    {
        $movements = $liability->movements()->with('reversalOf')->get();
        $balance = (int) $liability->initial_amount_rupiah;
        foreach ($movements as $movement) {
            $balance += match ($movement->kind) {
                'refund' => -(int) $movement->amount_rupiah,
                'reversal' => $movement->reversalOf?->kind === 'refund' ? (int) $movement->amount_rupiah : 0,
                default => 0,
            };
        }

        return max(0, $balance);
    }

    /** @param array<string,mixed> $input */
    public function refundTemporaryLiability(Tenant $tenant, User $actor, int $liabilityId, array $input): CashTransaction
    {
        return DB::transaction(function () use ($tenant, $actor, $liabilityId, $input): CashTransaction {
            $key = (string) $input['idempotency_key'];
            $existing = CashTransaction::query()->where('tenant_id', $tenant->id)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                $movement = $existing->temporaryLiabilityMovements()->where('kind', 'refund')->first();
                if ($movement === null || (int) $existing->amount_rupiah !== (int) $input['amount_rupiah']
                    || $existing->category !== 'temporary_liability_refund'
                    || (int) $movement->temporary_liability_id !== $liabilityId
                    || (int) $movement->amount_rupiah !== (int) $input['amount_rupiah']) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Kunci idempotensi sudah digunakan untuk nominal lain.']);
                }

                return $existing;
            }

            $liability = TemporaryLiability::query()->where('tenant_id', $tenant->id)->whereKey($liabilityId)->lockForUpdate()->firstOrFail();
            $amount = (int) $input['amount_rupiah'];
            if ($amount < 1 || $amount > $this->temporaryLiabilityBalance($liability)) {
                throw ValidationException::withMessages(['amount_rupiah' => 'Nominal melebihi saldo dana sementara yang belum dikembalikan.']);
            }

            $cash = CashTransaction::query()->create([
                'tenant_id' => $tenant->id,
                'direction' => 'out',
                'category' => 'temporary_liability_refund',
                'amount_rupiah' => $amount,
                'occurred_on' => $input['occurred_on'],
                'method' => $input['method'],
                'external_reference' => $input['external_reference'] ?? null,
                'description' => $input['description'] ?? 'Pengembalian kelebihan penerimaan dana.',
                'recorded_by' => $actor->id,
                'idempotency_key' => $key,
                'created_at' => now('UTC'),
            ]);
            TemporaryLiabilityMovement::query()->create([
                'tenant_id' => $tenant->id,
                'temporary_liability_id' => $liability->id,
                'kind' => 'refund',
                'amount_rupiah' => $amount,
                'cash_transaction_id' => $cash->id,
                'reason' => $input['description'] ?? 'Pengembalian kelebihan penerimaan dana.',
                'actor_user_id' => $actor->id,
                'idempotency_key' => $key.':temporary-liability:'.$liability->id,
                'created_at' => now('UTC'),
            ]);
            $this->audit->record($actor, $tenant, 'temporary_liability.refund_recorded', 'temporary_liability', $liability->id, null, [
                'cash_transaction_id' => $cash->id,
                'amount_rupiah' => $amount,
                'reason' => $input['description'] ?? 'Pengembalian kelebihan penerimaan dana.',
            ]);

            return $cash;
        });
    }
}
