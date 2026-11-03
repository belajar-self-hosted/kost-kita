<script module lang="ts">
    export const layout = {
        breadcrumbs: [{ title: 'Dasbor platform', href: '/platform' }],
    };
</script>

<script lang="ts">
    import AppHead from '@/components/AppHead.svelte';

    let {
        ownerCounts,
        propertyCount,
        tenantCount,
    }: {
        ownerCounts: { active: number; suspended: number };
        propertyCount: number;
        tenantCount: number;
    } = $props();
</script>

<AppHead title="Dasbor Platform" />

<main class="space-y-8 p-4 md:p-8">
    <header class="space-y-2">
        <p class="text-sm font-medium text-muted-foreground">KostKita</p>
        <h1 class="text-3xl font-semibold tracking-tight">Dasbor platform</h1>
        <p class="max-w-2xl text-sm text-muted-foreground">Ringkasan agregat platform. Halaman ini tidak membuka rincian transaksi atau data operasional tenant.</p>
    </header>

    <section aria-label="Statistik platform" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <article class="rounded-xl border bg-card p-5 shadow-sm"><p class="text-sm text-muted-foreground">Pemilik aktif</p><p class="mt-2 text-3xl font-semibold">{ownerCounts.active}</p></article>
        <article class="rounded-xl border bg-card p-5 shadow-sm"><p class="text-sm text-muted-foreground">Pemilik ditangguhkan</p><p class="mt-2 text-3xl font-semibold">{ownerCounts.suspended}</p></article>
        <article class="rounded-xl border bg-card p-5 shadow-sm"><p class="text-sm text-muted-foreground">Tenant terdaftar</p><p class="mt-2 text-3xl font-semibold">{tenantCount}</p></article>
        <article class="rounded-xl border bg-card p-5 shadow-sm"><p class="text-sm text-muted-foreground">Properti aktif</p><p class="mt-2 text-3xl font-semibold">{propertyCount}</p></article>
    </section>
</main>
