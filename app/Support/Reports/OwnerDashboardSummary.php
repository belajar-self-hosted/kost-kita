<?php

namespace App\Support\Reports;

use App\Models\AuditLog;
use App\Models\CashTransaction;
use App\Models\DepositMovement;
use App\Models\DepositSettlement;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\InvoiceCashAllocation;
use App\Models\InvoiceLine;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class OwnerDashboardSummary
{
    private const INVOICE_OUTSTANDING_SQL = '(COALESCE(dashboard_invoice_lines.amount_rupiah, 0) + COALESCE(dashboard_invoice_adjustments.amount_rupiah, 0) - COALESCE(dashboard_invoice_cash.amount_rupiah, 0) - COALESCE(dashboard_invoice_deposit.amount_rupiah, 0))';

    private const INVOICE_SUMMARY_AMOUNT_SQL = 'COALESCE(SUM(CASE WHEN '.self::INVOICE_OUTSTANDING_SQL.' > 0 THEN '.self::INVOICE_OUTSTANDING_SQL.' ELSE 0 END), 0) AS amount_rupiah';

    private const INVOICE_SUMMARY_COUNT_SQL = 'COALESCE(SUM(CASE WHEN '.self::INVOICE_OUTSTANDING_SQL.' > 0 THEN 1 ELSE 0 END), 0) AS invoice_count';

    /** @return array<string,mixed> */
    public function for(Tenant $tenant, CarbonImmutable $today): array
    {
        $today = $today->setTimezone('Asia/Jakarta')->startOfDay();
        $todayDate = $today->toDateString();
        $monthStart = $today->startOfMonth()->toDateString();

        return [
            'asOfDate' => $todayDate,
            'tenantName' => $tenant->display_name,
            'propertyCounts' => $this->propertyCounts($tenant),
            'occupancy' => $this->occupancy($tenant, $todayDate),
            'finance' => $this->finance($tenant, $monthStart, $todayDate),
            'cashFlowTrend' => $this->cashFlowTrend($tenant, $today),
            'invoiceSummary' => $this->invoiceSummary($tenant, $today),
            'reminders' => $this->reminders($tenant, $today),
            'recentProperties' => Property::query()->where('tenant_id', $tenant->id)
                ->select(['id', 'name', 'address', 'status', 'updated_at'])
                ->latest('updated_at')->limit(5)->get(),
            'recentActivity' => AuditLog::query()->where('tenant_id', $tenant->id)
                ->latest('occurred_at')->limit(8)
                ->get(['id', 'action', 'subject_type', 'occurred_at']),
        ];
    }

    /** @return array{active:int,archived:int} */
    private function propertyCounts(Tenant $tenant): array
    {
        $properties = Property::query()->where('tenant_id', $tenant->id);

        return [
            'active' => (clone $properties)->where('status', 'active')->count(),
            'archived' => (clone $properties)->where('status', 'archived')->count(),
        ];
    }

    /** @return array{occupied:int,capacity:int,available:int,maintenanceBlocked:int,occupancyPercent:float} */
    private function occupancy(Tenant $tenant, string $today): array
    {
        $rooms = DB::table('rooms')
            ->join('properties', function ($join): void {
                $join->on('properties.id', '=', 'rooms.property_id')
                    ->on('properties.tenant_id', '=', 'rooms.tenant_id');
            })
            ->where('rooms.tenant_id', $tenant->id)
            ->where('properties.status', 'active')
            ->where('rooms.operational_status', 'rentable');

        $capacity = (int) (clone $rooms)->count('rooms.id');
        $occupied = (int) DB::table('tenancies')
            ->join('rooms', function ($join): void {
                $join->on('rooms.id', '=', 'tenancies.room_id')
                    ->on('rooms.tenant_id', '=', 'tenancies.tenant_id');
            })
            ->join('properties', function ($join): void {
                $join->on('properties.id', '=', 'tenancies.property_id')
                    ->on('properties.tenant_id', '=', 'tenancies.tenant_id');
            })
            ->where('tenancies.tenant_id', $tenant->id)
            ->where('properties.status', 'active')
            ->where('rooms.operational_status', 'rentable')
            ->whereNotNull('tenancies.actual_start_date')
            ->whereDate('tenancies.actual_start_date', '<=', $today)
            ->where(function ($query) use ($today): void {
                $query->whereNull('tenancies.actual_checkout_date')
                    ->orWhereDate('tenancies.actual_checkout_date', '>=', $today);
            })
            ->distinct()->count('tenancies.room_id');

        $maintenanceBlocked = (int) DB::table('maintenance_jobs')
            ->join('rooms', function ($join): void {
                $join->on('rooms.id', '=', 'maintenance_jobs.room_id')
                    ->on('rooms.tenant_id', '=', 'maintenance_jobs.tenant_id');
            })
            ->join('properties', function ($join): void {
                $join->on('properties.id', '=', 'maintenance_jobs.property_id')
                    ->on('properties.tenant_id', '=', 'maintenance_jobs.tenant_id');
            })
            ->where('maintenance_jobs.tenant_id', $tenant->id)
            ->where('properties.status', 'active')
            ->where('maintenance_jobs.status', 'scheduled')
            ->where('maintenance_jobs.blocks_room', true)
            ->where('rooms.operational_status', 'rentable')
            ->whereDate('maintenance_jobs.scheduled_start_date', '<=', $today)
            ->whereDate('maintenance_jobs.scheduled_end_exclusive', '>', $today)
            ->distinct()->count('maintenance_jobs.room_id');

        return [
            'occupied' => $occupied,
            'capacity' => $capacity,
            'available' => max(0, $capacity - $occupied - $maintenanceBlocked),
            'maintenanceBlocked' => $maintenanceBlocked,
            'occupancyPercent' => $capacity === 0 ? 0.0 : round(($occupied / $capacity) * 100, 1),
        ];
    }

    /** @return array<string,int> */
    private function finance(Tenant $tenant, string $monthStart, string $today): array
    {
        $transactions = CashTransaction::query()->where('tenant_id', $tenant->id)
            ->whereDate('occurred_on', '>=', $monthStart)
            ->whereDate('occurred_on', '<=', $today);

        $cashIn = (int) (clone $transactions)->where('direction', 'in')->sum('amount_rupiah');
        $cashOut = (int) (clone $transactions)->where('direction', 'out')->sum('amount_rupiah');
        $rentReceipts = (int) (clone $transactions)->where('category', 'rent_receipt')->sum('amount_rupiah')
            + (int) InvoiceCashAllocation::query()->where('tenant_id', $tenant->id)->where('kind', 'allocation')
                ->whereHas('cashTransaction', fn (Builder $query) => $query->where('category', 'mixed_receipt')
                    ->where('direction', 'in')->whereDate('occurred_on', '>=', $monthStart)->whereDate('occurred_on', '<=', $today))
                ->sum('amount_rupiah');
        $depositReceipts = (int) DepositMovement::query()->where('tenant_id', $tenant->id)->where('kind', 'receipt')
            ->whereHas('cashTransaction', fn (Builder $query) => $query->whereDate('occurred_on', '>=', $monthStart)->whereDate('occurred_on', '<=', $today))
            ->sum('amount_rupiah');
        $maintenancePaid = (int) (clone $transactions)->where('category', 'maintenance_expense')->sum('amount_rupiah');
        $operationalPaid = (int) (clone $transactions)->where('category', 'operating_expense')->sum('amount_rupiah');
        $depositRefunded = (int) (clone $transactions)->where('category', 'deposit_refund')->sum('amount_rupiah');
        $rentRefunded = (int) (clone $transactions)->where('category', 'rent_refund')->sum('amount_rupiah');

        return [
            'cashIn' => $cashIn,
            'cashOut' => $cashOut,
            'netCashFlow' => $cashIn - $cashOut,
            'rentReceived' => $rentReceipts,
            'depositReceived' => $depositReceipts,
            'maintenancePaid' => $maintenancePaid,
            'operationalPaid' => $operationalPaid,
            'depositRefunded' => $depositRefunded,
            'rentRefunded' => $rentRefunded,
        ];
    }

    /** @return list<array{month:string,label:string,cashIn:int,cashOut:int,netCashFlow:int}> */
    private function cashFlowTrend(Tenant $tenant, CarbonImmutable $today): array
    {
        $firstMonth = $today->startOfMonth()->subMonths(5);
        $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        $trend = [];
        for ($offset = 0; $offset < 6; $offset++) {
            $month = $firstMonth->addMonths($offset);
            $key = $month->format('Y-m');
            $monthLabel = $monthNames[(int) $month->format('n') - 1].' '.$month->format('Y');
            $trend[$key] = [
                'month' => $key,
                'label' => $monthLabel,
                'cashIn' => 0,
                'cashOut' => 0,
                'netCashFlow' => 0,
            ];
        }

        $events = CashTransaction::query()->where('tenant_id', $tenant->id)
            ->whereDate('occurred_on', '>=', $firstMonth->toDateString())
            ->whereDate('occurred_on', '<=', $today->toDateString())
            ->get(['direction', 'amount_rupiah', 'occurred_on']);

        foreach ($events as $event) {
            $key = $event->occurred_on->format('Y-m');
            if (! isset($trend[$key])) {
                continue;
            }

            $amount = (int) $event->amount_rupiah;
            if ($event->direction === 'in') {
                $trend[$key]['cashIn'] += $amount;
            } else {
                $trend[$key]['cashOut'] += $amount;
            }
        }

        foreach ($trend as &$month) {
            $month['netCashFlow'] = $month['cashIn'] - $month['cashOut'];
        }
        unset($month);

        return array_values($trend);
    }

    /** @return array{amount_rupiah:int,invoice_count:int} */
    private function invoiceSummary(Tenant $tenant, CarbonImmutable $today): array
    {
        $summary = $this->invoiceBalances($tenant, $today)->reorder()->select([])
            ->selectRaw(self::INVOICE_SUMMARY_AMOUNT_SQL)
            ->selectRaw(self::INVOICE_SUMMARY_COUNT_SQL)
            ->first();

        return [
            'amount_rupiah' => (int) ($summary?->getAttribute('amount_rupiah') ?? 0),
            'invoice_count' => (int) ($summary?->getAttribute('invoice_count') ?? 0),
        ];
    }

    /** @return array{dueSoon:list<array<string,mixed>>,overdue:list<array<string,mixed>>,tenanciesEnding:list<array<string,mixed>>,depositRefundsPastDeadline:list<array<string,mixed>>,lateDepositClaims:list<array<string,mixed>>} */
    private function reminders(Tenant $tenant, CarbonImmutable $today): array
    {
        $todayDate = $today->toDateString();
        $endExclusive = $today->addDays(7)->toDateString();
        $relations = [
            'tenancy.resident:id,tenant_id,full_name',
            'tenancy.room:id,tenant_id,property_id,code',
            'tenancy.room.property:id,tenant_id,name',
            'booking.resident:id,tenant_id,full_name',
            'booking.room:id,tenant_id,property_id,code',
            'booking.room.property:id,tenant_id,name',
        ];
        $base = $this->invoiceBalances($tenant, $today);
        $dueSoon = (clone $base)->with($relations)
            ->whereDate('invoices.due_date', '>=', $todayDate)
            ->whereDate('invoices.due_date', '<=', $endExclusive)
            ->whereRaw(self::INVOICE_OUTSTANDING_SQL.' > 0')
            ->orderBy('invoices.due_date')->orderBy('invoices.id')->limit(10)->get()
            ->map(fn (Invoice $invoice): array => $this->invoiceReminder($invoice, (int) $invoice->getAttribute('report_outstanding_rupiah')))
            ->values()->all();
        $overdue = (clone $base)->with($relations)
            ->whereDate('invoices.due_date', '<', $todayDate)
            ->whereRaw(self::INVOICE_OUTSTANDING_SQL.' > 0')
            ->orderBy('invoices.due_date')->orderBy('invoices.id')->limit(10)->get()
            ->map(fn (Invoice $invoice): array => $this->invoiceReminder($invoice, (int) $invoice->getAttribute('report_outstanding_rupiah')))
            ->values()->all();

        return [
            'dueSoon' => array_values($dueSoon),
            'overdue' => array_values($overdue),
            'tenanciesEnding' => $this->tenanciesEnding($tenant, $today),
            'depositRefundsPastDeadline' => $this->depositRefundsPastDeadline($tenant, $today),
            'lateDepositClaims' => $this->lateDepositClaims($tenant, $today),
        ];
    }

    /** @return Builder<Invoice> */
    private function invoiceBalances(Tenant $tenant, CarbonImmutable $today): Builder
    {
        $lineTotals = InvoiceLine::query()->selectRaw('invoice_id, SUM(amount_rupiah) AS amount_rupiah')
            ->where('tenant_id', $tenant->id)->groupBy('invoice_id');
        $adjustmentTotals = InvoiceAdjustment::query()->selectRaw("invoice_id, SUM(CASE WHEN kind = 'debit' THEN amount_rupiah ELSE -amount_rupiah END) AS amount_rupiah")
            ->where('tenant_id', $tenant->id)->where('created_at', '<=', $today->setTimezone('UTC')->endOfDay())
            ->groupBy('invoice_id');
        $cashTotals = InvoiceCashAllocation::query()->selectRaw("invoice_cash_allocations.invoice_id, SUM(CASE WHEN invoice_cash_allocations.kind = 'allocation' THEN invoice_cash_allocations.amount_rupiah ELSE -invoice_cash_allocations.amount_rupiah END) AS amount_rupiah")
            ->join('cash_transactions', function ($join) use ($tenant, $today): void {
                $join->on('cash_transactions.id', '=', 'invoice_cash_allocations.cash_transaction_id')
                    ->where('cash_transactions.tenant_id', $tenant->id)
                    ->whereDate('cash_transactions.occurred_on', '<=', $today->toDateString());
            })
            ->where('invoice_cash_allocations.tenant_id', $tenant->id)->groupBy('invoice_cash_allocations.invoice_id');
        $depositTotals = DepositMovement::query()->selectRaw('invoice_id, SUM(amount_rupiah) AS amount_rupiah')
            ->where('tenant_id', $tenant->id)->where('kind', 'invoice_settlement')
            ->where('created_at', '<=', $today->setTimezone('UTC')->endOfDay())
            ->groupBy('invoice_id');

        return Invoice::query()->where('invoices.tenant_id', $tenant->id)
            ->leftJoinSub($lineTotals, 'dashboard_invoice_lines', fn ($join) => $join->on('dashboard_invoice_lines.invoice_id', '=', 'invoices.id'))
            ->leftJoinSub($adjustmentTotals, 'dashboard_invoice_adjustments', fn ($join) => $join->on('dashboard_invoice_adjustments.invoice_id', '=', 'invoices.id'))
            ->leftJoinSub($cashTotals, 'dashboard_invoice_cash', fn ($join) => $join->on('dashboard_invoice_cash.invoice_id', '=', 'invoices.id'))
            ->leftJoinSub($depositTotals, 'dashboard_invoice_deposit', fn ($join) => $join->on('dashboard_invoice_deposit.invoice_id', '=', 'invoices.id'))
            ->select('invoices.*')->addSelect(DB::raw(self::INVOICE_OUTSTANDING_SQL.' AS report_outstanding_rupiah'));
    }

    /** @return array<string,mixed> */
    private function invoiceReminder(Invoice $invoice, int $balance): array
    {
        if ($invoice->tenancy_id !== null) {
            $resident = $invoice->tenancy->resident;
            $room = $invoice->tenancy->room;
        } else {
            $resident = $invoice->booking?->resident;
            $room = $invoice->booking?->room;
        }

        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'resident_name' => $resident->full_name ?? 'Penyewa',
            'room_code' => $room->code ?? '—',
            'property_name' => $room?->property->name ?? '—',
            'due_date' => $invoice->due_date->toDateString(),
            'balance_rupiah' => $balance,
            'href' => route('owner.invoices.show', $invoice),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function tenanciesEnding(Tenant $tenant, CarbonImmutable $today): array
    {
        $reminders = Tenancy::query()->where('tenant_id', $tenant->id)->where('status', 'active')
            ->whereNull('actual_checkout_date')
            ->whereDate('planned_end_exclusive', '<=', $today->addDays(31)->toDateString())
            ->with(['resident:id,tenant_id,full_name', 'room:id,tenant_id,property_id,code', 'room.property:id,tenant_id,name'])
            ->orderBy('planned_end_exclusive')->limit(10)->get()
            ->map(function (Tenancy $tenancy) use ($today): array {
                $lastDay = $tenancy->planned_end_exclusive->subDay();

                return [
                    'id' => $tenancy->id,
                    'resident_name' => $tenancy->resident->full_name,
                    'room_code' => $tenancy->room->code,
                    'property_name' => $tenancy->room->property->name,
                    'planned_last_day' => $lastDay->toDateString(),
                    'days_remaining' => (int) $today->diffInDays($lastDay, false),
                    'href' => route('owner.tenancies.show', $tenancy),
                ];
            })->all();

        return array_values($reminders);
    }

    /** @return list<array<string,mixed>> */
    private function depositRefundsPastDeadline(Tenant $tenant, CarbonImmutable $today): array
    {
        $deadlineBoundary = $today->setTimezone('UTC');
        $settlements = DepositSettlement::query()->where('tenant_id', $tenant->id)
            ->whereIn('status', ['approved', 'partially_settled', 'late'])
            ->where('day7_deadline_at', '<', $deadlineBoundary)
            ->whereHas('lines', fn (Builder $query) => $query->where('kind', 'refund'))
            ->with([
                'account.booking.resident', 'account.booking.room.property',
                'account.tenancy.resident', 'account.tenancy.room.property',
                'lines' => fn ($query) => $query->where('kind', 'refund'),
            ])->orderBy('day7_deadline_at')->limit(25)->get();

        $reminders = [];
        foreach ($settlements as $settlement) {
            foreach ($settlement->lines as $line) {
                $refunded = (int) DepositMovement::query()->where('tenant_id', $tenant->id)
                    ->where('settlement_line_id', $line->id)->where('kind', 'refund')->sum('amount_rupiah');
                $reversed = (int) DB::table('deposit_movements as reversal')
                    ->join('deposit_movements as original', 'original.id', '=', 'reversal.reversal_of_movement_id')
                    ->where('reversal.tenant_id', $tenant->id)
                    ->where('reversal.kind', 'reversal')->where('original.kind', 'refund')
                    ->where('original.settlement_line_id', $line->id)->sum('reversal.amount_rupiah');
                $remaining = max(0, (int) $line->amount_rupiah - $refunded + $reversed);
                if ($remaining === 0) {
                    continue;
                }

                $account = $settlement->account;
                if ($account->tenancy_id !== null) {
                    $resident = $account->tenancy->resident;
                    $room = $account->tenancy->room;
                } else {
                    $resident = $account->booking?->resident;
                    $room = $account->booking?->room;
                }
                $deadline = $settlement->day7_deadline_at->setTimezone('Asia/Jakarta')->toDateString();

                $reminders[] = [
                    'settlement_id' => $settlement->id,
                    'deposit_account_id' => $account->id,
                    'resident_name' => $resident->full_name ?? 'Penyewa',
                    'room_code' => $room->code ?? '—',
                    'property_name' => $room?->property->name ?? '—',
                    'deadline' => $deadline,
                    'late_days' => max(1, (int) CarbonImmutable::parse($deadline, 'Asia/Jakarta')->diffInDays($today, false)),
                    'balance_rupiah' => $remaining,
                    'href' => route('owner.deposits.show', $account),
                ];
            }
        }

        return array_slice($reminders, 0, 10);
    }

    /** @return list<array<string,mixed>> */
    private function lateDepositClaims(Tenant $tenant, CarbonImmutable $today): array
    {
        $deadlineBoundary = $today->setTimezone('UTC');
        $reminders = DepositSettlement::query()->where('tenant_id', $tenant->id)
            ->whereIn('status', ['approved', 'partially_settled', 'late'])
            ->where('day30_deadline_at', '<', $deadlineBoundary)
            ->whereHas('lines', fn (Builder $query) => $query->where('kind', 'held_claim')->whereDoesntHave('claimResolution'))
            ->with([
                'account.booking.resident', 'account.booking.room.property',
                'account.tenancy.resident', 'account.tenancy.room.property',
                'lines' => fn ($query) => $query->where('kind', 'held_claim')->whereDoesntHave('claimResolution'),
            ])->orderBy('day30_deadline_at')->limit(10)->get()
            ->map(function (DepositSettlement $settlement) use ($today): array {
                $account = $settlement->account;
                if ($account->tenancy_id !== null) {
                    $resident = $account->tenancy->resident;
                    $room = $account->tenancy->room;
                } else {
                    $resident = $account->booking?->resident;
                    $room = $account->booking?->room;
                }
                $deadline = $settlement->day30_deadline_at->setTimezone('Asia/Jakarta')->toDateString();

                return [
                    'settlement_id' => $settlement->id,
                    'deposit_account_id' => $account->id,
                    'resident_name' => $resident->full_name ?? 'Penyewa',
                    'room_code' => $room->code ?? '—',
                    'property_name' => $room?->property->name ?? '—',
                    'deadline' => $deadline,
                    'late_days' => max(1, (int) CarbonImmutable::parse($deadline, 'Asia/Jakarta')->diffInDays($today, false)),
                    'balance_rupiah' => (int) $settlement->lines->sum('amount_rupiah'),
                    'href' => route('owner.deposits.show', $account),
                ];
            })->all();

        return array_values($reminders);
    }
}
