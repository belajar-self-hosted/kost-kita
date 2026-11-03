# Perawatan dan Biaya Operasional

## Riwayat perawatan

Catat kerusakan dan servis per properti, dengan hubungan ke kamar bila relevan. Perawatan fasilitas umum dapat tidak memiliki kamar tertentu.

Informasi yang dicatat:

- properti dan kamar opsional;
- kategori servis, seperti AC, kamar mandi, listrik, air, pintu, pengecatan, fasilitas umum, atau lainnya;
- tanggal servis dan rentang jadwal mulai-selesai bila pekerjaan belum dilakukan;
- deskripsi kerusakan dan pekerjaan;
- biaya;
- nama teknisi/vendor opsional;
- status pengerjaan;
- catatan tambahan.

Status pekerjaan yang tercatat meliputi dilaporkan, dijadwalkan, dan selesai. Catatan historis dipertahankan agar kerusakan berulang dan biaya per properti/kamar dapat ditinjau.

Jika kamar tidak dapat digunakan karena maintenance, rentang maintenance membuat kamar tidak tersedia pada periode tersebut. Simpan rentang internal sebagai interval setengah terbuka yang sama dengan [aturan ketersediaan kamar](availability.md); tanggal akhir yang ditampilkan kepada pengguna tetap dipahami sebagai hari terakhir kamar tidak tersedia.

Jadwal maintenance yang memblokir kamar harus ditolak bila rentangnya bertabrakan dengan booking `confirmed` atau tenancy `scheduled`/`active`. Simpan pekerjaan tetap sebagai `reported` agar pemilik dapat memilih jadwal lain atau mencatat pekerjaan yang tidak memblokir kamar; jangan menyimpan jadwal blocking yang membuat room reservation dan maintenance mengklaim periode yang sama.

## Biaya servis dan arus kas

Catatan biaya servis tidak otomatis berarti kas sudah keluar. Sistem mencatat cash outflow hanya pada tanggal pembayaran yang benar-benar dilakukan. Satu biaya tidak boleh dikirim ke laporan lebih dari sekali.

Model hubungan antara pekerjaan servis, kewajiban, dan pembayaran ditetapkan di [Model Data](../data/model.md). Jika biaya dibayar beberapa kali atau perlu koreksi, gunakan aturan alokasi dan reversal pada [Keuangan dan Laporan](finance.md).

## Biaya operasional

Di luar servis, pemilik perlu mencatat pengeluaran seperti listrik, air, internet, kebersihan, gaji penjaga, perlengkapan, administrasi, dan kategori lain.

Setiap pengeluaran memiliki properti, tanggal transaksi/pembayaran, kategori, nominal, metode pembayaran, deskripsi, dan referensi kamar opsional. Hanya kas yang sudah dibayar masuk perhitungan arus kas keluar.
