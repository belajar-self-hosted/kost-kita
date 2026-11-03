<script module lang="ts">
    export const layout = { breadcrumbs: [{ title: 'Booking', href: '/owner/bookings' }, { title: 'Tambah booking', href: '#' }] };
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';

    type Property = { id: number; name: string; rooms: { id: number; code: string; operational_status: string }[] };
    type Resident = { id: number; full_name: string; whatsapp_number: string };
    let { properties, residents, initial }: { properties: Property[]; residents: Resident[]; initial: { room_id: number | null; planned_start_date: string; planned_end_exclusive: string; agreed_monthly_rent_rupiah: number | null; required_deposit_rupiah: number } } = $props();
    let residentMode = $state('existing');

    const roomCount = $derived(properties.reduce((count, property) => count + property.rooms.length, 0));
</script>

<AppHead title="Tambah booking" />
<main class="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-8">
    <header class="space-y-2"><p class="text-sm font-medium text-muted-foreground">Booking</p><h1 class="text-3xl font-semibold tracking-tight">Tambah booking</h1><p class="text-sm text-muted-foreground">Pending belum menahan kamar. Konfirmasi akan memeriksa ulang ketersediaan.</p></header>
    <Form action="/owner/bookings" method="post" class="space-y-6 rounded-xl border bg-card p-5 shadow-sm">
        {#snippet children({ errors, processing })}
            <section class="space-y-4"><h2 class="font-semibold">Kamar dan periode</h2>
                <div class="space-y-2"><label for="room_id" class="text-sm font-medium">Kamar <span aria-hidden="true">*</span></label><select id="room_id" name="room_id" required class="h-10 w-full rounded-md border bg-background px-3 text-sm"><option value="">Pilih kamar</option>{#each properties as property (property.id)}<optgroup label={property.name}>{#each property.rooms as room (room.id)}<option value={room.id} selected={Number(initial.room_id) === room.id} disabled={room.operational_status !== 'rentable'}>{room.code}{room.operational_status !== 'rentable' ? ' · tidak beroperasi' : ''}</option>{/each}</optgroup>{/each}</select>{#if roomCount === 0}<p class="text-sm text-muted-foreground">Tambahkan kamar yang dapat disewakan terlebih dahulu.</p>{/if}<InputError message={errors.room_id} /></div>
                <div class="grid gap-4 sm:grid-cols-2"><div class="space-y-2"><label for="planned_start_date" class="text-sm font-medium">Tanggal rencana masuk <span aria-hidden="true">*</span></label><Input id="planned_start_date" name="planned_start_date" type="date" value={initial.planned_start_date} required /><InputError message={errors.planned_start_date} /></div><div class="space-y-2"><label for="planned_end_exclusive" class="text-sm font-medium">Kamar tersedia kembali mulai <span aria-hidden="true">*</span></label><Input id="planned_end_exclusive" name="planned_end_exclusive" type="date" value={initial.planned_end_exclusive} required /><p class="text-xs text-muted-foreground">Tanggal ini tidak termasuk masa huni.</p><InputError message={errors.planned_end_exclusive} /></div></div>
            </section>
            <section class="space-y-4 border-t pt-5"><div><h2 class="font-semibold">Data penyewa</h2><p class="text-sm text-muted-foreground">Penyewa tidak memerlukan akun aplikasi.</p></div>
                {#if residents.length > 0}<fieldset class="flex flex-wrap gap-4"><legend class="sr-only">Sumber data penyewa</legend><label class="flex items-center gap-2 text-sm"><input type="radio" name="resident_mode" value="existing" bind:group={residentMode} />Pilih penyewa yang ada</label><label class="flex items-center gap-2 text-sm"><input type="radio" name="resident_mode" value="new" bind:group={residentMode} />Tambah kontak baru</label></fieldset>{/if}
                {#if residentMode === 'existing' && residents.length > 0}<div class="space-y-2"><label for="resident_id" class="text-sm font-medium">Penyewa <span aria-hidden="true">*</span></label><select id="resident_id" name="resident_id" required class="h-10 w-full rounded-md border bg-background px-3 text-sm"><option value="">Pilih penyewa</option>{#each residents as resident (resident.id)}<option value={resident.id}>{resident.full_name} · {resident.whatsapp_number}</option>{/each}</select><InputError message={errors.resident_id} /></div>{:else}<div class="grid gap-4 sm:grid-cols-2"><div class="space-y-2"><label for="resident_full_name" class="text-sm font-medium">Nama penyewa <span aria-hidden="true">*</span></label><Input id="resident_full_name" name="resident_full_name" required maxlength={160} /><InputError message={errors.resident_full_name} /></div><div class="space-y-2"><label for="resident_whatsapp_number" class="text-sm font-medium">Nomor WhatsApp <span aria-hidden="true">*</span></label><Input id="resident_whatsapp_number" name="resident_whatsapp_number" type="tel" required maxlength={32} /><InputError message={errors.resident_whatsapp_number} /></div></div>{/if}
            </section>
            <section class="space-y-4 border-t pt-5"><div><h2 class="font-semibold">Kesepakatan</h2><p class="text-sm text-muted-foreground">Tarif katalog terisi sebagai nilai awal dan dapat disesuaikan dengan kesepakatan.</p></div>
                <div class="grid gap-4 sm:grid-cols-2"><div class="space-y-2"><label for="agreed_monthly_rent_rupiah" class="text-sm font-medium">Harga sewa per bulan (Rp) <span aria-hidden="true">*</span></label><Input id="agreed_monthly_rent_rupiah" name="agreed_monthly_rent_rupiah" type="number" min="1" step="1" value={initial.agreed_monthly_rent_rupiah ?? ''} required /><InputError message={errors.agreed_monthly_rent_rupiah} /></div><div class="space-y-2"><label for="required_deposit_rupiah" class="text-sm font-medium">Deposit jaminan yang disepakati (Rp)</label><Input id="required_deposit_rupiah" name="required_deposit_rupiah" type="number" min="0" step="1" value={initial.required_deposit_rupiah} /><p class="text-xs text-muted-foreground">Uang muka booking dan deposit jaminan memakai satu kewajiban.</p><InputError message={errors.required_deposit_rupiah} /></div></div>
                <div class="space-y-2"><label for="notes" class="text-sm font-medium">Catatan</label><textarea id="notes" name="notes" rows={3} maxlength={2000} class="w-full rounded-md border bg-background px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"></textarea><InputError message={errors.notes} /></div>
            </section>
            <div class="flex justify-end gap-3 border-t pt-4"><Link href="/owner/bookings" class="inline-flex items-center rounded-md border px-4 py-2 text-sm">Batal</Link><Button type="submit" disabled={processing || roomCount === 0}>{processing ? 'Menyimpan…' : 'Simpan sebagai pending'}</Button></div>
        {/snippet}
    </Form>
</main>
