<?php

namespace Database\Seeders;

use App\Models\CurriculumChapter;
use App\Models\CurriculumSubject;
use App\Support\EducationCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CurriculumCatalogSeeder extends Seeder
{
    private const SOURCE = 'https://buku.kemendikdasmen.go.id/';

    public function run(): void
    {
        foreach ($this->subjects() as $definition) {
            $grades = array_map(fn (int $grade) => "Kelas {$grade}", $definition['grades']);
            $levels = collect($definition['grades'])
                ->map(fn (int $grade) => $grade <= 6 ? 'SD' : ($grade <= 9 ? 'SMP' : 'SMA'))
                ->unique()
                ->values()
                ->all();
            $normalizedName = $this->normalize($definition['name']);

            $subject = CurriculumSubject::updateOrCreate(
                ['normalized_name' => $normalizedName],
                [
                    'name' => $definition['name'],
                    'group_name' => $definition['group'],
                    'education_levels' => $levels,
                    'grades' => $grades,
                    'is_elective' => $definition['elective'] ?? false,
                    'is_active' => true,
                    'curriculum_name' => 'Kurikulum Merdeka',
                    'edition' => '2025/2026',
                    'source_url' => self::SOURCE,
                ]
            );

            foreach ($definition['grades'] as $grade) {
                $level = $grade <= 6 ? 'SD' : ($grade <= 9 ? 'SMP' : 'SMA');
                foreach ($this->chaptersFor($definition['name'], $grade) as $index => $title) {
                    $chapter = CurriculumChapter::updateOrCreate(
                        [
                            'curriculum_subject_id' => $subject->id,
                            'grade' => "Kelas {$grade}",
                            'normalized_title' => $this->normalize($title),
                        ],
                        [
                            'education_level' => $level,
                            'title' => $title,
                            'sort_order' => $index + 1,
                            'is_active' => true,
                            'source_reference' => $this->hasExactBookChapters($definition['name'], $grade)
                                ? 'Judul bab buku siswa SIBI Kurikulum Merdeka, edisi aktif 2025/2026'
                                : 'Struktur materi awal Kurikulum Merdeka; admin dapat menyesuaikan judul menurut buku sekolah',
                        ]
                    );
                }
            }
        }

        $this->seedFlexibleSubjects();
    }

    private function subjects(): array
    {
        $all = range(1, 12);

        return [
            ['name' => 'Pendidikan Agama Islam dan Budi Pekerti', 'group' => 'Agama', 'grades' => $all],
            ['name' => 'Pendidikan Agama Kristen dan Budi Pekerti', 'group' => 'Agama', 'grades' => $all],
            ['name' => 'Pendidikan Agama Katolik dan Budi Pekerti', 'group' => 'Agama', 'grades' => $all],
            ['name' => 'Pendidikan Agama Hindu dan Budi Pekerti', 'group' => 'Agama', 'grades' => $all],
            ['name' => 'Pendidikan Agama Buddha dan Budi Pekerti', 'group' => 'Agama', 'grades' => $all],
            ['name' => 'Pendidikan Agama Khonghucu dan Budi Pekerti', 'group' => 'Agama', 'grades' => $all],
            ['name' => 'Pendidikan Pancasila', 'group' => 'Wajib', 'grades' => $all],
            ['name' => 'Bahasa Indonesia', 'group' => 'Wajib', 'grades' => $all],
            ['name' => 'Matematika', 'group' => 'Wajib', 'grades' => $all],
            ['name' => 'Pendidikan Jasmani, Olahraga, dan Kesehatan', 'group' => 'Wajib', 'grades' => $all],
            ['name' => 'Seni Musik', 'group' => 'Seni', 'grades' => $all, 'elective' => true],
            ['name' => 'Seni Rupa', 'group' => 'Seni', 'grades' => $all, 'elective' => true],
            ['name' => 'Seni Teater', 'group' => 'Seni', 'grades' => $all, 'elective' => true],
            ['name' => 'Seni Tari', 'group' => 'Seni', 'grades' => $all, 'elective' => true],
            ['name' => 'Muatan Lokal', 'group' => 'Lokal', 'grades' => $all, 'elective' => true],
            ['name' => 'Program Kebutuhan Khusus', 'group' => 'Layanan Inklusif', 'grades' => $all, 'elective' => true],
            ['name' => 'Ilmu Pengetahuan Alam dan Sosial', 'group' => 'Wajib', 'grades' => range(3, 6)],
            ['name' => 'Bahasa Inggris', 'group' => 'Wajib/Pilihan', 'grades' => range(3, 12)],
            ['name' => 'Koding dan Kecerdasan Artifisial', 'group' => 'Pilihan', 'grades' => range(5, 12), 'elective' => true],
            ['name' => 'Ilmu Pengetahuan Alam', 'group' => 'Wajib', 'grades' => range(7, 10)],
            ['name' => 'Ilmu Pengetahuan Sosial', 'group' => 'Wajib', 'grades' => range(7, 10)],
            ['name' => 'Informatika', 'group' => 'Wajib/Pilihan', 'grades' => range(7, 12)],
            ['name' => 'Prakarya Budi Daya', 'group' => 'Prakarya', 'grades' => range(7, 10), 'elective' => true],
            ['name' => 'Prakarya Kerajinan', 'group' => 'Prakarya', 'grades' => range(7, 10), 'elective' => true],
            ['name' => 'Prakarya Rekayasa', 'group' => 'Prakarya', 'grades' => range(7, 10), 'elective' => true],
            ['name' => 'Prakarya Pengolahan', 'group' => 'Prakarya', 'grades' => range(7, 10), 'elective' => true],
            ['name' => 'Fisika', 'group' => 'IPA/Pilihan', 'grades' => range(10, 12), 'elective' => true],
            ['name' => 'Kimia', 'group' => 'IPA/Pilihan', 'grades' => range(10, 12), 'elective' => true],
            ['name' => 'Biologi', 'group' => 'IPA/Pilihan', 'grades' => range(10, 12), 'elective' => true],
            ['name' => 'Ekonomi', 'group' => 'IPS/Pilihan', 'grades' => range(10, 12), 'elective' => true],
            ['name' => 'Sosiologi', 'group' => 'IPS/Pilihan', 'grades' => range(10, 12), 'elective' => true],
            ['name' => 'Geografi', 'group' => 'IPS/Pilihan', 'grades' => range(10, 12), 'elective' => true],
            ['name' => 'Sejarah', 'group' => 'Wajib', 'grades' => range(10, 12)],
            ['name' => 'Matematika Tingkat Lanjut', 'group' => 'Pilihan SMA', 'grades' => range(11, 12), 'elective' => true],
            ['name' => 'Sejarah Tingkat Lanjut', 'group' => 'Pilihan SMA', 'grades' => range(11, 12), 'elective' => true],
            ['name' => 'Bahasa Indonesia Tingkat Lanjut', 'group' => 'Pilihan SMA', 'grades' => range(11, 12), 'elective' => true],
            ['name' => 'Bahasa Inggris Tingkat Lanjut', 'group' => 'Pilihan SMA', 'grades' => range(11, 12), 'elective' => true],
            ['name' => 'Antropologi', 'group' => 'Pilihan SMA', 'grades' => range(11, 12), 'elective' => true],
            ['name' => 'Bahasa Arab', 'group' => 'Bahasa Pilihan', 'grades' => range(11, 12), 'elective' => true],
            ['name' => 'Bahasa Jepang', 'group' => 'Bahasa Pilihan', 'grades' => range(11, 12), 'elective' => true],
            ['name' => 'Bahasa Jerman', 'group' => 'Bahasa Pilihan', 'grades' => range(11, 12), 'elective' => true],
            ['name' => 'Bahasa Korea', 'group' => 'Bahasa Pilihan', 'grades' => range(11, 12), 'elective' => true],
            ['name' => 'Bahasa Mandarin', 'group' => 'Bahasa Pilihan', 'grades' => range(11, 12), 'elective' => true],
            ['name' => 'Bahasa Prancis', 'group' => 'Bahasa Pilihan', 'grades' => range(11, 12), 'elective' => true],
            ['name' => 'Prakarya dan Kewirausahaan Budi Daya', 'group' => 'Pilihan SMA', 'grades' => range(11, 12), 'elective' => true],
            ['name' => 'Prakarya dan Kewirausahaan Kerajinan', 'group' => 'Pilihan SMA', 'grades' => range(11, 12), 'elective' => true],
            ['name' => 'Prakarya dan Kewirausahaan Rekayasa', 'group' => 'Pilihan SMA', 'grades' => range(11, 12), 'elective' => true],
            ['name' => 'Prakarya dan Kewirausahaan Pengolahan', 'group' => 'Pilihan SMA', 'grades' => range(11, 12), 'elective' => true],
        ];
    }

    private function seedFlexibleSubjects(): void
    {
        foreach ($this->flexibleSubjects() as $definition) {
            $normalizedName = $this->normalize($definition['name']);
            $subject = CurriculumSubject::query()
                ->where('normalized_name', $normalizedName)
                ->first();
            $isNew = ! $subject;
            $subject ??= new CurriculumSubject([
                'normalized_name' => $normalizedName,
            ]);

            $levels = array_values(array_unique([
                ...($subject->education_levels ?? []),
                ...$definition['levels'],
            ]));
            $grades = array_values(array_unique([
                ...($subject->grades ?? []),
                ...EducationCatalog::gradesForLevels($definition['levels']),
            ]));

            $subject->fill([
                'name' => $subject->name ?: $definition['name'],
                'group_name' => $isNew ? $definition['group'] : $subject->group_name,
                'education_levels' => $levels,
                'grades' => $grades,
                'is_elective' => $isNew ? true : $subject->is_elective,
                'is_active' => true,
                'curriculum_name' => $isNew ? 'Katalog Fleksibel BimbelKu' : $subject->curriculum_name,
                'edition' => $subject->edition ?: '2026',
                'source_url' => $subject->source_url,
            ]);
            $subject->save();

            foreach ($definition['levels'] as $level) {
                foreach (EducationCatalog::GRADES_BY_LEVEL[$level] as $grade) {
                    foreach ($this->flexibleChapters($definition['name'], $level) as $index => $title) {
                        $chapter = CurriculumChapter::updateOrCreate(
                            [
                                'curriculum_subject_id' => $subject->id,
                                'grade' => $grade,
                                'normalized_title' => $this->normalize($title),
                            ],
                            [
                                'education_level' => $level,
                                'title' => $title,
                                'sort_order' => $index + 1,
                                'is_active' => true,
                                'source_reference' => 'Materi awal fleksibel; admin dapat menyesuaikan isi menurut kebutuhan murid.',
                            ]
                        );
                    }
                }
            }
        }
    }

    private function flexibleSubjects(): array
    {
        return [
            ['name' => 'Akuntansi', 'group' => 'Bisnis', 'levels' => ['Umum']],
            ['name' => 'Manajemen', 'group' => 'Bisnis', 'levels' => ['Umum']],
            ['name' => 'Pemrograman', 'group' => 'Teknologi', 'levels' => ['Umum']],
            ['name' => 'Bahasa Inggris', 'group' => 'Bahasa', 'levels' => ['Umum']],
            ['name' => 'Bahasa Arab', 'group' => 'Bahasa', 'levels' => ['Umum']],
            ['name' => 'Bahasa Jepang', 'group' => 'Bahasa', 'levels' => ['Umum']],
            ['name' => 'Bahasa Korea', 'group' => 'Bahasa', 'levels' => ['Umum']],
            ['name' => 'Bahasa Mandarin', 'group' => 'Bahasa', 'levels' => ['Umum']],
            ['name' => 'Bahasa Prancis', 'group' => 'Bahasa', 'levels' => ['Umum']],
            ['name' => 'Komputer Dasar', 'group' => 'Teknologi', 'levels' => ['Umum']],
            ['name' => 'Microsoft Office', 'group' => 'Teknologi', 'levels' => ['Umum']],
            ['name' => 'Desain Grafis', 'group' => 'Keterampilan', 'levels' => ['Umum']],
            ['name' => 'Public Speaking', 'group' => 'Keterampilan', 'levels' => ['Umum']],
            ['name' => 'Mengaji dan Tahsin', 'group' => 'Keagamaan', 'levels' => ['Umum']],
            ['name' => 'Gitar', 'group' => 'Musik', 'levels' => ['Umum']],
            ['name' => 'Persiapan Tes CPNS dan PPPK', 'group' => 'Persiapan Tes', 'levels' => ['Umum']],
            ['name' => 'Persiapan UTBK', 'group' => 'Persiapan Tes', 'levels' => ['Umum']],
            ['name' => 'Fotografi Dasar', 'group' => 'Keterampilan', 'levels' => ['Umum']],
        ];
    }

    private function flexibleChapters(string $subject, string $level): array
    {
        $specific = [
            'Komputer Dasar' => ['Pengenalan Perangkat', 'Sistem Operasi', 'Internet dan Keamanan', 'Pengelolaan Berkas', 'Latihan Praktis'],
            'Microsoft Office' => ['Microsoft Word', 'Microsoft Excel', 'Microsoft PowerPoint', 'Kolaborasi Dokumen', 'Proyek Praktis'],
            'Desain Grafis' => ['Prinsip Desain', 'Warna dan Tipografi', 'Penggunaan Aplikasi', 'Pembuatan Karya', 'Evaluasi Portofolio'],
            'Public Speaking' => ['Struktur Materi', 'Suara dan Artikulasi', 'Bahasa Tubuh', 'Mengelola Gugup', 'Praktik Presentasi'],
            'Mengaji dan Tahsin' => ['Makharijul Huruf', 'Sifat Huruf', 'Hukum Tajwid', 'Kelancaran Bacaan', 'Praktik dan Evaluasi'],
            'Gitar' => ['Pengenalan Instrumen', 'Chord Dasar', 'Ritme dan Strumming', 'Melodi', 'Praktik Lagu'],
            'Persiapan Tes CPNS dan PPPK' => ['Seleksi Kompetensi Dasar', 'Kompetensi Teknis', 'Manajerial', 'Sosio-Kultural', 'Simulasi dan Evaluasi'],
            'Persiapan UTBK' => ['Tes Potensi Skolastik', 'Literasi Bahasa Indonesia', 'Literasi Bahasa Inggris', 'Penalaran Matematika', 'Simulasi dan Evaluasi'],
            'Fotografi Dasar' => ['Pengenalan Kamera', 'Eksposur', 'Komposisi', 'Pencahayaan', 'Praktik dan Evaluasi'],
        ];

        if (isset($specific[$subject])) {
            return $specific[$subject];
        }

        return ['Pengenalan dan Tujuan', 'Dasar-Dasar', 'Latihan Terarah', 'Penerapan Praktis', 'Evaluasi Kemampuan'];
    }

    private function chaptersFor(string $subject, int $grade): array
    {
        $exact = $this->exactChapters();
        if (isset($exact[$subject][$grade])) {
            return $exact[$subject][$grade];
        }

        $themes = [
            'Pendidikan Pancasila' => ['Pancasila dan Nilai Kehidupan', 'Konstitusi dan Norma', 'Bhinneka Tunggal Ika', 'Negara Kesatuan Republik Indonesia', 'Gotong Royong dan Kewargaan'],
            'Pendidikan Jasmani, Olahraga, dan Kesehatan' => ['Pola Gerak dan Kebugaran', 'Permainan dan Olahraga', 'Senam dan Aktivitas Ritmik', 'Keselamatan dan Kesehatan', 'Gaya Hidup Aktif'],
            'Seni Musik' => ['Unsur Musik', 'Bernyanyi dan Bermain Musik', 'Kreasi Musik', 'Apresiasi Karya Musik'],
            'Seni Rupa' => ['Unsur dan Prinsip Rupa', 'Menggambar dan Membentuk', 'Kreasi Media Rupa', 'Apresiasi Karya Rupa'],
            'Seni Teater' => ['Tubuh, Suara, dan Ekspresi', 'Tokoh dan Cerita', 'Pementasan', 'Apresiasi Teater'],
            'Seni Tari' => ['Unsur Gerak Tari', 'Ruang, Waktu, dan Tenaga', 'Kreasi Tari', 'Apresiasi Pertunjukan'],
            'Muatan Lokal' => ['Potensi dan Identitas Daerah', 'Bahasa dan Tradisi Lokal', 'Seni dan Keterampilan Lokal', 'Lingkungan serta Kearifan Lokal'],
            'Program Kebutuhan Khusus' => ['Orientasi dan Mobilitas', 'Komunikasi dan Interaksi', 'Kemandirian', 'Teknologi Bantu dan Advokasi Diri'],
            'Koding dan Kecerdasan Artifisial' => ['Berpikir Komputasional', 'Algoritma dan Pemrograman', 'Data dan Representasi', 'Kecerdasan Artifisial', 'Etika serta Keamanan Digital'],
            'Informatika' => ['Berpikir Komputasional', 'Sistem Komputer', 'Jaringan dan Internet', 'Analisis Data', 'Algoritma dan Pemrograman', 'Dampak Sosial Informatika'],
            'Prakarya Budi Daya' => ['Potensi Budi Daya', 'Perencanaan Produksi', 'Proses Budi Daya', 'Pengemasan dan Evaluasi'],
            'Prakarya Kerajinan' => ['Bahan dan Teknik Kerajinan', 'Perancangan Produk', 'Produksi Kerajinan', 'Kemasan dan Evaluasi'],
            'Prakarya Rekayasa' => ['Sistem dan Teknologi Rekayasa', 'Perancangan Produk', 'Pembuatan Prototipe', 'Pengujian dan Evaluasi'],
            'Prakarya Pengolahan' => ['Bahan Pangan dan Nonpangan', 'Perencanaan Produk', 'Proses Pengolahan', 'Pengemasan dan Evaluasi'],
            'Matematika Tingkat Lanjut' => ['Aljabar dan Fungsi Lanjut', 'Geometri Analitik', 'Kalkulus', 'Statistika dan Peluang Lanjut'],
            'Sejarah Tingkat Lanjut' => ['Metode Penelitian Sejarah', 'Peradaban Dunia', 'Revolusi dan Perubahan Global', 'Indonesia dalam Sejarah Dunia'],
            'Bahasa Indonesia Tingkat Lanjut' => ['Kajian Teks Akademik', 'Apresiasi Sastra', 'Penulisan Ilmiah', 'Presentasi dan Publikasi'],
            'Bahasa Inggris Tingkat Lanjut' => ['Academic Reading', 'Argumentative Writing', 'Public Speaking', 'Literature and Intercultural Communication'],
            'Antropologi' => ['Konsep Dasar Antropologi', 'Kebudayaan dan Identitas', 'Keragaman Masyarakat', 'Perubahan Sosial Budaya', 'Penelitian Etnografi'],
        ];

        if (str_starts_with($subject, 'Pendidikan Agama')) {
            return ['Keimanan dan Ketakwaan', 'Kitab Suci dan Ajaran', 'Akhlak serta Budi Pekerti', 'Ibadah dan Praktik Kehidupan', 'Sejarah serta Keteladanan'];
        }
        if (str_starts_with($subject, 'Bahasa ') && ! str_contains($subject, 'Indonesia') && ! str_contains($subject, 'Inggris')) {
            return ['Perkenalan dan Identitas', 'Keluarga dan Kehidupan Harian', 'Sekolah dan Lingkungan', 'Kegiatan serta Waktu', 'Budaya dan Komunikasi'];
        }
        if (str_starts_with($subject, 'Prakarya dan Kewirausahaan')) {
            return ['Peluang dan Ide Usaha', 'Perancangan Produk', 'Produksi dan Kendali Mutu', 'Pemasaran', 'Evaluasi Usaha'];
        }

        return $themes[$subject] ?? ['Konsep Dasar', 'Penerapan Konsep', 'Analisis dan Pemecahan Masalah', 'Proyek serta Evaluasi'];
    }

    private function exactChapters(): array
    {
        return [
            'Matematika' => [
                1 => ['Bilangan sampai 20', 'Penjumlahan dan Pengurangan', 'Bentuk di Sekitar Kita', 'Pengukuran', 'Data Sederhana'],
                2 => ['Bilangan sampai 1.000', 'Penjumlahan dan Pengurangan', 'Perkalian dan Pembagian', 'Pecahan Sederhana', 'Pengukuran dan Bangun Datar'],
                3 => ['Bilangan Cacah sampai 10.000', 'Operasi Hitung', 'Kalimat Matematika', 'Pengukuran Panjang dan Berat', 'Pecahan', 'Bangun Datar dan Data'],
                4 => ['Bilangan Cacah Besar', 'Pecahan dan Desimal', 'Pola Gambar dan Bilangan', 'Pengukuran Luas dan Volume', 'Bangun Datar', 'Penyajian Data'],
                5 => ['Bilangan Cacah sampai 1.000.000', 'KPK dan FPB', 'Pecahan', 'Keliling dan Luas', 'Bangun Ruang', 'Rasio dan Data'],
                6 => ['Operasi Bilangan', 'Pecahan dan Desimal', 'Rasio dan Proporsi', 'Lingkaran', 'Bangun Ruang', 'Peluang dan Data'],
                7 => ['Bilangan Bulat dan Rasional', 'Rasio', 'Bentuk Aljabar', 'Persamaan Linear', 'Kesebangunan', 'Data dan Peluang'],
                8 => ['Pola Bilangan', 'Koordinat Kartesius', 'Relasi dan Fungsi', 'Persamaan Garis Lurus', 'Teorema Pythagoras', 'Statistika'],
                9 => ['Bilangan Berpangkat dan Bentuk Akar', 'Persamaan Kuadrat', 'Transformasi Geometri', 'Kesebangunan dan Kekongruenan', 'Bangun Ruang', 'Peluang'],
                10 => ['Eksponen dan Logaritma', 'Barisan dan Deret', 'Vektor', 'Trigonometri', 'Sistem Persamaan Linear', 'Statistika dan Peluang'],
                11 => ['Komposisi Fungsi dan Fungsi Invers', 'Lingkaran', 'Matriks', 'Statistika', 'Peluang Bersyarat'],
                12 => ['Transformasi Fungsi', 'Geometri Analitik', 'Limit dan Turunan', 'Integral', 'Peluang dan Statistika Lanjut'],
            ],
            'Bahasa Indonesia' => [
                1 => ['Bunyi dan Huruf', 'Kata tentang Diri', 'Keluarga dan Teman', 'Lingkungan Sekitar', 'Cerita dan Puisi Anak'],
                2 => ['Mengenal Perasaan', 'Menjaga Kesehatan', 'Berhati-hati di Mana Saja', 'Keluargaku Unik', 'Berteman dalam Keragaman'],
                3 => ['Ayo, Main!', 'Kawan Seiring', 'Pengobar Semangat', 'Senyum di Sekitarku', 'Jelajah Dunia'],
                4 => ['Sudah Besar', 'Di Bawah Atap', 'Lihat Sekitar', 'Meliuk dan Menerjang', 'Bertukar atau Membayar'],
                5 => ['Aku yang Unik', 'Buku Jendela Dunia', 'Ekspresi Diri melalui Hobi', 'Belajar Berwirausaha', 'Menjadi Warga Dunia'],
                6 => ['Bangga Menjadi Anak Indonesia', 'Musisi Indonesia di Pentas Dunia', 'Taman Nasional dan Situs Warisan Dunia', 'Jeda untuk Iklim', 'Anak-Anak yang Mengubah Dunia'],
                7 => ['Jelajah Nusantara', 'Berkelana di Dunia Imajinasi', 'Hal yang Baik bagi Tubuh', 'Aksi Nyata Para Pelindung Bumi', 'Membuka Gerbang Dunia'],
                8 => ['Menulis Teks Laporan Hasil Observasi', 'Membuat Iklan, Slogan, dan Poster', 'Menulis Artikel Ilmiah Populer', 'Mengulas Karya Fiksi', 'Menciptakan Puisi'],
                9 => ['Demi Keluarga', 'Buku-Buku Berbicara', 'Komunikasi Ujung Jari', 'Dari Hobi Menjadi Pundi-Pundi', 'Menuju Laut'],
                10 => ['Mengungkap Fakta Alam secara Objektif', 'Mengungkapkan Kritik lewat Senyuman', 'Menyusuri Nilai dalam Cerita Lintas Zaman', 'Belajar Menjadi Negosiator', 'Memetik Keteladanan dari Biografi'],
                11 => ['Mengenalkan dan Mempromosikan Produk Pangan Lokal', 'Menyajikan Berita Inovasi', 'Menggali Nilai Sejarah Bangsa', 'Menulis Puisi yang Menginspirasi', 'Mengenal Keberagaman Indonesia'],
                12 => ['Mengkritisi Informasi tentang Tokoh', 'Mempresentasikan Ide Kewirausahaan', 'Memahami Isu Kecerdasan Artifisial', 'Menyampaikan Opini tentang Perundungan', 'Mengungkap Kekaguman dalam Narasi'],
            ],
            'Ilmu Pengetahuan Alam dan Sosial' => [
                3 => ['Mari Kenali Hewan di Sekitar', 'Ayo Mengenal Siklus pada Makhluk Hidup', 'Hidup Bersama Alam', 'Berkenalan dengan Energi', 'Aku dan Lingkungan Sekitarku'],
                4 => ['Tumbuhan, Sumber Kehidupan di Bumi', 'Wujud Zat dan Perubahannya', 'Gaya di Sekitar Kita', 'Mengubah Bentuk Energi', 'Cerita tentang Daerahku', 'Indonesia Kaya Budaya'],
                5 => ['Melihat karena Cahaya, Mendengar karena Bunyi', 'Harmoni dalam Ekosistem', 'Magnet, Listrik, dan Teknologi', 'Bumi dan Perubahannya', 'Bangsa Indonesia Kaya', 'Kegiatan Ekonomi'],
                6 => ['Sistem Gerak Manusia', 'Cerita tentang Indonesia Kita', 'Pelesir Keliling Dunia', 'Indonesia dan Masyarakat Dunia', 'Menjelajah Bumi dan Antariksa'],
            ],
            'Ilmu Pengetahuan Alam' => [
                7 => ['Hakikat Ilmu Sains dan Metode Ilmiah', 'Zat dan Perubahannya', 'Suhu, Kalor, dan Pemuaian', 'Gerak dan Gaya', 'Klasifikasi Makhluk Hidup', 'Ekologi dan Keanekaragaman Hayati'],
                8 => ['Pengenalan Sel', 'Struktur dan Fungsi Tubuh Makhluk Hidup', 'Usaha, Energi, dan Pesawat Sederhana', 'Getaran, Gelombang, dan Cahaya', 'Unsur, Senyawa, dan Campuran'],
                9 => ['Pertumbuhan dan Perkembangan', 'Sistem Koordinasi, Reproduksi, dan Homeostasis', 'Tekanan', 'Listrik, Magnet, dan Energi Alternatif', 'Reaksi Kimia dan Dinamikanya'],
                10 => ['Pengukuran dalam Kerja Ilmiah', 'Virus dan Peranannya', 'Kimia Hijau', 'Hukum Dasar Kimia', 'Energi Terbarukan', 'Keanekaragaman Makhluk Hidup dan Ekosistem'],
            ],
            'Ilmu Pengetahuan Sosial' => [
                7 => ['Keluarga Awal Kehidupan', 'Keberagaman Lingkungan Sekitar', 'Potensi Ekonomi Lingkungan', 'Pemberdayaan Masyarakat'],
                8 => ['Kondisi Geografis dan Pelestarian Sumber Daya', 'Kemajemukan Masyarakat Indonesia', 'Nasionalisme dan Jati Diri Bangsa', 'Pembangunan Perekonomian Indonesia'],
                9 => ['Manusia dan Perubahan', 'Perkembangan Ekonomi Digital', 'Tantangan Pembangunan Indonesia', 'Kerja Sama Dunia'],
                10 => ['Manusia, Ruang, dan Lingkungan', 'Sosiologi dalam Masyarakat', 'Kegiatan Ekonomi', 'Metode Penelitian Sosial', 'Sejarah Indonesia'],
            ],
            'Fisika' => [
                10 => ['Besaran dan Pengukuran', 'Vektor', 'Gerak Lurus', 'Energi Terbarukan', 'Pemanasan Global'],
                11 => ['Vektor dan Kinematika', 'Dinamika Gerak', 'Fluida', 'Gelombang dan Bunyi', 'Kalor dan Termodinamika'],
                12 => ['Listrik Statis dan Dinamis', 'Medan Magnet', 'Arus Bolak-Balik', 'Gelombang Elektromagnetik', 'Fisika Modern'],
            ],
            'Kimia' => [
                10 => ['Struktur Atom', 'Sistem Periodik Unsur', 'Ikatan Kimia', 'Hukum Dasar Kimia', 'Kimia Hijau'],
                11 => ['Hidrokarbon', 'Termokimia', 'Kinetika Kimia', 'Kesetimbangan Kimia', 'Asam dan Basa'],
                12 => ['Larutan Penyangga dan Hidrolisis', 'Kelarutan', 'Elektrokimia', 'Kimia Unsur', 'Senyawa Karbon'],
            ],
            'Biologi' => [
                10 => ['Keanekaragaman Hayati', 'Virus', 'Bakteri dan Protista', 'Fungi dan Plantae', 'Animalia dan Ekosistem'],
                11 => ['Sel', 'Sistem Organ Manusia', 'Metabolisme', 'Pembelahan Sel', 'Pertumbuhan dan Perkembangan'],
                12 => ['Genetika dan Pewarisan Sifat', 'Evolusi', 'Bioteknologi', 'Ekologi Lanjut', 'Isu Biologi Kontemporer'],
            ],
            'Ekonomi' => [
                10 => ['Konsep Dasar Ilmu Ekonomi', 'Kegiatan Ekonomi', 'Pasar dan Pembentukan Harga', 'Lembaga Jasa Keuangan', 'Ekonomi Digital'],
                11 => ['Pendapatan Nasional', 'Pertumbuhan dan Pembangunan Ekonomi', 'Ketenagakerjaan', 'Inflasi dan Kebijakan Ekonomi', 'Badan Usaha'],
                12 => ['Akuntansi Dasar', 'Perdagangan Internasional', 'Kerja Sama Ekonomi', 'APBN dan APBD', 'Ekonomi Indonesia'],
            ],
            'Sosiologi' => [
                10 => ['Sosiologi sebagai Ilmu', 'Identitas Diri dan Tindakan Sosial', 'Hubungan Sosial', 'Lembaga Sosial', 'Penelitian Sosial'],
                11 => ['Kelompok Sosial', 'Permasalahan Sosial', 'Konflik dan Integrasi', 'Kesetaraan dalam Perbedaan', 'Pemberdayaan Komunitas'],
                12 => ['Perubahan Sosial', 'Globalisasi dan Masyarakat Digital', 'Ketimpangan Sosial', 'Kearifan Lokal', 'Evaluasi Pemberdayaan'],
            ],
            'Geografi' => [
                10 => ['Pengantar Ilmu Geografi', 'Peta, Penginderaan Jauh, dan SIG', 'Penelitian Geografi', 'Fenomena Geosfer', 'Lingkungan dan Kebencanaan'],
                11 => ['Posisi Strategis Indonesia', 'Keragaman Hayati', 'Kependudukan', 'Mitigasi Bencana', 'Lingkungan Hidup'],
                12 => ['Pembangunan Wilayah', 'Interaksi Desa dan Kota', 'Kerja Sama Antarwilayah', 'Negara Maju dan Berkembang', 'Geografi Regional Dunia'],
            ],
            'Sejarah' => [
                10 => ['Pengantar Ilmu Sejarah', 'Asal-Usul Nenek Moyang', 'Kerajaan Hindu-Buddha', 'Kerajaan Islam', 'Kolonialisme dan Perlawanan'],
                11 => ['Pergerakan Kebangsaan', 'Pendudukan Jepang', 'Proklamasi dan Revolusi', 'Demokrasi Liberal dan Terpimpin', 'Orde Baru'],
                12 => ['Reformasi', 'Indonesia dalam Perang Dingin', 'Dekolonisasi Dunia', 'Organisasi Internasional', 'Isu Sejarah Kontemporer'],
            ],
            'Bahasa Inggris' => [
                3 => ['Greetings and Introductions', 'My Family', 'My School', 'Food and Drinks', 'Daily Activities'],
                4 => ['What Are You Doing?', 'There Are Sixty-Seven English Books', 'My Living Room Is beside the Kitchen', 'Cici Cooks in the Kitchen', 'Where Is My Pencil?'],
                5 => ['What Delicious Bakso!', 'I Want an Ice Cream Cone', 'How Much Is It?', 'I Have a Stomachache', 'What a Nice Skirt!'],
                6 => ['I Studied Last Night', 'We Went to a Museum', 'I Was in Bali Last Week', 'My Unforgettable Holiday', 'Our Environment'],
                7 => ['About Me', 'Culinary and Me', 'Home Sweet Home', 'My School Activities', 'This Is My World'],
                8 => ['Celebrating Independence Day', 'Kindness Begins with Me', 'Love Our World', 'No Littering', 'Embrace Yourself'],
                9 => ['Exploring Fauna of Indonesia', 'Taking Trips', 'Journey to the Fantasy Worlds', 'Upcycling Used Materials', 'Digital Life'],
                10 => ['Great Athletes', 'Sports Events', 'Sports and Health', 'Healthy Foods', 'Graffiti'],
                11 => ['Digital Literacies and My Identities', 'Love Your Environment', 'Healthy Life for a Healthy Future', 'Indonesian Environmental Figures', 'Personal Money Management'],
                12 => ['Narrative and Storytelling', 'Argumentative Text', 'Public Issues', 'Media Literacy', 'Future Study and Career'],
            ],
        ];
    }

    private function hasExactBookChapters(string $subject, int $grade): bool
    {
        return isset($this->exactChapters()[$subject][$grade]);
    }

    private function normalize(string $value): string
    {
        return Str::lower(preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value));
    }
}
