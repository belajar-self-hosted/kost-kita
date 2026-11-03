<script lang="ts">
    import { Link, page } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { dashboard, login, register } from '@/routes';

    const auth = $derived(page.props.auth);
</script>

<AppHead title="Kelola kost lebih rapi" />

<main class="flex min-h-screen items-center justify-center bg-muted/30 px-4 py-12">
    <section class="w-full max-w-3xl rounded-2xl border bg-card p-7 shadow-sm sm:p-12">
        <div class="flex items-center justify-between gap-4">
            <a href="/" class="text-lg font-semibold tracking-tight">KostKita</a>
            {#if auth.user}
                <Link href={dashboard()} class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground">Buka aplikasi</Link>
            {:else}
                <Link href={login()} class="rounded-md border px-4 py-2 text-sm font-medium hover:bg-muted">Masuk</Link>
            {/if}
        </div>

        <div class="mt-14 max-w-2xl space-y-6">
            <p class="text-sm font-medium text-primary">Manajemen kost untuk pemilik</p>
            <h1 class="text-4xl font-semibold tracking-tight sm:text-5xl">Kelola properti dan operasional kost dengan lebih rapi.</h1>
            <p class="max-w-xl text-base leading-7 text-muted-foreground">KostKita membantu pemilik mengelola data properti dan aktivitas penyewaan dalam satu aplikasi.</p>
            {#if auth.user}
                <Link href={dashboard()} class="inline-flex rounded-md bg-primary px-5 py-3 text-sm font-medium text-primary-foreground">Buka dasbor</Link>
            {:else}
                <div class="flex flex-wrap gap-3">
                    <Link href={register()} class="inline-flex rounded-md bg-primary px-5 py-3 text-sm font-medium text-primary-foreground">Daftar sebagai pemilik</Link>
                    <Link href={login()} class="inline-flex rounded-md border px-5 py-3 text-sm font-medium hover:bg-muted">Masuk ke akun</Link>
                </div>
            {/if}
        </div>

        <div class="mt-14 grid gap-3 border-t pt-6 text-sm text-muted-foreground sm:grid-cols-3">
            <p>Akses khusus pemilik kost dan Super Admin.</p>
            <p>Data setiap pemilik dipisahkan oleh tenant.</p>
            <p>Perubahan penting dicatat untuk audit.</p>
        </div>
    </section>
</main>
