# ADR-003: Deposit Jaminan dan Settlement

- Status: accepted
- Tanggal: 2026-11-03
- Pemilik keputusan: pengguna mendelegasikan kebijakan settlement kepada agent berdasarkan best practice

## Konteks

Pemilik menetapkan DP booking dan security deposit sebagai satu kewajiban. Deposit diterima sebelum atau saat check-in, tetap menjadi liability hingga direfund atau diselesaikan, dan tidak boleh otomatis dikembalikan atau dihanguskan ketika booking batal atau menjadi NO_SHOW. MVP hanya menyediakan login Pemilik Kost dan Super Admin; settlement operasional dilakukan oleh pemilik melalui aplikasi dan komunikasi WhatsApp.

## Keputusan

1. DP booking dan security deposit adalah nama untuk kewajiban yang sama, bukan dua saldo. Pembayaran awal pada booking tetap menjadi satu deposit ketika booking dikonversi menjadi tenancy.
2. Deposit hanya boleh dikurangi untuk (a) kewajiban booking/tenancy yang sudah jatuh tempo dan tercatat, termasuk sewa/utilitas atau biaya pembatalan bila ditagihkan dan diizinkan oleh kesepakatan, atau (b) kerusakan yang disebabkan penyewa di luar keausan wajar dengan biaya perbaikan aktual yang wajar dan terdokumentasi.
3. Jangan mendeduct keausan wajar, maintenance rutin pemilik, biaya arbitrer/tanpa bukti, atau kerugian masa depan yang belum terjadi.
4. Untuk setiap pengurangan, simpan perincian item, dasar kesepakatan, hasil inspeksi keluar, foto sebelum/sesudah bila relevan, invoice/struk atau estimasi tertulis yang wajar, nilai, aktor, persetujuan pemilik, waktu, dan jejak pemberitahuan kepada penyewa melalui WhatsApp. Persetujuan/acknowledgement penyewa tidak menjadi syarat mengembalikan saldo yang tidak disengketakan.
5. Pemilik adalah satu-satunya approver pada MVP. Sistem tidak melakukan refund, forfeiture, atau deduction otomatis saat check-out, pembatalan, maupun NO_SHOW.
6. Hitung batas dalam hari kalender Asia/Jakarta; hari setelah check-out aktual atau pembatalan/NO_SHOW booking menjadi hari ke-1. Kirim pernyataan settlement sementara yang terperinci dan refund saldo yang tidak disengketakan paling lambat akhir hari ke-7. Bila ada klaim kerusakan atau sengketa tertentu yang didukung bukti, tahan hanya jumlah yang wajar; refund bagian lain paling lambat hari ke-7. Finalkan klaim tertahan paling lambat akhir hari ke-30 berdasarkan biaya aktual dan bukti final. Jika biaya aktual/bukti final belum tersedia pada hari ke-30, refund jumlah tertahan dan tandai settlement terlambat.
7. Refund adalah cash outflow dan pengurangan liability. Pemakaian deposit untuk menyelesaikan invoice adalah settlement non-kas: kurangi liability dan saldo invoice dalam satu transaksi, simpan movement/audit, dan jangan buat cash event atau mengubah Arus Kas Bersih. Kelebihan penerimaan eksternal mengikuti temporary-liability policy di [Keuangan dan Laporan](../business/finance.md).
8. Batas 7 dan 30 hari adalah default kebijakan produk yang direkomendasikan; ADR ini bukan kajian hukum dan tidak menetapkan tenggat hukum. Ketentuan perjanjian sewa dan aturan setempat yang berlaku tetap harus dipatuhi.

## Trade-off

Bukti per item dan pemberitahuan memberi dasar yang dapat ditinjau kedua pihak, tetapi menambah pencatatan bagi pemilik. Batas waktu cepat mengurangi ketidakpastian bagi penyewa; pengecualian hingga 30 hari hanya berlaku untuk jumlah yang memiliki klaim spesifik dan bukti pendukung. Settlement non-kas dilaporkan terpisah agar laporan arus kas tidak menghitung ulang uang yang sudah diterima saat deposit dibayar.

## Referensi

- [DJKN Kementerian Keuangan — Perlukah Uang Jaminan dalam Pemanfaatan Barang Milik Negara?](https://www.djkn.kemenkeu.go.id/kpknl-batam/baca-artikel/18243/Perlukah-Uang-Jaminan-Dalam-Pemanfaatan-Barang-Milik-Negara.html) membahas uang jaminan untuk pemanfaatan BMN: dapat menutup kewajiban dan perbaikan yang ditetapkan, serta kelebihan dikembalikan. Referensi ini hanya contoh desain untuk tata kelola deposit pada aset publik; bukan aturan yang berlaku langsung bagi rumah kost privat dan bukan dasar tenggat 7/30 hari.

Aturan bisnis aktif dan spesifikasi ledger berada di [Keuangan dan Laporan](../business/finance.md); skenario verifikasi berada di [Strategi Kualitas](../quality/testing.md).
