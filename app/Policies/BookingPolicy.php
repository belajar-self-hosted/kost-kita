<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === 'owner' && $user->account_status === 'active';
    }

    public function view(User $user, Booking $booking): bool
    {
        return $user->role === 'owner' && $user->account_status === 'active'
            && $user->tenant()->whereKey($booking->tenant_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->role === 'owner' && $user->account_status === 'active';
    }

    public function transition(User $user, Booking $booking): bool
    {
        return $this->view($user, $booking);
    }
}
