<?php

namespace App\Policies;

use App\Models\Resident;
use App\Models\User;

class ResidentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === 'owner' && $user->account_status === 'active';
    }

    public function view(User $user, Resident $resident): bool
    {
        return $user->role === 'owner' && $user->account_status === 'active'
            && $user->tenant()->whereKey($resident->tenant_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->role === 'owner' && $user->account_status === 'active';
    }

    public function update(User $user, Resident $resident): bool
    {
        return $this->view($user, $resident);
    }

    public function archive(User $user, Resident $resident): bool
    {
        return $this->view($user, $resident);
    }
}
