export function isTurnstileEnabled(
  siteKey: string | undefined = import.meta.env.VITE_TURNSTILE_SITE_KEY,
): boolean {
  return Boolean(siteKey?.trim());
}
