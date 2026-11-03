> **ARSIP NON-NORMATIF:** sumber sebelum konsolidasi. Gunakan hanya untuk melacak asal materi; requirement aktif ada di [indeks dokumentasi](../README.md).

# KOST MANAGEMENT SYSTEM

## 1. ROLE & OBJECTIVE

Bertindaklah sebagai **Senior Full-Stack Software Engineer, Software Architect, Database Architect, dan UI/UX Engineer** yang berpengalaman membangun aplikasi SaaS berbasis Laravel dan Svelte untuk lingkungan production.

Saya ingin membangun aplikasi web bernama sementara **Kost Management System**, yaitu platform yang membantu pemilik usaha kost dalam mengelola unit kamar, penyewa, reservasi, pembayaran, perawatan properti, serta laporan dan analitik keuangan.

Aplikasi harus dibangun dengan pendekatan:

- Production-oriented architecture.
- Clean, modular, maintainable, dan scalable code.
- Security by design.
- Multi-tenant data isolation.
- Database integrity dan transactional consistency.
- Responsive dan intuitive user interface.
- Reliable financial calculations.
- Explicit business rules dan state management.
- Automated testing untuk alur bisnis kritis.

**Tujuan utama:** menghasilkan aplikasi manajemen kost yang dapat digunakan oleh banyak pemilik kost secara independen melalui satu platform, tanpa memungkinkan satu pemilik mengakses atau memodifikasi data pemilik lainnya.

Jangan langsung mengimplementasikan kode sebelum memahami keseluruhan kebutuhan, merancang arsitektur, mengidentifikasi risiko, dan menyusun implementation plan.

---

## 2. TECHNOLOGY STACK

Gunakan teknologi berikut:

**Backend**

- Laravel dengan versi stabil yang kompatibel dan didukung.
- PHP dengan versi yang sesuai.
- Laravel authentication dan authorization.
- Eloquent ORM.
- Form Request Validation.
- Service/Action Layer untuk business logic kompleks.
- Database transactions untuk operasi sensitif.
- Laravel Scheduler untuk pekerjaan terjadwal.
- Laravel Queue jika diperlukan.

**Frontend**

- Svelte dengan TypeScript.
- Gunakan SvelteKit atau integrasi Laravel + Inertia.js + Svelte berdasarkan analisis arsitektur yang paling sesuai.
- Tailwind CSS.
- Reusable components.
- Reactive UI dan state management yang terorganisasi.

**Database**

- MySQL atau PostgreSQL.
- Relational database design.
- Foreign key constraints, indexes, dan appropriate unique constraints.
- Database migration dan seeder.

**Reporting**

- Export laporan ke Excel (.xlsx).
- Export laporan ke PDF.
- Format file harus terstruktur, konsisten, dan sesuai dengan data yang difilter.

**Infrastructure**

- Environment-based configuration.
- Logging dan error monitoring.
- Deployment-friendly configuration.
- Automated testing dan CI/CD readiness.

Jangan menambahkan teknologi atau dependency yang tidak memberikan manfaat jelas. Setiap keputusan arsitektur harus memiliki justifikasi teknis.

---

## 3. APPLICATION ARCHITECTURE

### 3.1 Multi-Tenant Architecture

Aplikasi memiliki banyak pemilik kost yang menggunakan platform secara bersamaan.

Gunakan pendekatan multi-tenant dengan shared database dan tenant scoping, kecuali analisis teknis menunjukkan pendekatan lain lebih sesuai.

Ketentuan:

1. Setiap pemilik kost hanya boleh mengakses data properti miliknya.
2. Semua kamar, booking, penyewa, pembayaran, dan servis harus terasosiasi dengan tenant/properti yang benar.
3. Jangan mempercayai tenant ID yang dikirim dari frontend tanpa validasi ownership.
4. Terapkan tenant isolation melalui authorization policy, query scoping, dan service layer.
5. Super Admin memiliki akses pengelolaan lintas tenant sesuai hak aksesnya.
6. Gunakan pengujian otomatis untuk memastikan data tidak bocor antar tenant.
7. Cegah IDOR (Insecure Direct Object Reference) pada seluruh resource.

### 3.2 Multiple Properties

Arsitektur database sebaiknya mendukung satu pemilik kost memiliki lebih dari satu properti atau bangunan kost.

Contoh:

Pemilik B memiliki:

- Kost Melati.
- Kost Mawar.
- Kost Anggrek.

Setiap properti memiliki kamar, penyewa, transaksi, dan laporan tersendiri.

Dashboard dapat mendukung filter berdasarkan properti atau agregasi seluruh properti milik pemilik yang sama.

Jika fitur multiple properties belum menjadi bagian MVP, tetap rancang struktur database agar fitur tersebut dapat ditambahkan tanpa perubahan besar.

---

## 4. USER ROLES & ACCESS CONTROL

Aplikasi hanya memiliki dua role pengguna yang dapat login.

### 4.1 Super Admin

Super Admin merupakan saya sebagai pemilik platform.

Hak akses:

