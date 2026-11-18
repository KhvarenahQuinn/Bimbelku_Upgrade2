<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'BimbelKu')</title>
    @yield('preheader')
    <style>
        @media only screen and (max-width: 560px) {
            .email-wrapper { width: 100% !important; }
            .email-card { border-radius: 0 !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background:#f8fafc; font-family:Arial,Helvetica,sans-serif; color:#0f172a; line-height:1.6">
    <div class="email-wrapper" style="width:100%; table-layout:fixed; background:#f8fafc; padding:32px 16px">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; margin:0 auto">
            <tr>
                <td class="email-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:16px; overflow:hidden">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                        <tr>
                            <td style="background:#ea580c; padding:24px 28px; text-align:center">
                                <h1 style="margin:0; font-size:28px; font-weight:700; color:#ffffff; letter-spacing:-0.5px">BimbelKu</h1>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:32px 28px 24px">
                                @yield('content')
                            </td>
                        </tr>
                        <tr>
                            <td style="background:#f8fafc; border-top:1px solid #e2e8f0; padding:20px 28px; text-align:center">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 auto">
                                    <tr>
                                        <td style="font-size:12px; color:#64748b; padding-bottom:12px">
                                            <strong>Keamanan:</strong> BimbelKu tidak pernah meminta password, PIN, atau kode verifikasi via email/telepon. Jika email ini mencurigakan, abaikan dan hubungi kami melalui aplikasi resmi.
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="font-size:12px; color:#94a3b8">
                                            &copy; {{ date('Y') }} BimbelKu. Semua hak dilindungi.
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>