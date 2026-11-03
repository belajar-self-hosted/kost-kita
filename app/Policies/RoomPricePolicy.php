<?php

namespace App\Policies;

use App\Models\Room;
use App\Models\RoomPrice;
use App\Models\RoomType;
use App\Models\User;

class RoomPricePolicy
{
    public function create(User $user, Room|RoomType $target): bool
    {
        $activeTarget = $target instanceof Room
            ? $target->operational_status !== 'archived'
            : $target->status === 'active';

        return $activeTarget
            && $user->role === 'owner'
            && $user->account_status === 'active'
            && $user->tenant()->whereKey($target->tenant_id)->exists();
    }

    public function view(User $user, RoomPrice $price): bool
    {
        return $this->owns($user, $price);
    }

    public function update(User $user, RoomPrice $price): bool
    {
        return $this->owns($user, $price);
    }

    public function end(User $user, RoomPrice $price): bool
    {
        return $this->owns($user, $price);
    }

    private function owns(User $user, RoomPrice $price): bool
    {
        return $user->role === 'owner'
            && $user->account_status === 'active'
            && $user->tenant()->whereKey($price->tenant_id)->exists();
    }
}
