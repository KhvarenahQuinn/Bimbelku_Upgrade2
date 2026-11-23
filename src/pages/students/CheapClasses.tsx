import { useCallback, useEffect, useMemo, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { AlertCircle, ArrowRight, BarChart3, BookOpen, CalendarDays, CheckCircle2, Clock3, CreditCard, Loader2, Users } from "lucide-react";
import StudentLayout from "@/components/StudentLayout";
import { Button } from "@/components/ui/button";
import http, { getApiError } from "@/lib/http";
import { notify } from "@/lib/notify";
import { useConfirmDialog } from "@/components/ConfirmDialogProvider";
import WorkspacePageIntro from "@/components/WorkspacePageIntro";

type Enrollment = {
  id: number;
  status: string;
  seat_expires_at?: string;
  order_id?: number;
  order_number?: string;
  order_status?: string;
  can_cancel: boolean;
};

type ClassSession = {
  id: number;
  session_number: number;
  starts_at: string;
  ends_at: string;
  status: string;
};

type CheapClass = {
  id: number;
  subject_name: string;
  education_level: string;
  grade: string;
  chapter: string;
  topic?: string;
  starts_at: string;
  ends_at: string;
  session_count: number;
  sessions: ClassSession[];
  price_per_student: number;
  price_per_session: number;
  custom_price_per_student?: number | null;
  minimum_participants: number;
  maximum_participants: number;
  participant_count: number;
  occupied_seat_count: number;
  confirmed_participant_count: number;
  pending_payment_count: number;
  registration_opens_at: string;
  registration_deadline: string;
  status: string;
  cancellation_reason?: string;
  can_join: boolean;
  enrollment?: Enrollment | null;
  teacher?: { name?: string; photo?: string | null } | null;
  meeting_link?: string | null;
};

const money = (value: number) => new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(value);
const dateTime = (value: string) => new Date(value).toLocaleString("id-ID", { weekday: "long", day: "numeric", month: "long", hour: "2-digit", minute: "2-digit" });

const stateLabel: Record<string, string> = {
  waiting_teacher: "Tutor pengganti sedang dicari",
  open: "Pendaftaran dibuka",
  registration_closed: "Pendaftaran ditutup",
  awaiting_verification: "Menunggu verifikasi",
  confirmed: "Kelas dikonfirmasi",
  completed: "Kelas selesai",
  cancelled: "Kelas dibatalkan",
  seat_held: "Kursi ditahan",
  payment_submitted: "Bukti sedang diperiksa",
  payment_rejected: "Bukti perlu dikirim ulang",
  partially_paid: "Pembayaran masih kurang",
  confirmed_enrollment: "Peserta terkonfirmasi",
  payment_expired: "Waktu pembayaran berakhir",
  cancellation_pending: "Kelas dibatalkan, bukti masih diperiksa",
  refund_pending: "Refund diproses",
  refunded: "Refund selesai",
};

const sessionStateLabel: Record<string, string> = {
  scheduled: "Terjadwal",
  report_required: "Menunggu laporan tutor",
  awaiting_admin_verification: "Menunggu verifikasi admin",
  revision_requested: "Laporan sedang diperbaiki",
  completed: "Selesai",
  cancelled: "Dibatalkan",
};

export default function CheapClasses() {
  const navigate = useNavigate();
  const confirm = useConfirmDialog();
  const [classes, setClasses] = useState<CheapClass[]>([]);
  const [loading, setLoading] = useState(true);
  const [working, setWorking] = useState<number | null>(null);
  const [scope, setScope] = useState<"active" | "history">("active");

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const response = await http.get<CheapClass[]>("/student/cheap-classes");
      setClasses(response.data);
    } catch (error) {
      notify.error(getApiError(error, "Kelas Bersama belum dapat dimuat."));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void load(); }, [load]);

  const activeClasses = useMemo(() => classes.filter((item) => !["completed", "cancelled"].includes(item.status)), [classes]);
  const historyClasses = useMemo(() => classes.filter((item) => ["completed", "cancelled"].includes(item.status)), [classes]);
  const visibleClasses = scope === "active" ? activeClasses : historyClasses;

  const join = async (item: CheapClass) => {
    setWorking(item.id);
    try {
      const response = await http.post(`/student/cheap-classes/${item.id}/join`);
      notify.success(response.data.message);
      window.dispatchEvent(new Event("bimbelku:data-changed"));
      navigate("/payment", {
        state: {
          orderId: response.data.order_id,
          invoiceId: response.data.order_number,
          tutorName: "Tutor diumumkan setelah kelas dikonfirmasi",
          subject: item.subject_name,
          type: "Online - Kelas Bersama",
          price: item.price_per_student,
          date: item.starts_at,
          paymentDueAt: response.data.payment_due_at,
          durationHours: 1,
          totalLearningHours: item.session_count,
          orderKind: response.data.order_kind || "cheap_class",
          enrollmentStatus: response.data.enrollment_status || "seat_held",
          cheapClassStatus: response.data.cheap_class_status || item.status,
          cheapClassCancellationReason: item.cancellation_reason,
          canCancel: Boolean(response.data.can_cancel),
          canResubmit: Boolean(response.data.can_resubmit),
        },
      });
    } catch (error) {
      notify.error(getApiError(error));
      await load();
    } finally {
      setWorking(null);
    }
  };

  const cancel = async (item: CheapClass) => {
    if (!item.enrollment) return;
    const approved = await confirm({
      title: "Batalkan keikutsertaan?",
      description: "Kursi akan dilepas. Kamu masih dapat bergabung lagi jika pendaftaran tetap dibuka dan kuota masih tersedia. Pembatalan tidak tersedia setelah bukti pembayaran dikirim.",
      confirmText: "Batalkan keikutsertaan",
      tone: "danger",
    });
    if (!approved) return;
    setWorking(item.id);
    try {
      const response = await http.post(`/student/cheap-class-enrollments/${item.enrollment.id}/cancel`);
      notify.success(response.data.message);
      window.dispatchEvent(new Event("bimbelku:data-changed"));
      await load();
    } catch (error) {
      notify.error(getApiError(error));
    } finally {
      setWorking(null);
    }
  };

  return (
    <StudentLayout title="Kelas Bersama">
      <div className="mx-auto max-w-7xl space-y-7 pb-12">
        <div data-tour="cheap-class-hero"><WorkspacePageIntro eyebrow="Belajar bersama online" title="Kelas Bersama" description="Pilih kelas berdasarkan mapel, jenjang, jadwal, kapasitas, dan harga. Tutor ditetapkan setelah kesiapan seluruh sesi diperiksa." icon={Users} /></div>

        {!loading && classes.length > 0 && <section className="rounded-[1.5rem] border border-slate-100 bg-white p-2 shadow-sm"><div className="grid grid-cols-2 gap-1.5 rounded-2xl bg-slate-100 p-1.5"><button type="button" onClick={() => setScope("active")} className={`min-h-11 rounded-xl text-sm font-black ${scope === "active" ? "bg-white text-indigo-700 shadow-sm" : "text-slate-500"}`}>Aktif ({activeClasses.length})</button><button type="button" onClick={() => setScope("history")} className={`min-h-11 rounded-xl text-sm font-black ${scope === "history" ? "bg-white text-indigo-700 shadow-sm" : "text-slate-500"}`}>Riwayat ({historyClasses.length})</button></div></section>}

        {loading ? (
          <div className="grid min-h-64 place-items-center rounded-[2rem] border border-slate-100 bg-white"><Loader2 className="animate-spin text-indigo-600" /></div>
        ) : classes.length === 0 ? (
          <div className="rounded-[2rem] border-2 border-dashed border-slate-200 bg-white px-6 py-16 text-center"><BookOpen className="mx-auto text-slate-300" size={40} /><h2 className="mt-4 text-lg font-black text-slate-800">Belum ada Kelas Bersama yang dijadwalkan</h2><p className="mt-2 text-sm text-slate-500">Penawaran baru akan tampil otomatis saat pendaftaran dibuka.</p><Link to="/student/packages/new" className="mt-5 inline-flex text-sm font-black text-indigo-600">Cari les privat <ArrowRight className="ml-1" size={16} /></Link></div>
        ) : visibleClasses.length === 0 ? (
          <div className="rounded-[2rem] border-2 border-dashed border-slate-200 bg-white px-6 py-12 text-center"><BookOpen className="mx-auto text-slate-300" size={34} /><p className="mt-3 text-sm font-bold text-slate-500">{scope === "history" ? "Riwayat Kelas Bersama masih kosong." : "Tidak ada Kelas Bersama aktif."}</p></div>
        ) : (
          <section data-tour="cheap-class-list" className="grid gap-5 lg:grid-cols-2">
            {visibleClasses.map((item) => <ClassCard key={item.id} item={item} working={working === item.id} onJoin={() => join(item)} onCancel={() => cancel(item)} />)}
          </section>
        )}
      </div>
    </StudentLayout>
  );
}

