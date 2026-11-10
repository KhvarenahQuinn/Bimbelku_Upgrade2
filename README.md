# BimbelKu

BimbelKu adalah aplikasi pencocokan murid dan tutor berbasis jadwal, materi, mode belajar, performa, serta jarak untuk kelas offline. Proyek ini memakai React + TypeScript pada frontend dan Laravel API + MySQL pada backend.

Versi ini memuat Fondasi Tahap 1, Tampilan Mobile Tahap 2, Keamanan Keuangan
Tahap 3, Pencocokan dan Sesi Tahap 4, serta Paket, Promo, Konten, dan Dashboard
Tahap 5, alur murid Tahap 6A, operasional tutor Tahap 6B, admin operasional Tahap 6C, serta finalisasi alur dan cleanup Tahap 6D per 15 September 2026. Tidak ada marketplace tutor bebas, payment gateway, cashback, referral, atau pencairan otomatis.

## Fitur utama

- Tiga peran terpisah: murid, tutor, dan admin.
- Persetujuan orang tua atau wali dicatat untuk pendaftaran murid di bawah 18 tahun.
- Verifikasi tutor dengan identitas, foto wajah langsung, ijazah/kualifikasi, serta sertifikat opsional.
- Calon tutor mengirim data pendaftaran, memverifikasi email, lalu mengikuti seleksi dan tes melalui WhatsApp sebelum admin dapat mengaktifkan akun.
- Kategori pembelajaran dibatasi menjadi SD, SMP, SMA, dan Umum.
- Satu mata pelajaran utama dan beberapa jenjang per tutor.
- Rentang jam tersedia per hari; sistem menolak benturan sesi.
- Pencarian otomatis berdasarkan kompetensi, seluruh jadwal paket, jarak untuk kelas offline, kontinuitas, beban kelas, dan riwayat kesempatan penawaran; rating hanya menjadi pemecah seri terakhir.
- Permintaan lanjutan memprioritaskan tutor sebelumnya apabila seluruh syarat masih terpenuhi.
- Paket 1, 4, 8, atau 12 sesi dengan masa penggunaan 7 atau 30 hari.
- Paket dapat dibagi kepada maksimal tiga mapel dengan tutor berbeda.
- Satu sesi paket berlangsung 60 menit pada slot tepat di pergantian jam.
- Admin mengatur pilihan slot; murid tidak memasukkan jam paket secara bebas.
- Satu tagihan paket dibuka sebelum pencarian tutor; pencarian baru dimulai setelah pembayaran diverifikasi.
- Mesin matching mengirim satu penawaran pada satu waktu secara berurutan dan melanjutkan antrean saat penawaran ditolak atau kedaluwarsa.
- Rekomendasi jadwal mempertahankan pola hari dan membandingkan jam berdasarkan guru yang dapat memenuhi seluruh sesi.
- Perpanjangan per mapel memprioritaskan tutor lama tujuh hari sebelum paket berakhir.
- Voucher klaim dan kode promo memakai satu mesin diskon backend.
- Harga promo menampilkan harga normal dicoret, harga akhir, dan label diskon kecil.
- Banner empat detik dan tutorial carousel dikelola admin melalui CRUD.
- Dashboard murid adaptif menampilkan paket, sisa sesi, tutor, jadwal, progres, dan voucher.
- Dashboard admin menampilkan antrean pembayaran, pencarian tutor, kasus, refund, pencairan, dan verifikasi.
- Admin dapat memperluas radius 3/5/8/12 km dan menetapkan tutor yang lolos validasi secara manual.
- Satu admin utama memiliki seluruh akses operasional; pembuatan admin tambahan ditutup dan seluruh mutasi admin tetap masuk audit berantai.
- Radar pencarian visual dengan radius offline 3, 5, 8, sampai 12 km.
- Formulir permintaan dibagi menjadi empat langkah pada ponsel dan desktop.
- Pemeriksaan slot tutor dilakukan sebelum permintaan dikirim tanpa membuka identitas tutor.
- Navigasi bawah dibedakan untuk murid dan tutor pada layar ponsel.
- Panduan singkat disediakan sesuai halaman dan peran pengguna.
- Tabel riwayat tutor diubah menjadi kartu pada layar kecil.
- Kelas privat; fondasi kelompok lama tetap tersimpan tetapi tidak dipromosikan pada Tahap 5.
- Materi bertingkat: jenjang, kelas, mata pelajaran, bab, submateri, tujuan, catatan, dan lampiran.
- Pembayaran diverifikasi sebelum matching dimulai; profil tutor ditampilkan setelah tutor menerima penawaran dan penetapan berhasil.
- Transfer manual ke admin, verifikasi bukti, penolakan, serta unggah ulang bukti.
- Komisi tersimpan sebagai snapshot transaksi; nilai awal 20%.
- Jurnal dana berpasangan dan tidak dapat diedit langsung.
- Kunci idempotensi mencegah pembayaran, refund, dan pencairan tercatat dua kali.
- Modul keuangan hanya dapat diproses admin utama dan tidak lagi memakai halaman kode autentikator terpisah.
- Rekening tutor yang baru diubah menahan pencairan selama 24 jam.
- Pencairan bernilai besar tetap memerlukan bukti transfer, konfirmasi, idempotensi, dan audit, tetapi tidak memerlukan admin kedua.
- Tindakan keuangan masuk ke rantai audit yang tidak dapat diubah.
- Bukti pelaksanaan, persetujuan murid, keberatan 48 jam, dan pemeriksaan admin.
- Chat kelas internal yang menolak kontak pribadi, akun media sosial, dan tautan luar.
- PIN murid, check-in tutor, lokasi offline, check-out, dan durasi aktual.
- Asesmen awal, target yang disetujui murid, laporan sesi, dan progres belajar.
- Laporan ketidakhadiran murid/tutor, keadaan darurat, refund penuh, dan pencairan manual.
- Kualitas tutor memakai rating dari sesi nyata dan pemeriksaan admin, tanpa sistem poin atau prioritas otomatis untuk rating tertinggi.
- Dialog konfirmasi dan toast buatan aplikasi; tidak memakai `alert()` atau `confirm()` bawaan browser.
- Dokumen dan bukti sensitif disimpan privat serta hanya dibuka melalui API terautentikasi.
- Email, nomor pribadi, dan data wali tidak diberikan kepada pengguna lain.
- Lazy loading halaman dan bundle produksi terpisah agar frontend tetap ringan.

