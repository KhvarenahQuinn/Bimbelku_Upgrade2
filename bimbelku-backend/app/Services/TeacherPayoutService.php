<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Notification;
use App\Models\Payout;
use App\Models\Setting;
use App\Models\TeacherPayoutRequest;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Support\XenditPayoutChannelCatalog;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class TeacherPayoutService
{
    public const MINIMUM_AMOUNT = 10000;

    public function __construct(
        private readonly XenditMoneyMovementService $gateway,
        private readonly BankAccountNameVerifier $bankNameVerifier,
    ) {}

    public function availableAmount(int $teacherId): float
    {
        return round((float) Booking::query()
            ->where('teacher_id', $teacherId)
            ->where('status', 'completed')
            ->whereDoesntHave('disputes', fn ($query) => $query->whereNull('resolved_at'))
            ->selectRaw('COALESCE(SUM(CASE WHEN teacher_net_amount - teacher_paid_amount - teacher_reserved_amount > 0 THEN teacher_net_amount - teacher_paid_amount - teacher_reserved_amount ELSE 0 END), 0) AS available')
            ->value('available'), 2);
    }

    /** @return array{request:TeacherPayoutRequest,payout:Payout} */
    public function request(User $teacher, float $requestedAmount): array
    {
        if ($requestedAmount < self::MINIMUM_AMOUNT) {
            throw new RuntimeException('Nominal pencairan minimal Rp10.000.');
        }

        $teacher->loadMissing('teacherProfile');
        $profile = $teacher->teacherProfile;
        $channelCode = $profile?->payout_channel_code ?: XenditPayoutChannelCatalog::inferCode($profile?->bank_name);
        if (! $profile || blank($channelCode) || blank($profile->account_number) || blank($profile->account_name)) {
            throw new RuntimeException('Lengkapi rekening pencairan yang didukung sebelum menarik saldo.');
        }
        if (blank($teacher->address)) {
            throw new RuntimeException('Lengkapi alamat asli pada profil tutor sebelum menarik saldo. Data ini diperlukan untuk transfer bank.');
        }
        if ($profile->payout_hold_until?->isFuture()) {
            throw new RuntimeException('Pencairan ditahan sampai '.$profile->payout_hold_until->translatedFormat('d M Y, H:i').' WIB setelah perubahan rekening.');
        }
        $normalizeName = static fn (string $name): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
        if ($normalizeName((string) $profile->account_name) !== $normalizeName((string) $teacher->name)) {
            throw new RuntimeException('Nama pada rekening tersimpan berbeda dari nama akun tutor. Periksa identitas dan rekening tujuan.');
        }
        if (! $this->bankNameVerifier->available() || ! $this->bankNameVerifier->matches((string) $channelCode, (string) $profile->account_number, (string) $teacher->name)) {
            throw new RuntimeException('Pencairan belum dapat dikirim: verifikasi nama pemilik rekening langsung dari bank belum tersedia atau belum cocok. Saldo Anda tetap aman.');
        }
        if (! $this->gateway->configured()) {
            throw new RuntimeException('Layanan pencairan otomatis belum siap. Hubungi pengelola BimbelKu.');
        }

        $records = DB::transaction(function () use ($teacher, $requestedAmount, $channelCode) {
            $profile = TeacherProfile::query()->where('user_id', $teacher->id)->lockForUpdate()->firstOrFail();
            if ($profile->payout_hold_until?->isFuture()) {
                throw new RuntimeException('Pencairan masih ditahan setelah perubahan rekening.');
            }
            if (blank($profile->payout_channel_code)) {
                $profile->forceFill(['payout_channel_code' => $channelCode])->save();
            }

            $bookings = Booking::query()
                ->where('teacher_id', $teacher->id)
                ->where('status', 'completed')
                ->whereDoesntHave('disputes', fn ($query) => $query->whereNull('resolved_at'))
                ->whereRaw('(teacher_net_amount - teacher_paid_amount - teacher_reserved_amount) > 0')
                ->oldest('completed_at')
                ->lockForUpdate()
                ->get();
            $available = round((float) $bookings->sum(fn (Booking $booking) => max(
                0,
                (float) $booking->teacher_net_amount - (float) $booking->teacher_paid_amount - (float) $booking->teacher_reserved_amount
            )), 2);
            if ($requestedAmount > $available + 0.009) {
                throw new RuntimeException('Nominal pencairan melebihi saldo tersedia.');
            }

            $remaining = round($requestedAmount, 2);
            $allocations = [];
            $grossEquivalent = 0.0;
            foreach ($bookings as $booking) {
                if ($remaining <= 0.009) {
                    break;
                }
                $bookingAvailable = max(0, (float) $booking->teacher_net_amount - (float) $booking->teacher_paid_amount - (float) $booking->teacher_reserved_amount);
                $allocated = round(min($remaining, $bookingAvailable), 2);
                if ($allocated <= 0) {
                    continue;
                }
                $allocations[] = ['booking_id' => $booking->id, 'amount' => $allocated];
                $ratio = (float) $booking->teacher_net_amount > 0 ? $allocated / (float) $booking->teacher_net_amount : 0;
                $grossEquivalent += (float) $booking->gross_amount * $ratio;
                $booking->forceFill([
                    'teacher_reserved_amount' => round((float) $booking->teacher_reserved_amount + $allocated, 2),
                    'payout_status' => $allocated >= $bookingAvailable - 0.009 ? 'requested' : 'ready',
                ])->save();
                $remaining = round($remaining - $allocated, 2);
            }
            if ($remaining > 0.009 || $allocations === []) {
                throw new RuntimeException('Saldo berubah saat pencairan dibuat. Muat ulang halaman dan coba kembali.');
            }

            $taxPercent = min(100, max(0, (float) (Setting::query()->where('key', 'teacher_withholding_tax_percent')->value('value') ?? 0)));
            $taxAmount = round($requestedAmount * $taxPercent / 100, 2);
            $transferAmount = round($requestedAmount - $taxAmount, 2);
            if ($transferAmount <= 0) {
                throw new RuntimeException('Nominal setelah potongan tidak valid.');
            }
            $bookingIds = collect($allocations)->pluck('booking_id')->all();
            $commission = max(0, round($grossEquivalent - $requestedAmount, 2));

            $payoutRequest = TeacherPayoutRequest::create([
                'teacher_id' => $teacher->id,
                'booking_ids' => $bookingIds,
                'allocation_breakdown' => $allocations,
                'gross_amount' => round($grossEquivalent, 2),
                'commission_amount' => $commission,
                'requested_amount' => $requestedAmount,
                'tax_amount' => $taxAmount,
                'net_amount' => $transferAmount,
                'bank_name' => $profile->bank_name,
                'payout_channel_code' => $channelCode,
                'account_number' => $profile->account_number,
                'account_name' => $profile->account_name,
                'bank_details_version' => (int) $profile->bank_details_version,
                'status' => 'processing',
                'requested_at' => now(),
            ]);

            $payout = Payout::create([
                'user_id' => $teacher->id,
                'amount' => $transferAmount,
                'requested_amount' => $requestedAmount,
                'tax_amount' => $taxAmount,
                'period' => 'Saldo sampai '.now()->translatedFormat('d M Y'),
                'total_classes' => count($bookingIds),
                'proof_url' => null,
                'status' => 'processing',
                'payment_provider' => 'xendit',
                'gross_amount' => round($grossEquivalent, 2),
                'commission_amount' => $commission,
                'processed_at' => now(),
                'booking_ids' => $bookingIds,
                'allocation_breakdown' => $allocations,
                'bank_name' => $profile->bank_name,
                'account_number' => $profile->account_number,
                'account_name' => $profile->account_name,
                'payout_channel_code' => $channelCode,
            ]);
            $payoutRequest->update(['payout_id' => $payout->id]);

            Notification::updateOrCreate(['unique_key' => "payout-processing:{$payout->id}"], [
                'user_id' => $teacher->id,
                'title' => 'Pencairan sedang diproses',
                'message' => 'Pencairan Rp'.number_format($transferAmount, 0, ',', '.').' sedang dikirim otomatis ke rekening terdaftar.',
                'type' => 'info',
                'target_url' => '/guru/dompet',
                'is_read' => false,
            ]);

            return ['request' => $payoutRequest, 'payout' => $payout];
        }, 3);

        try {
            $this->gateway->createPayout($records['payout'], $teacher);
        } catch (Throwable $exception) {
            // An uncertain network result is not a failed transfer. Keep funds
            // reserved until an authenticated terminal webhook reconciles it.
            if ($records['payout']->fresh()->gateway_status === 'FAILED') {
                $this->release($records['payout'], $exception->getMessage());
            }
            throw $exception;
        }

        return ['request' => $records['request']->fresh(), 'payout' => $records['payout']->fresh()];
    }

    public function complete(Payout $payout): void
    {
        DB::transaction(function () use ($payout) {
            $locked = Payout::query()->lockForUpdate()->findOrFail($payout->id);
            if (in_array($locked->status, ['completed', 'failed'], true)) {
                return;
            }
            $allocations = $locked->allocation_breakdown ?: collect($locked->booking_ids ?? [])->map(fn ($id) => ['booking_id' => $id, 'amount' => null])->all();
            foreach ($allocations as $allocation) {
                $booking = Booking::query()->lockForUpdate()->find($allocation['booking_id'] ?? 0);
                if (! $booking) {
                    continue;
                }
                $amount = $allocation['amount'] === null
                    ? max((float) $booking->teacher_reserved_amount, (float) $booking->teacher_net_amount - (float) $booking->teacher_paid_amount)
                    : min((float) $allocation['amount'], (float) $booking->teacher_reserved_amount);
                $paid = round((float) $booking->teacher_paid_amount + $amount, 2);
                $reserved = round(max(0, (float) $booking->teacher_reserved_amount - $amount), 2);
                $available = max(0, (float) $booking->teacher_net_amount - $paid - $reserved);
                $booking->forceFill([
                    'teacher_paid_amount' => $paid,
                    'teacher_reserved_amount' => $reserved,
                    'payout_status' => $available > 0.009 ? 'ready' : ($reserved > 0.009 ? 'requested' : 'paid'),
                    'payout_request_id' => null,
                ])->save();
            }
            $locked->forceFill(['status' => 'completed', 'processed_at' => now()])->save();
            Notification::query()->where('unique_key', "payout-processing:{$locked->id}")->update(['is_read' => true]);
            TeacherPayoutRequest::query()->where('payout_id', $locked->id)->update(['status' => 'completed', 'processed_at' => now()]);
        }, 3);
    }

    public function release(Payout $payout, string $reason): void
    {
        DB::transaction(function () use ($payout, $reason) {
            $locked = Payout::query()->lockForUpdate()->findOrFail($payout->id);
            if (in_array($locked->status, ['completed', 'failed'], true)) {
                return;
            }
            $allocations = $locked->allocation_breakdown ?: collect($locked->booking_ids ?? [])->map(fn ($id) => ['booking_id' => $id, 'amount' => null])->all();
            foreach ($allocations as $allocation) {
                $booking = Booking::query()->lockForUpdate()->find($allocation['booking_id'] ?? 0);
                if (! $booking) {
                    continue;
                }
                $releaseAmount = $allocation['amount'] === null ? (float) $booking->teacher_reserved_amount : (float) $allocation['amount'];
                $reserved = round(max(0, (float) $booking->teacher_reserved_amount - $releaseAmount), 2);
                $available = max(0, (float) $booking->teacher_net_amount - (float) $booking->teacher_paid_amount - $reserved);
                $booking->forceFill([
                    'teacher_reserved_amount' => $reserved,
                    'payout_status' => $available > 0.009 ? 'ready' : ((float) $booking->teacher_paid_amount > 0 ? 'paid' : 'cancelled'),
                    'payout_request_id' => null,
                ])->save();
            }
            $locked->forceFill([
                'status' => 'failed',
                'gateway_status' => $locked->gateway_status ?: 'FAILED',
                'gateway_failure_code' => $locked->gateway_failure_code ?: 'PAYOUT_FAILED',
            ])->save();
            Notification::query()->where('unique_key', "payout-processing:{$locked->id}")->update(['is_read' => true]);
            TeacherPayoutRequest::query()->where('payout_id', $locked->id)->update([
                'status' => 'failed', 'processed_at' => now(), 'review_notes' => $reason,
            ]);
        }, 3);
    }
}
