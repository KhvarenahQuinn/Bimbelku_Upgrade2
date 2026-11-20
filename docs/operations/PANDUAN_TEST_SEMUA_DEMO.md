# Panduan menjalankan demo dan tes BimbelKu

Panduan ini membantu menjalankan demo lokal, mengecek hasil pada frontend dan backend, serta membedakan fixture pengujian dari alur pembayaran sandbox. Gunakan data uji dan hindari kredensial production.

## Sebelum menjalankan demo

- Pastikan backend Laravel dan database lokal aktif serta migrasi sesuai versi source code.
- Bangun frontend dengan `npm run build`, lalu jalankan preview melalui script yang disediakan proyek.
- Untuk tes gateway, gunakan Xendit test mode dan periksa `APP_ENV` serta `XENDIT_ENABLED`.
- Catat akun dan identitas fixture yang dicetak oleh command demo. Jangan gunakan akun tersebut di production.
- Jalankan satu skenario sampai selesai sebelum membuat fixture berikutnya. Beberapa command demo mengganti data fixture sebelumnya.

## Perintah pemeriksaan yang umum

Dari root repository:

```sh
npm run typecheck
npm run lint
npm run build
```

Dari `bimbelku-backend/`:

```sh
./vendor/bin/pint --test
php artisan test
php artisan route:list
```

Tes otomasi terpilih dapat dijalankan dengan filter PHPUnit, misalnya `php artisan test --filter=UploadSecurityTest`.

## Alur demo pembayaran

Buat pesanan dari aplikasi, lanjutkan checkout sandbox, lalu kembali ke halaman tagihan dan gunakan pemeriksaan status jika webhook tidak dapat mencapai lingkungan lokal. Pastikan status pada riwayat transaksi terverifikasi dari backend sebelum menguji matching, kelas, atau refund.

Untuk mempercepat demo lokal, command `demo:journey-time` dapat menggeser waktu sesi uji. Command ini tidak menggantikan aksi tutor dan murid pada aplikasi: tutor tetap harus memulai sesi, murid mengonfirmasi kehadiran, tutor mengirim laporan, dan murid atau admin menyelesaikan verifikasi sesuai alur.

Lihat [panduan demo pembayaran](DEMO-PEMBAYARAN-SANDBOX.md) untuk tahapan checkout, perpanjangan, pergantian tutor, serta batas simulasi lokal. Daftar skenario dan status tes ada di [testing-matrix.md](testing-matrix.md).