## Kebutuhan sistem

- Node.js 20 atau lebih baru
- npm 10 atau lebih baru
- PHP 8.2 atau lebih baru
- Composer 2
- MySQL 8 atau MariaDB yang kompatibel

## Instalasi frontend

Jalankan dari folder utama proyek:

```bash
cp .env.example .env
npm ci
npm run dev
```

Frontend berjalan di `http://127.0.0.1:8080`. Ubah `VITE_API_BASE_URL` jika alamat API berbeda.

## Jalankan semuanya dengan satu perintah

Untuk pengembangan lokal, seluruh stack bisa dinyalakan sekaligus:

```bash
npm start
# atau
./start.sh
```

Perintah tersebut akan:

- membuat `.env` dari `.env.example` bila belum ada, serta `APP_KEY` backend;
- menginstall dependency frontend/backend bila `node_modules` atau `vendor` belum ada;
- menyalakan container database lewat `bimbelku-backend/docker-compose.yml`;
- menunggu database benar-benar siap sebelum melanjutkan;
- menjalankan `php artisan migrate` dan seeder awal bila database masih kosong/baru sebagian;
- menjalankan backend `http://127.0.0.1:8000`, Vite `http://127.0.0.1:8080`, dan `schedule:work`;
- memakai service yang sudah aktif bila portnya sudah terpakai.

Opsi yang tersedia:

```bash
npm start -- --no-docker      # database sudah dikelola sendiri
npm start -- --no-scheduler   # tanpa schedule:work
npm start -- --no-frontend    # hanya backend + scheduler
```

