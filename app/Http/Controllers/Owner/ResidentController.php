<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Resident;
use App\Support\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ResidentController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->current();
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'include_archived' => ['nullable', 'boolean'],
        ]);

        $residents = Resident::query()
            ->where('tenant_id', $tenant->id)
            ->when(! ($filters['include_archived'] ?? false), fn ($query) => $query->whereNull('archived_at'))
            ->when(isset($filters['search']) && trim($filters['search']) !== '', function ($query) use ($filters): void {
                $search = '%'.trim($filters['search']).'%';
                $query->where(fn ($inner) => $inner->where('full_name', 'like', $search)->orWhere('whatsapp_number', 'like', $search));
            })
            ->withCount('tenancies')
            ->orderBy('full_name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Owner/Residents/Index', [
            'residents' => $residents,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'include_archived' => (bool) ($filters['include_archived'] ?? false),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Owner/Residents/Create');
    }

    public function store(Request $request, TenantContext $tenantContext, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:160'],
            'whatsapp_number' => ['required', 'string', 'max:40'],
            'contact_info' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);
        $data['full_name'] = trim($data['full_name']);
        $data['whatsapp_number'] = trim($data['whatsapp_number']);
        if ($data['full_name'] === '' || $data['whatsapp_number'] === '') {
            throw ValidationException::withMessages(['full_name' => 'Nama dan nomor WhatsApp wajib diisi.']);
        }
        $tenant = $tenantContext->current();
        $resident = DB::transaction(function () use ($request, $tenant, $data, $audit): Resident {
            $resident = Resident::query()->create([
                'tenant_id' => $tenant->id,
                ...$data,
            ]);
            $audit->record(
                actor: $request->user(), tenant: $tenant, action: 'resident.created', subjectType: 'resident', subjectId: $resident->id,
                newValues: ['full_name' => $resident->full_name],
            );

            return $resident;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Data penyewa berhasil dibuat.']);

        return to_route('owner.residents.show', $resident);
    }

    public function show(string $resident, TenantContext $tenantContext): Response
    {
        $resident = $this->findResident($resident, $tenantContext);
        $this->authorize('view', $resident);

        return Inertia::render('Owner/Residents/Show', [
            'resident' => $resident->only(['id', 'full_name', 'whatsapp_number', 'contact_info', 'notes', 'archived_at']),
            'tenancies' => $resident->tenancies()
                ->with('room:id,code')
                ->orderByDesc('planned_start_date')
                ->get()
                ->map(fn ($tenancy) => [
                    'id' => $tenancy->id,
                    'status' => $tenancy->status,
                    'room_code' => $tenancy->room?->code,
                    'planned_start_date' => $tenancy->planned_start_date->toDateString(),
                    'planned_end_exclusive' => $tenancy->planned_end_exclusive->toDateString(),
                ]),
            'bookings' => $resident->bookings()
                ->orderByDesc('planned_start_date')
                ->get(['id', 'status', 'planned_start_date', 'planned_end_exclusive']),
        ]);
    }

    public function edit(string $resident, TenantContext $tenantContext): Response
    {
        $resident = $this->findResident($resident, $tenantContext);
        $this->authorize('update', $resident);

        return Inertia::render('Owner/Residents/Edit', [
            'resident' => $resident->only(['id', 'full_name', 'whatsapp_number', 'contact_info', 'notes', 'archived_at']),
        ]);
    }

    public function update(Request $request, string $resident, TenantContext $tenantContext, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:160'],
            'whatsapp_number' => ['required', 'string', 'max:40'],
            'contact_info' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);
        $data['full_name'] = trim($data['full_name']);
        $data['whatsapp_number'] = trim($data['whatsapp_number']);
        if ($data['full_name'] === '' || $data['whatsapp_number'] === '') {
            throw ValidationException::withMessages(['full_name' => 'Nama dan nomor WhatsApp wajib diisi.']);
        }
        $tenant = $tenantContext->current();
        $record = $this->findResident($resident, $tenantContext);
        $updated = DB::transaction(function () use ($request, $tenant, $record, $data, $audit): Resident {
            $resident = Resident::query()->where('tenant_id', $tenant->id)->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $this->authorize('update', $resident);
            if ($resident->archived_at !== null) {
                throw ValidationException::withMessages(['resident' => 'Data penyewa yang diarsipkan tidak dapat diedit.']);
            }
            $resident->update($data);
            $audit->record(
                actor: $request->user(), tenant: $tenant, action: 'resident.updated', subjectType: 'resident', subjectId: $resident->id,
                newValues: ['changed_fields' => array_keys($data)],
            );

            return $resident;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Data penyewa berhasil diperbarui.']);

        return to_route('owner.residents.show', $updated);
    }

    public function archive(Request $request, string $resident, TenantContext $tenantContext, AuditLogger $audit): RedirectResponse
    {
        $request->validate(['confirmation' => ['accepted']]);
        $tenant = $tenantContext->current();
        $record = $this->findResident($resident, $tenantContext);
        $archived = DB::transaction(function () use ($request, $tenant, $record, $audit): bool {
            $resident = Resident::query()->where('tenant_id', $tenant->id)->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $this->authorize('archive', $resident);
            $hasOpenBookings = $resident->bookings()
                ->where(fn ($query) => $query
                    ->where('status', 'pending')
                    ->orWhere(fn ($confirmed) => $confirmed
                        ->where('status', 'confirmed')
                        ->where('check_in_deadline_at', '>', now('UTC'))))
                ->exists();
            $hasOpenTenancies = $resident->tenancies()->whereIn('status', ['scheduled', 'active'])->exists();
            if ($hasOpenBookings || $hasOpenTenancies) {
                return false;
            }
            if ($resident->archived_at !== null) {
                return true;
            }

            $resident->forceFill(['archived_at' => now('UTC')])->save();
            $audit->record(
                actor: $request->user(), tenant: $tenant, action: 'resident.archived', subjectType: 'resident', subjectId: $resident->id,
                oldValues: ['archived_at' => null], newValues: ['archived_at' => $resident->archived_at?->toISOString()], reason: 'owner_requested_archive',
            );

            return true;
        });
        if (! $archived) {
            return back()->withErrors(['archive' => 'Selesaikan booking dan masa sewa aktif sebelum mengarsipkan penyewa.']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Data penyewa berhasil diarsipkan.']);

        return to_route('owner.residents.index');
    }

    private function findResident(string $id, TenantContext $tenantContext): Resident
    {
        return Resident::query()->where('tenant_id', $tenantContext->current()->id)->whereKey($id)->firstOrFail();
    }
}
