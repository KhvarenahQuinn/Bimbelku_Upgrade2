# Rencana revisi BimbelKu

Dokumen ini merangkum kebutuhan produk dan kriteria penerimaan untuk perubahan navigasi, refund, autentikasi, halaman publik, dan jalur kontak. Status implementasi perlu diverifikasi terhadap aplikasi sebelum rencana ditandai selesai.

## Navigasi dan badge

Badge merah menandai perubahan baru yang belum dilihat. Badge hilang setelah pengguna membuka area terkait. Status proses yang masih berjalan tetap terlihat pada halaman tanpa membuat menu terus menyala.

Perhatian harus dikaitkan dengan pengguna, jenis perubahan, entitas, route dan tab, waktu pembuatan, waktu baca, serta validitas. Contoh perhatian: pembayaran perlu dilanjutkan, hasil matching baru, perubahan status refund, penawaran kelas tutor, perubahan jadwal, dan pencairan gagal.

Kriteria penerimaan:

- Badge muncul hanya untuk perubahan yang baru dan relevan bagi pengguna.
- Membuka target perhatian menghapus badge tanpa menghapus data proses yang masih aktif.
- Perhatian untuk murid, tutor, dan admin memiliki tujuan route yang benar.
- Tab yang dipilih tetap terbuka setelah halaman dimuat ulang bila perilaku itu memang diharapkan.

## Refund dan transaksi

Tampilkan status refund yang dapat dipahami murid, tutor, dan admin. Jelaskan apakah refund menunggu pilihan tujuan, sedang dikirim, berhasil, atau perlu pemeriksaan. Jangan menyatakan refund berhasil hanya karena permintaan diterima.

Pembayaran dan refund memerlukan pencatatan yang konsisten dan pencegahan proses ganda melalui `idempotency key`. Callback pembayaran harus lolos validasi `webhook signature`. Status sukses tidak boleh diturunkan oleh notifikasi gagal atau kedaluwarsa yang datang terlambat.

Kriteria penerimaan:

- Murid dapat melihat status dan tindakan yang tersedia pada Riwayat Transaksi.
- Refund ke saldo dan refund ke metode asal mengikuti sumber dana serta aturan yang berlaku.
- Admin mendapat perhatian untuk kasus yang memerlukan pemeriksaan manual.
- Perubahan status gateway dapat ditelusuri tanpa menulis rahasia atau data sensitif ke log.

## Login, registrasi, dan sesi

Validasi form harus konsisten antara frontend dan backend. Registrasi membatasi email ke provider konsumen yang disetujui dan domain pendidikan. Login tetap menerima akun yang sudah ada, termasuk akun internal. Sesi autentikasi harus mempertahankan perlindungan CSRF, masa berlaku, logout, dan pemeriksaan status akun.

Cakupan pengujian mencakup murid, tutor, admin, login Google, verifikasi email, reset password, dan sesi browser. Pesan error harus membantu pengguna menyelesaikan masalah tanpa membocorkan detail autentikasi.

## Halaman publik

Setiap halaman publik perlu menjawab kebutuhan pembacanya dengan struktur dan bukti yang relevan. Hindari pola layout yang sama untuk semua halaman. Gunakan informasi yang didukung kebijakan, layanan, dan data aplikasi.

Sebelum publikasi, periksa judul, isi, CTA, tautan internal, aksesibilitas, tampilan mobile, metadata, serta konsistensi dengan halaman program dan proses pemesanan. Jangan menampilkan data pribadi tanpa persetujuan.

## Mega menu dan kontak

Mega menu Wawasan tidak menampilkan FAQ atau tombol “Lihat semua”. Mega menu Tentang tidak menampilkan Kontak. Tautan Tentang BimbelKu harus menuju halaman yang tersedia.

Sediakan akses WhatsApp pada topbar dan tombol mengambang di kanan bawah halaman publik. Tombol tidak menutup konten, navigasi mobile, atau tindakan utama. Pastikan tujuan tautan memakai nomor dan pesan yang telah dikonfigurasi secara aman.

## Pengujian dan penyelesaian

Jalankan lint, typecheck, build frontend, Pint, serta tes Laravel yang mencakup alur terkait. Tes transaksi sandbox dipisahkan dari tes otomatis dengan gateway tiruan. Catat hasil dan command yang benar-benar dijalankan.

Daftar operasional tes ada di [panduan testing](../operations/testing.md). Checklist keamanan deployment ada di [dokumentasi security](../security/DEPLOYMENT_SECURITY.md). Kontrak backend tersedia di [dokumentasi API](../api/README.md).
