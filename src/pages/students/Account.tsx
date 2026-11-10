import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import {
  BadgePercent,
  Bell,
  ChevronRight,
  CircleHelp,
  FileText,
  MessageSquareText,
  ShieldCheck,
  UserRound,
} from "lucide-react";

import StudentLayout from "@/components/StudentLayout";
import LogoutButton from "@/components/LogoutButton";
import { getCached } from "@/lib/http";
import { NAVIGATION_ATTENTION_CHANGED_EVENT, pageGroupForPath, type AttentionNotification } from "@/lib/navigationAttention";

type Student = {
  name?: string;
  email?: string;
  phone?: string;
  avatar_url?: string | null;
  avatar?: string | null;
  student_education_level?: string | null;
  grade?: string | null;
  school_name?: string | null;
  address?: string | null;
};

type Shortcut = {
  label: string;
  description: string;
  to?: string;
  icon: typeof UserRound;
  action?: () => void;
  state?: { from: string };
  attention?: boolean;
  attentionCount?: number;
};

const Group = ({ title, items }: { title: string; items: Shortcut[] }) => (
  <section className="overflow-hidden rounded-[1.75rem] border border-slate-100 bg-white shadow-sm">
    <h2 className="border-b border-slate-100 px-5 py-4 text-sm font-black text-slate-900 sm:px-6">{title}</h2>
    <div className="divide-y divide-slate-100">
      {items.map(({ label, description, to, icon: Icon, action, state, attention, attentionCount }) => {
        const content = (
          <>
            <span className="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-blue-50 text-blue-600"><Icon size={20} /></span>
            <span className="min-w-0 flex-1 text-left">
              <span className="flex flex-wrap items-center gap-2 text-sm font-black text-slate-800">{label}{attention && <span className="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-black text-rose-600 ring-1 ring-rose-100"><span className="h-1.5 w-1.5 rounded-full bg-rose-500" />{attentionCount && attentionCount > 1 ? `${attentionCount} baru` : "Baru"}</span>}</span>
              <span className="mt-0.5 block text-xs font-medium leading-5 text-slate-500">{description}</span>
            </span>
            <ChevronRight size={18} className="shrink-0 text-slate-300" />
          </>
        );
        const className = "flex min-h-16 w-full items-center gap-3 px-5 py-3 transition hover:bg-slate-50 sm:px-6";
        return to ? <Link key={label} to={to} state={state} className={className}>{content}</Link> : <button key={label} type="button" onClick={action} className={className}>{content}</button>;
      })}
    </div>
  </section>
);

