<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class TenantContext
{
    public function current(): Tenant
    {
        $user = Auth::user();

        abort_unless($user instanceof User && $user->role === 'owner' && $user->account_status === 'active', 403);

        return $user->tenant()->first() ?? abort(403);
    }
}
