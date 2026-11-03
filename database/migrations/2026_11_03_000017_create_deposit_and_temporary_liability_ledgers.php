<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temporary_liabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('source_cash_transaction_id');
            $table->unsignedBigInteger('resident_id')->nullable();
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->unsignedBigInteger('tenancy_id')->nullable();
            $table->unsignedBigInteger('initial_amount_rupiah');
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'id'], 'temporary_liabilities_tenant_id_unique');
            $table->unique(['tenant_id', 'source_cash_transaction_id'], 'temporary_liabilities_source_unique');
            $table->foreign(['tenant_id', 'source_cash_transaction_id'], 'temporary_liabilities_cash_fk')
                ->references(['tenant_id', 'id'])->on('cash_transactions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'resident_id'], 'temporary_liabilities_resident_fk')
                ->references(['tenant_id', 'id'])->on('residents')->restrictOnDelete();
            $table->foreign(['tenant_id', 'booking_id'], 'temporary_liabilities_booking_fk')
                ->references(['tenant_id', 'id'])->on('bookings')->restrictOnDelete();
            $table->foreign(['tenant_id', 'tenancy_id'], 'temporary_liabilities_tenancy_fk')
                ->references(['tenant_id', 'id'])->on('tenancies')->restrictOnDelete();
        });

        Schema::create('temporary_liability_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('temporary_liability_id');
            $table->string('kind', 16);
            $table->unsignedBigInteger('amount_rupiah');
            $table->unsignedBigInteger('cash_transaction_id')->nullable();
            $table->unsignedBigInteger('reversal_of_movement_id')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 160);
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'id'], 'temporary_liability_movements_tenant_id_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'temporary_liability_movements_idempotency_unique');
            $table->foreign(['tenant_id', 'temporary_liability_id'], 'temporary_liability_movements_liability_fk')
                ->references(['tenant_id', 'id'])->on('temporary_liabilities')->restrictOnDelete();
            $table->foreign(['tenant_id', 'cash_transaction_id'], 'temporary_liability_movements_cash_fk')
                ->references(['tenant_id', 'id'])->on('cash_transactions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reversal_of_movement_id'], 'temporary_liability_movements_reversal_fk')
                ->references(['tenant_id', 'id'])->on('temporary_liability_movements')->restrictOnDelete();
        });

        $temporaryInvalid = "NEW.kind NOT IN ('refund','reversal') OR NEW.amount_rupiah <= 0 OR ((NEW.kind = 'reversal') != (NEW.reversal_of_movement_id IS NOT NULL))";
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER temporary_liability_movements_invariants_insert BEFORE INSERT ON temporary_liability_movements WHEN {$temporaryInvalid} BEGIN SELECT RAISE(ABORT, 'invalid temporary liability movement'); END");
            DB::statement('CREATE TRIGGER temporary_liabilities_amount_insert BEFORE INSERT ON temporary_liabilities WHEN NEW.initial_amount_rupiah <= 0 BEGIN SELECT RAISE(ABORT, "invalid temporary liability amount"); END');
        } else {
            DB::statement("ALTER TABLE temporary_liability_movements ADD CONSTRAINT temporary_liability_movements_kind_check CHECK (kind IN ('refund','reversal'))");
            DB::statement('ALTER TABLE temporary_liability_movements ADD CONSTRAINT temporary_liability_movements_amount_check CHECK (amount_rupiah > 0)');
            DB::statement('ALTER TABLE temporary_liabilities ADD CONSTRAINT temporary_liabilities_amount_check CHECK (initial_amount_rupiah > 0)');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS temporary_liability_movements_invariants_insert');
            DB::statement('DROP TRIGGER IF EXISTS temporary_liabilities_amount_insert');
        } else {
            foreach ([
                ['temporary_liability_movements', 'temporary_liability_movements_kind_check'], ['temporary_liability_movements', 'temporary_liability_movements_amount_check'],
                ['temporary_liabilities', 'temporary_liabilities_amount_check'],
            ] as [$table, $constraint]) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
            }
        }

        Schema::dropIfExists('temporary_liability_movements');
        Schema::dropIfExists('temporary_liabilities');
    }
};
