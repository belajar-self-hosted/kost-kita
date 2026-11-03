<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $property_id
 * @property int|null $room_id
 * @property string $category
 * @property string $description
 * @property string $status
 * @property bool $blocks_room
 * @property CarbonImmutable|null $scheduled_start_date
 * @property CarbonImmutable|null $scheduled_end_exclusive
 * @property CarbonImmutable|null $completed_at
 * @property int $created_by
 */
class MaintenanceJob extends Model
{
    protected $fillable = [
        'tenant_id', 'property_id', 'room_id', 'category', 'description', 'status', 'blocks_room',
        'scheduled_start_date', 'scheduled_end_exclusive', 'completed_at', 'vendor_name', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'blocks_room' => 'boolean',
            'scheduled_start_date' => 'immutable_date',
            'scheduled_end_exclusive' => 'immutable_date',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @param Builder<MaintenanceJob> $query
     * @return Builder<MaintenanceJob>
     */
    public function scopeAvailabilityBlocker(Builder $query): Builder
    {
        return $query->where('status', 'scheduled')->where('blocks_room', true);
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

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
