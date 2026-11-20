# Tahap 1 — Pemetaan dan persiapan pengujian BimbelKu

Run ID: **S1-20261003**. Tanggal: 3 Oktober 2026, zona Asia/Jakarta. Workspace: `C:\laragon\www\Website_Bimbelku Upgrade`.

Status: **pemetaan dan harness persiapan selesai; runtime lokal belum siap penuh untuk seluruh pengujian UI/sandbox**. Tahap 2–9 belum dijalankan pada putaran ini. Ini bukan sertifikasi bebas bug, bukan hasil Lighthouse, dan bukan bukti uang sudah terkirim ke bank.

## 1. Yang dikerjakan dan batasnya

- Memetakan 16 alur layanan, operasi admin, halaman publik/bantuan, dan pemeriksaan lintas sistem terhadap route frontend/backend serta layanan yang menanganinya.
- Membuat `testing-matrix.md`: 190 skenario dengan ID, tahap, alur, role, metode, hasil wajib, status, dan bukti. Sepuluh skenario PRE tahap 1 sudah dieksekusi; 177 belum diuji dan 3 terhalang layanan/HTTPS. Hitung ulang melalui validator jika daftar berubah.
- Menyiapkan fixture sintetis reusable untuk tes otomatis di SQLite memory. Tidak menambah akun browser pada database utama.
- Menguji guard perintah demo, ketidaklayakan skip pada tagihan unpaid/tanpa kelas, serta start/end privat dan kelas bersama. Aksi pengguna, laporan, dan persetujuan tidak boleh dianggap selesai hanya karena waktu digeser.
- Menginspeksi database interaktif lewat transaksi MySQL READ ONLY lalu rollback; tidak melakukan reset, seed, migrasi, perubahan akun, pembayaran, refund, atau payout di database tersebut.
- Memeriksa koneksi provider lewat satu GET status sesi demo yang sudah ada; tidak membuat checkout/transaksi baru.
- Membiarkan perubahan kerja pengguna yang sudah ada tetap utuh. Hanya dokumen, skrip pemeriksaan, fixture tes, tes tahap 1, dan penambahan tiga perintah npm yang dibuat/diubah untuk pekerjaan ini.

## 2. Peta sembilan tahap dan cakupan

| Tahap | Fokus | Alur/cabang yang dimiliki |
| --- | --- | --- |
| 1 | Persiapan | PRE-01 s.d. PRE-10; inventaris, fixture, clock, sandbox readonly, matriks |
| 2 | Akun dan otoritas | Akun murid, onboarding tutor, Google/manual, session, dokumen privat, admin CRUD/role |
| 3 | Pesanan dan harga | Guest claim, mapel/program, jenjang, peserta, durasi/sesi, diskon/voucher, konfigurasi kelas bersama/katalog |
| 4 | Pembayaran | Metode aktif, sukses/pending/gagal/expired, reopen/retry, webhook/idempotensi, mixed tender, HTTPS embedded |
| 5 | Pembentukan kelas | Matching berurutan, partial tutor program, retry/batal, kode join, kuota dan kepastian kelas bersama |
| 6 | Kelas berjalan | Kehadiran, laporan Bab/program, approve, progress/rating, masalah sesi, laporan kelas bersama |
| 7 | Perubahan perjalanan | Reschedule, ganti tutor, renewal, konsistensi riwayat dan assignment |
| 8 | Uang dan laporan | Refund, saldo, pencairan, verifikasi bank, rekonsiliasi, laporan/filter/PDF |
| 9 | Regresi akhir | Delapan perjalanan lengkap, CMS, notifikasi, akses, responsive/a11y, security, performa/SEO |

Matriks mencatat cabang layanan utama dan kasus risiko tinggi, bukan seluruh kombinasi matematis. Jika inspeksi tahap berikutnya menemukan cabang baru, tambahkan ID sebelum menutup tahap tersebut. Keberadaan 315 metode `test_*` lama dalam repo bukan berarti 315 tes sudah dijalankan atau semua cabang telah tercakup.

## 3. Fixture otomatis yang sudah tersedia

Sumber: `bimbelku-backend/tests/Support/StageOneFixtureSet.php`. Guard wajib: environment testing, koneksi SQLite, database `:memory:`. Di luar itu helper menolak sebelum membuat data.

Fixture terdiri dari **14 akun sintetis** dan 3 mapel SMA Kelas 12. Domain `bimbelku.test` hanya identitas tes; tidak mengirim email sungguhan. Tidak ada unggahan identitas palsu, tidak memublikasikan tutor, dan tidak memakai data bank sungguhan.

