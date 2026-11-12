import DashboardBrand from "@/components/DashboardBrand";
import { lazy, Suspense, useCallback, useState, useEffect, useRef } from "react";
import { Link, useLocation } from "react-router-dom";
import ProfileQuickMenu from "@/components/ProfileQuickMenu";
import { scheduleNonCriticalTask } from "@/lib/schedule";
import { usePersistentSidebarScroll } from "@/hooks/usePersistentSidebarScroll";
import { announceNavigationAttentionChanged, attentionTargetLabel, hasSidebarAttention, NAVIGATION_ATTENTION_CHANGED_EVENT, unreadIdsForCurrentPage, type AttentionNotification } from "@/lib/navigationAttention";
import {
  Bell,
  BookOpen,
  ClipboardList,
  Home,
  Menu,
  MessageSquare,
  Search,
  User,
  Users,
  CreditCard,
  X,
} from "lucide-react";

const RoleQuickGuide = lazy(() => import("@/components/RoleQuickGuide"));
const MobileBottomNav = lazy(() => import("@/components/MobileBottomNav"));
const DESKTOP_MEDIA_QUERY = "(min-width: 1280px)";

const storedUser = () => {
  try {
    return JSON.parse(localStorage.getItem("user") || "null");
  } catch {
    return null;
  }
};

interface StudentLayoutProps {
  children: React.ReactNode;
  title: string;
  lockContentScroll?: boolean;
  onAttentionNotificationsChange?: (notifications: AttentionNotification[]) => void;
}

