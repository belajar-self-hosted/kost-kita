<?php

namespace App\Policies;

use App\Models\Property;
use App\Models\Room;
use App\Models\User;

class RoomPolicy
{
    public function viewAny(User $user, Property $property): bool
    {
        return $this->ownsProperty($user, $property);
    }

    public function view(User $user, Room $room): bool
    {
        return $this->owns($user, $room);
    }

    public function create(User $user, Property $property): bool
    {
        return $this->ownsProperty($user, $property) && $property->status === 'active';
    }

    public function update(User $user, Room $room): bool
    {
        return $this->owns($user, $room) && $room->operational_status !== 'archived';
    }

    public function archive(User $user, Room $room): bool
    {
        return $this->owns($user, $room) && $room->operational_status !== 'archived';
    }

    private function owns(User $user, Room $room): bool
    {
        return $this->isActiveOwner($user)
            && $user->tenant()->whereKey($room->tenant_id)->exists();
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