- Login ke dashboard Super Admin.
- Melihat seluruh pemilik kost yang terdaftar.
- Melihat informasi dan status akun pemilik.
- Mengaktifkan atau menonaktifkan akun pemilik.
- Melihat statistik penggunaan platform.
- Melihat jumlah properti dan unit kamar terdaftar.
- Melihat jumlah pemilik kost aktif.
- Mengelola akun pemilik sesuai kewenangan.
- Melihat informasi operasional lintas tenant jika diperlukan.

Super Admin tidak boleh secara otomatis mengubah catatan keuangan atau penyewaan tanpa mekanisme administratif yang jelas dan tercatat.

### 4.2 Pemilik Kost

Pemilik kost dapat:

- Registrasi menggunakan email.
- Login dan logout.
- Mengelola profil akun.
- Mengelola properti dan unit kamar.
- Mengelola tipe kamar dan harga.
- Mengelola booking dan check-in.
- Mengelola data penyewa.
- Mencatat pembayaran sewa dan perpanjangan.
- Mengelola riwayat servis.
- Melihat dashboard analitik.
- Mengelola dan mengekspor laporan.

Seluruh aktivitas dibatasi pada data yang menjadi hak miliknya.

### 4.3 Authentication & Authorization

Implementasikan:

- Secure password hashing.
- Email verification.
- Forgot dan reset password.
- Session management.
- CSRF protection untuk autentikasi berbasis session.
- Rate limiting pada login dan endpoint sensitif.
- Role-based access control.
- Resource ownership validation.
- Secure logout dan session invalidation.

**Catatan:** Penyewa bukan pengguna aplikasi dan tidak perlu memiliki akun. Seluruh informasi penyewa dikelola oleh pemilik kost.

---

## 5. PROPERTY & ROOM MANAGEMENT

### 5.1 CRUD Properti

Pemilik dapat membuat dan mengelola informasi properti, mencakup:

- Nama properti.
- Alamat.
- Deskripsi.
- Informasi tambahan.

### 5.2 CRUD Unit Kamar

Setiap properti memiliki sejumlah kamar.

Data kamar:

- ID internal.
- Nomor atau kode kamar, misalnya 101, 102, A01.
- Tipe kamar.
- Harga sewa.
- Status operasional.
- Deskripsi atau catatan tambahan.
- Tanggal pembuatan dan perubahan data.

Nomor kamar harus unik dalam satu properti, tetapi boleh sama pada properti berbeda.

### 5.3 CRUD Tipe Kamar

Contoh tipe:

- Reguler.
- Eksklusif.
- Premium.
- VIP.
- Custom.

Pemilik dapat menambahkan tipe kamar sesuai kebutuhan.

Satu tipe kamar dapat digunakan oleh beberapa unit kamar.

### 5.4 CRUD Harga

Harga sewa dapat dikonfigurasi berdasarkan:

- Unit kamar atau tipe kamar.
- Nominal harga.
- Periode sewa, misalnya harian atau bulanan.
- Tanggal efektif harga jika diperlukan.

**Business rules:**

1. Harga harus memiliki nominal yang valid.
2. Perubahan harga tidak boleh mengubah nominal transaksi historis.
3. Setiap transaksi harus menyimpan snapshot harga saat kesepakatan dibuat.
4. Perubahan harga kamar hanya berlaku untuk transaksi baru, kecuali pemilik membuat penyesuaian khusus secara eksplisit.
5. Transaksi historis tetap mempertahankan nominal aslinya.

---

## 6. ROOM AVAILABILITY ENGINE

Sistem harus memiliki mekanisme pengecekan ketersediaan kamar berdasarkan tanggal.

**Jangan menentukan ketersediaan kamar hanya dengan boolean `is_occupied` atau status teks yang diperbarui secara manual.**

Ketersediaan kamar harus dihitung berdasarkan:

- Status operasional kamar.
- Booking yang masih aktif dan memblokir tanggal.
- Periode sewa penghuni aktif.
- Perpanjangan periode sewa.
- Pembatalan booking.
- Check-out penghuni.
- Periode kamar tidak dapat digunakan karena maintenance jika relevan.

### 6.1 Contoh Skenario

Kamar 101 memiliki penghuni dengan periode:

1 November 2026 sampai 30 November 2026.

Pengguna lain ingin booking:

- 15 November 2026: tidak tersedia.
- 25 November 2026: tidak tersedia.
- 1 Desember 2026: dapat tersedia apabila penghuni sebelumnya tidak memperpanjang dan tidak ada booking lain yang memblokir tanggal tersebut.

Sistem tidak boleh menjanjikan kamar tersedia tanpa mempertimbangkan seluruh jadwal yang memblokir kamar.

### 6.2 Date Overlap Validation

Gunakan interval tanggal yang konsisten.

Rekomendasi representasi internal adalah interval half-open:

`[start_date, end_date)`

Artinya:

- `start_date` termasuk dalam periode penggunaan.
- `end_date` merupakan batas akhir eksklusif.
- Dua periode yang saling bersentuhan pada batas akhir tidak dianggap overlap.

Untuk input tanggal check-out yang dipahami pengguna sebagai tanggal terakhir menghuni, lakukan konversi secara konsisten pada backend.

Aturan overlap:

`existing.start < requested.end AND existing.end > requested.start`

