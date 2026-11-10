<?php

namespace App\Support;

use App\Models\Refund;

/**
 * Single source of truth for the refund state machine shown to students.
 * Internal statuses stay technical; this presenter translates them into the
 * four-step timeline required by the product specification.
 */
class RefundStatusPresenter
{
    public const STAGE_ELIGIBLE = 'eligible';

    public const STAGE_DESTINATION_SELECTED = 'destination_selected';

    public const STAGE_PREPARING = 'preparing';

    public const STAGE_SUBMITTED = 'submitted';

    public const STAGE_PROCESSING = 'processing';

    public const STAGE_SUCCEEDED = 'succeeded';

    public const STAGE_FAILED = 'failed';

    public const STAGE_NEEDS_REVIEW = 'needs_review';

    /** @return array<string, mixed> */
    public static function present(Refund $refund): array
    {
        $stage = self::stage($refund);

        return [
            'stage' => $stage,
            'stage_label' => self::label($stage),
            'status_message' => self::message($refund, $stage),
            'can_switch_to_balance' => self::canSwitchToBalance($refund),
            'timeline' => self::timeline($refund, $stage),
            'last_reconciled_at' => self::iso($refund->last_reconciled_at),
            'gateway_failure_message' => $refund->gateway_failure_message,
        ];
    }

    public static function stage(Refund $refund): string
    {
        if ($refund->status === 'paid') {
            return self::STAGE_SUCCEEDED;
        }
        if ($refund->status === 'failed' || $refund->gatewayFailed()) {
            return self::STAGE_FAILED;
        }
        if ($refund->gateway_status === 'SUCCEEDED') {
            // The provider already returned the money but local settlement has
            // not completed. Never show this as a normal in-progress refund.
            return self::STAGE_NEEDS_REVIEW;
        }
        if (! $refund->destination_selected_at) {
            return self::STAGE_ELIGIBLE;
        }
        if ($refund->gateway_status === 'SUBMITTING') {
            return self::STAGE_PREPARING;
        }
        if (in_array($refund->gateway_status, ['REQUESTED', 'PENDING', 'PROCESSING', 'ACCEPTED'], true)) {
            return self::STAGE_PROCESSING;
        }
        if ($refund->gateway_submitted_at) {
            return self::STAGE_SUBMITTED;
        }

        return self::STAGE_DESTINATION_SELECTED;
    }

    public static function label(string $stage): string
    {
        return match ($stage) {
            self::STAGE_ELIGIBLE => 'Memenuhi syarat',
            self::STAGE_DESTINATION_SELECTED => 'Tujuan dipilih',
            self::STAGE_PREPARING => 'Sedang disiapkan',
            self::STAGE_SUBMITTED => 'Dikirim',
            self::STAGE_PROCESSING => 'Sedang diproses',
            self::STAGE_SUCCEEDED => 'Berhasil',
            self::STAGE_FAILED => 'Gagal',
            default => 'Perlu pemeriksaan',
        };
    }

    public static function message(Refund $refund, ?string $stage = null): string
    {
        $stage ??= self::stage($refund);

        return match ($stage) {
            self::STAGE_ELIGIBLE => 'Refund memenuhi syarat. Pilih tujuan pengembalian dana untuk melanjutkan.',
            self::STAGE_DESTINATION_SELECTED => 'Tujuan refund sudah dipilih. Sistem akan memproses pengembalian dana.',
            self::STAGE_PREPARING => 'Nominal dan metode pengembalian sedang disiapkan.',
            self::STAGE_SUBMITTED => 'Permintaan pengembalian dana sudah dikirim ke penyedia pembayaran.',
            self::STAGE_PROCESSING => 'Pengembalian dana sedang diproses. Status diperiksa otomatis; perbarui halaman untuk melihat perubahan terbaru.',
            self::STAGE_SUCCEEDED => $refund->destination_method === 'bimbelku_balance'
                ? 'Refund sudah masuk ke Saldo BimbelKu.'
                : 'Pengembalian dana sudah berhasil diselesaikan.',
            self::STAGE_FAILED => self::failureMessage($refund),
            default => 'Pengembalian dana sudah diterima penyedia pembayaran tetapi belum tercatat selesai. Tim BimbelKu akan memeriksa dan menyelesaikannya.',
        };
    }

