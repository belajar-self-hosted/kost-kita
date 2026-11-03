<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            { title: 'Kamar', href: '#' },
            { title: 'Tambah kamar', href: '#' },
        ],
    };
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';

    let { property, roomTypes }: { property: { id: number; name: string }; roomTypes: { id: number; name: string }[] } = $props();
    let facilityFields = $state<string[]>(['']);

    function addFacility() {
        facilityFields = [...facilityFields, ''];
    }

    function removeFacility(index: number) {
        facilityFields = facilityFields.filter((_, itemIndex) => itemIndex !== index);
        if (facilityFields.length === 0) facilityFields = [''];
    }
</script>

<AppHead title="Tambah kamar" />

<main class="mx-auto w-full max-w-3xl space-y-6 p-4 md:p-8">
    <header class="space-y-2"><p class="text-sm font-medium text-muted-foreground">{property.name}</p><h1 class="text-3xl font-semibold tracking-tight">Tambah kamar</h1><p class="text-sm text-muted-foreground">Kepemilikan properti dan tenant ditentukan server.</p></header>
    <Form action={`/owner/properties/${property.id}/rooms`} method="post" class="space-y-5 rounded-xl border bg-card p-5 shadow-sm">
        {#snippet children({ errors, processing })}
            <div class="space-y-2"><label for="code" class="text-sm font-medium">Kode kamar <span aria-hidden="true">*</span></label><Input id="code" name="code" required maxlength={80} placeholder="Contoh: A-01" /><InputError message={errors.code} /></div>
            <div class="space-y-2"><label for="room_type_id" class="text-sm font-medium">Tipe kamar</label><select id="room_type_id" name="room_type_id" class="h-10 w-full rounded-md border bg-background px-3 text-sm"><option value="">Tanpa tipe</option>{#each roomTypes as roomType (roomType.id)}<option value={roomType.id}>{roomType.name}</option>{/each}</select><InputError message={errors.room_type_id} /></div>
            <div class="space-y-2"><label for="operational_status" class="text-sm font-medium">Status operasional</label><select id="operational_status" name="operational_status" class="h-10 w-full rounded-md border bg-background px-3 text-sm"><option value="rentable">Dapat disewakan</option><option value="out_of_service">Tidak beroperasi</option></select><InputError message={errors.operational_status} /></div>
            <div class="space-y-2"><label for="description" class="text-sm font-medium">Keterangan</label><textarea id="description" name="description" rows={3} maxlength={2000} class="w-full rounded-md border bg-background px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring" placeholder="Catatan tentang kamar"></textarea><InputError message={errors.description} /></div>
            <fieldset class="space-y-3"><legend class="text-sm font-medium">Fasilitas</legend>{#each facilityFields as _, index (index)}<div class="flex gap-2"><Input name="facilities[]" bind:value={facilityFields[index]} maxlength={100} placeholder="Contoh: AC" /><Button type="button" variant="outline" size="sm" onclick={() => removeFacility(index)} aria-label="Hapus fasilitas">Hapus</Button></div>{/each}<InputError message={errors.facilities} /><InputError message={errors['facilities.0']} /><Button type="button" variant="outline" size="sm" onclick={addFacility}>Tambah fasilitas</Button></fieldset>
            <div class="flex justify-end gap-3 border-t pt-4"><Link href={`/owner/properties/${property.id}/rooms`} class="inline-flex items-center rounded-md border px-4 py-2 text-sm">Batal</Link><Button type="submit" disabled={processing}>{processing ? 'Menyimpan…' : 'Simpan kamar'}</Button></div>
        {/snippet}
    </Form>
</main>
