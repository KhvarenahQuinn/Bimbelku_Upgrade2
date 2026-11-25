English: [cloudflare-setup.md](cloudflare-setup.md) | Bahasa Indonesia: [cloudflare-setup.id.md](cloudflare-setup.id.md)

# Cloudflare Free Tier setup for Platform BimbelKu

This guide covers the production Cloudflare zone and Laravel origin. Confirm the production hostname and origin address before changing firewall or WAF rules. The Xendit callback registered in `bimbelku-backend/routes/api.php` is `POST /api/webhooks/xendit`. Exempt this exact path from edge challenges.

## 1. DNS and TLS

1. Add the production domain to Cloudflare and update the registrar nameservers.
2. Create the required A/AAAA or CNAME records for the web origin. Enable **Proxied** (orange cloud) for public web traffic. Do not expose an unproxied origin record for the application.
3. Install a valid origin certificate whose names match the production host. Use Cloudflare Origin CA or another trusted certificate.
4. In **SSL/TLS → Overview**, choose **Full (strict)**. Do not use Flexible.
5. Enable **Always Use HTTPS** in **SSL/TLS → Edge Certificates**. Configure HTTPS consistently on the application and origin to prevent redirect loops.

## 2. Restrict origin access and trust proxy headers

Configure the server firewall or security group to allow inbound HTTP/HTTPS (ports 80 and 443) only from Cloudflare's current IPv4 and IPv6 address ranges. Block direct public access to the origin. Manage SSH and other administrative ports under a separate policy. Maintain the allowlist from Cloudflare's published IP list; do not copy a potentially stale range into this guide.

Laravel 11 configures `trustProxies(at: '*')` in `bimbelku-backend/bootstrap/app.php`. Use this setting only when the origin firewall blocks connections from untrusted clients. Cloudflare sends the client IP chain in `X-Forwarded-For`. Laravel uses this forwarded header for `$request->ip()`, including application rate-limit and audit attribution. Cloudflare also sends `CF-Connecting-IP`. Do not trust client-supplied forwarding headers if the origin is reachable outside Cloudflare.

## 3. Turnstile

1. In Cloudflare **Turnstile**, create a **Managed** widget. Allow the exact production hostnames, including a separate `www` hostname if used.
2. Set the public site key as `VITE_TURNSTILE_SITE_KEY` in the frontend build environment. Rebuild and redeploy the frontend after changing it.
3. Store the private secret as `TURNSTILE_SECRET_KEY` in the backend production secret store. Keep it out of frontend variables, this repository, and browser code.
4. Set `TURNSTILE_ENABLED=true` in production. In local and testing environments, set it to false or leave it unset when no secret is configured. If unset, the default follows whether `TURNSTILE_SECRET_KEY` is set. When enabled, a missing or invalid token returns HTTP 422. Siteverify failures are fail-closed.
5. Confirm the shared Turnstile widget appears on `/login`, `/admin/login`, and `/register`. The API endpoints are `POST /api/login`, `POST /api/login/admin`, and `POST /api/register`. The frontend sends the token as `cf-turnstile-response`; the server also accepts `turnstile_token`. The admin endpoint has its own application-level login throttle.

## 4. WAF custom rules

Create these rules in **Security → WAF → Custom rules**, in the order shown. Put Rule 1 first so an edge challenge cannot replace the JSON response expected by Xendit.

### Priority 1: skip challenges for the Xendit webhook

Expression:

```text
(http.request.method eq "POST" and http.request.uri.path eq "/api/webhooks/xendit")
```

Action: **Skip**. Select the remaining custom rules and managed WAF rules that could challenge or block the request. Do not apply this exception to all `/api/*` paths. The backend still authenticates the callback with Xendit's callback token. This skips edge challenges; it does not bypass authentication. Keep the callback available over HTTPS.

### Priority 2: challenge high threat scores on browser pages

Expression:

```text
(cf.threat_score gt 14 and not starts_with(http.request.uri.path, "/api/"))
```

Action: **Managed Challenge** (Cloudflare's “Verify you are human” interstitial). Keep the `/api/` exclusion so SPA XHR/fetch requests receive application JSON instead of an HTML challenge. Confirm `cf.threat_score` is available to the zone's ruleset. If the plan no longer provides it, use a supported equivalent bot signal and retain the `/api/` exclusion.

## 5. Rate limiting

Create a rate-limiting rule for these paths. Restrict it to POST requests if appropriate, count by source IP, and set a threshold such as **more than 10 requests per 1 minute**. Choose **Block** or **Managed Challenge**. Use a period and mitigation timeout available on the Free plan dashboard.

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

`/api/login/admin` is the actual backend route for the admin portal. `/api/admin/login` is included for deployments or proxies that expose that alternate path. Exclude the Xendit callback from this rule. Laravel applies a separate, more generous webhook limiter.

## 6. Cache and security events

Cache hashed static assets with a long lifetime. Do not cache API responses containing user data, sessions, payments, or other personalized content. Purge the cache after deployment if stale content remains. Review Cloudflare Security Events and Turnstile Analytics for false positives. Test the webhook again after changing WAF or rate-limit rules.

## 7. Verify the setup

- Complete a login with the Turnstile Managed widget. With `TURNSTILE_ENABLED=true`, submit without a token and confirm the endpoint returns JSON HTTP `422` with `error_code: turnstile_failed`.
- Sign in through `/admin/login` and perform an auditable admin action. Confirm `admin_audit_logs.ip_address` contains the visitor's public client IP, not a Cloudflare edge IP.
- Send a valid signed or token-authenticated Xendit test callback to `POST /api/webhooks/xendit`. Confirm Laravel returns HTTP `200`, not an edge HTML challenge or `403`.
- Exceed the configured edge request threshold on one of the listed paths. Confirm Cloudflare returns HTTP `429` or the configured managed challenge, while ordinary SPA `/api/` calls still return JSON.
- Check origin firewall logs, response security headers, cache behavior, WAF rule order, Security Events, and Turnstile Analytics.

For related guidance, see the [API documentation](api/README.md) and [deployment security guide](security/README.md).