### 6.3 Concurrency Protection

Wajib menangani skenario dua permintaan booking pada kamar dan periode yang sama.

Gunakan:

- Database transaction.
- Pessimistic locking atau strategi concurrency control yang sesuai.
- Revalidasi ketersediaan sebelum membuat reservasi final.
- Database integrity constraints yang memungkinkan.

Jangan hanya mengandalkan pengecekan availability di frontend.

---

## 7. BOOKING & RESERVATION MANAGEMENT

### 7.1 Business Workflow

Proses kesepakatan sewa dilakukan di luar aplikasi melalui WhatsApp.

Contoh:

- A = Calon penyewa.
- B = Pemilik kost.

Alur utama:

1. Calon penyewa menghubungi pemilik melalui WhatsApp.
2. Pemilik dan calon penyewa menyepakati kamar, harga, dan tanggal masuk.
3. Jika telah ada kesepakatan, pemilik mencatat booking di dashboard.
4. Sistem memvalidasi ketersediaan kamar pada periode yang dipilih.
5. Pemilik memasukkan informasi booking.
6. Sistem menyimpan booking dan memperbarui proyeksi ketersediaan kamar.
7. Ketika penyewa benar-benar masuk, pemilik mengonversi booking menjadi check-in.

Semua pencatatan dilakukan oleh pemilik kost. Tidak diperlukan akun penyewa atau checkout online.

### 7.2 Booking Form

Field wajib:

- Properti.
- Unit kamar.
- Nama calon penyewa.
- Nomor WhatsApp aktif.
- Tanggal rencana check-in.
- Durasi atau periode rencana sewa.
- Harga sewa yang disepakati.
- Status booking.

Field pembayaran:

- Apakah ada uang muka?
- Nominal uang muka.
- Metode pembayaran.
- Tanggal pembayaran jika sudah diterima.

Field tambahan:

- Catatan opsional.

### 7.3 Booking Status

Gunakan state yang eksplisit:

- `pending`: booking dicatat tetapi belum dikonfirmasi.
- `confirmed`: booking telah disetujui dan memblokir periode kamar.
- `checked_in`: booking berhasil dikonversi menjadi masa sewa aktif.
- `cancelled`: booking dibatalkan.
- `expired`: booking kedaluwarsa jika kebijakan expiry diterapkan.

Tentukan secara eksplisit status mana yang memblokir ketersediaan kamar.

Sebagai default:

- Confirmed booking memblokir kamar.
- Pending booking tidak memblokir kamar kecuali ada mekanisme temporary hold yang jelas.
- Cancelled dan expired tidak memblokir kamar.
- Checked-in menggunakan periode sewa aktual untuk memblokir kamar.

### 7.4 Booking Validation

Sistem harus menolak:

- Booking dengan rentang tanggal tidak valid.
- Booking yang overlap dengan periode terblokir.
- Nominal uang muka negatif.
- Nominal uang muka yang melebihi kewajiban pembayaran tanpa kebijakan overpayment.
- Booking pada kamar yang sedang tidak dapat disewakan.
- Perubahan booking yang menimbulkan konflik jadwal.

### 7.5 Booking Conversion

Saat booking dikonversi menjadi check-in:

1. Validasi kembali kamar dan periode.
2. Buat record masa sewa.
3. Hubungkan dengan record booking.
4. Transfer atau alokasikan pembayaran uang muka sesuai aturan akuntansi.
5. Hindari duplikasi pemasukan.
6. Perbarui status booking menjadi `checked_in`.

Semua operasi terkait harus bersifat atomic.

---

## 8. CHECK-IN & TENANCY MANAGEMENT

### 8.1 Direct Check-In

Pemilik kost dapat membuat check-in tanpa booking sebelumnya.

Form:

- Properti dan unit kamar.
- Nama penyewa.
- Nomor WhatsApp aktif.
- Tanggal masuk.
- Tanggal keluar atau akhir periode sewa.
- Durasi sewa.
- Harga sewa yang disepakati.
- Nominal pembayaran.
- Metode pembayaran.
- Tanggal jatuh tempo pembayaran.
- Catatan tambahan.

Catatan penting: bedakan antara tanggal akhir masa sewa dan tanggal jatuh tempo pembayaran. Keduanya bisa berbeda.

### 8.2 Tenant & Tenancy Data

Pisahkan data identitas penyewa dan data kontrak atau masa sewanya.

**Tenant (penyewa):**

- Nama.
- Nomor WhatsApp.
- Informasi kontak.
- Catatan.

**Tenancy (masa sewa):**

- Referensi penyewa.
- Referensi kamar.
- Tanggal mulai.
- Tanggal akhir.
- Harga sewa yang disepakati.
- Status masa sewa.
- Ketentuan pembayaran.
- Riwayat perpanjangan.

Dengan struktur ini, penyewa yang sama dapat memiliki riwayat sewa tanpa kehilangan informasi historis.

### 8.3 Tenancy Status

Gunakan status yang terdefinisi, misalnya:

- `scheduled`
- `active`
- `completed`
- `terminated`

Status harus konsisten dengan jadwal dan proses bisnis.

Jangan menghapus data penyewaan historis ketika penyewa check-out.

