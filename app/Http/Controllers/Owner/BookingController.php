<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Room;
use App\Support\BookingService;
use App\Support\RoomPriceService;
use App\Support\TenancyService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->current();
        $filters = $request->validate([
            'status' => ['nullable', 'in:pending,confirmed,checked_in,cancelled,no_show'],
            'property_id' => ['nullable', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $bookings = Booking::query()
            ->where('tenant_id', $tenant->id)
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['property_id']), fn ($query) => $query->where('property_id', $filters['property_id']))
            ->when(isset($filters['room_id']), fn ($query) => $query->where('room_id', $filters['room_id']))
            ->when(isset($filters['from']), fn ($query) => $query->whereDate('planned_start_date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($query) => $query->whereDate('planned_start_date', '<=', $filters['to']))
            ->with(['property:id,name', 'room:id,code', 'resident:id,full_name,whatsapp_number'])
            ->orderByDesc('planned_start_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Owner/Bookings/Index', [
            'bookings' => $bookings,
            'filters' => [
                'status' => $filters['status'] ?? '',
                'property_id' => $filters['property_id'] ?? '',
                'room_id' => $filters['room_id'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
        ]);
    }

    public function create(Request $request, TenantContext $tenantContext, RoomPriceService $prices): Response
    {
        $tenant = $tenantContext->current();
        $initial = $request->validate([
            'room_id' => ['nullable', 'integer'],
            'planned_start_date' => ['nullable', 'date_format:Y-m-d'],
            'planned_end_exclusive' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $properties = $tenant->properties()
            ->where('status', 'active')
            ->with(['rooms' => fn ($query) => $query->where('operational_status', 'rentable')->whereNull('archived_at')->orderBy('code')])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($property) => [
                'id' => $property->id,
                'name' => $property->name,
                'rooms' => $property->rooms->map(fn ($room) => [
                    'id' => $room->id,
                    'code' => $room->code,
                    'operational_status' => $room->operational_status,
                ])->values(),
            ]);
        $catalogPrice = null;
        if (isset($initial['room_id'], $initial['planned_start_date'])) {
            $room = Room::query()->where('tenant_id', $tenant->id)->whereKey($initial['room_id'])->first();
            if ($room) {
                $catalogPrice = $prices->resolveForRoom($room, $initial['planned_start_date']);
            }
        }

        return Inertia::render('Owner/Bookings/Create', [
            'properties' => $properties,
            'residents' => $tenant->residents()->whereNull('archived_at')->orderBy('full_name')->get(['id', 'full_name', 'whatsapp_number']),
            'initial' => [
                'room_id' => $initial['room_id'] ?? null,
                'planned_start_date' => $initial['planned_start_date'] ?? CarbonImmutable::today('Asia/Jakarta')->toDateString(),
                'planned_end_exclusive' => $initial['planned_end_exclusive'] ?? null,
                'agreed_monthly_rent_rupiah' => $catalogPrice['monthly_rent_rupiah'] ?? null,
                'required_deposit_rupiah' => 0,
            ],
        ]);
    }

    public function store(Request $request, TenantContext $tenantContext, BookingService $bookings): RedirectResponse
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
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);
        $tenant = $tenantContext->current();
        $booking = $bookings->createPending($tenant, $request->user(), $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Booking pending berhasil dicatat.']);

        return to_route('owner.bookings.show', $booking);
    }

    public function show(string $booking, TenantContext $tenantContext, BookingService $bookings): Response
    {
        $record = $bookings->findBooking($tenantContext->current(), (int) $booking);
        $this->authorize('view', $record);
        $record->load(['property:id,name', 'room:id,code', 'resident:id,full_name,whatsapp_number', 'depositAccount', 'tenancy:id,booking_id,status']);

        return Inertia::render('Owner/Bookings/Show', [
            'booking' => [
                'id' => $record->id,
                'status' => $record->status,
                'property' => $record->property?->only(['id', 'name']),
                'room' => $record->room?->only(['id', 'code']),
                'resident' => $record->resident?->only(['id', 'full_name', 'whatsapp_number']),
                'planned_start_date' => $record->planned_start_date->toDateString(),
                'planned_end_exclusive' => $record->planned_end_exclusive->toDateString(),
                'agreed_monthly_rent_rupiah' => (int) $record->agreed_monthly_rent_rupiah,
                'check_in_deadline_at' => $record->check_in_deadline_at?->setTimezone('Asia/Jakarta')->toIso8601String(),
                'notes' => $record->notes,
            ],
            'grace_extension_idempotency_key' => (string) Str::uuid(),
            'deposit_account' => $record->depositAccount?->only(['id', 'required_amount_rupiah', 'booking_id', 'tenancy_id']),
            'tenancy' => $record->tenancy?->only(['id', 'status']),
            'actions' => [
                'can_confirm' => $record->status === 'pending',
                'can_cancel' => in_array($record->status, ['pending', 'confirmed'], true),
                'can_extend_grace' => $record->status === 'confirmed' && $record->check_in_deadline_at?->isFuture(),
                'can_check_in' => $record->status === 'confirmed',
            ],
        ]);
    }

    public function confirm(string $booking, Request $request, TenantContext $tenantContext, BookingService $bookings): RedirectResponse
    {
        $record = $bookings->findBooking($tenantContext->current(), (int) $booking);
        $this->authorize('transition', $record);
        $bookings->confirm($tenantContext->current(), $request->user(), $record->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Booking berhasil dikonfirmasi dan menahan periode kamar.']);

        return to_route('owner.bookings.show', $record);
    }

    public function cancel(string $booking, Request $request, TenantContext $tenantContext, BookingService $bookings): RedirectResponse
    {
        $data = $request->validate(['confirmation' => ['accepted'], 'reason' => ['nullable', 'string', 'max:500']]);
        $record = $bookings->findBooking($tenantContext->current(), (int) $booking);
        $this->authorize('transition', $record);
        $bookings->cancel($tenantContext->current(), $request->user(), $record->id, $data['reason'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Booking berhasil dibatalkan.']);

        return to_route('owner.bookings.show', $record);
    }

    public function extendGracePeriod(string $booking, Request $request, TenantContext $tenantContext, BookingService $bookings): RedirectResponse
    {
        $data = $request->validate([
            'new_deadline_at' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $record = $bookings->findBooking($tenantContext->current(), (int) $booking);
        $this->authorize('transition', $record);
        $bookings->extendGracePeriod(
            $tenantContext->current(), $request->user(), $record->id,
            $data['new_deadline_at'], $data['reason'], $data['idempotency_key'],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Grace period berhasil diperpanjang.']);

        return to_route('owner.bookings.show', $record);
    }

    public function checkIn(string $booking, Request $request, TenantContext $tenantContext, BookingService $bookings, TenancyService $tenancies): RedirectResponse
    {
        $data = $request->validate([
            'actual_start_date' => ['nullable', 'date_format:Y-m-d'],
            'agreed_monthly_rent_rupiah' => ['nullable', 'integer', 'min:1'],
            'payment_terms' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);
        $record = $bookings->findBooking($tenantContext->current(), (int) $booking);
        $this->authorize('transition', $record);
        $tenancy = $tenancies->checkInFromBooking($tenantContext->current(), $request->user(), $record->id, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Check-in berhasil dicatat.']);

        return to_route('owner.tenancies.show', $tenancy);
    }
}
