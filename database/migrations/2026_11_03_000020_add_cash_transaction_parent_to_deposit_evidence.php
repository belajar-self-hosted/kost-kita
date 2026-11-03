<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE deposit_evidence ADD COLUMN cash_transaction_id INTEGER NULL REFERENCES cash_transactions(id) ON DELETE RESTRICT');
            DB::statement('CREATE UNIQUE INDEX deposit_evidence_cash_hash_unique ON deposit_evidence (tenant_id, cash_transaction_id, sha256)');
            DB::statement('DROP TRIGGER IF EXISTS deposit_evidence_parent_invariants_insert');
            DB::statement("CREATE TRIGGER deposit_evidence_parent_invariants_insert BEFORE INSERT ON deposit_evidence WHEN ((NEW.settlement_line_id IS NOT NULL) + (NEW.claim_resolution_id IS NOT NULL) + (NEW.cash_transaction_id IS NOT NULL)) != 1 OR (NEW.cash_transaction_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM cash_transactions WHERE tenant_id = NEW.tenant_id AND id = NEW.cash_transaction_id)) BEGIN SELECT RAISE(ABORT, 'invalid deposit evidence parent'); END");
        } else {
            Schema::table('deposit_evidence', function (Blueprint $table): void {
                $table->unsignedBigInteger('cash_transaction_id')->nullable();
                $table->unique(['tenant_id', 'cash_transaction_id', 'sha256'], 'deposit_evidence_cash_hash_unique');
                $table->foreign(['tenant_id', 'cash_transaction_id'], 'deposit_evidence_cash_fk')
                    ->references(['tenant_id', 'id'])->on('cash_transactions')->restrictOnDelete();
            });
            DB::statement('ALTER TABLE deposit_evidence DROP CONSTRAINT deposit_evidence_parent_check');
            DB::statement('ALTER TABLE deposit_evidence ADD CONSTRAINT deposit_evidence_parent_check CHECK (num_nonnulls(settlement_line_id, claim_resolution_id, cash_transaction_id) = 1)');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS deposit_evidence_parent_invariants_insert');
            DB::statement("CREATE TRIGGER deposit_evidence_parent_invariants_insert BEFORE INSERT ON deposit_evidence WHEN (NEW.settlement_line_id IS NULL) = (NEW.claim_resolution_id IS NULL) BEGIN SELECT RAISE(ABORT, 'invalid deposit evidence parent'); END");
            DB::statement('DROP INDEX IF EXISTS deposit_evidence_cash_hash_unique');
            DB::statement('ALTER TABLE deposit_evidence DROP COLUMN cash_transaction_id');
        } else {
            DB::statement('ALTER TABLE deposit_evidence DROP CONSTRAINT deposit_evidence_parent_check');
            DB::statement('ALTER TABLE deposit_evidence ADD CONSTRAINT deposit_evidence_parent_check CHECK ((settlement_line_id IS NULL) <> (claim_resolution_id IS NULL))');
            Schema::table('deposit_evidence', function (Blueprint $table): void {
                $table->dropForeign('deposit_evidence_cash_fk');
                $table->dropUnique('deposit_evidence_cash_hash_unique');
                $table->dropColumn('cash_transaction_id');
            });
        }
    }
};
