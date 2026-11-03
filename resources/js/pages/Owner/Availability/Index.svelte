<script module lang="ts">
    export const layout = { breadcrumbs: [{ title: 'Ketersediaan', href: '/owner/availability' }] };
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';

    type Property = { id: number; name: string };
    type Conflict = { type: string; id: number; status: string; start_date: string; end_exclusive: string; description: string };
    type Room = { id: number; code: string; operational_status: string; price: { monthly_rent_rupiah: number; source: string } | null; conflicts: Conflict[] };
    let { properties, filters, rooms }: { properties: Property[]; filters: { property_id: string; room_id: string; start_date: string; end_exclusive: string }; rooms: Room[] } = $props();

    const rupiah = (amount: number) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(amount);
    const conflictLabel: Record<string, string> = { booking: 'Booking', tenancy: 'Masa sewa', maintenance: 'Maintenance', operational_status: 'Status operasional' };
    const operationalLabel: Record<string, string> = { rentable: 'Dapat disewakan', out_of_service: 'Tidak beroperasi', archived: 'Diarsipkan' };
</script>

<AppHead title="Ketersediaan kamar" />
<main class="space-y-6 p-4 md:p-8">
    <header class="space-y-2"><p class="text-sm font-medium text-muted-foreground">Jadwal kamar</p><h1 class="text-3xl font-semibold tracking-tight">Ketersediaan</h1><p class="max-w-3xl text-sm text-muted-foreground">Ketersediaan dihitung untuk rentang tanggal dari status kamar, booking confirmed, tenancy, dan maintenance aktif. Hasil ini informatif; penyimpanan tetap memeriksa konflik ulang.</p></header>
    <Form action="/owner/availability" method="get" class="grid gap-4 rounded-xl border bg-card p-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
        {#snippet children({ processing })}
            <div class="space-y-1"><label for="property_id" class="text-sm font-medium">Properti <span aria-hidden="true">*</span></label><select id="property_id" name="property_id" value={filters.property_id} required class="h-10 w-full rounded-md border bg-background px-3 text-sm"><option value="">Pilih properti</option>{#each properties as property (property.id)}<option value={property.id}>{property.name}</option>{/each}</select></div>
            <div class="space-y-1"><label for="room_id" class="text-sm font-medium">Kamar</label><select id="room_id" name="room_id" value={filters.room_id} class="h-10 w-full rounded-md border bg-background px-3 text-sm"><option value="">Semua kamar</option>{#each rooms as room (room.id)}<option value={room.id}>Kamar {room.code}</option>{/each}</select><p class="text-xs text-muted-foreground">Pilih properti dahulu untuk melihat pilihan kamar.</p></div>
            <div class="space-y-1"><label for="start_date" class="text-sm font-medium">Tanggal mulai</label><Input id="start_date" name="start_date" type="date" value={filters.start_date} required /></div>
            <div class="space-y-1"><label for="end_exclusive" class="text-sm font-medium">Kamar tersedia kembali mulai</label><Input id="end_exclusive" name="end_exclusive" type="date" value={filters.end_exclusive} required /><p class="text-xs text-muted-foreground">Batas akhir rentang tidak termasuk.</p></div>
            <div class="sm:col-span-2 lg:col-span-4"><Button type="submit" disabled={processing}>Periksa ketersediaan</Button></div>
        {/snippet}
    </Form>
    {#if filters.property_id}
        <section class="space-y-4" aria-label="Hasil ketersediaan">
            {#if rooms.length === 0}<div class="rounded-xl border border-dashed bg-card p-10 text-center"><h2 class="font-medium">Tidak ada kamar pada filter ini</h2><p class="mt-1 text-sm text-muted-foreground">Pilih properti atau rentang lain.</p></div>{:else}{#each rooms as room (room.id)}<article class="rounded-xl border bg-card p-5 shadow-sm"><div class="flex flex-wrap items-start justify-between gap-4"><div><h2 class="text-lg font-semibold">Kamar {room.code}</h2><p class="text-sm text-muted-foreground">Status: {operationalLabel[room.operational_status] ?? room.operational_status}</p></div><div class="text-right">{#if room.operational_status === 'rentable' && room.conflicts.length === 0}<span class="rounded-full border border-emerald-600/30 bg-emerald-500/10 px-3 py-1 text-sm font-medium text-emerald-800 dark:text-emerald-300">Tersedia</span>{:else}<span class="rounded-full border border-amber-600/30 bg-amber-500/10 px-3 py-1 text-sm font-medium">Tidak tersedia</span>{/if}{#if room.price}<p class="mt-2 text-sm">{rupiah(room.price.monthly_rent_rupiah)} / bulan</p><p class="text-xs text-muted-foreground">Tarif {room.price.source === 'room' ? 'khusus kamar' : 'tipe kamar'}</p>{/if}</div></div>
                {#if room.conflicts.length > 0}<ul class="mt-4 divide-y rounded-lg border">{#each room.conflicts as conflict (conflict.type + conflict.id)}<li class="flex flex-wrap items-center justify-between gap-3 p-3 text-sm"><div><span class="font-medium">{conflictLabel[conflict.type] ?? 'Jadwal'}</span><span class="ml-2 rounded-full border px-2 py-0.5 text-xs">{conflict.status}</span><p class="mt-1 text-muted-foreground">{conflict.description}</p></div><span class="text-muted-foreground">{conflict.start_date} – {conflict.end_exclusive}</span></li>{/each}</ul>{/if}
                <div class="mt-4 flex flex-wrap gap-3 text-sm"><Link href={`/owner/rooms/${room.id}`} class="text-primary underline-offset-4 hover:underline">Detail kamar</Link><Link href={`/owner/bookings/create?room_id=${room.id}&planned_start_date=${filters.start_date}&planned_end_exclusive=${filters.end_exclusive}`} class="text-primary underline-offset-4 hover:underline">Catat booking</Link><Link href={`/owner/direct-check-ins/create?room_id=${room.id}&planned_start_date=${filters.start_date}&planned_end_exclusive=${filters.end_exclusive}`} class="text-primary underline-offset-4 hover:underline">Check-in langsung</Link></div>
            </article>{/each}{/if}
        </section>
    {:else}
        <section class="rounded-xl border border-dashed bg-card p-8 text-center"><p class="text-sm text-muted-foreground">Pilih properti dan rentang tanggal untuk melihat ketersediaan kamar.</p></section>
    {/if}
</main>
