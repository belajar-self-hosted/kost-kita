<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\DepositSettlementService;
use Illuminate\Console\Command;

class MarkLateDepositSettlements extends Command
{
    protected $signature = 'deposits:mark-late-settlements';

    protected $description = 'Mark unresolved deposit claims that passed their 30-day deadline';

    public function handle(DepositSettlementService $settlements): int
    {
        $marked = 0;
        Tenant::query()->with('ownerUser')->orderBy('id')->chunkById(100, function ($tenants) use ($settlements, &$marked): void {
            foreach ($tenants as $tenant) {
                if ($tenant->ownerUser !== null) {
                    $marked += $settlements->markLateClaims($tenant, $tenant->ownerUser);
                }
            }
        });

        $this->info("Marked {$marked} late settlement(s).");

        return self::SUCCESS;
    }
}
