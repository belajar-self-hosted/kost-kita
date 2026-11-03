<?php

namespace App\Policies;

use App\Models\CashTransaction;
use App\Models\User;

class CashTransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === 'owner' && $user->account_status === 'active';
    }

    public function view(User $user, CashTransaction $transaction): bool
    {
        return $this->viewAny($user) && $user->tenant()->whereKey($transaction->tenant_id)->exists();
    }
}
