# Panduan Deployment KostKita — Proxmox Homelab

> **Status:** LXC aplikasi sudah dibuat; Docker, Compose, dan self-hosted runner belum dipasang menurut informasi pemilik. Audit keadaan aktual dan deployment belum dilakukan.
> **Tanggal status:** 3 November 2026.

Panduan ini mengurutkan deployment KostKita di LXC aplikasi Proxmox. [HOMELAB-SSOT](HOMELAB-SSOT.md) tetap menjadi sumber kebenaran untuk gateway bersama; [ADR-004](../decisions/ADR-004-proxmox-docker-deployment.md) menetapkan keputusan khusus KostKita. Jika contoh generik homelab bertentangan dengan ADR-004, gunakan ADR-004 untuk lokasi database dan jalur trafik aplikasi. Panduan ini tidak mengizinkan perubahan infrastruktur sebelum audit, interview informasi yang belum tersedia, dan persetujuan rencana.

## 1. Status dan nilai yang sudah ditetapkan

Repository yang dituju adalah [`belajar-self-hosted/kost-kita`](https://github.com/belajar-self-hosted/kost-kita). Pastikan repository dapat diakses oleh GitHub self-hosted runner di LXC sebelum menjadwalkan deployment; jangan menaruh token GitHub pada URL clone atau file source.

LXC `10026` sudah dibuat pada `192.168.0.26` memakai template `debian-13-standard_13.6-1`. Docker Engine, Compose Plugin, dan self-hosted runner belum dikonfigurasi menurut prompt pemilik; verifikasi keadaan aktual secara read-only sebelum instalasi. Firewall LXC saat ini dinyatakan nonaktif dan tidak boleh diaktifkan tanpa persetujuan pemilik.

Pada 3 November 2026, source proyek belum berisi `Dockerfile`, `compose.yaml`/`docker-compose.yml`, `.dockerignore`, atau workflow deploy. Artifact tersebut harus dibuat dan ditinjau sebelum perintah build/deploy di bawah dapat dijalankan. Workflow yang tersedia baru workflow CI; dokumentasi ini menjelaskan target dan runbook, bukan bukti bahwa deployment sudah aktif.

Implementasi deployment harus menyediakan runtime HTTP Laravel yang production-ready pada PHP 8.3+, menyertakan extension Composer yang diperlukan (termasuk `pdo_pgsql`), dan membangun asset frontend dengan Node 22. Jangan menjalankan `php artisan serve` sebagai server produksi. Tetapkan runtime serta port container/host dalam service inventory sebelum membuat route gateway.

## 2. Nilai infrastruktur KostKita

| Item                        | Nilai atau ketetapan                                                                                             |
| --------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| Hypervisor                  | Proxmox VE — Server Mamay                                                                                        |
| LAN                         | `192.168.0.0/24`, gateway `192.168.0.1`, bridge `vmbr0`                                                          |
| Ingress                     | LXC `10080`, `192.168.0.80`, Cloudflare Tunnel remote-managed, Traefik File Provider                             |
| Target Traefik              | `v3.7.17`; versi aktif harus diverifikasi dan diperbarui sebelum go-live                                         |
| LXC aplikasi KostKita       | ID `10026`, IP `192.168.0.26`; sudah dibuat, berbeda dari ingress `10080`                                        |
| OS/container                | Debian 13 Trixie, template `debian-13-standard_13.6-1`                                                           |
| Fitur/resource LXC          | `nesting=1`, `keyctl=1`; baseline 2 vCPU, 2 GB RAM, disk 20–32 GB perlu diverifikasi terhadap konfigurasi aktual |
| Firewall LXC                | Nonaktif menurut pemilik; jangan ubah tanpa persetujuan                                                          |
| Docker dan runner           | Belum dikonfigurasi menurut pemilik; verifikasi sebelum install                                                  |
| Aplikasi dan database       | Docker Compose pada LXC aplikasi yang sama                                                                       |
| Database                    | PostgreSQL pada network internal Compose; port `5432` tidak dipublikasikan                                       |
| Repository                  | `https://github.com/belajar-self-hosted/kost-kita.git`                                                           |
| Domain                      | `sequein.my.id`                                                                                                  |
| Subdomain                   | `kostkita`                                                                                                       |
| URL publik                  | `https://kostkita.sequein.my.id`                                                                                 |
| Port HTTP pada LXC aplikasi | `8080` pada IP `192.168.0.26`; catat pada inventaris layanan                                                     |

Empat nilai identitas deployment yang berubah ketika template digunakan untuk layanan lain adalah ID LXC, IP LXC, domain, dan subdomain. URL publik dibentuk dari subdomain dan domain. Port, credentials, serta storage adalah konfigurasi aplikasi yang harus diputuskan/dibuat pada tahap deployment, bukan pengganti nilai identitas tersebut.

```text
Browser --HTTPS--> Cloudflare Edge
  --Cloudflare Tunnel--> cloudflared pada LXC 10080
  --> Traefik :80 (File Provider saja)
  --HTTP lewat LAN--> 192.168.0.26:<APP_HTTP_PORT>
  --> web/runtime Laravel --network Compose privat--> PostgreSQL :5432
```

LXC aplikasi tidak bergabung ke Docker network `web-gateway`; network itu hanya berada di LXC ingress. Gateway menjangkau aplikasi melalui IP LAN statis. Jangan membuat port forwarding 80/443 di router.

## 3. Alur persetujuan deployment

### Tahap A — Audit dokumentasi dan status aktual

1. Baca [HOMELAB-SSOT](HOMELAB-SSOT.md) dan ADR-004 sebagai acuan infrastruktur.
2. Cocokkan nilai tetap di bagian 2 dan rangkum perbedaan antara dokumentasi dengan keadaan LXC/gateway.
3. Audit LXC `10026` secara read-only. Verifikasi status, OS/template, IP, resource, nesting/keyctl, firewall, Docker/Compose, runner, storage, dan port yang sudah terpakai.
4. Jangan install, restart, enable, disable, atau mengubah konfigurasi pada tahap audit.

### Tahap B — Interview informasi yang belum tersedia

Jangan mengulang hal yang sudah diputuskan. Stack aplikasi adalah Laravel 13/PHP 8.3+, Inertia 3, Svelte 5/TypeScript; repository yang diberikan adalah `belajar-self-hosted/kost-kita`; database produksi PostgreSQL berada pada Compose di LXC aplikasi; SQLite dipakai lokal/test; identitas host adalah LXC `10026`, `192.168.0.26`, domain `sequein.my.id`, subdomain `kostkita`.

Konfirmasi hanya hal yang belum tersedia setelah audit:

- Branch/trigger deploy yang terlindungi. CI lokal saat ini mengarah ke `main`, tetapi branch remote perlu diverifikasi.
- HTTP runtime/container dan port HTTP host yang akan dicatat ke inventory.
- Alokasi resource aktual LXC dibandingkan build Node/PHP dan PostgreSQL.
- Lokasi/ukuran persistent volume PostgreSQL dan bukti deposit, lokasi backup off-node, retensi, serta jadwal restore rehearsal.
- SMTP/provider untuk verifikasi email dan reset password.
- Cara pemilik ingin membatasi akses LAN ke HTTP app port, karena firewall LXC sekarang nonaktif. Jangan mengaktifkan firewall tanpa persetujuan.
- Secrets yang perlu dibuat langsung di LXC, seperti `APP_KEY`, credential DB/mail, dan credential runner. Jangan meminta pemilik mengirim nilainya di chat.

### Tahap C — Rencana untuk persetujuan

Setelah audit dan interview, paparkan arsitektur akhir, dependency/resource, struktur direktori, Dockerfile/Compose, runner/workflow, koneksi ke ingress existing, secrets, health check, backup/restore, rollout, risiko, dan rollback. Tunggu persetujuan pemilik sebelum implementasi atau perubahan sistem.

### Tahap D — Eksekusi bertahap

Setelah persetujuan, kerjakan satu langkah atau satu kelompok perintah kecil. Jelaskan tujuan dan risikonya, tunggu output terminal pemilik, verifikasi hasil, lalu lanjut. Jika terjadi error, selesaikan sebelum meneruskan. Jangan mengubah konfigurasi existing atau ingress aplikasi lain tanpa persetujuan.

Kriteria gerbang: audit selesai tanpa perubahan; pertanyaan yang tersisa dijawab; pemilik menyetujui rencana; database test terpisah dan tak mungkin tersambung ke production.

## 4. LXC aplikasi dan source

LXC KostKita sudah dibuat dengan ID `10026` dan IP `192.168.0.26`; jangan membuat LXC pengganti. Pada Tahap A, pastikan keadaan aktual cocok dengan Debian 13 template `debian-13-standard_13.6-1`, subnet `/24`, gateway `192.168.0.1`, nesting/keyctl aktif, dan firewall nonaktif. Bandingkan core/RAM/disk aktual dengan baseline ADR-004. Tahap ini bersifat read-only.

Setelah rencana disetujui, pasang Docker Engine serta Compose Plugin mengikuti bagian aplikasi di [HOMELAB-SSOT](HOMELAB-SSOT.md). Buat direktori dengan pemisahan source dan rahasia:

```text
/opt/kostkita/
├── .env                 # Rahasia runtime, chmod 600, tidak berada di source/build context
└── src/                 # Checkout repository KostKita
```

Checkout source ke `src/` menggunakan kredensial deploy yang hanya dapat membaca repository:

```bash
install -d -o runner -g runner /opt/kostkita/src
git clone https://github.com/belajar-self-hosted/kost-kita.git /opt/kostkita/src
```

Jika direktori sudah berisi checkout, sinkronkan hanya `/opt/kostkita/src`. Jangan menjalankan `rsync --delete` terhadap `/opt/kostkita`, karena direktori itu juga menyimpan `.env` dan mungkin mount/berkas operasional. Runner harus terpasang pada LXC aplikasi; jangan menjalankan job deployment dari pull request fork atau kode yang tidak dipercaya.

## 5. Kontrak container aplikasi

Compose yang akan ditambahkan ke repository harus memenuhi kontrak berikut:

| Service                        | Tanggung jawab dan batas jaringan                                                                                                            |
| ------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------- |
| `app`                          | Runtime PHP/Laravel; menerima environment aplikasi; dapat mengakses PostgreSQL melalui network Compose privat.                               |
| `web` atau runtime HTTP setara | Menyajikan Laravel dan `public/build`; satu-satunya service yang menerbitkan port HTTP ke IP LXC. Port ditetapkan setelah runtime dipilih.   |
| `db`                           | PostgreSQL dengan volume data persisten dan readiness check; tidak mempunyai `ports:` atau route Traefik.                                    |
| `scheduler`                    | Satu instance menjalankan `php artisan schedule:work`; diperlukan untuk expiry no-show, recurring invoice, dan penanda settlement terlambat. |
| `worker`                       | Tambahkan hanya bila ada pekerjaan queue yang benar-benar diproses asynchronous. Gunakan image/release yang sama dengan aplikasi.            |

PostgreSQL hanya berada di network internal Compose. Service HTTP memublikasikan port host pada IP LXC `192.168.0.26`, misalnya pola `192.168.0.26:APP_HTTP_PORT:PORT_HTTP_CONTAINER`; jangan memakai port `3000` dari contoh Node.js di HOMELAB-SSOT.

Rancang network Compose agar `db` hanya berada pada network data privat. Aplikasi dapat memakai network data itu dan network egress terpisah bila SMTP dibutuhkan; jangan menempatkan PostgreSQL pada network egress. Firewall LXC saat ini nonaktif. Jika service HTTP dipublikasikan pada IP LXC, klien LAN berpotensi mengaksesnya langsung dan melewati Cloudflare/Traefik. Laporkan paparan ini dan minta keputusan pemilik sebelum menambahkan firewall atau ACL; jangan mengaktifkannya otomatis. Otorisasi Laravel tetap wajib pada setiap route.

Build image dari source di LXC tanpa GHCR. Tahap build harus memakai `composer install --no-dev`, `npm ci`, dan `npm run build`; tahap runtime hanya menyertakan asset hasil build dan dependency produksi. Jalankan `composer check-platform-reqs --no-dev` pada image runtime saat CI/build memverifikasi extension. Buat target test terpisah yang menyertakan dev dependency bila acceptance test dijalankan dalam container.

Simpan data yang perlu bertahan saat container diganti:

- PostgreSQL pada named volume atau bind mount khusus di LXC.
- Bukti deposit pada storage privat Laravel (`storage/app/private` sesuai konfigurasi disk `local`). Jangan menyimpan bukti di `public`, image, atau checkout source.
- `APP_KEY` dan `.env` tetap sama antar-release. Jangan menjalankan `key:generate` setiap deployment.

Jangan menjalankan `docker compose down -v` pada stack berisi data. Jangan menambahkan Docker socket ke gateway Traefik.

## 6. Environment produksi

Buat `/opt/kostkita/.env` di LXC, atur owner yang dipakai runner deployment dan mode `600`. File tersebut berada di luar source/build context dan tidak boleh dikomit, disinkronkan, dikirim sebagai build arg, atau dicetak ke log.

Nilai aplikasi yang perlu disetel:

```dotenv
APP_NAME=KostKita
APP_ENV=production
APP_DEBUG=false
APP_URL=https://kostkita.sequein.my.id
LOG_CHANNEL=stderr
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=kostkita
DB_USERNAME=<APP_DATABASE_ROLE>
DB_PASSWORD=<SECRET>
DB_SSLMODE=prefer

POSTGRES_DB=kostkita
POSTGRES_USER=<DB_BOOTSTRAP_ADMIN_ROLE>
POSTGRES_PASSWORD=<DB_BOOTSTRAP_ADMIN_SECRET>

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=null
SESSION_ENCRYPT=true
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
```

Ganti semua nilai bertanda `<...>` sebelum menjalankan Compose. Compose meneruskan `POSTGRES_DB`, `POSTGRES_USER`, dan `POSTGRES_PASSWORD` hanya ke service `db` untuk inisialisasi pertama pada volume kosong. `POSTGRES_USER` adalah akun bootstrap berhak administratif; Laravel memakai `DB_USERNAME`/`DB_PASSWORD` yang berbeda dan tanpa hak superuser. Siapkan role/database aplikasi melalui init script yang ditinjau atau command administratif satu kali; perubahan variabel init pada volume yang sudah berisi data tidak mengubah role PostgreSQL.

`SESSION_DOMAIN=null` menjaga cookie hanya untuk host aplikasi. `APP_KEY` harus dihasilkan satu kali dan disimpan dalam file rahasia.

`MAIL_MAILER` pada `.env.example` adalah `log`; konfigurasi itu tidak mengirim email. Karena akun owner perlu verifikasi email dan pemulihan password, tetapkan SMTP/provider produksi dan alamat `MAIL_FROM_ADDRESS` sebelum membuka registrasi publik. Simpan username/password mailer di `.env`.

Kode saat ini menetapkan timezone framework ke UTC dan menggunakan `Asia/Jakarta` pada aturan tanggal bisnis/scheduler. Pertahankan sinkronisasi waktu sistem dan gunakan zona scheduler yang telah ditetapkan; jangan mengubah timestamp database menjadi waktu lokal.

Laravel belum mengonfigurasi trusted proxy di `bootstrap/app.php`. Sebelum route publik diaktifkan, konfigurasi middleware agar hanya mempercayai gateway `192.168.0.80`, sesuai ADR-004. Target konfigurasinya:

```php
$middleware->trustProxies(at: ['192.168.0.80']);
```

Jangan menggunakan wildcard atau mempercayai seluruh LAN. Uji bahwa URL yang dibuat aplikasi memakai HTTPS dan header `X-Forwarded-*` dari sumber lain tidak dapat memalsukan host, scheme, atau alamat klien.

Tambahkan `.dockerignore` di root build context. Minimum exclude `.env`, `.env.*`, `.git`, `vendor`, `node_modules`, log, cache lokal, dan `storage/app/private`; sertakan `.env.example` hanya bila diperlukan. Build context yang disarankan adalah `/opt/kostkita/src`, sehingga `/opt/kostkita/.env` tidak masuk ke konteks.

## 7. Uji PostgreSQL sebelum production

Acceptance test PostgreSQL wajib menggunakan database khusus, misalnya `kostkita_acceptance`, pada service `db` di LXC uji. Jangan pernah menjalankan `migrate:fresh`, PHPUnit, atau acceptance suite pada nama database produksi.

1. Buat database uji terpisah dan pastikan user test tidak diarahkan ke database `kostkita` produksi.
2. Jalankan migrasi pada database uji.
3. Jalankan acceptance concurrency sesuai [README PostgreSQL tests](../../tests/Postgres/README.md): `KOSTKITA_PG_TEST_DATABASE=kostkita_acceptance DB_CONNECTION=pgsql DB_DATABASE=kostkita_acceptance vendor/bin/phpunit -c phpunit.postgres.xml`.
4. Jalankan report/dashboard feature tests dengan `DB_CONNECTION=pgsql` dan `DB_DATABASE=kostkita_acceptance` dari test image yang berisi dev dependencies.
5. Catat hasil dan versi PostgreSQL sebelum menggunakan schema pada production.

Test suite PostgreSQL tersendiri yang tersedia sekarang menguji konkurensi booking. Tes laporan default memakai SQLite; pengujian query laporan PostgreSQL harus dijalankan terpisah terhadap database acceptance. Kriteria lulus: tidak ada koneksi ke production, concurrency test lulus, dan laporan mempertahankan snapshot tanggal akhir serta filter tenant pada PostgreSQL.

## 8. Build, migrasi, dan smoke test

Jalankan dari `/opt/kostkita/src` setelah Dockerfile dan Compose tersedia. Gunakan file environment eksternal:

```bash
cd /opt/kostkita/src
docker compose --env-file ../.env config -q
docker compose --env-file ../.env build --pull
docker compose --env-file ../.env up -d db
docker compose --env-file ../.env exec -T db sh -lc 'pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
docker compose --env-file ../.env run --rm app php artisan migrate --force
docker compose --env-file ../.env up -d --remove-orphans
docker compose --env-file ../.env ps
```

Sebelum migrasi pertama, verifikasi target `DB_HOST`/`DB_DATABASE` dari konfigurasi runtime dan pastikan database kosong merupakan database KostKita yang dimaksud. Sebelum migrasi release yang telah memuat data, buat backup konsisten dan tinjau migration yang akan dijalankan.

Verifikasi dari LXC aplikasi:

```bash
curl --fail --show-error http://192.168.0.26:<APP_HTTP_PORT>/up
docker compose --env-file ../.env exec -T app php artisan migrate:status
docker compose --env-file ../.env logs --tail=100 app web db scheduler
```

`/up` memeriksa kesiapan HTTP Laravel. `migrate:status` memeriksa akses schema/database; kedua pemeriksaan harus sukses sebelum mengaktifkan router Traefik. Buat Super Admin awal melalui shell tepercaya dengan `php artisan app:create-super-admin`; command meminta password secara interaktif. Jangan menyimpan password pada argument, workflow, atau source.

## 9. Ingress, HTTPS, dan forwarded headers

Setelah semua nilai inventory diisi dan smoke test internal lulus, tambahkan `/opt/ingress/dynamic/kostkita.yml` pada LXC `10080`:

```yaml
http:
    routers:
        kostkita-router:
            rule: 'Host(`kostkita.sequein.my.id`)'
            entryPoints:
                - web
            service: kostkita-service

    services:
        kostkita-service:
            loadBalancer:
                servers:
                    - url: 'http://192.168.0.26:<APP_HTTP_PORT>'
                passHostHeader: true
```

Ganti placeholder sebelum menyimpan file. Pastikan nama router/service unik, lalu verifikasi file dimuat Traefik. Pada Cloudflare Zero Trust, arahkan Public Hostname KostKita ke `http://traefik:80` sesuai remote-managed Tunnel. Cloudflare Edge menyajikan HTTPS publik; tidak dibuat tunnel kedua pada LXC aplikasi.

Pastikan gateway tetap memakai File Provider saja, tidak memasang `/var/run/docker.sock`, dan mempercayai forwarded headers hanya dari subnet `web-gateway` khusus cloudflared. Laravel mempercayai proxy `192.168.0.80` saja. Jangan publikasi PostgreSQL `5432`, SSH, Proxmox Web UI, atau Traefik Dashboard `:8080`; router rumah tidak memakai port forwarding 80/443.

Uji dari luar LAN bahwa `https://kostkita.sequein.my.id` menyajikan sertifikat valid, redirect/cookie login tetap HTTPS, login owner dan Super Admin berjalan, dan akses tidak mengungkap debug trace. Target ini mengikuti variabel yang diberikan pemilik. Ganti URL salinan lain yang tidak cocok, termasuk `osc.mamay.my.id`, agar tidak menguji host yang salah. Setelah berhasil, perbarui service inventory homelab dengan FQDN, LXC ID/IP, port, file dynamic config, protokol, serta status deployment.

## 10. Scheduler, log, dan email

Jalankan tepat satu container scheduler dengan command `php artisan schedule:work`. Jadwal yang harus terlihat pada log/monitoring adalah expiry booking no-show setiap menit, generate invoice pukul 00:10 `Asia/Jakarta`, dan penanda settlement deposit terlambat pukul 00:20 `Asia/Jakarta`. Jangan menambahkan cron kedua untuk command yang sama.

Kirim log ke `stderr` agar tersedia di `docker compose logs`; pastikan rotasi log Docker dibatasi agar disk LXC 20–32 GB tidak penuh. Jangan mengandalkan log container sebagai audit database atau backup transaksi.

Lakukan smoke test email verifikasi owner dan reset password menggunakan provider produksi sebelum registrasi umum dibuka. Tidak ada pengingat WhatsApp/email ke penyewa pada MVP; pengingat produk hanya muncul di dashboard pemilik.

## 11. Backup, restore, dan rollback

Backup database PostgreSQL dan `storage/app/private` secara teratur ke storage di luar node Proxmox. Buat backup tambahan sebelum migrasi. Rahasia `.env`/`APP_KEY` perlu disimpan di tempat terenkripsi dengan akses terbatas; tanpa `APP_KEY` lama, data terenkripsi yang ada tidak boleh diasumsikan dapat dipulihkan. Tentukan retensi dan jadwal backup pada runbook operasional sebelum go-live.

Sebelum mengaktifkan layanan, lakukan restore rehearsal pada database dan volume file uji yang terpisah. Verifikasi row penting, akses file bukti, login, dan laporan setelah restore. Catat waktu pemulihan serta hasil di checklist `DEPLOY-HOME-06` pada [Strategi Kualitas](../quality/testing.md).

Rollback aplikasi menggunakan image/release sebelumnya hanya bila perubahan schema kompatibel. Jangan otomatis menjalankan `migrate:rollback` setelah ada data produksi; koreksi schema/data dilakukan dengan rencana forward-fix atau restore terkontrol. Jangan menghapus named volume saat menghentikan atau mengganti container.

## 12. CI/CD in-LXC

Workflow deployment mengikuti bagian In-LXC CI/CD di HOMELAB-SSOT dan harus ditambahkan ke repository sebelum aktivasi:

- Gunakan self-hosted runner yang terisolasi pada LXC aplikasi. Karena anggota grup `docker` dapat mengendalikan host/container dan membaca `.env`, perlakukan runner sebagai principal produksi.
- Izinkan deployment dari branch terlindungi atau trigger manual yang diotorisasi. Jangan gunakan runner deployment untuk job `pull_request` dari fork.
- Batasi permission GitHub Actions, pin action pihak ketiga ke commit SHA, dan matikan persistensi credential checkout bila tidak diperlukan.
- Sinkronkan source hanya ke `/opt/kostkita/src`; pertahankan `/opt/kostkita/.env` dan volume data di luar target `rsync --delete`.
- Build image lokal pada LXC tanpa GHCR. Health check harus lulus sebelum release dianggap berhasil. Jangan cetak environment atau secret ke log/artifact.
- Simpan image/release sebelumnya agar rollback aplikasi tidak memerlukan build ulang ketika CI gagal.

## 13. Checklist go-live

- [ ] LXC `10026`, IP `192.168.0.26`, dan URL `https://kostkita.sequein.my.id` tercatat pada service inventory; port HTTP final juga dicatat setelah runtime dipilih.
- [ ] Repository dan branch deployment dapat diakses oleh runner; workflow deployment tepercaya telah ditinjau.
- [ ] Dockerfile, Compose, `.dockerignore`, runtime HTTP, health check, dan satu scheduler tersedia serta telah dibangun.
- [ ] PostgreSQL berada di volume persisten pada LXC aplikasi; port `5432` tidak dipublikasikan.
- [ ] Acceptance concurrency dan report/dashboard PostgreSQL lulus pada database uji terpisah.
- [ ] `.env`, `APP_KEY`, role DB, SMTP, forwarded proxy Laravel, dan private evidence storage telah dikonfigurasi.
- [ ] Paparan HTTP pada LAN akibat firewall LXC nonaktif sudah ditinjau dan kebijakan akses disetujui pemilik; tidak ada firewall yang diaktifkan tanpa persetujuan.
- [ ] Backup database serta evidence berada di luar node Proxmox; restore rehearsal sukses.
- [ ] Gateway memakai Traefik `v3.7.17`, File Provider saja, tanpa Docker socket/provider, dan trusted subnet cloudflared telah divalidasi.
- [ ] Cloudflare Public Hostname menuju `traefik:80`; HTTPS publik, login, email verifikasi, dan route owner/Super Admin lolos smoke test.
- [ ] Tidak ada route publik untuk database, SSH, Proxmox, atau dashboard Traefik; tidak ada port forwarding router.
- [ ] Service inventory dan status `DEPLOY-HOME-01` sampai `DEPLOY-HOME-07` diperbarui berdasarkan bukti.

Kriteria keberhasilan menggunakan `https://kostkita.sequein.my.id`. Jika prompt/template lama menyebut `https://osc.mamay.my.id`, gunakan target URL KostKita di atas karena sesuai dengan domain dan subdomain yang ditetapkan pada bagian 2.

Deployment selesai bila setiap checklist bertanda selesai dan acceptance criteria yang terkait pada [Strategi Kualitas](../quality/testing.md) memiliki bukti uji. Status dokumen ini tidak boleh dianggap sebagai bukti bahwa go-live telah dilakukan.

## Referensi

- [HOMELAB-SSOT](HOMELAB-SSOT.md) — topologi ingress, provisioning, Cloudflare Tunnel, Traefik File Provider, self-hosted runner, dan inventory.
- [ADR-004](../decisions/ADR-004-proxmox-docker-deployment.md) — keputusan khusus runtime, PostgreSQL, port aplikasi, dan keamanan deployment KostKita.
- [Arsitektur Sistem](../architecture/overview.md) — Laravel/Inertia/Svelte, PostgreSQL, tenancy, dan scheduler.
- [Strategi Kualitas](../quality/testing.md) — deployment acceptance DEPLOY-HOME-01 sampai DEPLOY-HOME-07.
- [PostgreSQL acceptance test README](../../tests/Postgres/README.md) — konfigurasi database uji concurrency.
- [GitHub repository](https://github.com/belajar-self-hosted/kost-kita) — source code yang dituju.
