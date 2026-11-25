English: [cloudflare-setup.md](cloudflare-setup.md) | Bahasa Indonesia: [cloudflare-setup.id.md](cloudflare-setup.id.md)

# Pengaturan Cloudflare Free Tier — Platform BimbelKu

Panduan ini mencakup zona Cloudflare produksi dan origin Laravel. Terapkan perubahan firewall dan WAF setelah memastikan hostname produksi serta alamat origin yang benar. Callback Xendit yang terdaftar di `bimbelku-backend/routes/api.php` adalah `POST /api/webhooks/xendit`; kecualikan path persis ini dari challenge edge.

## 1. DNS dan TLS

1. Tambahkan domain produksi ke Cloudflare, lalu ubah nameserver di registrar.
2. Buat record A/AAAA atau CNAME yang diperlukan untuk origin web, kemudian aktifkan **Proxied** (awan oranye) untuk lalu lintas web publik. Jangan membuka record origin aplikasi tanpa proxy.
3. Pasang sertifikat origin yang valid dan sesuai hostname produksi (Cloudflare Origin CA atau sertifikat tepercaya lainnya).
4. Di **SSL/TLS → Overview**, pilih **Full (strict)**. Jangan gunakan Flexible.
5. Aktifkan **Always Use HTTPS** di **SSL/TLS → Edge Certificates**. Selaraskan konfigurasi HTTPS aplikasi dan origin agar tidak terjadi redirect loop.

## 2. Batasi akses origin dan percayai header proxy

Pada firewall/server security group, izinkan koneksi HTTP/HTTPS masuk (port 80 dan 443) hanya dari rentang alamat IPv4 dan IPv6 Cloudflare terkini. Tolak akses publik langsung ke origin; kelola SSH dan port administrasi lain dengan kebijakan terpisah. Perbarui allowlist dari daftar IP resmi Cloudflare, jangan menyalin rentang yang mungkin sudah kedaluwarsa ke panduan ini.

Laravel 11 mengatur `trustProxies(at: '*')` di `bimbelku-backend/bootstrap/app.php`. Pengaturan ini aman hanya jika firewall origin mencegah koneksi langsung dari klien yang tidak tepercaya. Cloudflare mengirim rantai IP klien melalui `X-Forwarded-For`; penanganan trusted proxy Laravel menggunakan header standar tersebut untuk `$request->ip()`, termasuk atribusi rate limit aplikasi dan audit. Cloudflare juga mengirim `CF-Connecting-IP`; jangan mempercayai header forwarding dari klien jika origin dapat diakses di luar Cloudflare.

## 3. Turnstile

1. Di Cloudflare **Turnstile**, buat widget **Managed** dan izinkan hostname produksi yang tepat, termasuk hostname `www` jika digunakan secara terpisah.
2. Atur site key publik sebagai `VITE_TURNSTILE_SITE_KEY` di environment build frontend. Build dan deploy ulang frontend setelah nilainya berubah.
3. Simpan secret privat sebagai `TURNSTILE_SECRET_KEY` di secret store backend produksi. Jangan menaruh secret di variabel frontend, repository, atau kode browser.
4. Atur `TURNSTILE_ENABLED=true` di produksi. Untuk local/testing, biarkan false atau jangan setel jika secret tidak tersedia. Jika flag tidak diatur, nilai default mengikuti keberadaan `TURNSTILE_SECRET_KEY`. Saat aktif, token yang hilang/tidak valid ditolak dengan HTTP 422 dan kegagalan siteverify ditangani secara fail-closed.
5. Pastikan widget Turnstile bersama tampil pada `/login`, `/admin/login`, dan `/register`. Endpoint API-nya adalah `POST /api/login`, `POST /api/login/admin`, dan `POST /api/register`; frontend mengirim token sebagai `cf-turnstile-response` (juga diterima sebagai `turnstile_token`). Endpoint admin tetap memiliki throttle login aplikasi tersendiri.

## 4. Aturan WAF kustom — urutan wajib

