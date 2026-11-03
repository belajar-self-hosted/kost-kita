<?php

namespace Tests\Feature\Owner;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingGraceExtension;
use App\Models\DepositAccount;
use App\Models\Resident;
use App\Models\Tenancy;
use App\Support\BookingService;
use App\Support\RoomAvailabilityService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\OwnerTestData;
use Tests\TestCase;

class BookingNoShowTest extends TestCase
{
    use OwnerTestData;
    use RefreshDatabase;

    public function test_expired_confirmed_booking_becomes_no_show_once_without_changing_deposit_account(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $resident = Resident::query()->create([
            'tenant_id' => $tenant->id,
            'full_name' => 'Rani Test',
            'whatsapp_number' => '+628123456789',
        ]);
        $deadline = Carbon::parse('2026-11-12 00:00:00', 'Asia/Jakarta')->utc();
        $booking = Booking::query()->create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'room_id' => $room->id,
            'resident_id' => $resident->id,
            'status' => 'confirmed',
            'planned_start_date' => '2026-11-10',
            'planned_end_exclusive' => '2026-12-10',
            'agreed_monthly_rent_rupiah' => 1500000,
            'check_in_deadline_at' => $deadline,
            'confirmed_at' => Carbon::parse('2026-11-09 12:00:00', 'Asia/Jakarta')->utc(),
            'created_by' => $owner->id,
        ]);
        $deposit = DepositAccount::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'required_amount_rupiah' => 500000,
        ]);
        $this->travelTo(Carbon::parse('2026-11-12 00:00:00', 'Asia/Jakarta'));
        $availability = app(RoomAvailabilityService::class);

        Artisan::call('bookings:expire-no-show');
        Artisan::call('bookings:expire-no-show');
        $booking->refresh();
        $this->assertSame('no_show', $booking->status);
        $this->assertNotNull($booking->no_show_at);
        $this->assertSame($deposit->id, $booking->depositAccount()->value('id'));
        $this->assertSame(1, AuditLog::query()->where('action', 'booking.no_show.expired')->count());

        $conflicts = $availability->conflicts(
            $room,
            CarbonImmutable::parse('2026-11-10', 'Asia/Jakarta'),
            CarbonImmutable::parse('2026-12-10', 'Asia/Jakarta'),
        );
        $this->assertCount(0, $conflicts);
        $this->assertSame(0, $availability->reconcileExpiredBookingsForRoom($room));
        $this->assertSame(1, AuditLog::query()->where('action', 'booking.no_show.expired')->count());
    }

    public function test_availability_request_reconciles_a_late_no_show_before_returning_rooms(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $resident = Resident::query()->create([
            'tenant_id' => $tenant->id,
            'full_name' => 'Rani Availability',
            'whatsapp_number' => '+628123456789',
        ]);
        $booking = Booking::query()->create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'room_id' => $room->id,
            'resident_id' => $resident->id,
            'status' => 'confirmed',
            'planned_start_date' => '2026-11-10',
            'planned_end_exclusive' => '2026-12-10',
            'agreed_monthly_rent_rupiah' => 1500000,
            'check_in_deadline_at' => Carbon::parse('2026-11-12 00:00:00', 'Asia/Jakarta')->utc(),
            'created_by' => $owner->id,
        ]);
        $this->travelTo(Carbon::parse('2026-11-12 00:00:00', 'Asia/Jakarta'));

        $response = $this->get('/owner/availability?property_id='.$property->id.'&room_id='.$room->id.'&start_date=2026-11-10&end_exclusive=2026-12-10');
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $this->assertSame('no_show', $booking->fresh()->status);
        $this->assertCount(0, app(RoomAvailabilityService::class)->conflicts(
            $room,
            CarbonImmutable::parse('2026-11-10', 'Asia/Jakarta'),
            CarbonImmutable::parse('2026-12-10', 'Asia/Jakarta'),
        ));
    }

    public function test_booking_remains_confirmed_before_expiry_boundary(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $resident = Resident::query()->create([
            'tenant_id' => $tenant->id,
            'full_name' => 'Rani Test',
            'whatsapp_number' => '+628123456789',
        ]);
        $deadline = Carbon::parse('2026-11-12 00:00:00', 'Asia/Jakarta')->utc();
        $booking = Booking::query()->create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'room_id' => $room->id,
            'resident_id' => $resident->id,
            'status' => 'confirmed',
            'planned_start_date' => '2026-11-10',
            'planned_end_exclusive' => '2026-12-10',
            'agreed_monthly_rent_rupiah' => 1500000,
            'check_in_deadline_at' => $deadline,
            'created_by' => $owner->id,
        ]);
        $this->travelTo(Carbon::parse('2026-11-11 23:59:59', 'Asia/Jakarta'));

        $this->assertSame(0, app(RoomAvailabilityService::class)->reconcileExpiredBookingsForRoom($room));
        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    public function test_pending_booking_cannot_be_confirmed_after_its_default_no_show_deadline(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $resident = Resident::query()->create(['tenant_id' => $tenant->id, 'full_name' => 'Late Booking', 'whatsapp_number' => '+628123456789']);
        $booking = Booking::query()->create([
            'tenant_id' => $tenant->id, 'property_id' => $property->id, 'room_id' => $room->id, 'resident_id' => $resident->id,
            'status' => 'pending', 'planned_start_date' => '2026-11-10', 'planned_end_exclusive' => '2026-12-10',
            'agreed_monthly_rent_rupiah' => 1500000, 'created_by' => $owner->id,
        ]);
        $this->travelTo(Carbon::parse('2026-11-12 00:00:00', 'Asia/Jakarta'));

        try {
            app(BookingService::class)->confirm($tenant, $owner, $booking->id);
            $this->fail('Expected confirmation after the default check-in deadline to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
            $this->assertSame('pending', $booking->fresh()->status);
            $this->assertNull($booking->fresh()->check_in_deadline_at);
        }
    }

    public function test_late_expiry_is_committed_when_confirmation_is_rejected_by_another_active_conflict(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $expiredResident = Resident::query()->create(['tenant_id' => $tenant->id, 'full_name' => 'Expired', 'whatsapp_number' => '+628111111111']);
        $expired = Booking::query()->create([
            'tenant_id' => $tenant->id, 'property_id' => $property->id, 'room_id' => $room->id, 'resident_id' => $expiredResident->id,
            'status' => 'confirmed', 'planned_start_date' => '2026-11-10', 'planned_end_exclusive' => '2026-12-10',
            'agreed_monthly_rent_rupiah' => 1500000,
            'check_in_deadline_at' => Carbon::parse('2026-11-12 00:00:00', 'Asia/Jakarta')->utc(), 'created_by' => $owner->id,
        ]);
        $pendingResident = Resident::query()->create(['tenant_id' => $tenant->id, 'full_name' => 'Pending', 'whatsapp_number' => '+628122222222']);
        $pending = Booking::query()->create([
            'tenant_id' => $tenant->id, 'property_id' => $property->id, 'room_id' => $room->id, 'resident_id' => $pendingResident->id,
            'status' => 'pending', 'planned_start_date' => '2026-11-15', 'planned_end_exclusive' => '2026-12-15',
            'agreed_monthly_rent_rupiah' => 1500000, 'created_by' => $owner->id,
        ]);
        $activeResident = Resident::query()->create(['tenant_id' => $tenant->id, 'full_name' => 'Current', 'whatsapp_number' => '+628133333333']);
        Tenancy::query()->create([
            'tenant_id' => $tenant->id, 'property_id' => $property->id, 'room_id' => $room->id, 'resident_id' => $activeResident->id,
            'status' => 'active', 'planned_start_date' => '2026-11-01', 'planned_end_exclusive' => '2026-12-01',
            'actual_start_date' => '2026-11-01', 'actual_check_in_at' => now('UTC'), 'agreed_monthly_rent_rupiah' => 1500000,
            'billing_anchor_day' => 1, 'created_by' => $owner->id,
        ]);
        $this->travelTo(Carbon::parse('2026-11-12 00:00:00', 'Asia/Jakarta'));

        try {
            app(BookingService::class)->confirm($tenant, $owner, $pending->id);
            $this->fail('Expected the active tenancy conflict to reject confirmation.');
        } catch (ValidationException) {
            $this->assertSame('no_show', $expired->fresh()->status);
            $this->assertNotNull($expired->fresh()->no_show_at);
            $this->assertSame('pending', $pending->fresh()->status);
            $this->assertSame(1, AuditLog::query()->where('action', 'booking.no_show.expired')->count());
        }
    }

    public function test_owner_can_submit_grace_extension_with_the_idempotency_key_from_booking_show(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $resident = Resident::query()->create(['tenant_id' => $tenant->id, 'full_name' => 'Grace Resident', 'whatsapp_number' => '+628123456789']);
        $booking = Booking::query()->create([
            'tenant_id' => $tenant->id, 'property_id' => $property->id, 'room_id' => $room->id, 'resident_id' => $resident->id,
            'status' => 'confirmed', 'planned_start_date' => '2026-11-10', 'planned_end_exclusive' => '2026-12-10',
            'agreed_monthly_rent_rupiah' => 1500000,
            'check_in_deadline_at' => Carbon::parse('2026-11-12 00:00:00', 'Asia/Jakarta')->utc(),
            'created_by' => $owner->id,
        ]);
        $this->travelTo(Carbon::parse('2026-11-10 12:00:00', 'Asia/Jakarta'));

        $this->get("/owner/bookings/{$booking->id}")->assertInertia(fn (Assert $page) => $page
            ->component('Owner/Bookings/Show')
            ->where('grace_extension_idempotency_key', fn ($value): bool => is_string($value) && Str::isUuid($value)));

        $key = (string) Str::uuid();
        $this->post("/owner/bookings/{$booking->id}/extend-grace-period", [
            'new_deadline_at' => '2026-11-15 00:00:00',
            'reason' => 'Koordinasi check-in',
            'idempotency_key' => $key,
        ])->assertRedirect("/owner/bookings/{$booking->id}");

        $this->assertSame(1, BookingGraceExtension::query()->where('booking_id', $booking->id)->where('idempotency_key', $key)->count());
        $this->assertSame('2026-11-15 00:00:00', $booking->fresh()->check_in_deadline_at->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s'));
    }
}
