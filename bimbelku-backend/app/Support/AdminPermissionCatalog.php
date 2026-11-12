<?php

namespace App\Support;

use Illuminate\Http\Request;

class AdminPermissionCatalog
{
    public const OPERATIONS_DASHBOARD = 'operations.dashboard';

    public const MATCHING_MANAGE = 'matching.manage';

    public const FINANCE_PAYMENTS = 'finance.payments';

    public const FINANCE_PAYOUTS = 'finance.payouts';

    public const FINANCE_REFUNDS = 'finance.refunds';

    public const TEACHERS_MANAGE = 'teachers.manage';

    public const CASES_MANAGE = 'cases.manage';

    public const USERS_MANAGE = 'users.manage';

    public const CLASSES_MANAGE = 'classes.manage';

    public const CONTENT_MANAGE = 'content.manage';

    public const SUPPORT_MANAGE = 'support.manage';

    public const SETTINGS_MANAGE = 'settings.manage';

    public const AUDIT_VIEW = 'audit.view';

    public static function groups(): array
    {
        return [
            [
                'key' => 'operations',
                'label' => 'Operasional',
                'permissions' => [
                    self::definition(self::OPERATIONS_DASHBOARD, 'Lihat dashboard operasional', 'Melihat ringkasan pekerjaan admin dan antrean utama.'),
                    self::definition(self::MATCHING_MANAGE, 'Kelola pencarian tutor', 'Memantau pencarian, memperluas radius, dan menetapkan tutor.'),
                    self::definition(self::CLASSES_MANAGE, 'Kelola kelas', 'Melihat kelas, peserta, pelaksanaan, dan moderasi ulasan.'),
                    self::definition(self::CASES_MANAGE, 'Tangani kasus', 'Memutus sengketa, banding, laporan, dan penyelesaian kelas.'),
                ],
            ],
            [
                'key' => 'finance',
                'label' => 'Keuangan',
                'permissions' => [
                    self::definition(self::FINANCE_PAYMENTS, 'Kelola pembayaran murid', 'Memeriksa bukti pembayaran dan rekening penerimaan.'),
                    self::definition(self::FINANCE_PAYOUTS, 'Kelola pencairan tutor', 'Memeriksa saldo, persetujuan, komisi, dan transfer pencairan.'),
                    self::definition(self::FINANCE_REFUNDS, 'Kelola refund dan saldo', 'Memproses refund ke rekening atau Saldo BimbelKu.'),
                ],
            ],
            [
                'key' => 'people',
                'label' => 'Tutor dan pengguna',
                'permissions' => [
                    self::definition(self::TEACHERS_MANAGE, 'Verifikasi tutor', 'Memeriksa dokumen, menerima, atau menolak tutor.'),
                    self::definition(self::USERS_MANAGE, 'Kelola pengguna', 'Mengaktifkan atau memblokir akun murid dan tutor.'),
                    self::definition(self::SUPPORT_MANAGE, 'Kelola bantuan dan notifikasi', 'Menangani tiket serta mengirim notifikasi.'),
                ],
            ],
            [
                'key' => 'content',
                'label' => 'Katalog dan pengaturan',
                'permissions' => [
                    self::definition(self::CONTENT_MANAGE, 'Kelola katalog dan konten', 'Mengatur mapel, materi, harga, paket, promo, banner, dan tutorial.'),
                    self::definition(self::SETTINGS_MANAGE, 'Kelola pengaturan website', 'Mengatur footer, media sosial, tampilan tutor, dan catatan internal.'),
                ],
            ],
            [
                'key' => 'security',
                'label' => 'Keamanan dan pengawasan',
                'permissions' => [
                    self::definition(self::AUDIT_VIEW, 'Lihat audit perubahan', 'Melihat riwayat perubahan admin dan rantai integritas audit.'),
                ],
            ],
        ];
    }

    public static function allCodes(): array
    {
        return collect(self::groups())
            ->flatMap(fn (array $group) => $group['permissions'])
            ->pluck('code')
            ->values()
            ->all();
    }

    public static function labels(): array
    {
        return collect(self::groups())
            ->flatMap(fn (array $group) => $group['permissions'])
            ->mapWithKeys(fn (array $permission) => [$permission['code'] => $permission['label']])
            ->all();
    }

    public static function permissionFor(Request $request): ?string
    {
        $path = preg_replace('#^api/admin/?#', '', trim($request->path(), '/')) ?? '';

        if ($path === '' || $path === 'dashboard-stats') {
            return self::OPERATIONS_DASHBOARD;
        }

        $rules = [
            '#^tutor-searches(?:/|$)#' => self::MATCHING_MANAGE,
            '#^(?:pending-payments|finance/payments|verify-payment|orders|payment-settings)(?:/|$)#' => self::FINANCE_PAYMENTS,
            '#^(?:finance/refunds|refunds)(?:/|$)#' => self::FINANCE_REFUNDS,
            '#^(?:finance|payout|commission-setting)(?:/|$)#' => self::FINANCE_PAYOUTS,
            '#^(?:pending-teachers|history-teachers|verify-teacher|teachers/.+/(?:verification-stage|schedule))(?:/|$)#' => self::TEACHERS_MANAGE,
            '#^(?:users|accounts)(?:/|$)#' => self::USERS_MANAGE,
            '#^(?:cases|teacher-replacements|disputes|session-reports|bookings/.+/(?:completion-review|attendance-evidence))(?:/|$)#' => self::CASES_MANAGE,
            '#^packages/.+/tutor-exit(?:-preview)?(?:/|$)#' => self::FINANCE_REFUNDS,
            '#^(?:classes|ratings)(?:/|$)#' => self::CLASSES_MANAGE,
            '#^notifications/(?:recipients|send)(?:/|$)#' => self::SUPPORT_MANAGE,
            '#^(?:hourly-rates|private-participant-pricing|program-groups|learning-programs|subject-groups|subjects|chapters|stage-five|cheap-class-templates|cheap-classes)(?:/|$)#' => self::CONTENT_MANAGE,
            '#^(?:website-settings|website-media|website-tutor-gallery|settings/footer|admin-socials|socials|settings/teacher-cover|public-tutors|notes)(?:/|$)#' => self::SETTINGS_MANAGE,
            '#^audit-log(?:/|$)#' => self::AUDIT_VIEW,
        ];

        foreach ($rules as $pattern => $permission) {
            if (preg_match($pattern, $path) === 1) {
                return $permission;
            }
        }

        return null;
    }

    public static function categoryFor(Request $request): string
    {
        $permission = self::permissionFor($request);

        return $permission ? explode('.', $permission)[0] : 'unknown';
    }

    private static function definition(string $code, string $label, string $description): array
    {
        return compact('code', 'label', 'description');
    }
}
