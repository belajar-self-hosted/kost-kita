<?php

namespace App\Policies;

use App\Models\Property;
use App\Models\RoomType;
use App\Models\User;

class RoomTypePolicy
{
    public function viewAny(User $user, Property $property): bool
    {
        return $this->ownsProperty($user, $property);
    }

    public function view(User $user, RoomType $roomType): bool
    {
        return $this->owns($user, $roomType);
    }

    public function create(User $user, Property $property): bool
    {
        return $this->ownsProperty($user, $property) && $property->status === 'active';
    }

    public function update(User $user, RoomType $roomType): bool
    {
        return $this->owns($user, $roomType) && $roomType->status === 'active';
    }

    public function archive(User $user, RoomType $roomType): bool
    {
        return $this->owns($user, $roomType) && $roomType->status === 'active';
    }

    private function owns(User $user, RoomType $roomType): bool
    {
        return $this->isActiveOwner($user)
            && $user->tenant()->whereKey($roomType->tenant_id)->exists();
    }

    private function ownsProperty(User $user, Property $property): bool
    {
        return $this->isActiveOwner($user)
            && $user->tenant()->whereKey($property->tenant_id)->exists();
    }

    private function isActiveOwner(User $user): bool
    {
        return $user->role === 'owner' && $user->account_status === 'active';
    }
}
