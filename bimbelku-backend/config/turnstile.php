<?php

return [
    /*
    | Cloudflare Turnstile untuk endpoint publik yang rawan bot (registrasi
    | dan reset kata sandi). Kosong berarti verifikasi DIMATIKAN: pengembangan
    | lokal dan suite test tidak perlu kunci. Saat kunci diisi, verifikasi
    | otomatis aktif dan permintaan tanpa token valid ditolak 422.
    */
    'secret_key' => env('TURNSTILE_SECRET_KEY'),
    'verify_url' => env('TURNSTILE_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
    'enabled' => (bool) env('TURNSTILE_SECRET_KEY'),
    // Zona waktu tunggu panggilan siteverify; jangan sampai registrasi
    // menggantung bila Cloudflare lambat.
    'timeout_seconds' => (int) env('TURNSTILE_TIMEOUT_SECONDS', 5),
];
