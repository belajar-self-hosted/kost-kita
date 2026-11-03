<?php

namespace Tests\Feature\Owner;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Resident;
use App\Support\RoomAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OwnerTestData;
use Tests\TestCase;

class BookingAvailabilityTest extends TestCase
{
    use OwnerTestData;
    use RefreshDatabase;

    public function test_pending_booking_does_not_block_but_confirmed_overlap_does(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $resident = Resident::query()->create([
            'tenant_id' => $tenant->id,
            'full_name' => 'Rani Test',
            'whatsapp_number' => '+628123456789',
        ]);
        $booking = Booking::query()->create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'room_id' => $room->id,
            'resident_id' => $resident->id,
            'status' => 'pending',
            'planned_start_date' => '2026-11-10',
            'planned_end_exclusive' => '2026-12-10',
            'agreed_monthly_rent_rupiah' => 1500000,
            'created_by' => $owner->id,
        ]);
        $start = CarbonImmutable::parse('2026-11-10', 'Asia/Jakarta');
        $end = CarbonImmutable::parse('2026-12-10', 'Asia/Jakarta');
        $availability = app(RoomAvailabilityService::class);

        $this->assertCount(0, $availability->conflicts($room, $start, $end));

        $booking->update([
            'status' => 'confirmed',
            'check_in_deadline_at' => CarbonImmutable::parse('2026-12-01 00:00:00', 'Asia/Jakarta')->utc(),
        ]);

        $conflicts = $availability->conflicts($room, $start, $end);
        $this->assertCount(1, $conflicts);
        $this->assertSame('booking', $conflicts->first()['type']);
        $this->assertSame($booking->id, $conflicts->first()['id']);
    }

    public function test_adjacent_confirmed_booking_periods_are_available(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $resident = Resident::query()->create([
            'tenant_id' => $tenant->id,
            'full_name' => 'Rani Test',
            'whatsapp_number' => '+628123456789',
        ]);
        Booking::query()->create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'room_id' => $room->id,
            'resident_id' => $resident->id,
            'status' => 'confirmed',
            'planned_start_date' => '2026-11-10',
            'planned_end_exclusive' => '2026-12-10',
            'agreed_monthly_rent_rupiah' => 1500000,
            'check_in_deadline_at' => CarbonImmutable::parse('2026-12-01 00:00:00', 'Asia/Jakarta')->utc(),
            'created_by' => $owner->id,
        ]);

        $conflicts = app(RoomAvailabilityService::class)->conflicts(
            $room,
            CarbonImmutable::parse('2026-12-10', 'Asia/Jakarta'),
            CarbonImmutable::parse('2027-01-10', 'Asia/Jakarta'),
        );

        $this->assertCount(0, $conflicts);
    }

    public function test_only_one_overlapping_pending_booking_can_be_confirmed(): void
    {
        ['room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00', 'Asia/Jakarta'));

        $first = $this->post('/owner/bookings', $this->bookingInput($room->id, 'Rani Test'));
        $first->assertRedirect();
        $firstBookingId = (int) \DB::table('bookings')->value('id');
        $this->post("/owner/bookings/{$firstBookingId}/confirm")->assertRedirect();
        $this->assertDatabaseHas('bookings', ['id' => $firstBookingId, 'status' => 'confirmed']);
        $deadline = Booking::query()->findOrFail($firstBookingId)->check_in_deadline_at;
        $this->assertSame('2026-11-12 00:00:00', $deadline->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s'));

        $second = $this->post('/owner/bookings', $this->bookingInput($room->id, 'Budi Test'));
        $second->assertRedirect();
        $secondBookingId = (int) \DB::table('bookings')->where('id', '!=', $firstBookingId)->value('id');

        $this->post("/owner/bookings/{$secondBookingId}/confirm")
            ->assertSessionHasErrors('room_id');
        $this->assertDatabaseHas('bookings', ['id' => $secondBookingId, 'status' => 'pending']);
    }

    public function test_confirmed_booking_requires_a_checkin_deadline_in_storage(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $resident = Resident::query()->create([
            'tenant_id' => $tenant->id,
            'full_name' => 'Deadline Resident',
            'whatsapp_number' => '+628123456789',
        ]);

        $this->expectException(QueryException::class);
        Booking::query()->create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'room_id' => $room->id,
            'resident_id' => $resident->id,
            'status' => 'confirmed',
            'planned_start_date' => '2026-11-10',
            'planned_end_exclusive' => '2026-12-10',
            'agreed_monthly_rent_rupiah' => 1500000,
            'created_by' => $owner->id,
        ]);
    }

    public function test_booking_cancellation_persists_reason_and_records_it_in_audit(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $resident = Resident::query()->create([
            'tenant_id' => $tenant->id,
            'full_name' => 'Cancellation Resident',
            'whatsapp_number' => '+628123456789',
        ]);
        $booking = Booking::query()->create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'room_id' => $room->id,
            'resident_id' => $resident->id,
            'status' => 'pending',
            'planned_start_date' => '2026-11-10',
            'planned_end_exclusive' => '2026-12-10',
            'agreed_monthly_rent_rupiah' => 1500000,
            'created_by' => $owner->id,
        ]);
        $reason = 'Penyewa membatalkan sebelum membayar deposit.';

        $this->post("/owner/bookings/{$booking->id}/cancel", [
            'confirmation' => '1',
            'reason' => $reason,
        ])->assertRedirect("/owner/bookings/{$booking->id}");

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'cancelled', 'cancellation_reason' => $reason]);
        $audit = AuditLog::query()->where('action', 'booking.cancelled')->where('subject_id', $booking->id)->firstOrFail();
        $this->assertSame('pending', $audit->old_values['status']);
        $this->assertNull($audit->old_values['cancellation_reason']);
        $this->assertSame('cancelled', $audit->new_values['status']);
        $this->assertSame($reason, $audit->new_values['cancellation_reason']);
    }

    /** @return array<string, mixed> */
    private function bookingInput(int $roomId, string $name): array
    {
        return [
            'room_id' => $roomId,
            'resident' => ['full_name' => $name, 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-10',
            'planned_end_exclusive' => '2026-12-10',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 500000,
            'notes' => null,
        ];
    }
}
