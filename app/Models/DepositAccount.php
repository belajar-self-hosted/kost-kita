<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $booking_id
 * @property int|null $tenancy_id
 * @property int $required_amount_rupiah
 * @property-read Booking|null $booking
 * @property-read Tenancy|null $tenancy
 * @property-read Collection<int, DepositMovement> $movements
 * @property-read Collection<int, DepositSettlement> $settlements
 */
class DepositAccount extends Model
{
    protected $fillable = ['tenant_id', 'booking_id', 'tenancy_id', 'required_amount_rupiah'];

    protected function casts(): array
    {
        return ['required_amount_rupiah' => 'integer'];
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

    /** @return HasMany<DepositMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(DepositMovement::class);
    }

    /** @return HasMany<DepositSettlement, $this> */
    public function settlements(): HasMany
    {
        return $this->hasMany(DepositSettlement::class);
    }
}
