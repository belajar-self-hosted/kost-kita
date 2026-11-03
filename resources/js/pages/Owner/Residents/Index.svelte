<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            { title: 'Penyewa', href: '/owner/residents' },
        ],
    };
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';

    type Resident = { id: number; full_name: string; whatsapp_number: string; contact_info: string | null; archived_at: string | null; tenancies_count: number };
    type PaginationLink = { url: string | null; label: string; active: boolean };

    let {
        residents,
        filters,
    }: {
        residents: { data: Resident[]; links: PaginationLink[]; from: number | null; to: number | null; total: number };
        filters: { search: string; include_archived: boolean };
    } = $props();
</script>

<AppHead title="Penyewa" />

<main class="space-y-6 p-4 md:p-8">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div class="space-y-2"><p class="text-sm font-medium text-muted-foreground">Manajemen</p><h1 class="text-3xl font-semibold tracking-tight">Penyewa</h1><p class="text-sm text-muted-foreground">Simpan kontak penyewa untuk mengelola booking dan masa sewa.</p></div>
        <Link href="/owner/residents/create" class="inline-flex rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground">Tambah penyewa</Link>
    </header>

    <Form action="/owner/residents" method="get" class="grid gap-3 rounded-xl border bg-card p-4 sm:grid-cols-[1fr_auto_auto] sm:items-end">
        {#snippet children({ processing })}
            <div class="space-y-1"><label for="search" class="text-sm font-medium">Cari nama atau WhatsApp</label><Input id="search" name="search" value={filters.search} placeholder="Nama atau nomor kontak" /></div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="include_archived" value="1" checked={filters.include_archived} class="size-4 rounded border" />Tampilkan arsip</label>
            <Button type="submit" disabled={processing}>Terapkan</Button>
        {/snippet}
    </Form>

    <section class="overflow-hidden rounded-xl border bg-card shadow-sm" aria-label="Daftar penyewa">
        {#if residents.data.length === 0}
            <div class="p-10 text-center"><h2 class="font-medium">Belum ada penyewa</h2><p class="mt-1 text-sm text-muted-foreground">Tambahkan kontak penyewa untuk mulai mencatat booking atau check-in.</p></div>
        {:else}
            <div class="overflow-x-auto"><table class="w-full min-w-[42rem] text-left text-sm">
                <thead class="border-b bg-muted/40 text-muted-foreground"><tr><th class="px-5 py-3 font-medium">Nama</th><th class="px-5 py-3 font-medium">WhatsApp</th><th class="px-5 py-3 font-medium">Masa sewa</th><th class="px-5 py-3 font-medium">Status</th><th class="px-5 py-3 text-right font-medium">Aksi</th></tr></thead>
                <tbody class="divide-y">{#each residents.data as resident (resident.id)}<tr>
                    <td class="px-5 py-4 font-medium"><Link href={`/owner/residents/${resident.id}`} class="underline-offset-4 hover:underline">{resident.full_name}</Link></td>
                    <td class="px-5 py-4"><a href={`https://wa.me/${resident.whatsapp_number.replace(/\D/g, '')}`} class="text-primary underline-offset-4 hover:underline">{resident.whatsapp_number}</a></td>
                    <td class="px-5 py-4">{resident.tenancies_count}</td>
                    <td class="px-5 py-4"><span class="rounded-full border px-2.5 py-1 text-xs">{resident.archived_at ? 'Diarsipkan' : 'Aktif'}</span></td>
                    <td class="px-5 py-4 text-right"><Link href={`/owner/residents/${resident.id}`} class="text-primary underline-offset-4 hover:underline">Lihat detail</Link></td>
                </tr>{/each}</tbody>
            </table></div>
            <footer class="flex flex-wrap items-center justify-between gap-4 border-t px-5 py-4 text-sm"><p class="text-muted-foreground">Menampilkan {residents.from ?? 0}–{residents.to ?? 0} dari {residents.total} penyewa</p><nav aria-label="Navigasi halaman" class="flex flex-wrap gap-1">{#each residents.links as link, index (index)}{#if link.url}<Link href={link.url} class={`rounded border px-3 py-1.5 ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`}>{link.label}</Link>{:else}<span class="rounded border px-3 py-1.5 text-muted-foreground">{link.label}</span>{/if}{/each}</nav></footer>
        {/if}
    </section>
</main>
