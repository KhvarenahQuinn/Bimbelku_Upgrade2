<?php

namespace Database\Seeders;

use App\Models\CurriculumSubject;
use App\Models\TeacherAvailability;
use App\Models\TeacherProfile;
use App\Models\TeacherSubject;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Tutor demo tambahan untuk Matematika semua jenjang (SD, SMP, SMA, Umum).
 * Idempoten: aman dijalankan berulang kali.
 */
class DemoMathTutorSeeder extends Seeder
{
    /**
     * One source for the tutor's legal name. It is written to `users.name` and
     * to the bank account holder name, which must agree for a payout to pass
     * the bank-owner check.
     */
    private const NAME = 'Rina Kartika, S.Pd.';

    private const ADDRESS = 'Jl. Rungkut Asri Timur No. 14, Kec. Rungkut, Kota Surabaya, Jawa Timur 60293';

    private const CITY = 'Surabaya';

    private const BANK_NAME = 'Bank Central Asia (BCA)';

    private const CHANNEL_CODE = 'BCA';

    private const ACCOUNT_NUMBER = '1234567890';

    public function run(): void
    {
        $mathematics = CurriculumSubject::query()
            ->where('normalized_name', 'matematika')
            ->orWhere('name', 'Matematika')
            ->first();

        $adminId = User::query()->where('role', 'admin')->orderBy('id')->value('id');

        $tutor = User::updateOrCreate(
            ['email' => 'rina.matematika@bimbelku.com'],
            [
                'name' => self::NAME,
                'phone' => '081377788899',
                'address' => self::ADDRESS,
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
            ['user_id' => $tutor->id],
            [
                'expertise' => 'Matematika',
                'title' => 'Tutor Matematika SD–SMA',
                'bio' => 'Mengajar Matematika untuk semua jenjang pendidikan, dari SD, SMP, SMA, hingga kelas umum. Fokus pada pemahaman konsep dan latihan soal.',
                'experience' => '6 Tahun',
                'location' => self::CITY,
                'latitude' => -7.2575,
                'longitude' => 112.7521,
                'max_travel_km' => 12,
                'is_accepting_requests' => true,
                'verified_at' => now(),
                'verified_by' => $adminId,
                'verification_stage' => 'approved',
                'verification_notes' => 'Dokumen dan tes WhatsApp lolos. Siap menerima permintaan Matematika semua jenjang.',
                'documents_checked_at' => now(),
                'whatsapp_test_scheduled_at' => now(),
                'whatsapp_test_passed_at' => now(),
                // Seeded so the demo tutor can be paid out without a manual
                // bank-details save; the holder name is the same constant.
                'bank_name' => self::BANK_NAME,
                'payout_channel_code' => self::CHANNEL_CODE,
                'account_number' => self::ACCOUNT_NUMBER,
                'account_name' => self::NAME,
            ]
        );

        // Satu mapel aktif untuk semua jenjang dan mode online/offline.
        TeacherSubject::query()
            ->where('teacher_profile_id', $profile->id)
            ->delete();

        TeacherSubject::create([
            'teacher_profile_id' => $profile->id,
            'curriculum_subject_id' => $mathematics?->id,
            'name' => 'Matematika',
            'levels' => ['SD', 'SMP', 'SMA', 'Umum'],
            'is_active' => true,
            'is_online' => true,
            'is_offline' => true,
            'is_private_active' => true,
            'is_group_active' => false,
        ]);

        // Ketersediaan setiap hari agar tutor bisa menerima tawaran kelas.
        foreach (['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'] as $day) {
            TeacherAvailability::updateOrCreate(
                ['user_id' => $tutor->id, 'day' => $day],
                [
                    'start_time' => '08:00',
                    'end_time' => '22:00',
                    'slots' => [['start_time' => '08:00', 'end_time' => '22:00']],
                    'is_active' => true,
                ]
            );
        }

        $this->command?->info("Tutor Matematika siap: {$tutor->email} / password123");
    }
}
