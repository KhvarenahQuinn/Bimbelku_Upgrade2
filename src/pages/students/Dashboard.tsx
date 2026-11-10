import { notify } from "@/lib/notify";
import { useCallback, useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import {
  AlertCircle,
  ArrowRight,
  BookOpenCheck,
  CalendarDays,
  Clock3,
  CreditCard,
  MapPin,
  MessageSquare,
  Monitor,
  Radar,
  RefreshCw,
  Tag,
  UserRound,
  Users,
  WifiOff,
  X,
} from "lucide-react";
import axios from "axios";

import DynamicBannerCarousel from "@/components/DynamicBannerCarousel";
import StudentLayout from "@/components/StudentLayout";
import { getApiError, getCached } from "@/lib/http";

type Subject = {
  id: number;
  name: string;
  status: string;
  allocated_sessions: number;
  teacher?: { name: string; avatar_url?: string | null } | null;
  sessions: Array<{ id?: number; start_at: string; status: string }>;
};
type PackageData = {
  id: number;
  status: string;
  plan: { name: string };
  total_sessions: number;
  used_sessions: number;
  remaining_sessions: number;
  duration_hours: number;
  expires_at?: string | null;
  payment_due_at?: string | null;
  subjects: Subject[];
  latest_order?: { id: number; status: string; payment_provider?: string | null } | null;
  latest_refund?: {
    id?: number;
    status: string;
    destination_method?: string | null;
    destination_selected_at?: string | null;
    stage_label?: string | null;
    status_message?: string | null;
    can_switch_to_balance?: boolean;
  } | null;
};
type DashboardData = {
  packages: PackageData[];
  voucher_count: number;
  next_session?: {
    id: number;
    subject: string;
    chapter?: string | null;
    teacher_name: string;
    learning_mode: "online" | "offline";
    status: string;
    start_at: string;
    end_at: string;
  } | null;
  unread_messages_count: number;
  recent_notifications: Array<{
    id: number;
    title: string;
    message: string;
    created_at: string;
    is_read: boolean;
  }>;
  active_disputes_count: number;
};

const activeStatuses = ["active", "payment_submitted", "awaiting_payment", "payment_rejected", "partially_paid", "matching", "teacher_pending", "no_teacher", "refund_pending"];

const DISMISSED_REFUNDS_KEY = "bimbelku_dismissed_refunds";

const loadDismissedRefunds = (): Set<number> => {
  try {
    const parsed = JSON.parse(localStorage.getItem(DISMISSED_REFUNDS_KEY) || "[]") as unknown;
    if (!Array.isArray(parsed)) return new Set();
    return new Set(parsed.filter((item): item is number => typeof item === "number"));
  } catch {
    return new Set();
  }
};

const storedStudentName = () => {
  try {
    const value = JSON.parse(localStorage.getItem("user") || "null")?.name;
    return typeof value === "string" && value.trim() ? value.trim() : "Murid";
  } catch {
    return "Murid";
  }
};

export default function Dashboard() {
  const [name, setName] = useState(storedStudentName);
  const [data, setData] = useState<DashboardData>({
    packages: [],
    voucher_count: 0,
    next_session: null,
    unread_messages_count: 0,
    recent_notifications: [],
    active_disputes_count: 0,
  });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [dismissedRefunds, setDismissedRefunds] = useState<Set<number>>(() => loadDismissedRefunds());

  const dismissRefund = (refundId: number) => {
    setDismissedRefunds((current) => {
      const next = new Set(current);
      next.add(refundId);
      try {
        localStorage.setItem(DISMISSED_REFUNDS_KEY, JSON.stringify([...next]));
      } catch {
        // Penyimpanan lokal penuh/blocked: banner tetap tersembunyi selama sesi ini.
      }
      return next;
    });
  };

  const load = useCallback(async () => {
    try {
      // Nama dapat tampil dari penyimpanan lokal. Request profil tetap diperbarui
      // di belakang layar sehingga dashboard tidak menunggu endpoint kedua.
      void getCached<{ name: string }>("/user", { maxAgeMs: 60_000 })
        .then((response) => {
          const rawName = response.data?.name;
          if (typeof rawName === "string" && rawName.trim()) setName(rawName.trim());
        })
        .catch(() => undefined);

      const dashboardResponse = await getCached<DashboardData>("/student/dashboard-v2", { maxAgeMs: 10_000 });
      const raw = dashboardResponse.data as Partial<DashboardData> | null | undefined;
      const packages = Array.isArray(raw?.packages)
        ? raw.packages.map((packageItem) => ({
            ...packageItem,
            plan: packageItem?.plan && typeof packageItem.plan.name === "string"
              ? packageItem.plan
              : { name: "Paket Belajar" },
            subjects: Array.isArray(packageItem?.subjects)
              ? packageItem.subjects.map((subject) => ({
                  ...subject,
                  sessions: Array.isArray(subject?.sessions) ? subject.sessions : [],
                }))
              : [],
          }))
        : [];
      setData({
        packages,
        voucher_count: Number(raw?.voucher_count || 0),
        next_session: raw?.next_session ?? null,
        unread_messages_count: Number(raw?.unread_messages_count || 0),
        recent_notifications: Array.isArray(raw?.recent_notifications) ? raw.recent_notifications : [],
        active_disputes_count: Number(raw?.active_disputes_count || 0),
      });
      setError(null);
    } catch (error) {
      if (axios.isAxiosError(error)) {
        if (!error.response) {
          setError("network");
        } else if (error.response.status === 401) {
          setError("unauthorized");
        } else if (error.response.status === 403) {
          setError("forbidden");
        } else if (error.response.status === 404) {
          setError("not_found");
        } else {
          setError("generic");
        }
      } else {
        setError("generic");
      }
      notify.error(getApiError(error, "Dashboard murid gagal dimuat."));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  useEffect(() => {
    const refresh = () => {
      if (document.visibilityState === "visible") void load();
    };
    window.addEventListener("focus", refresh);
    window.addEventListener("bimbelku:data-changed", refresh);
    return () => {
      window.removeEventListener("focus", refresh);
      window.removeEventListener("bimbelku:data-changed", refresh);
    };
  }, [load]);

  const retry = () => {
    setError(null);
    setLoading(true);
    void load();
  };

  const activePackage = useMemo(
    () => data.packages.find((item) => activeStatuses.includes(item.status)),
    [data.packages],
  );
  const nearestSubjectSessions = useMemo(() => {
    const now = Date.now();
    return data.packages
      .flatMap((item) => Array.isArray(item.subjects) ? item.subjects : [])
      .flatMap((subject) => (Array.isArray(subject.sessions) ? subject.sessions : [])
        .map((session) => ({ ...session, subject: subject.name })))
      .filter((session) => new Date(session.start_at).getTime() >= now)
      .sort((a, b) => new Date(a.start_at).getTime() - new Date(b.start_at).getTime())
      .slice(0, 3);
  }, [data.packages]);

  return (
    <StudentLayout title="Beranda">
      <div className="w-full min-w-0 space-y-5 overflow-hidden pb-20 sm:space-y-6">
        <div className="flex min-w-0 items-end justify-between gap-4">
          <div>
            <p className="text-xs font-black uppercase tracking-[.18em] text-indigo-600">Beranda murid</p>
            <h1 className="mt-2 text-2xl font-black text-slate-900 sm:text-3xl">Halo, {loading ? "…" : name.split(" ")[0]}!</h1>
          </div>
          <Link to="/student/packages/new" className="hidden items-center gap-2 rounded-2xl bg-indigo-600 px-4 py-2.5 text-sm font-black text-white sm:flex">
            <Radar size={17} /> Cari Les
          </Link>
        </div>

        <DynamicBannerCarousel audience="student" />

        <section aria-label="Akses cepat murid" className="rounded-[1.5rem] border border-slate-100 bg-white p-3 shadow-sm xl:hidden">
          <div className="grid grid-cols-4 gap-2">
            {[
              { label: "Pesanan", to: "/student/packages", icon: BookOpenCheck, tone: "bg-orange-50 text-orange-700" },
              { label: "Kelas Bersama", to: "/student/kelas-murah", icon: Users, tone: "bg-violet-50 text-violet-700" },
              { label: "Riwayat", to: "/student/history", icon: CreditCard, tone: "bg-rose-50 text-rose-700" },
              { label: "Voucher", to: "/student/vouchers", icon: Tag, tone: "bg-amber-50 text-amber-700" },
            ].map(({ label, to, icon: Icon, tone }) => <Link key={to} to={to} className="group flex min-w-0 flex-col items-center gap-2 rounded-2xl px-1 py-2 text-center transition active:scale-95"><span className={`grid h-12 w-12 place-items-center rounded-2xl ${tone}`}><Icon size={20} /></span><span className="line-clamp-2 w-full text-[10px] font-black leading-4 text-slate-700">{label}</span></Link>)}
          </div>
        </section>

        {loading ? (
          <DashboardSkeleton />
        ) : error ? (
          <ErrorState error={error} onRetry={retry} />
        ) : (
          <>
            <AdaptiveCard packageData={activePackage} nextSession={data.next_session} dismissedRefundIds={dismissedRefunds} onDismissRefund={dismissRefund} />

            <Link to="/student/kelas-murah" className="group flex min-w-0 items-center justify-between gap-4 overflow-hidden rounded-[1.5rem] border border-violet-100 bg-gradient-to-br from-white via-violet-50 to-fuchsia-50 p-5 shadow-sm transition hover-rise-half hover-shadow-lg sm:rounded-[2rem]">
              <div className="flex min-w-0 items-center gap-4"><span className="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-violet-600 to-fuchsia-600 text-white shadow-lg"><Users size={21} /></span><div className="min-w-0"><p className="text-[10px] font-black uppercase tracking-widest text-violet-600">Kelas online bersama</p><h2 className="mt-1 text-lg font-black text-slate-900">Kelas Bersama</h2><p className="mt-1 text-sm text-slate-600">Lihat kelas yang dibuat admin dengan kuota dan harga hemat.</p></div></div><ArrowRight className="shrink-0 text-violet-600 transition group-hover:translate-x-1" size={20} /></Link>

            {activePackage && (
              <section className="grid grid-cols-3 gap-2 sm:gap-3">
                <Summary icon={BookOpenCheck} label="Sisa sesi" value={`${activePackage.remaining_sessions}`} color="bg-indigo-600" />
                <Summary icon={UserRound} label="Mata pelajaran" value={`${activePackage.subjects.length}`} color="bg-emerald-600" />
                <Summary icon={CalendarDays} label="Masa aktif" value={activePackage.expires_at ? `${Math.max(0, Math.ceil((new Date(activePackage.expires_at).getTime() - Date.now()) / 86_400_000))} hari` : "Belum aktif"} color="bg-orange-500" />
              </section>
            )}

            {data.unread_messages_count > 0 && (
              <Link
                to="/student/messages"
                className="group flex min-w-0 items-center justify-between gap-3 overflow-hidden rounded-[1.5rem] border border-orange-100 bg-gradient-to-br from-white to-orange-50 p-4 shadow-sm transition-all hover-shadow-md sm:gap-4 sm:rounded-[2rem] sm:p-5"
              >
                <div className="flex min-w-0 flex-1 items-center gap-4">
                  <div className="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-orange-500 text-white shadow-md">
                    <MessageSquare size={21} />
                  </div>
                  <div className="min-w-0">
                    <p className="text-[10px] font-black uppercase tracking-wider text-orange-700">Pesan baru</p>
                    <p className="mt-1 break-words text-lg font-black text-slate-900 sm:text-xl">{data.unread_messages_count} pesan belum dibaca</p>
                    <p className="mt-1 text-sm text-slate-500">Buka percakapan untuk membalas.</p>
                  </div>
                </div>
                <ArrowRight size={20} className="shrink-0 text-slate-500 transition group-hover:translate-x-1" />
              </Link>
            )}

            {data.recent_notifications.length > 0 && (
              <section className="render-auto w-full min-w-0 overflow-hidden rounded-[1.5rem] border border-slate-100 bg-white p-4 shadow-sm sm:rounded-[2rem] sm:p-5">
                <div className="flex min-w-0 items-start justify-between gap-3">
                  <div className="min-w-0">
                    <h2 className="break-words text-lg font-black text-slate-900 sm:text-xl">Notifikasi Terbaru</h2>
                    <p className="mt-1 text-sm text-slate-500">3 notifikasi terakhir.</p>
                  </div>
                  <Link to="/student/notifications" className="shrink-0 text-xs font-black text-indigo-600 sm:text-sm">Semua</Link>
                </div>
                <div className="mt-4 space-y-3">
                  {data.recent_notifications.slice(0, 3).map((notif) => (
                    <div
                      key={notif.id}
                      className={`flex items-start gap-3 rounded-2xl p-3 ${notif.is_read ? 'bg-slate-50' : 'bg-blue-50 border border-blue-100'}`}
                    >
                      <div className={`w-2 h-2 mt-2 rounded-full shrink-0 ${notif.is_read ? 'bg-slate-300' : 'bg-blue-500'}`} />
                      <div className="min-w-0 flex-1">
                        <p className={`text-sm ${notif.is_read ? 'font-medium text-slate-600' : 'font-bold text-slate-900'}`}>{notif.title}</p>
                        <p className="mt-1 text-xs text-slate-500 line-clamp-1">{notif.message}</p>
                        <p className="mt-1 text-[10px] text-slate-500">
                          {new Date(notif.created_at).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' })}
                        </p>
                      </div>
                    </div>
                  ))}
                </div>
              </section>
            )}

            {data.active_disputes_count > 0 && (
              <Link
                to="/student/help"
                className="group flex min-w-0 items-center justify-between gap-3 overflow-hidden rounded-[1.5rem] border border-rose-100 bg-gradient-to-br from-white to-rose-50 p-4 shadow-sm transition-all hover-shadow-md sm:gap-4 sm:rounded-[2rem] sm:p-5"
              >
                <div className="flex min-w-0 flex-1 items-center gap-4">
                  <div className="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-rose-500 text-white shadow-md animate-pulse">
                    <AlertCircle size={21} />
                  </div>
                  <div className="min-w-0">
                    <p className="text-[10px] font-black uppercase tracking-wider text-rose-700">Memerlukan perhatian</p>
                    <p className="mt-1 text-xl font-black text-slate-900">{data.active_disputes_count} sengketa aktif</p>
                    <p className="mt-1 text-sm text-slate-500">Admin akan menyelesaikan dalam 7 hari.</p>
                  </div>
                </div>
                <ArrowRight size={20} className="shrink-0 text-slate-500 transition group-hover:translate-x-1" />
              </Link>
            )}

            {activePackage?.subjects.length ? (
              <section className="render-auto w-full min-w-0 overflow-hidden rounded-[1.5rem] border border-slate-100 bg-white p-4 shadow-sm sm:rounded-[2rem] sm:p-6">
                <div className="flex min-w-0 items-start justify-between gap-3">
                  <div className="min-w-0"><h2 className="break-words text-lg font-black text-slate-900 sm:text-xl">Mata pelajaran saya</h2><p className="mt-1 break-words text-xs leading-5 text-slate-500 sm:text-sm">Tutor dan progres dipisahkan untuk setiap mapel.</p></div>
                  <Link to="/student/packages" className="shrink-0 pt-0.5 text-xs font-black text-indigo-600 sm:text-sm">Detail</Link>
                </div>
                <div className="mt-5 grid gap-3 lg:grid-cols-2">
                  {activePackage.subjects.map((subject) => {
                    const completed = subject.sessions.filter((session) => session.status === "completed").length;
                    const progress = Math.round(completed / Math.max(1, subject.allocated_sessions) * 100);
                    return (
                      <div key={subject.id} className="w-full min-w-0 overflow-hidden rounded-2xl bg-slate-50 p-3.5 sm:rounded-3xl sm:p-4">
                        <div className="flex items-center gap-3">
                          <div className="grid h-10 w-10 shrink-0 place-items-center overflow-hidden rounded-full bg-white text-indigo-600 shadow-sm sm:h-11 sm:w-11">
                            {subject.teacher?.avatar_url ? <img src={subject.teacher.avatar_url} alt={`Foto tutor ${subject.teacher.name}`} loading="lazy" decoding="async" className="h-full w-full object-cover" /> : <UserRound size={19} />}
                          </div>
                          <div className="min-w-0 flex-1"><h3 className="truncate font-black text-slate-900">{subject.name}</h3><p className="truncate text-xs text-slate-500">{subject.teacher ? `Tutor ${subject.teacher.name}` : "Tutor sedang dicari"}</p></div>
                          <span className="shrink-0 text-xs font-black text-indigo-700 sm:text-sm">{progress}%</span>
                        </div>
                        <div className="mt-4 h-2 overflow-hidden rounded-full bg-white"><div className="h-full rounded-full bg-gradient-to-r from-indigo-600 to-cyan-500" style={{ width: `${progress}%` }} /></div>
                        <p className="mt-2 text-[11px] font-bold text-slate-500">{completed} dari {subject.allocated_sessions} sesi selesai</p>
                      </div>
                    );
                  })}
                </div>
              </section>
            ) : (
              <section className="rounded-[2rem] border border-dashed border-slate-300 bg-white p-10 text-center">
                <BookOpenCheck className="mx-auto text-slate-300" size={38} />
                <h2 className="mt-3 text-xl font-black text-slate-800">Belum ada paket aktif</h2>
                <p className="mt-2 text-sm text-slate-500">Mulai dari satu sesi atau pilih paket bulanan.</p>
                <Link to="/student/packages/new" className="mt-5 inline-flex rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-black text-white">Pilih Paket Belajar</Link>
              </section>
            )}

            <section className="render-auto grid gap-5 lg:grid-cols-[1fr_330px]">
              <div className="w-full min-w-0 overflow-hidden rounded-[1.5rem] border border-slate-100 bg-white p-4 shadow-sm sm:rounded-[2rem] sm:p-6">
                <div className="flex min-w-0 items-start justify-between gap-3">
                  <div className="min-w-0"><h2 className="break-words text-lg font-black text-slate-900 sm:text-xl">Jadwal minggu ini</h2><p className="mt-1 text-xs text-slate-500 sm:text-sm">Tiga sesi terdekat.</p></div>
                  <Link to="/student/my-classes" className="shrink-0 pt-0.5 text-xs font-black text-indigo-600 sm:text-sm">Semua</Link>
                </div>
                <div className="mt-4 space-y-3">
                  {nearestSubjectSessions.length ? nearestSubjectSessions.map((session, index) => (
                    <div key={session.id ?? `${session.subject}-${session.start_at}-${index}`} className="flex min-w-0 items-center gap-3 rounded-2xl bg-slate-50 p-4">
                      <div className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-white text-indigo-600 shadow-sm"><Clock3 size={18} /></div>
                      <div className="min-w-0 flex-1"><p className="truncate text-sm font-black text-slate-800 sm:text-base">{session.subject}</p><p className="mt-1 truncate text-[11px] text-slate-500 sm:text-xs">{new Date(session.start_at).toLocaleString("id-ID", { weekday: "long", day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" })}</p></div>
                    </div>
                  )) : <p className="rounded-2xl bg-slate-50 p-5 text-sm font-semibold text-slate-500">Belum ada jadwal aktif.</p>}
                </div>
              </div>

              <Link to="/student/vouchers" className="group relative w-full min-w-0 overflow-hidden rounded-[1.5rem] bg-gradient-to-br from-orange-500 to-rose-600 p-5 text-white shadow-lg sm:rounded-[2rem] sm:p-6">
                <div className="absolute -right-10 -top-10 h-36 w-36 rounded-full border-[24px] border-white/10" />
                <Tag className="relative" size={28} />
                <p className="relative mt-7 text-4xl font-black">{data.voucher_count}</p>
                <h2 className="relative mt-1 text-lg font-black">Voucher tersedia</h2>
                <p className="relative mt-2 text-sm text-white/90">Gunakan sebelum masa penawaran berakhir.</p>
                <span className="relative mt-5 inline-flex items-center gap-1 text-sm font-black">Lihat Voucher <ArrowRight size={16} className="transition group-hover:translate-x-1" /></span>
              </Link>
            </section>
          </>
        )}
      </div>
    </StudentLayout>
  );
}

function DashboardSkeleton() {
  return (
    <div role="status" aria-live="polite" aria-label="Memuat data dashboard" className="space-y-5">
      <section className="min-h-36 animate-pulse rounded-[2rem] border border-indigo-100 bg-gradient-to-br from-white to-indigo-50 p-5 motion-reduce:animate-none sm:p-6">
        <div className="h-4 w-28 rounded-full bg-indigo-100" />
        <div className="mt-4 h-7 w-3/4 max-w-md rounded-xl bg-slate-200" />
        <div className="mt-3 h-4 w-full max-w-xl rounded-lg bg-slate-100" />
      </section>
      <section className="grid grid-cols-3 gap-2 sm:gap-3" aria-hidden="true">
        {[0, 1, 2].map((item) => (
          <div key={item} className="min-h-24 animate-pulse rounded-2xl border border-slate-100 bg-white p-3 motion-reduce:animate-none sm:rounded-3xl sm:p-5">
            <div className="h-10 w-10 rounded-xl bg-slate-100" />
            <div className="mt-3 h-3 w-16 rounded bg-slate-100" />
          </div>
        ))}
      </section>
      <span className="sr-only">Data dashboard sedang dimuat.</span>
    </div>
  );
}

function AdaptiveCard({ packageData, nextSession, dismissedRefundIds = new Set<number>(), onDismissRefund }: { packageData?: PackageData; nextSession?: DashboardData["next_session"]; dismissedRefundIds?: Set<number>; onDismissRefund?: (refundId: number) => void }) {
  if (!packageData) {
    return <ActionCard icon={Radar} eyebrow="Mulai belajar" title="Pilih Paket Belajar" description="Tentukan jumlah sesi, mapel, dan jadwal sejak awal." to="/student/packages/new" action="Lihat Paket" />;
  }
  if (["matching", "teacher_pending"].includes(packageData.status)) {
    const accepted = packageData.subjects.filter((item) => item.teacher).length;
    const missing = packageData.subjects.filter((item) => item.status === "no_teacher").length;
    return <ActionCard icon={Radar} eyebrow="Radar aktif" title={accepted > 0 && missing > 0 ? `${accepted} tutor siap, ${missing} belum ditemukan` : "Pencarian Tutor Berlangsung"} description={`${accepted} dari ${packageData.subjects.length} tutor mapel sudah menerima. ${missing > 0 ? "Cari lagi, ubah jadwal mapel yang belum terisi, atau batalkan paket." : "Kelas dimulai setelah semua tutor lengkap."}`} to="/student/packages" action="Lihat Pilihan" pulse />;
  }
  if (packageData.status === "no_teacher") {
    const accepted = packageData.subjects.filter((item) => item.teacher).length;
    const missing = packageData.subjects.filter((item) => item.status === "no_teacher").length;
    return <ActionCard icon={Radar} eyebrow="Pencarian perlu keputusan" title={accepted > 0 && missing > 0 ? `${accepted} tutor siap, ${missing} belum ditemukan` : "Tutor Belum Tersedia"} description="Cari lagi bila masih tersedia, ubah jadwal mapel yang belum terisi, atau batalkan seluruh paket. Kelas belum aktif." to="/student/packages" action="Lihat Pilihan" />;
  }
  if (["awaiting_payment", "payment_rejected", "partially_paid"].includes(packageData.status) && packageData.latest_order) {
    return <ActionCard icon={CreditCard} eyebrow={packageData.status === "partially_paid" ? "Pembayaran masih kurang" : packageData.status === "payment_rejected" ? "Bukti perlu diperbaiki" : "Pesanan sudah diperiksa"} title={packageData.status === "partially_paid" ? "Lunasi Sisa Pembayaran" : "Selesaikan Pembayaran"} description="Pencarian tutor dimulai setelah pembayaran dinyatakan lunas oleh sistem." to="/payment" action={packageData.status === "partially_paid" ? "Bayar Kekurangan" : "Bayar Sekarang"} />;
  }
  if (packageData.status === "payment_submitted") {
    return <ActionCard icon={RefreshCw} eyebrow="Bukti sudah masuk" title="Pembayaran Diperiksa Admin" description="Pencarian tutor akan dimulai segera setelah bukti disetujui." to="/student/history" action="Lihat Status" />;
  }
  if (packageData.status === "refund_pending") {
    const refund = packageData.latest_refund;
    if (refund?.status === "paid") {
      // Banner "Refund Selesai" bisa ditutup dan tidak muncul lagi setelah dibaca.
      if (refund.id && dismissedRefundIds.has(refund.id)) return null;
      return <ActionCard icon={RefreshCw} eyebrow="Refund selesai" title="Dana Sudah Dikembalikan" description={refund.destination_method === "bimbelku_balance" ? "Refund sudah masuk ke Saldo BimbelKu dan dapat dipakai untuk belajar." : "Pengembalian dana sudah selesai. Detail tujuan dan mutasi dapat dilihat di riwayat transaksi."} to="/student/history" action="Lihat Detail" onDismiss={refund.id && onDismissRefund ? () => onDismissRefund(refund.id) : undefined} />;
    }
    if (refund?.can_switch_to_balance) {
      return <ActionCard icon={RefreshCw} eyebrow="Perlu keputusan" title="Refund ke Metode Asal Gagal" description="Pilih Saldo BimbelKu agar dana tetap dapat dipakai untuk Paket Belajar atau Kelas Bersama." to="/student/packages" action="Pilih Saldo" />;
    }
    if (refund && !refund.destination_selected_at) {
      return <ActionCard icon={RefreshCw} eyebrow="Dana aman" title="Pilih Tujuan Refund" description="Pilih Saldo BimbelKu atau metode pembayaran asal. Pembayaran via Xendit (QRIS, e-wallet, VA) dikembalikan otomatis ke metode asal." to="/student/packages" action="Pilih Tujuan" />;
    }
    if (refund?.destination_method === "xendit_original") {
      return <ActionCard icon={RefreshCw} eyebrow="Diproses otomatis" title="Refund ke Metode Pembayaran Asal" description="Dana dikembalikan otomatis melalui Xendit. Tidak perlu menunggu bukti transfer admin." to="/student/history" action="Lihat Status" />;
    }
    if (refund?.destination_method === "bimbelku_balance") {
      return <ActionCard icon={RefreshCw} eyebrow="Diproses otomatis" title="Refund ke Saldo BimbelKu" description="Dana sedang dikreditkan ke Saldo BimbelKu. Status diperbarui otomatis tanpa persetujuan admin." to="/student/history" action="Lihat Status" />;
    }
    return <ActionCard icon={RefreshCw} eyebrow="Dana aman" title="Refund Sedang Diproses" description="Status pengembalian dana diperbarui otomatis. Buka riwayat transaksi untuk detail tujuan refund." to="/student/history" action="Lihat Status" />;
  }
  if (nextSession) {
    const modeIcon = nextSession.learning_mode === "online" ? Monitor : MapPin;
    return <ActionCard icon={modeIcon} eyebrow="Kelas berikutnya" title={`${nextSession.subject} bersama ${nextSession.teacher_name}`} description={new Date(nextSession.start_at).toLocaleString("id-ID", { weekday: "long", day: "numeric", month: "long", hour: "2-digit", minute: "2-digit" })} to="/student/my-classes" action="Lihat Detail" />;
  }
  return <ActionCard icon={BookOpenCheck} eyebrow="Paket aktif" title={`${packageData.remaining_sessions} sesi masih tersedia`} description="Buka Kelas Saya untuk melihat tutor, jadwal, dan laporan." to="/student/my-classes" action="Buka Kelas Saya" />;
}

function ActionCard({ icon: Icon, eyebrow, title, description, to, action, pulse = false, onDismiss }: { icon: typeof Radar; eyebrow: string; title: string; description: string; to: string; action: string; pulse?: boolean; onDismiss?: () => void }) {
  return <section className="relative max-w-full overflow-hidden rounded-[2rem] border border-indigo-100 bg-gradient-to-br from-white to-indigo-50 p-5 shadow-sm sm:p-6">
    {onDismiss && (
      <button
        type="button"
        aria-label={`Tutup notifikasi ${eyebrow}`}
        onClick={onDismiss}
        className="absolute right-3 top-3 grid h-8 w-8 shrink-0 place-items-center rounded-full bg-white/80 text-slate-500 transition hover:bg-slate-900 hover:text-white"
      >
        <X size={16} />
      </button>
    )}
    <div className="flex min-w-0 flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
      <div className="flex min-w-0 items-center gap-4">
        <div className={`grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-indigo-600 text-white shadow-lg shadow-indigo-200 ${pulse ? "animate-pulse" : ""}`}><Icon size={24} /></div>
        <div className="min-w-0">
          <p className="text-[10px] font-black uppercase tracking-[.18em] text-indigo-600">{eyebrow}</p>
          <h2 className="mt-1 break-words text-lg font-black text-slate-900 sm:text-xl">{title}</h2>
          <p className="mt-1 break-words text-sm text-slate-500">{description}</p>
        </div>
      </div>
      <Link to={to} className="inline-flex w-full shrink-0 items-center justify-center gap-2 rounded-2xl bg-slate-950 px-4 py-3 text-sm font-black text-white sm:w-auto">{action} <ArrowRight size={16} /></Link>
    </div>
  </section>;
}

function Summary({ icon: Icon, label, value, color }: { icon: typeof BookOpenCheck; label: string; value: string; color: string }) {
  return <div className="flex min-w-0 flex-col items-center gap-2 rounded-2xl border border-slate-100 bg-white p-3 text-center shadow-sm sm:flex-row sm:gap-4 sm:rounded-3xl sm:p-5 sm:text-left"><div className={`grid h-10 w-10 shrink-0 place-items-center rounded-xl text-white sm:h-12 sm:w-12 sm:rounded-2xl ${color}`}><Icon size={19} /></div><div className="min-w-0"><p className="text-[9px] font-black uppercase tracking-wide text-slate-500 sm:text-[10px] sm:tracking-wider">{label}</p><p className="mt-1 truncate text-base font-black text-slate-900 sm:text-xl">{value}</p></div></div>;
}

function ErrorState({ error, onRetry }: { error: string; onRetry: () => void }) {
  if (error === "network") {
    return (
      <div className="grid min-h-[400px] place-items-center px-4 text-center">
        <div>
          <WifiOff className="mx-auto text-slate-300" size={48} />
          <h2 className="mt-4 text-xl font-black text-slate-800">Koneksi Terputus</h2>
          <p className="mt-2 text-sm text-slate-500">Periksa koneksi internet Anda dan coba lagi.</p>
          <button type="button" onClick={onRetry} className="mt-5 inline-flex items-center gap-2 rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-black text-white hover:bg-indigo-700 transition-colors">
            <RefreshCw size={16} /> Coba Lagi
          </button>
        </div>
      </div>
    );
  }

  if (error === "forbidden") {
    return (
      <div className="grid min-h-[400px] place-items-center px-4 text-center">
        <div>
          <AlertCircle className="mx-auto text-amber-500" size={48} />
          <h2 className="mt-4 text-xl font-black text-slate-800">Akses Ditolak</h2>
          <p className="mt-2 text-sm text-slate-500">Anda tidak memiliki izin untuk mengakses halaman ini.</p>
          <Link to="/student/profile" className="mt-5 inline-flex rounded-2xl bg-slate-900 px-5 py-3 text-sm font-black text-white hover:bg-slate-800 transition-colors">
            Kembali ke Profil
          </Link>
        </div>
      </div>
    );
  }

  if (error === "not_found") {
    return (
      <div className="grid min-h-[400px] place-items-center px-4 text-center">
        <div>
          <AlertCircle className="mx-auto text-slate-300" size={48} />
          <h2 className="mt-4 text-xl font-black text-slate-800">Data Tidak Ditemukan</h2>
          <p className="mt-2 text-sm text-slate-500">Dashboard tidak dapat dimuat saat ini.</p>
          <button type="button" onClick={onRetry} className="mt-5 inline-flex items-center gap-2 rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-black text-white hover:bg-indigo-700 transition-colors">
            <RefreshCw size={16} /> Coba Lagi
          </button>
        </div>
      </div>
    );
  }

  if (error === "unauthorized") {
    return (
      <div className="grid min-h-[400px] place-items-center px-4 text-center">
        <div>
          <AlertCircle className="mx-auto text-orange-500" size={48} />
          <h2 className="mt-4 text-xl font-black text-slate-800">Sesi Berakhir</h2>
          <p className="mt-2 text-sm text-slate-500">Sesi Anda telah berakhir. Silakan login kembali.</p>
          <Link to="/login" className="mt-5 inline-flex rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-black text-white hover:bg-indigo-700 transition-colors">
            Login Kembali
          </Link>
        </div>
      </div>
    );
  }

  // Generic error
  return (
    <div className="grid min-h-[400px] place-items-center px-4 text-center">
      <div>
        <AlertCircle className="mx-auto text-rose-500" size={48} />
        <h2 className="mt-4 text-xl font-black text-slate-800">Terjadi Kesalahan</h2>
        <p className="mt-2 text-sm text-slate-500">Dashboard tidak dapat dimuat. Silakan coba lagi.</p>
        <button type="button" onClick={onRetry} className="mt-5 inline-flex items-center gap-2 rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-black text-white hover:bg-indigo-700 transition-colors">
          <RefreshCw size={16} /> Coba Lagi
        </button>
      </div>
    </div>
  );
}
