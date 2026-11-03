# Keuangan dan Laporan

## Prinsip pencatatan

Pisahkan kewajiban/tagihan dari penerimaan uang. Tagihan menyimpan nominal yang harus dibayar dan jatuh tempo; payment menyimpan kas yang benar-benar diterima. Pembayaran parsial dan beberapa pembayaran untuk satu tagihan harus dapat dihitung tanpa membulatkan atau memakai floating-point.

Nominal uang menggunakan tipe fixed precision yang aman atau satuan minor integer. Operasi yang membuat/mengalokasikan pembayaran dan memperbarui saldo harus berada dalam satu transaksi database. Request berulang atau bersamaan tidak boleh membuat penerimaan ganda.

## Kewajiban dan pembayaran

MVP memakai siklus bulanan yang mengikuti hari check-in asli. Buat invoice pertama saat check-in dan invoice berikutnya pada awal setiap siklus; invoice jatuh tempo pada tanggal mulai siklus tanpa grace period otomatis. Invoice boleh diterbitkan lebih awal tanpa mengubah due date. Tandai saldo OVERDUE setelah due date lewat; kondisi overdue tidak membatalkan kontrak.

Tarif snapshot untuk setiap invoice mengikuti tarif tenancy yang berlaku pada awal siklus. Jika tarif berubah melalui perpanjangan, perubahan mulai berlaku pada batas siklus berikutnya; perubahan yang dicatat tepat pada batas siklus berlaku untuk siklus itu. Jangan membuat prorata hanya karena perubahan tarif di tengah siklus.

Hitung siklus dengan kalender, bukan periode tetap 30 hari. Jika hari anchor tidak tersedia pada suatu bulan, gunakan hari terakhir bulan itu untuk siklus tersebut dan pertahankan anchor asli agar tidak terjadi date drift. Contoh: anchor 31 menghasilkan 31 Januari → 28 Februari → 31 Maret → 30 April → 31 Mei. Generator harus idempotent: pemanggilan ulang tidak membuat invoice ganda untuk tenancy dan siklus yang sama.

Scope MVP menetapkan unit sewa bulanan saja. Jika unit harian atau tahunan disetujui untuk rilis berikutnya, hitung periodenya dengan aritmetika kalender sesuai unit dan kesepakatan, bukan asumsi periode tetap 30 atau 365 hari.

Data kewajiban dapat mencakup tenant/tenancy, periode sewa, nominal, tanggal jatuh tempo, dan status terhitung. Saldo belum dibayar berasal dari nominal kewajiban dikurangi pembayaran aktif yang dialokasikan.

Status tampilan minimal:

- belum dibayar;
- dibayar sebagian;
- lunas;
- terlambat jika masih ada saldo dan tanggal jatuh tempo sudah lewat.

Status saldo harus dihitung dari nilai kewajiban, alokasi pembayaran, dan tanggal; jangan mengandalkan label manual saja. Pembayaran parsial didukung. Nominal pembayaran baru yang melebihi sisa kewajiban ditolak. Jika kelebihan atas deposit sudah benar-benar diterima di luar aplikasi, rekonsiliasi kas mengikuti aturan temporary liability di bawah.

### Prorata check-out lebih awal

Hitung prorata menggunakan hari kalender aktual dalam siklus yang terkena check-out. Secara internal, periode siklus dan periode huni memakai interval setengah terbuka [start_date, end_date). Hitung jumlah hari overlap antara masa huni dan siklus; pembagi adalah jumlah hari aktual dalam siklus.

Prorated Amount = (Used Days / Total Cycle Days) × Cycle Rent

Gunakan decimal arithmetic. Bulatkan sekali pada nilai akhir ke Rupiah utuh terdekat dengan mode half-up. Prorata tidak otomatis menghasilkan refund; simpan invoice awal dan histori payment, lalu buat adjustment/credit note dan refund terpisah hanya bila disetujui serta dicatat.

Jika check-out terjadi dalam siklus berjalan sebelum invoice siklus itu dibuat oleh scheduler, pastikan kewajiban siklus tersedia terlebih dahulu sebelum membuat credit prorata. Invoice siklus tersebut tetap menyimpan tarif dan rentang aslinya; credit note menyimpan hari terpakai dan menurunkan saldo tanpa mengubah invoice awal.

### Transisi saldo

Saldo kewajiban mengikuti total alokasi pembayaran aktif:

