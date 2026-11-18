@extends('emails.layouts.base')

@section('title', 'Reset PIN Pembayaran BimbelKu')

@section('preheader')
    <meta name="preheader" content="Tautan reset PIN pembayaran untuk akun BimbelKu Anda. Berlaku 60 menit.">
@endsection

@section('content')
    <p style="margin:0 0 16px; font-size:16px; color:#0f172a">Halo{{ filled($name ?? null) ? " " . $name : "" }}!</p>

    <p style="margin:0 0 24px; font-size:16px; color:#334155">Kami menerima permintaan untuk mengatur ulang PIN pembayaran akun BimbelKu Anda.</p>

    <p style="margin:0 0 24px; font-size:16px; color:#334155">Klik tombol di bawah ini untuk membuat PIN pembayaran baru:</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0">
        <tr>
            <td style="text-align:center">
                <a href="{{ $url }}" style="display:inline-block; background:#ea580c; color:#ffffff; font-weight:600; font-size:16px; text-decoration:none; padding:14px 28px; border-radius:8px; border:0">Reset PIN Pembayaran</a>
            </td>
        </tr>
    </table>

    <p style="margin:0 0 16px; font-size:14px; color:#475569">Atau salin tautan ini ke browser Anda:<br><a href="{{ $url }}" style="color:#ea580c; word-break:break-all">{{ $url }}</a></p>

    <p style="margin:0 0 16px; font-size:14px; color:#475569">Link ini akan kedaluwarsa dalam 60 menit dan hanya dapat dipakai satu kali.</p>

    <p style="margin:0; font-size:13px; color:#64748b">Jika Anda tidak meminta reset PIN, abaikan email ini. PIN lama Anda tetap berlaku.</p>
@endsection