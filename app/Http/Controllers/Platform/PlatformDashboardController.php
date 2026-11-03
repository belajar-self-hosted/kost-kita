<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class PlatformDashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Platform/Dashboard', [
            'ownerCounts' => [
                'active' => User::query()->where('role', 'owner')->where('account_status', 'active')->count(),
                'suspended' => User::query()->where('role', 'owner')->where('account_status', 'suspended')->count(),
            ],
            'propertyCount' => Property::query()->where('status', 'active')->count(),
            'tenantCount' => Tenant::query()->count(),
        ]);
    }
}
