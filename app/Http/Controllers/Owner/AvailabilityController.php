<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Support\RoomAvailabilityService;
use App\Support\RoomPriceService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AvailabilityController extends Controller
{
    public function index(
        Request $request,
        TenantContext $tenantContext,
        RoomAvailabilityService $availability,
        RoomPriceService $prices,
    ): Response {
        $tenant = $tenantContext->current();
        $filters = $request->validate([
            'property_id' => ['nullable', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_exclusive' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $start = CarbonImmutable::parse($filters['start_date'] ?? CarbonImmutable::today('Asia/Jakarta')->toDateString(), 'Asia/Jakarta')->startOfDay();
        $end = CarbonImmutable::parse($filters['end_exclusive'] ?? $start->addDays(30)->toDateString(), 'Asia/Jakarta')->startOfDay();
        if (! $end->greaterThan($start)) {
            abort(422, 'Tanggal akhir harus setelah tanggal mulai.');
        }

        $properties = $tenant->properties()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name']);
        $rooms = collect();
        if (isset($filters['property_id'])) {
            $property = $tenant->properties()->whereKey($filters['property_id'])->where('status', 'active')->firstOrFail();
            $query = $property->rooms()->whereNull('archived_at')->orderBy('code');
            if (isset($filters['room_id'])) {
                $query->whereKey($filters['room_id']);
            }
            $rooms = $query->get();
            if (isset($filters['room_id']) && $rooms->isEmpty()) {
                abort(404);
            }
            $rooms = $rooms->map(function (Room $room) use ($availability, $prices, $start, $end) {
                return [
                    'id' => $room->id,
                    'code' => $room->code,
                    'operational_status' => $room->operational_status,
                    'price' => $prices->resolveForRoom($room, $start),
                    'conflicts' => $availability->reconcileAndGetConflicts($room, $start, $end),
                ];
            });
        }

        return Inertia::render('Owner/Availability/Index', [
            'properties' => $properties,
            'filters' => [
                'property_id' => $filters['property_id'] ?? '',
                'room_id' => $filters['room_id'] ?? '',
                'start_date' => $start->toDateString(),
                'end_exclusive' => $end->toDateString(),
            ],
            'rooms' => $rooms,
        ]);
    }
}