Log tiap service ditulis ke folder `.run/`. Tekan `Ctrl+C` untuk menghentikan service yang dijalankan perintah ini.


## Instalasi backend

Masuk ke folder backend:

```bash
cd bimbelku-backend
composer install
cp .env.example .env
php artisan key:generate
```

Buat database MySQL bernama `bimbelku`, lalu sesuaikan bagian `DB_*` dalam `.env`.

Sebelum seeding, isi akun admin awal dengan kata sandi minimal 12 karakter:

```dotenv
PRIMARY_ADMIN_EMAIL=admin@domain-anda.id
SEED_ADMIN_EMAIL=admin@domain-anda.id
SEED_ADMIN_PASSWORD=kata-sandi-kuat-anda
SEED_DEMO_USERS=false
```

Project memakai satu admin utama. Halaman autentikator dan alur persetujuan admin kedua sudah dihentikan. Keamanan tindakan sensitif tetap menggunakan pembatasan role, validasi admin utama, idempotensi, bukti transfer, dan audit berantai. Pada produksi gunakan `APP_ENV=production`, `APP_DEBUG=false`, kata sandi admin kuat, HTTPS, serta konfigurasi email dan database produksi yang benar.

Penggantian guru aktif secara default (`FEATURE_TEACHER_REPLACEMENT=true`). Nilai
`false` hanya digunakan sebagai emergency kill switch; setelah mengubahnya,
jalankan `php artisan config:clear`.

Lanjutkan:

```bash
php artisan migrate --seed
php artisan storage:link
php artisan serve --host=127.0.0.1 --port=8000
```

Foto publik kini dibuka melalui endpoint API `public-media`. Foto tetap dapat
ditampilkan ketika symbolic link `public/storage` belum tersedia atau `APP_URL`
berbeda dari alamat Laragon. Perintah `storage:link` tetap disarankan untuk
kompatibilitas berkas lama.

Jika halaman admin Kelas Murah menampilkan peringatan database, jalankan:

```bash
cd bimbelku-backend
php artisan migrate
php artisan optimize:clear
php artisan route:list --path=cheap-class
```

Jalankan backend dari folder proyek yang sama dengan frontend. Backend lama
tidak memiliki route dan tabel Kelas Murah terbaru.

Jika pesan tetap muncul setelah project diganti, hentikan proses backend lama
dengan `Ctrl+C`, lalu jalankan kembali `php artisan serve` dari folder
`bimbelku-backend` milik project yang baru. Route list harus menampilkan endpoint
admin, murid, dan guru untuk `cheap-class`.

Migration terakhir juga memindahkan dokumen atau bukti sensitif dari disk publik instalasi lama ke disk privat. Karena itu, lakukan backup `storage/app` sebelum menjalankan migration pada data produksi.

Migration Tahap 1 menambahkan tanggal lahir dan persetujuan wali secara
bertahap. Data akun lama tidak dihapus. Akun lama yang belum mempunyai tanggal
lahir tetap dapat digunakan dan dapat dilengkapi melalui proses administrasi.

Migration Tahap 3 menonaktifkan katalog Perguruan Tinggi dan Semester tanpa
menghapus transaksi lama. Migration historis ini juga menambahkan jurnal keuangan,
idempotensi, penahanan rekening tutor, dan log audit. Struktur lama untuk autentikator
dan persetujuan admin kedua dipertahankan pada riwayat migration, tetapi tidak lagi
dipakai oleh route atau halaman runtime.

Migration Tahap 4 menambahkan chat kelas, PIN, kehadiran, rencana belajar, dan
laporan progres. Migration ini tidak menghapus akun, kelas, atau transaksi lama.

Migration Tahap 5 menambahkan paket, alokasi mapel, sesi paket, promo, klaim,
banner, tutorial, dan perpanjangan tutor. Migration ini juga menghubungkan order
dan booking lama secara nullable sehingga riwayat Tahap 1–4 tetap dapat dibuka.

Pada terminal backend kedua, jalankan proses tenggat:

```bash
php artisan schedule:work
```

