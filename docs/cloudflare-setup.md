English: [cloudflare-setup.md](cloudflare-setup.md) | Bahasa Indonesia: [cloudflare-setup.id.md](cloudflare-setup.id.md)

# Cloudflare Free Tier setup — Platform BimbelKu

This guide covers the production Cloudflare zone and Laravel origin. Apply firewall and WAF changes only after confirming the production hostname and origin address. The Xendit callback registered in `bimbelku-backend/routes/api.php` is `POST /api/webhooks/xendit`; keep this exact path exempt from edge challenges.

## 1. DNS and TLS

1. Add the production domain to Cloudflare and update the registrar nameservers.
2. Create the required A/AAAA or CNAME records for the web origin and enable **Proxied** (orange cloud) for public web traffic. Do not expose an unproxied origin record for the application.
3. Install a valid origin certificate whose names match the production host (Cloudflare Origin CA or another trusted certificate).
4. In **SSL/TLS → Overview**, choose **Full (strict)**. Do not use Flexible.
5. Enable **Always Use HTTPS** in **SSL/TLS → Edge Certificates**. Keep the application and origin HTTPS configuration consistent to avoid redirect loops.

## 2. Restrict the origin and trust proxy headers

At the server firewall/security group, allow inbound HTTP/HTTPS (ports 80 and 443) only from Cloudflare's current IPv4 and IPv6 address ranges. Deny direct public access to the origin; keep SSH and other management ports under their separate administrative policy. Maintain the allowlist from Cloudflare's published IP list rather than copying a potentially stale range into this guide.

Laravel 11 configures `trustProxies(at: '*')` in `bimbelku-backend/bootstrap/app.php`. This is safe only when the origin firewall prevents untrusted clients from connecting directly. Cloudflare supplies the client chain in `X-Forwarded-For`; Laravel's trusted proxy handling uses that standard forwarded header for `$request->ip()`, including application rate-limit and audit attribution. Cloudflare also supplies `CF-Connecting-IP`; do not trust client-supplied forwarding headers on an origin that can be reached outside Cloudflare.

## 3. Turnstile

1. In Cloudflare **Turnstile**, create a **Managed** widget and allow the exact production hostnames, including any separately used `www` hostname.
2. Set the public site key as `VITE_TURNSTILE_SITE_KEY` in the frontend build environment. Rebuild and redeploy the frontend after changing it.
3. Set the private secret as `TURNSTILE_SECRET_KEY` in the backend production secret store. Never put it in frontend variables, this repository, or browser code.
4. Set `TURNSTILE_ENABLED=true` in production. In local/testing environments, leave it false or omit it with no secret configured. If the flag is omitted, its default follows whether `TURNSTILE_SECRET_KEY` is set. When explicitly enabled, a missing/invalid token is rejected with HTTP 422, and siteverify failures fail closed.
5. Confirm the shared Turnstile widget is rendered for `/login`, `/admin/login`, and `/register`. Their API endpoints are `POST /api/login`, `POST /api/login/admin`, and `POST /api/register`; the frontend sends the token as `cf-turnstile-response` (also accepted as `turnstile_token`). The admin endpoint keeps its independent application-level login throttle.

## 4. WAF custom rules — order matters

Create the rules in **Security → WAF → Custom rules**, in the order below. Rule 1 must be first so payment callbacks never receive an HTML challenge page instead of the JSON response expected by Xendit.

### Priority 1 — mandatory Xendit webhook skip

**Expression:**

```text
(http.request.method eq "POST" and http.request.uri.path eq "/api/webhooks/xendit")
```

**Action:** **Skip**. Select the remaining custom rules and managed WAF rules that can challenge or block this request. Do not apply this exception to all `/api/*` requests. The callback is authenticated by the backend's Xendit callback-token validation, so this is an edge-challenge exception, not an authentication bypass. Keep the callback reachable over HTTPS.

### Priority 2 — high threat-score challenge for browser pages only

**Expression:**

```text
(cf.threat_score gt 14 and not starts_with(http.request.uri.path, "/api/"))
```

**Action:** **Managed Challenge** (Cloudflare's “Verify you are human” interstitial). Keep the `/api/` exclusion: SPA XHR/fetch calls must receive application JSON rather than an HTML challenge response. Confirm `cf.threat_score` is available to the zone's ruleset. If Cloudflare changes its availability on the plan, use a supported equivalent bot signal without removing the `/api/` exclusion.

## 5. Rate limiting

Create a rate-limiting rule for the paths below (restricting it to POST is appropriate), counting requests by source IP, with a threshold such as **more than 10 requests per 1 minute** and an action of **Block** or **Managed Challenge**. Choose a period and mitigation timeout supported by the Free plan dashboard.

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

`/api/login/admin` is the actual backend route for the admin portal. `/api/admin/login` is included for deployments or proxies that expose that alternate path. Do not include the Xendit callback in this limiter; Laravel applies a separate, generous webhook limiter.

## 6. Cache and security events

Cache hashed static assets with a long lifetime. Do not cache API responses containing user data, sessions, payments, or other personalized content. Purge the cache after deployment if stale content remains. Review Cloudflare Security Events and Turnstile Analytics for false positives, and recheck the webhook after every WAF or rate-limit change.

## 7. Verification after setup

- Make a login attempt and confirm the Turnstile Managed widget completes. With `TURNSTILE_ENABLED=true`, submit without a token and confirm the endpoint returns JSON HTTP `422` with `error_code: turnstile_failed`.
- Sign in through `/admin/login`, perform an auditable admin action, and confirm `admin_audit_logs.ip_address` contains the visitor's public client IP rather than a Cloudflare edge IP.
- Send a legitimate signed/token-authenticated Xendit test callback to `POST /api/webhooks/xendit`; confirm Laravel returns HTTP `200`, never an edge HTML challenge or `403`.
- Exceed the configured edge request threshold on one of the listed paths and confirm Cloudflare returns HTTP `429` (or the configured managed challenge) while ordinary SPA `/api/` calls continue returning JSON.
- Check origin firewall logs, response security headers, cache behavior, WAF rule order, Security Events, and Turnstile Analytics.

For related guidance, see the [API documentation](api/README.md) and [deployment security guide](security/README.md).
