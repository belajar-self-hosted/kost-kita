<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\RoomType;
use App\Support\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RoomTypeController extends Controller
{
    public function index(string $property, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->current();
        $property = $tenant->properties()->findOrFail($property);
        $this->authorize('view', $property);

        $roomTypes = RoomType::query()
            ->where('tenant_id', $tenant->id)
            ->where('property_id', $property->id)
            ->withCount('rooms')
            ->orderBy('status')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Owner/RoomTypes/Index', [
            'property' => $property->only(['id', 'name']),
            'roomTypes' => $roomTypes,
        ]);
    }

    public function create(string $property, TenantContext $tenantContext): Response
    {
        $property = $tenantContext->current()->properties()->findOrFail($property);
        $this->authorize('create', [RoomType::class, $property]);
        abort_if($property->status !== 'active', 404);

        return Inertia::render('Owner/RoomTypes/Create', [
            'property' => $property->only(['id', 'name']),
        ]);
    }

    public function store(Request $request, string $property, TenantContext $tenantContext, AuditLogger $auditLogger): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $validated['name'] = trim($validated['name']);
        if ($validated['name'] === '') {
            throw ValidationException::withMessages(['name' => 'Nama tipe kamar wajib diisi.']);
        }

        $tenant = $tenantContext->current();
        $roomType = DB::transaction(function () use ($request, $property, $tenant, $validated, $auditLogger): RoomType {
            $propertyRecord = $tenant->properties()->whereKey($property)->lockForUpdate()->firstOrFail();
            $this->authorize('create', [RoomType::class, $propertyRecord]);
            abort_if($propertyRecord->status !== 'active', 404);
            $this->assertUniqueName($tenant->id, $propertyRecord->id, $validated['name']);

            $roomType = $propertyRecord->roomTypes()->create([
                'tenant_id' => $tenant->id,
                'name' => $validated['name'],
                'description' => isset($validated['description']) ? trim($validated['description']) : null,
                'status' => 'active',
            ]);

            $auditLogger->record(
                actor: $request->user(), tenant: $tenant,
                action: 'room_type.created', subjectType: 'room_type', subjectId: $roomType->id,
                newValues: $roomType->only(['property_id', 'name', 'status']),
            );

            return $roomType;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tipe kamar berhasil dibuat.']);

        return to_route('owner.properties.room-types.index', $roomType->property_id);
    }

    public function edit(string $roomType, TenantContext $tenantContext): Response
    {
        $roomType = $this->findRoomType($roomType, $tenantContext);
        $this->authorize('update', $roomType);

        return Inertia::render('Owner/RoomTypes/Edit', [
            'roomType' => $roomType->only(['id', 'property_id', 'name', 'description']),
        ]);
    }

    public function update(Request $request, string $roomType, TenantContext $tenantContext, AuditLogger $auditLogger): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $validated['name'] = trim($validated['name']);
        if ($validated['name'] === '') {
            throw ValidationException::withMessages(['name' => 'Nama tipe kamar wajib diisi.']);
        }

        $tenant = $tenantContext->current();
        $record = $this->findRoomType($roomType, $tenantContext);
        $updated = DB::transaction(function () use ($record, $validated, $request, $tenant, $auditLogger): RoomType {
            $property = $tenant->properties()->whereKey($record->property_id)->lockForUpdate()->firstOrFail();
            abort_if($property->status !== 'active', 404);
            $roomType = RoomType::query()
                ->where('tenant_id', $tenant->id)
                ->where('property_id', $property->id)
                ->whereKey($record->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->authorize('update', $roomType);
            $this->assertUniqueName($tenant->id, $property->id, $validated['name'], $roomType->id);

            $oldValues = $roomType->only(['name', 'status']);
            $roomType->update([
                'name' => $validated['name'],
                'description' => isset($validated['description']) ? trim($validated['description']) : null,
            ]);

            $auditLogger->record(
                actor: $request->user(), tenant: $tenant,
                action: 'room_type.updated', subjectType: 'room_type', subjectId: $roomType->id,
                oldValues: $oldValues, newValues: $roomType->only(['name', 'status']),
            );

            return $roomType;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tipe kamar berhasil diperbarui.']);

        return to_route('owner.properties.room-types.index', $updated->property_id);
    }

    public function archive(Request $request, string $roomType, TenantContext $tenantContext, AuditLogger $auditLogger): RedirectResponse
    {
        $request->validate(['confirmation' => ['accepted']]);
        $tenant = $tenantContext->current();
        $record = $this->findRoomType($roomType, $tenantContext);
        $archived = DB::transaction(function () use ($record, $tenant, $request, $auditLogger): bool {
            $property = $tenant->properties()->whereKey($record->property_id)->lockForUpdate()->firstOrFail();
            $roomType = RoomType::query()
                ->where('tenant_id', $tenant->id)
                ->where('property_id', $property->id)
                ->whereKey($record->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->authorize('archive', $roomType);

            if ($roomType->rooms()
                ->where('tenant_id', $tenant->id)
                ->where('property_id', $property->id)
                ->where('operational_status', '!=', 'archived')
                ->exists()) {
                return false;
            }

            $oldValues = $roomType->only(['name', 'status']);
            $roomType->update(['status' => 'archived']);
            $auditLogger->record(
                actor: $request->user(), tenant: $tenant,
                action: 'room_type.archived', subjectType: 'room_type', subjectId: $roomType->id,
                oldValues: $oldValues, newValues: $roomType->only(['name', 'status']),
            );

            return true;
        });

        if (! $archived) {
            return back()->withErrors(['archive' => 'Arsipkan kamar aktif pada tipe ini terlebih dahulu.']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tipe kamar berhasil diarsipkan.']);

        return to_route('owner.properties.room-types.index', $record->property_id);
    }

    private function findRoomType(string $id, TenantContext $tenantContext): RoomType
    {
        return RoomType::query()
            ->where('tenant_id', $tenantContext->current()->id)
            ->whereKey($id)
            ->firstOrFail();
    }

    private function assertUniqueName(int $tenantId, int $propertyId, string $name, ?int $ignoreId = null): void
    {
        $query = RoomType::query()
            ->where('tenant_id', $tenantId)
            ->where('property_id', $propertyId)
            ->where('name_normalized', Str::lower($name));

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['name' => 'Tipe kamar dengan nama tersebut sudah ada di properti ini.']);
        }
    }
}