| Kondisi | Status saldo |
|---|---|
| Belum ada pembayaran yang dialokasikan | belum dibayar |
| Ada alokasi, tetapi jumlahnya di bawah nominal kewajiban | dibayar sebagian |
| Jumlah alokasi sama dengan nominal kewajiban | lunas |
| Tanggal jatuh tempo lewat dan masih ada saldo | terlambat; kondisi ini dapat bersamaan dengan dibayar sebagian |

Refund/reversal mengubah alokasi aktif dan saldo secara terlacak.

Jenis penerimaan yang disebutkan:

- pembayaran sewa;
- deposit jaminan yang dibayar saat booking maupun check-in;
- pelunasan sewa;
- pembayaran perpanjangan.

Jenis kewajiban dan jenis pembayaran adalah konsep berbeda. Satu penerimaan dapat dialokasikan ke satu atau lebih kewajiban dalam tenant yang sama, termasuk split antara tagihan sewa dan deposit jaminan bila penyewa membayar keduanya dalam satu transfer. Total seluruh alokasi wajib sama dengan nominal transfer aktual dan setiap alokasi dibatasi oleh saldo kewajiban terkait. Untuk penerimaan campuran, laporan menguraikan sewa dan deposit dari allocation/liability ledger tetapi menghitung transfer parent satu kali pada total kas. Jika booking dikonversi menjadi tenancy, uang muka yang sudah diterima tetap dihitung satu kali.

## Deposit jaminan (security deposit)

Deposit jaminan adalah satu kewajiban yang sama dengan DP booking. Nama DP menjelaskan kapan uang diterima; nama deposit jaminan menjelaskan tujuan dan perlakuan saldonya. Keduanya memakai satu nominal wajib, satu saldo liability, dan satu riwayat penerimaan/settlement. Penerimaan yang benar-benar terjadi menambah kas dan liability, bukan rental revenue.

- Simpan nominal security deposit yang diwajibkan dan saldo liability-nya.
- Perubahan nominal yang disepakati sebelum pembayaran dikonfirmasi harus menyimpan aktor, waktu, nilai lama/baru, dan audit trail.
- Tolak pembayaran baru yang melebihi sisa nominal deposit yang diwajibkan, baik pembayaran tersebut dimasukkan saat booking maupun check-in.
- Jika dana berlebih sudah benar-benar diterima di luar sistem, catat seluruh kas yang diterima beserta bukti transfer privat; alokasikan bagian yang diwajibkan ke deposit liability dan catat kelebihannya sebagai temporary liability. Jangan otomatis mengubah kelebihan menjadi sewa atau kredit penyewa.
- Untuk rekonsiliasi dana eksternal yang memang melebihi sisa kewajiban deposit, alokasikan ke deposit tepat sebesar sisa kewajiban saat ini. Catat hanya nominal yang melampaui sisa itu sebagai temporary liability; jika dana aktual tidak melebihi kewajiban, gunakan pencatatan receipt normal.
- Refund deposit adalah kas keluar sekaligus pengurangan liability; jangan klasifikasikan sebagai biaya operasional.
- Deduction hanya boleh mencakup kewajiban booking/tenancy yang sudah jatuh tempo dan tercatat, sepanjang dinyatakan dalam kesepakatan tertulis, atau kerusakan yang disebabkan penyewa di luar keausan wajar dengan biaya perbaikan aktual yang wajar dan terdokumentasi.
- Jangan potong untuk keausan wajar, maintenance rutin pemilik, biaya tanpa bukti, biaya administratif arbitrer, atau kerugian masa depan yang belum terjadi.
- Setiap settlement memerlukan rincian per item, dasar kesepakatan, hasil inspeksi keluar, bukti yang relevan, notifikasi WhatsApp kepada penyewa, aktor pemilik, dan audit trail. Pemilik adalah satu-satunya approver pada MVP.
- Pemilik dapat membatalkan proposal yang masih `draft` dengan alasan sebelum approval. Simpan draft dan alasan pembatalan sebagai histori audit; tidak ada movement/cash event dari pembatalan, dan hanya draft yang sudah `cancelled` boleh digantikan proposal baru.
- Jumlah item proposal harus sama dengan seluruh saldo deposit yang masih menjadi liability saat settlement dibuka. Masukkan sisa yang tidak dipotong sebagai item refund agar satu settlement tidak meninggalkan liability tanpa jalur penyelesaian.
- Hitung batas dalam hari kalender Asia/Jakarta; hari setelah check-out aktual, pembatalan/NO_SHOW booking, atau terminasi tenancy `scheduled` menjadi hari ke-1. Untuk tenancy yang belum check-in, gunakan `terminated_at` sebagai tanggal peristiwa dan biarkan `actual_checkout_date` kosong. Kirim pernyataan settlement sementara yang terperinci dan refund saldo yang tidak disengketakan paling lambat akhir hari ke-7. Bila ada klaim spesifik yang terdokumentasi, tahan hanya nominal yang wajar dan didukung bukti, lalu refund sisanya paling lambat hari ke-7. Finalkan klaim tertahan paling lambat akhir hari ke-30 berdasarkan biaya aktual dan bukti final; bila biaya aktual/bukti final belum ada, refund jumlah tertahan dan tandai keterlambatan di dashboard.
- Penggunaan deposit untuk melunasi invoice adalah settlement non-kas: kurangi liability deposit dan saldo invoice dalam satu transaksi, simpan movement audit terpisah, dan jangan membuat cash event atau mengubah Arus Kas Bersih.
- Status NO_SHOW tidak otomatis mengembalikan atau menghanguskan deposit.

