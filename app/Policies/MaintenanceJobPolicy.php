<?php

namespace App\Policies;

use App\Models\MaintenanceJob;
use App\Models\User;

class MaintenanceJobPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === 'owner' && $user->account_status === 'active';
    }

    public function view(User $user, MaintenanceJob $job): bool
    {
        return $user->role === 'owner' && $user->account_status === 'active'
            && $user->tenant()->whereKey($job->tenant_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->role === 'owner' && $user->account_status === 'active';
    }

    public function transition(User $user, MaintenanceJob $job): bool
    {
        return $this->view($user, $job);
    }
}
