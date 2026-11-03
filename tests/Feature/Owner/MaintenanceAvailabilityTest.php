<?php

namespace Tests\Feature\Owner;

use App\Models\Booking;
use App\Models\MaintenanceJob;
use App\Models\Resident;
use App\Models\Tenancy;
use App\Support\MaintenanceScheduleService;
use App\Support\RoomAvailabilityService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\OwnerTestData;
use Tests\TestCase;

class MaintenanceAvailabilityTest extends TestCase
{
    use OwnerTestData;
    use RefreshDatabase;

    public function test_scheduled_blocking_maintenance_releases_availability_when_cancelled_or_completed(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $job = $this->reportedJob($owner->id, $tenant->id, $property->id, $room->id, true);
        $service = app(MaintenanceScheduleService::class);

        $service->schedule($tenant, $owner, $job->id, [
            'room_id' => $room->id,
            'blocks_room' => true,
            'scheduled_start_date' => '2026-11-10',
            'scheduled_end_exclusive' => '2026-11-12',
        ]);
        $conflicts = app(RoomAvailabilityService::class)->conflicts(
            $room,
            CarbonImmutable::parse('2026-11-10', 'Asia/Jakarta'),
            CarbonImmutable::parse('2026-11-12', 'Asia/Jakarta'),
        );
        $this->assertSame('maintenance', $conflicts->first()['type']);

        $service->complete($tenant, $owner, $job->id);
        $this->assertSame(0, app(RoomAvailabilityService::class)->conflicts(
            $room,
            CarbonImmutable::parse('2026-11-10', 'Asia/Jakarta'),
            CarbonImmutable::parse('2026-11-12', 'Asia/Jakarta'),
        )->count());
    }

    public function test_cancelling_a_blocking_schedule_releases_availability_and_nonblocking_schedule_never_blocks(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $service = app(MaintenanceScheduleService::class);
        $blocking = $this->reportedJob($owner->id, $tenant->id, $property->id, $room->id, true);
        $service->schedule($tenant, $owner, $blocking->id, [
            'room_id' => $room->id, 'blocks_room' => true,
            'scheduled_start_date' => '2026-11-10', 'scheduled_end_exclusive' => '2026-11-12',
        ]);
        $service->cancel($tenant, $owner, $blocking->id);

        $this->assertSame(0, app(RoomAvailabilityService::class)->conflicts(
            $room,
            CarbonImmutable::parse('2026-11-10', 'Asia/Jakarta'),
            CarbonImmutable::parse('2026-11-12', 'Asia/Jakarta'),
        )->count());

        $nonblocking = $this->reportedJob($owner->id, $tenant->id, $property->id, $room->id, false);
        $service->schedule($tenant, $owner, $nonblocking->id, [
            'room_id' => $room->id, 'blocks_room' => false,
            'scheduled_start_date' => '2026-11-10', 'scheduled_end_exclusive' => '2026-11-12',
        ]);
        $this->assertSame('scheduled', $nonblocking->fresh()->status);
        $this->assertSame(0, app(RoomAvailabilityService::class)->conflicts(
            $room,
            CarbonImmutable::parse('2026-11-10', 'Asia/Jakarta'),
            CarbonImmutable::parse('2026-11-12', 'Asia/Jakarta'),
        )->count());
    }

    public function test_blocking_maintenance_overlap_with_confirmed_booking_is_rejected_without_schedule(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $resident = Resident::query()->create(['tenant_id' => $tenant->id, 'full_name' => 'Reserved Resident', 'whatsapp_number' => '+628123456789']);
        Booking::query()->create([
            'tenant_id' => $tenant->id, 'property_id' => $property->id, 'room_id' => $room->id, 'resident_id' => $resident->id,
            'status' => 'confirmed', 'planned_start_date' => '2026-11-10', 'planned_end_exclusive' => '2026-12-10',
            'agreed_monthly_rent_rupiah' => 1500000, 'check_in_deadline_at' => Carbon::parse('2026-11-12 00:00:00', 'Asia/Jakarta')->utc(),
            'created_by' => $owner->id,
        ]);
        $job = $this->reportedJob($owner->id, $tenant->id, $property->id, $room->id, true);

        try {
            app(MaintenanceScheduleService::class)->schedule($tenant, $owner, $job->id, [
                'room_id' => $room->id,
                'blocks_room' => true,
                'scheduled_start_date' => '2026-11-15',
                'scheduled_end_exclusive' => '2026-11-20',
            ]);
            $this->fail('Expected maintenance overlapping a confirmed booking to be rejected.');
        } catch (ValidationException) {
            $job->refresh();
            $this->assertSame('reported', $job->status);
            $this->assertNull($job->scheduled_start_date);
            $this->assertNull($job->scheduled_end_exclusive);
            $this->assertCount(1, app(RoomAvailabilityService::class)->conflicts(
                $room,
                CarbonImmutable::parse('2026-11-15', 'Asia/Jakarta'),
                CarbonImmutable::parse('2026-11-20', 'Asia/Jakarta'),
            ));
        }
    }

