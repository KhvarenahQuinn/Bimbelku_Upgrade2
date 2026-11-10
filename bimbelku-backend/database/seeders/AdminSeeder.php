<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = mb_strtolower(trim((string) env(
            'PRIMARY_ADMIN_EMAIL',
            env('SEED_ADMIN_EMAIL', '')
        )));
        $password = (string) env('SEED_ADMIN_PASSWORD');

        if ($email === '' || mb_strlen($password) < 12) {
            $this->command?->warn(
                'Admin tidak dibuat. Isi PRIMARY_ADMIN_EMAIL atau SEED_ADMIN_EMAIL, serta SEED_ADMIN_PASSWORD minimal 12 karakter.'
            );

            return;
        }

        $existing = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($existing && $existing->role !== 'admin') {
            $this->command?->error(
                'Admin tidak dibuat karena email tersebut sudah dipakai akun murid atau tutor.'
            );

            return;
        }
        $admin = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Admin Utama',
                'password' => Hash::make($password),
                'password_updated_at' => now(),
                'role' => 'admin',
                'admin_type' => 'super_admin',
                'admin_permissions' => null,
                'admin_permissions_updated_by' => null,
                'admin_permissions_updated_at' => now(),
                'status' => 'active',
                'email_verified_at' => now(),
                'terms_accepted_at' => now(),
                'privacy_accepted_at' => now(),
                'policy_version' => config('app.policy_version'),
            ]
        );

        // Admin biasa yang dibuat dari panel tetap dipertahankan. Penonaktifan
        // hanya boleh dilakukan eksplisit oleh admin utama agar proses seed
        // deployment tidak memblokir akun operasional secara diam-diam.
        $this->command?->info("Admin utama aktif: {$admin->email}");
    }
}
