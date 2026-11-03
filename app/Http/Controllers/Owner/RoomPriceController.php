<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\RoomPrice;
use App\Models\RoomType;
use App\Support\AuditLogger;
use App\Support\RoomPriceService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RoomPriceController extends Controller
{
    public function indexForRoom(string $room, TenantContext $tenantContext, RoomPriceService $priceService): Response
    {
        $room = $this->findRoom($room, $tenantContext);
        $this->authorize('view', $room);
        $tenantId = $tenantContext->current()->id;
        $date = now('Asia/Jakarta')->toDateString();

        return Inertia::render('Owner/Prices/Index', [
            'target' => ['type' => 'room', 'id' => $room->id, 'label' => 'Kamar '.$room->code],
            'listUrl' => '/owner/rooms/'.$room->id.'/prices',
            'createUrl' => '/owner/rooms/'.$room->id.'/prices/create',
            'price' => $priceService->resolveForRoom($room, $date),
            'prices' => $room->prices()->where('tenant_id', $tenantId)->where('property_id', $room->property_id)->orderByDesc('effective_from')->get()->map(fn (RoomPrice $price) => $this->presentPrice($price, 'room')),
            'effectiveOn' => $date,
        ]);
    }

    public function createForRoom(string $room, TenantContext $tenantContext, RoomPriceService $priceService): Response
    {
        $room = $this->findRoom($room, $tenantContext);
        $this->authorize('create', [RoomPrice::class, $room]);
        abort_if($room->property()->value('status') !== 'active', 404);

        return Inertia::render('Owner/Prices/Create', [
            'target' => ['type' => 'room', 'id' => $room->id, 'label' => 'Kamar '.$room->code],
            'storeUrl' => '/owner/rooms/'.$room->id.'/prices',
            'suggestedPrice' => $priceService->resolveForRoom($room, now('Asia/Jakarta')->toDateString()),
            'today' => now('Asia/Jakarta')->toDateString(),
        ]);
    }

    public function storeForRoom(Request $request, string $room, TenantContext $tenantContext, RoomPriceService $priceService, AuditLogger $auditLogger): RedirectResponse
    {
        $target = $this->findRoom($room, $tenantContext);

        return $this->storeForTarget($request, $target, $tenantContext, $priceService, $auditLogger);
    }

    public function indexForRoomType(string $roomType, TenantContext $tenantContext): Response
    {
        $roomType = $this->findRoomType($roomType, $tenantContext);
        $this->authorize('view', $roomType);
        $tenantId = $tenantContext->current()->id;
        $effectiveOn = now('Asia/Jakarta')->toDateString();
        $current = $roomType->prices()->where('tenant_id', $tenantId)->where('property_id', $roomType->property_id)->effectiveOn($effectiveOn)->orderByDesc('effective_from')->first();

        return Inertia::render('Owner/Prices/Index', [
            'target' => ['type' => 'room_type', 'id' => $roomType->id, 'label' => 'Tipe kamar '.$roomType->name],
            'listUrl' => '/owner/room-types/'.$roomType->id.'/prices',
            'createUrl' => '/owner/room-types/'.$roomType->id.'/prices/create',
            'price' => $current ? $this->presentPrice($current, 'room_type') : null,
            'prices' => $roomType->prices()->where('tenant_id', $tenantId)->where('property_id', $roomType->property_id)->orderByDesc('effective_from')->get()->map(fn (RoomPrice $price) => $this->presentPrice($price, 'room_type')),
            'effectiveOn' => $effectiveOn,
        ]);
    }

    public function createForRoomType(string $roomType, TenantContext $tenantContext): Response
    {
        $roomType = $this->findRoomType($roomType, $tenantContext);
        $this->authorize('create', [RoomPrice::class, $roomType]);
        abort_if($roomType->property()->value('status') !== 'active', 404);

        return Inertia::render('Owner/Prices/Create', [
            'target' => ['type' => 'room_type', 'id' => $roomType->id, 'label' => 'Tipe kamar '.$roomType->name],
            'storeUrl' => '/owner/room-types/'.$roomType->id.'/prices',
            'suggestedPrice' => null,
            'today' => now('Asia/Jakarta')->toDateString(),
        ]);
    }

    public function storeForRoomType(Request $request, string $roomType, TenantContext $tenantContext, RoomPriceService $priceService, AuditLogger $auditLogger): RedirectResponse
    {
        $target = $this->findRoomType($roomType, $tenantContext);

        return $this->storeForTarget($request, $target, $tenantContext, $priceService, $auditLogger);
    }

    public function edit(string $price, TenantContext $tenantContext): Response
    {
        $price = $this->findPrice($price, $tenantContext);
        $this->authorize('update', $price);

        return Inertia::render('Owner/Prices/Edit', [
            'price' => [
                ...$this->presentPrice($price, $price->room_id !== null ? 'room' : 'room_type'),
                'editUrl' => '/owner/prices/'.$price->id,
                'endUrl' => '/owner/prices/'.$price->id.'/end',
                'today' => CarbonImmutable::today('Asia/Jakarta')->toDateString(),
                'targetUrl' => $price->room_id !== null
                    ? '/owner/rooms/'.$price->room_id.'/prices'
                    : '/owner/room-types/'.$price->room_type_id.'/prices',
            ],
        ]);
    }

    public function update(Request $request, string $price, TenantContext $tenantContext, RoomPriceService $priceService, AuditLogger $auditLogger): RedirectResponse
    {
        $validated = $this->validatedPrice($request);
        $tenant = $tenantContext->current();
        $record = $this->findPrice($price, $tenantContext);

        $updated = DB::transaction(function () use ($record, $validated, $tenant, $priceService, $request, $auditLogger): RoomPrice {
            $property = $tenant->properties()->whereKey($record->property_id)->lockForUpdate()->firstOrFail();
            $target = $this->lockTarget($record, $tenant->id);
            abort_if($property->status !== 'active', 404);
            $record = RoomPrice::query()
                ->where('tenant_id', $tenant->id)
                ->whereKey($record->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->authorize('update', $record);

            if ($record->effective_from->lessThanOrEqualTo(CarbonImmutable::today('Asia/Jakarta'))) {
                throw ValidationException::withMessages(['price' => 'Harga yang sudah berlaku tidak dapat ditulis ulang. Akhiri periodenya lalu buat harga baru.']);
            }

            $this->assertTargetBelongsToProperty($target, $property->id);
            $endExclusive = $this->endExclusive($validated['effective_until_date'] ?? null);
            $this->validateStartDate($validated['effective_from'], allowToday: false);
            $this->assertDateRange($validated['effective_from'], $endExclusive);
            $priceService->assertNoOverlap(
                $tenant->id,
                $property->id,
                $record->room_id !== null ? 'room_id' : 'room_type_id',
                $record->room_id ?? $record->room_type_id,
                $validated['effective_from'],
                $endExclusive,
                $record->id,
            );

            $oldValues = $record->only(['monthly_rent_rupiah', 'effective_from', 'effective_until_exclusive']);
            $record->update([
                'monthly_rent_rupiah' => (int) $validated['monthly_rent_rupiah'],
                'effective_from' => $validated['effective_from'],
                'effective_until_exclusive' => $endExclusive,
            ]);
            $auditLogger->record(
                actor: $request->user(), tenant: $tenant,
                action: 'room_price.updated', subjectType: 'room_price', subjectId: $record->id,
                oldValues: $oldValues, newValues: $record->only(['monthly_rent_rupiah', 'effective_from', 'effective_until_exclusive']),
            );

            return $record;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tarif berhasil diperbarui.']);

        return to_route('owner.prices.edit', $updated);
    }

    public function end(Request $request, string $price, TenantContext $tenantContext, RoomPriceService $priceService, AuditLogger $auditLogger): RedirectResponse
    {
        $tenant = $tenantContext->current();
        $record = $this->findPrice($price, $tenantContext);
        $request->validate([
            'effective_until_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$record->effective_from->toDateString()],
        ]);
        $endExclusive = $this->endExclusive($request->string('effective_until_date')->toString());

        DB::transaction(function () use ($record, $tenant, $endExclusive, $request, $priceService, $auditLogger): void {
            $property = $tenant->properties()->whereKey($record->property_id)->lockForUpdate()->firstOrFail();
            $target = $this->lockTarget($record, $tenant->id);
            $record = RoomPrice::query()
                ->where('tenant_id', $tenant->id)
                ->whereKey($record->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->authorize('end', $record);
            $this->assertTargetBelongsToProperty($target, $property->id);

            $todayExclusive = CarbonImmutable::today('Asia/Jakarta')->addDay()->toDateString();
            if ($endExclusive < $todayExclusive) {
                throw ValidationException::withMessages(['effective_until_date' => 'Tarif hanya dapat diakhiri pada hari ini atau tanggal mendatang.']);
            }

            if ($record->effective_until_exclusive !== null && $endExclusive >= $record->effective_until_exclusive->toDateString()) {
                throw ValidationException::withMessages(['effective_until_date' => 'Tanggal akhir baru harus lebih awal dari tanggal akhir yang sudah tercatat.']);
            }

            $this->assertDateRange($record->effective_from->toDateString(), $endExclusive);
            $priceService->assertNoOverlap(
                $tenant->id,
                $property->id,
                $record->room_id !== null ? 'room_id' : 'room_type_id',
                $record->room_id ?? $record->room_type_id,
                $record->effective_from->toDateString(),
                $endExclusive,
                $record->id,
            );

            $oldValues = $record->only(['monthly_rent_rupiah', 'effective_from', 'effective_until_exclusive']);
            $record->update(['effective_until_exclusive' => $endExclusive]);
            $auditLogger->record(
                actor: $request->user(), tenant: $tenant,
                action: 'room_price.ended', subjectType: 'room_price', subjectId: $record->id,
                oldValues: $oldValues, newValues: $record->only(['monthly_rent_rupiah', 'effective_from', 'effective_until_exclusive']),
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Periode tarif berhasil diakhiri.']);

        return back();
    }

    private function storeForTarget(Request $request, Room|RoomType $target, TenantContext $tenantContext, RoomPriceService $priceService, AuditLogger $auditLogger): RedirectResponse
    {
        $validated = $this->validatedPrice($request);
        $this->authorize('create', [RoomPrice::class, $target]);
        $tenant = $tenantContext->current();
        $isRoom = $target instanceof Room;

        $price = DB::transaction(function () use ($target, $isRoom, $tenant, $validated, $priceService, $request, $auditLogger): RoomPrice {
            $property = $tenant->properties()->whereKey($target->property_id)->lockForUpdate()->firstOrFail();
            abort_if($property->status !== 'active', 404);
            $lockedTarget = $isRoom
                ? Room::query()->where('tenant_id', $tenant->id)->where('property_id', $property->id)->whereKey($target->id)->lockForUpdate()->firstOrFail()
                : RoomType::query()->where('tenant_id', $tenant->id)->where('property_id', $property->id)->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $this->authorize('create', [RoomPrice::class, $lockedTarget]);
            $this->assertTargetBelongsToProperty($lockedTarget, $property->id);

            $endExclusive = $this->endExclusive($validated['effective_until_date'] ?? null);
            $this->validateStartDate($validated['effective_from']);
            $this->assertDateRange($validated['effective_from'], $endExclusive);
            $targetColumn = $isRoom ? 'room_id' : 'room_type_id';
            $priceService->assertNoOverlap($tenant->id, $property->id, $targetColumn, $lockedTarget->id, $validated['effective_from'], $endExclusive);

            $price = $lockedTarget->prices()->create([
                'tenant_id' => $tenant->id,
                'property_id' => $property->id,
                'created_by' => $request->user()->id,
                'monthly_rent_rupiah' => (int) $validated['monthly_rent_rupiah'],
                'effective_from' => $validated['effective_from'],
                'effective_until_exclusive' => $endExclusive,
            ]);
            $auditLogger->record(
                actor: $request->user(), tenant: $tenant,
                action: 'room_price.created', subjectType: 'room_price', subjectId: $price->id,
                newValues: [
                    'property_id' => $price->property_id,
                    $targetColumn => $lockedTarget->id,
                    'monthly_rent_rupiah' => $price->monthly_rent_rupiah,
                    'effective_from' => $price->effective_from->toDateString(),
                    'effective_until_exclusive' => $price->effective_until_exclusive?->toDateString(),
                ],
            );

            return $price;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tarif berhasil ditambahkan.']);
        $url = $isRoom
            ? '/owner/rooms/'.$target->id.'/prices'
            : '/owner/room-types/'.$target->id.'/prices';

        return redirect($url);
    }

    /** @return array<string, mixed> */
    private function validatedPrice(Request $request): array
    {
        return $request->validate([
            'monthly_rent_rupiah' => ['required', 'integer', 'min:1'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_until_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ]);
    }

    private function validateStartDate(string $start, bool $allowToday = true): void
    {
        $today = CarbonImmutable::today('Asia/Jakarta')->toDateString();
        if ($allowToday ? $start < $today : $start <= $today) {
            throw ValidationException::withMessages(['effective_from' => 'Harga baru tidak dapat berlaku surut.']);
        }
    }

    private function assertDateRange(string $start, ?string $endExclusive): void
    {
        if ($endExclusive !== null && $endExclusive <= $start) {
            throw ValidationException::withMessages(['effective_until_date' => 'Tanggal akhir harus setelah tanggal mulai.']);
        }
    }

    private function endExclusive(?string $inclusiveEnd): ?string
    {
        return $inclusiveEnd === null
            ? null
            : CarbonImmutable::createFromFormat('!Y-m-d', $inclusiveEnd, 'Asia/Jakarta')->addDay()->toDateString();
    }

    private function findRoom(string $id, TenantContext $tenantContext): Room
    {
        return Room::query()->where('tenant_id', $tenantContext->current()->id)->whereKey($id)->firstOrFail();
    }

    private function findRoomType(string $id, TenantContext $tenantContext): RoomType
    {
        return RoomType::query()->where('tenant_id', $tenantContext->current()->id)->whereKey($id)->firstOrFail();
    }

    private function findPrice(string $id, TenantContext $tenantContext): RoomPrice
    {
        return RoomPrice::query()->where('tenant_id', $tenantContext->current()->id)->whereKey($id)->firstOrFail();
    }

    private function lockTarget(RoomPrice $price, int $tenantId): Room|RoomType
    {
        if ($price->room_id !== null) {
            return Room::query()->where('tenant_id', $tenantId)->where('property_id', $price->property_id)->whereKey($price->room_id)->lockForUpdate()->firstOrFail();
        }

        return RoomType::query()->where('tenant_id', $tenantId)->where('property_id', $price->property_id)->whereKey($price->room_type_id)->lockForUpdate()->firstOrFail();
    }

    private function assertTargetBelongsToProperty(Room|RoomType $target, int $propertyId): void
    {
        if ($target->property_id !== $propertyId) {
            abort(404);
        }
    }

    /** @return array{id:int,monthly_rent_rupiah:int,effective_from:string,effective_until_exclusive:?string,effective_until_date:?string,can_edit:bool,can_end:bool,source:string} */
    private function presentPrice(RoomPrice $price, string $source): array
    {
        $today = CarbonImmutable::today('Asia/Jakarta');

        return [
            'id' => $price->id,
            'monthly_rent_rupiah' => (int) $price->monthly_rent_rupiah,
            'effective_from' => $price->effective_from->toDateString(),
            'effective_until_exclusive' => $price->effective_until_exclusive?->toDateString(),
            'effective_until_date' => $price->effective_until_exclusive?->subDay()->toDateString(),
            'can_edit' => $price->effective_from->greaterThan($today),
            'can_end' => $price->effective_until_exclusive === null,
            'source' => $source,
        ];
    }
}
