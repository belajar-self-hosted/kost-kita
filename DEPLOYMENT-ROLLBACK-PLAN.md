# Deployment & Rollback Plan — KostKita

Diperbarui 3 November 2026. Ini rencana testing/staging, bukan bukti aktivasi atau otorisasi deployment. Arsitektur: push main → GitHub Actions quality gates → runner LXC aplikasi → build Docker Compose lokal → deployment → health/SHA verification. Tanpa GHCR.

## Status saat ini

- Workflow lama ID 405389177 disabled_manually; API pukul 23:37 WIB menunjukkan 0 queued/in_progress/waiting/requested/pending.
- main protected; ruleset aktif mensyaratkan PR dan check ci strict, tetapi meminta 0 approval. Sebelum runner diaktifkan, atur main agar memerlukan minimal satu approving review dan pastikan reviewer memeriksa workflow/deploy/Compose. Environment GitHub belum ada.
- Node mamay, CT 10026, hostname kost-kita, IP 192.168.0.26, dan runner unit aktif dalam CT sudah cocok dari output pemilik.
- Compose runtime, active SHA, deployment path, penggunaan resource serta data dummy belum dibuktikan.
- Gateway Traefik mempublikasikan api.insecure dashboard pada port 8080 ke semua interface; route Cloudflare/origin belum terbukti.
- Backup testing DEFERRED — ACCEPTED RISK; data dapat hilang. Backup/restore wajib sebelum production.

**Batas isolasi dan availability:** Environment staging adalah label/protection GitHub, bukan runtime terpisah. Workflow memakai /opt/kostkita/.env dan Compose stack/volume KostKita yang sudah ada pada CT 10026; deployment akan mengganti stack itu. Pastikan data dummy dan tetapkan jendela gangguan serta pemberitahuan tester karena aktivasi Compose tidak atomik dan durasi downtime belum diketahui. Jika endpoint tidak boleh terganggu, siapkan project/database/volume/env/ingress terpisah; saat ini belum tersedia.

## Checklist sebelum mengaktifkan staging

1. Pastikan database/file hanya berisi data dummy/testing.
2. Dari CT 10026, ulangi tanpa pager: docker ps/compose ls, status DB/scheduler/worker, df/df -i/free, Docker filesystem, metadata deployment directory dan image/revision. Jangan baca/print .env.
3. Minta pemilik ingress meninjau api.insecure, listener 8080, Docker provider/socket, firewall dan Cloudflare router/tunnel; cocokkan origin ke 192.168.0.26:8080.
4. Review workflow branch codex/cicd-production-remediation. Ia menunggu workflow tests ID 405373892 sukses untuk push main/repo/SHA sama, tanpa workflow_dispatch, dengan concurrency kostkita-staging-deploy dan deployment flag default-off.
5. Buat GitHub Environment staging yang membatasi branch ke main. Set expected hostname dan batas disk/inode dari pengukuran. Pertahankan repository variable KOSTKITA_DEPLOYMENT_ENABLED tidak ada atau false selama merge dan pemeriksaan awal.
6. Merge workflow setelah review dengan flag tetap false; jalankan CI pada merge commit dan verifikasi deploy job dilewati. Jika workflow masih disabled_manually, pastikan YAML remote benar dan flag false, lalu enable hanya workflow yang sudah ditinjau. Workflow disabled tidak menerima trigger sampai di-enable. Ulangi no-run check, pastikan legacy workflow tetap nonaktif, CI rule aktif, dan tidak ada CI main tertunda sebelum mengatur flag true. Flag true membuat CI main berikutnya yang sukses dapat deploy.
7. Backup tidak perlu dibangun untuk testing menurut keputusan pemilik. Risiko kehilangan data harus dipahami; jangan gunakan data nyata.

## Urutan deployment testing setelah diaktifkan pemilik

1. Push/merge main menjalankan workflow tests pada GitHub-hosted runner. Semua quality/security/static checks harus lulus pada SHA kandidat.
2. Workflow deployment hanya menerima event sukses dari tests untuk push main di repo sama, memverifikasi tested SHA masih head main, lalu checkout SHA itu.
3. Runner pada LXC target membangun image dengan Docker Compose lokal. Tidak ada GHCR. Skrip memvalidasi provenance, hostname/IP/virtualisasi, Compose config, environment secara secret-safe, storage dan resource thresholds sebelum aktivasi.
4. Migration pending membuat deployment gagal tertutup. Tidak ada migration otomatis, migration rollback, volume deletion atau global prune.
5. Setelah aktivasi, verifikasi image SHA app/web/scheduler, database readiness, asset, release endpoint, scheduler/worker dan ingress. /up 200 sendiri tidak cukup.

## Rollback dan pemulihan

- Build/health/migration guard failure menghasilkan status pipeline gagal.
- Setelah kegagalan pasca-aktivasi, skrip best-effort mencoba image app/web/scheduler sebelumnya di atas Compose config kandidat dan mengecek image/container serta web health.
- Rollback hanya image; Compose config lama, schema dan data tidak dipulihkan. Compose activation dapat menimbulkan downtime/versi campuran, durasinya belum diukur dan belum ada rehearsal di LXC.
- Jangan gunakan down -v, Docker prune global, hapus volume/DB atau migrate:rollback.
- Jika rollback gagal, pertahankan data, hentikan aktivasi, catat SHA/error dan ikuti recovery operator. Backup testing ditunda, sehingga data mungkin tidak dapat dipulihkan.

## Production execution checklist

- [ ] Backup off-node PostgreSQL dan file privat termasuk perlindungan APP_KEY.
- [ ] Retention, integrity check dan restore rehearsal terisolasi terbukti; RPO/RTO dicatat.
- [ ] Security ingress, data/privacy, observability dan incident response selesai.
- [ ] Migration kompatibel dengan versi lama/baru dan recovery plan ditinjau.
- [ ] Kapasitas dan rollback image/config/traffic/schema direhearse.
- [ ] Checklist production seluruhnya VERIFIED.
- [ ] Persetujuan final terpisah sebelum workflow production, migration atau deployment operasional.
