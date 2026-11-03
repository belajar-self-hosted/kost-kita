<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email_normalized')->nullable()->after('email');
            $table->string('role')->default('owner')->after('password');
            $table->string('account_status')->default('active')->after('role');
        });

        $seen = [];
        DB::table('users')->orderBy('id')->get(['id', 'email'])->each(function ($user) use (&$seen): void {
            $normalized = strtolower(trim($user->email));

            if (isset($seen[$normalized])) {
                throw new RuntimeException('Duplicate normalized user emails must be resolved before this migration: '.$normalized);
            }

            $seen[$normalized] = true;
            DB::table('users')->where('id', $user->id)->update(['email_normalized' => $normalized]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('email_normalized');
        });

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER users_email_normalized_insert BEFORE INSERT ON users WHEN NEW.email_normalized IS NULL BEGIN SELECT RAISE(ABORT, 'email_normalized is required'); END");
            DB::statement("CREATE TRIGGER users_email_normalized_update BEFORE UPDATE OF email_normalized ON users WHEN NEW.email_normalized IS NULL BEGIN SELECT RAISE(ABORT, 'email_normalized is required'); END");
            DB::statement("CREATE TRIGGER users_email_normalized_consistency_insert BEFORE INSERT ON users WHEN NEW.email_normalized != lower(trim(NEW.email)) BEGIN SELECT RAISE(ABORT, 'email_normalized must match email'); END");
            DB::statement("CREATE TRIGGER users_email_normalized_consistency_update BEFORE UPDATE OF email, email_normalized ON users WHEN NEW.email_normalized != lower(trim(NEW.email)) BEGIN SELECT RAISE(ABORT, 'email_normalized must match email'); END");
            DB::statement("CREATE TRIGGER users_role_check_insert BEFORE INSERT ON users WHEN NEW.role NOT IN ('owner', 'super_admin') BEGIN SELECT RAISE(ABORT, 'invalid users.role'); END");
            DB::statement("CREATE TRIGGER users_role_check_update BEFORE UPDATE OF role ON users WHEN NEW.role NOT IN ('owner', 'super_admin') BEGIN SELECT RAISE(ABORT, 'invalid users.role'); END");
            DB::statement("CREATE TRIGGER users_account_status_check_insert BEFORE INSERT ON users WHEN NEW.account_status NOT IN ('active', 'suspended') BEGIN SELECT RAISE(ABORT, 'invalid users.account_status'); END");
            DB::statement("CREATE TRIGGER users_account_status_check_update BEFORE UPDATE OF account_status ON users WHEN NEW.account_status NOT IN ('active', 'suspended') BEGIN SELECT RAISE(ABORT, 'invalid users.account_status'); END");
        } else {
            DB::statement('ALTER TABLE users ALTER COLUMN email_normalized SET NOT NULL');
            DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_normalized_consistency_check CHECK (email_normalized = lower(trim(email)))');
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('owner', 'super_admin'))");
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_account_status_check CHECK (account_status IN ('active', 'suspended'))");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            foreach ([
                'users_email_normalized_insert', 'users_email_normalized_update',
                'users_email_normalized_consistency_insert', 'users_email_normalized_consistency_update',
                'users_role_check_insert', 'users_role_check_update',
                'users_account_status_check_insert', 'users_account_status_check_update',
            ] as $trigger) {
                DB::statement('DROP TRIGGER IF EXISTS '.$trigger);
            }
        } else {
            DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');
            DB::statement('ALTER TABLE users DROP CONSTRAINT users_account_status_check');
            DB::statement('ALTER TABLE users DROP CONSTRAINT users_email_normalized_consistency_check');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email_normalized']);
            $table->dropColumn(['email_normalized', 'role', 'account_status']);
        });
    }
};
