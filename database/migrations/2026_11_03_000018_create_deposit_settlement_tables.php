<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('deposit_account_id');
            $table->string('status', 24)->default('draft');
            $table->text('exit_inspection_notes');
            $table->timestampTz('opened_at');
            $table->timestampTz('statement_issued_at')->nullable();
            $table->timestampTz('day7_deadline_at');
            $table->timestampTz('day30_deadline_at');
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('whatsapp_notified_at')->nullable();
            $table->timestampTz('finalized_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id'], 'deposit_settlements_tenant_id_unique');
            $table->unique(['tenant_id', 'deposit_account_id'], 'deposit_settlements_account_unique');
            $table->foreign(['tenant_id', 'deposit_account_id'], 'deposit_settlements_account_fk')
                ->references(['tenant_id', 'id'])->on('deposit_accounts')->restrictOnDelete();
            $table->index(['tenant_id', 'status', 'day30_deadline_at'], 'deposit_settlements_status_deadline_idx');
        });

        Schema::create('deposit_settlement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('settlement_id');
            $table->string('kind', 32);
            $table->unsignedBigInteger('amount_rupiah');
            $table->text('reason');
            $table->text('agreement_basis')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->string('idempotency_key', 160);
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'id'], 'deposit_settlement_lines_tenant_id_unique');
            $table->unique(['tenant_id', 'settlement_id', 'id'], 'deposit_settlement_lines_settlement_id_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'deposit_settlement_lines_idempotency_unique');
            $table->foreign(['tenant_id', 'settlement_id'], 'deposit_settlement_lines_settlement_fk')
                ->references(['tenant_id', 'id'])->on('deposit_settlements')->restrictOnDelete();
            $table->foreign(['tenant_id', 'invoice_id'], 'deposit_settlement_lines_invoice_fk')
                ->references(['tenant_id', 'id'])->on('invoices')->restrictOnDelete();
        });

        Schema::create('deposit_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('deposit_account_id');
            $table->string('kind', 32);
            $table->unsignedBigInteger('amount_rupiah');
            $table->unsignedBigInteger('cash_transaction_id')->nullable();
            $table->unsignedBigInteger('settlement_line_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('reversal_of_movement_id')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 160);
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'id'], 'deposit_movements_tenant_id_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'deposit_movements_idempotency_unique');
            $table->foreign(['tenant_id', 'deposit_account_id'], 'deposit_movements_account_fk')
                ->references(['tenant_id', 'id'])->on('deposit_accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'cash_transaction_id'], 'deposit_movements_cash_fk')
                ->references(['tenant_id', 'id'])->on('cash_transactions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'settlement_line_id'], 'deposit_movements_settlement_line_fk')
                ->references(['tenant_id', 'id'])->on('deposit_settlement_lines')->restrictOnDelete();
            $table->foreign(['tenant_id', 'invoice_id'], 'deposit_movements_invoice_fk')
                ->references(['tenant_id', 'id'])->on('invoices')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reversal_of_movement_id'], 'deposit_movements_reversal_fk')
                ->references(['tenant_id', 'id'])->on('deposit_movements')->restrictOnDelete();
            $table->index(['tenant_id', 'deposit_account_id', 'kind'], 'deposit_movements_account_kind_idx');
        });

        Schema::create('deposit_claim_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('settlement_id');
            $table->unsignedBigInteger('held_claim_line_id');
            $table->unsignedBigInteger('actual_damage_amount_rupiah')->default(0);
            $table->unsignedBigInteger('refund_amount_rupiah')->default(0);
            $table->unsignedBigInteger('damage_movement_id')->nullable();
            $table->unsignedBigInteger('refund_movement_id')->nullable();
            $table->text('resolution_basis');
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at');
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'id'], 'deposit_claim_resolutions_tenant_id_unique');
            $table->unique(['tenant_id', 'held_claim_line_id'], 'deposit_claim_resolutions_claim_unique');
            $table->foreign(['tenant_id', 'settlement_id'], 'deposit_claim_resolutions_settlement_fk')
                ->references(['tenant_id', 'id'])->on('deposit_settlements')->restrictOnDelete();
            $table->foreign(['tenant_id', 'settlement_id', 'held_claim_line_id'], 'deposit_claim_resolutions_line_fk')
                ->references(['tenant_id', 'settlement_id', 'id'])->on('deposit_settlement_lines')->restrictOnDelete();
            $table->foreign(['tenant_id', 'damage_movement_id'], 'deposit_claim_resolutions_damage_movement_fk')
                ->references(['tenant_id', 'id'])->on('deposit_movements')->restrictOnDelete();
            $table->foreign(['tenant_id', 'refund_movement_id'], 'deposit_claim_resolutions_refund_movement_fk')
                ->references(['tenant_id', 'id'])->on('deposit_movements')->restrictOnDelete();
        });

        Schema::create('deposit_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('settlement_line_id')->nullable();
            $table->unsignedBigInteger('claim_resolution_id')->nullable();
            $table->string('storage_key', 512);
            $table->string('original_filename', 255);
            $table->string('mime_type', 120);
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'id'], 'deposit_evidence_tenant_id_unique');
            $table->foreign(['tenant_id', 'settlement_line_id'], 'deposit_evidence_line_fk')
                ->references(['tenant_id', 'id'])->on('deposit_settlement_lines')->restrictOnDelete();
            $table->foreign(['tenant_id', 'claim_resolution_id'], 'deposit_evidence_resolution_fk')
                ->references(['tenant_id', 'id'])->on('deposit_claim_resolutions')->restrictOnDelete();
        });

        $movementInvalid = "NEW.kind NOT IN ('receipt','refund','invoice_settlement','damage_deduction','reversal') OR NEW.amount_rupiah <= 0 OR ((NEW.kind = 'reversal') != (NEW.reversal_of_movement_id IS NOT NULL))";
        $lineInvalid = "NEW.kind NOT IN ('refund','invoice_deduction','damage_deduction','held_claim') OR NEW.amount_rupiah <= 0 OR ((NEW.kind = 'invoice_deduction') != (NEW.invoice_id IS NOT NULL))";
        $parentInvalid = '(NEW.settlement_line_id IS NULL) = (NEW.claim_resolution_id IS NULL)';
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER deposit_settlement_lines_invariants_insert BEFORE INSERT ON deposit_settlement_lines WHEN {$lineInvalid} BEGIN SELECT RAISE(ABORT, 'invalid deposit settlement line'); END");
            DB::statement("CREATE TRIGGER deposit_movements_invariants_insert BEFORE INSERT ON deposit_movements WHEN {$movementInvalid} BEGIN SELECT RAISE(ABORT, 'invalid deposit movement'); END");
            DB::statement("CREATE TRIGGER deposit_evidence_parent_invariants_insert BEFORE INSERT ON deposit_evidence WHEN {$parentInvalid} BEGIN SELECT RAISE(ABORT, 'invalid deposit evidence parent'); END");
            DB::statement("CREATE TRIGGER deposit_settlements_status_insert BEFORE INSERT ON deposit_settlements WHEN NEW.status NOT IN ('draft','approved','partially_settled','finalized','late') BEGIN SELECT RAISE(ABORT, 'invalid deposit settlement status'); END");
        } else {
            DB::statement("ALTER TABLE deposit_settlements ADD CONSTRAINT deposit_settlements_status_check CHECK (status IN ('draft','approved','partially_settled','finalized','late'))");
            DB::statement("ALTER TABLE deposit_settlement_lines ADD CONSTRAINT deposit_settlement_lines_kind_check CHECK (kind IN ('refund','invoice_deduction','damage_deduction','held_claim'))");
            DB::statement('ALTER TABLE deposit_settlement_lines ADD CONSTRAINT deposit_settlement_lines_amount_check CHECK (amount_rupiah > 0)');
            DB::statement('ALTER TABLE deposit_settlement_lines ADD CONSTRAINT deposit_settlement_lines_invoice_check CHECK ((kind = \'invoice_deduction\') = (invoice_id IS NOT NULL))');
            DB::statement('ALTER TABLE deposit_evidence ADD CONSTRAINT deposit_evidence_parent_check CHECK ((settlement_line_id IS NULL) <> (claim_resolution_id IS NULL))');
            DB::statement("ALTER TABLE deposit_movements ADD CONSTRAINT deposit_movements_kind_check CHECK (kind IN ('receipt','refund','invoice_settlement','damage_deduction','reversal'))");
            DB::statement('ALTER TABLE deposit_movements ADD CONSTRAINT deposit_movements_amount_check CHECK (amount_rupiah > 0)');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS deposit_settlement_lines_invariants_insert');
            DB::statement('DROP TRIGGER IF EXISTS deposit_movements_invariants_insert');
            DB::statement('DROP TRIGGER IF EXISTS deposit_evidence_parent_invariants_insert');
            DB::statement('DROP TRIGGER IF EXISTS deposit_settlements_status_insert');
        } else {
            foreach ([
                ['deposit_evidence', 'deposit_evidence_parent_check'],
                ['deposit_settlement_lines', 'deposit_settlement_lines_kind_check'],
                ['deposit_settlement_lines', 'deposit_settlement_lines_amount_check'],
                ['deposit_settlement_lines', 'deposit_settlement_lines_invoice_check'],
                ['deposit_settlements', 'deposit_settlements_status_check'],
                ['deposit_movements', 'deposit_movements_kind_check'], ['deposit_movements', 'deposit_movements_amount_check'],
            ] as [$table, $constraint]) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
            }
        }

        Schema::dropIfExists('deposit_evidence');
        Schema::dropIfExists('deposit_claim_resolutions');
        Schema::dropIfExists('deposit_movements');
        Schema::dropIfExists('deposit_settlement_lines');
        Schema::dropIfExists('deposit_settlements');
    }
};
