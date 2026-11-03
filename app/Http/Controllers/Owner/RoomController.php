<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\MaintenanceJob;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomFacility;
use App\Models\RoomType;
use App\Models\Tenancy;
use App\Support\AuditLogger;
use App\Support\RoomAvailabilityService;
use App\Support\RoomPriceService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RoomController extends Controller
{
    public function index(string $property, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->current();
        $property = $tenant->properties()->findOrFail($property);
        $this->authorize('view', $property);

        $rooms = Room::query()
            ->where('tenant_id', $tenant->id)
            ->where('property_id', $property->id)
            ->with(['roomType:id,name,status'])
            ->withCount('facilities')
            ->orderBy('operational_status')
            ->orderBy('code')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Owner/Rooms/Index', [
            'property' => $property->only(['id', 'name']),
            'rooms' => $rooms,
        ]);
    }

    public function create(string $property, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->current();
        $property = $tenant->properties()->findOrFail($property);
        $this->authorize('create', [Room::class, $property]);
        abort_if($property->status !== 'active', 404);

        return Inertia::render('Owner/Rooms/Create', [
            'property' => $property->only(['id', 'name']),
            'roomTypes' => $property->roomTypes()
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, string $property, TenantContext $tenantContext, AuditLogger $auditLogger): RedirectResponse
    {
        $validated = $this->validatedRoom($request);
        $validated['code'] = trim($validated['code']);
        if ($validated['code'] === '') {
            throw ValidationException::withMessages(['code' => 'Kode kamar wajib diisi.']);
        }

        $tenant = $tenantContext->current();
        $room = DB::transaction(function () use ($request, $property, $tenant, $validated, $auditLogger): Room {
            $propertyRecord = $tenant->properties()->whereKey($property)->lockForUpdate()->firstOrFail();
            $this->authorize('create', [Room::class, $propertyRecord]);
            abort_if($propertyRecord->status !== 'active', 404);
            $roomType = $this->roomTypeForProperty($propertyRecord, $validated['room_type_id'] ?? null);
            $this->assertUniqueCode($tenant->id, $propertyRecord->id, $validated['code']);
            $facilities = $this->normalizedFacilities($validated['facilities'] ?? []);

            $room = $propertyRecord->rooms()->create([
                'tenant_id' => $tenant->id,
                'room_type_id' => $roomType?->id,
                'code' => $validated['code'],
                'operational_status' => $validated['operational_status'],
                'description' => isset($validated['description']) ? trim($validated['description']) : null,
            ]);

            $this->replaceFacilities($room, $tenant->id, $facilities);
            $auditLogger->record(
                actor: $request->user(), tenant: $tenant,
                action: 'room.created', subjectType: 'room', subjectId: $room->id,
                newValues: [
                    ...$room->only(['property_id', 'room_type_id', 'code', 'operational_status']),
                    'facilities' => $facilities,
                ],
            );

            return $room;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Kamar berhasil dibuat.']);

        return to_route('owner.rooms.show', $room);
    }

    public function show(string $room, TenantContext $tenantContext, RoomPriceService $priceService): Response
    {
        $room = $this->findRoom($room, $tenantContext);
        $this->authorize('view', $room);
        $room->load(['facilities:id,room_id,label', 'roomType:id,name,status']);
        $effectiveOn = now('Asia/Jakarta')->toDateString();

        return Inertia::render('Owner/Rooms/Show', [
            'property' => $room->property()->firstOrFail()->only(['id', 'name']),
            'room' => [
                ...$room->only(['id', 'property_id', 'room_type_id', 'code', 'operational_status', 'description', 'archived_at', 'updated_at']),
                'room_type' => $room->roomType?->only(['id', 'name', 'status']),
                'facilities' => $room->facilities->map(fn (RoomFacility $facility) => $facility->label)->values(),
                'price' => $priceService->resolveForRoom($room, $effectiveOn),
                'price_effective_on' => $effectiveOn,
            ],
        ]);
    }

    public function edit(string $room, TenantContext $tenantContext): Response
    {
        $room = $this->findRoom($room, $tenantContext);
        $this->authorize('update', $room);
        $property = $room->property()->firstOrFail();

        return Inertia::render('Owner/Rooms/Edit', [
            'property' => $property->only(['id', 'name']),
            'room' => [
                ...$room->only(['id', 'property_id', 'room_type_id', 'code', 'operational_status', 'description']),
                'facilities' => $room->facilities()->orderBy('normalized_label')->pluck('label')->values(),
            ],
            'roomTypes' => $property->roomTypes()
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, string $room, TenantContext $tenantContext, AuditLogger $auditLogger, RoomAvailabilityService $availability): RedirectResponse
    {
        $validated = $this->validatedRoom($request);
        $validated['code'] = trim($validated['code']);
        if ($validated['code'] === '') {
            throw ValidationException::withMessages(['code' => 'Kode kamar wajib diisi.']);
        }

        $tenant = $tenantContext->current();
        $record = $this->findRoom($room, $tenantContext);
        $updated = $availability->withReconciledRoom($record, function (Room $room) use ($request, $tenant, $validated, $auditLogger): Room {
            $property = $tenant->properties()->whereKey($room->property_id)->firstOrFail();
            abort_if($property->status !== 'active', 404);
            $this->authorize('update', $room);
            if (
                $room->operational_status !== $validated['operational_status']
                && $validated['operational_status'] === 'out_of_service'
                && $this->availabilityBlockersExist($tenant->id, $property->id, $room->id)
            ) {
                throw ValidationException::withMessages([
                    'operational_status' => 'Selesaikan atau ubah booking, masa sewa, dan maintenance aktif sebelum mengubah status operasional kamar.',
                ]);
            }

            $roomType = $this->roomTypeForProperty($property, $validated['room_type_id'] ?? null);
            $this->assertUniqueCode($tenant->id, $property->id, $validated['code'], $room->id);
            $facilities = $this->normalizedFacilities($validated['facilities'] ?? []);
            $oldValues = [
                ...$room->only(['room_type_id', 'code', 'operational_status']),
                'facilities' => $room->facilities()->orderBy('normalized_label')->pluck('label')->values()->all(),
            ];

            $room->update([
                'room_type_id' => $roomType?->id,
                'code' => $validated['code'],
                'operational_status' => $validated['operational_status'],
                'description' => isset($validated['description']) ? trim($validated['description']) : null,
            ]);
            $this->replaceFacilities($room, $tenant->id, $facilities);

            $auditLogger->record(
                actor: $request->user(), tenant: $tenant,
                action: 'room.updated', subjectType: 'room', subjectId: $room->id,
                oldValues: $oldValues,
                newValues: [
                    ...$room->only(['room_type_id', 'code', 'operational_status']),
                    'facilities' => $facilities,
                ],
            );

            return $room;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Perubahan kamar berhasil disimpan.']);

        return to_route('owner.rooms.show', $updated);
    }

    public function archive(Request $request, string $room, TenantContext $tenantContext, AuditLogger $auditLogger, RoomAvailabilityService $availability): RedirectResponse
    {
        $request->validate(['confirmation' => ['accepted']]);
        $tenant = $tenantContext->current();
        $record = $this->findRoom($room, $tenantContext);
        $archived = $availability->withReconciledRoom($record, function (Room $room) use ($tenant, $request, $auditLogger): bool {
            $this->authorize('archive', $room);

            if ($this->openBusinessRecordsExist($tenant->id, $room->property_id, $room->id)) {
                return false;
            }

            $oldValues = $room->only(['code', 'room_type_id', 'operational_status', 'archived_at']);
            $room->forceFill(['operational_status' => 'archived', 'archived_at' => now('UTC')])->save();
            $auditLogger->record(
                actor: $request->user(), tenant: $tenant,
                action: 'room.archived', subjectType: 'room', subjectId: $room->id,
                oldValues: $oldValues,
                newValues: $room->only(['code', 'room_type_id', 'operational_status', 'archived_at']),
            );

            return true;
        });

        if (! $archived) {
            return back()->withErrors(['archive' => 'Selesaikan booking, masa sewa, atau maintenance terkait sebelum mengarsipkan kamar.']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Kamar berhasil diarsipkan.']);

        return to_route('owner.properties.rooms.index', $record->property_id);
    }

    /** @return array<string, mixed> */
    private function validatedRoom(Request $request): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:80'],
            'room_type_id' => ['nullable', 'integer'],
            'operational_status' => ['required', 'in:rentable,out_of_service'],
            'description' => ['nullable', 'string', 'max:2000'],
            'facilities' => ['nullable', 'array', 'max:30'],
            'facilities.*' => ['nullable', 'string', 'max:100'],
        ]);
    }

    private function findRoom(string $id, TenantContext $tenantContext): Room
    {
        return Room::query()
            ->where('tenant_id', $tenantContext->current()->id)
            ->whereKey($id)
            ->firstOrFail();
    }

    private function roomTypeForProperty(Property $property, mixed $roomTypeId): ?RoomType
    {
        if ($roomTypeId === null || $roomTypeId === '') {
            return null;
        }

        return $property->roomTypes()
            ->where('status', 'active')
            ->whereKey($roomTypeId)
            ->firstOrFail();
    }

    private function assertUniqueCode(int $tenantId, int $propertyId, string $code, ?int $ignoreId = null): void
    {
        $query = Room::query()
            ->where('tenant_id', $tenantId)
            ->where('property_id', $propertyId)
            ->where('code_normalized', Str::lower($code));

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['code' => 'Kode kamar tersebut sudah dipakai di properti ini.']);
        }
    }

    /** @param array<int, mixed> $facilities
     * @return list<string>
     */
    private function normalizedFacilities(array $facilities): array
    {
        $labels = array_values(array_filter(array_map(fn ($label) => trim((string) $label), $facilities), fn ($label) => $label !== ''));
        $normalized = array_map(fn (string $label) => Str::lower($label), $labels);

        if (count(array_unique($normalized)) !== count($normalized)) {
            throw ValidationException::withMessages(['facilities' => 'Fasilitas yang sama tidak boleh dicatat dua kali.']);
        }

        return $labels;
    }

    /** @param list<string> $facilities */
    private function replaceFacilities(Room $room, int $tenantId, array $facilities): void
    {
        $room->facilities()->delete();

        foreach ($facilities as $label) {
            $room->facilities()->create([
                'tenant_id' => $tenantId,
                'label' => $label,
            ]);
        }
    }

    private function openBusinessRecordsExist(int $tenantId, int $propertyId, int $roomId): bool
    {
        return Booking::query()
            ->where('tenant_id', $tenantId)
            ->where('property_id', $propertyId)
            ->where('room_id', $roomId)
            ->whereIn('status', ['pending', 'confirmed'])
            ->exists()
            || Tenancy::query()
                ->where('tenant_id', $tenantId)
                ->where('property_id', $propertyId)
                ->where('room_id', $roomId)
                ->whereIn('status', ['scheduled', 'active'])
                ->exists()
            || MaintenanceJob::query()
                ->where('tenant_id', $tenantId)
                ->where('property_id', $propertyId)
                ->where('room_id', $roomId)
                ->whereIn('status', ['reported', 'scheduled'])
                ->exists();
    }

    private function availabilityBlockersExist(int $tenantId, int $propertyId, int $roomId): bool
    {
        // Out-of-service may coexist with scheduled maintenance; it only broadens the unavailable period.
        return Booking::query()
            ->where('tenant_id', $tenantId)
            ->where('property_id', $propertyId)
            ->where('room_id', $roomId)
            ->where('status', 'confirmed')
            ->exists()
            || Tenancy::query()
                ->where('tenant_id', $tenantId)
                ->where('property_id', $propertyId)
                ->where('room_id', $roomId)
                ->whereIn('status', ['scheduled', 'active'])
                ->exists();
    }
}
