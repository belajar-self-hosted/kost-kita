# Model Data Konseptual

## Status

Skema di dokumen lama adalah sketsa dan saling berbeda. Dokumen ini menetapkan konsep dan invariant domain, bukan DDL. Desain relasional fisik MVP, tabel, field, FK, indeks, ownership, dan strategi concurrency ditetapkan di [Skema Relasional](relational-schema.md); perubahan di sana tidak boleh mengubah aturan domain pada dokumen ini atau aturan bisnis pemilik topik.

## Konsep data

- **Akun dan tenant pemilik:** MVP memiliki akun login Pemilik Kost dan Super Admin; tenant adalah batas kepemilikan data milik pemilik kost. ERD fisik perlu menentukan relasi akun pemilik ke tenant tanpa memberi pemilik akses lintas tenant.
- **Properti:** milik satu pemilik; satu pemilik dapat memiliki beberapa properti.
- **Tipe kamar:** kategori yang dapat dipakai beberapa kamar dalam properti.
- **Kamar:** berada pada satu properti, memiliki kode unik dalam properti itu, fasilitas, tipe, dan status operasional.
- **Harga:** katalog harga kamar atau tipe kamar memakai tanggal efektif; prioritas tarif dan snapshot kesepakatan diatur di [Booking dan Masa Sewa](../business/booking-tenancy.md).
- **Penyewa:** identitas orang yang dapat memiliki beberapa histori masa sewa.
- **Masa sewa:** menghubungkan penyewa dan kamar dengan periode rencana serta check-in/check-out aktual, harga, status, ketentuan pembayaran, dan histori perpanjangan. Billing anchor mengikuti check-in aktual; rincian fisik tanggal rencana/aktual ada pada [skema relasional](relational-schema.md).
- **Booking:** menghubungkan calon penyewa, kamar, periode rencana, status termasuk NO_SHOW, harga kesepakatan, pembayaran awal atas deposit jaminan, tanggal akhir grace period, dan histori perpanjangan grace period.
- **Kewajiban/tagihan:** nominal dan jatuh tempo untuk sewa/perpanjangan serta kewajiban booking yang secara tertulis disepakati dan jatuh tempo; siklus sewa bulanan dan billing anchor berasal dari tanggal check-in.
- **Pembayaran dan alokasi:** penerimaan kas yang dapat melunasi satu atau lebih kewajiban; relasi alokasi mencegah satu penerimaan dihitung berulang.
- **Security deposit liability:** satu kewajiban per booking/tenancy yang dapat dibayar saat booking maupun check-in; saldo wajib, diterima, direfund, atau disettle harus tetap satu liability melalui konversi booking ke tenancy. Terpisah dari rental revenue.
- **Deposit settlement movement:** refund kas atau penggunaan non-kas untuk invoice/kewajiban eligible; setiap movement menyimpan alasan, dasar kontrak, bukti, aktor, persetujuan, dan waktu.
- **Temporary liability:** penampung bagian dana berlebih yang benar-benar diterima sebelum direfund atau diselesaikan secara eksplisit.
- **Perawatan:** pekerjaan servis di properti atau kamar beserta status, biaya, tanggal mulai, dan tanggal selesai.
- **Biaya operasional:** pengeluaran selain servis, terkait properti dan opsional kamar.
- **Transaksi kas/ledger:** catatan terverifikasi untuk arus masuk, arus keluar, refund, koreksi, dan laporan.
- **Audit log:** catatan aktor dan perubahan untuk tindakan administratif serta finansial penting.

Jangan mengumpulkan atau menyimpan nomor KTP/identitas resmi penyewa pada MVP.

Booking DP dan security deposit adalah satu kewajiban serta satu liability. Pembayaran di booking tetap terlacak sebagai penerimaan deposit yang sama setelah booking menjadi tenancy, tanpa cash event atau liability ganda. Penerimaan menambah kas dan liability, bukan pendapatan sewa. Refund mengurangi kas dan liability; pemakaian deposit untuk invoice mengurangi liability serta saldo invoice sebagai settlement non-kas, bukan arus kas baru. Kelebihan dana yang diterima di luar sistem dicatat utuh dan bagian berlebih masuk temporary liability.

## Relasi yang harus dijaga

1. Setiap properti memiliki tepat satu pemilik.
2. Setiap kamar terhubung ke properti; kode kamar unik hanya dalam lingkup properti itu.
3. Setiap booking, tenancy, pembayaran, perawatan, biaya, dan ekspor dapat ditelusuri ke pemilik yang berhak melalui foreign key yang valid.
4. Identitas penyewa tidak digabung dengan tenancy sehingga histori tidak hilang saat check-out.
5. Booking hasil konversi terhubung ke tenancy yang terbentuk.
6. Pembayaran terpisah dari kewajiban dan dicatat satu kali; alokasi menjelaskan kewajiban yang dibayar.
7. Catatan servis terpisah dari bukti pembayaran agar biaya tidak menjadi arus kas keluar sebelum benar-benar dibayar.
8. Data finansial historis tidak terhapus saat properti, kamar, penyewa, atau akun dinonaktifkan.

## Integritas dan retensi

- Gunakan foreign key yang valid dan indeks pada foreign key serta kolom filter utama.
- Perubahan harga tidak menulis ulang nilai kesepakatan/transaksi yang telah tercatat.
- Nilai uang memakai presisi aman; jangan menggunakan floating-point.
- Operasi booking dan keuangan yang saling terkait harus transaksional.
- Hindari cascade delete yang menghilangkan histori booking, masa sewa, pembayaran, atau audit.
- Seluruh tabel tenant-owned harus punya jalur ownership yang dapat diperiksa backend.

Skema fisik memilih `tenant_id` eksplisit pada setiap tabel tenant-owned serta foreign key gabungan untuk menjaga parent dan child tetap berada pada tenant yang sama. Backend tetap mengambil tenant dari akun terautentikasi; `tenant_id` dari klien tidak menjadi bukti akses. Detail implementasi ada di [Skema Relasional](relational-schema.md).

Perawatan yang memblokir kamar menyimpan tanggal mulai dan selesai. Rentang ini mengikuti aturan interval pada [Ketersediaan Kamar](../business/availability.md).

Invoice bulanan menyimpan billing anchor day asli agar siklus tanggal 29–31 tetap mengikuti tanggal check-in tanpa drift. Pemrosesan NO_SHOW menyimpan batas grace period yang eksklusif, waktu transisi, serta audit jika pemilik memperpanjang grace period.

## Model keuangan

Entitas final dapat menggunakan ledger transaksi atau pendekatan lain selama menghasilkan satu sumber kas yang dapat direkonsiliasi. Setiap cash event memiliki jenis dan klasifikasi terpisah dari revenue/liability. Jangan menyimpan nilai yang sama sekaligus sebagai penerimaan pembayaran dan transaksi pemasukan independen tanpa hubungan yang mencegah duplikasi.

Tinjau [Skema Relasional](relational-schema.md) dan [Kontrak Route/Action](../architecture/route-actions.md) sebelum membuat migration atau backend action; keduanya menerjemahkan invariant konseptual ini ke kontrak implementasi. Login Pengelola tidak dibuat pada MVP.