export default function Account() {
  const [student, setStudent] = useState<Student | null>(null);
  const [loading, setLoading] = useState(true);
  const [attentionNotifications, setAttentionNotifications] = useState<AttentionNotification[]>([]);
  const [unreadCount, setUnreadCount] = useState(0);

  useEffect(() => {
    void getCached<Student>("/user", { maxAgeMs: 60_000 })
      .then((response) => setStudent(response.data))
      .finally(() => setLoading(false));

    const loadAttention = (force = false) => {
      void getCached<{ attention_notifications?: AttentionNotification[]; unread_count?: number }>("/notifications", { maxAgeMs: 15_000, force })
        .then((response) => {
          setAttentionNotifications(Array.isArray(response.data.attention_notifications) ? response.data.attention_notifications : []);
          setUnreadCount(Number(response.data.unread_count || 0));
        })
        .catch(() => {
          if (!force) {
            setAttentionNotifications([]);
            setUnreadCount(0);
          }
        });
    };

    loadAttention(false);
    const sync = () => loadAttention(true);
    window.addEventListener(NAVIGATION_ATTENTION_CHANGED_EVENT, sync);
    return () => window.removeEventListener(NAVIGATION_ATTENTION_CHANGED_EVENT, sync);
  }, []);

  const openTutorial = () => window.dispatchEvent(new Event("bimbelku:open-tutorial"));

  const attentionCountFor = (...groupKeys: string[]) => attentionNotifications.filter((notification) => {
    if (notification.is_read) return false;
    if (!notification.target_url) return false;
    const group = pageGroupForPath("student", notification.target_url);
    return Boolean(group && groupKeys.includes(group.key));
  }).length;

  const voucherAttention = attentionCountFor("student-vouchers");
  const profileAttention = attentionCountFor("student-profile");

  return (
    <StudentLayout title="Saya">
      <div className="mx-auto max-w-5xl space-y-5">
        <section data-tour="student-account-card" className="overflow-hidden rounded-[1.75rem] border border-orange-100 bg-gradient-to-br from-[#FFF7ED] via-white to-indigo-50 p-5 shadow-sm sm:rounded-[2rem] sm:p-8">
          {loading ? (
            <div className="h-24 animate-pulse rounded-3xl bg-white/10" role="status" aria-label="Memuat profil" />
          ) : (
            <>
              <div className="flex items-center gap-4 sm:gap-5">
                <div className="grid h-16 w-16 shrink-0 place-items-center overflow-hidden rounded-2xl bg-[#14213D] text-xl font-black text-white ring-4 ring-orange-100 sm:h-20 sm:w-20 sm:rounded-3xl sm:text-2xl">
                  {student?.avatar_url || student?.avatar ? <img src={student.avatar_url || student.avatar || ""} alt="Foto profil" loading="lazy" decoding="async" className="h-full w-full object-cover" /> : student?.name?.charAt(0) || "M"}
                </div>
                <div className="min-w-0 flex-1">
                  <p className="text-xs font-black uppercase tracking-[0.18em] text-orange-700">Akun murid</p>
                  <h1 className="mt-1 truncate text-xl font-black text-slate-950 sm:text-2xl">{student?.name || "Murid BimbelKu"}</h1>
                  <p className="mt-1 text-sm font-medium text-slate-600">
                    {[student?.student_education_level, student?.grade, student?.school_name].filter(Boolean).join(" • ") || student?.email || "Lengkapi profil belajar agar pesanan lebih cepat dibuat."}
                  </p>
                </div>
                <Link to="/student/profile" className="hidden min-h-11 shrink-0 items-center rounded-xl bg-[#14213D] px-4 text-sm font-black text-white sm:inline-flex">Edit profil</Link>
              </div>
              <dl className="mt-6 grid gap-2 border-t border-slate-200/80 pt-5 sm:grid-cols-3"><div><dt className="text-[10px] font-black uppercase tracking-wider text-slate-400">Email akun</dt><dd className="mt-1 truncate text-sm font-bold text-slate-800">{student?.email || "Belum tersedia"}</dd></div><div><dt className="text-[10px] font-black uppercase tracking-wider text-slate-400">Nomor aktif</dt><dd className="mt-1 truncate text-sm font-bold text-slate-800">{student?.phone || "Belum dilengkapi"}</dd></div><div><dt className="text-[10px] font-black uppercase tracking-wider text-slate-400">Lokasi belajar</dt><dd className="mt-1 truncate text-sm font-bold text-slate-800">{student?.address || "Diatur saat dibutuhkan"}</dd></div></dl>
            </>
          )}
        </section>

        <div className="grid gap-5 lg:grid-cols-2">
          <Group title="Voucher dan penawaran" items={[
            { label: "Voucher Saya", description: "Lihat voucher yang sudah diklaim dan masa berlakunya.", to: "/student/vouchers", icon: BadgePercent, attention: voucherAttention > 0, attentionCount: voucherAttention },
          ]} />
          <Group title="Pengaturan akun" items={[
            { label: "Keamanan dan PIN", description: "Atur PIN pembayaran, kata sandi, dan lihat saldo BimbelKu.", to: "/student/settings", icon: ShieldCheck },
            { label: "Profil dan lokasi", description: "Atur identitas, pendidikan, alamat, dan lokasi belajar dalam satu halaman.", to: "/student/profile", icon: UserRound, attention: profileAttention > 0, attentionCount: profileAttention },
            { label: "Notifikasi", description: unreadCount > 0 ? `${unreadCount} notifikasi belum dibaca. Buka untuk melihat isi dan tujuan yang jelas.` : "Lihat seluruh pemberitahuan akun di satu tempat.", to: "/student/notifications", icon: Bell, attention: unreadCount > 0, attentionCount: unreadCount },
            { label: "Privasi", description: "Baca kebijakan penggunaan dan perlindungan data.", to: "/privacy", state: { from: "/student/account" }, icon: ShieldCheck },
          ]} />
          <Group title="Bantuan dan informasi" items={[
            { label: "Tutorial penggunaan", description: "Tampilkan kembali panduan dengan tombol target yang tetap terang.", icon: CircleHelp, action: openTutorial },
            { label: "Pusat Bantuan", description: "Hubungi tim BimbelKu melalui WhatsApp.", to: "/student/help", icon: MessageSquareText },
            { label: "Syarat dan ketentuan", description: "Baca aturan layanan BimbelKu.", to: "/terms", state: { from: "/student/account" }, icon: FileText },
          ]} />
          <LogoutButton accent="student" className="lg:col-start-2" />
        </div>
      </div>
    </StudentLayout>
  );
}
