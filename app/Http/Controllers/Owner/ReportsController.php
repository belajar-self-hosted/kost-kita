<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Support\Reports\ReportExportService;
use App\Support\Reports\ReportFilters;
use App\Support\Reports\ReportPage;
use App\Support\Reports\ReportQueryService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

class ReportsController extends Controller
{
    /** @var list<string> */
    private const REPORTS = ['cash-flow', 'invoices', 'tenancies', 'bookings', 'maintenance'];

    public function index(): InertiaResponse
    {
        return Inertia::render('Owner/Reports/Index', [
            'reports' => [
                ['key' => 'cash-flow', 'title' => 'Arus kas', 'description' => 'Penerimaan, pengeluaran, dan Arus Kas Bersih berdasarkan kas aktual.'],
                ['key' => 'invoices', 'title' => 'Saldo tagihan', 'description' => 'Kewajiban, pembayaran, dan outstanding per akhir periode.'],
                ['key' => 'tenancies', 'title' => 'Masa sewa', 'description' => 'Riwayat penyewa dan periode huni.'],
                ['key' => 'bookings', 'title' => 'Booking', 'description' => 'Booking, status, harga, dan deposit yang diterima.'],
                ['key' => 'maintenance', 'title' => 'Maintenance', 'description' => 'Pekerjaan servis dan biaya kas yang dibayar.'],
            ],
        ]);
    }

    public function show(
        Request $request,
        string $report,
        TenantContext $tenantContext,
        ReportQueryService $queries,
    ): InertiaResponse {
        abort_unless(in_array($report, self::REPORTS, true), 404);
        $tenant = $tenantContext->current();
        $filters = ReportFilters::fromRequest($request, $tenant, $queries->types($report, $tenant), $queries->statuses($report));
        $pageInput = $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $perPage = 50;
        $requestedPage = (int) ($pageInput['page'] ?? 1);
        $dataset = $queries->run($report, $tenant, $filters, new ReportPage(($requestedPage - 1) * $perPage, $perPage));
        $totalRows = $dataset->totalRows ?? count($dataset->rows);
        $lastPage = max(1, (int) ceil($totalRows / $perPage));
        $currentPage = min($requestedPage, $lastPage);
        if ($currentPage !== $requestedPage) {
            $dataset = $queries->run($report, $tenant, $filters, new ReportPage(($currentPage - 1) * $perPage, $perPage));
        }
        $datasetArray = $dataset->toArray();

        return Inertia::render('Owner/Reports/Show', [
            'dataset' => $datasetArray,
            'filters' => $filters->toArray(),
            'filterOptions' => [
                'properties' => $tenant->properties()->orderBy('name')->get(['id', 'name', 'status']),
                'rooms' => Room::query()->where('tenant_id', $tenant->id)
                    ->when($filters->propertyId !== null, fn ($rooms) => $rooms->where('property_id', $filters->propertyId))
                    ->with('property:id,name')->orderBy('code')->get(['id', 'property_id', 'code'])
                    ->map(fn (Room $room): array => ['id' => $room->id, 'property_id' => $room->property_id, 'code' => $room->code, 'property_name' => $room->property?->name]),
                'types' => $queries->types($report, $tenant),
                'statuses' => $queries->statuses($report),
            ],
            'showTypeFilter' => $queries->types($report, $tenant) !== [],
            'showStatusFilter' => $queries->statuses($report) !== [],
            'pagination' => ['current_page' => $currentPage, 'last_page' => $lastPage, 'per_page' => $perPage, 'total' => $totalRows],
        ]);
    }

    public function export(
        Request $request,
        string $report,
        string $format,
        TenantContext $tenantContext,
        ReportQueryService $queries,
        ReportExportService $exports,
    ): Response {
        abort_unless(in_array($report, self::REPORTS, true), 404);
        abort_unless(in_array($format, ['xlsx', 'pdf'], true), 404);
        $tenant = $tenantContext->current();
        $filters = ReportFilters::fromRequest($request, $tenant, $queries->types($report, $tenant), $queries->statuses($report));
        $dataset = $queries->run($report, $tenant, $filters, new ReportPage(0, ReportExportService::MAX_EXPORT_ROWS + 1, stopAfterLimit: true));
        if (($dataset->totalRows ?? count($dataset->rows)) > ReportExportService::MAX_EXPORT_ROWS) {
            return response('Hasil laporan melebihi batas 5.000 baris per berkas. Persempit rentang tanggal atau filter sebelum mengunduh.', 422);
        }

        $filterLabels = [];
        if ($filters->propertyId !== null) {
            $filterLabels['property_id'] = (string) ($tenant->properties()->whereKey($filters->propertyId)->value('name') ?? '#'.$filters->propertyId);
        }
        if ($filters->roomId !== null) {
            $room = Room::query()->where('tenant_id', $tenant->id)->whereKey($filters->roomId)
                ->with('property:id,name')->first(['id', 'property_id', 'code']);
            $filterLabels['room_id'] = $room === null
                ? '#'.$filters->roomId
                : ($room->property?->name === null ? $room->code : $room->property->name.' · '.$room->code);
        }

        return $exports->download($dataset, $tenant->display_name, $format, $filterLabels);
    }
}
