<?php

return [
    /*
    | Cloudflare Turnstile untuk login, admin login, registrasi, dan reset
    | kata sandi. TURNSTILE_ENABLED dapat mengaktifkan/menonaktifkan verifikasi
    | eksplisit; jika tidak disetel, keberadaan secret key menjadi default.
    | Lokal dan suite test dapat membiarkannya nonaktif.
    */
    'secret_key' => env('TURNSTILE_SECRET_KEY'),
    'verify_url' => env('TURNSTILE_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
    'enabled' => filter_var(env('TURNSTILE_ENABLED', (bool) env('TURNSTILE_SECRET_KEY')), FILTER_VALIDATE_BOOLEAN),
    // Zona waktu tunggu panggilan siteverify; jangan sampai registrasi
    // menggantung bila Cloudflare lambat.
    'timeout_seconds' => (int) env('TURNSTILE_TIMEOUT_SECONDS', 5),
];
