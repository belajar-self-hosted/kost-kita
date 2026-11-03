<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX deposit_settlements_account_unique');
        } else {
            DB::statement('ALTER TABLE deposit_settlements DROP CONSTRAINT deposit_settlements_account_unique');
        }
        DB::statement("CREATE UNIQUE INDEX deposit_settlements_active_account_unique ON deposit_settlements (tenant_id, deposit_account_id) WHERE status <> 'cancelled'");

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS deposit_settlements_status_insert');
            DB::statement("CREATE TRIGGER deposit_settlements_status_insert BEFORE INSERT ON deposit_settlements WHEN NEW.status NOT IN ('draft','approved','partially_settled','finalized','late','cancelled') BEGIN SELECT RAISE(ABORT, 'invalid deposit settlement status'); END");
            DB::statement("CREATE TRIGGER deposit_settlements_status_update BEFORE UPDATE OF status ON deposit_settlements WHEN NEW.status NOT IN ('draft','approved','partially_settled','finalized','late','cancelled') BEGIN SELECT RAISE(ABORT, 'invalid deposit settlement status'); END");
        } else {
            DB::statement('ALTER TABLE deposit_settlements DROP CONSTRAINT deposit_settlements_status_check');
            DB::statement("ALTER TABLE deposit_settlements ADD CONSTRAINT deposit_settlements_status_check CHECK (status IN ('draft','approved','partially_settled','finalized','late','cancelled'))");
        }
    }

    public function down(): void
    {
        if (DB::table('deposit_settlements')->where('status', 'cancelled')->exists()) {
            throw new RuntimeException('Cancelled settlement history prevents restoring the one-settlement-per-account constraint.');
        }

        DB::statement('DROP INDEX deposit_settlements_active_account_unique');

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS deposit_settlements_status_insert');
            DB::statement('DROP TRIGGER IF EXISTS deposit_settlements_status_update');
            DB::statement("CREATE TRIGGER deposit_settlements_status_insert BEFORE INSERT ON deposit_settlements WHEN NEW.status NOT IN ('draft','approved','partially_settled','finalized','late') BEGIN SELECT RAISE(ABORT, 'invalid deposit settlement status'); END");
        } else {
            DB::statement('ALTER TABLE deposit_settlements DROP CONSTRAINT deposit_settlements_status_check');
            DB::statement("ALTER TABLE deposit_settlements ADD CONSTRAINT deposit_settlements_status_check CHECK (status IN ('draft','approved','partially_settled','finalized','late'))");
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX deposit_settlements_account_unique ON deposit_settlements (tenant_id, deposit_account_id)');
        } else {
            DB::statement('ALTER TABLE deposit_settlements ADD CONSTRAINT deposit_settlements_account_unique UNIQUE (tenant_id, deposit_account_id)');
        }
    }
};
