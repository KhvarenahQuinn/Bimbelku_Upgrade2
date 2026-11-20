import { useEffect, useRef, useState } from "react";

interface TurnstileWidgetProps {
  onTokenChange: (token: string) => void;
  resetTrigger?: number;
  action?: string;
}

type TurnstileStatus = "idle" | "loading" | "ready" | "verified" | "expired" | "error" | "bypassed";

declare global {
  interface Window {
    turnstile?: {
      render: (container: HTMLElement, options: {
        sitekey: string;
        action: string;
        theme: string;
        callback: (token: string) => void;
        "expired-callback": () => void;
        "error-callback": (errorCode: string) => void;
      }) => number;
      reset: (widgetId: number) => void;
      remove: (widgetId: number) => void;
    };
  }
}

export default function TurnstileWidget({ onTokenChange, resetTrigger, action = "register" }: TurnstileWidgetProps) {
  const containerRef = useRef<HTMLDivElement>(null);
  const widgetIdRef = useRef<number | null>(null);
  const scriptLoadedRef = useRef(false);
  const scriptLoadTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const renderAttemptedRef = useRef(false);
  const scriptReadyRef = useRef(false);
  const renderWidgetRef = useRef<() => void>(() => {});
  const [token, setToken] = useState<string>("");
  const [status, setStatus] = useState<TurnstileStatus>("idle");
  const [errorMessage, setErrorMessage] = useState<string>("");

  // Keep callbacks current without tying the widget lifecycle to parent renders.
  const onTokenChangeRef = useRef(onTokenChange);
  const onVerifyRef = useRef<(token: string) => void>(() => {});
  const onExpireRef = useRef<() => void>(() => {});
  const onErrorRef = useRef<(errorCode: string) => void>(() => {});
  onTokenChangeRef.current = onTokenChange;
  onVerifyRef.current = (verifiedToken) => {
    setToken(verifiedToken);
    setStatus("verified");
    setErrorMessage("");
  };
  onExpireRef.current = () => {
    setToken("");
    setStatus("expired");
    setErrorMessage("Verifikasi keamanan Cloudflare gagal atau kedaluwarsa. Silakan coba lagi.");
  };
  onErrorRef.current = (errorCode) => {
    setToken("");
    onTokenChangeRef.current("");
    setStatus("error");
    setErrorMessage(`Verifikasi keamanan gagal (kode: ${errorCode}). Pastikan hostname terdaftar di Cloudflare Turnstile atau muat ulang.`);
  };

  // Reset widget when resetTrigger changes (e.g., after form submission error)
  useEffect(() => {
    if (resetTrigger !== undefined && widgetIdRef.current !== null && window.turnstile) {
      window.turnstile.reset(widgetIdRef.current);
      setToken("");
      scriptReadyRef.current = true;
      setStatus("ready");
      setErrorMessage("");
    }
  }, [resetTrigger]);

  // Load Turnstile script with timeout fallback
  useEffect(() => {
    // If script already loaded and widget rendered, ensure ready state
    if (scriptLoadedRef.current && window.turnstile) {
      scriptReadyRef.current = true;
      setStatus("ready");
      renderWidgetRef.current?.();
      return;
    }

    const siteKey = import.meta.env.VITE_TURNSTILE_SITE_KEY?.trim();
    if (!siteKey) {
      scriptReadyRef.current = false;
      setStatus("bypassed");
      return; // Bypass mode for local dev
    }

    // Check if turnstile is already available globally (e.g., from another tab switch)
    if (window.turnstile) {
      scriptLoadedRef.current = true;
      scriptReadyRef.current = true;
      setStatus("ready");
      renderWidgetRef.current?.();
      return;
    }

    // Check if script tag already exists in document.head
    const existingScript = document.querySelector<HTMLScriptElement>('script[src*="challenges.cloudflare.com/turnstile"]');
    if (existingScript) {
      scriptLoadedRef.current = true;
      scriptReadyRef.current = true;
      setStatus("loading");

      const handleExistingLoad = () => {
        existingScript.removeEventListener("load", handleExistingLoad);
        existingScript.removeEventListener("error", handleExistingError);
        scriptReadyRef.current = true;
        renderWidgetRef.current?.();
        setStatus("ready");
      };
      const handleExistingError = () => {
        existingScript.removeEventListener("load", handleExistingLoad);
        existingScript.removeEventListener("error", handleExistingError);
        setStatus("error");
        setErrorMessage("Gagal memuat skrip verifikasi. Periksa koneksi internet Anda.");
      };
      existingScript.addEventListener("load", handleExistingLoad);
      existingScript.addEventListener("error", handleExistingError);
      return;
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
      script.removeEventListener("load", handleLoad);
      script.removeEventListener("error", handleError);
      scriptLoadedRef.current = true;
      scriptReadyRef.current = true;
      try {
        renderWidgetRef.current?.();
      } catch {
        setStatus("error");
        setErrorMessage("Gagal memuat verifikasi keamanan. Silakan coba lagi.");
      }
      setStatus("ready");
    };

    const handleError = () => {
      if (scriptLoadTimeoutRef.current) {
        clearTimeout(scriptLoadTimeoutRef.current);
        scriptLoadTimeoutRef.current = null;
      }
      script.removeEventListener("load", handleLoad);
      script.removeEventListener("error", handleError);
      setStatus("error");
      setErrorMessage("Gagal memuat skrip verifikasi. Periksa koneksi internet Anda.");
    };

    script.addEventListener("load", handleLoad);
    script.addEventListener("error", handleError);

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
      script.removeEventListener("load", handleLoad);
      script.removeEventListener("error", handleError);
      // Do NOT remove the global script tag from document.head to avoid "Turnstile already has been loaded"
    };
  }, []);

  const siteKey = import.meta.env.VITE_TURNSTILE_SITE_KEY?.trim();
  const theme = "light";

  renderWidgetRef.current = () => {
    if (!scriptReadyRef.current || !containerRef.current || !window.turnstile || !siteKey) return;
    // Prevent "Turnstile has already been rendered in this container"
    if (widgetIdRef.current !== null) {
      if (window.turnstile) {
        window.turnstile.reset(widgetIdRef.current);
      }
      return;
    }
    if (renderAttemptedRef.current) return;

    renderAttemptedRef.current = true;
    widgetIdRef.current = window.turnstile.render(containerRef.current, {
      sitekey: siteKey,
      action,
      theme,
      callback: (verifiedToken: string) => onVerifyRef.current(verifiedToken),
      "expired-callback": () => onExpireRef.current(),
      "error-callback": (errorCode: string) => onErrorRef.current(errorCode),
    });
  };

  // Render once per configuration. Script readiness is signaled without restarting this effect.
  useEffect(() => {
    renderWidgetRef.current();

    return () => {
      if (widgetIdRef.current !== null && window.turnstile) {
        window.turnstile.remove(widgetIdRef.current);
        widgetIdRef.current = null;
        renderAttemptedRef.current = false;
      }
    };
  }, [siteKey, action, theme]);

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
    scriptReadyRef.current = false;
    // Do NOT remove the global script tag
    setStatus("idle");
    setErrorMessage("");
    setTimeout(() => {
      const siteKeyRetry = import.meta.env.VITE_TURNSTILE_SITE_KEY?.trim();
      if (!siteKeyRetry || !window.turnstile) {
        // If no site key or turnstile not loaded, reload script
        const script = document.createElement("script");
        script.src = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
        script.async = true;
        script.defer = true;

        const handleRetryLoad = () => {
          script.removeEventListener("load", handleRetryLoad);
          script.removeEventListener("error", handleRetryError);
          scriptLoadedRef.current = true;
          scriptReadyRef.current = true;
          renderWidgetRef.current();
          setStatus("ready");
        };

        const handleRetryError = () => {
          script.removeEventListener("load", handleRetryLoad);
          script.removeEventListener("error", handleRetryError);
          setStatus("error");
          setErrorMessage("Gagal memuat skrip verifikasi. Periksa koneksi internet Anda.");
        };

        script.addEventListener("load", handleRetryLoad);
        script.addEventListener("error", handleRetryError);

        document.head.appendChild(script);
      } else {
        // Turnstile already loaded, just re-render
        scriptLoadedRef.current = true;
        scriptReadyRef.current = true;
        renderWidgetRef.current();
        setStatus("ready");
      }
    }, 0);
  };

  // Keep the Turnstile host stable so Cloudflare's challenge state is not remounted.
  return (
    <div className="cf-turnstile" data-sitekey={siteKey}>
      {status === "bypassed" && (
        <p className="text-xs text-slate-500 text-center">Mode pengembangan: verifikasi dilewati</p>
      )}
      <div
        ref={containerRef}
        className={`cf-turnstile ${status === "ready" || status === "verified" ? "" : "hidden"}`}
        data-sitekey={siteKey}
      />
      {status === "loading" && (
        <div className="flex items-center justify-center gap-2 py-4 text-sm text-slate-500" role="status">
          <svg className="animate-spin h-5 w-5 text-orange-500" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          <span>Memuat verifikasi keamanan…</span>
        </div>
      )}
      {(status === "error" || status === "expired") && (
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
      )}
      {status === "verified" && (
        <p className="mt-2 text-xs text-emerald-600 text-center" role="status">Verifikasi keamanan berhasil.</p>
      )}
    </div>
  );
}