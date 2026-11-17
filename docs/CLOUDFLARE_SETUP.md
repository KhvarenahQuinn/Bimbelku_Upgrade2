# Panduan Instalasi & Konfigurasi Cloudflare untuk BimbelKu

> **Versi:** 1.0  
> **Branch:** `revisi/2-badge-admin`  
> **Tanggal:** 2026-11-17  
> **Audience:** DevOps / System Admin yang mendeploy BimbelKu ke produksi.

---

## Daftar Isi

1. [Arsitektur & Persiapan DNS](#1-arsitektur--persiapan-dns)
2. [SSL/TLS & Keamanan WAF](#2-ssltls--keamanan-waf)
3. [Aturan Cache (*Cache Rules*)](#3-aturan-cache-cache-rules)
3. [Konfigurasi Cloudflare Turnstile & `.env` Produksi](#4-konfigurasi-cloudflare-turnstile--env-produksi)
4. [Verifikasi Pasca-Deploy](#5-verifikasi-pasca-deploy)
5. [Penjelasan: Satu Branch, Dengan & Tanpa Cloudflare](#6-penjelasan-satu-branch-dengan--tanpa-cloudflare)

---

## 1. Arsitektur & Persiapan DNS

### 1.1 Alur Trafik

```
Pengguna (Browser)
      │
      ▼
Cloudflare Edge (CDN + WAF + SSL Termination)
      │  ──────────────────────────────────────
      │  Proxied (awan oranye): SSL Offload,
      │  WAF, Cache, Turnstile, Rate Limit
      │
      ▼
Origin Server (VPS/VM)
      │  HTTPS (port 443) atau HTTP (port 80)
      │  Aplikasi Laravel + Vite
      ▼
Database (MySQL/PostgreSQL) + Redis/Database Cache
```

### 1.2 Menambahkan Domain ke Cloudflare

1. Login ke [Cloudflare Dashboard](https://dash.cloudflare.com).
2. Klik **Add a Site** → masukkan domain (mis. `bimbelku.id`).
3. Pilih plan **Free** (cukup untuk fitur yang dibutuhkan).
4. Cloudflare akan memindai *DNS records* yang ada. Pastikan:
   - `A @` → IP VPS origin (IPv4)
   - `A www` → IP VPS origin (IPv4) atau `CNAME www @`
   - **Mode Proxy: Proxied (awan oranye)** untuk `@` dan `www`
4. Ganti *nameserver* di registrar domain ke nameserver Cloudflare (2 buah, mis. `dina.ns.cloudflare.com`, `tom.ns.cloudflare.com`).
5. Tunggu propagasi DNS (biasanya < 5 menit, maks. 24 jam).

> **Catatan:** Hanya record `@` dan `www` yang perlu **Proxied**. Subdomain lain (`api`, `storage`, dll) boleh *DNS Only* bila tidak perlu WAF/cache.

### 1.3 Mengapa Satu Branch Bisa Berjalan Tanpa & Dengan Cloudflare

Kode di `revisi/2-badge-admin` sudah mendukung kedua mode:

| Mode | `TRUSTED_PROXIES` | `APP_URL` / `FRONTEND_URL` | `MAIL_MAILER` | `TURNSTILE_SECRET_KEY` |
|------|-------------------|----------------------------|---------------|------------------------|
| **Tanpa Cloudflare** (lokal / staging) | kosong | `http://localhost:8080` | `log` | kosong (bypass) |
| **Dengan Cloudflare** (produksi) | IP Cloudflare / `REMOTE_ADDR` | `https://domain.com` | `smtp` | `0x4AAAA...` |

Kunci utamanya ada di `bootstrap/app.php`:

```php
$trustedProxies = array_values(array_filter(array_map(
    'trim', explode(',', (string) env('TRUSTED_PROXIES', ''))
), fn (string $proxy): bool => $proxy !== ''));

if ($trustedProxies !== []) {
    $middleware->trustProxies(
        at: $trustedProxies,
        headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_PREFIX,
    );
}
```

Jika `TRUSTED_PROXIES` kosong (lokal), Laravel **tidak** memercayai header `X-Forwarded-*` dan tidak mengubah `request()->ip()` / `url()->current()`. Saat `TRUSTED_PROXIES` diisi (produksi), Laravel mempercayai `CF-Connecting-IP`, `X-Forwarded-For`, `X-Forwarded-Proto`, dsb., sehingga `request()->secure()` → `true` dan `url()->current()` menghasilkan `https://...`.

---

## 2. SSL/TLS & Keamanan WAF

### 2.1 SSL/TLS Mode

| Mode | Kapan Dipakai | Catatan |
|------|---------------|---------|
| **Full (Strict)** | **Produksi wajib** | Cloudflare ↔ Origin pakai sertifikat valid (CA terpercaya atau Cloudflare Origin CA). Paling aman. |
| Full | Sementara | Origin pakai sertifikat self-signed / tidak valid. Bisa dibajak di tengah jalan. |
| Flexible | **JANGAN** | Cloudflare → Origin pakai HTTP. Data pengguna terekspos. |

**Langkah di Dashboard Cloudflare:**
1. **SSL/TLS** → **Overview** → pilih **Full (Strict)**.
2. **Edge Certificates** → aktifkan:
   - **Always Use HTTPS** → `On`
   - **Automatic HTTPS Rewrites** → `On`
   - **Minimum TLS Version** → `TLS 1.2` (atau `1.3` jika didukung origin)
   - **TLS 1.3** → `On`

### 2.2 SSL Origin (Cloudflare Origin CA)

Agar *Full (Strict)* bekerja, origin harus punya sertifikat valid.

1. **SSL/TLS** → **Origin Server** → **Create Certificate**.
2. Pilih domain (`bimbelku.id`, `*.bimbelku.id`), validitas 15 tahun (maksimal).
3. Simpan `Origin Certificate` (`.pem`) dan `Private Key` (`.key`) ke server origin.
2. Konfigurasi web server (Nginx/Apache) agar memakai pasangan kunci tersebut di port 443.

> Alternatif: gunakan Let's Encrypt (Certbot) di origin + *Auto Renewal* via cron. Keduanya valid untuk *Full (Strict)*.

### 2.3 WAF (Web Application Firewall)

| Aturan | Tindakan | Alasan |
|--------|----------|--------|
| **Managed Ruleset: OWASP Core Ruleset** | `Log` (1 minggu) → `Block` | Melindungi dari SQLi, XSS, LFI, RCE, dll. Mulai mode *Log* untuk menganalisis *false positive*. |
| **Bot Fight Mode** | `On` | Mengurangi bot scraping/credential stuffing. Pastikan tidak memblokir callback Xendit (lihat §2.4). |
| **Rate Limiting Rules** | Lihat §2.3 | Melindungi endpoint login/registrasi dari brute-force. |

#### 2.3.1 Rate Limiting Login & Registrasi

| Endpoint | Batas | Periode | Aksi |
|----------|-------|---------|------|
| `POST /api/login` | 10 req/menit per IP | 1 menit | `Challenge (CAPTCHA)` |
| `POST /api/login/admin` | 5 req/menit per IP+email | 1 menit | `Block` |
| `POST /api/register` | 5 req/menit per IP | 1 menit | `Challenge (CAPTCHA)` |
| `POST /api/forgot-password` | 5 req/menit per IP | 1 menit | `Challenge (CAPTCHA)` |

> Rate limiter Laravel (`throttle:auth-login`, `auth-register`, `auth-forgot-password`) sudah berjalan di aplikasi (10/5 req/menit). Cloudflare Rate Limit adalah lapisan tambahan di *edge*.

#### 2.3.2 Penyempurnaan WAF untuk Webhook Xendit

Callback Xendit (`POST /api/webhooks/xendit`) **harus** melewati WAF tanpa hambatan.

1. **Security** → **WAF** → **Create Rule** → **Skip**:
   - Field: `URI Path`
   - Operator: `equals`
   - Value: `/api/webhooks/xendit`
   - Action: `Skip` → pilih **All WAF Managed Rules** + **Rate Limiting** + **Bot Fight Mode**.
2. Pastikan `XENDIT_WEBHOOK_TOKEN` di server sama dengan token di dashboard Xendit.

> Alternatif: IP Allowlist Xendit (rentang IP resmi Xendit) di **Security → WAF → IP Access Rules** → `Allow` → `IP Range`.

---

## 3. Aturan Cache (*Cache Rules*)

### 3.1 Prinsip Dasar

| Pola Path | Tindakan | TTL Edge | Alasan |
|-----------|----------|----------|--------|
| `/assets/*` (hash Vite) | **Cache Everything** | 1 tahun (31536000 s) | File ber-hash (`app-xyz123.js`) tidak berubah. |
| `/images/*`, `/fonts/*` | **Cache Everything** | 1 tahun | Aset statis. |
| `/cara-belajar/*`, `/program/*`, `/tutor/*` | **Cache Everything** | 1 jam (3600 s) | Konten semi-statis; `Cache-Control` dari origin dihormati. |
| `/api/*` | **Bypass Cache** | — | Data dinamis, bergantung user/sesi. |
| `/sanctum/*`, `/api/auth/*` | **Bypass Cache** | — | Token, CSRF, sesi. |
| `/webhooks/xendit` | **Bypass Cache** | — | Webhook tidak boleh di-cache. |
| `/admin/*`, `/student/*`, `/guru/*` | **Bypass Cache** | — | Halaman autentik, data pribadi. |
| `/login`, `/register`, `/forgot-password` | **Bypass Cache** | — | Halaman autentik. |

### 3.2 Implementasi di Cloudflare

1. **Caching** → **Configuration** → **Caching Level**: `Standard` (default).
2. **Caching** → **Cache Rules** → **Create Rule** (urutan matters):

| # | Match (Expression) | Setting | Value |
|---|-------------------|---------|-------|
| 1 | `http.request.uri.path matches "^/api/.*"` | Eligible for Cache | `false` |
| 2 | `http.request.uri.path matches "^/sanctum/.*"` | Eligible for Cache | `false` |
| 3 | `http.request.uri.path eq "/api/webhooks/xendit"` | Eligible for Cache | `false` |
| 4 | `http.request.uri.path matches "^/(admin|student|guru|login|register|forgot-password).*"` | Eligible for Cache | `false` |
| 5 | `http.request.uri.path matches "^/assets/.*"` | Cache Eligible | `true`; Edge TTL: `31536000`; Browser TTL: `31536000` |
| 6 | `http.request.uri.path matches "^/(images|fonts)/.*"` | Cache Eligible | `true`; Edge TTL: `31536000` |
| 7 | `http.request.uri.path matches "^/cara-belajar/.*"` | Cache Eligible | `true`; Edge TTL: `3600`; Browser TTL: `3600` |
| 8 | `http.request.uri.path matches "^/program/.*"` | Cache Eligible | `true`; Edge TTL: `3600` |
| 9 | `http.request.uri.path matches "^/tutor/.*"` | Cache Eligible | `true`; Edge TTL: `3600` |

> Urutan rules penting: *Bypass* (1–4) harus di atas *Cache* (5–9). Cloudflare mengevaluasi rules berurutan.

### 3.3 Purge Cache Saat Deploy

Setiap deploy frontend (Vite build menghasilkan hash baru), hapus cache aset:

```bash
# Via API (otomatis di CI/CD)
curl -X POST "https://api.cloudflare.com/client/v4/zones/{ZONE_ID}/purge_cache" \
  -H "Authorization: Bearer $CF_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"files":["https://domain.com/assets/*"]}'

# Atau via dashboard: Caching → Configuration → Purge Cache → Custom Purge → https://domain.com/assets/*
```

---

## 4. Konfigurasi Cloudflare Turnstile & `.env` Produksi

### 4.1 Membuat Turnstile Widget

1. Dashboard Cloudflare → **Turnstile** → **Add Site**.
2. Domain: `bimbelku.id` (atau domain produksi).
3. Widget Mode: **Managed** (otomatis menampilkan challenge saat perlu) atau **Non-Interactive** (invisible).
3. Simpan → dapatkan **Site Key** (public) dan **Secret Key** (private).

### 4.2 Variabel `.env` Produksi

```dotenv
# === Aplikasi ===
APP_ENV=production
APP_DEBUG=false
APP_URL=https://bimbelku.id
FRONTEND_URL=https://bimbelku.id

# Proxy Cloudflare (lihat bootstrap/app.php)
TRUSTED_PROXIES=173.245.48.0/20,103.21.244.0/22,103.22.200.0/22,103.31.4.0/22,141.101.64.0/18,108.162.192.0/18,190.93.240.0/20,188.114.96.0/20,197.234.240.0/22,198.41.128.0/17,162.158.0.0/15,104.16.0.0/13,104.24.0.0/14,172.64.0.0/13,131.0.72.0/22,2400:cb00::/32,2606:4700::/32,2803:f800::/32,2405:b500::/32,2405:8100::/32,2405:9000::/32,2a06:98c0::/29,2c0f:f248::/32
# Atau singkat: TRUSTED_PROXIES=REMOTE_ADDR  (percayai proxy yang langsung terhubung)

# Turnstile (diperlukan untuk /register & /forgot-password)
TURNSTILE_SECRET_KEY=0x4AAAAAAA...
TURNSTILE_TIMEOUT_SECONDS=5

# Mail: produksi WAJIB smtp, jangan log
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_USERNAME=your-brevo-login
MAIL_PASSWORD=your-brevo-smtp-key
MAIL_FROM_ADDRESS="noreply@bimbelku.id"
MAIL_FROM_NAME="BimbelKu"

# Xendit (produksi pakai prefix xnd_production_)
XENDIT_ENABLED=true
XENDIT_SECRET_KEY=xnd_production_...
XENDIT_WEBHOOK_TOKEN=sama-dengan-dashboard-xendit
XENDIT_BASE_URL=https://api.xendit.co
XENDIT_COUNTRY=ID
XENDIT_CURRENCY=IDR
XENDIT_SUCCESS_URL=https://bimbelku.id/payment?gateway=success
XENDIT_CANCEL_URL=https://bimbelku.id/payment?gateway=cancelled
XENDIT_SESSION_TTL_MINUTES=60

# Cache & Queue
CACHE_STORE=database          # atau redis; JANGAN array
QUEUE_CONNECTION=sync         # tidak ada worker terpisah; sinkron
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=bimbelku
DB_USERNAME=...
DB_PASSWORD=...
```

> **Penting:** `CACHE_STORE` **jangan** `array`. Cache `array` membuat lock `withoutOverlapping` pada scheduler tidak berfungsi di multi-proses. Gunakan `database` atau `redis`.

---

## 5. Verifikasi Pasca-Deploy

Jalankan setelah deploy & DNS propagasi selesai:

| Pemeriksaan | Cara | Harapan |
|-------------|------|---------|
| **HTTPS Redirect** | `curl -I http://bimbelku.id` | `301 Moved Permanently` → `Location: https://...` |
| **SSL Full Strict** | `curl -I https://bimbelku.id` | `200 OK`; sertifikat valid; `Strict-Transport-Security` header ada. |
| **IP Asli Pengunjung** | `php artisan tinker` → `request()->ip()` di log request | IP asli pengunjung (bukan IP Cloudflare). |
| **HTTPS Scheme** | `request()->secure()` di controller | `true` untuk request HTTPS. |
| **Turnstile Registrasi** | Buka `/register` tanpa token → 422; dengan token valid → 201. | 422 `turnstile_failed` tanpa token; 201 Created dengan token valid. |
| **Turnstile Lupa Password** | Sama seperti registrasi. | 422 tanpa token; 200 dengan token valid. |
| **Webhook Xendit** | Dashboard Xendit → *Test Webhook* → 200 OK. | 200 OK; log `xendit_refund_failed` / `xendit_payout_failed` tidak error. |
| **Penjadwal (Scheduler)** | `Cache::get('scheduler:last_heartbeat_at')` berubah tiap menit. | Timestamp ISO8601 terbaru < 2 menit. |
| **Schedule List** | `php artisan schedule:list` | 11 baris (termasuk `finance:verify-wallets` 02:30). |
| **Verifikasi Wallet** | `php artisan finance:verify-wallets` | Exit code 0, output "konsisten". |
| **Verifikasi Ledger** | `php artisan finance:verify-ledger` | Exit code 0. |
| **Overflow Horizontal** | Buka 5 breakpoint (320, 390, 768, 1280, 1440) di Chrome DevTools. | Tidak ada scroll horizontal (`overflow-x`). |
| **Keyboard Navigasi** | Tab di form login/register/OTP. | Fokus bergerak berurutan; toggle password bisa diakses `Space`/`Enter`. |
| **Screen Reader** | NVDA/VoiceOver di form login. | Label & `aria-describedby` terbaca. |

---

## 5. Penjelasan: Satu Branch, Dengan & Tanpa Cloudflare

Kode di `revisi/2-badge-admin` dirancang **environment-agnostic**:

| Komponen | Tanpa Cloudflare (Lokal) | Dengan Cloudflare (Produksi) |
|----------|--------------------------|------------------------------|
| `TRUSTED_PROXIES` | kosong → Laravel **tidak** memercayai `X-Forwarded-*` | IP Cloudflare / `REMOTE_ADDR` → Laravel memercayai `CF-Connecting-IP`, `X-Forwarded-*` |
| `request()->ip()` | IP loopback / LAN | IP asli pengunjung (dari `CF-Connecting-IP`) |
| `request()->secure()` | `false` (HTTP) | `true` (HTTPS via Cloudflare) |
| `url()->current()` | `http://localhost:8080/...` | `https://domain.com/...` |
| `MAIL_MAILER` | `log` → tulis ke `storage/logs/mail.log` | `smtp` → kirim lewat Brevo/SMTP |
| `TURNSTILE_SECRET_KEY` | kosong → **bypass** (dev/test bebas) | terisi → **enforce** (fail-closed) |
| `MAIL_MAILER` | `log` → `storage/logs/mail.log` (OTP terbaca di log) | `smtp` → kirim lewat Brevo, OTP **tidak** di disk |
| `CACHE_STORE` | `database` (lokal) | `database` / `redis` (produksi) |
| `QUEUE_CONNECTION` | `sync` (sinkron) | `sync` (atau `redis` jika worker terpisah) |

**Kunci:** Semua perbedaan dikendalikan lewat **variabel lingkungan (`.env`)**. Tidak ada *branch* terpisah, tidak ada `if (env('APP_ENV') === 'production')` tersebar di kode. Semua keputusan runtime diambil dari `.env` → **satu branch, deploy ke mana saja**.

---

## Lampiran: Checklist Cepat (Copy-Paste ke Notion/GitHub Issue)

```
[ ] DNS: A @ + A www → IP VPS, Proxied (awan oranye)
[ ] Nameserver di registrar → Cloudflare
[ ] SSL/TLS: Full (Strict) + Origin CA cert / Let's Encrypt di origin
[ ] Always Use HTTPS: On
[ ] Automatic HTTPS Rewrites: On
[ ] WAF OWASP: Log → Block (setelah 1 minggu)
[ ] Bot Fight Mode: On (Skip rule untuk /api/webhooks/xendit)
[ ] Rate Limit: /api/login 10/min, /api/register 5/min
[ ] Cache Rules: 1-4 Bypass, 5-9 Cache (lihat §3.2)
[ ] Purge Cache /assets/* saat deploy
[ ] Turnstile: Site Key di frontend, Secret Key di .env produksi
[ ] TRUSTED_PROXIES = IP Cloudflare / REMOTE_ADDR
[ ] MAIL_MAILER=smtp (produksi), MAIL_LOG_CHANNEL=mail (local only)
[ ] TURNSTILE_SECRET_KEY diisi di produksi
[ ] Cron host: * * * * * cd /var/www/bimbelku/bimbelku-backend && php artisan schedule:run
[ ] Verifikasi: schedule:list 11 baris, heartbeat tiap menit, Turnstile 422/201, Webhook 200, HTTPS redirect, IP asli di log
[ ] Deploy: git pull → composer install --no-dev → npm ci → npm run build → php artisan migrate --force → php artisan config:cache route:cache view:cache → systemctl reload nginx/php-fpm
```

---

## Referensi

- [Cloudflare DNS Records](https://developers.cloudflare.com/dns/manage-dns-records/)
- [Cloudflare SSL/TLS](https://developers.cloudflare.com/ssl/)
- [Cloudflare WAF](https://developers.cloudflare.com/waf/)
- [Cloudflare Cache Rules](https://developers.cloudflare.com/cache/how-to/cache-rules/)
- [Cloudflare Turnstile](https://developers.cloudflare.com/turnstile/)
- [Cloudflare Rate Limiting](https://developers.cloudflare.com/waf/tools/rate-limiting-rules/)
- [Laravel Trusted Proxies](https://laravel.com/docs/12.x/requests#configuring-trusted-proxies)
- [Laravel Sanctum](https://laravel.com/docs/12.x/sanctum)
- [Laravel Scheduler](https://laravel.com/docs/12.x/scheduling)

---

*Dokumen ini adalah bagian dari repositori BimbelKu di branch `revisi/2-badge-admin`. Simpan di `docs/CLOUDFLARE_SETUP.md` dan perbarui saat ada perubahan infrastruktur.*