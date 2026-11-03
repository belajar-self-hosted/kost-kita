<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            { title: 'Kamar', href: '#' },
            { title: 'Detail kamar', href: '#' },
        ],
    };
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';

    type Price = { monthly_rent_rupiah: number; source: 'room' | 'room_type'; effective_from: string; effective_until_exclusive: string | null };
    type Room = { id: number; property_id: number; room_type_id: number | null; code: string; operational_status: 'rentable' | 'out_of_service' | 'archived'; description: string | null; archived_at: string | null; updated_at: string; room_type: { id: number; name: string; status: string } | null; facilities: string[]; price: Price | null; price_effective_on: string };
    let { property, room }: { property: { id: number; name: string }; room: Room } = $props();

    const rupiah = (amount: number) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(amount);
    const date = (value: string) => new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeZone: 'Asia/Jakarta' }).format(new Date(`${value}T00:00:00+07:00`));
</script>

<AppHead title={`Kamar ${room.code}`} />

<main class="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-8">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div class="space-y-2"><p class="text-sm font-medium text-muted-foreground">{property.name}</p><h1 class="text-3xl font-semibold tracking-tight">Kamar {room.code}</h1><p class="text-sm text-muted-foreground">{room.description || 'Belum ada keterangan.'}</p></div>
        <div class="flex gap-2"><Link href={`/owner/properties/${property.id}/rooms`} class="inline-flex items-center rounded-md border px-4 py-2 text-sm">Daftar kamar</Link>{#if room.operational_status !== 'archived'}<Link href={`/owner/rooms/${room.id}/edit`} class="inline-flex items-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground">Ubah</Link>{/if}</div>
    </header>

    <section class="grid gap-4 sm:grid-cols-2">
        <article class="rounded-xl border bg-card p-5 shadow-sm"><p class="text-sm text-muted-foreground">Status operasional</p><p class="mt-2 font-medium">{room.operational_status === 'rentable' ? 'Dapat disewakan' : room.operational_status === 'out_of_service' ? 'Tidak beroperasi' : 'Diarsipkan'}</p></article>
        <article class="rounded-xl border bg-card p-5 shadow-sm"><p class="text-sm text-muted-foreground">Tipe kamar</p><p class="mt-2 font-medium">{room.room_type?.name ?? 'Tanpa tipe'}</p></article>
    </section>

    <section class="space-y-3 rounded-xl border bg-card p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="font-semibold">Tarif bulanan untuk {date(room.price_effective_on)}</h2><p class="mt-1 text-sm text-muted-foreground">Tarif katalog adalah nilai awal untuk kesepakatan baru.</p></div><Link href={`/owner/rooms/${room.id}/prices`} class="text-sm font-medium text-primary underline-offset-4 hover:underline">Riwayat tarif</Link></div>
        {#if room.price}<p class="text-2xl font-semibold">{rupiah(room.price.monthly_rent_rupiah)}</p><p class="text-sm text-muted-foreground">Sumber: {room.price.source === 'room' ? 'override khusus kamar' : 'tarif tipe kamar'} · mulai {date(room.price.effective_from)}</p>{:else}<p class="rounded-md border border-dashed p-4 text-sm text-muted-foreground">Belum ada tarif yang berlaku. Pemilik dapat memasukkan harga kesepakatan manual saat booking atau check-in.</p>{/if}
    </section>

    <section class="space-y-3 rounded-xl border bg-card p-5 shadow-sm"><h2 class="font-semibold">Fasilitas</h2>{#if room.facilities.length}<ul class="flex flex-wrap gap-2">{#each room.facilities as facility (facility)}<li class="rounded-full border px-3 py-1 text-sm">{facility}</li>{/each}</ul>{:else}<p class="text-sm text-muted-foreground">Belum ada fasilitas tercatat.</p>{/if}</section>

    {#if room.operational_status !== 'archived'}
        <section class="space-y-4 rounded-xl border border-amber-300/60 bg-card p-5 shadow-sm"><div><h2 class="font-semibold">Arsipkan kamar</h2><p class="mt-1 text-sm text-muted-foreground">Histori dipertahankan. Arsip ditolak bila masih ada booking, masa sewa, atau maintenance aktif.</p></div><Form action={`/owner/rooms/${room.id}/archive`} method="post" class="space-y-3">{#snippet children({ errors, processing })}<label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirmation" value="1" required class="mt-0.5 size-4 rounded border" /><span>Saya memahami kamar ini akan diarsipkan.</span></label><InputError message={errors.archive} /><div class="flex justify-end"><Button type="submit" variant="destructive" disabled={processing}>{processing ? 'Mengarsipkan…' : 'Arsipkan kamar'}</Button></div>{/snippet}</Form></section>
    {:else}
        <section class="rounded-xl border bg-muted/30 p-5"><h2 class="font-semibold">Kamar diarsipkan</h2><p class="mt-1 text-sm text-muted-foreground">Tanggal arsip: {room.archived_at ? date(room.archived_at.slice(0, 10)) : '—'}</p></section>
    {/if}
</main>
