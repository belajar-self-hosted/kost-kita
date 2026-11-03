<?php

namespace Tests\Feature\Owner;

use App\Models\CashTransaction;
use App\Models\DepositAccount;
use App\Models\DepositEvidence;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Support\InvoicePaymentService;
use App\Support\TenancyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\OwnerTestData;
use Tests\TestCase;

class FinancialWorkflowPagesTest extends TestCase
{
    use OwnerTestData;
    use RefreshDatabase;

    public function test_invoice_list_shows_derived_status_and_outstanding_balance(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Tenant Finance', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2027-01-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 300000,
        ]);
        $invoice = Invoice::query()->where('tenancy_id', $tenancy->id)->firstOrFail();

        $this->get('/owner/invoices')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Owner/Invoices/Index')
            ->where('invoices.data.0.id', $invoice->id)
            ->where('invoices.data.0.balance_rupiah', 1500000)
            ->where('invoices.data.0.status', 'unpaid'));
    }

    public function test_payment_form_only_lists_this_tenants_open_obligations(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Tenant Finance', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2027-01-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 300000,
        ]);
        $invoice = Invoice::query()->where('tenancy_id', $tenancy->id)->firstOrFail();
        $deposit = DepositAccount::query()->where('tenancy_id', $tenancy->id)->firstOrFail();

        $this->get('/owner/payments/create')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Owner/Payments/Create')
            ->where('invoices.0.id', $invoice->id)
            ->where('invoices.0.balance_rupiah', 1500000)
            ->where('deposits.0.id', $deposit->id)
            ->where('deposits.0.balance_rupiah', 0));
    }

    public function test_expense_list_is_available_to_owner_and_records_unpaid_obligations(): void
    {
        ['tenant' => $tenant, 'property' => $property] = $this->createOwnerWithRoom();

        $this->post('/owner/expenses', [
            'property_id' => $property->id,
            'kind' => 'operational',
            'category' => 'internet',
            'description' => 'Tagihan internet November',
            'amount_due_rupiah' => 350000,
            'incurred_on' => '2026-11-01',
        ])->assertRedirect();

        $this->get('/owner/expenses')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Owner/Expenses/Index')
            ->where('expenses.data.0.amount_due_rupiah', 350000)
            ->where('expenses.data.0.paid_rupiah', 0)
            ->where('expenses.data.0.outstanding_rupiah', 350000));
    }

    public function test_owner_can_record_a_mixed_receipt_and_open_its_receipt_detail(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Tenant Finance', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2027-01-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 300000,
        ]);
        $invoice = Invoice::query()->where('tenancy_id', $tenancy->id)->firstOrFail();
        $deposit = DepositAccount::query()->where('tenancy_id', $tenancy->id)->firstOrFail();

        $response = $this->post('/owner/payments', [
            'amount_rupiah' => 1800000,
            'occurred_on' => '2026-11-03',
            'method' => 'bank_transfer',
            'external_reference' => 'TRX-PAGE-001',
            'idempotency_key' => 'c9422667-6106-4c7d-9ce8-c056142e24e5',
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount_rupiah' => 1500000],
                ['deposit_account_id' => $deposit->id, 'amount_rupiah' => 300000],
            ],
        ]);
        $cash = CashTransaction::query()->firstOrFail();

        $response->assertRedirect(route('owner.payments.show', $cash));
        $this->get(route('owner.payments.show', $cash))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Owner/Payments/Show')
            ->where('payment.category', 'mixed_receipt')
            ->where('payment.amount_rupiah', 1800000)
            ->has('payment.invoice_allocations', 1)
            ->has('payment.deposit_movements', 1));
    }

    public function test_deposit_index_and_detail_show_liability_without_tenant_data_from_elsewhere(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Tenant Finance', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2027-01-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 300000,
        ]);
        $deposit = DepositAccount::query()->where('tenancy_id', $tenancy->id)->firstOrFail();

        $this->get('/owner/deposits')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Owner/Deposits/Index')
            ->where('deposits.data.0.required_amount_rupiah', 300000)
            ->where('deposits.data.0.balance_rupiah', 0));
        $this->get(route('owner.deposits.show', $deposit))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Owner/Deposits/Show')
            ->where('deposit.id', $deposit->id)
            ->where('deposit.required_amount_rupiah', 300000)
            ->where('deposit.balance_rupiah', 0)
            ->where('deposit.can_reconcile_external_overpayment', true));
    }

    public function test_owner_can_record_rent_refund_with_required_private_evidence(): void
    {
        Storage::fake('local');
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Tenant Refund', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2027-01-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 0,
        ]);
        $invoice = Invoice::query()->where('tenancy_id', $tenancy->id)->firstOrFail();
        $receipt = app(InvoicePaymentService::class)->recordReceipt($tenant, $owner, [
            'amount_rupiah' => 1500000,
            'occurred_on' => '2026-11-03',
            'method' => 'bank_transfer',
            'idempotency_key' => 'refund-page-receipt:001',
            'allocations' => [['invoice_id' => $invoice->id, 'amount_rupiah' => 1500000]],
        ]);
        $allocation = $receipt->invoiceAllocations()->firstOrFail();
        $credit = InvoiceAdjustment::query()->create([
            'tenant_id' => $tenant->id,
            'invoice_id' => $invoice->id,
            'kind' => 'credit',
            'amount_rupiah' => 400000,
            'reason' => 'Kredit disetujui pemilik.',
            'idempotency_key' => 'refund-page-credit:001',
            'created_by' => $owner->id,
            'created_at' => now('UTC'),
        ]);

        $this->get(route('owner.payments.show', $receipt))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Owner/Payments/Show')
            ->where('payment.invoice_allocations.0.refundable_rupiah', 1500000)
            ->where('payment.invoice_allocations.0.available_credit_rupiah', 400000)
            ->where('payment.invoice_allocations.0.credit_adjustments.0.id', $credit->id));

        $refundData = [
            'amount_rupiah' => 400000,
            'allocation_id' => $allocation->id,
            'credit_adjustment_id' => $credit->id,
            'occurred_on' => '2026-11-04',
            'method' => 'bank_transfer',
            'external_reference' => 'REFUND-PAGE-001',
            'reason' => 'Refund sesuai persetujuan pemilik.',
            'idempotency_key' => 'e27c31e3-2f44-498b-8aa1-98a5bf3511cf',
        ];

        $this->from(route('owner.payments.show', $receipt))->post(route('owner.payments.refunds.store', $receipt), $refundData)->assertSessionHasErrors('evidence');
        $this->assertSame(1, CashTransaction::query()->where('tenant_id', $tenant->id)->count());

        $response = $this->post(route('owner.payments.refunds.store', $receipt), [
            ...$refundData,
            'evidence' => [UploadedFile::fake()->createWithContent('rent-refund-proof.pdf', '%PDF-1.4 proof')],
        ]);
        $refund = CashTransaction::query()->where('tenant_id', $tenant->id)->where('category', 'rent_refund')->firstOrFail();
        $response->assertRedirect(route('owner.payments.show', $refund));

        $evidence = DepositEvidence::query()->where('tenant_id', $tenant->id)->where('cash_transaction_id', $refund->id)->firstOrFail();
        $this->assertSame('rent-refund-proof.pdf', $evidence->original_filename);
        $this->get(route('owner.deposit-evidence.download', $evidence))->assertOk();
        $this->get(route('owner.payments.show', $refund))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Owner/Payments/Show')
            ->where('payment.direction', 'out')
            ->where('payment.evidence.0.filename', 'rent-refund-proof.pdf'));
        $this->assertSame(1500000, (int) $receipt->fresh()->amount_rupiah);
    }
}
