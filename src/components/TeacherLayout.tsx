import DashboardBrand from "@/components/DashboardBrand";
import { lazy, Suspense, useCallback, useState, useEffect, useRef } from "react";
import { Link, useLocation, useNavigate } from "react-router-dom";
import ProfileQuickMenu from "@/components/ProfileQuickMenu";
import { scheduleNonCriticalTask } from "@/lib/schedule";
import { usePersistentSidebarScroll } from "@/hooks/usePersistentSidebarScroll";
import { announceNavigationAttentionChanged, hasSidebarAttention, NAVIGATION_ATTENTION_CHANGED_EVENT, unreadIdsForCurrentPage, type AttentionNotification } from "@/lib/navigationAttention";
import {
  LayoutDashboard, BookOpen, Menu, X, Settings, Wallet, Banknote, 
  GraduationCap, CalendarClock, HelpCircle, Bell, MessageSquare, ClipboardCheck, BarChart3
} from "lucide-react";

const RoleQuickGuide = lazy(() => import("@/components/RoleQuickGuide"));
const MobileBottomNav = lazy(() => import("@/components/MobileBottomNav"));
const DESKTOP_MEDIA_QUERY = "(min-width: 1280px)";

const storedTeacher = () => {
  try {
    return JSON.parse(localStorage.getItem("user") || "null");
  } catch {
    return null;
  }
};

interface TeacherLayoutProps {
  children: React.ReactNode;
  title: string;
  lockContentScroll?: boolean;
  onAttentionNotificationsChange?: (notifications: AttentionNotification[]) => void;
}

