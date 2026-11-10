import axios, { AxiosRequestConfig, AxiosResponse } from "axios";
import { API_BASE_URL, STORAGE_BASE_URL } from "@/lib/apiBase";
import { SESSION_MARKER, SESSION_UPDATED_EVENT, isAuthTransitionPath } from "@/lib/session";

export { API_BASE_URL, STORAGE_BASE_URL } from "@/lib/apiBase";

axios.defaults.withCredentials = true;

const http = axios.create({
  baseURL: API_BASE_URL,
  timeout: 20000,
  withCredentials: true,
  headers: {
    Accept: "application/json",
  },
});

type CacheEntry = {
  expiresAt: number;
  response: AxiosResponse<unknown>;
};

type CachedGetConfig = AxiosRequestConfig & {
  maxAgeMs?: number;
  force?: boolean;
};

const responseCache = new Map<string, CacheEntry>();
const inFlightGets = new Map<string, Promise<AxiosResponse<unknown>>>();
const financialMutationKeys = new Map<string, { key: string; expiresAt: number }>();
const MAX_CACHE_ENTRIES = 80;
let cacheGeneration = 0;

const PUBLIC_GET_ENDPOINTS = new Set([
  "/learning-catalog",
  "/settings/footer",
  "/socials",
  "/settings/teacher-cover",
  "/website-content",
  "/package-plans",
  "/program-groups",
  "/learning-programs",
  "/learning-time-slots",
  "/content/banners",
  "/content/tutorials",
  "/content/promotions",
]);

const requestPathname = (url: string): string => {
  const withoutBase = url.startsWith(API_BASE_URL)
    ? url.slice(API_BASE_URL.length)
    : url;
  return withoutBase.split("?")[0] || "/";
};

const isPublicGetRequest = (method: string | undefined, url: string): boolean => {
  if ((method || "get").toLowerCase() !== "get") return false;
  const pathname = requestPathname(url);
  return PUBLIC_GET_ENDPOINTS.has(pathname)
    || pathname.startsWith("/content/promotions/")
    || pathname.startsWith("/subject-pages/");
};

type FinancialRequestConfig = AxiosRequestConfig & {
  bimbelkuFinanceKeySlot?: string;
};

const stableParams = (params: unknown): string => {
  if (!params || typeof params !== "object") return "";

  return JSON.stringify(
    Object.entries(params as Record<string, unknown>)
      .filter(([, value]) => value !== undefined && value !== null)
      .sort(([left], [right]) => left.localeCompare(right)),
  );
};

const cacheKey = (url: string, config: AxiosRequestConfig): string => {
  const token = isPublicGetRequest(config.method || "get", url)
    ? "public"
    : localStorage.getItem("token") || "public";
  return `${token}|${url}|${stableParams(config.params)}`;
};

const pruneCache = () => {
  const now = Date.now();
  for (const [key, entry] of responseCache) {
    if (entry.expiresAt <= now) responseCache.delete(key);
  }

  while (responseCache.size > MAX_CACHE_ENTRIES) {
    const oldestKey = responseCache.keys().next().value as string | undefined;
    if (!oldestKey) break;
    responseCache.delete(oldestKey);
  }
};

export function clearApiCache(pathFragment?: string) {
  cacheGeneration += 1;
  if (!pathFragment) {
    responseCache.clear();
    inFlightGets.clear();
    return;
  }

  for (const key of responseCache.keys()) {
    if (key.includes(pathFragment)) responseCache.delete(key);
  }
  for (const key of inFlightGets.keys()) {
    if (key.includes(pathFragment)) inFlightGets.delete(key);
  }
}

export async function getCached<T = unknown>(
  url: string,
  config: CachedGetConfig = {},
): Promise<AxiosResponse<T>> {
  const {
    maxAgeMs = 30_000,
    force = false,
    ...requestConfig
  } = config;
  const key = cacheKey(url, requestConfig);
  const requestGeneration = cacheGeneration;
  const cached = responseCache.get(key);

  if (!force && cached && cached.expiresAt > Date.now()) {
    return cached.response as AxiosResponse<T>;
  }
  if (force) responseCache.delete(key);

  const pending = inFlightGets.get(key);
  if (pending) return pending as Promise<AxiosResponse<T>>;

  const request = http.get<T>(url, requestConfig)
    .then((response) => {
      if (requestGeneration === cacheGeneration) {
        responseCache.set(key, {
          expiresAt: Date.now() + Math.max(0, maxAgeMs),
          response,
        });
        pruneCache();
      }
      return response;
    })
    .finally(() => {
      inFlightGets.delete(key);
    });

  inFlightGets.set(key, request as Promise<AxiosResponse<unknown>>);
  return request;
}

