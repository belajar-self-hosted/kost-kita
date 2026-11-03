<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name');
            $table->text('address')->nullable();
            $table->string('status')->default('active');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status']);
        });

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER properties_status_check_insert BEFORE INSERT ON properties WHEN NEW.status NOT IN ('active', 'archived') BEGIN SELECT RAISE(ABORT, 'invalid properties.status'); END");
            DB::statement("CREATE TRIGGER properties_status_check_update BEFORE UPDATE OF status ON properties WHEN NEW.status NOT IN ('active', 'archived') BEGIN SELECT RAISE(ABORT, 'invalid properties.status'); END");
            DB::statement("CREATE TRIGGER properties_archive_timestamp_check_insert BEFORE INSERT ON properties WHEN (NEW.status = 'archived' AND NEW.archived_at IS NULL) OR (NEW.status = 'active' AND NEW.archived_at IS NOT NULL) BEGIN SELECT RAISE(ABORT, 'invalid properties.archived_at'); END");
            DB::statement("CREATE TRIGGER properties_archive_timestamp_check_update BEFORE UPDATE OF status, archived_at ON properties WHEN (NEW.status = 'archived' AND NEW.archived_at IS NULL) OR (NEW.status = 'active' AND NEW.archived_at IS NOT NULL) BEGIN SELECT RAISE(ABORT, 'invalid properties.archived_at'); END");
        } else {
            DB::statement("ALTER TABLE properties ADD CONSTRAINT properties_status_check CHECK (status IN ('active', 'archived'))");
            DB::statement("ALTER TABLE properties ADD CONSTRAINT properties_archive_timestamp_check CHECK ((status = 'archived' AND archived_at IS NOT NULL) OR (status = 'active' AND archived_at IS NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
