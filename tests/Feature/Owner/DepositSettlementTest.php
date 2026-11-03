<?php

namespace Tests\Feature\Owner;

use App\Models\AuditLog;
use App\Models\CashTransaction;
use App\Models\DepositAccount;
use App\Models\DepositEvidence;
use App\Models\DepositMovement;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Support\DepositEvidenceService;
use App\Support\DepositLedgerService;
use App\Support\DepositSettlementService;
use App\Support\InvoiceBalanceService;
use App\Support\InvoicePaymentService;
use App\Support\TenancyService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\OwnerTestData;
use Tests\TestCase;

class DepositSettlementTest extends TestCase
{
    use OwnerTestData;
    use RefreshDatabase;

    public function test_approved_deposit_invoice_settlement_is_non_cash_and_audited(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-10-01 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Tenant Test', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-10-01',
            'planned_end_exclusive' => '2027-01-01',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 1000000,
        ]);
        $invoice = Invoice::query()->where('tenancy_id', $tenancy->id)->firstOrFail();
        $deposit = DepositAccount::query()->where('tenancy_id', $tenancy->id)->firstOrFail();
        app(InvoicePaymentService::class)->recordReceipt($tenant, $owner, [
            'amount_rupiah' => 1000000,
            'occurred_on' => '2026-10-01',
            'method' => 'bank_transfer',
            'idempotency_key' => 'settlement-deposit-receipt',
            'allocations' => [['deposit_account_id' => $deposit->id, 'amount_rupiah' => 1000000]],
        ]);

        $this->travelTo(Carbon::parse('2026-10-12 09:00:00', 'Asia/Jakarta'));
        app(TenancyService::class)->checkOut($tenant, $owner, $tenancy->id, '2026-10-10');
        $amount = app(InvoiceBalanceService::class)->outstanding($invoice->fresh());
        $this->assertGreaterThan(0, $amount);
        $beforeCash = CashTransaction::query()->where('tenant_id', $tenant->id)->count();

        $settlements = app(DepositSettlementService::class);
        $settlement = $settlements->open($tenant, $owner, $deposit->id, [
            'exit_inspection_notes' => 'Kamar diperiksa saat penyewa keluar.',
            'lines' => [[
                'kind' => 'invoice_deduction',
                'amount_rupiah' => $amount,
                'reason' => 'Pelunasan tagihan sewa Oktober yang belum dibayar.',
                'agreement_basis' => 'Perjanjian menyatakan saldo sewa jatuh tempo dapat diselesaikan dari deposit.',
                'invoice_id' => $invoice->id,
            ], [
                'kind' => 'refund',
                'amount_rupiah' => 1000000 - $amount,
                'reason' => 'Pengembalian saldo deposit setelah tagihan dilunasi.',
            ]],
        ]);
        $settlements->recordWhatsAppNotification($tenant, $owner, $settlement->id);
        $approved = $settlements->approve($tenant, $owner, $settlement->id);

        $this->assertSame('partially_settled', $approved->fresh()->status);
        $this->assertSame(0, app(InvoiceBalanceService::class)->outstanding($invoice->fresh()));
        $movement = DepositMovement::query()->where('invoice_id', $invoice->id)->where('kind', 'invoice_settlement')->firstOrFail();
        $this->assertSame($amount, (int) $movement->amount_rupiah);
        $this->assertSame($beforeCash, CashTransaction::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_held_claim_requires_evidence_and_owner_approval_cannot_be_skipped(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Tenant Test', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2026-12-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 300000,
        ]);
        $deposit = DepositAccount::query()->where('tenancy_id', $tenancy->id)->firstOrFail();
        app(InvoicePaymentService::class)->recordReceipt($tenant, $owner, [
            'amount_rupiah' => 300000,
            'occurred_on' => '2026-11-03',
            'method' => 'cash',
            'idempotency_key' => 'claim-deposit-receipt',
            'allocations' => [['deposit_account_id' => $deposit->id, 'amount_rupiah' => 300000]],
        ]);
        app(TenancyService::class)->checkOut($tenant, $owner, $tenancy->id, '2026-11-03');

        $this->expectException(ValidationException::class);
        app(DepositSettlementService::class)->open($tenant, $owner, $deposit->id, [
            'exit_inspection_notes' => 'Pemeriksaan keluar.',
            'lines' => [[
                'kind' => 'held_claim',
                'amount_rupiah' => 100000,
                'reason' => 'Klaim kerusakan yang spesifik.',
                'agreement_basis' => 'Dasar sesuai kesepakatan tertulis.',
                'evidence' => [],
            ], [
                'kind' => 'refund', 'amount_rupiah' => 200000,
                'reason' => 'Pengembalian bagian saldo yang tidak diklaim.',
            ]],
        ]);
    }

    public function test_normal_wear_and_routine_maintenance_cannot_be_deducted_from_deposit(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Tenant Test', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2026-12-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 200000,
        ]);
        $deposit = DepositAccount::query()->where('tenancy_id', $tenancy->id)->firstOrFail();
        app(InvoicePaymentService::class)->recordReceipt($tenant, $owner, [
            'amount_rupiah' => 200000,
            'occurred_on' => '2026-11-03',
            'method' => 'cash',
            'idempotency_key' => 'wear-deposit-receipt',
            'allocations' => [['deposit_account_id' => $deposit->id, 'amount_rupiah' => 200000]],
        ]);
        app(TenancyService::class)->checkOut($tenant, $owner, $tenancy->id, '2026-11-03');

        $this->expectException(ValidationException::class);
        app(DepositSettlementService::class)->open($tenant, $owner, $deposit->id, [
            'exit_inspection_notes' => 'Pemeriksaan akhir kamar.',
            'lines' => [[
                'kind' => 'damage_deduction',
                'amount_rupiah' => 50000,
                'reason' => 'Biaya untuk keausan wajar dan maintenance rutin.',
                'agreement_basis' => 'Klausul perjanjian sewa.',
                'evidence' => [UploadedFile::fake()->create('wear.pdf', 10, 'application/pdf')],
            ], [
                'kind' => 'refund', 'amount_rupiah' => 150000,
                'reason' => 'Pengembalian saldo deposit yang tidak dipotong.',
            ]],
        ]);
    }

    public function test_deposit_refund_requires_actual_transfer_proof_and_download_is_tenant_scoped(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Tenant Test', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2026-12-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 300000,
        ]);
        $deposit = DepositAccount::query()->where('tenancy_id', $tenancy->id)->firstOrFail();
        app(InvoicePaymentService::class)->recordReceipt($tenant, $owner, [
            'amount_rupiah' => 300000,
            'occurred_on' => '2026-11-03',
            'method' => 'cash',
            'idempotency_key' => 'deposit-refund-receipt',
            'allocations' => [['deposit_account_id' => $deposit->id, 'amount_rupiah' => 300000]],
        ]);
        app(TenancyService::class)->checkOut($tenant, $owner, $tenancy->id, '2026-11-03');

        $settlements = app(DepositSettlementService::class);
        $settlement = $settlements->open($tenant, $owner, $deposit->id, [
            'exit_inspection_notes' => 'Kamar diperiksa dan tidak ada kerusakan.',
            'lines' => [[
                'kind' => 'refund', 'amount_rupiah' => 300000,
                'reason' => 'Pengembalian penuh karena tidak ada potongan.',
            ]],
        ]);
        $this->assertSame('2026-11-10 23:59:59', $settlement->day7_deadline_at->setTimezone('Asia/Jakarta')->toDateTimeString());
        $settlements->recordWhatsAppNotification($tenant, $owner, $settlement->id);
        $settlements->approve($tenant, $owner, $settlement->id);
        try {
            $settlements->recordRefund($tenant, $owner, $deposit->id, [
                'settlement_line_id' => $settlement->lines()->firstOrFail()->id,
                'amount_rupiah' => 300000,
                'occurred_on' => '2026-11-03',
                'method' => 'bank_transfer',
                'external_reference' => 'REFUND-DEP-NOPROOF',
                'idempotency_key' => 'deposit-refund:no-proof',
            ]);
            $this->fail('Expected actual refund without evidence to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount_rupiah', $exception->errors());
        }
        try {
            $settlements->recordRefund($tenant, $owner, $deposit->id, [
                'settlement_line_id' => $settlement->lines()->firstOrFail()->id,
                'amount_rupiah' => 300001,
                'occurred_on' => '2026-11-03',
                'method' => 'bank_transfer',
                'external_reference' => 'REFUND-DEP-OVER',
                'idempotency_key' => 'deposit-refund:over',
                'evidence' => [UploadedFile::fake()->create('bukti-overshoot.pdf', 10, 'application/pdf')],
            ]);
            $this->fail('Expected a refund above the approved line to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount_rupiah', $exception->errors());
        }
        $cash = $settlements->recordRefund($tenant, $owner, $deposit->id, [
            'settlement_line_id' => $settlement->lines()->firstOrFail()->id,
            'amount_rupiah' => 300000,
            'occurred_on' => '2026-11-03',
            'method' => 'bank_transfer',
            'external_reference' => 'REFUND-DEP-001',
            'reason' => 'Refund deposit aktual.',
            'idempotency_key' => 'deposit-refund:001',
            'evidence' => [UploadedFile::fake()->create('bukti-refund.pdf', 10, 'application/pdf')],
        ]);
        $cashRetry = $settlements->recordRefund($tenant, $owner, $deposit->id, [
            'settlement_line_id' => $settlement->lines()->firstOrFail()->id,
            'amount_rupiah' => 300000,
            'occurred_on' => '2026-11-03',
            'method' => 'bank_transfer',
            'external_reference' => 'REFUND-DEP-001',
            'reason' => 'Refund deposit aktual.',
            'idempotency_key' => 'deposit-refund:001',
            'evidence' => [UploadedFile::fake()->create('bukti-refund.pdf', 10, 'application/pdf')],
        ]);
        $evidence = DepositEvidence::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $download = app(DepositEvidenceService::class)->download($tenant, $owner, $evidence->id);

        $this->assertSame('deposit_refund', $cash->category);
        $this->assertSame(0, app(DepositLedgerService::class)->balance($deposit->fresh()));
        $this->assertSame('finalized', $settlement->fresh()->status);
        $this->assertSame('application/pdf', $evidence->mime_type);
        $this->assertSame($cash->id, $cashRetry->id);
        $this->assertFalse(Storage::disk('public')->exists($evidence->storage_key));
        $this->assertSame(200, $download->getStatusCode());

        $otherOwner = User::factory()->create()->refresh();
        $otherTenant = Tenant::query()->create(['owner_user_id' => $otherOwner->id, 'display_name' => 'Other Owner']);
        $this->expectException(ModelNotFoundException::class);
        app(DepositEvidenceService::class)->download($otherTenant, $otherOwner, $evidence->id);
    }

    public function test_settlement_proposal_must_account_for_the_full_deposit_balance(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Tenant Test', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2026-12-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 300000,
        ]);
        $deposit = DepositAccount::query()->where('tenancy_id', $tenancy->id)->firstOrFail();
        app(InvoicePaymentService::class)->recordReceipt($tenant, $owner, [
            'amount_rupiah' => 300000,
            'occurred_on' => '2026-11-03',
            'method' => 'cash',
            'idempotency_key' => 'partial-settlement-deposit-receipt',
            'allocations' => [['deposit_account_id' => $deposit->id, 'amount_rupiah' => 300000]],
        ]);
        app(TenancyService::class)->checkOut($tenant, $owner, $tenancy->id, '2026-11-03');

        try {
            app(DepositSettlementService::class)->open($tenant, $owner, $deposit->id, [
                'exit_inspection_notes' => 'Pemeriksaan akhir kamar.',
                'lines' => [[
                    'kind' => 'refund', 'amount_rupiah' => 250000,
                    'reason' => 'Pengembalian sebagian saldo deposit.',
                ]],
            ]);
            $this->fail('Expected a proposal that strands deposit liability to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
            $this->assertSame(0, $deposit->settlements()->count());
            $this->assertSame(300000, app(DepositLedgerService::class)->balance($deposit->fresh()));
        }
    }

    public function test_owner_can_cancel_a_draft_and_open_a_replacement_without_erasing_history(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Tenant Test', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2026-12-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 300000,
        ]);
        $deposit = DepositAccount::query()->where('tenancy_id', $tenancy->id)->firstOrFail();
        app(InvoicePaymentService::class)->recordReceipt($tenant, $owner, [
            'amount_rupiah' => 300000,
            'occurred_on' => '2026-11-03',
            'method' => 'cash',
            'idempotency_key' => 'cancel-draft-deposit-receipt',
            'allocations' => [['deposit_account_id' => $deposit->id, 'amount_rupiah' => 300000]],
        ]);
        app(TenancyService::class)->checkOut($tenant, $owner, $tenancy->id, '2026-11-03');

        $service = app(DepositSettlementService::class);
        $proposal = [
            'exit_inspection_notes' => 'Kamar diperiksa saat penyewa keluar.',
            'lines' => [[
                'kind' => 'refund',
                'amount_rupiah' => 300000,
                'reason' => 'Pengembalian penuh tanpa potongan.',
            ]],
        ];
        $draft = $service->open($tenant, $owner, $deposit->id, $proposal);
        $response = $this->actingAs($owner)->post(route('owner.deposit-settlements.cancel', $draft), [
            'reason' => 'Nominal proposal perlu dikoreksi.',
        ]);
        $response->assertRedirect('/owner/deposits/'.$deposit->id);
        $cancelled = $draft->fresh();
        $replacement = $service->open($tenant, $owner, $deposit->id, $proposal);

        $this->assertSame('cancelled', $cancelled->status);
        $cancellation = AuditLog::query()->where('tenant_id', $tenant->id)->where('action', 'deposit.settlement_draft_cancelled')->firstOrFail();
        $this->assertSame($owner->id, $cancellation->actor_user_id);
        $this->assertSame('Nominal proposal perlu dikoreksi.', $cancellation->new_values['reason']);
        $this->assertNotNull($cancellation->occurred_at);
        $this->assertSame('draft', $replacement->status);
        $this->assertSame(2, $deposit->settlements()->count());
        $this->assertSame(1, AuditLog::query()->where('tenant_id', $tenant->id)->where('action', 'deposit.settlement_draft_cancelled')->count());
    }

    public function test_duplicate_invoice_deduction_lines_are_aggregated_before_any_movement_is_written(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Tenant Test', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2026-12-03',
            'agreed_monthly_rent_rupiah' => 300000,
            'required_deposit_rupiah' => 200000,
        ]);
        $invoice = Invoice::query()->where('tenancy_id', $tenancy->id)->firstOrFail();
        $deposit = DepositAccount::query()->where('tenancy_id', $tenancy->id)->firstOrFail();
        app(InvoicePaymentService::class)->recordReceipt($tenant, $owner, [
            'amount_rupiah' => 200000,
            'occurred_on' => '2026-11-03',
            'method' => 'cash',
            'idempotency_key' => 'aggregate-deduction-deposit-receipt',
            'allocations' => [['deposit_account_id' => $deposit->id, 'amount_rupiah' => 200000]],
        ]);
        $this->travelTo(Carbon::parse('2026-11-12 09:00:00', 'Asia/Jakarta'));
        app(TenancyService::class)->checkOut($tenant, $owner, $tenancy->id, '2026-11-12');
        $outstanding = app(InvoiceBalanceService::class)->outstanding($invoice->fresh());
        $this->assertSame(100000, $outstanding);

        $settlements = app(DepositSettlementService::class);
        $settlement = $settlements->open($tenant, $owner, $deposit->id, [
            'exit_inspection_notes' => 'Pemeriksaan keluar kamar.',
            'lines' => [[
                'kind' => 'invoice_deduction', 'amount_rupiah' => 60000,
                'reason' => 'Pengurangan saldo sewa sesuai perjanjian.',
                'agreement_basis' => 'Perjanjian mengizinkan pengurangan saldo sewa jatuh tempo.',
                'invoice_id' => $invoice->id,
            ], [
                'kind' => 'invoice_deduction', 'amount_rupiah' => 60000,
                'reason' => 'Pengurangan saldo sewa sesuai perjanjian.',
                'agreement_basis' => 'Perjanjian mengizinkan pengurangan saldo sewa jatuh tempo.',
                'invoice_id' => $invoice->id,
            ], [
                'kind' => 'refund', 'amount_rupiah' => 80000,
                'reason' => 'Pengembalian saldo deposit setelah tagihan diselesaikan.',
            ]],
        ]);
        $settlements->recordWhatsAppNotification($tenant, $owner, $settlement->id);

        try {
            $settlements->approve($tenant, $owner, $settlement->id);
            $this->fail('Expected duplicate invoice deductions over the aggregate balance to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('settlement', $exception->errors());
            $this->assertSame(0, DepositMovement::query()->where('invoice_id', $invoice->id)->where('kind', 'invoice_settlement')->count());
            $this->assertSame($outstanding, app(InvoiceBalanceService::class)->outstanding($invoice->fresh()));
        }
    }

    public function test_paid_deposit_on_scheduled_tenancy_can_be_settled_after_cancellation(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Tenant Test', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-10',
            'planned_end_exclusive' => '2027-01-10',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 300000,
        ]);
        $deposit = DepositAccount::query()->where('tenancy_id', $tenancy->id)->firstOrFail();
        app(InvoicePaymentService::class)->recordReceipt($tenant, $owner, [
            'amount_rupiah' => 300000,
            'occurred_on' => '2026-11-03',
            'method' => 'bank_transfer',
            'idempotency_key' => 'scheduled-cancel-deposit-receipt',
            'allocations' => [['deposit_account_id' => $deposit->id, 'amount_rupiah' => 300000]],
        ]);
        $cancelled = app(TenancyService::class)->cancelScheduled($tenant, $owner, $tenancy->id, 'Rencana sewa dibatalkan pemilik.');
        $this->assertNotNull($cancelled->terminated_at);

        $settlement = app(DepositSettlementService::class)->open($tenant, $owner, $deposit->id, [
            'exit_inspection_notes' => 'Check-in belum terjadi; deposit dikembalikan penuh.',
            'lines' => [[
                'kind' => 'refund', 'amount_rupiah' => 300000,
                'reason' => 'Pengembalian deposit setelah pembatalan masa sewa terjadwal.',
            ]],
        ]);

        $this->assertSame('draft', $settlement->status);
        $this->assertSame(
            $cancelled->terminated_at->setTimezone('Asia/Jakarta')->startOfDay()->toDateTimeString(),
            $settlement->opened_at->setTimezone('Asia/Jakarta')->startOfDay()->toDateTimeString(),
        );
        $this->assertSame(0, Invoice::query()->where('tenancy_id', $tenancy->id)->count());
    }

    public function test_held_claim_finalization_splits_actual_damage_and_refund_with_private_evidence(): void
    {
        Storage::fake('local');
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Tenant Test', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2026-12-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 200000,
        ]);
        $deposit = DepositAccount::query()->where('tenancy_id', $tenancy->id)->firstOrFail();
        app(InvoicePaymentService::class)->recordReceipt($tenant, $owner, [
            'amount_rupiah' => 200000,
            'occurred_on' => '2026-11-03',
            'method' => 'cash',
            'idempotency_key' => 'claim-finalize-receipt',
            'allocations' => [['deposit_account_id' => $deposit->id, 'amount_rupiah' => 200000]],
        ]);
        app(TenancyService::class)->checkOut($tenant, $owner, $tenancy->id, '2026-11-03');

        $settlements = app(DepositSettlementService::class);
        $settlement = $settlements->open($tenant, $owner, $deposit->id, [
            'exit_inspection_notes' => 'Dinding diperiksa saat check-out.',
            'lines' => [[
                'kind' => 'held_claim',
                'amount_rupiah' => 200000,
                'reason' => 'Klaim kerusakan cat akibat penyewa.',
                'agreement_basis' => 'Kerusakan di luar keausan wajar pada kesepakatan sewa.',
                'evidence' => [UploadedFile::fake()->create('foto-awal.pdf', 10, 'application/pdf')],
            ]],
        ]);
        $claim = $settlement->lines()->firstOrFail();
        $settlements->recordWhatsAppNotification($tenant, $owner, $settlement->id);
        $settlements->approve($tenant, $owner, $settlement->id);
        $resolution = $settlements->finalizeHeldClaim($tenant, $owner, $settlement->id, $claim->id, [
            'actual_damage_amount_rupiah' => 120000,
            'resolution_basis' => 'Biaya aktual berdasarkan kuitansi perbaikan.',
            'occurred_on' => '2026-11-04',
            'method' => 'bank_transfer',
            'external_reference' => 'REFUND-HOLD-001',
            'idempotency_key' => 'claim-resolution:001',
            'evidence' => [UploadedFile::fake()->create('kuitansi-dan-refund.pdf', 10, 'application/pdf')],
        ]);
        $resolutionRetry = $settlements->finalizeHeldClaim($tenant, $owner, $settlement->id, $claim->id, [
            'actual_damage_amount_rupiah' => 120000,
            'resolution_basis' => 'Biaya aktual berdasarkan kuitansi perbaikan.',
            'occurred_on' => '2026-11-04',
            'method' => 'bank_transfer',
            'external_reference' => 'REFUND-HOLD-001',
            'idempotency_key' => 'claim-resolution:001',
            'evidence' => [UploadedFile::fake()->create('kuitansi-dan-refund.pdf', 10, 'application/pdf')],
        ]);

        $this->assertSame(120000, (int) $resolution->actual_damage_amount_rupiah);
        $this->assertSame(80000, (int) $resolution->refund_amount_rupiah);
        $this->assertSame($resolution->id, $resolutionRetry->id);
        $this->assertSame(120000, (int) DepositMovement::query()->where('kind', 'damage_deduction')->sum('amount_rupiah'));
        $this->assertSame(80000, (int) CashTransaction::query()->where('category', 'deposit_refund')->sum('amount_rupiah'));
        $this->assertSame('finalized', $settlement->fresh()->status);
        $this->assertSame(2, DepositEvidence::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_day30_claim_scheduler_marks_late_without_issuing_refund(): void
    {
        Storage::fake('local');
        ['owner' => $owner, 'tenant' => $tenant, 'room' => $room] = $this->createOwnerWithRoom();
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $tenancy = app(TenancyService::class)->createDirect($tenant, $owner, [
            'room_id' => $room->id,
            'resident' => ['full_name' => 'Tenant Test', 'whatsapp_number' => '+628123456789'],
            'planned_start_date' => '2026-11-03',
            'planned_end_exclusive' => '2026-12-03',
            'agreed_monthly_rent_rupiah' => 1500000,
            'required_deposit_rupiah' => 200000,
        ]);
        $deposit = DepositAccount::query()->where('tenancy_id', $tenancy->id)->firstOrFail();
        app(InvoicePaymentService::class)->recordReceipt($tenant, $owner, [
            'amount_rupiah' => 200000,
            'occurred_on' => '2026-11-03',
            'method' => 'cash',
            'idempotency_key' => 'late-claim-receipt',
            'allocations' => [['deposit_account_id' => $deposit->id, 'amount_rupiah' => 200000]],
        ]);
        app(TenancyService::class)->checkOut($tenant, $owner, $tenancy->id, '2026-11-03');
        $settlement = app(DepositSettlementService::class)->open($tenant, $owner, $deposit->id, [
            'exit_inspection_notes' => 'Klaim menunggu kuitansi final.',
            'lines' => [[
                'kind' => 'held_claim', 'amount_rupiah' => 200000,
                'reason' => 'Klaim kerusakan terdokumentasi.',
                'agreement_basis' => 'Kesepakatan sewa.',
                'evidence' => [UploadedFile::fake()->create('klaim.pdf', 10, 'application/pdf')],
            ]],
        ]);
        $service = app(DepositSettlementService::class);
        $service->recordWhatsAppNotification($tenant, $owner, $settlement->id);
        $service->approve($tenant, $owner, $settlement->id);
        $this->travelTo(Carbon::parse('2026-12-04 00:01:00', 'Asia/Jakarta'));

        Artisan::call('deposits:mark-late-settlements');

        $this->assertSame('late', $settlement->fresh()->status);
        $this->assertSame(1, CashTransaction::query()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(200000, app(DepositLedgerService::class)->balance($deposit->fresh()));
    }
}
