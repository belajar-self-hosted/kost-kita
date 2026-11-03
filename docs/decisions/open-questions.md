# Keputusan Wawancara

## Status

Tidak ada keputusan produk atau arsitektur yang sedang menunggu jawaban pengguna per 3 November 2026. Pertanyaan implementasi yang belum dirinci dapat diputuskan agent selama mematuhi dokumen canonical dan mencatat ADR untuk perubahan arsitektur besar.

## Keputusan yang sudah dikonfirmasi

Daftar ini adalah indeks keputusan wawancara, bukan sumber aturan terpisah. Dokumen pada kolom sumber adalah acuan canonical untuk aturan rinci.

| Topik | Keputusan | Sumber canonical |
|---|---|---|
| Aktor/login | Hanya Pemilik Kost dan Super Admin login pada MVP; penyewa tidak mengakses aplikasi dan staf belum memiliki role login. | [Keamanan dan Akses](../security/access-control.md), [Cakupan Fitur](../product/scope.md) |
| Booking dan komunikasi penyewa | Pemilik mencatat booking setelah kesepakatan melalui WhatsApp; pending tidak menahan kamar, confirmed menahan periode, dan no-show mengikuti grace period yang telah ditetapkan. Pengingat aplikasi hanya tampil di dashboard pemilik. | [Booking dan Masa Sewa](../business/booking-tenancy.md), [Keuangan dan Laporan](../business/finance.md) |
| Unit sewa MVP | Bulanan saja; billing cycle mengikuti tanggal check-in dengan anchor kalender asli. | [Cakupan Fitur](../product/scope.md), [Keuangan dan Laporan](../business/finance.md) |
| Prioritas tarif | Tarif khusus kamar meng-override tarif tipe bila keduanya berlaku; resolver memakai tanggal mulai booking/tenancy atau tanggal efektif perpanjangan, dan pemilik menyimpan harga kesepakatan sebagai snapshot. | [Booking dan Masa Sewa](../business/booking-tenancy.md) |
| Check-out awal | Prorata memakai hari kalender aktual; kamar tetap terpakai sampai pemilik mencatat check-out. | [Booking dan Masa Sewa](../business/booking-tenancy.md), [Keuangan dan Laporan](../business/finance.md) |
| DP dan deposit jaminan | Satu kewajiban dan liability, termasuk saat DP diterima ketika booking. Overpayment baru ditolak; kelebihan dana yang sudah diterima di luar aplikasi direkonsiliasi sebagai temporary liability. | [Keuangan dan Laporan](../business/finance.md), [ADR-003](ADR-003-security-deposit-settlement.md) |
| Settlement deposit | Pemilik menyetujui settlement yang didukung kesepakatan, jatuh tempo, bukti, dan audit; batas 7/30 hari adalah default produk. | [ADR-003](ADR-003-security-deposit-settlement.md) |
| Basis laporan | Basis kas operasional dengan label Arus Kas Bersih; outstanding invoice dan liability deposit ditampilkan terpisah. | [ADR-002](ADR-002-financial-reporting-basis.md), [Keuangan dan Laporan](../business/finance.md) |
| Data identitas | Nomor KTP atau identitas resmi penyewa tidak dikumpulkan/disimpan pada MVP. | [Model Data](../data/model.md) |
| Stack aplikasi | Laravel + Inertia + Svelte/TypeScript; pilihan frontend didelegasikan kepada agent dan dicatat pada ADR. | [Arsitektur Sistem](../architecture/overview.md), [ADR-001](ADR-001-frontend-integration.md) |
| Hosting dan akses | Home server Proxmox VE, LXC dan Docker Compose; akses publik memakai domain dan HTTPS melalui gateway ingress homelab. | [ADR-004](ADR-004-proxmox-docker-deployment.md), [HOMELAB-SSOT](../deployment/HOMELAB-SSOT.md) |
| Pengamanan ingress bersama | Target upgrade Traefik `v3.7.17`; routing hanya melalui File Provider tanpa Docker Provider/socket; forwarded headers dipercaya dari subnet cloudflared yang dibatasi. Rollout gateway belum diverifikasi. | [HOMELAB-SSOT](../deployment/HOMELAB-SSOT.md), [ADR-004](ADR-004-proxmox-docker-deployment.md) |
| Database | PostgreSQL produksi berada pada Compose di LXC aplikasi KostKita; SQLite lokal/test; port PostgreSQL tidak dipublikasikan. Ini pilihan khusus aplikasi walau contoh homelab memakai VM 200. | [Arsitektur Sistem](../architecture/overview.md), [ADR-004](ADR-004-proxmox-docker-deployment.md) |
| Perawatan | Jadwal maintenance yang memblokir kamar memakai rentang mulai-selesai. | [Perawatan dan Biaya Operasional](../business/maintenance.md), [Ketersediaan Kamar](../business/availability.md) |
| Harga perpanjangan | Tarif baru berlaku mulai siklus tagihan berikutnya; jika dicatat tepat di batas siklus, berlaku untuk siklus itu. Perubahan tarif di tengah siklus tidak diprorata. | [Booking dan Masa Sewa](../business/booking-tenancy.md), [Keuangan dan Laporan](../business/finance.md) |
| Konflik jadwal maintenance | Jadwal maintenance yang memblokir ditolak jika overlap booking confirmed atau tenancy scheduled/active; pekerjaan tetap dilaporkan tanpa jadwal blocking. | [Perawatan dan Biaya Operasional](../business/maintenance.md) |
| Snapshot saldo invoice | Laporan invoice untuk tanggal lampau menghitung outstanding per akhir periode dari invoice, adjustment, kas yang benar-benar terjadi, dan settlement non-kas hingga tanggal itu; pembayaran setelahnya tidak mengubah snapshot. | [Keuangan dan Laporan](../business/finance.md) |

## Gerbang operasional sebelum go-live

Ini bukan keputusan produk yang menunggu pengguna, melainkan verifikasi lapangan yang belum dapat dinyatakan selesai dari dokumentasi saja:

- verifikasi gateway aktif sudah memakai target Traefik `v3.7.17` dan tidak lagi memberi Traefik akses Docker socket;
- tetapkan subnet `web-gateway` yang hanya berisi Traefik dan cloudflared lalu validasi perlakuan forwarded headers;
- audit LXC KostKita yang dinyatakan sudah dibuat, lalu cocokkan ID/IP dengan nilai yang dipilih; setelah runtime dan route diterapkan, catat port HTTP serta file Dynamic Config aktual pada inventaris layanan homelab;
- verifikasi `.dockerignore`, hak workflow runner, serta backup/restore PostgreSQL sebelum mengaktifkan hostname publik.

Jika muncul kebutuhan baru yang mengubah aturan bisnis, akses, data, atau teknologi, catat pertanyaannya di sini lalu perbarui dokumen pemilik topik setelah dikonfirmasi.
