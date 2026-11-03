# Strategi Kualitas dan Pengujian

## Prinsip verifikasi

Uji aturan pada lapisan backend karena backend adalah sumber kebenaran untuk akses, jadwal, status, serta uang. Pilih cakupan unit, feature/integration, dan end-to-end sesuai risiko. Kriteria pengujian yang menyentuh keputusan terbuka harus menunggu keputusan itu; jangan mengabadikan asumsi sebagai acceptance test.

## Area wajib diverifikasi

### Autentikasi dan akses

- Registrasi, login/logout, reset kata sandi, dan verifikasi email sesuai metode yang disetujui.
- Pembatasan role; pemilik tidak dapat mengakses properti, pembayaran, atau ekspor pemilik lain.
- Super Admin hanya melakukan tindakan lintas tenant yang termasuk izinnya.
- Ekspor menerapkan scope dan filter yang sama dengan laporan.

#### Acceptance tests: akses dan privasi tenant

| ID | Given / when | Acceptance criteria |
|---|---|---|
| ACCESS-01 | Pemilik mencoba membaca, mengubah, membayar, atau mengekspor resource tenant lain dengan ID langsung atau filter request. | Backend menolak akses secara aman; tidak ada data tenant lain di props, pesan error, hasil export, atau perubahan database. |
| ACCESS-02 | Akun belum terverifikasi, suspended, atau role yang salah mencoba route `/owner/*` atau `/platform/*`; pemilik mencoba route platform. | Akses ditolak menurut role/status; Super Admin hanya mendapat aksi platform yang eksplisit; role dan tenant tidak dapat diubah lewat input klien. |
| PRIVACY-01 | Form resident atau request aplikasi mengirim nomor KTP/identitas resmi, atau mencoba mengambil bukti settlement tanpa otorisasi. | Identitas resmi tidak disimpan/dikirim ke props; bukti settlement hanya dapat diakses oleh pemilik yang berwenang melalui route terlindungi. |

#### Acceptance tests: tarif katalog dan snapshot kesepakatan

| ID | Given / when | Acceptance criteria |
|---|---|---|
| PRICE-01 | Tarif tipe kamar dan tarif khusus kamar sama-sama berlaku pada tanggal booking/check-in. | Resolver server memakai tarif khusus kamar sebagai nilai awal; bila override kamar tidak berlaku, gunakan tarif tipe yang berlaku. |
| PRICE-02 | Pemilik memasukkan harga kesepakatan ketika tidak ada tarif katalog yang berlaku; atau dua periode harga untuk target katalog yang sama bertumpang tindih. | Harga manual yang valid diterima; periode bertumpang tindih untuk target sama ditolak, sedangkan periode tipe dan kamar boleh bertumpang tindih. |
| PRICE-03 | Harga katalog berubah setelah booking/tenancy dibuat atau ketika tenancy diperpanjang. | Booking, tenancy, dan invoice lama mempertahankan snapshot; kesepakatan perpanjangan menyimpan harga baru yang disetujui secara terpisah. |

### Ketersediaan dan booking

- Rentang tanggal tidak valid ditolak.
- Periode overlap ditolak berdasarkan definisi interval tunggal.
- Status booking mengubah pemblokiran sesuai aturan.
- Booking yang dikonversi membuat satu tenancy tanpa penerimaan uang ganda.
- Dua request bersamaan untuk periode yang sama tidak dapat membuat dua booking confirmed.
- Perpanjangan yang konflik dengan booking masa depan dihentikan.

#### Acceptance tests: grace period dan NO_SHOW

