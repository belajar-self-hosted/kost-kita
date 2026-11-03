<script module lang="ts">
    export const layout = {
        breadcrumbs: [{ title: 'Dasbor', href: '/owner' }],
    };
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';

    type Property = { id: number; name: string; address: string | null; status: 'active' | 'archived' };
    type Activity = { id: number; action: string; subject_type: string; occurred_at: string };
    type InvoiceReminder = {
        id: number;
        invoice_number: string;
        resident_name: string;
        room_code: string;
        property_name: string;
        due_date: string;
        balance_rupiah: number;
        href: string;
    };
    type TenancyReminder = {
        id: number;
        resident_name: string;
        room_code: string;
        property_name: string;
        planned_last_day: string;
        days_remaining: number;
        href: string;
    };
    type DepositReminder = {
        settlement_id: number;
        deposit_account_id: number;
        resident_name: string;
        room_code: string;
        property_name: string;
        deadline: string;
        late_days: number;
        balance_rupiah: number;
        href: string;
    };

    let {
        ownerName,
        tenantName,
        asOfDate,
        propertyCounts,
        occupancy,
        finance,
        cashFlowTrend,
        invoiceSummary,
        reminders,
        recentProperties,
        recentActivity,
    }: {
        ownerName: string;
        tenantName: string;
        asOfDate: string;
        propertyCounts: { active: number; archived: number };
        occupancy: { occupied: number; capacity: number; available: number; maintenanceBlocked: number; occupancyPercent: number };
        finance: {
            cashIn: number;
            cashOut: number;
            netCashFlow: number;
            rentReceived: number;
            depositReceived: number;
            maintenancePaid: number;
            operationalPaid: number;
            depositRefunded: number;
            rentRefunded: number;
        };
        cashFlowTrend: { month: string; label: string; cashIn: number; cashOut: number; netCashFlow: number }[];
        invoiceSummary: { amount_rupiah: number; invoice_count: number };
        reminders: {
            dueSoon: InvoiceReminder[];
            overdue: InvoiceReminder[];
            tenanciesEnding: TenancyReminder[];
            depositRefundsPastDeadline: DepositReminder[];
            lateDepositClaims: DepositReminder[];
        };
        recentProperties: Property[];
        recentActivity: Activity[];
    } = $props();

    const rupiah = new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    });

    const dateFormat = new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeZone: 'Asia/Jakarta',
    });

    const formatRupiah = (amount: number) => rupiah.format(amount);
    const maxTrendCash = $derived(Math.max(1, ...cashFlowTrend.flatMap((month) => [month.cashIn, month.cashOut])));
    const trendBarWidth = (amount: number) => `${amount === 0 ? 0 : Math.max(1, Math.round((amount / maxTrendCash) * 100))}%`;
    const formatDate = (value: string) => dateFormat.format(new Date(`${value}T00:00:00+07:00`));
    const formatDateTime = (value: string) =>
        new Intl.DateTimeFormat('id-ID', {
            dateStyle: 'medium',
            timeStyle: 'short',
            timeZone: 'Asia/Jakarta',
        }).format(new Date(value));

    const activityLabels: Record<string, string> = {
        'owner.registered': 'Akun pemilik dibuat',
        'property.created': 'Properti ditambahkan',
        'property.updated': 'Properti diperbarui',
        'property.archived': 'Properti diarsipkan',
        'room.created': 'Kamar ditambahkan',
        'room.updated': 'Kamar diperbarui',
        'booking.created': 'Booking dicatat',
        'booking.confirmed': 'Booking dikonfirmasi',
        'booking.cancelled': 'Booking dibatalkan',
        'booking.checked_in': 'Booking menjadi check-in',
        'booking.no_show': 'Booking ditandai no-show',
        'tenancy.checked_in': 'Penyewa check-in',
        'tenancy.extended': 'Masa sewa diperpanjang',
        'tenancy.checked_out': 'Penyewa check-out',
        'tenancy.terminated': 'Masa sewa dihentikan',
        'payment.received': 'Pembayaran dicatat',
        'expense.created': 'Biaya dicatat',
        'expense.payment_recorded': 'Pembayaran biaya dicatat',
        'maintenance.reported': 'Perawatan dilaporkan',
        'maintenance.scheduled': 'Perawatan dijadwalkan',
        'deposit.settlement_approved': 'Settlement deposit disetujui',
    };
