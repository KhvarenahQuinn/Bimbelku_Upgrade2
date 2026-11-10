export type AttentionRole = "student" | "teacher" | "admin";

export const NAVIGATION_ATTENTION_CHANGED_EVENT = "bimbelku:navigation-attention-changed";

export const announceNavigationAttentionChanged = () => {
  if (typeof window !== "undefined") {
    window.dispatchEvent(new Event(NAVIGATION_ATTENTION_CHANGED_EVENT));
  }
};

export type AttentionNotification = {
  id: number;
  is_read: boolean;
  target_url?: string | null;
  attention_key?: string | null;
  entity_type?: string | null;
  entity_id?: number | null;
};

type PageGroup = {
  key: string;
  paths: string[];
  mobileKey?: string;
};

const STUDENT_GROUPS: PageGroup[] = [
  { key: "student-dashboard", paths: ["/student/dashboard"], mobileKey: "home" },
  { key: "student-search", paths: ["/student/packages/new", "/student/find", "/search"], mobileKey: "search" },
  { key: "student-process", paths: ["/student/packages", "/payment"], mobileKey: "classes" },
  { key: "student-classes", paths: ["/student/my-classes"], mobileKey: "classes" },
  { key: "student-progress", paths: ["/student/progress"], mobileKey: "classes" },
  { key: "student-messages", paths: ["/student/messages"], mobileKey: "messages" },
  { key: "student-account", paths: ["/student/account"], mobileKey: "account" },
  { key: "student-profile", paths: ["/student/profile"], mobileKey: "account" },
  { key: "student-history", paths: ["/student/history"], mobileKey: "account" },
  { key: "student-vouchers", paths: ["/student/vouchers", "/student/offers"], mobileKey: "account" },
  { key: "student-help", paths: ["/student/help"], mobileKey: "account" },
  { key: "student-notifications", paths: ["/student/notifications"], mobileKey: "account" },
];

const TEACHER_GROUPS: PageGroup[] = [
  { key: "teacher-dashboard", paths: ["/guru"], mobileKey: "home" },
  { key: "teacher-offers", paths: ["/guru/permintaan"], mobileKey: "offers" },
  { key: "teacher-classes", paths: ["/guru/kelas", "/guru/kelas-murah"], mobileKey: "classes" },
  { key: "teacher-schedule", paths: ["/guru/jadwal"], mobileKey: "classes" },
  { key: "teacher-messages", paths: ["/guru/pesan"], mobileKey: "messages" },
  { key: "teacher-account", paths: ["/guru/saya"], mobileKey: "account" },
  { key: "teacher-profile", paths: ["/guru/profil"], mobileKey: "account" },
  { key: "teacher-wallet", paths: ["/guru/dompet", "/guru/rekening", "/guru/pencairan/rekening", "/guru/gaji"], mobileKey: "account" },
  { key: "teacher-performance", paths: ["/guru/performa"], mobileKey: "account" },
  { key: "teacher-notifications", paths: ["/guru/notifikasi"], mobileKey: "account" },
  { key: "teacher-help", paths: ["/guru/bantuan"], mobileKey: "account" },
];

const ADMIN_GROUPS: PageGroup[] = [
  { key: "admin-dashboard", paths: ["/admin"], mobileKey: "home" },
  { key: "admin-profile", paths: ["/admin/profile"] },
  { key: "admin-searches", paths: ["/admin/tutor-searches"] },
  { key: "admin-classes", paths: ["/admin/classes"] },
  { key: "admin-cases", paths: ["/admin/cases"] },
  { key: "admin-payments", paths: ["/admin/pembayaran"], mobileKey: "payments" },
  { key: "admin-payouts", paths: ["/admin/finance"] },
  { key: "admin-refunds", paths: ["/admin/refunds"] },
  { key: "admin-payment-settings", paths: ["/admin/settings-payment"] },
  { key: "admin-teachers", paths: ["/admin/guru"], mobileKey: "teachers" },
  { key: "admin-users", paths: ["/admin/users"] },
  { key: "admin-send-notification", paths: ["/admin/notifikasi"] },
  { key: "admin-subjects", paths: ["/admin/subjects"] },
  { key: "admin-topics", paths: ["/admin/chapters"] },
  { key: "admin-rates", paths: ["/admin/hourly-rates"] },
  { key: "admin-stage-five", paths: ["/admin/stage-five"] },
  { key: "admin-ratings", paths: ["/admin/ratings"] },
  { key: "admin-display", paths: ["/admin/settings-display"] },
  { key: "admin-notes", paths: ["/admin/notes"] },
  { key: "admin-footer", paths: ["/admin/settings-footer"] },
  { key: "admin-audit", paths: ["/admin/audit-log"] },
];