| ID | Given / when | Acceptance criteria |
|---|---|---|
| BOOK-NOSHOW-01 | Booking confirmed memiliki rencana check-in 10 November 2026; scheduler berjalan sebelum 12 November 2026 pukul 00.00 WIB. | Status tetap confirmed selama grace period sampai akhir 11 November dan kamar tetap terblokir. |
| BOOK-NOSHOW-02 | Check-in belum dicatat dan scheduler berjalan pada 12 November 2026 pukul 00.00 WIB atau sesudahnya. | Dalam satu transaksi, status menjadi NO_SHOW; kamar tidak lagi diblokir; tidak ada refund, forfeiture, atau settlement deposit otomatis. |
| BOOK-NOSHOW-03 | Scheduler memproses booking confirmed yang sudah lewat batas waktu lebih dari sekali. | Transisi tetap satu kali, tidak ada transaksi atau audit event finansial duplikat, dan proses dapat diulang dengan aman. |
| BOOK-NOSHOW-04 | Pemilik memperpanjang grace period sebelum waktu expiry. | Batas baru tersimpan, aktor/waktu/nilai lama-baru tercatat, dan booking tetap memblokir sesuai periode yang disepakati. |
| BOOK-NOSHOW-05 | Pemilik mencoba memperpanjang grace period setelah expiry, atau sebelum expiry tetapi berbenturan dengan tenancy/booking lain. | Setelah expiry, perpanjangan ditolak dan backend merekonsiliasi booking menjadi NO_SHOW tanpa mengubah tanggal rencana; untuk konflik sebelum expiry, perpanjangan ditolak, status tetap confirmed, dan batas waktu tidak berubah. |
| BOOK-NOSHOW-06 | Scheduler menandai NO_SHOW bersamaan dengan request booking baru untuk kamar/periode sama. | Penguncian dan revalidasi availability mencegah dua booking confirmed yang overlap. |
| BOOK-NOSHOW-07 | Scheduler terlambat; check-in diminta pada atau setelah expiry sementara booking masih berstatus confirmed. | Backend menolak check-in dan secara atomik merekonsiliasi booking ke NO_SHOW; tenancy tidak dibuat. |
| BOOK-NOSHOW-08 | Scheduler terlambat; availability diperiksa setelah expiry sementara booking masih tersimpan confirmed. | Backend merekonsiliasi booking ke NO_SHOW sebelum memutuskan availability; booking kedaluwarsa tidak terus memblokir kamar. |

### Masa sewa

- Check-in langsung dan dari booking tersimpan lengkap.
- Check-out mempertahankan histori.
- Tanggal akhir masa sewa tidak tercampur dengan jatuh tempo pembayaran.
- Perhitungan durasi memakai kalender sebenarnya.
- Check-out lebih awal mengubah tenancy menjadi terminated; tanggal akhir rencana saja tidak melepas kamar.

#### Acceptance tests: check-in tenancy terjadwal

| ID | Given / when | Acceptance criteria |
|---|---|---|
| TENANCY-CHECKIN-01 | Tenancy langsung dibuat untuk tanggal mendatang dengan status `scheduled`; pemilik mencatat check-in aktual kemudian dan request diulang. | Sebelum check-in, tenancy tidak aktif dan tidak mendapat invoice awal; aktivasi memvalidasi ulang availability, menyimpan tanggal/waktu aktual serta billing anchor dari tanggal aktual, lalu membuat tepat satu invoice pertama yang jatuh tempo pada tanggal check-in. Generator invoice tidak menerbitkan invoice bagi tenancy `scheduled`. |

#### Acceptance tests: harga perpanjangan

| ID | Given / when | Acceptance criteria |
|---|---|---|
| TENANCY-EXTENSION-PRICE-01 | Perpanjangan dengan tarif baru dicatat di tengah siklus bulanan. | Invoice siklus berjalan tetap memakai tarif lama; tarif baru mulai pada tanggal awal siklus berikutnya dan tidak membuat adjustment prorata. |
| TENANCY-EXTENSION-PRICE-02 | Perpanjangan dengan tarif baru dicatat tepat pada awal siklus bulanan. | Tarif baru berlaku untuk siklus yang baru dimulai; invoice lama tidak berubah dan tarif baru tersimpan sebagai event perpanjangan. |

