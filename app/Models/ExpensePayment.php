<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $expense_id
 * @property int $cash_transaction_id
 * @property string $kind
 * @property int $amount_rupiah
 * @property int|null $reverses_payment_id
 * @property string $idempotency_key
 * @property CarbonImmutable $created_at
 * @property-read Expense $expense
 * @property-read CashTransaction $cashTransaction
 */
class ExpensePayment extends Model
{
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'expense_id', 'cash_transaction_id', 'kind', 'amount_rupiah', 'reverses_payment_id', 'idempotency_key', 'created_at'];

    protected function casts(): array
    {
        return ['amount_rupiah' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Expense payment allocations are immutable.'));
        static::deleting(fn () => throw new LogicException('Expense payment allocations cannot be deleted.'));
    }

    /** @return BelongsTo<Expense, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /** @return BelongsTo<CashTransaction, $this> */
    public function cashTransaction(): BelongsTo
    {
        return $this->belongsTo(CashTransaction::class);
    }
}
