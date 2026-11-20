# Matriks pengujian menyeluruh BimbelKu

Disiapkan 3 Oktober 2026. Dokumen ini memuat rencana pengujian dalam sembilan tahap. Kondisi awal dan hasil tahap 1 ada di [testing-stage1.md](testing-stage1.md); panduan alur manual ada di [testing.md](testing.md).

## Aturan pencatatan

Status: `BELUM` berarti belum dijalankan pada putaran ini; `LULUS` berarti seluruh hasil dan bukti wajib tersedia; `GAGAL` berarti hasil aktual berbeda; `TERHALANG` berarti prasyarat belum tersedia; `ULANG` berarti skenario perlu dijalankan lagi setelah perubahan. Tes yang sudah ada tidak otomatis meluluskan skenario.

Metode: READ (inspeksi tanpa perubahan), API (tes otomatis dengan SQLite terisolasi), UI (browser), SANDBOX (payment provider development), CLOCK (percepatan waktu terisolasi). Jika skenario mencantumkan API+UI+SANDBOX, lampirkan bukti untuk setiap metode; hasil mock API saja tidak cukup. Bukti minimal mencakup nama tes dan hasilnya, atau run ID/tanggal, alias akun, kode paket/tagihan uji, hasil aktual, serta screenshot tanpa data rahasia. Catat hasil tiap metode.

Role yang dipakai: TAMU, MURID, PEMILIK, TEMAN, TUTOR, ADMIN, UTAMA (admin utama), SISTEM, dan HARNESS. Gunakan identitas sintetis. Jangan tampilkan akun uji sebagai tutor nyata di direktori publik.

Prioritas utama adalah pembayaran, harga, matching, akses data, refund, penyelesaian sesi, dan pencairan. Catat temuan proses bisnis terpisah dari temuan visual atau performa. Tahap 1 memeriksa kesiapan; tahap 2–9 belum dijalankan pada putaran ini.

## Jejak kode dan tes per alur

Path backend di bawah relatif ke `bimbelku-backend/`. Route resmi: `routes/api.php`; halaman dan redirect: `src/App.tsx` di akar proyek. Periksa sumber ini lagi jika kode berubah.

| Alur | Halaman masuk / sumber utama | Kandidat regresi yang sudah tersedia, belum dijalankan pada putaran ini |
| --- | --- | --- |
| 01 Akun murid | /register, /login, /complete-profile; AuthController, GoogleAuthController, PersistentLoginService | GoogleAuthenticationTest, PersistentBrowserSessionTest, EmailVerificationAndPinResetTest, PasswordResetEmailTest |
| 02 Onboarding tutor | /jadi-tutor, /guru/profil, /admin/guru; AuthController, AdminController | StageFourAccountFlowTest, StageSixBTeacherOperationsTest, LoginPortalSeparationTest |
| 03 Pesanan privat | /pesan, /student/packages/new, /pesanan/:code; StudentPackageController, GuestPackageOrderController, LearningProgramSelection | GuestPackageOrderFlowTest, LearningProgramProductTest, LearningProgramAudienceTest, ProgramTwoHourPriceAllocationTest, StageTwoMobileBookingTest |
| 04 Pembayaran | /payment, /student/history; XenditPaymentController, XenditPaymentService, XenditWebhookService | PaymentJourneyDemoTest, PaymentReconciliationTest, PaymentQrisDisplayTest, XenditMoneyFlowTest |
| 05 Matching | /student/packages, /guru/permintaan, /admin/tutor-searches; TeacherMatchingService, PackageCheckoutService, TeacherOfferController | TeacherOfferResponseWindowTest, PackageRescheduleMatchingRetryTest, LearningProgramProductTest |
| 06 Privat bersama teman | /student/my-classes; PrivateClassJoinController | PrivateClassJoinTest |
| 07 Jadwal | /student/packages/:id/reschedule, /guru/jadwal; ScheduleChangeController, StudentPackageController, TeacherScheduleController | PackageRescheduleMatchingRetryTest, CheckpointTwoOperationsAuditTest, AdminFinanceAndTeacherScheduleTest |
| 08 Sesi privat | /student/my-classes, /guru/kelas; LearningSessionController, StudentController | SessionPresenceFlowV2Test, StageOneSessionActionReminderTest, LearningProgramProductTest |
| 09 Masalah sesi | ruang kelas, /admin/cases; SessionWorkflowController, ExpireBookingWorkflow | StageFiveWorkflowTest, CheckpointTwoOperationsAuditTest, StageThreeFinanceSecurityTest |
| 10 Ganti tutor | paket/ruang kelas dan /admin/cases; TeacherReplacementController, PartialPackageRefundService | TeacherReplacementWorkflowTest, DemoTeacherReplacementCommandTest, PaymentJourneyDemoTest |
| 11 Perpanjangan | paket lama -> /student/packages/new; StudentPackageController, PackageCheckoutService | DemoPackageRenewalCommandTest, PaymentJourneyDemoTest |
| 12 Kelas bersama | /student/kelas-murah, /guru/kelas, /admin/kelas-murah; CheapClassController, TeacherCheapClassController, AdminCheapClassController, CheapClassService | CheapClassWorkflowTest, CheapClassPaymentLifecycleTest, CheapClassRecurringEngineTest, CheapClassTutorIsolationTest |
| 13 Refund | /student/history, /admin/finance?tab=refunds; StudentRefundController, RefundSettlementService | XenditMoneyFlowTest, TeacherReplacementWorkflowTest, StageSixCFinanceOperationsTest |
| 14 Pencairan | /guru/dompet, /guru/pencairan/rekening; TeacherOperationsController, TeacherPayoutService, BankAccountNameVerifier | XenditMoneyFlowTest, StageThreeFinanceSecurityTest, StageSixBTeacherOperationsTest |
| 15 Progress/ulasan | /student/progress, /guru/progress/package-subject/:id; RatingController, LearningSessionController | SessionRatingSubmissionTest, LearningProgramProductTest, PublicWebsiteCmsTest |
| 16 Voucher | /student/vouchers dan ringkasan pesanan; StudentPackageController, PackageCheckoutService | StageFivePackageExperienceTest, StageTwoCustomerWalletPaymentTest |
| ADM Operasional | /admin/users, /admin/subjects, /admin/stage-five, /admin/finance, /admin/website-media, /admin/public-tutors | StageSixCAdminOperationsTest, CurriculumSubjectGroupTest, AdminFinanceAndTeacherScheduleTest, WebsiteTutorGalleryTest, TeacherCoverSettingsTest |
| PUB Situs/bantuan | /, /program, /tutor, /area-layanan, /cara-belajar/:slug, /why-us, help per role | SubjectPageContentTest, PublicWebsiteCmsTest, LandingSubjectContractTest, StageSixCAccessAuditTest |
| QA Lintas sistem | src/App.tsx, notification/read state, responsive, SEO, keamanan | CheckpointFourQualityAuditTest, CheckpointFiveFinalRegressionTest, RateLimiterIsolationTest |

