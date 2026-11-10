import DashboardBrand from "@/components/DashboardBrand";
import { useCallback, useEffect, useMemo, useState } from "react";
import type { ReactNode } from "react";
import { Link, useLocation } from "react-router-dom";
import type { LucideIcon } from "lucide-react";
import {
  BadgeDollarSign,
  Bell,
  BookOpen,
  Coins,
  FileCheck,
  Gavel,
  Globe,
  Image,
  Layers3,
  LayoutDashboard,
  LibraryBig,
  Menu,
  MessageSquare,
  MoreHorizontal,
  NotebookPen,
  PieChart,
  SearchCheck,
  ScrollText,
  Tags,
  Users,
  X,
} from "lucide-react";
import ProfileQuickMenu from "@/components/ProfileQuickMenu";
import RoleQuickGuide from "@/components/RoleQuickGuide";
import { ADMIN_PERMISSIONS, canAdmin } from "@/lib/adminPermissions";
import { scheduleNonCriticalTask } from "@/lib/schedule";
import { usePersistentSidebarScroll } from "@/hooks/usePersistentSidebarScroll";
import {
  hasAdminMobileMenuAttention,
  hasSidebarAttention,
  unreadIdsForCurrentPage,
  type AttentionNotification,
} from "@/lib/navigationAttention";

interface AdminLayoutProps {
  children: ReactNode;
  title: string;
  subtitle?: string;
  lockContentScroll?: boolean;
}

type NavigationItem = {
  to: string;
  label: string;
  icon: LucideIcon;
  exact?: boolean;
  permission: string;
};

type NavigationSection = {
  label: string;
  items: NavigationItem[];
};

type OperationalAttentionCounts = {
  teachers: number;
  orders: number;
  matching_attention: number;
  cases: number;
  monitoring_attention: number;
  refunds: number;
  payouts: number;
};

const emptyOperationalCounts: OperationalAttentionCounts = {
  teachers: 0,
  orders: 0,
  matching_attention: 0,
  cases: 0,
  monitoring_attention: 0,
  refunds: 0,
  payouts: 0,
};

const operationalCountForPath = (path: string, counts: OperationalAttentionCounts): number => ({
  "/admin/tutor-searches": counts.matching_attention,
  "/admin/classes": counts.monitoring_attention,
  "/admin/cases": counts.cases,
  "/admin/finance": counts.orders + counts.refunds + counts.payouts,
  "/admin/guru": counts.teachers,
}[path] ?? 0);

const operationalToneForPath = (path: string): "urgent" | "warning" => (
  ["/admin/finance", "/admin/guru"].includes(path) ? "warning" : "urgent"
);