const groupsFor = (role: AttentionRole): PageGroup[] => {
  if (role === "student") return STUDENT_GROUPS;
  if (role === "teacher") return TEACHER_GROUPS;
  return ADMIN_GROUPS;
};

export const normalizeAttentionPath = (value?: string | null): string => {
  if (!value) return "";
  const raw = value.trim();
  if (!raw.startsWith("/")) return "";
  return raw.split("?")[0].split("#")[0].replace(/\/+$/, "") || "/";
};

const pathMatches = (pathname: string, base: string): boolean => {
  if (base === "/admin" || base === "/guru") return pathname === base;
  return pathname === base || pathname.startsWith(`${base}/`);
};

export const pageGroupForPath = (role: AttentionRole, value?: string | null): PageGroup | null => {
  const pathname = normalizeAttentionPath(value);
  if (!pathname) return null;

  const candidates = groupsFor(role)
    .flatMap((group) => group.paths.map((path) => ({ group, path })))
    .sort((left, right) => right.path.length - left.path.length);

  return candidates.find(({ path }) => pathMatches(pathname, path))?.group ?? null;
};

const ATTENTION_GROUPS: Partial<Record<AttentionRole, Record<string, string>>> = {
  student: {
    student_order_status_changed: "student-process",
    student_payment_required: "student-process",
    student_matching_result: "student-process",
    student_refund_status_changed: "student-history",
  },
  teacher: {
    teacher_new_offer: "teacher-offers",
    teacher_class_changed: "teacher-classes",
    teacher_schedule_changed: "teacher-schedule",
    teacher_class_cancelled: "teacher-classes",
    teacher_payout_changed: "teacher-wallet",
    teacher_payout_failed: "teacher-wallet",
  },
  admin: {
    admin_refund_exception: "admin-payouts",
  },
};

const notificationGroup = (role: AttentionRole, notification: AttentionNotification): PageGroup | null => {
  const key = notification.attention_key ? ATTENTION_GROUPS[role]?.[notification.attention_key] : undefined;
  return (key ? groupsFor(role).find((group) => group.key === key) : null)
    ?? pageGroupForPath(role, notification.target_url);
};

export const notificationTargetsCurrentPage = (
  role: AttentionRole,
  currentPath: string,
  notification: AttentionNotification,
): boolean => {
  if (notification.is_read || !notification.target_url) return false;
  const currentGroup = pageGroupForPath(role, currentPath);
  const targetGroup = notificationGroup(role, notification);
  if (!currentGroup || !targetGroup || currentGroup.key !== targetGroup.key) return false;

  const current = attentionUrl(currentPath);
  const target = attentionUrl(notification.target_url);
  if (!current || !target) return false;

  const defaults = (url: URL) => {
    const path = normalizeAttentionPath(url.pathname);
    return {
      tab: pathMatches(path, "/student/my-classes") ? "schedule" : "",
      scope: pathMatches(path, "/student/packages") || pathMatches(path, "/guru/kelas") || pathMatches(path, "/guru/permintaan") ? "active" : "",
      class_kind: "",
    };
  };
  const currentDefaults = defaults(current);
  const targetDefaults = defaults(target);

  return (["tab", "scope", "class_kind"] as const).every((key) => {
    const currentValue = current.searchParams.get(key) ?? currentDefaults[key];
    const targetValue = target.searchParams.get(key) ?? targetDefaults[key];
    return currentValue === targetValue;
  });
};

export const unreadIdsForCurrentPage = (
  role: AttentionRole,
  currentPath: string,
  notifications: AttentionNotification[],
): number[] => notifications
  .filter((notification) => notificationTargetsCurrentPage(role, currentPath, notification))
  .map((notification) => notification.id);

export const hasSidebarAttention = (
  role: AttentionRole,
  navPath: string,
  notifications: AttentionNotification[],
): boolean => {
  const navGroup = pageGroupForPath(role, navPath);
  if (!navGroup) return false;

  return notifications.some((notification) => {
    if (notification.is_read) return false;
    if (!notification.target_url) return false;
    const targetGroup = notificationGroup(role, notification);
    if (!targetGroup) return false;

    // Sidebar murid memang hanya memiliki lima menu besar. Karena itu indikator
    // mengikuti kelompok bottom-nav, sedangkan tutor/admin mengikuti halaman nyata.
    // Sidebar desktop memisahkan Proses Pesanan dan Jadwal Belajar.
    // Pengelompokan mobile hanya dipakai oleh hasMobileAttention.
    return targetGroup.key === navGroup.key;
  });
};

