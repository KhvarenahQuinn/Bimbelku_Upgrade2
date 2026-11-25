English: [cloudflare-setup.md](cloudflare-setup.md) | Bahasa Indonesia: [cloudflare-setup.id.md](cloudflare-setup.id.md)

# Setup Cloudflare Free Tier untuk Platform BimbelKu

Panduan ini menjelaskan konfigurasi zona Cloudflare produksi dan origin server Laravel. Pastikan hostname produksi dan alamat origin server benar sebelum mengubah firewall atau WAF. Route callback Xendit yang terdaftar di `bimbelku-backend/routes/api.php` adalah `POST /api/webhooks/xendit`. Kecualikan path ini dari edge challenge.

## 1. DNS dan TLS

1. Tambahkan domain produksi ke Cloudflare, lalu ubah nameserver di registrar.
2. Buat record A/AAAA atau CNAME untuk origin web. Aktifkan **Proxied** (awan oranye) untuk traffic web publik. Jangan membuka record origin aplikasi tanpa proxy.
3. Pasang origin certificate yang valid dan sesuai dengan production hostname. Gunakan Cloudflare Origin CA atau certificate tepercaya lainnya.
4. Di **SSL/TLS → Overview**, pilih **Full (strict)**. Jangan gunakan Flexible.
5. Aktifkan **Always Use HTTPS** di **SSL/TLS → Edge Certificates**. Samakan konfigurasi HTTPS di aplikasi dan origin server agar tidak terjadi redirect loop.

## 2. Batasi akses origin dan percayai proxy headers

Atur server firewall atau security group agar hanya menerima HTTP/HTTPS masuk (port 80 dan 443) dari rentang alamat IPv4 dan IPv6 Cloudflare terbaru. Blokir akses publik langsung ke origin server. Atur SSH dan port administrasi lain dengan kebijakan terpisah. Perbarui allowlist dari daftar IP resmi Cloudflare. Jangan menyalin rentang yang mungkin sudah kedaluwarsa ke panduan ini.

Laravel 11 mengatur `trustProxies(at: '*')` di `bimbelku-backend/bootstrap/app.php`. Gunakan konfigurasi ini hanya jika origin firewall memblokir koneksi dari klien yang tidak tepercaya. Cloudflare mengirim rantai IP klien melalui `X-Forwarded-For`. Laravel memakai forwarded header ini untuk `$request->ip()`, termasuk atribusi rate limiting aplikasi dan audit. Cloudflare juga mengirim `CF-Connecting-IP`. Jangan percayai forwarding headers dari klien jika origin server dapat diakses di luar Cloudflare.

## 3. Turnstile

1. Di Cloudflare **Turnstile**, buat widget **Managed**. Izinkan production hostname yang tepat, termasuk hostname `www` terpisah jika digunakan.
2. Atur site key publik sebagai `VITE_TURNSTILE_SITE_KEY` di frontend build environment. Build dan deploy ulang frontend setelah mengubahnya.
3. Simpan secret privat sebagai `TURNSTILE_SECRET_KEY` di backend production secret store. Jangan masukkan secret ke frontend variables, repository, atau browser code.
4. Atur `TURNSTILE_ENABLED=true` di produksi. Di local dan testing environments, atur ke false atau biarkan tidak disetel jika secret tidak tersedia. Jika tidak disetel, nilai default mengikuti keberadaan `TURNSTILE_SECRET_KEY`. Saat aktif, token yang hilang atau tidak valid menghasilkan HTTP 422. Kegagalan siteverify bersifat fail-closed.
5. Pastikan shared Turnstile widget tampil pada `/login`, `/admin/login`, dan `/register`. API endpoints-nya adalah `POST /api/login`, `POST /api/login/admin`, dan `POST /api/register`. Frontend mengirim token sebagai `cf-turnstile-response`; server juga menerima `turnstile_token`. Admin endpoint memiliki application-level login throttle tersendiri.

## 4. WAF custom rules

Buat rules berikut di **Security → WAF → Custom rules** sesuai urutan. Tempatkan Rule 1 di urutan pertama agar edge challenge tidak mengganti JSON response yang diharapkan Xendit.

### Prioritas 1: skip challenge untuk Xendit webhook

Expression:

```text
(http.request.method eq "POST" and http.request.uri.path eq "/api/webhooks/xendit")
```

Action: **Skip**. Pilih custom rules berikutnya serta managed WAF rules yang dapat menantang atau memblokir request ini. Jangan terapkan pengecualian ke semua path `/api/*`. Backend tetap mengautentikasi callback dengan Xendit callback token. Aturan ini melewati edge challenge, bukan autentikasi. Pastikan callback tetap dapat diakses melalui HTTPS.

### Prioritas 2: challenge untuk threat score tinggi pada halaman browser

Expression:

```text
(cf.threat_score gt 14 and not starts_with(http.request.uri.path, "/api/"))
```

Action: **Managed Challenge** (interstitial Cloudflare “Verify you are human”). Pertahankan pengecualian `/api/` agar SPA XHR/fetch requests menerima application JSON, bukan HTML challenge. Pastikan `cf.threat_score` tersedia di zone ruleset. Jika paket Cloudflare tidak lagi menyediakan field ini, gunakan bot signal setara yang didukung dan tetap kecualikan `/api/`.

## 5. Rate limiting

Buat rate-limiting rule untuk path berikut. Batasi ke POST jika sesuai, hitung request berdasarkan source IP, dan gunakan ambang seperti **lebih dari 10 request per 1 menit**. Pilih action **Block** atau **Managed Challenge**. Gunakan periode dan mitigation timeout yang tersedia di dashboard Free Tier.

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

`/api/login/admin` adalah backend route yang digunakan admin portal. `/api/admin/login` disertakan untuk deployment atau proxy yang menyediakan alternate path tersebut. Kecualikan Xendit callback dari rule ini. Laravel menerapkan webhook limiter terpisah dengan batas lebih longgar.

## 6. Cache dan security events

Cache hashed static assets dengan masa berlaku panjang. Jangan cache API responses yang berisi data pengguna, sessions, payments, atau konten personal. Purge cache setelah deployment jika konten lama masih tampil. Tinjau Cloudflare Security Events dan Turnstile Analytics untuk false positives. Uji webhook lagi setelah mengubah WAF atau rate-limit rules.

## 7. Verifikasi setup

- Selesaikan login dengan Turnstile Managed widget. Dengan `TURNSTILE_ENABLED=true`, kirim request tanpa token dan pastikan endpoint mengembalikan JSON HTTP `422` dengan `error_code: turnstile_failed`.
- Masuk melalui `/admin/login` dan lakukan admin action yang tercatat di audit. Pastikan `admin_audit_logs.ip_address` berisi public client IP pengunjung, bukan Cloudflare edge IP.
- Kirim Xendit test callback dengan signature atau token valid ke `POST /api/webhooks/xendit`. Pastikan Laravel mengembalikan HTTP `200`, bukan edge HTML challenge atau `403`.
- Lampaui edge request threshold pada salah satu path yang tercantum. Pastikan Cloudflare mengembalikan HTTP `429` atau managed challenge yang dipilih, sementara SPA `/api/` requests biasa tetap menerima JSON.
- Periksa origin firewall logs, response security headers, cache behavior, urutan WAF rules, Security Events, dan Turnstile Analytics.

Untuk panduan terkait, baca [dokumentasi API](api/README.md) dan [panduan keamanan deployment](security/README.md).
