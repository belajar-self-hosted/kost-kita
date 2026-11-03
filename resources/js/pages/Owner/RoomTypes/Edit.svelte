<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            { title: 'Tipe kamar', href: '#' },
            { title: 'Ubah tipe', href: '#' },
        ],
    };
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';

    let { roomType }: { roomType: { id: number; property_id: number; name: string; description: string | null } } = $props();
</script>

<AppHead title="Ubah tipe kamar" />

<main class="mx-auto w-full max-w-3xl space-y-6 p-4 md:p-8">
    <header class="space-y-2"><p class="text-sm font-medium text-muted-foreground">Tipe kamar</p><h1 class="text-3xl font-semibold tracking-tight">Ubah tipe kamar</h1><p class="text-sm text-muted-foreground">Perubahan tarif katalog tetap mengikuti periode efektif.</p></header>
    <Form action={`/owner/room-types/${roomType.id}`} method="put" class="space-y-5 rounded-xl border bg-card p-5 shadow-sm">
        {#snippet children({ errors, processing })}
            <div class="space-y-2"><label for="name" class="text-sm font-medium">Nama tipe <span aria-hidden="true">*</span></label><Input id="name" name="name" value={roomType.name} required maxlength={120} /><InputError message={errors.name} /></div>
            <div class="space-y-2"><label for="description" class="text-sm font-medium">Keterangan</label><textarea id="description" name="description" rows={4} maxlength={2000} class="w-full rounded-md border bg-background px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring">{roomType.description ?? ''}</textarea><InputError message={errors.description} /></div>
            <div class="flex justify-end gap-3 border-t pt-4"><Link href={`/owner/properties/${roomType.property_id}/room-types`} class="inline-flex items-center rounded-md border px-4 py-2 text-sm">Batal</Link><Button type="submit" disabled={processing}>{processing ? 'Menyimpan…' : 'Simpan perubahan'}</Button></div>
        {/snippet}
    </Form>
</main>
