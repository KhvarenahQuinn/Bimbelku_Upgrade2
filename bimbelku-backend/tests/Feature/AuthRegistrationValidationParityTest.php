<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kontrak validasi register harus sama dengan aturan yang dipakai frontend
 * (`src/lib/validation.ts` + `Register.tsx`). Setiap field harus menjawab 422
 * dengan kunci `errors.<field>`, bukan pesan generik.
 */
class AuthRegistrationValidationParityTest extends TestCase
{
    use RefreshDatabase;

    private function baseStudentPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Murid Valid',
            'email' => 'murid.valid@example.test',
            'phone' => '081234567890',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'student',
            'terms_accepted' => '1',
            'privacy_accepted' => '1',
            'date_of_birth' => '2005-05-05',
        ], $overrides);
    }

    public function test_valid_student_registration_is_accepted(): void
    {
        $this->postJson('/api/register', $this->baseStudentPayload())
            ->assertCreated()
            ->assertJsonPath('requires_email_verification', true);

        $this->assertDatabaseHas('users', [
            'email' => 'murid.valid@example.test',
            'role' => 'student',
        ]);
    }

    public function test_email_field_rejects_invalid_format_with_field_level_error(): void
    {
        // Rule `email` Laravel (Validator, bukan filter_var) menerima `a@b` dan
        // `user@example`; frontend `isValidEmail()` menolaknya. Divergensi ini
        // disengaja: frontend lebih ketat, backend tetap sumber kebenaran, dan
        // tidak ada alamat yang valid di backend namun ditolak frontend.
        // Test ini mengunci format yang ditolak keduanya.
        foreach (['not-an-email', 'user..name@example.test', 'user@@example.test', 'plainaddress'] as $email) {
            $response = $this->postJson('/api/register', $this->baseStudentPayload(['email' => $email]));
            $response->assertStatus(422)->assertJsonValidationErrors('email');
            $this->assertNotSame('The given data was invalid.', $response->json('message'));
        }
    }

    public function test_email_rule_is_more_permissive_than_frontend_on_bare_domains(): void
    {
        // Dokumentasi perilaku: backend menerima alamat yang ditolak frontend.
        // Kalau Laravel memperketat rule ini di masa depan, test ini gagal dan
        // memberi tahu bahwa komentar divergensi di atas sudah usang.
        foreach (['a@b', 'user@example'] as $index => $email) {
            $this->postJson('/api/register', $this->baseStudentPayload([
                'email' => $email,
                'phone' => '08123456789'.$index,
            ]))->assertCreated();
        }
    }

    public function test_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);

        $this->postJson('/api/register', $this->baseStudentPayload(['email' => 'taken@example.test']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email')
            ->assertJsonPath('errors.email.0', 'Email ini sudah terdaftar. Silakan masuk dengan akun tersebut atau gunakan email lain.');
    }

    public function test_phone_must_match_shared_regex(): void
    {
        // `max:16` menang lebih dulu untuk string > 16 karakter, jadi pesan
        // spesifik regex hanya diuji untuk input yang lolos batas panjang.
        foreach (['123', '0812-3456-7890', 'abcdefghij'] as $phone) {
            $this->postJson('/api/register', $this->baseStudentPayload(['phone' => $phone]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('phone')
                ->assertJsonPath('errors.phone.0', 'Nomor WhatsApp/telepon harus berisi 8–15 angka.');
        }

        // Format yang sama dengan frontend `isValidPhone`: digit 8–15 dengan awalan + opsional.
        foreach (['+6281234567890', '081234567890'] as $phone) {
            $this->postJson('/api/register', $this->baseStudentPayload([
                'email' => 'phone-ok-'.md5($phone).'@example.test',
                'phone' => $phone,
            ]))->assertCreated();
        }
    }

    public function test_password_requires_minimum_length_and_confirmation(): void
    {
        $this->postJson('/api/register', $this->baseStudentPayload([
            'password' => 'short',
            'password_confirmation' => 'short',
        ]))->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/register', $this->baseStudentPayload([
            'password_confirmation' => 'different123',
        ]))->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_date_of_birth_cannot_be_in_the_future(): void
    {
        $this->postJson('/api/register', $this->baseStudentPayload([
            'date_of_birth' => now()->addDay()->toDateString(),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('date_of_birth');
    }

    public function test_terms_and_privacy_are_required(): void
    {
        $this->postJson('/api/register', $this->baseStudentPayload(['terms_accepted' => '0']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('terms_accepted');

        $this->postJson('/api/register', $this->baseStudentPayload(['privacy_accepted' => '0']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('privacy_accepted');
    }

    public function test_role_must_be_student_or_teacher(): void
    {
        $this->postJson('/api/register', $this->baseStudentPayload(['role' => 'admin']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }
}
