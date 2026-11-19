# Panduan testing perjalanan belajar BimbelKu

Baca [hasil persiapan tahap 1](testing-stage1.md) dan [matriks skenario](testing-matrix.md) sebelum menjalankan fixture demo. Tes otomatis dan uji browser berjalan pada environment berbeda. Pencairan nyata memerlukan verifikasi rekening yang sesuai.

Panduan ini untuk menguji **dari pemesanan sampai kelas selesai** di komputer lokal. Gunakan Xendit **development/test mode**, bukan uang sungguhan. Uji satu skenario sampai selesai sebelum membuat skenario berikutnya supaya kode tagihan dan akun tidak tertukar.

## 0. Persiapan sekali di awal

1. Pastikan Laragon/MySQL aktif dan backend sudah dimigrasikan. Bangun frontend lalu jalankan preview dari akar proyek `C:\laragon\www\Website_Bimbelku Upgrade`:

   ```powershell
   npm run build
   npm run preview -- --port 8080
   ```

   Buka `http://127.0.0.1:8080`. Jika terminal mengatakan port 8000/8080 sudah aktif, server tersebut dipakai ulang; tidak perlu menjalankan server kedua. Perubahan frontend baru terlihat setelah `npm run build` dan halaman di-refresh.

2. Di `bimbelku-backend/.env`, pastikan `APP_ENV=local`, `XENDIT_ENABLED=true`, dan `XENDIT_SECRET_KEY` diawali `xnd_development_`. Jangan menaruh atau mengirim kunci rahasia di screenshot. Jika mengganti `.env`, jalankan `php artisan config:clear` dari folder backend.
3. Buka terminal kedua di folder backend:

   ```powershell
   cd 'C:\laragon\www\Website_Bimbelku Upgrade\bimbelku-backend'
   ```

   Semua perintah `php artisan` di bawah dijalankan dari folder ini. Jangan jalankan perintah demo pada database produksi. Fixture seperti `demo:cheap-class pre-payment` dapat mengganti data demo sebelumnya; gunakan database lokal khusus testing dan cadangkan data penting.
4. Siapkan tiga jendela/profil browser terpisah: **murid** (`/login`), **tutor** (`/login`), dan **admin** (`/admin/login`). Jangan berganti akun pada tab yang sama saat sebuah sesi sedang diuji.
5. Untuk paket privat, tutor uji harus sudah aktif, diverifikasi admin, menerima permintaan, mengajar mapel/jenjang yang dipilih, dan memiliki jam tersedia yang cocok. Untuk paket beberapa mapel, siapkan tutor **berbeda** untuk tiap mapel. Jika tidak, pengujian berhenti di matching dan perintah skip waktu sesi belum dapat digunakan.
6. Setiap kali pembayaran sandbox selesai, kembali ke halaman pembayaran dan tekan **Periksa status tagihan** bila status belum berubah. Pastikan status tagihan di **Riwayat Transaksi** menjadi `paid`/lunas sebelum lanjut. Di localhost, webhook Xendit tidak bisa langsung masuk dari internet; tombol pemeriksaan status melakukan sinkronisasi server-ke-server.

### Rumus skip waktu yang dipakai berulang

Ambil **kode tagihan persis** dari Riwayat Transaksi murid, bukan ID paket, kode program, atau `Session ID` Xendit. Contoh di bawah memakai `KODE_TAGIHAN`; **ganti tulisan itu dengan kode milik pesananmu**. Tagihan harus sudah lunas lewat Xendit sandbox dan terhubung dengan paket/Kelas Bersama.

```powershell
php artisan demo:journey-time KODE_TAGIHAN status
php artisan demo:journey-time KODE_TAGIHAN start
# Setelah tutor memulai dan murid mengonfirmasi hadir lewat web:
php artisan demo:journey-time KODE_TAGIHAN end
```

`status` menampilkan ID semua sesi. `start` memajukan sesi berikutnya ke waktu sekarang; `end` mengakhiri jendela waktunya, **bukan** otomatis menyelesaikan kelas. Untuk memilih sesi tertentu, tambahkan `--session=ID_SESI` pada `start` dan `end` berdasarkan tabel `status`. Ulangi siklus ini untuk **setiap** sesi. Jangan menjalankan `end` sebelum aksi kehadiran yang diwajibkan web. Perintah akan menolak tagihan pending, tagihan tes koneksi tanpa kelas, atau sesi yang belum memperoleh tutor.

