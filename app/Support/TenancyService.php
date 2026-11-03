<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\DepositAccount;
use App\Models\InvoiceAdjustment;
use App\Models\InvoiceLine;
use App\Models\Resident;
use App\Models\Room;
use App\Models\Tenancy;
use App\Models\TenancyExtension;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class TenancyService
{
    public function __construct(
        private readonly RoomAvailabilityService $availability,
        private readonly RoomPriceService $prices,
        private readonly MonthlyBillingCycle $cycles,
        private readonly InitialInvoiceService $invoices,
        private readonly RecurringInvoiceService $recurringInvoices,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string,mixed> $data */
    public function createDirect(Tenant $tenant, User $actor, array $data): Tenancy
    {
        $room = Room::query()->where('tenant_id', $tenant->id)->whereKey($data['room_id'])->firstOrFail();

        return $this->availability->withReconciledRoom($room, function (Room $room) use ($tenant, $actor, $data): Tenancy {
            $property = $room->property()->firstOrFail();
            abort_if($property->status !== 'active' || $room->operational_status !== 'rentable', 404);

            $start = CarbonImmutable::parse($data['planned_start_date'], 'Asia/Jakarta')->startOfDay();
            $end = CarbonImmutable::parse($data['planned_end_exclusive'], 'Asia/Jakarta')->startOfDay();
            $this->assertPeriod($start, $end);
            if ($this->availability->conflicts($room, $start, $end)->isNotEmpty()) {
                throw ValidationException::withMessages(['room_id' => 'Kamar tidak tersedia untuk periode tersebut.']);
            }

            $catalog = $this->prices->resolveForRoom($room, $start);
            $rent = (int) ($data['agreed_monthly_rent_rupiah'] ?? $catalog['monthly_rent_rupiah'] ?? 0);
            if ($rent < 1) {
                throw ValidationException::withMessages(['agreed_monthly_rent_rupiah' => 'Masukkan harga sewa bulanan yang disepakati.']);
            }
            $resident = $this->residentFromInput($tenant, $data);

            $isImmediate = ! $start->isAfter(CarbonImmutable::today('Asia/Jakarta'));
            $tenancy = Tenancy::query()->create([
                'tenant_id' => $tenant->id,
                'property_id' => $property->id,
                'room_id' => $room->id,
                'resident_id' => $resident->id,
                'status' => $isImmediate ? 'active' : 'scheduled',
                'planned_start_date' => $start->toDateString(),
                'planned_end_exclusive' => $end->toDateString(),
                'actual_start_date' => $isImmediate ? $start->toDateString() : null,
                'actual_check_in_at' => $isImmediate ? now('UTC') : null,
                'agreed_monthly_rent_rupiah' => $rent,
                'billing_anchor_day' => $isImmediate ? $start->day : null,
                'payment_terms' => isset($data['payment_terms']) ? trim((string) $data['payment_terms']) : null,
                'notes' => isset($data['notes']) ? trim((string) $data['notes']) : null,
                'created_by' => $actor->id,
            ]);
            DepositAccount::query()->create([
                'tenant_id' => $tenant->id,
                'tenancy_id' => $tenancy->id,
                'required_amount_rupiah' => (int) ($data['required_deposit_rupiah'] ?? 0),
            ]);
            if ($isImmediate) {
                $this->invoices->createForActualCheckIn($tenancy, $actor);
            }

            $this->audit->record(
                actor: $actor,
                tenant: $tenant,
                action: 'tenancy.direct_check_in_created',
                subjectType: 'tenancy',
                subjectId: $tenancy->id,
                newValues: [
                    'property_id' => $property->id,
                    'room_id' => $room->id,
                    'resident_id' => $resident->id,
                    'status' => $tenancy->status,
                    'planned_start_date' => $start->toDateString(),
                    'planned_end_exclusive' => $end->toDateString(),
                    'actual_start_date' => $tenancy->actual_start_date?->toDateString(),
                    'agreed_monthly_rent_rupiah' => $rent,
                ],
            );

            return $tenancy->refresh();
        });
    }

    /** @param array<string,mixed> $data */
    public function checkInFromBooking(Tenant $tenant, User $actor, int $bookingId, array $data): Tenancy
    {
        $booking = Booking::query()
            ->where('tenant_id', $tenant->id)
            ->whereKey($bookingId)
            ->with('room')
            ->firstOrFail();

        return $this->availability->withReconciledRoom($booking->room, function (Room $room) use ($tenant, $actor, $bookingId, $data): Tenancy {
            $booking = Booking::query()->where('tenant_id', $tenant->id)->whereKey($bookingId)->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::now('UTC');

            if ($booking->status !== 'confirmed' || $booking->check_in_deadline_at === null) {
                throw ValidationException::withMessages(['status' => 'Hanya booking confirmed yang belum kedaluwarsa dapat digunakan untuk check-in.']);
            }
            abort_if($room->operational_status !== 'rentable' || $room->property()->value('status') !== 'active', 404);

            $actualStart = CarbonImmutable::parse($data['actual_start_date'] ?? CarbonImmutable::now('Asia/Jakarta')->toDateString(), 'Asia/Jakarta')->startOfDay();
            $plannedEnd = $this->businessDate($booking->planned_end_exclusive);
            $this->assertPeriod($actualStart, $plannedEnd);
            if ($actualStart->greaterThan(CarbonImmutable::today('Asia/Jakarta'))) {
                throw ValidationException::withMessages(['actual_start_date' => 'Tanggal check-in aktual tidak boleh berada di masa depan.']);
            }
            if ($this->availability->conflicts(
                $room,
                $actualStart,
                $plannedEnd,
                ignoreBookingId: $booking->id,
            )->isNotEmpty()) {
                throw ValidationException::withMessages(['room_id' => 'Kamar tidak tersedia untuk check-in pada tanggal tersebut.']);
            }

            $rent = (int) ($data['agreed_monthly_rent_rupiah'] ?? $booking->agreed_monthly_rent_rupiah);
            if ($rent < 1) {
                throw ValidationException::withMessages(['agreed_monthly_rent_rupiah' => 'Harga sewa bulanan harus lebih dari nol.']);
            }
            $tenancy = Tenancy::query()->create([
                'tenant_id' => $tenant->id,
                'property_id' => $booking->property_id,
                'room_id' => $booking->room_id,
                'resident_id' => $booking->resident_id,
                'booking_id' => $booking->id,
                'status' => 'active',
                'planned_start_date' => $booking->planned_start_date->toDateString(),
                'planned_end_exclusive' => $plannedEnd->toDateString(),
                'actual_start_date' => $actualStart->toDateString(),
                'actual_check_in_at' => $now,
                'agreed_monthly_rent_rupiah' => $rent,
                'billing_anchor_day' => $actualStart->day,
                'payment_terms' => isset($data['payment_terms']) ? trim((string) $data['payment_terms']) : null,
                'notes' => isset($data['notes']) ? trim((string) $data['notes']) : null,
                'created_by' => $actor->id,
            ]);
            $deposit = DepositAccount::query()
                ->where('tenant_id', $tenant->id)
                ->where('booking_id', $booking->id)
                ->lockForUpdate()
                ->firstOrFail();
            $deposit->update(['tenancy_id' => $tenancy->id]);
            $booking->update(['status' => 'checked_in', 'checked_in_at' => $now]);
            $this->invoices->createForActualCheckIn($tenancy, $actor);

            $this->audit->record(
                actor: $actor, tenant: $tenant, action: 'booking.checked_in', subjectType: 'booking', subjectId: $booking->id,
                oldValues: ['status' => 'confirmed'], newValues: ['status' => 'checked_in', 'tenancy_id' => $tenancy->id],
            );
            $this->audit->record(
                actor: $actor, tenant: $tenant, action: 'tenancy.created_from_booking', subjectType: 'tenancy', subjectId: $tenancy->id,
                newValues: [
                    'booking_id' => $booking->id,
                    'resident_id' => $booking->resident_id,
                    'room_id' => $booking->room_id,
                    'actual_start_date' => $actualStart->toDateString(),
                    'planned_end_exclusive' => $plannedEnd->toDateString(),
                    'agreed_monthly_rent_rupiah' => $rent,
                    'deposit_account_id' => $deposit->id,
                ],
            );

            return $tenancy;
        });
    }

    /** @param array<string,mixed> $data */
    public function activateScheduled(Tenant $tenant, User $actor, int $tenancyId, array $data): Tenancy
    {
        $record = $this->findTenancy($tenant, $tenancyId);

        return $this->availability->withReconciledRoom($record->room, function (Room $room) use ($tenant, $actor, $tenancyId, $data): Tenancy {
            $tenancy = Tenancy::query()->where('tenant_id', $tenant->id)->whereKey($tenancyId)->lockForUpdate()->firstOrFail();
            if ($tenancy->status !== 'scheduled') {
                throw ValidationException::withMessages(['status' => 'Hanya masa sewa terjadwal yang dapat diaktifkan.']);
            }

            $actualStart = CarbonImmutable::parse($data['actual_start_date'] ?? CarbonImmutable::now('Asia/Jakarta')->toDateString(), 'Asia/Jakarta')->startOfDay();
            $plannedEnd = $this->businessDate($tenancy->planned_end_exclusive);
            $this->assertPeriod($actualStart, $plannedEnd);
            if ($actualStart->greaterThan(CarbonImmutable::today('Asia/Jakarta'))) {
                throw ValidationException::withMessages(['actual_start_date' => 'Tanggal check-in aktual tidak boleh berada di masa depan.']);
            }
            if ($this->availability->conflicts($room, $actualStart, $plannedEnd, ignoreTenancyId: $tenancy->id)->isNotEmpty()) {
                throw ValidationException::withMessages(['room_id' => 'Kamar memiliki konflik jadwal pada tanggal check-in aktual.']);
            }

            $now = CarbonImmutable::now('UTC');
            $oldStatus = $tenancy->status;
            $tenancy->update([
                'status' => 'active',
                'actual_start_date' => $actualStart->toDateString(),
                'actual_check_in_at' => $now,
                'billing_anchor_day' => $actualStart->day,
            ]);
            $this->invoices->createForActualCheckIn($tenancy->refresh(), $actor);
            $this->audit->record(
                actor: $actor, tenant: $tenant, action: 'tenancy.checked_in', subjectType: 'tenancy', subjectId: $tenancy->id,
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => 'active', 'actual_start_date' => $actualStart->toDateString(), 'actual_check_in_at' => $now->toISOString()],
            );

            return $tenancy->refresh();
        });
    }

    public function cancelScheduled(Tenant $tenant, User $actor, int $tenancyId, string $reason): Tenancy
    {
        $record = $this->findTenancy($tenant, $tenancyId);

        return $this->availability->withReconciledRoom($record->room, function () use ($tenant, $actor, $tenancyId, $reason): Tenancy {
            $tenancy = Tenancy::query()->where('tenant_id', $tenant->id)->whereKey($tenancyId)->lockForUpdate()->firstOrFail();
            if ($tenancy->status !== 'scheduled') {
                throw ValidationException::withMessages(['status' => 'Hanya masa sewa terjadwal yang dapat dibatalkan.']);
            }

            $terminatedAt = now('UTC');
            $tenancy->update(['status' => 'terminated', 'terminated_at' => $terminatedAt]);
            $this->audit->record(
                actor: $actor, tenant: $tenant, action: 'tenancy.scheduled_cancelled', subjectType: 'tenancy', subjectId: $tenancy->id,
                oldValues: ['status' => 'scheduled'], newValues: ['status' => 'terminated', 'terminated_at' => $terminatedAt->toISOString(), 'reason' => trim($reason)],
            );

            return $tenancy->refresh();
        });
    }

    /** @param array<string,mixed> $data */
    public function extend(Tenant $tenant, User $actor, int $tenancyId, array $data): Tenancy
    {
        $record = $this->findTenancy($tenant, $tenancyId);

        return $this->availability->withReconciledRoom($record->room, function (Room $room) use ($tenant, $actor, $tenancyId, $data): Tenancy {
            $tenancy = Tenancy::query()->where('tenant_id', $tenant->id)->whereKey($tenancyId)->lockForUpdate()->firstOrFail();
            if ($tenancy->status !== 'active' || $tenancy->actual_start_date === null || $tenancy->billing_anchor_day === null) {
                throw ValidationException::withMessages(['status' => 'Hanya masa sewa aktif yang dapat diperpanjang.']);
            }

            $newEnd = CarbonImmutable::parse($data['new_planned_end_exclusive'], 'Asia/Jakarta')->startOfDay();
            $oldEnd = $this->businessDate($tenancy->planned_end_exclusive);
            if (! $newEnd->greaterThan($oldEnd)) {
                throw ValidationException::withMessages(['new_planned_end_exclusive' => 'Tanggal akhir baru harus setelah tanggal akhir saat ini.']);
            }
            $extensionCheckStart = $oldEnd->greaterThan(CarbonImmutable::today('Asia/Jakarta'))
                ? $oldEnd
                : CarbonImmutable::today('Asia/Jakarta');
            if ($newEnd->greaterThan($extensionCheckStart)
                && $this->availability->conflicts($room, $extensionCheckStart, $newEnd, ignoreTenancyId: $tenancy->id)->isNotEmpty()) {
                throw ValidationException::withMessages(['new_planned_end_exclusive' => 'Perpanjangan bertabrakan dengan booking, masa sewa, atau maintenance lain.']);
            }

            $effectiveFrom = $this->cycles->effectiveStartForDate(
                CarbonImmutable::now('Asia/Jakarta')->toDateString(),
                $tenancy->actual_start_date->toDateString(),
                $tenancy->billing_anchor_day,
            );
            $currentRent = $this->rentForCycleStart($tenancy, $effectiveFrom);
            $catalog = $this->prices->resolveForRoom($room, $effectiveFrom);
            $newRent = (int) ($data['agreed_monthly_rent_rupiah'] ?? $catalog['monthly_rent_rupiah'] ?? $currentRent);
            if ($newRent < 1) {
                throw ValidationException::withMessages(['agreed_monthly_rent_rupiah' => 'Harga sewa bulanan harus lebih dari nol.']);
            }

            $extension = TenancyExtension::query()->create([
                'tenant_id' => $tenant->id,
                'tenancy_id' => $tenancy->id,
                'old_planned_end_exclusive' => $oldEnd->toDateString(),
                'new_planned_end_exclusive' => $newEnd->toDateString(),
                'old_monthly_rent_rupiah' => $currentRent,
                'new_monthly_rent_rupiah' => $newRent,
                'effective_from' => $effectiveFrom,
                'actor_user_id' => $actor->id,
                'reason' => isset($data['reason']) ? trim((string) $data['reason']) : null,
                'created_at' => now('UTC'),
            ]);
            $tenancy->update(['planned_end_exclusive' => $newEnd->toDateString()]);
            $this->audit->record(
                actor: $actor, tenant: $tenant, action: 'tenancy.extended', subjectType: 'tenancy', subjectId: $tenancy->id,
                oldValues: ['planned_end_exclusive' => $oldEnd->toDateString(), 'monthly_rent_rupiah' => $currentRent],
                newValues: [
                    'planned_end_exclusive' => $newEnd->toDateString(),
                    'monthly_rent_rupiah' => $newRent,
                    'effective_from' => $effectiveFrom,
                    'extension_id' => $extension->id,
                ],
            );

            return $tenancy->refresh();
        });
    }

    public function checkOut(Tenant $tenant, User $actor, int $tenancyId, string $lastOccupiedDate): Tenancy
    {
        $record = $this->findTenancy($tenant, $tenancyId);

        return $this->availability->withReconciledRoom($record->room, function () use ($tenant, $actor, $tenancyId, $lastOccupiedDate): Tenancy {
            $tenancy = Tenancy::query()->where('tenant_id', $tenant->id)->whereKey($tenancyId)->lockForUpdate()->firstOrFail();
            if ($tenancy->status !== 'active' || $tenancy->actual_start_date === null) {
                throw ValidationException::withMessages(['status' => 'Hanya masa sewa aktif yang dapat di-check-out.']);
            }

            $checkoutDate = CarbonImmutable::parse($lastOccupiedDate, 'Asia/Jakarta')->startOfDay();
            $actualStart = $this->businessDate($tenancy->actual_start_date);
            $plannedEnd = $this->businessDate($tenancy->planned_end_exclusive);
            if ($checkoutDate->lessThan($actualStart)) {
                throw ValidationException::withMessages(['actual_checkout_date' => 'Tanggal check-out tidak boleh sebelum tanggal check-in aktual.']);
            }
            if ($checkoutDate->greaterThan(CarbonImmutable::today('Asia/Jakarta'))) {
                throw ValidationException::withMessages(['actual_checkout_date' => 'Tanggal check-out tidak boleh berada di masa depan.']);
            }
            $exclusiveEnd = $checkoutDate->addDay();
            $early = $exclusiveEnd->lessThan($plannedEnd);
            $this->createEarlyCheckoutAdjustment($tenant, $actor, $tenancy, $exclusiveEnd);

            $oldStatus = $tenancy->status;
            $tenancy->update([
                'status' => $early ? 'terminated' : 'completed',
                'actual_checkout_date' => $checkoutDate->toDateString(),
                'checkout_recorded_at' => now('UTC'),
            ]);
            $this->audit->record(
                actor: $actor, tenant: $tenant, action: 'tenancy.checked_out', subjectType: 'tenancy', subjectId: $tenancy->id,
                oldValues: ['status' => $oldStatus, 'planned_end_exclusive' => $plannedEnd->toDateString()],
                newValues: [
                    'status' => $tenancy->status,
                    'actual_checkout_date' => $checkoutDate->toDateString(),
                    'exclusive_occupancy_end' => $exclusiveEnd->toDateString(),
                ],
            );

            return $tenancy->refresh();
        });
    }

    public function findTenancy(Tenant $tenant, int $tenancyId): Tenancy
    {
        return Tenancy::query()->where('tenant_id', $tenant->id)->whereKey($tenancyId)->with(['room', 'resident'])->firstOrFail();
    }

    public function rentForCycleStart(Tenancy $tenancy, string $cycleStart): int
    {
        $extension = TenancyExtension::query()
            ->where('tenant_id', $tenancy->tenant_id)
            ->where('tenancy_id', $tenancy->id)
            ->whereDate('effective_from', '<=', $cycleStart)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        return (int) ($extension->new_monthly_rent_rupiah ?? $tenancy->agreed_monthly_rent_rupiah);
    }

    public function currentMonthlyRent(Tenancy $tenancy): int
    {
        if ($tenancy->actual_start_date === null || $tenancy->billing_anchor_day === null) {
            return (int) $tenancy->agreed_monthly_rent_rupiah;
        }

        $cycleStart = $this->cycles->cycleStartForDate(
            CarbonImmutable::today('Asia/Jakarta')->toDateString(),
            $tenancy->actual_start_date->toDateString(),
            $tenancy->billing_anchor_day,
        );

        return $this->rentForCycleStart($tenancy, $cycleStart);
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

        if (empty($data['resident']['full_name']) || empty($data['resident']['whatsapp_number'])) {
            throw ValidationException::withMessages(['resident.full_name' => 'Pilih penyewa atau isi nama dan nomor WhatsApp penyewa.']);
        }

        return Resident::query()->create([
            'tenant_id' => $tenant->id,
            'full_name' => trim((string) $data['resident']['full_name']),
            'whatsapp_number' => trim((string) $data['resident']['whatsapp_number']),
            'contact_info' => isset($data['resident']['contact_info']) ? trim((string) $data['resident']['contact_info']) : null,
        ]);
    }

    private function createEarlyCheckoutAdjustment(Tenant $tenant, User $actor, Tenancy $tenancy, CarbonImmutable $checkoutExclusive): void
    {
        $actualStart = $this->businessDate($tenancy->actual_start_date);
        $checkoutExclusive = $this->businessDate($checkoutExclusive);
        $this->recurringInvoices->generateFor($tenancy, $checkoutExclusive->subDay());
        $line = InvoiceLine::query()
            ->where('tenant_id', $tenant->id)
            ->where('kind', 'rent')
            ->whereHas('invoice', fn ($query) => $query->where('tenant_id', $tenant->id)->where('tenancy_id', $tenancy->id))
            ->whereDate('service_start_date', '<', $checkoutExclusive->toDateString())
            ->whereDate('service_end_exclusive', '>', $checkoutExclusive->toDateString())
            ->orderByDesc('service_start_date')
            ->first();
        if (! $line || $line->monthly_rent_snapshot_rupiah === null) {
            return;
        }

        $cycleStart = $this->businessDate($line->service_start_date);
        $cycleEnd = $this->businessDate($line->service_end_exclusive);
        $usedStart = $actualStart->greaterThan($cycleStart) ? $actualStart : $cycleStart;
        $usedEnd = $checkoutExclusive->lessThan($cycleEnd) ? $checkoutExclusive : $cycleEnd;
        $usedDays = max(0, (int) $usedStart->diffInDays($usedEnd));
        $cycleDays = max(1, (int) $cycleStart->diffInDays($cycleEnd));
        if ($usedDays >= $cycleDays || $usedDays < 1) {
            return;
        }

        $monthlyRent = (int) $line->monthly_rent_snapshot_rupiah;
        $base = intdiv($monthlyRent, $cycleDays);
        $remainder = $monthlyRent % $cycleDays;
        $fractionNumerator = $remainder * $usedDays;
        $prorated = $base * $usedDays + intdiv($fractionNumerator, $cycleDays);
        if (($fractionNumerator % $cycleDays) * 2 >= $cycleDays) {
            $prorated++;
        }
        $credit = (int) $line->amount_rupiah - $prorated;
        if ($credit < 1) {
            return;
        }

        $invoice = $line->invoice()->firstOrFail();
        InvoiceAdjustment::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'idempotency_key' => "checkout:{$tenancy->id}:{$invoice->id}:{$checkoutExclusive->toDateString()}"],
            [
                'invoice_id' => $invoice->id,
                'kind' => 'credit',
                'amount_rupiah' => $credit,
                'reason' => 'Prorata check-out awal berdasarkan hari kalender aktual.',
                'proration_start_date' => $usedStart->toDateString(),
                'proration_end_exclusive' => $usedEnd->toDateString(),
                'used_days' => $usedDays,
                'cycle_days' => $cycleDays,
                'monthly_rent_snapshot_rupiah' => $monthlyRent,
                'created_by' => $actor->id,
                'created_at' => now('UTC'),
            ],
        );
    }

    private function assertPeriod(CarbonImmutable $start, CarbonImmutable $endExclusive): void
    {
        if (! $endExclusive->greaterThan($start)) {
            throw ValidationException::withMessages(['planned_end_exclusive' => 'Tanggal akhir harus setelah tanggal mulai.']);
        }
    }

    private function businessDate(CarbonImmutable $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->toDateString(), 'Asia/Jakarta')->startOfDay();
    }
}