| Alias | Email sintetis | Kegunaan |
| --- | --- | --- |
| owner | qa.stage1.owner@bimbelku.test | Pemesan dan pemilik konfirmasi kelas |
| friend | qa.stage1.friend@bimbelku.test | Peserta tambahan yang meminta join |
| outsider | qa.stage1.outsider@bimbelku.test | Pengujian akses data kelas orang lain |
| unverified | qa.stage1.unverified@bimbelku.test | Murid email belum diverifikasi |
| minor | qa.stage1.minor@bimbelku.test | Murid di bawah umur dengan data wali sintetis |
| primary_admin | qa.stage1.primary_admin@bimbelku.test | Admin utama; identitas utama dikonfigurasi hanya dalam tes |
| ordinary_admin | qa.stage1.ordinary_admin@bimbelku.test | Admin biasa, bukan admin utama |
| math_teacher | qa.stage1.math_teacher@bimbelku.test | Tutor Matematika aktif, Senin 08.00–16.00 |
| indonesian_teacher | qa.stage1.indonesian_teacher@bimbelku.test | Tutor Bahasa Indonesia aktif, Senin 08.00–16.00 |
| english_teacher | qa.stage1.english_teacher@bimbelku.test | Tutor Bahasa Inggris aktif, Senin 08.00–16.00 |
| replacement_teacher | qa.stage1.replacement_teacher@bimbelku.test | Kandidat pengganti Matematika, Senin 08.00–16.00 |
| unavailable_teacher | qa.stage1.unavailable_teacher@bimbelku.test | Matematika tersedia Selasa, sengaja tidak cocok dengan Senin |
| paused_teacher | qa.stage1.paused_teacher@bimbelku.test | Aktif/verifikasi, tetapi tidak menerima permintaan |
| pending_teacher | qa.stage1.pending_teacher@bimbelku.test | Pending dan belum diverifikasi |

Semua data hilang saat proses tes selesai. Akun ini **tidak dapat dipakai login pada localhost biasa**. Fixture memastikan identitas, relasi mapel, dan variasi waktu dapat dibentuk; belum membuktikan matching/authorization/login berhasil. Hal itu diuji tahap 2/5. Variasi tutor offline/radius, rekening, program lengkap, Bab, promotion dan kelas dibuat per-skenario pada tahap yang memilikinya agar tidak mengotori fixture dasar.

## 4. Snapshot database dan akun browser yang sudah ada

Audit readonly terakhir: **08:56 WIB, 3 Oktober 2026**. Ini snapshot, dapat berubah karena penggunaan website setelah audit.

| Pemeriksaan | Hasil |
| --- | --- |
| Environment | local |
| Database | MySQL, terhubung; audit melalui transaksi read-only |
| Config cache | Tidak aktif |
| Akun aktif berdasarkan role | 4 murid, 3 tutor, 2 admin; kewenangan/kelengkapan tiap akun belum diuji lewat UI |
| Data tersimpan | 10 users, 22 paket, 28 orders, 22 bookings, 6 refunds, 0 payouts |
| Katalog | 5 paket, 63 mapel, 2 program, 16 time slots; jumlah tabel bukan jaminan semuanya aktif/valid |
| Migrasi pending | 2026_09_30_000100_add_education_levels_to_learning_programs |
| Google OAuth | client ID/secret ada, callback loopback; login browser belum diuji |
| Email lokal | SMTP; tes otomatis memakai array + Mail fake, jangan mengirim ke kontak nyata |
| Queue lokal | database; worker tidak terdeteksi saat audit |
| Scheduler | Proses schedule:work/run tidak terdeteksi saat audit; bukan bukti bahwa tidak ada jadwal eksternal |
| Port | MySQL 3306 aktif; frontend 8080, backend 8000, preview desain 8081 tidak aktif |

| Akun demo interaktif yang diinspeksi | Kondisi |
| --- | --- |
| demo.student@bimbelku.local | Murid aktif, email verified |
| demo.student2@bimbelku.local | Murid aktif, email verified |
| demo.gateway@bimbelku.local | Murid demo koneksi, bukan bukti punya kelas |
| demo.tutor@bimbelku.local | Tutor aktif/verifikasi, menerima permintaan, Matematika; 7 baris availability aktif |
| azrilguru@gmail.com | Akun demo yang diminta sebelumnya; aktif/verifikasi, Bahasa Indonesia; 6 baris availability aktif |

Password tidak dibaca, diubah, atau ditampilkan. Jumlah availability bukan bukti jamnya cocok dengan setiap pesanan. Tutor aktif ketiga tidak diidentifikasi sebagai fixture demo oleh filter audit; jangan mengubahnya tanpa memastikan asal akun. Untuk UI program tiga mapel perlu memastikan tutor ketiga serta kandidat pengganti yang tepat tersedia sebelum eksekusi, bukan menganggap semua sudah cocok.