---

## 9. ROOM DASHBOARD & OCCUPANCY CARDS

Dashboard pemilik menampilkan daftar kamar dalam bentuk card.

### 9.1 Empty Room

Jika kamar tersedia pada tanggal yang sedang dilihat:

**Kamar 102**

- Status: Kosong.
- Tipe: Reguler.
- Harga sewa.
- Tombol Detail.
- Tombol Booking.
- Tombol Check-In.

### 9.2 Occupied Room

Jika kamar memiliki penghuni aktif:

**Kamar 101**

- Status: Dihuni.
- Nama penghuni: Rafi.
- WhatsApp: nomor kontak Rafi.
- Tanggal masuk.
- Tanggal akhir sewa.
- Durasi sewa: 2 bulan 4 hari.
- Sisa waktu menuju akhir sewa.
- Status pembayaran.
- Tombol Lihat Detail.
- Tombol Catat Pembayaran.
- Tombol Perpanjang Sewa.
- Tombol Check-Out.

Durasi sewa dan sisa waktu harus dihitung secara konsisten dari tanggal yang tersimpan, bukan menggunakan perhitungan kasar bulan selalu 30 hari.

### 9.3 Future Booking Indicator

Jika kamar sedang kosong namun telah memiliki booking confirmed di masa depan, sistem harus menampilkan indikator:

**Kosong — Sudah Dipesan**

Dengan informasi:

- Jadwal booking berikutnya.
- Nama calon penyewa jika pengguna berwenang.
- Periode booking.

Kamar yang kosong hari ini belum tentu tersedia untuk seluruh periode yang diminta.

### 9.4 Maintenance Room

Kamar yang tidak dapat digunakan karena perbaikan harus mempunyai indikator tersendiri, misalnya:

**Tidak Tersedia — Maintenance**

Jangan mencampur status operasional kamar dengan occupancy status.

---

## 10. RENT PAYMENT & EXTENSION

### 10.1 Payment Recording

Pemilik kost dapat memilih kamar yang sedang disewa dan mencatat pembayaran.

Field:

- Kamar.
- Penyewa aktif.
- Referensi periode sewa.
- Nominal pembayaran.
- Metode pembayaran.
- Tanggal pembayaran.
- Jenis pembayaran.
- Referensi jatuh tempo jika diperlukan.
- Keterangan.

Contoh keterangan:

"Pelunasan sewa bulan November."

### 10.2 Payment Categories

Minimal mendukung:

- Rental payment.
- Booking down payment.
- Rental settlement.
- Rental extension payment.

Pisahkan konsep pembayaran uang masuk dari jenis kewajiban yang sedang dibayar.

### 10.3 Rent Extension

Alur:

1. Pemilik memilih kamar dengan masa sewa aktif.
2. Sistem menampilkan informasi penyewa.
3. Pemilik memilih fitur perpanjangan sewa.
4. Masukkan tanggal akhir sewa baru.
5. Masukkan harga atau tagihan perpanjangan.
6. Masukkan nominal pembayaran jika ada.
7. Tentukan tanggal jatuh tempo pembayaran baru.
8. Sistem memvalidasi konflik dengan booking masa depan.
9. Jika valid, simpan perpanjangan dan catatan pembayaran terkait.
10. Dashboard diperbarui.

**Critical rule:** Perpanjangan tidak boleh otomatis menimpa booking confirmed yang sudah dimiliki orang lain.

Jika terjadi konflik, tampilkan informasi dan hentikan perpanjangan sampai diselesaikan melalui alur perubahan atau pembatalan booking yang eksplisit.

### 10.4 Partial Payment

Desain pembayaran harus mendukung:

- Belum dibayar.
- Dibayar sebagian.
- Lunas.
- Lewat jatuh tempo.

Nominal tagihan dan pembayaran harus dicatat secara terpisah agar sistem dapat menghitung outstanding balance dengan benar.

### 10.5 Payment Integrity

- Gunakan database transactions.
- Gunakan decimal/fixed precision atau integer minor units untuk nilai uang.
- Jangan menggunakan floating-point untuk kalkulasi finansial.
- Jangan membuat pemasukan ganda saat booking dikonversi menjadi check-in.
- Sediakan audit trail untuk perubahan transaksi.
- Transaksi salah sebaiknya dikoreksi menggunakan mekanisme void/reversal atau adjustment, bukan menghapus jejak keuangan.

---

## 11. PAYMENT DUE DATE ALERTS

Dashboard memiliki sistem peringatan pembayaran dan masa sewa.

### 11.1 Upcoming Payment Due

Jika suatu tagihan jatuh tempo kurang dari atau sama dengan tujuh hari, tampilkan indikator:

**Perhatian Segera**

Informasi yang ditampilkan:

- Nama penyewa.
- Nomor kamar.
- Tanggal jatuh tempo.
- Jumlah tagihan yang belum dibayar.
- Sisa hari.

Jika card diklik, arahkan ke daftar tagihan terkait.

### 11.2 Overdue Payment

Jika tanggal jatuh tempo sudah lewat dan masih ada saldo tagihan:

Tampilkan status:

**Terlambat Bayar**

