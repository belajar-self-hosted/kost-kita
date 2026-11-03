<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            { title: 'Dasbor', href: '/owner' },
            { title: 'Properti', href: '/owner/properties' },
        ],
    };
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';

    type Property = { id: number; name: string; address: string | null; status: 'active' | 'archived'; archived_at: string | null };
    type PaginationLink = { url: string | null; label: string; active: boolean };

    let {
        properties,
        filters,
    }: {
        properties: { data: Property[]; links: PaginationLink[]; from: number | null; to: number | null; total: number };
        filters: { q: string; status: string };
    } = $props();
</script>

<AppHead title="Properti" />

<main class="space-y-6 p-4 md:p-8">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div class="space-y-2">
            <p class="text-sm font-medium text-muted-foreground">Manajemen</p>
            <h1 class="text-3xl font-semibold tracking-tight">Properti</h1>
            <p class="text-sm text-muted-foreground">Daftar properti dalam tenant milikmu.</p>
        </div>
        <Link href="/owner/properties/create" class="inline-flex rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground">Tambah properti</Link>
    </header>

    <Form action="/owner/properties" method="get" class="grid gap-3 rounded-xl border bg-card p-4 sm:grid-cols-[1fr_12rem_auto]">
        {#snippet children({ errors, processing })}
            <div class="space-y-1">
                <label for="q" class="text-sm font-medium">Cari nama atau alamat</label>
                <Input id="q" name="q" value={filters.q} placeholder="Contoh: Kost Melati" />
                <InputError message={errors.q} />
            </div>
            <div class="space-y-1">
                <label for="status" class="text-sm font-medium">Status</label>
                <select id="status" name="status" value={filters.status} class="h-10 w-full rounded-md border bg-background px-3 text-sm">
                    <option value="">Semua status</option>
                    <option value="active">Aktif</option>
                    <option value="archived">Diarsipkan</option>
                </select>
                <InputError message={errors.status} />
            </div>
            <div class="flex items-end"><Button type="submit" disabled={processing}>Terapkan</Button></div>
        {/snippet}
    </Form>

    <section class="overflow-hidden rounded-xl border bg-card shadow-sm">
        {#if properties.data.length === 0}
            <div class="p-10 text-center">
                <h2 class="font-medium">{filters.q || filters.status ? 'Tidak ada properti yang cocok' : 'Belum ada properti'}</h2>
                <p class="mt-1 text-sm text-muted-foreground">{filters.q || filters.status ? 'Coba ubah kata kunci atau filter status.' : 'Tambahkan properti pertama untuk memulai.'}</p>
            </div>
        {:else}
            <div class="overflow-x-auto">
                <table class="w-full min-w-[36rem] text-left text-sm">
                    <thead class="border-b bg-muted/40 text-muted-foreground"><tr><th class="px-5 py-3 font-medium">Nama</th><th class="px-5 py-3 font-medium">Alamat</th><th class="px-5 py-3 font-medium">Status</th><th class="px-5 py-3 text-right font-medium">Aksi</th></tr></thead>
                    <tbody class="divide-y">
                        {#each properties.data as property (property.id)}
                            <tr>
                                <td class="px-5 py-4 font-medium"><Link href={`/owner/properties/${property.id}`} class="underline-offset-4 hover:underline">{property.name}</Link></td>
                                <td class="px-5 py-4 text-muted-foreground">{property.address || '—'}</td>
                                <td class="px-5 py-4"><span class="rounded-full border px-2.5 py-1 text-xs">{property.status === 'active' ? 'Aktif' : 'Diarsipkan'}</span></td>
                                <td class="px-5 py-4 text-right"><Link href={`/owner/properties/${property.id}`} class="text-primary underline-offset-4 hover:underline">Lihat detail</Link></td>
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>
            <footer class="flex flex-wrap items-center justify-between gap-4 border-t px-5 py-4 text-sm">
                <p class="text-muted-foreground">Menampilkan {properties.from ?? 0}–{properties.to ?? 0} dari {properties.total} properti</p>
                <nav aria-label="Navigasi halaman" class="flex flex-wrap gap-1">
                    {#each properties.links as link, index (index)}
                        {#if link.url}
                            <Link href={link.url} class={`rounded border px-3 py-1.5 ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`}>{link.label}</Link>
                        {:else}
                            <span class="rounded border px-3 py-1.5 text-muted-foreground">{link.label}</span>
                        {/if}
                    {/each}
                </nav>
            </footer>
        {/if}
    </section>
</main>
