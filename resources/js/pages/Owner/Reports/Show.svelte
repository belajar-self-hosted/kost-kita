<script module lang="ts">
    export const layout = { breadcrumbs: [{ title: 'Laporan', href: '/owner/reports' }] };
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';

    type Column = { key: string; label: string; type?: 'money' | 'number' | 'date' };
    type Dataset = {
        key: string;
        title: string;
        columns: Column[];
        rows: Record<string, string | number | null>[];
        summary: Record<string, string | number>;
        summary_labels: Record<string, string>;
        period: { from: string; to: string };
        generated_at: string;
    };
    type PropertyOption = { id: number; name: string; status: string };
    type RoomOption = { id: number; property_id: number; code: string; property_name: string | null };
    type PageInfo = { current_page: number; last_page: number; per_page: number; total: number };

    let {
        dataset,
        filters,
        filterOptions,
        showTypeFilter,
        showStatusFilter,
        pagination,
    }: {
        dataset: Dataset;
        filters: Record<string, string | number | null>;
        filterOptions: { properties: PropertyOption[]; rooms: RoomOption[]; types: string[]; statuses: string[] };
        showTypeFilter: boolean;
        showStatusFilter: boolean;
        pagination: PageInfo;
    } = $props();

    const reportPath = $derived(`/owner/reports/${dataset.key}`);
    const exportQuery = $derived(new URLSearchParams(
        Object.entries(filters).flatMap(([key, value]) => value === null || value === '' ? [] : [[key, String(value)]]),
    ).toString());
    const statusLabels: Record<string, string> = {
        unpaid: 'Belum dibayar', partial: 'Dibayar sebagian', paid: 'Lunas', overdue: 'Terlambat',
        pending: 'Menunggu', confirmed: 'Dikonfirmasi', checked_in: 'Sudah check-in', cancelled: 'Dibatalkan', no_show: 'Tidak datang',
        scheduled: 'Terjadwal', active: 'Aktif', completed: 'Selesai', terminated: 'Dihentikan', reported: 'Dilaporkan',
    };
    const typeLabels: Record<string, string> = {
        rent_receipt: 'Penerimaan sewa', deposit_receipt: 'Penerimaan deposit', temporary_liability: 'Liability sementara',
        rent_refund: 'Refund sewa', deposit_refund: 'Refund deposit', temporary_liability_refund: 'Refund liability sementara',
        maintenance_expense: 'Biaya maintenance', operating_expense: 'Biaya operasional',
    };
    const formatValue = (value: string | number | null, type?: Column['type']) => {
        if (value === null || value === '') return '—';
        if (type === 'money') return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(value));
        if (type === 'number') return new Intl.NumberFormat('id-ID').format(Number(value));
        if (typeof value === 'string' && statusLabels[value]) return statusLabels[value];
        if (typeof value === 'string' && typeLabels[value]) return typeLabels[value];
        return String(value);
    };
    const pageHref = (pageNumber: number) => `${reportPath}?${new URLSearchParams({ ...Object.fromEntries(Object.entries(filters).map(([key, value]) => [key, String(value ?? '')])), page: String(pageNumber) })}`;
</script>

<AppHead title={dataset.title} />