Scheduler wajib aktif karena menangani reminder penawaran, penawaran tutor kedaluwarsa, rolling pool, retry pencarian, batas pembayaran, waktu mulai sesi, dan antrean pemeriksaan 48 jam. Halaman admin Pencarian Tutor menampilkan heartbeat; status “belum terdeteksi” berarti `schedule:work` atau cron perlu diperiksa.

Aturan awal pemesanan privat adalah minimum 24 jam sebelum sesi pertama, atau 12 jam untuk perpanjangan dengan tutor yang sama. Penawaran guru berlaku 60 menit, mendapat satu pengingat sekitar menit ke-30, dan pencarian berhenti sebelum cutoff kelas. Nilai tersebut tersimpan dalam tabel `settings` dan dibaca bersama oleh frontend serta backend.

Jangan menjalankan `php artisan migrate:fresh` pada database yang berisi data penting. Perintah tersebut menghapus seluruh tabel dan isi database.

## Konfigurasi email

OTP verifikasi akun, reset PIN pembayaran, dan reset kata sandi memakai konfigurasi `MAIL_*` Laravel. Pada instalasi lokal, `MAIL_MAILER=log` sengaja menulis email ke `bimbelku-backend/storage/logs/laravel.log`.

Untuk pengiriman gratis dengan Brevo SMTP, aktifkan Transactional Email, autentikasi domain/alamat pengirim, lalu buat SMTP key. Gunakan SMTP login dan SMTP key dari halaman **Settings > SMTP & API**; jangan memakai password akun atau API key dan jangan commit rahasianya:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_USERNAME=LOGIN_SMTP_BREVO
MAIL_PASSWORD=SMTP_KEY_BREVO
MAIL_FROM_ADDRESS=noreply@domain-terverifikasi.id
MAIL_FROM_NAME="${APP_NAME}"
```

Port 587 adalah pilihan utama. Jika jaringan memblokirnya, Brevo juga menyediakan port 2525.

Setelah mengubah environment produksi, muat ulang konfigurasi Laravel:

```bash
php artisan optimize:clear
php artisan config:cache
```

Kegagalan pengiriman OTP mengembalikan respons sementara dan membatalkan kode yang baru dibuat, sehingga pengguna dapat langsung mencoba kirim ulang tanpa menunggu cooldown.

Pastikan `FRONTEND_URL` berisi tepat satu origin frontend kanonis karena nilai ini digunakan untuk membuat link email. Gunakan `FRONTEND_ORIGINS` sebagai daftar origin CORS yang diizinkan dan pisahkan beberapa origin dengan koma.

## Akun demo opsional

Data demo tidak dibuat secara default. Untuk lingkungan lokal saja, ubah:

```dotenv
SEED_DEMO_USERS=true
```

Lalu jalankan:

```bash
php artisan db:seed
```

Jangan mengaktifkan akun demo pada produksi.

## Pemeriksaan kualitas

Frontend:

```bash
npm run check:checkpoint1
npm run check
```

Pemeriksaan khusus Tahap 1 pada Windows Laragon:

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\scripts\test-tahap1.ps1
```

Pemeriksaan Tahap 2:

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\scripts\test-tahap2.ps1
```

Pemeriksaan Tahap 3:

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\scripts\test-tahap3.ps1
```

Perintah Tahap 3 menjalankan ESLint, TypeScript, kontrak API, build produksi,
migration bertahap, seluruh tes Laravel, dan verifikasi rantai jurnal keuangan.

Pemeriksaan Tahap 4:

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\scripts\test-tahap4-learning.ps1
```

Pemeriksaan Tahap 5:

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\scripts\test-tahap5.ps1
```

