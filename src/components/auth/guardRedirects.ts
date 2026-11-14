import type { StoredSessionUser } from "@/lib/session";

/** Role yang dikenal aplikasi. */
export type AppRole = "admin" | "teacher" | "student";

/**
 * Single source of truth untuk tujuan setelah login. Dipakai guard, fallback
 * 404, dan halaman login agar tidak ada dua definisi yang bisa berbeda.
 *
 * Path harus benar-benar terdaftar di App.tsx. `/admin/dashboard` dan
 * `/guru/dashboard` tidak ada; dashboard admin adalah `/admin` dan dashboard
 * tutor adalah `/guru`. Mengarahkan ke rute hantu akan jatuh ke fallback dan
 * memutar redirect tanpa akhir.
 */
export function getHomeRedirectPath(user: StoredSessionUser | null | undefined): string {
  switch (user?.role) {
    case "admin":
      return "/admin";
    case "teacher":
      return "/guru";
    case "student":
      return "/student/dashboard";
    default:
      return "/";
  }
}

export const LOGIN_PATH_BY_AREA: Record<AppRole, string> = {
  admin: "/admin/login",
  teacher: "/login",
  student: "/login",
};

/** Rute admin punya portal login sendiri; rute lain memakai login umum. */
export const loginPathForPathname = (pathname: string): string =>
  pathname === "/admin" || pathname.startsWith("/admin/")
    ? LOGIN_PATH_BY_AREA.admin
    : LOGIN_PATH_BY_AREA.student;
