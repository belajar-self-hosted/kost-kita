# ADR-001: Integrasi Frontend Laravel

- Status: diterima
- Tanggal: 2026-11-03
- Pemilik keputusan: pengguna mendelegasikan pilihan frontend kepada agent

## Konteks

Produk adalah aplikasi manajemen kost untuk pemilik dan Super Admin. Penyewa kamar tidak login. Kebutuhan utama berupa dashboard privat, form dan tabel operasional, jadwal kamar, pembayaran, laporan, serta ekspor. Laravel sudah ditetapkan sebagai backend.

Dua opsi yang ada di dokumen awal adalah Laravel dengan Inertia + Svelte, atau Laravel API + SvelteKit. Penilaian memilih integrasi yang sesuai dengan aplikasi privat berbasis CRUD dan tidak menambah server frontend terpisah tanpa kebutuhan produk.

## Keputusan

Gunakan Laravel dengan Inertia.js dan Svelte:

- Laravel menangani routing, controller, autentikasi/session, otorisasi, validasi, dan business logic.
- Inertia menghubungkan route Laravel dengan halaman Svelte.
- Gunakan Svelte + TypeScript untuk antarmuka dan Tailwind CSS untuk styling.
- Gunakan Svelte starter kit resmi Laravel sebagai scaffold awal; dokumentasi resmi saat ini menyebut Svelte 5, TypeScript, Tailwind CSS, Vite, dan Inertia.
- Rilis MVP tidak memiliki SvelteKit server atau API JSON terpisah untuk UI pemilik.

Pilihan ini adalah rekomendasi arsitektur berdasarkan kebutuhan aplikasi, bukan klaim bahwa satu framework selalu paling unggul untuk semua produk.

## Alasan

Dokumentasi Laravel menyediakan starter kit Svelte berbasis Inertia dan menjelaskan bahwa integrasi ini memakai route/controller Laravel untuk routing, pemindahan data halaman, dan autentikasi dalam satu aplikasi. Ini sesuai untuk dashboard privat yang tidak membutuhkan indeksasi mesin pencari atau portal penyewa.

SvelteKit menyediakan router file-system, server-side rendering, pemuatan data server, form actions, dan adapter deployment. Kemampuan itu bermanfaat untuk frontend yang berdiri sendiri, halaman publik yang perlu SEO, atau klien berbeda. Kebutuhan sekarang tidak memerlukannya, sementara memakai dua server akan menambah batas autentikasi, kontrak API, konfigurasi deployment, dan pemeliharaan.

Kesimpulan trade-off tersebut adalah inferensi arsitektur dari scope yang diberikan pengguna. Inertia juga mendukung SSR bila kebutuhan produk kelak memerlukannya, sehingga keputusan ini tidak menutup opsi tersebut.

## Konsekuensi

- Route dan kontrak aksi UI ditulis pada Laravel; operasi mengembalikan halaman/data Inertia sesuai kebutuhan.
- Autentikasi memakai mekanisme session Laravel. Setiap halaman tetap memeriksa role dan ownership tenant di backend.
- Tidak ada kebutuhan CORS atau token API untuk komunikasi internal UI pada MVP.
- Aplikasi mobile, portal penyewa, atau klien pihak ketiga dapat memerlukan API terpisah dan menjadi keputusan baru.
- Hosting dan akses internet mengikuti [ADR-004](ADR-004-proxmox-docker-deployment.md). Jika SSR Inertia kelak diaktifkan, tambahkan worker/service SSR ke Docker Compose dan tinjau kebutuhan resource serta monitoringnya.

## Sumber resmi yang diperiksa

- [Laravel Frontend](https://laravel.com/framework/docs/frontend) — menjelaskan peran Inertia sebagai penghubung Laravel dengan Svelte dan pemakaian route/controller Laravel.
- [Laravel Starter Kits](https://laravel.com/framework/docs/starter-kits) — mencantumkan starter kit Svelte berbasis Inertia dan setup autentikasinya.
- [Inertia.js](https://inertiajs.com/) — menjelaskan routing sisi server, pemakaian autentikasi backend, dan tidak perlunya API terpisah untuk pola integrasinya.
- [SvelteKit Introduction](https://svelte.dev/docs/kit/introduction) — menjelaskan SvelteKit sebagai framework aplikasi dengan routing dan opsi rendering.
- [SvelteKit Routing](https://svelte.dev/docs/kit/routing) — menjelaskan route halaman/server, pemuatan data, dan form actions.
