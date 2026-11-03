<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $booking_id
 * @property CarbonImmutable $old_deadline_at
 * @property CarbonImmutable $new_deadline_at
 * @property string $reason
 * @property int $actor_user_id
 */
class BookingGraceExtension extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'booking_id', 'old_deadline_at', 'new_deadline_at', 'reason', 'actor_user_id',
        'idempotency_key', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_deadline_at' => 'immutable_datetime',
            'new_deadline_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
