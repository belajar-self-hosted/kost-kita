<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $temporary_liability_id
 * @property string $kind
 * @property int $amount_rupiah
 * @property int|null $cash_transaction_id
 * @property int|null $reversal_of_movement_id
 * @property string $idempotency_key
 * @property-read TemporaryLiability $liability
 * @property-read CashTransaction|null $cashTransaction
 * @property-read TemporaryLiabilityMovement|null $reversalOf
 */
class TemporaryLiabilityMovement extends Model
{
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'temporary_liability_id', 'kind', 'amount_rupiah', 'cash_transaction_id', 'reversal_of_movement_id', 'reason', 'actor_user_id', 'approved_by', 'idempotency_key', 'created_at'];

    protected function casts(): array
    {
        return ['amount_rupiah' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Temporary liability movements are immutable.'));
        static::deleting(fn () => throw new LogicException('Temporary liability movements cannot be deleted.'));
    }

    /** @return BelongsTo<TemporaryLiability, $this> */
    public function liability(): BelongsTo
    {
        return $this->belongsTo(TemporaryLiability::class, 'temporary_liability_id');
    }

    /** @return BelongsTo<CashTransaction, $this> */
    public function cashTransaction(): BelongsTo
    {
        return $this->belongsTo(CashTransaction::class);
    }

    /** @return BelongsTo<TemporaryLiabilityMovement, $this> */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_movement_id');
    }
}