const navigation: NavigationSection[] = [
  {
    label: "Pusat operasional",
    items: [
      { to: "/admin", label: "Ringkasan kerja", icon: LayoutDashboard, exact: true, permission: ADMIN_PERMISSIONS.OPERATIONS_DASHBOARD },
      { to: "/admin/tutor-searches", label: "Pencarian tutor", icon: SearchCheck, permission: ADMIN_PERMISSIONS.MATCHING_MANAGE },
      { to: "/admin/classes", label: "Monitoring kelas", icon: BookOpen, permission: ADMIN_PERMISSIONS.CLASSES_MANAGE },
      { to: "/admin/cases", label: "Pusat kasus", icon: Gavel, permission: ADMIN_PERMISSIONS.CASES_MANAGE },
    ],
  },
  {
    label: "Transaksi dan dana",
    items: [
      { to: "/admin/finance", label: "Pusat keuangan", icon: BadgeDollarSign, permission: ADMIN_PERMISSIONS.FINANCE_PAYOUTS },
    ],
  },
  {
    label: "Tutor dan pengguna",
    items: [
      { to: "/admin/guru", label: "Verifikasi tutor", icon: FileCheck, permission: ADMIN_PERMISSIONS.TEACHERS_MANAGE },
      { to: "/admin/users", label: "Data pengguna", icon: Users, permission: ADMIN_PERMISSIONS.USERS_MANAGE },
      { to: "/admin/notifikasi", label: "Kirim notifikasi", icon: Bell, permission: ADMIN_PERMISSIONS.SUPPORT_MANAGE },
    ],
  },
  {
    label: "Katalog dan konten",
    items: [
      { to: "/admin/subjects", label: "Katalog & program", icon: LibraryBig, permission: ADMIN_PERMISSIONS.CONTENT_MANAGE },
      { to: "/admin/chapters", label: "Materi kurikulum", icon: Layers3, permission: ADMIN_PERMISSIONS.CONTENT_MANAGE },
      { to: "/admin/hourly-rates", label: "Harga per sesi", icon: Coins, permission: ADMIN_PERMISSIONS.CONTENT_MANAGE },
      { to: "/admin/kelas-murah", label: "Kelas Bersama", icon: Users, permission: ADMIN_PERMISSIONS.CONTENT_MANAGE },
      { to: "/admin/stage-five", label: "Paket, promo, dan konten", icon: Tags, permission: ADMIN_PERMISSIONS.CONTENT_MANAGE },
    ],
  },
  {
    label: "Pengaturan",
    items: [
      { to: "/admin/ratings", label: "Moderasi ulasan", icon: PieChart, permission: ADMIN_PERMISSIONS.CLASSES_MANAGE },
      { to: "/admin/settings-display", label: "Sampul default tutor", icon: Image, permission: ADMIN_PERMISSIONS.SETTINGS_MANAGE },
      { to: "/admin/public-tutors", label: "Tutor publik", icon: Users, permission: ADMIN_PERMISSIONS.SETTINGS_MANAGE },
      { to: "/admin/website", label: "Teks website", icon: Globe, permission: ADMIN_PERMISSIONS.SETTINGS_MANAGE },
      { to: "/admin/website-media", label: "Media website", icon: Image, permission: ADMIN_PERMISSIONS.SETTINGS_MANAGE },
      { to: "/admin/notes", label: "Catatan admin", icon: NotebookPen, permission: ADMIN_PERMISSIONS.SETTINGS_MANAGE },
      { to: "/admin/settings-footer", label: "Footer website", icon: Globe, permission: ADMIN_PERMISSIONS.SETTINGS_MANAGE },
    ],
  },
  {
    label: "Keamanan dan pengawasan",
    items: [
      { to: "/admin/audit-log", label: "Audit perubahan", icon: ScrollText, permission: ADMIN_PERMISSIONS.AUDIT_VIEW },
    ],
  },
];

