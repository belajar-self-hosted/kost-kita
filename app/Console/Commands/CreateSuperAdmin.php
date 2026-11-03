<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class CreateSuperAdmin extends Command
{
    protected $signature = 'app:create-super-admin';

    protected $description = 'Create a verified, active Super Admin account';

    public function handle(): int
    {
        $name = trim($this->ask('Name'));
        $email = strtolower(trim($this->ask('Email')));

        try {
            Validator::make(
                ['name' => $name, 'email' => $email],
                ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'ascii', 'max:255', 'unique:users,email_normalized']],
            )->validate();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password');
        $confirmation = (string) $this->secret('Confirm password');

        try {
            Validator::make(
                ['password' => $password, 'password_confirmation' => $confirmation],
                ['password' => ['required', 'confirmed', Password::min(12)->mixedCase()->letters()->numbers()->symbols()]],
            )->validate();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $admin = DB::transaction(function () use ($name, $email, $password): User {
            $admin = new User;
            $admin->forceFill([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'role' => 'super_admin',
                'account_status' => 'active',
                'email_verified_at' => now('UTC'),
            ])->save();

            app(AuditLogger::class)->record(
                actor: $admin,
                tenant: null,
                action: 'platform.super_admin.provisioned',
                subjectType: 'user',
                subjectId: $admin->id,
                newValues: ['role' => $admin->role, 'account_status' => $admin->account_status],
                reason: 'super_admin_provisioned_via_cli',
            );

            return $admin;
        });

        $this->info('Super Admin created: '.$admin->email);

        return self::SUCCESS;
    }
}
