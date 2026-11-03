<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === 'owner' && $user->account_status === 'active';
    }

    public function create(User $user): bool
    {
        return $user->role === 'owner' && $user->account_status === 'active';
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->viewAny($user) && $user->tenant()->whereKey($expense->tenant_id)->exists();
    }

    public function update(User $user, Expense $expense): bool
    {
        return $this->view($user, $expense);
    }
}