Saat booking dikonversi menjadi tenancy, pertahankan liability deposit yang sama dan tautkan riwayatnya ke tenancy; jangan membuat liability atau penerimaan kedua. Settlement dan refund memerlukan persetujuan eksplisit pemilik, tidak berjalan otomatis saat check-out, pembatalan, atau NO_SHOW. Ketentuan kontrak yang disepakati dan aturan setempat tetap berlaku. Batas 7/30 hari adalah default kebijakan produk yang direkomendasikan; dokumen ini bukan kajian hukum dan tidak menetapkan tenggat hukum. Lihat [ADR-003](../decisions/ADR-003-security-deposit-settlement.md).

## Koreksi, pembatalan, dan refund

Transaksi salah tidak dihapus tanpa jejak. Gunakan void, reversal, atau adjustment yang dapat diaudit. Refund sewa hanya dicatat setelah transfer benar-benar dilakukan, memerlukan credit adjustment yang disetujui, alasan, dan bukti transfer privat. Hubungkan cash outflow `rent_refund` ke receipt awal dan balik alokasi receipt yang direfund; simpan receipt, adjustment, reversal, dan bukti sebagai histori terpisah agar saldo dapat direkonsiliasi. Refund merupakan arus kas keluar pada tanggal refund. Pembatalan booking yang sudah menerima DP diselesaikan pemilik melalui WhatsApp; sistem menyimpan transaksi aktual dan tidak menghapus penerimaan awal, seperti dijelaskan di [Booking dan Masa Sewa](booking-tenancy.md).

Penggunaan credit adjustment untuk refund adalah saldo bersama pada invoice. Kunci invoice dalam urutan ID stabil sebelum menghitung saldo credit dan refund sebelumnya; aturan ini mencegah dua refund bersamaan memakai credit yang sama.

## Basis laporan

MVP menggunakan basis kas operasional sesuai [ADR-002](../decisions/ADR-002-financial-reporting-basis.md) yang telah disetujui:

Net cash flow = kas masuk yang diterima - kas keluar yang dibayar

Kas masuk meliputi penerimaan sewa/perpanjangan yang benar-benar diterima dan penerimaan deposit jaminan, termasuk yang dibayar saat booking. Tampilkan penerimaan deposit dalam satu kategori; deposit tetap liability, bukan pendapatan sewa. Kas keluar meliputi maintenance dan biaya operasional yang benar-benar dibayar serta refund deposit yang diklasifikasikan tersendiri.

Net cash flow bukan laba bersih akuntansi. Gunakan label “Arus Kas Bersih (Net Cash Flow)”. Tagihan yang belum dibayar ditampilkan sebagai outstanding invoice terpisah dan tidak dihitung sebagai kas masuk. Deposit jaminan yang diterima dihitung sebagai kas masuk tetapi liability; refund-nya dihitung sebagai kas keluar sekaligus pengurangan liability, bukan biaya operasional. Pemakaian deposit untuk invoice adalah penyelesaian non-kas dan dilaporkan terpisah dari arus kas.

Semua biaya servis/operasional masuk ke laporan arus kas tepat satu kali, pada saat benar-benar dibayar. Catatan biaya yang belum dibayar tetap dapat dilacak sebagai kewajiban, tetapi tidak dihitung sebagai kas keluar.

## Dasbor dan laporan

Ringkasan yang disebutkan:

