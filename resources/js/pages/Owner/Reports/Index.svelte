<script module lang="ts">
    export const layout = { breadcrumbs: [{ title: 'Laporan', href: '/owner/reports' }] };
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';

    type Report = { key: string; title: string; description: string };
    let { reports }: { reports: Report[] } = $props();
</script>

<AppHead title="Laporan" />

<main class="space-y-6 p-4 md:p-8">
    <header>
        <p class="text-sm font-medium text-muted-foreground">Operasional KostKita</p>
        <h1 class="mt-1 text-3xl font-semibold tracking-tight">Laporan</h1>
        <p class="mt-2 text-sm text-muted-foreground">Semua angka mengikuti filter dan sumber data yang sama dengan file ekspor.</p>
    </header>

    <section aria-label="Daftar laporan" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        {#each reports as report (report.key)}
            <article class="rounded-xl border bg-card p-5 shadow-sm">
                <h2 class="text-lg font-semibold">{report.title}</h2>
                <p class="mt-2 min-h-10 text-sm text-muted-foreground">{report.description}</p>
                <Link href={`/owner/reports/${report.key}`} class="mt-5 inline-flex rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground">
                    Buka laporan
                </Link>
            </article>
        {/each}
    </section>
</main>
