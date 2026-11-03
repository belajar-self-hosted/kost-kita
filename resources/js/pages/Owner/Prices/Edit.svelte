<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            { title: 'Tarif', href: '#' },
            { title: 'Kelola periode', href: '#' },
        ],
    };
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';

    type Price = {
        id: number;
        monthly_rent_rupiah: number;
        effective_from: string;
        effective_until_date: string | null;
        can_edit: boolean;
        can_end: boolean;
        today: string;
        editUrl: string;
        endUrl: string;
        targetUrl: string;
    };

    let { price }: { price: Price } = $props();
    const date = (value: string) => new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeZone: 'Asia/Jakarta' }).format(new Date(`${value}T00:00:00+07:00`));
    const rupiah = (amount: number) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(amount);
</script>

<AppHead title="Kelola periode tarif" />

<main class="mx-auto w-full max-w-3xl space-y-6 p-4 md:p-8">
    <header class="flex flex-wrap items-start justify-between gap-4"><div class="space-y-2"><h1 class="text-3xl font-semibold tracking-tight">Kelola periode tarif</h1><p class="text-sm text-muted-foreground">Riwayat yang sudah berlaku tidak dapat ditulis ulang.</p></div><Link href={price.targetUrl} class="inline-flex items-center rounded-md border px-4 py-2 text-sm">Riwayat tarif</Link></header>
    <section class="rounded-xl border bg-card p-5 shadow-sm"><p class="text-sm text-muted-foreground">Tarif per bulan</p><p class="mt-1 text-2xl font-semibold">{rupiah(price.monthly_rent_rupiah)}</p><p class="mt-2 text-sm text-muted-foreground">Mulai {date(price.effective_from)} · berakhir {price.effective_until_date ? date(price.effective_until_date) : 'tanpa tanggal akhir'}</p></section>

    {#if price.can_edit}
        <section class="space-y-4 rounded-xl border bg-card p-5 shadow-sm"><div><h2 class="font-semibold">Ubah tarif mendatang</h2><p class="mt-1 text-sm text-muted-foreground">Periode tidak boleh bertumpang tindih dengan tarif lain.</p></div><Form action={price.editUrl} method="put" class="space-y-4">{#snippet children({ errors, processing })}<div class="space-y-2"><label for="monthly_rent_rupiah" class="text-sm font-medium">Tarif per bulan (Rupiah)</label><Input id="monthly_rent_rupiah" name="monthly_rent_rupiah" type="number" min="1" step="1" value={price.monthly_rent_rupiah} required /><InputError message={errors.monthly_rent_rupiah} /></div><div class="space-y-2"><label for="effective_from" class="text-sm font-medium">Mulai berlaku</label><Input id="effective_from" name="effective_from" type="date" min={price.today} value={price.effective_from} required /><InputError message={errors.effective_from} /></div><div class="space-y-2"><label for="effective_until_date" class="text-sm font-medium">Berlaku sampai (opsional)</label><Input id="effective_until_date" name="effective_until_date" type="date" value={price.effective_until_date ?? ''} /><InputError message={errors.effective_until_date} /></div><InputError message={errors.price} /><div class="flex justify-end"><Button type="submit" disabled={processing}>{processing ? 'Menyimpan…' : 'Simpan perubahan'}</Button></div>{/snippet}</Form></section>
    {:else}
        <p class="rounded-lg border bg-muted/30 p-4 text-sm">Nilai tarif dan tanggal mulainya dikunci karena periode ini sudah berlaku atau dimulai hari ini.</p>
    {/if}

    {#if price.can_end}
        <section class="space-y-4 rounded-xl border border-amber-300/60 bg-card p-5 shadow-sm"><div><h2 class="font-semibold">Akhiri periode tarif</h2><p class="mt-1 text-sm text-muted-foreground">Tarif berlaku sampai tanggal yang dipilih. Histori tetap tersimpan.</p></div><Form action={price.endUrl} method="post" class="space-y-4">{#snippet children({ errors, processing })}<div class="space-y-2"><label for="end_effective_until_date" class="text-sm font-medium">Berlaku sampai, termasuk tanggal ini</label><Input id="end_effective_until_date" name="effective_until_date" type="date" min={price.effective_from} value={price.effective_until_date ?? ''} required /><InputError message={errors.effective_until_date} /></div><div class="flex justify-end"><Button type="submit" variant="outline" disabled={processing}>{processing ? 'Menyimpan…' : 'Akhiri periode'}</Button></div>{/snippet}</Form></section>
    {/if}
</main>