Pemeriksaan final Tahap 6C:

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\scripts\test-stage6c-final.ps1 -SkipInstall
```

Backend, setelah Composer terpasang:

```bash
cd bimbelku-backend
php artisan test
php artisan route:list
```

## Struktur penting

```text
src/                         Frontend React
src/pages/                   Halaman publik, murid, tutor, admin
src/components/              Layout, dialog, dan komponen bersama
bimbelku-backend/app/        Logika Laravel
bimbelku-backend/routes/     Rute API dan scheduler
bimbelku-backend/database/   Migration, factory, dan seeder
PRD_REVISI_BIMBELKU.md       Aturan produk terbaru
IMPLEMENTATION_STATUS.md     Cakupan implementasi dan verifikasi
REVISION_NOTES.md            Ringkasan perubahan dari versi lama
```

## Catatan pembayaran dan keamanan admin

Sistem tidak memindahkan uang. Murid mentransfer ke rekening admin, admin memeriksa bukti terhadap mutasi rekening, lalu sistem mencatat statusnya. Bank dan nomor rekening/e-wallet asal ikut dicatat sebagai tujuan refund. Refund dan pendapatan tutor ditransfer manual oleh admin, disertai bukti transfer.

Nominal, komisi, dan rekening pembayaran dapat diatur admin. Perubahan komisi hanya berlaku untuk transaksi baru karena setiap booking menyimpan snapshot persentasenya.

Runtime terbaru memakai satu admin utama tanpa halaman kode autentikator dan tanpa admin kedua. Tindakan keuangan tetap dibatasi oleh role admin utama, validasi server, transaksi database, kunci idempotensi, bukti transfer privat, penahanan rekening tutor, dan audit berantai. Produksi wajib memakai kata sandi admin unik, HTTPS, `APP_DEBUG=false`, backup, serta pembatasan akses panel admin.

## Checklist produksi

- Gunakan `APP_ENV=production` dan `APP_DEBUG=false`.
- Gunakan HTTPS.
- Isi `APP_URL`, `FRONTEND_URL`, database, mail, dan akun admin dengan nilai produksi.
- Jalankan queue/scheduler melalui process manager.
- Atur backup database dan berkas `storage/app`.
- Batasi akses panel admin dan gunakan kata sandi unik.
- Jalankan `npm audit --omit=dev` dan `composer audit` kembali sebelum setiap rilis.
- Jangan menyertakan `.env`, dump database, atau kredensial ke repositori/ZIP publik.
- Tinjau Syarat & Ketentuan serta Kebijakan Privasi bersama pihak yang berwenang sebelum peluncuran.

## Audit Checkpoint 2 — Operasional inti

Audit kedua memeriksa halaman dan API admin, tutor, murid, kelas, jadwal, serta pembayaran. Perbaikan utama mencakup interval jadwal 10 menit pada UI dan backend, jumlah pesan dashboard murid dari database, penguncian perubahan rekening selama tagihan paket aktif, serta pembatasan pelepasan penawaran hanya ketika tutor diblokir.

Pemeriksaan khusus:

```powershell
cd C:\laragon\www\Website_Bimbelku
npm run check:checkpoint2

cd bimbelku-backend
php artisan test --filter=CheckpointTwoOperationsAuditTest
```

Laporan lengkap tersedia pada `AUDIT_CHECKPOINT_2_ADMIN_GURU_MURID_KELAS_JADWAL_PEMBAYARAN_2026-08-03.md`.


## Audit Checkpoint 3 — Komunikasi dan file privat

Checkpoint ketiga memperbaiki privasi laporan kelas kelompok, mengembalikan pesan sistem pesanan ke controller chat aktif, menandai seluruh pesan belum dibaca tanpa batas 100, mencegah laporan sesi ganda, membuat query tiket portabel, menambahkan notifikasi bantuan untuk pihak lawan, dan mengamankan nama unduhan file privat.

Tes lokal utama: `php artisan test --filter=CheckpointThreeCommunicationAuditTest`.

## Audit kualitas Checkpoint 4

Pemeriksaan responsif, keamanan, performa, dan aksesibilitas tersedia melalui:

```powershell
npm run check:checkpoint4
```

Token API default berlaku 720 menit melalui `SANCTUM_TOKEN_EXPIRATION`. Vite hanya membuka `127.0.0.1` secara default; gunakan `npm run dev -- --host 0.0.0.0` hanya ketika perlu menguji dari HP pada jaringan lokal tepercaya.
