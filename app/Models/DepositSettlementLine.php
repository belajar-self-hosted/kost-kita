<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class DepositSettlementLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'settlement_id', 'kind', 'amount_rupiah', 'reason', 'agreement_basis', 'invoice_id', 'idempotency_key', 'approved_by', 'approved_at', 'created_at'];

    protected function casts(): array
    {
        return ['amount_rupiah' => 'integer', 'approved_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $model): void {
            if ($model->isDirty(['tenant_id', 'settlement_id', 'kind', 'amount_rupiah', 'reason', 'agreement_basis', 'invoice_id', 'idempotency_key', 'created_at'])) {
                throw new LogicException('Deposit settlement lines are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Deposit settlement lines cannot be deleted.'));
    }

    /** @return BelongsTo<DepositSettlement, $this> */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(DepositSettlement::class, 'settlement_id');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return HasMany<DepositEvidence, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(DepositEvidence::class, 'settlement_line_id');
    }

    /** @return HasMany<DepositClaimResolution, $this> */
    public function claimResolution(): HasMany
    {
        return $this->hasMany(DepositClaimResolution::class, 'held_claim_line_id');
    }
}
