<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            { title: 'Tipe kamar', href: '#' },
            { title: 'Tambah tipe', href: '#' },
        ],
    };
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';

    let { property }: { property: { id: number; name: string } } = $props();
</script>

<AppHead title="Tambah tipe kamar" />

<main class="mx-auto w-full max-w-3xl space-y-6 p-4 md:p-8">
    <header class="space-y-2"><p class="text-sm font-medium text-muted-foreground">{property.name}</p><h1 class="text-3xl font-semibold tracking-tight">Tambah tipe kamar</h1><p class="text-sm text-muted-foreground">Nama tipe dapat digunakan ulang pada kamar di properti ini.</p></header>
    <Form action={`/owner/properties/${property.id}/room-types`} method="post" class="space-y-5 rounded-xl border bg-card p-5 shadow-sm">
        {#snippet children({ errors, processing })}
            <div class="space-y-2"><label for="name" class="text-sm font-medium">Nama tipe <span aria-hidden="true">*</span></label><Input id="name" name="name" required maxlength={120} placeholder="Contoh: Kamar mandi dalam" /><InputError message={errors.name} /></div>
            <div class="space-y-2"><label for="description" class="text-sm font-medium">Keterangan</label><textarea id="description" name="description" rows={4} maxlength={2000} class="w-full rounded-md border bg-background px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring" placeholder="Fitur utama tipe kamar"></textarea><InputError message={errors.description} /></div>
            <div class="flex justify-end gap-3 border-t pt-4"><Link href={`/owner/properties/${property.id}/room-types`} class="inline-flex items-center rounded-md border px-4 py-2 text-sm">Batal</Link><Button type="submit" disabled={processing}>{processing ? 'Menyimpan…' : 'Simpan tipe'}</Button></div>
        {/snippet}
    </Form>
</main>
