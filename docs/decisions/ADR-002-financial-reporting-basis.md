# ADR-002: Basis Laporan Keuangan MVP

- Status: accepted
- Tanggal: 2026-11-03
- Pemilik keputusan: pengguna

## Konteks

Dokumen awal menyebut “keuntungan bersih” pada PRD, sedangkan instruksi operasional mendefinisikan kas masuk dikurangi kas keluar dan mengingatkan bahwa hasil tersebut bukan laba bersih akuntansi. Data MVP mencatat tagihan, pembayaran, uang muka, biaya servis, dan biaya operasional.

## Rekomendasi

Untuk MVP, gunakan laporan **basis kas operasional** dan sebut hasilnya **Arus Kas Bersih (Net Cash Flow)**, bukan Laba Bersih (Net Profit). Tampilkan:

- kas yang benar-benar diterima sebagai kas masuk;
- deposit jaminan, termasuk pembayaran DP saat booking, sebagai satu kategori kas masuk sekaligus liability, bukan rental revenue;
- kas yang benar-benar dibayar sebagai kas keluar;
- refund deposit sebagai kas keluar dan pengurangan liability, bukan biaya operasional;
- deduction atau pemakaian deposit sebagai settlement non-kas yang dilaporkan terpisah dan tidak mengubah Arus Kas Bersih;
- saldo tagihan belum dibayar secara terpisah dari kas masuk;
- net cash flow sebagai total kas masuk dikurangi kas keluar.

Kelompokkan terpisah: penerimaan sewa, penerimaan deposit jaminan, refund deposit, settlement deposit non-kas, biaya maintenance, dan biaya operasional. Jika satu transfer dialokasikan ke sewa dan deposit, rincian kategori mengikuti alokasi masing-masing ledger sementara transfer aktual dihitung satu kali pada total kas. DP booking dan deposit jaminan tidak dicatat sebagai dua penerimaan atau liability berbeda. Outstanding invoice tidak termasuk uang diterima. Dashboard dan ekspor Excel/PDF menggunakan sumber data serta formula yang sama.

Jangan sajikan laporan laba-rugi akrual dalam MVP. Jika nanti dibutuhkan, buat keputusan dan model akuntansi terpisah yang menentukan waktu pengakuan sewa dan biaya, termasuk dampak non-kas saat deposit dipakai melunasi sewa/kerusakan, biaya yang sudah terjadi tetapi belum dibayar, dan perlakuan aset/penyusutan. Laporan operasional MVP bukan laporan keuangan formal.

## Alasan

Pemilik membutuhkan catatan praktis tentang uang yang benar-benar diterima atau dibayarkan. Model data yang ada memang mengumpulkan tanggal penerimaan/pembayaran dan saldo tagihan, tetapi belum menetapkan aturan pengakuan pendapatan dan beban akrual. Menampilkan selisih kas sebagai “laba bersih” dapat menyesatkan karena DP, tagihan belum dibayar, dan pengeluaran yang belum dibayar tidak mengikuti periode yang sama.

PSAK 207/IAS 7 membedakan pelaporan arus kas dari informasi laba/rugi. IAS 1 menyatakan laporan keuangan berbasis akrual kecuali informasi arus kas. Perbedaan ini mendukung pemisahan nama dan rumus kedua jenis laporan; rekomendasi scope MVP di atas adalah keputusan produk, bukan pernyataan kepatuhan akuntansi.

## Trade-off

- Basis kas lebih mudah direkonsiliasi dengan bukti uang masuk/keluar yang dicatat pemilik.
- Basis kas tidak menjawab laba akuntansi untuk suatu bulan dan tidak memasukkan kewajiban yang belum dibayar sebagai kas keluar.
- Laporan akrual memberi gambaran hasil per periode, tetapi membutuhkan aturan tambahan yang belum ada pada produk.

## Sumber resmi

- [IAI — PSAK 207 Laporan Arus Kas](https://web.iaiglobal.or.id/PSAK-Umum/8)
- [IFRS — IAS 7 Statement of Cash Flows](https://www.ifrs.org/issued-standards/list-of-standards/ias-7-statement-of-cash-flows/)
- [IFRS — IAS 1 Presentation of Financial Statements, paragraf 27–28](https://www.ifrs.org/content/dam/ifrs/publications/pdf-standards/english/2022/issued/part-a/ias-1-presentation-of-financial-statements.pdf)

## Keputusan pemilik

Pemilik menyetujui basis kas operasional saja untuk MVP. Label arus kas bersih, DP booking sebagai pembayaran deposit jaminan yang sama, perlakuan deposit sebagai liability, settlement non-kas terpisah, outstanding invoice terpisah, dan kesamaan formula dashboard/export merupakan aturan aktif. Aturan rinci serta ledger deposit berada di [Keuangan dan Laporan](../business/finance.md) dan [ADR-003](ADR-003-security-deposit-settlement.md).
