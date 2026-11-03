# Arsitektur Sistem

## Stack aplikasi

Keputusan frontend/backend yang disetujui: Laravel dengan Inertia.js dan Svelte. Gunakan Laravel sebagai aplikasi backend dan routing utama, Inertia sebagai penghubung halaman, serta Svelte + TypeScript untuk UI. Pilihan dan alasannya dicatat pada [ADR-001](../decisions/ADR-001-frontend-integration.md).

Gunakan Svelte starter kit resmi Laravel sebagai titik awal. Dokumentasi Laravel saat ini menyediakan starter kit Svelte berbasis Inertia; kit tersebut menggunakan Svelte 5, TypeScript, Tailwind CSS, dan Vite. Pilih versi Laravel yang masih didukung saat proyek mulai dibangun.

Database produksi: PostgreSQL di dalam Docker Compose pada LXC aplikasi KostKita; SQLite digunakan untuk pengembangan lokal dan test. LXC aplikasi terpisah dari gateway ingress LXC 10080. Topologi, routing, dan CI/CD mengikuti [HOMELAB-SSOT](../deployment/HOMELAB-SSOT.md); keputusan khusus KostKita tercatat di [ADR-004](../decisions/ADR-004-proxmox-docker-deployment.md).

## Batas sistem

Arsitektur tingkat tinggi:

1. Antarmuka web responsif untuk pemilik dan role platform yang disetujui.
2. Backend sebagai sumber kebenaran untuk identitas, otorisasi, validasi, availability, status bisnis, dan kalkulasi keuangan.
3. Database relasional untuk data properti, jadwal, masa sewa, kewajiban, pembayaran, perawatan, dan audit.
4. Proses terjadwal untuk membuat tagihan bulanan dan memperbarui pengingat di dashboard pemilik.
5. Ekspor laporan menghasilkan file dari query backend yang sudah menerapkan scope tenant dan filter.

Untuk trafik publik, Cloudflare Edge menerima HTTPS lalu meneruskan melalui Cloudflare Tunnel remote-managed ke Traefik v3 di gateway LXC `10080` (`192.168.0.80`). Traefik memakai Dynamic File Provider untuk meneruskan request melalui LAN `192.168.0.0/24` ke service HTTP pada IP statis LXC aplikasi. Tunnel, jaringan `web-gateway`, domain routing, dan inventaris layanan mengikuti [dokumen homelab](../deployment/HOMELAB-SSOT.md). KostKita tidak menjalankan Traefik/cloudflared kedua di LXC aplikasi dan tidak memakai port forwarding router.

UI pemilik dan UI Super Admin berada di aplikasi Laravel yang sama. Data halaman diteruskan ke komponen Svelte melalui Inertia. Autentikasi, session, route, dan otorisasi dikelola di Laravel. Aplikasi tidak memerlukan frontend server atau API JSON terpisah untuk rilis ini.

Jika kelak dibutuhkan aplikasi mobile, portal pengguna lain, atau klien pihak ketiga, evaluasi API terpisah sebagai keputusan baru. Kebutuhan itu belum termasuk scope saat ini.

## Kontrak route/action

Kontrak implementasi Laravel berada di [Route/Action MVP](route-actions.md). Setiap operasi harus mencatat input, validasi, otorisasi, tenant scope, respons Inertia, dan error yang dapat ditangani UI. Keputusan transport dan autentikasi tercatat di [ADR-001](../decisions/ADR-001-frontend-integration.md).

Kelompok aksi yang harus dicakup:

- **Akun:** registrasi pemilik, login/logout, verifikasi email, dan pemulihan kata sandi.
- **Platform:** dasbor Super Admin, daftar/detail pemilik, aktif/nonaktif akun, serta statistik pemilik/properti/kamar.
- **Operasional pemilik:** properti, tipe kamar, kamar dan harga; booking; penyewa dan masa sewa; tagihan dan pembayaran; maintenance dan biaya operasional.
- **Dasbor/laporan:** metrik, filter laporan, dan ekspor yang menggunakan tenant scope dan filter sama.

Jangan menerima status booking atau tenancy dari klien sebagai transisi bebas; backend harus memvalidasi transisi pada [Booking dan Masa Sewa](../business/booking-tenancy.md). Route contract hanya memetakan aturan pemilik topik, bukan menggantikan aturan bisnisnya.

## Multi-tenant

Baseline produk adalah SaaS dengan database bersama dan scoping tenant. Pemilik dapat memiliki beberapa properti; setiap data harus memiliki jalur ownership yang bisa diverifikasi ke pemilik. Query, route model binding, operasi layanan, dan ekspor memeriksa ownership di backend.

Super Admin membutuhkan scope administratif lintas tenant untuk fungsi platform yang diizinkan. Scope tersebut tidak berarti boleh mengubah transaksi finansial tenant secara diam-diam. Detail izin ada di [Keamanan dan Akses](../security/access-control.md).

## Batas tanggung jawab

- UI menampilkan form, status, dan hasil backend; UI tidak menjadi sumber perhitungan keuangan atau availability.
- Backend memvalidasi setiap input penting dan menyimpan perubahan terkait booking/keuangan secara atomik.
- Database menjaga foreign key, keunikan dalam lingkup properti, dan batasan nilai yang dapat diekspresikan dengan aman.
- Scheduler/queue hanya menjalankan pekerjaan yang kebijakannya telah disetujui dan dapat diulang dengan aman.
- Semua angka di dasbor, laporan, dan ekspor berasal dari definisi yang sama di [Keuangan dan Laporan](../business/finance.md).

## Infrastruktur yang tercatat

KostKita berjalan pada LXC aplikasi khusus yang terpisah dari gateway ingress. Docker Compose di LXC aplikasi menjalankan Laravel dan PostgreSQL pada jaringan internal Compose; PostgreSQL tidak memublikasikan port ke LAN atau internet. Hanya port HTTP aplikasi yang dapat dijangkau dari gateway `192.168.0.80`, sesuai port yang dicatat di inventaris layanan.

GitHub Actions self-hosted runner berada di LXC aplikasi dan membangun image secara lokal tanpa GHCR, sesuai standar homelab. File `.env` produksi berada di LXC dengan izin terbatas dan tidak ikut sinkronisasi source/deploy. Backup database harus disalin ke penyimpanan di luar node Proxmox dan prosedur restore diuji. Rincian routing, TLS edge, runner, serta trade-off LXC/Docker ada pada [ADR-004](../decisions/ADR-004-proxmox-docker-deployment.md) dan [HOMELAB-SSOT](../deployment/HOMELAB-SSOT.md).

Persyaratan operasional mencakup konfigurasi berbasis environment/secret, logging, pemantauan error, deployment yang dapat diulang, restore database yang terdokumentasi, HTTPS publik via Cloudflare Edge, serta CI/CD homelab. TLS berakhir di Cloudflare Edge; request bergerak melalui tunnel ke Traefik, lalu melalui HTTP internal pada bridge/LAN ke aplikasi. Laravel harus dikonfigurasi untuk URL publik HTTPS dan mempercayai forwarded headers hanya dari gateway yang ditetapkan. Untuk urutan kerja, lihat [Roadmap](../plan/roadmap.md).
