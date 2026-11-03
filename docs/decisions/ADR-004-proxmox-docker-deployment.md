# ADR-004: Deployment KostKita pada Homelab Mamay

- Status: accepted; target identity verified, testing deployment not ready
- Tanggal: 2026-11-03
- Pemilik keputusan: pengguna

## Konteks

Output read-only pemilik memverifikasi node mamay (Proxmox VE 9.2.2) dan application LXC CT 10026: running, hostname kost-kita, IP 192.168.0.26/24 pada vmbr0, 4 cores, RAM 16 GiB, swap 4 GiB dan rootfs 32 GB. Di dalam CT, hostname/IP sama dan unit self-hosted runner actions.runner.belajar-self-hosted-kost-kita.kost-kita.service active. Penggunaan resource, runtime Compose, active SHA dan deployment directory belum terverifikasi.

Gateway adalah CT 10080, hostname gateway, IP 192.168.0.80/24. Output yang diberikan menunjukkan Traefik dan cloudflared berjalan, tetapi api.insecure=true, Docker provider/socket aktif, dan host port 8080 bind ke semua interface. Router serta Cloudflare origin belum dibuktikan.

## Keputusan

### LXC dan Docker Compose

- Gunakan application LXC ID 10026 dan IP 192.168.0.26/24; gateway ingress tetap terpisah pada CT 10080 dan IP 192.168.0.80.
- Jalankan Docker Compose pada CT aplikasi dan build image secara lokal di tempat aplikasi dijalankan; tidak menggunakan GHCR atau registry eksternal.
- PostgreSQL tetap pada network Compose internal dan volume persisten; jangan publish port 5432.
- Penggunaan resource aktual dan listener perlu diverifikasi sebelum deployment.

### Ingress dan TLS

Alur target: Cloudflare → Cloudflare Tunnel pada gateway → Traefik → aplikasi 192.168.0.26:8080 → PostgreSQL di Compose network.

- Output gateway menunjukkan Traefik image traefik:v3 dengan label versi 3.7.17 dan cloudflared:latest; keduanya berjalan saat output diambil.
- Dashboard/API insecure aktif dan port 8080 bind ke 0.0.0.0 serta IPv6. Jangkauan internet/firewall belum diketahui. Pemilik ingress perlu membatasi dashboard, meninjau Docker socket/provider, dan membuktikan router/tunnel sebelum ingress dinyatakan aman.
- Konfigurasi aktual mengaktifkan Docker Provider dan File Provider. Ini berbeda dari baseline file-only homelab.
- Endpoint /up memberi HTTP 200 dengan body HTML dari domain dan IP langsung; /release.txt memberi 404. Ini bukti reachability saja, bukan pemetaan origin atau versi.
- Jangan mengubah gateway bersama dari workflow aplikasi.

### CI/CD, environment dan data

- Target flow: push main → Actions quality gates → runner pada CT 10026 → checkout tested SHA → local Docker Compose build → deploy → SHA/health verification. Tanpa GHCR.
- Workflow lama ID 405389177 sekarang disabled_manually dan snapshot API 23:37 WIB tidak menunjukkan run nonterminal.
- main dilindungi ruleset aktif yang mewajibkan PR dan status ci strict; approval count 0. Sebelum runner Docker diaktifkan, atur main agar memerlukan minimal satu approving review dan pastikan perubahan workflow/deployment/Compose ditinjau. Actions permission dan runner inventory masih butuh autentikasi.
- Workflow remediasi lokal tidak memiliki workflow_dispatch, memerlukan CI sukses untuk push main/SHA sama, concurrency tunggal, dan deployment flag default-off. Gunakan GitHub Environment staging saat testing; environment itu belum dibuat dan workflow lokal belum dipush.
- Environment bernama staging tidak mengisolasi runtime. Workflow menggunakan /opt/kostkita/.env dan Compose stack/volume KostKita yang sudah ada pada CT 10026; deployment akan mengganti stack tersebut. Data harus dummy. Project, database, volume, env dan ingress staging yang terpisah belum tersedia.
- APP_ENV=production dan APP_DEBUG=false tetap konfigurasi runtime Laravel yang aman. Nama GitHub Environment staging hanya mengidentifikasi target uji.
- Data target harus dummy/testing. Status data saat ini belum diverifikasi.

### Backup, migration dan rollback

- Pemilik menerima risiko kehilangan data percobaan. Backup/retention/restore rehearsal **DEFERRED — ACCEPTED RISK** untuk testing; tidak ada backup yang diklaim tersedia.
- Backup off-node dan restore rehearsal terisolasi tetap wajib sebelum operasi production.
- Skrip berhenti jika migration pending dan tidak menjalankan migration atau migrate:rollback otomatis. Review kompatibilitas release lama/baru dan migration secara terpisah.
- Rollback lokal mencoba memulihkan image app/web/scheduler di atas Compose file kandidat; tidak memulihkan config, schema atau data.

> **Readiness:** Testing/staging belum siap karena workflow lokal belum remote, service/resource/path dan data dummy belum diverifikasi, Environment staging belum ada, dan gateway memiliki mismatch keamanan. Production belum siap, termasuk karena backup/restore belum dipenuhi. Lihat [Infrastructure Verification Report](../../INFRASTRUCTURE-VERIFICATION-REPORT.md), [checklist](../../PRODUCTION-READINESS-CHECKLIST.md), dan [rencana remediasi](../../REMEDIATION-PLAN.md).

## Konsekuensi

- Build dan runtime berbagi resource pada LXC; ukur capacity sebelum aktivasi.
- Compose activation tidak atomik. Rollback image tidak mengembalikan keseluruhan konfigurasi atau data.
- Gateway merupakan infrastruktur bersama; temuan security ditangani pemilik ingress melalui rencana terpisah.
- Jangan masukkan data riil selama testing. Production hanya dipertimbangkan setelah checklist production lengkap dan persetujuan operasional terpisah.
