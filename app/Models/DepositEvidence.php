<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepositEvidence extends Model
{
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'settlement_line_id', 'claim_resolution_id', 'cash_transaction_id', 'storage_key', 'original_filename', 'mime_type', 'sha256', 'uploaded_by', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<DepositSettlementLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(DepositSettlementLine::class, 'settlement_line_id');
    }

    /** @return BelongsTo<DepositClaimResolution, $this> */
    public function resolution(): BelongsTo
    {
        return $this->belongsTo(DepositClaimResolution::class, 'claim_resolution_id');
    }

    /** @return BelongsTo<CashTransaction, $this> */
    public function cashTransaction(): BelongsTo
    {
        return $this->belongsTo(CashTransaction::class);
    }
}
