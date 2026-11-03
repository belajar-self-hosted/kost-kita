<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            { title: 'Properti', href: '/owner/properties' },
            { title: 'Tambah properti', href: '/owner/properties/create' },
        ],
    };
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
</script>

<AppHead title="Tambah properti" />

<main class="mx-auto w-full max-w-3xl space-y-6 p-4 md:p-8">
    <header class="space-y-2"><p class="text-sm font-medium text-muted-foreground">Properti</p><h1 class="text-3xl font-semibold tracking-tight">Tambah properti</h1><p class="text-sm text-muted-foreground">Masukkan informasi dasar. Kepemilikan tenant ditetapkan oleh server.</p></header>
    <Form action="/owner/properties" method="post" class="space-y-5 rounded-xl border bg-card p-5 shadow-sm">
        {#snippet children({ errors, processing })}
            <div class="space-y-2"><label for="name" class="text-sm font-medium">Nama properti <span aria-hidden="true">*</span></label><Input id="name" name="name" required maxlength={160} autocomplete="organization" placeholder="Contoh: Kost Melati" /><InputError message={errors.name} /></div>
            <div class="space-y-2"><label for="address" class="text-sm font-medium">Alamat atau lokasi</label><textarea id="address" name="address" rows={4} maxlength={1000} class="w-full rounded-md border bg-background px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring" placeholder="Alamat atau catatan lokasi"></textarea><InputError message={errors.address} /></div>
            <div class="flex flex-wrap justify-end gap-3 border-t pt-4"><Link href="/owner/properties" class="inline-flex items-center rounded-md border px-4 py-2 text-sm">Batal</Link><Button type="submit" disabled={processing}>{processing ? 'Menyimpan…' : 'Simpan properti'}</Button></div>
        {/snippet}
    </Form>
</main>
