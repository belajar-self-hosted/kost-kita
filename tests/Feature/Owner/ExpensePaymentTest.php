<?php

namespace Tests\Feature\Owner;

use App\Models\CashTransaction;
use App\Models\Expense;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ExpensePaymentService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\OwnerTestData;
use Tests\TestCase;

class ExpensePaymentTest extends TestCase
{
    use OwnerTestData;
    use RefreshDatabase;

    public function test_partial_expense_payment_records_only_actual_cash_and_retries_idempotently(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createOwnerWithRoom();
        $expense = Expense::query()->create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'room_id' => $room->id,
            'kind' => 'maintenance',
            'category' => 'plumbing',
            'description' => 'Perbaikan keran',
            'amount_due_rupiah' => 1000000,
            'incurred_on' => '2026-11-03',
            'created_by' => $owner->id,
        ]);

        $service = app(ExpensePaymentService::class);
        $input = [
            'amount_rupiah' => 400000,
            'occurred_on' => '2026-11-03',
            'method' => 'cash',
            'idempotency_key' => 'expense-payment:1',
        ];
        $payment = $service->recordPayment($tenant, $owner, $expense->id, $input);
        $retry = $service->recordPayment($tenant, $owner, $expense->id, $input);

        $this->assertSame($payment->id, $retry->id);
        $this->assertSame(400000, $expense->fresh()->paidAmount());
        $this->assertSame(600000, $expense->fresh()->outstandingAmount());
        $this->assertSame(1, CashTransaction::query()->where('tenant_id', $tenant->id)->count());
        $this->assertSame('out', $payment->direction);
        $this->assertSame('maintenance_expense', $payment->category);

        try {
            $service->recordPayment($tenant, $owner, $expense->id, [
                'amount_rupiah' => 700000,
                'occurred_on' => '2026-11-04',
                'method' => 'cash',
                'idempotency_key' => 'expense-payment:stale-balance',
            ]);
            $this->fail('Expected a stale expense balance to reject an overpayment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount_rupiah', $exception->errors());
            $this->assertSame(1, CashTransaction::query()->where('tenant_id', $tenant->id)->count());
        }
    }

    public function test_expense_payment_cannot_exceed_remaining_obligation(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property] = $this->createOwnerWithRoom();
        $expense = Expense::query()->create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'kind' => 'operational',
            'category' => 'internet',
            'description' => 'Internet bulan ini',
            'amount_due_rupiah' => 200000,
            'incurred_on' => '2026-11-03',
            'created_by' => $owner->id,
        ]);

        try {
            app(ExpensePaymentService::class)->recordPayment($tenant, $owner, $expense->id, [
                'amount_rupiah' => 200001,
                'occurred_on' => '2026-11-03',
                'method' => 'bank_transfer',
                'idempotency_key' => 'expense-payment:over',
            ]);
            $this->fail('Expected expense overpayment to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount_rupiah', $exception->errors());
            $this->assertSame(0, CashTransaction::query()->count());
        }
    }

    public function test_expense_payment_target_is_scoped_to_the_current_tenant(): void
    {
        ['owner' => $owner, 'tenant' => $tenant] = $this->createOwnerWithRoom();
        $otherOwner = User::factory()->create()->refresh();
        $otherTenant = Tenant::query()->create(['owner_user_id' => $otherOwner->id, 'display_name' => 'Other Owner']);
        $otherProperty = $otherTenant->properties()->create(['name' => 'Other Kost']);
        $expense = Expense::query()->create([
            'tenant_id' => $otherTenant->id,
            'property_id' => $otherProperty->id,
            'kind' => 'operational',
            'category' => 'internet',
            'description' => 'Internet tenant lain',
            'amount_due_rupiah' => 200000,
            'incurred_on' => '2026-11-03',
            'created_by' => $otherOwner->id,
        ]);

        $this->expectException(ModelNotFoundException::class);
        app(ExpensePaymentService::class)->recordPayment($tenant, $owner, $expense->id, [
            'amount_rupiah' => 100000,
            'occurred_on' => '2026-11-03',
            'method' => 'bank_transfer',
            'idempotency_key' => 'cross-tenant-expense',
        ]);
    }
}
