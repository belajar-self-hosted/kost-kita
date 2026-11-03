# Dokumentasi Kost Management System

## Status dan aturan penggunaan

Dokumen ini adalah indeks untuk kebutuhan produk dan aturan aplikasi. Dua dokumen lama telah dipindahkan ke arsip; gunakan hanya file yang ditautkan di bawah sebagai acuan aktif.

Status: baseline hasil konsolidasi dari dokumen sumber dan keputusan wawancara. Keputusan yang telah dikonfirmasi dan pertanyaan baru tercatat di [Keputusan Wawancara](decisions/open-questions.md); saat ini tidak ada keputusan pengguna yang menunggu jawaban. Jangan menetapkan sendiri jawaban yang mengubah perilaku produk, hak akses, data keuangan, atau pilihan teknologi. Keputusan final setelah dikonfirmasi pengguna atau didelegasikan secara eksplisit oleh pengguna, lalu dicatat di dokumen yang berwenang.

Jika aturan aktif dan pertanyaan terbuka tampak bertentangan, pertanyaan terbuka menjelaskan bagian yang belum boleh dianggap final. Jika sebuah pekerjaan bergantung pada keputusan itu, selesaikan bagian independen terlebih dahulu lalu minta keputusan sebelum menerapkan bagian yang terdampak. Alasannya: pilihan diam-diam dapat menyebabkan akses data, jadwal kamar, atau angka keuangan yang salah.

## Urutan baca

1. [Ringkasan Produk](product/overview.md) — tujuan, pengguna, dan asumsi produk.
2. [Cakupan Fitur](product/scope.md) — modul yang masuk MVP dan batas rilis.
3. [Arsitektur Sistem](architecture/overview.md) — batas komponen dan integrasi aplikasi.
4. Jika mengubah deployment atau ingress, baca [HOMELAB-SSOT](deployment/HOMELAB-SSOT.md) dan [ADR-004](decisions/ADR-004-proxmox-docker-deployment.md) untuk baseline serta keputusan arsitektur; gunakan [Panduan Deployment KostKita](deployment/DEPLOYMENT-GUIDE.md) untuk urutan provisioning, build, smoke test, dan go-live.
5. Baca aturan bisnis yang sesuai tugas:
   - [Ketersediaan Kamar](business/availability.md)
   - [Booking dan Masa Sewa](business/booking-tenancy.md)
   - [Keuangan dan Laporan](business/finance.md)
   - [Perawatan dan Biaya Operasional](business/maintenance.md)
6. Baca [Model Data](data/model.md) dan [Skema Relasional MVP](data/relational-schema.md) untuk relasi fisik; baca [Kontrak Route/Action](architecture/route-actions.md) untuk operasi Laravel. Baca juga [Keamanan dan Akses](security/access-control.md), [Kebutuhan UI](ux/requirements.md), dan [Strategi Kualitas](quality/testing.md) sebelum mengubah area tersebut.
7. [Roadmap](plan/roadmap.md) mengatur urutan kerja. Keputusan wawancara tercatat di [Keputusan Wawancara](decisions/open-questions.md).

## Sumber kebenaran per topik

| Topik | Dokumen yang berwenang |
|---|---|
| Tujuan dan asumsi produk | product/overview.md |
| Cakupan dan fase fitur | product/scope.md |
| Periode dan ketersediaan kamar | business/availability.md |
| Booking, check-in, check-out, dan perpanjangan | business/booking-tenancy.md |
| Pembayaran, saldo, arus kas, dan laporan | business/finance.md |
| Servis dan biaya operasional | business/maintenance.md |
| Batas sistem dan teknologi | architecture/overview.md, decisions/ADR-001-frontend-integration.md |
| Route, action, validasi, otorisasi, dan respons Inertia | architecture/route-actions.md |
| Deployment KostKita dan integrasi homelab | decisions/ADR-004-proxmox-docker-deployment.md; deployment/HOMELAB-SSOT.md adalah acuan bersama untuk ingress, routing publik, CI/CD, dan provisioning layanan |
| Langkah deployment dan go-live KostKita | deployment/DEPLOYMENT-GUIDE.md |
| Keputusan basis laporan MVP | decisions/ADR-002-financial-reporting-basis.md |
| Perlakuan deposit jaminan dan settlement | business/finance.md, decisions/ADR-003-security-deposit-settlement.md |
| Invariant domain data | data/model.md |
| Skema relasional, ownership, FK, indeks, dan constraint | data/relational-schema.md |
| Role, autentikasi, dan isolasi tenant | security/access-control.md |
| Navigasi dan perilaku antarmuka | ux/requirements.md |
| Verifikasi kualitas | quality/testing.md |
| Urutan dan kriteria selesai fase | plan/roadmap.md |

Satu aturan hanya didefinisikan di dokumen pemilik topiknya. Dokumen lain boleh menautkannya, tetapi tidak menyalin atau mengubah maknanya. Arsip di archive/ hanya menyimpan bahan historis dan tidak menjadi sumber requirement.

## Aturan kerja agent

- Sebelum mengubah fitur, baca dokumen pemilik topik dan pertanyaan terbuka terkait. Pekerjaan selesai bila aturan yang disentuh dan dependensinya telah diperiksa.
- Backend menjadi sumber kebenaran untuk validasi, izin, status, ketersediaan, dan perhitungan uang. Ini mencegah data klien menghasilkan hasil yang tidak konsisten atau melewati kontrol akses.
- Setiap perubahan data tenant harus memeriksa ownership di backend. Jangan menerima tenant atau property ID dari klien sebagai bukti hak akses.
- Operasi booking yang mengubah ketersediaan dan operasi keuangan harus atomik serta aman terhadap request bersamaan. Perubahan selesai bila tidak meninggalkan record parsial atau transaksi ganda.
- Pertahankan riwayat masa sewa dan jejak transaksi. Gunakan koreksi yang terlacak untuk transaksi salah; penghapusan tidak boleh menghilangkan histori finansial.
- Jangan memperluas scope atau memilih teknologi yang belum diputuskan. Catat kebutuhan keputusan di open-questions.md dan minta konfirmasi sebelum mengunci perilaku atau stack.
- Sebelum implementasi modul, pastikan flow, skema relasional, kontrak route/action, hak akses, dan kriteria verifikasinya tersedia di dokumen terkait. Ini mengurangi keputusan bisnis tersembunyi di kode.
- Sebelum mengaktifkan deployment publik, penuhi gerbang operasional di [Keputusan Wawancara](decisions/open-questions.md); dokumen homelab mendeskripsikan target, tetapi tidak membuktikan bahwa gateway, runner, backup, atau inventaris layanan sudah diterapkan.

## Arsip

Dokumen sumber awal tersedia di [archive/](archive/README.md) untuk pelacakan asal materi. Jangan gunakan arsip untuk menyelesaikan konflik; gunakan baseline aktif dan daftar keputusan terbuka.
