<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\LearningPackage;
use App\Models\Notification;
use App\Models\Order;
use App\Models\PackageRenewal;
use App\Models\PackageSession;
use App\Models\PaymentSetting;
use App\Models\User;
use App\Services\CheapClassService;
use App\Services\CustomerWalletService;
use App\Services\GatewayPaymentSettlementService;
use App\Services\PackageCheckoutService;
use App\Services\PaymentReconciliationService;
use App\Services\RefundSettlementService;
use App\Services\ScheduleReleaseService;
use App\Services\XenditPaymentService;
use App\Support\RefundStatusPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class OrderController extends Controller
{
    public function pay(
        Request $request,
        int $id,
        CheapClassService $cheapClassService,
        PackageCheckoutService $packageCheckoutService,
        CustomerWalletService $wallets,
        PaymentReconciliationService $reconciliation
    ) {
        $walletInput = $request->validate([
            'use_wallet' => ['sometimes', 'boolean'],
            'wallet_expected_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_pin' => ['nullable', 'digits:6'],
        ]);

        $order = Order::query()
            ->where('user_id', $request->user()->id)
            ->with(['booking', 'participant.bookingRequest', 'learningPackage.subjects.sessions.booking', 'cheapClassEnrollment.cheapClass'])
            ->findOrFail($id);

        if (! in_array($order->status, ['pending', 'rejected', 'partially_paid'], true)) {
            return $this->paymentError('Tagihan ini sudah tidak dapat dibayar. Periksa status tagihan sebelum mencoba lagi.', 422, 'invoice_not_payable');
        }

        $isTopUp = $order->status === 'partially_paid';
        $useWallet = $isTopUp
            ? (float) $order->wallet_reserved_amount > 0
            : (bool) ($walletInput['use_wallet'] ?? false);
        $clientWalletExpected = $useWallet && array_key_exists('wallet_expected_amount', $walletInput)
            ? round((float) $walletInput['wallet_expected_amount'], 2)
            : null;
        // A top-up reuses the wallet hold authorized on the first payment.
        // Asking for the PIN again would block the top-up because the client
        // intentionally only submits proof for the remaining bank transfer.
        if ($useWallet && ! $isTopUp) {
            $paymentPin = (string) ($walletInput['payment_pin'] ?? '');
            if (! filled($request->user()->payment_pin_hash)) {
                return $this->paymentError('Buat PIN pembayaran terlebih dahulu untuk menggunakan Saldo BimbelKu.', 409, 'payment_pin_setup_required');
            }
            if ($paymentPin === '' || ! Hash::check($paymentPin, (string) $request->user()->payment_pin_hash)) {
                return $this->paymentError('PIN pembayaran tidak sesuai.', 422, 'payment_pin_invalid');
            }
        }

        $walletQuote = $wallets->quoteForOrder($order, (int) $request->user()->id);
        if ($useWallet && ! $walletQuote['supported']) {
            return $this->paymentError(
                'Saldo BimbelKu hanya tersedia untuk Paket Belajar dan Kelas Kelompok. Tagihan arsip lama tetap memakai transfer.',
                422,
                'wallet_not_supported'
            );
        }
        $walletAmount = $isTopUp
            ? round((float) $order->wallet_reserved_amount, 2)
            : ($useWallet ? round((float) $walletQuote['usable_amount'], 2) : 0.0);
        $orderTotalDue = round(max(0, (float) $order->amount - (float) $order->external_received_amount), 2);
        if ($useWallet && ! $isTopUp && $walletAmount + 0.009 < $orderTotalDue) {
            return $this->paymentError(
                'Saldo BimbelKu belum mencukupi seluruh tagihan. Pembayaran saldo hanya tersedia bila saldo menutup penuh; gunakan metode pembayaran lain untuk tagihan ini.',
                422,
                'wallet_full_payment_required'
            );
        }
        if ($useWallet && ! $isTopUp && $clientWalletExpected !== null && abs($walletAmount - $clientWalletExpected) > 0.009) {
            return $this->paymentError(
                'Saldo yang tersedia berubah. Muat ulang pembayaran sebelum melanjutkan.',
                409,
                'wallet_balance_changed'
            );
        }
        $externalDue = round(max(
            0,
            (float) $order->amount - $walletAmount - (float) $order->external_received_amount
        ), 2);

        if ((bool) config('xendit.enabled') && $externalDue > 0) {
            return $this->paymentError(
                'Pembayaran manual sudah dinonaktifkan. Gunakan pembayaran otomatis.',
                410,
                'manual_payment_retired'
            );
        }

        if ($externalDue > 0) {
            $paymentSettings = PaymentSetting::query()->first();
            if (
                ! $paymentSettings
                || blank($paymentSettings->bank_name)
                || blank($paymentSettings->account_number)
                || blank($paymentSettings->account_name)
            ) {
                return $this->paymentError(
                    'Metode pembayaran belum siap. Admin perlu melengkapi rekening tujuan sebelum bukti dapat dikirim.',
                    503,
                    'payment_settings_unavailable'
                );
            }
        }

        $required = $externalDue > 0 ? 'required' : 'nullable';
        $validated = $request->validate([
            'file' => [$required, 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'sender_name' => [$required, 'string', 'max:150', 'regex:/\pL/u', 'not_regex:/\d/u'],
            'bank_name' => [$required, 'string', 'max:100', 'regex:/\pL/u'],
            'sender_account_number' => [$required, 'string', 'min:8', 'max:20', 'regex:/^[0-9]+$/'],
            'use_wallet' => ['sometimes', 'boolean'],
            'wallet_expected_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_pin' => ['nullable', 'digits:6'],
        ], [
            'sender_name.regex' => 'Nama pemilik rekening wajib mengandung huruf.',
            'sender_name.not_regex' => 'Nama pemilik rekening tidak boleh memuat angka.',
            'bank_name.regex' => 'Nama bank atau e-wallet wajib mengandung huruf.',
            'sender_account_number.min' => 'Nomor rekening atau e-wallet minimal 8 digit.',
            'sender_account_number.max' => 'Nomor rekening atau e-wallet maksimal 20 digit.',
            'sender_account_number.regex' => 'Nomor rekening atau e-wallet hanya boleh berisi angka.',
        ]);
        $validated['use_wallet'] = $useWallet;
        $validated['wallet_expected_amount'] = $useWallet ? ($clientWalletExpected ?? $walletAmount) : 0;
        $validated['external_due'] = $externalDue;

        if ($order->learning_package_id) {
            return $this->payPackage($request, $order, $validated, $packageCheckoutService, $wallets, $reconciliation);
        }
        if ($order->cheap_class_enrollment_id) {
            return $this->payCheapClass($request, $order, $validated, $cheapClassService, $reconciliation);
        }

        return $this->paymentError('Tagihan arsip pemesanan langsung sudah dipensiunkan.', 410, 'legacy_order_retired');
    }

    public function getActiveOrder(
        Request $request,
        CheapClassService $cheapClassService,
        PaymentReconciliationService $reconciliation
    ) {
        $cheapClassService->refreshLifecycle();
        $order = Order::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('status', ['pending', 'rejected', 'partially_paid'])
            ->where(function ($query) {
                $query->whereNotNull('learning_package_id')
                    ->orWhereNotNull('cheap_class_enrollment_id');
            })
            ->with(['learningPackage.plan', 'cheapClassEnrollment.cheapClass'])
            ->latest()
            ->first();

        if (! $order) {
            return response()->json(null);
        }
        if ($order->learning_package_id) {
            if ($order->learningPackage?->payment_due_at?->isPast()) {
                $this->expirePackageOrder($order, $reconciliation);

                return response()->json(null);
            }
            if (! in_array($order->learningPackage?->status, ['awaiting_payment', 'payment_rejected', 'partially_paid'], true)) {
                return response()->json(null);
            }
        } elseif ($order->cheap_class_enrollment_id) {
            $enrollment = $order->cheapClassEnrollment;
            $class = $enrollment?->cheapClass;
            if (! $enrollment || ! $class || ! in_array($enrollment->status, ['seat_held', 'payment_rejected', 'partially_paid'], true) || ! $enrollment->seat_expires_at?->isFuture()) {
                return response()->json(null);
            }
        } else {
            return response()->json(null);
        }

        $details = is_array($order->class_details_snapshot)
            ? $order->class_details_snapshot
            : json_decode((string) $order->class_details_snapshot, true);
        $cheapEnrollment = $order->cheapClassEnrollment;
        $cheapClass = $cheapEnrollment?->cheapClass;
        $isCheapClass = $order->cheap_class_enrollment_id !== null;
        $canCancel = $isCheapClass
            && $cheapEnrollment
            && $cheapClass?->registration_deadline?->isFuture()
            && in_array($cheapEnrollment->status, ['seat_held', 'payment_rejected'], true)
            && in_array($order->status, ['pending', 'rejected'], true);
        $canResubmit = $isCheapClass
            && $cheapEnrollment?->status === 'payment_rejected'
            && $order->status === 'rejected'
            && $cheapEnrollment?->seat_expires_at?->isFuture()
            && $cheapClass?->registration_deadline?->isFuture()
            && in_array($cheapClass?->status, ['open', 'registration_closed', 'awaiting_verification'], true)
            && $cheapClass->enrollments()->where('status', 'confirmed')->count() < (int) $cheapClass->maximum_participants;

        return response()->json([
            'order_id' => $order->id,
            'order_number' => $order->order_id,
            'amount' => $order->amount,
            'status' => $order->status,
            'rejection_reason' => $order->payment_rejection_reason,
            'created_at' => $order->created_at,
            'payment_due_at' => $order->learningPackage?->payment_due_at ?? $order->cheapClassEnrollment?->seat_expires_at,
            'subject' => $details['subject'] ?? 'Kelas',
            'type' => ucfirst((string) ($details['method'] ?? 'online')).' · '.(
                $isCheapClass
                    ? 'Kelas Kelompok'
                    : ($order->learning_package_id ? 'Paket Belajar' : 'Privat 1-on-1')
            ),
            'tutor_name' => $details['teacher_name'] ?? 'Tutor',
            'scheduled_at' => $details['start_at'] ?? null,
            'subtotal_amount' => $order->subtotal_amount,
            'discount_amount' => $order->discount_amount,
            'package_name' => $details['package_name'] ?? null,
            'duration_hours' => (int) ($details['duration_hours'] ?? $order->learningPackage?->duration_hours ?? 1),
            'total_learning_hours' => (int) ($details['total_learning_hours'] ?? 0),
            'order_kind' => $isCheapClass ? 'cheap_class' : ($order->learning_package_id ? 'package' : 'booking'),
            'enrollment_status' => $cheapEnrollment?->status,
            'cheap_class_status' => $cheapClass?->status,
            'cheap_class_cancellation_reason' => $cheapClass?->cancellation_reason,
            'can_cancel' => (bool) $canCancel,
            'can_resubmit' => (bool) $canResubmit,
            'will_refund_if_accepted' => false,
            'external_received_amount' => (float) $order->external_received_amount,
            'payment_outstanding_amount' => (float) ($order->payment_outstanding_amount ?: max(0, (float) $order->amount - (float) $order->wallet_reserved_amount - (float) $order->external_received_amount)),
            'payment_reconciliation_status' => $order->payment_reconciliation_status,
            'top_up_due_at' => $order->top_up_due_at,
        ]);
    }

    public function cancelOrder(
        Request $request,
        int $id,
        CheapClassService $cheapClassService,
        XenditPaymentService $gateway,
        GatewayPaymentSettlementService $settlement
    ) {
        $order = Order::query()->where('user_id', $request->user()->id)
            ->with(['learningPackage', 'cheapClassEnrollment.cheapClass'])->findOrFail($id);
        try {
            $payment = Cache::lock('gateway-order-settlement:'.$order->id, 180)->block(15,
                function () use ($order, $request, $cheapClassService, $gateway) {
                    $current = $order->fresh(['learningPackage', 'cheapClassEnrollment.cheapClass']);
                    if ($current->status === 'cancelled') {
                        // Also close checkout links left behind by older code.
                        return $gateway->cancelSession($current);
                    }
                    abort_unless(in_array($current->status, ['pending', 'rejected'], true), 422,
                        'Tagihan tidak dapat dibatalkan setelah pembayaran diterima atau sedang diperiksa.');
                    abort_unless($current->learning_package_id || $current->cheap_class_enrollment_id, 410,
                        'Tagihan arsip pemesanan langsung sudah dipensiunkan.');
                    if ($enrollment = $current->cheapClassEnrollment) {
                        abort_unless(in_array($enrollment->status, ['seat_held', 'payment_rejected'], true)
                            && $enrollment->cheapClass?->registration_deadline?->isFuture(), 422,
                            'Keikutsertaan sudah tidak dapat dibatalkan.');
                    }
                    $paid = $gateway->cancelSession($current);
                    if ($paid !== null) {
                        return $paid;
                    }
                    if ($current->cheap_class_enrollment_id) {
                        $cheapClassService->cancelEnrollment($current->cheapClassEnrollment, $request->user());
                    } else {
                        $this->cancelPackageOrder($current);
                    }

                    return null;
                });
            if ($payment !== null) {
                // Settlement uses the same lock: run it after releasing the
                // cancellation lock so a payment race cannot deadlock.
                $settlement->settle($order, $payment);

                return response()->json(['message' => 'Pembayaran sudah diterima. Tagihan belum bayar tidak dapat dibatalkan; periksa status pesanan atau pengembalian dana di Riwayat Pembayaran.'], 422);
            }

            return response()->json(['message' => 'Pesanan dan checkout pembayaran berhasil dibatalkan.']);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }
    }

    public function index(
        Request $request,
        CheapClassService $cheapClassService,
        PaymentReconciliationService $reconciliation,
        RefundSettlementService $refundSettlement
    ) {
        $cheapClassService->refreshLifecycle();

        // Let the student's transaction history settle a gateway refund that
        // already succeeded, even if the webhook or scheduler was missed.
        $refundSettlement->reconcileDue($request->user()->id, 5, 10);

        Order::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('status', ['pending', 'rejected', 'partially_paid'])
            ->whereNotNull('learning_package_id')
            ->whereHas('learningPackage', fn ($packages) => $packages
                ->whereNotNull('payment_due_at')
                ->where('payment_due_at', '<=', now()))
            ->with('learningPackage')
            ->get()
            ->each(fn (Order $order) => $this->expirePackageOrder($order, $reconciliation));

        $orders = Order::query()
            ->where('user_id', $request->user()->id)
            ->where(function ($query) {
                $query->whereNotNull('learning_package_id')
                    ->orWhereNotNull('cheap_class_enrollment_id');
            })
            // Paket sumber demo adalah riwayat belajar buatan, bukan transaksi
            // yang dibayar murid. Simpan untuk alur renewal, tetapi jangan
            // tampilkan sebagai pembayaran kedua di riwayat murid.
            ->where(function ($query) {
                $query->whereNull('order_id')
                    ->orWhere('order_id', 'not like', 'DEMO-RENEW-OLD-%');
            })
            ->with(['refund', 'learningPackage.plan', 'cheapClassEnrollment.cheapClass'])
            ->latest()
            ->paginate(20);

        $orders->getCollection()->transform(function (Order $order) {
            $details = is_array($order->class_details_snapshot)
                ? $order->class_details_snapshot
                : json_decode((string) $order->class_details_snapshot, true);
            $isCheapClass = $order->cheap_class_enrollment_id !== null;
            $orderKind = $isCheapClass ? 'cheap_class' : 'package';
            $cheapEnrollment = $order->cheapClassEnrollment;
            $cheapClass = $cheapEnrollment?->cheapClass;
            $refundPayload = null;
            if ($order->refund) {
                $breakdown = $order->refund->tenderBreakdown();
                $refundPayload = array_merge($order->refund->toArray(), RefundStatusPresenter::present($order->refund), [
                    'wallet_funded_amount' => $breakdown['wallet_funded_amount'],
                    'external_funded_amount' => $breakdown['external_funded_amount'],
                ]);
            }

            return [
                'id' => $order->id,
                'order_id' => $order->order_id,
                'amount' => $order->amount,
                'status' => $order->status,
                'created_at' => $order->created_at,
                'verified_at' => $order->verified_at,
                'payment_proof_url' => $order->payment_proof_url,
                'subject' => $details['subject'] ?? '-',
                'tutor_name' => $details['teacher_name'] ?? '-',
                'type' => ucfirst((string) ($details['method'] ?? 'online')).' · '.(
                    $orderKind === 'cheap_class'
                        ? 'Kelas Kelompok'
                        : 'Paket Belajar'
                ),
                'schedule' => $details['start_at'] ?? null,
                'payment_due_at' => $order->learningPackage?->payment_due_at ?? $order->cheapClassEnrollment?->seat_expires_at,
                'booking_id' => $order->booking_id,
                'learning_package_id' => $order->learning_package_id,
                'renewal_of_id' => $order->learningPackage?->renewal_of_id,
                'package_name' => $order->learningPackage?->plan?->name,
                'order_kind' => $orderKind,
                'duration_hours' => (int) ($details['duration_hours'] ?? $order->learningPackage?->duration_hours ?? 1),
                'total_learning_hours' => (int) ($details['total_learning_hours'] ?? 0),
                'subtotal_amount' => $order->subtotal_amount,
                'discount_amount' => $order->discount_amount,
                'wallet_reserved_amount' => (float) $order->wallet_reserved_amount,
                'wallet_applied_amount' => (float) $order->wallet_applied_amount,
                'external_payment_amount' => round(max(0, (float) $order->amount - (float) ($order->status === 'submitted' ? $order->wallet_reserved_amount : $order->wallet_applied_amount)), 2),
                'payment_provider' => $order->payment_provider,
                'gateway_status' => $order->gateway_status,
                'gateway_session_id' => $order->gateway_session_id,
                'gateway_expires_at' => $order->gateway_expires_at,
                'enrollment_status' => $cheapEnrollment?->status,
                'cheap_class_status' => $cheapClass?->status,
                'cheap_class_cancellation_reason' => $cheapClass?->cancellation_reason,
                'refund' => $refundPayload,
                'payment_rejection_reason' => $order->payment_rejection_reason,
            ];
        });

        return response()->json($orders);
    }

    private function payCheapClass(Request $request, Order $order, array $validated, CheapClassService $cheapClassService, PaymentReconciliationService $reconciliation)
    {
        $path = $validated['external_due'] > 0
            ? $request->file('file')->store('payment_proofs', 'local')
            : null;
        $previousProof = $order->payment_proof;
        $autoVerifyResult = null;
        try {
            $submit = function () use ($cheapClassService, $order, $validated, $path) {
                $cheapClassService->submitPayment(
                    $order,
                    $validated,
                    $path,
                    (bool) $validated['use_wallet'],
                    (float) $validated['wallet_expected_amount']
                );
            };

            if ((float) $validated['external_due'] <= 0) {
                // Full-wallet checkout harus atomik. Jika verifikasi otomatis
                // gagal, reserve/order/ledger ikut rollback sehingga saldo tidak
                // tertahan pada submitted order tanpa bukti transfer.
                $autoVerifyResult = DB::transaction(function () use ($submit, $cheapClassService, $order) {
                    $submit();

                    return $cheapClassService->verifyPayment($order->fresh(), 'paid', '', null);
                }, 3);
            } else {
                $submit();
            }
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }

            if ($exception instanceof HttpExceptionInterface) {
                $message = $exception->getMessage();
                if ($exception->getStatusCode() === 409 && str_contains($message, 'Saldo yang tersedia berubah')) {
                    return $this->paymentError($message, 409, 'wallet_balance_changed');
                }
                if ($exception->getStatusCode() === 503 && str_contains($message, 'pemeriksaan integritas')) {
                    return $this->paymentError($message, 503, 'wallet_integrity_locked');
                }
                if ($exception->getStatusCode() === 422 && $message === 'Batas pembayaran sudah berakhir.') {
                    $cheapClassService->refreshLifecycle(false);

                    return $this->paymentError(
                        'Batas pembayaran sudah berakhir. Tagihan tidak lagi menerima bukti transfer.',
                        422,
                        'payment_expired'
                    );
                }
                if ($exception->getStatusCode() === 422 && in_array($message, [
                    'Tagihan Kelas Kelompok tidak memiliki data peserta yang valid.',
                    'Tagihan ini sudah tidak dapat dibayar.',
                    'Kursi tidak lagi menerima pembayaran.',
                    'Kelas tidak lagi menerima pembayaran.',
                    'Kuota peserta terverifikasi sudah penuh sehingga bukti tidak dapat dikirim ulang.',
                ], true)) {
                    return $this->paymentError($message, 422, 'invoice_not_payable');
                }
            }
            throw $exception;
        }

        if ($path) {
            $reconciliation->recordSubmission($order->fresh(), $path, $validated);
        }

        if ((float) $validated['external_due'] <= 0) {
            $result = $autoVerifyResult ?? ['message' => 'Pembayaran dengan Saldo BimbelKu berhasil.'];

            return response()->json([
                'message' => $result['message'] ?? 'Pembayaran dengan Saldo BimbelKu berhasil.',
                'status' => $order->fresh()->status,
                'wallet_used' => (float) $order->fresh()->wallet_applied_amount,
                'external_due' => 0,
            ]);
        }

        return response()->json([
            'message' => (float) $validated['wallet_expected_amount'] > 0
                ? 'Saldo BimbelKu sudah dialokasikan. Bukti transfer sisa tagihan berhasil dikirim untuk diperiksa.'
                : 'Bukti transfer berhasil dikirim. Kursi tetap aman selama admin memeriksa bukti.',
            'status' => 'submitted',
            'wallet_reserved' => (float) $order->fresh()->wallet_reserved_amount,
            'external_due' => (float) $validated['external_due'],
        ]);
    }

    private function payPackage(
        Request $request,
        Order $order,
        array $validated,
        PackageCheckoutService $packageCheckoutService,
        CustomerWalletService $wallets,
        PaymentReconciliationService $reconciliation
    ) {
        $package = $order->learningPackage;
        if (! $package || ! in_array($package->status, ['awaiting_payment', 'payment_rejected', 'partially_paid'], true)) {
            return $this->paymentError('Paket tidak lagi menunggu pembayaran. Periksa status paket sebelum mencoba lagi.', 422, 'package_not_payable');
        }
        if ($package->payment_due_at?->isPast()) {
            $this->expirePackageOrder($order, $reconciliation);

            return $this->paymentError('Batas pembayaran paket sudah berakhir. Buat tagihan baru sebelum membayar.', 422, 'payment_expired');
        }
        if ($conflict = $this->findPackagePaymentConflict($package)) {
            return $this->scheduleConflictResponse($conflict);
        }

        $path = $validated['external_due'] > 0
            ? $request->file('file')->store('payment_proofs', 'local')
            : null;
        $previousProof = null;
        $activationResult = null;
        try {
            $submit = function () use ($order, $path, $validated, $wallets, &$previousProof) {
                $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
                User::query()->lockForUpdate()->findOrFail($lockedOrder->user_id);
                $package = $lockedOrder->learningPackage()->lockForUpdate()->firstOrFail();
                abort_unless(
                    in_array($lockedOrder->status, ['pending', 'rejected', 'partially_paid'], true)
                        && in_array($package->status, ['awaiting_payment', 'payment_rejected', 'partially_paid'], true),
                    422,
                    'Tagihan paket sudah tidak dapat dibayar.'
                );
                abort_if($package->payment_due_at?->isPast(), 422, 'Batas pembayaran paket sudah berakhir. Tagihan tidak lagi menerima bukti transfer.');
                if ($conflict = $this->findPackagePaymentConflict($package)) {
                    abort(422, $conflict['message']);
                }

                $walletReserved = (float) $lockedOrder->wallet_reserved_amount > 0
                    ? (float) $lockedOrder->wallet_reserved_amount
                    : ($validated['use_wallet']
                    ? $wallets->reserveForPayment(
                        $lockedOrder,
                        (int) $lockedOrder->user_id,
                        (float) $validated['wallet_expected_amount'],
                        (int) $lockedOrder->user_id
                    )
                    : 0.0);
                $externalDue = round(max(0, (float) $lockedOrder->amount - $walletReserved - (float) $lockedOrder->external_received_amount), 2);
                abort_if($externalDue > 0 && ! $path, 422, 'Bukti pembayaran eksternal wajib diunggah untuk sisa tagihan.');
                abort_if($externalDue <= 0 && $path, 422, 'Tagihan ini sudah tertutup penuh oleh Saldo BimbelKu.');

                $previousProof = $lockedOrder->payment_proof;
                $lockedOrder->update([
                    'status' => 'submitted',
                    'payment_proof' => $path,
                    'sender_name' => $validated['sender_name'] ?? null,
                    'bank_name' => $validated['bank_name'] ?? null,
                    'sender_account_number' => $validated['sender_account_number'] ?? null,
                    'payment_rejection_reason' => null,
                    'payment_submitted_at' => now(),
                ]);
                $package->update(['status' => 'payment_submitted']);
                $package->subjects()
                    ->with(['sessions.booking', 'bookingRequest'])
                    ->get()
                    ->each(function ($subject) {
                        $subject->update(['status' => 'payment_submitted']);
                        $subject->bookingRequest?->update(['status' => 'payment_submitted']);
                        $subject->sessions->each(function ($session) {
                            $session->update(['status' => 'payment_submitted']);
                            $session->booking?->update(['status' => 'payment_submitted']);
                            $session->booking?->participants()->update(['status' => 'payment_submitted']);
                        });
                    });
            };

            if ((float) $validated['external_due'] <= 0) {
                // Reserve + auto-settlement full wallet berada pada transaksi
                // luar yang sama. Exception saat aktivasi tidak boleh meninggalkan
                // saldo tertahan pada submitted order tanpa bukti transfer.
                DB::transaction(function () use ($submit, $packageCheckoutService, $order, &$activationResult) {
                    $submit();
                    $activationResult = $packageCheckoutService->activatePaidPackage($order->fresh(), null);
                }, 3);
            } else {
                DB::transaction($submit, 3);
            }
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 409 && str_contains($exception->getMessage(), 'Saldo yang tersedia berubah')) {
                return $this->paymentError($exception->getMessage(), 409, 'wallet_balance_changed');
            }
            if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 503 && str_contains($exception->getMessage(), 'pemeriksaan integritas')) {
                return $this->paymentError($exception->getMessage(), 503, 'wallet_integrity_locked');
            }
            throw $exception;
        }

        if ($path) {
            $reconciliation->recordSubmission($order->fresh(), $path, $validated);
        }

        if ((float) $validated['external_due'] <= 0) {
            $result = $activationResult ?? 'matching';

            return response()->json([
                'message' => $result === 'refund_pending'
                    ? 'Saldo diterapkan, tetapi sesi pertama sudah dimulai. Refund penuh masuk antrean admin.'
                    : ($result === 'no_teacher'
                        ? 'Pembayaran dengan Saldo BimbelKu diterima. Sistem belum menemukan tutor yang tersedia.'
                        : 'Pembayaran dengan Saldo BimbelKu diterima dan pencarian tutor dimulai.'),
                'status' => $order->fresh()->status,
                'wallet_used' => (float) $order->fresh()->wallet_applied_amount,
                'external_due' => 0,
            ]);
        }

        User::query()->where('role', 'admin')->pluck('id')->each(
            fn (int $adminId) => Notification::updateOrCreate(
                ['unique_key' => "package-payment-proof:{$order->id}:{$adminId}"],
                [
                    'user_id' => $adminId,
                    'title' => 'Bukti pembayaran paket',
                    'message' => "Tagihan {$order->order_id} menunggu pemeriksaan.",
                    'type' => 'info',
                    'target_url' => '/admin/finance?tab=payments',
                    'is_read' => false,
                ]
            )
        );

        return response()->json([
            'message' => (float) $validated['wallet_expected_amount'] > 0
                ? 'Saldo BimbelKu sudah dialokasikan. Bukti transfer sisa tagihan paket berhasil dikirim.'
                : 'Bukti transfer paket berhasil dikirim.',
            'status' => 'submitted',
            'wallet_reserved' => (float) $order->fresh()->wallet_reserved_amount,
            'external_due' => (float) $validated['external_due'],
        ]);
    }

    private function paymentError(string $message, int $status, string $code, array $extra = [])
    {
        return response()->json([
            'message' => $message,
            'error_code' => $code,
            ...$extra,
        ], $status);
    }

    private function scheduleConflictResponse(array $conflict)
    {
        return $this->paymentError(
            $conflict['message'],
            422,
            'schedule_conflict',
            ['conflict' => $conflict['conflict']]
        );
    }

    private function findBookingPaymentConflict(Booking $booking): ?array
    {
        if (! $booking->start_at || ! $booking->end_at) {
            return null;
        }

        $hasConflict = Booking::query()
            ->where('student_id', $booking->student_id)
            ->where('id', '!=', $booking->id)
            ->whereIn('status', [
                'awaiting_payment', 'payment_submitted', 'confirmed', 'in_progress',
                'awaiting_student_approval', 'disputed', 'admin_review_required',
            ])
            ->where('start_at', '<', $booking->end_at)
            ->where('end_at', '>', $booking->start_at)
            // Only a fully released order or the session's own released
            // request frees the slot; a partial refund keeps the rest.
            ->whereDoesntHave('order', fn ($order) => $order
                ->whereIn('status', ['refunded', 'cancelled', 'expired']))
            ->whereDoesntHave('bookingRequest', fn ($bookingRequest) => $bookingRequest
                ->whereIn('status', ['refund_pending', 'refunded', 'cancelled', 'payment_expired', 'expired']))
            ->whereDoesntHave('bookingRequest.packageSubject.package', fn ($package) => $package
                ->whereIn('status', ['refund_pending', 'refunded', 'cancelled', 'payment_expired']))
            ->exists();

        if (! $hasConflict) {
            return null;
        }

        return $this->scheduleConflictPayload(
            'Kelas',
            $booking->start_at,
            $booking->end_at
        );
    }

    private function findPackagePaymentConflict(LearningPackage $package): ?array
    {
        $package->loadMissing('subjects.sessions');

        foreach ($package->subjects as $subject) {
            foreach ($subject->sessions as $session) {
                if (! $session->scheduled_start_at || ! $session->scheduled_end_at) {
                    continue;
                }

                $bookingConflict = Booking::query()
                    ->where('student_id', $package->student_id)
                    ->whereIn('status', [
                        'awaiting_payment', 'payment_submitted', 'confirmed', 'in_progress',
                        'awaiting_student_approval', 'disputed', 'admin_review_required',
                    ])
                    ->where('start_at', '<', $session->scheduled_end_at)
                    ->where('end_at', '>', $session->scheduled_start_at)
                    ->whereDoesntHave('order', fn ($order) => $order
                        ->whereIn('status', ['refunded', 'cancelled', 'expired']))
                    ->whereDoesntHave('bookingRequest', fn ($bookingRequest) => $bookingRequest
                        ->whereIn('status', ['refund_pending', 'refunded', 'cancelled', 'payment_expired', 'expired']))
                    ->whereDoesntHave('bookingRequest.packageSubject.package', fn ($other) => $other
                        ->whereIn('status', ['refund_pending', 'refunded', 'cancelled', 'payment_expired']))
                    ->whereDoesntHave('bookingRequest.packageSubject', fn ($query) => $query
                        ->where('learning_package_id', $package->id))
                    ->exists();

                $packageConflict = PackageSession::query()
                    ->where('id', '!=', $session->id)
                    ->whereHas('subject.package', fn ($query) => $query
                        ->where('student_id', $package->student_id)
                        ->where('id', '!=', $package->id)
                        ->whereNotIn('status', ['cancelled', 'payment_expired', 'completed', 'refund_pending', 'refunded']))
                    ->whereNotIn('status', ['cancelled', 'payment_expired', 'completed', 'refund_pending', 'refunded'])
                    ->where('scheduled_start_at', '<', $session->scheduled_end_at)
                    ->where('scheduled_end_at', '>', $session->scheduled_start_at)
                    ->exists();

                if ($bookingConflict || $packageConflict) {
                    return $this->scheduleConflictPayload(
                        $subject->subject_name ?: 'Sesi paket',
                        $session->scheduled_start_at,
                        $session->scheduled_end_at
                    );
                }
            }
        }

        return null;
    }

    private function scheduleConflictPayload(string $subject, $start, $end): array
    {
        $timezone = config('app.timezone', 'Asia/Jakarta');
        $localStart = $start->copy()->timezone($timezone);
        $localEnd = $end->copy()->timezone($timezone);
        $startLabel = $localStart->format('d/m/Y H.i');
        $endLabel = $localEnd->format('H.i');

        return [
            'message' => "Jadwal {$subject} pada {$startLabel}–{$endLabel} bertabrakan dengan kelas atau paket lain yang masih aktif. Jika belum transfer, jangan lanjutkan pembayaran dan atur ulang jadwal. Jika sudah transfer, jangan membayar ulang; hubungi admin melalui Bantuan agar pembayaran dapat ditindaklanjuti.",
            'conflict' => [
                'subject' => $subject,
                'start_at' => $localStart->toIso8601String(),
                'end_at' => $localEnd->toIso8601String(),
            ],
        ];
    }

    private function expirePackageOrder(
        Order $order,
        PaymentReconciliationService $reconciliation
    ): void {
        DB::transaction(function () use ($order, $reconciliation) {
            $lockedOrder = Order::query()->lockForUpdate()->find($order->id);
            if (! $lockedOrder || ! in_array($lockedOrder->status, ['pending', 'rejected', 'partially_paid'], true)) {
                return;
            }
            $package = $lockedOrder->learningPackage()->lockForUpdate()->first();
            $reconciliation->returnExpiredPartialPayment($lockedOrder);
            $lockedOrder->update(['status' => 'expired']);
            if (! $package) {
                return;
            }

            $package->update(['status' => 'payment_expired', 'payment_due_at' => null]);
            $package->subjects()->with(['sessions.booking.bookingRequest', 'bookingRequest.offers'])->get()->each(
                function ($subject) {
                    $subject->update(['status' => 'payment_expired']);
                    $subject->bookingRequest?->update(['status' => 'payment_expired']);
                    $subject->bookingRequest?->offers()
                        ->whereIn('status', ['pending', 'accepted'])
                        ->update(['status' => 'cancelled', 'responded_at' => now()]);
                    $subject->sessions->each(function ($session) {
                        $session->update(['status' => 'payment_expired']);
                        $session->booking?->update(['status' => 'payment_expired', 'payout_status' => 'cancelled']);
                        $session->booking?->participants()->update(['status' => 'payment_expired']);
                        $bookingRequest = $session->booking?->bookingRequest;
                        $bookingRequest?->update(['status' => 'payment_expired']);
                        if ($bookingRequest) {
                            $bookingRequest->offers()
                                ->whereIn('status', ['pending', 'accepted'])
                                ->update(['status' => 'cancelled', 'responded_at' => now()]);
                        }
                    });
                }
            );
            $package->promotionClaims()
                ->where('status', 'reserved')
                ->update(['status' => 'available', 'released_at' => now(), 'learning_package_id' => null]);
            app(ScheduleReleaseService::class)->releasePackage($package, 'payment_expired', 'payment_expired');
            PackageRenewal::query()
                ->where('new_package_id', $package->id)
                ->whereIn('status', ['requested', 'tutor_accepted'])
                ->update(['status' => 'cancelled']);
            Notification::updateOrCreate(
                ['unique_key' => "package-payment-expired:{$lockedOrder->id}:{$lockedOrder->user_id}"],
                [
                    'user_id' => $lockedOrder->user_id,
                    'title' => 'Batas pembayaran paket berakhir',
                    'message' => 'Tagihan dibatalkan karena batas waktu pembayaran sudah berakhir.',
                    'type' => 'warning',
                    'target_url' => '/student/history',
                    'is_read' => false,
                ]
            );
        }, 3);
    }

    private function cancelPackageOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            abort_unless(in_array($lockedOrder->status, ['pending', 'rejected'], true), 422);
            $package = $lockedOrder->learningPackage()->lockForUpdate()->firstOrFail();
            $lockedOrder->update(['status' => 'cancelled']);
            $package->update(['status' => 'cancelled', 'payment_due_at' => null]);
            $package->subjects()->with(['sessions.booking.bookingRequest', 'bookingRequest.offers'])->get()->each(
                function ($subject) {
                    $subject->update(['status' => 'cancelled']);
                    $subject->bookingRequest?->update(['status' => 'cancelled']);
                    $subject->bookingRequest?->offers()
                        ->whereIn('status', ['pending', 'accepted'])
                        ->update(['status' => 'cancelled', 'responded_at' => now()]);
                    $subject->sessions->each(function ($session) {
                        $session->update(['status' => 'cancelled']);
                        $session->booking?->update(['status' => 'cancelled', 'payout_status' => 'cancelled']);
                        $session->booking?->participants()->update(['status' => 'cancelled']);
                        $bookingRequest = $session->booking?->bookingRequest;
                        $bookingRequest?->update(['status' => 'cancelled']);
                        if ($bookingRequest) {
                            $bookingRequest->offers()
                                ->whereIn('status', ['pending', 'accepted'])
                                ->update(['status' => 'cancelled', 'responded_at' => now()]);
                        }
                    });
                }
            );
            $package->promotionClaims()
                ->where('status', 'reserved')
                ->update(['status' => 'available', 'released_at' => now(), 'learning_package_id' => null]);
            app(ScheduleReleaseService::class)->releasePackage($package, 'cancelled', 'cancelled');
            PackageRenewal::query()
                ->where('new_package_id', $package->id)
                ->whereIn('status', ['requested', 'tutor_accepted'])
                ->update(['status' => 'cancelled']);
        }, 3);
    }
}
