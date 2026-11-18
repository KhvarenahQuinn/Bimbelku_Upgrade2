@extends('emails.layouts.base')

@section('title', 'Kode Verifikasi BimbelKu')

@section('preheader')
    <meta name="preheader" content="Kode verifikasi 6 digit untuk akun BimbelKu Anda. Berlaku {{ $expiresMinutes }} menit.">
@endsection

@section('content')
    @php
        $purposeCopy = [
            'email_verification' => 'Gunakan kode berikut untuk memverifikasi email akun BimbelKu Anda.',
            'payment_pin_reset' => 'Gunakan kode berikut untuk mengatur ulang PIN pembayaran akun BimbelKu Anda.',
            'payout_bank_change' => 'Gunakan kode berikut untuk mengonfirmasi perubahan rekening pencairan Anda. Dana pencairan hanya dapat dikirim ke rekening yang sudah dikonfirmasi.',
        ];
    @endphp

    <p style="margin:0 0 16px; font-size:16px; color:#0f172a">Halo {{ $name }},</p>

    <p style="margin:0 0 24px; font-size:16px; color:#334155">
        {{ $purposeCopy[$purpose] ?? 'Gunakan kode berikut untuk melanjutkan permintaan Anda.' }}
    </p>

    <div style="margin:24px 0; padding:20px; text-align:center; background:#fff7ed; border:1px solid #fed7aa; border-radius:12px">
        <div style="font-size:36px; font-weight:700; letter-spacing:8px; color:#c2410c; user-select:all; -webkit-user-select:all; -moz-user-select:all; -ms-user-select:all">{{ $code }}</div>
    </div>

    <p style="margin:0 0 16px; font-size:14px; color:#475569">Kode berlaku selama {{ $expiresMinutes }} menit dan hanya dapat digunakan satu kali.</p>

    <p style="margin:0; font-size:13px; color:#64748b">Abaikan email ini jika Anda tidak melakukan permintaan tersebut. Jangan berikan kode kepada siapa pun.</p>
@endsection