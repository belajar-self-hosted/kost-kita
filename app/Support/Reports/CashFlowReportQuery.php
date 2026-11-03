<?php

namespace App\Support\Reports;

use App\Models\CashTransaction;
use App\Models\Property;
use App\Models\Resident;
use App\Models\Room;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

class CashFlowReportQuery
{
    /** @var array<string, string> */
    private const CATEGORY_LABELS = [
        'rent_receipt' => 'Penerimaan sewa',
        'deposit_receipt' => 'Penerimaan deposit jaminan',
        'temporary_liability' => 'Penerimaan liability sementara',
        'rent_refund' => 'Refund sewa',
        'deposit_refund' => 'Refund deposit jaminan',
        'temporary_liability_refund' => 'Refund liability sementara',
        'maintenance_expense' => 'Biaya maintenance',
        'operating_expense' => 'Biaya operasional',
    ];

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::CATEGORY_LABELS);
    }

    public function run(Tenant $tenant, ReportFilters $filters, ?ReportPage $page = null): ReportDataset
    {
        $events = CashTransaction::query()
            ->where('tenant_id', $tenant->id)
            ->whereDate('occurred_on', '>=', $filters->from->toDateString())
            ->whereDate('occurred_on', '<=', $filters->to->toDateString())
            ->with([
                'invoiceAllocations.invoice.booking.property', 'invoiceAllocations.invoice.booking.room', 'invoiceAllocations.invoice.booking.resident',
                'invoiceAllocations.invoice.tenancy.property', 'invoiceAllocations.invoice.tenancy.room', 'invoiceAllocations.invoice.tenancy.resident',
                'depositMovements.account.booking.property', 'depositMovements.account.booking.room', 'depositMovements.account.booking.resident',
                'depositMovements.account.tenancy.property', 'depositMovements.account.tenancy.room', 'depositMovements.account.tenancy.resident',
                'expensePayments.expense.property', 'expensePayments.expense.room',
                'temporaryLiabilities.booking.property', 'temporaryLiabilities.booking.room', 'temporaryLiabilities.booking.resident',
                'temporaryLiabilities.tenancy.property', 'temporaryLiabilities.tenancy.room', 'temporaryLiabilities.tenancy.resident',
                'temporaryLiabilityMovements.liability.booking.property', 'temporaryLiabilityMovements.liability.booking.room', 'temporaryLiabilityMovements.liability.booking.resident',
                'temporaryLiabilityMovements.liability.tenancy.property', 'temporaryLiabilityMovements.liability.tenancy.room', 'temporaryLiabilityMovements.liability.tenancy.resident',
            ])
            ->orderBy('occurred_on')->orderBy('id')->lazy(100);

        $rows = [];
        $rowCount = 0;
        $cashIn = 0;
        $cashOut = 0;
        foreach ($events as $event) {
            foreach ($this->attributions($event) as $row) {
                if ($filters->propertyId !== null && $row['property_id'] !== $filters->propertyId) {
                    continue;
                }
                if ($filters->roomId !== null && $row['room_id'] !== $filters->roomId) {
                    continue;
                }
                if ($filters->type !== null && $row['type'] !== $filters->type) {
                    continue;
                }

                $reportRow = [
                    'date' => $event->occurred_on->toDateString(),
                    'transaction_id' => $event->id,
                    'type' => $row['type'],
                    'type_label' => self::CATEGORY_LABELS[$row['type']] ?? $row['type'],
                    'property' => $row['property_name'],
                    'room' => $row['room_code'],
                    'description' => $event->description ?? $row['resident_name'] ?? 'Transaksi kas',
                    'cash_in_rupiah' => $event->direction === 'in' ? $row['amount_rupiah'] : 0,
                    'cash_out_rupiah' => $event->direction === 'out' ? $row['amount_rupiah'] : 0,
                    'method' => $event->method,
                ];
                $cashIn += (int) $reportRow['cash_in_rupiah'];
                $cashOut += (int) $reportRow['cash_out_rupiah'];
                if ($page === null || $page->contains($rowCount)) {
                    $rows[] = $reportRow;
                }
                $rowCount++;
                if ($page?->shouldStopAfter($rowCount)) {
                    break;
                }
            }
            if ($page?->shouldStopAfter($rowCount)) {
                break;
            }
        }

        return new ReportDataset(
            'cash-flow',
            'Arus Kas',
            [
                ['key' => 'date', 'label' => 'Tanggal', 'type' => 'date'],
                ['key' => 'type_label', 'label' => 'Jenis'],
                ['key' => 'property', 'label' => 'Properti'],
                ['key' => 'room', 'label' => 'Kamar'],
                ['key' => 'description', 'label' => 'Keterangan'],
                ['key' => 'cash_in_rupiah', 'label' => 'Kas masuk', 'type' => 'money'],
                ['key' => 'cash_out_rupiah', 'label' => 'Kas keluar', 'type' => 'money'],
                ['key' => 'method', 'label' => 'Metode'],
            ],
            $rows,
            ['cash_in_rupiah' => $cashIn, 'cash_out_rupiah' => $cashOut, 'net_cash_flow_rupiah' => $cashIn - $cashOut],
            ['cash_in_rupiah' => 'Total kas masuk', 'cash_out_rupiah' => 'Total kas keluar', 'net_cash_flow_rupiah' => 'Arus Kas Bersih (Net Cash Flow)'],
            $filters->toArray(),
            $filters->period(),
            CarbonImmutable::now('Asia/Jakarta')->format('Y-m-d H:i:s T'),
            $page === null ? null : $rowCount,
        );
    }

    /** @return list<array{type: string, amount_rupiah: int, property_id: int|null, property_name: string|null, room_id: int|null, room_code: string|null, resident_name: string|null}> */
    private function attributions(CashTransaction $event): array
    {
        $rows = [];

        foreach ($event->invoiceAllocations as $allocation) {
            $invoice = $allocation->invoice;
            $source = $invoice->tenancy ?? $invoice->booking;
            $rows[] = $this->attribution(
                $allocation->kind === 'reversal' ? 'rent_refund' : 'rent_receipt',
                (int) $allocation->amount_rupiah,
                $source?->property,
                $source?->room,
                $source?->resident,
            );
        }

        foreach ($event->depositMovements as $movement) {
            if (! in_array($movement->kind, ['receipt', 'refund'], true)) {
                continue;
            }
            $source = $movement->account->tenancy ?? $movement->account->booking;
            $rows[] = $this->attribution(
                $movement->kind === 'refund' ? 'deposit_refund' : 'deposit_receipt',
                (int) $movement->amount_rupiah,
                $source?->property,
                $source?->room,
                $source?->resident,
            );
        }

        foreach ($event->temporaryLiabilities as $liability) {
            $source = $liability->tenancy ?? $liability->booking;
            $rows[] = $this->attribution('temporary_liability', (int) $liability->initial_amount_rupiah, $source?->property, $source?->room, $source?->resident);
        }

        foreach ($event->temporaryLiabilityMovements as $movement) {
            if ($movement->kind !== 'refund') {
                continue;
            }
            $source = $movement->liability->tenancy ?? $movement->liability->booking;
            $rows[] = $this->attribution('temporary_liability_refund', (int) $movement->amount_rupiah, $source?->property, $source?->room, $source?->resident);
        }

        foreach ($event->expensePayments as $payment) {
            if ($payment->kind !== 'allocation') {
                continue;
            }
            $expense = $payment->expense;
            $rows[] = $this->attribution($event->category, (int) $payment->amount_rupiah, $expense->property, $expense->room, null);
        }

        if ($rows === []) {
            $rows[] = $this->attribution($event->category, (int) $event->amount_rupiah, null, null, null);
        }

        return $rows;
    }

    /** @return array{type: string, amount_rupiah: int, property_id: int|null, property_name: string|null, room_id: int|null, room_code: string|null, resident_name: string|null} */
    private function attribution(string $type, int $amount, ?Property $property, ?Room $room, ?Resident $resident): array
    {
        return [
            'type' => $type,
            'amount_rupiah' => $amount,
            'property_id' => $property?->id,
            'property_name' => $property?->name,
            'room_id' => $room?->id,
            'room_code' => $room?->code,
            'resident_name' => $resident?->full_name,
        ];
    }
}
