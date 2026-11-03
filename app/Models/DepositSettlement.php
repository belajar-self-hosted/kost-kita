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
 * @property int $deposit_account_id
 * @property string $status
 * @property string $exit_inspection_notes
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $statement_issued_at
 * @property CarbonImmutable $day7_deadline_at
 * @property CarbonImmutable $day30_deadline_at
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $whatsapp_notified_at
 * @property CarbonImmutable|null $finalized_at
 * @property-read DepositAccount $account
 * @property-read Collection<int, DepositSettlementLine> $lines
 * @property-read Collection<int, DepositClaimResolution> $resolutions
 */
class DepositSettlement extends Model
{
    protected $fillable = ['tenant_id', 'deposit_account_id', 'status', 'exit_inspection_notes', 'opened_at', 'statement_issued_at', 'day7_deadline_at', 'day30_deadline_at', 'approved_by', 'approved_at', 'whatsapp_notified_at', 'finalized_at'];

    protected function casts(): array
    {
        return ['opened_at' => 'immutable_datetime', 'statement_issued_at' => 'immutable_datetime', 'day7_deadline_at' => 'immutable_datetime', 'day30_deadline_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime', 'whatsapp_notified_at' => 'immutable_datetime', 'finalized_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<DepositAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(DepositAccount::class, 'deposit_account_id');
    }

    /** @return HasMany<DepositSettlementLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(DepositSettlementLine::class, 'settlement_id');
    }

    /** @return HasMany<DepositClaimResolution, $this> */
    public function resolutions(): HasMany
    {
        return $this->hasMany(DepositClaimResolution::class, 'settlement_id');
    }
}
