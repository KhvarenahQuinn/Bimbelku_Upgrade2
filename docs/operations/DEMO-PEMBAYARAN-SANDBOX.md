# Demo pengujian pembayaran BimbelKu

Dokumen ini memisahkan **tes otomatis tanpa uang**, **uji koneksi Xendit test mode sungguhan**, dan **uji manual dari browser**. Lulusnya satu bagian tidak berarti bagian lain otomatis lulus.

## 1. Tes otomatis seluruh perjalanan

Dari akar proyek:

```powershell
npm run demo:payment-journey
```

Tes memakai database pengujian terisolasi dan respons gateway tiruan. Database MySQL lokal serta akun pengguna tidak diubah. Yang diuji melalui endpoint aplikasi:

| Skenario | Bukti yang harus lulus |
| --- | --- |
| Privat sendiri | Pesanan tamu diklaim oleh email yang sama, checkout dibayar, tutor menerima penawaran, sesi berlangsung, laporan Bab dibuat, murid menyetujui, paket selesai, dan saldo tutor tersedia. |
| Privat bersama teman | Harga dan identitas dua peserta tersimpan; sesudah dibayar, sesi dituntaskan melalui alur yang sama hingga paket selesai. |
| Kelas Bersama | Kursi ditahan lalu dibayar; tutor memulai kelas, mengirim laporan setelah sesi, admin memverifikasi, dan murid melihat kelas selesai. |
| Perpanjangan | Paket baru ditagih dan dibayar, tutor lama menerima penawaran, keempat sesi dituntaskan satu per satu, dan paket perpanjangan selesai. |
| Ganti tutor | Pembayaran awal tetap dipakai; tutor pengganti menerima penawaran lalu menuntaskan dua sesi tersisa tanpa order atau tagihan kedua. |
| Pengganti tidak ditemukan | Refund sesi tersisa menunggu pilihan murid. Murid memilih dan mengonfirmasi metode pembayaran asal; refund dikirim ke gateway dan dicatat sekali. |

Webhook pembayaran yang sama dikirim dua kali pada skenario checkout untuk memastikan tidak terjadi kredit/paket ganda. Waktu sesi dimajukan hanya di database tes agar tidak perlu menunggu hari jadwal; aksi siap mengajar, presensi, laporan, persetujuan, dan verifikasi tetap memakai endpoint aplikasi. Ini membuktikan alur aplikasi, **bukan** pembayaran bank nyata atau pengiriman webhook sungguhan dari Xendit. Tes tenggat kursi dan pensiunnya pembayaran manual dijalankan terpisah dengan:

```powershell
cd bimbelku-backend
php artisan test --filter=CheapClassPaymentLifecycleTest
```

## 2. Uji koneksi Xendit test mode sungguhan

Pastikan `.env` backend memakai `APP_ENV=local`, `XENDIT_ENABLED=true`, kunci **development** (`xnd_development_…`), dan token webhook. Perintah akan menolak environment atau kunci live.

```powershell
cd bimbelku-backend
php artisan demo:gateway-connectivity start
php artisan demo:gateway-connectivity status ID_TAGIHAN_DARI_START
```

`start` membuat satu tagihan demo Rp15.000 dan meminta Payment Session langsung ke API Xendit test mode. `status` membaca status session langsung dari Xendit; ganti `ID_TAGIHAN_DARI_START` dengan angka yang dicetak perintah `start`, misalnya `9`. Ini **hanya tes koneksi**: tagihan tersebut tidak punya paket/kelas dan tidak akan berubah menjadi kelas setelah dibayar. Untuk memvalidasi kelas sampai selesai jalankan bagian 1, lalu lakukan uji browser bagian 3 bila perlu. Jangan menyimpulkan bahwa webhook, matching, atau refund sudah lulus hanya karena URL checkout muncul.

## 3. Uji manual lewat web dengan checkout sandbox

Jalankan backend dan preview melalui `npm run preview -- --port 8080`. Gunakan alamat `http://127.0.0.1:8080`. Jangan gunakan kunci live atau uang nyata.

