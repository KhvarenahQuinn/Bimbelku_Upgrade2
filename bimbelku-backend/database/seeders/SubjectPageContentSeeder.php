<?php

namespace Database\Seeders;

use App\Models\CurriculumSubject;
use App\Models\SubjectPageContent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SubjectPageContentSeeder extends Seeder
{
    public function run(): void
    {
        CurriculumSubject::query()
            ->where('is_active', true)
            ->with(['chapters' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')])
            ->orderBy('id')
            ->each(function (CurriculumSubject $subject): void {
                $content = $this->contentFor($subject);
                $page = SubjectPageContent::query()->firstOrNew(['curriculum_subject_id' => $subject->id]);
                $isNew = ! $page->exists;

                foreach ($content as $key => $value) {
                    if ($isNew || blank($page->getAttribute($key))) {
                        $page->setAttribute($key, $value);
                    }
                }

                if ($isNew) {
                    $page->is_published = true;
                }
                $page->save();
            });
    }

    private function contentFor(CurriculumSubject $subject): array
    {
        $name = $subject->name;
        $levels = collect($subject->education_levels)->filter()->values();
        $levelLabel = $levels->implode(', ') ?: 'Umum';
        $profile = $this->profile($name, (string) $subject->group_name);
        $topics = $this->representativeTopics($subject->chapters->pluck('title'));

        return [
            'hero_intro' => "Pendampingan {$name} disusun dari kebutuhan dan tingkat kemampuan peserta. Tutor membantu memahami {$profile['focus']}, berlatih secara bertahap, dan mengevaluasi bagian yang masih perlu diperkuat.",
            'learning_approach' => "Pembelajaran dimulai dengan memetakan kebutuhan pada {$name}, lalu tutor menyusun urutan materi dan latihan yang sesuai. Peserta dapat memilih bab dari katalog, menyampaikan target khusus, serta memantau catatan belajar setelah kelas berjalan.",
            'facts' => [
                ['label' => 'Jenjang tersedia', 'value' => $levelLabel],
                ['label' => 'Format belajar', 'value' => 'Privat 1–8 peserta'],
                ['label' => 'Mode', 'value' => 'Online atau tatap muka sesuai ketersediaan'],
                ['label' => 'Materi', 'value' => 'Pilih bab dan tulis kebutuhan khusus'],
                ['label' => 'Jumlah sesi', 'value' => 'Dipilih saat menyusun paket'],
                ['label' => 'Harga', 'value' => 'Dihitung dari pilihan sebelum pembayaran'],
                ['label' => 'Pencarian tutor', 'value' => 'Dimulai setelah pembayaran terverifikasi'],
                ['label' => 'Penggantian tutor', 'value' => 'Dapat diajukan sesuai ketentuan layanan'],
            ],
            'learning_map' => $topics->map(function (string $topic, int $index) use ($profile): array {
                $difficulties = [
                    'Konsep awal belum kuat atau istilah pada materi belum dipahami.',
                    'Mampu mengikuti contoh, tetapi belum konsisten saat mengerjakan sendiri.',
                    'Kesulitan memilih langkah atau strategi ketika bentuk soal berubah.',
                    'Belum terbiasa menjelaskan proses dan memeriksa kembali hasil belajar.',
                    'Perlu menggabungkan beberapa konsep untuk tugas, ujian, atau proyek.',
                ];
                $approaches = [
                    'Tutor memeriksa pemahaman awal dan menjelaskan konsep dengan contoh sederhana.',
                    'Contoh dikerjakan bersama, kemudian bantuan dikurangi secara bertahap.',
                    'Peserta membandingkan beberapa strategi dan membahas letak kesalahannya.',
                    'Tutor memberi latihan terarah, umpan balik, dan rangkuman yang dapat ditinjau kembali.',
                    "Materi diterapkan pada latihan campuran atau target nyata sesuai {$profile['practice']}.",
                ];

                return [
                    'stage' => $topic,
                    'coverage' => "Konsep inti, contoh, dan latihan pada {$topic} disesuaikan dengan jenjang serta target peserta.",
                    'difficulty' => $difficulties[$index] ?? end($difficulties),
                    'approach' => $approaches[$index] ?? end($approaches),
                ];
            })->all(),
            'learning_journey' => [
                ['period' => 'Sebelum sesi pertama', 'title' => 'Pemetaan kebutuhan', 'description' => "Peserta memilih bab {$name}, menuliskan target, dan menyampaikan bagian yang terasa sulit."],
                ['period' => 'Sesi pertama', 'title' => 'Cek kemampuan awal', 'description' => 'Tutor mengonfirmasi tujuan dan melihat cara peserta memahami atau menyelesaikan materi.'],
                ['period' => 'Sesi berikutnya', 'title' => 'Belajar dan latihan bertahap', 'description' => "Konsep, latihan, dan {$profile['practice']} dipelajari sesuai ritme peserta."],
                ['period' => 'Setiap selesai kelas', 'title' => 'Catatan perkembangan', 'description' => 'Aktivitas, materi, dan catatan sesi tersimpan pada progress belajar.'],
                ['period' => 'Akhir paket', 'title' => 'Tinjau hasil dan langkah lanjut', 'description' => 'Peserta meninjau kemajuan bersama tutor dan menentukan materi yang masih perlu dilanjutkan.'],
            ],
            'benefits' => [
                'Tujuan belajar dan bab yang dipilih tercatat sejak pemesanan.',
                'Penjelasan serta latihan dapat disesuaikan dengan tingkat kemampuan peserta.',
                'Jadwal, mode belajar, durasi, dan jumlah sesi dipilih sebelum membayar.',
                'Catatan sesi membantu peserta melihat materi yang sudah dan belum dikuasai.',
                'Peserta dapat mengajukan penggantian tutor melalui alur yang tersedia bila diperlukan.',
            ],
            'suitable_for' => [
                "Peserta yang ingin memperkuat dasar {$name}.",
                'Peserta yang mempunyai bab atau target belajar tertentu.',
                'Peserta yang membutuhkan ruang bertanya lebih banyak daripada di kelas besar.',
                'Peserta yang ingin belajar sendiri atau bersama kelompok kecil hingga delapan orang.',
                "Peserta yang sedang menyiapkan tugas, asesmen, ujian, proyek, atau kemampuan praktis terkait {$name}.",
            ],
            'reasons' => [
                ['title' => 'Dimulai dari kebutuhan nyata', 'description' => 'Mapel, bab, target, tingkat kemampuan, dan catatan tambahan diisi sebelum pencarian tutor.'],
                ['title' => 'Tutor melalui pemeriksaan', 'description' => 'Tutor harus melengkapi dokumen, mengikuti tes melalui WhatsApp, dan diaktifkan admin sebelum menerima kelas.'],
                ['title' => 'Harga terlihat sebelum membayar', 'description' => 'Sistem menghitung total dari pilihan peserta, durasi, jumlah sesi, mode belajar, dan pengaturan harga yang aktif.'],
                ['title' => 'Matching dijelaskan sejak awal', 'description' => 'Pencarian tutor baru berjalan setelah pembayaran terverifikasi, dan pilihan penanganan ditampilkan bila tutor belum ditemukan.'],
                ['title' => 'Perkembangan tidak hilang', 'description' => 'Jadwal, kehadiran, materi, dan catatan sesi terhubung dengan akun peserta.'],
            ],
            'articles' => [
                ['title' => "Apa yang dipelajari dalam {$name}?", 'body' => "Isi belajar mengikuti jenjang, bab yang dipilih, dan tujuan peserta. Pada awal pendampingan, tutor memeriksa pemahaman terhadap {$profile['focus']}. Setelah itu, materi dipelajari melalui penjelasan, contoh, latihan, dan {$profile['practice']} yang relevan. Daftar bab aktif tetap menjadi acuan utama; peserta juga dapat menuliskan kebutuhan yang lebih spesifik saat memesan."],
                ['title' => 'Bagaimana satu sesi berjalan?', 'body' => 'Tutor membuka sesi dengan meninjau target dan kesulitan terakhir. Bagian inti dipakai untuk membahas konsep serta latihan, lalu sesi ditutup dengan rangkuman dan catatan perkembangan. Susunan ini dapat berubah mengikuti kebutuhan peserta; sistem tidak menjanjikan hasil instan atau nilai tertentu.'],
                ['title' => 'Kesalahan belajar yang sering menghambat', 'body' => "Hambatan tidak selalu berasal dari kurangnya latihan. Peserta dapat menghafal langkah tanpa memahami alasan, melewatkan konsep dasar, atau tidak memeriksa kembali pekerjaannya. Karena itu, pendampingan {$name} diarahkan untuk menemukan letak kesulitan dan memilih latihan yang sesuai, bukan sekadar menambah jumlah soal."],
            ],
            'faqs' => $this->faqs($name),
            'source_note' => $subject->source_url
                ? "Katalog materi {$subject->curriculum_name} {$subject->edition}; sumber: {$subject->source_url}. Informasi layanan mengikuti fungsi BimbelKu yang aktif."
                : 'Katalog materi internal BimbelKu. Informasi layanan mengikuti fungsi BimbelKu yang aktif dan dapat diperbarui admin.',
            'reviewed_at' => now()->toDateString(),
        ];
    }

    private function representativeTopics(Collection $topics): Collection
    {
        $unique = $topics->map(fn ($topic) => trim((string) $topic))->filter()->unique()->values();
        if ($unique->count() <= 5) {
            return $unique;
        }

        $last = $unique->count() - 1;

        return collect([0, (int) round($last * .25), (int) round($last * .5), (int) round($last * .75), $last])
            ->map(fn (int $index) => $unique[$index])->unique()->values();
    }

    private function profile(string $name, string $group): array
    {
        $normalized = Str::lower($name.' '.$group);

        return match (true) {
            str_contains($normalized, 'matematika'), str_contains($normalized, 'fisika'), str_contains($normalized, 'kimia') => ['focus' => 'konsep, hubungan antarbesaran, dan langkah pemecahan masalah', 'practice' => 'latihan soal dari dasar sampai penerapan'],
            str_contains($normalized, 'biologi'), str_contains($normalized, 'alam') => ['focus' => 'konsep ilmiah, proses, bukti, dan keterkaitan antarsistem', 'practice' => 'analisis gambar, data, kasus, dan soal konsep'],
            str_contains($normalized, 'bahasa') => ['focus' => 'kosakata, struktur, pemahaman teks, serta komunikasi lisan dan tulisan', 'practice' => 'membaca, menyimak, menulis, dan percakapan terarah'],
            str_contains($normalized, 'sejarah'), str_contains($normalized, 'sosiologi'), str_contains($normalized, 'antropologi'), str_contains($normalized, 'geografi'), str_contains($normalized, 'sosial'), str_contains($normalized, 'pancasila') => ['focus' => 'konsep, konteks, hubungan sebab-akibat, dan cara menyusun argumen', 'practice' => 'membaca sumber, menganalisis kasus, dan menulis jawaban'],
            str_contains($normalized, 'ekonomi'), str_contains($normalized, 'akuntansi'), str_contains($normalized, 'manajemen') => ['focus' => 'konsep, perhitungan, pencatatan, dan pengambilan keputusan', 'practice' => 'latihan kasus, perhitungan, dan proyek sederhana'],
            str_contains($normalized, 'informatika'), str_contains($normalized, 'koding'), str_contains($normalized, 'pemrograman'), str_contains($normalized, 'komputer'), str_contains($normalized, 'microsoft') => ['focus' => 'logika, penggunaan alat digital, keamanan, dan penyelesaian masalah', 'practice' => 'demonstrasi, latihan langsung, dan proyek kecil'],
            str_contains($normalized, 'seni'), str_contains($normalized, 'desain'), str_contains($normalized, 'fotografi'), str_contains($normalized, 'gitar'), str_contains($normalized, 'teater'), str_contains($normalized, 'tari') => ['focus' => 'teknik dasar, kepekaan, proses kreatif, dan evaluasi karya', 'practice' => 'demonstrasi teknik, latihan terarah, dan pembuatan karya'],
            str_contains($normalized, 'prakarya'), str_contains($normalized, 'budi daya'), str_contains($normalized, 'kerajinan'), str_contains($normalized, 'pengolahan'), str_contains($normalized, 'rekayasa') => ['focus' => 'perencanaan, teknik, proses produksi, keselamatan, dan evaluasi hasil', 'practice' => 'observasi, perancangan, pembuatan, dan evaluasi produk'],
            str_contains($normalized, 'agama'), str_contains($normalized, 'mengaji'), str_contains($normalized, 'tahsin') => ['focus' => 'pemahaman ajaran, bacaan atau praktik, nilai, dan penerapan dalam kehidupan', 'practice' => 'membaca, berdiskusi, berlatih, dan merefleksikan penerapan'],
            str_contains($normalized, 'tes'), str_contains($normalized, 'utbk'), str_contains($normalized, 'cpns'), str_contains($normalized, 'pppk') => ['focus' => 'kompetensi yang diujikan, strategi pengerjaan, ketepatan, dan pengelolaan waktu', 'practice' => 'latihan bertahap, pembahasan kesalahan, dan simulasi'],
            str_contains($normalized, 'public speaking') => ['focus' => 'struktur pesan, artikulasi, bahasa tubuh, dan kepercayaan diri', 'practice' => 'latihan berbicara, rekaman, dan umpan balik'],
            default => ['focus' => 'konsep inti, keterampilan dasar, penerapan, dan evaluasi', 'practice' => 'contoh, latihan terarah, diskusi, dan tugas praktis'],
        };
    }

    private function faqs(string $name): array
    {
        return [
            ['question' => "Apakah materi Les {$name} dapat dipilih sendiri?", 'answer' => 'Ya. Pilih bab yang tersedia dan tuliskan target atau kesulitan khusus saat menyusun paket.'],
            ['question' => 'Apakah bisa belajar online dan tatap muka?', 'answer' => 'Bisa. Pilihan aktual mengikuti mode yang didukung tutor, jadwal, alamat, dan area layanan pada saat matching.'],
            ['question' => 'Apakah dapat memesan tanpa login?', 'answer' => 'Bisa. Isi identitas dan email saat memesan. Setelah pesanan tersimpan, masuk atau daftar dengan email yang sama untuk menghubungkan pesanan dan melanjutkan pembayaran.'],
            ['question' => 'Kapan pencarian tutor dimulai?', 'answer' => 'Matching tutor dimulai setelah pembayaran dikirim dan diverifikasi sesuai alur yang ditampilkan pada pesanan.'],
            ['question' => 'Apakah saya dapat memilih tutor tertentu?', 'answer' => 'Sistem mencocokkan tutor yang aktif berdasarkan mapel, jenjang, mode, jadwal, dan area. Tutor tidak dipilih secara acak maupun hanya berdasarkan rating tertinggi.'],
            ['question' => 'Bagaimana jika tutor belum ditemukan?', 'answer' => 'Pilihan yang tersedia dapat meliputi mengubah jadwal, beralih ke online, memperluas pencarian, atau mengajukan refund sesuai status dan ketentuan pesanan.'],
            ['question' => 'Apakah boleh belajar bersama teman?', 'answer' => 'Boleh. Privat mendukung 1–8 peserta. Harga bertingkat hanya aktif untuk jumlah peserta yang sudah mempunyai pengaturan harga dari admin.'],
            ['question' => 'Berapa jumlah sesi yang harus dipilih?', 'answer' => 'Jumlah sesi mengikuti paket yang aktif. Rincian masa berlaku dan batas mapel terlihat sebelum melanjutkan pemesanan.'],
            ['question' => 'Apakah tutor dapat diganti?', 'answer' => 'Penggantian tutor dapat diajukan dari kelas aktif dan akan diproses sesuai alasan, sisa sesi, serta ketentuan layanan.'],
            ['question' => 'Apakah nilai atau hasil tertentu dijamin?', 'answer' => 'Tidak. BimbelKu menyediakan pendampingan dan pencatatan progress, tetapi hasil tetap dipengaruhi kehadiran, latihan, kondisi peserta, dan faktor lain.'],
        ];
    }
}
