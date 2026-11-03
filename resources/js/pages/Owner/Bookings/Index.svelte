<script module lang="ts">
    export const layout = { breadcrumbs: [{ title: 'Booking', href: '/owner/bookings' }] };
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';

    type Booking = { id: number; status: string; planned_start_date: string; planned_end_exclusive: string; agreed_monthly_rent_rupiah: number; check_in_deadline_at: string | null; room: { id: number; code: string }; property: { id: number; name: string }; resident: { id: number; full_name: string; whatsapp_number: string } };
    type PaginationLink = { url: string | null; label: string; active: boolean };
    let { bookings, filters }: { bookings: { data: Booking[]; links: PaginationLink[]; from: number | null; to: number | null; total: number }; filters: { status: string; property_id: string; room_id: string; from: string; to: string } } = $props();

    const labels: Record<string, string> = { pending: 'Menunggu', confirmed: 'Dikonfirmasi', checked_in: 'Sudah check-in', cancelled: 'Dibatalkan', no_show: 'Tidak hadir' };
    const rupiah = (amount: number) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(amount);
</script>

<AppHead title="Booking" />
<main class="space-y-6 p-4 md:p-8">
    <header class="flex flex-wrap items-end justify-between gap-4"><div class="space-y-2"><p class="text-sm font-medium text-muted-foreground">Manajemen</p><h1 class="text-3xl font-semibold tracking-tight">Booking</h1><p class="text-sm text-muted-foreground">Catat kesepakatan setelah disepakati dengan calon penyewa.</p></div><Link href="/owner/bookings/create" class="inline-flex rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground">Tambah booking</Link></header>
    <Form action="/owner/bookings" method="get" class="grid gap-3 rounded-xl border bg-card p-4 sm:grid-cols-2 lg:grid-cols-5 lg:items-end">
        {#snippet children({ processing })}
            <div class="space-y-1"><label for="status" class="text-sm font-medium">Status</label><select id="status" name="status" value={filters.status} class="h-10 w-full rounded-md border bg-background px-3 text-sm"><option value="">Semua status</option><option value="pending">Menunggu</option><option value="confirmed">Dikonfirmasi</option><option value="checked_in">Sudah check-in</option><option value="cancelled">Dibatalkan</option><option value="no_show">Tidak hadir</option></select></div>
            <div class="space-y-1"><label for="property_id" class="text-sm font-medium">ID properti</label><Input id="property_id" name="property_id" type="number" min="1" value={filters.property_id} /></div>
            <div class="space-y-1"><label for="room_id" class="text-sm font-medium">ID kamar</label><Input id="room_id" name="room_id" type="number" min="1" value={filters.room_id} /></div>
            <div class="space-y-1"><label for="from" class="text-sm font-medium">Masuk mulai</label><Input id="from" name="from" type="date" value={filters.from} /></div>
            <div class="space-y-1"><label for="to" class="text-sm font-medium">Masuk sampai</label><Input id="to" name="to" type="date" value={filters.to} /></div>
            <div class="sm:col-span-2 lg:col-span-5"><Button type="submit" disabled={processing}>Terapkan filter</Button></div>
        {/snippet}
    </Form>
    <section class="overflow-hidden rounded-xl border bg-card shadow-sm" aria-label="Daftar booking">
        {#if bookings.data.length === 0}<div class="p-10 text-center"><h2 class="font-medium">Belum ada booking</h2><p class="mt-1 text-sm text-muted-foreground">Booking pending tidak menahan ketersediaan kamar sampai dikonfirmasi.</p></div>{:else}
            <div class="overflow-x-auto"><table class="w-full min-w-[54rem] text-left text-sm"><thead class="border-b bg-muted/40 text-muted-foreground"><tr><th class="px-5 py-3 font-medium">Penyewa</th><th class="px-5 py-3 font-medium">Kamar</th><th class="px-5 py-3 font-medium">Periode</th><th class="px-5 py-3 font-medium">Tarif</th><th class="px-5 py-3 font-medium">Status</th><th class="px-5 py-3 text-right font-medium">Aksi</th></tr></thead><tbody class="divide-y">{#each bookings.data as booking (booking.id)}<tr><td class="px-5 py-4"><Link href={`/owner/residents/${booking.resident.id}`} class="font-medium underline-offset-4 hover:underline">{booking.resident.full_name}</Link><p class="mt-1 text-xs text-muted-foreground">{booking.resident.whatsapp_number}</p></td><td class="px-5 py-4"><p>{booking.property.name}</p><p class="text-xs text-muted-foreground">Kamar {booking.room.code}</p></td><td class="px-5 py-4">{booking.planned_start_date}<span class="text-muted-foreground"> – </span>{booking.planned_end_exclusive}</td><td class="px-5 py-4">{rupiah(booking.agreed_monthly_rent_rupiah)}<span class="text-xs text-muted-foreground"> / bulan</span></td><td class="px-5 py-4"><span class="rounded-full border px-2.5 py-1 text-xs">{labels[booking.status] ?? booking.status}</span></td><td class="px-5 py-4 text-right"><Link href={`/owner/bookings/${booking.id}`} class="text-primary underline-offset-4 hover:underline">Detail</Link></td></tr>{/each}</tbody></table></div>
            <footer class="flex flex-wrap items-center justify-between gap-4 border-t px-5 py-4 text-sm"><p class="text-muted-foreground">Menampilkan {bookings.from ?? 0}–{bookings.to ?? 0} dari {bookings.total} booking</p><nav aria-label="Navigasi halaman" class="flex flex-wrap gap-1">{#each bookings.links as link, index (index)}{#if link.url}<Link href={link.url} class={`rounded border px-3 py-1.5 ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`}>{link.label}</Link>{:else}<span class="rounded border px-3 py-1.5 text-muted-foreground">{link.label}</span>{/if}{/each}</nav></footer>
        {/if}
    </section>
</main>
