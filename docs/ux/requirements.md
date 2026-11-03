# Kebutuhan Antarmuka

## Prinsip

Antarmuka harus jelas, responsif, berfokus pada data, dapat diakses dengan keyboard, dan memakai hierarki visual konsisten. Sediakan loading, empty, error, serta validation feedback untuk halaman yang memuat atau mengubah data.

## Navigasi pemilik

Area navigasi yang tercatat:

- Dasbor;
- Properti;
- Unit kamar;
- Tipe kamar dan harga;
- Booking;
- Penyewa dan masa sewa;
- Pembayaran dan perpanjangan;
- Servis/maintenance;
- Biaya operasional;
- Laporan dan keuangan;
- Pengaturan akun.

## Navigasi platform

Area Super Admin yang tercatat:

- Dasbor platform;
- Manajemen pemilik kost;
- Manajemen properti;
- Statistik platform;
- Audit dan monitoring;
- Pengaturan platform.

MVP tidak menyediakan navigasi atau login staf. Jika staf ditambahkan pada rilis berikutnya, navigasi mengikuti matriks izin yang disetujui di [Keamanan dan Akses](../security/access-control.md).

## Kartu kamar

Pada tanggal pilihan, kartu kamar menjelaskan kondisi yang dihitung backend:

- **Tersedia:** nomor, tipe, harga, detail, tombol booking dan check-in.
- **Dihuni:** nama dan kontak penghuni, tanggal masuk/akhir sewa, durasi, sisa masa sewa, status pembayaran, serta aksi detail, pembayaran, perpanjangan, dan check-out.
- **Kosong dengan booking masa depan:** jadwal booking berikutnya dan periode; nama calon penyewa hanya untuk aktor yang berhak.
- **Maintenance:** indikator bahwa kamar tidak dapat digunakan.

Kartu tidak boleh menyatakan kamar tersedia untuk rentang tanggal tertentu tanpa memeriksa semua pemblokir jadwal. Definisi jadwal ada di [Ketersediaan Kamar](../business/availability.md).

## Pengingat dan laporan

Pisahkan pengingat tagihan segera jatuh tempo, tagihan terlambat, dan masa sewa mendekati akhir. Setiap kategori menampilkan data tindak lanjut yang sesuai. Pengingat hanya tampil di dashboard pemilik; aplikasi tidak mengirim pesan ke penyewa pada MVP.

Dashboard menunjukkan snapshot okupansi per tanggal, arus kas bulan berjalan, serta tren arus kas enam bulan. Halaman laporan mendukung filter properti, rentang tanggal, kamar, jenis transaksi, dan status pembayaran bila relevan. Laporan booking merangkum jumlah per status dalam periode, dan laporan maintenance merangkum pembayaran kas aktual per kategori pekerjaan. Ekspor mengikuti filter aktif. Tabel besar mendukung pencarian/filter serta pagination bila volume memerlukannya.

## Interaksi yang sensitif

- Tampilkan validasi di dekat input yang salah.
- Minta konfirmasi untuk pembatalan atau tindakan yang mengubah data keuangan/jadwal.
- Setelah aksi berhasil, tampilkan status yang bersumber dari respons backend.
- Sediakan aksesibilitas semantik dan fokus keyboard.
- Pada ponsel, navigasi dan tabel tetap dapat digunakan tanpa kehilangan informasi penting.
