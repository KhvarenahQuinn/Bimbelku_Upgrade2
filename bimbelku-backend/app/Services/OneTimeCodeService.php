<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class OneTimeCodeService
{
    public const EMAIL_VERIFICATION = 'email_verification';

    public const PAYMENT_PIN_RESET = 'payment_pin_reset';

    public const PAYOUT_BANK_CHANGE = 'payout_bank_change';

    public const EXPIRES_MINUTES = 10;

    public const RESEND_SECONDS = 60;

    public const MAX_ATTEMPTS = 5;

    public function send(User $user, string $purpose): void
    {
        $this->guardCooldown($user, $purpose);
        $code = (string) random_int(100000, 999999);
        $recordId = $this->store($user, $purpose, $code);
        $title = match ($purpose) {
            self::EMAIL_VERIFICATION => 'Verifikasi Email BimbelKu',
            self::PAYOUT_BANK_CHANGE => 'Verifikasi Rekening Pencairan BimbelKu',
            default => 'Reset PIN Pembayaran BimbelKu',
        };
        try {
            Mail::send(['emails.verification_code', 'emails.verification_code_text'], [
                'name' => $user->name, 'code' => $code, 'purpose' => $purpose,
                'expiresMinutes' => self::EXPIRES_MINUTES,
            ], fn ($message) => $message->to($user->email)->subject($title));
        } catch (\Throwable $exception) {
            DB::table('verification_codes')->where('id', $recordId)->delete();
            throw $exception;
        }
        DB::table('verification_codes')->where('user_id', $user->id)
            ->where('purpose', $purpose)->whereNull('used_at')
            ->where('id', '<>', $recordId)
            ->update(['used_at' => now(), 'updated_at' => now()]);
    }

    private function guardCooldown(User $user, string $purpose): void
    {
        $last = DB::table('verification_codes')->where('user_id', $user->id)
            ->where('purpose', $purpose)->max('sent_at');
        if (! $last) {
            return;
        }
        $available = Carbon::parse($last)->addSeconds(self::RESEND_SECONDS);
        if ($available->isFuture()) {
            $seconds = max(1, (int) ceil(now()->diffInSeconds($available)));
            throw ValidationException::withMessages([
                'email' => 'Tunggu '.$seconds.' detik sebelum meminta kode baru.',
            ]);
        }
    }

    private function store(User $user, string $purpose, string $code): int
    {
        return DB::table('verification_codes')->insertGetId([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::EXPIRES_MINUTES),
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function verify(User $user, string $purpose, string $code): void
    {
        $error = DB::transaction(function () use ($user, $purpose, $code): ?string {
            $record = DB::table('verification_codes')->where('user_id', $user->id)
                ->where('purpose', $purpose)->whereNull('used_at')->latest('id')
                ->lockForUpdate()->first();
            if (! $record || now()->greaterThan($record->expires_at)) {
                return 'Kode sudah kedaluwarsa. Minta kode baru.';
            }
            if ((int) $record->attempts >= self::MAX_ATTEMPTS) {
                return 'Batas percobaan kode tercapai. Minta kode baru.';
            }
            if (! Hash::check($code, $record->code_hash)) {
                DB::table('verification_codes')->where('id', $record->id)->increment('attempts');

                return 'Kode OTP tidak sesuai.';
            }
            DB::table('verification_codes')->where('id', $record->id)
                ->update(['used_at' => now(), 'updated_at' => now()]);

            return null;
        });
        if ($error) {
            throw ValidationException::withMessages(['code' => $error]);
        }
    }
}
