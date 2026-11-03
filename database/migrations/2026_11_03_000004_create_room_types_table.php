<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('property_id');
            $table->string('name', 120);
            $table->string('name_normalized', 160);
            $table->text('description')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'property_id', 'name_normalized'], 'room_types_name_unique');
            $table->unique(['tenant_id', 'property_id', 'id'], 'room_types_tenant_property_id_unique');
            $table->foreign(['tenant_id', 'property_id'], 'room_types_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->restrictOnDelete();
            $table->index(['tenant_id', 'property_id', 'status'], 'room_types_scope_status_idx');
        });

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER room_types_status_check_insert BEFORE INSERT ON room_types WHEN NEW.status NOT IN ('active', 'archived') BEGIN SELECT RAISE(ABORT, 'invalid room_types.status'); END");
            DB::statement("CREATE TRIGGER room_types_status_check_update BEFORE UPDATE OF status ON room_types WHEN NEW.status NOT IN ('active', 'archived') BEGIN SELECT RAISE(ABORT, 'invalid room_types.status'); END");
        } else {
            DB::statement("ALTER TABLE room_types ADD CONSTRAINT room_types_status_check CHECK (status IN ('active', 'archived'))");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS room_types_status_check_insert');
            DB::statement('DROP TRIGGER IF EXISTS room_types_status_check_update');
        } else {
            DB::statement('ALTER TABLE room_types DROP CONSTRAINT room_types_status_check');
        }

        Schema::dropIfExists('room_types');
    }
};
