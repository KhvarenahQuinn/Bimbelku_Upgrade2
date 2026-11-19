# Operasional

Gunakan panduan ini untuk menjalankan pemeriksaan, demo, deployment, dan pemeliharaan aplikasi. Jalankan perintah frontend dari root repository dan perintah Laravel dari `bimbelku-backend/`. Pilih panduan [pindah dan hosting](panduan-pindah-dan-hosting.md), [hosting teknisi](panduan-hosting-untuk-teknisi.md), atau [skenario testing](testing-matrix.md) sesuai kebutuhan.

## Pemeriksaan kode

```sh
npm run typecheck
npm run lint
npm run build
cd bimbelku-backend
./vendor/bin/pint --test
php artisan test
```

Laravel menyimpan log tiap kanal di subdirektori terpisah dengan `daily log rotation`. Masa simpan bergantung pada kanal. Periksa `storage/logs/` untuk memastikan file log tersedia.

Sebelum deployment, atur `environment variables`, pastikan storage dapat ditulis, lalu jalankan pemeriksaan pada environment tujuan. Jangan salin secret ke catatan atau log.
