import { useEffect, useId, useRef, useState } from "react";
import { Link, useLocation } from "react-router-dom";
import { ChevronDown, CircleHelp, LogOut, Settings, UserRound } from "lucide-react";
import { useLogout } from "@/hooks/useLogout";

type Accent = "student" | "teacher" | "admin";

const styles: Record<Accent, { ring: string; badge: string; icon: string }> = {
  student: {
    ring: "from-slate-300 to-slate-500 shadow-slate-500/20",
    badge: "bg-slate-100 text-slate-700",
    icon: "bg-slate-100 text-slate-700",
  },
  teacher: {
    ring: "from-indigo-500 to-violet-600 shadow-indigo-500/20",
    badge: "bg-indigo-50 text-indigo-700",
    icon: "bg-indigo-50 text-indigo-700",
  },
  admin: {
    ring: "from-orange-500 to-rose-600 shadow-orange-500/20",
    badge: "bg-orange-50 text-orange-700",
    icon: "bg-orange-50 text-orange-700",
  },
};

export default function ProfileQuickMenu({
  user,
  accent,
  roleLabel,
  profileTo,
  accountTo,
  helpTo,
}: {
  user: any;
  accent: Accent;
  roleLabel: string;
  profileTo: string;
  accountTo?: string;
  helpTo?: string;
}) {
  const [open, setOpen] = useState(false);
  const menuId = useId();
  const triggerRef = useRef<HTMLButtonElement | null>(null);
  const location = useLocation();
  const logout = useLogout();
  const tone = styles[accent];
  const image = user?.avatar_url || user?.avatar || user?.photo || user?.photo_url;
  const initial = String(user?.name || roleLabel || "U").trim().charAt(0).toUpperCase();

  const handleLogout = () => {
    setOpen(false);
    void logout();
  };

  useEffect(() => setOpen(false), [location.pathname, location.search]);

  useEffect(() => {
    if (!open) return;
    const close = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        setOpen(false);
        triggerRef.current?.focus();
      }
    };
    const isMobile = !window.matchMedia("(min-width: 1024px)").matches;
    const previousOverflow = document.body.style.overflow;
    if (isMobile) document.body.style.overflow = "hidden";
    window.addEventListener("keydown", close);
    return () => {
      if (isMobile) document.body.style.overflow = previousOverflow;
      window.removeEventListener("keydown", close);
    };
  }, [open]);

  return (
    <div className="relative z-[120] border-l border-slate-200 pl-1.5 sm:pl-4">
      <button
        ref={triggerRef}
        type="button"
        onClick={() => setOpen((value) => !value)}
        aria-expanded={open}
        aria-controls={menuId}
        aria-label="Buka menu profil"
        className="group flex min-h-11 min-w-11 items-center justify-end gap-2 rounded-2xl px-1.5 py-1 transition hover:bg-slate-50 sm:gap-3 sm:px-2"
      >
        <div className="hidden min-w-0 text-right md:block">
          <p className="max-w-[15rem] break-words whitespace-normal text-sm font-black leading-tight text-slate-800">{user?.name || "Memuat..."}</p>
          <span className={`mt-1 inline-flex rounded-full px-2 py-0.5 text-[9px] font-black uppercase tracking-wider ${tone.badge}`}>{roleLabel}</span>
        </div>
        <div className={`h-10 w-10 shrink-0 rounded-full bg-gradient-to-tr p-[2px] shadow-lg ${tone.ring}`}>
          <div className="flex h-full w-full items-center justify-center overflow-hidden rounded-full bg-white">
            {image ? (
              <img src={image} alt={`Foto profil ${user?.name || roleLabel}`} loading="lazy" decoding="async" className="h-full w-full object-cover" />
            ) : (
              <span className="text-sm font-black text-slate-700">{initial}</span>
            )}
          </div>
        </div>
        <ChevronDown size={15} className={`hidden text-slate-400 transition sm:block ${open ? "rotate-180" : ""}`} />
      </button>

      {open && (
        <>
          <button
            type="button"
            aria-label="Tutup menu profil"
            className="fixed inset-0 z-[9998] bg-transparent"
            onClick={() => setOpen(false)}
          />
          <div
            id={menuId}
            aria-label="Menu akun"
            className="fixed right-4 top-20 z-[9999] w-[min(18rem,calc(100vw-2rem))] max-h-[calc(100dvh-6rem)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-3 shadow-[0_18px_50px_rgba(15,23,42,0.18)] animate-in fade-in zoom-in-95 duration-150 lg:absolute lg:right-0 lg:top-full lg:mt-3 lg:w-[min(22rem,calc(100vw-2rem))] lg:max-h-none lg:overflow-hidden lg:rounded-[1.5rem] lg:border lg:p-3"
          >
            <div className="mx-auto mb-2 h-1 w-10 rounded-full bg-slate-200 lg:hidden" aria-hidden="true" />

            <div className="rounded-2xl bg-slate-950 p-4 text-white">
              <div className="flex min-w-0 items-center gap-3">
                <div className={`grid h-12 w-12 shrink-0 place-items-center overflow-hidden rounded-2xl bg-white ${tone.icon}`}>
                  {image ? <img src={image} alt="" loading="lazy" decoding="async" className="h-full w-full object-cover" /> : <span className="font-black">{initial}</span>}
                </div>
                <div className="min-w-0">
                  <p className="break-words whitespace-normal font-black leading-tight">{user?.name || "Pengguna BimbelKu"}</p>
                  <p className="mt-0.5 truncate text-xs text-slate-300">{user?.email || "Akun BimbelKu"}</p>
                  <span className="mt-2 inline-flex rounded-full bg-white/10 px-2.5 py-1 text-[9px] font-black uppercase tracking-wider text-white">{roleLabel}</span>
                </div>
              </div>
            </div>

            <div className="mt-2 space-y-1">
              <Link to={profileTo} onClick={() => setOpen(false)} className="flex min-h-12 items-center gap-3 rounded-2xl px-4 py-3 text-sm font-black text-slate-700 transition hover:bg-slate-50">
                <span className={`grid h-9 w-9 place-items-center rounded-xl ${tone.icon}`}><UserRound size={17} /></span>
                Profil Saya
              </Link>
              {accountTo && (
                <Link to={accountTo} onClick={() => setOpen(false)} className="flex min-h-12 items-center gap-3 rounded-2xl px-4 py-3 text-sm font-black text-slate-700 transition hover:bg-slate-50">
                  <span className="grid h-9 w-9 place-items-center rounded-xl bg-slate-100 text-slate-600"><Settings size={17} /></span>
                  Pengaturan
                </Link>
              )}
              {helpTo && (
                <Link to={helpTo} onClick={() => setOpen(false)} className="flex min-h-12 items-center gap-3 rounded-2xl px-4 py-3 text-sm font-black text-slate-700 transition hover:bg-slate-50">
                  <span className="grid h-9 w-9 place-items-center rounded-xl bg-emerald-50 text-emerald-700"><CircleHelp size={17} /></span>
                  Bantuan
                </Link>
              )}
            </div>

            <div className="mt-2 border-t border-slate-100 pt-2">
              <button
                type="button"
                onClick={handleLogout}
                className="flex min-h-12 w-full items-center gap-3 rounded-2xl px-4 py-3 text-left text-sm font-black text-rose-600 transition hover:bg-rose-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 focus-visible:ring-offset-2"
              >
                <span className="grid h-9 w-9 place-items-center rounded-xl bg-rose-50 text-rose-600"><LogOut size={17} /></span>
                Keluar
              </button>
            </div>

          </div>
        </>
      )}
    </div>
  );
}
