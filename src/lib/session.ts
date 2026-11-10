import axios from "axios";

export const SESSION_MARKER = "cookie-session";

/**
 * Dipancarkan setiap kali sesi browser berubah (login, refresh profil, logout).
 * `@/lib/http` memakainya untuk membuang cache response milik sesi lama, dan
 * komponen runtime memakainya untuk menunda polling sampai sesi siap.
 */
export const SESSION_UPDATED_EVENT = "bimbelku:session-updated";

export type StoredSessionUser = {
  role?: string;
  [key: string]: unknown;
};

let sessionVersion = 0;

const notifySessionUpdated = (token: string | null) => {
  sessionVersion += 1;
  if (typeof window !== "undefined") {
    window.dispatchEvent(new CustomEvent(SESSION_UPDATED_EVENT, { detail: { token } }));
  }
};

/**
 * Sinkronkan header default axios dengan token yang sedang aktif.
 * Interceptor `@/lib/http` tetap menambahkan header per request, tetapi
 * pemanggilan axios langsung juga harus memakai token yang sama.
 */
const applyAxiosAuthHeader = (token: string | null) => {
  if (token && token !== SESSION_MARKER) {
    axios.defaults.headers.common["Authorization"] = `Bearer ${token}`;
    return;
  }
  delete axios.defaults.headers.common["Authorization"];
};

/**
 * Simpan sesi browser.
 *
 * Token Bearer langsung ditulis ke localStorage dan header default axios
 * sebelum event sesi dipancarkan atau navigasi berikutnya dijalankan. Dengan
 * begitu request terautentikasi yang berjalan setelah callback tidak sempat
 * memakai sesi lama/kosong.
 */
export function storeBrowserSession(user: StoredSessionUser, accessToken?: string | null) {
  const candidate = typeof accessToken === "string" ? accessToken.trim() : "";
  const token = candidate && candidate !== SESSION_MARKER ? candidate : SESSION_MARKER;
  localStorage.setItem("token", token);
  localStorage.setItem("user", JSON.stringify(user));
  applyAxiosAuthHeader(token);
  notifySessionUpdated(token);
}

/**
 * Perbarui profil tersimpan tanpa menyentuh token yang sudah aktif.
 */
export function updateStoredSessionUser(user: StoredSessionUser) {
  localStorage.setItem("user", JSON.stringify(user));
  notifySessionUpdated(localStorage.getItem("token"));
}

/**
 * Terapkan token yang sudah tersimpan saat aplikasi dimuat ulang.
 */
export function hydrateBrowserSession() {
  applyAxiosAuthHeader(localStorage.getItem("token"));
}

export function clearBrowserSession() {
  localStorage.removeItem("token");
  localStorage.removeItem("user");
  applyAxiosAuthHeader(null);
  notifySessionUpdated(null);
}

export function usesCookieSession() {
  return localStorage.getItem("token") === SESSION_MARKER;
}

export type StoredSessionSnapshot = {
  token: string | null;
  role: string;
  user: StoredSessionUser | null;
};

/** Baca sesi tersimpan tanpa melempar error saat JSON rusak. */
export function readStoredSession(): StoredSessionSnapshot {
  const token = localStorage.getItem("token");
  const rawUser = localStorage.getItem("user");
  if (!rawUser) return { token, role: "", user: null };

  try {
    const user = JSON.parse(rawUser) as StoredSessionUser;
    return { token, role: String(user?.role || ""), user };
  } catch {
    return { token, role: "", user: null };
  }
}

export function getSessionVersion() {
  return sessionVersion;
}

export function subscribeSession(listener: () => void) {
  if (typeof window === "undefined") return () => {};
  const sessionHandler = () => listener();
  // Perubahan dari tab lain tidak melewati event sesi di tab ini.
  const storageHandler = () => {
    sessionVersion += 1;
    listener();
  };
  window.addEventListener(SESSION_UPDATED_EVENT, sessionHandler);
  window.addEventListener("storage", storageHandler);
  return () => {
    window.removeEventListener(SESSION_UPDATED_EVENT, sessionHandler);
    window.removeEventListener("storage", storageHandler);
  };
}

/**
 * Halaman transisi OAuth tidak boleh memicu polling terautentikasi atau
 * logout paksa selama kode masih ditukar dengan sesi baru.
 */
const AUTH_TRANSITION_PREFIXES = ["/oauth/google/callback", "/auth/google/callback"];

export function isAuthTransitionPath(pathname = typeof window !== "undefined" ? window.location.pathname : "") {
  return AUTH_TRANSITION_PREFIXES.some((prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`));
}