Sertakan nominal tunggakan dan jumlah hari keterlambatan.

### 11.3 Upcoming Tenancy End

Buat kategori notifikasi terpisah untuk penghuni yang masa sewanya segera berakhir.

Jangan mencampur:

- Pembayaran segera jatuh tempo.
- Pembayaran terlambat.
- Masa sewa segera berakhir.

Ketiganya merupakan kondisi bisnis berbeda.

### 11.4 Alert Calculation

Alert harus dihitung berdasarkan data yang valid dan tanggal terkini.

Gunakan timezone aplikasi yang jelas, dengan default `Asia/Jakarta` untuk operasional di Indonesia.

---

## 12. PROPERTY MAINTENANCE & SERVICE HISTORY

Sediakan halaman tersendiri untuk mengelola riwayat perawatan properti.

### 12.1 Maintenance Categories

Contoh:

- Servis AC.
- Perbaikan kamar mandi.
- Perbaikan listrik.
- Perbaikan air.
- Perbaikan pintu.
- Pengecatan.
- Perbaikan fasilitas umum.
- Lainnya.

### 12.2 Maintenance Form

Field:

- Properti.
- Kamar terkait (opsional untuk perbaikan fasilitas umum).
- Jenis servis.
- Tanggal servis.
- Deskripsi kerusakan.
- Deskripsi pekerjaan.
- Biaya servis.
- Nama teknisi/vendor (opsional).
- Status pengerjaan.
- Catatan tambahan.

### 12.3 Maintenance History

Simpan seluruh riwayat servis untuk kebutuhan:

- Pelacakan kerusakan berulang.
- Analisis pengeluaran properti.
- Rekap biaya per kamar.
- Perencanaan pemeliharaan.

Jika biaya servis sudah dibayarkan, sistem mencatat cash outflow sesuai tanggal pembayaran.

Biaya servis tidak boleh tercatat dua kali di laporan keuangan.

---

## 13. OPERATING EXPENSE MANAGEMENT

Selain biaya servis, sistem sebaiknya menyediakan pencatatan biaya operasional.

Contoh:

- Listrik.
- Air.
- Internet.
- Kebersihan.
- Gaji penjaga.
- Pembelian perlengkapan.
- Biaya administratif.
- Biaya operasional lainnya.

Setiap pengeluaran memiliki:

- Properti.
- Tanggal transaksi.
- Kategori.
- Nominal.
- Metode pembayaran.
- Deskripsi.
- Referensi kamar jika relevan.

Gunakan data pengeluaran ini sebagai komponen laporan arus kas.

---

## 14. OWNER ANALYTICS DASHBOARD

Dashboard harus memberikan ringkasan kondisi bisnis kost.

### 14.1 Occupancy Metrics

Tampilkan:

- Total properti.
- Total kamar.
- Kamar terisi saat ini.
- Kamar kosong saat ini.
- Kamar tidak aktif/maintenance.
- Jumlah booking confirmed.
- Occupancy rate.

Definisikan occupancy rate dengan jelas.

Sebagai contoh, occupancy rate saat ini:

`Kamar terhuni / Kamar yang dapat disewakan × 100%`

Pastikan penanganan kamar maintenance serta periode pelaporan konsisten dengan definisi metrik.

### 14.2 Financial Metrics

Tampilkan:

- Total penerimaan sewa.
- Total penerimaan uang muka booking.
- Total biaya servis.
- Total biaya operasional.
- Net cash flow.
- Total tagihan belum dibayar.
- Total tunggakan.

### 14.3 Financial Formula

Untuk cash basis reporting:

`Net Cash Flow = Total Cash Inflow - Total Cash Outflow`

Cash inflow dapat mencakup:

- Pembayaran sewa.
- Pembayaran perpanjangan.
- Uang muka booking yang benar-benar diterima.

Cash outflow dapat mencakup:

- Biaya servis yang sudah dibayar.
- Biaya operasional yang sudah dibayar.
- Refund atau pengembalian uang muka.

**Penting:** Net cash flow tidak selalu sama dengan laba bersih akuntansi. Jangan menyebutnya laba bersih tanpa memperhitungkan pengakuan pendapatan, beban, dan kewajiban secara benar.

### 14.4 Dashboard Charts

Sediakan visualisasi:

- Tren penerimaan bulanan.
- Tren pengeluaran bulanan.
- Net cash flow per bulan.
- Distribusi occupancy.
- Jumlah booking per periode.
- Biaya maintenance berdasarkan kategori.

Seluruh angka pada grafik harus berasal dari data backend yang sama dengan laporan keuangan.

---

## 15. FINANCIAL REPORTING CENTER

Sediakan halaman khusus bernama:

**Laporan & Keuangan Properti**

Halaman harus mendukung filter:

- Properti.
- Rentang tanggal.
- Kamar (jika relevan).
- Jenis transaksi.
- Status pembayaran.

### 15.1 Financial Summary

Tampilkan:

- Pemasukan sewa kamar.
- Penerimaan deposit/uang muka booking.
- Total biaya maintenance.
- Total biaya operasional.
- Total arus kas masuk.
- Total arus kas keluar.
- Net cash flow.

