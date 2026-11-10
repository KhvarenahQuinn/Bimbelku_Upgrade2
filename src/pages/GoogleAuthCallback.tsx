import { useCallback, useEffect, useRef, useState } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import { Loader2, RotateCw } from "lucide-react";
import { API_BASE_URL } from "@/lib/apiBase";
import { storeBrowserSession } from "@/lib/session";

type GoogleExchangeUser = { role?: string } & Record<string, unknown>;

type GoogleExchangeResponse = {
  access_token: string;
  user: GoogleExchangeUser;
  requires_profile_completion?: boolean;
  redirect?: string | null;
  pending_guest_order?: string | null;
};

type ExchangeErrorPayload = {
  message?: string;
  error_code?: string;
  retry_after_seconds?: number | string;
};

type ExchangeErrorShape = {
  response?: {
    status?: number;
    data?: ExchangeErrorPayload;
    headers?: Record<string, string | number | undefined>;
  };
};

type CallbackPhase = "processing" | "rate_limited" | "error";

/**
 * Single-flight per authorization code.
 *
 * React StrictMode menjalankan effect dua kali di mode development. Map ini
 * memastikan kode yang sama hanya menghasilkan satu POST. Entri yang gagal
 * dihapus agar tombol "Coba lagi" benar-benar mengirim request baru, sedangkan
 * entri yang berhasil tetap disimpan supaya callback duplikat tidak mengirim
 * ulang kode yang sudah dikonsumsi.
 */
const exchangeRequests = new Map<string, Promise<GoogleExchangeResponse>>();

const exchangeGoogleCode = (code: string): Promise<GoogleExchangeResponse> => {
  const existing = exchangeRequests.get(code);
  if (existing) return existing;

  const request = import("axios")
    .then(({ default: axios }) =>
      axios.post<GoogleExchangeResponse>(
        `${API_BASE_URL}/auth/google/exchange`,
        { code },
        { withCredentials: true },
      ),
    )
    .then((response) => response.data);

  exchangeRequests.set(code, request);
  request.catch(() => {
    // Hanya hapus request yang masih menjadi pemilik kode ini.
    if (exchangeRequests.get(code) === request) exchangeRequests.delete(code);
  });

  return request;
};

const readStoredRole = (): string => {
  try {
    return String(JSON.parse(localStorage.getItem("user") || "null")?.role || "");
  } catch {
    return "";
  }
};

const dashboardFor = (role: string): string => {
  if (role === "admin") return "/admin/login";
  return role === "teacher" ? "/guru" : "/murid";
};

