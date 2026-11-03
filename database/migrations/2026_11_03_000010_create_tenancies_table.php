<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('resident_id');
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->string('status', 24);
            $table->date('planned_start_date');
            $table->date('planned_end_exclusive');
            $table->date('actual_start_date')->nullable();
            $table->timestampTz('actual_check_in_at')->nullable();
            $table->date('actual_checkout_date')->nullable();
            $table->timestampTz('checkout_recorded_at')->nullable();
            $table->unsignedBigInteger('agreed_monthly_rent_rupiah');
            $table->unsignedTinyInteger('billing_anchor_day')->nullable();
            $table->text('payment_terms')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id'], 'tenancies_tenant_id_unique');
            $table->unique(['tenant_id', 'booking_id'], 'tenancies_booking_unique');
            $table->foreign(['tenant_id', 'property_id'], 'tenancies_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->restrictOnDelete();
            $table->foreign(['tenant_id', 'property_id', 'room_id'], 'tenancies_room_fk')
                ->references(['tenant_id', 'property_id', 'id'])->on('rooms')->restrictOnDelete();
            $table->foreign(['tenant_id', 'resident_id'], 'tenancies_resident_fk')
                ->references(['tenant_id', 'id'])->on('residents')->restrictOnDelete();
            $table->foreign(['tenant_id', 'booking_id'], 'tenancies_booking_fk')
                ->references(['tenant_id', 'id'])->on('bookings')->restrictOnDelete();
            $table->index(['tenant_id', 'room_id', 'status', 'planned_start_date', 'planned_end_exclusive'], 'tenancies_room_planned_idx');
            $table->index(['tenant_id', 'room_id', 'status', 'actual_start_date', 'actual_checkout_date'], 'tenancies_room_actual_idx');
            $table->index(['tenant_id', 'status', 'planned_end_exclusive'], 'tenancies_status_end_idx');
            $table->index(['tenant_id', 'resident_id', 'planned_start_date'], 'tenancies_resident_start_idx');
        });

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER tenancies_invariants_insert BEFORE INSERT ON tenancies WHEN NEW.status NOT IN ('scheduled', 'active', 'completed', 'terminated') OR NEW.planned_end_exclusive <= NEW.planned_start_date OR NEW.agreed_monthly_rent_rupiah <= 0 OR (NEW.billing_anchor_day IS NOT NULL AND (NEW.billing_anchor_day < 1 OR NEW.billing_anchor_day > 31)) OR (NEW.actual_checkout_date IS NOT NULL AND (NEW.actual_start_date IS NULL OR NEW.actual_checkout_date < NEW.actual_start_date)) BEGIN SELECT RAISE(ABORT, 'invalid tenancies row'); END");
            DB::statement("CREATE TRIGGER tenancies_invariants_update BEFORE UPDATE ON tenancies WHEN NEW.status NOT IN ('scheduled', 'active', 'completed', 'terminated') OR NEW.planned_end_exclusive <= NEW.planned_start_date OR NEW.agreed_monthly_rent_rupiah <= 0 OR (NEW.billing_anchor_day IS NOT NULL AND (NEW.billing_anchor_day < 1 OR NEW.billing_anchor_day > 31)) OR (NEW.actual_checkout_date IS NOT NULL AND (NEW.actual_start_date IS NULL OR NEW.actual_checkout_date < NEW.actual_start_date)) BEGIN SELECT RAISE(ABORT, 'invalid tenancies row'); END");
        } else {
            DB::statement("ALTER TABLE tenancies ADD CONSTRAINT tenancies_status_check CHECK (status IN ('scheduled', 'active', 'completed', 'terminated'))");
            DB::statement('ALTER TABLE tenancies ADD CONSTRAINT tenancies_period_check CHECK (planned_end_exclusive > planned_start_date)');
            DB::statement('ALTER TABLE tenancies ADD CONSTRAINT tenancies_rent_check CHECK (agreed_monthly_rent_rupiah > 0)');
            DB::statement('ALTER TABLE tenancies ADD CONSTRAINT tenancies_anchor_day_check CHECK (billing_anchor_day IS NULL OR billing_anchor_day BETWEEN 1 AND 31)');
            DB::statement('ALTER TABLE tenancies ADD CONSTRAINT tenancies_checkout_date_check CHECK (actual_checkout_date IS NULL OR (actual_start_date IS NOT NULL AND actual_checkout_date >= actual_start_date))');
        }

        Schema::create('tenancy_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('tenancy_id');
            $table->date('old_planned_end_exclusive');
            $table->date('new_planned_end_exclusive');
            $table->unsignedBigInteger('old_monthly_rent_rupiah');
            $table->unsignedBigInteger('new_monthly_rent_rupiah');
            $table->date('effective_from');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'id'], 'tenancy_extensions_tenant_id_unique');
            $table->foreign(['tenant_id', 'tenancy_id'], 'tenancy_extensions_tenancy_fk')
                ->references(['tenant_id', 'id'])->on('tenancies')->restrictOnDelete();
            $table->index(['tenant_id', 'tenancy_id', 'effective_from'], 'tenancy_extensions_effective_idx');
        });

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER tenancy_extensions_invariants_insert BEFORE INSERT ON tenancy_extensions WHEN NEW.new_planned_end_exclusive <= NEW.old_planned_end_exclusive OR NEW.old_monthly_rent_rupiah <= 0 OR NEW.new_monthly_rent_rupiah <= 0 BEGIN SELECT RAISE(ABORT, 'invalid tenancy extension'); END");
            DB::statement("CREATE TRIGGER tenancy_extensions_invariants_update BEFORE UPDATE ON tenancy_extensions WHEN NEW.new_planned_end_exclusive <= NEW.old_planned_end_exclusive OR NEW.old_monthly_rent_rupiah <= 0 OR NEW.new_monthly_rent_rupiah <= 0 BEGIN SELECT RAISE(ABORT, 'invalid tenancy extension'); END");
        } else {
            DB::statement('ALTER TABLE tenancy_extensions ADD CONSTRAINT tenancy_extensions_period_check CHECK (new_planned_end_exclusive > old_planned_end_exclusive)');
            DB::statement('ALTER TABLE tenancy_extensions ADD CONSTRAINT tenancy_extensions_rent_check CHECK (old_monthly_rent_rupiah > 0 AND new_monthly_rent_rupiah > 0)');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            foreach ([
                'tenancies_invariants_insert', 'tenancies_invariants_update',
                'tenancy_extensions_invariants_insert', 'tenancy_extensions_invariants_update',
            ] as $trigger) {
                DB::statement('DROP TRIGGER IF EXISTS '.$trigger);
            }
        } else {
            DB::statement('ALTER TABLE tenancies DROP CONSTRAINT tenancies_status_check');
            DB::statement('ALTER TABLE tenancies DROP CONSTRAINT tenancies_period_check');
            DB::statement('ALTER TABLE tenancies DROP CONSTRAINT tenancies_rent_check');
            DB::statement('ALTER TABLE tenancies DROP CONSTRAINT tenancies_anchor_day_check');
            DB::statement('ALTER TABLE tenancies DROP CONSTRAINT tenancies_checkout_date_check');
            DB::statement('ALTER TABLE tenancy_extensions DROP CONSTRAINT tenancy_extensions_period_check');
            DB::statement('ALTER TABLE tenancy_extensions DROP CONSTRAINT tenancy_extensions_rent_check');
        }

        Schema::dropIfExists('tenancy_extensions');
        Schema::dropIfExists('tenancies');
    }
};
