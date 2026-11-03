<?php

namespace App\Support;

use App\Models\MaintenanceJob;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaintenanceScheduleService
{
    public function __construct(
        private readonly RoomAvailabilityService $availability,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string,mixed> $data */
    public function schedule(Tenant $tenant, User $actor, int $jobId, array $data): MaintenanceJob
    {
        $record = $this->findJob($tenant, $jobId);
        $roomId = isset($data['room_id']) && $data['room_id'] !== '' ? (int) $data['room_id'] : $record->room_id;
        $room = $roomId === null ? null : Room::query()
            ->where('tenant_id', $tenant->id)
            ->where('property_id', $record->property_id)
            ->whereNull('archived_at')
            ->whereKey($roomId)
            ->firstOrFail();
        if (! empty($data['blocks_room']) && $room === null) {
            throw ValidationException::withMessages(['room_id' => 'Pilih kamar untuk jadwal maintenance yang memblokir ketersediaan.']);
        }
        $start = isset($data['scheduled_start_date'])
            ? CarbonImmutable::parse($data['scheduled_start_date'], 'Asia/Jakarta')->startOfDay()
            : null;
        $end = isset($data['scheduled_end_exclusive'])
            ? CarbonImmutable::parse($data['scheduled_end_exclusive'], 'Asia/Jakarta')->startOfDay()
            : null;
        if (($start === null) !== ($end === null) || ($start !== null && ! $end->greaterThan($start))) {
            throw ValidationException::withMessages(['scheduled_end_exclusive' => 'Rentang maintenance harus valid dan memiliki tanggal akhir setelah tanggal mulai.']);
        }
        if (! empty($data['blocks_room']) && ($start === null || $end === null)) {
            throw ValidationException::withMessages(['scheduled_start_date' => 'Rentang tanggal wajib diisi untuk maintenance yang memblokir kamar.']);
        }
        $save = function (?Room $lockedRoom) use ($tenant, $actor, $jobId, $data, $record, $start, $end): MaintenanceJob {
            $property = Property::query()->where('tenant_id', $tenant->id)->whereKey($record->property_id)->firstOrFail();
            abort_if($property->status !== 'active', 404);
            if ($lockedRoom?->archived_at !== null) {
                abort(404);
            }
            $job = MaintenanceJob::query()->where('tenant_id', $tenant->id)->whereKey($jobId)->lockForUpdate()->firstOrFail();
            if (! in_array($job->status, ['reported', 'scheduled'], true)) {
                throw ValidationException::withMessages(['status' => 'Pekerjaan yang selesai atau dibatalkan tidak dapat dijadwalkan.']);
            }

            $blocksRoom = (bool) ($data['blocks_room'] ?? $job->blocks_room);
            if ($blocksRoom) {
                if (! $lockedRoom || ! $start || ! $end) {
                    throw ValidationException::withMessages(['scheduled_start_date' => 'Kamar dan rentang tanggal wajib untuk maintenance yang memblokir.']);
                }
                abort_if($lockedRoom->property()->value('status') !== 'active', 404);
                $conflicts = $this->availability->conflicts(
                    $lockedRoom,
                    $start,
                    $end,
                    ignoreMaintenanceJobId: $job->id,
                )->filter(fn (array $conflict) => $conflict['type'] !== 'room_status');
                if ($conflicts->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'scheduled_start_date' => 'Jadwal memblokir kamar bertabrakan dengan booking, masa sewa, atau maintenance lain. Pekerjaan tetap dilaporkan tanpa jadwal blocking.',
                    ]);
                }
            }

            $oldValues = [
                'status' => $job->status,
                'blocks_room' => $job->blocks_room,
                'scheduled_start_date' => $job->scheduled_start_date?->toDateString(),
                'scheduled_end_exclusive' => $job->scheduled_end_exclusive?->toDateString(),
            ];
            $job->update([
                'room_id' => $lockedRoom?->id,
                'status' => 'scheduled',
                'blocks_room' => $blocksRoom,
                'scheduled_start_date' => $start?->toDateString(),
                'scheduled_end_exclusive' => $end?->toDateString(),
            ]);
            $this->audit->record(
                actor: $actor, tenant: $tenant, action: 'maintenance.scheduled', subjectType: 'maintenance_job', subjectId: $job->id,
                oldValues: $oldValues,
                newValues: [
                    'status' => 'scheduled',
                    'room_id' => $job->room_id,
                    'blocks_room' => $job->blocks_room,
                    'scheduled_start_date' => $job->scheduled_start_date?->toDateString(),
                    'scheduled_end_exclusive' => $job->scheduled_end_exclusive?->toDateString(),
                ],
            );

            return $job->refresh();
        };

        if ($room) {
            return $this->availability->withReconciledRoom($room, $save);
        }

        return DB::transaction(function () use ($tenant, $record, $save): MaintenanceJob {
            $property = Property::query()->where('tenant_id', $tenant->id)->whereKey($record->property_id)->lockForUpdate()->firstOrFail();
            abort_if($property->status !== 'active', 404);

            return $save(null);
        }, attempts: 3);
    }

    public function complete(Tenant $tenant, User $actor, int $jobId): MaintenanceJob
    {
        $record = $this->findJob($tenant, $jobId);

        $complete = function () use ($tenant, $actor, $jobId): MaintenanceJob {
            $job = MaintenanceJob::query()->where('tenant_id', $tenant->id)->whereKey($jobId)->lockForUpdate()->firstOrFail();
            if (! in_array($job->status, ['reported', 'scheduled'], true)) {
                throw ValidationException::withMessages(['status' => 'Pekerjaan ini tidak dapat ditandai selesai dari status saat ini.']);
            }
            $oldStatus = $job->status;
            $completedAt = now('UTC');
            $job->update(['status' => 'completed', 'completed_at' => $completedAt]);
            $this->audit->record(
                actor: $actor, tenant: $tenant, action: 'maintenance.completed', subjectType: 'maintenance_job', subjectId: $job->id,
                oldValues: ['status' => $oldStatus], newValues: ['status' => 'completed', 'completed_at' => $completedAt->toISOString()],
            );

            return $job->refresh();
        };

        if ($record->room) {
            return $this->availability->withReconciledRoom($record->room, fn (): MaintenanceJob => $complete());
        }

        return DB::transaction(function () use ($tenant, $record, $complete): MaintenanceJob {
            Property::query()->where('tenant_id', $tenant->id)->whereKey($record->property_id)->lockForUpdate()->firstOrFail();

            return $complete();
        }, attempts: 3);
    }

    public function cancel(Tenant $tenant, User $actor, int $jobId, ?string $reason = null): MaintenanceJob
    {
        $record = $this->findJob($tenant, $jobId);

        $cancel = function () use ($tenant, $actor, $jobId, $reason): MaintenanceJob {
            $job = MaintenanceJob::query()->where('tenant_id', $tenant->id)->whereKey($jobId)->lockForUpdate()->firstOrFail();
            if (! in_array($job->status, ['reported', 'scheduled'], true)) {
                throw ValidationException::withMessages(['status' => 'Pekerjaan ini tidak dapat dibatalkan dari status saat ini.']);
            }
            $oldStatus = $job->status;
            $job->update(['status' => 'cancelled']);
            $this->audit->record(
                actor: $actor, tenant: $tenant, action: 'maintenance.cancelled', subjectType: 'maintenance_job', subjectId: $job->id,
                oldValues: ['status' => $oldStatus], newValues: ['status' => 'cancelled', 'reason' => $reason],
            );

            return $job->refresh();
        };

        if ($record->room) {
            return $this->availability->withReconciledRoom($record->room, fn (): MaintenanceJob => $cancel());
        }

        return DB::transaction(function () use ($tenant, $record, $cancel): MaintenanceJob {
            Property::query()->where('tenant_id', $tenant->id)->whereKey($record->property_id)->lockForUpdate()->firstOrFail();

            return $cancel();
        }, attempts: 3);
    }

    public function findJob(Tenant $tenant, int $jobId): MaintenanceJob
    {
        return MaintenanceJob::query()->where('tenant_id', $tenant->id)->whereKey($jobId)->with(['property', 'room'])->firstOrFail();
    }
}
