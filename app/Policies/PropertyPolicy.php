<?php

namespace App\Policies;

use App\Models\Property;
use App\Models\User;

class PropertyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === 'owner' && $user->account_status === 'active';
    }

    public function view(User $user, Property $property): bool
    {
        return $this->owns($user, $property);
    }

    public function create(User $user): bool
    {
        return $user->role === 'owner' && $user->account_status === 'active' && $user->tenant()->exists();
    }

    public function update(User $user, Property $property): bool
    {
        return $this->owns($user, $property) && $property->status === 'active';
    }

    public function archive(User $user, Property $property): bool
    {
        return $this->owns($user, $property) && $property->status === 'active';
    }

    private function owns(User $user, Property $property): bool
    {
        return $user->role === 'owner'
            && $user->account_status === 'active'
            && $user->tenant()->whereKey($property->tenant_id)->exists();
    }
}
