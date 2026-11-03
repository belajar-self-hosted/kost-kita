<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $invoice_id
 * @property string $kind
 * @property int $amount_rupiah
 * @property CarbonImmutable|null $proration_start_date
 * @property CarbonImmutable|null $proration_end_exclusive
 * @property int|null $used_days
 * @property int|null $cycle_days
 * @property int|null $monthly_rent_snapshot_rupiah
 * @property CarbonImmutable $created_at
 * @property-read Invoice $invoice
 * @property-read InvoiceAdjustment|null $referenceAdjustment
 * @property-read User|null $creator
 */
class InvoiceAdjustment extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Invoice adjustments are immutable.'));
        static::deleting(fn () => throw new LogicException('Invoice adjustments cannot be deleted.'));
    }

    protected $fillable = [
        'tenant_id', 'invoice_id', 'kind', 'amount_rupiah', 'reason', 'proration_start_date',
        'proration_end_exclusive', 'used_days', 'cycle_days', 'monthly_rent_snapshot_rupiah',
        'reference_adjustment_id', 'idempotency_key', 'created_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_rupiah' => 'integer',
            'proration_start_date' => 'immutable_date',
            'proration_end_exclusive' => 'immutable_date',
            'used_days' => 'integer',
            'cycle_days' => 'integer',
            'monthly_rent_snapshot_rupiah' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<InvoiceAdjustment, $this> */
    public function referenceAdjustment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reference_adjustment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