#### Acceptance tests: billing cycle dan prorata

| ID | Given / when | Acceptance criteria |
|---|---|---|
| BILL-CYCLE-01 | Tenancy check-in tanggal 31 Januari dengan billing anchor day 31. | Siklus berikutnya dimulai 28 Februari, lalu 31 Maret, 30 April, dan 31 Mei; anchor 31 tidak drift. |
| BILL-CYCLE-02 | Bulan Februari merupakan leap year. | Siklus anchor 29 tetap memakai 29 Februari dan kembali ke anchor asli pada bulan berikutnya. |
| BILL-CYCLE-03 | Invoice diterbitkan lebih awal sebelum awal siklus berikutnya. | Due date tetap pada tanggal mulai siklus; invoice tidak mendapat grace period otomatis. |
| BILL-CYCLE-04 | Due date terlewati dan saldo invoice masih ada. | Saldo menjadi OVERDUE; kontrak/tenancy tidak otomatis dibatalkan. |
| BILL-CYCLE-05 | Scheduler/generator menjalankan periode tenancy yang sama lebih dari sekali. | Maksimal satu invoice untuk tenancy dan siklus tersebut. |
| PRORATA-01 | Tenancy berakhir sebelum akhir siklus bulanan. | Used Days adalah overlap masa huni dan siklus dengan interval [start_date, end_date); Total Cycle Days adalah jumlah hari kalender aktual pada siklus. |
| PRORATA-02 | Check-out terjadi pada bulan Februari normal/leap year atau siklus ber-anchor tanggal 29–31. | Nilai menggunakan jumlah hari kalender aktual; tidak menggunakan pembagi tetap 30 hari atau hitungan inklusif ganda. |
| PRORATA-03 | Nilai prorata memiliki pecahan Rupiah. | Decimal arithmetic dipakai; nilai akhir dibulatkan sekali ke Rupiah terdekat dengan half-up. |
| PRORATA-04 | Perhitungan prorata menghasilkan saldo/refund. | Invoice dan payment awal tetap tersimpan; tidak ada refund otomatis; adjustment dan refund dicatat sebagai transaksi terpisah yang berizin dan dapat diaudit. |
| PRORATA-05 | Check-out aktual terjadi pada siklus berjalan sebelum scheduler membuat invoice siklus tersebut. | Backend membuat kewajiban siklus yang diperlukan lalu mencatat credit prorata dalam transaksi; invoice dan snapshot tarif tetap utuh, serta retry tidak menggandakan credit. |
| RENT-REFUND-01 | Pemilik mencatat refund sewa aktual atas alokasi receipt dengan credit adjustment yang sah. | Bukti transfer privat wajib; tanpa bukti tidak terbentuk cash event. Dengan bukti, satu `rent_refund` outflow dan reversal alokasi tercatat serta tertaut ke receipt asal; receipt dan credit adjustment tetap ada; retry idempotent; bukti hanya dapat diunduh oleh owner tenant terkait. |
| RENT-REFUND-02 | Dua request refund bersamaan mencoba memakai sisa credit invoice yang sama dari receipt berbeda. | Penguncian invoice membuat validasi ulang berurutan; total refund tidak pernah melebihi credit yang disetujui dan request yang melampaui saldo ditolak tanpa cash event/reversal parsial. |

### Keuangan

- Pembayaran sebagian dan pelunasan melalui beberapa payment menghasilkan saldo benar.
- Alokasi uang muka tidak tercatat dua kali saat konversi booking.
- Refund/reversal menghasilkan histori dan arus kas yang benar.
- Biaya servis/operasional baru dihitung sebagai kas keluar saat dibayar dan tepat satu kali.
- Dasbor, laporan, dan XLSX/PDF menghasilkan total yang sama untuk filter yang sama.
- Nominal menggunakan presisi aman; operasi bersamaan/berulang tidak menggandakan transaksi.

