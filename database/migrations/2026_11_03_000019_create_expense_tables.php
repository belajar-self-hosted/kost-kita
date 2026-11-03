<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('room_id')->nullable();
            $table->unsignedBigInteger('maintenance_job_id')->nullable();
            $table->string('kind', 24);
            $table->string('category', 100);
            $table->text('description');
            $table->unsignedBigInteger('amount_due_rupiah');
            $table->date('incurred_on');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id'], 'expenses_tenant_id_unique');
            $table->foreign(['tenant_id', 'property_id'], 'expenses_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->restrictOnDelete();
            $table->foreign(['tenant_id', 'property_id', 'room_id'], 'expenses_room_fk')
                ->references(['tenant_id', 'property_id', 'id'])->on('rooms')->restrictOnDelete();
            $table->foreign(['tenant_id', 'maintenance_job_id'], 'expenses_maintenance_fk')
                ->references(['tenant_id', 'id'])->on('maintenance_jobs')->restrictOnDelete();
            $table->index(['tenant_id', 'property_id', 'incurred_on'], 'expenses_property_date_idx');
        });

        Schema::create('expense_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('expense_id');
            $table->unsignedBigInteger('cash_transaction_id');
            $table->string('kind', 16);
            $table->unsignedBigInteger('amount_rupiah');
            $table->unsignedBigInteger('reverses_payment_id')->nullable();
            $table->string('idempotency_key', 160);
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'id'], 'expense_payments_tenant_id_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'expense_payments_idempotency_unique');
            $table->foreign(['tenant_id', 'expense_id'], 'expense_payments_expense_fk')
                ->references(['tenant_id', 'id'])->on('expenses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'cash_transaction_id'], 'expense_payments_cash_fk')
                ->references(['tenant_id', 'id'])->on('cash_transactions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reverses_payment_id'], 'expense_payments_reversal_fk')
                ->references(['tenant_id', 'id'])->on('expense_payments')->restrictOnDelete();
            $table->index(['tenant_id', 'expense_id', 'kind'], 'expense_payments_expense_kind_idx');
        });

        $expenseInvalid = "NEW.kind NOT IN ('maintenance','operational') OR NEW.amount_due_rupiah <= 0";
        $paymentInvalid = "NEW.kind NOT IN ('allocation','reversal') OR NEW.amount_rupiah <= 0 OR ((NEW.kind = 'reversal') != (NEW.reverses_payment_id IS NOT NULL))";
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER expenses_invariants_insert BEFORE INSERT ON expenses WHEN {$expenseInvalid} BEGIN SELECT RAISE(ABORT, 'invalid expense'); END");
            DB::statement("CREATE TRIGGER expense_payments_invariants_insert BEFORE INSERT ON expense_payments WHEN {$paymentInvalid} BEGIN SELECT RAISE(ABORT, 'invalid expense payment'); END");
        } else {
            DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_kind_check CHECK (kind IN ('maintenance','operational'))");
            DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_amount_check CHECK (amount_due_rupiah > 0)');
            DB::statement("ALTER TABLE expense_payments ADD CONSTRAINT expense_payments_kind_check CHECK (kind IN ('allocation','reversal'))");
            DB::statement('ALTER TABLE expense_payments ADD CONSTRAINT expense_payments_amount_check CHECK (amount_rupiah > 0)');
            DB::statement('ALTER TABLE expense_payments ADD CONSTRAINT expense_payments_reversal_check CHECK ((kind = \'reversal\') = (reverses_payment_id IS NOT NULL))');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS expenses_invariants_insert');
            DB::statement('DROP TRIGGER IF EXISTS expense_payments_invariants_insert');
        } else {
            foreach ([
                ['expenses', 'expenses_kind_check'], ['expenses', 'expenses_amount_check'],
                ['expense_payments', 'expense_payments_kind_check'], ['expense_payments', 'expense_payments_amount_check'],
                ['expense_payments', 'expense_payments_reversal_check'],
            ] as [$table, $constraint]) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
            }
        }

        Schema::dropIfExists('expense_payments');
        Schema::dropIfExists('expenses');
    }
};
