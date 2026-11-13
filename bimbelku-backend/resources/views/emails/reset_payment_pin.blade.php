<!DOCTYPE html>
<html>
<head>
    <title>Reset PIN Pembayaran</title>
</head>
<body style="font-family: Arial, sans-serif; padding: 20px;">
    <h2>Halo{{ filled($name ?? null) ? " ".$name : "" }}!</h2>
    <p>Kami menerima permintaan untuk mengatur ulang PIN pembayaran akun BimbelKu Anda.</p>
    <p>Klik tombol di bawah ini untuk membuat PIN pembayaran baru:</p>
    <br>
    <a href="{{ $url }}" style="background-color: #4F46E5; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Reset PIN Pembayaran</a>
    <br><br>
    <p>Link ini akan kedaluwarsa dalam 60 menit dan hanya dapat dipakai satu kali.</p>
    <p>Jika Anda tidak meminta reset PIN, abaikan email ini. PIN lama Anda tetap berlaku.</p>
</body>
</html>
