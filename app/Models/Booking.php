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
 * @property string $status
 * @property CarbonImmutable $planned_start_date
 * @property CarbonImmutable $planned_end_exclusive
 * @property int $agreed_monthly_rent_rupiah
 * @property CarbonImmutable|null $check_in_deadline_at
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $checked_in_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $no_show_at
 * @property string|null $cancellation_reason
 * @property int $created_by
 */
class Booking extends Model
{
    protected $fillable = [
        'tenant_id', 'property_id', 'room_id', 'resident_id', 'status',
        'planned_start_date', 'planned_end_exclusive', 'agreed_monthly_rent_rupiah',
        'check_in_deadline_at', 'confirmed_at', 'checked_in_at', 'cancelled_at', 'no_show_at',
        'cancellation_reason', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'planned_start_date' => 'immutable_date',
            'planned_end_exclusive' => 'immutable_date',
            'agreed_monthly_rent_rupiah' => 'integer',
            'check_in_deadline_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'checked_in_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'no_show_at' => 'immutable_datetime',
        ];
    }

    /** @param Builder<Booking> $query
     * @return Builder<Booking>
     */
    public function scopeBlocking(Builder $query): Builder
    {
        return $query->where('status', 'confirmed');
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

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<BookingGraceExtension, $this> */
    public function graceExtensions(): HasMany
    {
        return $this->hasMany(BookingGraceExtension::class);
    }

    /** @return HasOne<Tenancy, $this> */
    public function tenancy(): HasOne
    {
        return $this->hasOne(Tenancy::class);
    }

    /** @return HasOne<DepositAccount, $this> */
    public function depositAccount(): HasOne
    {
        return $this->hasOne(DepositAccount::class);
    }
}
