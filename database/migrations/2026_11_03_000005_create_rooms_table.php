<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('room_type_id')->nullable();
            $table->string('code', 80);
            $table->string('code_normalized', 160);
            $table->string('operational_status', 32)->default('rentable');
            $table->text('description')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'property_id', 'code_normalized'], 'rooms_property_code_unique');
            $table->unique(['tenant_id', 'id'], 'rooms_tenant_id_unique');
            $table->unique(['tenant_id', 'property_id', 'id'], 'rooms_tenant_property_id_unique');
            $table->foreign(['tenant_id', 'property_id'], 'rooms_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->restrictOnDelete();
            $table->foreign(['tenant_id', 'property_id', 'room_type_id'], 'rooms_room_type_fk')
                ->references(['tenant_id', 'property_id', 'id'])->on('room_types')->restrictOnDelete();
            $table->index(['tenant_id', 'property_id', 'operational_status'], 'rooms_scope_status_idx');
        });

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER rooms_operational_status_check_insert BEFORE INSERT ON rooms WHEN NEW.operational_status NOT IN ('rentable', 'out_of_service', 'archived') BEGIN SELECT RAISE(ABORT, 'invalid rooms.operational_status'); END");
            DB::statement("CREATE TRIGGER rooms_operational_status_check_update BEFORE UPDATE OF operational_status ON rooms WHEN NEW.operational_status NOT IN ('rentable', 'out_of_service', 'archived') BEGIN SELECT RAISE(ABORT, 'invalid rooms.operational_status'); END");
            DB::statement("CREATE TRIGGER rooms_archive_timestamp_check_insert BEFORE INSERT ON rooms WHEN (NEW.operational_status = 'archived' AND NEW.archived_at IS NULL) OR (NEW.operational_status != 'archived' AND NEW.archived_at IS NOT NULL) BEGIN SELECT RAISE(ABORT, 'invalid rooms.archived_at'); END");
            DB::statement("CREATE TRIGGER rooms_archive_timestamp_check_update BEFORE UPDATE OF operational_status, archived_at ON rooms WHEN (NEW.operational_status = 'archived' AND NEW.archived_at IS NULL) OR (NEW.operational_status != 'archived' AND NEW.archived_at IS NOT NULL) BEGIN SELECT RAISE(ABORT, 'invalid rooms.archived_at'); END");
        } else {
            DB::statement("ALTER TABLE rooms ADD CONSTRAINT rooms_operational_status_check CHECK (operational_status IN ('rentable', 'out_of_service', 'archived'))");
            DB::statement("ALTER TABLE rooms ADD CONSTRAINT rooms_archive_timestamp_check CHECK ((operational_status = 'archived' AND archived_at IS NOT NULL) OR (operational_status IN ('rentable', 'out_of_service') AND archived_at IS NULL))");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            foreach ([
                'rooms_operational_status_check_insert', 'rooms_operational_status_check_update',
                'rooms_archive_timestamp_check_insert', 'rooms_archive_timestamp_check_update',
            ] as $trigger) {
                DB::statement('DROP TRIGGER IF EXISTS '.$trigger);
            }
        } else {
            DB::statement('ALTER TABLE rooms DROP CONSTRAINT rooms_operational_status_check');
            DB::statement('ALTER TABLE rooms DROP CONSTRAINT rooms_archive_timestamp_check');
        }

        Schema::dropIfExists('rooms');
    }
};
