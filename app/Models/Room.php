<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $property_id
 * @property int|null $room_type_id
 * @property string $code
 * @property string $operational_status
 * @property CarbonImmutable|null $archived_at
 */
class Room extends Model
{
    protected $fillable = ['tenant_id', 'code', 'code_normalized', 'room_type_id', 'operational_status', 'description', 'archived_at'];

    protected static function booted(): void
    {
        static::saving(function (Room $room): void {
            $room->code = trim($room->code);
            $room->code_normalized = Str::lower($room->code);
        });
    }

    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /** @return HasMany<RoomFacility, $this> */
    public function facilities(): HasMany
    {
        return $this->hasMany(RoomFacility::class);
    }

    /** @return HasMany<RoomPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(RoomPrice::class);
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** @return HasMany<Tenancy, $this> */
    public function tenancies(): HasMany
    {
        return $this->hasMany(Tenancy::class);
    }

    /** @return HasMany<MaintenanceJob, $this> */
    public function maintenanceJobs(): HasMany
    {
        return $this->hasMany(MaintenanceJob::class);
    }
}
