<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class TemporaryLiability extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Temporary liability amounts are immutable.'));
        static::deleting(fn () => throw new LogicException('Temporary liabilities cannot be deleted.'));
    }

    protected $fillable = ['tenant_id', 'source_cash_transaction_id', 'resident_id', 'booking_id', 'tenancy_id', 'initial_amount_rupiah', 'created_at'];

    protected function casts(): array
    {
        return ['initial_amount_rupiah' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<CashTransaction, $this> */
    public function sourceCashTransaction(): BelongsTo
    {
        return $this->belongsTo(CashTransaction::class, 'source_cash_transaction_id');
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<Tenancy, $this> */
    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    /** @return HasMany<TemporaryLiabilityMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(TemporaryLiabilityMovement::class);
    }
}
