<?php

namespace Tests\Feature;

use App\Models\TeacherProfile;
use App\Models\TeacherSubject;
use App\Models\User;
use Database\Seeders\CurriculumCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StageFourAccountFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_registration_email_explains_how_to_continue(): void
    {
        User::factory()->create(['email' => 'sudah.ada@example.com']);

        $this->postJson('/api/register', [
            'name' => 'Murid Baru', 'email' => 'sudah.ada@example.com',
            'phone' => '081234567891', 'password' => 'password123',
            'password_confirmation' => 'password123', 'role' => 'student',
            'date_of_birth' => now()->subYears(20)->toDateString(),
            'terms_accepted' => true, 'privacy_accepted' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Email ini sudah terdaftar. Silakan masuk dengan akun tersebut atau gunakan email lain.');
    }

    public function test_minor_student_registration_requires_guardian_consent(): void
    {
        $birthDate = now()->subYears(12)->toDateString();
        $payload = [
            'name' => 'Murid Anak',
            'email' => 'murid.anak@example.com',
            'phone' => '081234567891',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'student',
            'date_of_birth' => $birthDate,
            'terms_accepted' => true,
            'privacy_accepted' => true,
        ];

        $this->postJson('/api/register', $payload)
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Nama orang tua atau wali wajib diisi untuk murid di bawah 18 tahun.'
            );

        $response = $this->postJson('/api/register', [
            ...$payload,
            'guardian_name' => 'Wali Murid',
            'guardian_phone' => '081234567892',
            'guardian_relationship' => 'orang_tua',
            'guardian_consent' => true,
        ]);

        $response
            ->assertCreated()
            ->assertJsonMissing(['guardian_name' => 'Wali Murid'])
            ->assertJsonMissing(['guardian_phone' => '081234567892']);

        $this->assertDatabaseHas('users', [
            'email' => 'murid.anak@example.com',
            'date_of_birth' => $birthDate,
            'guardian_name' => 'Wali Murid',
            'guardian_phone' => '081234567892',
            'guardian_relationship' => 'orang_tua',
        ]);
        $this->assertNotNull(
            User::query()->where('email', 'murid.anak@example.com')->value('guardian_consent_at')
        );
    }

    public function test_adult_student_registration_does_not_require_guardian_data(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Murid Dewasa',
            'email' => 'murid.dewasa@example.com',
            'phone' => '081234567893',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'student',
            'date_of_birth' => now()->subYears(20)->toDateString(),
            'terms_accepted' => true,
            'privacy_accepted' => true,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('users', [
            'email' => 'murid.dewasa@example.com',
            'guardian_name' => null,
            'guardian_consent_at' => null,
        ]);
    }

    public function test_student_registration_creates_an_active_account(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Murid Tahap Empat',
            'email' => 'murid.tahap4@example.com',
            'phone' => '0000000000',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'student',
            'date_of_birth' => now()->subYears(20)->toDateString(),
            'terms_accepted' => true,
            'privacy_accepted' => true,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user.role', 'student')
            ->assertJsonPath('user.status', 'active');

        $this->assertDatabaseHas('users', [
            'email' => 'murid.tahap4@example.com',
            'role' => 'student',
            'status' => 'active',
        ]);
    }

    public function test_student_registration_profile_fields_are_saved_and_returned_after_login(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Murid Profil Lengkap',
            'email' => 'murid.profil@example.com',
            'phone' => '081234567894',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'student',
            'student_education_level' => 'SMP',
            'grade' => 'Kelas 7',
            'school_name' => 'SMP Contoh',
            'address' => 'Jl. Contoh No. 1',
            'date_of_birth' => now()->subYears(20)->toDateString(),
            'terms_accepted' => true,
            'privacy_accepted' => true,
        ]);

        $response->assertCreated();

        $student = User::query()->where('email', 'murid.profil@example.com')->firstOrFail();
        $this->assertSame('SMP', $student->student_education_level);
        $this->assertSame('Kelas 7', $student->grade);
        $this->assertSame('SMP Contoh', $student->school_name);
        $this->assertSame('Jl. Contoh No. 1', $student->address);

        Sanctum::actingAs($student);
        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('student_education_level', 'SMP')
            ->assertJsonPath('grade', 'Kelas 7')
            ->assertJsonPath('school_name', 'SMP Contoh')
            ->assertJsonPath('address', 'Jl. Contoh No. 1');
    }

    public function test_teacher_registration_remains_pending_with_private_documents(): void
    {
        $this->seed(CurriculumCatalogSeeder::class);
        Storage::fake('local');
        Storage::fake('public');

        $response = $this->post('/api/register', [
            'name' => 'Tutor Tahap Empat',
            'email' => 'tutor.tahap4@example.com',
            'phone' => '081234567899',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'teacher',
            'terms_accepted' => '1',
            'privacy_accepted' => '1',
            'expertise' => 'Matematika',
            'public_degree' => 'S.Pd.',
            'levels' => ['SD', 'SMP'],
            'teaching_method' => 'hybrid',
            'identity_document' => UploadedFile::fake()->image('identitas.jpg'),
            'live_selfie' => UploadedFile::fake()->image('selfie.jpg'),
            'qualification_document' => UploadedFile::fake()->create(
                'ijazah.pdf',
                100,
                'application/pdf'
            ),
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user.role', 'teacher')
            ->assertJsonPath('user.status', 'pending');

        $teacher = User::query()->where('email', 'tutor.tahap4@example.com')->firstOrFail();
        $profile = $teacher->teacherProfile()->firstOrFail();
        $this->assertSame('S.Pd.', $profile->public_degree);

        // Ringkasan WhatsApp hanya berisi data yang aman dibagikan.
        $response
            ->assertJsonPath('application_code', 'TUTOR-'.$teacher->id)
            ->assertJsonPath('registration_summary.application_code', 'TUTOR-'.$teacher->id)
            ->assertJsonPath('registration_summary.name', 'Tutor Tahap Empat')
            ->assertJsonPath('registration_summary.whatsapp', '081234567899')
            ->assertJsonPath('registration_summary.subject', 'Matematika')
            ->assertJsonPath('registration_summary.degree', 'S.Pd.')
            ->assertJsonPath('registration_summary.levels', ['SD', 'SMP'])
            ->assertJsonMissingPath('registration_summary.email')
            ->assertJsonMissingPath('registration_summary.password')
            ->assertJsonMissingPath('registration_summary.identity_document')
            ->assertJsonMissingPath('registration_summary.live_selfie');

        Storage::disk('local')->assertExists($profile->identity_document);
        Storage::disk('local')->assertExists($profile->live_selfie);
        Storage::disk('local')->assertExists($profile->qualification_document);
        Storage::disk('public')->assertMissing($profile->identity_document);
        $this->assertArrayNotHasKey('phone', $teacher->toArray());
        $this->assertArrayNotHasKey('whatsapp_number', $profile->toArray());
        $this->assertArrayNotHasKey('latitude', $profile->toArray());
        $this->assertArrayNotHasKey('account_number', $profile->toArray());
    }

    public function test_teacher_registration_requires_degree_or_latest_education(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Calon Tutor',
            'email' => 'calon-tutor@example.com',
            'phone' => '081234567899',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'teacher',
            'terms_accepted' => true,
            'privacy_accepted' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.public_degree.0', 'Gelar atau pendidikan terakhir wajib diisi untuk pendaftaran tutor.');
    }

    public function test_student_cannot_open_another_users_teacher_document(): void
    {
        Storage::fake('local');
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'pending',
        ]);
        $path = UploadedFile::fake()->image('identitas.jpg')->store(
            'teacher_identity',
            'local'
        );
        TeacherProfile::create([
            'user_id' => $teacher->id,
            'identity_document' => $path,
            'is_accepting_requests' => false,
        ]);
        Sanctum::actingAs($student);

        $this->get("/api/teachers/{$teacher->id}/documents/identity_document")
            ->assertForbidden();
    }

    public function test_admin_user_listing_rejects_the_admin_role_filter(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/users?role=admin')
            ->assertUnprocessable();
    }

    public function test_teacher_filter_options_are_not_limited_to_the_current_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $subjectNames = collect(range(1, 11))->map(fn (int $number) => sprintf('Mapel %02d', $number));
        foreach ($subjectNames as $subjectName) {
            $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
            $profile = TeacherProfile::create([
                'user_id' => $teacher->id,
                'is_accepting_requests' => true,
            ]);
            TeacherSubject::create([
                'teacher_profile_id' => $profile->id,
                'name' => $subjectName,
                'levels' => ['SMP'],
                'is_active' => true,
                'is_online' => true,
                'is_offline' => false,
                'is_private_active' => true,
                'is_group_active' => false,
            ]);
        }
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/users?role=teacher&per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data');

        $this->assertEqualsCanonicalizing(
            $subjectNames->all(),
            $response->json('subject_options')
        );
    }

    public function test_student_can_save_a_personal_cover_without_changing_password(): void
    {
        Storage::fake('public');
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'phone' => '081234567890',
            'password' => Hash::make('password123'),
        ]);
        Sanctum::actingAs($student);

        $this->post('/api/user', [
            '_method' => 'PUT',
            'name' => 'Murid Bersampul',
            'phone' => '081234567890',
            'profile_cover' => UploadedFile::fake()->image('sampul.jpg', 1200, 400),
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Murid Bersampul');

        $student->refresh();
        $this->assertNotNull($student->profile_cover);
        Storage::disk('public')->assertExists($student->profile_cover);
        $this->assertTrue(Hash::check('password123', $student->password));
    }

    public function test_student_can_clear_address_and_location_without_a_broken_success_response(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'phone' => '081234567890',
            'address' => 'Jl. Lama No. 10',
            'maps_link' => 'https://maps.google.com/?q=-6.2,106.8',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'location_consent_at' => now(),
        ]);
        Sanctum::actingAs($student);

        $this->putJson('/api/user', [
            'name' => $student->name,
            'phone' => '081234567890',
            'address' => '',
            'maps_link' => '',
            'latitude' => '',
            'longitude' => '',
            'location_consent' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.address', null)
            ->assertJsonPath('data.maps_link', null)
            ->assertJsonPath('data.latitude', null)
            ->assertJsonPath('data.longitude', null)
            ->assertJsonPath('data.location_consent_at', null);

        $student->refresh();
        $this->assertNull($student->address);
        $this->assertNull($student->maps_link);
        $this->assertNull($student->latitude);
        $this->assertNull($student->longitude);
        $this->assertNull($student->location_consent_at);
    }

    public function test_password_is_changed_through_a_separate_endpoint(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'phone' => '081234567890',
            'password' => Hash::make('password123'),
        ]);
        Sanctum::actingAs($student);

        $this->putJson('/api/user/password', [
            'current_password' => 'password123',
            'password' => 'password456',
            'password_confirmation' => 'password456',
        ])
            ->assertOk()
            ->assertJsonStructure(['message', 'password_updated_at']);

        $student->refresh();
        $this->assertTrue(Hash::check('password456', $student->password));
        $this->assertNotNull($student->password_updated_at);
    }

    public function test_profile_exposes_has_password_flag_for_google_accounts(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'google_id' => 'google-123',
            'password' => Hash::make('random-internal-secret'),
            'has_password' => false,
        ]);
        Sanctum::actingAs($student);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('has_password', false);
    }

    public function test_google_account_can_set_initial_password_without_current_password(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'google_id' => 'google-456',
            'password' => Hash::make('random-internal-secret'),
            'has_password' => false,
        ]);
        Sanctum::actingAs($student);

        $this->postJson('/api/user/set-initial-password', [
            'new_password' => 'password-baru',
            'new_password_confirmation' => 'password-baru',
        ])
            ->assertOk()
            ->assertJsonPath('has_password', true);

        $student->refresh();
        $this->assertTrue($student->has_password);
        $this->assertTrue(Hash::check('password-baru', $student->password));
        $this->assertNotNull($student->password_updated_at);
    }

    public function test_account_with_password_still_requires_current_password(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'password' => Hash::make('password123'),
            'has_password' => true,
        ]);
        Sanctum::actingAs($student);

        $this->putJson('/api/user/password', [
            'password' => 'password456',
            'password_confirmation' => 'password456',
        ])->assertUnprocessable();

        $this->assertTrue(Hash::check('password123', $student->fresh()->password));
    }

    public function test_google_account_can_set_payment_pin_without_current_password(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'google_id' => 'google-789',
            'password' => Hash::make('random-internal-secret'),
            'has_password' => false,
        ]);
        Sanctum::actingAs($student);

        $this->getJson('/api/student/payment-pin/status')
            ->assertOk()
            ->assertJsonPath('has_password', false);

        $this->postJson('/api/student/payment-pin', [
            'pin' => '123456',
            'pin_confirmation' => '123456',
        ])->assertOk()->assertJsonPath('configured', true);

        $this->assertTrue(Hash::check('123456', $student->fresh()->payment_pin_hash));
    }

    public function test_password_reset_updates_the_security_timestamp(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'password' => Hash::make('password123'),
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => $student->email,
            'token' => Hash::make('valid-reset-token'),
            'created_at' => now(),
        ]);

        $this->postJson('/api/reset-password', [
            'email' => $student->email,
            'token' => 'valid-reset-token',
            'password' => 'password456',
            'password_confirmation' => 'password456',
        ])->assertOk();

        $student->refresh();
        $this->assertTrue(Hash::check('password456', $student->password));
        $this->assertNotNull($student->password_updated_at);
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => $student->email,
        ]);
    }

    public function test_frontend_url_and_cors_origins_are_configured_separately(): void
    {
        $this->assertSame('http://127.0.0.1:8080', config('app.frontend_url'));
        $this->assertContains('http://127.0.0.1:8080', config('cors.allowed_origins'));
        $this->assertContains('http://localhost:8080', config('cors.allowed_origins'));
        $this->assertSame('2026-07-29', config('app.policy_version'));
    }

    public function test_teacher_personal_cover_does_not_trigger_reverification(): void
    {
        Storage::fake('public');
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
            'phone' => '081234567890',
        ]);
        $verifiedAt = now()->subDay();
        TeacherProfile::create([
            'user_id' => $teacher->id,
            'whatsapp_number' => '081234567890',
            'is_accepting_requests' => true,
            'verified_at' => $verifiedAt,
        ]);
        Sanctum::actingAs($teacher);

        $this->post('/api/teacher/profile', [
            'name' => $teacher->name,
            'whatsapp_number' => '081234567890',
            'profile_cover' => UploadedFile::fake()->image('sampul-tutor.jpg', 1200, 400),
        ])
            ->assertOk()
            ->assertJsonPath('reverification_required', false);

        $teacher->refresh();
        $this->assertSame('active', $teacher->status);
        $this->assertNotNull($teacher->profile_cover);
        Storage::disk('public')->assertExists($teacher->profile_cover);
        $this->assertNotNull($teacher->teacherProfile->verified_at);
    }
}
