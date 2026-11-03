<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === 'owner' && $user->account_status === 'active';
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->viewAny($user) && $user->tenant()->whereKey($invoice->tenant_id)->exists();
    }
}
