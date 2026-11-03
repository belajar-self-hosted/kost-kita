<?php

namespace App\Policies;

use App\Models\Tenancy;
use App\Models\User;

class TenancyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === 'owner' && $user->account_status === 'active';
    }

    public function view(User $user, Tenancy $tenancy): bool
    {
        return $user->role === 'owner' && $user->account_status === 'active'
            && $user->tenant()->whereKey($tenancy->tenant_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->role === 'owner' && $user->account_status === 'active';
    }

    public function transition(User $user, Tenancy $tenancy): bool
    {
        return $this->view($user, $tenancy);
    }
}