    public static function canSwitchToBalance(Refund $refund): bool
    {
        return $refund->status !== 'paid'
            && $refund->gatewayFailed()
            && $refund->destination_method === 'xendit_original';
    }

    /**
     * Four visible steps: submitted, sent, processing, and final result.
     *
     * @return array<int, array{key:string,label:string,state:string,at:?string,description:string}>
     */
    public static function timeline(Refund $refund, ?string $stage = null): array
    {
        $stage ??= self::stage($refund);
        $failed = $stage === self::STAGE_FAILED;
        $succeeded = $stage === self::STAGE_SUCCEEDED;
        $sentAt = $refund->gateway_submitted_at ?: $refund->destination_selected_at;
        $processingAt = $refund->last_reconciled_at ?: $refund->gateway_processed_at;
        $resultAt = $refund->processed_at ?: ($failed ? ($refund->gateway_processed_at ?: $refund->updated_at) : null);

        $sentState = match (true) {
            $succeeded, $failed => 'done',
            in_array($stage, [self::STAGE_SUBMITTED, self::STAGE_PROCESSING], true) => 'done',
            $sentAt !== null => 'done',
            default => 'pending',
        };
        $processingState = match (true) {
            $succeeded, $failed => 'done',
            $stage === self::STAGE_PROCESSING => 'current',
            $sentAt !== null => 'current',
            default => 'pending',
        };
        $resultState = match (true) {
            $succeeded => 'done',
            $failed => 'failed',
            default => 'pending',
        };

        return [
            [
                'key' => 'requested',
                'label' => 'Pengajuan',
                'state' => 'done',
                'at' => self::iso($refund->created_at),
                'description' => 'Refund diajukan dan diverifikasi sistem.',
            ],
            [
                'key' => 'sent',
                'label' => 'Dikirim',
                'state' => $sentState,
                'at' => self::iso($sentAt),
                'description' => 'Permintaan diteruskan ke penyedia pembayaran.',
            ],
            [
                'key' => 'processing',
                'label' => 'Sedang diproses',
                'state' => $processingState,
                'at' => self::iso($processingAt),
                'description' => $failed
                    ? 'Pemrosesan berhenti karena penyedia pembayaran menolak permintaan.'
                    : 'Penyedia pembayaran memproses pengembalian dana.',
            ],
            [
                'key' => 'result',
                'label' => $succeeded ? 'Berhasil' : ($failed ? 'Gagal' : 'Berhasil / gagal'),
                'state' => $resultState,
                'at' => self::iso($resultAt),
                'description' => match (true) {
                    $succeeded => 'Dana sudah dikembalikan.',
                    $failed => self::failureMessage($refund),
                    default => 'Hasil akhir akan diperbarui otomatis.',
                },
            ],
        ];
    }

    private static function failureMessage(Refund $refund): string
    {
        $reason = trim((string) $refund->gateway_failure_message);
        if ($reason === '') {
            $reason = trim((string) $refund->gateway_failure_code);
        }
        $base = $reason !== ''
            ? "Pengembalian ke metode pembayaran asal gagal: {$reason}"
            : 'Pengembalian ke metode pembayaran asal gagal.';

        if (self::canSwitchToBalance($refund)) {
            return $base.' Kamu dapat memilih Saldo BimbelKu agar dana tetap dapat dipakai untuk belajar.';
        }

        return $base.' Tim BimbelKu akan memeriksa dan menindaklanjuti.';
    }

    private static function iso($value): ?string
    {
        return $value ? $value->toIso8601String() : null;
    }
}
