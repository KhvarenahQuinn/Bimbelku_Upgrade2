<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ResetPasswordMail;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    private const COOLDOWN_SECONDS = 60;

    // 1. KIRIM LINK RESET (Forgot Password)
    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        $email = mb_strtolower(trim((string) $request->email));
        $genericMessage = 'Jika email terdaftar, link reset akan dikirim.';
        $cooldownKey = 'password-reset-email:'.hash_hmac(
            'sha256',
            $email,
            (string) config('app.key')
        );

        if (RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            $seconds = max(1, RateLimiter::availableIn($cooldownKey));

            return response()->json([
                'message' => 'Tunggu sebelum meminta link reset kembali.',
                'error_code' => 'password_reset_cooldown',
                'retry_after_seconds' => $seconds,
            ], 429, ['Retry-After' => (string) $seconds]);
        }

        // Berlaku juga untuk email yang tidak terdaftar agar respons tidak
        // membocorkan keberadaan akun melalui perilaku cooldown.
        RateLimiter::hit($cooldownKey, self::COOLDOWN_SECONDS);

        $user = User::where('email', $email)->first();

        // Keamanan: Jangan kasih tau kalau email gak ketemu
        if (! $user) {
            return response()->json(['message' => $genericMessage], 200);
        }

        // Buat Token
        $token = Str::random(60);

        // Simpan ke DB (Update jika sudah ada, Insert jika belum)
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'token' => Hash::make($token),
                'created_at' => Carbon::now(),
            ]
        );

        $url = rtrim((string) config('app.frontend_url', 'http://localhost:8080'), '/')
            .'/reset-password?'.http_build_query(['token' => $token, 'email' => $email]);

        // Kirim Email
        try {
            Mail::to($email)->send(new ResetPasswordMail($url, $user?->name));
        } catch (\Throwable $exception) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            RateLimiter::clear($cooldownKey);
            report($exception);
        }

        return response()->json(['message' => $genericMessage]);
    }

    // 2. PROSES UBAH PASSWORD (Reset Password)
    public function reset(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed', // butuh field password_confirmation di frontend
        ]);

        $email = mb_strtolower(trim((string) $request->email));
        $record = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->first();

        if (! $record || ! Hash::check((string) $request->token, (string) $record->token)) {
            return response()->json(['message' => 'Token tidak valid atau email salah.'], 400);
        }

        // Cek Expired (60 menit)
        if (Carbon::parse($record->created_at)->addMinutes(60)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            return response()->json(['message' => 'Token sudah kadaluarsa.'], 400);
        }

        // Update Password User
        $user = User::where('email', $email)->first();
        if ($user) {
            $user->password = Hash::make($request->password);
            $user->has_password = true;
            $user->password_updated_at = now();
            $user->save();
            $user->tokens()->delete();
        }

        // Hapus Token setelah dipakai
        DB::table('password_reset_tokens')->where('email', $email)->delete();

        return response()->json(['message' => 'Password berhasil diubah. Silakan login.']);
    }
}
