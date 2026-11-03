<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('room_id')->nullable();
            $table->unsignedBigInteger('room_type_id')->nullable();
            $table->unsignedBigInteger('monthly_rent_rupiah');
            $table->date('effective_from');
            $table->date('effective_until_exclusive')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id'], 'room_prices_tenant_id_unique');
            $table->unique(['tenant_id', 'property_id', 'room_id', 'effective_from'], 'room_prices_room_start_unique');
            $table->unique(['tenant_id', 'property_id', 'room_type_id', 'effective_from'], 'room_prices_type_start_unique');
            $table->foreign(['tenant_id', 'property_id'], 'room_prices_property_fk')
                ->references(['tenant_id', 'id'])->on('properties')->restrictOnDelete();
            $table->foreign(['tenant_id', 'property_id', 'room_id'], 'room_prices_room_fk')
                ->references(['tenant_id', 'property_id', 'id'])->on('rooms')->restrictOnDelete();
            $table->foreign(['tenant_id', 'property_id', 'room_type_id'], 'room_prices_room_type_fk')
                ->references(['tenant_id', 'property_id', 'id'])->on('room_types')->restrictOnDelete();
            $table->index(['tenant_id', 'property_id', 'effective_from', 'effective_until_exclusive'], 'room_prices_effective_idx');
        });

        $valid = 'monthly_rent_rupiah > 0 AND ((room_id IS NOT NULL AND room_type_id IS NULL) OR (room_id IS NULL AND room_type_id IS NOT NULL)) AND (effective_until_exclusive IS NULL OR effective_until_exclusive > effective_from)';

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER room_prices_check_insert BEFORE INSERT ON room_prices WHEN NOT ($valid) BEGIN SELECT RAISE(ABORT, 'invalid room_prices record'); END");
            DB::statement("CREATE TRIGGER room_prices_check_update BEFORE UPDATE ON room_prices WHEN NOT ($valid) BEGIN SELECT RAISE(ABORT, 'invalid room_prices record'); END");
        } else {
            DB::statement("ALTER TABLE room_prices ADD CONSTRAINT room_prices_check CHECK ($valid)");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS room_prices_check_insert');
            DB::statement('DROP TRIGGER IF EXISTS room_prices_check_update');
        } else {
            DB::statement('ALTER TABLE room_prices DROP CONSTRAINT room_prices_check');
        }

        Schema::dropIfExists('room_prices');
    }
};
