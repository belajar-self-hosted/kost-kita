<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\BookingGraceExtension;
use App\Models\DepositAccount;
use App\Models\Resident;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function __construct(
        private readonly RoomAvailabilityService $availability,
        private readonly RoomPriceService $prices,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string,mixed> $data */
    public function createPending(Tenant $tenant, User $actor, array $data): Booking
    {
        return DB::transaction(function () use ($tenant, $actor, $data): Booking {
            $room = Room::query()
                ->where('tenant_id', $tenant->id)
                ->whereKey($data['room_id'])
                ->firstOrFail();
            $lockedRoom = $this->availability->lockRoom($tenant->id, $room->property_id, $room->id);
            $this->availability->lockRelatedBlockers($lockedRoom);
            $property = $lockedRoom->property()->firstOrFail();
            abort_if($property->status !== 'active' || $lockedRoom->operational_status !== 'rentable', 404);

            $resident = $this->residentFromInput($tenant, $data);
            $plannedStart = CarbonImmutable::parse($data['planned_start_date'], 'Asia/Jakarta')->startOfDay();
            $plannedEnd = CarbonImmutable::parse($data['planned_end_exclusive'], 'Asia/Jakarta')->startOfDay();
            if (! $plannedEnd->greaterThan($plannedStart)) {
                throw ValidationException::withMessages(['planned_end_exclusive' => 'Tanggal akhir harus setelah tanggal mulai.']);
            }

            $catalog = $this->prices->resolveForRoom($lockedRoom, $plannedStart);
            $rent = (int) ($data['agreed_monthly_rent_rupiah'] ?? $catalog['monthly_rent_rupiah'] ?? 0);
            if ($rent < 1) {
                throw ValidationException::withMessages(['agreed_monthly_rent_rupiah' => 'Masukkan harga sewa bulanan yang disepakati.']);
            }
            $requiredDeposit = (int) ($data['required_deposit_rupiah'] ?? 0);
            if ($requiredDeposit < 0) {
                throw ValidationException::withMessages(['required_deposit_rupiah' => 'Nominal deposit tidak boleh negatif.']);
            }

            $booking = Booking::query()->create([
                'tenant_id' => $tenant->id,
                'property_id' => $property->id,
                'room_id' => $lockedRoom->id,
                'resident_id' => $resident->id,
                'status' => 'pending',
                'planned_start_date' => $plannedStart->toDateString(),
                'planned_end_exclusive' => $plannedEnd->toDateString(),
                'agreed_monthly_rent_rupiah' => $rent,
                'notes' => isset($data['notes']) ? trim((string) $data['notes']) : null,
                'created_by' => $actor->id,
            ]);
            DepositAccount::query()->create([
                'tenant_id' => $tenant->id,
                'booking_id' => $booking->id,
                'required_amount_rupiah' => $requiredDeposit,
            ]);

            $this->audit->record(
                actor: $actor,
                tenant: $tenant,
                action: 'booking.created',
                subjectType: 'booking',
                subjectId: $booking->id,
                newValues: [
                    'property_id' => $property->id,
                    'room_id' => $lockedRoom->id,
                    'resident_id' => $resident->id,
                    'status' => 'pending',
                    'planned_start_date' => $plannedStart->toDateString(),
                    'planned_end_exclusive' => $plannedEnd->toDateString(),
                    'agreed_monthly_rent_rupiah' => $rent,
                    'required_deposit_rupiah' => $requiredDeposit,
                ],
            );

            return $booking;
        }, attempts: 3);
    }

    public function confirm(Tenant $tenant, User $actor, int $bookingId): Booking
    {
        $booking = $this->findBooking($tenant, $bookingId);

        return $this->availability->withReconciledRoom($booking->room, function (Room $room) use ($tenant, $actor, $bookingId): Booking {
            $booking = Booking::query()
                ->where('tenant_id', $tenant->id)
                ->where('property_id', $room->property_id)
                ->where('room_id', $room->id)
                ->whereKey($bookingId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($booking->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'Hanya booking pending yang dapat dikonfirmasi.']);
            }
            abort_if($room->operational_status !== 'rentable' || $room->property()->value('status') !== 'active', 404);
            $start = CarbonImmutable::parse($booking->planned_start_date->toDateString(), 'Asia/Jakarta');
            $end = CarbonImmutable::parse($booking->planned_end_exclusive->toDateString(), 'Asia/Jakarta');
            $now = CarbonImmutable::now('Asia/Jakarta');
            $deadline = $start->addDays(2)->startOfDay()->utc();
            if ($deadline->lessThanOrEqualTo($now->utc())) {
                throw ValidationException::withMessages(['status' => 'Batas check-in booking sudah lewat. Buat booking untuk tanggal baru atau catat check-in langsung.']);
            }
            if ($this->availability->conflicts($room, $start, $end, ignoreBookingId: $booking->id)->isNotEmpty()) {
                throw ValidationException::withMessages(['room_id' => 'Kamar sudah memiliki booking, masa sewa, atau maintenance yang bertabrakan.']);
            }

            $oldValues = ['status' => $booking->status, 'check_in_deadline_at' => $booking->check_in_deadline_at?->toISOString()];
            $booking->update([
                'status' => 'confirmed',
                'confirmed_at' => $now->utc(),
                'check_in_deadline_at' => $deadline,
            ]);
            $this->audit->record(
                actor: $actor,
                tenant: $tenant,
                action: 'booking.confirmed',
                subjectType: 'booking',
                subjectId: $booking->id,
                oldValues: $oldValues,
                newValues: ['status' => 'confirmed', 'check_in_deadline_at' => $deadline->toISOString()],
            );

            return $booking->refresh();
        });
    }

    public function cancel(Tenant $tenant, User $actor, int $bookingId, ?string $reason = null): Booking
    {
        $booking = $this->findBooking($tenant, $bookingId);

        return $this->availability->withReconciledRoom($booking->room, function () use ($tenant, $actor, $bookingId, $reason): Booking {
            $booking = Booking::query()->where('tenant_id', $tenant->id)->whereKey($bookingId)->lockForUpdate()->firstOrFail();

            if (! in_array($booking->status, ['pending', 'confirmed'], true)) {
                throw ValidationException::withMessages(['status' => 'Booking ini tidak dapat dibatalkan dari status saat ini.']);
            }

            $oldStatus = $booking->status;
            $oldCancellationReason = $booking->cancellation_reason;
            $cancellationReason = $reason === null || trim($reason) === '' ? null : trim($reason);
            $booking->update([
                'status' => 'cancelled',
                'cancelled_at' => now('UTC'),
                'cancellation_reason' => $cancellationReason,
            ]);
            $this->audit->record(
                actor: $actor, tenant: $tenant, action: 'booking.cancelled', subjectType: 'booking', subjectId: $booking->id,
                oldValues: ['status' => $oldStatus, 'cancellation_reason' => $oldCancellationReason],
                newValues: ['status' => 'cancelled', 'cancellation_reason' => $cancellationReason],
                reason: $cancellationReason,
            );

            return $booking->refresh();
        });
    }

    public function extendGracePeriod(Tenant $tenant, User $actor, int $bookingId, string $newDeadlineValue, string $reason, string $idempotencyKey): Booking
    {
        $booking = $this->findBooking($tenant, $bookingId);
        $newDeadline = CarbonImmutable::parse($newDeadlineValue, 'Asia/Jakarta')->utc();

        return $this->availability->withReconciledRoom($booking->room, function (Room $room) use ($tenant, $actor, $bookingId, $newDeadline, $reason, $idempotencyKey): Booking {
            $booking = Booking::query()->where('tenant_id', $tenant->id)->whereKey($bookingId)->lockForUpdate()->firstOrFail();

            if ($booking->status !== 'confirmed' || $booking->check_in_deadline_at === null) {
                throw ValidationException::withMessages(['status' => 'Hanya booking confirmed yang belum kedaluwarsa dapat diperpanjang.']);
            }

            $existing = BookingGraceExtension::query()
                ->where('tenant_id', $tenant->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                if ($existing->booking_id === $booking->id && $existing->new_deadline_at->equalTo($newDeadline)) {
                    return $booking;
                }
                throw ValidationException::withMessages(['idempotency_key' => 'Kunci permintaan ini sudah digunakan untuk perubahan lain.']);
            }

            $now = CarbonImmutable::now('UTC');
            if ($booking->check_in_deadline_at->lessThanOrEqualTo($now)) {
                throw ValidationException::withMessages(['new_deadline_at' => 'Booking telah kedaluwarsa dan menjadi NO_SHOW.']);
            }
            if (! $newDeadline->greaterThan($booking->check_in_deadline_at)) {
                throw ValidationException::withMessages(['new_deadline_at' => 'Batas baru harus setelah batas saat ini.']);
            }

            $start = CarbonImmutable::parse($booking->planned_start_date->toDateString(), 'Asia/Jakarta');
            $end = CarbonImmutable::parse($booking->planned_end_exclusive->toDateString(), 'Asia/Jakarta');
            if ($this->availability->conflicts($room, $start, $end, ignoreBookingId: $booking->id)->isNotEmpty()) {
                throw ValidationException::withMessages(['new_deadline_at' => 'Perpanjangan akan bertabrakan dengan booking atau masa sewa lain.']);
            }

            $oldDeadline = $booking->check_in_deadline_at;
            $booking->update(['check_in_deadline_at' => $newDeadline]);
            BookingGraceExtension::query()->create([
                'tenant_id' => $tenant->id,
                'booking_id' => $booking->id,
                'old_deadline_at' => $oldDeadline,
                'new_deadline_at' => $newDeadline,
                'reason' => trim($reason),
                'actor_user_id' => $actor->id,
                'idempotency_key' => $idempotencyKey,
                'created_at' => $now,
            ]);
            $this->audit->record(
                actor: $actor, tenant: $tenant, action: 'booking.grace_period_extended', subjectType: 'booking', subjectId: $booking->id,
                oldValues: ['check_in_deadline_at' => $oldDeadline->toISOString()],
                newValues: ['check_in_deadline_at' => $newDeadline->toISOString(), 'reason' => trim($reason)],
            );

            return $booking->refresh();
        });
    }

    public function findBooking(Tenant $tenant, int $bookingId): Booking
    {
        return Booking::query()
            ->where('tenant_id', $tenant->id)
            ->whereKey($bookingId)
            ->with(['room', 'resident'])
            ->firstOrFail();
    }

    /** @param array<string,mixed> $data */
    private function residentFromInput(Tenant $tenant, array $data): Resident
    {
        if (! empty($data['resident_id'])) {
            return Resident::query()
                ->where('tenant_id', $tenant->id)
                ->whereNull('archived_at')
                ->whereKey($data['resident_id'])
                ->lockForUpdate()
                ->firstOrFail();
        }

        return Resident::query()->create([
            'tenant_id' => $tenant->id,
            'full_name' => trim((string) $data['resident']['full_name']),
            'whatsapp_number' => trim((string) $data['resident']['whatsapp_number']),
            'contact_info' => isset($data['resident']['contact_info']) ? trim((string) $data['resident']['contact_info']) : null,
        ]);
    }
}
