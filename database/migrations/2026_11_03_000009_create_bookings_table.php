<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('resident_id');
            $table->string('status', 24)->default('pending');
            $table->date('planned_start_date');
            $table->date('planned_end_exclusive');
            $table->unsignedBigInteger('agreed_monthly_rent_rupiah');
            $table->timestampTz('check_in_deadline_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('checked_in_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('no_show_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id'], 'bookings_tenant_id_unique');
            $table->foreign(['tenant_id', 'property_id'], 'bookings_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->restrictOnDelete();
            $table->foreign(['tenant_id', 'property_id', 'room_id'], 'bookings_room_fk')
                ->references(['tenant_id', 'property_id', 'id'])->on('rooms')->restrictOnDelete();
            $table->foreign(['tenant_id', 'resident_id'], 'bookings_resident_fk')
                ->references(['tenant_id', 'id'])->on('residents')->restrictOnDelete();
            $table->index(['tenant_id', 'room_id', 'status', 'planned_start_date', 'planned_end_exclusive'], 'bookings_room_availability_idx');
            $table->index(['tenant_id', 'status', 'check_in_deadline_at'], 'bookings_deadline_idx');
            $table->index(['tenant_id', 'status', 'planned_start_date'], 'bookings_status_start_idx');
        });

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER bookings_invariants_insert BEFORE INSERT ON bookings WHEN NEW.status NOT IN ('pending', 'confirmed', 'checked_in', 'cancelled', 'no_show') OR NEW.planned_end_exclusive <= NEW.planned_start_date OR NEW.agreed_monthly_rent_rupiah <= 0 OR (NEW.status = 'confirmed' AND NEW.check_in_deadline_at IS NULL) BEGIN SELECT RAISE(ABORT, 'invalid bookings row'); END");
            DB::statement("CREATE TRIGGER bookings_invariants_update BEFORE UPDATE ON bookings WHEN NEW.status NOT IN ('pending', 'confirmed', 'checked_in', 'cancelled', 'no_show') OR NEW.planned_end_exclusive <= NEW.planned_start_date OR NEW.agreed_monthly_rent_rupiah <= 0 OR (NEW.status = 'confirmed' AND NEW.check_in_deadline_at IS NULL) BEGIN SELECT RAISE(ABORT, 'invalid bookings row'); END");
        } else {
            DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_status_check CHECK (status IN ('pending', 'confirmed', 'checked_in', 'cancelled', 'no_show'))");
            DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_period_check CHECK (planned_end_exclusive > planned_start_date)');
            DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_rent_check CHECK (agreed_monthly_rent_rupiah > 0)');
            DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_confirmation_deadline_check CHECK (status != 'confirmed' OR check_in_deadline_at IS NOT NULL)");
        }

        Schema::create('booking_grace_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('booking_id');
            $table->timestampTz('old_deadline_at');
            $table->timestampTz('new_deadline_at');
            $table->text('reason');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'idempotency_key'], 'booking_grace_extensions_idempotency_unique');
            $table->foreign(['tenant_id', 'booking_id'], 'booking_grace_extensions_booking_fk')
                ->references(['tenant_id', 'id'])->on('bookings')->restrictOnDelete();
        });

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER booking_grace_extensions_deadline_insert BEFORE INSERT ON booking_grace_extensions WHEN NEW.new_deadline_at <= NEW.old_deadline_at BEGIN SELECT RAISE(ABORT, 'booking grace deadline must increase'); END");
            DB::statement("CREATE TRIGGER booking_grace_extensions_deadline_update BEFORE UPDATE ON booking_grace_extensions WHEN NEW.new_deadline_at <= NEW.old_deadline_at BEGIN SELECT RAISE(ABORT, 'booking grace deadline must increase'); END");
        } else {
            DB::statement('ALTER TABLE booking_grace_extensions ADD CONSTRAINT booking_grace_extensions_deadline_check CHECK (new_deadline_at > old_deadline_at)');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            foreach ([
                'bookings_invariants_insert', 'bookings_invariants_update',
                'booking_grace_extensions_deadline_insert', 'booking_grace_extensions_deadline_update',
            ] as $trigger) {
                DB::statement('DROP TRIGGER IF EXISTS '.$trigger);
            }
        } else {
            DB::statement('ALTER TABLE bookings DROP CONSTRAINT bookings_status_check');
            DB::statement('ALTER TABLE bookings DROP CONSTRAINT bookings_period_check');
            DB::statement('ALTER TABLE bookings DROP CONSTRAINT bookings_rent_check');
            DB::statement('ALTER TABLE bookings DROP CONSTRAINT bookings_confirmation_deadline_check');
            DB::statement('ALTER TABLE booking_grace_extensions DROP CONSTRAINT booking_grace_extensions_deadline_check');
        }

        Schema::dropIfExists('booking_grace_extensions');
        Schema::dropIfExists('bookings');
    }
};
