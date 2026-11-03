<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->unsignedBigInteger('tenancy_id')->nullable();
            $table->unsignedBigInteger('required_amount_rupiah')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id'], 'deposit_accounts_tenant_id_unique');
            $table->unique(['tenant_id', 'booking_id'], 'deposit_accounts_booking_unique');
            $table->unique(['tenant_id', 'tenancy_id'], 'deposit_accounts_tenancy_unique');
            $table->foreign(['tenant_id', 'booking_id'], 'deposit_accounts_booking_fk')
                ->references(['tenant_id', 'id'])->on('bookings')->restrictOnDelete();
            $table->foreign(['tenant_id', 'tenancy_id'], 'deposit_accounts_tenancy_fk')
                ->references(['tenant_id', 'id'])->on('tenancies')->restrictOnDelete();
            $table->index(['tenant_id', 'booking_id', 'tenancy_id'], 'deposit_accounts_sources_idx');
        });

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER deposit_accounts_invariants_insert BEFORE INSERT ON deposit_accounts WHEN (NEW.booking_id IS NULL AND NEW.tenancy_id IS NULL) OR NEW.required_amount_rupiah < 0 BEGIN SELECT RAISE(ABORT, 'invalid deposit account'); END");
            DB::statement("CREATE TRIGGER deposit_accounts_invariants_update BEFORE UPDATE ON deposit_accounts WHEN (NEW.booking_id IS NULL AND NEW.tenancy_id IS NULL) OR NEW.required_amount_rupiah < 0 BEGIN SELECT RAISE(ABORT, 'invalid deposit account'); END");
        } else {
            DB::statement('ALTER TABLE deposit_accounts ADD CONSTRAINT deposit_accounts_source_check CHECK (booking_id IS NOT NULL OR tenancy_id IS NOT NULL)');
            DB::statement('ALTER TABLE deposit_accounts ADD CONSTRAINT deposit_accounts_amount_check CHECK (required_amount_rupiah >= 0)');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS deposit_accounts_invariants_insert');
            DB::statement('DROP TRIGGER IF EXISTS deposit_accounts_invariants_update');
        } else {
            DB::statement('ALTER TABLE deposit_accounts DROP CONSTRAINT deposit_accounts_source_check');
            DB::statement('ALTER TABLE deposit_accounts DROP CONSTRAINT deposit_accounts_amount_check');
        }

        Schema::dropIfExists('deposit_accounts');
    }
};
