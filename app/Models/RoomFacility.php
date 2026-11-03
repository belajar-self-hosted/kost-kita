<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class RoomFacility extends Model
{
    protected $fillable = ['tenant_id', 'label', 'normalized_label'];

    protected static function booted(): void
    {
        static::saving(function (RoomFacility $facility): void {
            $facility->label = trim($facility->label);
            $facility->normalized_label = Str::lower($facility->label);
        });
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