Kategori uang muka harus ditampilkan terpisah untuk analisis tetapi tetap dihitung satu kali dalam total penerimaan kas.

### 15.2 Cash Flow Report

Buat tabel dengan kolom:

| Tanggal    | Jenis Arus Kas  | Kamar | Keterangan   | Kas Masuk   | Kas Keluar |
| ---------- | --------------- | ----- | ------------ | ----------- | ---------- |
| 01/11/2026 | Pembayaran Sewa | 101   | Sewa Rafi    | Rp1.500.000 | -          |
| 02/11/2026 | DP Booking      | 102   | DP Andi      | Rp300.000   | -          |
| 03/11/2026 | Servis AC       | 101   | Perbaikan AC | -           | Rp250.000  |

Berikan total di bagian bawah tabel.

### 15.3 Tenancy Duration Report

Tampilkan:

| Unit Kamar | Nama Penyewa | Tanggal Masuk | Akhir Sewa | Durasi  | Status |
| ---------- | ------------ | ------------- | ---------- | ------- | ------ |
| 101        | Rafi         | 01/11/2026    | 31/12/2026 | 2 bulan | Aktif  |

Laporan harus dapat menampilkan histori masa sewa berdasarkan filter, bukan hanya penghuni aktif.

### 15.4 Maintenance Expense Report

Tampilkan:

| Tanggal    | Kamar | Jenis Servis | Pekerjaan             | Biaya     |
| ---------- | ----- | ------------ | --------------------- | --------- |
| 03/11/2026 | 101   | AC           | Penggantian kapasitor | Rp250.000 |

Sediakan total biaya dan filter berdasarkan jenis servis serta properti.

### 15.5 Booking Report

Tampilkan:

- Nomor booking.
- Nama calon penyewa.
- Kamar.
- Tanggal rencana masuk.
- Periode booking.
- Status booking.
- Nominal uang muka.
- Status pembayaran.

### 15.6 Outstanding Payment Report

Tampilkan:

- Kamar.
- Nama penyewa.
- Nominal tagihan.
- Total pembayaran.
- Sisa tagihan.
- Jatuh tempo.
- Status.

---

## 16. EXCEL & PDF EXPORT

Seluruh laporan utama harus dapat diekspor ke:

- Microsoft Excel (.xlsx).
- PDF (.pdf).

### 16.1 Export Requirements

File yang dihasilkan harus:

1. Mengikuti filter yang sedang diterapkan pengguna.
2. Menampilkan nama properti.
3. Menampilkan judul laporan.
4. Menampilkan periode laporan.
5. Menampilkan tanggal pembuatan file.
6. Memiliki tabel terstruktur.
7. Menampilkan total dan subtotal jika relevan.
8. Menggunakan format mata uang Rupiah.
9. Menggunakan format tanggal yang konsisten.
10. Tidak mengekspos data tenant lain.

### 16.2 Excel Requirements

- Gunakan format XLSX yang valid.
- Header kolom jelas.
- Numeric cells disimpan sebagai nilai numerik.
- Format currency diterapkan pada cell.
- Auto filter jika sesuai.
- Freeze header untuk laporan panjang.
- Formula total atau nilai total hasil backend yang konsisten.

### 16.3 PDF Requirements

- Layout profesional.
- Header dokumen.
- Informasi properti.
- Tabel mudah dibaca.
- Pagination untuk data panjang.
- Ringkasan subtotal dan total.
- Penanganan tabel yang melewati beberapa halaman.

Jangan menggunakan hasil render screenshot dashboard sebagai pengganti laporan PDF.

---

## 17. DATABASE DESIGN

Sebelum mengimplementasikan backend, rancang ERD dan database schema.

Entitas yang perlu dievaluasi minimal mencakup:

1. `users`
2. `properties`
3. `room_types`
4. `rooms`
5. `room_prices` atau struktur harga yang sesuai
6. `tenants` untuk identitas penyewa
7. `bookings`
8. `tenancies`
9. `tenancy_extensions` jika diperlukan
10. `invoices` atau `charges`
11. `payments`
12. `payment_allocations` jika digunakan
13. `maintenance_records`
14. `operational_expenses`
15. `financial_transactions` atau pendekatan ledger yang sesuai
16. `audit_logs`

Nama tabel dapat disesuaikan jika terdapat desain yang lebih baik.

### Mandatory Database Rules

- Primary key konsisten.
- Foreign key valid.
- Cascading delete tidak boleh menghancurkan transaksi historis secara tidak sengaja.
- Referential integrity dipertahankan.
- Unique room code berlaku dalam lingkup properti.
- Index pada foreign key dan kolom filter utama.
- Monetary values menggunakan presisi yang aman.
- Semua operasi yang memerlukan atomicity menggunakan transaction.
- Seluruh entitas tenant-owned harus mempunyai jalur ownership yang dapat diverifikasi.

Jelaskan relasi antar tabel sebelum implementasi.

---

## 18. UI/UX REQUIREMENTS

Gunakan desain dashboard SaaS yang modern, profesional, dan mudah digunakan.

Karakter visual:

- Clean.
- Minimalist.
- Konsisten.
- Data-focused.
- Responsive.
- Accessible.

