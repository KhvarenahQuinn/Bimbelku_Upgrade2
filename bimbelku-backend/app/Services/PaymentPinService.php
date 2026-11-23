<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Satu tempat untuk memeriksa PIN pembayaran. PIN mengizinkan pemakaian saldo,
 * jadi percobaan yang gagal harus dihitung dan dibatasi. Sebelum ini setiap
 * pemanggil hanya membandingkan hash tanpa pencatatan apa pun.
 */
class PaymentPinService
{
    public const MAX_ATTEMPTS = 5;

    public const LOCK_MINUTES = 15;

    /**
     * @return array{ok:bool,code:string,message:string,status:int,locked_until:?CarbonInterface}
     */
    public function verify(User $user, string $pin): array
    {
        $lockedUntil = $user->payment_pin_locked_until;
        if ($lockedUntil?->isFuture()) {
            return $this->lockedResult($lockedUntil);
        }

        if (
            filled($user->payment_pin_hash)
            && $pin !== ''
            && Hash::check($pin, (string) $user->payment_pin_hash)
        ) {
            $this->clearFailures($user);

            return ['ok' => true, 'code' => 'ok', 'message' => '', 'status' => 200, 'locked_until' => null];
        }

        return $this->registerFailure($user);
    }

    /**
     * Reset the failure budget after a successful verification or a PIN change.
     */
    public function clearFailures(User $user): void
    {
        if ((int) $user->payment_pin_failed_attempts === 0 && $user->payment_pin_locked_until === null) {
            return;
        }

        User::query()->whereKey($user->getKey())->update([
            'payment_pin_failed_attempts' => 0,
            'payment_pin_locked_until' => null,
        ]);

        $user->forceFill([
            'payment_pin_failed_attempts' => 0,
            'payment_pin_locked_until' => null,
        ]);
    }

    /**
     * @return array{ok:bool,code:string,message:string,status:int,locked_until:?CarbonInterface}
     */
    private function registerFailure(User $user): array
    {
        // Ditulis di transaksi sendiri: permintaan yang ditolak setelah ini
        // tidak boleh menghapus penghitung percobaan.
        [$attempts, $lockedUntil] = DB::transaction(function () use ($user): array {
            $locked = User::query()->lockForUpdate()->find($user->getKey());
            if (! $locked) {
                return [0, null];
            }

            $attempts = (int) $locked->payment_pin_failed_attempts + 1;
            $lockedUntil = $attempts >= self::MAX_ATTEMPTS
                ? now()->addMinutes(self::LOCK_MINUTES)
                : null;

            $locked->forceFill([
                'payment_pin_failed_attempts' => min($attempts, self::MAX_ATTEMPTS),
                'payment_pin_locked_until' => $lockedUntil,
            ])->save();

            return [$attempts, $lockedUntil];
        }, 3);

        $user->forceFill([
            'payment_pin_failed_attempts' => min($attempts, self::MAX_ATTEMPTS),
            'payment_pin_locked_until' => $lockedUntil,
        ]);

        if ($lockedUntil) {
            $this->notifyLocked($user, $lockedUntil);

            return $this->lockedResult($lockedUntil);
        }

        return [
            'ok' => false,
            'code' => 'payment_pin_invalid',
            'message' => 'PIN pembayaran tidak sesuai.',
            'status' => 422,
            'locked_until' => null,
        ];
    }

    private function notifyLocked(User $user, CarbonInterface $lockedUntil): void
    {
        Notification::updateOrCreate(
            ['unique_key' => 'payment-pin-locked:'.$user->getKey().':'.$lockedUntil->getTimestamp()],
            [
                'user_id' => $user->getKey(),
                'title' => 'PIN pembayaran dikunci sementara',
                'message' => 'PIN pembayaran dikunci selama '.self::LOCK_MINUTES.' menit setelah '
                    .self::MAX_ATTEMPTS.' percobaan gagal. Pencairan dan pemakaian saldo baru dapat '
                    .'dilakukan setelah '.$lockedUntil->translatedFormat('d M Y, H:i').' WIB. '
                    .'Jika bukan Anda yang mencoba, atur ulang PIN dari halaman pengaturan.',
                'type' => 'warning',
                'is_read' => false,
            ]
        );
    }

    /**
     * @return array{ok:bool,code:string,message:string,status:int,locked_until:?CarbonInterface}
     */
    private function lockedResult(CarbonInterface $lockedUntil): array
    {
        return [
            'ok' => false,
            'code' => 'payment_pin_locked',
            'message' => 'PIN pembayaran dikunci sementara setelah beberapa percobaan gagal. Coba lagi setelah '
                .$lockedUntil->translatedFormat('d M Y, H:i').' WIB.',
            'status' => 423,
            'locked_until' => $lockedUntil,
        ];
    }
}
