<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('residents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('full_name', 160);
            $table->string('whatsapp_number', 40);
            $table->text('contact_info')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id'], 'residents_tenant_id_unique');
            $table->index(['tenant_id', 'archived_at', 'full_name'], 'residents_scope_archive_name_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('residents');
    }
};