const readAdmin = () => {
  try {
    const value = JSON.parse(localStorage.getItem("user") || "null") as { name?: string; email?: string; role?: string; admin_type?: string | null; admin_permissions?: string[]; is_primary_admin?: boolean } | null;
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

export default function AdminLayout({ children, title, subtitle = "Pusat operasional BimbelKu", lockContentScroll = false }: AdminLayoutProps) {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [attentionNotifications, setAttentionNotifications] = useState<AttentionNotification[]>([]);
  const [operationalCounts, setOperationalCounts] = useState<OperationalAttentionCounts>(emptyOperationalCounts);
  const location = useLocation();
  const { sidebarScrollRef, handleSidebarScroll } = usePersistentSidebarScroll("admin", location.pathname);
  const admin = useMemo(readAdmin, []);
  const visibleNavigation = useMemo(
    () => navigation
      .map((section) => ({
        ...section,
        items: section.items.filter((item) => canAdmin(admin, item.permission)),
      }))
      .filter((section) => section.items.length > 0),
    [admin],
  );
  const mobileNavigation = useMemo(() => {
    const preferredPaths = ["/admin", "/admin/guru", "/admin/finance"];
    const availableItems = visibleNavigation.flatMap((section) => section.items);
    return preferredPaths
      .map((path) => availableItems.find((item) => item.to === path))
      .filter((item): item is NavigationItem => Boolean(item));
  }, [visibleNavigation]);
  const adminLabel = admin.is_primary_admin || admin.admin_type === "super_admin" ? "Admin utama" : "Admin";
  const initials = admin.name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join("") || "AD";

  const isActive = (item: NavigationItem) => {
    if (item.exact) return location.pathname === item.to;
    return location.pathname === item.to || location.pathname.startsWith(`${item.to}/`);
  };

  const fetchAttentionData = useCallback(async (force = false) => {
    if (!localStorage.getItem("token")) return;
    try {
      const { getCached } = await import("@/lib/http");
      const [notificationsResult, statsResult] = await Promise.allSettled([
        getCached<{ attention_notifications?: AttentionNotification[] }>("/notifications", {
          params: { per_page: 1 },
          maxAgeMs: force ? 5_000 : 15_000,
          force,
        }),
        getCached<{ counts?: Partial<OperationalAttentionCounts> }>("/admin/dashboard-stats", {
          maxAgeMs: force ? 5_000 : 15_000,
          force,
        }),
      ]);
      if (notificationsResult.status === "fulfilled") {
        const notifications = notificationsResult.value.data.attention_notifications;
        setAttentionNotifications(Array.isArray(notifications) ? notifications : []);
      }
      if (statsResult.status === "fulfilled") {
        const next = statsResult.value.data.counts || {};
        setOperationalCounts({
          teachers: Number(next.teachers || 0),
          orders: Number(next.orders || 0),
          matching_attention: Number(next.matching_attention || 0),
          cases: Number(next.cases || 0),
          monitoring_attention: Number(next.monitoring_attention || 0),
          refunds: Number(next.refunds || 0),
          payouts: Number(next.payouts || 0),
        });
      }
    } catch {
      // Badge navigasi bersifat pendukung dan tidak boleh mengganggu halaman admin.
    }
  }, []);

  useEffect(() => {
    setSidebarOpen(false);
  }, [location.pathname, location.search]);

  useEffect(() => {
    let disposed = false;
    const start = () => { if (!disposed) void fetchAttentionData(false); };
    const cancelScheduledStart = scheduleNonCriticalTask(start);
    const refreshAttention = () => { if (!disposed) void fetchAttentionData(true); };
    window.addEventListener("bimbelku:data-changed", refreshAttention);
    window.addEventListener("bimbelku:navigation-attention-changed", refreshAttention);
    const interval = window.setInterval(() => {
      if (document.visibilityState === "visible") void fetchAttentionData(true);
    }, 60_000);
    return () => {
      disposed = true;
      cancelScheduledStart();
      window.removeEventListener("bimbelku:data-changed", refreshAttention);
      window.removeEventListener("bimbelku:navigation-attention-changed", refreshAttention);
      window.clearInterval(interval);
    };
  }, [fetchAttentionData]);

  useEffect(() => {
    const ids = unreadIdsForCurrentPage("admin", `${location.pathname}${location.search}`, attentionNotifications);
    if (!ids.length) return;

    const idSet = new Set(ids);
    setAttentionNotifications((current) => current.filter((item) => !idSet.has(item.id)));

    let cancelled = false;
    void import("@/lib/http")
      .then(({ default: http }) => http.post("/notifications/read-batch", { ids }))
      .catch(() => {
        if (!cancelled) void fetchAttentionData(true);
      });

    return () => { cancelled = true; };
  }, [attentionNotifications, fetchAttentionData, location.pathname, location.search]);

  return (
    <div className="flex h-dvh w-full max-w-full overflow-hidden bg-slate-50 font-sans text-slate-800">
      <a href="#main-content" className="skip-link">Lewati ke konten utama</a>
      {sidebarOpen && (
        <button
          type="button"
          aria-label="Tutup menu admin"
          className="fixed inset-0 z-40 bg-slate-950/55 backdrop-blur-sm xl:hidden"
          onClick={() => setSidebarOpen(false)}
        />
      )}

      <aside
        className={`fixed inset-y-0 left-0 z-50 flex w-[min(19rem,90vw)] flex-col border-r border-slate-200 bg-white shadow-2xl transition-transform duration-300 xl:static xl:translate-x-0 xl:shadow-none ${
          sidebarOpen ? "translate-x-0" : "-translate-x-full"
        }`}
      >
        <div className="flex h-20 items-center gap-3 border-b border-slate-100 px-5 sm:px-6">
          <DashboardBrand className="h-11 w-11" />
          <div className="min-w-0">
            <p className="truncate text-lg font-black tracking-tight text-slate-950">BimbelKu Admin</p>
            <p className="text-[11px] font-bold uppercase tracking-[0.18em] text-orange-600">Pusat operasional</p>
          </div>
          <button
            type="button"
            aria-label="Tutup menu"
            className="ml-auto grid h-10 w-10 place-items-center rounded-xl text-slate-500 hover:bg-slate-100 xl:hidden"
            onClick={() => setSidebarOpen(false)}
          >
            <X size={21} />
          </button>
        </div>

        <nav
          ref={sidebarScrollRef}
          onScroll={handleSidebarScroll}
          className="custom-scrollbar flex-1 space-y-7 overflow-y-auto px-4 py-5"
        >
          {visibleNavigation.map((section) => (
            <div key={section.label}>
              <p className="mb-2 px-3 text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">
                {section.label}
              </p>
              <div className="space-y-1">
                {section.items.map((item) => (
                  <NavItem
                    key={item.to}
                    item={item}
                    active={isActive(item)}
                    attention={hasSidebarAttention("admin", item.to, attentionNotifications)}
                    attentionCount={operationalCountForPath(item.to, operationalCounts)}
                    attentionTone={operationalToneForPath(item.to)}
                  />
                ))}
              </div>
            </div>
          ))}
        </nav>

        <div className="border-t border-slate-100 p-4">
          <div className="mb-3 flex min-w-0 items-center gap-3 rounded-2xl bg-slate-50 p-3">
            <div className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-slate-950 text-xs font-black text-white">
              {initials}
            </div>
            <div className="min-w-0">
              <p className="truncate text-sm font-black text-slate-900">{admin.name}</p>
              <p className="truncate text-xs text-slate-500">{admin.email}</p>
              <p className="mt-0.5 text-[10px] font-black uppercase tracking-wider text-orange-600">{adminLabel}</p>
            </div>
          </div>

        </div>
      </aside>

      <main id="main-content" tabIndex={-1} className="relative flex min-w-0 flex-1 flex-col overflow-x-hidden overflow-y-hidden">
        <header className="z-30 flex h-16 shrink-0 items-center justify-between border-b border-slate-200 bg-white/90 px-3 backdrop-blur-xl sm:h-20 sm:px-6 xl:px-8">
          <div className="flex min-w-0 items-center gap-3">
            <button
              type="button"
              aria-label="Buka menu admin"
              className="grid h-11 w-11 shrink-0 place-items-center rounded-xl border border-slate-200 bg-white text-slate-600 shadow-sm hover:bg-slate-50 xl:hidden"
              onClick={() => setSidebarOpen(true)}
            >
              <Menu size={21} />
            </button>
            <div className="min-w-0">
              <p className="truncate text-base font-black text-slate-950 sm:text-lg">{title}</p>
              <p className="hidden truncate text-xs font-medium text-slate-500 sm:block">{subtitle}</p>
            </div>
          </div>
          <div className="flex shrink-0 items-center gap-1 sm:gap-2">
            <RoleQuickGuide role="admin" />
            <div className="hidden items-center gap-2 rounded-full bg-emerald-50 px-3 py-2 text-[11px] font-black text-emerald-700 md:flex sm:px-4">
              <span className="h-2 w-2 rounded-full bg-emerald-500" />
              <span>Sistem aktif</span>
            </div>
              <ProfileQuickMenu
              user={admin}
              accent="admin"
              roleLabel="Admin"
              profileTo="/admin/profile"
              accountTo="/admin/settings-display"

            />
          </div>
        </header>

        <div className={`mobile-app-content min-h-0 flex-1 basis-0 overflow-x-hidden scroll-smooth p-4 pb-24 sm:px-6 sm:py-7 sm:pb-28 xl:px-8 xl:pb-7 ${lockContentScroll ? "overflow-y-hidden" : "overflow-y-auto"}`}>
          <div className={`mx-auto flex min-h-0 w-full min-w-0 max-w-[90rem] flex-col pb-0 sm:pb-24 xl:pb-16 ${lockContentScroll ? "h-full" : ""}`}>{children}</div>
        </div>
        {!sidebarOpen && (
          <AdminMobileBottomNav
            items={mobileNavigation}
            isActive={isActive}
            menuActive={!mobileNavigation.some((item) => isActive(item))}
            attentionNotifications={attentionNotifications}
            operationalCounts={operationalCounts}
            onOpenMenu={() => setSidebarOpen(true)}
          />
        )}
      </main>
    </div>
  );
}

function NavItem({
  item,
  active,
  attention = false,
  attentionCount = 0,
  attentionTone = "urgent",
}: {
  item: NavigationItem;
  active: boolean;
  attention?: boolean;
  attentionCount?: number;
  attentionTone?: "urgent" | "warning";
}) {
  const Icon = item.icon;

  return (
    <Link
      to={item.to}
      aria-current={active ? "page" : undefined}
      className={`group flex min-h-11 items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-bold transition ${
        active
          ? "bg-slate-950 text-white shadow-lg shadow-slate-200"
          : "text-slate-600 hover:bg-slate-100 hover:text-slate-950"
      }`}
    >
      <span className="relative shrink-0">
        <Icon
          size={19}
          className={active ? "text-orange-400" : "text-slate-400 transition group-hover:text-orange-500"}
          strokeWidth={active ? 2.5 : 2}
        />
        {attention && <span aria-label="Ada pembaruan yang belum dilihat" className="absolute -right-1.5 -top-1.5 h-2.5 w-2.5 rounded-full bg-rose-500 ring-2 ring-white" />}
      </span>
      <span className="min-w-0 flex-1 truncate">{item.label}</span>
      {attentionCount > 0 ? (
        <span
          aria-label={`${attentionCount} item perlu tindakan`}
          className={`inline-flex min-w-6 shrink-0 items-center justify-center rounded-full px-1.5 py-0.5 text-[10px] font-black ${
            attentionTone === "warning"
              ? "bg-amber-100 text-amber-800"
              : "bg-rose-100 text-rose-700"
          } ${active ? "ring-1 ring-white/50" : ""}`}
        >
          {attentionCount > 99 ? "99+" : attentionCount}
        </span>
      ) : active ? <span className="h-1.5 w-1.5 rounded-full bg-orange-400" /> : null}
    </Link>
  );
}

function AdminMobileBottomNav({
  items,
  isActive,
  menuActive,
  attentionNotifications,
  operationalCounts,
  onOpenMenu,
}: {
  items: NavigationItem[];
  isActive: (item: NavigationItem) => boolean;
  menuActive: boolean;
  attentionNotifications: AttentionNotification[];
  operationalCounts: OperationalAttentionCounts;
  onOpenMenu: () => void;
}) {
  const mobileRoutes = items.map((item) => item.to);
  const menuOperationalCount = [
    "/admin/tutor-searches",
    "/admin/classes",
    "/admin/cases",
    "/admin/finance",
  ].reduce((total, path) => total + operationalCountForPath(path, operationalCounts), 0);
  const menuAttention = menuOperationalCount > 0 || hasAdminMobileMenuAttention(mobileRoutes, attentionNotifications);
  return (
    <nav
      aria-label="Navigasi utama admin"
      className="fixed inset-x-3 bottom-[max(env(safe-area-inset-bottom),0.5rem)] z-40 rounded-[1.4rem] border border-slate-200/80 bg-white/95 p-1.5 shadow-[0_14px_40px_rgba(15,23,42,0.18)] backdrop-blur-xl xl:hidden"
    >
      <div className="mx-auto grid max-w-lg grid-cols-5 gap-1">
        {items.map((item) => {
          const active = isActive(item);
          const Icon = item.icon;
          const operationalCount = operationalCountForPath(item.to, operationalCounts);
          const attention = operationalCount > 0 || hasSidebarAttention("admin", item.to, attentionNotifications);
          const shortLabel = item.to === "/admin"
            ? "Beranda"
            : item.to === "/admin/guru"
              ? "Tutor"
              : item.to === "/admin/finance"
                ? "Dana"
                  : item.label;

          return (
            <Link
              key={item.to}
              to={item.to}
              aria-current={active ? "page" : undefined}
              aria-label={item.label}
              className={`flex min-h-[3.45rem] min-w-0 flex-col items-center justify-center gap-1 rounded-[1rem] px-1 text-[10px] font-bold transition ${
                active
                  ? "bg-orange-50 text-orange-600"
                  : "text-slate-400 hover:bg-slate-50 hover:text-slate-600"
              }`}
            >
              <span className="relative">
                <Icon size={19} strokeWidth={active ? 2.7 : 2} />
                {attention && <MobileAttentionBadge count={operationalCount} label="item perlu tindakan" />}
              </span>
              <span className="max-w-full truncate">{shortLabel}</span>
            </Link>
          );
        })}
        <button
          type="button"
          onClick={onOpenMenu}
          aria-label="Buka semua menu admin"
          aria-pressed={menuActive}
          className={`flex min-h-[3.45rem] min-w-0 flex-col items-center justify-center gap-1 rounded-[1rem] px-1 text-[10px] font-bold transition ${
            menuActive
              ? "bg-orange-50 text-orange-600"
              : "text-slate-400 hover:bg-slate-50 hover:text-slate-600"
          }`}
        >
          <span className="relative">
            <MoreHorizontal size={19} strokeWidth={menuActive ? 2.7 : 2} />
            {menuAttention && <MobileAttentionBadge count={menuOperationalCount} label="item perlu tindakan di menu lain" />}
          </span>
          <span className="max-w-full truncate">Menu</span>
        </button>
      </div>
    </nav>
  );
}

function MobileAttentionBadge({ count, label }: { count: number; label: string }) {
  return count > 0 ? (
    <span
      aria-label={`${count} ${label}`}
      className="absolute -right-3 -top-2 inline-flex min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[8px] font-black leading-4 text-white ring-2 ring-white"
    >
      {count > 99 ? "99+" : count}
    </span>
  ) : (
    <span aria-label="Ada pembaruan yang belum dilihat" className="absolute -right-1.5 -top-1.5 h-2.5 w-2.5 rounded-full bg-rose-500 ring-2 ring-white" />
  );
}
