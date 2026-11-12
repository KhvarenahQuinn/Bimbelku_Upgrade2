<?php

namespace App\Providers;

use App\Models\Booking;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Refund;
use App\Models\TeacherReplacementRequest;
use App\Observers\BookingObserver;
use App\Observers\OrderObserver;
use App\Observers\PayoutObserver;
use App\Observers\RefundObserver;
use App\Policies\TeacherReplacementRequestPolicy;
use App\Services\BankAccountNameVerifier;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        app(BankAccountNameVerifier::class)->assertNotEnabledInProduction();

        Gate::policy(TeacherReplacementRequest::class, TeacherReplacementRequestPolicy::class);

        $actorKey = static function (Request $request): string {
            $userId = $request->user()?->getAuthIdentifier();

            return $userId !== null
                ? 'user:'.(string) $userId
                : 'ip:'.(string) $request->ip();
        };

        $routeValue = static function (Request $request, string $parameter): string {
            $value = $request->route($parameter);

            if ($value instanceof UrlRoutable) {
                return (string) $value->getRouteKey();
            }

            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }

            return 'global';
        };

        /**
         * Register a named limiter whose counter is isolated from every other action.
         *
         * Generic middleware such as `throttle:5,1` derives its key primarily from the
         * authenticated user / IP. When many routes use that same numeric definition,
         * unrelated actions (including background polling) can consume the same bucket.
         * Every mutable / polled action below therefore receives its own named namespace.
         */
        $registerIsolatedLimiter = static function (
            string $name,
            int $perMinute,
            string $message,
            array $routeParameters = [],
            ?string $errorCode = null,
        ) use ($actorKey, $routeValue): void {
            RateLimiter::for($name, function (Request $request) use (
                $name,
                $perMinute,
                $message,
                $routeParameters,
                $errorCode,
                $actorKey,
                $routeValue,
            ) {
                $keyParts = [$name, $actorKey($request)];

                foreach ($routeParameters as $parameter) {
                    $keyParts[] = $parameter.':'.$routeValue($request, $parameter);
                }

                return Limit::perMinute($perMinute)
                    ->by(implode(':', $keyParts))
                    ->response(function (Request $request, array $headers) use ($message, $name, $errorCode) {
                        $retryAfter = max(1, (int) ($headers['Retry-After'] ?? 60));
                        $resolvedMessage = str_replace(':seconds', (string) $retryAfter, $message);

                        return response()->json([
                            'message' => $resolvedMessage,
                            'error_code' => $errorCode ?? str_replace('-', '_', $name).'_rate_limited',
                            'retry_after_seconds' => $retryAfter,
                        ], 429, $headers);
                    });
            });
        };

        // Public reads are intentionally generous but finite to absorb scraping and
        // accidental polling without blocking a normal landing-page visit.
        $registerIsolatedLimiter('public-read', 120, 'Permintaan publik terlalu sering. Tunggu :seconds detik lalu coba lagi.');
        $registerIsolatedLimiter('public-media', 300, 'Media dimuat terlalu sering. Tunggu :seconds detik lalu coba lagi.');

        // Public authentication. Each endpoint owns a separate IP bucket.
        $registerIsolatedLimiter('auth-register', 5, 'Pendaftaran dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.');
        $registerIsolatedLimiter('auth-login', 10, 'Percobaan login terlalu sering. Tunggu :seconds detik lalu coba lagi.');
        $registerIsolatedLimiter('auth-forgot-password', 5, 'Permintaan reset kata sandi terlalu sering. Tunggu :seconds detik lalu coba lagi.');
        $registerIsolatedLimiter('auth-reset-password', 5, 'Reset kata sandi dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.');
        $registerIsolatedLimiter('auth-email-verification-resend', 5, 'Permintaan kode verifikasi terlalu sering. Tunggu :seconds detik lalu coba lagi.');
        $registerIsolatedLimiter('auth-email-verification-verify', 10, 'Percobaan kode verifikasi terlalu sering. Tunggu :seconds detik lalu coba lagi.');
        $registerIsolatedLimiter('guest-package-quote', 20, 'Harga paket terlalu sering dihitung. Tunggu :seconds detik lalu coba lagi.');
        $registerIsolatedLimiter('guest-package-create', 5, 'Pembuatan pesanan dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.');
        $registerIsolatedLimiter('guest-package-status', 20, 'Status pesanan terlalu sering diperiksa. Tunggu :seconds detik lalu coba lagi.', ['code']);
        $registerIsolatedLimiter('guest-package-claim', 5, 'Penyambungan pesanan dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['code']);

        // Shared authenticated actions.
        $registerIsolatedLimiter('account-password-change', 5, 'Perubahan kata sandi dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.');
        $registerIsolatedLimiter('student-payment-pin-set', 5, 'Pengaturan PIN dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.');
        $registerIsolatedLimiter('student-payment-pin-forgot', 5, 'Permintaan lupa PIN terlalu sering. Tunggu :seconds detik lalu coba lagi.');
        $registerIsolatedLimiter('student-payment-pin-forgot-email', 5, 'Permintaan link reset PIN terlalu sering. Tunggu :seconds detik lalu coba lagi.');
        $registerIsolatedLimiter('payment-pin-reset-token', 10, 'Reset PIN lewat email dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.');
        $registerIsolatedLimiter('student-payment-pin-reset-request', 3, 'Permintaan reset PIN terlalu sering. Tunggu :seconds detik lalu coba lagi.');
        $registerIsolatedLimiter('student-payment-pin-reset', 10, 'Percobaan reset PIN terlalu sering. Tunggu :seconds detik lalu coba lagi.');
        $registerIsolatedLimiter('session-action-poll', 60, 'Pemeriksaan status sesi terlalu sering. Tunggu :seconds detik lalu coba lagi.');
        $registerIsolatedLimiter('booking-message-send', 30, 'Pesan dikirim terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['booking']);
        $registerIsolatedLimiter('booking-schedule-options', 30, 'Pilihan jadwal dimuat terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['booking']);
        $registerIsolatedLimiter('booking-schedule-change-create', 5, 'Permintaan perubahan jadwal dibuat terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['booking']);
        $registerIsolatedLimiter('booking-schedule-change-respond', 10, 'Respons perubahan jadwal dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['booking', 'scheduleChangeRequest']);

        // Student actions.
        $registerIsolatedLimiter('student-cheap-class-join', 10, 'Permintaan bergabung kelas dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['cheapClass']);
        $registerIsolatedLimiter('student-cheap-class-cancel', 10, 'Pembatalan kelas dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['cheapClassEnrollment']);
        $registerIsolatedLimiter('student-tutor-availability', 20, 'Pengecekan ketersediaan tutor terlalu sering. Tunggu :seconds detik lalu coba lagi.');
        $registerIsolatedLimiter('student-session-presence-confirm', 10, 'Konfirmasi kehadiran dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['booking']);
        $registerIsolatedLimiter('student-refund-destination', 10, 'Pemilihan tujuan refund dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['refund']);
        $registerIsolatedLimiter('student-refund-sync', 30, 'Status refund diperiksa terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['refund']);
        $registerIsolatedLimiter('student-class-join-request', 10, 'Permintaan bergabung kelas dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.');
        $registerIsolatedLimiter('student-class-code-create', 10, 'Kode kelas dibuat terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['learningPackage']);
        $registerIsolatedLimiter('student-class-join-decision', 10, 'Keputusan permintaan bergabung dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['learningPackage', 'classJoin']);
        $registerIsolatedLimiter('student-payment-status-sync', 30, 'Status pembayaran diperiksa terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['order']);
        $registerIsolatedLimiter('student-package-retry', 5, 'Pencarian tutor diulang terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['learningPackage']);
        $registerIsolatedLimiter('student-package-reschedule', 5, 'Penjadwalan ulang dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['learningPackage']);
        $registerIsolatedLimiter('student-teacher-replacement', 3, 'Pengajuan ganti guru dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['learningPackage', 'packageSubject']);
        $registerIsolatedLimiter('student-teacher-replacement-action', 6, 'Aksi penggantian guru dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['teacherReplacement']);
        $registerIsolatedLimiter('admin-teacher-replacement-review', 10, 'Keputusan penggantian guru dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['teacherReplacement']);
        $registerIsolatedLimiter('student-promotion-preview', 20, 'Pengecekan promo dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.');
        $registerIsolatedLimiter('student-promotion-claim', 10, 'Klaim promo dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['promotion']);

        // Existing package/payment buckets retained with their established API error codes.
        $registerIsolatedLimiter(
            'student-package-quote',
            20,
            'Harga paket terlalu sering dihitung. Tunggu :seconds detik lalu coba lagi.',
            [],
            'package_quote_rate_limited',
        );
        $registerIsolatedLimiter(
            'student-package-create',
            5,
            'Pembuatan paket dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.',
            [],
            'package_create_rate_limited',
        );
        $registerIsolatedLimiter(
            'student-payment-submit',
            5,
            'Bukti pembayaran dikirim terlalu sering. Tunggu :seconds detik lalu coba lagi. Tagihanmu tetap aktif.',
            ['id'],
            'payment_rate_limited',
        );

        // Teacher actions. Financial actions intentionally use an actor-wide action bucket.
        $registerIsolatedLimiter('teacher-cheap-class-meeting-link', 10, 'Tautan pertemuan diperbarui terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['cheapClass']);
        $registerIsolatedLimiter('teacher-cheap-class-start-session', 10, 'Konfirmasi kehadiran kelas dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['cheapClass']);
        $registerIsolatedLimiter('teacher-cheap-class-progress', 20, 'Progress kelas diperbarui terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['cheapClass']);
        $registerIsolatedLimiter('teacher-offer-action', 10, 'Respons tawaran dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['teacherOffer']);
        $registerIsolatedLimiter('teacher-bank-change', 5, 'Perubahan rekening dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.');
        $registerIsolatedLimiter('teacher-payout-request', 5, 'Permintaan pencairan dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.');
        $registerIsolatedLimiter('teacher-session-ready', 10, 'Konfirmasi kesiapan mengajar dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['booking']);
        $registerIsolatedLimiter('teacher-session-checkout', 10, 'Penyelesaian sesi dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['booking']);

        // Admin operational actions.
        $registerIsolatedLimiter('admin-cheap-class-template-create', 10, 'Pembuatan template kelas dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.');
        $registerIsolatedLimiter('admin-cheap-class-template-recurrence', 10, 'Perubahan pengulangan kelas dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['cheapClassTemplate']);
        $registerIsolatedLimiter('admin-cheap-class-retry-teacher', 10, 'Pencarian ulang tutor dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['cheapClass']);
        $registerIsolatedLimiter('admin-cheap-class-session-review', 20, 'Review laporan sesi dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['cheapClass', 'session']);
        $registerIsolatedLimiter('admin-matching-synchronize', 20, 'Sinkronisasi pencarian tutor dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['bookingRequest']);
        $registerIsolatedLimiter('admin-matching-expand-radius', 10, 'Perluasan radius dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['bookingRequest']);
        $registerIsolatedLimiter('admin-matching-assign-teacher', 10, 'Penetapan tutor dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['bookingRequest']);
        $registerIsolatedLimiter('admin-refund-reconcile', 10, 'Pemeriksaan refund dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['refund']);
        $registerIsolatedLimiter('admin-refund-retry', 5, 'Pengulangan refund dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['refund']);
        $registerIsolatedLimiter('admin-tutor-exit', 5, 'Refund keluar tutor dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['learningPackage']);
        $registerIsolatedLimiter('admin-session-decision', 20, 'Keputusan sesi dilakukan terlalu sering. Tunggu :seconds detik lalu coba lagi.');
        $registerIsolatedLimiter('student-session-appeal', 5, 'Banding sesi dibuat terlalu sering. Tunggu :seconds detik lalu coba lagi.', ['booking']);
        $registerIsolatedLimiter('teacher-payout-otp', 5, 'Permintaan kode verifikasi rekening terlalu sering. Tunggu :seconds detik lalu coba lagi.');

        Order::observe(OrderObserver::class);
        Booking::observe(BookingObserver::class);
        Refund::observe(RefundObserver::class);
        Payout::observe(PayoutObserver::class);
    }
}
