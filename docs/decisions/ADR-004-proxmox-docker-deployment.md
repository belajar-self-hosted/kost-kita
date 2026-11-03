# ADR-004: Deployment KostKita pada Homelab Mamay

- Status: accepted (target architecture; live deployment not verified)
- Tanggal: 2026-11-03
- Pemilik keputusan: pengguna

## Konteks

Pengguna menjalankan Proxmox VE pada home server dan menetapkan deployment KostKita di LXC menggunakan Docker. Pemilik menyatakan LXC aplikasi sudah dialokasikan sebagai ID `10026` pada IP `192.168.0.26`; identitas serta keadaan aktual belum diverifikasi; domain/subdomain publiknya `kostkita.sequein.my.id`. Aplikasi perlu dapat diakses melalui HTTPS. Topologi ingress dan CI/CD homelab sudah ditetapkan pada [HOMELAB-SSOT](../deployment/HOMELAB-SSOT.md), yang menjadi acuan untuk gateway bersama.

## Keputusan

### LXC aplikasi dan Docker Compose

- Gunakan LXC khusus KostKita ID `10026`, IP statis `192.168.0.26/24` pada `vmbr0`, gateway `192.168.0.1`. LXC ini berbeda dari gateway ingress LXC `10080` (`gateway-ingress`, `192.168.0.80`). LXC dinyatakan sudah dibuat oleh pemilik; node, identitas, Docker/Compose dan runner masih harus diverifikasi secara read-only.
- Ikuti baseline LXC aplikasi homelab: unprivileged, `nesting=1,keyctl=1`, Debian 13 Trixie, sekurang-kurangnya 2 vCPU, 2048 MB RAM, dan 20–32 GB disk; sesuaikan resource setelah mengukur build dan beban aplikasi.
- Jalankan Docker Engine dan Compose Plugin di LXC aplikasi. Compose memuat container aplikasi Laravel dan PostgreSQL, beserta Scheduler/worker yang benar-benar diperlukan.
- PostgreSQL produksi berada di Compose yang sama dengan aplikasi. Gunakan jaringan Compose internal dan volume persisten; jangan publish port `5432` ke LAN, gateway, atau internet. Pilihan ini khusus KostKita; contoh `VM 200 / 192.168.0.200` pada HOMELAB-SSOT adalah pola contoh layanan lain, bukan host database KostKita.
- Publikasikan runtime HTTP Laravel pada port host `8080` di LXC `192.168.0.26`; jangan menyalin port `3000` dari contoh aplikasi Node.js pada HOMELAB-SSOT.

### Ingress publik dan TLS

Aliran trafik KostKita mengikuti gateway homelab:

```text
Browser --HTTPS--> Cloudflare Edge
  --encrypted Cloudflare Tunnel--> cloudflared di LXC 10080
  --> Traefik v3 :80 (Dynamic File Provider)
  --HTTP melalui LAN--> LXC aplikasi KostKita :8080
  --> container Laravel --Compose network internal--> PostgreSQL :5432
```

- Pertahankan Cloudflare Tunnel remote-managed yang sudah ada. Gateway publik menargetkan Traefik `v3.7.17` yang disetujui; baseline lama mematok `v3.1`, sehingga versi gateway aktif harus diverifikasi dan di-upgrade sebelum KostKita go-live.
- Public Hostname di Cloudflare One mengarah ke `traefik:80` di network `web-gateway`; jangan buat tunnel atau reverse proxy kedua pada LXC aplikasi.
- Tambahkan router dan service unik, misalnya `kostkita-router` dan `kostkita-service`, pada `/opt/ingress/dynamic/kostkita.yml`. Router memakai `kostkita.sequein.my.id` dan meneruskan ke `192.168.0.26:8080`; catat port tersebut di inventaris layanan.
- Routing gateway memakai Dynamic File Provider saja; jangan aktifkan Docker Provider atau mount Docker socket pada Traefik.
- Cloudflare Edge menangani HTTPS publik. Koneksi dari `cloudflared` ke Traefik dan dari Traefik ke aplikasi memakai HTTP internal sesuai baseline homelab. Konfigurasikan `forwardedHeaders.trustedIPs` pada Traefik untuk subnet Docker `web-gateway` khusus cloudflared; jangan gunakan mode `insecure` atau percaya seluruh LAN. Konfigurasikan `APP_URL` sebagai HTTPS publik dan percayai forwarded headers di Laravel hanya dari gateway `192.168.0.80`.
- Tidak ada port forwarding 80/443 di router fisik. Jangan buat Public Hostname untuk PostgreSQL, SSH, Proxmox Web UI, atau Traefik Dashboard `:8080`. Dashboard Traefik tetap LAN/VPN saja.
- Batasi akses jaringan pada kebutuhan layanan: Traefik perlu menjangkau port HTTP aplikasi; PostgreSQL hanya dapat dijangkau aplikasi di jaringan Compose. Firewall LXC aplikasi saat ini nonaktif menurut pemilik; jangan mengubahnya tanpa persetujuan. Sebelum go-live, jelaskan bahwa port HTTP yang dipublikasikan dapat dijangkau klien LAN langsung dan sepakati kebijakan/mitigasi akses.

### CI/CD dan rahasia produksi

