<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('direction', 8);
            $table->string('category', 40);
            $table->unsignedBigInteger('amount_rupiah');
            $table->date('occurred_on');
            $table->string('method', 32);
            $table->string('external_reference', 160)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 160);
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'id'], 'cash_transactions_tenant_id_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'cash_transactions_idempotency_unique');
            $table->foreign(['tenant_id', 'reversal_of_id'], 'cash_transactions_reversal_fk')
                ->references(['tenant_id', 'id'])->on('cash_transactions')->restrictOnDelete();
            $table->index(['tenant_id', 'occurred_on', 'category'], 'cash_transactions_date_category_idx');
        });

        Schema::create('invoice_cash_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('cash_transaction_id');
            $table->unsignedBigInteger('invoice_id');
            $table->string('kind', 16);
            $table->unsignedBigInteger('amount_rupiah');
            $table->unsignedBigInteger('reverses_allocation_id')->nullable();
            $table->string('idempotency_key', 160);
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'id'], 'invoice_cash_allocations_tenant_id_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'invoice_cash_allocations_idempotency_unique');
            $table->foreign(['tenant_id', 'cash_transaction_id'], 'invoice_allocations_cash_fk')
                ->references(['tenant_id', 'id'])->on('cash_transactions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'invoice_id'], 'invoice_allocations_invoice_fk')
                ->references(['tenant_id', 'id'])->on('invoices')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reverses_allocation_id'], 'invoice_allocations_reversal_fk')
                ->references(['tenant_id', 'id'])->on('invoice_cash_allocations')->restrictOnDelete();
            $table->index(['tenant_id', 'invoice_id', 'kind'], 'invoice_allocations_invoice_kind_idx');
        });

        $cashInvalid = "NEW.direction NOT IN ('in','out') OR NEW.category NOT IN ('rent_receipt','mixed_receipt','rent_refund','deposit_receipt','deposit_refund','temporary_liability_refund','maintenance_expense','operating_expense') OR NEW.amount_rupiah <= 0 OR ((NEW.category IN ('rent_receipt','mixed_receipt','deposit_receipt')) != (NEW.direction = 'in'))";
        $allocationInvalid = "NEW.kind NOT IN ('allocation','reversal') OR NEW.amount_rupiah <= 0 OR ((NEW.kind = 'reversal') != (NEW.reverses_allocation_id IS NOT NULL))";
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER cash_transactions_invariants_insert BEFORE INSERT ON cash_transactions WHEN {$cashInvalid} BEGIN SELECT RAISE(ABORT, 'invalid cash transaction'); END");
            DB::statement("CREATE TRIGGER invoice_cash_allocations_invariants_insert BEFORE INSERT ON invoice_cash_allocations WHEN {$allocationInvalid} BEGIN SELECT RAISE(ABORT, 'invalid invoice allocation'); END");
        } else {
            DB::statement("ALTER TABLE cash_transactions ADD CONSTRAINT cash_transactions_direction_check CHECK (direction IN ('in','out'))");
            DB::statement("ALTER TABLE cash_transactions ADD CONSTRAINT cash_transactions_category_check CHECK (category IN ('rent_receipt','mixed_receipt','rent_refund','deposit_receipt','deposit_refund','temporary_liability_refund','maintenance_expense','operating_expense'))");
            DB::statement('ALTER TABLE cash_transactions ADD CONSTRAINT cash_transactions_amount_check CHECK (amount_rupiah > 0)');
            DB::statement("ALTER TABLE cash_transactions ADD CONSTRAINT cash_transactions_category_direction_check CHECK ((category IN ('rent_receipt','mixed_receipt','deposit_receipt') AND direction = 'in') OR (category NOT IN ('rent_receipt','mixed_receipt','deposit_receipt') AND direction = 'out'))");
            DB::statement("ALTER TABLE invoice_cash_allocations ADD CONSTRAINT invoice_cash_allocations_kind_check CHECK (kind IN ('allocation','reversal'))");
            DB::statement('ALTER TABLE invoice_cash_allocations ADD CONSTRAINT invoice_cash_allocations_amount_check CHECK (amount_rupiah > 0)');
            DB::statement("ALTER TABLE invoice_cash_allocations ADD CONSTRAINT invoice_cash_allocations_reversal_check CHECK ((kind = 'reversal') = (reverses_allocation_id IS NOT NULL))");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS invoice_cash_allocations_invariants_insert');
            DB::statement('DROP TRIGGER IF EXISTS cash_transactions_invariants_insert');
        } else {
            foreach ([
                ['invoice_cash_allocations', 'invoice_cash_allocations_kind_check'],
                ['invoice_cash_allocations', 'invoice_cash_allocations_amount_check'],
                ['invoice_cash_allocations', 'invoice_cash_allocations_reversal_check'],
                ['cash_transactions', 'cash_transactions_direction_check'],
                ['cash_transactions', 'cash_transactions_category_check'],
                ['cash_transactions', 'cash_transactions_amount_check'],
                ['cash_transactions', 'cash_transactions_category_direction_check'],
            ] as [$table, $constraint]) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
            }
        }

        Schema::dropIfExists('invoice_cash_allocations');
        Schema::dropIfExists('cash_transactions');
    }
};
