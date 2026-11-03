<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\MaintenanceJob;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenancy;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoomAvailabilityService
{
    public function lockRoom(int $tenantId, int $propertyId, int $roomId): Room
    {
        Property::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($propertyId)
            ->lockForUpdate()
            ->firstOrFail();

        return Room::query()
            ->where('tenant_id', $tenantId)
            ->where('property_id', $propertyId)
            ->whereKey($roomId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function lockRelatedBlockers(Room $room): void
    {
        Booking::query()
            ->where('tenant_id', $room->tenant_id)
            ->where('property_id', $room->property_id)
            ->where('room_id', $room->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);

        Tenancy::query()
            ->where('tenant_id', $room->tenant_id)
            ->where('property_id', $room->property_id)
            ->where('room_id', $room->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);

        MaintenanceJob::query()
            ->where('tenant_id', $room->tenant_id)
            ->where('property_id', $room->property_id)
            ->where('room_id', $room->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
    }

    public function reconcileExpiredBookingsForRoom(Room $room): int
    {
        return DB::transaction(function () use ($room): int {
            $lockedRoom = $this->lockRoom((int) $room->tenant_id, (int) $room->property_id, (int) $room->id);
            $this->lockRelatedBlockers($lockedRoom);

            return $this->reconcileExpiredBookingsForLockedRoom($lockedRoom);
        }, attempts: 3);
    }

    /**
     * @template TResult
     *
     * @param  callable(Room):TResult  $callback
     * @return TResult
     */
    public function withReconciledRoom(Room $room, callable $callback): mixed
    {
        $validationFailure = null;
        $result = DB::transaction(function () use ($room, $callback, &$validationFailure): mixed {
            $lockedRoom = $this->lockRoom((int) $room->tenant_id, (int) $room->property_id, (int) $room->id);
            $this->lockRelatedBlockers($lockedRoom);
            $this->reconcileExpiredBookingsForLockedRoom($lockedRoom);

            try {
                return $callback($lockedRoom);
            } catch (ValidationException $exception) {
                $validationFailure = $exception;

                return null;
            }
        }, attempts: 3);

        if ($validationFailure instanceof ValidationException) {
            throw $validationFailure;
        }

        return $result;
    }

    public function reconcileExpiredBookingsForLockedRoom(Room $room): int
    {
        $now = now('UTC');
        $expired = Booking::query()
            ->where('tenant_id', $room->tenant_id)
            ->where('property_id', $room->property_id)
            ->where('room_id', $room->id)
            ->where('status', 'confirmed')
            ->whereNotNull('check_in_deadline_at')
            ->where('check_in_deadline_at', '<=', $now)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($expired->isEmpty()) {
            return 0;
        }

        $tenant = Tenant::query()->findOrFail($room->tenant_id);
        $actor = $tenant->ownerUser()->firstOrFail();
        foreach ($expired as $booking) {
            if ($booking->status !== 'confirmed' || ! $booking->check_in_deadline_at?->lessThanOrEqualTo($now)) {
                continue;
            }

            $oldValues = [
                'status' => $booking->status,
                'check_in_deadline_at' => $booking->check_in_deadline_at->toISOString(),
            ];
            $booking->update(['status' => 'no_show', 'no_show_at' => $now]);
            app(AuditLogger::class)->record(
                actor: $actor,
                tenant: $tenant,
                action: 'booking.no_show.expired',
                subjectType: 'booking',
                subjectId: $booking->id,
                oldValues: $oldValues,
                newValues: ['status' => 'no_show', 'no_show_at' => $now->toISOString()],
            );
        }

        return $expired->count();
    }

    /** @return Collection<int, array{type:string,id:int,status:string,start_date:string,end_exclusive:?string,description:string}> */
    public function reconcileAndGetConflicts(Room $room, CarbonImmutable $start, CarbonImmutable $endExclusive): Collection
    {
        return $this->withReconciledRoom(
            $room,
            fn (Room $lockedRoom): Collection => $this->conflicts($lockedRoom, $start, $endExclusive),
        );
    }

    /** @return Collection<int, array{type:string,id:int,status:string,start_date:string,end_exclusive:?string,description:string}> */
    public function conflicts(
        Room $room,
        CarbonImmutable $start,
        CarbonImmutable $endExclusive,
        ?int $ignoreBookingId = null,
        ?int $ignoreTenancyId = null,
        ?int $ignoreMaintenanceJobId = null,
    ): Collection {
        $start = $start->startOfDay();
        $endExclusive = $endExclusive->startOfDay();
        if (! $endExclusive->greaterThan($start)) {
            throw ValidationException::withMessages(['end_exclusive' => 'Tanggal akhir harus setelah tanggal mulai.']);
        }

        $conflicts = collect();
        if ($room->operational_status !== 'rentable') {
            $conflicts->push([
                'type' => 'room_status',
                'id' => $room->id,
                'status' => $room->operational_status,
                'start_date' => $start->toDateString(),
                'end_exclusive' => $endExclusive->toDateString(),
                'description' => 'Status operasional kamar tidak dapat disewakan.',
            ]);
        }

        $now = CarbonImmutable::now('UTC');
        $bookings = Booking::query()
            ->where('tenant_id', $room->tenant_id)
            ->where('property_id', $room->property_id)
            ->where('room_id', $room->id)
            ->where('status', 'confirmed')
            ->when($ignoreBookingId !== null, fn ($query) => $query->whereKeyNot($ignoreBookingId))
            ->whereDate('planned_start_date', '<', $endExclusive->toDateString())
            ->whereDate('planned_end_exclusive', '>', $start->toDateString())
            ->orderBy('id')
            ->get();

        foreach ($bookings as $booking) {
            if ($booking->check_in_deadline_at?->lessThanOrEqualTo($now)) {
                continue;
            }

            $conflicts->push([
                'type' => 'booking',
                'id' => $booking->id,
                'status' => $booking->status,
                'start_date' => $booking->planned_start_date->toDateString(),
                'end_exclusive' => $booking->planned_end_exclusive->toDateString(),
                'description' => 'Booking confirmed bertabrakan dengan periode yang diminta.',
            ]);
        }

        $tenancies = Tenancy::query()
            ->where('tenant_id', $room->tenant_id)
            ->where('property_id', $room->property_id)
            ->where('room_id', $room->id)
            ->whereIn('status', ['scheduled', 'active'])
            ->when($ignoreTenancyId !== null, fn ($query) => $query->whereKeyNot($ignoreTenancyId))
            ->orderBy('id')
            ->get();

        foreach ($tenancies as $tenancy) {
            $tenancyStartDate = $tenancy->status === 'active'
                ? ($tenancy->actual_start_date ?? $tenancy->planned_start_date)
                : $tenancy->planned_start_date;
            $tenancyStart = CarbonImmutable::parse($tenancyStartDate->toDateString(), 'Asia/Jakarta')->startOfDay();
            $tenancyEndExclusive = $tenancy->status === 'active'
                ? ($tenancy->actual_checkout_date === null
                    ? null
                    : CarbonImmutable::parse($tenancy->actual_checkout_date->toDateString(), 'Asia/Jakarta')->addDay()->startOfDay())
                : CarbonImmutable::parse($tenancy->planned_end_exclusive->toDateString(), 'Asia/Jakarta')->startOfDay();
            if ($this->overlaps($tenancyStart, $tenancyEndExclusive, $start, $endExclusive)) {
                $conflicts->push([
                    'type' => 'tenancy',
                    'id' => $tenancy->id,
                    'status' => $tenancy->status,
                    'start_date' => $tenancyStart->toDateString(),
                    'end_exclusive' => $tenancyEndExclusive?->toDateString(),
                    'description' => $tenancy->status === 'active'
                        ? 'Masa sewa masih aktif sampai pemilik mencatat check-out.'
                        : 'Masa sewa terjadwal bertabrakan dengan periode yang diminta.',
                ]);
            }
        }

        $maintenanceJobs = MaintenanceJob::query()
            ->availabilityBlocker()
            ->where('tenant_id', $room->tenant_id)
            ->where('property_id', $room->property_id)
            ->where('room_id', $room->id)
            ->when($ignoreMaintenanceJobId !== null, fn ($query) => $query->whereKeyNot($ignoreMaintenanceJobId))
            ->whereDate('scheduled_start_date', '<', $endExclusive->toDateString())
            ->whereDate('scheduled_end_exclusive', '>', $start->toDateString())
            ->orderBy('id')
            ->get();

        foreach ($maintenanceJobs as $job) {
            $conflicts->push([
                'type' => 'maintenance',
                'id' => $job->id,
                'status' => $job->status,
                'start_date' => $job->scheduled_start_date->toDateString(),
                'end_exclusive' => $job->scheduled_end_exclusive->toDateString(),
                'description' => 'Kamar dijadwalkan untuk maintenance pada periode yang diminta.',
            ]);
        }

        return $conflicts->values();
    }

    public function assertAvailable(Room $room, CarbonImmutable $start, CarbonImmutable $endExclusive): void
    {
        $conflicts = $this->reconcileAndGetConflicts($room, $start, $endExclusive);
        if ($conflicts->isNotEmpty()) {
            throw ValidationException::withMessages([
                'room_id' => 'Kamar tidak tersedia pada rentang tanggal tersebut. Periksa konflik booking, masa sewa, maintenance, atau status kamar.',
            ]);
        }
    }

    private function overlaps(
        CarbonImmutable $existingStart,
        ?CarbonImmutable $existingEndExclusive,
        CarbonImmutable $requestedStart,
        CarbonImmutable $requestedEndExclusive,
    ): bool {
        return $existingStart->lessThan($requestedEndExclusive)
            && ($existingEndExclusive === null || $existingEndExclusive->greaterThan($requestedStart));
    }
}
