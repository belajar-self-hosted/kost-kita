<?php

namespace Tests\Feature\Owner;

use App\Models\Booking;
use App\Models\BookingGraceExtension;
use App\Models\DepositAccount;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\Resident;
use App\Models\Tenancy;
use App\Models\TenancyExtension;
use App\Support\BookingService;
use App\Support\InitialInvoiceService;
use App\Support\InvoiceBalanceService;
use App\Support\RoomAvailabilityService;
use App\Support\TenancyService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\OwnerTestData;
use Tests\TestCase;

class TenancyLifecycleTest extends TestCase
{
    use OwnerTestData;
    use RefreshDatabase;

    public function test_future_direct_tenancy_issues_its_first_invoice_once_when_checked_in(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 12:00:00', 'Asia/Jakarta'));

        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, $this->directTenancyInput($room->id, '2026-11-10', '2027-01-10'));

        $this->assertSame('scheduled', $tenancy->status);
        $this->assertNull($tenancy->actual_start_date);
        $this->assertSame(0, Invoice::query()->where('tenancy_id', $tenancy->id)->count());

        $this->travelTo(Carbon::parse('2026-11-10 09:00:00', 'Asia/Jakarta'));
        $active = app(TenancyService::class)->activateScheduled($tenant, $owner, $tenancy->id, ['actual_start_date' => '2026-11-10']);
        $invoice = Invoice::query()->where('tenancy_id', $active->id)->firstOrFail();
        app(InitialInvoiceService::class)->createForActualCheckIn($active, $owner);

