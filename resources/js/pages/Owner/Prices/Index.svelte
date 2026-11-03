<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            { title: 'Katalog', href: '/owner/properties' },
            { title: 'Riwayat tarif', href: '#' },
        ],
    };
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';

    type Price = {
        id: number;
        monthly_rent_rupiah: number;
        effective_from: string;
        effective_until_exclusive: string | null;
        effective_until_date: string | null;
        can_edit: boolean;
        can_end: boolean;
        source: 'room' | 'room_type';
    };

    let {
        target,
        listUrl,
        createUrl,
        price,
        prices,
        effectiveOn,
    }: {
        target: { type: 'room' | 'room_type'; id: number; label: string };
        listUrl: string;
        createUrl: string;
        price: { monthly_rent_rupiah: number; source: 'room' | 'room_type'; effective_from: string; effective_until_exclusive: string | null } | null;
        prices: Price[];
        effectiveOn: string;
    } = $props();

    const rupiah = (amount: number) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(amount);
    const date = (value: string) => new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeZone: 'Asia/Jakarta' }).format(new Date(`${value}T00:00:00+07:00`));
</script>

<AppHead title="Riwayat tarif" />

<main class="space-y-6 p-4 md:p-8">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div class="space-y-2"><p class="text-sm font-medium text-muted-foreground">{target.label}</p><h1 class="text-3xl font-semibold tracking-tight">Riwayat tarif bulanan</h1><p class="text-sm text-muted-foreground">Periode tarif memakai tanggal akhir inklusif dan disimpan sebagai riwayat.</p></div>
        <div class="flex gap-2"><Link href={listUrl} class="inline-flex rounded-md border px-4 py-2 text-sm">Kembali</Link><Link href={createUrl} class="inline-flex rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground">Tambah tarif</Link></div>
    </header>

    <section class="rounded-xl border bg-card p-5 shadow-sm">
        <p class="text-sm text-muted-foreground">Tarif yang digunakan untuk {date(effectiveOn)}</p>
        {#if price}<p class="mt-2 text-2xl font-semibold">{rupiah(price.monthly_rent_rupiah)}</p><p class="mt-1 text-sm text-muted-foreground">{price.source === 'room' ? 'Override khusus kamar' : 'Tarif tipe kamar'} · mulai {date(price.effective_from)}</p>{:else}<p class="mt-2 text-sm">Belum ada tarif yang berlaku. Tarif kesepakatan dapat dimasukkan manual pada proses booking atau check-in.</p>{/if}
    </section>

    <section class="overflow-hidden rounded-xl border bg-card shadow-sm">
        {#if prices.length === 0}
            <div class="p-10 text-center"><h2 class="font-medium">Belum ada tarif</h2><p class="mt-1 text-sm text-muted-foreground">Tambahkan tarif dengan tanggal mulai berlakunya.</p></div>
        {:else}
            <div class="overflow-x-auto">
                <table class="w-full min-w-[44rem] text-left text-sm">
                    <thead class="border-b bg-muted/40 text-muted-foreground"><tr><th class="px-5 py-3 font-medium">Tarif bulanan</th><th class="px-5 py-3 font-medium">Mulai berlaku</th><th class="px-5 py-3 font-medium">Akhir berlaku</th><th class="px-5 py-3 font-medium">Sumber</th><th class="px-5 py-3 text-right">Aksi</th></tr></thead>
                    <tbody class="divide-y">
                        {#each prices as item (item.id)}
                            <tr>
                                <td class="px-5 py-4 font-medium">{rupiah(item.monthly_rent_rupiah)}</td>
                                <td class="px-5 py-4">{date(item.effective_from)}</td>
                                <td class="px-5 py-4">{item.effective_until_date ? date(item.effective_until_date) : 'Tidak ditentukan'}</td>
                                <td class="px-5 py-4">{item.source === 'room' ? 'Kamar' : 'Tipe kamar'}</td>
                                <td class="px-5 py-4 text-right"><Link href={`/owner/prices/${item.id}/edit`} class="text-primary underline-offset-4 hover:underline">Kelola periode</Link></td>
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>
        {/if}
    </section>
</main>