## 1. Les privat sendiri: dari nol sampai selesai

1. Masuk sebagai murid uji, atau buat pesanan sebagai tamu dengan email uji lalu daftar/login memakai **email yang sama**. Buka **Cari Les**, pilih **Mata pelajaran**, tentukan jenjang, mapel, mode online atau tatap muka, paket/jumlah sesi, hari, jam, dan identitas. Untuk materi, pilih **Pilih bab tersedia** (minimal satu bab sesuai kelas, catatan tambahan opsional) atau **Tulis materi sendiri** dan isi **Target atau materi yang ingin difokuskan**, misalnya “Masih kesulitan pecahan dan soal cerita; ingin mengulang dari dasar”. Admin dapat mengaktifkan/menonaktifkan **Tampilkan pilihan bab kepada murid** saat tambah/ubah mapel. Jika dinonaktifkan, hanya input teks yang tampil; jika belum tersedia bab yang cocok, gunakan teks. Program seperti TKA tetap mengikuti materi program dan catatan tambahan opsional. Untuk tes paling sederhana pilih **online** dan satu mapel yang tutor ujinya benar-benar kuasai. Pilih paket dengan jumlah sesi paling kecil yang tersedia; tidak harus satu sesi.
2. Di ringkasan, catat nominal, jumlah sesi, durasi, dan kode pesanan. Lanjutkan ke tagihan dan bayar melalui Xendit **test mode**. Jangan pakai perintah `demo:gateway-connectivity` untuk langkah ini; perintah itu hanya membuat tagihan koneksi tanpa kelas.
3. Kembali ke web, tekan **Periksa status tagihan** bila perlu. Buka **Riwayat Transaksi**: satu tagihan harus lunas. Di **Pesanan/Paket Privat**, status berubah menjadi pencarian tutor.
4. Di jendela tutor, buka **Permintaan Kelas** (`/guru/permintaan`) dan terima penawaran mapel tersebut. Bila tidak ada penawaran, periksa verifikasi, mapel, jadwal tersedia, mode, dan jangkauan tatap muka tutor. Setelah tutor menerima, paket menjadi aktif dan jadwal muncul di **Kelas Saya** (`/student/my-classes`) serta **Kelas Saya** tutor (`/guru/kelas`). Untuk kelas online, tutor isi dan simpan tautan Google Meet/Zoom pada detail kelas.
5. Salin kode tagihan dari Riwayat Transaksi, lalu di terminal backend jalankan `php artisan demo:journey-time KODE_TAGIHAN status`. Jalankan `... start`. Refresh browser murid dan tutor.
6. Tutor buka kelas/ruang belajar dan tekan **Saya Siap Mengajar**. Murid buka kelas yang sama dan tekan **Saya Sudah Hadir**. Sekarang jalankan `php artisan demo:journey-time KODE_TAGIHAN end`.
7. Tutor kembali ke kelas, tekan **Selesaikan pembelajaran & isi hasil**, isi materi yang dibahas, hasil belajar, dan latihan berikutnya, lalu kirim. Pada pesanan baru tanpa pilihan Bab, penilaian materi diisi tutor langsung; pada pesanan lama yang sudah memakai Bab, progress Bab tetap digunakan. Persentase **Pertemuan selesai** tidak berarti persentase penguasaan materi. Murid buka **Kelas Saya**, periksa laporan dan tekan **Sesi Sesuai** jika datanya benar. Jangan memilih **Ada masalah** untuk jalur sukses normal.
8. Jalankan `... status`, lalu ulangi langkah 5–7 untuk sesi selanjutnya. Setelah sesi terakhir disetujui, paket harus menjadi **Selesai** dan saldo sesi yang sah tampak di **Dompet tutor** (`/guru/dompet`). Jangan menganggap paket selesai hanya karena perintah `end` berhasil.

**Cek hasil:** satu pembayaran lunas, satu tutor untuk satu mapel, semua sesi berstatus selesai, laporan bisa dibaca murid, saldo tutor bertambah setelah sesi disahkan, dan tidak ada tagihan kedua. Untuk uji anti-duplikasi, tutup checkout sebelum bayar lalu buka tagihan lagi: sistem harus memakai sesi pembayaran aktif yang sama.

## 2. Les privat bersama teman (dua atau lebih peserta)

