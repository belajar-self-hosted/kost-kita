<?php

namespace App\Console\Commands;

use App\Models\Tenancy;
use App\Support\RecurringInvoiceService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class GenerateMonthlyInvoices extends Command
{
    protected $signature = 'billing:generate-monthly-invoices';

    protected $description = 'Generate due monthly rent invoices for checked-in tenancies';

    public function handle(RecurringInvoiceService $invoices): int
    {
        $throughDate = CarbonImmutable::now('Asia/Jakarta')->startOfDay();
        $generated = 0;

        Tenancy::query()
            ->whereNotNull('actual_check_in_at')
            ->whereIn('status', ['active', 'completed', 'terminated'])
            ->orderBy('id')
            ->chunkById(100, function ($tenancies) use ($invoices, $throughDate, &$generated): void {
                foreach ($tenancies as $tenancy) {
                    $generated += $invoices->generateFor($tenancy, $throughDate);
                }
            });

        $this->info("Generated {$generated} invoice(s).");

        return self::SUCCESS;
    }
}
