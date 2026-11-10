import { useEffect, useRef, useState } from "react";
import { useNavigate } from "react-router-dom";
import { notify } from "@/lib/notify";
import { storeBrowserSession, usesCookieSession, clearBrowserSession, isAuthTransitionPath } from "@/lib/session";

export const SESSION_EXPIRED_EVENT = "bimbelku:session-expired";

export default function SessionLifecycle() {
  const navigate = useNavigate();
  const handledRef = useRef(false);
  const [offline, setOffline] = useState(() => !navigator.onLine);

  useEffect(() => {
    const handleExpired = () => {
      if (handledRef.current) return;
      handledRef.current = true;
      let role = "";
      try {
        role = String(JSON.parse(localStorage.getItem("user") || "null")?.role || "");
      } catch {
        role = "";
      }
      clearBrowserSession();
      sessionStorage.removeItem("bimbelku_payment_order");
      notify.warning("Sesi telah berakhir. Silakan masuk kembali.");
      const current = `${window.location.pathname}${window.location.search}${window.location.hash}`;
      const isAdminPath = current === "/admin" || current.startsWith("/admin/");
      const loginPath = role === "admin" || isAdminPath ? "/admin/login" : "/login";
      const alreadyOnLogin = window.location.pathname === loginPath;
      navigate(
        alreadyOnLogin ? loginPath : `${loginPath}?redirect=${encodeURIComponent(current)}`,
        { replace: true },
      );
      window.setTimeout(() => {
        handledRef.current = false;
      }, 1500);
    };
    const handleOffline = () => setOffline(true);
    const handleOnline = () => {
      setOffline(false);
      notify.success("Koneksi internet tersambung kembali.");
    };

    window.addEventListener(SESSION_EXPIRED_EVENT, handleExpired);
    window.addEventListener("offline", handleOffline);
    window.addEventListener("online", handleOnline);

    return () => {
      window.removeEventListener(SESSION_EXPIRED_EVENT, handleExpired);
      window.removeEventListener("offline", handleOffline);
      window.removeEventListener("online", handleOnline);
    };
  }, [navigate]);

  useEffect(() => {
    if (!usesCookieSession() || isAuthTransitionPath()) return;

    let active = true;
    void import("@/lib/http")
      .then(({ default: http }) => http.get("/user"))
      .then((response) => {
        // Token bisa berubah ke sesi Bearer baru saat request ini masih terbang
        // (mis. callback Google selesai). Jangan timpa sesi baru dengan marker.
        if (active && usesCookieSession() && response.data) storeBrowserSession(response.data);
      })
      .catch(() => {
        // Interceptor global menangani sesi yang sudah berakhir.
      });

    return () => {
      active = false;
    };
  }, []);

  if (!offline) return null;

  return (
    <div
      role="status"
      aria-live="assertive"
      className="fixed inset-x-3 top-3 z-[var(--layer-alert)] mx-auto flex max-w-xl items-center justify-center gap-2 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-center text-sm font-bold text-amber-900 shadow-xl"
    >
      <span aria-hidden="true" className="text-base">●</span>
      Koneksi terputus. Data yang belum dikirim tetap berada di formulir.
    </div>
  );
}
