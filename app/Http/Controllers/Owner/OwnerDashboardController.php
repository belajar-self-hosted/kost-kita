<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Support\Reports\OwnerDashboardSummary;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OwnerDashboardController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenantContext, OwnerDashboardSummary $dashboard): Response
    {
        $tenant = $tenantContext->current();
        $summary = $dashboard->for($tenant, CarbonImmutable::now('Asia/Jakarta'));

        return Inertia::render('Owner/Dashboard', [
            'ownerName' => $request->user()->name,
            ...$summary,
        ]);
    }
}
