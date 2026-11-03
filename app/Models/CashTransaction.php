<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $direction
 * @property string $category
 * @property int $amount_rupiah
 * @property CarbonImmutable $occurred_on
 * @property string $method
 * @property string|null $external_reference
 * @property string|null $description
 * @property int $recorded_by
 * @property string $idempotency_key
 * @property int|null $reversal_of_id
 * @property CarbonImmutable $created_at
 * @property-read Tenant $tenant
 * @property-read User $recorder
 * @property-read CashTransaction|null $reversalOf
 * @property-read Collection<int, InvoiceCashAllocation> $invoiceAllocations
 * @property-read Collection<int, DepositMovement> $depositMovements
 * @property-read Collection<int, ExpensePayment> $expensePayments
 * @property-read Collection<int, TemporaryLiabilityMovement> $temporaryLiabilityMovements
 * @property-read Collection<int, DepositEvidence> $evidence
 */
class CashTransaction extends Model
{
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'direction', 'category', 'amount_rupiah', 'occurred_on', 'method', 'external_reference', 'description', 'recorded_by', 'idempotency_key', 'reversal_of_id', 'created_at'];

    protected function casts(): array
    {
        return ['amount_rupiah' => 'integer', 'occurred_on' => 'immutable_date', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Cash transactions are immutable.'));
        static::deleting(fn () => throw new LogicException('Cash transactions cannot be deleted.'));
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<CashTransaction, $this> */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /** @return HasMany<InvoiceCashAllocation, $this> */
    public function invoiceAllocations(): HasMany
    {
        return $this->hasMany(InvoiceCashAllocation::class);
    }

    /** @return HasMany<DepositMovement, $this> */
    public function depositMovements(): HasMany
    {
        return $this->hasMany(DepositMovement::class);
    }

    /** @return HasMany<ExpensePayment, $this> */
    public function expensePayments(): HasMany
    {
        return $this->hasMany(ExpensePayment::class);
    }

    /** @return HasMany<TemporaryLiabilityMovement, $this> */
    public function temporaryLiabilityMovements(): HasMany
    {
        return $this->hasMany(TemporaryLiabilityMovement::class);
    }

    /** @return HasMany<TemporaryLiability, $this> */
    public function temporaryLiabilities(): HasMany
    {
        return $this->hasMany(TemporaryLiability::class, 'source_cash_transaction_id');
    }

    /** @return HasMany<DepositEvidence, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(DepositEvidence::class);
    }
}