1. **Privat sendiri:** buat paket satu peserta dari katalog/form pemesanan, isi email sendiri, masuk dengan email itu, buka tagihannya dan lanjutkan checkout Xendit test mode. Setelah simulasi bayar, kembali ke halaman pembayaran dan tekan **Periksa status**. Periksa order `paid`, paket tidak lagi `awaiting_payment`, dan matching tutor dimulai.
2. **Privat bersama teman:** ulangi dengan dua peserta dan isi identitas masing-masing. Pastikan tier diskon 2–maksimum sudah lengkap di admin. Bandingkan nominal ringkasan dengan nominal checkout. Sesudah bayar, dua identitas peserta harus tetap ada dan hanya satu tagihan yang lunas.
3. **Kelas Bersama:** siapkan penawaran lokal dengan `php artisan demo:cheap-class pre-payment` di folder backend. Masuk sebagai `demo.student@bimbelku.local` / `password`, buka Kelas Bersama demo, tekan gabung, bayar via checkout sandbox, lalu **Periksa status**. Kursi dan kelas harus berubah menjadi `confirmed`. Fixture ini hanya terbuka sekitar 55 menit sejak dibuat; ulangi `pre-payment` jika kedaluwarsa.
4. **Perpanjangan:** siapkan `php artisan demo:package-renewal setup`. Masuk sebagai `demo.student@bimbelku.local` / `password`, pilih paket demo yang sudah selesai, buat perpanjangan, bayar lewat checkout sandbox, lalu periksa status. Paket perpanjangan harus terhubung ke paket asal dan tutor lama mendapat penawaran. Perintah demo lama `payment-paid` **tidak** dipakai untuk uji gateway karena langsung mengubah status lokal.
5. **Ganti tutor:** setelah paket privat sandbox benar-benar lunas dan sudah memiliki sesi yang selesai serta sesi mendatang, ajukan ganti tutor. Persetujuan admin dan penerimaan tutor baru tidak boleh membuat session Xendit atau order kedua. Fixture `php artisan demo:teacher-replacement setup` dapat dipakai untuk mencoba UI penggantian lebih cepat, tetapi fixture itu menyiapkan order berstatus `paid` tanpa pembayaran sandbox; jadi tidak membuktikan pembayaran awal ke Xendit.

Untuk setiap checkout: tutup halaman checkout sekali lalu buka kembali tagihan. Session aktif harus dipakai ulang, bukan membuat tagihan baru. Uji juga pembayaran gagal/kedaluwarsa; paket dan kursi tidak boleh langsung dianggap lunas.

### Lanjutkan setelah pembayaran, jangan berhenti di halaman tagihan

- **Privat sendiri / bersama teman:** setelah tagihan `paid`, pastikan ada penawaran tutor dan tutor menerimanya. Sebelum sesi online dimulai, isi tautan meeting. Tutor menyatakan siap, murid mengonfirmasi kehadiran, lalu tutor check-out dan mengisi laporan Bab. Murid menyetujui laporan. Periksa sesi `completed`, paket `completed` setelah sesi terakhir, dan saldo tersedia di akun tutor.
- **Kelas Bersama:** setelah kursi `confirmed`, tutor memulai sesi sesuai jadwal. Sesudah sesi berakhir, tutor mengisi laporan dan admin memverifikasinya. Periksa status sesi serta kelas `completed` dan progress dapat dibaca murid. Untuk mempercepat waktu fixture lokal, perintah `php artisan demo:cheap-class session-ended` hanya dipakai setelah tutor benar-benar memulai sesi.
- **Perpanjangan:** pembayaran harus menghasilkan paket baru, bukan mengubah ulang tagihan lama. Tutor menerima penawaran paket baru; ulangi alur siap–hadir–laporan–persetujuan untuk setiap sesi sampai `used_sessions` mencapai jumlah sesi dan paket `completed`.
- **Ganti tutor:** setelah satu sesi selesai, ajukan pergantian untuk sesi tersisa. Tutor baru menerima penawaran; selesaikan semua sesi tersisa dengan tutor baru. Periksa paket `completed` dan jumlah order tetap. Jika tutor pengganti tidak ditemukan, uji cabang refund sesi tersisa dan pastikan tidak ada sesi fiktif yang dianggap selesai.
- **Pilihan refund:** buka **Riwayat Transaksi** sebagai murid. Refund yang memenuhi syarat harus menunggu pilihan, bukan langsung dikirim ke gateway. Pilih **Saldo BimbelKu** atau **Metode pembayaran asal**, baca ringkasan, lalu tekan **Konfirmasi tujuan**. Untuk pembayaran campuran, bagian yang semula dibayar dari saldo tetap kembali ke saldo. Periksa status akhir di Riwayat Transaksi; jangan konfirmasi pilihan kedua setelah refund mulai diproses.

