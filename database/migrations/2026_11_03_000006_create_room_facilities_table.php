<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_facilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('room_id');
            $table->string('label', 100);
            $table->string('normalized_label', 120);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'room_id', 'normalized_label'], 'room_facilities_label_unique');
            $table->foreign(['tenant_id', 'room_id'], 'room_facilities_room_fk')
                ->references(['tenant_id', 'id'])->on('rooms')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_facilities');
    }
};
