<?php

namespace Tests\Feature\Owner;

use App\Models\Booking;
use App\Models\MaintenanceJob;
use App\Models\Resident;
use App\Models\Tenancy;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OwnerTestData;
use Tests\TestCase;

class ArchiveAvailabilityTest extends TestCase
{
    use OwnerTestData;
    use RefreshDatabase;

    public function test_completed_checkin_history_does_not_prevent_archiving_room_property_or_resident(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $resident = Resident::query()->create(['tenant_id' => $tenant->id, 'full_name' => 'Checked In Resident', 'whatsapp_number' => '+628123456789']);
        $booking = Booking::query()->create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'room_id' => $room->id,
            'resident_id' => $resident->id,
            'status' => 'checked_in',
            'planned_start_date' => '2026-11-01',
            'planned_end_exclusive' => '2026-12-01',
            'agreed_monthly_rent_rupiah' => 1500000,
            'checked_in_at' => now('UTC'),
            'created_by' => $owner->id,
        ]);
        Tenancy::query()->create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'room_id' => $room->id,
            'resident_id' => $resident->id,
            'booking_id' => $booking->id,
            'status' => 'completed',
            'planned_start_date' => '2026-11-01',
            'planned_end_exclusive' => '2026-12-01',
            'actual_start_date' => '2026-11-01',
            'actual_checkout_date' => '2026-11-30',
            'checkout_recorded_at' => now('UTC'),
            'agreed_monthly_rent_rupiah' => 1500000,
            'billing_anchor_day' => 1,
            'created_by' => $owner->id,
        ]);

        $this->post("/owner/rooms/{$room->id}/archive", ['confirmation' => 'yes'])->assertRedirect();
        $this->assertSame('archived', $room->fresh()->operational_status);
        $this->post("/owner/properties/{$property->id}/archive", ['confirmation' => 'yes'])->assertRedirect();
        $this->assertSame('archived', $property->fresh()->status);
        $this->post("/owner/residents/{$resident->id}/archive", ['confirmation' => 'yes'])->assertRedirect();

        $this->assertNotNull($resident->fresh()->archived_at);
    }

    public function test_room_archive_is_blocked_by_a_scheduled_tenancy(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $resident = Resident::query()->create(['tenant_id' => $tenant->id, 'full_name' => 'Scheduled Resident', 'whatsapp_number' => '+628123456789']);
        Tenancy::query()->create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'room_id' => $room->id,
            'resident_id' => $resident->id,
            'status' => 'scheduled',
            'planned_start_date' => '2026-11-10',
            'planned_end_exclusive' => '2026-12-10',
            'agreed_monthly_rent_rupiah' => 1500000,
            'created_by' => $owner->id,
        ]);

        $this->post("/owner/rooms/{$room->id}/archive", ['confirmation' => 'yes'])
            ->assertSessionHasErrors('archive');
        $this->assertSame('rentable', $room->fresh()->operational_status);
    }

    public function test_property_cannot_be_archived_while_maintenance_is_unresolved(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $room->forceFill(['operational_status' => 'archived', 'archived_at' => now('UTC')])->save();
        MaintenanceJob::query()->create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'room_id' => $room->id,
            'category' => 'plumbing',
            'description' => 'Open work report',
            'status' => 'reported',
            'blocks_room' => false,
            'created_by' => $owner->id,
        ]);

        $response = $this->post("/owner/properties/{$property->id}/archive", ['confirmation' => 'yes']);
        $this->assertContains($response->getStatusCode(), [302, 303], $response->getContent());
        $response->assertSessionHasErrors('archive');

        $this->assertSame('active', $property->fresh()->status);
    }

    public function test_room_archive_reconciles_an_overdue_confirmed_booking_before_checking_blockers(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $booking = $this->expiredBooking($owner->id, $tenant->id, $property->id, $room->id);
        $this->travelTo(Carbon::parse('2026-11-12 00:00:00', 'Asia/Jakarta'));

        $this->post("/owner/rooms/{$room->id}/archive", ['confirmation' => 'yes'])->assertRedirect();

        $this->assertSame('no_show', $booking->fresh()->status);
        $this->assertSame('archived', $room->fresh()->operational_status);
    }

    public function test_out_of_service_update_reconciles_an_overdue_confirmed_booking_before_checking_blockers(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $booking = $this->expiredBooking($owner->id, $tenant->id, $property->id, $room->id);
        $this->travelTo(Carbon::parse('2026-11-12 00:00:00', 'Asia/Jakarta'));

        $this->put("/owner/rooms/{$room->id}", [
            'code' => $room->code,
            'operational_status' => 'out_of_service',
            'facilities' => [],
        ])->assertRedirect();

        $this->assertSame('no_show', $booking->fresh()->status);
        $this->assertSame('out_of_service', $room->fresh()->operational_status);
    }

    public function test_property_archive_reconciles_overdue_bookings_for_its_rooms_in_order(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $booking = $this->expiredBooking($owner->id, $tenant->id, $property->id, $room->id);
        $room->forceFill(['operational_status' => 'archived', 'archived_at' => now('UTC')])->save();
        $this->travelTo(Carbon::parse('2026-11-12 00:00:00', 'Asia/Jakarta'));

        $this->post("/owner/properties/{$property->id}/archive", ['confirmation' => 'yes'])->assertRedirect();

        $this->assertSame('no_show', $booking->fresh()->status);
        $this->assertSame('archived', $property->fresh()->status);
    }

    public function test_resident_archive_does_not_wait_for_an_expired_confirmed_booking(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $booking = $this->expiredBooking($owner->id, $tenant->id, $property->id, $room->id);
        $resident = Resident::query()->findOrFail($booking->resident_id);
        $this->travelTo(Carbon::parse('2026-11-12 00:00:00', 'Asia/Jakarta'));

        $response = $this->post("/owner/residents/{$resident->id}/archive", ['confirmation' => 'yes']);

        $response->assertRedirect('/owner/residents');
        $this->assertNotNull($resident->fresh()->archived_at);
        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    private function expiredBooking(int $ownerId, int $tenantId, int $propertyId, int $roomId): Booking
    {
        $resident = Resident::query()->create([
            'tenant_id' => $tenantId,
            'full_name' => 'Expired Resident',
            'whatsapp_number' => '+628123456789',
        ]);

        return Booking::query()->create([
            'tenant_id' => $tenantId,
            'property_id' => $propertyId,
            'room_id' => $roomId,
            'resident_id' => $resident->id,
            'status' => 'confirmed',
            'planned_start_date' => '2026-11-10',
            'planned_end_exclusive' => '2026-12-10',
            'agreed_monthly_rent_rupiah' => 1500000,
            'check_in_deadline_at' => Carbon::parse('2026-11-12 00:00:00', 'Asia/Jakarta')->utc(),
            'created_by' => $ownerId,
        ]);
    }
}