Nama controller/service merujuk `app/Http/Controllers/Api/`, `app/Services/`, atau `app/Console/Commands/`; nama tes merujuk `tests/Feature/*.php`. Referensi menunjukkan pintu masuk inspeksi, bukan jaminan tes lama mencakup semua baris di bawah.

## Skenario terdaftar

| ID | Tahap | Alur | Role | Metode | Skenario | Hasil wajib | Status | Bukti |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| PRE-01 | 1 | PRE | HARNESS | READ+API | Lingkungan dan database otomatis | testing + SQLite memory, bukan database interaktif | LULUS | testing-stage1.md / S1-20261003 / test_preparation_uses_an_isolated_database_and_fake_mail |
| PRE-02 | 1 | PRE | HARNESS | API | Siapkan identitas sintetis per role | Pemilik, teman, luar kelas, minor, belum verifikasi, admin biasa/utama berbeda | LULUS | testing-stage1.md / S1-20261003 / test_fixture_roles_and_subject_specific_tutor_availability_are_ready |
| PRE-03 | 1 | PRE | HARNESS | API | Siapkan tutor program dan pengganti | Tiga mapel tiga tutor; kandidat pengganti dan jadwal tidak cocok tersedia | LULUS | testing-stage1.md / S1-20261003 / StageOneFixtureSet |
| PRE-04 | 1 | PRE | HARNESS | API | Perintah demo di production | Semua perintah menolak, tidak membuat akun atau transaksi | LULUS | testing-stage1.md / S1-20261003 / test_demo_commands_refuse_production_without_creating_accounts_or_orders |
| PRE-05 | 1 | PRE | HARNESS | API | Demo memakai kunci production pada local | Ditolak sebelum transaksi/jadwal berubah | LULUS | testing-stage1.md / S1-20261003 / test_local_time_skip_refuses_a_production_payment_key + DemoGatewayConnectivityCommandTest |
| PRE-06 | 1 | PRE | HARNESS | API | Skip tagihan belum lunas/tanpa kelas | Ditolak, status tagihan tetap | LULUS | testing-stage1.md / S1-20261003 / DemoJourneyTimeCommandTest |
| PRE-07 | 1 | PRE | HARNESS | API+CLOCK | Majukan sesi privat/bersama dan tenggat proses tes | Clock dipulihkan; start/end tidak bypass kehadiran/laporan/approval | LULUS | testing-stage1.md / S1-20261003 / tiga tes clock di TestingStageOnePreparationTest |
| PRE-08 | 1 | PRE | SISTEM | READ | Audit migrasi dan akun demo lokal | Catat kesiapan nyata tanpa reset atau menyentuh transaksi | LULUS | testing-stage1.md / snapshot 08:56 WIB; satu migrasi pending, bukan berarti runtime siap penuh |
| PRE-09 | 1 | PRE | SISTEM | READ+SANDBOX | GET sesi demo pembayaran yang sudah ada | Respons remote valid; tidak membuat pembayaran/refund/payout | LULUS | testing-stage1.md / HTTP 200 EXPIRED pada sesi demo lama |
| PRE-10 | 1 | PRE | HARNESS | READ | Kelengkapan matriks | ID unik, semua 16 alur/9 tahap tercakup, metode dan bukti tervalidasi | LULUS | scripts/check-testing-matrix.mjs / 190 skenario |
| AUTH-01 | 2 | 01 | TAMU | API+UI | Register manual valid | Akun murid terbentuk dan verifikasi jelas | BELUM | — |
| AUTH-02 | 2 | 01 | TAMU | API+UI | Email sudah digunakan | Pesan error jelas, tidak membuat akun ganda | BELUM | — |
| AUTH-03 | 2 | 01 | MURID | API+UI | Login benar/salah | Akun benar masuk; kredensial salah ditolak tanpa bocor data | BELUM | — |
| AUTH-04 | 2 | 01 | MURID | API+UI | Email belum verifikasi/resend | Akses sesuai kebijakan, tautan kedaluwarsa/tidak sah ditolak | BELUM | — |
| AUTH-05 | 2 | 01 | TAMU | API+UI | Login Google akun baru/lama | Satu akun, callback konsisten, profile completion jika perlu | BELUM | — |
| AUTH-06 | 2 | 01 | TAMU | API+UI | OAuth dibatalkan/state salah/kode dipakai ulang | Tidak menciptakan sesi sah dari callback tidak valid | BELUM | — |
| AUTH-07 | 2 | 01 | MURID | API+UI | Reload/tutup browser/sesi habis | Sesi bertahan sesuai kebijakan; expiry meminta login dengan jelas | BELUM | — |
| AUTH-08 | 2 | 01 | MURID | API+UI | Logout satu/semua perangkat | Token terkait tak bisa dipakai kembali | BELUM | — |
| AUTH-09 | 2 | 01 | MURID | API+UI | Lupa/reset password dan token salah | Reset sah berhasil; token ulang/expired ditolak | BELUM | — |
| AUTH-10 | 2 | 01 | MURID | API+UI | Minor tanpa/dengan wali | Kelengkapan dan persetujuan wali konsisten | BELUM | — |
| TREG-01 | 2 | 02 | TAMU | API+UI | Register tutor langkah lengkap | Data tersimpan dan akun pending; belum boleh menerima kelas | BELUM | — |
| TREG-02 | 2 | 02 | TAMU | API+UI | Persyaratan/gelar belum lengkap | Error pada langkah yang benar, input tidak hilang | BELUM | — |
| TREG-03 | 2 | 02 | TAMU | API+UI | Berkas salah tipe/ukuran/berbahaya | Ditolak dan dokumen privat tidak menjadi URL publik | BELUM | — |
| TREG-04 | 2 | 02 | TUTOR | UI | WhatsApp setelah register lengkap | Pesan memuat data relevan, tidak mengirim password/dokumen sensitif | BELUM | — |
| TREG-05 | 2 | 02 | ADMIN | API+UI | Verifikasi lolos/ditolak | Status tutor dan hak akses berubah sesuai keputusan | BELUM | — |
| TREG-06 | 2 | 02 | TUTOR | API+UI | Aktif tanpa jadwal/berhenti menerima | Tidak menjadi kandidat matching yang tidak memenuhi syarat | BELUM | — |
| TREG-07 | 2 | 02 | TUTOR | API+UI | Login tutor pada login bersama | Route guru benar; admin memakai portal terpisah | BELUM | — |
| TREG-08 | 2 | 02 | TUTOR | API+UI | Persetujuan tampil publik terpisah | Aktif mengajar tidak otomatis berarti identitas boleh dipublikasikan | BELUM | — |
| ORD-01 | 3 | 03 | TAMU | API+UI | Pesan tanpa akun lalu register email sama | Pesanan terhubung tepat satu kali ke akun yang sah | BELUM | — |
| ORD-02 | 3 | 03 | TAMU | API+UI | Pesanan tamu dan login email berbeda | Tidak mengklaim pesanan milik email lain | BELUM | — |
| ORD-03 | 3 | 03 | MURID | API+UI | Privat satu peserta mapel dengan Bab | Materi, sesi, durasi, identitas dan nominal tersimpan benar | BELUM | — |
| ORD-04 | 3 | 03 | PEMILIK | API+UI | Privat beberapa peserta | Jumlah/detail peserta dan diskon konsisten sebelum bayar | BELUM | — |
| ORD-05 | 3 | 03 | MURID | API+UI | Program pilih satu/dua/tiga mapel tanpa Bab | Mapel pilihan sah dan Bab tidak diwajibkan | BELUM | — |
| ORD-06 | 3 | 03 | MURID | API+UI | Program jenjang sesuai/tidak sesuai/Umum | Ketersediaan awal sesuai konfigurasi, bukan selalu SMA | BELUM | — |
| ORD-07 | 3 | 03 | MURID | API+UI | Online dengan lokasi jauh/kosong | Tidak gagal karena radius tatap muka | BELUM | — |
| ORD-08 | 3 | 03 | MURID | API+UI | Tatap muka alamat/radius valid/tidak valid | Lokasi wajib dan aturan jangkauan dijelaskan | BELUM | — |
| ORD-09 | 3 | 03 | MURID | API+UI | Durasi satu/dua jam pada program multi-mapel | Ringkasan dan tagihan sama; porsi tiap tutor mengikuti sesi/durasi | BELUM | — |
| ORD-10 | 3 | 03 | MURID | API+UI | Jumlah peserta minimum/maksimum/lebih satu | Batas admin dipatuhi; harga tak aktif tidak memakai angka tebak | BELUM | — |
| ORD-11 | 3 | 03 | MURID | API+UI | Hari maksimum satu lalu ganti/batalkan pilihan | Pilihan hari tidak terkunci dan jumlah sesi tetap sah | BELUM | — |
| ORD-12 | 3 | 03 | MURID | API+UI | Validasi per langkah dan kembali ke langkah lama | Tidak meminta field tahap berikutnya; data valid tidak hilang | BELUM | — |
| ORD-13 | 3 | 03 | MURID | API+UI | Jadwal tumpang tindih/jam tak aktif/data dimanipulasi | Server menolak meski frontend dilewati | BELUM | — |
| ORD-14 | 3 | 03 | MURID | API+UI | Paket kelima dan paket nonaktif | Semua paket aktif tampil, paket nonaktif tidak dapat dipesan baru | BELUM | — |
| ORD-15 | 3 | 03 | MURID | API+UI | Double submit pesanan | Tidak membuat paket/tagihan tak sengaja berulang | BELUM | — |
| PAY-01 | 4 | 04 | MURID | API+UI+SANDBOX | Checkout tagihan valid | Nominal tampil dan sesi pembayaran bisa dibuat | BELUM | — |
| PAY-02 | 4 | 04 | MURID | API+UI+SANDBOX | QR/VA/ewallet yang aktif | Instruksi sesuai metode; konfirmasi jelas dan fallback ewallet valid | BELUM | — |
| PAY-03 | 4 | 04 | MURID | API+UI+SANDBOX | Pembayaran berhasil | Order lunas satu kali lalu matching/join dilanjutkan | BELUM | — |
| PAY-04 | 4 | 04 | MURID | API+UI+SANDBOX | Pending/gagal/kedaluwarsa | Tidak aktifkan kelas; alasan dan tindakan berikutnya jelas | BELUM | — |
| PAY-05 | 4 | 04 | MURID | API+UI+SANDBOX | Tutup/reload/buka lagi/double click | Tagihan dan sesi aktif dipakai ulang, tidak double charge | BELUM | — |
| PAY-06 | 4 | 04 | SISTEM | API | Webhook ganda/urutan terbalik | Status terminal tidak mundur; buku besar satu kali | BELUM | — |
| PAY-07 | 4 | 04 | SISTEM | API | Token/nominal/currency/reference webhook salah | Ditolak/tidak melunaskan tagihan lain | BELUM | — |
| PAY-08 | 4 | 04 | MURID | API+UI+SANDBOX | Return success tanpa pembayaran | Redirect saja tidak menandai lunas | BELUM | — |
| PAY-09 | 4 | 04 | MURID | API+UI+SANDBOX | Return localhost dan sinkronisasi status | Bisa kembali; sinkronisasi melunaskan hanya jika provider mengonfirmasi | BELUM | — |
| PAY-10 | 4 | 04 | SISTEM | API | Timeout setelah provider menerima permintaan | Retry memakai idempotensi; hasil ambigu tidak dianggap gagal pasti | BELUM | — |
| PAY-11 | 4 | 04 | SISTEM | API+CLOCK | Pembayaran datang setelah pesanan batal/expired | Tidak membentuk kelas tak sah; dana punya jalur penyelesaian | BELUM | — |
| PAY-12 | 4 | 04 | MURID | API+UI | Saldo/mixed tender jika masih tersedia | Saldo + pembayaran eksternal = total, ledger tidak ganda | BELUM | — |
| PAY-13 | 4 | 04 | MURID | UI+SANDBOX | Embedded checkout melalui HTTPS | SDK/metode/redirect bekerja sungguhan, bukan preview visual | TERHALANG | HTTPS browser belum disiapkan |
| MAT-01 | 5 | 05 | TUTOR | API+UI | Kandidat memenuhi mapel/jenjang/jadwal/mode | Hanya tutor yang benar menerima penawaran | BELUM | — |
| MAT-02 | 5 | 05 | TUTOR | API+UI | Tutor menerima | Penawaran diterima dan sesi ditugaskan sekali | BELUM | — |
| MAT-03 | 5 | 05 | TUTOR | API+UI | Tutor menolak | Berlanjut ke kandidat berikutnya, bukan berhenti tanpa informasi | BELUM | — |
| MAT-04 | 5 | 05 | SISTEM | API+CLOCK | Tutor tidak merespons | Tenggat dihormati, penawaran berikutnya tidak tumpang tindih | BELUM | — |
| MAT-05 | 5 | 05 | MURID | API+UI | Tidak ada kandidat | Status no_teacher dan cari lagi/ubah jadwal/batal jelas | BELUM | — |
| MAT-06 | 5 | 05 | MURID | API+UI | Cari lagi setelah kondisi tutor diperbaiki | Tidak terhalang riwayat pencarian lama secara keliru | BELUM | — |
| MAT-07 | 5 | 05 | MURID | API+UI | TKA dua tutor siap satu belum | Informasi per mapel benar; belum mengaktifkan sebagian paket | BELUM | — |
| MAT-08 | 5 | 05 | MURID | API+UI | Semua mapel program menerima | Satu tutor per mapel, semua sesi terbentuk sesuai pesanan | BELUM | — |
| MAT-09 | 5 | 05 | SISTEM | API | Dua accept serentak/offer lama | Hanya assignment sah; tidak ada bentrok/booking ganda | BELUM | — |
| MAT-10 | 5 | 05 | MURID | API+UI | Online retry dan offline perluasan radius | Radius hanya memengaruhi tatap muka | BELUM | — |
| MAT-11 | 5 | 05 | MURID | API+UI | Batal ketika sebagian tutor menerima | Semua penawaran/sesi pending berhenti; refund tidak berulang | BELUM | — |
| JOIN-01 | 5 | 06 | PEMILIK | API+UI | Buat kode kelas privat aktif | Kode hanya untuk paket eligible dan pemiliknya | BELUM | — |
| JOIN-02 | 5 | 06 | TEMAN | API+UI | Masukkan kode | Permintaan masuk menunggu keputusan pemilik | BELUM | — |
| JOIN-03 | 5 | 06 | PEMILIK | API+UI | Setujui teman | Jadwal/kelas terlihat pada akun teman yang benar | BELUM | — |
| JOIN-04 | 5 | 06 | PEMILIK | API+UI | Tolak teman | Tidak memberi akses kelas/transaksi | BELUM | — |
| JOIN-05 | 5 | 06 | TEMAN | API+UI | Kode salah/paket tidak aktif/kelas penuh | Error jelas, kuota tidak bertambah | BELUM | — |
| JOIN-06 | 5 | 06 | TEMAN | API+UI | Join ulang/approve serentak | Tidak membuat peserta ganda atau melebihi kuota | BELUM | — |
| JOIN-07 | 5 | 06 | TEMAN | API+UI | Aksi kehadiran/konfirmasi/refund oleh teman | Hanya pemesan memiliki kewenangan keuangan/konfirmasi utama | BELUM | — |
| SCH-01 | 7 | 07 | MURID | API+UI | Ganti jadwal saat belum mendapat tutor | Jumlah/mapel/sesi tetap sesuai pesanan dibayar | BELUM | — |
| SCH-02 | 7 | 07 | MURID | API+UI | Satu sesi lalu memilih empat mapel | Tidak bisa menambah beban layanan lewat reschedule | BELUM | — |
| SCH-03 | 7 | 07 | MURID | API+UI | Ubah hanya mapel program yang belum terisi | Penugasan mapel siap tidak berubah tanpa alasan | BELUM | — |
| SCH-04 | 7 | 07 | PEMILIK | API+UI | Kelas aktif ubah satu/future | Hanya sesi sasaran yang diajukan berubah | BELUM | — |
| SCH-05 | 7 | 07 | TUTOR | API+UI | Semua pihak menyetujui | Jadwal baru berlaku setelah persetujuan lengkap | BELUM | — |
| SCH-06 | 7 | 07 | TUTOR | API+UI+CLOCK | Ditolak/tidak ditanggapi | Jadwal lama tetap; proposal expired punya status jelas | BELUM | — |
| SCH-07 | 7 | 07 | PEMILIK | API+UI | Bentrok/jam tak aktif/perubahan terlalu dekat | Ditolak tanpa merusak sesi lama | BELUM | — |
| SCH-08 | 7 | 07 | TUTOR | API+UI | Ubah ketersediaan dengan kelas aktif | Tidak menghapus/menggeser booking yang disepakati diam-diam | BELUM | — |
| SES-01 | 6 | 08 | TUTOR | API+UI+CLOCK | Mulai sesi pada jendela waktu yang sah | Tutor siap tercatat tanpa otomatis melunaskan sesi | BELUM | — |
| SES-02 | 6 | 08 | TUTOR | API+UI | Online tanpa/dengan meeting link | Tanpa link ditolak; link sah dapat diakses peserta berhak | BELUM | — |
| SES-03 | 6 | 08 | PEMILIK | API+UI | Konfirmasi hadir setelah tutor siap | Sesi in_progress, waktu kehadiran tercatat benar | BELUM | — |
| SES-04 | 6 | 08 | TUTOR | API+UI+CLOCK | Akhir waktu/check-out | Tidak bypass kehadiran/laporan/konfirmasi murid | BELUM | — |
| SES-05 | 6 | 08 | TUTOR | API+UI | Laporan mapel dengan Bab | Progress Bab sah dan provisional sampai disetujui | BELUM | — |
| SES-06 | 6 | 08 | TUTOR | API+UI | Laporan program tanpa Bab | Materi/estimasi progress cukup; tidak wajib field yang tidak ada | BELUM | — |
| SES-07 | 6 | 08 | PEMILIK | API+UI | Setujui laporan | Sesi selesai, used_sessions/progress/pendapatan konsisten | BELUM | — |
| SES-08 | 6 | 08 | SISTEM | API+UI | Double laporan/approve/reload | Sesi dan pendapatan tidak terhitung dua kali | BELUM | — |
| SES-09 | 6 | 08 | PEMILIK | API+UI+CLOCK | Seluruh sesi paket/program selesai | Semua tutor benar, paket completed, total uang sesuai | BELUM | — |
| SES-10 | 6 | 08 | TUTOR | API+UI | Tatap muka lokasi sesuai/tidak sesuai | Aturan kehadiran tak memblokir online | BELUM | — |
| CASE-01 | 6 | 09 | TUTOR | API+UI+CLOCK | Murid tidak hadir | Laporan hanya pada kondisi/tenggat sah dan dana ditahan | BELUM | — |
| CASE-02 | 6 | 09 | MURID | API+UI+CLOCK | Tutor tidak hadir | Bukti/status/tindakan berikutnya jelas | BELUM | — |
| CASE-03 | 6 | 09 | TUTOR | API+UI | Keadaan darurat | Laporan dan sesi terkait konsisten pada kedua pihak | BELUM | — |
| CASE-04 | 6 | 09 | PEMILIK | API+UI | Keberatan sebelum approve | Sengketa dibuat, saldo terkait belum bisa ditarik | BELUM | — |
| CASE-05 | 6 | 09 | ADMIN | API+UI | Putusan refund murid | Hanya nominal yang berhak dikembalikan | BELUM | — |
| CASE-06 | 6 | 09 | ADMIN | API+UI | Putusan tutor dibayar | Pendapatan dibuka satu kali dan alasan tercatat | BELUM | — |
| CASE-07 | 6 | 09 | SISTEM | API+CLOCK | Murid tidak merespons laporan | Masuk completion review sesuai kode, tidak diam-diam lunas | BELUM | — |
| CASE-08 | 6 | 09 | MURID | API+UI | Laporan berulang/setelah keputusan final | Tidak membuat sengketa/dana duplikat | BELUM | — |
| REP-01 | 7 | 10 | PEMILIK | API+UI | Ajukan ganti tutor sesi mendatang | Sesi sasaran dan alasan benar | BELUM | — |
| REP-02 | 7 | 10 | ADMIN | API+UI | Setujui/tolak permintaan | Dampak assignment sesuai keputusan dan audit tercatat | BELUM | — |
| REP-03 | 7 | 10 | PEMILIK | API+UI | Batalkan permintaan pending | Tidak melanjutkan matching pengganti | BELUM | — |
| REP-04 | 7 | 10 | TUTOR | API+UI | Tutor pengganti menerima | Sesi lama tetap milik tutor lama; sisa milik pengganti | BELUM | — |
| REP-05 | 7 | 10 | PEMILIK | API+UI | Pengganti tidak ada/cari lagi/ubah jadwal | Semua opsi memakai sesi tersisa, tanpa tagihan kedua | BELUM | — |
| REP-06 | 7 | 10 | PEMILIK | API+UI+SANDBOX | Refund sesi penggantian tidak terpenuhi | Refund sebagian tepat, tidak mengambil uang sesi selesai | BELUM | — |
| REP-07 | 7 | 10 | SISTEM | API | Permintaan ganda/feature dimatikan | Tidak ada penggantian overlapping/bypass feature gate | BELUM | — |
| REN-01 | 7 | 11 | PEMILIK | API+UI | Paket memenuhi/belum memenuhi syarat renewal | Tombol/server sepakat tentang eligibility | BELUM | — |
| REN-02 | 7 | 11 | PEMILIK | API+UI+SANDBOX | Perpanjang dan bayar | Paket/tagihan baru terhubung ke lama, bukan pembayaran lama ulang | BELUM | — |
| REN-03 | 7 | 11 | TUTOR | API+UI | Tutor lama menerima | Kelas baru aktif dengan assignment baru | BELUM | — |
| REN-04 | 7 | 11 | TUTOR | API+UI | Tutor lama menolak/tidak tersedia | Lanjut matching sesuai aturan, tanpa tutor dipaksa | BELUM | — |
| REN-05 | 7 | 11 | PEMILIK | API+UI+CLOCK | Tagihan gagal/expired/batal | Paket lama tetap utuh, renewal belum aktif | BELUM | — |
| REN-06 | 7 | 11 | PEMILIK | API+UI | Perpanjangan multi-peserta | Batasan sekarang dijelaskan sejak awal; tidak gagal terlambat | BELUM | — |
| REN-07 | 7 | 11 | SISTEM | API+UI | Double submit dan riwayat transaksi | Satu renewal sah satu tagihan; tidak dua baris palsu | BELUM | — |
| SHARED-01 | 3 | 12 | ADMIN | API+UI | Buat kelas tutor/kuota/minimum/jadwal | Konfigurasi valid; minimum tidak melebihi maksimum | BELUM | — |
| SHARED-02 | 3 | 12 | MURID | API+UI | Daftar kuota tersedia/penuh | Kursi dicadangkan sesuai batas, bukan tanpa batas | BELUM | — |
| SHARED-03 | 4 | 12 | MURID | API+UI+SANDBOX | Bayar kursi kelas bersama | Enrollment lunas satu kali dan transaksi terhubung benar | BELUM | — |
| SHARED-04 | 5 | 12 | SISTEM | API+CLOCK | Tidak bayar/batalkan kursi unpaid | Kursi dilepas, tagihan expired/cancelled konsisten | BELUM | — |
| SHARED-05 | 5 | 12 | SISTEM | API+UI+CLOCK | Kuota minimum tercapai | Kelas dikonfirmasi pada kondisi/waktu yang benar | BELUM | — |
| SHARED-06 | 5 | 12 | SISTEM | API+UI+CLOCK | Minimum tidak tercapai | Kelas batal dan peserta lunas mendapat jalur refund | BELUM | — |
| SHARED-07 | 5 | 12 | SISTEM | API+UI+CLOCK | Tutor tidak tersedia/pengganti tidak ada | Tidak menjalankan kelas tanpa tutor yang sah | BELUM | — |
| SHARED-08 | 6 | 12 | TUTOR | API+UI+CLOCK | Mulai kelas, kehadiran, laporan | Setiap sesi punya akses/kehadiran/progress benar | BELUM | — |
| SHARED-09 | 6 | 12 | ADMIN | API+UI | Verifikasi atau minta revisi laporan | Status/dana tidak selesai sebelum persyaratan terpenuhi | BELUM | — |
| SHARED-10 | 6 | 12 | SISTEM | API+UI+CLOCK | Seluruh sesi selesai | Kelas selesai dan saldo/report sesuai peserta sah | BELUM | — |
| SHARED-11 | 5 | 12 | SISTEM | API | Dua murid mengambil kursi terakhir | Kapasitas tidak terlampaui | BELUM | — |
| SHARED-12 | 9 | 12 | ADMIN | API+UI+CLOCK | Kelas berulang dan pembatalan admin | Generasi jadwal tidak ganda, alasan/refund terhubung | BELUM | — |
| REF-01 | 8 | 13 | MURID | API+UI | Batal saat matching/tutor tidak ditemukan | Refund eligible tanpa keputusan kelayakan admin ulang | BELUM | — |
| REF-02 | 8 | 13 | MURID | API+UI | Pilih saldo sebelum konfirmasi | Tujuan jelas, saldo masuk satu kali | BELUM | — |
| REF-03 | 8 | 13 | MURID | API+UI+SANDBOX | Pilih metode awal | Permintaan provider tepat dan hasil final terlihat | BELUM | — |
| REF-04 | 8 | 13 | SISTEM | API+UI+SANDBOX | Pending/succeeded/failed refund | Status pihak sama; gagal punya tindakan, bukan macet tanpa arah | BELUM | — |
| REF-05 | 8 | 13 | SISTEM | API | Notifikasi/refund request ganda | Dana kembali tepat satu kali | BELUM | — |
| REF-06 | 8 | 13 | MURID | API+UI | Refund parsial dan campuran sumber dana | Pembagian sesuai sumber/nominal, total tidak melebihi pembayaran | BELUM | — |
| REF-07 | 8 | 13 | MURID | API+UI | Refund keputusan sengketa/kelas bersama | Kelayakan/otoritas mengikuti aturan sumbernya; jangan disamakan semua otomatis | BELUM | — |
| REF-08 | 8 | 13 | MURID | API+UI | Ganti tujuan setelah dikirim/akses refund orang lain | Ditolak tanpa mengalihkan dana | BELUM | — |
| REF-09 | 8 | 13 | ADMIN | API+UI+SANDBOX | Rekonsiliasi/retry saat perlu penanganan | Tidak refund ganda; alasan dan tindakan tercatat | BELUM | — |
| REF-10 | 8 | 13 | MURID | API+UI | Pending belum memilih tujuan | Ada tindakan pilih tujuan, tidak dianggap selesai | BELUM | — |
| CASH-01 | 8 | 14 | TUTOR | API+UI | Sesi approved/unapproved/disputed | Hanya hak pendapatan yang sah dapat ditarik | BELUM | — |
| CASH-02 | 8 | 14 | TUTOR | API+UI | Nominal 9999/10000/saldo/lebih saldo | Batas minimum dan saldo ditegakkan server | BELUM | — |
| CASH-03 | 8 | 14 | TUTOR | API+UI | Nominal -> rekening -> ajukan langsung | Tidak perlu tombol simpan terpisah/persetujuan admin rutin | BELUM | — |
| CASH-04 | 8 | 14 | TUTOR | API+UI | Nama rekening beda/alamat kosong/hold rekening | Ditahan dengan alasan jelas, saldo aman | BELUM | — |
| CASH-05 | 8 | 14 | TUTOR | API+UI | Name verifier belum tersedia | Fail closed, bukan membandingkan teks sebagai bukti bank | BELUM | — |
| CASH-06 | 8 | 14 | TUTOR | API+UI+SANDBOX | Transfer bank dengan verifikasi nama nyata | Nama diperiksa provider dan transfer sukses tanpa admin rutin | TERHALANG | BankAccountNameVerifier selalu false |
| CASH-07 | 8 | 14 | SISTEM | API | Payout gagal atau respons ambigu | Gagal terminal melepas saldo; ambigu tetap reserved sampai pasti | BELUM | — |
| CASH-08 | 8 | 14 | TUTOR | API+UI | Tarik sebagian/berulang/dua request bersamaan | Sisa saldo benar; tidak mengalokasikan pendapatan dua kali | BELUM | — |
| CASH-09 | 8 | 14 | SISTEM | API | Potongan pajak dan callback duplikat | Nominal kirim dan laporan benar, penyelesaian satu kali | BELUM | — |
| RATE-01 | 6 | 15 | MURID | API+UI | Ulasan sesi approved tanpa legacy classroom | Bisa memberi ulasan pada booking yang sah | BELUM | — |
| RATE-02 | 6 | 15 | MURID | API+UI | Belum approved/bukan peserta/ulasan ulang | Ditolak jelas, tidak menghasilkan rating palsu/ganda | BELUM | — |
| RATE-03 | 6 | 15 | MURID | API+UI | Program beberapa tutor | Ulasan mengikuti tutor sesi itu, bukan tutor mapel lain | BELUM | — |
| RATE-04 | 6 | 15 | MURID | API+UI | Progress laporan sebelum/setelah persetujuan | Progress resmi berubah pada waktu yang benar | BELUM | — |
| RATE-05 | 9 | 15 | ADMIN | API+UI | Moderasi dan publikasi ulasan/cerita siswa | Sumber/izin nyata, tidak memublikasikan identitas tanpa persetujuan | BELUM | — |
| VCH-01 | 3 | 16 | MURID | API+UI | Klaim dan gunakan promo valid | Diskon benar pada quote/order, klaim tidak ganda | BELUM | — |
| VCH-02 | 3 | 16 | MURID | API+UI | Minimum/target/expired/kuota/telah terpakai | Promo tak sah ditolak jelas | BELUM | — |
| VCH-03 | 4 | 16 | SISTEM | API | Reserve sebelum bayar lalu bayar | Status reserved -> used tepat satu kali | BELUM | — |
| VCH-04 | 4 | 16 | SISTEM | API+CLOCK | Batal/expired dan pelepasan promo | Pelepasan mengikuti aturan, tanpa menggandakan kuota | BELUM | — |
| VCH-05 | 3 | 16 | MURID | API+UI | Promo dan diskon peserta bersamaan | Urutan/dasar hitung konsisten hingga alokasi tutor | BELUM | — |
| ADM-01 | 2 | ADM | UTAMA | API+UI | CRUD admin biasa | Form/tombol valid, create/update/delete benar dengan audit | BELUM | — |
| ADM-02 | 2 | ADM | ADMIN | API+UI | Coba membuat/menghapus admin lain | Server menolak sesuai batas kewenangan | BELUM | — |
| ADM-03 | 2 | ADM | UTAMA | API+UI | Hapus diri/admin terakhir | Tidak mengunci akses pengelola utama | BELUM | — |
| ADM-04 | 3 | ADM | ADMIN | API+UI | CRUD kelompok/mapel/Bab/program | Relasi jelas, pilihan cursor/mobile dapat digunakan | BELUM | — |
| ADM-05 | 3 | ADM | ADMIN | API+UI | Duplikat nama/relasi sedang dipakai | Error manusiawi dan data pesanan lama tetap utuh | BELUM | — |
| ADM-06 | 3 | ADM | ADMIN | API+UI | Tarif/paket/diskon peserta/jam belajar | Perubahan memengaruhi pesanan baru, tidak mengubah harga lunas | BELUM | — |
| ADM-07 | 7 | ADM | ADMIN | API+UI | Lihat/update jadwal tutor | Jadwal harian detail dan padding/form tidak terpotong | BELUM | — |
| ADM-08 | 8 | ADM | ADMIN | API+UI | Laporan keuangan filter/paginasi | Total sesuai transaksi dan hasil tidak memuat semua sekaligus | BELUM | — |
| ADM-09 | 8 | ADM | ADMIN | API+UI | PDF laporan dibuka/diunduh | File valid, tidak diblokir, akses hanya role sah | BELUM | — |
| ADM-10 | 9 | ADM | ADMIN | API+UI | Logo/hero/foto/sampul upload/preview | Media tersimpan tampil, default/reset sesuai pilihan | BELUM | — |
| ADM-11 | 9 | ADM | ADMIN | API+UI | Galeri beranda versus kenali tutor | Pengelolaan tidak saling tercampur, izin dan identitas benar | BELUM | — |
| ADM-12 | 9 | ADM | ADMIN | API+UI | Direktori tutor filter/paginasi/consent | Lebih dari 20 tetap terjangkau lewat filter seluruh data | BELUM | — |
| ADM-13 | 9 | ADM | ADMIN | API+UI | Banner/testimoni/FAQ/konten CMS | Layout terkunci; sumber/tanggal/izin klaim tetap benar | BELUM | — |
| ADM-14 | 9 | ADM | ADMIN | API+UI | Audit log dan aksi keuangan | Pelaku/aksi jelas, rahasia tidak masuk log publik | BELUM | — |
| PUB-01 | 9 | PUB | TAMU | UI | Semua link desktop/mobile mega menu | Route benar, bukan judul berbeda menuju isi generik/tidak terbuka | BELUM | — |
| PUB-02 | 9 | PUB | TAMU | UI | Katalog kategori/detail dan prefill pesanan | Anchor tepat, mapel/program/paket konsisten | BELUM | — |
| PUB-03 | 9 | PUB | TAMU | UI | Halaman cara belajar/refund/kelas bersama/tentang/area | Informasi sesuai fungsi nyata dan CTA menuju tindakan benar | BELUM | — |
| PUB-04 | 9 | PUB | MURID | UI | Bantuan murid/tutor FAQ -> WhatsApp | Halaman terbuka dan kontak resmi konsisten | BELUM | — |
| PUB-05 | 9 | PUB | TAMU | UI | Animasi/sticky/smooth scroll/banner | Target judul tak tertutup; reduced-motion/offscreen dihormati | BELUM | — |
| QA-01 | 9 | QA | SISTEM | API+UI | Notifikasi perubahan -> dibuka -> dibaca | Tanda merah tepat submenu, hilang setelah dibaca, bukan status lama | BELUM | — |
| QA-02 | 9 | QA | MURID | UI | Dashboard/menu/account/banner mobile | Tidak ada route tombol berulang; menu mobile tidak menduplikasi bottom nav | BELUM | — |
| QA-03 | 9 | QA | SISTEM | API+UI | URL/API milik akun lain dan role salah | Akses ditolak; dokumen/bank/PII tidak bocor | BELUM | — |
| QA-04 | 9 | QA | SISTEM | API | Manipulasi nominal/input/file/token/rate limit | Validasi server efektif dan tidak memercayai frontend | BELUM | — |
| QA-05 | 9 | QA | TAMU | UI | 360/390/768/1280/1440 px + zoom/keyboard | Input/modal/card tidak keluar layar; fokus/label/kontras dapat digunakan | BELUM | — |
| QA-06 | 9 | QA | TAMU | UI | Lighthouse halaman perwakilan | Performance/SEO target >90 dengan URL/build/kondisi dan laporan tercatat | BELUM | — |
| QA-07 | 9 | QA | TAMU | READ+UI | Canonical/sitemap/indexability/metadata | Halaman publik terindeks sesuai tujuan; area akun tidak diekspos | BELUM | — |
| QA-08 | 9 | QA | SISTEM | READ+API | Dependency audit/bundle/static security | Temuan dicatat dan tidak ada rahasia dalam build | BELUM | — |
| E2E-01 | 9 | QA | MURID | UI+SANDBOX+CLOCK | Privat sendiri dari pesan sampai uang tutor | Tidak ada langkah yang berhenti tanpa tindakan berikutnya | BELUM | — |
| E2E-02 | 9 | QA | PEMILIK | UI+SANDBOX+CLOCK | Privat teman + join + sesi selesai | Jadwal anggota, otoritas pemilik dan uang konsisten | BELUM | — |
| E2E-03 | 9 | QA | MURID | UI+SANDBOX+CLOCK | TKA tiga mapel/durasi dua jam | Semua sesi tiga tutor, laporan tanpa Bab, rating dan total uang benar | BELUM | — |
| E2E-04 | 9 | QA | MURID | UI+SANDBOX+CLOCK | Kelas bersama dari daftar sampai selesai | Kuota, kelas, verifikasi laporan dan pendapatan benar | BELUM | — |
| E2E-05 | 9 | QA | PEMILIK | UI+SANDBOX+CLOCK | Renewal paket sampai selesai | Satu tagihan baru, riwayat lama utuh | BELUM | — |
| E2E-06 | 9 | QA | PEMILIK | UI+SANDBOX+CLOCK | Ganti tutor lalu kelas selesai | Tanpa tagihan kedua, pembagian pendapatan benar | BELUM | — |
| E2E-07 | 9 | QA | MURID | UI+SANDBOX | No teacher -> cari lagi/jadwal/batal/refund | Seluruh cabang memiliki hasil akhir yang konsisten | BELUM | — |
| E2E-08 | 9 | QA | TUTOR | UI+SANDBOX | Saldo sesi -> penarikan -> bank | Verifikasi bank nyata dan status transfer final | TERHALANG | BankAccountNameVerifier selalu false |

