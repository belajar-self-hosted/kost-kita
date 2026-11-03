<?php

namespace Tests\Feature\Owner;

use App\Models\CashTransaction;
use App\Models\DepositAccount;
use App\Models\DepositMovement;
use App\Models\DepositSettlement;
use App\Models\DepositSettlementLine;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ExpensePaymentService;
use App\Support\InvoicePaymentService;
use App\Support\TenancyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\OwnerTestData;
use Tests\TestCase;

class OwnerDashboardTest extends TestCase
{
    use OwnerTestData;
    use RefreshDatabase;

    public function test_dashboard_shows_current_month_cash_and_tenant_scoped_occupancy(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));

        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Tenant Dashboard', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2026-12-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 300000,
        ]);
        $invoice = Invoice::query()->where('tenancy_id', $tenancy->id)->firstOrFail();
        $deposit = DepositAccount::query()->where('tenancy_id', $tenancy->id)->firstOrFail();

        app(InvoicePaymentService::class)->recordReceipt($tenant, $owner, [
            'amount_rupiah' => 1000000,
            'occurred_on' => '2026-11-03',
            'method' => 'bank_transfer',
            'idempotency_key' => 'dashboard-mixed-receipt:1',
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount_rupiah' => 700000],
                ['deposit_account_id' => $deposit->id, 'amount_rupiah' => 300000],
            ],
        ]);

        $expense = Expense::query()->create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'room_id' => $room->id,
            'kind' => 'maintenance',
            'category' => 'plumbing',
            'description' => 'Perbaikan keran',
            'amount_due_rupiah' => 250000,
            'incurred_on' => '2026-11-03',
            'created_by' => $owner->id,
        ]);
        app(ExpensePaymentService::class)->recordPayment($tenant, $owner, $expense->id, [
            'amount_rupiah' => 250000,
            'occurred_on' => '2026-11-03',
            'method' => 'cash',
            'idempotency_key' => 'dashboard-expense-payment:1',
        ]);

        $this->assertSame(1, CashTransaction::query()->where('tenant_id', $tenant->id)->where('direction', 'in')->count());
        $this->assertSame(1000000, (int) CashTransaction::query()->where('tenant_id', $tenant->id)
            ->whereDate('occurred_on', '>=', '2026-11-01')->whereDate('occurred_on', '<=', '2026-11-03')
            ->where('direction', 'in')->sum('amount_rupiah'));

        $otherOwner = User::factory()->create()->refresh();
        $otherTenant = Tenant::query()->create(['owner_user_id' => $otherOwner->id, 'display_name' => 'Other Owner']);
        CashTransaction::query()->create([
            'tenant_id' => $otherTenant->id,
            'direction' => 'in',
            'category' => 'rent_receipt',
            'amount_rupiah' => 9000000,
            'occurred_on' => '2026-11-03',
            'method' => 'cash',
            'recorded_by' => $otherOwner->id,
            'idempotency_key' => 'other-tenant-dashboard-cash',
            'created_at' => now('UTC'),
        ]);

        $this->get('/owner')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Owner/Dashboard')
            ->where('asOfDate', '2026-11-03')
            ->where('propertyCounts.active', 1)
            ->where('occupancy.capacity', 1)
            ->where('occupancy.occupied', 1)
            ->where('occupancy.available', 0)
            ->where('finance.cashIn', 1000000)
            ->where('finance.cashOut', 250000)
            ->where('finance.netCashFlow', 750000)
            ->where('finance.rentReceived', 700000)
            ->where('finance.depositReceived', 300000)
            ->where('cashFlowTrend.0.month', '2026-06')
            ->where('cashFlowTrend.5.month', '2026-11')
            ->where('cashFlowTrend.5.cashIn', 1000000)
            ->where('cashFlowTrend.5.cashOut', 250000)
            ->where('cashFlowTrend.5.netCashFlow', 750000)
            ->where('finance.maintenancePaid', 250000)
            ->where('invoiceSummary.amount_rupiah', 800000)
            ->where('invoiceSummary.invoice_count', 1));
    }

    public function test_dashboard_separates_due_soon_overdue_and_tenancy_end_reminders(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $firstRoom] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $secondRoom = $property->rooms()->create([
            'tenant_id' => $tenant->id,
            'code' => 'A-02',
            'operational_status' => 'rentable',
        ]);

        $soonTenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $firstRoom->id,
            'resident' => ['full_name' => 'Soon Resident', 'whatsapp_number' => '+628111111111'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2026-12-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 0,
        ]);
        $overdueTenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $secondRoom->id,
            'resident' => ['full_name' => 'Late Resident', 'whatsapp_number' => '+628122222222'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2027-01-03',
            'agreed_monthly_rent_rupiah' => 1700000,
            'required_deposit_rupiah' => 0,
        ]);
        $overdueInvoice = Invoice::query()->where('tenancy_id', $overdueTenancy->id)->firstOrFail();
        $overdueInvoice->update(['due_date' => '2026-11-02']);

        $this->get('/owner')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Owner/Dashboard')
            ->where('reminders.dueSoon.0.resident_name', 'Soon Resident')
            ->where('reminders.dueSoon.0.due_date', '2026-11-03')
            ->where('reminders.overdue.0.resident_name', 'Late Resident')
            ->where('reminders.overdue.0.due_date', '2026-11-02')
            ->where('reminders.tenanciesEnding.0.resident_name', 'Soon Resident')
            ->where('reminders.tenanciesEnding.0.planned_last_day', '2026-12-02')
            ->where('reminders.tenanciesEnding.0.days_remaining', 29));
    }

    public function test_dashboard_shows_only_unpaid_refund_lines_and_unresolved_late_claims_for_this_tenant(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-20 09:00:00', 'Asia/Jakarta'));

        $refundTenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Refund Resident', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2026-12-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 500000,
        ]);
        $refundAccount = DepositAccount::query()->where('tenancy_id', $refundTenancy->id)->firstOrFail();
        $refundSettlement = $this->createSettlement($tenant->id, $refundAccount->id, 'approved', '2026-11-11 00:00:00', '2026-12-04 00:00:00');
        $refundLine = $this->createSettlementLine($tenant->id, $refundSettlement->id, 'refund', 500000, 'refund-line-1');
        $refundCash = CashTransaction::query()->create([
            'tenant_id' => $tenant->id,
            'direction' => 'out',
            'category' => 'deposit_refund',
            'amount_rupiah' => 100000,
            'occurred_on' => '2026-11-12',
            'method' => 'bank_transfer',
            'recorded_by' => $owner->id,
            'idempotency_key' => 'dashboard-refund-partial',
            'created_at' => now('UTC'),
        ]);
        DepositMovement::query()->create([
            'tenant_id' => $tenant->id,
            'deposit_account_id' => $refundAccount->id,
            'kind' => 'refund',
            'amount_rupiah' => 100000,
            'cash_transaction_id' => $refundCash->id,
            'settlement_line_id' => $refundLine->id,
            'actor_user_id' => $owner->id,
            'approved_by' => $owner->id,
            'idempotency_key' => 'dashboard-refund-movement',
            'created_at' => now('UTC'),
        ]);

        $claimRoom = $property->rooms()->create([
            'tenant_id' => $tenant->id,
            'code' => 'A-02',
            'operational_status' => 'rentable',
        ]);
        $claimTenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $claimRoom->id,
            'resident' => ['full_name' => 'Claim Resident', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2026-12-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 200000,
        ]);
        $claimAccount = DepositAccount::query()->where('tenancy_id', $claimTenancy->id)->firstOrFail();
        $claimSettlement = $this->createSettlement($tenant->id, $claimAccount->id, 'approved', '2026-10-20 00:00:00', '2026-11-19 00:00:00');
        $this->createSettlementLine($tenant->id, $claimSettlement->id, 'held_claim', 200000, 'held-claim-line-1');

        $otherOwner = User::factory()->create()->refresh();
        $otherTenant = Tenant::query()->create(['owner_user_id' => $otherOwner->id, 'display_name' => 'Other Owner']);
        $otherProperty = $otherTenant->properties()->create(['name' => 'Other Kost']);
        $otherRoom = $otherProperty->rooms()->create(['tenant_id' => $otherTenant->id, 'code' => 'B-01', 'operational_status' => 'rentable']);
        $otherTenancy = app(TenancyService::class)->createDirect($otherTenant, $otherOwner, [
            'room_id' => $otherRoom->id,
            'resident' => ['full_name' => 'Other Resident', 'whatsapp_number' => '+628199999999'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2026-12-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 100000,
        ]);
        $otherAccount = DepositAccount::query()->where('tenancy_id', $otherTenancy->id)->firstOrFail();
        $otherSettlement = $this->createSettlement($otherTenant->id, $otherAccount->id, 'late', '2026-10-20 00:00:00', '2026-11-19 00:00:00');
        $this->createSettlementLine($otherTenant->id, $otherSettlement->id, 'held_claim', 100000, 'other-held-claim-line');

        $this->get('/owner')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Owner/Dashboard')
            ->where('reminders.depositRefundsPastDeadline.0.resident_name', 'Refund Resident')
            ->where('reminders.depositRefundsPastDeadline.0.balance_rupiah', 400000)
            ->where('reminders.lateDepositClaims.0.resident_name', 'Claim Resident')
            ->where('reminders.lateDepositClaims.0.balance_rupiah', 200000)
            ->has('reminders.lateDepositClaims', 1));
    }

    public function test_dashboard_does_not_mark_refund_late_before_the_local_day7_deadline_ends(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-20 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Belum Terlambat', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2026-12-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 600000,
        ]);
        $account = DepositAccount::query()->where('tenancy_id', $tenancy->id)->firstOrFail();
        $settlement = $this->createSettlement(
            $tenant->id,
            $account->id,
            'approved',
            '2026-11-21 06:59:59.999999',
            '2026-12-14 06:59:59.999999',
        );
        $this->createSettlementLine($tenant->id, $settlement->id, 'refund', 500000, 'refund-before-deadline');
        $this->createSettlementLine($tenant->id, $settlement->id, 'held_claim', 100000, 'claim-before-deadline');

        $this->get('/owner')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Owner/Dashboard')
            ->has('reminders.depositRefundsPastDeadline', 0)
            ->has('reminders.lateDepositClaims', 0));
    }

    private function createSettlement(int $tenantId, int $accountId, string $status, string $day7Deadline, string $day30Deadline): DepositSettlement
    {
        return DepositSettlement::query()->create([
            'tenant_id' => $tenantId,
            'deposit_account_id' => $accountId,
            'status' => $status,
            'exit_inspection_notes' => 'Inspeksi keluar dicatat.',
            'opened_at' => now('UTC')->subDays(40),
            'statement_issued_at' => now('UTC')->subDays(40),
            'day7_deadline_at' => $day7Deadline,
            'day30_deadline_at' => $day30Deadline,
            'approved_by' => $status === 'draft' ? null : auth()->id(),
            'approved_at' => $status === 'draft' ? null : now('UTC')->subDays(39),
            'whatsapp_notified_at' => now('UTC')->subDays(40),
        ]);
    }

    private function createSettlementLine(int $tenantId, int $settlementId, string $kind, int $amount, string $key): DepositSettlementLine
    {
        return DepositSettlementLine::query()->create([
            'tenant_id' => $tenantId,
            'settlement_id' => $settlementId,
            'kind' => $kind,
            'amount_rupiah' => $amount,
            'reason' => 'Penyelesaian deposit yang disetujui.',
            'idempotency_key' => $key,
            'approved_by' => auth()->id(),
            'approved_at' => now('UTC')->subDays(39),
            'created_at' => now('UTC')->subDays(40),
        ]);
    }
}
