# Booking dan Masa Sewa

## Booking

### Proses pencatatan

Kesepakatan dilakukan oleh pemilik dan calon penyewa di luar aplikasi; dokumen operasional menyebut WhatsApp. Setelah kesepakatan, pemilik memasukkan booking ke aplikasi. Status akun penyewa dijelaskan di [Keamanan dan Akses](../security/access-control.md).

DP booking adalah pembayaran awal atas kewajiban deposit jaminan yang sama, bukan saldo terpisah. Jika booking dibatalkan atau menjadi NO_SHOW, penyewa menghubungi pemilik melalui WhatsApp. Pemilik menyelesaikan settlement berdasarkan [aturan deposit](finance.md#deposit-jaminan-security-deposit), lalu mencatat refund atau deduction yang benar-benar terjadi. Sistem tidak otomatis mengembalikan atau menghanguskan deposit.

Sebelum menyimpan booking, backend memvalidasi ownership properti/kamar, rentang tanggal, status operasional, dan konflik jadwal. Detail overlap dan konkurensi hanya didefinisikan di [Ketersediaan Kamar](availability.md).

### Tarif kamar dan snapshot harga

Tarif katalog dapat ditetapkan pada tipe kamar sebagai tarif default atau pada kamar tertentu sebagai pengecualian. Untuk mengisi nilai awal, resolusi booking memakai tanggal rencana mulai; check-in langsung memakai tanggal mulai tenancy; perpanjangan memakai tanggal efektif perpanjangan. Jika tarif tipe dan tarif khusus kamar sama-sama berlaku pada tanggal efektif itu, gunakan tarif khusus kamar. Jika tidak ada tarif khusus kamar yang berlaku, gunakan tarif tipe sebagai nilai awal; jika katalog tidak memiliki tarif yang berlaku, pemilik tetap mencatat harga kesepakatan aktual secara manual pada booking/check-in.

Saat booking atau check-in disepakati, simpan tarif bulanan sebagai snapshot pada catatan kesepakatan. Perubahan tarif katalog hanya berlaku untuk kesepakatan baru; perpanjangan dapat memakai harga baru yang disepakati dan disimpan sebagai snapshot peristiwa perpanjangan.

### Informasi booking

Catatan booking memuat:

- properti dan kamar;
- nama serta nomor WhatsApp calon penyewa;
- tanggal masuk yang direncanakan dan rentang sewa;
- harga yang disepakati;
- status booking;
- catatan opsional;
- alasan pembatalan opsional, maksimal 500 karakter, disimpan pada booking dan dicatat pada audit saat pembatalan;
- bila ada pembayaran deposit jaminan: nominal, metode, dan tanggal penerimaan; pembayaran saat booking dan check-in masuk ke liability deposit yang sama;
- batas grace period check-in untuk booking confirmed; perubahan manual dicatat di audit trail.

Aturan pemilihan tarif dan snapshot ini juga berlaku pada check-in langsung dan perpanjangan.

### Status booking

| Status | Arti dan dampak |
|---|---|
| pending | Dicatat tetapi belum dikonfirmasi; tidak memblokir ketersediaan pada baseline. |
| confirmed | Disepakati dan memblokir periode booking. |
| checked_in | Booking dikonversi menjadi tenancy; tenancy aktual menjadi sumber periode pemblokir. |
| cancelled | Dibatalkan dan tidak memblokir. Settlement deposit dibicarakan melalui WhatsApp dan dicatat oleh pemilik sesuai [kebijakan deposit](finance.md#deposit-jaminan-security-deposit). |
| NO_SHOW | Booking confirmed yang belum check-in saat grace period berakhir; tidak memblokir kamar dan tidak otomatis mengembalikan atau menghanguskan deposit. |

Konversi booking menjadi check-in harus atomik: validasi ulang jadwal, buat tenancy, hubungkan ke booking, pertahankan liability deposit yang sama tanpa membuat kewajiban atau kas masuk kedua kali, lalu ubah status booking.

### Transisi booking

| Dari | Ke | Syarat |
|---|---|---|
| pending | confirmed | Pemilik mengonfirmasi setelah menyepakati booking. |
| pending | cancelled | Pemilik membatalkan; status tidak lagi memblokir. |
| confirmed | checked_in | Check-in berhasil dibuat atomik sebelum waktu expiry; backend memeriksa ulang deadline walau Scheduler terlambat. |
| confirmed | cancelled | Pemilik membatalkan secara eksplisit; settlement deposit dibicarakan melalui WhatsApp dan dicatat sesuai transaksi aktual tanpa menghapus bukti penerimaan awal. |
| confirmed | NO_SHOW | Scheduler atau operasi backend yang menemukan deadline terlewati mengubah status secara atomik; check-in setelah expiry ditolak. |

Status cancelled, NO_SHOW, dan checked_in tidak kembali ke status sebelumnya. Perubahan setelah itu dibuat sebagai tindakan baru yang terlacak.

### Grace period dan no-show

Default grace period adalah satu hari kalender setelah tanggal rencana check-in. Jika check-in direncanakan pada 10 November 2026, grace period berakhir pada akhir 11 November; booking berubah menjadi NO_SHOW pada 12 November 2026 pukul 00.00 Asia/Jakarta bila check-in belum dicatat.

Laravel Scheduler menjalankan pemeriksaan dengan kondisi idempotent dan concurrency-safe. Dalam transaksi, kunci booking yang akan diproses, baca ulang status dan batas waktu, lalu ubah hanya booking confirmed yang sudah melewati batas. Semua operasi check-in juga memvalidasi deadline ini di backend. Jika Scheduler terlambat dan operasi mendapati deadline sudah lewat, backend menolak check-in dan merekonsiliasi booking menjadi NO_SHOW secara atomik. Menjalankan job berulang kali tidak boleh membuat transisi atau transaksi finansial ganda.

Pemilik dapat memperpanjang grace period secara manual sebelum waktu expiry. Simpan aktor, waktu, nilai lama/baru, dan alasan di audit trail; tolak perpanjangan bila bentrok dengan periode booking atau tenancy lain. Perpanjangan tidak mengubah tanggal rencana check-in atau sewa tanpa tindakan terpisah.

Perubahan ke NO_SHOW hanya mengubah status booking dan ketersediaannya. Perubahan ini tidak otomatis mengembalikan atau menghanguskan deposit. Setiap refund, deduction, atau settlement dilakukan melalui alur finansial terpisah yang berizin dan dapat diaudit.

## Penyewa dan masa sewa

Identitas penyewa dan kontrak masa sewa adalah dua konsep berbeda. Satu identitas penyewa dapat mempunyai beberapa masa sewa historis. Check-out menutup masa sewa dan mempertahankan histori, bukan menghapus penyewa.

Data identitas dapat berisi nama, nomor WhatsApp, kontak, dan catatan. Masa sewa menghubungkan penyewa dengan kamar dan menyimpan tanggal mulai/akhir, harga yang disepakati, ketentuan pembayaran, status, serta riwayat perpanjangan.

Contoh status tenancy yang tercatat:

- scheduled — masa sewa telah dibuat dan belum dimulai;
- active — masa sewa sedang berlangsung;
- completed — masa sewa selesai sesuai rencana;
- terminated — masa sewa dihentikan lebih awal.

Status harus konsisten dengan tanggal dan tindakan pengguna. Tanggal akhir masa sewa berbeda dari jatuh tempo pembayaran. Periode sewa bulanan mengikuti tanggal check-in aktual; tagihan pertama dibuat saat check-in dan tagihan berikutnya dibuat otomatis setiap siklus bulanan. Tenancy langsung yang dijadwalkan ke tanggal mendatang belum memiliki siklus tagihan sampai pemilik mencatat check-in aktual. Siklus invoice, jatuh tempo, serta prorata mengikuti [aturan Keuangan dan Laporan](finance.md). Pembayaran parsial termasuk kebutuhan MVP.

### Transisi masa sewa

| Dari | Ke | Syarat |
|---|---|---|
| scheduled | active | Check-in aktual terjadi. |
| scheduled | terminated | Masa sewa dibatalkan sebelum mulai dengan tindakan eksplisit. |
| active | completed | Pemilik mencatat check-out; tenancy tetap active dan kamar tetap terpakai setelah tanggal akhir sampai check-out dicatat. |
| active | terminated | Pemilik mencatat penghentian lebih awal; tagihan dihitung prorata berdasarkan hari kalender aktual dalam siklus. |

completed dan terminated menutup masa sewa; buat masa sewa baru untuk penyewaan berikutnya agar histori tetap utuh. Lewatnya tanggal akhir saja tidak menyelesaikan tenancy atau membuka kamar; pemilik perlu mencatat check-out aktual.

Pembatalan tenancy `scheduled` menyimpan instant `terminated_at` UTC dan tidak mengisi `actual_checkout_date`, karena penghuni belum memakai kamar. Instant ini menjadi peristiwa awal settlement deposit; batas hari settlement dihitung pada kalender Asia/Jakarta sesuai [aturan keuangan](finance.md#deposit-jaminan-security-deposit).

## Check-in

Pemilik dapat membuat check-in langsung tanpa booking. Data mencakup properti/kamar, identitas penyewa, tanggal masuk dan akhir masa sewa, harga yang disepakati, informasi pembayaran awal bila ada, tanggal jatuh tempo, dan catatan.

Untuk check-in dari booking, booking dan tenancy harus saling terhubung agar histori serta uang muka tetap dapat ditelusuri.

## Perpanjangan

Perpanjangan menyimpan tanggal akhir baru, harga/tagihan yang disepakati, pembayaran bila diterima, dan jatuh tempo pembayaran. Backend harus memeriksa konflik dengan booking confirmed setelah masa sewa saat ini.

Jika ada konflik, hentikan penyimpanan dan tampilkan booking yang bertabrakan. Perpanjangan tidak boleh menimpa booking pihak lain secara otomatis; perubahan booking harus melalui tindakan eksplisit yang tercatat.

Jika pemilik menyepakati tarif bulanan baru saat memperpanjang, tarif baru berlaku mulai batas siklus tagihan bulanan berikutnya. Bila perpanjangan dicatat tepat pada tanggal mulai siklus, tarif baru berlaku untuk siklus tersebut. Invoice siklus yang sedang berjalan mempertahankan snapshot tarif lama; perubahan tarif saja tidak menghasilkan prorata di tengah siklus. Aturan ini tidak mengubah perhitungan prorata untuk check-out lebih awal.

## Dashboard kamar

Kartu kamar untuk tanggal pilihan dapat menunjukkan:

- kamar yang tersedia beserta tipe, harga, detail, booking, dan check-in;
- kamar dihuni beserta identitas penyewa, tanggal, sisa masa sewa, status pembayaran, pembayaran, perpanjangan, dan check-out;
- kamar kosong saat ini tetapi memiliki booking confirmed di masa depan;
- kamar yang tidak tersedia karena maintenance.

Format durasi harus menghitung tanggal kalender sebenarnya, bukan menganggap setiap bulan terdiri dari 30 hari. Rincian tampilan ada di [Kebutuhan UI](../ux/requirements.md).
