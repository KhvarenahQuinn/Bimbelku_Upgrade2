# API BimbelKu

API backend berjalan melalui Laravel di `bimbelku-backend`. Daftar route aktif dapat diperiksa dengan `php artisan route:list` dari direktori backend. Endpoint publik dan endpoint yang memerlukan sesi tercantum pada `routes/api.php`.

## Keamanan request

Endpoint pendaftaran menerapkan validasi email provider konsumen tepercaya dan domain institusi pendidikan. Endpoint yang menerima perubahan berulang dapat memakai `idempotency key`; batas request publik menerapkan `rate limiting`. Integrasi pembayaran memverifikasi `webhook signature` sebelum memproses callback.

File privat hanya dapat diakses melalui endpoint yang memeriksa hak akses pengguna. Respons file menyetel `X-Content-Type-Options: nosniff` dan `Content Security Policy` dengan sandbox.
