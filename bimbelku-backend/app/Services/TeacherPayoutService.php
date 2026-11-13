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
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class TeacherPayoutService
{
    public const MINIMUM_AMOUNT = 10000;

    public function __construct(
        private readonly XenditMoneyMovementService $gateway,
        private readonly BankAccountNameVerifier $bankNameVerifier,
        private readonly OneTimeCodeService $oneTimeCodes,
    ) {}

    /**
     * Signed available balance. A negative value means the tutor was paid more
     * than the sessions now earn (for example after a session refund), and it
     * must stay visible so the payout flow can refuse and the UI can warn.
     */
    public function availableAmount(int $teacherId): float
    {
        return round((float) Booking::query()
            ->where('teacher_id', $teacherId)
            ->where('status', 'completed')
            ->whereDoesntHave('disputes', fn ($query) => $query->whereNull('resolved_at'))
            ->selectRaw('COALESCE(SUM(teacher_net_amount - teacher_paid_amount - teacher_reserved_amount), 0) AS available')
            ->value('available'), 2);
    }

    /** Amount the tutor was overpaid and therefore cannot withdraw. */
    public function overpaidAmount(int $teacherId): float
    {
        return round(max(0, -$this->availableAmount($teacherId)), 2);
    }

    /** @return array{request:TeacherPayoutRequest,payout:Payout} */
    public function request(
        User $teacher,
        float $requestedAmount,
        ?array $bankDetails = null,
        ?array $verification = null
    ): array {
        $verificationMethod = null;
        if ($requestedAmount < self::MINIMUM_AMOUNT) {
            throw new RuntimeException('Nominal pencairan minimal Rp10.000.');
        }
        if ($this->availableAmount((int) $teacher->id) <= 0) {
            throw new RuntimeException('Saldo tidak tersedia untuk dicairkan. Ada sesi yang dikembalikan setelah pencairan sebelumnya.');
        }
        if (! $this->gateway->configured()) {
            throw new RuntimeException('Layanan pencairan otomatis belum siap. Hubungi pengelola BimbelKu.');
        }

        $teacher->loadMissing('teacherProfile');
        $profile = $teacher->teacherProfile;
        $hasNewBankDetails = is_array($bankDetails) && filled($bankDetails['account_number'] ?? null);
        $channelCode = $hasNewBankDetails
            ? ($bankDetails['payout_channel_code'] ?? XenditPayoutChannelCatalog::inferCode($bankDetails['bank_name'] ?? null))
            : ($profile?->payout_channel_code ?: XenditPayoutChannelCatalog::inferCode($profile?->bank_name));
        if (! $hasNewBankDetails && (! $profile || blank($channelCode) || blank($profile->account_number) || blank($profile->account_name))) {
            throw new RuntimeException('Lengkapi rekening pencairan yang didukung sebelum menarik saldo.');
        }
        if ($hasNewBankDetails && (blank($channelCode) || XenditPayoutChannelCatalog::get((string) $channelCode) === null)) {
            throw new RuntimeException('Bank belum mendukung pencairan otomatis. Pilih bank dari daftar yang tersedia.');
        }
        if (blank($teacher->address)) {
            throw new RuntimeException('Lengkapi alamat asli pada profil tutor sebelum menarik saldo. Data ini diperlukan untuk transfer bank.');
        }
        if (! $hasNewBankDetails && $profile->payout_hold_until?->isFuture()) {
            throw new RuntimeException('Pencairan ditahan sampai '.$profile->payout_hold_until->translatedFormat('d M Y, H:i').' WIB setelah perubahan rekening.');
        }

        $verification = $this->assertVerification($teacher, $profile, $verification);
        $verificationMethod = (string) ($verification['method'] ?? 'otp');

        $previousBank = [];
        $records = DB::transaction(function () use ($teacher, $requestedAmount, $channelCode, $bankDetails, $verification, $verificationMethod, &$previousBank) {
            $profile = TeacherProfile::query()->where('user_id', $teacher->id)->lockForUpdate()->first();
            if (! $profile) {
                $profile = TeacherProfile::query()->create(['user_id' => $teacher->id]);
            }
            $bankChangedNow = false;
            $previousBank = $profile->only([
                'bank_name', 'payout_channel_code', 'account_number', 'account_name',
                'bank_account_fingerprint', 'bank_account_changed_at', 'payout_hold_until', 'bank_details_version',
            ]);
            if (is_array($bankDetails) && filled($bankDetails['account_number'] ?? null)) {
                $this->assertBankNameMatches($teacher, $bankDetails);
                if ($verificationMethod === 'otp') {
                    $this->oneTimeCodes->verify(
                        $teacher,
                        OneTimeCodeService::PAYOUT_BANK_CHANGE,
                        (string) ($verification['code'] ?? '')
                    );
                }
                [$profile, $bankChangedNow] = $this->storeBankDetails($teacher, $profile, $bankDetails);
            }
            // A hold created by an earlier request still blocks this payout; a
            // different account saved inside this same request must not.
            if ($profile->payout_hold_until?->isFuture() && ! $bankChangedNow) {
                throw new RuntimeException('Pencairan masih ditahan setelah perubahan rekening.');
            }
            if (blank($profile->payout_channel_code)) {
                $profile->forceFill(['payout_channel_code' => $channelCode])->save();
            }
            // The stored account must also pass the bank-owner check before any
            // funds are reserved.
            $this->assertStoredBankVerified($teacher, $profile);

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

            return ['request' => $payoutRequest, 'payout' => $payout, 'accountChanged' => $bankChangedNow];
        }, 3);

        try {
            $this->gateway->createPayout($records['payout'], $teacher);
        } catch (Throwable $exception) {
            // The bank account must only be stored by a payout that actually
            // reached the gateway, so the whole save is rolled back here.
            if ($records['accountChanged']) {
                DB::transaction(function () use ($teacher, $previousBank) {
                    TeacherProfile::query()->where('user_id', $teacher->id)->update([
                        'bank_name' => $previousBank['bank_name'],
                        'payout_channel_code' => $previousBank['payout_channel_code'],
                        'account_number' => $previousBank['account_number'],
                        'account_name' => $previousBank['account_name'],
                        'bank_account_fingerprint' => $previousBank['bank_account_fingerprint'],
                        'bank_account_changed_at' => $previousBank['bank_account_changed_at'],
                        'payout_hold_until' => $previousBank['payout_hold_until'],
                        'bank_details_version' => $previousBank['bank_details_version'],
                    ]);
                }, 3);
            }
            // An uncertain network result is not a failed transfer. Keep funds
            // reserved until an authenticated terminal webhook reconciles it.
            if ($records['payout']->fresh()->gateway_status === 'FAILED') {
                $this->release($records['payout'], $exception->getMessage());
            }
            throw $exception;
        }

        return ['request' => $records['request']->fresh(), 'payout' => $records['payout']->fresh()];
    }

    /**
     * The payout is the security checkpoint: the bank account is only stored
     * together with the payout itself, after the chosen verification method
     * succeeds. A failed gateway call therefore leaves the account untouched.
     *
     * @return array{0:TeacherProfile,1:bool}
     */
    private function storeBankDetails(User $teacher, TeacherProfile $profile, array $bankDetails): array
    {

        $accountNumber = preg_replace('/\D+/', '', (string) ($bankDetails['account_number'] ?? '')) ?? '';
        $accountName = trim((string) ($bankDetails['account_name'] ?? ''));
        $channelCode = (string) ($bankDetails['payout_channel_code'] ?? XenditPayoutChannelCatalog::inferCode($bankDetails['bank_name'] ?? null));
        $channel = XenditPayoutChannelCatalog::get($channelCode);
        if (! $channel || $accountNumber === '' || $accountName === '') {
            throw new RuntimeException('Data rekening pencairan belum lengkap.');
        }

        $fingerprint = hash_hmac('sha256', implode('|', [
            $channelCode,
            $accountNumber,
            mb_strtolower($accountName),
        ]), (string) config('app.key'));
        if (hash_equals((string) ($profile->bank_account_fingerprint ?? ''), $fingerprint)) {
            return [$profile, false];
        }

        $holdHours = min(72, max(1, (int) (Setting::where('key', 'bank_change_hold_hours')->value('value') ?? 24)));
        $profile->forceFill([
            'bank_name' => $channel['label'],
            'payout_channel_code' => $channelCode,
            'account_number' => $accountNumber,
            'account_name' => $accountName,
            'bank_account_fingerprint' => $fingerprint,
            'bank_account_changed_at' => now(),
            'payout_hold_until' => now()->addHours($holdHours),
            'bank_details_version' => (int) $profile->bank_details_version + 1,
        ])->save();

        Notification::updateOrCreate(
            ['unique_key' => 'teacher-bank-changed:'.$teacher->id.':'.$profile->payout_hold_until],
            [
                'user_id' => $teacher->id,
                'title' => 'Rekening pencairan diubah',
                'message' => 'Pencairan berikutnya ditahan sementara untuk melindungi saldo setelah perubahan rekening.',
                'type' => 'warning',
                'target_url' => '/guru/rekening',
                'is_read' => false,
            ]
        );

        return [$profile->fresh(), true];
    }

    private function assertStoredBankVerified(User $teacher, TeacherProfile $profile): void
    {
        $channelCode = (string) $profile->payout_channel_code;
        $accountNumber = preg_replace('/\D+/', '', (string) $profile->account_number) ?? '';
        if (
            blank($channelCode)
            || $accountNumber === ''
            || blank($profile->account_name)
            || ! $this->bankNameVerifier->available()
            || ! $this->bankNameVerifier->matches($channelCode, $accountNumber, (string) $teacher->name)
        ) {
            throw new RuntimeException('Pencairan belum dapat dikirim: verifikasi nama pemilik rekening langsung dari bank belum tersedia atau belum cocok. Saldo Anda tetap aman.');
        }
    }

    private function assertBankNameMatches(User $teacher, array $bankDetails): void
    {
        $normalize = static fn (string $name): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
        $name = (string) ($bankDetails['account_name'] ?? '');
        if ($normalize($name) !== $normalize((string) $teacher->name)) {
            throw new RuntimeException('Nama pemilik rekening harus sama dengan nama akun tutor.');
        }
        $accountNumber = preg_replace('/\D+/', '', (string) ($bankDetails['account_number'] ?? '')) ?? '';
        if (strlen($accountNumber) < 8 || strlen($accountNumber) > 20) {
            throw new RuntimeException('Nomor rekening atau e-wallet tidak valid.');
        }
        $channelCode = (string) ($bankDetails['payout_channel_code'] ?? XenditPayoutChannelCatalog::inferCode($bankDetails['bank_name'] ?? null));
        if (! $this->bankNameVerifier->available()
            || ! $this->bankNameVerifier->matches($channelCode, $accountNumber, (string) $teacher->name)) {
            throw new RuntimeException('Pencairan belum dapat dikirim: verifikasi nama pemilik rekening langsung dari bank belum tersedia atau belum cocok. Saldo Anda tetap aman.');
        }
    }

    /**
     * @return array{method:string,code:?string,current_password:?string}
     */
    private function assertVerification(User $teacher, ?TeacherProfile $profile, ?array $verification): array
    {
        $method = $profile?->payout_verification_method ?: 'otp';
        $payload = [
            'method' => $method,
            'code' => $verification['otp'] ?? $verification['code'] ?? null,
            'current_password' => $verification['password'] ?? $verification['current_password'] ?? null,
        ];

        if ($method === 'password') {
            if (blank($payload['current_password']) || ! Hash::check((string) $payload['current_password'], $teacher->password)) {
                throw new RuntimeException('Masukkan kata sandi akun yang benar untuk memverifikasi pencairan.');
            }
        } else {
            if (blank($payload['code'])) {
                throw new RuntimeException('Kode verifikasi email wajib diisi sebelum menyimpan rekening atau mencairkan dana.');
            }
        }

        return $payload;
    }

    public function complete(Payout $payout): void
    {
        DB::transaction(function () use ($payout) {
            $locked = Payout::query()->lockForUpdate()->findOrFail($payout->id);
            // `reversed` is terminal; `on_hold` may still settle.
            if (in_array($locked->status, ['completed', 'failed', 'reversed'], true)) {
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
            if (in_array($locked->status, ['completed', 'failed', 'reversed'], true)) {
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
            // Writing status='failed' is safe here: the guard above only lets
            // non-terminal statuses (processing / on_hold) reach this point.
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

    /**
     * Undo the `reserved -> paid` move made by complete() when the bank later
     * returns the transfer. Must run inside the caller's transaction and
     * before release(), because the funds return to the reservation first and
     * then travel back to the available balance.
     *
     * The reversed amount comes from the allocation stored on the payout, not
     * from the current teacher_net_amount which may have changed after a
     * session refund.
     */
    public function revertCompletedAllocation(Payout $payout): void
    {
        $allocations = $payout->allocation_breakdown ?: collect($payout->booking_ids ?? [])->map(fn ($id) => ['booking_id' => $id, 'amount' => null])->all();
        foreach ($allocations as $allocation) {
            $booking = Booking::query()->lockForUpdate()->find($allocation['booking_id'] ?? 0);
            if (! $booking) {
                continue;
            }
            $amount = $allocation['amount'] === null
                ? (float) $booking->teacher_paid_amount
                : (float) $allocation['amount'];
            $paidBefore = (float) $booking->teacher_paid_amount;
            $paid = round(max(0, $paidBefore - $amount), 2);
            if ($paidBefore - $amount < -0.009) {
                Log::warning('Payout reversal clamped teacher_paid_amount', [
                    'payout_id' => $payout->id,
                    'booking_id' => $booking->id,
                    'paid_before' => $paidBefore,
                    'reversal_amount' => $amount,
                ]);
            }
            $reserved = round((float) $booking->teacher_reserved_amount + $amount, 2);
            $available = (float) $booking->teacher_net_amount - $paid - $reserved;
            $booking->forceFill([
                'teacher_paid_amount' => $paid,
                'teacher_reserved_amount' => $reserved,
                'payout_status' => $available > 0.009 ? 'ready' : 'paid',
            ])->save();
        }
        TeacherPayoutRequest::query()->where('payout_id', $payout->id)->update([
            'status' => 'reversed',
        ]);
    }
}
