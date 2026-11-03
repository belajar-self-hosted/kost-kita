<?php

namespace Tests\Feature\Owner;

use App\Models\AuditLog;
use App\Models\CashTransaction;
use App\Models\DepositAccount;
use App\Models\DepositEvidence;
use App\Models\DepositMovement;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\InvoiceCashAllocation;
use App\Models\TemporaryLiability;
use App\Models\Tenant;
use App\Models\User;
use App\Support\DepositEvidenceService;
use App\Support\DepositLedgerService;
use App\Support\InvoiceBalanceService;
use App\Support\InvoicePaymentService;
use App\Support\TenancyService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\OwnerTestData;
use Tests\TestCase;

class FinancialLedgerTest extends TestCase
{
    use OwnerTestData;
    use RefreshDatabase;

    public function test_mixed_receipt_is_one_cash_event_with_exact_invoice_and_deposit_allocations(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Tenant Test', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2027-01-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 300000,
        ]);
        $invoice = Invoice::query()->where('tenancy_id', $tenancy->id)->firstOrFail();
        $deposit = DepositAccount::query()->where('tenancy_id', $tenancy->id)->firstOrFail();

        $receipt = app(InvoicePaymentService::class)->recordReceipt($tenant, $owner, [
            'amount_rupiah' => 1800000,
            'occurred_on' => '2026-11-03',
            'method' => 'bank_transfer',
            'external_reference' => 'TRX-001',
            'idempotency_key' => 'receipt:TRX-001',
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount_rupiah' => 1500000],
                ['deposit_account_id' => $deposit->id, 'amount_rupiah' => 300000],
            ],
        ]);

        $this->assertSame('mixed_receipt', $receipt->category);
        $this->assertSame(1800000, (int) $receipt->amount_rupiah);
        $this->assertSame(1, $receipt->invoiceAllocations()->count());
        $this->assertSame(1, DepositMovement::query()->where('cash_transaction_id', $receipt->id)->where('kind', 'receipt')->count());
        $this->assertSame(1800000, CashTransaction::query()->where('tenant_id', $tenant->id)->sum('amount_rupiah'));
    }

    public function test_receipt_idempotency_rejects_changes_to_immutable_cash_details(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Tenant Test', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2027-01-03',
            'agreed_monthly_rent_rupiah' => 1500000,
        ]);
        $invoice = Invoice::query()->where('tenancy_id', $tenancy->id)->firstOrFail();
        $input = [
            'amount_rupiah' => 1500000,
            'occurred_on' => '2026-11-03',
            'method' => 'bank_transfer',
            'external_reference' => 'TRX-IDEMPOTENT',
            'description' => 'Pembayaran sewa November',
            'idempotency_key' => 'receipt:immutable-details',
            'allocations' => [['invoice_id' => $invoice->id, 'amount_rupiah' => 1500000]],
        ];
        $service = app(InvoicePaymentService::class);
        $receipt = $service->recordReceipt($tenant, $owner, $input);

        foreach ([
            ['occurred_on' => '2026-11-04'],
            ['method' => 'cash'],
            ['external_reference' => 'TRX-OTHER'],
            ['description' => 'Pembayaran berbeda'],
        ] as $change) {
            try {
                $service->recordReceipt($tenant, $owner, [...$input, ...$change]);
                $this->fail('Expected a changed receipt retry to be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('idempotency_key', $exception->errors());
            }
        }

        $this->assertSame(1, CashTransaction::query()->where('tenant_id', $tenant->id)->count());
        $this->assertSame($receipt->id, CashTransaction::query()->where('tenant_id', $tenant->id)->firstOrFail()->id);
    }

    public function test_new_receipt_cannot_exceed_invoice_or_deposit_balance(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Tenant Test', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2027-01-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 300000,
        ]);
        $invoice = Invoice::query()->where('tenancy_id', $tenancy->id)->firstOrFail();

        try {
            app(InvoicePaymentService::class)->recordReceipt($tenant, $owner, [
                'amount_rupiah' => 1500001,
                'occurred_on' => '2026-11-03',
                'method' => 'cash',
                'idempotency_key' => 'receipt:overpayment',
                'allocations' => [['invoice_id' => $invoice->id, 'amount_rupiah' => 1500001]],
            ]);
            $this->fail('Expected overpayment to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('allocations', $exception->errors());
            $this->assertSame(0, CashTransaction::query()->count());
        }
    }

    public function test_external_deposit_overpayment_is_kept_as_temporary_liability(): void
    {
        Storage::fake('local');
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Tenant Test', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2027-01-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 500000,
        ]);
        $deposit = DepositAccount::query()->where('tenancy_id', $tenancy->id)->firstOrFail();

        $input = [
            'deposit_account_id' => $deposit->id,
            'amount_rupiah' => 700000,
            'deposit_allocation_rupiah' => 500000,
            'occurred_on' => '2026-11-03',
            'method' => 'bank_transfer',
            'external_reference' => 'BANK-OVER-001',
            'reason' => 'Dana masuk utuh sebelum dicatat di aplikasi.',
            'idempotency_key' => 'external:over-001',
            'evidence' => [UploadedFile::fake()->createWithContent('bukti-transfer.pdf', 'Bukti transfer eksternal')],
        ];
        $service = app(InvoicePaymentService::class);
        $receipt = $service->reconcileExternalDepositOverpayment($tenant, $owner, $input);
        $retry = $service->reconcileExternalDepositOverpayment($tenant, $owner, $input);
        $proof = DepositEvidence::query()->where('tenant_id', $tenant->id)->where('cash_transaction_id', $receipt->id)->firstOrFail();

        $this->assertSame(700000, (int) $receipt->amount_rupiah);
        $this->assertSame(500000, (int) DepositMovement::query()->where('cash_transaction_id', $receipt->id)->where('kind', 'receipt')->value('amount_rupiah'));
        $this->assertSame(200000, (int) TemporaryLiability::query()->where('source_cash_transaction_id', $receipt->id)->value('initial_amount_rupiah'));
        $this->assertSame($receipt->id, $retry->id);
        $this->assertSame(1, DepositEvidence::query()->where('tenant_id', $tenant->id)->where('cash_transaction_id', $receipt->id)->count());
        Storage::disk('local')->assertExists($proof->storage_key);

        $otherOwner = User::factory()->create()->refresh();
        $otherTenant = Tenant::query()->create(['owner_user_id' => $otherOwner->id, 'display_name' => 'Other Owner']);
        $this->expectException(ModelNotFoundException::class);
        app(DepositEvidenceService::class)->download($otherTenant, $otherOwner, $proof->id);
    }

    public function test_external_deposit_overpayment_requires_private_transfer_evidence(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Tenant Test', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2027-01-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 500000,
        ]);
        $deposit = DepositAccount::query()->where('tenancy_id', $tenancy->id)->firstOrFail();

        try {
            app(InvoicePaymentService::class)->reconcileExternalDepositOverpayment($tenant, $owner, [
                'deposit_account_id' => $deposit->id,
                'amount_rupiah' => 700000,
                'deposit_allocation_rupiah' => 500000,
                'occurred_on' => '2026-11-03',
                'method' => 'bank_transfer',
                'external_reference' => 'BANK-OVER-NO-PROOF',
                'reason' => 'Dana masuk utuh sebelum dicatat di aplikasi.',
                'idempotency_key' => 'external:over-no-proof',
            ]);
            $this->fail('Expected reconciliation without transfer evidence to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('evidence', $exception->errors());
            $this->assertSame(0, CashTransaction::query()->count());
        }
    }

    public function test_external_deposit_reconciliation_rejects_an_allocation_below_the_remaining_obligation(): void
    {
        Storage::fake('local');
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Tenant Test', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2027-01-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 500000,
        ]);
        $deposit = DepositAccount::query()->where('tenancy_id', $tenancy->id)->firstOrFail();

        try {
            app(InvoicePaymentService::class)->reconcileExternalDepositOverpayment($tenant, $owner, [
                'deposit_account_id' => $deposit->id,
                'amount_rupiah' => 700000,
                'deposit_allocation_rupiah' => 300000,
                'occurred_on' => '2026-11-03',
                'method' => 'bank_transfer',
                'external_reference' => 'BANK-UNDER-ALLOCATED',
                'reason' => 'Dana eksternal dicatat dengan pembagian yang kurang.',
                'idempotency_key' => 'external:under-allocated',
                'evidence' => [UploadedFile::fake()->createWithContent('bukti-transfer.pdf', 'Bukti transfer eksternal')],
            ]);
            $this->fail('Expected an allocation below the remaining deposit obligation to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('deposit_allocation_rupiah', $exception->errors());
            $this->assertSame(0, CashTransaction::query()->count());
            $this->assertSame(0, TemporaryLiability::query()->count());
        }
    }

    public function test_external_overpayment_reconciliation_rejects_a_receipt_that_does_not_exceed_the_obligation(): void
    {
        Storage::fake('local');
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Tenant Test', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2027-01-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 500000,
        ]);
        $deposit = DepositAccount::query()->where('tenancy_id', $tenancy->id)->firstOrFail();

        try {
            app(InvoicePaymentService::class)->reconcileExternalDepositOverpayment($tenant, $owner, [
                'deposit_account_id' => $deposit->id,
                'amount_rupiah' => 300000,
                'deposit_allocation_rupiah' => 300000,
                'reason' => 'Perlu dicatat sebagai penerimaan biasa, bukan surplus.',
                'occurred_on' => '2026-11-03',
                'method' => 'bank_transfer',
                'external_reference' => 'BANK-NOT-OVERPAID',
                'idempotency_key' => 'external:not-overpaid',
                'evidence' => [UploadedFile::fake()->createWithContent('normal-receipt.pdf', '%PDF-1.4 receipt')],
            ]);
            $this->fail('Expected overpayment reconciliation to require funds above the remaining deposit obligation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('deposit_allocation_rupiah', $exception->errors());
            $this->assertSame(0, CashTransaction::query()->where('tenant_id', $tenant->id)->count());
        }
    }

    public function test_fully_satisfied_deposit_external_surplus_uses_zero_allocation_and_audited_refund(): void
    {
        Storage::fake('local');
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Tenant Test', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2027-01-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 0,
        ]);
        $deposit = DepositAccount::query()->where('tenancy_id', $tenancy->id)->firstOrFail();
        $input = [
            'deposit_account_id' => $deposit->id,
            'amount_rupiah' => 200000,
            'deposit_allocation_rupiah' => 0,
            'occurred_on' => '2026-11-03',
            'method' => 'bank_transfer',
            'external_reference' => 'BANK-ZERO-DEPOSIT',
            'reason' => 'Penerimaan aktual saat kewajiban deposit sudah terpenuhi.',
            'idempotency_key' => 'external:zero-deposit-allocation',
            'evidence' => [UploadedFile::fake()->createWithContent('bukti-transfer.pdf', 'Bukti transfer eksternal')],
        ];
        $payments = app(InvoicePaymentService::class);
        $receipt = $payments->reconcileExternalDepositOverpayment($tenant, $owner, $input);
        $retry = $payments->reconcileExternalDepositOverpayment($tenant, $owner, $input);
        $liability = TemporaryLiability::query()->where('source_cash_transaction_id', $receipt->id)->firstOrFail();

        $this->assertSame($receipt->id, $retry->id);
        $this->assertSame(0, DepositMovement::query()->where('cash_transaction_id', $receipt->id)->count());
        $this->assertSame(200000, (int) $liability->initial_amount_rupiah);
        $this->get(route('owner.deposits.show', $deposit))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Owner/Deposits/Show')
            ->where('deposit.can_reconcile_external_overpayment', true));

        $refund = app(DepositLedgerService::class)->refundTemporaryLiability($tenant, $owner, $liability->id, [
            'amount_rupiah' => 200000,
            'occurred_on' => '2026-11-04',
            'method' => 'bank_transfer',
            'external_reference' => 'REFUND-ZERO-DEPOSIT',
            'description' => 'Pengembalian kelebihan dana yang telah diterima.',
            'idempotency_key' => 'external:zero-deposit-refund',
        ]);
        $audit = AuditLog::query()->where('tenant_id', $tenant->id)
            ->where('action', 'temporary_liability.refund_recorded')->where('subject_id', $liability->id)->firstOrFail();

        $this->assertSame('temporary_liability_refund', $refund->category);
        $this->assertSame('out', $refund->direction);
        $this->assertSame(0, app(DepositLedgerService::class)->temporaryLiabilityBalance($liability->fresh()));
        $this->assertSame($refund->id, $audit->new_values['cash_transaction_id']);
        $this->assertSame(200000, $audit->new_values['amount_rupiah']);
    }

    public function test_rent_refund_reverses_the_original_allocation_without_erasing_the_receipt(): void
    {
        Storage::fake('local');
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Tenant Test', 'whatsapp_number' => '+628123456789'],
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
            'idempotency_key' => 'rent-receipt:refund-test',
            'allocations' => [['invoice_id' => $invoice->id, 'amount_rupiah' => 1500000]],
        ]);
        $credit = InvoiceAdjustment::query()->create([
            'tenant_id' => $tenant->id,
            'invoice_id' => $invoice->id,
            'kind' => 'credit',
            'amount_rupiah' => 400000,
            'reason' => 'Kredit refund yang disetujui pemilik.',
            'idempotency_key' => 'rent-credit:refund-test',
            'created_by' => $owner->id,
            'created_at' => now('UTC'),
        ]);
        $allocation = $receipt->invoiceAllocations()->firstOrFail();

        $refundInput = [
            'amount_rupiah' => 400000,
            'occurred_on' => '2026-11-04',
            'method' => 'bank_transfer',
            'external_reference' => 'REFUND-001',
            'reason' => 'Pengembalian sesuai credit note.',
            'credit_adjustment_id' => $credit->id,
            'idempotency_key' => 'rent-refund:001',
            'evidence' => [UploadedFile::fake()->createWithContent('refund.pdf', '%PDF-1.4 refund proof')],
            'allocations' => [['allocation_id' => $allocation->id, 'amount_rupiah' => 400000]],
        ];
        $refund = app(InvoicePaymentService::class)->recordRentRefund($tenant, $owner, $receipt->id, $refundInput);
        $refundRetry = app(InvoicePaymentService::class)->recordRentRefund($tenant, $owner, $receipt->id, $refundInput);

        $this->assertSame('rent_refund', $refund->category);
        $this->assertSame($refund->id, $refundRetry->id);
        $this->assertSame($receipt->id, $refund->reversal_of_id);
        $this->assertSame(1, $invoice->cashAllocations()->where('kind', 'reversal')->count());
        $this->assertSame(0, app(InvoiceBalanceService::class)->outstanding($invoice->fresh()));
        $this->assertSame(1900000, (int) CashTransaction::query()->where('tenant_id', $tenant->id)->sum('amount_rupiah'));
    }

    public function test_rent_refunds_from_separate_receipts_share_one_invoice_credit_limit(): void
    {
        Storage::fake('local');
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Tenant Test', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2027-01-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 0,
        ]);
        $invoice = Invoice::query()->where('tenancy_id', $tenancy->id)->firstOrFail();
        $payments = app(InvoicePaymentService::class);
        $firstReceipt = $payments->recordReceipt($tenant, $owner, [
            'amount_rupiah' => 500000, 'occurred_on' => '2026-11-03', 'method' => 'bank_transfer',
            'idempotency_key' => 'refund-share-credit-receipt-1',
            'allocations' => [['invoice_id' => $invoice->id, 'amount_rupiah' => 500000]],
        ]);
        $secondReceipt = $payments->recordReceipt($tenant, $owner, [
            'amount_rupiah' => 500000, 'occurred_on' => '2026-11-03', 'method' => 'bank_transfer',
            'idempotency_key' => 'refund-share-credit-receipt-2',
            'allocations' => [['invoice_id' => $invoice->id, 'amount_rupiah' => 500000]],
        ]);
        $credit = InvoiceAdjustment::query()->create([
            'tenant_id' => $tenant->id, 'invoice_id' => $invoice->id, 'kind' => 'credit', 'amount_rupiah' => 600000,
            'reason' => 'Kredit yang dapat dipakai untuk satu refund.', 'idempotency_key' => 'shared-refund-credit',
            'created_by' => $owner->id, 'created_at' => now('UTC'),
        ]);
        $firstAllocation = $firstReceipt->invoiceAllocations()->firstOrFail();
        $secondAllocation = $secondReceipt->invoiceAllocations()->firstOrFail();
        $refundInput = [
            'amount_rupiah' => 300000, 'occurred_on' => '2026-11-04', 'method' => 'bank_transfer',
            'external_reference' => 'REFUND-SHARED-CREDIT-1', 'reason' => 'Refund berdasarkan credit note.',
            'credit_adjustment_id' => $credit->id, 'idempotency_key' => 'refund-shared-credit-1',
            'evidence' => [UploadedFile::fake()->createWithContent('refund-1.pdf', '%PDF-1.4 refund proof')],
            'allocations' => [['allocation_id' => $firstAllocation->id, 'amount_rupiah' => 300000]],
        ];
        try {
            $payments->recordRentRefund($tenant, $owner, $firstReceipt->id, [
                ...$refundInput,
                'amount_rupiah' => 600000,
                'external_reference' => 'REFUND-DUPLICATE-ALLOCATION',
                'idempotency_key' => 'refund-duplicate-allocation',
                'allocations' => [
                    ['allocation_id' => $firstAllocation->id, 'amount_rupiah' => 300000],
                    ['allocation_id' => $firstAllocation->id, 'amount_rupiah' => 300000],
                ],
            ]);
            $this->fail('Expected duplicate refund rows to be aggregated against the original receipt allocation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('allocations', $exception->errors());
            $this->assertSame(0, CashTransaction::query()->where('tenant_id', $tenant->id)->where('category', 'rent_refund')->count());
        }
        $firstRefund = $payments->recordRentRefund($tenant, $owner, $firstReceipt->id, $refundInput);
        $this->assertSame('rent_refund', $firstRefund->category);

        try {
            $payments->recordRentRefund($tenant, $owner, $secondReceipt->id, [
                ...$refundInput,
                'external_reference' => 'REFUND-SHARED-CREDIT-2',
                'idempotency_key' => 'refund-shared-credit-2',
                'evidence' => [UploadedFile::fake()->createWithContent('refund-2.pdf', '%PDF-1.4 refund proof')],
                'amount_rupiah' => 400000,
                'allocations' => [['allocation_id' => $secondAllocation->id, 'amount_rupiah' => 400000]],
            ]);
            $this->fail('Expected refunds across receipts to share the invoice credit limit.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('allocations', $exception->errors());
            $this->assertSame(300000, (int) InvoiceCashAllocation::query()->where('tenant_id', $tenant->id)->where('invoice_id', $invoice->id)->where('kind', 'reversal')->sum('amount_rupiah'));
            $this->assertSame(1, CashTransaction::query()->where('tenant_id', $tenant->id)->where('category', 'rent_refund')->count());
        }
    }

    public function test_payment_service_rejects_cross_tenant_targets_and_stale_overpayment(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $otherOwner = User::factory()->create()->refresh();
        $otherTenant = Tenant::query()->create(['owner_user_id' => $otherOwner->id, 'display_name' => 'Other Owner']);
        $otherProperty = $otherTenant->properties()->create(['name' => 'Other Kost']);
        $otherRoom = $otherProperty->rooms()->create(['tenant_id' => $otherTenant->id, 'code' => 'B-01', 'operational_status' => 'rentable']);
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $otherTenancy = app(TenancyService::class)->createDirect($otherTenant, $otherOwner, [
            'room_id' => $otherRoom->id,
            'resident' => ['full_name' => 'Tenant Other', 'whatsapp_number' => '+628123456780'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2027-01-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 0,
        ]);
        $otherInvoice = Invoice::query()->where('tenancy_id', $otherTenancy->id)->firstOrFail();

        try {
            app(InvoicePaymentService::class)->recordReceipt($tenant, $owner, [
                'amount_rupiah' => 100000,
                'occurred_on' => '2026-11-03',
                'method' => 'cash',
                'idempotency_key' => 'cross-tenant-invoice',
                'allocations' => [['invoice_id' => $otherInvoice->id, 'amount_rupiah' => 100000]],
            ]);
            $this->fail('Expected a foreign-tenant invoice allocation to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('allocations', $exception->errors());
            $this->assertSame(0, CashTransaction::query()->count());
        }

        $ownRoom = $property->rooms()->create(['tenant_id' => $tenant->id, 'code' => 'A-02', 'operational_status' => 'rentable']);
        $ownTenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $ownRoom->id,
            'resident' => ['full_name' => 'Tenant Own', 'whatsapp_number' => '+628123456781'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2027-01-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 0,
        ]);
        $ownInvoice = Invoice::query()->where('tenancy_id', $ownTenancy->id)->firstOrFail();
        app(InvoicePaymentService::class)->recordReceipt($tenant, $owner, [
            'amount_rupiah' => 1000000,
            'occurred_on' => '2026-11-03',
            'method' => 'cash',
            'idempotency_key' => 'partial-current-balance',
            'allocations' => [['invoice_id' => $ownInvoice->id, 'amount_rupiah' => 1000000]],
        ]);

        try {
            app(InvoicePaymentService::class)->recordReceipt($tenant, $owner, [
                'amount_rupiah' => 500001,
                'occurred_on' => '2026-11-03',
                'method' => 'cash',
                'idempotency_key' => 'stale-overpayment',
                'allocations' => [['invoice_id' => $ownInvoice->id, 'amount_rupiah' => 500001]],
            ]);
            $this->fail('Expected a stale-balance overpayment to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('allocations', $exception->errors());
        }
    }

    public function test_deposit_requirement_can_change_before_first_receipt_and_is_locked_afterward(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Tenant Test', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2027-01-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 300000,
        ]);
        $deposit = DepositAccount::query()->where('tenancy_id', $tenancy->id)->firstOrFail();
        $ledger = app(DepositLedgerService::class);
        $updated = $ledger->updateRequirement($tenant, $owner, $deposit->id, 500000, 'Perubahan kesepakatan sebelum pembayaran.');
        $this->assertSame(500000, (int) $updated->required_amount_rupiah);

        app(InvoicePaymentService::class)->recordReceipt($tenant, $owner, [
            'amount_rupiah' => 500000,
            'occurred_on' => '2026-11-03',
            'method' => 'cash',
            'idempotency_key' => 'deposit-requirement-update-receipt',
            'allocations' => [['deposit_account_id' => $deposit->id, 'amount_rupiah' => 500000]],
        ]);
        try {
            $ledger->updateRequirement($tenant, $owner, $deposit->id, 700000, 'Perubahan setelah pembayaran.');
            $this->fail('Expected a deposit requirement change after a receipt to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('required_amount_rupiah', $exception->errors());
            $this->assertSame(500000, (int) $deposit->fresh()->required_amount_rupiah);
        }
    }
}
