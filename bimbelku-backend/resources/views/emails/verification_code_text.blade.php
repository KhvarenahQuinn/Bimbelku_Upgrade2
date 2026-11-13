@php
    $purposeCopy = [
        'email_verification' => 'Gunakan kode berikut untuk memverifikasi email akun BimbelKu Anda.',
        'payment_pin_reset' => 'Gunakan kode berikut untuk mengatur ulang PIN pembayaran akun BimbelKu Anda.',
        'payout_bank_change' => 'Gunakan kode berikut untuk mengonfirmasi perubahan rekening pencairan Anda. Dana pencairan hanya dapat dikirim ke rekening yang sudah dikonfirmasi.',
    ];
@endphp
BimbelKu

Halo {{ $name }},

{{ $purposeCopy[$purpose] ?? 'Gunakan kode berikut untuk melanjutkan permintaan Anda.' }}

KODE: {{ $code }}

Kode berlaku selama {{ $expiresMinutes }} menit dan hanya dapat digunakan satu kali.

Abaikan email ini jika Anda tidak melakukan permintaan tersebut. Jangan berikan kode kepada siapa pun.
