<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('invoice_id');
            $table->string('kind', 16);
            $table->unsignedBigInteger('amount_rupiah');
            $table->text('reason');
            $table->date('proration_start_date')->nullable();
            $table->date('proration_end_exclusive')->nullable();
            $table->unsignedSmallInteger('used_days')->nullable();
            $table->unsignedSmallInteger('cycle_days')->nullable();
            $table->unsignedBigInteger('monthly_rent_snapshot_rupiah')->nullable();
            $table->unsignedBigInteger('reference_adjustment_id')->nullable();
            $table->string('idempotency_key', 160);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'id'], 'invoice_adjustments_tenant_id_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'invoice_adjustments_idempotency_unique');
            $table->foreign(['tenant_id', 'invoice_id'], 'invoice_adjustments_invoice_fk')
                ->references(['tenant_id', 'id'])->on('invoices')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reference_adjustment_id'], 'invoice_adjustments_reference_fk')
                ->references(['tenant_id', 'id'])->on('invoice_adjustments')->restrictOnDelete();
        });

        $prorationInvalid = '(NEW.proration_start_date IS NULL) != (NEW.proration_end_exclusive IS NULL) OR (NEW.proration_end_exclusive IS NOT NULL AND NEW.proration_end_exclusive <= NEW.proration_start_date) OR ((NEW.used_days IS NULL) != (NEW.cycle_days IS NULL)) OR (NEW.used_days IS NOT NULL AND (NEW.cycle_days IS NULL OR NEW.used_days < 1 OR NEW.used_days > NEW.cycle_days))';

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER invoice_adjustments_invariants_insert BEFORE INSERT ON invoice_adjustments WHEN NEW.kind NOT IN ('credit', 'debit') OR NEW.amount_rupiah <= 0 OR {$prorationInvalid} BEGIN SELECT RAISE(ABORT, 'invalid invoice adjustment'); END");
            DB::statement("CREATE TRIGGER invoice_adjustments_invariants_update BEFORE UPDATE ON invoice_adjustments WHEN NEW.kind NOT IN ('credit', 'debit') OR NEW.amount_rupiah <= 0 OR {$prorationInvalid} BEGIN SELECT RAISE(ABORT, 'invalid invoice adjustment'); END");
        } else {
            DB::statement("ALTER TABLE invoice_adjustments ADD CONSTRAINT invoice_adjustments_kind_check CHECK (kind IN ('credit', 'debit'))");
            DB::statement('ALTER TABLE invoice_adjustments ADD CONSTRAINT invoice_adjustments_amount_check CHECK (amount_rupiah > 0)');
            DB::statement('ALTER TABLE invoice_adjustments ADD CONSTRAINT invoice_adjustments_proration_pair_check CHECK ((proration_start_date IS NULL) = (proration_end_exclusive IS NULL))');
            DB::statement('ALTER TABLE invoice_adjustments ADD CONSTRAINT invoice_adjustments_proration_range_check CHECK (proration_end_exclusive IS NULL OR proration_end_exclusive > proration_start_date)');
            DB::statement('ALTER TABLE invoice_adjustments ADD CONSTRAINT invoice_adjustments_proration_days_check CHECK ((used_days IS NULL) = (cycle_days IS NULL) AND (used_days IS NULL OR used_days BETWEEN 1 AND cycle_days))');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS invoice_adjustments_invariants_insert');
            DB::statement('DROP TRIGGER IF EXISTS invoice_adjustments_invariants_update');
        } else {
            foreach ([
                'invoice_adjustments_kind_check', 'invoice_adjustments_amount_check',
                'invoice_adjustments_proration_pair_check', 'invoice_adjustments_proration_range_check',
                'invoice_adjustments_proration_days_check',
            ] as $constraint) {
                DB::statement("ALTER TABLE invoice_adjustments DROP CONSTRAINT {$constraint}");
            }
        }

        Schema::dropIfExists('invoice_adjustments');
    }
};
