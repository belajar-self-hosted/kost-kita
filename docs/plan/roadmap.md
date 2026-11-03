# Roadmap Implementasi

## Gerbang sebelum kode fitur

Selesaikan analisis kebutuhan, jawaban pertanyaan terbuka yang memengaruhi fitur pertama, keputusan stack, flow, model data, izin, dan strategi pengujian. Gerbang ini selesai bila keputusan dicatat di dokumen pemilik topik dan acceptance checks dapat ditelusuri. Ini mencegah perilaku bisnis penting ditentukan tanpa persetujuan.

Sebelum modul mulai dibuat, siapkan dan tinjau artefak berikut:

| Artefak | Sumber canonical | Kriteria siap |
|---|---|---|
| Requirements dan asumsi | product/overview.md, product/scope.md, decisions/open-questions.md | Cakupan modul dan keputusan yang memengaruhinya sudah jelas. |
| Arsitektur dan alur data | architecture/overview.md serta ADR bila perlu | Batas frontend/backend, autentikasi, database, dan deployment yang relevan telah disetujui. |
| ERD dan aturan integritas | data/model.md, data/relational-schema.md | Relasi fisik, tenant ownership, FK komposit, indeks, nilai IDR, dan retensi dapat ditinjau sebelum migration. |
| Role dan izin | security/access-control.md | Setiap aktor dan aksi pada modul memiliki aturan izin yang tegas. |
| Flow dan state-transition matrix | business/availability.md, business/booking-tenancy.md, business/finance.md | Transisi booking dan tenancy, hasil terhadap saldo, dampak jadwal/kas, serta konflik/error sudah ditentukan. |
| Route/action dan validasi | architecture/route-actions.md | Setiap operasi memiliki method/path, action, input, validasi, otorisasi, hasil Inertia, dan scope data sebelum coding modul. |
| Struktur halaman | ux/requirements.md | Halaman, state loading/empty/error, navigasi, dan tindakan sensitif dapat ditelusuri. |
| Acceptance dan strategi test | quality/testing.md | Setiap aturan kritis memiliki pemeriksaan yang dapat lulus/gagal. |
| Roadmap | Dokumen ini | Dependensi dan kriteria keluar fase jelas; scope MVP sudah ditetapkan di product/scope.md. |

## Urutan kerja dasar

Pemilik menetapkan semua modul utama, termasuk laporan, sebagai scope MVP. Urutan di bawah menunjukkan dependensi pengerjaan dan tidak memindahkan modul tersebut ke rilis berikutnya.

### Tahap 1 — Fondasi

- Setup proyek dan konfigurasi environment menggunakan stack yang telah ditetapkan.
- Autentikasi, role, tenant isolation, dan audit dasar.
- Layout dan pengelolaan properti.

Selesai bila aktor yang disetujui dapat masuk, properti ter-scope, dan percobaan akses lintas tenant gagal.

### Tahap 2 — Kamar, harga, dan availability

- CRUD tipe kamar, kamar, fasilitas, dan harga.
- Riwayat/snapshot harga.
- Pemeriksaan jadwal berdasarkan status operasional, booking, dan tenancy.

Selesai bila tanggal tersedia/overlap dan request bersamaan memenuhi [aturan availability](../business/availability.md).

### Tahap 3 — Booking dan masa sewa

- Booking, check-in dari booking atau langsung, penyewa/tenancy, check-out, dan perpanjangan.
- Validasi konflik dan riwayat penghuni.

Selesai bila flow di [Booking dan Masa Sewa](../business/booking-tenancy.md) berjalan tanpa menghapus histori atau menimpa booking.

### Tahap 4 — Keuangan dan pengeluaran

- Kewajiban/tagihan, alokasi payment, saldo, uang muka, koreksi/refund.
- Catatan servis dan biaya operasional yang terhubung ke kas keluar saat dibayar.

Selesai bila total konsisten, transaksi atomik, dan tidak ada pembayaran/pengeluaran ganda.

### Tahap 5 — Dasbor, pengingat, dan laporan

- Metrik occupancy dan keuangan.
- Pengingat jatuh tempo/tunggakan/akhir masa sewa.
- Laporan, filter, ekspor XLSX/PDF sesuai scope yang disetujui.

Selesai bila angka dasbor, tabel, dan ekspor cocok untuk filter yang sama serta tidak memuat data tenant lain.

### Tahap 6 — Hardening produksi

- Review keamanan, akses, konkurensi, performa, dan integritas database.
- Aksesibilitas, logging, monitoring, CI, dan persiapan deployment.

Selesai bila kriteria keamanan dan kualitas yang relevan di [Strategi Kualitas](../quality/testing.md) telah diverifikasi serta konfigurasi deployment ditinjau.

## Penentuan MVP

Daftar lengkap ada di [Cakupan Fitur](../product/scope.md). Pengingat internal termasuk scope dan tidak ada pesan eksternal ke penyewa pada MVP. Aturan deposit jaminan dan settlement tercatat di [Keuangan dan Laporan](../business/finance.md) serta [ADR-003](../decisions/ADR-003-security-deposit-settlement.md).

## Status implementasi

Per 3 November 2026, implementasi aplikasi untuk Phase 1–5 tersedia: fondasi akun/properti, katalog dan availability, booking/tenancy, finance, dashboard, lima laporan, serta ekspor. Suite SQLite lulus dengan 91 test dan 798 assertion; PHPStan, Pint, route inspection, Svelte type check, frontend build, Composer validation, dan migration `--pretend` SQLite in-memory juga lulus. Tidak ada koneksi atau migration PostgreSQL yang dijalankan pada sesi ini.

Gerbang yang masih terbuka sebelum deployment adalah PostgreSQL concurrency acceptance pada database uji khusus, verifikasi query laporan pada PostgreSQL, dan deployment/hardening di LXC sesuai [HOMELAB-SSOT](../deployment/HOMELAB-SSOT.md). Phase 6 tetap mencakup pengukuran performa pada LXC, keamanan operasional, backup/restore, logging/monitoring, dan konfigurasi ingress publik HTTPS. Lihat checklist setiap phase untuk status acceptance yang lebih rinci.
