<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\MaintenanceJob;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tenancy;
use App\Support\AuditLogger;
use App\Support\RoomAvailabilityService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PropertyController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,archived'],
        ]);
        $tenant = $tenantContext->current();

        $properties = $tenant->properties()
            ->when($filters['q'] ?? null, fn ($query, $search) => $query->where(function ($query) use ($search) {
                $query->where('name', 'like', '%'.$search.'%')->orWhere('address', 'like', '%'.$search.'%');
            }))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('status')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Owner/Properties/Index', [
            'properties' => $properties,
            'filters' => ['q' => $filters['q'] ?? '', 'status' => $filters['status'] ?? ''],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Property::class);

        return Inertia::render('Owner/Properties/Create');
    }

    public function store(Request $request, TenantContext $tenantContext, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize('create', Property::class);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'address' => ['nullable', 'string', 'max:1000'],
        ]);
        $tenant = $tenantContext->current();

        $property = DB::transaction(function () use ($tenant, $validated, $request, $auditLogger): Property {
            $property = $tenant->properties()->create([
                'name' => trim($validated['name']),
                'address' => isset($validated['address']) ? trim($validated['address']) : null,
                'status' => 'active',
            ]);

            $auditLogger->record(
                actor: $request->user(), tenant: $tenant,
                action: 'property.created', subjectType: 'property', subjectId: $property->id,
                newValues: $property->only(['name', 'address', 'status']),
            );

            return $property;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Properti berhasil dibuat.']);

        return to_route('owner.properties.show', $property);
    }

    public function show(string $property, TenantContext $tenantContext): Response
    {
        $property = $tenantContext->current()->properties()->findOrFail($property);
        $this->authorize('view', $property);

        return Inertia::render('Owner/Properties/Show', [
            'property' => $property->only(['id', 'name', 'address', 'status', 'archived_at', 'updated_at']),
        ]);
    }

    public function edit(string $property, TenantContext $tenantContext): Response
    {
        $property = $tenantContext->current()->properties()->findOrFail($property);
        $this->authorize('update', $property);

        return Inertia::render('Owner/Properties/Edit', [
            'property' => $property->only(['id', 'name', 'address']),
        ]);
    }

    public function update(Request $request, string $property, TenantContext $tenantContext, AuditLogger $auditLogger): RedirectResponse
    {
        $property = $tenantContext->current()->properties()->findOrFail($property);
        $this->authorize('update', $property);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'address' => ['nullable', 'string', 'max:1000'],
        ]);
        $tenant = $tenantContext->current();

        DB::transaction(function () use ($property, $validated, $request, $tenant, $auditLogger): void {
            $oldValues = $property->only(['name', 'address', 'status']);
            $property->update([
                'name' => trim($validated['name']),
                'address' => isset($validated['address']) ? trim($validated['address']) : null,
            ]);

            $auditLogger->record(
                actor: $request->user(), tenant: $tenant,
                action: 'property.updated', subjectType: 'property', subjectId: $property->id,
                oldValues: $oldValues, newValues: $property->only(['name', 'address', 'status']),
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Perubahan properti berhasil disimpan.']);

        return to_route('owner.properties.show', $property);
    }

    public function archive(Request $request, string $property, TenantContext $tenantContext, AuditLogger $auditLogger, RoomAvailabilityService $availability): RedirectResponse
    {
        $request->validate(['confirmation' => ['accepted']]);
        $tenant = $tenantContext->current();
        $archived = DB::transaction(function () use ($property, $request, $tenant, $auditLogger, $availability): bool {
            // Phase 2 booking, tenancy, and maintenance writes that add or change blockers must lock this property row first.
            $property = $tenant->properties()->whereKey($property)->lockForUpdate()->firstOrFail();
            $this->authorize('archive', $property);

            $rooms = Room::query()
                ->where('tenant_id', $tenant->id)
                ->where('property_id', $property->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            foreach ($rooms as $room) {
                $availability->lockRelatedBlockers($room);
                $availability->reconcileExpiredBookingsForLockedRoom($room);
            }

            if ($this->openBusinessRecordsExist($property, $tenant->id)) {
                return false;
            }

            $oldValues = $property->only(['name', 'address', 'status', 'archived_at']);
            $property->forceFill(['status' => 'archived', 'archived_at' => now('UTC')])->save();

            $auditLogger->record(
                actor: $request->user(), tenant: $tenant,
                action: 'property.archived', subjectType: 'property', subjectId: $property->id,
                oldValues: $oldValues,
                newValues: $property->only(['name', 'address', 'status', 'archived_at']),
                reason: 'owner_requested_archive',
            );

            return true;
        });

        if (! $archived) {
            return back()->withErrors(['archive' => 'Arsipkan tipe dan kamar terlebih dahulu, lalu selesaikan booking, masa sewa, atau maintenance aktif sebelum mengarsipkan properti.']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Properti berhasil diarsipkan.']);

        return to_route('owner.properties.index');
    }

    private function openBusinessRecordsExist(Property $property, int $tenantId): bool
    {
        return RoomType::query()
            ->where('tenant_id', $tenantId)
            ->where('property_id', $property->id)
            ->where('status', '!=', 'archived')
            ->exists()
            || Room::query()
                ->where('tenant_id', $tenantId)
                ->where('property_id', $property->id)
                ->where('operational_status', '!=', 'archived')
                ->exists()
            || Booking::query()
                ->where('tenant_id', $tenantId)
                ->where('property_id', $property->id)
                ->whereIn('status', ['pending', 'confirmed'])
                ->exists()
            || Tenancy::query()
                ->where('tenant_id', $tenantId)
                ->where('property_id', $property->id)
                ->whereIn('status', ['scheduled', 'active'])
                ->exists()
            || MaintenanceJob::query()
                ->where('tenant_id', $tenantId)
                ->where('property_id', $property->id)
                ->whereIn('status', ['reported', 'scheduled'])
                ->exists();
    }
}
