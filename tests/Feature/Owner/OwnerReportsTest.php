<?php

namespace Tests\Feature\Owner;

use App\Models\Booking;
use App\Models\CashTransaction;
use App\Models\DepositAccount;
use App\Models\DepositMovement;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\InvoiceCashAllocation;
use App\Models\MaintenanceJob;
use App\Models\Tenancy;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ExpensePaymentService;
use App\Support\InvoiceBalanceService;
use App\Support\InvoicePaymentService;
use App\Support\Reports\CashFlowReportQuery;
use App\Support\Reports\ReportDataset;
use App\Support\Reports\ReportFilters;
use App\Support\Reports\ReportPage;
use App\Support\Reports\ReportQueryService;
use App\Support\TenancyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\OwnerTestData;
use Tests\TestCase;

class OwnerReportsTest extends TestCase
{
    use OwnerTestData;
    use RefreshDatabase;

    public function test_cash_flow_report_classifies_mixed_receipts_refunds_and_only_actual_expense_payments(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Penyewa Laporan', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2027-01-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 300000,
        ]);
        $invoice = Invoice::query()->where('tenancy_id', $tenancy->id)->firstOrFail();
        $deposit = DepositAccount::query()->where('tenancy_id', $tenancy->id)->firstOrFail();

        $receipt = app(InvoicePaymentService::class)->recordReceipt($tenant, $owner, [
            'amount_rupiah' => 1700000,
            'occurred_on' => '2026-11-03',
            'method' => 'bank_transfer',
            'idempotency_key' => 'reports-mixed-receipt-001',
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount_rupiah' => 1400000],
                ['deposit_account_id' => $deposit->id, 'amount_rupiah' => 300000],
            ],
        ]);
        $receiptAllocation = InvoiceCashAllocation::query()
            ->where('tenant_id', $tenant->id)->where('cash_transaction_id', $receipt->id)->firstOrFail();

        $rentRefund = CashTransaction::query()->create([
            'tenant_id' => $tenant->id,
            'direction' => 'out',
            'category' => 'rent_refund',
            'amount_rupiah' => 100000,
            'occurred_on' => '2026-11-30',
            'method' => 'bank_transfer',
            'recorded_by' => $owner->id,
            'idempotency_key' => 'reports-rent-refund-001',
            'reversal_of_id' => $receipt->id,
            'created_at' => now('UTC'),
        ]);
        InvoiceCashAllocation::query()->create([
            'tenant_id' => $tenant->id,
            'cash_transaction_id' => $rentRefund->id,
            'invoice_id' => $invoice->id,
            'kind' => 'reversal',
            'amount_rupiah' => 100000,
            'reverses_allocation_id' => $receiptAllocation->id,
            'idempotency_key' => 'reports-rent-refund-allocation-001',
            'created_at' => now('UTC'),
        ]);
        $depositRefund = CashTransaction::query()->create([
            'tenant_id' => $tenant->id,
            'direction' => 'out',
            'category' => 'deposit_refund',
            'amount_rupiah' => 50000,
            'occurred_on' => '2026-11-30',
            'method' => 'bank_transfer',
            'recorded_by' => $owner->id,
            'idempotency_key' => 'reports-deposit-refund-001',
            'created_at' => now('UTC'),
        ]);
        DepositMovement::query()->create([
            'tenant_id' => $tenant->id,
            'deposit_account_id' => $deposit->id,
            'kind' => 'refund',
            'amount_rupiah' => 50000,
            'cash_transaction_id' => $depositRefund->id,
            'reason' => 'Refund deposit aktual.',
            'actor_user_id' => $owner->id,
            'approved_by' => $owner->id,
            'idempotency_key' => 'reports-deposit-refund-movement-001',
            'created_at' => now('UTC'),
        ]);
        DepositMovement::query()->create([
            'tenant_id' => $tenant->id,
            'deposit_account_id' => $deposit->id,
            'kind' => 'invoice_settlement',
            'amount_rupiah' => 100000,
            'invoice_id' => $invoice->id,
            'reason' => 'Penyelesaian non-kas deposit ke invoice.',
            'actor_user_id' => $owner->id,
            'approved_by' => $owner->id,
            'idempotency_key' => 'reports-noncash-settlement-001',
            'created_at' => now('UTC'),
        ]);

        $this->travelTo(Carbon::parse('2026-11-30 09:00:00', 'Asia/Jakarta'));
        $expense = Expense::query()->create([
            'tenant_id' => $tenant->id,
            'property_id' => $room->property_id,
            'room_id' => $room->id,
            'kind' => 'operational',
            'category' => 'cleaning',
            'description' => 'Kebersihan akhir bulan',
            'amount_due_rupiah' => 100000,
            'incurred_on' => '2026-11-30',
            'created_by' => $owner->id,
        ]);
        app(ExpensePaymentService::class)->recordPayment($tenant, $owner, $expense->id, [
            'amount_rupiah' => 100000,
            'occurred_on' => '2026-11-30',
            'method' => 'cash',
            'idempotency_key' => 'reports-period-end-expense-001',
        ]);
        Expense::query()->create([
            'tenant_id' => $tenant->id,
            'property_id' => $room->property_id,
            'room_id' => $room->id,
            'kind' => 'operational',
            'category' => 'internet',
            'description' => 'Tagihan internet belum dibayar',
            'amount_due_rupiah' => 75000,
            'incurred_on' => '2026-11-30',
            'created_by' => $owner->id,
        ]);

        $this->get('/owner/reports/cash-flow?from=2026-11-01&to=2026-11-30')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Owner/Reports/Show')
            ->where('dataset.summary.cash_in_rupiah', 1700000)
            ->where('dataset.summary.cash_out_rupiah', 250000)
            ->where('dataset.summary.net_cash_flow_rupiah', 1450000)
            ->has('dataset.rows', 5)
            ->where('dataset.rows.0.cash_in_rupiah', 1400000)
            ->where('dataset.rows.1.cash_in_rupiah', 300000)
            ->where('dataset.rows.2.type', 'rent_refund')
            ->where('dataset.rows.2.cash_out_rupiah', 100000)
            ->where('dataset.rows.3.type', 'deposit_refund')
            ->where('dataset.rows.3.cash_out_rupiah', 50000)
            ->where('dataset.rows.4.cash_out_rupiah', 100000)
            ->where('pagination.total', 5));

        $this->assertSame(4, CashTransaction::query()->where('tenant_id', $tenant->id)->count());

        $filters = ReportFilters::fromRequest(Request::create('/owner/reports/cash-flow', 'GET', [
            'from' => '2026-11-01',
            'to' => '2026-11-30',
        ]), $tenant, CashFlowReportQuery::types());
        $preflight = app(ReportQueryService::class)->run('cash-flow', $tenant, $filters, new ReportPage(0, 1, stopAfterLimit: true));
        $this->assertCount(1, $preflight->rows);
        $this->assertSame(1, $preflight->totalRows);
        $this->assertSame(1400000, $preflight->summary['cash_in_rupiah']);
    }

    public function test_invoice_report_reconstructs_outstanding_at_period_end_after_later_payment(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Penyewa Historical', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2027-01-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 0,
        ]);
        $invoice = Invoice::query()->where('tenancy_id', $tenancy->id)->firstOrFail();

        $this->travelTo(Carbon::parse('2026-12-02 09:00:00', 'Asia/Jakarta'));
        app(InvoicePaymentService::class)->recordReceipt($tenant, $owner, [
            'amount_rupiah' => 500000,
            'occurred_on' => '2026-12-01',
            'method' => 'bank_transfer',
            'idempotency_key' => 'reports-later-payment-001',
            'allocations' => [['invoice_id' => $invoice->id, 'amount_rupiah' => 500000]],
        ]);
        InvoiceAdjustment::query()->create([
            'tenant_id' => $tenant->id,
            'invoice_id' => $invoice->id,
            'kind' => 'credit',
            'amount_rupiah' => 200000,
            'reason' => 'Koreksi setelah periode laporan.',
            'idempotency_key' => 'reports-later-credit-001',
            'created_by' => $owner->id,
            'created_at' => now('UTC'),
        ]);

        $this->get('/owner/reports/invoices?from=2026-11-01&to=2026-11-30')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Owner/Reports/Show')
            ->where('dataset.rows.0.outstanding_rupiah', 1500000)
            ->where('dataset.rows.0.status', 'overdue')
            ->where('dataset.summary.outstanding_rupiah', 1500000));
        $this->assertSame(800000, app(InvoiceBalanceService::class)->outstanding($invoice->fresh()));
    }

    public function test_report_defaults_to_month_to_date_and_rejects_foreign_property_filter(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));

        $this->get('/owner/reports/cash-flow')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.from', '2026-11-01')
            ->where('filters.to', '2026-11-03'));

        $foreignOwner = User::factory()->create()->refresh();
        $foreignTenant = Tenant::query()->create(['owner_user_id' => $foreignOwner->id, 'display_name' => 'Tenant Lain']);
        $foreignProperty = $foreignTenant->properties()->create(['name' => 'Properti Rahasia']);
        $this->actingAs($owner)->get('/owner/reports/cash-flow?property_id='.$foreignProperty->id)->assertSessionHasErrors('property_id');
        $foreignRoom = $foreignProperty->rooms()->create(['tenant_id' => $foreignTenant->id, 'code' => 'X-01', 'operational_status' => 'rentable']);
        $this->get('/owner/reports/cash-flow?room_id='.$foreignRoom->id)->assertSessionHasErrors('room_id');
        $otherProperty = $tenant->properties()->create(['name' => 'Properti Kedua']);
        $otherRoom = $otherProperty->rooms()->create(['tenant_id' => $tenant->id, 'code' => 'B-01', 'operational_status' => 'rentable']);
        $this->get('/owner/reports/cash-flow?property_id='.$property->id.'&room_id='.$otherRoom->id)->assertSessionHasErrors('room_id');
        $this->get('/owner/reports/cash-flow?from=2026-11-04&to=2026-11-03')->assertSessionHasErrors('to');
    }

    public function test_report_list_and_booking_report_are_paginated(): void
    {
        ['tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $resident = $tenant->residents()->create(['full_name' => 'Penyewa Banyak', 'whatsapp_number' => '+628123456789']);
        for ($index = 1; $index <= 51; $index++) {
            Booking::query()->create([
                'tenant_id' => $tenant->id,
                'property_id' => $property->id,
                'room_id' => $room->id,
                'resident_id' => $resident->id,
                'status' => 'pending',
                'planned_start_date' => '2026-11-03',
                'planned_end_exclusive' => '2026-12-03',
                'agreed_monthly_rent_rupiah' => 1000000,
                'created_by' => $tenant->owner_user_id,
            ]);
        }
        foreach (['2026-10-31', '2026-12-01'] as $boundaryDate) {
            Booking::query()->create([
                'tenant_id' => $tenant->id,
                'property_id' => $property->id,
                'room_id' => $room->id,
                'resident_id' => $resident->id,
                'status' => 'pending',
                'planned_start_date' => $boundaryDate,
                'planned_end_exclusive' => Carbon::parse($boundaryDate)->addMonth()->toDateString(),
                'agreed_monthly_rent_rupiah' => 1000000,
                'created_by' => $tenant->owner_user_id,
            ]);
        }

        $this->get('/owner/reports')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Owner/Reports/Index')->has('reports', 5));
        $this->get('/owner/reports/bookings?from=2026-11-01&to=2026-11-30')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('dataset.rows', 50)->where('pagination.total', 51)->where('pagination.last_page', 2)
            ->where('dataset.summary.status_pending_count', 51));
        $this->get('/owner/reports/bookings?from=2026-11-01&to=2026-11-30&page=2')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('dataset.rows', 1)->where('pagination.current_page', 2)->where('pagination.total', 51)
            ->where('dataset.summary.booking_count', 51)->where('dataset.summary.monthly_rent_rupiah', 51000000));

        $filters = ReportFilters::fromRequest(Request::create('/owner/reports/bookings', 'GET', [
            'from' => '2026-11-01',
            'to' => '2026-11-30',
        ]), $tenant);
        $preflight = app(ReportQueryService::class)->run('bookings', $tenant, $filters, new ReportPage(0, 2, stopAfterLimit: true));
        $this->assertCount(2, $preflight->rows);
        $this->assertSame(2, $preflight->totalRows);
        $this->assertSame(2, $preflight->summary['booking_count']);
    }

    public function test_tenancy_report_uses_half_open_actual_occupancy_periods(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $occupiedRoom] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $endedRoom = $property->rooms()->create([
            'tenant_id' => $tenant->id,
            'code' => 'A-02',
            'operational_status' => 'rentable',
        ]);

        $occupiedTenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $occupiedRoom->id,
            'resident' => ['full_name' => 'Masih Menghuni', 'whatsapp_number' => '+628111111111'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2026-12-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 0,
        ]);
        $endedTenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $endedRoom->id,
            'resident' => ['full_name' => 'Sudah Check-out', 'whatsapp_number' => '+628122222222'],
            'planned_start_date' => '2026-10-01',
            'planned_end_exclusive' => '2026-12-01',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 0,
        ]);
        $endedTenancy->update(['status' => 'completed', 'actual_checkout_date' => '2026-10-31']);
        for ($index = 1; $index <= 50; $index++) {
            Tenancy::query()->create([
                'tenant_id' => $tenant->id,
                'property_id' => $property->id,
                'room_id' => $occupiedRoom->id,
                'resident_id' => $occupiedTenancy->resident_id,
                'status' => 'active',
                'planned_start_date' => '2026-11-03',
                'planned_end_exclusive' => '2026-12-03',
                'actual_start_date' => '2026-11-03',
                'actual_check_in_at' => now('UTC'),
                'agreed_monthly_rent_rupiah' => 1000,
                'billing_anchor_day' => 3,
                'created_by' => $owner->id,
            ]);
        }

        $this->get('/owner/reports/tenancies?from=2026-11-01&to=2026-11-30')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Owner/Reports/Show')
            ->has('dataset.rows', 50)
            ->where('dataset.rows.0.resident', 'Masih Menghuni')
            ->where('dataset.summary.tenancy_count', 51)
            ->where('dataset.summary.monthly_rent_rupiah', 1550000)
            ->where('pagination.total', 51));
        $this->get('/owner/reports/tenancies?from=2026-11-01&to=2026-11-30&page=2')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('dataset.rows', 1)
            ->where('pagination.current_page', 2)
            ->where('dataset.summary.tenancy_count', 51)
            ->where('dataset.summary.monthly_rent_rupiah', 1550000));
    }

    public function test_maintenance_report_includes_cash_paid_on_the_period_end_date(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $job = MaintenanceJob::query()->create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'room_id' => $room->id,
            'category' => 'plumbing',
            'description' => 'Perbaikan pipa',
            'status' => 'reported',
            'blocks_room' => false,
            'created_by' => $owner->id,
        ]);
        $expense = Expense::query()->create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'room_id' => $room->id,
            'maintenance_job_id' => $job->id,
            'kind' => 'maintenance',
            'category' => 'plumbing',
            'description' => 'Biaya perbaikan pipa',
            'amount_due_rupiah' => 250000,
            'incurred_on' => '2026-11-03',
            'created_by' => $owner->id,
        ]);

        $this->travelTo(Carbon::parse('2026-11-30 09:00:00', 'Asia/Jakarta'));
        app(ExpensePaymentService::class)->recordPayment($tenant, $owner, $expense->id, [
            'amount_rupiah' => 250000,
            'occurred_on' => '2026-11-30',
            'method' => 'bank_transfer',
            'idempotency_key' => 'reports-maintenance-period-end-001',
        ]);
        for ($index = 1; $index <= 50; $index++) {
            MaintenanceJob::query()->create([
                'tenant_id' => $tenant->id,
                'property_id' => $property->id,
                'room_id' => $room->id,
                'category' => 'plumbing',
                'description' => 'Pekerjaan tambahan '.$index,
                'status' => 'reported',
                'blocks_room' => false,
                'created_by' => $owner->id,
            ]);
        }

        $this->get('/owner/reports/maintenance?from=2026-11-03&to=2026-11-30&type=plumbing')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Owner/Reports/Show')
            ->where('dataset.summary.cash_paid_rupiah', 250000)
            ->where('dataset.summary.cash_paid_category_0_rupiah', 250000)
            ->where('dataset.summary_labels.cash_paid_category_0_rupiah', 'Kas maintenance · plumbing')
            ->where('dataset.rows.0.cash_paid_in_period_rupiah', 250000)
            ->has('dataset.rows', 50)
            ->where('dataset.summary.job_count', 51)
            ->where('pagination.total', 51));
        $this->get('/owner/reports/maintenance?from=2026-11-03&to=2026-11-30&type=plumbing&page=2')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('dataset.rows', 1)
            ->where('pagination.current_page', 2)
            ->where('dataset.summary.cash_paid_rupiah', 250000)
            ->where('dataset.summary.cash_paid_category_0_rupiah', 250000)
            ->where('dataset.summary_labels.cash_paid_category_0_rupiah', 'Kas maintenance · plumbing')
            ->where('dataset.summary.job_count', 51));
    }

    public function test_xlsx_and_pdf_exports_use_report_data_and_safe_streamed_files(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Penyewa Ekspor', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2027-01-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 0,
        ]);
        $invoice = Invoice::query()->where('tenancy_id', $tenancy->id)->firstOrFail();
        app(InvoicePaymentService::class)->recordReceipt($tenant, $owner, [
            'amount_rupiah' => 1500000,
            'occurred_on' => '2026-11-03',
            'method' => 'bank_transfer',
            'idempotency_key' => 'reports-export-001',
            'allocations' => [['invoice_id' => $invoice->id, 'amount_rupiah' => 1500000]],
        ]);

        $xlsxResponse = $this->get('/owner/reports/cash-flow/exports/xlsx?from=2026-11-01&to=2026-11-30&property_id='.$room->property_id.'&room_id='.$room->id)->assertOk();
        $this->assertStringContainsString('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $xlsxResponse->headers->get('content-type'));
        $xlsxPath = tempnam(sys_get_temp_dir(), 'kostkita-report-');
        file_put_contents($xlsxPath, $xlsxResponse->streamedContent());
        $sheet = IOFactory::load($xlsxPath)->getActiveSheet();
        $this->assertSame('KostKita · Arus Kas', $sheet->getCell('A1')->getValue());
        $this->assertSame('Filter aktif: Properti: Test Kost · Kamar: Test Kost · A-01 · Dari: 2026-11-01 · Sampai: 2026-11-30', $sheet->getCell('A3')->getValue());
        $this->assertSame(1500000, $sheet->getCell('F5')->getValue());
        @unlink($xlsxPath);

        $pdfResponse = $this->get('/owner/reports/cash-flow/exports/pdf?from=2026-11-01&to=2026-11-30')->assertOk();
        $this->assertSame('application/pdf', $pdfResponse->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF-', $pdfResponse->streamedContent());
    }

    public function test_export_rejects_result_over_the_documented_row_limit_before_rendering(): void
    {
        ['tenant' => $tenant] = $this->createOwnerWithRoom();
        $queries = \Mockery::mock(ReportQueryService::class);
        $queries->shouldReceive('types')->once()->withArgs(fn (string $report, Tenant $resolvedTenant): bool => $report === 'bookings' && $resolvedTenant->id === $tenant->id)->andReturn([]);
        $queries->shouldReceive('statuses')->once()->with('bookings')->andReturn([]);
        $queries->shouldReceive('run')->once()->withArgs(function (string $report, Tenant $resolvedTenant, ReportFilters $filters, ReportPage $page) use ($tenant): bool {
            $this->assertSame('bookings', $report);
            $this->assertSame($tenant->id, $resolvedTenant->id);
            $this->assertSame(5001, $page->limit);
            $this->assertTrue($page->stopAfterLimit);

            return true;
        })->andReturn(new ReportDataset(
            'bookings', 'Laporan Booking', [], [], [], [], [],
            ['from' => '2026-01-01', 'to' => '2026-12-31'], '2026-12-31 23:59:59 WIB', 5001,
        ));
        $this->instance(ReportQueryService::class, $queries);

        $this->get('/owner/reports/bookings/exports/xlsx?from=2026-01-01&to=2026-12-31')
            ->assertStatus(422)
            ->assertSeeText('Hasil laporan melebihi batas 5.000 baris per berkas');
    }

    public function test_report_pages_and_exports_require_owner_authentication(): void
    {
        $this->createOwnerWithRoom();
        Auth::logout();

        foreach (['cash-flow', 'invoices', 'tenancies', 'bookings', 'maintenance'] as $report) {
            $this->get('/owner/reports/'.$report)->assertRedirect('/login');
            foreach (['xlsx', 'pdf'] as $format) {
                $this->get('/owner/reports/'.$report.'/exports/'.$format)->assertRedirect('/login');
            }
        }
    }
}
