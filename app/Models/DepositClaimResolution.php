<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class DepositClaimResolution extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Deposit claim resolutions are immutable.'));
        static::deleting(fn () => throw new LogicException('Deposit claim resolutions cannot be deleted.'));
    }

    protected $fillable = ['tenant_id', 'settlement_id', 'held_claim_line_id', 'actual_damage_amount_rupiah', 'refund_amount_rupiah', 'damage_movement_id', 'refund_movement_id', 'resolution_basis', 'approved_by', 'approved_at', 'created_at'];

    protected function casts(): array
    {
        return ['actual_damage_amount_rupiah' => 'integer', 'refund_amount_rupiah' => 'integer', 'approved_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<DepositSettlement, $this> */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(DepositSettlement::class, 'settlement_id');
    }

    /** @return BelongsTo<DepositSettlementLine, $this> */
    public function heldClaimLine(): BelongsTo
    {
        return $this->belongsTo(DepositSettlementLine::class, 'held_claim_line_id');
    }

    /** @return BelongsTo<DepositMovement, $this> */
    public function damageMovement(): BelongsTo
    {
        return $this->belongsTo(DepositMovement::class, 'damage_movement_id');
    }

    /** @return BelongsTo<DepositMovement, $this> */
    public function refundMovement(): BelongsTo
    {
        return $this->belongsTo(DepositMovement::class, 'refund_movement_id');
    }
}
