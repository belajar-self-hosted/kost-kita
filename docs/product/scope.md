# Cakupan Fitur

## Status

Pemilik menetapkan rilis pertama mencakup semua modul utama, termasuk laporan. Daftar di bawah adalah scope MVP. Urutan implementasinya ditentukan pada [Roadmap](../plan/roadmap.md).

## Kemampuan produk

| Area | Kemampuan yang tercatat | Detail aturan |
|---|---|---|
| Dasbor pemilik | Ringkasan properti/kamar, okupansi, pengingat jatuh tempo, akhir masa sewa, aktivitas terbaru, dan metrik keuangan | [UI](../ux/requirements.md), [keuangan](../business/finance.md) |
| Properti dan kamar | Kelola beberapa properti, detail properti, kamar, fasilitas, tipe kamar, harga, dan status operasional | [Model data](../data/model.md), [ketersediaan](../business/availability.md) |
| Harga | Harga per kamar atau tipe dengan periode bulanan; perubahan tidak menulis ulang kesepakatan lama | [Booking dan masa sewa](../business/booking-tenancy.md) |
| Booking | Catat calon penyewa, periode, harga kesepakatan, status, uang muka; expiry NO_SHOW otomatis setelah grace period; konversi ke check-in | [Booking dan masa sewa](../business/booking-tenancy.md) |
| Penyewa dan masa sewa | Simpan identitas penyewa terpisah dari riwayat masa sewa; dukung check-in langsung, check-out, dan perpanjangan | [Booking dan masa sewa](../business/booking-tenancy.md) |
| Keuangan | Tagihan bulanan otomatis, pembayaran penuh/parsial, saldo, satu liability deposit jaminan yang dapat dibayar saat booking, refund/settlement, biaya servis, dan biaya operasional | [Keuangan](../business/finance.md), [perawatan](../business/maintenance.md) |
| Perawatan | Riwayat kerusakan dan servis, jadwal, biaya, status pengerjaan, vendor, serta hubungan opsional ke kamar | [Perawatan](../business/maintenance.md) |
| Laporan | Ringkasan kas, rincian kas masuk/keluar, masa sewa, maintenance, booking, serta saldo tagihan; ekspor XLSX dan PDF disebutkan | [Keuangan](../business/finance.md) |
| Akun platform | Registrasi dan login pemilik, pemulihan kata sandi, verifikasi email, serta administrasi platform oleh Super Admin | [Keamanan dan Akses](../security/access-control.md) |
| Pengelola | Akun staf dengan akses terbatas | Tidak termasuk login MVP; role MVP hanya Super Admin dan Pemilik. |

## User flow yang menjadi baseline

1. **Onboarding pemilik:** daftar akun, isi profil properti, masukkan kamar dan harga, lalu mulai dari dasbor.
2. **Booking:** pemilik dan calon penyewa menyepakati ketentuan di luar aplikasi; pemilik memeriksa ketersediaan lalu mencatat booking.
3. **Check-in:** pemilik membuat masa sewa dari booking atau mencatat check-in langsung.
4. **Pembayaran:** pemilik mencatat penerimaan; saldo tagihan berubah mengikuti nominal pembayaran yang dialokasikan.
5. **Perpanjangan:** masa sewa diperpanjang setelah konflik booking masa depan diperiksa.
6. **Check-out:** pemilik menutup masa sewa tanpa menghapus histori; kamar tersedia sesuai jadwal dan status operasional.
7. **Perawatan dan laporan:** biaya yang dibayar masuk ke laporan kas satu kali; pemilik memfilter dan mengekspor laporan.

Aturan rinci untuk tiap flow hanya didefinisikan di dokumen bisnis yang ditautkan pada tabel.

## Prioritas dan batas rilis

MVP mencakup properti dan kamar, booking, penyewa/masa sewa, pembayaran/perpanjangan, maintenance, biaya operasional, dasbor, pengingat dalam aplikasi, laporan serta ekspor XLSX/PDF, dan fungsi platform Super Admin yang tercatat. Definisi role login ada pada [Keamanan dan Akses](../security/access-control.md).

Login Pengelola tidak masuk MVP. Pengelolaan staf dapat dipertimbangkan sebagai rilis lanjutan setelah hak aksesnya dirancang dan disetujui.
