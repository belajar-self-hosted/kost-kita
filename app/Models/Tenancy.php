<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $property_id
 * @property int $room_id
 * @property int $resident_id
 * @property int|null $booking_id
 * @property string $status
 * @property CarbonImmutable $planned_start_date
 * @property CarbonImmutable $planned_end_exclusive
 * @property CarbonImmutable|null $actual_start_date
 * @property CarbonImmutable|null $actual_check_in_at
 * @property CarbonImmutable|null $actual_checkout_date
 * @property CarbonImmutable|null $checkout_recorded_at
 * @property CarbonImmutable|null $terminated_at
 * @property int $agreed_monthly_rent_rupiah
 * @property int|null $billing_anchor_day
 * @property int $created_by
 */
class Tenancy extends Model
{
    protected $fillable = [
        'tenant_id', 'property_id', 'room_id', 'resident_id', 'booking_id', 'status',
        'planned_start_date', 'planned_end_exclusive', 'actual_start_date', 'actual_check_in_at',
        'actual_checkout_date', 'checkout_recorded_at', 'terminated_at', 'agreed_monthly_rent_rupiah', 'billing_anchor_day',
        'payment_terms', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'planned_start_date' => 'immutable_date',
            'planned_end_exclusive' => 'immutable_date',
            'actual_start_date' => 'immutable_date',
            'actual_check_in_at' => 'immutable_datetime',
            'actual_checkout_date' => 'immutable_date',
            'checkout_recorded_at' => 'immutable_datetime',
            'terminated_at' => 'immutable_datetime',
            'agreed_monthly_rent_rupiah' => 'integer',
            'billing_anchor_day' => 'integer',
        ];
    }

    /** @param Builder<Tenancy> $query
     * @return Builder<Tenancy>
     */
    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn('status', ['scheduled', 'active']);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<Resident, $this> */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<TenancyExtension, $this> */
    public function extensions(): HasMany
    {
        return $this->hasMany(TenancyExtension::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return HasOne<DepositAccount, $this> */
    public function depositAccount(): HasOne
    {
        return $this->hasOne(DepositAccount::class);
    }
}
