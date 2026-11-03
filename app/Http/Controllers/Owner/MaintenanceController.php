<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceJob;
use App\Support\AuditLogger;
use App\Support\MaintenanceScheduleService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MaintenanceController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->current();
        $filters = $request->validate([
            'property_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:reported,scheduled,completed,cancelled'],
        ]);
        $jobs = MaintenanceJob::query()
            ->where('tenant_id', $tenant->id)
            ->when(isset($filters['property_id']), fn ($query) => $query->where('property_id', $filters['property_id']))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->with(['property:id,name', 'room:id,code'])
            ->orderByRaw("CASE status WHEN 'reported' THEN 0 WHEN 'scheduled' THEN 1 WHEN 'completed' THEN 2 ELSE 3 END")
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->withQueryString();
        $properties = $tenant->properties()
            ->where('status', 'active')
            ->with(['rooms' => fn ($query) => $query->whereNull('archived_at')->orderBy('code')])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($property) => [
                'id' => $property->id,
                'name' => $property->name,
                'rooms' => $property->rooms->map(fn ($room) => ['id' => $room->id, 'code' => $room->code])->values(),
            ]);

        return Inertia::render('Owner/Maintenance/Index', [
            'jobs' => $jobs,
            'properties' => $properties,
            'filters' => ['property_id' => $filters['property_id'] ?? '', 'status' => $filters['status'] ?? ''],
        ]);
    }

    public function store(Request $request, TenantContext $tenantContext, MaintenanceScheduleService $maintenance): RedirectResponse
    {
        $data = $request->validate([
            'property_id' => ['required', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'category' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:4000'],
            'blocks_room' => ['nullable', 'boolean'],
            'scheduled_start_date' => ['nullable', 'date_format:Y-m-d'],
            'scheduled_end_exclusive' => ['nullable', 'date_format:Y-m-d'],
            'vendor_name' => ['nullable', 'string', 'max:160'],
        ]);
        $tenant = $tenantContext->current();
        $this->authorize('create', MaintenanceJob::class);
        $job = DB::transaction(function () use ($request, $tenant, $data): MaintenanceJob {
            $property = $tenant->properties()->whereKey($data['property_id'])->where('status', 'active')->lockForUpdate()->firstOrFail();
            $room = null;
            if (! empty($data['room_id'])) {
                $room = $property->rooms()->whereKey($data['room_id'])->whereNull('archived_at')->firstOrFail();
            }
            $job = MaintenanceJob::query()->create([
                'tenant_id' => $tenant->id,
                'property_id' => $property->id,
                'room_id' => $room?->id,
                'category' => trim($data['category']),
                'description' => trim($data['description']),
                'status' => 'reported',
                'blocks_room' => (bool) ($data['blocks_room'] ?? false),
                'vendor_name' => isset($data['vendor_name']) ? trim($data['vendor_name']) : null,
                'created_by' => $request->user()->id,
            ]);
            app(AuditLogger::class)->record(
                actor: $request->user(), tenant: $tenant, action: 'maintenance.reported', subjectType: 'maintenance_job', subjectId: $job->id,
                newValues: ['property_id' => $property->id, 'room_id' => $room?->id, 'category' => $job->category, 'status' => 'reported'],
            );

            return $job;
        });

        if (! empty($data['scheduled_start_date']) || ! empty($data['scheduled_end_exclusive']) || ! empty($data['blocks_room'])) {
            try {
                $maintenance->schedule($tenant, $request->user(), $job->id, $data);
            } catch (ValidationException $exception) {
                return back()->withInput()->withErrors($exception->errors());
            }
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Catatan maintenance berhasil dibuat.']);

        return to_route('owner.maintenance.show', $job);
    }

    public function show(string $work, TenantContext $tenantContext, MaintenanceScheduleService $maintenance): Response
    {
        $job = $maintenance->findJob($tenantContext->current(), (int) $work);
        $this->authorize('view', $job);

        return Inertia::render('Owner/Maintenance/Show', [
            'job' => [
                'id' => $job->id,
                'status' => $job->status,
                'category' => $job->category,
                'description' => $job->description,
                'blocks_room' => $job->blocks_room,
                'scheduled_start_date' => $job->scheduled_start_date?->toDateString(),
                'scheduled_end_exclusive' => $job->scheduled_end_exclusive?->toDateString(),
                'completed_at' => $job->completed_at?->setTimezone('Asia/Jakarta')->toIso8601String(),
                'created_at' => $job->created_at?->setTimezone('Asia/Jakarta')->toIso8601String(),
                'vendor_name' => $job->vendor_name,
                'room' => $job->room?->only(['id', 'code']),
                'property' => $job->property?->only(['id', 'name']),
            ],
        ]);
    }

    public function schedule(string $work, Request $request, TenantContext $tenantContext, MaintenanceScheduleService $maintenance): RedirectResponse
    {
        $data = $request->validate([
            'room_id' => ['nullable', 'integer'],
            'blocks_room' => ['required', 'boolean'],
            'scheduled_start_date' => ['nullable', 'date_format:Y-m-d'],
            'scheduled_end_exclusive' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $job = $maintenance->findJob($tenantContext->current(), (int) $work);
        $this->authorize('transition', $job);
        $maintenance->schedule($tenantContext->current(), $request->user(), $job->id, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Jadwal maintenance berhasil disimpan.']);

        return to_route('owner.maintenance.show', $job);
    }

    public function complete(string $work, Request $request, TenantContext $tenantContext, MaintenanceScheduleService $maintenance): RedirectResponse
    {
        $request->validate(['confirmation' => ['accepted']]);
        $job = $maintenance->findJob($tenantContext->current(), (int) $work);
        $this->authorize('transition', $job);
        $maintenance->complete($tenantContext->current(), $request->user(), $job->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Maintenance ditandai selesai.']);

        return to_route('owner.maintenance.show', $job);
    }

    public function cancel(string $work, Request $request, TenantContext $tenantContext, MaintenanceScheduleService $maintenance): RedirectResponse
    {
        $data = $request->validate([
            'confirmation' => ['accepted'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $job = $maintenance->findJob($tenantContext->current(), (int) $work);
        $this->authorize('transition', $job);
        $maintenance->cancel($tenantContext->current(), $request->user(), $job->id, $data['reason'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Jadwal maintenance dibatalkan.']);

        return to_route('owner.maintenance.show', $job);
    }
}