### 18.1 Owner Navigation

Minimal mencakup:

- Dashboard.
- Properti.
- Unit Kamar.
- Tipe Kamar & Harga.
- Booking.
- Penyewa & Masa Sewa.
- Pembayaran & Perpanjangan.
- Servis & Maintenance.
- Biaya Operasional.
- Laporan & Keuangan.
- Pengaturan Akun.

### 18.2 Super Admin Navigation

- Dashboard Platform.
- Manajemen Pemilik Kost.
- Manajemen Properti.
- Statistik Platform.
- Audit & Monitoring.
- Pengaturan Platform.

### 18.3 UX Behavior

- Loading state.
- Empty state.
- Error state.
- Validation feedback.
- Confirmation dialog untuk operasi sensitif.
- Search dan filtering.
- Pagination untuk tabel besar.
- Mobile-responsive navigation.
- Semantic HTML dan keyboard accessibility.

Hindari antarmuka yang terlalu padat atau menampilkan terlalu banyak informasi tanpa hierarki yang jelas.

---

## 19. SECURITY REQUIREMENTS

Implementasikan perlindungan terhadap:

- Broken access control.
- IDOR.
- SQL injection.
- Cross-site scripting.
- CSRF.
- Authentication brute force.
- Mass assignment vulnerabilities.
- Sensitive information exposure.
- Unauthorized file export.
- Cross-tenant data leakage.
- Race conditions pada booking dan pembayaran.

Seluruh input harus divalidasi pada backend.

Semua operasi administratif dan finansial penting harus memiliki jejak audit yang dapat diperiksa.

Jangan menyimpan secrets atau credentials secara hardcoded di source code.

---

## 20. BUSINESS RULES & EDGE CASES

Pastikan sistem menangani setidaknya skenario berikut:

1. Dua calon penyewa ingin booking kamar yang sama pada tanggal yang overlap.
2. Pemilik memperpanjang sewa ketika kamar sudah memiliki booking masa depan.
3. Booking dibatalkan setelah uang muka diterima.
4. Booking sudah confirmed tetapi penyewa tidak datang.
5. Booking dikonversi menjadi check-in tanpa menggandakan penerimaan uang muka.
6. Penyewa membayar sebagian tagihan.
7. Penyewa melunasi tagihan dalam beberapa transaksi.
8. Penyewa check-out sebelum masa sewa berakhir.
9. Tanggal akhir sewa sudah lewat tetapi penghuni belum check-out secara aktual.
10. Kamar kosong saat ini tetapi memiliki booking di masa depan.
11. Kamar sedang maintenance.
12. Pemilik mengubah harga saat masih ada masa sewa aktif.
13. Pemilik salah memasukkan nominal pembayaran.
14. Pembayaran uang muka harus dikembalikan.
15. Pemilik mencoba menghapus kamar yang memiliki histori transaksi.
16. Satu pemilik mencoba mengakses data milik pemilik lain.
17. Dua request pembayaran atau booking yang sama masuk secara bersamaan.
18. Pengguna mencoba mengekspor laporan tenant yang bukan miliknya.
19. Data sewa memiliki tanggal mulai dan akhir yang tidak valid.
20. Properti belum memiliki kamar atau transaksi.

Definisikan expected behavior untuk setiap skenario sebelum implementasi.

---

## 21. TESTING & QUALITY ASSURANCE

Implementasikan automated testing pada business logic utama.

### 21.1 Authentication Testing

- Registration.
- Login.
- Logout.
- Password reset.
- Email verification.
- Role restrictions.

### 21.2 Authorization Testing

- Pemilik tidak bisa mengakses properti orang lain.
- Pemilik tidak bisa mengubah pembayaran orang lain.
- Pemilik tidak bisa mengunduh laporan tenant lain.
- Super Admin memiliki scope akses sesuai policy.

### 21.3 Booking Testing

- Successful booking.
- Overlapping booking rejection.
- Concurrent booking protection.
- Booking cancellation.
- Booking-to-check-in conversion.
- Invalid date range.

### 21.4 Financial Testing

- Partial payment.
- Full payment.
- Overdue balance.
- Booking deposit allocation.
- Rent extension.
- Refund and reversal.
- Cash flow calculation.
- Report totals consistency.

### 21.5 Maintenance Testing

- Maintenance cost recording.
- Maintenance report.
- Financial expense synchronization.

Gunakan unit, feature, integration, dan end-to-end tests secara proporsional.

Test kritis harus berjalan otomatis dalam CI.

---

## 22. DEVELOPMENT METHODOLOGY

Jangan membangun seluruh aplikasi secara acak dalam satu tahap.

Gunakan fase pengembangan berikut:

### Phase 1 — Requirements & Architecture

- Analisis kebutuhan.
- Identifikasi ambiguity.
- Definisi business rules.
- Pemilihan integrasi Laravel-Svelte.
- Desain arsitektur.
- ERD dan relasi database.
- State transition design.
- Rencana testing.

### Phase 2 — Foundation

- Project setup.
- Authentication.
- Authorization.
- Multi-tenant isolation.
- Dashboard layout.
- Properti management.

### Phase 3 — Core Room Management