http.interceptors.request.use((config) => {
  const method = config.method?.toLowerCase();
  const url = String(config.url || "");
  const token = localStorage.getItem("token");
  if (token && token !== SESSION_MARKER && !isPublicGetRequest(method, url)) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  const financialMutation = method && !["get", "head", "options"].includes(method) && (
    /\/orders\/\d+\/pay$/.test(url)
    || /\/orders\/\d+\/xendit-session$/.test(url)
    || /\/student\/cheap-classes\/\d+\/join$/.test(url)
    || url === "/admin/cheap-class-templates"
    || /\/admin\/cheap-class-templates\/\d+\/recurrence$/.test(url)
    || /\/admin\/cheap-classes\/\d+\/cancel$/.test(url)
    || /\/admin\/cheap-classes\/\d+\/sessions\/\d+\/(?:verify|request-revision)$/.test(url)
    || url === "/student/packages"
    || url.includes("/teacher/bank")
    || url === "/teacher/payout-requests"
    || url.includes("/admin/verify-payment")
    || url.includes("/admin/payment-settings")
    || url.includes("/admin/commission-setting")
    || url.includes("/admin/payout")
    || url.includes("/admin/refunds/")
    || /\/student\/refunds\/\d+\/destination$/.test(url)
    || /\/student\/packages\/\d+\/subjects\/\d+\/teacher-replacements$/.test(url)
    || /\/student\/teacher-replacements\/\d+\/(?:cancel|retry|reschedule|request-refund)$/.test(url)
    || /\/admin\/teacher-replacements\/\d+\/(?:approve|reject)$/.test(url)
  );
  if (financialMutation && !config.headers["Idempotency-Key"]) {
    const slot = `${method}:${url}`;
    const existing = financialMutationKeys.get(slot);
    const key = existing && existing.expiresAt > Date.now()
      ? existing.key
      : typeof crypto.randomUUID === "function"
        ? crypto.randomUUID()
        : `bimbelku-${Date.now()}-${Math.random().toString(36).slice(2)}`;
    financialMutationKeys.set(slot, {
      key,
      expiresAt: Date.now() + 5 * 60_000,
    });
    config.headers["Idempotency-Key"] = key;
    (config as FinancialRequestConfig).bimbelkuFinanceKeySlot = slot;
  }
  return config;
});

/**
 * Endpoint latar yang tidak boleh memicu logout paksa. Kegagalan di sini
 * ditangani komponennya masing-masing; request kritis lain tetap memvalidasi
 * sesi dan akan memicu logout bila token benar-benar kedaluwarsa.
 */
const NON_CRITICAL_SESSION_PATHS = new Set([
  "/active-order",
  "/session-action/next",
]);

const bearerFromHeaders = (headers: unknown): string | null => {
  if (!headers || typeof headers !== "object") return null;
  const record = headers as Record<string, unknown> & { get?: (name: string) => unknown };
  const raw = typeof record.get === "function"
    ? record.get("Authorization")
    : record.Authorization ?? record.authorization;
  return typeof raw === "string" && raw.startsWith("Bearer ")
    ? raw.slice("Bearer ".length).trim()
    : null;
};

// Cache response terikat pada token saat request dibuat. Saat token berganti,
// buang cache agar halaman baru tidak membaca data milik sesi sebelumnya.
let lastKnownSessionToken = typeof window !== "undefined" ? localStorage.getItem("token") : null;
if (typeof window !== "undefined") {
  window.addEventListener(SESSION_UPDATED_EVENT, () => {
    const token = localStorage.getItem("token");
    if (token === lastKnownSessionToken) return;
    lastKnownSessionToken = token;
    clearApiCache();
  });
}

const handleRejectedResponse = (error: unknown) => {
  if (axios.isAxiosError(error)) {
    const financeSlot = (error.config as FinancialRequestConfig | undefined)
      ?.bimbelkuFinanceKeySlot;
    if (financeSlot && error.response) {
      financialMutationKeys.delete(financeSlot);
    }
    const requestUrl = String(error.config?.url || "");
    const requestPath = requestPathname(requestUrl);
    const ignoresExpiredSession = requestUrl.endsWith("/login")
      || requestUrl.endsWith("/logout")
      || requestUrl.endsWith("/logout-all")
      || requestUrl.endsWith("/auth/google/exchange");
    const storedToken = localStorage.getItem("token");
    const activeToken = storedToken && storedToken !== SESSION_MARKER ? storedToken : null;
    const requestToken = bearerFromHeaders(error.config?.headers);

    // Token berubah saat request masih terbang (mis. callback OAuth baru saja
    // menyimpan sesi baru). 401 dari token lama bukan alasan untuk menghapus
    // sesi yang baru.
    const tokenChanged = Boolean(activeToken && activeToken !== requestToken);
    const nonCriticalRequest = NON_CRITICAL_SESSION_PATHS.has(requestPath);
    const authTransition = isAuthTransitionPath();

    if (
      error.response?.status === 401
      && storedToken
      && !ignoresExpiredSession
      && !nonCriticalRequest
      && !authTransition
      && !tokenChanged
    ) {
      clearApiCache();
      window.dispatchEvent(new Event("bimbelku:session-expired"));
    }
  }
  return Promise.reject(error);
};