## 5. Pembayaran sandbox: apa yang sudah terbukti

- Pembayaran aktif di konfigurasi lokal.
- Kunci terdeteksi **development**, token webhook ada. Nilainya tidak dicetak.
- Return success/cancel menunjuk loopback, bukan domain produksi.
- GET sesi demo koneksi lama ke endpoint provider berhasil **HTTP 200**, status **EXPIRED**.
- Sesi expired adalah sesi demo lama; bukan pembayaran gagal pada pesanan baru, dan tidak diaktifkan/dibuat ulang dalam audit ini.
- Hasil ini membuktikan kunci bisa membaca sesi tersebut, **belum membuktikan izin create checkout, semua channel aktif, webhook masuk, refund, atau payout**.

Webhook delivery masih BELUM diuji. Localhost tidak otomatis dapat dijangkau provider dari internet. Tahap 4 perlu membedakan uji sinkronisasi status server-to-server di localhost dari uji webhook nyata pada backend beralamat HTTPS yang dapat dijangkau. Jangan menganggap token terisi berarti webhook sudah terkirim.

Pembayaran embedded HTTPS masih TERHALANG karena alamat/browser HTTPS belum disiapkan. HTTP localhost memakai fallback hosted checkout menurut `XenditPaymentService`. Preview desain terpisah bukan bukti SDK embedded/gateway berjalan nyata.

## 6. Skip waktu: siap untuk apa, belum untuk apa

`demo:journey-time` memiliki stage `status`, `start`, `end` dan opsi `--session`. Pada local membutuhkan kunci development dan tagihan paid ber-provider yang benar. Perintah bukan pengganti pembayaran.

- `status`: membaca daftar sesi; tidak menggeser jadwal.
- `start`: menggeser satu sesi ke waktu sekarang; tidak otomatis menyatakan tutor/murid hadir.
- `end`: menggeser akhir waktunya; menolak bila kehadiran/mulai sesi belum memenuhi syarat.
- Privat sesudah end tetap membutuhkan laporan tutor dan konfirmasi murid.
- Kelas bersama sesudah end tetap membutuhkan laporan tutor dan verifikasi sesuai aturan sekarang.
- Command tidak otomatis mempercepat tenggat penawaran matching, guest order, proposal jadwal, refund provider atau pencairan bank.

Untuk deadline non-sesi di tes otomatis gunakan `Carbon::setTestNow` dalam proses SQLite terisolasi dan jalankan handler yang relevan pada fixture yang dimiliki tes itu. Selalu reset clock di teardown. Jangan mengubah jam Windows atau menjalankan expiry global pada database pengguna untuk memaksa demo.

Dalam smoke test tahap 1, order paid dan status presence disiapkan sebagai **fixture sintetis** hanya untuk memeriksa command. Tidak ada klaim bahwa checkout/presence sungguhan sudah lulus. Alur role sesungguhnya diuji tahap 4/6 dan kemudian E2E.

## 7. Bukti eksekusi tahap 1

Perintah dari akar proyek:

```powershell
npm run check:testing-stage1
```

Runner memaksa env child process: testing, SQLite memory, mail array, queue sync, cache/session array, endpoint/kunci gateway sintetis, config-cache path khusus yang harus belum ada. Tes melarang HTTP tak terdaftar dan memakai Mail fake. Tidak menjalankan suite pembayaran/kelas penuh secara diam-diam.

Hasil: **10 tes, 92 assertions, PASS** (PHPUnit 11.5.56, PHP 8.3.30; 5.255 detik pada run final sebelum pembaruan bukti matriks).

| Tes | Yang dibuktikan |
| --- | --- |
| TestingStageOnePreparationTest: isolation | DB memory, mail array, queue sync, tidak mengirim HTTP/email |
| TestingStageOnePreparationTest: fixture roles | 14 akun, 3 mapel, tutor terpisah, kandidat beda jadwal/paused/pending, admin utama berbeda |
| TestingStageOnePreparationTest: production refusal | Enam perintah demo menolak di production tanpa menciptakan user/order |
| TestingStageOnePreparationTest: local live-key refusal | Skip sesi menolak kunci production di local |
| TestingStageOnePreparationTest: deadline clock | Clock proses dapat maju dan dipulihkan |
| TestingStageOnePreparationTest: private skip | start/end tidak otomatis approve/completed; end butuh presence |
| TestingStageOnePreparationTest: shared skip | start/end tidak menggantikan laporan/verifikasi |
| DemoJourneyTimeCommandTest (2 tes) | Tagihan unpaid dan paid tanpa kelas tidak dapat diskip |
| DemoGatewayConnectivityCommandTest (1 tes) | Live key ditolak; development fixture membuat sesi mock pending, bukan pembayaran nyata |

