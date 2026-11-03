<?php

namespace Tests\Feature\Owner;

use App\Models\Invoice;
use App\Support\TenancyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\OwnerTestData;
use Tests\TestCase;

class RecurringInvoiceGenerationTest extends TestCase
{
    use OwnerTestData;
    use RefreshDatabase;

    public function test_monthly_invoice_command_uses_original_anchor_and_is_idempotent(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-10-31 09:00:00', 'Asia/Jakarta'));

        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Tenant Test', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-10-31',
            'planned_end_exclusive' => '2027-04-30',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 0,
        ]);

        $this->travelTo(Carbon::parse('2026-11-30 09:00:00', 'Asia/Jakarta'));
        Artisan::call('billing:generate-monthly-invoices');
        $novemberInvoice = Invoice::query()->where('tenancy_id', $tenancy->id)->whereDate('cycle_start_date', '2026-11-30')->firstOrFail();
        $this->assertSame('2026-12-31', $novemberInvoice->cycle_end_exclusive->toDateString());

        $this->travelTo(Carbon::parse('2027-01-31 09:00:00', 'Asia/Jakarta'));
        Artisan::call('billing:generate-monthly-invoices');
        Artisan::call('billing:generate-monthly-invoices');

        $this->assertSame(1, Invoice::query()->where('tenancy_id', $tenancy->id)->whereDate('cycle_start_date', '2026-11-30')->count());
        $this->assertSame(1, Invoice::query()->where('tenancy_id', $tenancy->id)->whereDate('cycle_start_date', '2026-12-31')->count());
        $this->assertSame(1, Invoice::query()->where('tenancy_id', $tenancy->id)->whereDate('cycle_start_date', '2027-01-31')->count());
        $this->assertSame(1500000, (int) $novemberInvoice->lines()->value('amount_rupiah'));
    }
}
