<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $booking_id
 * @property int|null $tenancy_id
 * @property string $invoice_number
 * @property string $idempotency_key
 * @property CarbonImmutable $issued_at
 * @property CarbonImmutable $due_date
 * @property CarbonImmutable|null $cycle_start_date
 * @property CarbonImmutable|null $cycle_end_exclusive
 * @property int|null $billing_anchor_day
 * @property-read Booking|null $booking
 * @property-read Tenancy|null $tenancy
 * @property-read User|null $creator
 * @property-read Collection<int, InvoiceLine> $lines
 * @property-read Collection<int, InvoiceAdjustment> $adjustments
 * @property-read Collection<int, InvoiceCashAllocation> $cashAllocations
 * @property-read Collection<int, DepositMovement> $depositSettlements
 */
class Invoice extends Model
{
    protected $fillable = [
        'tenant_id', 'booking_id', 'tenancy_id', 'invoice_number', 'idempotency_key', 'issued_at',
        'due_date', 'cycle_start_date', 'cycle_end_exclusive', 'billing_anchor_day', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'immutable_datetime',
            'due_date' => 'immutable_date',
            'cycle_start_date' => 'immutable_date',
            'cycle_end_exclusive' => 'immutable_date',
            'billing_anchor_day' => 'integer',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<Tenancy, $this> */
    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /** @return HasMany<InvoiceAdjustment, $this> */
    public function adjustments(): HasMany
    {
        return $this->hasMany(InvoiceAdjustment::class);
    }

    /** @return HasMany<InvoiceCashAllocation, $this> */
    public function cashAllocations(): HasMany
    {
        return $this->hasMany(InvoiceCashAllocation::class);
    }

    /** @return HasMany<DepositMovement, $this> */
    public function depositSettlements(): HasMany
    {
        return $this->hasMany(DepositMovement::class)->where('kind', 'invoice_settlement');
    }
}