export default function StudentLayout({ children, title, lockContentScroll = false, onAttentionNotificationsChange }: StudentLayoutProps) {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [isDesktop, setIsDesktop] = useState(() => window.matchMedia(DESKTOP_MEDIA_QUERY).matches);
  const [userData, setUserData] = useState<any>(() => storedUser());

  // --- STATE NOTIFIKASI ---
  const [notifications, setNotifications] = useState<any[]>([]);
  const [attentionNotifications, setAttentionNotifications] = useState<AttentionNotification[]>([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [showNotifDropdown, setShowNotifDropdown] = useState(false);

  // [BARU] State untuk Modal Detail Notifikasi
  const [selectedNotif, setSelectedNotif] = useState<any>(null);

  const lastNotificationIdRef = useRef<number>(0);
  const location = useLocation();
  const { sidebarScrollRef, handleSidebarScroll } = usePersistentSidebarScroll("student", location.pathname);
  const activeMenu = (() => {
    const path = location.pathname;
    if (path === "/student/dashboard") return "home";
    if (path === "/student/packages/new") return "search";
    if (path === "/student/kelas-murah") return "cheap-classes";
    if (path === "/student/packages" || path.startsWith("/student/packages/")) return "process";
    if (path === "/student/my-classes" || path.startsWith("/student/my-classes/") || path === "/student/progress" || path.startsWith("/student/progress/")) return "classes";
    if (path === "/student/messages" || path.startsWith("/student/messages/")) return "messages";
    if (path === "/student/history" || path.startsWith("/student/history/")) return "payments";
    if (["/student/account", "/student/profile", "/student/settings", "/student/vouchers", "/student/offers", "/student/help", "/student/notifications"].some((route) => path === route || path.startsWith(`${route}/`))) return "account";
    return "";
  })();

  useEffect(() => {
    setSidebarOpen(false);
    setShowNotifDropdown(false);
  }, [location.pathname]);

  useEffect(() => {
    const mediaQuery = window.matchMedia(DESKTOP_MEDIA_QUERY);
    const syncBreakpoint = () => setIsDesktop(mediaQuery.matches);
    syncBreakpoint();
    mediaQuery.addEventListener("change", syncBreakpoint);
    return () => mediaQuery.removeEventListener("change", syncBreakpoint);
  }, []);

  useEffect(() => {
    if (!selectedNotif) return;
    const handleEscape = (event: KeyboardEvent) => {
      if (event.key === "Escape") setSelectedNotif(null);
    };
    window.addEventListener("keydown", handleEscape);
    return () => window.removeEventListener("keydown", handleEscape);
  }, [selectedNotif]);

  // Profil untuk header disegarkan setelah pekerjaan kritis halaman selesai.
  // Nama/role tetap langsung tersedia dari localStorage sehingga tidak ada perubahan visual awal.
  useEffect(() => {
    let disposed = false;
    const fetchUser = async () => {
      try {
        const token = localStorage.getItem("token");
        if (!token) return;
        const { getCached } = await import("@/lib/http");
        const res = await getCached("/user", { maxAgeMs: 60_000 });
        if (!disposed) setUserData(res.data);
      } catch (e) { console.error("Gagal load user", e); }
    };
    const start = () => { if (!disposed) void fetchUser(); };

    const cancelScheduledStart = scheduleNonCriticalTask(start);
    return () => {
      disposed = true;
      cancelScheduledStart();
    };
  }, []);

  const handleNotifClick = useCallback(async (notif: any) => {
      setSelectedNotif(notif);
      setShowNotifDropdown(false);

      if (!notif.is_read) {
          setNotifications(prev => prev.map(n => n.id === notif.id ? {...n, is_read: true} : n));
          setAttentionNotifications(prev => prev.filter((n) => n.id !== notif.id));
          setUnreadCount(prev => Math.max(0, prev - 1));

          try {
              const { default: http } = await import("@/lib/http");
              await http.post(`/notifications/${notif.id}/read`);
              announceNavigationAttentionChanged();
          } catch {
              // Polling berikutnya akan menyelaraskan status jika request gagal.
          }
      }
  }, []);

  // 2. Fetch Notifications (Polling)
  const fetchNotifications = useCallback(async (isPolling = false) => {
      try {
          const token = localStorage.getItem("token");
          if (!token) return;
          const { getCached } = await import("@/lib/http");
          const res = await getCached("/notifications", {
            maxAgeMs: isPolling ? 5_000 : 15_000,
            force: isPolling,
          });

          const data = Array.isArray(res.data.notifications) ? res.data.notifications : [];
          const attention = Array.isArray(res.data.attention_notifications)
            ? res.data.attention_notifications
            : data.filter((item: any) => !item.is_read && item.target_url);
          const count = Number(res.data.unread_count || 0);

          setNotifications(data);
          setAttentionNotifications(attention);
          setUnreadCount(count);

          // TOAST POP-UP
          if (data.length > 0) {
              const latest = data[0];
              if (isPolling && latest.id > lastNotificationIdRef.current) {

                  // Munculkan Custom Toast
                  window.dispatchEvent(new Event("bimbelku:toast-needed"));
                  void import("sonner").then(({ toast }) => {
                    toast.custom((t) => (
                      <button
                          type="button"
                          aria-label={`Buka notifikasi: ${latest.title}`}
                          className="pointer-events-auto mx-auto flex w-[calc(100vw-1.5rem)] max-w-sm min-w-0 gap-3 overflow-hidden rounded-2xl border border-slate-100 bg-white p-3.5 text-left shadow-2xl transition hover:bg-slate-50 sm:w-full sm:gap-4 sm:p-4"
                          onClick={() => {
                              toast.dismiss(t);
                              handleNotifClick(latest);
                          }}
                      >
                          <div className="w-12 h-12 rounded-full bg-blue-50 flex items-center justify-center text-blue-600 shrink-0">
                              <Bell size={20} className="animate-bounce"/>
                          </div>
                          <div className="min-w-0 flex-1">
                              <h4 className="break-words text-sm font-bold text-slate-800">{latest.title}</h4>
                              <p className="mt-1 line-clamp-2 break-words text-xs text-slate-500">{latest.message}</p>
                              <p className="text-[10px] text-blue-700 mt-2 font-bold">Ketuk untuk membaca</p>
                          </div>
                      </button>
                    ), { duration: 5000, position: 'top-center' });
                  });
              }
              lastNotificationIdRef.current = latest.id;
          }

      } catch (error) { console.error("Notif error", error); }
  }, [handleNotifClick]);

  useEffect(() => {
      let disposed = false;
      const start = () => {
        if (!disposed) void fetchNotifications(false);
      };
      const refreshVisible = () => {
        if (!disposed && document.visibilityState === "visible") void fetchNotifications(true);
      };
      const cancelScheduledStart = scheduleNonCriticalTask(start);
      const interval = window.setInterval(refreshVisible, 15000);
      window.addEventListener("focus", refreshVisible);
      document.addEventListener("visibilitychange", refreshVisible);
      return () => {
        disposed = true;
        cancelScheduledStart();
        window.clearInterval(interval);
        window.removeEventListener("focus", refreshVisible);
        document.removeEventListener("visibilitychange", refreshVisible);
      };
  }, [fetchNotifications]);

  // Sinkronkan indikator segera ketika Pusat Notifikasi/halaman lain mengubah status baca.
  useEffect(() => {
      const syncAttention = () => { void fetchNotifications(true); };
      window.addEventListener(NAVIGATION_ATTENTION_CHANGED_EVENT, syncAttention);
      return () => window.removeEventListener(NAVIGATION_ATTENTION_CHANGED_EVENT, syncAttention);
  }, [fetchNotifications]);

  useEffect(() => {
    onAttentionNotificationsChange?.(attentionNotifications);
  }, [attentionNotifications, onAttentionNotificationsChange]);

  useEffect(() => {
    const ids = unreadIdsForCurrentPage("student", `${location.pathname}${location.search}`, attentionNotifications);
    if (!ids.length) return;

    const idSet = new Set(ids);
    setAttentionNotifications((current) => current.filter((item) => !idSet.has(item.id)));
    setNotifications((current) => current.map((item) => idSet.has(item.id) ? { ...item, is_read: true } : item));
    setUnreadCount((current) => Math.max(0, current - ids.length));
    void import("@/lib/http")
      .then(({ default: http }) => http.post("/notifications/read-batch", { ids }))
      .then(announceNavigationAttentionChanged)
      .catch(() => void fetchNotifications(true));
  }, [attentionNotifications, fetchNotifications, location.pathname, location.search]);

  return (
    <div className="flex h-dvh min-h-screen w-full max-w-full overflow-hidden bg-[#F8FAFC] font-sans text-slate-800 selection:bg-blue-100 selection:text-blue-900">
      <a href="#main-content" className="skip-link">Lewati ke konten utama</a>
      {sidebarOpen && (
        <button type="button" aria-label="Tutup menu murid" className="fixed inset-0 z-30 bg-slate-900/60 backdrop-blur-sm transition-opacity duration-300 xl:hidden" onClick={() => setSidebarOpen(false)} />
      )}

      {/* --- SIDEBAR --- */}
      <aside aria-label="Menu utama murid" className={`fixed xl:static inset-y-0 left-0 z-40 w-[min(18rem,88vw)] shrink-0 bg-white border-r border-slate-100 transform transition-transform duration-300 ease-out ${sidebarOpen ? 'translate-x-0' : '-translate-x-full xl:translate-x-0'} shadow-2xl xl:shadow-none flex flex-col`}>
        {/* LOGO AREA */}
        <div className="h-24 flex items-center px-8">
            <div className="flex items-center gap-3">
                <DashboardBrand />
                <div>
                  <h1 className="text-xl font-black text-slate-900 tracking-tight leading-none">BimbelKu</h1>
                  <span className="text-[10px] font-bold text-blue-600 uppercase tracking-[0.2em] bg-blue-50 px-1.5 py-0.5 rounded mt-1 inline-block">Student</span>
                </div>
            </div>
            <button type="button" aria-label="Tutup menu" className="ml-auto rounded-xl bg-slate-50 p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-600 xl:hidden" onClick={() => setSidebarOpen(false)}>
              <X size={20} />
            </button>
        </div>

        <nav ref={sidebarScrollRef} onScroll={handleSidebarScroll} className="flex-1 overflow-y-auto px-5 py-6 custom-scrollbar">
          <div>
              <p className="px-4 mb-3 text-[10px] font-extrabold text-slate-500 uppercase tracking-widest flex items-center gap-2"><span className="w-1.5 h-1.5 bg-blue-400 rounded-full"></span> Menu Utama</p>
              <div className="space-y-1">
                  <NavItem to="/student/dashboard" icon={Home} label="Beranda" active={activeMenu === "home"} tour="student-home" attention={hasSidebarAttention("student", "/student/dashboard", attentionNotifications)} />
                  <NavItem to="/student/packages/new" icon={Search} label="Cari Les" active={activeMenu === "search"} tour="student-cari-les" attention={hasSidebarAttention("student", "/student/packages/new", attentionNotifications)} />
                  <NavItem to="/student/kelas-murah" icon={Users} label="Kelas Bersama" active={activeMenu === "cheap-classes"} attention={hasSidebarAttention("student", "/student/kelas-murah", attentionNotifications)} />
                  <NavItem to="/student/packages" icon={ClipboardList} label="Proses Pesanan" active={activeMenu === "process"} attention={hasSidebarAttention("student", "/student/packages", attentionNotifications)} />
                  <NavItem to="/student/my-classes" icon={BookOpen} label="Jadwal Belajar" active={activeMenu === "classes"} tour="student-kelas" attention={hasSidebarAttention("student", "/student/my-classes", attentionNotifications)} />
                  <NavItem to="/student/messages" icon={MessageSquare} label="Pesan" active={activeMenu === "messages"} tour="student-pesan" attention={hasSidebarAttention("student", "/student/messages", attentionNotifications)} />
                  <NavItem to="/student/history" icon={CreditCard} label="Riwayat Pembayaran" active={activeMenu === "payments"} attention={hasSidebarAttention("student", "/student/history", attentionNotifications)} />
                  <NavItem to="/student/account" icon={User} label="Saya" active={activeMenu === "account"} tour="student-saya" attention={hasSidebarAttention("student", "/student/account", attentionNotifications)} />
              </div>
          </div>
        </nav>

      </aside>

      {/* --- MAIN CONTENT --- */}
      <main id="main-content" tabIndex={-1} className="relative flex min-h-0 min-w-0 flex-1 flex-col overflow-x-hidden overflow-y-hidden">

        {/* HEADER */}
        <header className="sticky top-0 z-20 flex h-16 shrink-0 items-center justify-between border-b border-slate-200/70 bg-white/95 px-3 backdrop-blur-xl transition-all sm:h-20 sm:px-6 xl:px-8">
          <div className="flex min-w-0 items-center gap-3 sm:gap-4">
            <button type="button" aria-label="Buka menu" className="rounded-xl border border-slate-200 bg-white p-2.5 text-slate-500 shadow-sm transition hover:bg-slate-50 xl:hidden" onClick={() => setSidebarOpen(true)}>
              <Menu size={20} />
            </button>
            <h1 className="truncate text-base font-black tracking-tight text-slate-800 sm:text-xl">{title}</h1>
          </div>

          <div className="flex shrink-0 items-center gap-1 sm:gap-3 xl:gap-5">
             <Suspense fallback={<span className="block h-10 w-10" aria-hidden="true" />}>
               <RoleQuickGuide role="student" />
             </Suspense>

             {/* DROPDOWN NOTIFIKASI */}
             <div className="relative">
                 <button
                    type="button"
                    onClick={() => setShowNotifDropdown(!showNotifDropdown)}
                    aria-label={`Notifikasi${unreadCount ? `, ${unreadCount} belum dibaca` : ""}`}
                    aria-expanded={showNotifDropdown}
                    className="relative p-2.5 rounded-full text-slate-500 hover:bg-white hover:text-blue-600 hover-shadow-md transition-all duration-300 group"
                 >
                    <Bell size={20} className={unreadCount > 0 ? 'animate-pulse' : ''} />
                    {unreadCount > 0 && (
                        <span className="absolute top-2.5 right-3 w-2.5 h-2.5 bg-rose-500 rounded-full ring-2 ring-white animate-pulse"></span>
                    )}
                 </button>

                 {showNotifDropdown && (
                     <>
                        <button type="button" aria-label="Tutup daftar notifikasi" className="fixed inset-0 z-[var(--layer-dropdown)]" onClick={() => setShowNotifDropdown(false)} />
                        <div className="fixed inset-x-3 top-[4.5rem] z-[var(--layer-dropdown)] max-w-[calc(100vw-1.5rem)] origin-top-right overflow-hidden rounded-[1.5rem] border border-slate-100 bg-white shadow-2xl ring-1 ring-slate-100 animate-in fade-in zoom-in-95 duration-200 sm:absolute sm:inset-x-auto sm:right-0 sm:top-auto sm:mt-4 sm:w-[min(24rem,calc(100vw-2rem))] sm:rounded-[2rem]">

                            <div className="p-5 border-b border-slate-50 bg-white flex justify-between items-center sticky top-0 z-10">
                                <h3 className="font-bold text-slate-800 text-lg">Notifikasi</h3>
                                {unreadCount > 0 && <span className="text-[10px] bg-rose-50 text-rose-600 px-2.5 py-1 rounded-full font-bold border border-rose-100">{unreadCount} Baru</span>}
                            </div>

                            <div className="max-h-[min(60dvh,400px)] overflow-y-auto custom-scrollbar p-2 space-y-1">
                                {notifications.length > 0 ? notifications.map((notif) => (
                                    <button
                                        type="button"
                                        key={notif.id}
                                        onClick={() => handleNotifClick(notif)} // BUKA MODAL
                                        className={`group flex w-full items-start gap-4 rounded-2xl p-4 text-left transition-all ${notif.is_read ? 'bg-white hover:bg-slate-50 opacity-60' : 'bg-blue-50/50 hover:bg-blue-50 border border-blue-100'}`}
                                    >
                                        <div className={`w-2 h-2 mt-2 rounded-full shrink-0 ${notif.is_read ? 'bg-slate-300' : 'bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.6)]'}`}></div>
                                        <div className="min-w-0 flex-1">
                                            <h4 className={`break-words text-sm ${notif.is_read ? 'font-medium text-slate-600' : 'font-bold text-slate-800'}`}>{notif.title}</h4>
                                            <p className="mt-1 line-clamp-2 break-words text-xs leading-relaxed text-slate-500">{notif.message}</p>
                                            <p className="text-[10px] text-slate-500 mt-2 font-medium flex items-center gap-1">
                                                {new Date(notif.created_at).toLocaleDateString('id-ID', {day: 'numeric', month: 'short', hour: '2-digit', minute:'2-digit'})}
                                            </p>
                                        </div>
                                    </button>
                                )) : (
                                    <div className="py-12 text-center text-slate-500 text-sm flex flex-col items-center gap-2">
                                        <Bell size={32} className="opacity-20"/>
                                        <span>Belum ada notifikasi.</span>
                                    </div>
                                )}
                            </div>
                        </div>
                     </>
                 )}
             </div>

              <ProfileQuickMenu
               user={userData}
               accent="student"
               roleLabel="Murid"
               profileTo="/student/profile"
               accountTo="/student/account"
               helpTo="/student/help"
             />
          </div>
        </header>

        <div id="student-scroll-container" className={`mobile-app-content min-h-0 flex-1 basis-0 overflow-x-hidden scroll-smooth p-4 pb-[calc(7.25rem+env(safe-area-inset-bottom))] sm:p-6 sm:pb-24 xl:p-8 xl:pb-8 ${lockContentScroll ? "overflow-y-hidden" : "overflow-y-auto"}`}>
          <div className={`mx-auto w-full min-w-0 max-w-7xl ${lockContentScroll ? "flex h-full min-h-0 flex-col" : "pb-20 sm:pb-24 xl:pb-16"}`}>{children}</div>
        </div>
        {!isDesktop && !sidebarOpen && (
          <Suspense fallback={null}>
            <MobileBottomNav role="student" attentionNotifications={attentionNotifications} />
          </Suspense>
        )}
        {isDesktop && <Link
          to="/student/messages"
          aria-label="Buka pesan kelas"
          title="Pesan kelas"
          className="fixed bottom-7 right-7 z-20 hidden h-14 w-14 place-items-center rounded-full bg-slate-950 text-white shadow-xl transition hover-rise hover:bg-indigo-700 xl:grid"
        >
          <MessageSquare size={21} />
        </Link>}

        {/* --- [FIXED] MODAL DETAIL NOTIFIKASI SISWA --- */}
        {selectedNotif && (
            <div role="dialog" aria-modal="true" aria-label="Detail notifikasi" className="fixed inset-0 z-[var(--layer-modal)] flex items-end justify-center bg-slate-900/60 p-3 pb-[max(env(safe-area-inset-bottom),0.75rem)] backdrop-blur-sm animate-in fade-in duration-300 sm:items-center sm:p-4">
                {/* [PERBAIKAN]
                    1. flex flex-col: Agar children (header, content, footer) tertata vertikal
                    2. max-h-[90dvh]: Batasi tinggi modal agar tidak melebihi layar
                */}
                <div className="relative flex max-h-[calc(100dvh-1.5rem)] w-full max-w-lg flex-col overflow-hidden rounded-[2rem] bg-white shadow-2xl animate-in zoom-in-95 duration-300 sm:max-h-[90dvh] sm:rounded-[2.5rem]">
                    <div className="absolute top-0 right-0 w-32 h-32 bg-blue-100/50 rounded-full blur-3xl -mr-10 -mt-10 pointer-events-none"></div>

                    {/* Header: Fixed */}
                    <div className="relative z-10 shrink-0 bg-white p-5 pb-4 sm:p-8 sm:pb-4">
                        <div className="flex justify-between items-start mb-6">
                            <div className="w-14 h-14 bg-blue-50 rounded-2xl flex items-center justify-center text-blue-600 shadow-sm border border-blue-100">
                                <MessageSquare size={28}/>
                            </div>
                            <button
                                type="button"
                                onClick={() => setSelectedNotif(null)}
                                aria-label="Tutup notifikasi"
                                className="p-2 bg-slate-50 text-slate-500 rounded-full hover:bg-rose-50 hover:text-rose-500 transition"
                            >
                                <X size={20}/>
                            </button>
                        </div>

                        <div>
                            <h3 className="break-words text-xl font-black leading-tight text-slate-900 sm:text-2xl">{selectedNotif.title}</h3>
                            <div className="mt-2 flex flex-wrap items-center gap-2 text-[10px] font-bold uppercase tracking-wider text-slate-500 sm:text-xs">
                                <span>Dari Admin</span>
                                <span>•</span>
                                <span>{new Date(selectedNotif.created_at).toLocaleString('id-ID', { dateStyle: 'full', timeStyle: 'short' })}</span>
                            </div>
                        </div>
                    </div>

                    {/* Content: Scrollable */}
                    <div className="relative z-10 overflow-y-auto px-5 custom-scrollbar sm:px-8">
                        <div className="whitespace-pre-wrap break-words rounded-3xl border border-slate-100 bg-slate-50 p-4 text-sm leading-relaxed text-slate-600 sm:p-6">
                            {selectedNotif.message}
                        </div>
                    </div>

                    {/* Footer: Fixed */}
                    <div className="relative z-10 shrink-0 bg-white p-5 pt-6 sm:p-8 sm:pt-6">
                        <div className={`grid gap-2 ${selectedNotif.target_url?.startsWith("/") ? "sm:grid-cols-2" : ""}`}>
                            <button
                                type="button"
                                onClick={() => setSelectedNotif(null)}
                                className="w-full rounded-xl border border-slate-200 bg-white py-3 font-bold text-slate-700 transition hover:bg-slate-50"
                            >
                                Tutup Pesan
                            </button>
                            {selectedNotif.target_url?.startsWith("/") && (
                                <Link
                                    to={selectedNotif.target_url}
                                    onClick={() => setSelectedNotif(null)}
                                    className="flex w-full items-center justify-center rounded-xl bg-slate-900 px-4 py-3 text-center font-bold text-white shadow-lg transition hover:bg-indigo-700"
                                >
                                    Buka {attentionTargetLabel("student", selectedNotif.target_url) || "halaman terkait"}
                                </Link>
                            )}
                        </div>
                    </div>
                </div>

                {/* Scrollbar Style */}
                <style>{`
                    .custom-scrollbar::-webkit-scrollbar { width: 6px; }
                    .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
                    .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
                    .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
                `}</style>
            </div>
        )}

      </main>
    </div>
  );
}

function NavItem({ to, icon: Icon, label, active, tour, attention = false }: any) {
  return (
    <Link data-tour={tour} to={to} aria-current={active ? "page" : undefined} className={`group relative flex items-center justify-between px-5 py-3.5 text-sm font-bold rounded-[1.2rem] transition-all duration-300 mb-1 ${active ? 'bg-blue-600 text-white shadow-xl shadow-blue-600/30 translate-x-1' : 'text-slate-500 hover:bg-slate-50 hover:text-blue-600'}`}>
      <div className="flex items-center gap-3.5">
        <span className="relative shrink-0">
          <Icon size={20} className={`${active ? 'text-blue-100' : 'text-slate-500 group-hover:text-blue-500'} transition-colors duration-300`} strokeWidth={active ? 2.5 : 2}/>
          {attention && <span aria-label="Ada pembaruan yang belum dilihat" className="absolute -right-1.5 -top-1.5 h-2.5 w-2.5 rounded-full bg-rose-500 ring-2 ring-white" />}
        </span>
        <span>{label}</span>
      </div>
      {active && <div className="w-1.5 h-1.5 rounded-full bg-white shadow-sm animate-pulse"></div>}
    </Link>
  );
}
