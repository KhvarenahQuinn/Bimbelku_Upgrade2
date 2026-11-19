# Keamanan

Panduan ini mengumpulkan konfigurasi dan praktik perlindungan untuk aplikasi BimbelKu. Terapkan pembaruan keamanan melalui proses perubahan yang teruji; jangan menaikkan versi framework atau dependency tanpa perubahan yang direncanakan.

## Area penting

- Pendaftaran membatasi domain email sesuai daftar provider konsumen dan institusi pendidikan.
- Autentikasi dan akses dokumen memeriksa identitas serta izin pengguna.
- Upload memeriksa MIME berdasarkan isi file, menolak konten aktif, dan memberi nama acak sebelum penyimpanan.
- File privat tidak disajikan melalui disk publik. Respons file memuat `nosniff` dan `Content Security Policy` sandbox.
- Pembayaran memvalidasi `webhook signature`, menerapkan `idempotency key`, dan mencatat kejadian pada kanal log khusus.

Baca [panduan keamanan deployment](DEPLOYMENT_SECURITY.md) dan [pengaturan Cloudflare](../CLOUDFLARE_SETUP.md) untuk mengonfigurasi layanan.
