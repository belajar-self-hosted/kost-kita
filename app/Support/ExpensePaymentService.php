<?php

namespace App\Support;

use App\Models\CashTransaction;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpensePaymentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string,mixed> $input */
    public function recordPayment(Tenant $tenant, User $actor, int $expenseId, array $input): CashTransaction
    {
        return DB::transaction(function () use ($tenant, $actor, $expenseId, $input): CashTransaction {
            $key = (string) $input['idempotency_key'];
            $existing = CashTransaction::query()->where('tenant_id', $tenant->id)->where('idempotency_key', $key)
                ->with('expensePayments')
                ->first();
            if ($existing !== null) {
                $allocation = $existing->expensePayments->where('kind', 'allocation')->first();
                if ($allocation === null) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Kunci idempotensi tidak merujuk alokasi biaya.']);
                }
                if ((int) $existing->amount_rupiah !== (int) $input['amount_rupiah']
                    || $existing->category !== ($allocation->expense->kind === 'maintenance' ? 'maintenance_expense' : 'operating_expense')
                    || (int) $allocation->expense_id !== $expenseId
                    || (int) $allocation->amount_rupiah !== (int) $input['amount_rupiah']) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Kunci idempotensi sudah dipakai untuk nominal lain.']);
                }

                return $existing;
            }

            $expense = Expense::query()->where('tenant_id', $tenant->id)->whereKey($expenseId)->lockForUpdate()->firstOrFail();
            $amount = (int) $input['amount_rupiah'];
            if ($amount < 1 || $amount > $expense->outstandingAmount()) {
                throw ValidationException::withMessages(['amount_rupiah' => 'Nominal pembayaran melebihi sisa kewajiban biaya.']);
            }

            $category = $expense->kind === 'maintenance' ? 'maintenance_expense' : 'operating_expense';
            $cash = CashTransaction::query()->create([
                'tenant_id' => $tenant->id,
                'direction' => 'out',
                'category' => $category,
                'amount_rupiah' => $amount,
                'occurred_on' => $input['occurred_on'],
                'method' => $input['method'],
                'external_reference' => $input['external_reference'] ?? null,
                'description' => $input['description'] ?? $expense->description,
                'recorded_by' => $actor->id,
                'idempotency_key' => $key,
                'created_at' => now('UTC'),
            ]);
            ExpensePayment::query()->create([
                'tenant_id' => $tenant->id,
                'expense_id' => $expense->id,
                'cash_transaction_id' => $cash->id,
                'kind' => 'allocation',
                'amount_rupiah' => $amount,
                'idempotency_key' => $key.':expense:'.$expense->id,
                'created_at' => now('UTC'),
            ]);
            $this->audit->record($actor, $tenant, 'expense.payment_recorded', 'expense', $expense->id, null, [
                'cash_transaction_id' => $cash->id, 'amount_rupiah' => $amount, 'category' => $category,
            ]);

            return $cash;
        });
    }
}
