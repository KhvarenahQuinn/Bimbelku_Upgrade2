<?php

namespace App\Services;

use App\Models\GuestPackageOrder;
use App\Models\User;

/**
 * Menghubungkan draf pesanan tamu ke akun murid yang emailnya sama.
 *
 * Penautan hanya boleh terjadi setelah email akun terverifikasi dan akun aktif.
 * Status draf tidak diubah di sini; murid tetap menyelesaikan klaim lewat
 * halaman pesanan agar harga dan jadwal divalidasi ulang.
 */
class GuestPackageLinkService
{
    public function pendingCodeFor(?User $user): ?string
    {
        if (
            ! $user
            || $user->role !== 'student'
            || $user->status !== 'active'
            || ! $user->email_verified_at
        ) {
            return null;
        }

        GuestPackageOrder::query()
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);

        $guest = GuestPackageOrder::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $user->email)])
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $guest) {
            return null;
        }

        if (! $guest->student_id) {
            $guest->update(['student_id' => $user->id]);
        }

        return (string) $guest->code;
    }
}