export default function TeacherLayout({ children, title, lockContentScroll = false, onAttentionNotificationsChange }: TeacherLayoutProps) {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [isDesktop, setIsDesktop] = useState(() => window.matchMedia(DESKTOP_MEDIA_QUERY).matches);
  const [userData, setUserData] = useState<any>(() => storedTeacher());

  // STATE NOTIFIKASI
  const [notifications, setNotifications] = useState<any[]>([]);
  const [attentionNotifications, setAttentionNotifications] = useState<AttentionNotification[]>([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [showNotifDropdown, setShowNotifDropdown] = useState(false);

  // [BARU] Modal State
  const [selectedNotif, setSelectedNotif] = useState<any>(null);

  const lastNotificationIdRef = useRef<number>(0);
  const location = useLocation();
  const { sidebarScrollRef, handleSidebarScroll } = usePersistentSidebarScroll("teacher", location.pathname);
  const navigate = useNavigate();
  const isActive = (path: string, exact = false) => exact
    ? location.pathname === path
    : location.pathname === path || location.pathname.startsWith(`${path}/`);

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

  useEffect(() => {
    let disposed = false;
    const fetchProfile = async () => {
      try {
        const { getCached } = await import("@/lib/http");
        const response = await getCached("/teacher/profile", { maxAgeMs: 60_000 });
        if (!disposed) {
          setUserData({ name: response.data.user.name, email: response.data.user.email, photo: response.data.profile.photo_url });
        }
      } catch (error) { console.error("Gagal profil", error); }
    };
    const start = () => { if (!disposed) void fetchProfile(); };

    const cancelScheduledStart = scheduleNonCriticalTask(start);
    return () => {
      disposed = true;
      cancelScheduledStart();
    };
  }, []);

  const handleNotifClick = useCallback(async (notif: any) => {
      if (notif.target_url?.startsWith("/")) {
        navigate(notif.target_url);
      } else {
        setSelectedNotif(notif);
      }
      setShowNotifDropdown(false);

      if (!notif.is_read) {
          setNotifications(prev => prev.map(n => n.id === notif.id ? {...n, is_read: true} : n));
          setAttentionNotifications(prev => prev.filter((n) => n.id !== notif.id));
          setUnreadCount(prev => Math.max(0, prev - 1));
          try {
              const { default: http } = await import("@/lib/http");
              await http.post(`/notifications/${notif.id}/read`);
          } catch {
              // Polling berikutnya akan menyelaraskan status jika request gagal.
          }
      }
  }, [navigate]);

  // --- LOGIKA NOTIFIKASI ---
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

                  // Custom Toast dimuat hanya ketika polling benar-benar menemukan notifikasi baru.
                  window.dispatchEvent(new Event("bimbelku:toast-needed"));
                  void import("sonner").then(({ toast }) => {
                    toast.custom((t) => (
                      <button
                          type="button"
                          aria-label={`Buka notifikasi: ${latest.title}`}
                          className="pointer-events-auto flex w-full max-w-md gap-4 rounded-2xl border border-slate-100 bg-white p-4 text-left shadow-2xl transition hover:bg-slate-50 animate-in slide-in-from-top-5 duration-500"
                          onClick={() => {
                              toast.dismiss(t);
                              handleNotifClick(latest);
                          }}
                      >
                          <div className="w-12 h-12 rounded-full bg-indigo-50 flex items-center justify-center text-indigo-600 shrink-0">
                              <Bell size={20} className="animate-bounce"/>
                          </div>
                          <div className="flex-1">
                              <h4 className="font-bold text-slate-800 text-sm">{latest.title}</h4>
                              <p className="text-xs text-slate-500 mt-1 line-clamp-2">{latest.message}</p>
                              <p className="text-[10px] text-indigo-500 mt-2 font-bold">Ketuk untuk membaca</p>
                          </div>
                      </button>
                    ), { duration: 5000, position: 'top-center' });
                  });
              }
              lastNotificationIdRef.current = latest.id;
          }
      } catch {
          // Notifikasi tidak boleh mengganggu halaman utama ketika jaringan terputus.
      }
  }, [handleNotifClick]);

  useEffect(() => {
      let disposed = false;
      const start = () => { if (!disposed) void fetchNotifications(false); };
      const cancelScheduledStart = scheduleNonCriticalTask(start);
      const refreshVisible = () => {
        if (!disposed && document.visibilityState === "visible") void fetchNotifications(true);
      };
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

  useEffect(() => {
      const syncAttention = () => void fetchNotifications(true);
      window.addEventListener(NAVIGATION_ATTENTION_CHANGED_EVENT, syncAttention);
      return () => window.removeEventListener(NAVIGATION_ATTENTION_CHANGED_EVENT, syncAttention);
  }, [fetchNotifications]);

  useEffect(() => {
    onAttentionNotificationsChange?.(attentionNotifications);
  }, [attentionNotifications, onAttentionNotificationsChange]);

  useEffect(() => {
    const ids = unreadIdsForCurrentPage("teacher", `${location.pathname}${location.search}`, attentionNotifications);
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
    <div className="flex h-dvh w-full max-w-full overflow-hidden bg-[#F8FAFC] font-sans text-slate-800">
      <a href="#main-content" className="skip-link">Lewati ke konten utama</a>
      {sidebarOpen && ( <button type="button" aria-label="Tutup menu tutor" className="fixed inset-0 z-30 bg-slate-900/60 backdrop-blur-sm xl:hidden" onClick={() => setSidebarOpen(false)} /> )}

      {/* SIDEBAR */}
      <aside className={`fixed xl:static inset-y-0 left-0 z-40 w-[min(18rem,88vw)] bg-white border-r border-slate-100 transform transition-transform duration-300 ease-out ${sidebarOpen ? 'translate-x-0' : '-translate-x-full xl:translate-x-0'} shadow-xl xl:shadow-none flex flex-col`}>
        <div className="h-24 flex items-center px-8 border-b border-slate-50">
             <DashboardBrand className="mr-3 h-10 w-10" />
             <div><h1 className="text-xl font-black text-slate-900 tracking-tight leading-none">BimbelKu</h1><span className="text-[10px] font-bold text-indigo-500 uppercase tracking-widest bg-indigo-50 px-1.5 py-0.5 rounded mt-1 inline-block">Teacher</span></div>
             <button aria-label="Tutup menu" className="xl:hidden ml-auto text-slate-400 hover:text-indigo-600 transition" onClick={() => setSidebarOpen(false)}><X size={20} /></button>
        </div>

        <nav ref={sidebarScrollRef} onScroll={handleSidebarScroll} className="flex-1 px-5 py-6 space-y-8 overflow-y-auto custom-scrollbar">
            <div>
                <div className="px-4 mb-3 text-[10px] font-extrabold text-slate-400 uppercase tracking-widest flex items-center gap-2"><span className="w-1.5 h-1.5 bg-indigo-400 rounded-full"></span> Dashboard</div>
                <NavItem to="/guru" icon={LayoutDashboard} label="Overview" active={isActive('/guru', true)} attention={hasSidebarAttention("teacher", "/guru", attentionNotifications)} />
            </div>
            <div>
                <div className="px-4 mb-3 text-[10px] font-extrabold text-slate-400 uppercase tracking-widest flex items-center gap-2"><span className="w-1.5 h-1.5 bg-violet-400 rounded-full"></span> Akademik</div>
                <div className="space-y-1">
                    <NavItem to="/guru/permintaan" icon={ClipboardCheck} label="Permintaan Bimbel" active={isActive('/guru/permintaan')} attention={hasSidebarAttention("teacher", "/guru/permintaan", attentionNotifications)} />
                    <NavItem to="/guru/kelas" icon={BookOpen} label="Kelas Saya" active={isActive('/guru/kelas')} attention={hasSidebarAttention("teacher", "/guru/kelas", attentionNotifications)} />
                    <NavItem to="/guru/pesan" icon={MessageSquare} label="Pesan" active={isActive('/guru/pesan')} attention={hasSidebarAttention("teacher", "/guru/pesan", attentionNotifications)} />
                    <NavItem to="/guru/jadwal" icon={CalendarClock} label="Jadwal Mengajar" active={isActive('/guru/jadwal')} attention={hasSidebarAttention("teacher", "/guru/jadwal", attentionNotifications)} />
                </div>
            </div>
            <div>
                <div className="px-4 mb-3 text-[10px] font-extrabold text-slate-400 uppercase tracking-widest flex items-center gap-2"><span className="w-1.5 h-1.5 bg-emerald-400 rounded-full"></span> Keuangan</div>
                <div className="space-y-1">
                    <NavItem to="/guru/dompet" icon={Wallet} label="Dompet Tutor" active={isActive('/guru/dompet')} attention={hasSidebarAttention("teacher", "/guru/dompet", attentionNotifications)} />
                </div>
            </div>
            <div>
                <div className="px-4 mb-3 text-[10px] font-extrabold text-slate-400 uppercase tracking-widest flex items-center gap-2"><span className="w-1.5 h-1.5 bg-slate-400 rounded-full"></span> Saya</div>
                <div className="space-y-1">
                    <NavItem to="/guru/saya" icon={GraduationCap} label="Pusat Akun" active={isActive('/guru/saya')} attention={hasSidebarAttention("teacher", "/guru/saya", attentionNotifications)} />
                    <NavItem to="/guru/performa" icon={BarChart3} label="Performa & Banding" active={isActive('/guru/performa')} attention={hasSidebarAttention("teacher", "/guru/performa", attentionNotifications)} />
                    <NavItem to="/guru/notifikasi" icon={Bell} label="Notifikasi" active={isActive('/guru/notifikasi')} attention={hasSidebarAttention("teacher", "/guru/notifikasi", attentionNotifications)} />
                    <NavItem to="/guru/profil" icon={Settings} label="Edit Profil" active={isActive('/guru/profil')} attention={hasSidebarAttention("teacher", "/guru/profil", attentionNotifications)} />
                    <NavItem to="/guru/bantuan" icon={HelpCircle} label="Pusat Bantuan" active={isActive('/guru/bantuan')} attention={hasSidebarAttention("teacher", "/guru/bantuan", attentionNotifications)} />
                </div>
            </div>
        </nav>

      </aside>

      {/* MAIN CONTENT */}
      <main id="main-content" tabIndex={-1} className="relative flex min-h-0 min-w-0 flex-1 flex-col overflow-x-hidden overflow-y-hidden">
        <header className="h-16 sm:h-20 bg-white/80 backdrop-blur-xl border-b border-slate-200/50 flex items-center justify-between px-3 sm:px-6 xl:px-10 sticky top-0 z-20 transition-all">
          <div className="flex min-w-0 items-center gap-2 sm:gap-4">
            <button aria-label="Buka menu" className="xl:hidden p-2.5 bg-white border border-slate-200 text-slate-500 hover:bg-slate-50 rounded-xl shadow-sm transition" onClick={() => setSidebarOpen(true)}><Menu size={20} /></button>
            <div className="min-w-0"><h1 className="max-w-[9.5rem] truncate text-base font-black tracking-tight text-slate-800 sm:max-w-none sm:text-xl">{title}</h1></div>
          </div>

          <div className="flex items-center gap-2 sm:gap-5">
             <Suspense fallback={null}>
               <RoleQuickGuide role="teacher" />
             </Suspense>

             {/* DROPDOWN NOTIF */}
             <div className="relative">
                 <button onClick={() => setShowNotifDropdown(!showNotifDropdown)} aria-label={`Notifikasi${unreadCount ? `, ${unreadCount} belum dibaca` : ""}`} aria-expanded={showNotifDropdown} className="relative p-2.5 rounded-full text-slate-400 hover:bg-white hover:text-indigo-600 hover-shadow-md transition-all duration-300 group">
                    <Bell size={20} />
                    {unreadCount > 0 && <span className="absolute top-2.5 right-3 w-2.5 h-2.5 bg-rose-500 rounded-full ring-2 ring-white animate-pulse"></span>}
                 </button>
                 {showNotifDropdown && (
                     <>
                        <button type="button" aria-label="Tutup daftar notifikasi" className="fixed inset-0 z-[var(--layer-dropdown)]" onClick={() => setShowNotifDropdown(false)} />
                        <div className="fixed left-3 right-3 top-14 z-[var(--layer-dropdown)] mt-2 max-h-[min(72dvh,32rem)] origin-top-right overflow-hidden rounded-3xl border border-slate-100 bg-white shadow-2xl animate-in fade-in zoom-in-95 duration-200 sm:absolute sm:left-auto sm:right-0 sm:top-auto sm:mt-4 sm:w-[min(24rem,calc(100vw-1.5rem))]">
                            <div className="p-4 border-b border-slate-50 bg-slate-50/50 flex justify-between items-center"><h3 className="font-bold text-slate-800">Notifikasi</h3>{unreadCount > 0 && <span className="text-[10px] bg-rose-100 text-rose-600 px-2 py-0.5 rounded-full font-bold">{unreadCount} Baru</span>}</div>
                            <div className="max-h-[400px] overflow-y-auto custom-scrollbar p-2 space-y-1">
                                {notifications.length > 0 ? notifications.map((notif) => (
                                    <button type="button" key={notif.id} onClick={() => handleNotifClick(notif)} className={`flex w-full items-start gap-4 rounded-2xl p-4 text-left hover:bg-indigo-50/50 transition ${notif.is_read ? 'opacity-60 bg-white' : 'bg-indigo-50/30 border border-indigo-100'}`}>
                                        <div className={`w-2 h-2 mt-2 rounded-full shrink-0 ${notif.is_read ? 'bg-slate-300' : 'bg-indigo-500'}`}></div>
                                        <div className="min-w-0 flex-1"><h4 className={`break-words text-sm ${notif.is_read ? 'font-medium text-slate-600' : 'font-bold text-slate-800'}`}>{notif.title}</h4><p className="mt-1 line-clamp-2 break-words text-xs leading-relaxed text-slate-500">{notif.message}</p><p className="text-[10px] text-slate-400 mt-2">{new Date(notif.created_at).toLocaleString('id-ID')}</p></div>
                                    </button>
                                )) : <div className="p-8 text-center text-slate-400 text-sm">Belum ada notifikasi.</div>}
                            </div>
                            <div className="border-t border-slate-100 p-2"><Link to="/guru/notifikasi" onClick={() => setShowNotifDropdown(false)} className="block rounded-xl px-4 py-3 text-center text-xs font-black text-indigo-700 hover:bg-indigo-50">Buka semua notifikasi</Link></div>
                        </div>
                     </>
                 )}
             </div>

              <ProfileQuickMenu
               user={userData}
               accent="teacher"
               roleLabel="Tutor"
               profileTo="/guru/profil"
               accountTo="/guru/saya"
               helpTo="/guru/bantuan"
             />
          </div>
        </header>

        <div className={`mobile-app-content min-h-0 flex-1 basis-0 overflow-x-hidden scroll-smooth p-4 pb-[calc(7.25rem+env(safe-area-inset-bottom))] sm:p-6 sm:pb-24 xl:p-10 xl:pb-10 ${lockContentScroll ? "overflow-y-hidden" : "overflow-y-auto"}`}>
          <div className={`mx-auto w-full min-w-0 max-w-7xl ${lockContentScroll ? "flex h-full min-h-0 flex-col" : ""}`}>{children}</div>
        </div>
        {!isDesktop && !sidebarOpen && (
          <Suspense fallback={null}>
            <MobileBottomNav role="teacher" attentionNotifications={attentionNotifications} />
          </Suspense>
        )}

        {/* --- [FIXED] MODAL DETAIL NOTIFIKASI GURU --- */}
        {selectedNotif && (
            <div role="dialog" aria-modal="true" aria-label="Detail notifikasi" className="fixed inset-0 z-[var(--layer-modal)] flex items-end justify-center bg-slate-900/45 p-3 pb-[max(env(safe-area-inset-bottom),0.75rem)] animate-in fade-in duration-300 sm:items-center sm:p-4">
                {/* [PERBAIKAN] 
                    1. flex flex-col: Agar children (header, content, footer) tertata vertikal
                    2. max-h-[90dvh]: Batasi tinggi modal agar tidak melebihi layar
                */}
                <div className="relative flex max-h-[calc(100dvh-1.5rem)] w-full min-w-0 max-w-lg flex-col overflow-hidden rounded-[1.75rem] bg-white shadow-2xl animate-in slide-in-from-bottom-4 duration-300 sm:max-h-[90dvh] sm:rounded-[2.5rem] sm:zoom-in-95">
                    <div className="absolute top-0 right-0 w-32 h-32 bg-indigo-100/50 rounded-full blur-3xl -mr-10 -mt-10 pointer-events-none"></div>

                    {/* Header: Fixed (Shrink-0) */}
                    <div className="relative z-10 p-5 sm:p-8 pb-4 shrink-0 bg-white">
                        <div className="flex justify-between items-start mb-6">
                            <div className="w-14 h-14 bg-indigo-50 rounded-2xl flex items-center justify-center text-indigo-600 shadow-sm border border-indigo-100">
                                <MessageSquare size={28}/>
                            </div>
                            <button aria-label="Tutup notifikasi" onClick={() => setSelectedNotif(null)} className="p-2 bg-slate-50 text-slate-400 rounded-full hover:bg-rose-50 hover:text-rose-500 transition"><X size={20}/></button>
                        </div>

                        <div>
                            <h3 className="min-w-0 break-words text-xl font-black leading-tight text-slate-900 sm:text-2xl">{selectedNotif.title}</h3>
                            <div className="mt-2 flex min-w-0 flex-wrap items-center gap-2 text-[10px] font-bold uppercase tracking-wide text-slate-400 sm:text-xs sm:tracking-wider">
                                <span>Dari Admin</span><span>•</span><span>{new Date(selectedNotif.created_at).toLocaleString('id-ID', { dateStyle: 'full', timeStyle: 'short' })}</span>
                            </div>
                        </div>
                    </div>

                    {/* Content: Scrollable (Overflow-y-auto) */}
                    <div className="relative z-10 px-5 sm:px-8 overflow-y-auto custom-scrollbar">
                        <div className="min-w-0 break-words rounded-2xl border border-slate-100 bg-slate-50 p-4 text-sm leading-relaxed text-slate-600 whitespace-pre-wrap sm:rounded-3xl sm:p-6">
                            {selectedNotif.message}
                        </div>
                    </div>

                    {/* Footer: Fixed (Shrink-0) */}
                    <div className="relative z-10 p-5 sm:p-8 pt-6 shrink-0 bg-white">
                        <button onClick={() => setSelectedNotif(null)} className="w-full py-3 bg-indigo-600 text-white rounded-xl font-bold shadow-lg hover:bg-indigo-700 transition-all">Tutup Pesan</button>
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

function NavItem({ to, icon: Icon, label, active, attention = false }: any) {
  return (
    <Link
      to={to}
      aria-current={active ? "page" : undefined}
      className={`group relative flex items-center justify-between px-4 py-3.5 text-sm font-bold rounded-2xl transition-all duration-300 mb-1 ${
        active 
          ? 'bg-indigo-600 text-white shadow-xl shadow-indigo-600/20 translate-x-1' 
          : 'text-slate-500 hover:bg-slate-50 hover:text-indigo-600'
      }`}
    >
      <div className="flex items-center gap-3.5">
        <span className="relative shrink-0">
          <Icon 
              size={20} 
              className={`${active ? 'text-indigo-100' : 'text-slate-400 group-hover:text-indigo-500'} transition-colors duration-300`} 
              strokeWidth={active ? 2.5 : 2}
          />
          {attention && <span aria-label="Ada pembaruan yang belum dilihat" className="absolute -right-1.5 -top-1.5 h-2.5 w-2.5 rounded-full bg-rose-500 ring-2 ring-white" />}
        </span>
        <span>{label}</span>
      </div>
      {active && <div className="w-1.5 h-1.5 rounded-full bg-white shadow-sm animate-pulse"></div>}
    </Link>
  );
}
