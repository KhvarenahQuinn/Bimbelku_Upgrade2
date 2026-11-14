import { type ReactNode, useEffect, useRef, useState, useSyncExternalStore } from "react";
import { Navigate, Outlet, useLocation } from "react-router-dom";
import { canAdmin, type AdminPermission, permissionForAdminPath } from "@/lib/adminPermissions";
import { notify } from "@/lib/notify";
import { type AppRole, getHomeRedirectPath, loginPathForPathname } from "@/components/auth/guardRedirects";
import {
  getSessionVersion,
  readStoredSession,
  subscribeSession,
  type StoredSessionUser,
} from "@/lib/session";

function useHydratedSession() {
  const sessionVersion = useSyncExternalStore(
    subscribeSession,
    getSessionVersion,
    getSessionVersion,
  );
  const { token, user } = readStoredSession();
  const [hydrated, setHydrated] = useState(false);

  useEffect(() => {
    // Satu tick cukup: session.ts sudah menulis token dan user sebelum
    // navigasi, jadi menunggu effect hanya mencegah render sebelum hidrasi.
    setHydrated(true);
  }, [sessionVersion]);

  return {
    hydrated,
    token,
    user: (user as StoredSessionUser | null) ?? null,
    sessionVersion,
  };
}

function GuardLoading() {
  return (
    <div
      role="status"
      aria-live="polite"
      className="grid min-h-dvh place-items-center bg-slate-50"
    >
      <div className="flex flex-col items-center gap-3">
        <span
          aria-hidden="true"
          className="h-8 w-8 animate-spin rounded-full border-2 border-slate-200 border-t-orange-500 motion-reduce:animate-none"
        />
        <span className="text-sm font-bold text-slate-500">Memuat sesi…</span>
      </div>
    </div>
  );
}

interface ProtectedRouteProps {
  allowedRoles: AppRole[];
  /** Izin admin spesifik untuk sub-halaman sensitif. */
  requiredPermissions?: AdminPermission[];
  children?: ReactNode;
}

/**
 * Menahan render sampai sesi siap, lalu menegakkan role dan izin admin.
 * Penolakan memakai `replace` agar tombol kembali tidak masuk ke loop.
 */
export function ProtectedRoute({
  allowedRoles,
  requiredPermissions,
  children,
}: ProtectedRouteProps) {
  const location = useLocation();
  const { hydrated, token, user } = useHydratedSession();
  const deniedRef = useRef<string | null>(null);

  const signedIn = Boolean(token && user);
  const role = String(user?.role || "");
  const roleAllowed = allowedRoles.includes(role as AppRole);

  // Izin admin diambil dari path agar guard tetap benar walau pemanggil lupa
  // mengisi requiredPermissions pada rute baru.
  const pathPermission = role === "admin" ? permissionForAdminPath(location.pathname) : null;
  const needed = requiredPermissions?.length ? requiredPermissions : pathPermission ? [pathPermission] : [];
  const permissionDenied = role === "admin" && needed.some((permission) => !canAdmin(user, permission));

  const denied = signedIn && (!roleAllowed || permissionDenied);

  useEffect(() => {
    if (!denied) {
      deniedRef.current = null;
      return;
    }
    // Hanya satu toast per percobaan; guard ini bisa re-render berkali-kali.
    const key = `${location.pathname}:${role}`;
    if (deniedRef.current === key) return;
    deniedRef.current = key;
    notify.warning("Akses ditolak", {
      description: permissionDenied
        ? "Akun ini tidak memiliki izin untuk membuka halaman tersebut."
        : "Halaman ini tidak tersedia untuk peran akun ini.",
    });
  }, [denied, deniedRef, location.pathname, permissionDenied, role]);

  if (!hydrated) return <GuardLoading />;

  if (!signedIn) {
    const loginPath = loginPathForPathname(location.pathname);
    // Kedua halaman login membaca `?redirect=`, bukan state router, sehingga
    // tujuan asal tetap terbawa walau sesi baru dimuat ulang penuh.
    const returnTo = `${location.pathname}${location.search}${location.hash}`;
    return (
      <Navigate
        to={`${loginPath}?redirect=${encodeURIComponent(returnTo)}`}
        state={{ from: location }}
        replace
      />
    );
  }

  if (denied) {
    return <Navigate to={getHomeRedirectPath(user)} replace />;
  }

  return children ? <>{children}</> : <Outlet />;
}

/**
 * Halaman login tidak boleh bisa dibuka oleh sesi yang masih aktif. Semua role
 * diarahkan ke dashboard masing-masing, bukan ke satu tujuan tetap.
 */
export function GuestOnlyRoute({ children }: { children?: ReactNode }) {
  const { hydrated, token, user } = useHydratedSession();
  if (!hydrated) return <GuardLoading />;
  if (token && user) {
    return <Navigate to={getHomeRedirectPath(user)} replace />;
  }
  return children ? <>{children}</> : <Outlet />;
}

/** Fallback untuk URL tak dikenal; session-aware, bukan 404 buta. */
export function HomeRedirectFallback() {
  const { hydrated, user } = useHydratedSession();
  if (!hydrated) return <GuardLoading />;
  return <Navigate to={getHomeRedirectPath(user)} replace />;
}
