<?php

namespace Database\Seeders;

use App\Models\CurriculumSubject;
use App\Models\HourlyRate;
use App\Models\TeacherAvailability;
use App\Models\TeacherProfile;
use App\Models\TeacherSubject;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CurriculumCatalogSeeder::class);
        $this->call(SubjectPageContentSeeder::class);
        $this->call(StageFiveExperienceSeeder::class);
        $this->call(AdminSeeder::class);

        if (! filter_var(env('SEED_DEMO_USERS', false), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $teacher = User::updateOrCreate(
            ['email' => 'budi@guru.com'],
            [
                'name' => 'Budi Santoso',
                'phone' => '081234567890',
                'password' => Hash::make('password123'),
                'password_updated_at' => now(),
                'role' => 'teacher',
                'status' => 'active',
                'email_verified_at' => now(),
                'terms_accepted_at' => now(),
                'privacy_accepted_at' => now(),
                'policy_version' => config('app.policy_version'),
            ]
        );

        $profile = TeacherProfile::updateOrCreate(
            ['user_id' => $teacher->id],
            [
                'title' => 'Tutor Matematika',
                'bio' => 'Berpengalaman mendampingi pembelajaran Matematika tingkat SD sampai SMA.',
                'experience' => '10 Tahun',
                'whatsapp_number' => '081234567890',
                'location' => 'Surabaya',
                'latitude' => -7.2575,
                'longitude' => 112.7521,
                'max_travel_km' => 12,
                'is_accepting_requests' => true,
                'verified_at' => now(),
            ]
        );
        $mathematics = CurriculumSubject::query()
            ->where('normalized_name', 'matematika')
            ->first();

        TeacherSubject::query()->where('teacher_profile_id', $profile->id)->delete();
        TeacherSubject::create([
            'teacher_profile_id' => $profile->id,
            'curriculum_subject_id' => $mathematics?->id,
            'name' => 'Matematika',
            'levels' => ['SD', 'SMP', 'SMA'],
            'is_active' => true,
            'is_online' => true,
            'is_offline' => true,
            'is_private_active' => true,
        ]);

        TeacherAvailability::updateOrCreate(
            ['user_id' => $teacher->id, 'day' => 'Senin'],
            ['start_time' => '18:00', 'end_time' => '22:00', 'slots' => null, 'is_active' => true]
        );
        TeacherAvailability::updateOrCreate(
            ['user_id' => $teacher->id, 'day' => 'Rabu'],
            ['start_time' => '18:00', 'end_time' => '22:00', 'slots' => null, 'is_active' => true]
        );

        User::updateOrCreate(
            ['email' => 'murid@bimbelku.com'],
            [
                'name' => 'Murid Demo',
                'phone' => '081298765432',
                'password' => Hash::make('password123'),
                'password_updated_at' => now(),
                'role' => 'student',
                'status' => 'active',
                'school_name' => 'Sekolah Demo',
                'grade' => 'SMP',
                'address' => 'Surabaya',
                'latitude' => -7.2658,
                'longitude' => 112.7344,
                'email_verified_at' => now(),
                'terms_accepted_at' => now(),
                'privacy_accepted_at' => now(),
                'policy_version' => config('app.policy_version'),
            ]
        );

        $this->call(DemoMathTutorSeeder::class);

        foreach ([
            ['class_type' => 'private', 'learning_mode' => 'online', 'amount' => 40000],
            ['class_type' => 'private', 'learning_mode' => 'offline', 'amount' => 40000],
            ['class_type' => 'group', 'learning_mode' => 'online', 'amount' => 40000],
            ['class_type' => 'group', 'learning_mode' => 'offline', 'amount' => 40000],
        ] as $rate) {
            HourlyRate::updateOrCreate(
                [
                    'subject_name' => 'Matematika',
                    'curriculum_subject_id' => $mathematics?->id,
                    'education_level' => null,
                    'class_type' => $rate['class_type'],
                    'learning_mode' => $rate['learning_mode'],
                ],
                ['amount' => $rate['amount'], 'is_active' => true]
            );
        }
    }
}
