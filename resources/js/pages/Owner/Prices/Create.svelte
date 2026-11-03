<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            { title: 'Tarif', href: '#' },
            { title: 'Tambah tarif', href: '#' },
        ],
    };
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';

    let {
        target,
        storeUrl,
        suggestedPrice,
        today,
    }: {
        target: { type: 'room' | 'room_type'; id: number; label: string };
        storeUrl: string;
        suggestedPrice: { monthly_rent_rupiah: number; source: string } | null;
        today: string;
    } = $props();

    const backUrl = $derived(target.type === 'room' ? `/owner/rooms/${target.id}/prices` : `/owner/room-types/${target.id}/prices`);
</script>

<AppHead title="Tambah tarif bulanan" />

<main class="mx-auto w-full max-w-3xl space-y-6 p-4 md:p-8">
    <header class="space-y-2"><p class="text-sm font-medium text-muted-foreground">{target.label}</p><h1 class="text-3xl font-semibold tracking-tight">Tambah tarif bulanan</h1><p class="text-sm text-muted-foreground">Tanggal akhir yang dimasukkan bersifat inklusif. Kosongkan untuk tarif tanpa tanggal akhir.</p></header>
    {#if suggestedPrice}<p class="rounded-lg border bg-muted/30 p-4 text-sm">Tarif yang berlaku saat ini: <strong>{new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(suggestedPrice.monthly_rent_rupiah)}</strong></p>{/if}
    <Form action={storeUrl} method="post" class="space-y-5 rounded-xl border bg-card p-5 shadow-sm">
        {#snippet children({ errors, processing })}
            <div class="space-y-2"><label for="monthly_rent_rupiah" class="text-sm font-medium">Tarif per bulan (Rupiah) <span aria-hidden="true">*</span></label><Input id="monthly_rent_rupiah" name="monthly_rent_rupiah" type="number" min="1" step="1" required placeholder="Contoh: 1500000" /><InputError message={errors.monthly_rent_rupiah} /></div>
            <div class="space-y-2"><label for="effective_from" class="text-sm font-medium">Mulai berlaku <span aria-hidden="true">*</span></label><Input id="effective_from" name="effective_from" type="date" min={today} required /><InputError message={errors.effective_from} /></div>
            <div class="space-y-2"><label for="effective_until_date" class="text-sm font-medium">Berlaku sampai (opsional)</label><Input id="effective_until_date" name="effective_until_date" type="date" /><p class="text-xs text-muted-foreground">Tanggal akhir dihitung sampai penghujung hari tersebut.</p><InputError message={errors.effective_until_date} /></div>
            <InputError message={errors.price} />
            <div class="flex justify-end gap-3 border-t pt-4"><Link href={backUrl} class="inline-flex items-center rounded-md border px-4 py-2 text-sm">Batal</Link><Button type="submit" disabled={processing}>{processing ? 'Menyimpan…' : 'Simpan tarif'}</Button></div>
        {/snippet}
    </Form>
</main>
