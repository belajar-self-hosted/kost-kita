<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class RoomType extends Model
{
    protected $fillable = ['tenant_id', 'name', 'name_normalized', 'description', 'status'];

    protected static function booted(): void
    {
        static::saving(function (RoomType $roomType): void {
            $roomType->name = trim($roomType->name);
            $roomType->name_normalized = Str::lower($roomType->name);
        });
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return HasMany<Room, $this> */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    /** @return HasMany<RoomPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(RoomPrice::class);
    }
}