/** Hanya izinkan tujuan internal yang memang milik peran tersebut. */
const safeRoleRedirect = (value: string | null | undefined, role: string | undefined): string | null => {
  if (!value?.startsWith("/") || value.startsWith("//")) return null;
  const path = value.split(/[?#]/, 1)[0] || "";
  if (role === "teacher") {
    return path === "/guru" || path.startsWith("/guru/") ? value : null;
  }
  return path === "/murid"
    || path === "/search"
    || path === "/payment"
    || path.startsWith("/student/")
    || path.startsWith("/pesanan/")
    ? value
    : null;
};

export default function GoogleAuthCallback() {
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const code = (params.get("code") || "").trim();
  const started = useRef(false);
  const [phase, setPhase] = useState<CallbackPhase>("processing");
  const [error, setError] = useState("");
  const [retryAfter, setRetryAfter] = useState(0);

  const runExchange = useCallback(async (authorizationCode: string) => {
    const codeMarker = authorizationCode.slice(-16);
    setPhase("processing");
    setError("");
    setRetryAfter(0);

    // Callback yang sama diproses ulang setelah reload/remount. Sesi sudah
    // tersimpan, jadi lanjutkan ke dashboard tanpa exchange kedua.
    if (
      sessionStorage.getItem("google_auth_consumed_marker") === codeMarker
      && localStorage.getItem("token")
    ) {
      navigate(dashboardFor(readStoredRole()), { replace: true });
      return;
    }

    try {
      const { access_token, user, requires_profile_completion, redirect, pending_guest_order } =
        await exchangeGoogleCode(authorizationCode);

      if (user.role === "admin") {
        // Backend menolak admin lewat Google, tetapi tetap jaga-jaga.
        navigate("/admin/login", { replace: true });
        return;
      }

      // Komit token dan header default axios sebelum navigasi/request apa pun.
      storeBrowserSession(user, access_token);
      sessionStorage.setItem("google_auth_consumed_marker", codeMarker);

      if (requires_profile_completion) {
        const suffix = redirect ? `?redirect=${encodeURIComponent(redirect)}` : "";
        navigate(`/complete-profile${suffix}`, { replace: true });
        return;
      }

      const safeRedirect = safeRoleRedirect(redirect, user.role);
      if (safeRedirect) {
        navigate(safeRedirect, { replace: true });
        return;
      }

      if (user.role === "teacher") {
        navigate("/guru", { replace: true });
        return;
      }

      const serverCode = typeof pending_guest_order === "string" ? pending_guest_order : "";
      navigate(serverCode ? `/pesanan/${encodeURIComponent(serverCode)}` : "/murid", { replace: true });
    } catch (reason: unknown) {
      const response = (reason as ExchangeErrorShape)?.response;
      const payload = response?.data;

      // Exchange pertama yang berhasil bisa diikuti callback ganda. Jangan
      // tampilkan kegagalan palsu bila sesi dari kode ini sudah tersimpan.
      if (
        sessionStorage.getItem("google_auth_consumed_marker") === authorizationCode.slice(-16)
        && localStorage.getItem("token")
      ) {
        navigate(dashboardFor(readStoredRole()), { replace: true });
        return;
      }

      const retrySeconds = Math.max(
        1,
        Number(payload?.retry_after_seconds ?? response?.headers?.["retry-after"] ?? 0) || 0,
      );

      if (payload?.error_code === "auth_login_rate_limited" || response?.status === 429) {
        setPhase("rate_limited");
        setRetryAfter(retrySeconds || 30);
        setError(payload?.message || "Percobaan login Google terlalu sering. Tunggu sebentar lalu coba lagi.");
        return;
      }

      setPhase("error");
      setError(payload?.message || "Login Google gagal diproses.");
    }
  }, [navigate]);

  useEffect(() => {
    if (started.current) return;
    started.current = true;

    if (!code) {
      setPhase("error");
      setError("Kode login Google tidak ditemukan.");
      return;
    }

    void runExchange(code);
  }, [code, runExchange]);

  useEffect(() => {
    if (phase !== "rate_limited") return;

    const timer = window.setInterval(() => {
      setRetryAfter((value) => {
        if (value <= 1) {
          window.clearInterval(timer);
          return 0;
        }
        return value - 1;
      });
    }, 1000);

    return () => window.clearInterval(timer);
  }, [phase]);

  const retry = () => {
    if (phase !== "rate_limited" || retryAfter > 0) return;
    if (!code) {
      setPhase("error");
      setError("Kode login Google tidak ditemukan.");
      return;
    }
    void runExchange(code);
  };

  return (
    <main
      className="fixed inset-0 z-[var(--layer-alert)] grid min-h-dvh place-items-center bg-[#fff7ed] px-4 py-[calc(1.5rem+env(safe-area-inset-top))] pb-[calc(1.5rem+env(safe-area-inset-bottom))]"
      aria-busy={phase === "processing"}
    >
      {phase === "processing" ? (
        <div role="status" aria-live="polite" className="flex flex-col items-center text-center">
          <div className="relative grid h-24 w-24 place-items-center rounded-full bg-white shadow-2xl shadow-orange-200/70 ring-1 ring-orange-100">
            <span className="absolute inset-0 animate-ping rounded-full border border-orange-300/60 motion-reduce:animate-none" aria-hidden="true" />
            <Loader2 className="relative h-10 w-10 animate-spin text-orange-600 motion-reduce:animate-none" aria-hidden="true" />
          </div>
          <h1 className="mt-6 text-2xl font-black text-[#14213D]">Menyelesaikan masuk dengan Google…</h1>
          <p className="mt-2 max-w-sm text-sm leading-6 text-slate-600">
            Jangan tutup halaman ini. Anda akan diarahkan ke dashboard secara otomatis.
          </p>
        </div>
      ) : (
        <section
          role={phase === "rate_limited" ? "status" : "alert"}
          aria-live="polite"
          className="w-full max-w-md rounded-3xl border border-orange-100 bg-white p-8 text-center shadow-xl shadow-orange-100/60"
        >
          <h1 className="text-2xl font-black text-slate-900">
            {phase === "rate_limited" ? "Tunggu sebentar" : "Login Google gagal"}
          </h1>
          <p className="mt-3 text-sm leading-6 text-slate-600">{error}</p>

          {phase === "rate_limited" ? (
            <>
              <p className="mt-4 text-sm font-bold text-orange-700">
                {retryAfter > 0 ? `Coba lagi dalam ${retryAfter} detik.` : "Anda sudah dapat mencoba lagi."}
              </p>
              <button
                type="button"
                onClick={retry}
                disabled={retryAfter > 0}
                className="mt-5 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl bg-orange-600 px-5 py-3 text-sm font-bold text-white disabled:cursor-not-allowed disabled:opacity-50"
              >
                {retryAfter > 0
                  ? <Loader2 className="h-4 w-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                  : <RotateCw className="h-4 w-4" aria-hidden="true" />}
                {retryAfter > 0 ? "Menunggu…" : "Coba lagi"}
              </button>
              <Link to="/login" className="mt-4 inline-flex min-h-11 items-center text-xs font-semibold text-slate-500 underline">
                Batalkan dan kembali ke login
              </Link>
            </>
          ) : (
            <Link to="/login" className="mt-6 inline-flex min-h-11 items-center rounded-xl bg-orange-600 px-5 py-3 text-sm font-bold text-white">
              Kembali ke login
            </Link>
          )}
        </section>
      )}
    </main>
  );
}