function ClassCard({ item, working, onJoin, onCancel }: { item: CheapClass; working: boolean; onJoin: () => void; onCancel: () => void }) {
  const enrollment = item.enrollment;
  const payable = enrollment && ["seat_held", "payment_rejected", "partially_paid"].includes(enrollment.status) && ["pending", "rejected", "partially_paid"].includes(enrollment.order_status || "");
  const needsProofReview = enrollment?.status === "payment_submitted";
  const hasAcceptedPayment = enrollment?.status === "confirmed" && enrollment?.order_status === "paid";
  const normalPackagePrice = item.price_per_session * item.session_count;
  const hasPriceDiscount = normalPackagePrice > item.price_per_student + 0.009;
  const hasLearningAccess = ["confirmed", "completed"].includes(item.status) && hasAcceptedPayment;
  const waitingForClassConfirmation = hasAcceptedPayment && !["confirmed", "completed"].includes(item.status);
  const sessions = item.sessions.length ? item.sessions : [{ id: item.id, session_number: 1, starts_at: item.starts_at, ends_at: item.ends_at, status: "scheduled" }];
  const hasActions = Boolean(item.can_join || (payable && enrollment) || hasLearningAccess || enrollment?.can_cancel || (!item.can_join && !payable && !needsProofReview && !hasAcceptedPayment && item.status !== "cancelled" && !["cancellation_pending", "refund_pending", "refunded", "cancelled", "payment_expired"].includes(enrollment?.status || "")));

  return <article className="overflow-hidden rounded-[2rem] border border-slate-100 bg-white shadow-sm transition hover-rise-half hover-shadow-lg">
    <div className="border-b border-indigo-50 bg-gradient-to-br from-indigo-50 to-white p-5 sm:p-6">
      <div className="flex min-w-0 flex-col gap-3 min-[360px]:flex-row min-[360px]:items-start min-[360px]:justify-between">
        <div className="min-w-0"><p className="text-xs font-black uppercase tracking-[.18em] text-indigo-600">Kelas Bersama · Online</p><h2 className="mt-2 break-words text-xl font-black text-slate-900">{item.subject_name}</h2><p className="mt-1 text-sm font-bold text-slate-600">{item.education_level} · {item.grade}</p></div>
        <span className={`max-w-full self-start break-words rounded-full px-3 py-1.5 text-xs font-black leading-tight ${["confirmed", "completed"].includes(item.status) ? "bg-emerald-100 text-emerald-700" : item.status === "cancelled" ? "bg-rose-100 text-rose-700" : "bg-indigo-100 text-indigo-700"}`}>{stateLabel[item.status] || item.status}</span>
      </div>
      <p className="mt-4 rounded-xl border border-white bg-white/85 p-3 text-sm font-semibold text-slate-700 shadow-sm">{item.chapter}</p>
    </div>

    <div className="space-y-4 p-5 sm:p-6">
      <div className="grid grid-cols-1 gap-2.5 text-sm min-[360px]:grid-cols-2">
        <Meta icon={CalendarDays} label="Sesi pertama" value={dateTime(item.starts_at)} />
        <Meta icon={Clock3} label="Paket belajar" value={`${item.session_count} sesi · 1 jam/sesi`} />
        <Meta icon={Users} label="Kursi terisi" value={`${item.occupied_seat_count}/${item.maximum_participants}`} />
        <Meta icon={CheckCircle2} label="Pembayaran terverifikasi" value={`${item.confirmed_participant_count} · minimum ${item.minimum_participants}`} />
      </div>

      <div className="rounded-2xl border border-indigo-100 bg-gradient-to-br from-indigo-50 to-white p-4">
        <div className="flex items-center justify-between gap-3">
          <div className="flex min-w-0 items-center gap-3"><span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-white text-indigo-600 shadow-sm"><CreditCard size={18} /></span><div className="min-w-0"><p className="text-[10px] font-black uppercase tracking-wider text-indigo-500">Sekali bayar</p><p className="mt-0.5 flex flex-wrap items-baseline gap-2"><span className="text-lg font-black text-slate-900">{money(item.price_per_student)}</span>{hasPriceDiscount && <span className="text-xs font-semibold text-slate-400 line-through">{money(normalPackagePrice)}</span>}</p></div></div>
          <span className="rounded-full bg-white px-2.5 py-1 text-[10px] font-black text-indigo-700 shadow-sm">{item.session_count} sesi</span>
        </div>
        <p className="mt-3 text-xs font-semibold leading-5 text-indigo-800">Satu pembayaran mencakup seluruh paket. Tidak ada tagihan baru pada sesi berikutnya.</p>
      </div>

      {item.custom_price_per_student !== null && item.custom_price_per_student !== undefined && <div className="rounded-xl bg-emerald-50 p-3 text-xs font-bold text-emerald-800">Harga custom admin: {money(item.price_per_student)}</div>}

      <div className="rounded-2xl border border-indigo-100 bg-indigo-50/45 p-4">
        <div className="flex items-center justify-between gap-3"><p className="text-xs font-black text-indigo-950">{sessions.length > 1 ? "Jadwal semua sesi" : "Jadwal sesi"}</p><span className="text-[10px] font-black uppercase tracking-wider text-indigo-500">{sessions.length} sesi</span></div>
        <div className="mt-3 grid gap-2">
          {sessions.map((session) => <div key={session.id} className="flex items-start gap-3 rounded-xl border border-indigo-100/80 bg-white px-3 py-2.5"><span className="grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-indigo-100 text-[11px] font-black text-indigo-700">{session.session_number}</span><div className="min-w-0"><p className="text-xs font-black leading-5 text-slate-800">{dateTime(session.starts_at)}</p><p className="mt-0.5 text-[11px] font-semibold text-slate-500">{sessionStateLabel[session.status] || session.status}</p></div></div>)}
        </div>
      </div>

      <div className="rounded-xl border border-slate-100 bg-slate-50 p-3 text-xs leading-5 text-slate-600">Pendaftaran berakhir {dateTime(item.registration_deadline)}. Bukti transfer yang masuk sebelum tenggat tetap diperiksa meski pendaftaran telah ditutup.</div>
      {item.status === "cancelled" && <div className="flex gap-2 rounded-xl bg-rose-50 p-3 text-xs font-bold leading-5 text-rose-700"><AlertCircle className="shrink-0" size={16} />{item.cancellation_reason || "Kelas dibatalkan."}</div>}

      {needsProofReview ? (
        <div className="flex items-start gap-3 rounded-2xl border border-blue-100 bg-blue-50 p-4 text-blue-900">
          <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-white text-blue-600 shadow-sm"><Clock3 size={18} /></span>
          <div><p className="text-sm font-black">Pembayaran sedang diperiksa</p><p className="mt-1 text-xs font-semibold leading-5 text-blue-700">Bukti pembayaran sudah terkirim ke admin. Kamu tidak perlu mengirim ulang selama status ini masih tampil.</p></div>
        </div>
      ) : enrollment && <div className="rounded-xl border border-indigo-100 bg-indigo-50 p-3 text-xs font-bold text-indigo-800">{enrollment.status === "confirmed" ? (item.status === "confirmed" ? "Peserta terkonfirmasi" : "Pembayaran diterima · menunggu konfirmasi kelas") : enrollment.status === "cancelled" ? "Keikutsertaan dibatalkan" : stateLabel[enrollment.status] || enrollment.status}{enrollment.seat_expires_at && payable ? ` · bayar sebelum ${dateTime(enrollment.seat_expires_at)}` : ""}</div>}

      {waitingForClassConfirmation && <div className="rounded-xl bg-emerald-50 p-3 text-xs font-bold leading-5 text-emerald-800">Pembayaranmu sudah diverifikasi. Kelas akan dikonfirmasi setelah syarat minimum peserta terpenuhi dan seluruh bukti yang relevan selesai diperiksa.</div>}
      {enrollment?.status === "cancellation_pending" && <div className="rounded-xl bg-amber-50 p-3 text-xs font-bold leading-5 text-amber-800">Kelas sudah dibatalkan, tetapi bukti yang telanjur dikirim tetap diperiksa admin. Jika transfer valid, dana masuk antrean refund penuh.</div>}
      {hasLearningAccess && <div className="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between"><div className="flex items-center gap-2 text-sm font-black text-slate-600"><CheckCircle2 size={17} />Telah bergabung</div><Link to={`/student/my-classes?cheap_class=${item.id}`} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-white px-4 text-sm font-black text-indigo-700 shadow-sm ring-1 ring-slate-200 hover:bg-indigo-50">Buka di Kelas Saya <ArrowRight size={15} /></Link></div>}
      {item.status === "completed" && hasAcceptedPayment && <div className="rounded-2xl border border-violet-100 bg-violet-50 p-4 text-xs font-bold leading-5 text-violet-800"><p>Seluruh sesi sudah diverifikasi admin. Kelas masuk Riwayat dan progress akhir tetap bisa kamu lihat.</p><Link to={`/student/progress/cheap-class/${item.id}`} className="mt-3 inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-white px-4 text-sm font-black text-violet-700 shadow-sm ring-1 ring-violet-200 hover:bg-violet-100"><BarChart3 size={16} />Lihat Progress</Link></div>}

      {hasActions && <div className="flex flex-col gap-2 sm:flex-row">
        {item.can_join && <Button disabled={working} onClick={onJoin} className="h-11 flex-1 rounded-xl bg-indigo-600 font-black hover:bg-indigo-700">{working && <Loader2 className="mr-2 animate-spin" size={16} />}{enrollment && ["cancelled", "payment_expired"].includes(enrollment.status) ? "Gabung Lagi" : "Gabung Kelas Bersama"}</Button>}
        {payable && enrollment && <Link to="/payment" state={{ orderId: enrollment.order_id, invoiceId: enrollment.order_number, tutorName: "Tutor diumumkan setelah kelas dikonfirmasi", subject: item.subject_name, type: "Online - Kelas Bersama", price: item.price_per_student, date: item.starts_at, paymentDueAt: enrollment.seat_expires_at, durationHours: 1, totalLearningHours: item.session_count, orderKind: "cheap_class", enrollmentStatus: enrollment.status, cheapClassStatus: item.status, cheapClassCancellationReason: item.cancellation_reason, canCancel: enrollment.can_cancel }} className="inline-flex h-11 flex-1 items-center justify-center rounded-xl bg-indigo-600 px-4 text-sm font-black text-white hover:bg-indigo-700">Bayar paket ini</Link>}
        {enrollment?.can_cancel && <Button disabled={working} onClick={onCancel} variant="outline" className="h-11 rounded-xl text-rose-600 hover:bg-rose-50">Batalkan keikutsertaan</Button>}
        {!item.can_join && !payable && !needsProofReview && !hasAcceptedPayment && item.status !== "cancelled" && !["cancellation_pending", "refund_pending", "refunded", "cancelled", "payment_expired"].includes(enrollment?.status || "") && <div className="flex flex-1 items-center justify-center rounded-xl bg-slate-100 px-4 text-sm font-bold text-slate-500">Pendaftaran tidak tersedia</div>}
      </div>}
    </div>
  </article>;
}

function Meta({ icon: Icon, label, value }: { icon: typeof CalendarDays; label: string; value: string }) {
  return <div className="min-w-0 rounded-xl border border-slate-100 bg-slate-50/70 p-3"><div className="flex items-center gap-2"><Icon className="shrink-0 text-indigo-500" size={15} /><p className="text-[10px] font-black uppercase tracking-wider text-slate-400">{label}</p></div><p className="mt-2 break-words text-xs font-black leading-5 text-slate-700">{value}</p></div>;
}
