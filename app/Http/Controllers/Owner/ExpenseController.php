<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\MaintenanceJob;
use App\Models\Property;
use App\Support\AuditLogger;
use App\Support\ExpensePaymentService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): Response
    {
        $this->authorize('viewAny', Expense::class);
        $tenant = $tenantContext->current();
        $filters = $request->validate([
            'kind' => ['nullable', Rule::in(['maintenance', 'operational'])],
            'property_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['unpaid', 'partial', 'paid'])],
        ]);
        $expenses = Expense::query()->where('tenant_id', $tenant->id)
            ->when(isset($filters['kind']), fn ($query) => $query->where('kind', $filters['kind']))
            ->when(isset($filters['property_id']), fn ($query) => $query->where('property_id', $filters['property_id']))
            ->with(['property:id,name', 'room:id,code', 'maintenanceJob:id,category', 'payments'])
            ->orderByDesc('incurred_on')->orderByDesc('id')->get();
        $rows = $expenses->map(function (Expense $expense): array {
            $paid = $expense->paidAmount();
            $outstanding = $expense->outstandingAmount();

            return [
                'id' => $expense->id, 'kind' => $expense->kind, 'category' => $expense->category,
                'description' => $expense->description, 'amount_due_rupiah' => (int) $expense->amount_due_rupiah,
                'paid_rupiah' => $paid, 'outstanding_rupiah' => $outstanding,
                'status' => $outstanding === 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
                'incurred_on' => $expense->incurred_on->toDateString(),
                'property' => $expense->property?->only(['id', 'name']), 'room' => $expense->room?->only(['id', 'code']),
                'maintenance_category' => $expense->maintenanceJob?->category,
            ];
        })->filter(fn (array $expense) => ! isset($filters['status']) || $expense['status'] === $filters['status'])->values();
        $page = LengthAwarePaginator::resolveCurrentPage();
        $expenses = new LengthAwarePaginator(
            $rows->forPage($page, 20)->values(),
            $rows->count(),
            20,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'query' => $request->query()],
        );
        $properties = $tenant->properties()->where('status', 'active')->with(['rooms' => fn ($query) => $query->whereNull('archived_at')->orderBy('code')])->orderBy('name')->get(['id', 'name'])->map(fn ($property) => [
            'id' => $property->id, 'name' => $property->name,
            'rooms' => $property->rooms->map(fn ($room) => ['id' => $room->id, 'code' => $room->code])->values(),
        ]);

        return Inertia::render('Owner/Expenses/Index', [
            'expenses' => $expenses,
            'properties' => $properties,
            'filters' => ['kind' => $filters['kind'] ?? '', 'property_id' => $filters['property_id'] ?? '', 'status' => $filters['status'] ?? ''],
        ]);
    }

    public function store(Request $request, TenantContext $tenantContext, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'property_id' => ['required', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'maintenance_job_id' => ['nullable', 'integer'],
            'kind' => ['required', Rule::in(['maintenance', 'operational'])],
            'category' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:2000'],
            'amount_due_rupiah' => ['required', 'integer', 'min:1'],
            'incurred_on' => ['required', 'date_format:Y-m-d'],
        ]);
        $tenant = $tenantContext->current();
        $this->authorize('create', Expense::class);
        $expense = DB::transaction(function () use ($request, $tenant, $data, $audit): Expense {
            $property = Property::query()->where('tenant_id', $tenant->id)->whereKey($data['property_id'])->where('status', 'active')->lockForUpdate()->firstOrFail();
            $room = isset($data['room_id']) ? $property->rooms()->whereKey($data['room_id'])->whereNull('archived_at')->firstOrFail() : null;
            $job = null;
            if (isset($data['maintenance_job_id'])) {
                $job = MaintenanceJob::query()->where('tenant_id', $tenant->id)->where('property_id', $property->id)->whereKey($data['maintenance_job_id'])->firstOrFail();
                if ($data['kind'] !== 'maintenance' || ($job->room_id !== null && $room?->id !== $job->room_id)) {
                    abort(404);
                }
            }
            $expense = Expense::query()->create([
                'tenant_id' => $tenant->id, 'property_id' => $property->id, 'room_id' => $room?->id,
                'maintenance_job_id' => $job?->id, 'kind' => $data['kind'], 'category' => trim($data['category']),
                'description' => trim($data['description']), 'amount_due_rupiah' => $data['amount_due_rupiah'],
                'incurred_on' => $data['incurred_on'], 'created_by' => $request->user()->id,
            ]);
            $audit->record($request->user(), $tenant, 'expense.created', 'expense', $expense->id, null, [
                'property_id' => $property->id, 'room_id' => $room?->id, 'kind' => $expense->kind,
                'amount_due_rupiah' => $expense->amount_due_rupiah, 'incurred_on' => $expense->incurred_on->toDateString(),
            ]);

            return $expense;
        });

        return to_route('owner.expenses.show', $expense);
    }

    public function show(string $expense, TenantContext $tenantContext): Response
    {
        $record = Expense::query()->where('tenant_id', $tenantContext->current()->id)
            ->with(['property:id,name', 'room:id,code', 'maintenanceJob:id,category,description', 'payments.cashTransaction', 'creator:id,name'])
            ->whereKey((int) $expense)->firstOrFail();
        $this->authorize('view', $record);
        $paid = $record->paidAmount();

        return Inertia::render('Owner/Expenses/Show', ['expense' => [
            'id' => $record->id, 'kind' => $record->kind, 'category' => $record->category, 'description' => $record->description,
            'amount_due_rupiah' => (int) $record->amount_due_rupiah, 'paid_rupiah' => $paid,
            'outstanding_rupiah' => $record->outstandingAmount(), 'incurred_on' => $record->incurred_on->toDateString(),
            'property' => $record->property?->only(['id', 'name']), 'room' => $record->room?->only(['id', 'code']),
            'maintenance_job' => $record->maintenanceJob?->only(['id', 'category', 'description']), 'created_by' => $record->creator?->name,
            'payments' => $record->payments->map(fn ($payment) => [
                'id' => $payment->id, 'kind' => $payment->kind, 'amount_rupiah' => (int) $payment->amount_rupiah,
                'occurred_on' => $payment->cashTransaction->occurred_on->toDateString(), 'method' => $payment->cashTransaction->method,
                'external_reference' => $payment->cashTransaction->external_reference, 'cash_transaction_id' => $payment->cash_transaction_id,
            ])->values(),
        ], 'idempotency_key' => (string) Str::uuid()]);
    }

    public function update(string $expense, Request $request, TenantContext $tenantContext, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['maintenance', 'operational'])],
            'category' => ['required', 'string', 'max:100'], 'description' => ['required', 'string', 'max:2000'],
            'amount_due_rupiah' => ['required', 'integer', 'min:1'], 'incurred_on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        $tenant = $tenantContext->current();
        $record = Expense::query()->where('tenant_id', $tenant->id)->whereKey((int) $expense)->firstOrFail();
        $this->authorize('update', $record);
        DB::transaction(function () use ($tenant, $record, $data, $request, $audit): void {
            $locked = Expense::query()->where('tenant_id', $tenant->id)->whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($locked->paidAmount() > 0) {
                throw ValidationException::withMessages(['expense' => 'Biaya yang sudah dibayar tidak dapat ditimpa.']);
            }
            $before = $locked->only(['kind', 'category', 'description', 'amount_due_rupiah', 'incurred_on']);
            $locked->update([
                'kind' => $data['kind'], 'category' => trim($data['category']), 'description' => trim($data['description']),
                'amount_due_rupiah' => $data['amount_due_rupiah'], 'incurred_on' => $data['incurred_on'],
            ]);
            $audit->record($request->user(), $tenant, 'expense.updated', 'expense', $locked->id, $before, [...$locked->only(array_keys($before)), 'reason' => trim($data['reason'])]);
        });

        return to_route('owner.expenses.show', $record);
    }

    public function recordPayment(string $expense, Request $request, TenantContext $tenantContext, ExpensePaymentService $payments): RedirectResponse
    {
        $data = $request->validate([
            'amount_rupiah' => ['required', 'integer', 'min:1'], 'occurred_on' => ['required', 'date_format:Y-m-d'],
            'method' => ['required', Rule::in(['cash', 'bank_transfer', 'e_wallet', 'other'])],
            'external_reference' => ['nullable', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $record = Expense::query()->where('tenant_id', $tenantContext->current()->id)->whereKey((int) $expense)->firstOrFail();
        $this->authorize('view', $record);
        $payments->recordPayment($tenantContext->current(), $request->user(), $record->id, $data);

        return to_route('owner.expenses.show', $record);
    }
}
