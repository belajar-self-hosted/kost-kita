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
 * @property int $property_id
 * @property int|null $room_id
 * @property int|null $maintenance_job_id
 * @property string $kind
 * @property string $category
 * @property string $description
 * @property int $amount_due_rupiah
 * @property CarbonImmutable $incurred_on
 * @property int $created_by
 * @property-read User|null $creator
 * @property-read Property|null $property
 * @property-read Room|null $room
 * @property-read MaintenanceJob|null $maintenanceJob
 * @property-read Collection<int, ExpensePayment> $payments
 */
class Expense extends Model
{
    protected $fillable = ['tenant_id', 'property_id', 'room_id', 'maintenance_job_id', 'kind', 'category', 'description', 'amount_due_rupiah', 'incurred_on', 'created_by'];

    protected function casts(): array
    {
        return ['amount_due_rupiah' => 'integer', 'incurred_on' => 'immutable_date'];
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

    /** @return BelongsTo<MaintenanceJob, $this> */
    public function maintenanceJob(): BelongsTo
    {
        return $this->belongsTo(MaintenanceJob::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ExpensePayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(ExpensePayment::class);
    }

    public function paidAmount(): int
    {
        return (int) $this->payments()->where('kind', 'allocation')->sum('amount_rupiah')
            - (int) $this->payments()->where('kind', 'reversal')->sum('amount_rupiah');
    }

    public function outstandingAmount(): int
    {
        return max(0, (int) $this->amount_due_rupiah - $this->paidAmount());
    }
}
