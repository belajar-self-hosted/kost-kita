# Keamanan dan Akses

## Role dan status keputusan

| Aktor          | Izin yang tercatat                                                                                                                                                        | Status                                                                                   |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| Pemilik kost   | Mengelola properti/kamar, booking, penyewa, pembayaran, perawatan, laporan, serta kontak tenant melalui WhatsApp                                                          | MVP                                                                                      |
| Super Admin    | Melihat daftar dan status seluruh pemilik; mengaktifkan/menonaktifkan akun; mengelola akun sesuai kewenangan; melihat statistik jumlah pemilik aktif, properti, dan kamar | Baseline; akses ke data operasional lintas tenant harus punya alasan dan batas eksplisit |
| Pengelola/staf | Akun dengan akses terbatas                                                                                                                                                | Tidak memiliki login pada MVP                                                            |
| Penyewa        | Dicatat oleh pemilik; tidak memiliki akun atau akses aplikasi                                                                                                             | Tidak memiliki login                                                                     |

MVP hanya memiliki dua role login: Super Admin dan Pemilik Kost. Jangan membuat route atau akun login staf pada MVP; jika kebutuhan itu muncul untuk rilis berikutnya, tentukan matriks izinnya terlebih dahulu.

Super Admin tidak boleh mengubah catatan sewa atau finansial tenant secara otomatis. Tindakan administratif terhadap data operasional hanya boleh dilakukan melalui izin yang eksplisit dan audit trail.

## Isolasi tenant dan otorisasi

- Pemilik hanya dapat membaca atau mengubah data tenant miliknya.
- Setiap operasi membaca kepemilikan dari identitas terautentikasi dan relasi database, bukan mempercayai ID yang dikirim UI.
- Terapkan pemeriksaan ownership pada policy/otorisasi dan jalur backend yang memuat atau mengubah resource.
- Terapkan scoping tenant pada query dan ekspor agar ID langsung tidak membuka data tenant lain.
- Uji pemilik A tidak dapat membaca, mengubah, atau mengekspor resource pemilik B.
- Super Admin memakai jalur izin platform yang eksplisit. Perubahan data tenant harus tercatat; akses administratif tidak boleh menjadi bypass tersembunyi.

Pemeriksaan selesai bila setiap resource tenant-owned mengembalikan penolakan/not-found yang aman saat diminta aktor tanpa hak dan tidak menghasilkan data silang pada halaman, API, maupun file.

## Autentikasi dan sesi

Persyaratan yang tercatat:

- hashing kata sandi yang aman;
- registrasi/login, logout, pemulihan dan reset kata sandi;
- verifikasi email;
- invalidasi sesi saat logout;
- rate limiting untuk login dan endpoint sensitif;
- proteksi CSRF bila autentikasi menggunakan session/cookie;
- pembatasan fitur administratif berdasarkan role.

Rincian autentikasi dikelola oleh Laravel dan sesuai dengan stack yang ditetapkan di [Arsitektur Sistem](../architecture/overview.md).

## Perlindungan input dan operasi

- Semua input divalidasi backend sebelum tersimpan.
- Cegah broken access control, IDOR, SQL injection, XSS, CSRF, brute force, mass assignment, paparan informasi sensitif, dan ekspor tanpa izin.
- Operasi pembayaran, booking, perpanjangan, dan perubahan administratif memakai transaksi/konkurensi yang sesuai.
- Rahasia kredensial berada di konfigurasi environment/secret store, bukan source code.
- Catat audit untuk perubahan finansial serta tindakan administratif penting.

Pemeriksaan keamanan dan daftar verifikasi untuk area bisnis terdapat di [Strategi Kualitas](../quality/testing.md).
