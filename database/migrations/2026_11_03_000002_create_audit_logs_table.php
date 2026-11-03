<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('reason')->nullable();
            $table->timestampTz('occurred_at');
            $table->uuid('request_id')->nullable();

            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['actor_user_id', 'occurred_at']);
        });

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs BEGIN SELECT RAISE(ABORT, 'audit_logs are append-only'); END");
            DB::statement("CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs BEGIN SELECT RAISE(ABORT, 'audit_logs are append-only'); END");
        } else {
            DB::unprepared("CREATE FUNCTION prevent_audit_log_mutation() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'audit_logs are append-only'; END; $$ LANGUAGE plpgsql");
            DB::unprepared('CREATE TRIGGER audit_logs_append_only BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION prevent_audit_log_mutation()');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS audit_logs_no_update');
            DB::statement('DROP TRIGGER IF EXISTS audit_logs_no_delete');
        } else {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_append_only ON audit_logs');
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_audit_log_mutation()');
        }

        Schema::dropIfExists('audit_logs');
    }
};