1. Di admin, isi tabel harga/diskon untuk jumlah peserta yang ingin diuji. Tanpa angka harga yang diaktifkan admin, pemesanan multi-peserta memang tidak boleh dilanjutkan.
2. Ulangi langkah pemesanan nomor 1, tetapi pilih **Privat bersama teman** dan jumlah peserta yang sudah diberi harga. Masukkan identitas setiap peserta; jangan membuat satu pesanan terpisah per teman.
3. Sebelum membayar, cocokkan nominal ringkasan dengan aturan harga peserta dan diskon. Bayar **satu tagihan** Xendit sandbox. Sesudah sinkronisasi, periksa semua nama peserta masih tersimpan dan tutor mendapat satu penawaran untuk mapel itu.
4. Tutor menerima, lalu gunakan **kode tagihan yang sama** untuk pola `status → start → kehadiran lewat web → end → laporan tutor → persetujuan murid` seperti skenario 1. Ulangi sampai seluruh sesi habis.

**Cek hasil:** jumlah peserta, diskon, total bayar, jadwal, dan satu tutor konsisten sampai paket selesai. Saldo tutor berasal dari sesi yang sah, bukan dari jumlah tombol pembayaran yang diklik.

## 3. Program TKA / satu pesanan berisi beberapa mapel

1. Admin harus sudah membuat program TKA, menghubungkannya ke mapel yang benar, dan menyediakan paket yang mengizinkan jumlah mapel itu. Siapkan **satu tutor berbeda per mapel** dengan kompetensi/jadwal valid; misalnya tiga mapel berarti tiga tutor.
2. Di **Cari Les**, pilih jalur **Program/Persiapan Tes**, pilih TKA, lalu pilih mapel yang ingin dipesan dan alokasi sesi sesuai form. Untuk program, Bab tidak wajib; isi target belajar jika diperlukan. Periksa bahwa ringkasan menampilkan **satu paket, beberapa mapel, dan satu total tagihan**.
3. Bayar satu tagihan sandbox dan sinkronkan. Sistem mencari tutor per mapel. Tutor A menerima mapel A, tutor B mapel B, dan tutor C mapel C. **Paket baru aktif setelah semuanya menerima**. Di Pesanan, cek status setiap mapel; jangan menjalankan `start` sebelum semua tutor lengkap.
4. Jalankan `php artisan demo:journey-time KODE_TAGIHAN status`. Karena satu tagihan berisi banyak sesi dan tutor, gunakan ID sesi yang tercetak agar tidak tertukar:

   ```powershell
   php artisan demo:journey-time KODE_TAGIHAN start --session=ID_SESI
   # Tutor mapel itu siap; murid hadir.
   php artisan demo:journey-time KODE_TAGIHAN end --session=ID_SESI
   ```

5. Tutor untuk sesi tersebut kirim hasil belajar; murid tekan **Sesi Sesuai**. Ulangi untuk seluruh ID sesi dalam tabel `status`. Setelah sesi terakhir, cek paket selesai dan masing-masing tutor mendapat saldo untuk sesi yang dia ajar.

**Jika hanya dua dari tiga tutor menerima:** halaman Pesanan menunjukkan jumlah yang siap dan mapel yang belum terisi. Kelas **belum aktif**. Setelah pencarian mapel ketiga gagal, murid dapat **Cari lagi** selama batas pencarian masih mengizinkan, **Ubah jadwal** mapel yang belum mendapat tutor, atau **Batalkan seluruh paket**. Ubah jadwal/cari lagi tidak mengulang dua mapel yang sudah memperoleh tutor. Pembatalan menghentikan seluruh paket; cek proses pengembalian dana di Riwayat Transaksi. `demo:journey-time` **tidak** bisa memaksa status “tutor tidak ditemukan”.

## 4. Kelas Bersama: dari gabung sampai laporan disahkan

1. Di terminal backend lokal, jalankan:

   ```powershell
   php artisan demo:cheap-class pre-payment
   ```

   Fixture menampilkan akun murid `demo.student@bimbelku.local` / `password` dan tutor `demo.tutor@bimbelku.local` / `password`. Ia menyiapkan satu kelas demo yang pendaftarannya sedang terbuka, tutor sudah ditentukan, serta seorang peserta **fixture** sudah dianggap lunas. Hanya pembayaran murid utama yang kamu uji melalui gateway. Pendaftaran demo terbuka sekitar 55 menit; buat ulang fixture jika lewat waktu. Perintah ini dapat mengganti kelas demo lama.