#### Acceptance tests: klasifikasi receipt

| ID | Given / when | Acceptance criteria |
|---|---|---|
| PAYMENT-ALLOC-01 | Pemilik mencatat satu transfer Rp1.000.000 dengan alokasi Rp700.000 ke invoice sewa dan Rp300.000 ke deposit jaminan. | Satu cash event `mixed_receipt` Rp1.000.000 dibuat; alokasi sewa dan deposit tepat sebesar transfer, laporan menampilkan rincian dua kategori, dan total kas masuk hanya dihitung sekali. |

#### Acceptance tests: deposit jaminan dan laporan kas

| ID | Given / when | Acceptance criteria |
|---|---|---|
| DEPOSIT-01 | Deposit jaminan diwajibkan Rp500.000 dan pemilik mencoba mencatat pembayaran baru Rp700.000. | Validasi menolak pembayaran sebelum konfirmasi; tidak tercipta pembayaran/cash event Rp700.000. |
| DEPOSIT-02 | Setelah booking dicatat dan sebelum receipt deposit pertama, pemilik mengubah nominal deposit jaminan yang disepakati. | Nilai baru tersimpan dengan aktor, waktu, nilai lama/baru, dan alasan pada audit trail; perubahan ditolak setelah receipt pertama dicatat. |
| DEPOSIT-03 | Deposit jaminan Rp500.000 benar-benar diterima di luar aplikasi sebesar Rp700.000. | Cash receipt Rp700.000 dicatat utuh; Rp500.000 masuk liability deposit dan Rp200.000 temporary liability; kelebihan tidak menjadi rental revenue/kredit otomatis. |
| DEPOSIT-04 | Temporary liability Rp200.000 kemudian direfund. | Catat cash outflow Rp200.000 dan pengurangan temporary liability; jejak penerimaan awal tetap ada. |
| DEPOSIT-05 | Deposit jaminan Rp500.000 direfund setelah tenancy selesai. | Catat cash outflow dan pengurangan liability Rp500.000; jangan kategorikan sebagai biaya operasional atau rental refund. |
| DEPOSIT-06 | Booking berubah menjadi NO_SHOW. | Deposit tidak otomatis direfund atau dihanguskan; liability dan transaksi kas tetap sampai settlement eksplisit. |
| DEPOSIT-07 | Booking dengan deposit terbayar dikonversi menjadi tenancy. | Deposit booking tetap menjadi satu liability yang sama; tidak dibuat payment, cash event, atau saldo deposit kedua. |
| DEPOSIT-08 | Pemilik menyelesaikan deposit dengan invoice tenant yang belum dibayar atau kerusakan di luar keausan wajar. | Hanya kewajiban yang tercatat/diizinkan kontrak atau kerusakan dengan nilai wajar dan bukti dapat dideduct; rincian per item, dasar kontrak, bukti, aktor owner, approval, dan audit tersimpan. |
| DEPOSIT-09 | Pemilik mencoba mendeduct keausan wajar, maintenance rutin, biaya tanpa bukti, atau biaya arbitrer. | Deduction ditolak dan liability tidak berubah. |
| DEPOSIT-10 | Check-out/pembatalan/NO_SHOW memerlukan settlement dengan saldo tidak disengketakan dan klaim kerusakan tertentu yang didukung bukti. | Pernyataan settlement sementara diterbitkan dan saldo tidak disengketakan direfund paling lambat akhir hari ke-7 Asia/Jakarta; hanya nominal klaim yang didukung ditahan, serta notifikasi WhatsApp/audit tercatat. Acknowledgement tenant tidak menjadi syarat refund saldo tidak disengketakan; klaim tertahan difinalkan paling lambat hari ke-30. |
| DEPOSIT-11 | Estimasi kerusakan yang didukung bukti belum menjadi biaya aktual pada akhir hari ke-30 Asia/Jakarta. | Sistem menandai settlement overdue dan meminta aksi pemilik; tidak ada transfer/refund otomatis. Pemilik memfinalisasi dengan biaya aktual yang didukung bukti, atau nominal deduction nol dan refund aktual seluruh dana tertahan bila bukti final belum ada. |
| DEPOSIT-12 | Deposit digunakan untuk melunasi invoice melalui settlement non-kas. | Liability dan saldo invoice berkurang atomik; movement audit tercatat; tidak ada cash event baru dan Arus Kas Bersih tidak berubah. |
| DEPOSIT-13 | Staf atau aktor selain pemilik mencoba menyetujui refund/deduction. | Aksi ditolak; hanya Pemilik Kost yang berwenang menyetujui settlement di MVP. |
| DEPOSIT-14 | Booking dibatalkan atau menjadi NO_SHOW dan pemilik mencatat hasil kesepakatan settlement melalui WhatsApp. | Sistem tidak menetapkan refund/forfeiture otomatis; setiap refund atau deduction aktual memerlukan persetujuan pemilik, dasar kesepakatan, dan audit trail. |
| DEPOSIT-15 | Klaim kerusakan Rp200.000 ditahan; biaya perbaikan aktual yang wajar dan didukung bukti adalah Rp120.000 sebelum hari ke-30. | Pemilik memfinalisasi klaim; Rp120.000 dicatat sebagai deduction liability, Rp80.000 direfund dengan bukti transfer aktual, resolution menghubungkan claim dan movement, dan settlement baru finalized setelah seluruh saldo diselesaikan. |
| DEPOSIT-16 | Pemilik membuka satu-satunya settlement untuk liability deposit dengan total line di bawah saldo akun. | Proposal ditolak; line refund eksplisit untuk saldo yang tidak dideduct harus ditambahkan sehingga seluruh liability memiliki jalur penyelesaian. |
| DEPOSIT-17 | Dua line settlement mengurangi invoice sama yang saldonya lebih kecil dari jumlah kedua line. | Approval menghitung total per invoice di bawah lock; seluruh approval ditolak dan tidak ada movement deduksi yang dibuat jika aggregate melampaui outstanding invoice. |
| DEPOSIT-18 | Tenancy langsung berstatus scheduled, deposit sudah diterima, lalu pemilik membatalkan sebelum check-in. | Waktu terminasi tercatat; owner dapat membuka settlement dan mencatat refund/deduction aktual dengan deadline dihitung dari peristiwa terminasi. |
| DEPOSIT-19 | Sisa kewajiban deposit Rp500.000 dan pemilik merekonsiliasi kas eksternal Rp700.000 dengan alokasi deposit Rp300.000. | Request ditolak; deposit menerima tepat Rp500.000 dan hanya Rp200.000 menjadi temporary liability. Jika deposit sudah lunas, surplus penuh masuk temporary liability. |
| DEPOSIT-20 | Temporary liability direfund secara aktual. | Cash outflow dan pengurangan liability atomik; audit mencatat liability, jumlah, alasan, aktor, dan cash transaction ID; retry tidak membuat movement atau audit duplikat. |
| DEPOSIT-21 | Pemilik membatalkan draft settlement karena proposal perlu dikoreksi lalu membuka proposal pengganti; atau mencoba membatalkan draft yang sudah disetujui. | Pembatalan memerlukan alasan dan membuat audit trail, draft/line lama tetap tersimpan tanpa movement, proposal pengganti dapat dibuka, dan settlement approved tidak dapat dibatalkan. |
| REPORT-CASH-01 | Laporan menghitung penerimaan sewa, deposit jaminan yang diterima saat booking/check-in, refund deposit, maintenance, dan biaya operasional. | Deposit muncul dalam satu kategori kas dan satu liability; tidak dihitung sebagai rental revenue atau dicatat dua kali sebagai DP dan deposit. |
| REPORT-CASH-02 | Invoice belum dibayar atau dibayar sebagian. | Outstanding invoice ditampilkan terpisah dan tidak dihitung sebagai uang diterima. |
| REPORT-CASH-03 | Nilai yang sama ditampilkan di dashboard, XLSX, dan PDF dengan filter identik. | Total kas masuk, kas keluar, Arus Kas Bersih, liability deposit, dan outstanding invoice sama pada semua output. |
| REPORT-CASH-04 | Refund atau deduction deposit dibukukan. | Refund mengurangi kas dan liability tanpa menambah biaya operasional; deduction non-kas mengurangi liability serta kewajiban tenant tanpa memengaruhi Arus Kas Bersih. |
| REPORT-INVOICE-AS-OF-01 | Pembayaran invoice atau adjustment diposting setelah tanggal akhir filter laporan. | Snapshot invoice per tanggal akhir tidak memasukkan event setelah tanggal tersebut; due date dan saldo historis menentukan overdue, meskipun saldo saat ini sudah berubah. |
| REPORT-DASHBOARD-REMINDERS-01 | Owner membuka dashboard dengan invoice mendekati jatuh tempo/overdue, masa sewa mendekati akhir, refund deposit melewati hari ke-7, atau held claim melewati hari ke-30. | Setiap pengingat muncul dalam kategori terpisah dengan saldo, tanggal, sisa/kelebihan hari, dan tautan tindak lanjut; query tidak mengubah status/ledger dan hanya menampilkan data tenant owner tersebut. |
| REPORT-PERIOD-SUMMARY-01 | Pemilik membuka dashboard atau laporan dengan filter periode yang memuat kas, booking, dan maintenance. | Tren dashboard berisi enam bulan kas aktual hingga tanggal laporan; jumlah booking dikelompokkan per status menurut tanggal rencana check-in; pembayaran maintenance dikelompokkan per kategori pekerjaan menurut tanggal kas, tanpa mengubah total pada tabel atau export. |
| REPORT-EXPORT-LIMIT-01 | Ekspor memiliki 5.000 baris atau kurang; atau hasil query melebihi batas itu. | Hasil dalam batas diunduh dengan formula/metadata filter yang sama seperti halaman; hasil di atas batas dihentikan pada baris ke-5.001 dan ditolak sebelum baris sisanya diproses atau file dirender, dengan instruksi untuk mempersempit filter. |

