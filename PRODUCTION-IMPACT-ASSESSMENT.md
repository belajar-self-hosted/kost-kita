# Production Impact Assessment — KostKita

Diperbarui 4 November 2026. Pemilik melaporkan GitHub Environment/variables staging telah disetel dan Docker free space berada di atas threshold. Nilai GitHub belum diverifikasi melalui akses terautentikasi. Tidak ada runtime production/staging yang diubah dari sesi ini.

## Readiness

- **TESTING/STAGING READY: BELUM SIAP.** Buktikan dataset dummy dan runtime CT 10026, verifikasi Environment/variables yang dilaporkan, serta tinjau keamanan ingress. Workflow branch belum diterapkan ke GitHub.
- **PRODUCTION READY: TIDAK SIAP.** Backup/restore dan gerbang production lain belum dipenuhi.
- **Backup testing: DEFERRED — ACCEPTED RISK.** Pemilik menerima kehilangan seluruh data percobaan. Tidak ada backup atau restore yang diasumsikan tersedia.

## Risiko

| Risiko                           | Dampak                                                                                                                | Bukti / mitigasi                                                                                                                                           |
| -------------------------------- | --------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Kehilangan data testing          | Data DB/file dapat hilang dan tidak dipulihkan.                                                                       | Diterima hanya untuk testing; gunakan dummy data. Tidak ada volume/DB dihapus.                                                                             |
| Data nyata di lingkungan testing | Data pribadi/transaksi dapat terekspos atau hilang.                                                                   | UNVERIFIED; pastikan dummy-only sebelum deployment dan hentikan jika ada data operasional nyata.                                                           |
| Workflow lama                    | Dapat melakukan manual dispatch, migration paksa, in-place deployment dan prune global.                               | ID 405389177 disabled_manually; snapshot 23:37 WIB tidak ada run nonterminal. Jangan aktifkan kembali.                                                     |
| Workflow baru belum di main      | Workflow branch belum merged ke `main`.                                                                               | Default-off, tanpa workflow_dispatch; Environment/variables staging dilaporkan pemilik tetapi perlu verifikasi terautentikasi. Jangan ubah flag false.     |
| Build dan Compose activation     | Resource build berbagi LXC dengan aplikasi; aktivasi beberapa service tidak atomik dan dapat downtime/versi campuran. | Resource pemakaian belum diketahui; ukur dan rehearsal.                                                                                                    |
| Availability endpoint publik     | Domain merespons; workflow akan mengganti stack Compose yang sama.                                                    | Durasi gangguan belum diketahui. Tetapkan jendela dan beri tahu tester sebelum aktivasi; bila layanan harus selalu tersedia, perlu stack staging terpisah. |
| Migration                        | Release dengan migration pending berhenti; migration/rollback database tidak otomatis.                                | Tinjau kompatibilitas dan jalankan tindakan terpisah; jangan otomatis migration destruktif.                                                                |
| Health/release                   | /up HTTP 200 HTML tidak membuktikan DB atau commit. /release.txt 404.                                                 | Workflow lokal memeriksa SHA, app/DB, asset, scheduler; belum dijalankan pada LXC.                                                                         |
| Ingress dashboard/API            | api.insecure=true; 8080 bind ke 0.0.0.0 dan IPv6; Docker provider/socket aktif.                                       | MISMATCH. Jangkauan internet/firewall belum diketahui. Pemilik ingress meninjau sebelum menganggap jalur publik aman.                                      |
| Dependency advisory              | Dua dependency JavaScript terlapor severity low; tidak ada fix yang ditawarkan saat audit.                            | composer audit bersih. Catat dan tinjau ulang saat update package; jangan menonaktifkan security checks.                                                   |
| Rollback                         | Image rollback tidak memulihkan Compose config, schema atau database.                                                 | Best-effort dan belum direhearse. Pertahankan volume/data; recovery manual terencana jika gagal.                                                           |

## Migration dan kompatibilitas

Deployment script fail-closed jika migration pending dan tidak menjalankan migrate atau migrate:rollback. Karena itu release dengan perubahan schema tertahan sampai migration dinilai dan ditangani terpisah. Review expand/contract, lock/durasi, kompatibilitas aplikasi lama/baru dan kondisi gagal. Jangan menyimpulkan schema aman dari status nama migration saja.

## Aktivasi testing bertahap

GitHub Environment bernama staging bukan isolasi runtime. Workflow memakai /opt/kostkita/.env dan Compose container/volume KostKita yang sudah ada pada CT 10026. Deployment akan mengganti stack tersebut; domain dapat dijangkau, tetapi Cloudflare origin belum dibuktikan. Lanjutkan hanya setelah pemilik memastikan seluruh data dummy. Project, volume, env file dan ingress terpisah belum dibuat.

1. Pastikan data target dummy dan hasil runtime CT 10026 cocok dengan repository.
2. Biarkan repository variable KOSTKITA_DEPLOYMENT_ENABLED tidak ada atau false ketika perubahan workflow masuk.
3. Verifikasi Environment `staging` membatasi branch `main`, hostname `kost-kita`, thresholds disk `10737418240` bytes dan inode `500000`. Nilai tersebut dilaporkan pemilik; endpoint GitHub variables memerlukan autentikasi untuk konfirmasi. Docker free space yang dilaporkan adalah `21593849856` bytes dan `1672456` inode.
4. Sebelum flag true, cek lagi legacy disabled dan tidak ada CI main tertunda. Flag true berarti workflow tests main berikutnya yang sukses dapat memicu deployment.
5. Pantau workflow dan verifikasi candidate SHA serta health. Jika gagal, pipeline harus gagal; jangan lewati quality gate atau melakukan migration destruktif.
6. Tanpa backup, kehilangan data testing mungkin permanen.

## Production gate

Sebelum data operasional digunakan, wajib tersedia backup PostgreSQL/file privat off-node dengan perlindungan APP_KEY, retention dan integrity check, restore rehearsal terisolasi, RPO/RTO, keamanan gateway, data controls, migration plan, capacity/monitoring dan rollback rehearsal. PRODUCTION READY tetap NO sampai bukti itu lulus.