- penerimaan sewa;
- penerimaan deposit jaminan saat booking/check-in, refund, saldo liability, dan settlement non-kas;
- biaya maintenance dan biaya operasional yang telah dibayar;
- total kas masuk, kas keluar, dan Arus Kas Bersih;
- outstanding invoice dan tunggakan secara terpisah dari kas;
- tren enam bulan penerimaan/pengeluaran/Arus Kas Bersih, termasuk bulan berjalan secara month-to-date;
- okupansi kamar sebagai snapshot per tanggal dashboard, memakai kamar yang dapat disewakan;
- jumlah booking per status pada periode check-in yang dipilih;
- kas maintenance aktual per kategori pekerjaan pada periode laporan.

Rincian laporan dapat meliputi:

- arus kas dengan tanggal, jenis, kamar, keterangan, kas masuk, kas keluar, liability deposit, dan total;
- riwayat durasi masa sewa;
- biaya maintenance dengan tanggal, kamar, kategori servis, pekerjaan, dan total;
- booking beserta status dan pembayaran uang muka;
- saldo tagihan dengan nominal, total pembayaran, sisa, jatuh tempo, dan status.

Dasbor dan laporan harus memakai sumber transaksi serta definisi perhitungan yang sama. Tampilkan sewa, deposit jaminan, refund/settlement deposit, biaya, dan saldo invoice sebagai kategori berbeda. Penerimaan yang dilakukan saat booking tetap ditampilkan sebagai deposit jaminan, tidak dihitung dua kali atau ditampilkan terpisah sebagai DP. Filter yang tercatat: properti, rentang tanggal, kamar, jenis transaksi, dan status pembayaran jika relevan. Export Excel/PDF memakai sumber data dan formula yang sama dengan halaman laporan. Untuk menjaga penggunaan memori pada hosting mandiri, satu berkas dibatasi maksimal 5.000 baris; pemilik mempersempit filter bila hasil lebih besar.

Gunakan awal bulan kalender berjalan sampai hari ini sebagai periode awal halaman laporan. Filter tanggal mengikuti peristiwa yang dilaporkan: arus kas memakai tanggal aktual `cash_transactions.occurred_on`; invoice memakai tanggal terbit untuk daftar kewajiban dan saldo snapshot pada akhir periode; booking memakai tanggal rencana check-in; tenancy yang sudah check-in memakai overlap hunian aktual dengan rentang laporan, sedangkan tenancy `scheduled` memakai overlap periode rencana; maintenance memakai waktu laporan pekerjaan, sementara biaya cash-nya memakai tanggal pembayaran aktual. Untuk laporan saldo invoice dengan tanggal akhir yang dipilih, hitung posisi per akhir tanggal itu: invoice yang sudah diterbitkan sampai tanggal tersebut dikurangi adjustment yang sudah diposting dan kas yang benar-benar diterima sampai tanggal tersebut. Pembayaran setelah tanggal akhir tidak mengubah snapshot; gunakan tanggal `cash_transactions.occurred_on` untuk kas, tanggal issue invoice untuk kewajiban, dan waktu posting adjustment dalam zona `Asia/Jakarta` untuk credit/debit. Status overdue pada snapshot ditentukan dari `due_date` terhadap tanggal akhir dan saldo snapshot, bukan status hari ini.

## Pengingat

Tampilkan pada dashboard pemilik pengingat tagihan yang jatuh tempo dalam tujuh hari atau kurang, tunggakan yang masih bersaldo, masa sewa yang berakhir dalam 30 hari, serta klaim deposit yang melewati batas settlement sebagai kategori terpisah. Sertakan penyewa, kamar, tanggal, nominal saldo, dan sisa hari sesuai kategorinya. Rentang dan saldo memakai tanggal lokal `Asia/Jakarta`; pengingat bersifat read-only sampai pemilik membuka aksi sumbernya.

Pengingat dikirim hanya kepada pemilik melalui dashboard. Aplikasi tidak mengirim pesan langsung kepada penyewa pada MVP.

## Ekspor

Laporan utama yang disebutkan dapat diekspor ke XLSX dan PDF, mengikuti filter aktif dan hanya memuat tenant yang berwenang. Berkas berisi nama properti, judul, periode, tanggal pembuatan, tabel, subtotal/total, format Rupiah, dan tanggal konsisten.

Untuk XLSX, nilai uang numerik disimpan sebagai angka, format currency diterapkan, serta header/filter/freeze pane digunakan bila sesuai. PDF memiliki header, pagination, tabel yang terbaca lintas halaman, dan ringkasan jumlah. Screenshot dasbor bukan pengganti laporan PDF.

Ekspor XLSX dan PDF termasuk scope MVP. Persyaratan file yang sama berlaku untuk semua format; periksa [Cakupan Fitur](../product/scope.md) untuk batas rilis modul.
