# Panduan Deployment KostKita

> **Status 3 November 2026:** TESTING/STAGING belum siap diaktifkan. Workflow lama ID 405389177 sudah disabled_manually dan tidak ada run nonterminal pada snapshot 23:37 WIB. main protected dengan CI check wajib; Environment staging belum dibuat. Node mamay, CT 10026, hostname kost-kita, IP 192.168.0.26 dan unit runner aktif di CT sudah diverifikasi dari output pemilik. Runtime Compose, penggunaan resource, deployment path, data dummy dan router ingress belum terverifikasi. Traefik mengaktifkan api.insecure=true serta mempublikasikan port 8080 pada semua interface. PRODUCTION READY tetap NO sampai semua syarat production termasuk backup/restore dipenuhi.

Branch kerja codex/cicd-production-remediation belum dipush. Dokumen ini mencatat prasyarat dan cara verifikasi; bukan bukti bahwa workflow lokal sudah berjalan pada GitHub/LXC atau izin deployment.

## Sebelum testing deployment

1. Pastikan data pada target hanya dummy/testing, bukan data pribadi atau transaksi nyata.
2. Cocokkan Docker Compose services, database/scheduler/worker, active image SHA, deployment directory dan resource usage melalui output read-only dari LXC.
3. Minta pemilik ingress meninjau dashboard/API Traefik insecure pada port 8080, Docker provider/socket, firewall, router file, Cloudflare Tunnel dan origin.
4. Pastikan workflow lama tetap disabled_manually. Pertahankan KOSTKITA_DEPLOYMENT_ENABLED tidak ada atau false sampai staging siap.
5. Buat GitHub Environment staging dengan deployment branch dibatasi ke main. Isi expected hostname dan ambang disk/inode berdasarkan pengukuran LXC. Jangan gunakan environment production untuk fase uji.

## Topologi dan target

| Komponen                        | Status                                                                                              |
| ------------------------------- | --------------------------------------------------------------------------------------------------- |
| Proxmox                         | mamay, pve-manager/9.2.2                                                                            |
| Application LXC                 | CT 10026, kost-kita, 192.168.0.26/24, vmbr0; identitas cocok dari pct config dan output di dalam CT |
| Runner                          | Unit actions.runner.belajar-self-hosted-kost-kita.kost-kita.service active pada CT 10026            |
| Gateway                         | CT 10080, gateway, 192.168.0.80/24                                                                  |
| Deployment directory            | Belum diverifikasi; workflow lokal merujuk /opt/kostkita                                            |
| Compose services dan active SHA | Belum diverifikasi                                                                                  |

Arsitektur harus tetap GitHub Actions → self-hosted runner pada CT aplikasi → Docker Compose build pada CT yang sama → deployment → verifikasi. Tidak memakai GHCR. APP_ENV=production dan APP_DEBUG=false tetap konfigurasi Laravel runtime yang aman walaupun GitHub Environment bernama staging.

## Workflow staging

Workflow lokal hanya menerima keberhasilan workflow tests ID 405373892 untuk push main pada repository yang sama, mengikat deploy ke SHA yang lulus CI dan mengecek SHA masih head main. Ia tidak memiliki workflow_dispatch, memakai concurrency kostkita-staging-deploy dan mensyaratkan KOSTKITA_DEPLOYMENT_ENABLED=true. Workflow belum ada di GitHub.

**Penting:** GitHub Environment staging tidak mengisolasi runtime. Workflow menunjuk ke /opt/kostkita/.env dan deployment script mengganti Compose stack KostKita beserta database/volume yang sudah ada. Hostname publik merespons saat ini, tetapi origin Cloudflare belum dibuktikan. Pastikan data target dummy dan tetapkan jendela gangguan/pemberitahuan tester sebelum flag true karena aktivasi non-atomik. Stack staging terpisah dengan project, database, volume, env dan ingress belum disiapkan; gunakan jika endpoint tidak boleh terganggu.

Pemilik harus mengonfirmasi YAML remote sudah versi yang direview, workflow lama masih disabled, CI/main ruleset masih aktif, Environment staging tersedia, target hostname/ambang kapasitas terukur, serta tidak ada run main yang tertunda. Set repository variable deployment false/tidak ada selama perubahan workflow masuk. Mengubahnya menjadi true tidak memulai workflow sendiri, tetapi CI tests main berikutnya yang sukses dapat melakukan deployment.

Ruleset main yang terlihat mewajibkan 0 approval. Sebelum mengaktifkan runner Docker pada branch main yang publik, atur main agar memerlukan minimal satu approving review; reviewer harus memeriksa workflow, deployment script, Dockerfile dan Compose. Setelah merge dengan flag false, verifikasi YAML dan status workflow di Actions; bila workflow tetap disabled_manually, enable hanya setelah review dan no-run check baru. Workflow disabled tidak menerima trigger sampai di-enable ([GitHub Docs](https://docs.github.com/en/actions/how-tos/manage-workflow-runs/disable-and-enable-workflows)).

## Deployment, migration dan rollback

Skrip membangun image lokal pada LXC aplikasi dari commit teruji, tanpa registry. Ia memvalidasi provenance, hostname/IP/virtualisasi, SHA, Compose topology, environment secara secret-safe, storage dan ambang disk/inode. Migration pending menghentikan deployment; tidak ada migration/rollback database otomatis. Jangan jalankan migration destruktif otomatis. Tidak ada global Docker prune atau penghapusan volume.

Setelah aktivasi, verifikasi image/release SHA, Laravel dan database health, asset, scheduler, serta ingress. HTTP /up yang memberi 200 HTML tidak membuktikan release/database; /release.txt memberi 404 pada pengujian terbaru. Jangan nyatakan sukses sebelum kandidat SHA dan health checks terikat ke release diverifikasi.

Rollback lokal mencoba image app/web/scheduler sebelumnya di atas Compose config kandidat. Ini best-effort image-only; config lama, schema dan data tidak dipulihkan. Belum ada rehearsal di daemon LXC. Jangan memakai down -v, prune global, menghapus DB/volume, atau migrate:rollback.

## Backup dan production

Backup, retention dan restore rehearsal berstatus **DEFERRED — ACCEPTED RISK** untuk testing sesuai keputusan pemilik. Tidak ada backup/restore yang diklaim. Data testing dapat hilang total.

Sebelum penggunaan operasional, backup PostgreSQL dan file privat harus off-node, restore diuji di lingkungan terisolasi, cakupan/retention/integrity/RPO/RTO dicatat, gateway diamankan, migration compatibility ditinjau, dan rollback direhearse. Lihat [Production Readiness Checklist](../../PRODUCTION-READINESS-CHECKLIST.md), [Production Impact Assessment](../../PRODUCTION-IMPACT-ASSESSMENT.md), serta [Deployment & Rollback Plan](../../DEPLOYMENT-ROLLBACK-PLAN.md).
