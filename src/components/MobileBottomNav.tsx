import { Link, useLocation } from "react-router-dom";
import {
  BookOpen,
  ClipboardCheck,
  Home,
  MessageSquare,
  Search,
  User,
} from "lucide-react";
import { hasMobileAttention, type AttentionNotification } from "@/lib/navigationAttention";
import { notifyActiveNavTapped } from "@/lib/navigationReset";

type Role = "student" | "teacher";
type NavItem = {
  to: string;
  label: string;
  icon: typeof Home;
  key: string;
  tour?: string;
};

const studentItems: NavItem[] = [
  { key: "home", to: "/student/dashboard", label: "Beranda", icon: Home, tour: "student-home" },
  { key: "search", to: "/student/packages/new", label: "Cari Les", icon: Search, tour: "student-cari-les" },
  { key: "classes", to: "/student/my-classes", label: "Jadwal", icon: BookOpen, tour: "student-kelas" },
  { key: "messages", to: "/student/messages", label: "Pesan", icon: MessageSquare, tour: "student-pesan" },
  { key: "account", to: "/student/account", label: "Saya", icon: User, tour: "student-saya" },
];

const teacherItems: NavItem[] = [
  { key: "home", to: "/guru", label: "Beranda", icon: Home },
  { key: "offers", to: "/guru/permintaan", label: "Permintaan", icon: ClipboardCheck },
  { key: "classes", to: "/guru/kelas", label: "Kelas", icon: BookOpen },
  { key: "messages", to: "/guru/pesan", label: "Pesan", icon: MessageSquare },
  { key: "account", to: "/guru/saya", label: "Saya", icon: User },
];

export default function MobileBottomNav({ role, attentionNotifications = [] }: { role: Role; attentionNotifications?: AttentionNotification[] }) {
  const location = useLocation();
  const items = role === "student" ? studentItems : teacherItems;
  const accent = role === "student" ? "text-blue-600" : "text-indigo-600";
  const activeBackground = role === "student" ? "bg-blue-50" : "bg-indigo-50";
  const studentActive = (() => {
    const path = location.pathname;
    if (path === "/student/dashboard") return "home";
    if (path === "/student/packages/new") return "search";
    if (path === "/student/packages" || path.startsWith("/student/packages/") || path === "/student/my-classes" || path.startsWith("/student/my-classes/") || path === "/student/progress" || path.startsWith("/student/progress/")) return "classes";
    if (path === "/student/messages" || path.startsWith("/student/messages/")) return "messages";
    if (["/student/account", "/student/profile", "/student/history", "/student/vouchers", "/student/offers", "/student/help", "/student/notifications"].some((route) => path === route || path.startsWith(`${route}/`))) return "account";
    return "";
  })();
  const teacherActive = (() => {
    const path = location.pathname;
    if (path === "/guru") return "home";
    if (path === "/guru/permintaan" || path.startsWith("/guru/permintaan/")) return "offers";
    if (path === "/guru/kelas" || path.startsWith("/guru/kelas/") || path === "/guru/jadwal") return "classes";
    if (path === "/guru/pesan" || path.startsWith("/guru/pesan/")) return "messages";
    if (["/guru/saya", "/guru/profil", "/guru/rekening", "/guru/gaji", "/guru/performa", "/guru/notifikasi", "/guru/bantuan"].some((route) => path === route || path.startsWith(`${route}/`))) return "account";
    return "";
  })();

  return (
    <nav
      aria-label={`Navigasi utama ${role === "student" ? "murid" : "tutor"}`}
      className="fixed inset-x-3 bottom-[max(env(safe-area-inset-bottom),0.5rem)] z-30 rounded-[1.4rem] border border-slate-200/80 bg-white/95 p-1.5 shadow-[0_14px_40px_rgba(15,23,42,0.18)] backdrop-blur-xl xl:hidden"
    >
      <div className="mx-auto grid max-w-lg grid-cols-5 gap-1">
        {items.map((item, index) => {
          const active = role === "student"
            ? studentActive === item.key
            : teacherActive === item.key;
          const Icon = item.icon;
          const attention = hasMobileAttention(role, item.key, attentionNotifications);

          return (
            <Link
              key={`${item.to}-${index}`}
              data-tour={item.tour}
              to={item.to}
              onClick={() => { if (active) notifyActiveNavTapped(item.to); }}
              aria-current={active ? "page" : undefined}
              aria-label={item.label}
              className={`flex min-h-[3.45rem] min-w-0 flex-col items-center justify-center gap-1 rounded-[1rem] px-1 text-[10px] font-bold transition ${
                active
                  ? `${activeBackground} ${accent}`
                  : "text-slate-500 hover:bg-slate-50 hover:text-slate-600"
              }`}
            >
              <span className="relative">
                <Icon size={19} strokeWidth={active ? 2.7 : 2} />
                {attention && <span aria-label="Ada pembaruan yang belum dilihat" className="absolute -right-1.5 -top-1.5 h-2.5 w-2.5 rounded-full bg-rose-500 ring-2 ring-white" />}
              </span>
              <span className="max-w-full truncate">{item.label}</span>
            </Link>
          );
        })}
      </div>
    </nav>
  );
}
