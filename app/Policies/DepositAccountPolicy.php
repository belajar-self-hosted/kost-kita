<?php

namespace App\Policies;

use App\Models\DepositAccount;
use App\Models\User;

class DepositAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === 'owner' && $user->account_status === 'active';
    }

    public function view(User $user, DepositAccount $account): bool
    {
        return $this->viewAny($user) && $user->tenant()->whereKey($account->tenant_id)->exists();
    }
}
