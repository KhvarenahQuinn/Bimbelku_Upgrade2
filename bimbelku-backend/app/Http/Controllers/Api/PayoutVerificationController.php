<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TeacherProfile;
use App\Services\OneTimeCodeService;
use App\Services\TeacherPayoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class PayoutVerificationController extends Controller
{
    public function __construct(
        private readonly OneTimeCodeService $codes,
        private readonly TeacherPayoutService $payouts,
    ) {}

    public function sendOtp(Request $request)
    {
        $teacher = $request->user();
        $this->codes->send($teacher, OneTimeCodeService::PAYOUT_BANK_CHANGE);

        return response()->json([
            'message' => 'Kode verifikasi dikirim ke email akun. Kode berlaku 10 menit.',
            'expires_in_seconds' => OneTimeCodeService::EXPIRES_MINUTES * 60,
            'resend_after_seconds' => OneTimeCodeService::RESEND_SECONDS,
        ]);
    }

    /**
     * Switching to `password` requires the current password. Returning to `otp`
     * requires both the password and a freshly emailed code, so a hijacked
     * mailbox cannot silently weaken the check.
     */
    public function updateMethod(Request $request)
    {
        $validated = $request->validate([
            'method' => ['required', Rule::in(['otp', 'password'])],
            'current_password' => ['required', 'string', 'max:200'],
            'code' => ['nullable', 'string', 'size:6'],
        ]);

        $teacher = $request->user();
        if (! Hash::check($validated['current_password'], $teacher->password)) {
            return response()->json(['message' => 'Kata sandi akun tidak sesuai.'], 422);
        }
        if ($validated['method'] === 'otp' && blank($validated['code'] ?? null)) {
            return response()->json([
                'message' => 'Kode verifikasi email wajib diisi untuk kembali ke verifikasi OTP.',
                'errors' => ['code' => ['Kode verifikasi email wajib diisi.']],
            ], 422);
        }
        if ($validated['method'] === 'otp') {
            $this->codes->verify($teacher, OneTimeCodeService::PAYOUT_BANK_CHANGE, (string) $validated['code']);
        }

        $profile = DB::transaction(function () use ($teacher, $validated) {
            $profile = TeacherProfile::query()->firstOrCreate(['user_id' => $teacher->id]);
            $locked = TeacherProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $locked->forceFill([
                'payout_verification_method' => $validated['method'],
                'payout_verification_method_updated_at' => now(),
            ])->save();

            return $locked;
        }, 3);

        return response()->json([
            'message' => $validated['method'] === 'otp'
                ? 'Verifikasi pencairan memakai kode email.'
                : 'Verifikasi pencairan memakai kata sandi akun.',
            'data' => [
                'method' => $profile->payout_verification_method,
                'updated_at' => $profile->payout_verification_method_updated_at,
            ],
        ]);
    }
}
