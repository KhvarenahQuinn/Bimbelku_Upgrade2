import { useEffect, useRef, useState, useCallback } from "react";

interface TurnstileWidgetProps {
  onTokenChange: (token: string) => void;
  resetTrigger?: number;
}

export default function TurnstileWidget({ onTokenChange, resetTrigger }: TurnstileWidgetProps) {
  const containerRef = useRef<HTMLDivElement>(null);
  const widgetIdRef = useRef<number | null>(null);
  const scriptLoadedRef = useRef(false);
  const [token, setToken] = useState<string>("");

  // Reset widget when resetTrigger changes (e.g., after form submission error)
  useEffect(() => {
    if (resetTrigger !== undefined && widgetIdRef.current !== null && window.turnstile) {
      window.turnstile.reset(widgetIdRef.current);
      setToken("");
    }
  }, [resetTrigger]);

  // Load Turnstile script once
  useEffect(() => {
    if (scriptLoadedRef.current) return;
    const script = document.createElement("script");
    script.src = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
    script.async = true;
    script.defer = true;
    script.onload = () => {
      scriptLoadedRef.current = true;
    };
    document.head.appendChild(script);
    return () => {
      // Cleanup script on unmount
      if (document.head.contains(script)) {
        document.head.removeChild(script);
      }
    };
  }, []);

  // Render widget when container and script are ready
  useEffect(() => {
    if (!containerRef.current || !window.turnstile) return;

    const siteKey = import.meta.env.VITE_TURNSTILE_SITE_KEY?.trim();
    if (!siteKey) return; // Bypass mode for local dev

    const widgetId = window.turnstile.render(containerRef.current, {
      sitekey: import.meta.env.VITE_TURNSTILE_SITE_KEY,
      theme: "light",
      callback: (token: string) => {
        setToken(token);
      },
      "expired-callback": () => {
        setToken("");
      },
      "error-callback": () => {
        setToken("");
      },
    });

    widgetIdRef.current = widgetId;

    return () => {
      if (widgetIdRef.current !== null && window.turnstile) {
        window.turnstile.remove(widgetIdRef.current);
        widgetIdRef.current = null;
      }
    };
  }, []);

  // Sync token to parent
  useEffect(() => {
    onTokenChange(token);
  }, [token, onTokenChange]);

  return (
    <div
      ref={containerRef}
      className="cf-turnstile"
      data-sitekey={import.meta.env.VITE_TURNSTILE_SITE_KEY}
    />
  );
}