</script>

<AppHead title="Dasbor Pemilik" />

<main class="space-y-8 p-4 md:p-8">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div class="space-y-2">
            <p class="text-sm font-medium text-muted-foreground">{tenantName}</p>
            <h1 class="text-3xl font-semibold tracking-tight">Selamat datang, {ownerName}</h1>
            <p class="text-sm text-muted-foreground">Ringkasan operasional per {formatDate(asOfDate)}.</p>
        </div>
        <Link href="/owner/reports" class="inline-flex rounded-md border px-4 py-2 text-sm font-medium hover:bg-muted">Buka laporan</Link>
    </header>

    <section aria-label="Ringkasan operasional" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <article class="rounded-xl border bg-card p-5 shadow-sm">
            <p class="text-sm text-muted-foreground">Properti aktif</p>
            <p class="mt-2 text-3xl font-semibold">{propertyCounts.active}</p>
            <p class="mt-1 text-xs text-muted-foreground">{propertyCounts.archived} properti diarsipkan</p>
        </article>
        <article class="rounded-xl border bg-card p-5 shadow-sm">
            <p class="text-sm text-muted-foreground">Okupansi kamar</p>
            <p class="mt-2 text-3xl font-semibold">{occupancy.occupancyPercent}%</p>
            <p class="mt-1 text-xs text-muted-foreground">{occupancy.occupied} dari {occupancy.capacity} kamar siap disewakan</p>
        </article>
        <article class="rounded-xl border bg-card p-5 shadow-sm">
            <p class="text-sm text-muted-foreground">Kamar kosong</p>
            <p class="mt-2 text-3xl font-semibold">{occupancy.available}</p>
            <p class="mt-1 text-xs text-muted-foreground">{occupancy.maintenanceBlocked} kamar sedang diblokir maintenance</p>
        </article>
        <article class="rounded-xl border bg-card p-5 shadow-sm">
            <p class="text-sm text-muted-foreground">Tagihan belum lunas</p>
            <p class="mt-2 text-xl font-semibold">{formatRupiah(invoiceSummary.amount_rupiah)}</p>
            <p class="mt-1 text-xs text-muted-foreground">{invoiceSummary.invoice_count} invoice masih bersaldo</p>
        </article>
    </section>

    <section aria-label="Arus kas bulan ini" class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-xl font-semibold">Arus kas bulan ini</h2>
                <p class="text-sm text-muted-foreground">Berdasarkan kas aktual sejak {formatDate(asOfDate.slice(0, 7) + '-01')} sampai hari ini.</p>
            </div>
            <Link href="/owner/reports/cash-flow" class="text-sm font-medium text-primary underline-offset-4 hover:underline">Lihat laporan kas</Link>
        </div>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <article class="rounded-xl border bg-card p-5 shadow-sm">
                <p class="text-sm text-muted-foreground">Kas masuk</p>
                <p class="mt-2 text-2xl font-semibold">{formatRupiah(finance.cashIn)}</p>
                <p class="mt-1 text-xs text-muted-foreground">Termasuk penerimaan deposit sebagai liability</p>
            </article>
            <article class="rounded-xl border bg-card p-5 shadow-sm">
                <p class="text-sm text-muted-foreground">Kas keluar</p>
                <p class="mt-2 text-2xl font-semibold">{formatRupiah(finance.cashOut)}</p>
                <p class="mt-1 text-xs text-muted-foreground">Pengeluaran aktual dan refund</p>
            </article>
            <article class="rounded-xl border bg-card p-5 shadow-sm">
                <p class="text-sm text-muted-foreground">Arus Kas Bersih (Net Cash Flow)</p>
                <p class="mt-2 text-2xl font-semibold">{formatRupiah(finance.netCashFlow)}</p>
                <p class="mt-1 text-xs text-muted-foreground">Kas masuk dikurangi kas keluar</p>
            </article>
        </div>
        <section aria-label="Tren arus kas enam bulan" class="space-y-4 rounded-xl border bg-card p-5 shadow-sm">
            <div>
                <h3 class="font-semibold">Tren arus kas bulanan</h3>
                <p class="text-sm text-muted-foreground">Enam bulan terakhir sampai {formatDate(asOfDate)}. Deposit tercatat sebagai kas masuk liability.</p>
            </div>
            <div class="space-y-4">
                {#each cashFlowTrend as month (month.month)}
                    <article class="space-y-2">
                        <div class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                            <strong>{month.label}</strong>
                            <span class="text-muted-foreground">Arus kas bersih {formatRupiah(month.netCashFlow)}</span>
                        </div>
                        <div class="grid grid-cols-[3rem_minmax(0,1fr)_auto] items-center gap-2 text-xs">
                            <span>Masuk</span>
                            <div class="h-2 overflow-hidden rounded-full bg-muted" role="img" aria-label={`Kas masuk ${month.label}: ${formatRupiah(month.cashIn)}`}>
                                <div class="h-full rounded-full bg-emerald-600" style={`width: ${trendBarWidth(month.cashIn)}`}></div>
                            </div>
                            <span class="text-right tabular-nums">{formatRupiah(month.cashIn)}</span>
                            <span>Keluar</span>
                            <div class="h-2 overflow-hidden rounded-full bg-muted" role="img" aria-label={`Kas keluar ${month.label}: ${formatRupiah(month.cashOut)}`}>
                                <div class="h-full rounded-full bg-rose-500" style={`width: ${trendBarWidth(month.cashOut)}`}></div>
                            </div>
                            <span class="text-right tabular-nums">{formatRupiah(month.cashOut)}</span>
                        </div>
                    </article>
                {/each}
            </div>
        </section>
        <div class="grid gap-3 rounded-xl border bg-card p-4 sm:grid-cols-2 lg:grid-cols-4">
            <p class="text-sm">Penerimaan sewa <strong class="block">{formatRupiah(finance.rentReceived)}</strong></p>
            <p class="text-sm">Deposit diterima <strong class="block">{formatRupiah(finance.depositReceived)}</strong></p>
            <p class="text-sm">Maintenance dibayar <strong class="block">{formatRupiah(finance.maintenancePaid)}</strong></p>
            <p class="text-sm">Biaya operasional <strong class="block">{formatRupiah(finance.operationalPaid)}</strong></p>
            <p class="text-sm">Refund deposit <strong class="block">{formatRupiah(finance.depositRefunded)}</strong></p>
            <p class="text-sm">Refund sewa <strong class="block">{formatRupiah(finance.rentRefunded)}</strong></p>
        </div>
        <p class="text-xs text-muted-foreground">Arus kas bersih adalah laporan kas operasional, bukan laporan laba-rugi akuntansi.</p>
    </section>

    <section aria-label="Pengingat" class="space-y-5">
        <div>
            <h2 class="text-xl font-semibold">Perlu ditindaklanjuti</h2>
            <p class="text-sm text-muted-foreground">Pengingat ini hanya tampil untuk pemilik dan tidak mengirim pesan kepada penyewa.</p>
        </div>
        <div class="grid gap-5 xl:grid-cols-2">
            <section class="rounded-xl border bg-card p-5 shadow-sm">
                <div class="flex items-center justify-between gap-3"><h3 class="font-semibold">Jatuh tempo dalam 7 hari</h3><span class="rounded-full border px-2.5 py-1 text-xs">{reminders.dueSoon.length}</span></div>
                {#if reminders.dueSoon.length === 0}<p class="mt-3 text-sm text-muted-foreground">Tidak ada tagihan yang segera jatuh tempo.</p>{:else}<div class="mt-2 divide-y">
                {#each reminders.dueSoon as invoice (invoice.id)}
                    <Link href={invoice.href} class="flex items-start justify-between gap-4 py-3 text-sm hover:text-primary">
                        <span><strong class="block">{invoice.resident_name} · {invoice.room_code}</strong><span class="text-muted-foreground">{invoice.property_name} · {invoice.invoice_number} · jatuh tempo {formatDate(invoice.due_date)}</span></span>
                        <strong class="shrink-0">{formatRupiah(invoice.balance_rupiah)}</strong>
                    </Link>
                {/each}
                </div>{/if}
            </section>

            <section class="rounded-xl border bg-card p-5 shadow-sm">
                <div class="flex items-center justify-between gap-3"><h3 class="font-semibold">Tagihan terlambat</h3><span class="rounded-full border px-2.5 py-1 text-xs">{reminders.overdue.length}</span></div>
                {#if reminders.overdue.length === 0}<p class="mt-3 text-sm text-muted-foreground">Tidak ada tagihan yang terlambat.</p>{:else}<div class="mt-2 divide-y">
                {#each reminders.overdue as invoice (invoice.id)}
                    <Link href={invoice.href} class="flex items-start justify-between gap-4 py-3 text-sm hover:text-primary">
                        <span><strong class="block">{invoice.resident_name} · {invoice.room_code}</strong><span class="text-muted-foreground">{invoice.property_name} · {invoice.invoice_number} · sejak {formatDate(invoice.due_date)}</span></span>
                        <strong class="shrink-0">{formatRupiah(invoice.balance_rupiah)}</strong>
                    </Link>
                {/each}
                </div>{/if}
            </section>

            <section class="rounded-xl border bg-card p-5 shadow-sm">
                <div class="flex items-center justify-between gap-3"><h3 class="font-semibold">Masa sewa berakhir dalam 30 hari</h3><span class="rounded-full border px-2.5 py-1 text-xs">{reminders.tenanciesEnding.length}</span></div>
                {#if reminders.tenanciesEnding.length === 0}<p class="mt-3 text-sm text-muted-foreground">Tidak ada masa sewa yang perlu diperiksa.</p>{:else}<div class="mt-2 divide-y">
                {#each reminders.tenanciesEnding as tenancy (tenancy.id)}
                    <Link href={tenancy.href} class="flex items-start justify-between gap-4 py-3 text-sm hover:text-primary">
                        <span><strong class="block">{tenancy.resident_name} · {tenancy.room_code}</strong><span class="text-muted-foreground">{tenancy.property_name} · akhir sewa {formatDate(tenancy.planned_last_day)}</span></span>
                        <span class="shrink-0 text-muted-foreground">{tenancy.days_remaining < 0 ? `Lewat ${Math.abs(tenancy.days_remaining)} hari` : `${tenancy.days_remaining} hari lagi`}</span>
                    </Link>
                {/each}
                </div>{/if}
            </section>

            <section class="rounded-xl border bg-card p-5 shadow-sm">
                <div class="flex items-center justify-between gap-3"><h3 class="font-semibold">Refund deposit melewati batas hari ke-7</h3><span class="rounded-full border px-2.5 py-1 text-xs">{reminders.depositRefundsPastDeadline.length}</span></div>
                {#if reminders.depositRefundsPastDeadline.length === 0}<p class="mt-3 text-sm text-muted-foreground">Tidak ada refund deposit yang tertunda melewati batas.</p>{:else}<div class="mt-2 divide-y">
                {#each reminders.depositRefundsPastDeadline as refund (`refund-${refund.settlement_id}-${refund.deposit_account_id}`)}
                    <Link href={refund.href} class="flex items-start justify-between gap-4 py-3 text-sm hover:text-primary">
                        <span><strong class="block">{refund.resident_name} · {refund.room_code}</strong><span class="text-muted-foreground">{refund.property_name} · batas {formatDate(refund.deadline)} · lewat {refund.late_days} hari</span></span>
                        <strong class="shrink-0">{formatRupiah(refund.balance_rupiah)}</strong>
                    </Link>
                {/each}
                </div>{/if}
            </section>

            <section class="rounded-xl border bg-card p-5 shadow-sm">
                <div class="flex items-center justify-between gap-3"><h3 class="font-semibold">Klaim deposit melewati batas hari ke-30</h3><span class="rounded-full border px-2.5 py-1 text-xs">{reminders.lateDepositClaims.length}</span></div>
                {#if reminders.lateDepositClaims.length === 0}<p class="mt-3 text-sm text-muted-foreground">Tidak ada klaim deposit yang perlu difinalkan.</p>{:else}<div class="mt-2 divide-y">
                {#each reminders.lateDepositClaims as claim (`claim-${claim.settlement_id}`)}
                    <Link href={claim.href} class="flex items-start justify-between gap-4 py-3 text-sm hover:text-primary">
                        <span><strong class="block">{claim.resident_name} · {claim.room_code}</strong><span class="text-muted-foreground">{claim.property_name} · batas {formatDate(claim.deadline)} · lewat {claim.late_days} hari</span></span>
                        <strong class="shrink-0">{formatRupiah(claim.balance_rupiah)}</strong>
                    </Link>
                {/each}
                </div>{/if}
            </section>
        </div>
    </section>

    <section class="rounded-xl border bg-card p-5 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold">Properti terbaru</h2>
                <p class="text-sm text-muted-foreground">Data yang tampil hanya milik akunmu.</p>
            </div>
            <Link href="/owner/properties" class="text-sm font-medium text-primary underline-offset-4 hover:underline">Lihat semua</Link>
        </div>

        {#if recentProperties.length === 0}
            <div class="mt-5 rounded-lg border border-dashed p-6 text-center">
                <p class="font-medium">Belum ada properti</p>
                <p class="mt-1 text-sm text-muted-foreground">Tambahkan properti pertama untuk mulai menyiapkan data kost.</p>
                <Link href="/owner/properties/create" class="mt-4 inline-flex rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground">Tambah properti</Link>
            </div>
        {:else}
            <ul class="mt-5 divide-y">
                {#each recentProperties as property (property.id)}
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div>
                            <Link href={`/owner/properties/${property.id}`} class="font-medium underline-offset-4 hover:underline">{property.name}</Link>
                            <p class="text-sm text-muted-foreground">{property.address || 'Alamat belum diisi'}</p>
                        </div>
                        <span class="rounded-full border px-2.5 py-1 text-xs">{property.status === 'active' ? 'Aktif' : 'Diarsipkan'}</span>
                    </li>
                {/each}
            </ul>
        {/if}
    </section>

    <section class="rounded-xl border bg-card p-5 shadow-sm">
        <h2 class="text-lg font-semibold">Aktivitas terbaru</h2>
        {#if recentActivity.length === 0}
            <p class="mt-3 text-sm text-muted-foreground">Perubahan yang tercatat akan muncul di sini.</p>
        {:else}
            <ul class="mt-3 divide-y">
                {#each recentActivity as activity (activity.id)}
                    <li class="flex flex-wrap justify-between gap-3 py-3 text-sm">
                        <span>{activityLabels[activity.action] ?? 'Aktivitas tercatat'}</span>
                        <time class="text-muted-foreground">{formatDateTime(activity.occurred_at)}</time>
                    </li>
                {/each}
            </ul>
        {/if}
    </section>
</main>
