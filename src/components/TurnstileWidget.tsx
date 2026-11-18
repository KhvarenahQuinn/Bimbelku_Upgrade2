import { useEffect, useRef, useState } from "react";

interface TurnstileWidgetProps {
  onTokenChange: (token: string) => void;
  resetTrigger?: number;
}

type TurnstileStatus = "idle" | "loading" | "ready" | "verified" | "expired" | "error" | "bypassed";

export default function TurnstileWidget({ onTokenChange, resetTrigger }: TurnstileWidgetProps) {
  const containerRef = useRef<HTMLDivElement>(null);
  const widgetIdRef = useRef<number | null>(null);
  const scriptLoadedRef = useRef(false);
  const scriptLoadTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const renderAttemptedRef = useRef(false);
  const [token, setToken] = useState<string>("");
  const [status, setStatus] = useState<TurnstileStatus>("idle");
  const [errorMessage, setErrorMessage] = useState<string>("");

  // Store latest callback to avoid re-running effect when parent passes new function reference
  const onTokenChangeRef = useRef(onTokenChange);
  onTokenChangeRef.current = onTokenChange;

  // Reset widget when resetTrigger changes (e.g., after form submission error)
  useEffect(() => {
    if (resetTrigger !== undefined && widgetIdRef.current !== null && window.turnstile) {
      window.turnstile.reset(widgetIdRef.current);
      setToken("");
      setStatus("ready");
      setErrorMessage("");
    }
  }, [resetTrigger]);

  // Load Turnstile script with timeout fallback
  useEffect(() => {
    if (scriptLoadedRef.current) return;

    const siteKey = import.meta.env.VITE_TURNSTILE_SITE_KEY?.trim();
    if (!siteKey) {
      setStatus("bypassed");
      return; // Bypass mode for local dev
    }

    setStatus("loading");

    const script = document.createElement("script");
    script.src = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
    script.async = true;
    script.defer = true;

    const handleLoad = () => {
      if (scriptLoadTimeoutRef.current) {
        clearTimeout(scriptLoadTimeoutRef.current);
        scriptLoadTimeoutRef.current = null;
      }
      scriptLoadedRef.current = true;
      setStatus("ready");
    };

    const handleError = () => {
      if (scriptLoadTimeoutRef.current) {
        clearTimeout(scriptLoadTimeoutRef.current);
        scriptLoadTimeoutRef.current = null;
      }
      setStatus("error");
      setErrorMessage("Gagal memuat skrip verifikasi. Periksa koneksi internet Anda.");
    };

    script.onload = handleLoad;
    script.onerror = handleError;

    // Timeout fallback (8 seconds)
    scriptLoadTimeoutRef.current = setTimeout(() => {
      if (!scriptLoadedRef.current) {
        handleError();
      }
    }, 8_000);

    document.head.appendChild(script);

    return () => {
      if (scriptLoadTimeoutRef.current) {
        clearTimeout(scriptLoadTimeoutRef.current);
        scriptLoadTimeoutRef.current = null;
      }
      if (document.head.contains(script)) {
        document.head.removeChild(script);
      }
    };
  }, []);

  // Render widget when container and script are ready - only runs once per mount
  useEffect(() => {
    if (!containerRef.current || !window.turnstile) return;
    if (status !== "ready") return;
    if (renderAttemptedRef.current) return;

    const siteKey = import.meta.env.VITE_TURNSTILE_SITE_KEY?.trim();
    if (!siteKey) return;

    renderAttemptedRef.current = true;

    const widgetId = window.turnstile.render(containerRef.current, {
      sitekey: siteKey,
      theme: "light",
      callback: (token: string) => {
        setToken(token);
        setStatus("verified");
        setErrorMessage("");
      },
      "expired-callback": () => {
        setToken("");
        setStatus("expired");
        setErrorMessage("Verifikasi keamanan Cloudflare gagal atau kedaluwarsa. Silakan coba lagi.");
      },
      "error-callback": () => {
        setToken("");
        setStatus("error");
        setErrorMessage("Verifikasi keamanan Cloudflare gagal atau kedaluwarsa. Silakan coba lagi.");
      },
    });

    widgetIdRef.current = widgetId;

    return () => {
      if (widgetIdRef.current !== null && window.turnstile) {
        window.turnstile.remove(widgetIdRef.current);
        widgetIdRef.current = null;
        renderAttemptedRef.current = false;
      }
    };
  }, [status]);

  // Sync token to parent - only depends on token, not on onTokenChange reference
  useEffect(() => {
    onTokenChangeRef.current(token);
  }, [token]);

  const handleRetry = () => {
    if (widgetIdRef.current !== null && window.turnstile) {
      window.turnstile.reset(widgetIdRef.current);
      widgetIdRef.current = null;
    }
    renderAttemptedRef.current = false;
    scriptLoadedRef.current = false;
    if (document.querySelector('script[src*="turnstile"]')) {
      document.querySelector('script[src*="turnstile"]')?.remove();
    }
    setStatus("idle");
    setErrorMessage("");
  };

  // Render different UI based on status
  if (status === "bypassed") {
    return (
      <div className="cf-turnstile" data-sitekey={import.meta.env.VITE_TURNSTILE_SITE_KEY}>
        <p className="text-xs text-slate-500 text-center">Mode pengembangan: verifikasi dilewati</p>
      </div>
    );
  }

  if (status === "loading") {
    return (
      <div ref={containerRef} className="cf-turnstile" data-sitekey={import.meta.env.VITE_TURNSTILE_SITE_KEY}>
        <div className="flex items-center justify-center gap-2 py-4 text-sm text-slate-500">
          <svg className="animate-spin h-5 w-5 text-orange-500" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          <span>Memuat verifikasi keamanan…</span>
        </div>
      </div>
    );
  }

  if (status === "error" || status === "expired") {
    return (
      <div ref={containerRef} className="cf-turnstile" data-sitekey={import.meta.env.VITE_TURNSTILE_SITE_KEY}>
        <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-center">
          <p className="text-sm font-semibold text-red-700">{errorMessage || "Verifikasi keamanan Cloudflare gagal atau kedaluwarsa. Silakan coba lagi."}</p>
          <button
            type="button"
            onClick={handleRetry}
            className="mt-3 text-sm font-bold text-orange-600 hover:underline focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2"
          >
            Muat ulang verifikasi
          </button>
        </div>
      </div>
    );
  }

  if (status === "verified") {
    return (
      <div className="cf-turnstile" data-sitekey={import.meta.env.VITE_TURNSTILE_SITE_KEY}>
        <div ref={containerRef} className="cf-turnstile" data-sitekey={import.meta.env.VITE_TURNSTILE_SITE_KEY} />
        <p className="mt-2 text-xs text-emerald-600 text-center" role="status">Verifikasi keamanan berhasil.</p>
      </div>
    );
  }

  return (
    <div
      ref={containerRef}
      className="cf-turnstile"
      data-sitekey={import.meta.env.VITE_TURNSTILE_SITE_KEY}
    />
  );
}