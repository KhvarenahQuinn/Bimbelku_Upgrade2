<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ResetPaymentPinMail;
use App\Models\User;
use App\Services\OneTimeCodeService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentPinController extends Controller
{
    private const EMAIL_LINK_COOLDOWN_SECONDS = 60;

    private const EMAIL_LINK_EXPIRES_MINUTES = 60;

    public function status(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'configured' => filled($user->payment_pin_hash),
            'has_password' => (bool) ($user->has_password ?? true),
        ]);
    }

    public function set(Request $request)
    {
        $user = $request->user();
        $hasPassword = (bool) ($user->has_password ?? true);

        $rules = [
            'pin' => ['required', 'digits:6', 'confirmed'],
        ];
        if ($hasPassword) {
            $rules['current_password'] = ['required', 'string'];
        }

        $validated = $request->validate($rules, [
            'pin.digits' => 'PIN pembayaran harus terdiri dari 6 angka.',
            'pin.confirmed' => 'Ulangi PIN pembayaran belum sama.',
        ]);

        // Akun Google OAuth belum punya password lokal, jadi PIN boleh dibuat
        // langsung selama sesinya aktif. Akun dengan password tetap wajib
        // mengonfirmasi kata sandi sebelum membuat atau mengganti PIN.
        if ($hasPassword && ! Hash::check($validated['current_password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Kata sandi akun tidak sesuai.',
            ]);
        }

        $user->forceFill([
            'payment_pin_hash' => Hash::make($validated['pin']),
        ])->save();
        $this->clearEmailResetTokens((string) $user->email);

        return response()->json([
            'message' => 'PIN pembayaran berhasil disimpan.',
            'configured' => true,
            'has_password' => $hasPassword,
        ]);
    }

    /**
     * Mulai alur lupa PIN. Akun dengan kata sandi lokal memverifikasi lewat
     * kata sandi; akun Google OAuth (tanpa kata sandi) tetap memakai OTP email.
     */
    public function forgot(Request $request, OneTimeCodeService $codes)
    {
        $user = $request->user();
        if (! filled($user->payment_pin_hash)) {
            return response()->json([
                'message' => 'PIN pembayaran belum dibuat.',
                'error_code' => 'payment_pin_not_configured',
            ], 422);
        }

        if ((bool) ($user->has_password ?? true)) {
            return response()->json([
                'message' => 'Verifikasi kata sandi akun Anda untuk mengatur ulang PIN pembayaran.',
                'verification_method' => 'password',
                'requires_email_code' => false,
                'resend_after_seconds' => 0,
            ]);
        }

        return $this->sendResetCode($user, $codes);
    }

    /**
     * Endpoint lama tetap dipertahankan agar klien versi sebelumnya tidak
     * rusak. Perilakunya selalu mengirim OTP email.
     */
    public function requestReset(Request $request, OneTimeCodeService $codes)
    {
        $user = $request->user();
        if (! filled($user->payment_pin_hash)) {
            return response()->json(['message' => 'PIN pembayaran belum dibuat.'], 422);
        }

        return $this->sendResetCode($user, $codes);
    }

    public function reset(Request $request, OneTimeCodeService $codes)
    {
        $data = $request->validate([
            'code' => ['nullable', 'digits:6', 'required_without:current_password'],
            'current_password' => ['nullable', 'string', 'required_without:code'],
            'pin' => ['required', 'digits:6', 'confirmed'],
        ], [
            'pin.digits' => 'PIN pembayaran harus terdiri dari 6 angka.',
            'pin.confirmed' => 'Ulangi PIN pembayaran belum sama.',
            'code.required_without' => 'Masukkan kode OTP dari email atau kata sandi akun.',
            'current_password.required_without' => 'Masukkan kode OTP dari email atau kata sandi akun.',
        ]);

        $user = $request->user();
        if (! filled($user->payment_pin_hash)) {
            return response()->json([
                'message' => 'PIN pembayaran belum dibuat.',
                'error_code' => 'payment_pin_not_configured',
            ], 422);
        }

        $verifiedWith = 'email';
        if (filled($data['current_password'] ?? null)) {
            if (! (bool) ($user->has_password ?? true) || ! Hash::check($data['current_password'], (string) $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => 'Kata sandi akun tidak sesuai.',
                ]);
            }
            $verifiedWith = 'password';
        } else {
            $codes->verify($user, OneTimeCodeService::PAYMENT_PIN_RESET, (string) $data['code']);
        }

        $user->forceFill(['payment_pin_hash' => Hash::make($data['pin'])])->save();
        $this->clearEmailResetTokens((string) $user->email);

        return response()->json([
            'message' => 'PIN pembayaran berhasil diatur ulang.',
            'configured' => true,
            'verified_with' => $verifiedWith,
        ]);
    }

    /**
     * Kirim tautan reset PIN ke email akun. Ini adalah opsi pemulihan kedua
     * di samping verifikasi kata sandi, dan juga berguna untuk akun OAuth.
     */
    public function forgotEmail(Request $request)
    {
        $user = $request->user();
        if (! filled($user->payment_pin_hash)) {
            return response()->json([
                'message' => 'PIN pembayaran belum dibuat.',
                'error_code' => 'payment_pin_not_configured',
            ], 422);
        }

        $email = mb_strtolower(trim((string) $user->email));
        $record = DB::table('payment_pin_reset_tokens')->where('email', $email)->first();
        if ($record && filled($record->created_at)) {
            $availableAt = Carbon::parse($record->created_at)->addSeconds(self::EMAIL_LINK_COOLDOWN_SECONDS);
            if ($availableAt->isFuture()) {
                $seconds = max(1, (int) ceil(now()->diffInSeconds($availableAt)));

                return response()->json([
                    'message' => 'Tunggu sebelum meminta link reset PIN kembali.',
                    'error_code' => 'payment_pin_reset_cooldown',
                    'resend_after_seconds' => $seconds,
                ], 429, ['Retry-After' => (string) $seconds]);
            }
        }

        $token = Str::random(60);
        DB::table('payment_pin_reset_tokens')->updateOrInsert(
            ['email' => $email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        $url = rtrim((string) config('app.frontend_url', 'http://localhost:8080'), '/')
            .'/reset-pin?'.http_build_query(['token' => $token, 'email' => $email]);

        try {
            Mail::to($email)->send(new ResetPaymentPinMail($url));
        } catch (\Throwable $exception) {
            DB::table('payment_pin_reset_tokens')->where('email', $email)->delete();
            report($exception);

            return response()->json([
                'message' => 'Link reset PIN belum dapat dikirim. Silakan coba lagi sekarang.',
                'error_code' => 'email_delivery_failed',
                'resend_after_seconds' => 0,
            ], 503);
        }

        return response()->json([
            'message' => 'Link reset PIN telah dikirim ke email akun Anda. Buka tautan tersebut untuk membuat PIN baru.',
            'verification_method' => 'email_link',
            'requires_email_link' => true,
            'resend_after_seconds' => self::EMAIL_LINK_COOLDOWN_SECONDS,
        ]);
    }

    /**
     * Tukarkan token dari tautan email menjadi PIN pembayaran baru. Endpoint
     * ini publik karena tautan email mungkin dibuka di perangkat lain, tetapi
     * token 60 karakter yang di-hash menjadi faktor verifikasinya.
     */
    public function resetWithEmailToken(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'pin' => ['required', 'digits:6', 'confirmed'],
        ], [
            'pin.digits' => 'PIN pembayaran harus terdiri dari 6 angka.',
            'pin.confirmed' => 'Ulangi PIN pembayaran belum sama.',
        ]);

        $email = mb_strtolower(trim((string) $data['email']));
        $record = DB::table('payment_pin_reset_tokens')->where('email', $email)->first();
        if (! $record || ! Hash::check((string) $data['token'], (string) $record->token)) {
            return response()->json([
                'message' => 'Link reset PIN tidak valid atau sudah digunakan. Minta link baru.',
                'error_code' => 'payment_pin_reset_token_invalid',
            ], 422);
        }

        if (Carbon::parse($record->created_at)->addMinutes(self::EMAIL_LINK_EXPIRES_MINUTES)->isPast()) {
            DB::table('payment_pin_reset_tokens')->where('email', $email)->delete();

            return response()->json([
                'message' => 'Link reset PIN sudah kedaluwarsa. Minta link baru dari halaman Pengaturan.',
                'error_code' => 'payment_pin_reset_token_expired',
            ], 422);
        }

        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            DB::table('payment_pin_reset_tokens')->where('email', $email)->delete();

            return response()->json([
                'message' => 'Akun dengan email tersebut tidak ditemukan.',
                'error_code' => 'payment_pin_reset_account_missing',
            ], 422);
        }

        $user->forceFill(['payment_pin_hash' => Hash::make($data['pin'])])->save();
        $this->clearEmailResetTokens($email);

        return response()->json([
            'message' => 'PIN pembayaran berhasil diatur ulang. Silakan masuk kembali menggunakan PIN baru.',
            'configured' => true,
            'verified_with' => 'email_link',
        ]);
    }

    private function clearEmailResetTokens(string $email): void
    {
        DB::table('payment_pin_reset_tokens')
            ->where('email', mb_strtolower(trim($email)))
            ->delete();
    }

    private function sendResetCode($user, OneTimeCodeService $codes)
    {
        try {
            $codes->send($user, OneTimeCodeService::PAYMENT_PIN_RESET);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Kode reset PIN belum dapat dikirim. Silakan coba lagi sekarang.',
                'error_code' => 'email_delivery_failed',
                'resend_after_seconds' => 0,
            ], 503);
        }

        return response()->json([
            'message' => 'Kode OTP reset PIN telah dikirim ke email akun Anda.',
            'verification_method' => 'email',
            'requires_email_code' => true,
            'resend_after_seconds' => OneTimeCodeService::RESEND_SECONDS,
        ]);
    }
}