http.interceptors.response.use(
  (response) => {
    const financeSlot = (response.config as FinancialRequestConfig)
      .bimbelkuFinanceKeySlot;
    if (financeSlot) financialMutationKeys.delete(financeSlot);
    const method = response.config.method?.toLowerCase();
    if (method && !["get", "head", "options"].includes(method)) {
      clearApiCache();
      window.dispatchEvent(new CustomEvent("bimbelku:data-changed", {
        detail: { url: String(response.config.url || "") },
      }));
    }
    return response;
  },
  handleRejectedResponse,
);

// Beberapa halaman lama masih memakai axios langsung. Interceptor global ini
// menjaga perilaku sesi tetap konsisten sampai seluruh pemanggilan dimigrasikan.
axios.interceptors.response.use(
  (response) => response,
  handleRejectedResponse,
);


const validationMessageAliases: Record<string, string> = {
  "validation.distinct": "Pilihan yang sama tidak boleh dikirim lebih dari sekali.",
  "validation.required": "Data wajib belum lengkap.",
  "validation.exists": "Pilihan sudah tidak tersedia. Muat ulang lalu pilih kembali.",
  "validation.array": "Format pilihan tidak sesuai.",
  "validation.date": "Tanggal yang dipilih tidak valid.",
};

const duplicateFieldMessage = (field?: string): string => {
  const key = field?.split(".")[0];
  if (key === "email") return "Email ini sudah terdaftar. Masuk dengan akun tersebut atau gunakan email lain.";
  if (key === "slug") return "Nama atau alamat ini sudah digunakan. Ganti dengan nama yang berbeda.";
  if (key === "name") return "Nama ini sudah digunakan. Gunakan nama lain agar tidak tertukar.";
  if (key === "code") return "Kode ini sudah digunakan. Buat kode yang berbeda.";
  return "Data ini sudah digunakan. Masukkan nilai yang berbeda.";
};

const readableApiMessage = (message?: string, field?: string): string | undefined => {
  if (!message) return undefined;
  const clean = message.trim();
  if (clean === "validation.unique" || /^The .+ has already been taken\.?$/i.test(clean)) {
    return duplicateFieldMessage(field);
  }
  if (clean === "validation.min.array") {
    if (field === "subjects") return "Pilih minimal satu mata pelajaran.";
    if (field?.endsWith(".curriculum_chapter_ids")) return "Pilih minimal satu Bab untuk mapel ini.";
    if (field?.endsWith(".weekdays")) return "Pilih minimal satu hari belajar untuk mapel ini.";
    if (field?.endsWith(".schedules")) return "Atur minimal satu jadwal belajar untuk mapel ini.";
    return "Pilih minimal satu pilihan.";
  }
  return validationMessageAliases[clean] || message;
};

const isTechnicalServerMessage = (message: string): boolean => /no query results for model|modelnotfoundexception|sqlstate\[|stack trace|undefined (?:property|variable|array key)|call to (?:a member function|undefined method)|too few arguments|class [^ ]+ not found|syntax error/i.test(message);

export type ApiErrorDetails = {
  message: string;
  status?: number;
  code?: string;
  retryAfterSeconds?: number;
};

type ApiErrorPayload = {
  message?: string;
  errors?: Record<string, string[]>;
  error_code?: string;
  retry_after_seconds?: number;
};

export function getApiErrorDetails(
  error: unknown,
  fallback = "Terjadi kesalahan. Silakan coba lagi.",
): ApiErrorDetails {
  if (!axios.isAxiosError(error)) return { message: fallback };

  const data = error.response?.data as ApiErrorPayload | undefined;
  const status = error.response?.status;
  const firstErrorEntry = data?.errors ? Object.entries(data.errors).find(([, messages]) => messages.length > 0) : undefined;
  const firstError = firstErrorEntry?.[1][0];
  const genericMessage = data?.message === "The given data was invalid." ? undefined : data?.message;
  const rawRetryAfter = data?.retry_after_seconds ?? Number(error.response?.headers?.["retry-after"]);
  const retryAfterSeconds = Number.isFinite(Number(rawRetryAfter))
    ? Math.max(1, Number(rawRetryAfter))
    : undefined;
  let message = readableApiMessage(firstError || genericMessage, firstErrorEntry?.[0]);

  if (status === 429 && (!message || /too many attempts/i.test(message))) {
    message = retryAfterSeconds
      ? `Terlalu banyak percobaan dalam waktu singkat. Tunggu ${retryAfterSeconds} detik lalu coba lagi.`
      : "Terlalu banyak percobaan dalam waktu singkat. Tunggu sebentar lalu coba lagi.";
  }

  if (!message || isTechnicalServerMessage(message)) message = fallback;

  return {
    message,
    status,
    code: data?.error_code,
    retryAfterSeconds,
  };
}

export function getApiError(error: unknown, fallback = "Terjadi kesalahan. Silakan coba lagi."): string {
  return getApiErrorDetails(error, fallback).message;
}

export function getApiValidationErrors(error: unknown): Record<string, string> {
  if (!axios.isAxiosError(error)) return {};
  const data = error.response?.data as ApiErrorPayload | undefined;
  return Object.fromEntries(Object.entries(data?.errors || {}).map(([field, messages]) => [
    field, readableApiMessage(messages[0], field) || "Periksa kembali data ini.",
  ]));
}

export default http;
