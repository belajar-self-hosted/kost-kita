<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'email' => ['required', 'string'],
        ])->validate();

        $input['email'] = strtolower(trim($input['email']));

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $owner = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $owner->forceFill(['role' => 'owner', 'account_status' => 'active'])->save();

            $tenant = Tenant::create([
                'owner_user_id' => $owner->id,
                'display_name' => $owner->name,
            ]);

            app(AuditLogger::class)->record(
                actor: $owner,
                tenant: $tenant,
                action: 'owner.registered',
                subjectType: 'tenant',
                subjectId: $tenant->id,
                newValues: ['display_name' => $tenant->display_name],
            );

            return $owner;
        });
    }
}
