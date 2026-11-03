<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->unsignedBigInteger('tenancy_id')->nullable();
            $table->string('invoice_number', 48);
            $table->string('idempotency_key', 160);
            $table->timestampTz('issued_at');
            $table->date('due_date');
            $table->date('cycle_start_date')->nullable();
            $table->date('cycle_end_exclusive')->nullable();
            $table->unsignedTinyInteger('billing_anchor_day')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id'], 'invoices_tenant_id_unique');
            $table->unique(['tenant_id', 'invoice_number'], 'invoices_number_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'invoices_idempotency_unique');
            $table->unique(['tenant_id', 'tenancy_id', 'cycle_start_date', 'cycle_end_exclusive'], 'invoices_cycle_unique');
            $table->foreign(['tenant_id', 'booking_id'], 'invoices_booking_fk')
                ->references(['tenant_id', 'id'])->on('bookings')->restrictOnDelete();
            $table->foreign(['tenant_id', 'tenancy_id'], 'invoices_tenancy_fk')
                ->references(['tenant_id', 'id'])->on('tenancies')->restrictOnDelete();
            $table->index(['tenant_id', 'due_date'], 'invoices_due_date_idx');
            $table->index(['tenant_id', 'tenancy_id', 'cycle_start_date'], 'invoices_tenancy_cycle_idx');
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedSmallInteger('line_number');
            $table->string('kind', 32);
            $table->string('description');
            $table->unsignedBigInteger('amount_rupiah');
            $table->unsignedBigInteger('monthly_rent_snapshot_rupiah')->nullable();
            $table->date('service_start_date')->nullable();
            $table->date('service_end_exclusive')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'invoice_id', 'line_number'], 'invoice_lines_number_unique');
            $table->unique(['tenant_id', 'id'], 'invoice_lines_tenant_id_unique');
            $table->foreign(['tenant_id', 'invoice_id'], 'invoice_lines_invoice_fk')
                ->references(['tenant_id', 'id'])->on('invoices')->restrictOnDelete();
        });

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER invoices_invariants_insert BEFORE INSERT ON invoices WHEN ((NEW.booking_id IS NULL) = (NEW.tenancy_id IS NULL)) OR ((NEW.cycle_start_date IS NULL) != (NEW.cycle_end_exclusive IS NULL)) OR (NEW.cycle_end_exclusive IS NOT NULL AND NEW.cycle_end_exclusive <= NEW.cycle_start_date) OR (NEW.billing_anchor_day IS NOT NULL AND (NEW.billing_anchor_day < 1 OR NEW.billing_anchor_day > 31)) BEGIN SELECT RAISE(ABORT, 'invalid invoices row'); END");
            DB::statement("CREATE TRIGGER invoices_invariants_update BEFORE UPDATE ON invoices WHEN ((NEW.booking_id IS NULL) = (NEW.tenancy_id IS NULL)) OR ((NEW.cycle_start_date IS NULL) != (NEW.cycle_end_exclusive IS NULL)) OR (NEW.cycle_end_exclusive IS NOT NULL AND NEW.cycle_end_exclusive <= NEW.cycle_start_date) OR (NEW.billing_anchor_day IS NOT NULL AND (NEW.billing_anchor_day < 1 OR NEW.billing_anchor_day > 31)) BEGIN SELECT RAISE(ABORT, 'invalid invoices row'); END");
            DB::statement("CREATE TRIGGER invoice_lines_invariants_insert BEFORE INSERT ON invoice_lines WHEN NEW.kind NOT IN ('rent', 'agreed_charge', 'other') OR NEW.amount_rupiah <= 0 OR (NEW.kind = 'rent' AND (NEW.monthly_rent_snapshot_rupiah IS NULL OR NEW.service_start_date IS NULL OR NEW.service_end_exclusive IS NULL)) OR ((NEW.service_start_date IS NULL) != (NEW.service_end_exclusive IS NULL)) OR (NEW.service_end_exclusive IS NOT NULL AND NEW.service_end_exclusive <= NEW.service_start_date) BEGIN SELECT RAISE(ABORT, 'invalid invoice line'); END");
            DB::statement("CREATE TRIGGER invoice_lines_invariants_update BEFORE UPDATE ON invoice_lines WHEN NEW.kind NOT IN ('rent', 'agreed_charge', 'other') OR NEW.amount_rupiah <= 0 OR (NEW.kind = 'rent' AND (NEW.monthly_rent_snapshot_rupiah IS NULL OR NEW.service_start_date IS NULL OR NEW.service_end_exclusive IS NULL)) OR ((NEW.service_start_date IS NULL) != (NEW.service_end_exclusive IS NULL)) OR (NEW.service_end_exclusive IS NOT NULL AND NEW.service_end_exclusive <= NEW.service_start_date) BEGIN SELECT RAISE(ABORT, 'invalid invoice line'); END");
        } else {
            DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_source_check CHECK ((booking_id IS NULL) <> (tenancy_id IS NULL))');
            DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_cycle_pair_check CHECK ((cycle_start_date IS NULL) = (cycle_end_exclusive IS NULL))');
            DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_cycle_range_check CHECK (cycle_end_exclusive IS NULL OR cycle_end_exclusive > cycle_start_date)');
            DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_anchor_check CHECK (billing_anchor_day IS NULL OR billing_anchor_day BETWEEN 1 AND 31)');
            DB::statement("ALTER TABLE invoice_lines ADD CONSTRAINT invoice_lines_kind_check CHECK (kind IN ('rent', 'agreed_charge', 'other'))");
            DB::statement('ALTER TABLE invoice_lines ADD CONSTRAINT invoice_lines_amount_check CHECK (amount_rupiah > 0)');
            DB::statement("ALTER TABLE invoice_lines ADD CONSTRAINT invoice_lines_rent_snapshot_check CHECK (kind != 'rent' OR (monthly_rent_snapshot_rupiah IS NOT NULL AND service_start_date IS NOT NULL AND service_end_exclusive IS NOT NULL))");
            DB::statement('ALTER TABLE invoice_lines ADD CONSTRAINT invoice_lines_service_pair_check CHECK ((service_start_date IS NULL) = (service_end_exclusive IS NULL))');
            DB::statement('ALTER TABLE invoice_lines ADD CONSTRAINT invoice_lines_service_range_check CHECK (service_end_exclusive IS NULL OR service_end_exclusive > service_start_date)');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            foreach ([
                'invoices_invariants_insert', 'invoices_invariants_update',
                'invoice_lines_invariants_insert', 'invoice_lines_invariants_update',
            ] as $trigger) {
                DB::statement('DROP TRIGGER IF EXISTS '.$trigger);
            }
        } else {
            foreach ([
                ['invoice_lines', 'invoice_lines_kind_check'], ['invoice_lines', 'invoice_lines_amount_check'],
                ['invoice_lines', 'invoice_lines_rent_snapshot_check'], ['invoice_lines', 'invoice_lines_service_pair_check'],
                ['invoice_lines', 'invoice_lines_service_range_check'], ['invoices', 'invoices_source_check'],
                ['invoices', 'invoices_cycle_pair_check'], ['invoices', 'invoices_cycle_range_check'], ['invoices', 'invoices_anchor_check'],
            ] as [$table, $constraint]) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
            }
        }

        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
