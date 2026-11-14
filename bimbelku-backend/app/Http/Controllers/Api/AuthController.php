<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CurriculumSubject;
use App\Models\Notification;
use App\Models\TeacherProfile;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Services\GuestPackageLinkService;
use App\Services\OneTimeCodeService;
use App\Services\PersistentLoginService;
use App\Support\AdminPermissionCatalog;
use App\Support\EducationCatalog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /**
     * Pesan tunggal untuk seluruh kegagalan portal admin. Jangan pernah
     * dibedakan per penyebab.
     */
    private const GENERIC_ADMIN_LOGIN_ERROR = 'Email atau kata sandi salah.';

    // --- FITUR REGISTER ---
    public function register(Request $request, OneTimeCodeService $codes)
    {
        $request->merge([
            'name' => trim((string) $request->input('name')),
            'email' => mb_strtolower(trim((string) $request->input('email'))),
            'phone' => trim((string) $request->input('phone')),
            'expertise' => $request->filled('expertise')
                ? trim((string) $request->input('expertise'))
                : null,
        ]);
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255', 'regex:/\pL/u', 'not_regex:/\d/u'],
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => ['required', 'string', 'max:16', 'regex:/^\+?[0-9]{8,15}$/'],
            'password' => 'required|string|min:8|confirmed',
            'role' => 'required|in:student,teacher',
            'terms_accepted' => 'accepted',
            'privacy_accepted' => 'accepted',
            'school_name' => 'nullable|string|max:255',
            'student_education_level' => ['nullable', Rule::in(EducationCatalog::LEVELS)],
            'grade' => 'nullable|string|max:50',
            'date_of_birth' => 'required_if:role,student|nullable|date|before_or_equal:today',
            'guardian_name' => ['nullable', 'string', 'max:255', 'regex:/\pL/u', 'not_regex:/\d/u'],
            'guardian_phone' => ['nullable', 'string', 'max:16', 'regex:/^\+?[0-9]{8,15}$/'],
            'guardian_relationship' => 'nullable|in:orang_tua,wali_keluarga,wali_resmi',
            'guardian_consent' => 'nullable',
            'address' => 'nullable|string|max:1500',
            'maps_link' => 'nullable|string|url:http,https|max:500',
            'cv_file' => 'nullable|file|mimes:pdf|max:5120',
            'expertise' => 'required_if:role,teacher|nullable|string|max:120',
            'public_degree' => 'required_if:role,teacher|nullable|string|max:120',
            'levels' => 'required_if:role,teacher|nullable|array|min:1',
            'levels.*' => ['required', Rule::in(EducationCatalog::LEVELS)],
            'teaching_method' => 'required_if:role,teacher|nullable|in:online,offline,hybrid',
            'public_profile_consent' => ['nullable', 'boolean'],
            'linkedin' => 'nullable|url:http,https|max:500',
            'identity_document' => 'required_if:role,teacher|nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
            'live_selfie' => 'required_if:role,teacher|nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'qualification_document' => 'required_if:role,teacher|nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
            'certification_document' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
        ], [
            'email.unique' => 'Email ini sudah terdaftar. Silakan masuk dengan akun tersebut atau gunakan email lain.',
            'name.regex' => 'Nama lengkap wajib mengandung huruf.',
            'name.not_regex' => 'Nama lengkap tidak boleh memuat angka.',
            'phone.regex' => 'Nomor WhatsApp/telepon harus berisi 8–15 angka.',
            'guardian_name.regex' => 'Nama orang tua atau wali wajib mengandung huruf.',
            'guardian_name.not_regex' => 'Nama orang tua atau wali tidak boleh memuat angka.',
            'guardian_phone.regex' => 'Nomor orang tua atau wali harus berisi 8–15 angka.',
            'public_degree.required_if' => 'Gelar atau pendidikan terakhir wajib diisi untuk pendaftaran tutor.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->role === 'student'
            && $request->filled('student_education_level')
            && $request->filled('grade')
            && ! EducationCatalog::supports(
                $request->string('student_education_level')->toString(),
                $request->string('grade')->toString()
            )) {
            return response()->json([
                'message' => 'Kelas atau tingkat tidak sesuai dengan jenjang pendidikan.',
                'errors' => ['grade' => ['Kelas atau tingkat tidak sesuai dengan jenjang pendidikan.']],
            ], 422);
        }

        $isMinorStudent = $request->role === 'student'
            && $request->filled('date_of_birth')
            && Carbon::parse($request->date_of_birth)->age < 18;

        if ($isMinorStudent) {
            $guardianValidator = Validator::make($request->all(), [
                'guardian_name' => ['required', 'string', 'max:255', 'regex:/\pL/u', 'not_regex:/\d/u'],
                'guardian_phone' => ['required', 'string', 'max:16', 'regex:/^\+?[0-9]{8,15}$/'],
                'guardian_relationship' => 'required|in:orang_tua,wali_keluarga,wali_resmi',
                'guardian_consent' => 'accepted',
            ], [
                'guardian_name.required' => 'Nama orang tua atau wali wajib diisi untuk murid di bawah 18 tahun.',
                'guardian_name.regex' => 'Nama orang tua atau wali wajib mengandung huruf.',
                'guardian_name.not_regex' => 'Nama orang tua atau wali tidak boleh memuat angka.',
                'guardian_phone.required' => 'Nomor orang tua atau wali wajib diisi untuk murid di bawah 18 tahun.',
                'guardian_phone.regex' => 'Nomor orang tua atau wali harus berisi 8–15 angka.',
                'guardian_relationship.required' => 'Hubungan orang tua atau wali wajib dipilih.',
                'guardian_consent.accepted' => 'Persetujuan orang tua atau wali wajib diberikan.',
            ]);

            if ($guardianValidator->fails()) {
                return response()->json([
                    'message' => $guardianValidator->errors()->first(),
                    'errors' => $guardianValidator->errors(),
                ], 422);
            }
        }

        $catalogSubject = null;
        if ($request->role === 'teacher') {
            $catalogSubject = CurriculumSubject::query()
                ->where('is_active', true)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $request->expertise))])
                ->first();
            if (! $catalogSubject) {
                return response()->json([
                    'message' => 'Pilih mata pelajaran tutor dari katalog aktif.',
                ], 422);
            }
            if (array_diff($request->input('levels', []), $catalogSubject->education_levels ?? [])) {
                return response()->json([
                    'message' => 'Jenjang tutor tidak tersedia pada mata pelajaran tersebut.',
                ], 422);
            }
            $request->merge(['expertise' => $catalogSubject->name]);
        }

        $status = $request->role === 'teacher' ? 'pending' : 'active';
        $storedFiles = [];

        try {
            if ($request->role === 'teacher') {
                foreach ([
                    'cv_file' => 'cv_files',
                    'identity_document' => 'teacher_identity',
                    'live_selfie' => 'teacher_selfies',
                    'qualification_document' => 'teacher_qualifications',
                    'certification_document' => 'teacher_certifications',
                ] as $field => $directory) {
                    if ($request->hasFile($field)) {
                        $storedFiles[$field] = $request->file($field)->store($directory, 'local');
                    }
                }
            }

            $user = DB::transaction(function () use (
                $request,
                $status,
                $storedFiles,
                $catalogSubject,
                $isMinorStudent
            ) {
                $user = User::create([
                    'name' => $request->name,
                    'email' => mb_strtolower($request->email),
                    'email_verification_required_at' => now(),
                    'phone' => trim((string) $request->phone),
                    'password' => Hash::make($request->password),
                    'has_password' => true,
                    'password_updated_at' => now(),
                    'role' => $request->role,
                    'status' => $status,
                    'school_name' => $request->school_name ?? null,
                    'student_education_level' => $request->role === 'student'
                        ? $request->student_education_level
                        : null,
                    'grade' => $request->grade ?? null,
                    'date_of_birth' => $request->role === 'student'
                        ? $request->date_of_birth
                        : null,
                    'guardian_name' => $isMinorStudent
                        ? trim((string) $request->guardian_name)
                        : null,
                    'guardian_phone' => $isMinorStudent
                        ? trim((string) $request->guardian_phone)
                        : null,
                    'guardian_relationship' => $isMinorStudent
                        ? $request->guardian_relationship
                        : null,
                    'guardian_consent_at' => $isMinorStudent ? now() : null,
                    'address' => $request->address ?? null,
                    'maps_link' => $request->maps_link ?? null,
                    'terms_accepted_at' => now(),
                    'privacy_accepted_at' => now(),
                    'policy_version' => config('app.policy_version'),
                    'consent_ip' => $request->ip(),
                    'consent_user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                ]);

                if ($request->role !== 'teacher') {
                    return $user;
                }

                $profile = TeacherProfile::create([
                    'user_id' => $user->id,
                    'expertise' => $request->expertise,
                    'public_degree' => trim((string) $request->public_degree),
                    'linkedin' => $request->linkedin,
                    'teaching_method' => $request->teaching_method,
                    'whatsapp_number' => trim((string) $request->phone),
                    'cv_file' => $storedFiles['cv_file'] ?? null,
                    'identity_document' => $storedFiles['identity_document'] ?? null,
                    'live_selfie' => $storedFiles['live_selfie'] ?? null,
                    'qualification_document' => $storedFiles['qualification_document'] ?? null,
                    'certification_document' => $storedFiles['certification_document'] ?? null,
                    'is_accepting_requests' => false,
                    'public_profile_enabled' => $request->boolean('public_profile_consent'),
                    'public_profile_consent_at' => $request->boolean('public_profile_consent') ? now() : null,
                ]);

                TeacherSubject::create([
                    'teacher_profile_id' => $profile->id,
                    'name' => $request->expertise,
                    'curriculum_subject_id' => $catalogSubject?->id,
                    'levels' => array_values(array_unique($request->input('levels', []))),
                    'is_active' => true,
                    'is_online' => in_array($request->teaching_method, ['online', 'hybrid'], true),
                    'is_offline' => in_array($request->teaching_method, ['offline', 'hybrid'], true),
                    'is_private_active' => true,
                    'is_group_active' => true,
                ]);

                return $user;
            });
        } catch (\Throwable $exception) {
            foreach ($storedFiles as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        if ($user->role === 'teacher') {
            User::query()
                ->where('role', 'admin')
                ->where('status', 'active')
                ->pluck('id')
                ->each(fn (int $adminId) => Notification::updateOrCreate(
                    ['unique_key' => "teacher-registration:{$user->id}:{$adminId}"],
                    [
                        'user_id' => $adminId,
                        'title' => 'Pendaftaran tutor baru',
                        'message' => "{$user->name} telah mengirim data lengkap dan akan melanjutkan seleksi serta tes melalui WhatsApp.",
                        'type' => 'info',
                        'target_url' => '/admin/guru',
                        'is_read' => false,
                    ]
                ));
        }

        $emailDeliveryFailed = false;
        try {
            $codes->send($user, OneTimeCodeService::EMAIL_VERIFICATION);
        } catch (\Throwable $exception) {
            report($exception);
            $emailDeliveryFailed = true;
        }

        return response()->json([
            'message' => $emailDeliveryFailed
                ? 'Akun berhasil dibuat, tetapi kode belum dapat dikirim. Coba kirim ulang kode verifikasi.'
                : ($user->role === 'teacher'
                    ? 'Pendaftaran tutor tersimpan. Lanjutkan ke WhatsApp untuk seleksi dan tes; kode verifikasi email juga telah dikirim.'
                    : 'Akun berhasil dibuat. Masukkan kode OTP yang dikirim ke email Anda.'),
            'requires_email_verification' => true,
            'email_delivery_failed' => $emailDeliveryFailed,
            'resend_after_seconds' => $emailDeliveryFailed ? 0 : OneTimeCodeService::RESEND_SECONDS,
            'email' => $user->email,
            // Ringkasan aman untuk WhatsApp: tanpa kata sandi, email, atau isi
            // dokumen privat. Nilai berasal dari data yang benar-benar tersimpan.
            'application_code' => $user->role === 'teacher' ? 'TUTOR-'.$user->id : null,
            'registration_summary' => $user->role === 'teacher' ? [
                'application_code' => 'TUTOR-'.$user->id,
                'name' => $user->name,
                'whatsapp' => $user->phone,
                'subject' => $request->expertise,
                'degree' => $request->public_degree,
                'levels' => array_values((array) $request->input('levels', [])),
                'teaching_method' => $request->teaching_method,
                'linkedin' => $request->linkedin,
            ] : null,
            'user' => $user,
        ], 201);
    }

    // --- FITUR LOGIN ---
    public function login(Request $request, PersistentLoginService $sessions, GuestPackageLinkService $guestOrders)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
            'remember_device' => ['sometimes', 'boolean'],
            'login_portal' => ['sometimes', 'in:user,admin'],
        ]);

        $user = User::where('email', mb_strtolower(trim((string) $request->email)))->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Email atau password salah.'], 401);
        }

        // Portal memisahkan pintu masuk admin dari pintu masuk murid/tutor.
        // Portal tidak pernah menentukan hak akses; role tetap dibaca dari akun.
        $portal = (string) ($request->input('login_portal') ?: 'user');
        if ($portal === 'admin' && $user->role !== 'admin') {
            return response()->json([
                'message' => 'Akun ini bukan akun admin. Silakan masuk melalui halaman pengguna.',
            ], 403);
        }
        if ($portal !== 'admin' && $user->role === 'admin') {
            return response()->json([
                'message' => 'Akun admin harus masuk melalui halaman login admin.',
                'error_code' => 'admin_portal_required',
            ], 403);
        }

        if ($user->email_verification_required_at && ! $user->email_verified_at) {
            return response()->json([
                'message' => 'Email belum diverifikasi. Masukkan kode OTP yang telah dikirim.',
                'error_code' => 'email_not_verified',
                'email' => $user->email,
            ], 403);
        }

        // Cek Status
        if ($user->status === 'banned') {
            return response()->json(['message' => 'Akun Anda telah DIBLOKIR oleh Admin.'], 403);
        }

        if ($user->role === 'student' && $user->status === 'pending') {
            $user->update(['status' => 'active']);
        }

        if ($user->role === 'teacher' && $user->status === 'pending') {
            return response()->json([
                'message' => 'Akun tutor sedang menunggu pemeriksaan dokumen serta hasil seleksi dan tes melalui WhatsApp.',
            ], 403);
        }

        if ($user->role === 'teacher' && ! $user->teacherProfile?->verified_at) {
            $user->update(['status' => 'pending']);
            $user->tokens()->delete();

            return response()->json([
                'message' => 'Verifikasi tutor belum berlaku. Mohon tunggu pemeriksaan admin.',
            ], 403);
        }

        if ($user->status === 'rejected') {
            return response()->json(['message' => 'Maaf, akun Anda ditolak atau dinonaktifkan.'], 403);
        }
        if ($user->status !== 'active') {
            return response()->json(['message' => 'Akun Anda sedang tidak aktif.'], 403);
        }

        $session = $sessions->issue(
            $user,
            $request->boolean('remember_device'),
            'password'
        );

        return response()->json([
            'message' => 'Login berhasil',
            'access_token' => $session['access_token'],
            'token_type' => 'Bearer',
            'session_transport' => 'http_only_cookie',
            'session_expires_at' => $session['expires_at']->toIso8601String(),
            'remember_device' => $session['remembered'],
            'pending_guest_order' => $guestOrders->pendingCodeFor($user),
            'user' => [...$user->toArray(), 'is_primary_admin' => $user->role === 'admin' && $user->isPrimaryAdmin()],
        ])->withCookie($session['cookie']);
    }

    /**
     * Portal admin memakai endpoint sendiri supaya klien tidak perlu dipercaya
     * mengirim `login_portal`. Setiap kegagalan memakai pesan yang sama:
     * membedakan "bukan admin" dari "kredensial salah" akan membuat URL ini
     * bisa dipakai menebak peran akun (role enumeration).
     */
    public function adminLogin(Request $request, PersistentLoginService $sessions)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $genericFailure = response()->json([
            'message' => self::GENERIC_ADMIN_LOGIN_ERROR,
        ], 401);

        $user = User::where('email', mb_strtolower(trim((string) $credentials['email'])))->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return $genericFailure;
        }

        // Akun non-admin atau admin nonaktif tidak boleh tahu bahwa kredensial
        // mereka benar. Jawaban tetap identik dengan kredensial salah.
        if ($user->role !== 'admin' || $user->status !== 'active') {
            return $genericFailure;
        }

        // Sesi admin selalu pendek; portal ini tidak menawarkan "ingat perangkat".
        $session = $sessions->issue($user, false, 'password');

        return response()->json([
            'message' => 'Login berhasil',
            'access_token' => $session['access_token'],
            'token_type' => 'Bearer',
            'session_transport' => 'http_only_cookie',
            'session_expires_at' => $session['expires_at']->toIso8601String(),
            'remember_device' => false,
            'user' => [
                ...$user->toArray(),
                'is_primary_admin' => $user->isPrimaryAdmin(),
                // Guard frontend membaca daftar ini saat login, bukan hanya
                // saat memuat ulang profil.
                'admin_permissions' => AdminPermissionCatalog::allCodes(),
            ],
        ])->withCookie($session['cookie']);
    }

    public function logout(Request $request, PersistentLoginService $sessions)
    {
        $user = $request->user();
        $plainTextToken = trim((string) $request->bearerToken());

        if ($user) {
            $currentToken = $user->currentAccessToken();

            if ($currentToken instanceof PersonalAccessToken) {
                PersonalAccessToken::query()
                    ->whereKey($currentToken->getKey())
                    ->where('tokenable_type', $user->getMorphClass())
                    ->where('tokenable_id', $user->getKey())
                    ->delete();
            } elseif ($plainTextToken !== '') {
                $token = PersonalAccessToken::findToken($plainTextToken);

                if (
                    $token
                    && $token->tokenable_type === $user->getMorphClass()
                    && (int) $token->tokenable_id === (int) $user->getKey()
                ) {
                    $token->delete();
                }
            }
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        // RequestGuard menyimpan user yang sudah terautentikasi di memori.
        // Bersihkan guard setelah token dicabut agar token lama tidak dapat
        // dipakai lagi pada request berikutnya, termasuk pada worker panjang
        // dan rangkaian feature test dalam proses PHP yang sama.
        Auth::guard('sanctum')->forgetUser();
        Auth::forgetGuards();
        $request->setUserResolver(static fn () => null);

        return response()->json(['message' => 'Sesi berhasil ditutup.'])
            ->withCookie($sessions->forgetCookie());
    }

    public function logoutAll(Request $request, PersistentLoginService $sessions)
    {
        $request->user()?->tokens()->delete();
        Auth::guard('sanctum')->forgetUser();
        Auth::forgetGuards();
        $request->setUserResolver(static fn () => null);

        return response()->json(['message' => 'Semua sesi akun berhasil ditutup.'])
            ->withCookie($sessions->forgetCookie());
    }
}
