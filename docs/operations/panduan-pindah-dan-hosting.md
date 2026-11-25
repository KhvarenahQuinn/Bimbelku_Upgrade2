# Panduan memindahkan aplikasi dan menyiapkan hosting

Gunakan langkah ini saat memindahkan BimbelKu ke server baru. Siapkan server tujuan, akses database, dan backup sebelum mengubah DNS. Jalankan perpindahan pada jadwal yang memungkinkan pengujian dan pemulihan.

## Inventaris sebelum perpindahan

Catat domain frontend dan API, document root, versi PHP dan extension, konfigurasi database, layanan mail, queue worker, scheduler, serta lokasi storage. Periksa daftar `environment variables` yang diperlukan di konfigurasi aplikasi tanpa menyalin nilainya ke dokumen atau chat.

Buat backup database dan file yang diperlukan. Simpan backup di lokasi privat. Uji bahwa backup dapat dibaca sebelum melanjutkan.

## Menyiapkan server baru

1. Pasang komponen server sesuai versi dan persyaratan yang telah dipakai proyek.
2. Buat database dan akun database dengan hak terbatas.
3. Siapkan HTTPS dan domain API/frontend. Atur `APP_URL` dan `FRONTEND_URL` pada backend, serta `VITE_API_BASE_URL` untuk alamat API frontend. Untuk sesi cookie lintas subdomain, tinjau `SANCTUM_STATEFUL_DOMAINS`, konfigurasi CORS, dan `SESSION_DOMAIN` pada `.env.example` serta dokumentasi deployment.
4. Tarik source code branch atau tag yang telah diuji. Jangan menjalankan update dependency selama migrasi.
5. Instal dependency dari lockfile menggunakan Composer dan npm.
6. Buat konfigurasi rahasia pada server dan periksa koneksi database, mail, cache, queue, serta storage. Simpan `APP_KEY` yang sama bila membawa database lama; jangan jalankan `key:generate` pada data tersebut. Atur key/token Xendit sesuai environment dan pastikan callback HTTPS `/api/webhooks/xendit` serta `x-callback-token` cocok dengan Dashboard. Isi `TURNSTILE_SECRET_KEY` di backend bila verifikasi Turnstile diaktifkan; secret tidak boleh berada di variabel `VITE_*` atau bundle frontend.
7. Bangun frontend dengan `npm ci` dan `npm run build`.
8. Atur document root frontend ke `dist/` dan backend ke `bimbelku-backend/public/`.

## Pemindahan data

1. Aktifkan mode pemeliharaan bila diperlukan agar perubahan data berhenti sementara.
2. Ekspor database sumber dan pindahkan melalui jalur terenkripsi ke server tujuan.
3. Impor data, lalu cocokkan tabel penting dan jumlah record yang relevan.
4. Salin file storage ke lokasi privat yang benar dengan izin akses terbatas. Jangan menyalin dokumen privat ke direktori publik.
5. Jalankan pemeriksaan aplikasi dan tes baca file pada server tujuan.
6. Jalankan migration hanya jika rilis yang digunakan memang menyertakan perubahan schema dan backup telah diverifikasi.

## Uji sebelum DNS dialihkan

- Buka frontend melalui URL sementara dan periksa tampilan serta routing.
- Uji endpoint API, login, pendaftaran, email verifikasi, dan pengambilan media publik.
- Pastikan dokumen privat ditolak untuk pengguna tanpa izin.
- Uji upload, pembacaan log terpisah, queue, dan scheduler.
- Gunakan sandbox untuk callback dan transaksi pembayaran. Setelah pembayaran, uji webhook Xendit dan rekonsiliasi terjadwal; jangan menganggap halaman checkout saja sebagai bukti callback telah bekerja.

Setelah semua pemeriksaan lolos, alihkan DNS. Pantau error aplikasi, antrean, log, serta respons API selama masa stabilisasi. Simpan konfigurasi lama dan backup sampai pemulihan tidak lagi diperlukan.

Baca [panduan hosting teknisi](panduan-hosting-untuk-teknisi.md), [keamanan deployment](../security/DEPLOYMENT_SECURITY.md), [pengaturan Cloudflare](../cloudflare-setup.id.md), dan [demo pembayaran sandbox](DEMO-PEMBAYARAN-SANDBOX.md) untuk langkah terkait. Daftar variabel terbaru berada di `bimbelku-backend/.env.example`; gunakan key dan token contoh sebagai nama variabel saja, jangan salin nilai rahasia ke dokumentasi.