        $this->assertSame('active', $active->status);
        $this->assertSame('2026-11-10', $active->actual_start_date->toDateString());
        $this->assertSame(10, $active->billing_anchor_day);
        $this->assertSame('2026-11-10', $invoice->due_date->toDateString());
        $this->assertSame(1, Invoice::query()->where('tenancy_id', $active->id)->count());
    }

    public function test_direct_checkin_for_today_creates_active_tenancy_and_due_invoice_immediately(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));

        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, $this->directTenancyInput($room->id, '2026-11-03', '2026-12-03'));
        $invoice = Invoice::query()->where('tenancy_id', $tenancy->id)->firstOrFail();

        $this->assertSame('active', $tenancy->status);
        $this->assertSame('2026-11-03', $tenancy->actual_start_date->toDateString());
        $this->assertNotNull($tenancy->actual_check_in_at);
        $this->assertSame('2026-11-03', $invoice->due_date->toDateString());
        $this->assertSame($tenancy->id, $tenancy->depositAccount()->value('tenancy_id'));
    }

    public function test_booking_checkin_keeps_its_deposit_account_and_creates_one_invoice(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 12:00:00', 'Asia/Jakarta'));
        $booking = $this->confirmedBooking($owner->id, $tenant->id, $property->id, $room->id, '2026-11-03', '2026-12-03');
        $deposit = DepositAccount::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'required_amount_rupiah' => 500000,
        ]);

        $tenancy = app(TenancyService::class)->checkInFromBooking($tenant, $owner, $booking->id, ['actual_start_date' => '2026-11-03']);
        $invoice = Invoice::query()->where('tenancy_id', $tenancy->id)->firstOrFail();
        app(InitialInvoiceService::class)->createForActualCheckIn($tenancy, $owner);

        $this->assertSame('checked_in', $booking->fresh()->status);
        $this->assertSame(1, DepositAccount::query()->where('tenant_id', $tenant->id)->count());
        $this->assertSame($deposit->id, $tenancy->depositAccount()->value('id'));
        $this->assertSame($booking->id, $tenancy->depositAccount()->value('booking_id'));
        $this->assertSame($tenancy->id, $deposit->fresh()->tenancy_id);
        $this->assertSame(1, Invoice::query()->where('tenancy_id', $tenancy->id)->count());
        $this->assertSame('2026-11-03', $invoice->due_date->toDateString());
    }

    public function test_booking_conversion_rejects_a_future_actual_check_in_date(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 12:00:00', 'Asia/Jakarta'));
        $booking = $this->confirmedBooking($owner->id, $tenant->id, $property->id, $room->id, '2026-11-10', '2026-12-10');
        $deposit = DepositAccount::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'required_amount_rupiah' => 500000,
        ]);

        try {
            app(TenancyService::class)->checkInFromBooking($tenant, $owner, $booking->id, ['actual_start_date' => '2026-11-10']);
            $this->fail('Expected a future actual check-in date to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('actual_start_date', $exception->errors());
            $this->assertSame('confirmed', $booking->fresh()->status);
            $this->assertNull($deposit->fresh()->tenancy_id);
            $this->assertSame(0, Tenancy::query()->where('booking_id', $booking->id)->count());
            $this->assertSame(0, Invoice::query()->count());
        }
    }

    public function test_scheduled_tenancy_cannot_be_activated_with_a_future_actual_check_in_date(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 12:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, $this->directTenancyInput($room->id, '2026-11-10', '2027-01-10'));

        try {
            app(TenancyService::class)->activateScheduled($tenant, $owner, $tenancy->id, ['actual_start_date' => '2026-11-10']);
            $this->fail('Expected scheduled activation with a future actual check-in date to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('actual_start_date', $exception->errors());
            $this->assertSame('scheduled', $tenancy->fresh()->status);
            $this->assertNull($tenancy->fresh()->actual_start_date);
            $this->assertSame(0, Invoice::query()->where('tenancy_id', $tenancy->id)->count());
        }
    }

    public function test_direct_tenancy_cannot_attach_an_archived_resident(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $resident = Resident::query()->create([
            'tenant_id' => $tenant->id,
            'full_name' => 'Archived Resident',
            'whatsapp_number' => '+628123456789',
            'archived_at' => now('UTC'),
        ]);

        try {
            app(TenancyService::class)->createDirect($tenant, $owner, [
                ...$this->directTenancyInput($room->id, '2026-11-03', '2026-12-03'),
                'resident_id' => $resident->id,
            ]);
            $this->fail('Expected an archived resident to be rejected.');
        } catch (ModelNotFoundException) {
            $this->assertSame(0, Tenancy::query()->count());
            $this->assertSame(0, Invoice::query()->count());
        }
    }

    public function test_tenancy_show_prefills_extension_with_effective_cycle_rate_without_changing_original_rate(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-15 12:00:00', 'Asia/Jakarta'));
        $tenancy = $this->activeTenancy($owner->id, $tenant->id, $property->id, $room->id, '2026-10-31', '2027-01-31', '2026-10-31');
        TenancyExtension::query()->create([
            'tenant_id' => $tenant->id,
            'tenancy_id' => $tenancy->id,
            'old_planned_end_exclusive' => '2026-12-31',
            'new_planned_end_exclusive' => '2027-01-31',
            'old_monthly_rent_rupiah' => 1500000,
            'new_monthly_rent_rupiah' => 1750000,
            'effective_from' => '2026-10-31',
            'actor_user_id' => $owner->id,
            'created_at' => now('UTC'),
        ]);
        TenancyExtension::query()->create([
            'tenant_id' => $tenant->id,
            'tenancy_id' => $tenancy->id,
            'old_planned_end_exclusive' => '2027-01-31',
            'new_planned_end_exclusive' => '2027-02-28',
            'old_monthly_rent_rupiah' => 1750000,
            'new_monthly_rent_rupiah' => 2000000,
            'effective_from' => '2026-11-30',
            'actor_user_id' => $owner->id,
            'created_at' => now('UTC'),
        ]);

        $this->get("/owner/tenancies/{$tenancy->id}")->assertInertia(fn (Assert $page) => $page
            ->component('Owner/Tenancies/Show')
            ->where('tenancy.agreed_monthly_rent_rupiah', 1500000)
            ->where('tenancy.current_monthly_rent_rupiah', 1750000));

        $this->assertSame(1500000, $tenancy->fresh()->agreed_monthly_rent_rupiah);
    }

    public function test_tenancy_show_exposes_early_checkout_credit_and_adjusted_invoice_total(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-01 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, $this->directTenancyInput($room->id, '2026-11-01', '2027-01-01', 1500000));
        $invoice = Invoice::query()->where('tenancy_id', $tenancy->id)->firstOrFail();
        $this->travelTo(Carbon::parse('2026-11-10 09:00:00', 'Asia/Jakarta'));
        app(TenancyService::class)->checkOut($tenant, $owner, $tenancy->id, '2026-11-10');

        $this->get("/owner/tenancies/{$tenancy->id}")->assertInertia(fn (Assert $page) => $page
            ->component('Owner/Tenancies/Show')
            ->where('invoices.0.adjustment_label', 'Penyesuaian invoice')
            ->where('invoices.0.adjustments.0.kind', 'credit')
            ->where('invoices.0.adjustments.0.reason', 'Prorata check-out awal berdasarkan hari kalender aktual.')
            ->where('invoices.0.adjustments.0.amount_rupiah', 1000000)
            ->where('invoices.0.adjusted_total_rupiah', 500000));

        $this->assertSame(1, $invoice->adjustments()->count());
    }

    public function test_grace_extension_records_old_new_deadline_and_is_idempotent(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-10 12:00:00', 'Asia/Jakarta'));
        $booking = $this->confirmedBooking($owner->id, $tenant->id, $property->id, $room->id, '2026-11-10', '2026-12-10', '2026-11-12 00:00:00');
        $service = app(BookingService::class);

        $extended = $service->extendGracePeriod($tenant, $owner, $booking->id, '2026-11-15 00:00:00', 'Koordinasi check-in', '206df6ec-5420-4b88-af3e-ae9fefcf0011');
        $again = $service->extendGracePeriod($tenant, $owner, $booking->id, '2026-11-15 00:00:00', 'Koordinasi check-in', '206df6ec-5420-4b88-af3e-ae9fefcf0011');
        $event = BookingGraceExtension::query()->where('booking_id', $booking->id)->firstOrFail();

        $this->assertSame('confirmed', $extended->status);
        $this->assertSame('2026-11-15 00:00:00', $extended->check_in_deadline_at->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s'));
        $this->assertTrue($again->check_in_deadline_at->equalTo($extended->check_in_deadline_at));
        $this->assertSame('2026-11-12 00:00:00', $event->old_deadline_at->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s'));
        $this->assertSame($owner->id, $event->actor_user_id);
        $this->assertSame(1, BookingGraceExtension::query()->where('booking_id', $booking->id)->count());
    }

    public function test_grace_extension_conflict_keeps_booking_confirmed_and_deadline_unchanged(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 12:00:00', 'Asia/Jakarta'));
        $booking = $this->confirmedBooking($owner->id, $tenant->id, $property->id, $room->id, '2026-11-10', '2026-12-10', '2026-11-12 00:00:00');
        $resident = Resident::query()->create(['tenant_id' => $tenant->id, 'full_name' => 'Conflict Resident', 'whatsapp_number' => '+628123456789']);
        Tenancy::query()->create([
            'tenant_id' => $tenant->id, 'property_id' => $property->id, 'room_id' => $room->id, 'resident_id' => $resident->id,
            'status' => 'scheduled', 'planned_start_date' => '2026-11-20', 'planned_end_exclusive' => '2026-11-30',
            'agreed_monthly_rent_rupiah' => 1500000, 'created_by' => $owner->id,
        ]);

        try {
            app(BookingService::class)->extendGracePeriod($tenant, $owner, $booking->id, '2026-11-14 00:00:00', 'Ubah batas', '206df6ec-5420-4b88-af3e-ae9fefcf0012');
            $this->fail('Expected grace extension to reject the schedule conflict.');
        } catch (ValidationException) {
            $this->assertSame('confirmed', $booking->fresh()->status);
            $this->assertSame('2026-11-12 00:00:00', $booking->fresh()->check_in_deadline_at->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s'));
            $this->assertSame(0, BookingGraceExtension::query()->where('booking_id', $booking->id)->count());
        }
    }

    public function test_expired_grace_extension_is_rejected_after_reconciling_no_show(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $booking = $this->confirmedBooking($owner->id, $tenant->id, $property->id, $room->id, '2026-11-10', '2026-12-10', '2026-11-12 00:00:00');
        $this->travelTo(Carbon::parse('2026-11-12 00:00:00', 'Asia/Jakarta'));

        try {
            app(BookingService::class)->extendGracePeriod($tenant, $owner, $booking->id, '2026-11-15 00:00:00', 'Terlambat', '206df6ec-5420-4b88-af3e-ae9fefcf0013');
            $this->fail('Expected expired grace extension to be rejected.');
        } catch (ValidationException) {
            $booking->refresh();
            $this->assertSame('no_show', $booking->status);
            $this->assertSame('2026-11-10', $booking->planned_start_date->toDateString());
            $this->assertSame(0, BookingGraceExtension::query()->where('booking_id', $booking->id)->count());
        }
    }

    public function test_late_checkin_request_reconciles_expired_booking_without_creating_tenancy(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $booking = $this->confirmedBooking($owner->id, $tenant->id, $property->id, $room->id, '2026-11-10', '2026-12-10', '2026-11-12 00:00:00');
        $this->travelTo(Carbon::parse('2026-11-12 00:00:00', 'Asia/Jakarta'));

        try {
            app(TenancyService::class)->checkInFromBooking($tenant, $owner, $booking->id, ['actual_start_date' => '2026-11-12']);
            $this->fail('Expected check-in after expiry to be rejected.');
        } catch (ValidationException) {
            $this->assertSame('no_show', $booking->fresh()->status);
            $this->assertSame(0, Tenancy::query()->where('booking_id', $booking->id)->count());
        }
    }

    public function test_extension_rate_starts_at_the_next_cycle_boundary_when_recorded_mid_cycle(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-15 12:00:00', 'Asia/Jakarta'));
        $tenancy = $this->activeTenancy($owner->id, $tenant->id, $property->id, $room->id, '2026-10-31', '2026-11-30', '2026-10-31');

        app(TenancyService::class)->extend($tenant, $owner, $tenancy->id, [
            'new_planned_end_exclusive' => '2026-12-31', 'agreed_monthly_rent_rupiah' => 2000000,
        ]);
        $extension = TenancyExtension::query()->where('tenancy_id', $tenancy->id)->firstOrFail();

        $this->assertSame('2026-11-30', $extension->effective_from->toDateString());
        $this->assertSame(1500000, $extension->old_monthly_rent_rupiah);
        $this->assertSame(2000000, $extension->new_monthly_rent_rupiah);
    }

    public function test_extension_rate_applies_at_the_current_cycle_boundary(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-30 00:00:00', 'Asia/Jakarta'));
        $tenancy = $this->activeTenancy($owner->id, $tenant->id, $property->id, $room->id, '2026-10-31', '2026-11-30', '2026-10-31');

        app(TenancyService::class)->extend($tenant, $owner, $tenancy->id, [
            'new_planned_end_exclusive' => '2026-12-31', 'agreed_monthly_rent_rupiah' => 2000000,
        ]);
        $extension = TenancyExtension::query()->where('tenancy_id', $tenancy->id)->firstOrFail();

        $this->assertSame('2026-11-30', $extension->effective_from->toDateString());
        $this->assertSame(1500000, $extension->old_monthly_rent_rupiah);
        $this->assertSame(2000000, app(TenancyService::class)->rentForCycleStart($tenancy, '2026-11-30'));
    }

    public function test_early_checkout_uses_actual_leap_february_days_and_exclusive_checkout_boundary(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2028-01-31 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, $this->directTenancyInput($room->id, '2028-01-31', '2028-04-30', 1500001));
        $invoice = Invoice::query()->where('tenancy_id', $tenancy->id)->firstOrFail();

        $this->travelTo(Carbon::parse('2028-02-14 09:00:00', 'Asia/Jakarta'));
        $checkedOut = app(TenancyService::class)->checkOut($tenant, $owner, $tenancy->id, '2028-02-14');
        $adjustment = InvoiceAdjustment::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $conflictsAfterExclusiveBoundary = app(RoomAvailabilityService::class)->conflicts(
            $room,
            CarbonImmutable::parse('2028-02-15', 'Asia/Jakarta'),
            CarbonImmutable::parse('2028-02-16', 'Asia/Jakarta'),
        );

        $this->assertSame('2028-02-14', $checkedOut->actual_checkout_date->toDateString());
        $this->assertSame('terminated', $checkedOut->status);
        $this->assertSame(15, $adjustment->used_days);
        $this->assertSame(29, $adjustment->cycle_days);
        $this->assertSame(724138, $adjustment->amount_rupiah);
        $this->assertSame('2028-02-15', $adjustment->proration_end_exclusive->toDateString());
        $this->assertSame(0, $conflictsAfterExclusiveBoundary->where('type', 'tenancy')->count());
        $this->assertSame(1, Invoice::query()->where('tenancy_id', $tenancy->id)->count());
        $this->assertSame(0, $invoice->adjustments()->where('kind', 'refund')->count());
    }

    public function test_early_checkout_generates_a_missing_current_cycle_invoice_before_prorating(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-01-31 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, $this->directTenancyInput($room->id, '2026-01-31', '2026-04-30', 1500000));

        $this->assertSame(1, Invoice::query()->where('tenancy_id', $tenancy->id)->count());
        $this->travelTo(Carbon::parse('2026-02-28 09:00:00', 'Asia/Jakarta'));
        app(TenancyService::class)->checkOut($tenant, $owner, $tenancy->id, '2026-02-28');

        $februaryInvoice = Invoice::query()->where('tenancy_id', $tenancy->id)->whereDate('cycle_start_date', '2026-02-28')->firstOrFail();
        $adjustment = $februaryInvoice->adjustments()->firstOrFail();
        $this->assertSame('2026-02-28', $februaryInvoice->due_date->toDateString());
        $this->assertSame('2026-03-31', $februaryInvoice->cycle_end_exclusive->toDateString());
        $this->assertSame(31, $februaryInvoice->billing_anchor_day);
        $this->assertSame(1, $adjustment->used_days);
        $this->assertSame(31, $adjustment->cycle_days);
        $this->assertSame(1451613, $adjustment->amount_rupiah);
        $this->assertSame(48387, app(InvoiceBalanceService::class)->outstanding($februaryInvoice));
        $this->assertSame(2, Invoice::query()->where('tenancy_id', $tenancy->id)->count());
    }

    public function test_checkout_date_after_today_is_rejected_without_releasing_the_room(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 12:00:00', 'Asia/Jakarta'));
        $tenancy = $this->activeTenancy($owner->id, $tenant->id, $property->id, $room->id, '2026-10-01', '2026-12-01', '2026-10-01');

        try {
            app(TenancyService::class)->checkOut($tenant, $owner, $tenancy->id, '2026-11-04');
            $this->fail('Expected a future checkout date to be rejected.');
        } catch (ValidationException) {
            $this->assertSame('active', $tenancy->fresh()->status);
            $this->assertNull($tenancy->fresh()->actual_checkout_date);
            $this->assertSame(1, app(RoomAvailabilityService::class)->conflicts(
                $room,
                CarbonImmutable::parse('2026-11-04', 'Asia/Jakarta'),
                CarbonImmutable::parse('2026-11-05', 'Asia/Jakarta'),
            )->where('type', 'tenancy')->count());
        }
    }

    /** @return array<string, mixed> */
    private function directTenancyInput(int $roomId, string $start, string $end, int $rent = 1500000): array
    {
        return [
            'room_id' => $roomId,
            'resident' => ['full_name' => 'Test Resident', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => $start,
            'planned_end_exclusive' => $end,
            'agreed_monthly_rent_rupiah' => $rent,
            'required_deposit_rupiah' => 500000,
        ];
    }

    private function confirmedBooking(int $ownerId, int $tenantId, int $propertyId, int $roomId, string $start, string $end, string $deadline = '2026-11-12 00:00:00'): Booking
    {
        $resident = Resident::query()->create(['tenant_id' => $tenantId, 'full_name' => 'Booking Resident', 'whatsapp_number' => '+628123456789']);

        return Booking::query()->create([
            'tenant_id' => $tenantId,
            'property_id' => $propertyId,
            'room_id' => $roomId,
            'resident_id' => $resident->id,
            'status' => 'confirmed',
            'planned_start_date' => $start,
            'planned_end_exclusive' => $end,
            'agreed_monthly_rent_rupiah' => 1500000,
            'check_in_deadline_at' => Carbon::parse($deadline, 'Asia/Jakarta')->utc(),
            'confirmed_at' => now('UTC'),
            'created_by' => $ownerId,
        ]);
    }

    private function activeTenancy(int $ownerId, int $tenantId, int $propertyId, int $roomId, string $start, string $end, string $actualStart): Tenancy
    {
        $resident = Resident::query()->create(['tenant_id' => $tenantId, 'full_name' => 'Active Resident', 'whatsapp_number' => '+628123456789']);

        return Tenancy::query()->create([
            'tenant_id' => $tenantId,
            'property_id' => $propertyId,
            'room_id' => $roomId,
            'resident_id' => $resident->id,
            'status' => 'active',
            'planned_start_date' => $start,
            'planned_end_exclusive' => $end,
            'actual_start_date' => $actualStart,
            'actual_check_in_at' => now('UTC'),
            'agreed_monthly_rent_rupiah' => 1500000,
            'billing_anchor_day' => (int) Carbon::parse($actualStart)->format('j'),
            'created_by' => $ownerId,
        ]);
    }
}
