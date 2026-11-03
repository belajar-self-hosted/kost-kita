<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $cash_transaction_id
 * @property int $invoice_id
 * @property string $kind
 * @property int $amount_rupiah
 * @property int|null $reverses_allocation_id
 * @property string $idempotency_key
 * @property CarbonImmutable $created_at
 * @property-read Invoice $invoice
 * @property-read CashTransaction $cashTransaction
 * @property-read InvoiceCashAllocation|null $reversesAllocation
 */
class InvoiceCashAllocation extends Model
{
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'cash_transaction_id', 'invoice_id', 'kind', 'amount_rupiah', 'reverses_allocation_id', 'idempotency_key', 'created_at'];

    protected function casts(): array
    {
        return ['amount_rupiah' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Invoice cash allocations are immutable.'));
        static::deleting(fn () => throw new LogicException('Invoice cash allocations cannot be deleted.'));
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<CashTransaction, $this> */
    public function cashTransaction(): BelongsTo
    {
        return $this->belongsTo(CashTransaction::class);
    }

    /** @return BelongsTo<InvoiceCashAllocation, $this> */
    public function reversesAllocation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_allocation_id');
    }
}
