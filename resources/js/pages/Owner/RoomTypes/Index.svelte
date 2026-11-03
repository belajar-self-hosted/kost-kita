<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            { title: 'Properti', href: '/owner/properties' },
            { title: 'Tipe kamar', href: '#' },
        ],
    };
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';

    type RoomType = { id: number; name: string; description: string | null; status: 'active' | 'archived'; rooms_count: number };
    type PageLink = { url: string | null; label: string; active: boolean };

    let {
        property,
        roomTypes,
    }: {
        property: { id: number; name: string };
        roomTypes: { data: RoomType[]; links: PageLink[]; from: number | null; to: number | null; total: number };
    } = $props();
</script>

<AppHead title="Tipe kamar" />

<main class="space-y-6 p-4 md:p-8">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div class="space-y-2"><p class="text-sm font-medium text-muted-foreground">{property.name}</p><h1 class="text-3xl font-semibold tracking-tight">Tipe kamar</h1><p class="text-sm text-muted-foreground">Tipe menyediakan tarif bulanan default untuk kamar.</p></div>
        <div class="flex gap-2"><Link href={`/owner/properties/${property.id}`} class="inline-flex rounded-md border px-4 py-2 text-sm">Properti</Link><Link href={`/owner/properties/${property.id}/room-types/create`} class="inline-flex rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground">Tambah tipe</Link></div>
    </header>

    <section class="overflow-hidden rounded-xl border bg-card shadow-sm">
        {#if roomTypes.data.length === 0}
            <div class="p-10 text-center"><h2 class="font-medium">Belum ada tipe kamar</h2><p class="mt-1 text-sm text-muted-foreground">Buat tipe untuk mengelompokkan kamar dan menetapkan tarif default.</p></div>
        {:else}
            <div class="overflow-x-auto">
                <table class="w-full min-w-[44rem] text-left text-sm">
                    <thead class="border-b bg-muted/40 text-muted-foreground"><tr><th class="px-5 py-3 font-medium">Nama</th><th class="px-5 py-3 font-medium">Keterangan</th><th class="px-5 py-3 font-medium">Kamar</th><th class="px-5 py-3 font-medium">Status</th><th class="px-5 py-3 text-right">Aksi</th></tr></thead>
                    <tbody class="divide-y">
                        {#each roomTypes.data as roomType (roomType.id)}
                            <tr>
                                <td class="px-5 py-4 font-medium">{roomType.name}</td>
                                <td class="px-5 py-4 text-muted-foreground">{roomType.description || '—'}</td>
                                <td class="px-5 py-4">{roomType.rooms_count}</td>
                                <td class="px-5 py-4"><span class="rounded-full border px-2.5 py-1 text-xs">{roomType.status === 'active' ? 'Aktif' : 'Diarsipkan'}</span></td>
                                <td class="px-5 py-4 text-right">
                                    <div class="flex flex-wrap justify-end gap-3">
                                        <Link href={`/owner/room-types/${roomType.id}/prices`} class="text-primary underline-offset-4 hover:underline">Tarif</Link>
                                        {#if roomType.status === 'active'}
                                            <Link href={`/owner/room-types/${roomType.id}/edit`} class="text-primary underline-offset-4 hover:underline">Ubah</Link>
                                            <Form action={`/owner/room-types/${roomType.id}/archive`} method="post" class="inline-flex items-center gap-2">
                                                {#snippet children({ errors, processing })}
                                                    <input type="checkbox" name="confirmation" value="1" required aria-label={`Konfirmasi arsip ${roomType.name}`} class="size-4 rounded border" />
                                                    <Button type="submit" variant="outline" size="sm" disabled={processing}>Arsipkan</Button>
                                                    <InputError message={errors.archive} />
                                                {/snippet}
                                            </Form>
                                        {/if}
                                    </div>
                                </td>
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>
            <footer class="flex flex-wrap items-center justify-between gap-4 border-t px-5 py-4 text-sm">
                <p class="text-muted-foreground">Menampilkan {roomTypes.from ?? 0}–{roomTypes.to ?? 0} dari {roomTypes.total} tipe</p>
                <nav aria-label="Navigasi halaman" class="flex flex-wrap gap-1">
                    {#each roomTypes.links as link, index (index)}
                        {#if link.url}<Link href={link.url} class={`rounded border px-3 py-1.5 ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`}>{link.label}</Link>{:else}<span class="rounded border px-3 py-1.5 text-muted-foreground">{link.label}</span>{/if}
                    {/each}
                </nav>
            </footer>
        {/if}
    </section>
</main>