Aturan settlement deposit ditetapkan di [Keuangan dan Laporan](../business/finance.md) dan [ADR-003](../decisions/ADR-003-security-deposit-settlement.md).

### Maintenance dan laporan

- Catatan servis dapat terkait properti dan kamar opsional.
- Status kamar maintenance mencegah pemesanan selama periode yang relevan.
- Rekap biaya mengikuti tanggal pembayaran dan filter.

#### Acceptance tests: maintenance dan pembayaran biaya

| ID | Given / when | Acceptance criteria |
|---|---|---|
| MAINT-01 | Pekerjaan maintenance yang memblokir dijadwalkan pada rentang tertentu; pekerjaan non-blocking dibuat atau jadwal dibatalkan/diselesaikan. | Kamar terblokir hanya pada rentang `[start_date, end_date)` yang aktif; status non-blocking, selesai, atau dibatalkan tidak terus memblokir availability. |
| MAINT-02 | Pemilik menjadwalkan maintenance yang memblokir kamar pada rentang booking `confirmed` atau tenancy `scheduled`/`active`. | Aksi ditolak dan pekerjaan tetap `reported`; tidak ada jadwal blocking tersimpan atau perubahan availability. Pemilik dapat menyimpan rentang sebagai pekerjaan non-blocking atau mencoba tanggal lain. |
| EXPENSE-CASH-01 | Biaya maintenance/operasional dibayar sekaligus atau sebagian, lalu request pembayaran dikirim ulang. | Kas keluar dicatat sebesar uang yang benar-benar dibayar dan dialokasikan maksimal sebesar sisa kewajiban; biaya belum dibayar bukan kas keluar dan request ulang tidak menggandakan event. |