Buat aturan di **Security → WAF → Custom rules** sesuai urutan berikut. Aturan 1 harus berada di urutan pertama agar callback pembayaran tidak menerima halaman challenge HTML sebagai pengganti respons JSON yang diharapkan Xendit.

### Prioritas 1 — wajib melewati challenge untuk webhook Xendit

**Ekspresi:**

```text
(http.request.method eq "POST" and http.request.uri.path eq "/api/webhooks/xendit")
```

**Aksi:** **Skip**. Pilih custom rules berikutnya dan managed WAF rules yang dapat menantang atau memblokir request ini. Jangan menerapkan pengecualian ke seluruh `/api/*`. Callback tetap diautentikasi melalui validasi token callback Xendit di backend; pengecualian ini hanya melewati challenge edge, bukan autentikasi. Pastikan callback tetap tersedia melalui HTTPS.

### Prioritas 2 — challenge skor ancaman tinggi untuk halaman browser saja

**Ekspresi:**

```text
(cf.threat_score gt 14 and not starts_with(http.request.uri.path, "/api/"))
```

**Aksi:** **Managed Challenge** (halaman interstitial Cloudflare “Verify you are human”). Pertahankan pengecualian `/api/`: request SPA XHR/fetch harus menerima JSON aplikasi, bukan respons challenge HTML. Pastikan field `cf.threat_score` tersedia untuk ruleset zona. Jika Cloudflare mengubah ketersediaannya pada paket ini, gunakan sinyal bot setara yang didukung tanpa menghapus pengecualian `/api/`.

## 5. Rate limiting

Buat rate limiting untuk path berikut (batasi ke POST bila sesuai), hitung request berdasarkan IP sumber, dengan ambang seperti **lebih dari 10 request per 1 menit** dan aksi **Block** atau **Managed Challenge**. Pilih periode serta durasi mitigasi yang tersedia pada dashboard Free Tier.

```text
(http.request.method eq "POST" and (
  http.request.uri.path in {
    "/api/login"
    "/api/login/admin"
    "/api/admin/login"
    "/api/register"
    "/api/forgot-password"
    "/api/guest/packages/quote"
  }
))
```

`/api/login/admin` adalah route backend admin yang sebenarnya. `/api/admin/login` disertakan untuk deployment atau proxy yang menyediakan path alternatif tersebut. Jangan masukkan callback Xendit ke aturan ini; Laravel memiliki limiter webhook tersendiri dengan batas yang lebih longgar.

## 6. Cache dan security events

Cache aset statis ber-hash dengan masa berlaku panjang. Jangan cache respons API yang berisi data pengguna, sesi, pembayaran, atau konten personal. Lakukan purge setelah deployment jika konten lama masih tersaji. Tinjau Cloudflare Security Events dan Turnstile Analytics untuk false positive, lalu uji ulang webhook setiap kali aturan WAF atau rate limit berubah.

## 7. Checklist verifikasi setelah setup

- Lakukan percobaan login dan pastikan Managed widget Turnstile berhasil. Dengan `TURNSTILE_ENABLED=true`, kirim request tanpa token dan pastikan endpoint mengembalikan JSON HTTP `422` dengan `error_code: turnstile_failed`.
- Masuk melalui `/admin/login`, lakukan tindakan admin yang diaudit, lalu pastikan `admin_audit_logs.ip_address` berisi IP publik pengunjung, bukan IP edge Cloudflare.
- Kirim callback tes Xendit yang ditandatangani/ber-token valid ke `POST /api/webhooks/xendit`; pastikan Laravel mengembalikan HTTP `200`, bukan challenge HTML edge atau `403`.
- Lampaui ambang request edge pada salah satu path yang tercantum dan pastikan Cloudflare mengembalikan HTTP `429` (atau managed challenge yang dipilih), sementara request SPA `/api/` biasa tetap menerima JSON.
- Periksa log firewall origin, header keamanan respons, perilaku cache, urutan WAF, Security Events, dan Turnstile Analytics.

Untuk panduan terkait, baca [dokumentasi API](api/README.md) dan [panduan keamanan deployment](security/README.md).
