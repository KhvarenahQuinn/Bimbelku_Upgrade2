<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AdminPermissionCatalog;
use App\Support\EducationCatalog;
use App\Support\PublicMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    // =================================================
    // GET PROFILE (Ambil Data User Login)
    // Endpoint: GET /api/user
    // =================================================
    public function show(Request $request)
    {
        $user = Auth::user();

        // Cek apakah user punya avatar (kolom 'avatar' di tabel users)
        // Jika Anda belum punya kolom 'avatar', nanti saya beri panduan migrasinya di bawah.
        $avatarUrl = PublicMedia::url($user->avatar);

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'address' => $user->address,
            'maps_link' => $user->maps_link,
            'latitude' => $user->latitude,
            'longitude' => $user->longitude,
            'location_consent_at' => $user->location_consent_at?->toIso8601String(),
            'student_education_level' => $user->student_education_level,
            'grade' => $user->grade,
            'school_name' => $user->school_name,
            'learning_needs' => $user->learning_needs,
            'terms_accepted_at' => $user->terms_accepted_at?->toIso8601String(),
            'privacy_accepted_at' => $user->privacy_accepted_at?->toIso8601String(),
            'role' => $user->role,
            'admin_type' => $user->role === 'admin' ? ($user->isPrimaryAdmin() ? 'super_admin' : 'admin') : null,
            'is_primary_admin' => $user->role === 'admin' && $user->isPrimaryAdmin(),
            'admin_permissions' => $user->role === 'admin'
                ? AdminPermissionCatalog::allCodes()
                : [],
            'student_birth_date' => $user->role === 'student'
                ? $user->date_of_birth?->toDateString()
                : null,
            'is_minor' => $user->role === 'student'
                && $user->date_of_birth
                && $user->date_of_birth->age < 18,
            'guardian' => $user->role === 'student' && $user->guardian_consent_at
                ? [
                    'name' => $user->guardian_name,
                    'phone' => $user->guardian_phone,
                    'relationship' => $user->guardian_relationship,
                    'consent_at' => $user->guardian_consent_at?->toIso8601String(),
                ]
                : null,
            'avatar_url' => $avatarUrl,
            'profile_cover_url' => PublicMedia::url($user->profile_cover),
            'has_password' => (bool) ($user->has_password ?? true),
            'password_updated_at' => $user->password_updated_at?->toIso8601String(),
        ]);
    }

    // =================================================
    // UPDATE PROFILE (Nama, Foto, dan Sampul)
    // Endpoint: PUT /api/user
    // =================================================
    public function update(Request $request)
    {
        $user = Auth::user();

        if ($user->role !== 'student') {
            return response()->json([
                'message' => 'Profil tutor harus diperbarui melalui halaman profil tutor agar perubahan identitas dapat diperiksa ulang.',
            ], 403);
        }

        // 1. Validasi Input
        $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/\pL/u', 'not_regex:/\d/u'],
            'phone' => ['required', 'string', 'max:24', 'regex:/^\+?[0-9\s().-]+$/'],
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048', // Max 2MB
            'profile_cover' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
            'student_education_level' => ['nullable', Rule::in(EducationCatalog::LEVELS)],
            'grade' => ['nullable', 'string', 'max:50'],
            'school_name' => ['nullable', 'string', 'max:180'],
            'learning_needs' => ['nullable', 'string', 'max:1500'],
            'address' => ['nullable', 'string', 'max:1500'],
            'maps_link' => ['nullable', 'url', 'max:1500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'location_consent' => ['nullable', 'boolean'],
        ]);
        $normalizedPhone = preg_replace('/\D+/', '', (string) $request->input('phone'));
        if (! is_string($normalizedPhone) || ! preg_match('/^[0-9]{8,15}$/', $normalizedPhone)) {
            throw ValidationException::withMessages([
                'phone' => 'Nomor WhatsApp/telepon harus berisi 8–15 angka.',
            ]);
        }

        if ($request->filled('student_education_level') && $request->filled('grade')) {
            abort_unless(
                EducationCatalog::supports($request->string('student_education_level')->toString(), $request->string('grade')->toString()),
                422,
                'Kelas atau tingkat tidak sesuai jenjang.'
            );
        }

        $oldAvatar = $user->avatar;
        $oldCover = $user->profile_cover;
        $newAvatar = $request->hasFile('avatar')
            ? $request->file('avatar')->store('avatars', 'public')
            : null;
        $newCover = $request->hasFile('profile_cover')
            ? $request->file('profile_cover')->store('profile_covers', 'public')
            : null;

        try {
            $user->name = $request->name;
            $user->phone = $normalizedPhone;
            $user->student_education_level = $request->input('student_education_level') ?: null;
            $user->grade = $request->input('grade') ?: null;
            $user->school_name = $request->input('school_name') ?: null;
            $user->learning_needs = $request->input('learning_needs') ?: null;
            $user->address = $request->input('address') ?: null;
            $user->maps_link = $request->input('maps_link') ?: null;
            $user->latitude = $request->filled('latitude') ? $request->input('latitude') : null;
            $user->longitude = $request->filled('longitude') ? $request->input('longitude') : null;
            if ($request->has('location_consent')) {
                $user->location_consent_at = $request->boolean('location_consent') ? ($user->location_consent_at ?? now()) : null;
            }
            if ($newAvatar) {
                $user->avatar = $newAvatar;
            }
            if ($newCover) {
                $user->profile_cover = $newCover;
                if ($user->role === 'teacher') {
                    $user->profile_cover_use_default = false;
                }
            }
            $user->save();
        } catch (\Throwable $exception) {
            if ($newAvatar) {
                Storage::disk('public')->delete($newAvatar);
            }
            if ($newCover) {
                Storage::disk('public')->delete($newCover);
            }
            throw $exception;
        }

        if ($newAvatar && $oldAvatar && $oldAvatar !== $newAvatar) {
            Storage::disk('public')->delete($oldAvatar);
        }
        if ($newCover && $oldCover && $oldCover !== $newCover) {
            Storage::disk('public')->delete($oldCover);
        }

        return response()->json([
            'message' => 'Profil berhasil diperbarui!',
            'data' => [
                'name' => $user->name,
                'phone' => $user->phone,
                'avatar_url' => PublicMedia::url($user->avatar),
                'profile_cover_url' => PublicMedia::url($user->profile_cover),
                'student_education_level' => $user->student_education_level,
                'grade' => $user->grade,
                'school_name' => $user->school_name,
                'learning_needs' => $user->learning_needs,
                'address' => $user->address,
                'maps_link' => $user->maps_link,
                'latitude' => $user->latitude,
                'longitude' => $user->longitude,
                'location_consent_at' => $user->location_consent_at?->toIso8601String(),
            ],
        ]);
    }

    public function updatePassword(Request $request)
    {
        return $this->persistAccountPassword($request);
    }

    /**
     * Dipakai akun yang masuk lewat Google OAuth. Akun seperti ini belum
     * pernah menetapkan password lokal, jadi current_password tidak diminta.
     * Endpoint ini tetap aman jika akun sudah punya password: current_password
     * akan diwajibkan seperti perubahan password biasa.
     */
    public function setInitialPassword(Request $request)
    {
        return $this->persistAccountPassword($request);
    }

    private function persistAccountPassword(Request $request)
    {
        $user = $request->user();
        $hasPassword = (bool) ($user->has_password ?? true);

        // Terima dua gaya penamaan: password/new_password beserta konfirmasinya.
        if (! $request->filled('password')) {
            $request->merge(['password' => $request->input('new_password')]);
        }
        if (! $request->filled('password_confirmation')) {
            $request->merge(['password_confirmation' => $request->input('new_password_confirmation')]);
        }

        $rules = [
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];

        if ($hasPassword) {
            $rules['current_password'] = ['required', 'string'];
            $rules['password'][] = 'different:current_password';
        }

        $validated = $request->validate($rules);

        if ($hasPassword && ! Hash::check($validated['current_password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Kata sandi saat ini tidak sesuai.',
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'has_password' => true,
            'password_updated_at' => now(),
        ])->save();

        $currentTokenId = $user->currentAccessToken()?->id;
        $user->tokens()
            ->when($currentTokenId, fn ($query) => $query->whereKeyNot($currentTokenId))
            ->delete();

        return response()->json([
            'message' => $hasPassword
                ? 'Kata sandi berhasil diperbarui. Sesi lain telah ditutup.'
                : 'Kata sandi akun berhasil dibuat. Sesi lain telah ditutup.',
            'has_password' => true,
            'password_updated_at' => $user->password_updated_at?->toIso8601String(),
        ]);
    }
}