## Partisi data dan kombinasi yang wajib

Tidak semua kombinasi UI diuji dengan perkalian penuh. Uji semua cabang yang terdaftar, semua batas, kombinasi berisiko secara penuh, dan pairwise untuk variasi lain. Jangan mengklaim daftar ini bukti semua kemungkinan sudah diuji.

- Peserta: 1, 2, batas maksimum konfigurasi, maksimum + 1; pemesan ikut/tidak ikut belajar.
- Materi: mapel satu dengan Bab, beberapa mapel, program 1/2/3 mapel tanpa Bab; program SMA saja dan program lintas jenjang/Umum.
- Durasi: setiap durasi yang diaktifkan admin, wajib 1 dan 2 jam jika tersedia. Sesi: paling sedikit, paling banyak, dan paket kelima yang aktif.
- Jadwal: hari maksimum satu, beberapa hari, jam batas awal/akhir, tanggal masa lalu, bentrok, reschedule dari tidak cocok menjadi cocok.
- Tutor: terverifikasi siap, pending, berhenti menerima, jadwal tidak cocok, salah mapel/jenjang/mode, pengganti, sebagian program siap.
- Harga: harga tetap pada transaksi lunas; subtotal = tarif sah x durasi x sesi x peserta; diskon sesuai konfigurasi; jumlah alokasi tutor dan komisi mengikuti dasar yang sama. Pembulatan total harus tetap seimbang.
- Pembayaran: setiap channel aktif, success/pending/fail/expire, retry, duplikat, timeout ambigu, callback tidak sah, return URL tidak membuktikan lunas.
- Akun: pemilik, teman yang disetujui/ditolak, akun luar, admin biasa/utama, tamu; fresh login versus sesi tersimpan.
- Device: mobile kecil, mobile normal, tablet, desktop; desktop mega menu tetap terpisah, mobile drawer sesuai jumlah konten.
- Kombinasi wajib penuh: program + dua jam + multi tutor + refund sebagian; privat multi-peserta + join + ganti jadwal + konfirmasi; pembayaran retry + webhook ganda + renewal; pencairan sebagian + dua request bersamaan + callback ambigu.

