<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OneTimeCodeService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmailVerificationController extends Controller
{
    public function resend(Request $request, OneTimeCodeService $codes)
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $email = mb_strtolower(trim((string) $data['email']));
        $user = User::query()->where('email', $email)->first();
        if ($user && $user->email_verification_required_at && ! $user->email_verified_at) {
            try {
                $codes->send($user, OneTimeCodeService::EMAIL_VERIFICATION);
            } catch (\Throwable $exception) {
                report($exception);

                return response()->json([
                    'message' => 'Kode verifikasi belum dapat dikirim. Silakan coba lagi sekarang.',
                    'error_code' => 'email_delivery_failed',
                    'resend_after_seconds' => 0,
                ], 503);
            }
        }

        return response()->json([
            'message' => 'Jika email terdaftar dan belum diverifikasi, kode OTP telah dikirim.',
            'resend_after_seconds' => OneTimeCodeService::RESEND_SECONDS,
        ]);
    }

    public function verify(Request $request, OneTimeCodeService $codes)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
        ]);
        $email = mb_strtolower(trim((string) $data['email']));
        $user = User::query()->where('email', $email)->first();

        // Email tak dikenal, akun tanpa verifikasi tertunda, akun yang sudah
        // terverifikasi, dan kode salah harus menghasilkan jawaban yang sama.
        // Kalau tiap kondisi punya pesan sendiri, endpoint publik ini menjadi
        // alat untuk menebak alamat email mana yang terdaftar.
        if (! $user || ! $user->email_verification_required_at || $user->email_verified_at) {
            return $this->rejectedCode();
        }

        try {
            $codes->verify($user, OneTimeCodeService::EMAIL_VERIFICATION, $data['code']);
        } catch (ValidationException $exception) {
            return $this->rejectedCode();
        }

        $user->forceFill(['email_verified_at' => now()])->save();

        return response()->json([
            'message' => $user->role === 'teacher'
                ? 'Email berhasil diverifikasi. Admin akan menghubungi WhatsApp Anda untuk seleksi dan tes sebelum akun tutor diaktifkan.'
                : 'Email berhasil diverifikasi. Silakan masuk ke akun Anda.',
            'role' => $user->role,
        ]);
    }

    private function rejectedCode()
    {
        return response()->json([
            'message' => 'Kode verifikasi tidak valid atau sudah kedaluwarsa. Minta kode baru lalu coba lagi.',
            'error_code' => 'email_verification_code_invalid',
        ], 422);
    }
}
