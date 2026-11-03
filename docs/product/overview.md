# Ringkasan Produk

## Produk

Kost Management System adalah aplikasi web SaaS untuk membantu pemilik usaha kost mengelola beberapa properti dan seluruh aktivitas sewa dalam satu tempat. Aplikasi ditujukan agar nyaman digunakan melalui ponsel, tablet, maupun komputer.

## Masalah yang ditangani

Pemilik kost dapat kehilangan jejak pembayaran, tanggal jatuh tempo, masa sewa, booking, perbaikan, dan biaya operasional ketika informasi tersebar di buku, chat, atau ingatan. Aplikasi mengumpulkan data operasional tersebut dan menyajikan kondisi kamar serta arus kas berdasarkan catatan yang sama.

## Tujuan

- Mengelola properti, kamar, penyewa, booking, masa sewa, pembayaran, dan perawatan.
- Menampilkan jadwal ketersediaan kamar berdasarkan booking, masa sewa, dan status operasional.
- Membantu pemilik melihat tagihan yang perlu ditindaklanjuti dan masa sewa yang mendekati akhir.
- Menyediakan laporan pemasukan, pengeluaran, dan arus kas yang dapat difilter serta diunduh.
- Melindungi data setiap pemilik dari akses pemilik lain.

## Pengguna dan istilah

- **Pemilik kost** adalah pengguna yang memiliki satu atau beberapa properti dan mengelola datanya.
- **Super Admin** adalah pengelola platform yang memerlukan fungsi administrasi lintas akun.
- **Pengelola/staf** bukan role login pada MVP.
- **Penyewa/penghuni** adalah orang yang menyewa kamar. Aturan akun dan pencatatannya ditetapkan di [Keamanan dan Akses](../security/access-control.md) serta [Booking dan Masa Sewa](../business/booking-tenancy.md).
- **Tenant aplikasi** berarti batas data milik seorang pemilik kost, bukan orang yang menyewa kamar.

MVP hanya menyediakan login untuk Pemilik kost dan Super Admin. Detail hak akses ada pada [Keamanan dan Akses](../security/access-control.md).

## Asumsi produk yang tercatat

- Bahasa antarmuka: Indonesia.
- Mata uang: Rupiah (IDR).
- Zona waktu operasional awal: Asia/Jakarta.
- Satu pemilik dapat mengelola beberapa properti.
- Data antarpemilik harus terisolasi.
- Kesepakatan sewa dilakukan di luar aplikasi; dokumen operasional menyebut WhatsApp sebagai kanal komunikasi pemilik dan calon penyewa.
- Aplikasi berfokus pada pencatatan dan pengelolaan operasional oleh pemilik kost.

Asumsi di atas berasal dari bahan produk yang ada. Perubahan terhadapnya harus dicatat sebagai keputusan, bukan disimpulkan dari implementasi.

## Kualitas yang dituju

Aplikasi harus menjaga keamanan data tenant, keutuhan transaksi keuangan, konsistensi aturan jadwal, struktur yang mudah dikembangkan per fase, dan antarmuka responsif. Kriteria verifikasi rinci ada pada [Keamanan dan Akses](../security/access-control.md), [Ketersediaan Kamar](../business/availability.md), [Keuangan dan Laporan](../business/finance.md), serta [Strategi Kualitas](../quality/testing.md).

## Batas yang sudah disebutkan

- Backend harus memvalidasi aturan bisnis penting; UI hanya membantu pengguna memasukkan dan memahami data.

Ruang lingkup MVP ada di [Cakupan Fitur](scope.md).
