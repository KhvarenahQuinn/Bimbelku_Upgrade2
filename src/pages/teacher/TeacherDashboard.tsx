import { notify } from "@/lib/notify";
import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { AlertCircle, ArrowRight, Banknote, Bell, BookOpen, CalendarClock, ClipboardCheck, Loader2, MapPin, MessageSquare, Monitor, RefreshCw, ShieldCheck, Star, Users } from "lucide-react";
import DynamicBannerCarousel from "@/components/DynamicBannerCarousel";
import TeacherLayout from "@/components/TeacherLayout";
import { Button } from "@/components/ui/button";
import { getApiError, getCached } from "@/lib/http";

type DashboardData = {
  teacher: { name: string; is_accepting_requests: boolean; suspended_until?: string | null };
  priorities: { pending_offers: number; unread_messages: number; unread_notifications: number; schedule_responses: number };
  classes: { active: number; in_progress: number; awaiting_student: number; student_count: number; session_progress?: { total: number; completed: number; remaining: number }; next?: { id: number; subject: string; chapter?: string | null; start_at: string; end_at: string; learning_mode: string; status: string } | null };
  earnings: { held: number; available: number; requested: number };
  rating: { average: number; count: number };
};

const normalizeDashboard = (raw: Partial<DashboardData> | null | undefined): DashboardData => ({
  teacher: {
    name: typeof raw?.teacher?.name === "string" && raw.teacher.name.trim() ? raw.teacher.name : "Tutor",
    is_accepting_requests: Boolean(raw?.teacher?.is_accepting_requests),
    suspended_until: raw?.teacher?.suspended_until || null,
  },
  priorities: {
    pending_offers: Number(raw?.priorities?.pending_offers) || 0,
    unread_messages: Number(raw?.priorities?.unread_messages) || 0,
    unread_notifications: Number(raw?.priorities?.unread_notifications) || 0,
    schedule_responses: Number(raw?.priorities?.schedule_responses) || 0,
  },
  classes: {
    active: Number(raw?.classes?.active) || 0,
    in_progress: Number(raw?.classes?.in_progress) || 0,
    awaiting_student: Number(raw?.classes?.awaiting_student) || 0,
    student_count: Number(raw?.classes?.student_count) || 0,
    session_progress: {
      total: Number(raw?.classes?.session_progress?.total) || 0,
      completed: Number(raw?.classes?.session_progress?.completed) || 0,
      remaining: Number(raw?.classes?.session_progress?.remaining) || 0,
    },
    next: raw?.classes?.next || null,
  },
  earnings: {
    held: Number(raw?.earnings?.held) || 0,
    available: Number(raw?.earnings?.available) || 0,
    requested: Number(raw?.earnings?.requested) || 0,
  },
  rating: {
    average: Number(raw?.rating?.average) || 0,
    count: Number(raw?.rating?.count) || 0,
  },
});

const rupiah = (value: number) => new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(value || 0);
const dateTime = (value?: string | null) => value ? new Intl.DateTimeFormat("id-ID", { weekday: "long", day: "numeric", month: "long", hour: "2-digit", minute: "2-digit" }).format(new Date(value)) : "-";

