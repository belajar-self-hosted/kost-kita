<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            { title: 'Properti', href: '/owner/properties' },
            { title: 'Kamar', href: '#' },
        ],
    };
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';

    type Room = { id: number; code: string; operational_status: 'rentable' | 'out_of_service' | 'archived'; description: string | null; facilities_count: number; room_type: { id: number; name: string; status: string } | null };

    let { property, rooms }: { property: { id: number; name: string }; rooms: { data: Room[]; from: number | null; to: number | null; total: number } } = $props();
</script>

<AppHead title="Kamar" />

<main class="space-y-6 p-4 md:p-8">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div class="space-y-2"><p class="text-sm font-medium text-muted-foreground">{property.name}</p><h1 class="text-3xl font-semibold tracking-tight">Kamar</h1><p class="text-sm text-muted-foreground">Status operasional terpisah dari booking dan okupansi.</p></div>
        <div class="flex gap-2"><Link href={`/owner/properties/${property.id}`} class="inline-flex rounded-md border px-4 py-2 text-sm">Properti</Link><Link href={`/owner/properties/${property.id}/rooms/create`} class="inline-flex rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground">Tambah kamar</Link></div>
    </header>

    <section class="overflow-hidden rounded-xl border bg-card shadow-sm">
        {#if rooms.data.length === 0}
            <div class="p-10 text-center"><h2 class="font-medium">Belum ada kamar</h2><p class="mt-1 text-sm text-muted-foreground">Tambahkan kamar dan fasilitasnya untuk melanjutkan pengelolaan.</p></div>
        {:else}
            <div class="overflow-x-auto">
                <table class="w-full min-w-[44rem] text-left text-sm">
                    <thead class="border-b bg-muted/40 text-muted-foreground"><tr><th class="px-5 py-3 font-medium">Kode</th><th class="px-5 py-3 font-medium">Tipe</th><th class="px-5 py-3 font-medium">Fasilitas</th><th class="px-5 py-3 font-medium">Status operasional</th><th class="px-5 py-3 text-right">Aksi</th></tr></thead>
                    <tbody class="divide-y">
                        {#each rooms.data as room (room.id)}
                            <tr>
                                <td class="px-5 py-4 font-medium"><Link href={`/owner/rooms/${room.id}`} class="underline-offset-4 hover:underline">{room.code}</Link></td>
                                <td class="px-5 py-4">{room.room_type?.name ?? '—'}</td>
                                <td class="px-5 py-4">{room.facilities_count}</td>
                                <td class="px-5 py-4"><span class="rounded-full border px-2.5 py-1 text-xs">{room.operational_status === 'rentable' ? 'Dapat disewakan' : room.operational_status === 'out_of_service' ? 'Tidak beroperasi' : 'Diarsipkan'}</span></td>
                                <td class="px-5 py-4 text-right"><Link href={`/owner/rooms/${room.id}`} class="text-primary underline-offset-4 hover:underline">Lihat detail</Link></td>
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>
            <footer class="border-t px-5 py-4 text-sm text-muted-foreground">Menampilkan {rooms.from ?? 0}–{rooms.to ?? 0} dari {rooms.total} kamar</footer>
        {/if}
    </section>
</main>
