# Pratinjau desain halaman pembayaran

Dokumen ini menjelaskan cara membuat dan memeriksa build pratinjau pembayaran secara lokal. Build pratinjau disimpan terpisah dari `dist/` utama sehingga tidak mengganti artefak deployment.

## Menjalankan build

Dari root repository:

```sh
npm run build:payment-preview
```

Script menghasilkan output pratinjau sesuai konfigurasi proyek. Periksa terminal untuk lokasi output dan error build.

## Meninjau tampilan

Jalankan preview untuk direktori output tersebut menggunakan script yang tersedia pada `package.json`. Periksa layout pada lebar desktop dan mobile, label biaya, status checkout, pesan kesalahan, dan navigasi kembali.

Gunakan akun serta data sandbox. Jangan masukkan data pelanggan, token, atau kredensial production pada pratinjau.

## Batas penggunaan

Artefak ini hanya untuk pemeriksaan desain. Pastikan `npm run build` utama dan tes proyek dijalankan sebelum rilis. Pratinjau tidak membuktikan integrasi payment gateway maupun perilaku webhook.
