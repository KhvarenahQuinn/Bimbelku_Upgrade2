<?php

return [
    'teacher_replacement' => (bool) env('FEATURE_TEACHER_REPLACEMENT', true),

    // Stub verifikasi nama pemilik rekening. HANYA boleh aktif di local/testing.
    // Tidak pernah menggantikan lookup bank asli di produksi.
    'payout_bank_name_stub' => (bool) env('PAYOUT_BANK_NAME_VERIFICATION_STUB', false),
];
