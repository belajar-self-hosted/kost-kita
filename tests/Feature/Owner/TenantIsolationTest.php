<?php

namespace Tests\Feature\Owner;

use App\Models\Booking;
use App\Models\Resident;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OwnerTestData;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use OwnerTestData;
    use RefreshDatabase;

    public function test_owner_cannot_view_another_tenants_resident(): void
    {
        $this->createOwnerWithRoom();
        $foreignOwner = User::factory()->create();
        $foreignTenant = Tenant::query()->create(['owner_user_id' => $foreignOwner->id, 'display_name' => 'Other Owner']);
        $foreignResident = Resident::query()->create([
            'tenant_id' => $foreignTenant->id,
            'full_name' => 'Private Resident',
            'whatsapp_number' => '+628123456789',
        ]);

        $response = $this->get("/owner/residents/{$foreignResident->id}");

        $this->assertContains($response->getStatusCode(), [403, 404]);
        $response->assertDontSee('Private Resident');

        $foreignProperty = $foreignTenant->properties()->create(['name' => 'Other Kost']);
        $foreignRoom = $foreignProperty->rooms()->create([
            'tenant_id' => $foreignTenant->id,
            'code' => 'B-01',
            'operational_status' => 'rentable',
        ]);
        $foreignBooking = Booking::query()->create([
            'tenant_id' => $foreignTenant->id,
            'property_id' => $foreignProperty->id,
            'room_id' => $foreignRoom->id,
            'resident_id' => $foreignResident->id,
            'status' => 'pending',
            'planned_start_date' => '2026-11-10',
            'planned_end_exclusive' => '2026-12-10',
            'agreed_monthly_rent_rupiah' => 1500000,
            'created_by' => $foreignOwner->id,
        ]);

        $this->get("/owner/bookings/{$foreignBooking->id}")->assertNotFound();
    }
}
