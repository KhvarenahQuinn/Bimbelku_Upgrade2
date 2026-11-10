import { useEffect, useState, useSyncExternalStore } from "react";
import { useLocation } from "react-router-dom";
import {
  getSessionVersion,
  isAuthTransitionPath,
  readStoredSession,
  subscribeSession,
} from "@/lib/session";
import { scheduleNonCriticalTask } from "@/lib/schedule";

/**
 * Halaman autentikasi tidak boleh memasang polling latar. Selain tidak berguna,
 * request lama dengan token kedaluwarsa bisa memicu logout saat login baru
 * sedang diproses.
 */
const SESSION_BLOCKED_PATHS = new Set([
  "/login",
  "/admin/login",
  "/register",
  "/jadi-tutor",
  "/verify-email",
  "/forgot-password",
  "/reset-password",
  "/complete-profile",
]);

const SESSION_BLOCKED_PREFIXES = [
  "/oauth/",
  "/auth/",
  "/verify-email/",
  "/reset-password/",
];

/**
 * True hanya ketika sesi tersimpan sudah lengkap (token + user + role yang
 * diizinkan), halaman bukan halaman transisi autentikasi, dan frame utama
 * sudah sempat tampil. Komponen polling baru boleh dipasang setelah itu.
 */
export function useSessionReady(allowedRoles: string[]): boolean {
  const { pathname } = useLocation();
  const sessionVersion = useSyncExternalStore(subscribeSession, getSessionVersion, getSessionVersion);
  const { token, role } = readStoredSession();
  const [ready, setReady] = useState(false);
  const allowedKey = allowedRoles.join("|");

  const blockedByRoute = isAuthTransitionPath(pathname)
    || SESSION_BLOCKED_PATHS.has(pathname)
    || SESSION_BLOCKED_PREFIXES.some((prefix) => pathname.startsWith(prefix));
  const sessionUsable = Boolean(token && role && allowedKey.split("|").includes(role));

  useEffect(() => {
    setReady(false);

    if (!sessionUsable || blockedByRoute) return;

    return scheduleNonCriticalTask(() => setReady(true));
  }, [pathname, sessionVersion, sessionUsable, blockedByRoute, allowedKey]);

  // Gate ini juga berlaku pada render pertama setelah pindah halaman, sebelum
  // effect sempat mereset state. Popup tidak pernah sempat memicu request di
  // halaman login maupun halaman transisi OAuth.
  return ready && sessionUsable && !blockedByRoute;
}
