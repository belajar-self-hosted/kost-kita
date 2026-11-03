<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $property_id
 * @property int $monthly_rent_rupiah
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_until_exclusive
 *
 * @method static Builder<RoomPrice> effectiveOn(string|CarbonImmutable $date)
 */
class RoomPrice extends Model
{
    protected $fillable = [
        'tenant_id', 'property_id', 'created_by', 'monthly_rent_rupiah', 'effective_from', 'effective_until_exclusive',
    ];

    protected function casts(): array
    {
        return [
            'monthly_rent_rupiah' => 'integer',
            'effective_from' => 'immutable_date',
            'effective_until_exclusive' => 'immutable_date',
        ];
    }

    /** @param Builder<RoomPrice> $query
     * @return Builder<RoomPrice>
     */
    public function scopeEffectiveOn(Builder $query, string|CarbonImmutable $date): Builder
    {
        $date = $date instanceof CarbonImmutable ? $date->toDateString() : $date;

        return $query->whereDate('effective_from', '<=', $date)
            ->where(function (Builder $query) use ($date): void {
                $query->whereNull('effective_until_exclusive')
                    ->orWhereDate('effective_until_exclusive', '>', $date);
            });
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

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
