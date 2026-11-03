<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\Tenancy;
use App\Support\RoomPriceService;
use App\Support\TenancyService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TenancyController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->current();
        $filters = $request->validate([
            'status' => ['nullable', 'in:scheduled,active,completed,terminated'],
            'property_id' => ['nullable', 'integer'],
            'room_id' => ['nullable', 'integer'],
        ]);
        $tenancies = Tenancy::query()
            ->where('tenant_id', $tenant->id)
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['property_id']), fn ($query) => $query->where('property_id', $filters['property_id']))
            ->when(isset($filters['room_id']), fn ($query) => $query->where('room_id', $filters['room_id']))
            ->with(['property:id,name', 'room:id,code', 'resident:id,full_name,whatsapp_number'])
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'scheduled' THEN 1 WHEN 'completed' THEN 2 ELSE 3 END")
            ->orderByDesc('planned_start_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Owner/Tenancies/Index', [
            'tenancies' => $tenancies,
            'filters' => [
                'status' => $filters['status'] ?? '',
                'property_id' => $filters['property_id'] ?? '',
                'room_id' => $filters['room_id'] ?? '',
            ],
        ]);
    }

    public function createDirect(TenantContext $tenantContext, RoomPriceService $prices): Response
    {
        $tenant = $tenantContext->current();
        $rooms = Room::query()
            ->where('tenant_id', $tenant->id)
            ->whereNull('archived_at')
            ->where('operational_status', 'rentable')
            ->with(['property:id,name', 'roomType:id,name'])
            ->orderBy('property_id')
            ->orderBy('code')
            ->get()
            ->map(fn (Room $room) => [
                'id' => $room->id,
                'code' => $room->code,
                'property' => $room->property?->only(['id', 'name']),
                'room_type' => $room->roomType?->only(['id', 'name']),
                'operational_status' => $room->operational_status,
                'price' => $prices->resolveForRoom($room, now('Asia/Jakarta')->toDateString()),
            ]);

        return Inertia::render('Owner/DirectCheckIns/Create', [
            'rooms' => $rooms,
            'residents' => $tenant->residents()->whereNull('archived_at')->orderBy('full_name')->get(['id', 'full_name', 'whatsapp_number']),
            'initial' => [
                'room_id' => null,
                'resident_id' => null,
                'planned_start_date' => now('Asia/Jakarta')->toDateString(),
                'planned_end_exclusive' => null,
                'agreed_monthly_rent_rupiah' => null,
                'required_deposit_rupiah' => 0,
            ],
        ]);
    }

    public function storeDirect(Request $request, TenantContext $tenantContext, TenancyService $tenancies): RedirectResponse
    {
        $data = $request->validate([
            'room_id' => ['required', 'integer'],
            'resident_id' => ['nullable', 'integer'],
            'resident' => ['nullable', 'array'],
            'resident.full_name' => ['required_without:resident_id', 'nullable', 'string', 'max:160'],
            'resident.whatsapp_number' => ['required_without:resident_id', 'nullable', 'string', 'max:40'],
            'resident.contact_info' => ['nullable', 'string', 'max:2000'],
            'planned_start_date' => ['required', 'date_format:Y-m-d'],
            'planned_end_exclusive' => ['required', 'date_format:Y-m-d', 'after:planned_start_date'],
            'agreed_monthly_rent_rupiah' => ['nullable', 'integer', 'min:1'],
            'required_deposit_rupiah' => ['required', 'integer', 'min:0'],
            'payment_terms' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);
        $this->authorize('create', Tenancy::class);
        $tenancy = $tenancies->createDirect($tenantContext->current(), $request->user(), $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => $tenancy->status === 'active' ? 'Check-in berhasil dicatat.' : 'Masa sewa terjadwal berhasil dibuat.']);

        return to_route('owner.tenancies.show', $tenancy);
    }

    public function show(string $tenancy, TenantContext $tenantContext, TenancyService $tenancies): Response
    {
        $record = $tenancies->findTenancy($tenantContext->current(), (int) $tenancy);
        $this->authorize('view', $record);
        $record->load([
            'property:id,name', 'room:id,code', 'resident:id,full_name,whatsapp_number',
            'depositAccount:id,tenant_id,booking_id,tenancy_id,required_amount_rupiah',
            'invoices.lines', 'invoices.adjustments', 'extensions',
        ]);

        return Inertia::render('Owner/Tenancies/Show', [
            'tenancy' => [
                'id' => $record->id,
                'status' => $record->status,
                'property' => $record->property?->only(['id', 'name']),
                'room' => $record->room?->only(['id', 'code']),
                'resident' => $record->resident?->only(['id', 'full_name', 'whatsapp_number']),
                'planned_start_date' => $record->planned_start_date->toDateString(),
                'planned_end_exclusive' => $record->planned_end_exclusive->toDateString(),
                'actual_start_date' => $record->actual_start_date?->toDateString(),
                'actual_check_in_at' => $record->actual_check_in_at?->setTimezone('Asia/Jakarta')->toIso8601String(),
                'actual_checkout_date' => $record->actual_checkout_date?->toDateString(),
                'agreed_monthly_rent_rupiah' => (int) $record->agreed_monthly_rent_rupiah,
                'current_monthly_rent_rupiah' => $tenancies->currentMonthlyRent($record),
                'billing_anchor_day' => $record->billing_anchor_day,
                'payment_terms' => $record->payment_terms,
                'notes' => $record->notes,
            ],
            'invoices' => $record->invoices->map(fn ($invoice) => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'due_date' => $invoice->due_date->toDateString(),
                'cycle_start_date' => $invoice->cycle_start_date?->toDateString(),
                'cycle_end_exclusive' => $invoice->cycle_end_exclusive?->toDateString(),
                'adjustment_label' => $invoice->adjustments->isNotEmpty() ? 'Penyesuaian invoice' : null,
                'adjusted_total_rupiah' => (int) $invoice->lines->sum('amount_rupiah') + (int) $invoice->adjustments->sum(
                    fn ($adjustment): int => $adjustment->kind === 'debit' ? $adjustment->amount_rupiah : -$adjustment->amount_rupiah,
                ),
                'lines' => $invoice->lines->map(fn ($line) => [
                    'kind' => $line->kind,
                    'description' => $line->description,
                    'amount_rupiah' => (int) $line->amount_rupiah,
                ])->values(),
                'adjustments' => $invoice->adjustments->map(fn ($adjustment) => [
                    'kind' => $adjustment->kind,
                    'amount_rupiah' => (int) $adjustment->amount_rupiah,
                    'reason' => $adjustment->reason,
                ])->values(),
            ])->values(),
            'deposit_account' => $record->depositAccount?->only(['id', 'required_amount_rupiah', 'booking_id', 'tenancy_id']),
            'extensions' => $record->extensions->map(fn ($extension) => [
                'id' => $extension->id,
                'old_planned_end_exclusive' => $extension->old_planned_end_exclusive->toDateString(),
                'new_planned_end_exclusive' => $extension->new_planned_end_exclusive->toDateString(),
                'old_monthly_rent_rupiah' => (int) $extension->old_monthly_rent_rupiah,
                'new_monthly_rent_rupiah' => (int) $extension->new_monthly_rent_rupiah,
                'effective_from' => $extension->effective_from->toDateString(),
                'reason' => $extension->reason,
            ])->values(),
            'actions' => [
                'can_check_in' => $record->status === 'scheduled',
                'can_cancel' => $record->status === 'scheduled',
                'can_extend' => $record->status === 'active',
                'can_check_out' => $record->status === 'active',
            ],
        ]);
    }

    public function checkIn(string $tenancy, Request $request, TenantContext $tenantContext, TenancyService $tenancies): RedirectResponse
    {
        $data = $request->validate(['actual_start_date' => ['nullable', 'date_format:Y-m-d']]);
        $record = $tenancies->findTenancy($tenantContext->current(), (int) $tenancy);
        $this->authorize('transition', $record);
        $tenancies->activateScheduled($tenantContext->current(), $request->user(), $record->id, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Check-in aktual berhasil dicatat dan tagihan pertama diterbitkan.']);

        return to_route('owner.tenancies.show', $record);
    }

    public function extend(string $tenancy, Request $request, TenantContext $tenantContext, TenancyService $tenancies): RedirectResponse
    {
        $data = $request->validate([
            'new_planned_end_exclusive' => ['required', 'date_format:Y-m-d'],
            'agreed_monthly_rent_rupiah' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $record = $tenancies->findTenancy($tenantContext->current(), (int) $tenancy);
        $this->authorize('transition', $record);
        $tenancies->extend($tenantContext->current(), $request->user(), $record->id, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Masa sewa berhasil diperpanjang.']);

        return to_route('owner.tenancies.show', $record);
    }

    public function checkOut(string $tenancy, Request $request, TenantContext $tenantContext, TenancyService $tenancies): RedirectResponse
    {
        $data = $request->validate([
            'actual_checkout_date' => ['required', 'date_format:Y-m-d'],
            'confirmation' => ['accepted'],
        ]);
        $record = $tenancies->findTenancy($tenantContext->current(), (int) $tenancy);
        $this->authorize('transition', $record);
        $tenancies->checkOut($tenantContext->current(), $request->user(), $record->id, $data['actual_checkout_date']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Check-out berhasil dicatat; histori masa sewa tetap tersimpan.']);

        return to_route('owner.tenancies.show', $record);
    }

    public function cancel(string $tenancy, Request $request, TenantContext $tenantContext, TenancyService $tenancies): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'confirmation' => ['accepted'],
        ]);
        $record = $tenancies->findTenancy($tenantContext->current(), (int) $tenancy);
        $this->authorize('transition', $record);
        $tenancies->cancelScheduled($tenantContext->current(), $request->user(), $record->id, $data['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Masa sewa terjadwal berhasil dibatalkan.']);

        return to_route('owner.tenancies.show', $record);
    }
}
