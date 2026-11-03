# Deployment & Rollback Plan

Tanggal: 3 November 2026. **DRAFT / BLOCKED**: dokumen ini bukan otorisasi deployment production. Snapshot Actions pukul 20:21 WIB menunjukkan workflow lama aktif dan tidak ada run nonterminal saat itu. Pemeriksaan ulang pukul 21:37 WIB tidak dapat mengakses halaman repository GitHub (“Page not found”), dan akses `Actions: write` tidak tersedia. State run terbaru tidak diketahui; workflow belum dinonaktifkan dan tidak ada job dibatalkan. Gate di workspace lokal belum berlaku di GitHub. Arsitektur target tetap GitHub Actions → runner di LXC aplikasi → build Compose lokal → aktivasi; tanpa GHCR.

## Prasyarat dan kriteria keputusan

1. Selesaikan pemeriksaan dan mismatch di [laporan infrastruktur](INFRASTRUCTURE-VERIFICATION-REPORT.md), termasuk branch rules, runner sebenarnya, storage/permission dan gateway. Tetapkan satu kandidat commit dan cocokkan CI SHA dengan kandidat.
2. Buktikan backup database konsisten, storage privat dan APP_KEY di lokasi aman di luar node, serta restore di lingkungan terisolasi; catat hasil, waktu, RPO/RTO dan operator. Tetapkan image/konfigurasi/SHA sebelumnya dan retensinya.
3. Tinjau setiap migrasi, kompatibilitas app lama/baru, kebutuhan lock dan pemulihan. Rehearse build, aktivasi parsial, health failure dan rollback pada lingkungan uji; tetapkan waktu downtime, kapasitas dan abort threshold. Jangan menganggap image lama tersedia atau schema kompatibel tanpa bukti.
4. Sajikan kepada pemilik tindakan persis yang akan dilakukan, dampak, backup dan rollback; peroleh **persetujuan eksplisit terpisah** sebelum push/aktivasi workflow, perubahan GitHub/host, migrasi, restart, deployment atau perubahan ingress. Izin verifikasi read-only tidak mencakup tindakan ini.

## Urutan usulan setelah persetujuan

1. Operator memverifikasi kembali identitas target, perlindungan workflow, head `main`, kapasitas, integritas backup, release aktif dan kandidat yang lolos CI. Hentikan bila salah satu bukti tidak sesuai atau tidak tersedia.
2. Gunakan workflow dan skrip yang telah ditinjau pada commit kandidat; build image lokal bertanda SHA pada LXC target. Workflow tetap default-off sampai pemilik mengatur `KOSTKITA_DEPLOYMENT_ENABLED=true` setelah legacy workflow dinonaktifkan, branch `main` dilindungi dengan required checks, dan environment `production` dibatasi ke `main` dengan required reviewers. Verifikasi dan atur `KOSTKITA_EXPECTED_HOSTNAME`, serta ambang disk/inode `KOSTKITA_MIN_FREE_DISK_BYTES` dan `KOSTKITA_MIN_FREE_INODES` dari pengukuran host. Skrip mensyaratkan file `/opt/kostkita/.env` reguler milik user runner dengan mode `0600`, runner bernama `kost-kita`, LXC, hostname dan IP target sesuai, dan status schema tanpa migrasi pending. Pertahankan release sebelumnya; jangan prune image/volume global. Tidak ada backup evidence gate otomatis karena lokasi dan bukti restore belum tersedia; operator harus memastikan backup/rehearsal sebelum tindakan dan mencatat bukti pada checklist.
3. Jika terdapat migrasi pending, hentikan deployment otomatis. Migrasi hanya boleh direncanakan dan disetujui terpisah setelah kompatibilitas, backup dan dampak penguncian dinilai. Jangan gunakan rollback migration otomatis.
4. Aktifkan service secara terkoordinasi pada window disetujui. Compose tidak menjamin switch atomik; antisipasi downtime dan campuran versi. Skrip mencatat release manifest, lalu memeriksa container/SHA app, web dan scheduler, proses scheduler dan registry tugas, migration status, HTTP 200, hash aset Vite, dan revision endpoint pada IP lokal serta domain publik. Pemeriksaan registry hanya membuktikan tugas terdaftar, bukan bahwa task berhasil dieksekusi; cek scheduler/queue dan ingress melalui observability/operator setelah aktivasi. Hasil gagal berarti deployment gagal meskipun build sukses.

## Recovery jika gagal

- Hentikan aktivasi lanjutan dan catat service/image/SHA, schema, error serta waktu. Jaga data dan volume; jangan menjalankan `down -v`, prune global atau `migrate:rollback`.
- Untuk kegagalan setelah aktivasi, skrip mencoba mengaktifkan kembali image app/web/scheduler yang sebelumnya tercatat dengan Compose file kandidat, memverifikasi container/image serta health container web, lalu mengembalikan status gagal ke GitHub Actions. Ini rollback image-only: konfigurasi Compose kandidat tetap berlaku dan tidak dikembalikan ke konfigurasi lama. Pemulihan belum diuji pada Docker LXC. Operator harus memeriksa SHA/health/DB/asset/ingress dari jalur publik; bila rollback otomatis gagal, gunakan rehearsal yang disetujui. Jangan menebak nama project atau volume.
- Jika schema/data tidak kompatibel, isolasi trafik sesuai rencana yang disetujui dan pilih perbaikan maju atau restore dari backup yang diuji, dengan persetujuan untuk tindakan data. Jangan mengasumsikan restore tidak kehilangan transaksi setelah waktu backup.
- Dokumentasikan keadaan akhir, kehilangan data jika ada, durasi gangguan dan hasil verifikasi. Jika recovery tidak dapat dibuktikan, tetap nyatakan incident/blocked dan eskalasi kepada operator.

## Production execution checklist

- [ ] Legacy workflow ID `405389177` telah dinonaktifkan setelah pemeriksaan baru membuktikan tidak ada run aktif/queued; tidak ada job dibatalkan.
- [ ] Required checks pada protected `main`, workflow permissions, runner group/labels, serta reviewer dan branch restriction pada environment `production` telah dibaca dan diverifikasi.
- [ ] Proxmox node, CT ID, hostname/IP, lokasi fisik runner, `/opt/kostkita`, service dan running SHA cocok dengan source of truth.
- [ ] Nilai hostname dan disk/inode minimum berasal dari pengukuran target; Docker build dan PostgreSQL acceptance berhasil pada lingkungan yang sesuai.
- [ ] Secret-safe runtime configuration preflight lulus pada candidate; setting live diverifikasi tanpa menampilkan `.env` atau secret.
- [ ] Backup off-node terbaru, cakupan DB/private files/APP_KEY, integritas dan restore rehearsal terisolasi terbukti; RPO/RTO dicatat.
- [ ] Schema/migration status diketahui; perubahan schema kompatibel dengan versi lama dan baru, atau migrasi telah punya rencana serta persetujuan terpisah.
- [ ] Versi/image sebelumnya tersedia; image rollback app/web/scheduler dan dampak perubahan konfigurasi Compose telah direhearse. Image rollback tidak memulihkan konfigurasi Compose lama, jadi perubahan config memiliki prosedur pemulihan operator yang telah disetujui. Kapasitas dan retensi image/release disepakati.
- [ ] Public ingress, `/up`, active SHA, assets, DB readiness dan operasional scheduler/queue terverifikasi.
- [ ] Pemilik memberikan persetujuan final tertulis untuk mengaktifkan workflow atau memicu deployment pada SHA dan waktu yang ditentukan.
