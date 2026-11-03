<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $deposit_account_id
 * @property string $kind
 * @property int $amount_rupiah
 * @property int|null $cash_transaction_id
 * @property int|null $settlement_line_id
 * @property int|null $invoice_id
 * @property int|null $reversal_of_movement_id
 * @property string|null $reason
 * @property int $actor_user_id
 * @property int|null $approved_by
 * @property string $idempotency_key
 * @property CarbonImmutable $created_at
 * @property-read DepositAccount $account
 * @property-read CashTransaction|null $cashTransaction
 * @property-read DepositSettlementLine|null $settlementLine
 * @property-read Invoice|null $invoice
 * @property-read DepositMovement|null $reversalOf
 */
class DepositMovement extends Model
{
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'deposit_account_id', 'kind', 'amount_rupiah', 'cash_transaction_id', 'settlement_line_id', 'invoice_id', 'reversal_of_movement_id', 'reason', 'actor_user_id', 'approved_by', 'idempotency_key', 'created_at'];

    protected function casts(): array
    {
        return ['amount_rupiah' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Deposit movements are immutable.'));
        static::deleting(fn () => throw new LogicException('Deposit movements cannot be deleted.'));
    }

    /** @return BelongsTo<DepositAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(DepositAccount::class, 'deposit_account_id');
    }

    /** @return BelongsTo<CashTransaction, $this> */
    public function cashTransaction(): BelongsTo
    {
        return $this->belongsTo(CashTransaction::class);
    }

    /** @return BelongsTo<DepositSettlementLine, $this> */
    public function settlementLine(): BelongsTo
    {
        return $this->belongsTo(DepositSettlementLine::class, 'settlement_line_id');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<DepositMovement, $this> */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_movement_id');
    }
}