## 4. Lewati waktu sesi dari tagihan sandbox yang sudah lunas

Perintah berikut bekerja pada database lokal yang dipakai browser, **bukan** database tes terisolasi. Hanya untuk `APP_ENV=local` dan kunci Xendit development. Perintah menolak tagihan yang belum `paid` atau bukan pembayaran Xendit. Ambil **kode tagihan** dari Riwayat Transaksi murid; `KODE_TAGIHAN_ANDA` di bawah harus diganti dengan kode tersebut. Bisa juga memakai ID angka tagihan.

Dari folder `bimbelku-backend`:

```powershell
php artisan demo:journey-time KODE_TAGIHAN_ANDA status
php artisan demo:journey-time KODE_TAGIHAN_ANDA start
```

`status` menampilkan semua sesi dan ID-nya. `start` hanya memindahkan **sesi berikutnya yang belum selesai** ke waktu sekarang. Sesudah itu refresh browser, lalu:

- **Privat sendiri, privat bersama teman, dan perpanjangan:** tutor menerima penawaran terlebih dahulu. Untuk kelas online, tutor isi tautan meeting. Setelah `start`, tutor tekan **Siap Mengajar**, kemudian murid tekan **Saya Sudah Hadir**. Jalankan `end` di terminal. Tutor melakukan check-out dan mengisi laporan belajar; murid menyetujui. Ulangi `status`, `start`, dan `end` untuk setiap sesi berikutnya hingga paket berpindah ke Riwayat.
- **Kelas Bersama:** sesudah kursi confirmed dan tutor tersedia, jalankan `start`. Tutor tekan **Mulai Kelas**. Jalankan `end`, kemudian tutor kirim laporan dan admin memverifikasinya. Untuk kelas dengan beberapa sesi, ulangi per sesi hingga kelas selesai.
- **Ganti tutor:** ajukan pergantian setelah ada sesi privat yang selesai. Setelah tutor pengganti menerima penawaran, gunakan **kode tagihan awal yang sama** untuk sesi tersisa. Tidak ada tagihan kedua. Bila pengganti tidak ditemukan, cabang ini menuju pilihan refund murid, bukan skip waktu sesi.

Perintah untuk menutup waktu sesi:

```powershell
php artisan demo:journey-time KODE_TAGIHAN_ANDA end
```

Jika ada beberapa sesi belum selesai dan perlu memilih tertentu, tambahkan `--session=ID_SESI` dari tabel `status` pada perintah `start` atau `end`. Secara default sistem memilih sesi berikutnya yang belum selesai. `end` akan ditolak sebelum tutor/murid benar-benar melakukan aksi hadir melalui web. Perintah ini **tidak** membuat pembayaran, mengubah status order, menyetujui laporan, mengkredit saldo tutor, atau menyelesaikan paket secara langsung; semua itu harus terjadi melalui alur aplikasi.

`demo:gateway-connectivity` hanya menghasilkan tagihan tes koneksi tanpa paket/kelas sehingga tidak bisa dipakai dengan `demo:journey-time`. Buat pesanan dari web untuk uji browser penuh.

## Batas pengujian di localhost

Xendit tidak dapat mengirim webhook ke `127.0.0.1` dari internet. Di localhost, tombol **Periksa status** memakai pengecekan server-ke-server untuk menyelaraskan pembayaran. Agar pengiriman webhook sungguhan teruji, sediakan URL HTTPS publik yang meneruskan POST ke `/api/webhooks/xendit` pada backend lokal/deployment, lalu atur URL dan callback token di Dashboard Xendit test mode. Jangan membuka backend lokal ke internet tanpa autentikasi dan pembatasan akses yang sesuai.

Checkout test mode dan webhook Payment Session dijelaskan di [dokumentasi resmi Xendit](https://docs.xendit.co/docs/payment-sessions-overview). Tidak ada bukti pelunasan sungguhan sampai status gateway berhasil dibaca atau webhook tervalidasi dan status lokal berubah.
