<script module lang="ts">
    export const layout = { breadcrumbs: [{ title: 'Penyewa', href: '/owner/residents' }, { title: 'Ubah kontak', href: '#' }] };
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';

    type Resident = { id: number; full_name: string; whatsapp_number: string; contact_info: string | null; notes: string | null; archived_at: string | null };
    let { resident }: { resident: Resident } = $props();
</script>

<AppHead title={`Ubah ${resident.full_name}`} />
<main class="mx-auto w-full max-w-3xl space-y-6 p-4 md:p-8">
    <header class="space-y-2"><p class="text-sm font-medium text-muted-foreground">Kontak penyewa</p><h1 class="text-3xl font-semibold tracking-tight">Ubah {resident.full_name}</h1><p class="text-sm text-muted-foreground">Perubahan kontak tidak mengubah histori booking atau masa sewa.</p></header>
    <Form action={`/owner/residents/${resident.id}`} method="put" class="space-y-5 rounded-xl border bg-card p-5 shadow-sm">
        {#snippet children({ errors, processing })}
            <div class="space-y-2"><label for="full_name" class="text-sm font-medium">Nama lengkap</label><Input id="full_name" name="full_name" value={resident.full_name} required maxlength={160} autocomplete="name" /><InputError message={errors.full_name} /></div>
            <div class="space-y-2"><label for="whatsapp_number" class="text-sm font-medium">Nomor WhatsApp</label><Input id="whatsapp_number" name="whatsapp_number" type="tel" value={resident.whatsapp_number} required maxlength={32} autocomplete="tel" /><InputError message={errors.whatsapp_number} /></div>
            <div class="space-y-2"><label for="contact_info" class="text-sm font-medium">Kontak tambahan</label><Input id="contact_info" name="contact_info" value={resident.contact_info ?? ''} maxlength={255} /><InputError message={errors.contact_info} /></div>
            <div class="space-y-2"><label for="notes" class="text-sm font-medium">Catatan</label><textarea id="notes" name="notes" rows={4} maxlength={2000} class="w-full rounded-md border bg-background px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring">{resident.notes ?? ''}</textarea><InputError message={errors.notes} /></div>
            <div class="flex justify-end gap-3 border-t pt-4"><Link href={`/owner/residents/${resident.id}`} class="inline-flex items-center rounded-md border px-4 py-2 text-sm">Batal</Link><Button type="submit" disabled={processing}>{processing ? 'Menyimpan…' : 'Simpan perubahan'}</Button></div>
        {/snippet}
    </Form>
</main>