<main class="space-y-6 p-4 md:p-8">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <Link href="/owner/reports" class="text-sm text-primary underline-offset-4 hover:underline">Semua laporan</Link>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight">{dataset.title}</h1>
            <p class="mt-1 text-sm text-muted-foreground">Periode {dataset.period.from} sampai {dataset.period.to} · Dibuat {dataset.generated_at} WIB</p>
        </div>
        <div class="flex gap-2">
            <a href={`${reportPath}/exports/xlsx?${exportQuery}`} class="inline-flex items-center rounded-md border px-4 py-2 text-sm font-medium hover:bg-muted">Unduh XLSX</a>
            <a href={`${reportPath}/exports/pdf?${exportQuery}`} class="inline-flex items-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground">Unduh PDF</a>
        </div>
    </header>

    <Form action={reportPath} method="get" class="grid gap-3 rounded-xl border bg-card p-4 sm:grid-cols-2 lg:grid-cols-6 lg:items-end">
        {#snippet children({ errors, processing })}
            <div class="space-y-1">
                <label for="report_from" class="text-sm font-medium">Dari tanggal</label>
                <input id="report_from" name="from" type="date" value={filters.from ?? ''} required class="h-10 w-full rounded-md border bg-background px-3 text-sm" />
                <InputError message={errors.from} />
            </div>
            <div class="space-y-1">
                <label for="report_to" class="text-sm font-medium">Sampai tanggal</label>
                <input id="report_to" name="to" type="date" value={filters.to ?? ''} required class="h-10 w-full rounded-md border bg-background px-3 text-sm" />
                <InputError message={errors.to} />
            </div>
            <div class="space-y-1">
                <label for="report_property" class="text-sm font-medium">Properti</label>
                <select id="report_property" name="property_id" value={filters.property_id ?? ''} class="h-10 w-full rounded-md border bg-background px-3 text-sm">
                    <option value="">Semua properti</option>
                    {#each filterOptions.properties as property (property.id)}<option value={property.id}>{property.name}{property.status === 'archived' ? ' · Diarsipkan' : ''}</option>{/each}
                </select>
                <InputError message={errors.property_id} />
            </div>
            <div class="space-y-1">
                <label for="report_room" class="text-sm font-medium">Kamar</label>
                <select id="report_room" name="room_id" value={filters.room_id ?? ''} class="h-10 w-full rounded-md border bg-background px-3 text-sm">
                    <option value="">Semua kamar</option>
                    {#each filterOptions.rooms as room (room.id)}<option value={room.id}>{room.property_name ? `${room.property_name} · ` : ''}{room.code}</option>{/each}
                </select>
                <InputError message={errors.room_id} />
            </div>
            {#if showTypeFilter}
                <div class="space-y-1">
                    <label for="report_type" class="text-sm font-medium">Jenis</label>
                    <select id="report_type" name="type" value={filters.type ?? ''} class="h-10 w-full rounded-md border bg-background px-3 text-sm">
                        <option value="">Semua jenis</option>
                        {#each filterOptions.types as type (type)}<option value={type}>{typeLabels[type] ?? type}</option>{/each}
                    </select>
                    <InputError message={errors.type} />
                </div>
            {/if}
            {#if showStatusFilter}
                <div class="space-y-1">
                    <label for="report_status" class="text-sm font-medium">Status</label>
                    <select id="report_status" name="status" value={filters.status ?? ''} class="h-10 w-full rounded-md border bg-background px-3 text-sm">
                        <option value="">Semua status</option>
                        {#each filterOptions.statuses as status (status)}<option value={status}>{statusLabels[status] ?? status}</option>{/each}
                    </select>
                    <InputError message={errors.status} />
                </div>
            {/if}
            <div class="lg:col-span-6 flex justify-end"><Button type="submit" disabled={processing}>{processing ? 'Memuat…' : 'Terapkan filter'}</Button></div>
        {/snippet}
    </Form>

    <section aria-label="Ringkasan laporan" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {#each Object.entries(dataset.summary) as [key, value] (key)}
            <article class="rounded-xl border bg-card p-4 shadow-sm">
                <p class="text-sm text-muted-foreground">{dataset.summary_labels[key] ?? key}</p>
                <p class="mt-2 text-xl font-semibold">{formatValue(value, key.includes('rupiah') ? 'money' : 'number')}</p>
            </article>
        {/each}
    </section>

    <section class="overflow-hidden rounded-xl border bg-card shadow-sm">
        {#if dataset.rows.length === 0}
            <p class="p-8 text-center text-sm text-muted-foreground">Tidak ada data pada filter ini.</p>
        {:else}
            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-sm">
                    <thead class="bg-muted/60 text-left"><tr>{#each dataset.columns as column (column.key)}<th class="whitespace-nowrap px-4 py-3 font-medium">{column.label}</th>{/each}</tr></thead>
                    <tbody class="divide-y">
                        {#each dataset.rows as row, index (index)}
                            <tr class="hover:bg-muted/30">{#each dataset.columns as column (column.key)}<td class="px-4 py-3 align-top {column.type === 'money' ? 'text-right tabular-nums' : ''}">{formatValue(row[column.key] ?? null, column.type)}</td>{/each}</tr>
                        {/each}
                    </tbody>
                </table>
            </div>
            <footer class="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-sm">
                <p class="text-muted-foreground">Menampilkan {pagination.total === 0 ? 0 : (pagination.current_page - 1) * pagination.per_page + 1}–{Math.min(pagination.current_page * pagination.per_page, pagination.total)} dari {pagination.total} baris</p>
                <div class="flex gap-2">
                    {#if pagination.current_page > 1}<a href={pageHref(pagination.current_page - 1)} class="rounded-md border px-3 py-1.5">Sebelumnya</a>{/if}
                    {#if pagination.current_page < pagination.last_page}<a href={pageHref(pagination.current_page + 1)} class="rounded-md border px-3 py-1.5">Berikutnya</a>{/if}
                </div>
            </footer>
        {/if}
    </section>
    {#if dataset.key === 'cash-flow'}<p class="text-xs text-muted-foreground">Arus Kas Bersih (Net Cash Flow) adalah laporan kas operasional, bukan laporan laba rugi akuntansi.</p>{/if}
</main>