    public function test_blocking_maintenance_overlap_with_active_tenancy_is_rejected_and_work_stays_reported(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $resident = Resident::query()->create(['tenant_id' => $tenant->id, 'full_name' => 'Current Resident', 'whatsapp_number' => '+628123456789']);
        Tenancy::query()->create([
            'tenant_id' => $tenant->id, 'property_id' => $property->id, 'room_id' => $room->id, 'resident_id' => $resident->id,
            'status' => 'active', 'planned_start_date' => '2026-10-01', 'planned_end_exclusive' => '2026-11-01',
            'actual_start_date' => '2026-10-01', 'actual_check_in_at' => now('UTC'), 'agreed_monthly_rent_rupiah' => 1500000,
            'billing_anchor_day' => 1, 'created_by' => $owner->id,
        ]);
        $job = $this->reportedJob($owner->id, $tenant->id, $property->id, $room->id, true);

        try {
            app(MaintenanceScheduleService::class)->schedule($tenant, $owner, $job->id, [
                'room_id' => $room->id,
                'blocks_room' => true,
                'scheduled_start_date' => '2026-11-02',
                'scheduled_end_exclusive' => '2026-11-04',
            ]);
            $this->fail('Expected maintenance overlapping an active tenancy to be rejected.');
        } catch (ValidationException) {
            $this->assertSame('reported', $job->fresh()->status);
            $this->assertNull($job->fresh()->scheduled_start_date);
        }
    }

    public function test_store_keeps_conflicting_maintenance_reported_without_a_blocking_interval(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $resident = Resident::query()->create(['tenant_id' => $tenant->id, 'full_name' => 'Current Resident', 'whatsapp_number' => '+628123456789']);
        Tenancy::query()->create([
            'tenant_id' => $tenant->id, 'property_id' => $property->id, 'room_id' => $room->id, 'resident_id' => $resident->id,
            'status' => 'active', 'planned_start_date' => '2026-10-01', 'planned_end_exclusive' => '2026-11-01',
            'actual_start_date' => '2026-10-01', 'actual_check_in_at' => now('UTC'), 'agreed_monthly_rent_rupiah' => 1500000,
            'billing_anchor_day' => 1, 'created_by' => $owner->id,
        ]);

        $response = $this->post('/owner/maintenance', [
            'property_id' => $property->id,
            'room_id' => $room->id,
            'category' => 'plumbing',
            'description' => 'Repair sink',
            'blocks_room' => true,
            'scheduled_start_date' => '2026-11-02',
            'scheduled_end_exclusive' => '2026-11-04',
        ]);
        $this->assertContains($response->getStatusCode(), [302, 303], $response->getContent());
        $response->assertSessionHasErrors('scheduled_start_date');

        $this->assertDatabaseHas('maintenance_jobs', [
            'tenant_id' => $tenant->id,
            'room_id' => $room->id,
            'status' => 'reported',
            'blocks_room' => true,
            'scheduled_start_date' => null,
            'scheduled_end_exclusive' => null,
        ]);
    }

    public function test_schedule_rejects_an_archived_room_without_changing_the_report(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $job = $this->reportedJob($owner->id, $tenant->id, $property->id, $room->id, true);
        $room->forceFill(['operational_status' => 'archived', 'archived_at' => now('UTC')])->save();

        try {
            app(MaintenanceScheduleService::class)->schedule($tenant, $owner, $job->id, [
                'room_id' => $room->id,
                'blocks_room' => true,
                'scheduled_start_date' => '2026-11-10',
                'scheduled_end_exclusive' => '2026-11-12',
            ]);
            $this->fail('Expected an archived room to be rejected.');
        } catch (ModelNotFoundException) {
            $this->assertSame('reported', $job->fresh()->status);
            $this->assertNull($job->fresh()->scheduled_start_date);
            $this->assertNull($job->fresh()->scheduled_end_exclusive);
        }
    }

    private function reportedJob(int $ownerId, int $tenantId, int $propertyId, int $roomId, bool $blocksRoom): MaintenanceJob
    {
        return MaintenanceJob::query()->create([
            'tenant_id' => $tenantId,
            'property_id' => $propertyId,
            'room_id' => $roomId,
            'category' => 'plumbing',
            'description' => 'Repair sink',
            'status' => 'reported',
            'blocks_room' => $blocksRoom,
            'created_by' => $ownerId,
        ]);
    }
}