### Deployment pada homelab

Verifikasi infrastruktur produksi mengikuti [Panduan Deployment KostKita](../deployment/DEPLOYMENT-GUIDE.md), dengan baseline gateway pada [HOMELAB-SSOT](../deployment/HOMELAB-SSOT.md) dan keputusan khusus aplikasi pada [ADR-004](../decisions/ADR-004-proxmox-docker-deployment.md).

| ID | Given / when | Acceptance criteria |
|---|---|---|
| DEPLOY-HOME-01 | LXC aplikasi KostKita yang sudah dibuat diaudit sebelum Docker deployment. | LXC berbeda dari ingress LXC 10080; ID `10026`, IP `192.168.0.26`, Debian 13, gateway LAN, resource, status Docker/Compose, nesting/keyctl, dan firewall diverifikasi terhadap konfigurasi aktual. Tidak ada perubahan konfigurasi sebelum rencana deployment disetujui. |
| DEPLOY-HOME-02 | Aplikasi dipublikasikan melalui Cloudflare Tunnel. | Cloudflare Edge menerima HTTPS, tunnel menuju Traefik File Provider, lalu port HTTP aplikasi pada IP LAN yang tercatat; tidak ada port forwarding router. Versi Traefik aktif diverifikasi dan menggunakan target `v3.7.17` sebelum go-live. |
| DEPLOY-HOME-03 | Service publik dan database diperiksa dari luar Compose. | Hanya aplikasi web yang dijangkau gateway; PostgreSQL `5432` tidak dipublikasikan ke LAN/internet. Traefik tidak menggunakan Docker Provider/socket; Dashboard, SSH, dan Proxmox Web UI tidak memiliki route publik dari deployment KostKita. |
| DEPLOY-HOME-04 | Workflow deploy berjalan pada runner LXC aplikasi. | Runner diperlakukan sebagai principal produksi berhak administratif; hanya branch terlindungi/pemicu manual tepercaya yang deploy. Image dibangun lokal tanpa GHCR; `.env` dikecualikan dari sinkronisasi dan Docker build context, tidak masuk log/artifact/image; health check sukses sebelum deploy dianggap selesai. |
| DEPLOY-HOME-05 | Request publik diteruskan melalui Traefik. | Traefik hanya mempercayai forwarded headers dari subnet Docker `web-gateway` yang dipakai cloudflared, bukan `insecure` atau seluruh LAN. Laravel menghasilkan URL HTTPS dan hanya mempercayai proxy `192.168.0.80`; client/IP scheme tidak dapat dipalsukan dari jalur lain. |
| DEPLOY-HOME-06 | Backup PostgreSQL dan pemulihan diuji. | Backup tersedia di storage di luar node Proxmox dan restore dapat memulihkan database ke keadaan konsisten. |
| DEPLOY-HOME-07 | Ingress KostKita hendak diaktifkan. | Inventaris layanan sudah berisi FQDN, ID/IP LXC aplikasi, port HTTP, dan file Dynamic Config aktual; PostgreSQL tidak mendapat hostname atau route publik. |

## Kriteria selesai

Modul bisnis selesai bila acceptance checks untuk area terdampak lulus, akses tenant silang tertutup, perubahan multi-record atomik, dan hasil UI/laporan konsisten dengan data backend. Gunakan stack [Laravel + Inertia + Svelte](../decisions/ADR-001-frontend-integration.md), PostgreSQL untuk produksi, dan SQLite untuk pengembangan lokal/test. Target deployment tercatat di [ADR-004](../decisions/ADR-004-proxmox-docker-deployment.md) dan [HOMELAB-SSOT](../deployment/HOMELAB-SSOT.md).
