<script module lang="ts">
    export const layout = { breadcrumbs: [{ title: 'Masa sewa', href: '/owner/tenancies' }] };
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';

    type Tenancy = { id: number; status: string; planned_start_date: string; planned_end_exclusive: string; agreed_monthly_rent_rupiah: number; property: { id: number; name: string }; room: { id: number; code: string }; resident: { id: number; full_name: string; whatsapp_number: string } };
    type PaginationLink = { url: string | null; label: string; active: boolean };
    let { tenancies, filters }: { tenancies: { data: Tenancy[]; links: PaginationLink[]; from: number | null; to: number | null; total: number }; filters: { status: string; property_id: string; room_id: string } } = $props();

    const labels: Record<string, string> = { scheduled: 'Terjadwal', active: 'Aktif', completed: 'Selesai', terminated: 'Dihentikan' };
    const rupiah = (amount: number) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(amount);
</script>

<AppHead title="Masa sewa" />
<main class="space-y-6 p-4 md:p-8">
    <header class="flex flex-wrap items-end justify-between gap-4"><div class="space-y-2"><p class="text-sm font-medium text-muted-foreground">Manajemen</p><h1 class="text-3xl font-semibold tracking-tight">Masa sewa</h1><p class="text-sm text-muted-foreground">Riwayat masa sewa dipertahankan setelah check-out.</p></div><Link href="/owner/direct-check-ins/create" class="inline-flex rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground">Check-in langsung</Link></header>
    <Form action="/owner/tenancies" method="get" class="grid gap-3 rounded-xl border bg-card p-4 sm:grid-cols-2 lg:grid-cols-[1fr_12rem_12rem_auto] lg:items-end">
        {#snippet children({ processing })}<div class="space-y-1"><label for="status" class="text-sm font-medium">Status</label><select id="status" name="status" value={filters.status} class="h-10 w-full rounded-md border bg-background px-3 text-sm"><option value="">Semua status</option><option value="scheduled">Terjadwal</option><option value="active">Aktif</option><option value="completed">Selesai</option><option value="terminated">Dihentikan</option></select></div><div class="space-y-1"><label for="property_id" class="text-sm font-medium">ID properti</label><Input id="property_id" name="property_id" type="number" min="1" value={filters.property_id} /></div><div class="space-y-1"><label for="room_id" class="text-sm font-medium">ID kamar</label><Input id="room_id" name="room_id" type="number" min="1" value={filters.room_id} /></div><Button type="submit" disabled={processing}>Terapkan filter</Button>{/snippet}
    </Form>
    <section class="overflow-hidden rounded-xl border bg-card shadow-sm" aria-label="Daftar masa sewa">
        {#if tenancies.data.length === 0}<div class="p-10 text-center"><h2 class="font-medium">Belum ada masa sewa</h2><p class="mt-1 text-sm text-muted-foreground">Buat booking atau catat check-in langsung.</p><Link href="/owner/direct-check-ins/create" class="mt-4 inline-flex rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground">Check-in langsung</Link></div>{:else}
            <div class="overflow-x-auto"><table class="w-full min-w-[54rem] text-left text-sm"><thead class="border-b bg-muted/40 text-muted-foreground"><tr><th class="px-5 py-3 font-medium">Penyewa</th><th class="px-5 py-3 font-medium">Properti / kamar</th><th class="px-5 py-3 font-medium">Periode rencana</th><th class="px-5 py-3 font-medium">Tarif bulanan</th><th class="px-5 py-3 font-medium">Status</th><th class="px-5 py-3 text-right font-medium">Aksi</th></tr></thead><tbody class="divide-y">{#each tenancies.data as tenancy (tenancy.id)}<tr><td class="px-5 py-4"><Link href={`/owner/residents/${tenancy.resident.id}`} class="font-medium underline-offset-4 hover:underline">{tenancy.resident.full_name}</Link><p class="mt-1 text-xs text-muted-foreground">{tenancy.resident.whatsapp_number}</p></td><td class="px-5 py-4"><p>{tenancy.property.name}</p><p class="text-xs text-muted-foreground">Kamar {tenancy.room.code}</p></td><td class="px-5 py-4">{tenancy.planned_start_date}<span class="text-muted-foreground"> – </span>{tenancy.planned_end_exclusive}</td><td class="px-5 py-4">{rupiah(tenancy.agreed_monthly_rent_rupiah)}</td><td class="px-5 py-4"><span class="rounded-full border px-2.5 py-1 text-xs">{labels[tenancy.status] ?? tenancy.status}</span></td><td class="px-5 py-4 text-right"><Link href={`/owner/tenancies/${tenancy.id}`} class="text-primary underline-offset-4 hover:underline">Detail</Link></td></tr>{/each}</tbody></table></div>
            <footer class="flex flex-wrap items-center justify-between gap-4 border-t px-5 py-4 text-sm"><p class="text-muted-foreground">Menampilkan {tenancies.from ?? 0}–{tenancies.to ?? 0} dari {tenancies.total} masa sewa</p><nav aria-label="Navigasi halaman" class="flex flex-wrap gap-1">{#each tenancies.links as link, index (index)}{#if link.url}<Link href={link.url} class={`rounded border px-3 py-1.5 ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`}>{link.label}</Link>{:else}<span class="rounded border px-3 py-1.5 text-muted-foreground">{link.label}</span>{/if}{/each}</nav></footer>
        {/if}
    </section>
</main>
