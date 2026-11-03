<?php

namespace App\Support;

use App\Models\Room;
use App\Models\RoomPrice;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class RoomPriceService
{
    /** @return array{monthly_rent_rupiah:int,source:string,effective_from:string,effective_until_exclusive:?string}|null */
    public function resolveForRoom(Room $room, string|CarbonImmutable $effectiveOn): ?array
    {
        $date = $effectiveOn instanceof CarbonImmutable ? $effectiveOn->toDateString() : $effectiveOn;

        $roomPrice = $room->prices()
            ->where('tenant_id', $room->tenant_id)
            ->where('property_id', $room->property_id)
            ->effectiveOn($date)
            ->orderByDesc('effective_from')
            ->first();
        if ($roomPrice) {
            return $this->resolved($roomPrice, 'room');
        }

        $roomType = $room->roomType;
        if (! $roomType || $roomType->status !== 'active') {
            return null;
        }

        $typePrice = $roomType->prices()
            ->where('tenant_id', $room->tenant_id)
            ->where('property_id', $room->property_id)
            ->effectiveOn($date)
            ->orderByDesc('effective_from')
            ->first();

        return $typePrice ? $this->resolved($typePrice, 'room_type') : null;
    }

    public function assertNoOverlap(
        int $tenantId,
        int $propertyId,
        string $targetColumn,
        int $targetId,
        string $start,
        ?string $endExclusive,
        ?int $ignorePriceId = null,
    ): void {
        if (! in_array($targetColumn, ['room_id', 'room_type_id'], true)) {
            throw new \InvalidArgumentException('Unsupported room price target.');
        }

        $query = RoomPrice::query()
            ->where('tenant_id', $tenantId)
            ->where('property_id', $propertyId)
            ->where($targetColumn, $targetId)
            ->where(function ($query) use ($start): void {
                $query->whereNull('effective_until_exclusive')
                    ->orWhereDate('effective_until_exclusive', '>', $start);
            });

        if ($endExclusive !== null) {
            $query->whereDate('effective_from', '<', $endExclusive);
        }

        if ($ignorePriceId !== null) {
            $query->whereKeyNot($ignorePriceId);
        }

        if ($query->lockForUpdate()->exists()) {
            throw ValidationException::withMessages([
                'effective_from' => 'Periode harga bertumpang tindih dengan harga lain untuk target yang sama.',
            ]);
        }
    }

    /** @return array{monthly_rent_rupiah:int,source:string,effective_from:string,effective_until_exclusive:?string} */
    private function resolved(RoomPrice $price, string $source): array
    {
        return [
            'monthly_rent_rupiah' => (int) $price->monthly_rent_rupiah,
            'source' => $source,
            'effective_from' => $price->effective_from->toDateString(),
            'effective_until_exclusive' => $price->effective_until_exclusive?->toDateString(),
        ];
    }
}