2. Login sebagai murid demo, buka **Kelas Bersama** (`/student/kelas-murah`), temukan kelas demo, tekan **Gabung Kelas Bersama**, lalu **Bayar paket ini**. Bayar melalui Xendit test mode dan tekan **Periksa status tagihan** jika diperlukan.
3. Periksa kursi murid menjadi terkonfirmasi dan kelas berstatus `confirmed`. Catat kode tagihan murid utama dari Riwayat Transaksi. Di terminal jalankan `php artisan demo:journey-time KODE_TAGIHAN status`, lalu `... start`.
4. Tutor demo login, buka **Kelas Bersama** miliknya dan tekan **Mulai Kelas**. Setelah itu jalankan `php artisan demo:journey-time KODE_TAGIHAN end`.
5. Tutor isi jumlah murid hadir, progress Bab, dan catatan, lalu **Kirim Laporan Sesi**. Admin buka pengelolaan/jadwal Kelas Bersama dan verifikasi laporan. Murid refresh halaman untuk melihat progress dan status selesai.

**Cek hasil:** satu pembayaran sandbox murid utama, kursi terkonfirmasi, kelas mulai dan berakhir, laporan tutor diverifikasi admin, status kelas/sesi selesai. Jangan gunakan shortcut lama `demo:cheap-class payment-paid` untuk mengklaim gateway lulus; shortcut itu melewati pembayaran Xendit.

## 5. Perpanjangan paket dengan tutor lama

1. Agar tidak perlu menuntaskan paket lama dulu, jalankan di backend lokal:

   ```powershell
   php artisan demo:package-renewal setup
   ```

   Perintah menyiapkan paket **lama** yang sudah selesai serta akun `demo.student@bimbelku.local` dan `demo.tutor@bimbelku.local` (keduanya sandi `password`). Paket lama adalah fixture, **bukan** bukti pembayaran gateway. Jangan jalankan bersamaan dengan skenario Kelas Bersama pada akun demo yang sama jika ingin hasil mudah dibaca.
2. Login murid demo, buka riwayat Paket Privat, klik **Perpanjang dengan Tutor Ini**. Pilih materi berikutnya bila diminta, susun jadwal yang lolos validasi, lalu buat paket **baru**.
3. Bayar **tagihan paket baru** via Xendit sandbox; jangan jalankan `demo:package-renewal payment-paid` karena itu melompati gateway. Sinkronkan status. Pastikan paket baru menunjuk paket lama dan tutor lama mendapat penawaran baru.
4. Tutor lama menerima. Catat **kode tagihan baru**, jalankan `status/start/end` lewat `demo:journey-time`, dan lakukan aksi hadir, laporan, serta persetujuan murid untuk setiap sesi seperti skenario 1.

**Cek hasil:** ada paket/tagihan baru yang terhubung ke paket lama; order lama tidak dibayar ulang; sesi paket baru selesai dan saldo tutor hanya bertambah untuk sesi yang sah.

## 6. Ganti tutor di tengah paket

1. Pakai paket privat skenario 1 yang **benar-benar sudah dibayar di sandbox**, memiliki setidaknya dua sesi, dan tutor pertama sudah menerima. Selesaikan satu sesi penuh dengan `status/start/end`, laporan tutor, dan persetujuan murid.
2. Sebelum sesi-sesi berikutnya berlangsung, murid buka paket/Kelas Saya dan pilih **Ajukan Ganti Guru** pada mapel terkait. Isi alasan; selesaikan pemeriksaan admin jika status memerlukannya.
3. Pastikan tutor pertama tidak lagi ditugaskan pada sesi sisa dan penawaran pengganti muncul pada tutor lain yang kompeten serta tersedia. Tutor baru menerima.
4. Tetap gunakan **kode tagihan awal** pada `demo:journey-time` untuk sesi-sesi tersisa; tidak ada pembayaran kedua. Jalankan `status/start/end` per ID sesi, lalu tutor pengganti kirim laporan dan murid menyetujui sampai paket selesai.

**Cek hasil:** sesi lama tetap milik tutor pertama, sesi sisa milik tutor pengganti, progress tidak hilang, dan jumlah tagihan tidak bertambah. Fixture `demo:teacher-replacement setup` boleh dipakai untuk uji UI cepat, tetapi ia membuat pembayaran lokal berstatus lunas tanpa checkout sandbox; jangan pakai sebagai bukti gateway sukses.

