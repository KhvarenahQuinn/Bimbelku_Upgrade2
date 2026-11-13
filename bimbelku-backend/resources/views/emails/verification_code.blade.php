@php
    $purposeCopy = [
        'email_verification' => 'Gunakan kode berikut untuk memverifikasi email akun BimbelKu Anda.',
        'payment_pin_reset' => 'Gunakan kode berikut untuk mengatur ulang PIN pembayaran akun BimbelKu Anda.',
        'payout_bank_change' => 'Gunakan kode berikut untuk mengonfirmasi perubahan rekening pencairan Anda. Dana pencairan hanya dapat dikirim ke rekening yang sudah dikonfirmasi.',
    ];
@endphp
<!doctype html>
<html lang='id'>
<body style='margin:0;background:#f8fafc;font-family:Arial,sans-serif;color:#0f172a'>
<div style='max-width:560px;margin:32px auto;padding:28px;background:#fff;border:1px solid #e2e8f0;border-radius:16px'>
    <h1 style='margin:0 0 16px;font-size:24px;color:#ea580c'>BimbelKu</h1>
    <p>Halo {{ $name }},</p>
    <p>{{ $purposeCopy[$purpose] ?? 'Gunakan kode berikut untuk melanjutkan permintaan Anda.' }}</p>
    <div style='margin:24px 0;padding:18px;text-align:center;background:#fff7ed;border-radius:12px;font-size:32px;font-weight:700;letter-spacing:8px;color:#c2410c'>{{ $code }}</div>
    <p>Kode berlaku selama {{ $expiresMinutes }} menit dan hanya dapat digunakan satu kali.</p>
    <p style='font-size:13px;color:#64748b'>Abaikan email ini jika Anda tidak melakukan permintaan tersebut. Jangan berikan kode kepada siapa pun.</p>
</div>
</body>
</html>
