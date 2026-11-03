<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $full_name
 * @property string $whatsapp_number
 * @property string|null $contact_info
 * @property string|null $notes
 * @property CarbonImmutable|null $archived_at
 */
class Resident extends Model
{
    protected $fillable = ['tenant_id', 'full_name', 'whatsapp_number', 'contact_info', 'notes', 'archived_at'];

    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
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
}
