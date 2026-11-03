<script module lang="ts">
    export const layout = { breadcrumbs: [{ title: 'Penerimaan', href: '/owner/payments' }, { title: 'Rincian transfer', href: '#' }] };
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';

    type Allocation = {
        id: number;
        invoice_id: number;
        invoice_number: string | null;
        kind: string;
        amount_rupiah: number;
        refundable_rupiah: number;
        available_credit_rupiah: number;
        credit_adjustments: { id: number; amount_rupiah: number; reason: string }[];
    };
    type Movement = { id: number; deposit_account_id: number; resident: string | null; kind: string; amount_rupiah: number };
    type Evidence = { id: number; filename: string; mime_type: string };
    type Payment = {
        id: number;
        direction: string;
        category: string;
        amount_rupiah: number;
        occurred_on: string;
        method: string;
        external_reference: string | null;
        description: string | null;
        recorded_by: string | null;
        reversal_of_id: number | null;
        invoice_allocations: Allocation[];
        deposit_movements: Movement[];
        evidence: Evidence[];
    };

    let { payment, idempotency_key }: { payment: Payment; idempotency_key: string } = $props();
    const money = (value: number) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value);
</script>

<AppHead title={`Transfer ${payment.id}`} />
<main class="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-8">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div class="space-y-2">
            <p class="text-sm font-medium text-muted-foreground">Keuangan · {payment.category}</p>
            <h1 class="text-3xl font-semibold tracking-tight">{money(payment.amount_rupiah)}</h1>
            <p class="text-sm text-muted-foreground">Transfer {payment.direction === 'in' ? 'masuk' : 'keluar'} pada {payment.occurred_on} lewat {payment.method}</p>
        </div>
        <Link href="/owner/payments" class="inline-flex rounded-md border px-4 py-2 text-sm">Riwayat penerimaan</Link>
    </header>

    <section class="grid gap-4 sm:grid-cols-2">
        <article class="rounded-xl border bg-card p-5">
            <h2 class="font-semibold">Transfer</h2>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Referensi</dt><dd>{payment.external_reference ?? '—'}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Dicatat oleh</dt><dd>{payment.recorded_by ?? '—'}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Transaksi asal</dt><dd>{payment.reversal_of_id ?? '—'}</dd></div>
            </dl>
            {#if payment.description}<p class="mt-3 border-t pt-3 text-sm text-muted-foreground">{payment.description}</p>{/if}
        </article>
        <article class="rounded-xl border bg-card p-5">
            <h2 class="font-semibold">Alokasi sewa</h2>
            {#if payment.invoice_allocations.length === 0}
                <p class="mt-3 text-sm text-muted-foreground">Tidak ada alokasi invoice.</p>
            {:else}
                <div class="mt-3 divide-y">
                    {#each payment.invoice_allocations as allocation (allocation.id)}
                        <Link href={`/owner/invoices/${allocation.invoice_id}`} class="flex justify-between py-3 text-sm">
                            <span>{allocation.invoice_number ?? `Invoice ${allocation.invoice_id}`} · {allocation.kind}</span><span>{money(allocation.amount_rupiah)}</span>
                        </Link>
                    {/each}
                </div>
            {/if}
        </article>
    </section>

    <section class="rounded-xl border bg-card p-5">
        <h2 class="font-semibold">Pergerakan deposit</h2>
        {#if payment.deposit_movements.length === 0}
            <p class="mt-3 text-sm text-muted-foreground">Tidak ada pergerakan deposit pada transfer ini.</p>
        {:else}
            <div class="mt-3 divide-y">
                {#each payment.deposit_movements as movement (movement.id)}
                    <Link href={`/owner/deposits/${movement.deposit_account_id}`} class="flex justify-between gap-4 py-3 text-sm">
                        <span>{movement.resident ?? 'Penyewa'} · {movement.kind}</span><span>{money(movement.amount_rupiah)}</span>
                    </Link>
                {/each}
            </div>
        {/if}
    </section>

    {#if payment.evidence.length > 0}
        <section class="rounded-xl border bg-card p-5">
            <h2 class="font-semibold">Bukti transaksi</h2>
            <div class="mt-3 flex flex-wrap gap-2">
                {#each payment.evidence as evidence (evidence.id)}
                    <a href={`/owner/deposit-evidence/${evidence.id}`} class="rounded-md border px-3 py-1.5 text-sm text-primary underline-offset-4 hover:underline">Buka bukti · {evidence.filename}</a>
                {/each}
            </div>
        </section>
    {/if}

    {#if payment.direction === 'in'}
        <section class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold">Catat refund sewa aktual</h2>
                <p class="text-sm text-muted-foreground">Catat setelah transfer dilakukan. Setiap refund memerlukan credit adjustment dan bukti transfer privat.</p>
            </div>
            {#each payment.invoice_allocations as allocation (allocation.id)}
                {#if allocation.kind === 'allocation' && allocation.refundable_rupiah > 0 && allocation.available_credit_rupiah > 0 && allocation.credit_adjustments.length > 0}
                    <Form action={`/owner/payments/${payment.id}/refunds`} method="post" class="grid gap-3 rounded-xl border bg-card p-5 sm:grid-cols-2" enctype="multipart/form-data">
                        {#snippet children({ errors, processing })}
                            <input type="hidden" name="idempotency_key" value={idempotency_key} />
                            <input type="hidden" name="allocation_id" value={allocation.id} />
                            <div class="sm:col-span-2">
                                <h3 class="font-medium">{allocation.invoice_number ?? `Invoice ${allocation.invoice_id}`}</h3>
                                <p class="text-sm text-muted-foreground">Sisa alokasi: {money(allocation.refundable_rupiah)} · credit invoice tersedia: {money(allocation.available_credit_rupiah)}</p>
                            </div>
                            <div class="space-y-2">
                                <label for={`refund_amount_${allocation.id}`} class="text-sm font-medium">Nominal refund</label>
                                <Input id={`refund_amount_${allocation.id}`} name="amount_rupiah" type="number" min={1} max={Math.min(allocation.refundable_rupiah, allocation.available_credit_rupiah)} step={1} required />
                                <InputError message={errors.amount_rupiah} /><InputError message={errors.allocations} />
                            </div>
                            <div class="space-y-2">
                                <label for={`refund_credit_${allocation.id}`} class="text-sm font-medium">Credit adjustment</label>
                                <select id={`refund_credit_${allocation.id}`} name="credit_adjustment_id" required class="h-10 w-full rounded-md border bg-background px-3 text-sm">
                                    {#each allocation.credit_adjustments as adjustment (adjustment.id)}<option value={adjustment.id}>#{adjustment.id} · {money(adjustment.amount_rupiah)} · {adjustment.reason}</option>{/each}
                                </select>
                                <InputError message={errors.credit_adjustment_id} />
                            </div>
                            <div class="space-y-2">
                                <label for={`refund_date_${allocation.id}`} class="text-sm font-medium">Tanggal transfer</label>
                                <Input id={`refund_date_${allocation.id}`} name="occurred_on" type="date" required />
                                <InputError message={errors.occurred_on} />
                            </div>
                            <div class="space-y-2">
                                <label for={`refund_method_${allocation.id}`} class="text-sm font-medium">Metode</label>
                                <select id={`refund_method_${allocation.id}`} name="method" required class="h-10 w-full rounded-md border bg-background px-3 text-sm">
                                    <option value="bank_transfer">Transfer bank</option><option value="cash">Tunai</option><option value="e_wallet">Dompet digital</option><option value="other">Lainnya</option>
                                </select>
                            </div>
                            <div class="space-y-2">
                                <label for={`refund_ref_${allocation.id}`} class="text-sm font-medium">Referensi transfer</label>
                                <Input id={`refund_ref_${allocation.id}`} name="external_reference" maxlength={160} required />
                            </div>
                            <div class="space-y-2">
                                <label for={`refund_reason_${allocation.id}`} class="text-sm font-medium">Alasan</label>
                                <Input id={`refund_reason_${allocation.id}`} name="reason" minlength={3} maxlength={1000} required />
                                <InputError message={errors.reason} />
                            </div>
                            <div class="space-y-2 sm:col-span-2">
                                <label for={`refund_evidence_${allocation.id}`} class="text-sm font-medium">Bukti transfer privat</label>
                                <input id={`refund_evidence_${allocation.id}`} name="evidence[]" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" multiple required class="block w-full rounded-md border bg-background p-2 text-sm" />
                                <InputError message={errors.evidence} /><InputError message={errors['evidence.0']} />
                            </div>
                            <div class="sm:col-span-2 flex justify-end"><Button type="submit" variant="outline" disabled={processing}>{processing ? 'Mencatat…' : 'Catat refund aktual'}</Button></div>
                        {/snippet}
                    </Form>
                {/if}
            {/each}
        </section>
    {/if}
</main>
