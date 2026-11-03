<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $tenancy_id
 * @property CarbonImmutable $old_planned_end_exclusive
 * @property CarbonImmutable $new_planned_end_exclusive
 * @property int $old_monthly_rent_rupiah
 * @property int $new_monthly_rent_rupiah
 * @property CarbonImmutable $effective_from
 */
class TenancyExtension extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'tenancy_id', 'old_planned_end_exclusive', 'new_planned_end_exclusive',
        'old_monthly_rent_rupiah', 'new_monthly_rent_rupiah', 'effective_from', 'actor_user_id', 'reason', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_planned_end_exclusive' => 'immutable_date',
            'new_planned_end_exclusive' => 'immutable_date',
            'old_monthly_rent_rupiah' => 'integer',
            'new_monthly_rent_rupiah' => 'integer',
            'effective_from' => 'immutable_date',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Tenancy, $this> */
    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