export const hasMobileAttention = (
  role: Exclude<AttentionRole, "admin">,
  mobileKey: string,
  notifications: AttentionNotification[],
): boolean => notifications.some((notification) => {
  if (notification.is_read) return false;
  if (!notification.target_url) return false;
  return notificationGroup(role, notification)?.mobileKey === mobileKey;
});

export type StudentClassAttentionTab = "process" | "schedule" | "history";

const attentionUrl = (value?: string | null): URL | null => {
  if (!value?.startsWith("/")) return null;
  try {
    return new URL(value, "https://bimbelku.local");
  } catch {
    return null;
  }
};

export const studentClassAttentionTab = (value?: string | null): StudentClassAttentionTab | null => {
  const target = attentionUrl(value);
  if (!target) return null;
  const path = normalizeAttentionPath(target.pathname);

  if (pathMatches(path, "/student/packages") || pathMatches(path, "/payment")) return "process";
  if (!pathMatches(path, "/student/my-classes")) return null;

  const tab = target.searchParams.get("tab");
  if (tab === "process" || tab === "history") return tab;
  return "schedule";
};

export const unreadIdsForStudentClassTab = (
  tab: StudentClassAttentionTab,
  notifications: AttentionNotification[],
): number[] => notifications
  .filter((notification) => !notification.is_read && studentClassAttentionTab(notification.target_url) === tab)
  .map((notification) => notification.id);

export const unreadIdsForTeacherClassScope = (
  scope: "active" | "history",
  notifications: AttentionNotification[],
): number[] => notifications
  .filter((notification) => {
    if (notification.is_read) return false;
    const target = attentionUrl(notification.target_url);
    if (!target) return false;
    const path = normalizeAttentionPath(target.pathname);
    if (!pathMatches(path, "/guru/kelas") && !pathMatches(path, "/guru/kelas-murah")) return false;
    return (target.searchParams.get("scope") === "history" ? "history" : "active") === scope;
  })
  .map((notification) => notification.id);

export const attentionTargetLabel = (
  role: Exclude<AttentionRole, "admin">,
  value?: string | null,
): string | null => {
  const path = normalizeAttentionPath(value);
  if (!path) return "Informasi saja";

  if (role === "student") {
    if (pathMatches(path, "/student/packages")) return "Proses Pesanan";
    if (pathMatches(path, "/student/my-classes")) return "Jadwal Belajar";
    if (pathMatches(path, "/student/progress")) return "Perkembangan Belajar";
    if (pathMatches(path, "/student/messages")) return "Pesan";
    if (pathMatches(path, "/student/history") || pathMatches(path, "/payment")) return "Pembayaran & Riwayat";
    if (pathMatches(path, "/student/vouchers") || pathMatches(path, "/student/offers")) return "Voucher Saya";
    if (pathMatches(path, "/student/help")) return "Pusat Bantuan";
    if (pathMatches(path, "/student/profile")) return "Profil";
    if (pathMatches(path, "/student/notifications")) return "Pusat Notifikasi";
    if (pathMatches(path, "/student/dashboard")) return "Beranda";
  }

  if (role === "teacher") {
    if (pathMatches(path, "/guru/permintaan")) return "Permintaan Bimbel";
    if (pathMatches(path, "/guru/kelas")) return "Kelas Saya";
    if (pathMatches(path, "/guru/jadwal")) return "Jadwal";
    if (pathMatches(path, "/guru/pesan")) return "Pesan";
    if (pathMatches(path, "/guru/dompet") || pathMatches(path, "/guru/rekening") || pathMatches(path, "/guru/gaji")) return "Dompet Tutor";
    if (pathMatches(path, "/guru/performa")) return "Performa & Banding";
    if (pathMatches(path, "/guru/bantuan")) return "Pusat Bantuan";
    if (pathMatches(path, "/guru/profil")) return "Profil";
    if (pathMatches(path, "/guru/notifikasi")) return "Notifikasi";
    if (path === "/guru") return "Beranda";
  }

  return "Halaman terkait";
};

const ADMIN_MOBILE_ROUTES: Record<string, string> = {
  "/admin": "home",
  "/admin/guru": "teachers",
  "/admin/pembayaran": "payments",
};

export const adminMobileKeyForRoute = (route: string): string | null => ADMIN_MOBILE_ROUTES[route] ?? null;

export const hasAdminMobileMenuAttention = (
  visibleMobileRoutes: string[],
  notifications: AttentionNotification[],
): boolean => notifications.some((notification) => {
  if (notification.is_read || !notification.target_url) return false;
  const targetGroup = notificationGroup("admin", notification);
  if (!targetGroup) return false;
  return !visibleMobileRoutes.some((route) => {
    const mobileGroup = pageGroupForPath("admin", route);
    return mobileGroup?.key === targetGroup.key;
  });
});
