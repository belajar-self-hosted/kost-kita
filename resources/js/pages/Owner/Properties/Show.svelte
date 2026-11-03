<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            { title: 'Properti', href: '/owner/properties' },
            { title: 'Detail properti', href: '#' },
        ],
    };
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';

    let { property }: { property: { id: number; name: string; address: string | null; status: 'active' | 'archived'; archived_at: string | null; updated_at: string } } = $props();

    const formatDateTime = (value: string) =>
        new Intl.DateTimeFormat('id-ID', {
            dateStyle: 'medium',
            timeStyle: 'short',
            timeZone: 'Asia/Jakarta',
        }).format(new Date(value));
</script>

<AppHead title={property.name} />

<main class="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-8">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div class="space-y-2"><p class="text-sm font-medium text-muted-foreground">Detail properti</p><h1 class="text-3xl font-semibold tracking-tight">{property.name}</h1><p class="text-sm text-muted-foreground">{property.address || 'Alamat belum diisi'}</p></div>
        <div class="flex gap-2"><Link href="/owner/properties" class="inline-flex items-center rounded-md border px-4 py-2 text-sm">Kembali</Link>{#if property.status === 'active'}<Link href={`/owner/properties/${property.id}/edit`} class="inline-flex items-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground">Ubah</Link>{/if}</div>
    </header>

    <section class="grid gap-4 sm:grid-cols-2">
        <article class="rounded-xl border bg-card p-5 shadow-sm"><p class="text-sm text-muted-foreground">Status</p><p class="mt-2 font-medium">{property.status === 'active' ? 'Aktif' : 'Diarsipkan'}</p></article>
        <article class="rounded-xl border bg-card p-5 shadow-sm"><p class="text-sm text-muted-foreground">Diperbarui</p><p class="mt-2 font-medium">{formatDateTime(property.updated_at)}</p></article>
    </section>

    <section class="grid gap-4 sm:grid-cols-2">
        <Link href={`/owner/properties/${property.id}/rooms`} class="rounded-xl border bg-card p-5 shadow-sm transition hover:bg-muted/30">
            <h2 class="font-semibold">Kamar</h2><p class="mt-1 text-sm text-muted-foreground">Kelola kode kamar, fasilitas, dan tipe.</p>
        </Link>
        <Link href={`/owner/properties/${property.id}/room-types`} class="rounded-xl border bg-card p-5 shadow-sm transition hover:bg-muted/30">
            <h2 class="font-semibold">Tipe kamar dan tarif</h2><p class="mt-1 text-sm text-muted-foreground">Atur tipe dan tarif bulanan default.</p>
        </Link>
    </section>

    {#if property.status === 'active'}
        <section class="space-y-4 rounded-xl border border-amber-300/60 bg-card p-5 shadow-sm">
            <div><h2 class="font-semibold">Arsipkan properti</h2><p class="mt-1 text-sm text-muted-foreground">Properti tidak dihapus. Histori tetap tersedia; pengarsipan ditolak bila masih ada booking, masa sewa, atau maintenance aktif.</p></div>
            <Form action={`/owner/properties/${property.id}/archive`} method="post" class="space-y-3">
                {#snippet children({ errors, processing })}
                    <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirmation" value="1" required class="mt-0.5 size-4 rounded border" /><span>Saya memahami properti ini akan dinonaktifkan.</span></label>
                    <InputError message={errors.confirmation} />
                    <InputError message={errors.archive} />
                    <div class="flex justify-end"><Button type="submit" variant="destructive" disabled={processing}>{processing ? 'Mengarsipkan…' : 'Arsipkan properti'}</Button></div>
                {/snippet}
            </Form>
        </section>
    {:else}
        <section class="rounded-xl border bg-muted/30 p-5"><h2 class="font-semibold">Properti diarsipkan</h2><p class="mt-1 text-sm text-muted-foreground">Tanggal pengarsipan: {property.archived_at ? formatDateTime(property.archived_at) : '—'}</p></section>
    {/if}
</main>