Validator matriks: **190 ID unik**, 16 alur layanan + PRE/ADM/PUB/QA, semua sembilan tahap memiliki skenario. Sesudah pencatatan hasil: 10 LULUS, 177 BELUM, 3 TERHALANG. LULUS pada audit PRE berarti inspeksi berhasil, bukan semua prasyarat runtime sudah hijau.

## 8. Temuan/prasyarat yang tidak boleh ditutupi

| ID temuan | Kondisi | Dampak / tahap pemilik |
| --- | --- | --- |
| READY-01 | Satu migrasi program belum diterapkan | Sebelum UI tahap 3, backup database lokal dan terapkan migrasi yang sudah ada secara terarah; jangan migrate:fresh/reset. Schema memiliki prasyarat education_levels untuk kode baru. Belum diterapkan pada tahap 1 ini. |
| READY-02 | BankAccountNameVerifier::available dan matches selalu false | **Implementasi verifikasi nama bank belum tersedia**, bukan cukup mengaktifkan env. CASH-06/E2E-08 terhalang; tahap 8 harus menghubungkan layanan provider sebelum mengklaim transfer sungguhan lulus. Guard tidak boleh dimatikan. |
| READY-03 | Frontend/backend tidak sedang berjalan | Jalankan preview sebelum uji browser tahap 2. Jangan klaim halaman UI sudah diperiksa dari audit ini. |
| READY-04 | SMTP + queue database, worker tidak terdeteksi | Tahap 2 perlu memastikan pengiriman email tes dan worker dengan penerima aman. Tidak mengubah konfigurasi mail lokal atau mengirim email nyata di tahap ini. |
| READY-05 | Scheduler tidak terdeteksi | Tenggat/matching/refund tidak boleh dinilai hanya dari tunggu UI. Uji scheduler/handler terisolasi dan jalankan runtime demo dengan batas scope yang jelas. |
| READY-06 | Endpoint HTTPS embedded/webhook belum disiapkan | PAY-13 terhalang. Uji localhost fallback berbeda dari embedded HTTPS dan webhook provider sungguhan. |
| READY-07 | Akun UI/ketersediaan tiga tutor belum lengkap divalidasi | Fixture otomatis siap; sebelum UI tahap 5 verifikasi akun tambahan dan jangan reset akun demo lama yang punya riwayat. |
| RULE-01 | Renewal multi-peserta ditolak oleh kode | REN-06 menguji penjelasan batasan sejak awal; jika ingin dukungan baru, itu perubahan fitur tersendiri, bukan lulus paksa. |
| RULE-02 | Laporan privat tanpa respons -> admin review | CASE-07 harus mengikuti aturan nyata; tidak otomatis membayar tutor karena clock sudah lewat. |
| RULE-03 | Tidak semua sumber refund otomatis | Kelas bersama/sengketa perlu verifikasi alur sumbernya; jangan menganggap semua sama dengan no_teacher. |

## 9. Cara memeriksa persiapan lagi

Dari akar proyek:

```powershell
npm run check:testing-matrix
npm run check:testing-stage1
npm run audit:testing-readiness
```

Audit runtime normal tidak menghubungi provider. Opsional, hanya untuk GET sesi demo lama tanpa transaksi baru:

```powershell
php scripts/audit-testing-readiness.php --gateway-status
```

Audit mengembalikan exit 1 bila DB tidak dapat diperiksa atau masih ada migrasi pending; hasil JSON tetap menjelaskan kondisi. Exit 0 hanya menandakan audit DB/migrasi bersih, bukan seluruh kesiapan pembayaran/bank/UI. Endpoint remote dibatasi HTTPS host resmi dan key development; tidak mencetak secret, raw response, atau rekening pengguna.

Perintah seperti `demo:cheap-class setup/pre-payment/reset`, `demo:package-renewal setup`, dan `demo:session-reminder setup` dapat mengganti fixture demo atau membuat tagihan paid sintetis. Jangan jalankan perintah tersebut pada database interaktif untuk membuktikan pembayaran nyata. Panduan alur manual ada di [testing.md](testing.md); gunakan catatan kesiapan di dokumen ini sebelum menjalankan skenario.

## 10. Syarat menutup tahap berikutnya

Setiap tahap mempunyai bukti per skenario dan per metode, semua kegagalan mendapat ID, perbaikan diikuti retest dan regresi kasus terkait. Tidak ada bug kritis uang/akses yang boleh dianggap selesai karena tampilan berubah. Seluruh skenario terhalang tetap dicatat sampai layanan/config yang diperlukan benar-benar tersedia; persiapan tahap 1 tidak memberikan jaminan pencairan atau pembayaran bebas masalah.