- CRUD kamar.
- CRUD tipe kamar.
- CRUD harga.
- Room availability engine.
- Occupancy dashboard.

### Phase 4 — Booking & Tenancy

- Booking management.
- Check-in.
- Check-out.
- Tenant records.
- Tenancy management.
- Booking conversion.
- Rent extension.

### Phase 5 — Finance

- Invoicing/charges.
- Payment recording.
- Down payment allocation.
- Outstanding balances.
- Maintenance expenses.
- Operational expenses.
- Cash flow calculation.

### Phase 6 — Analytics & Reports

- Owner analytics dashboard.
- Due date alerts.
- Financial reports.
- Excel exports.
- PDF exports.

### Phase 7 — Production Hardening

- Security review.
- Performance optimization.
- Concurrency testing.
- Database integrity tests.
- Accessibility review.
- Logging dan monitoring.
- Deployment preparation.

Selesaikan dan verifikasi setiap fase sebelum melanjutkan ke fase berikutnya.

---

## 23. EXPECTED DELIVERABLES

Sebelum menulis kode, hasilkan:

1. **Requirements Analysis** — ringkasan kebutuhan dan alur bisnis.
2. **Assumptions & Open Questions** — ketidakjelasan yang perlu diputuskan.
3. **System Architecture** — teknologi, pembagian komponen, dan data flow.
4. **Database Design** — ERD, tabel, relasi, constraints, dan indexing.
5. **Role & Permission Matrix** — hak akses setiap role.
6. **Business Process Flows** — booking, check-in, checkout, perpanjangan, pembayaran, maintenance.
7. **State Transition Matrix** — transisi booking, tenancy, dan payment.
8. **API/Route Design** — endpoints, request validation, dan authorization.
9. **UI Page Structure** — navigasi, halaman, komponen utama.
10. **Implementation Roadmap** — fase pengembangan dan dependency antar fitur.
11. **Testing Strategy** — test cases dan acceptance criteria.

Dokumentasikan hasil di folder `docs/` dengan struktur yang logis dan mudah dipelihara.

Dokumentasi yang telah disetujui nantinya akan menjadi **Single Source of Truth (SSOT)** untuk implementasi.

---

## 24. AGENT EXECUTION RULES

Ikuti instruksi berikut secara ketat:

1. Jangan langsung menulis kode sebelum melakukan analisis dan perencanaan.
2. Jangan mengasumsikan kebutuhan bisnis yang ambigu sebagai keputusan final.
3. Jika menemukan business rules yang belum didefinisikan, identifikasi dan berikan rekomendasi berdasarkan best practice.
4. Bedakan secara eksplisit antara requirement asli, rekomendasi, asumsi, dan keputusan yang sudah disetujui.
5. Jangan menambahkan fitur yang berada di luar scope tanpa alasan dan persetujuan.
6. Jangan mengorbankan database integrity, security, atau maintainability demi implementasi cepat.
7. Jangan membuat placeholder atau mock data seolah-olah merupakan implementasi fitur production.
8. Gunakan best practice Laravel, Svelte, dan relational database.
9. Hindari overengineering dan dependency yang tidak diperlukan.
10. Setiap modul harus memiliki validation, authorization, error handling, dan test cases yang sesuai.
11. Pastikan backend menjadi sumber kebenaran untuk data, kalkulasi, dan business rules.
12. Jangan mempercayakan validasi kritis kepada frontend.
13. Jika terdapat beberapa pendekatan teknis, jelaskan trade-off dan rekomendasikan solusi terbaik.
14. Hindari duplikasi business logic antar controller, service, dan frontend.
15. Terapkan database migrations yang dapat direproduksi.
16. Jangan melakukan destructive operation pada database tanpa strategi yang aman.
17. Berikan laporan implementasi setiap fase, termasuk file yang berubah, test yang dijalankan, dan masalah yang masih terbuka.
18. Jangan mengklaim suatu fitur berhasil sebelum diverifikasi melalui pengujian yang relevan.

---

## 25. INITIAL TASK

Untuk respons pertama, **jangan langsung membuat seluruh aplikasi**.

Lakukan langkah berikut secara berurutan:

1. Analisis seluruh kebutuhan Kost Management System.
2. Evaluasi apakah arsitektur multi-tenant sudah tepat.
3. Bandingkan integrasi Laravel + Inertia.js + Svelte dengan Laravel API + SvelteKit, lalu pilih yang paling sesuai.
4. Identifikasi business rules yang berpotensi menimbulkan data inconsistency atau financial miscalculation.
5. Rancang high-level architecture.
6. Rancang ERD awal.
7. Buat alur booking, check-in, pembayaran, dan perpanjangan sewa.
8. Buat daftar edge cases dan risiko teknis.
9. Berikan rekomendasi pembagian MVP dan fitur lanjutan.
10. Susun implementation roadmap beserta acceptance criteria.
11. Identifikasi keputusan bisnis yang masih memerlukan persetujuan saya.

**Jangan mulai implementasi sebelum tahap analisis selesai dan keputusan bisnis kritis dikonfirmasi.**

Prioritaskan correctness, security, user experience, dan maintainability dibandingkan kecepatan implementasi.