export default function TeacherDashboard() {
  const [data, setData] = useState<DashboardData | null>(null);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const load = useCallback(async (force = false) => {
    setLoading(true); setFailed(false);
    try {
      const response = await getCached<DashboardData>("/teacher/dashboard-v2", { maxAgeMs: 10_000, force });
      setData(normalizeDashboard(response.data));
    }
    catch (error) { setFailed(true); notify.error(getApiError(error, "Dashboard tutor gagal dimuat.")); }
    finally { setLoading(false); }
  }, []);
  useEffect(() => { void load(); }, [load]);

  const priorityItems = data ? [
    { label: "Permintaan menunggu", count: data.priorities.pending_offers, to: "/guru/permintaan", icon: ClipboardCheck, color: "bg-amber-50 text-amber-700" },
    { label: "Pesan belum dibaca", count: data.priorities.unread_messages, to: "/guru/pesan", icon: MessageSquare, color: "bg-indigo-50 text-indigo-700" },
    { label: "Persetujuan jadwal", count: data.priorities.schedule_responses, to: "/guru/kelas", icon: CalendarClock, color: "bg-violet-50 text-violet-700" },
    { label: "Notifikasi baru", count: data.priorities.unread_notifications, to: "/guru/notifikasi", icon: Bell, color: "bg-rose-50 text-rose-700" },
  ].filter((item) => item.count > 0) : [];

  return <TeacherLayout title="Beranda Tutor"><div className="space-y-5 pb-10 sm:space-y-7">
    <section className="relative overflow-hidden rounded-[1.7rem] bg-gradient-to-br from-slate-950 via-indigo-950 to-violet-900 p-5 text-white shadow-xl sm:rounded-[2rem] sm:p-8">
      <div className="absolute -right-16 -top-16 h-56 w-56 rounded-full bg-indigo-400/20 blur-3xl" />
      <div className="relative flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between"><div><span className="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1.5 text-[10px] font-black uppercase tracking-wider text-indigo-100"><ShieldCheck size={13} />{data?.rating.count ? data.rating.average.toFixed(1) + " ★ · " + data.rating.count + " ulasan" : "Belum ada rating"} · {data?.teacher.is_accepting_requests ? "menerima permintaan" : "permintaan dijeda"}</span><h1 className="mt-4 text-2xl font-black sm:text-4xl">Halo, {data?.teacher.name?.split(" ")[0] || "Tutor"}</h1><p className="mt-2 max-w-xl text-sm leading-6 text-indigo-100/75">Selesaikan pekerjaan mendesak lebih dahulu, lalu lanjutkan sesi mengajar dan pencairan.</p></div><Button onClick={() => void load(true)} variant="outline" className="h-11 rounded-xl border-white/20 bg-white/10 text-white hover:bg-white/20 hover:text-white"><RefreshCw size={16} className="mr-2" />Muat ulang</Button></div>
    </section>

    <section aria-label="Menu cepat tutor" className="grid grid-cols-4 gap-2 rounded-[1.5rem] border border-slate-100 bg-white p-3 shadow-sm xl:hidden">
      {[
        { label: "Permintaan", to: "/guru/permintaan", icon: ClipboardCheck, tone: "bg-amber-50 text-amber-700" },
        { label: "Kelas", to: "/guru/kelas", icon: BookOpen, tone: "bg-indigo-50 text-indigo-700" },
        { label: "Jadwal", to: "/guru/jadwal", icon: CalendarClock, tone: "bg-violet-50 text-violet-700" },
        { label: "Pesan", to: "/guru/pesan", icon: MessageSquare, tone: "bg-sky-50 text-sky-700" },
        { label: "Gaji", to: "/guru/gaji", icon: Banknote, tone: "bg-emerald-50 text-emerald-700" },
        { label: "Rekening", to: "/guru/rekening", icon: ShieldCheck, tone: "bg-teal-50 text-teal-700" },
        { label: "Performa", to: "/guru/performa", icon: Star, tone: "bg-orange-50 text-orange-700" },
        { label: "Akun", to: "/guru/saya", icon: Users, tone: "bg-slate-100 text-slate-700" },
      ].map(({ label, to, icon: Icon, tone }) => <Link key={to} to={to} className="group flex min-w-0 flex-col items-center gap-2 rounded-2xl px-1 py-2 text-center transition active:scale-95"><span className={`grid h-11 w-11 place-items-center rounded-2xl ${tone}`}><Icon size={19} /></span><span className="w-full truncate text-[10px] font-black leading-4 text-slate-700">{label}</span></Link>)}
    </section>

    <DynamicBannerCarousel audience="teacher" />

    {loading ? <div className="grid min-h-72 place-items-center rounded-[2rem] bg-white"><Loader2 className="animate-spin text-indigo-600" size={30} /></div> : failed || !data ? <div className="rounded-[2rem] border border-rose-100 bg-white p-12 text-center"><AlertCircle className="mx-auto text-rose-400" /><p className="mt-3 font-black">Dashboard belum dapat dimuat</p><Button onClick={() => void load(true)} className="mt-4 rounded-xl bg-indigo-600">Coba lagi</Button></div> : <>
      <section><div className="mb-3 flex items-center justify-between"><div><h2 className="text-lg font-black text-slate-900">Perlu dikerjakan</h2><p className="mt-1 text-xs text-slate-500">Diurutkan berdasarkan pekerjaan yang menunggu tindakanmu.</p></div></div>{priorityItems.length ? <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">{priorityItems.map(({ label, count, to, icon: Icon, color }) => <Link key={label} to={to} className="group flex items-center gap-3 rounded-[1.4rem] border border-slate-100 bg-white p-4 shadow-sm transition hover-rise-half hover-shadow-lg"><span className={`grid h-11 w-11 shrink-0 place-items-center rounded-2xl ${color}`}><Icon size={18} /></span><span className="min-w-0 flex-1"><span className="block text-2xl font-black text-slate-900">{count}</span><span className="block text-xs font-bold text-slate-500">{label}</span></span><ArrowRight className="shrink-0 text-slate-300 group-hover:text-indigo-500" size={17} /></Link>)}</div> : <div className="rounded-[1.4rem] border border-emerald-100 bg-emerald-50 p-5 text-sm font-bold text-emerald-800"><ShieldCheck className="mr-2 inline" size={17} />Tidak ada pekerjaan mendesak saat ini.</div>}</section>

      <section className="grid grid-cols-2 gap-3 lg:grid-cols-5"><Metric icon={BookOpen} label="Kelas aktif" value={String(data.classes.active)} /><Metric icon={Users} label="Murid aktif" value={String(data.classes.student_count)} /><Metric icon={ClipboardCheck} label="Sesi selesai" value={`${data.classes.session_progress?.completed ?? 0}/${data.classes.session_progress?.total ?? 0}`} /><Metric icon={Star} label="Penilaian" value={data.rating.count ? `${data.rating.average.toFixed(1)} / 5` : "-"} /><Metric icon={Banknote} label="Siap dicairkan" value={rupiah(data.earnings.available)} /></section>

      <section className="grid gap-4 lg:grid-cols-[1.35fr_.65fr]">
        <article className="rounded-[1.7rem] border border-slate-100 bg-white p-5 shadow-sm sm:rounded-[2rem] sm:p-6"><div className="flex items-start justify-between gap-3"><div><p className="text-xs font-black uppercase tracking-wider text-indigo-500">Sesi berikutnya</p><h2 className="mt-2 text-xl font-black text-slate-900">{data.classes.next?.subject || "Belum ada jadwal"}</h2></div>{data.classes.next && <span className={`grid h-11 w-11 shrink-0 place-items-center rounded-2xl ${data.classes.next.learning_mode === "online" ? "bg-indigo-50 text-indigo-600" : "bg-emerald-50 text-emerald-600"}`}>{data.classes.next.learning_mode === "online" ? <Monitor size={19} /> : <MapPin size={19} />}</span>}</div>{data.classes.next ? <><p className="mt-2 text-sm text-slate-500">{data.classes.next.chapter || "Materi sesuai permintaan murid"}</p><div className="mt-4 rounded-2xl bg-slate-50 p-4 text-sm font-bold text-slate-700"><CalendarClock className="mr-2 inline text-indigo-600" size={17} />{dateTime(data.classes.next.start_at)} WIB</div><Button asChild className="mt-4 h-11 w-full rounded-xl bg-indigo-600"><Link to="/guru/kelas">Buka kelas <ArrowRight className="ml-2" size={16} /></Link></Button></> : <p className="mt-3 text-sm leading-6 text-slate-500">Permintaan yang diterima dan kelas berbayar akan muncul di sini.</p>}</article>
        <article className="rounded-[1.7rem] border border-slate-100 bg-white p-5 shadow-sm sm:rounded-[2rem] sm:p-6"><p className="text-xs font-black uppercase tracking-wider text-emerald-600">Ringkasan saldo</p><Money label="Ditahan" value={data.earnings.held} /><Money label="Tersedia" value={data.earnings.available} /><Money label="Diajukan" value={data.earnings.requested} /><Button asChild variant="outline" className="mt-4 h-11 w-full rounded-xl border-emerald-200 text-emerald-700"><Link to="/guru/gaji">Kelola pencairan</Link></Button></article>
      </section>
    </>}
  </div></TeacherLayout>;
}

function Metric({ icon: Icon, label, value }: { icon: typeof BookOpen; label: string; value: string }) { return <div className="min-w-0 rounded-[1.3rem] border border-slate-100 bg-white p-4 shadow-sm"><Icon className="text-indigo-600" size={18} /><p className="mt-3 truncate text-[10px] font-black uppercase tracking-wider text-slate-400">{label}</p><p className="mt-1 truncate text-lg font-black text-slate-900 sm:text-xl">{value}</p></div>; }
function Money({ label, value }: { label: string; value: number }) { return <div className="mt-3 flex items-center justify-between gap-3 rounded-xl bg-slate-50 px-3 py-3 text-sm"><span className="font-bold text-slate-500">{label}</span><span className="text-right font-black text-slate-900">{rupiah(value)}</span></div>; }
