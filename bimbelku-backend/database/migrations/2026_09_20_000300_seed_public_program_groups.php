<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('programs') || DB::table('programs')->exists()) {
            return;
        }

        $definitions = [
            ['name' => 'Pelajaran Sekolah', 'description' => 'Mata pelajaran inti dan pilihan untuk kebutuhan belajar SD, SMP, dan SMA.', 'groups' => ['Wajib', 'Wajib/Pilihan', 'Lokal', 'Layanan Inklusif'], 'sort' => 10],
            ['name' => 'Sains & Sosial', 'description' => 'IPA, IPS, dan mata pelajaran peminatan untuk penguatan konsep sekolah.', 'groups' => ['IPA/Pilihan', 'IPS/Pilihan'], 'sort' => 20],
            ['name' => 'Bahasa', 'description' => 'Bahasa sekolah dan bahasa pilihan untuk kebutuhan akademik maupun umum.', 'groups' => ['Bahasa', 'Bahasa Pilihan'], 'sort' => 30],
            ['name' => 'Persiapan Ujian', 'description' => 'Seluruh program persiapan tes dan ujian berada dalam satu kelompok khusus.', 'groups' => ['Persiapan Tes'], 'sort' => 40],
            ['name' => 'Seni & Alat Musik', 'description' => 'Seni pertunjukan, seni visual, dan pembelajaran alat musik.', 'groups' => ['Seni', 'Musik'], 'sort' => 50],
            ['name' => 'Teknologi & Keterampilan', 'description' => 'Komputer, pemrograman, desain, komunikasi, dan keterampilan praktis.', 'groups' => ['Teknologi', 'Keterampilan', 'Prakarya', 'Pilihan', 'Pilihan SMA'], 'sort' => 60],
            ['name' => 'Bisnis', 'description' => 'Program bisnis dan pengelolaan untuk kebutuhan belajar umum.', 'groups' => ['Bisnis'], 'sort' => 70],
            ['name' => 'Keagamaan', 'description' => 'Pendidikan agama, mengaji, dan pendampingan materi keagamaan.', 'groups' => ['Agama', 'Keagamaan'], 'sort' => 80],
        ];

        $now = now();
        foreach ($definitions as $definition) {
            $programId = DB::table('programs')->insertGetId([
                'name' => $definition['name'],
                'slug' => Str::slug($definition['name']),
                'description' => $definition['description'],
                'allow_multi_mapel' => true,
                'is_active' => true,
                'sort_order' => $definition['sort'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $subjectIds = DB::table('curriculum_subjects')
                ->where('is_active', true)
                ->whereIn('group_name', $definition['groups'])
                ->pluck('id');

            foreach ($subjectIds as $subjectId) {
                DB::table('program_curriculum_subject')->insertOrIgnore([
                    'program_id' => $programId,
                    'curriculum_subject_id' => $subjectId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $slugs = collect(['Pelajaran Sekolah', 'Sains & Sosial', 'Bahasa', 'Persiapan Ujian', 'Seni & Alat Musik', 'Teknologi & Keterampilan', 'Bisnis', 'Keagamaan'])->map(fn ($name) => Str::slug($name));
        DB::table('programs')->whereIn('slug', $slugs)->delete();
    }
};
