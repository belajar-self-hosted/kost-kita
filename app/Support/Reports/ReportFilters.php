<?php

namespace App\Support\Reports;

use App\Models\Room;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class ReportFilters
{
    /** @param array<string, mixed> $query */
    private function __construct(
        public ?int $propertyId,
        public ?int $roomId,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?string $type,
        public ?string $status,
        public array $query,
    ) {}

    /** @param list<string> $types
     * @param  list<string>  $statuses
     */
    public static function fromRequest(Request $request, Tenant $tenant, array $types = [], array $statuses = []): self
    {
        $validated = $request->validate([
            'property_id' => [
                'nullable', 'integer',
                Rule::exists('properties', 'id')->where(fn (QueryBuilder $query) => $query->where('tenant_id', $tenant->id)),
            ],
            'room_id' => [
                'nullable', 'integer',
                Rule::exists('rooms', 'id')->where(fn (QueryBuilder $query) => $query->where('tenant_id', $tenant->id)),
            ],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'type' => ['nullable', 'string', Rule::in($types)],
            'status' => ['nullable', 'string', Rule::in($statuses)],
        ]);

        $zone = 'Asia/Jakarta';
        $today = CarbonImmutable::now($zone)->startOfDay();
        $to = isset($validated['to'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['to'], $zone)->startOfDay()
            : $today;
        $from = isset($validated['from'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['from'], $zone)->startOfDay()
            : (isset($validated['to']) ? $to->startOfMonth() : $today->startOfMonth());

        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages(['to' => 'Tanggal akhir harus sama atau setelah tanggal awal.']);
        }

        $propertyId = isset($validated['property_id']) ? (int) $validated['property_id'] : null;
        $roomId = isset($validated['room_id']) ? (int) $validated['room_id'] : null;
        if ($propertyId !== null && $roomId !== null
            && ! Room::query()->where('tenant_id', $tenant->id)->whereKey($roomId)->where('property_id', $propertyId)->exists()) {
            throw ValidationException::withMessages(['room_id' => 'Kamar tidak termasuk properti yang dipilih.']);
        }

        return new self(
            $propertyId,
            $roomId,
            $from,
            $to,
            $validated['type'] ?? null,
            $validated['status'] ?? null,
            [
                ...($propertyId === null ? [] : ['property_id' => $propertyId]),
                ...($roomId === null ? [] : ['room_id' => $roomId]),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                ...(($validated['type'] ?? null) === null ? [] : ['type' => $validated['type']]),
                ...(($validated['status'] ?? null) === null ? [] : ['status' => $validated['status']]),
            ],
        );
    }

    public function startUtc(): CarbonImmutable
    {
        return $this->from->startOfDay()->setTimezone('UTC');
    }

    public function endUtc(): CarbonImmutable
    {
        return $this->to->endOfDay()->setTimezone('UTC');
    }

    /** @return array{from: string, to: string} */
    public function period(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString()];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->query;
    }
}
