<?php

namespace App\Policies;

use App\Models\DepositSettlement;
use App\Models\User;

class DepositSettlementPolicy
{
    public function view(User $user, DepositSettlement $settlement): bool
    {
        return $user->role === 'owner' && $user->account_status === 'active'
            && $user->tenant()->whereKey($settlement->tenant_id)->exists();
    }
}
