# Panduan hosting untuk teknisi

Dokumen ini merangkum langkah operasional untuk menyiapkan BimbelKu pada hosting yang mendukung PHP dan Node.js. Sesuaikan command dengan sistem operasi, versi PHP yang sudah dipakai proyek, serta layanan hosting yang tersedia. Jangan mengganti versi framework atau Composer/npm dalam proses deployment ini.

## Struktur layanan

- Frontend hasil build dilayani dari direktori `dist/`.
- API Laravel dilayani dari `bimbelku-backend/public/`.
- Jangan jadikan root repository, folder `vendor/`, `storage/`, atau folder backup sebagai document root.
- Database dan file privat harus berada di luar direktori yang dapat diakses publik.

## Persiapan server

1. Pastikan versi PHP memenuhi kebutuhan yang tercatat di `bimbelku-backend/composer.json`, beserta extension PHP yang diperlukan.
2. Sediakan database khusus aplikasi dan akun dengan hak akses terbatas pada database tersebut.
3. Siapkan HTTPS dan domain API/frontend. Pastikan origin menerima koneksi hanya melalui konfigurasi jaringan yang disetujui.
4. Buat konfigurasi runtime pada server. Atur `APP_KEY`, koneksi database, URL aplikasi, mail, dan integrasi pembayaran melalui `environment variables` atau file `.env` privat.
5. Pastikan `storage/` dan `bootstrap/cache/` dapat ditulis oleh user proses Laravel. Source code lain sebaiknya read-only.

## Deployment aplikasi

1. Ambil source code dari branch/rilis yang sudah diverifikasi.
2. Jalankan `composer install` dengan lockfile yang ada; jangan memperbarui dependency saat deployment.
3. Jalankan `npm ci` dan `npm run build` dari root repository.
4. Arahkan document root frontend ke `dist/`, dan API ke `bimbelku-backend/public/`.
5. Jalankan `php artisan migrate --force` hanya setelah backup dan pemeriksaan rilis selesai.
6. Jalankan `php artisan optimize` setelah konfigurasi production tersedia.
7. Pastikan queue worker aktif bila aplikasi memakai queue. Daftarkan scheduler Laravel dengan cron per menit.
8. Uji halaman utama, API health yang tersedia, login, pendaftaran, email, file privat, dan alur callback sandbox sebelum membuka trafik.

## Storage dan log

Disk `public` memuat media yang memang boleh ditampilkan publik dan harus dilayani melalui route media aplikasi. Disk `local` menyimpan dokumen privat. Jangan membuat symlink ke lokasi dokumen privat.

Log aplikasi dipisahkan menurut kanal dan memakai `daily log rotation`. Pantau kegagalan scheduler, webhook, pembayaran, dan upload. Jangan mencatat token, password, OTP, nomor rekening lengkap, atau isi dokumen.

## Keamanan dan pemeliharaan

- Atur HTTPS, firewall, dan pembatasan akses SSH.
- Aktifkan WAF dan rate limiting sesuai dokumentasi [Cloudflare](../CLOUDFLARE_SETUP.md).
- Pastikan respons file menyertakan `X-Content-Type-Options: nosniff` dan `Content Security Policy` sandbox.
- Simpan backup database di lokasi privat dan lakukan uji pemulihan berkala.
- Pantau ruang disk, kegagalan backup, queue, scheduler, serta aktivitas admin.
- Perbarui server dan dependency melalui perubahan yang terencana, lalu jalankan tes yang sesuai.

Untuk pemeriksaan tambahan, gunakan [checklist keamanan deployment](../security/DEPLOYMENT_SECURITY.md).
