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
 * @property int $line_number
 * @property string $kind
 * @property string $description
 * @property int $amount_rupiah
 * @property int|null $monthly_rent_snapshot_rupiah
 * @property CarbonImmutable|null $service_start_date
 * @property CarbonImmutable|null $service_end_exclusive
 */
class InvoiceLine extends Model
{
    protected $fillable = [
        'tenant_id', 'invoice_id', 'line_number', 'kind', 'description', 'amount_rupiah',
        'monthly_rent_snapshot_rupiah', 'service_start_date', 'service_end_exclusive',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Invoice lines are immutable.'));
        static::deleting(fn () => throw new LogicException('Invoice lines cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'amount_rupiah' => 'integer',
            'monthly_rent_snapshot_rupiah' => 'integer',
            'service_start_date' => 'immutable_date',
            'service_end_exclusive' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
