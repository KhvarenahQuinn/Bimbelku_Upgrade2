# Menyiapkan Cloudflare untuk BimbelKu

Panduan ini mencakup DNS, SSL/TLS, WAF, cache, Turnstile, dan pemeriksaan setelah deployment. Perubahan DNS dan aturan keamanan perlu diterapkan pada zona yang benar.

## DNS dan TLS

Tambahkan domain ke Cloudflare, perbarui nameserver pada registrar, lalu arahkan record web ke origin aplikasi. Aktifkan proxy Cloudflare untuk lalu lintas web. Gunakan mode SSL/TLS `Full (strict)` setelah sertifikat valid dipasang pada origin. Hindari mode `Flexible`, karena koneksi dari Cloudflare ke server akan tidak terenkripsi.

Batasi akses origin agar koneksi web masuk berasal dari jaringan Cloudflare. Simpan konfigurasi origin dan kredensial pada sistem deployment yang terlindungi, bukan di repository.

## WAF dan rate limiting

Aktifkan managed WAF rules dan tinjau hasilnya sebelum menambah pengecualian. Buat rate limiting untuk endpoint login dan pendaftaran dengan batas yang sesuai pola trafik aplikasi. Gunakan aturan yang tidak memblokir callback Xendit yang sah; validasi callback tetap dilakukan backend menggunakan `webhook signature`.

## Cache

Cache aset statis ber-hash dengan masa berlaku panjang. Jangan cache respons API yang berisi data pengguna, sesi, pembayaran, atau hasil personalisasi. Setelah deployment, lakukan purge bila konten lama masih tersaji.

## Turnstile

Buat widget Turnstile untuk hostname produksi dan masukkan site key ke `VITE_TURNSTILE_SITE_KEY` pada proses build frontend. Simpan secret key pada konfigurasi backend `TURNSTILE_SECRET_KEY`. Jangan menyimpan secret key di frontend atau source control.

Backend memverifikasi token pada layanan Cloudflare dan gagal secara tertutup jika verifikasi aktif tetapi layanan tidak dapat dihubungi. Uji token sukses, token tidak valid, token kedaluwarsa, dan kondisi jaringan.

## Pemeriksaan deployment

1. Pastikan DNS mengarah ke origin yang benar dan sertifikat TLS valid.
2. Periksa header keamanan dan perilaku cache pada halaman publik serta API.
3. Uji login dan pendaftaran dari browser produksi.
4. Pastikan callback pembayaran lolos validasi `webhook signature`.
5. Periksa Cloudflare Security Events dan Turnstile Analytics untuk false positive.

Untuk panduan backend, lihat [dokumentasi API](api/README.md) dan [keamanan](security/README.md).