## 7. Cabang tutor tidak ditemukan, batal, dan refund

1. Buat pesanan sandbox baru dengan mapel/jadwal yang **tidak mempunyai tutor uji yang cocok**, atau gunakan program TKA dengan satu mapel tanpa tutor. Jangan merusak ketersediaan tutor pada pesanan lain yang sedang diuji. Bayar dan sinkronkan tagihan.
2. Pantau status per mapel di **Pesanan**. Bila sebagian tutor sudah menerima, ringkasan harus menyebut berapa yang siap dan mapel mana yang belum. Jika **Cari lagi** tersedia, coba sekali; atau pilih **Ubah jadwal** hanya untuk mapel yang belum terisi. Pastikan tutor mapel lain tidak ditugaskan ulang.
3. Jika tidak ingin melanjutkan, pilih **Batalkan seluruh paket** dan baca konfirmasi. Semua penawaran, termasuk yang sebelumnya diterima, harus berhenti. Buka **Riwayat Transaksi → Refund**. Murid pilih **Saldo BimbelKu** atau **Metode pembayaran asal** sesuai yang tersedia, lalu tekan **Konfirmasi tujuan**.
4. Refresh dan periksa status refund. Jangan menganggap refund berhasil hanya karena tombol sudah ditekan: status akhir harus benar-benar sukses. Jika gateway menolak/mengalami kendala, catat kode kesalahan dan periksa rekonsiliasi/penanganan admin.

**Penting:** `demo:journey-time` hanya memajukan **waktu sesi**, bukan tenggat matching atau refund. Skenario gagal matching mungkin perlu menunggu proses pencarian normal; panduan ini tidak menyediakan perintah yang berpura-pura menemukan/menolak tutor.

## 8. Saldo dan pencairan tutor setelah sesi selesai

1. Setelah sesi privat pada skenario 1, 2, 3, 5, atau 6 disetujui murid, tutor buka **Dompet tutor** (`/guru/dompet`) dan refresh. Cek saldo tersedia; bila ada beberapa tutor TKA, buka **masing-masing akun tutor**.
2. Lengkapi rekening pencairan tutor, lalu isi nominal minimal **Rp10.000** dan tidak melebihi saldo tersedia. Periksa ringkasan potongan pajak jika ada, lalu ajukan pencairan. Tidak diperlukan persetujuan admin untuk permintaan normal.
3. Periksa **Proses pencairan** dan **Transfer berhasil**. Klik tombol saja belum membuktikan uang terkirim; cocokkan status dengan respons gateway sandbox. Saldo sesi yang belum disahkan murid tidak boleh dapat ditarik.

## Jika tersangkut, periksa di titik yang tepat

| Gejala | Pemeriksaan pertama |
| --- | --- |
| `demo:journey-time` mengatakan tagihan belum lunas | Periksa kode tagihan, status di Riwayat Transaksi, dan tombol **Periksa status tagihan**. Jangan gunakan ID `ps-...`. |
| Sesi belum punya booking lunas | Tutor untuk **semua mapel dalam paket** harus menerima lebih dulu. Cek Pesanan dan Permintaan Kelas tutor. |
| Tutor tidak mendapat penawaran | Periksa status aktif/verifikasi, mapel, jenjang, jam tersedia, mode online/offline, serta lokasi untuk tatap muka. |
| `end` ditolak | Tutor harus tekan **Saya Siap Mengajar** dan murid **Saya Sudah Hadir** sesudah `start`. Untuk Kelas Bersama, tutor harus tekan **Mulai Kelas**. |
| `start`/`end` memilih sesi yang salah | Jalankan `status`, lalu gunakan `--session=ID_SESI`. |
| Sudah `end`, paket belum selesai | Tutor masih perlu mengirim laporan; murid menyetujui sesi privat atau admin memverifikasi laporan Kelas Bersama. Ulangi untuk seluruh sesi. |
| Refund atau pencairan masih diproses | Periksa status gateway dan event/rekonsiliasi; jangan membuat permintaan baru dengan nominal sama hanya karena layar belum berubah. |

Untuk tes otomatis yang tidak mengubah database lokal, jalankan `npm run demo:payment-journey` dari root proyek. Tes ini memakai respons gateway tiruan dan tidak menggantikan uji browser dengan Xendit sandbox. Langkah checkout tersedia di [panduan demo pembayaran](DEMO-PEMBAYARAN-SANDBOX.md).
