<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('room_id')->nullable();
            $table->string('category', 100);
            $table->text('description');
            $table->string('status', 24)->default('reported');
            $table->boolean('blocks_room')->default(false);
            $table->date('scheduled_start_date')->nullable();
            $table->date('scheduled_end_exclusive')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('vendor_name')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id'], 'maintenance_jobs_tenant_id_unique');
            $table->foreign(['tenant_id', 'property_id'], 'maintenance_jobs_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->restrictOnDelete();
            $table->foreign(['tenant_id', 'property_id', 'room_id'], 'maintenance_jobs_room_fk')
                ->references(['tenant_id', 'property_id', 'id'])->on('rooms')->restrictOnDelete();
            $table->index(['tenant_id', 'room_id', 'status', 'scheduled_start_date', 'scheduled_end_exclusive'], 'maintenance_room_schedule_idx');
            $table->index(['tenant_id', 'property_id', 'status'], 'maintenance_property_status_idx');
        });

        $invariant = "NEW.status NOT IN ('reported', 'scheduled', 'completed', 'cancelled') OR ((NEW.scheduled_start_date IS NULL) != (NEW.scheduled_end_exclusive IS NULL)) OR (NEW.scheduled_end_exclusive IS NOT NULL AND NEW.scheduled_end_exclusive <= NEW.scheduled_start_date) OR (NEW.blocks_room = 1 AND NEW.status = 'scheduled' AND (NEW.room_id IS NULL OR NEW.scheduled_start_date IS NULL OR NEW.scheduled_end_exclusive IS NULL))";

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER maintenance_jobs_invariants_insert BEFORE INSERT ON maintenance_jobs WHEN {$invariant} BEGIN SELECT RAISE(ABORT, 'invalid maintenance job'); END");
            DB::statement("CREATE TRIGGER maintenance_jobs_invariants_update BEFORE UPDATE ON maintenance_jobs WHEN {$invariant} BEGIN SELECT RAISE(ABORT, 'invalid maintenance job'); END");
        } else {
            DB::statement("ALTER TABLE maintenance_jobs ADD CONSTRAINT maintenance_jobs_status_check CHECK (status IN ('reported', 'scheduled', 'completed', 'cancelled'))");
            DB::statement('ALTER TABLE maintenance_jobs ADD CONSTRAINT maintenance_jobs_period_pair_check CHECK ((scheduled_start_date IS NULL) = (scheduled_end_exclusive IS NULL))');
            DB::statement('ALTER TABLE maintenance_jobs ADD CONSTRAINT maintenance_jobs_period_check CHECK (scheduled_end_exclusive IS NULL OR scheduled_end_exclusive > scheduled_start_date)');
            DB::statement("ALTER TABLE maintenance_jobs ADD CONSTRAINT maintenance_jobs_blocker_check CHECK (NOT (blocks_room AND status = 'scheduled') OR (room_id IS NOT NULL AND scheduled_start_date IS NOT NULL AND scheduled_end_exclusive IS NOT NULL))");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS maintenance_jobs_invariants_insert');
            DB::statement('DROP TRIGGER IF EXISTS maintenance_jobs_invariants_update');
        } else {
            DB::statement('ALTER TABLE maintenance_jobs DROP CONSTRAINT maintenance_jobs_status_check');
            DB::statement('ALTER TABLE maintenance_jobs DROP CONSTRAINT maintenance_jobs_period_pair_check');
            DB::statement('ALTER TABLE maintenance_jobs DROP CONSTRAINT maintenance_jobs_period_check');
            DB::statement('ALTER TABLE maintenance_jobs DROP CONSTRAINT maintenance_jobs_blocker_check');
        }

        Schema::dropIfExists('maintenance_jobs');
    }
};
