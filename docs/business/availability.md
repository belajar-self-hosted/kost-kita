# Ketersediaan Kamar

## Aturan inti

Ketersediaan adalah hasil pemeriksaan jadwal, bukan kolom status yang diubah manual. Untuk rentang tanggal yang diminta, backend memeriksa:

- status operasional kamar;
- masa sewa yang masih memblokir rentang;
- booking yang statusnya memblokir rentang;
- perpanjangan masa sewa;
- rentang maintenance yang membuat kamar tidak dapat digunakan.

Kamar yang kosong pada hari ini belum tentu tersedia untuk rentang masa sewa di masa depan. UI harus menampilkan ketersediaan untuk tanggal yang ditanyakan.

## Makna tanggal dan overlap

Representasi internal yang tercatat adalah interval setengah terbuka: start termasuk, end tidak termasuk, ditulis sebagai [start_date, end_date). Dengan aturan ini, satu masa sewa dapat berakhir saat masa berikutnya mulai tanpa dianggap bentrok.

Dokumen sumber menyebut tanggal check-out yang dipahami sebagai hari terakhir penghuni masih berada di kamar. Backend harus menormalkan tanggal tersebut secara konsisten menjadi batas akhir eksklusif. Untuk definisi internal tadi, dua interval overlap bila:

existing.start < requested.end AND existing.end > requested.start

Validasi tanggal harus menolak rentang kosong atau terbalik. Perubahan rentang booking atau perpanjangan harus menjalankan pemeriksaan yang sama.

## Status yang memblokir

- Booking confirmed memblokir periode yang dicadangkan.
- Booking pending tidak memblokir sebagai baseline.
- Booking cancelled dan NO_SHOW tidak memblokir.
- Booking checked_in memakai periode tenancy aktual sebagai pemblokir; status booking tidak menjadi pengganti record tenancy.
- Tenancy scheduled memblokir periode yang disepakati.
- Tenancy active tetap memblokir kamar setelah tanggal akhir rencana sampai pemilik mencatat check-out aktual. Tanggal akhir rencana tidak otomatis membuka kamar.
- Setelah check-out dicatat, gunakan tanggal keluar aktual yang dinormalisasi menjadi batas akhir eksklusif untuk menghentikan pemblokiran.
- Rentang maintenance yang ditetapkan untuk kamar memblokir seluruh tanggal dalam rentang tersebut.
- Kamar yang tidak operasional tidak tersedia meskipun tidak ada booking atau tenancy.

Booking confirmed memblokir sampai check-in, pembatalan, atau expiry no-show. Setelah deadline terlewati, booking tidak boleh terus memblokir hanya karena Scheduler belum memperbarui status: backend merekonsiliasi booking ke NO_SHOW sebelum menyelesaikan pemeriksaan availability. Grace period, scheduler, dan perpanjangan manual diatur di [Booking dan Masa Sewa](booking-tenancy.md). Scheduler dan perubahan availability harus mengunci/memvalidasi ulang jadwal agar tidak membuat dua booking confirmed yang overlap.

## Proteksi request bersamaan

Pengecekan sebelum menyimpan booking tidak cukup karena request lain dapat masuk pada saat yang sama. Operasi pembuatan atau perubahan pemblokir kamar harus:

1. memulai transaksi database;
2. mengunci atau menggunakan strategi kontrol konkurensi yang sesuai untuk kamar/periode;
3. mengecek ulang overlap terhadap data terbaru;
4. menyimpan perubahan hanya bila periode masih tersedia;
5. melakukan rollback penuh bila validasi gagal.

Alur selesai bila dua request serentak untuk kamar dan periode overlap tidak dapat sama-sama berhasil.

## Status kamar dan okupansi

Pisahkan status operasional (misalnya dapat disewakan atau maintenance) dari okupansi pada tanggal tertentu. Jangan menyimpan satu status “kosong/terisi” sebagai satu-satunya sumber availability.

Untuk metrik contoh, occupancy rate saat ini dihitung sebagai:

Kamar terhuni / kamar yang dapat disewakan × 100%

Kamar maintenance dikeluarkan dari penyebut contoh tersebut. Definisi ini perlu digunakan sama di dasbor dan laporan; bila kebijakan metrik berubah, ubah satu definisi ini.

Detail card kamar ada di [Kebutuhan UI](../ux/requirements.md). Definisi tenancy dan booking ada di [Booking dan Masa Sewa](booking-tenancy.md).
