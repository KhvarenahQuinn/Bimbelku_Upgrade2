export const ADMIN_PERMISSIONS = {
  OPERATIONS_DASHBOARD: "operations.dashboard",
  MATCHING_MANAGE: "matching.manage",
  FINANCE_PAYMENTS: "finance.payments",
  FINANCE_PAYOUTS: "finance.payouts",
  FINANCE_REFUNDS: "finance.refunds",
  TEACHERS_MANAGE: "teachers.manage",
  CASES_MANAGE: "cases.manage",
  USERS_MANAGE: "users.manage",
  CLASSES_MANAGE: "classes.manage",
  CONTENT_MANAGE: "content.manage",
  SUPPORT_MANAGE: "support.manage",
  SETTINGS_MANAGE: "settings.manage",
  AUDIT_VIEW: "audit.view",
} as const;

export type AdminPermission = typeof ADMIN_PERMISSIONS[keyof typeof ADMIN_PERMISSIONS];

export type StoredAdminUser = {
  role?: string;
  admin_type?: "super_admin" | "standard_admin" | string | null;
  is_primary_admin?: boolean;
  admin_permissions?: string[];
};

export const isSuperAdmin = (user: StoredAdminUser | null | undefined) =>
  user?.role === "admin" && (user?.admin_type === "super_admin" || user?.is_primary_admin === true);

/**
 * Admin utama memegang seluruh izin. Admin biasa hanya boleh masuk ke area
 * yang tercantum di `admin_permissions` miliknya.
 */
export const canAdmin = (
  user: StoredAdminUser | null | undefined,
  permission: AdminPermission | string,
) => {
  if (user?.role !== "admin") return false;
  if (isSuperAdmin(user)) return true;
  const granted = user?.admin_permissions;
  if (!Array.isArray(granted)) return false;
  return granted.includes(permission);
};

export const permissionForAdminPath = (pathname: string): AdminPermission | null => {
  const path = pathname.replace(/^\/admin\/?/, "");
  if (!path) return ADMIN_PERMISSIONS.OPERATIONS_DASHBOARD;

  const rules: Array<[RegExp, AdminPermission]> = [
    [/^profile(?:\/|$)/, ADMIN_PERMISSIONS.OPERATIONS_DASHBOARD],
    [/^tutor-searches(?:\/|$)/, ADMIN_PERMISSIONS.MATCHING_MANAGE],
    [/^pembayaran(?:\/|$)/, ADMIN_PERMISSIONS.FINANCE_PAYMENTS],
    [/^refunds(?:\/|$)/, ADMIN_PERMISSIONS.FINANCE_REFUNDS],
    [/^finance-security(?:\/|$)/, ADMIN_PERMISSIONS.FINANCE_PAYMENTS],
    [/^finance(?:\/|$)/, ADMIN_PERMISSIONS.FINANCE_PAYOUTS],
    [/^guru(?:\/|$)/, ADMIN_PERMISSIONS.TEACHERS_MANAGE],
    [/^users(?:\/|$)/, ADMIN_PERMISSIONS.USERS_MANAGE],
    [/^cases(?:\/|$)/, ADMIN_PERMISSIONS.CASES_MANAGE],
    [/^(?:classes|ratings)(?:\/|$)/, ADMIN_PERMISSIONS.CLASSES_MANAGE],
    [/^(?:pesan|notifikasi)(?:\/|$)/, ADMIN_PERMISSIONS.SUPPORT_MANAGE],
    [/^(?:programs|subjects|chapters|hourly-rates|stage-five|kelas-murah)(?:\/|$)/, ADMIN_PERMISSIONS.CONTENT_MANAGE],
    [/^(?:website|website-media|public-tutors|settings-display|settings-footer|notes)(?:\/|$)/, ADMIN_PERMISSIONS.SETTINGS_MANAGE],
    [/^access-control(?:\/|$)/, ADMIN_PERMISSIONS.OPERATIONS_DASHBOARD],
    [/^audit-log(?:\/|$)/, ADMIN_PERMISSIONS.AUDIT_VIEW],
  ];

  for (const [pattern, permission] of rules) {
    if (pattern.test(path)) return permission;
  }
  return null;
};

export type StoredAdmin = {
  name: string;
  email: string;
  role: string;
  admin_type?: string | null;
  admin_permissions?: string[];
  is_primary_admin?: boolean;
};

/** Profil admin dari sesi browser. Dipakai sidebar dan halaman pendaratan. */
export const readStoredAdmin = (): StoredAdmin => {
  try {
    const value = JSON.parse(localStorage.getItem("user") || "null") as StoredAdmin | null;
    return {
      name: value?.name || "Administrator",
      email: value?.email || "Admin BimbelKu",
      role: value?.role || "admin",
      admin_type: value?.admin_type,
      admin_permissions: value?.admin_permissions,
      is_primary_admin: value?.is_primary_admin,
    };
  } catch {
    return { name: "Administrator", email: "Admin BimbelKu", role: "admin", admin_type: null, admin_permissions: undefined, is_primary_admin: false };
  }
};

/**
 * Urutan modul mengikuti sidebar. Admin yang tidak memegang
 * `operations.dashboard` tidak boleh mendarat di ringkasan kerja yang kosong,
 * jadi dialihkan ke modul pertama yang ia boleh buka.
 */
const ADMIN_LANDING_ORDER: ReadonlyArray<{ path: string; permission: string }> = [
  { path: "/admin/tutor-searches", permission: ADMIN_PERMISSIONS.MATCHING_MANAGE },
  { path: "/admin/classes", permission: ADMIN_PERMISSIONS.CLASSES_MANAGE },
  { path: "/admin/cases", permission: ADMIN_PERMISSIONS.CASES_MANAGE },
  { path: "/admin/finance", permission: ADMIN_PERMISSIONS.FINANCE_PAYOUTS },
  { path: "/admin/finance?tab=refunds", permission: ADMIN_PERMISSIONS.FINANCE_REFUNDS },
  { path: "/admin/finance?tab=payments", permission: ADMIN_PERMISSIONS.FINANCE_PAYMENTS },
  { path: "/admin/guru", permission: ADMIN_PERMISSIONS.TEACHERS_MANAGE },
  { path: "/admin/users", permission: ADMIN_PERMISSIONS.USERS_MANAGE },
  { path: "/admin/notifikasi", permission: ADMIN_PERMISSIONS.SUPPORT_MANAGE },
  { path: "/admin/subjects", permission: ADMIN_PERMISSIONS.CONTENT_MANAGE },
  { path: "/admin/ratings", permission: ADMIN_PERMISSIONS.CLASSES_MANAGE },
  { path: "/admin/settings-display", permission: ADMIN_PERMISSIONS.SETTINGS_MANAGE },
  { path: "/admin/audit-log", permission: ADMIN_PERMISSIONS.AUDIT_VIEW },
];

/** `null` berarti admin tidak memegang satu pun izin yang punya halaman. */
export const firstAllowedAdminPath = (user: StoredAdminUser | null | undefined): string | null => {
  const allowed = ADMIN_LANDING_ORDER.find((entry) => canAdmin(user, entry.permission));
  return allowed ? allowed.path : null;
};