- Ikuti standar In-LXC CI/CD pada HOMELAB-SSOT: GitHub Actions self-hosted runner berjalan pada LXC aplikasi; checkout harus tepat ke SHA yang lulus workflow `tests`, arsip release dibangun lokal, lalu Compose mengaktifkan image tersebut. Jangan gunakan GHCR untuk alur ini.
- Runner yang menjadi anggota grup `docker` diperlakukan sebagai principal produksi berhak administratif pada LXC. Workflow lokal tidak menyediakan `workflow_dispatch`; ia memerlukan CI sukses untuk push `main`, concurrency target tunggal, dan deployment flag eksplisit. Sebelum flag dibuka, lindungi `main`, atur environment `production` dengan reviewer/branch restriction, dan buktikan lokasi runner pada LXC. Jangan jalankan job pull request dari fork tidak tepercaya atau kode/action yang tidak dipercaya.
- Simpan `.env` produksi di LXC aplikasi dengan mode `600`; exclude dari sinkronisasi source dan jangan commit ke repository. Karena Compose memakai file ini sementara direktori menjadi build context, `.dockerignore` wajib mengecualikan `.env`, `.env.*`, dan file rahasia lainnya dari build context. Workflow boleh membaca rahasia untuk deployment runtime, tetapi tidak boleh mencetak atau mengemasnya ke log/artifact/image. Token Cloudflare tetap hanya berada pada gateway ingress.
- Health check deployment harus membuktikan commit/release aktif, kesehatan layanan dan database, asset, scheduler, serta ingress jika sudah diaktifkan; worker juga diperiksa bila dipakai. Jaga data PostgreSQL di volume persisten, backup database rutin ke storage di luar node Proxmox, dan uji restore.

> **Gerbang produksi:** perubahan repository telah diizinkan, tetapi aktivasi produksi, migrasi, restart dan perubahan infrastruktur live memerlukan persetujuan terpisah. Identitas host/runner, backup dan restore serta kesiapan layanan masih **BLOCKED** sampai ada bukti live. Lihat [panduan](../deployment/DEPLOYMENT-GUIDE.md) dan [rencana remediasi](../../REMEDIATION-PLAN.md).

> **Status workflow remote, 3 November 2026:** deploy workflow legacy ID `405389177` masih aktif. Snapshot Actions read-only pukul 20:21 WIB tidak menunjukkan run queued/aktif; pemeriksaan ulang pukul 21:37 WIB terhalang halaman “Page not found”, jadi state run/workflow terbaru tidak diketahui. Tool untuk membaca run terbaru atau men-disable workflow tidak tersedia. Workflow lokal yang diremediasi belum di-push, dan repository environment/protections belum dikonfigurasi atau diverifikasi.

## Konsekuensi dan trade-off

- Gateway ingress dikelola terpusat dan dapat melayani banyak aplikasi; perubahan routing memerlukan entri Dynamic File Provider dan Public Hostname di Cloudflare.
- Docker network `web-gateway` hanya ada di LXC `10080`; container KostKita tidak dapat bergabung ke network itu. Traefik menjangkau LXC aplikasi lewat alamat LAN.
- Sebelum route publik KostKita diaktifkan, periksa versi Traefik yang benar-benar berjalan, hapus konfigurasi Docker Provider/socket pada rollout gateway, tetapkan trusted subnet cloudflared, dan catat `kostkita.sequein.my.id`, LXC `10026`/`192.168.0.26`, serta port HTTP pada service inventory.
- Menggabungkan PostgreSQL dengan aplikasi dalam satu LXC mengikuti keputusan pengguna dan menyederhanakan resource homelab. Volume persisten dan backup off-host wajib untuk melindungi data; service PostgreSQL tetap tidak terekspos di jaringan publik.
- Proxmox menjelaskan LXC unprivileged dan opsi nesting, serta merekomendasikan container bertingkat di dalam QEMU VM untuk kebutuhan isolasi maksimum atau live migration. KostKita mengikuti pilihan LXC pengguna; tinjau ulang bila kebutuhan isolasi/availability berubah.
- ID LXC, IP, domain, dan subdomain KostKita sudah ditetapkan dan dicatat pada inventaris sebagai rencana. LXC dinyatakan sudah dibuat oleh pemilik, tetapi parameter aktual dan keadaan layanan harus diverifikasi saat audit. Port target HTTP `8080` sudah dipilih; listener dan route aktual masih perlu diverifikasi. Jangan menandai rute aktif sebelum gateway dan Cloudflare diuji.

## Sumber acuan

- [HOMELAB-SSOT](../deployment/HOMELAB-SSOT.md) — topology ingress, Cloudflare Tunnel, Traefik, Dynamic File Provider, alamat jaringan, provisioning LXC target, CI/CD tanpa GHCR, dan service inventory.
- [Panduan Deployment KostKita](../deployment/DEPLOYMENT-GUIDE.md) — urutan provisioning aplikasi, build, migrasi, smoke test, backup/restore, serta go-live.
- [Proxmox VE — Linux Container documentation](https://github.com/proxmox/pve-docs/blob/master/pct.adoc) — unprivileged containers, nesting, dan trade-off isolasi.
- [Docker Docs — Use Compose in production](https://docs.docker.com/compose/how-tos/production/) — deployment Compose pada satu server.
- [Docker Docs — Compose volumes](https://docs.docker.com/reference/compose-file/volumes) — volume persisten.
- [Docker Docs — Compose secrets](https://docs.docker.com/compose/how-tos/use-secrets) — pemberian rahasia per layanan.
- [Traefik — release support](https://doc.traefik.io/traefik/deprecation/releases/) — status dukungan minor Traefik; target v3.7.17 disetujui setelah baseline v3.1 diketahui tidak lagi mendapat security support.
- [Traefik — EntryPoints](https://doc.traefik.io/traefik/v3.7/reference/install-configuration/entrypoints/) — konfigurasi `forwardedHeaders.trustedIPs` untuk hanya mempercayai proxy yang ditetapkan.
- [Docker — Engine security](https://docs.docker.com/engine/security/) — risiko akses Docker daemon/socket yang berprivilege tinggi.
- [Docker — Build context](https://docs.docker.com/build/concepts/context/) — `.dockerignore` untuk mengecualikan rahasia dari build context.