## Invarian yang diperiksa pada setiap transisi uang/kelas

1. Satu konfirmasi transaksi tidak menghasilkan lebih dari satu aktivasi/ledger.
2. Dana refund tidak melebihi hak refund dan tidak dibayar lagi lewat retry.
3. Sesi belum disahkan/bersengketa tidak boleh menjadi saldo tersedia.
4. Saldo tersedia, dicadangkan, dibayar, dan potongan dapat direkonsiliasi; pencairan pending tidak dianggap gagal hanya karena timeout.
5. Matching sebelum seluruh tutor siap tidak membuat program aktif sebagian.
6. Online tidak terkena radius; pengubahan jadwal tidak menambah mapel/sesi/nominal secara tersembunyi.
7. Peserta teman tidak menjadi pemilik transaksi/konfirmasi hanya karena punya akses kelas.
8. Status murid, tutor, admin, dan provider memiliki sumber kebenaran yang konsisten; tidak diukur dari warna/tulisan UI saja.
9. Perubahan katalog/harga/tutor tidak merusak snapshot transaksi dan sesi yang sudah selesai.
10. Semua keadaan terminal maupun pending punya penjelasan dan tindakan berikutnya yang sah.

## Catatan eksekusi per skenario

Untuk setiap skenario yang mulai dijalankan, catat blok berikut di laporan tahap terkait. Jangan memasukkan secret key, token webhook, password nyata, KTP/selfie, nomor rekening, atau data kontak pengguna sebenarnya.

```text
Run ID / waktu / commit atau dirty snapshot:
ID skenario / metode yang selesai:
Alias akun dan fixture:
Prasyarat serta kode tagihan/paket demo:
Langkah yang dilakukan:
Hasil yang diharapkan:
Hasil aktual / status DB dan provider (jika relevan):
Bukti tes / screenshot aman:
Bug dan skenario terdampak yang harus diulang:
Status akhir (LULUS/GAGAL/TERHALANG/ULANG):
```

Perubahan aplikasi setelah tahap 1 memerlukan pembaruan inventaris dan penandaan ULANG untuk skenario terdampak. Pencairan nyata/embedded HTTPS yang terhalang tidak boleh diubah menjadi LULUS hanya karena mock atau preview visual berhasil.
