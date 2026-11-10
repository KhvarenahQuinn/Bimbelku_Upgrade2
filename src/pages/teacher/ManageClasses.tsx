import { notify } from "@/lib/notify";
import { FormEvent, lazy, Suspense, useEffect, useMemo, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import {
  AlertTriangle,
  ArrowLeft,
  ArrowUpDown,
  BarChart3,
  BookOpen,
  CalendarDays,
  CheckCircle2,
  Clock3,
  ExternalLink,
  FileWarning,
  Filter,
  GraduationCap,
  Link2,
  Loader2,
  MapPin,
  MessageCircle,
  Monitor,
  RefreshCw,
  ShieldAlert,
  UserRoundX,
  Users,
  WalletCards,
} from "lucide-react";
import TeacherLayout from "@/components/TeacherLayout";
import { useConfirmDialog } from "@/components/ConfirmDialogProvider";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import http, { getApiError, getCached } from "@/lib/http";
import { announceNavigationAttentionChanged, unreadIdsForTeacherClassScope, type AttentionNotification } from "@/lib/navigationAttention";
import { isValidHttpUrl, validateUpload } from "@/lib/validation";
import TeacherCheapClasses from "./CheapClasses";
import WorkspacePageIntro from "@/components/WorkspacePageIntro";

const LearningSessionHub = lazy(() => import("@/components/LearningSessionHub"));

interface Participant {
  id: number;
  student_id: number;
  name: string;
  status: string;
  amount: number;
  order_status: string;
  refund_status?: string;
}

interface TeacherClass {
  id: number;
  package_session_id?: number | null;
  package_subject_id?: number | null;
  learning_package_id?: number | null;
  package_code?: string | null;
  package_name?: string | null;
  subject: string;
  education_level?: string;
  grade?: string;
  chapter?: string;
  learning_goal?: string;
  method: "online" | "offline";
  status: string;
  tutor_ready_at?: string | null;
  student_confirmed_at?: string | null;
  session_focus_note?: string | null;
  start_at: string;
  end_at: string;
  duration_hours: number;
  meeting_link?: string;
  address?: string;
  maps_link?: string;
  gross_amount?: number;
  teacher_net_amount?: number;
  commission_percent: number;
  payout_status: string;
  completion_notes?: string;
  objection_deadline?: string;
  participants: Participant[];
  latest_report?: { type: string; status: string; chronology: string };
  latest_dispute?: { status: string; reason: string };
  package_progress?: { total_chapters: number; completed_chapters: number; in_progress_chapters: number; progress_percent: number } | null;
  package_session_progress?: {
    total_sessions: number;
    used_sessions: number;
    remaining_sessions: number;
    subject_total_sessions: number;
    subject_completed_sessions: number;
    subject_remaining_sessions: number;
  } | null;
  completion_steps: { check_in: boolean; attendance: boolean; check_out: boolean; progress: boolean; progress_reported_count: number; progress_required_count: number };
  latest_progress_report?: { id: number; session_number: number; material_covered: string; progress_percent: number; published_at: string } | null;
  can_complete: boolean;
  can_report_absence: boolean;
  can_report_emergency: boolean;
}

type Action = "absence" | "emergency";
type MethodFilter = "all" | TeacherClass["method"];
type ScheduleSort = "nearest" | "farthest";
type ClassScope = "active" | "history";
type ClassKind = "all" | "private" | "group";

const teacherHistoryStatuses = new Set(["completed", "refunded"]);

const statusLabels: Record<string, string> = {
  awaiting_payment: "Menunggu pembayaran",
  payment_submitted: "Bukti diperiksa",
  confirmed: "Terjadwal",
  in_progress: "Berlangsung",
  awaiting_student_approval: "Menunggu murid",
  disputed: "Keberatan diperiksa",
  absence_review: "Kehadiran diperiksa",
  admin_review_required: "Diperiksa admin",
  completed: "Selesai",
  emergency_refund_pending: "Refund darurat",
  refund_pending: "Refund diproses",
  refunded: "Direfund",
};

const rupiah = (value?: number) => new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(value || 0);

const isRecord = (value: unknown): value is Record<string, unknown> => Boolean(value) && typeof value === "object";

const normalizeParticipant = (value: unknown): Participant | null => {
  if (!isRecord(value)) return null;
  const id = Number(value.id);
  const studentId = Number(value.student_id);
  if (!Number.isFinite(id)) return null;

  return {
    id,
    student_id: Number.isFinite(studentId) ? studentId : 0,
    name: typeof value.name === "string" && value.name.trim() ? value.name : "Murid BimbelKu",
    status: typeof value.status === "string" ? value.status : "unknown",
    amount: Number.isFinite(Number(value.amount)) ? Number(value.amount) : 0,
    order_status: typeof value.order_status === "string" ? value.order_status : "unknown",
    refund_status: typeof value.refund_status === "string" ? value.refund_status : undefined,
  };
};

const normalizeTeacherClass = (value: unknown): TeacherClass | null => {
  if (!isRecord(value)) return null;
  const id = Number(value.id);
  if (!Number.isFinite(id)) return null;

  const participants = Array.isArray(value.participants)
    ? value.participants.map(normalizeParticipant).filter((item): item is Participant => item !== null)
    : [];

  return {
    ...(value as unknown as TeacherClass),
    id,
    subject: typeof value.subject === "string" && value.subject.trim() ? value.subject : "Mata pelajaran",
    method: value.method === "offline" ? "offline" : "online",
    status: typeof value.status === "string" && value.status ? value.status : "confirmed",
    start_at: typeof value.start_at === "string" ? value.start_at : "",
    end_at: typeof value.end_at === "string" ? value.end_at : "",
    duration_hours: Number.isFinite(Number(value.duration_hours)) ? Number(value.duration_hours) : 1,
    commission_percent: Number.isFinite(Number(value.commission_percent)) ? Number(value.commission_percent) : 0,
    payout_status: typeof value.payout_status === "string" ? value.payout_status : "pending",
    participants,
    completion_steps: isRecord(value.completion_steps) ? {
      check_in: Boolean(value.completion_steps.check_in),
      attendance: Boolean(value.completion_steps.attendance),
      check_out: Boolean(value.completion_steps.check_out),
      progress: Boolean(value.completion_steps.progress),
      progress_reported_count: Number(value.completion_steps.progress_reported_count || 0),
      progress_required_count: Number(value.completion_steps.progress_required_count || 0),
    } : { check_in: false, attendance: false, check_out: false, progress: false, progress_reported_count: 0, progress_required_count: 0 },
    package_session_progress: isRecord(value.package_session_progress) ? {
      total_sessions: Number(value.package_session_progress.total_sessions || 0),
      used_sessions: Number(value.package_session_progress.used_sessions || 0),
      remaining_sessions: Number(value.package_session_progress.remaining_sessions || 0),
      subject_total_sessions: Number(value.package_session_progress.subject_total_sessions || 0),
      subject_completed_sessions: Number(value.package_session_progress.subject_completed_sessions || 0),
      subject_remaining_sessions: Number(value.package_session_progress.subject_remaining_sessions || 0),
    } : null,
    can_complete: Boolean(value.can_complete),
    can_report_absence: Boolean(value.can_report_absence),
    can_report_emergency: Boolean(value.can_report_emergency),
  };
};

const normalizeClasses = (payload: unknown): TeacherClass[] => {
  const items = Array.isArray(payload)
    ? payload
    : isRecord(payload) && Array.isArray(payload.data)
      ? payload.data
      : [];

  return items.map(normalizeTeacherClass).filter((item): item is TeacherClass => item !== null);
};

const parseDate = (value?: string) => {
  if (!value) return null;
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? null : date;
};

const dateTime = (value?: string) => {
  const date = parseDate(value);
  return date
    ? new Intl.DateTimeFormat("id-ID", { dateStyle: "full", timeStyle: "short" }).format(date)
    : "Jadwal belum tersedia";
};

const timeRange = (start?: string, end?: string) => {
  const startDate = parseDate(start);
  const endDate = parseDate(end);
  if (!startDate || !endDate) return "Jam belum tersedia";
  const format = (date: Date) => date.toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" });
  return `${format(startDate)}–${format(endDate)}`;
};

const localDateTimeInput = () => {
  const date = new Date();
  return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
};

export default function ManageClasses() {
  const confirm = useConfirmDialog();
  const [searchParams, setSearchParams] = useSearchParams();
  const [classes, setClasses] = useState<TeacherClass[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [selected, setSelected] = useState<TeacherClass | null>(null);
  const [action, setAction] = useState<{ type: Action; item: TeacherClass } | null>(null);
  const [processing, setProcessing] = useState(false);
  const [meetingLink, setMeetingLink] = useState("");
  const [evidence, setEvidence] = useState<File | null>(null);
  const [notes, setNotes] = useState("");
  const [studentId, setStudentId] = useState("");
  const [incidentType, setIncidentType] = useState("");
  const [incidentAt, setIncidentAt] = useState("");
  const [incidentLocation, setIncidentLocation] = useState("");
  const [impact, setImpact] = useState("");
  const [hubBookingId, setHubBookingId] = useState<number | null>(null);
  const [hubReturnClass, setHubReturnClass] = useState<TeacherClass | null>(null);
  const [classAttention, setClassAttention] = useState<AttentionNotification[]>([]);
  const [hubInitialTab, setHubInitialTab] = useState<"session" | "progress">("session");
  const [classScope, setClassScope] = useState<ClassScope>(() => searchParams.get("scope") === "history" ? "history" : "active");
  const [methodFilter, setMethodFilter] = useState<MethodFilter>("all");
  const [scheduleSort, setScheduleSort] = useState<ScheduleSort>("nearest");
  const [groupRefreshToken, setGroupRefreshToken] = useState(0);
  const [classKind, setClassKind] = useState<ClassKind>(() => {
    const requested = searchParams.get("class_kind");
    return requested === "group" || requested === "private" ? requested : "all";
  });

  useEffect(() => {
    const requested = searchParams.get("class_kind");
    setClassKind(requested === "group" || requested === "private" ? requested : "all");
  }, [searchParams]);

  const changeClassKind = (nextKind: ClassKind) => {
    const next = new URLSearchParams(searchParams);
    if (nextKind === "all") next.delete("class_kind");
    else next.set("class_kind", nextKind);
    if (nextKind !== "group") {
      next.delete("cheap_class");
      next.delete("cheap_session");
      next.delete("cheap_action");
    }
    setClassKind(nextKind);
    setSearchParams(next, { replace: true });
  };

  const openClassScope = async (scope: ClassScope) => {
    setClassScope(scope);
    const next = new URLSearchParams(searchParams);
    if (scope === "history") next.set("scope", "history");
    else next.delete("scope");
    setSearchParams(next, { replace: true });
    const ids = unreadIdsForTeacherClassScope(scope, classAttention);
    if (!ids.length) return;

    const idSet = new Set(ids);
    setClassAttention((current) => current.filter((item) => !idSet.has(item.id)));
    try {
      await http.post("/notifications/read-batch", { ids });
      announceNavigationAttentionChanged();
    } catch {
      // Polling layout akan mengembalikan indikator bila status baca gagal disimpan.
    }
  };

  useEffect(() => { void loadClasses(); }, []);

  useEffect(() => {
    const refresh = () => {
      if (document.visibilityState === "visible") void loadClasses({ silent: true });
    };
    window.addEventListener("focus", refresh);
    window.addEventListener("bimbelku:data-changed", refresh);
    const timer = window.setInterval(refresh, 10_000);
    return () => {
      window.removeEventListener("focus", refresh);
      window.removeEventListener("bimbelku:data-changed", refresh);
      window.clearInterval(timer);
    };
  }, []);

  const activeClasses = useMemo(() => classes.filter((item) => !teacherHistoryStatuses.has(item.status)), [classes]);
  const historyClasses = useMemo(() => classes.filter((item) => teacherHistoryStatuses.has(item.status)), [classes]);

  const visibleClasses = useMemo(() => {
    const now = Date.now();
    const scoped = classScope === "active" ? activeClasses : historyClasses;
    const filtered = methodFilter === "all"
      ? scoped
      : scoped.filter((item) => item.method === methodFilter);

    return [...filtered].sort((left, right) => {
      const leftTime = parseDate(left.start_at)?.getTime();
      const rightTime = parseDate(right.start_at)?.getTime();
      const leftEndTime = parseDate(left.end_at)?.getTime();
      const rightEndTime = parseDate(right.end_at)?.getTime();

      if (leftTime === undefined && rightTime === undefined) return left.id - right.id;
      if (leftTime === undefined) return 1;
      if (rightTime === undefined) return -1;

      const leftOngoing = left.status === "in_progress"
        || (leftTime <= now && leftEndTime !== undefined && leftEndTime >= now);
      const rightOngoing = right.status === "in_progress"
        || (rightTime <= now && rightEndTime !== undefined && rightEndTime >= now);
      const leftUpcoming = leftTime >= now;
      const rightUpcoming = rightTime >= now;

      if (scheduleSort === "nearest" && leftOngoing !== rightOngoing) {
        return leftOngoing ? -1 : 1;
      }
      if (leftUpcoming !== rightUpcoming) return leftUpcoming ? -1 : 1;

      if (scheduleSort === "nearest") {
        return leftUpcoming ? leftTime - rightTime : rightTime - leftTime;
      }

      return leftUpcoming ? rightTime - leftTime : leftTime - rightTime;
    });
  }, [activeClasses, classScope, historyClasses, methodFilter, scheduleSort]);

  const loadClasses = async ({ silent = false }: { silent?: boolean } = {}) => {
    if (!silent) setLoading(true);
    try {
      const response = await getCached<unknown>("/teacher/classes", { maxAgeMs: 2_000, force: true });
      const normalized = normalizeClasses(response.data);
      setClasses(normalized);
      setSelected((current) => current ? normalized.find((item) => item.id === current.id) || null : null);
      setLoadError(null);
    } catch (error) {
      if (!silent) {
        const message = getApiError(error, "Daftar kelas gagal dimuat.");
        setClasses([]);
        setSelected(null);
        setLoadError(message);
        notify.error(message);
      }
    } finally {
      if (!silent) setLoading(false);
    }
  };

  const clearSessionActionQuery = () => {
    if (!searchParams.get("session")) return;
    const next = new URLSearchParams(searchParams);
    next.delete("session");
    next.delete("session_action");
    setSearchParams(next, { replace: true });
  };

  const saveMeetingLink = async (item: TeacherClass) => {
    if (meetingLink && !isValidHttpUrl(meetingLink)) {
      notify.error("Tautan kelas harus diawali http:// atau https://.");
      return;
    }
    setProcessing(true);
    try {
      const response = await http.put(`/teacher/classes/${item.id}`, { meeting_link: meetingLink });
      notify.success(response.data.message);
      await loadClasses();
    } catch (error) {
      notify.error(getApiError(error));
    } finally {
      setProcessing(false);
    }
  };

  const openAction = (type: Action, item: TeacherClass) => {
    setAction({ type, item });
    setEvidence(null);
    setNotes("");
    setStudentId(String(item.participants?.[0]?.student_id || ""));
    setIncidentType("");
    setIncidentAt(localDateTimeInput());
    setIncidentLocation("");
    setImpact("");
  };

  useEffect(() => {
    const sessionId = Number(searchParams.get("session"));
    const deepAction = searchParams.get("session_action");
    if (!Number.isFinite(sessionId) || !deepAction || classes.length === 0) return;
    const item = classes.find((row) => row.id === sessionId);
    if (!item) return;

    if (["checkout", "progress", "ready", "session"].includes(deepAction)) {
      setHubReturnClass(item);
      setHubInitialTab(deepAction === "progress" ? "progress" : "session");
      setHubBookingId(item.id);
      setSelected(null);
      return;
    }

    if (deepAction === "complete" && !action) {
      setHubReturnClass(item);
      setHubInitialTab("progress");
      setHubBookingId(item.id);
      setSelected(null);
    }
  }, [action, classes, searchParams]);

  const submitAction = async (event: FormEvent) => {
    event.preventDefault();
    if (!action || !evidence) return;
    if (action.type === "emergency") {
      const accepted = await confirm({
        title: "Laporkan keadaan darurat?",
        description: "Sesi akan dihentikan dan refund penuh murid langsung masuk antrean transfer admin. Admin tetap memeriksa kebenaran laporan.",
        confirmText: "Kirim laporan",
        tone: "warning",
      });
      if (!accepted) return;
    }

    const payload = new FormData();
    payload.append("evidence", evidence);
    let endpoint = "";
    if (action.type === "absence") {
      endpoint = "absence";
      payload.append("chronology", notes);
      if (studentId) payload.append("student_id", studentId);
    } else {
      endpoint = "emergency";
      payload.append("incident_type", incidentType);
      payload.append("chronology", notes);
      payload.append("incident_at", incidentAt);
      payload.append("incident_location", incidentLocation);
      payload.append("impact", impact);
    }

    setProcessing(true);
    try {
      const response = await http.post(`/teacher/bookings/${action.item.id}/${endpoint}`, payload);
      notify.success(response.data.message);
      setAction(null);
      setSelected(null);
      clearSessionActionQuery();
      await loadClasses();
    } catch (error) {
      notify.error(getApiError(error));
    } finally {
      setProcessing(false);
    }
  };

  const selectEvidence = (file?: File) => {
    const error = validateUpload(file, {
      label: "Bukti laporan",
      maxSizeMb: 5,
      extensions: ["jpg", "jpeg", "png", "webp", "pdf"],
    });
    if (error) {
      notify.error(error);
      setEvidence(null);
      return;
    }
    setEvidence(file || null);
  };

  return (
    <TeacherLayout title="Kelas Saya" onAttentionNotificationsChange={setClassAttention}>
      <div className="mx-auto max-w-7xl space-y-8 pb-24 sm:space-y-10 sm:pb-16">
        <div data-tour="teacher-classes-hero"><WorkspacePageIntro eyebrow="Pelaksanaan sesi" title="Kelas Saya" description="Kelola kelas privat dan Kelas Bersama dari konfirmasi hadir sampai hasil belajar. Kelas selesai tetap tersedia di Riwayat." icon={BookOpen} actions={<Button onClick={() => { void loadClasses(); setGroupRefreshToken((current) => current + 1); }} variant="outline" className="h-11 w-full rounded-xl sm:w-auto"><RefreshCw size={16} className="mr-2" />Muat ulang</Button>} /></div>

        <section className="rounded-[1.75rem] border border-slate-100 bg-white p-4 shadow-sm sm:p-5">
          <div className="grid gap-4 lg:grid-cols-2 lg:gap-6">
            <div>
              <p className="mb-2 text-[11px] font-black uppercase tracking-[.14em] text-slate-500">Jenis kelas</p>
              <div className="grid grid-cols-3 gap-1.5 rounded-2xl bg-slate-100 p-1.5">
                {([['all', 'Semua'], ['private', 'Privat'], ['group', 'Kelas Bersama']] as const).map(([value, label]) => <button key={value} type="button" onClick={() => changeClassKind(value)} aria-pressed={classKind === value} className={`min-h-11 rounded-xl px-2 text-xs font-black transition sm:text-sm ${classKind === value ? "bg-white text-indigo-700 shadow-sm" : "text-slate-500 hover:bg-white/70"}`}>{label}</button>)}
              </div>
            </div>
            <div>
              <p className="mb-2 text-[11px] font-black uppercase tracking-[.14em] text-slate-500">Status kelas</p>
              <div className="grid grid-cols-2 gap-1.5 rounded-2xl bg-slate-100 p-1.5">
                {([['active', 'Aktif'], ['history', 'Riwayat']] as const).map(([scope, label]) => <button key={scope} type="button" onClick={() => void openClassScope(scope)} aria-pressed={classScope === scope} className={`relative min-h-11 rounded-xl px-3 text-sm font-black transition ${classScope === scope ? "bg-white text-indigo-700 shadow-sm" : "text-slate-500 hover:bg-white/70"}`}>{label}{unreadIdsForTeacherClassScope(scope, classAttention).length > 0 && <span aria-label={`Ada pembaruan baru di ${label}`} className="absolute right-3 top-2.5 h-2.5 w-2.5 rounded-full bg-rose-500 ring-2 ring-white" />}</button>)}
              </div>
            </div>
          </div>
        </section>
        <div className={classKind === "group" ? "hidden" : "contents"}>

        {classKind === "private" && <section data-tour="teacher-class-steps" className="grid grid-cols-2 gap-2 sm:grid-cols-5">
          {["Siap mulai", "Kehadiran", "Selesaikan belajar", "Hasil belajar", "Konfirmasi murid"].map((label, index) => <div key={label} className="rounded-2xl border border-slate-100 bg-white p-3 shadow-sm"><span className="grid h-7 w-7 place-items-center rounded-lg bg-indigo-50 text-xs font-black text-indigo-700">{index + 1}</span><p className="mt-2 text-xs font-black leading-5 text-slate-700">{label}</p></div>)}
        </section>}

        {classKind === "all" && <div className="my-10 flex items-center gap-4"><span className="h-px flex-1 bg-slate-200" /><h2 className="text-sm font-black uppercase tracking-[.16em] text-slate-500">Kelas Privat</h2><span className="h-px flex-1 bg-slate-200" /></div>}

        {!loadError && classes.length > 0 && (
          <section className="rounded-[1.7rem] border border-slate-100 bg-white p-4 shadow-sm sm:p-5">
            <div className="flex items-center justify-between gap-3">
              <div className="flex min-w-0 items-center gap-2">
                <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-indigo-50 text-indigo-600">
                  <Filter size={17} />
                </span>
                <div className="min-w-0">
                  <p className="font-black text-slate-900">{classKind === "all" ? "Filter kelas privat" : "Filter kelas"}</p>
                  <p className="truncate text-xs text-slate-500">{visibleClasses.length} dari {classScope === "active" ? activeClasses.length : historyClasses.length} kelas {classScope === "active" ? "aktif" : "riwayat"} ditampilkan</p>
                </div>
              </div>
            </div>

            <div className="mt-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_230px] sm:items-end">
              <div>
                <p className="mb-2 text-xs font-black uppercase tracking-widest text-slate-500">Metode belajar</p>
                <div className="grid grid-cols-3 gap-1.5 rounded-2xl bg-slate-100 p-1.5">
                  {([
                    { value: "all", label: "Semua" },
                    { value: "online", label: "Online" },
                    { value: "offline", label: "Offline" },
                  ] as const).map((option) => {
                    const active = methodFilter === option.value;
                    return (
                      <button
                        key={option.value}
                        type="button"
                        onClick={() => setMethodFilter(option.value)}
                        aria-pressed={active}
                        className={`min-h-10 rounded-xl px-2 text-xs font-black transition sm:text-sm ${active ? "bg-white text-indigo-700 shadow-sm" : "text-slate-500 hover:bg-white/70 hover:text-slate-800"}`}
                      >
                        {option.label}
                      </button>
                    );
                  })}
                </div>
              </div>

              <div>
                <Label className="mb-2 flex items-center gap-2 text-xs font-black uppercase tracking-widest text-slate-500">
                  <ArrowUpDown size={14} /> Urutan jadwal
                </Label>
                <Select value={scheduleSort} onValueChange={(value) => setScheduleSort(value as ScheduleSort)}>
                  <SelectTrigger className="h-12 w-full rounded-xl border-slate-200 bg-white text-left font-bold">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent align="end" className="max-w-[calc(100vw-2rem)]">
                    <SelectItem value="nearest">Jadwal paling dekat</SelectItem>
                    <SelectItem value="farthest">Jadwal paling jauh</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
          </section>
        )}

        {loading ? (
          <div role="status" aria-live="polite" className="grid min-h-56 place-items-center rounded-[2rem] border border-slate-100 bg-white shadow-sm">
            <div className="text-center"><Loader2 className="mx-auto h-9 w-9 animate-spin text-indigo-600" /><p className="mt-3 text-sm font-bold text-slate-500">Memuat kelas tutor…</p></div>
          </div>
        ) : loadError ? (
          <div className="rounded-[2rem] border border-rose-100 bg-white px-5 py-12 text-center shadow-sm">
            <AlertTriangle className="mx-auto h-11 w-11 text-rose-400" />
            <p className="mt-4 font-black text-slate-800">Kelas belum dapat dimuat</p>
            <p className="mx-auto mt-2 max-w-lg text-sm leading-6 text-slate-500">{loadError}</p>
            <Button onClick={() => void loadClasses()} className="mt-5 rounded-xl bg-indigo-600 hover:bg-indigo-700"><RefreshCw size={16} className="mr-2" />Coba lagi</Button>
          </div>
        ) : classes.length === 0 ? (
          <div className="rounded-[2rem] border-2 border-dashed border-slate-200 bg-white py-20 text-center"><BookOpen className="mx-auto h-11 w-11 text-slate-300" /><p className="mt-4 font-black text-slate-800">Belum ada kelas privat terkonfirmasi</p><p className="mt-1 text-sm text-slate-500">Permintaan baru tersedia pada menu Permintaan Bimbel.</p></div>
        ) : visibleClasses.length === 0 ? (
          <div className="rounded-[2rem] border-2 border-dashed border-slate-200 bg-white px-5 py-14 text-center">
            <Filter className="mx-auto h-10 w-10 text-slate-300" />
            <p className="mt-4 font-black text-slate-800">
              {classScope === "history"
                ? "Riwayat kelas masih kosong"
                : methodFilter === "all"
                  ? "Belum ada jadwal kelas aktif"
                  : `Belum ada jadwal kelas ${methodFilter}`}
            </p>
            <p className="mt-1 text-sm text-slate-500">
              {classScope === "history"
                ? "Kelas yang sudah selesai atau direfund akan tersimpan di sini."
                : methodFilter === "all"
                  ? "Jadwal akan muncul di sini setelah kelas dengan murid dikonfirmasi."
                  : "Belum ada jadwal dengan metode ini. Coba tampilkan semua metode."}
            </p>
            {methodFilter !== "all" && <Button type="button" variant="outline" className="mt-5 rounded-xl" onClick={() => setMethodFilter("all")}>Tampilkan semua metode</Button>}
          </div>
        ) : (
          <div data-tour="teacher-class-list" className="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
            {visibleClasses.map((item) => (
              <article key={item.id} className="render-auto flex min-w-0 max-w-full flex-col overflow-hidden rounded-[1.75rem] border border-slate-100 bg-white p-5 shadow-sm transition hover-rise hover-shadow-xl sm:rounded-[2rem] sm:p-6">
                <div className="flex items-start justify-between gap-3">
                  <div className={`grid h-12 w-12 shrink-0 place-items-center rounded-2xl ${item.method === "online" ? "bg-indigo-50 text-indigo-600" : "bg-emerald-50 text-emerald-600"}`}>
                    {item.method === "online" ? <Monitor /> : <MapPin />}
                  </div>
                  <div className="flex min-w-0 flex-wrap justify-end gap-2">
                    <span className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-[11px] font-black ${item.method === "online" ? "bg-indigo-50 text-indigo-700" : "bg-emerald-50 text-emerald-700"}`}>
                      {item.method === "online" ? <Monitor size={13} /> : <MapPin size={13} />}
                      {item.method === "online" ? "Online" : "Offline"}
                    </span>
                    <span className="rounded-full bg-slate-100 px-3 py-1.5 text-[11px] font-bold text-slate-700">{statusLabels[item.status] || item.status}</span>
                  </div>
                </div>
                <p className="mt-5 min-w-0 break-words text-xs font-black uppercase tracking-[.14em] text-indigo-500 sm:tracking-widest">{item.subject} · Privat</p><h2 className="mt-1 min-w-0 break-words text-lg font-black leading-snug text-slate-900 sm:text-xl">{item.chapter || "Sesi belajar"}</h2>
                <div className="mt-4 space-y-2 text-xs text-slate-600"><Info icon={CalendarDays} text={dateTime(item.start_at)} /><Info icon={Users} text={`${item.participants.length} murid`} /><Info icon={Clock3} text={`${item.duration_hours} jam`} /></div>
                {item.package_progress && <div className="mt-4 rounded-xl bg-slate-50 p-3"><div className="flex items-center justify-between gap-3"><p className="text-[11px] font-black uppercase tracking-wider text-slate-400">Progress materi</p><span className="text-sm font-black text-indigo-700">{item.package_progress.progress_percent}%</span></div><div className="mt-2 h-2 overflow-hidden rounded-full bg-white"><div className="h-full rounded-full bg-indigo-600" style={{ width: `${item.package_progress.progress_percent}%` }} /></div><p className="mt-2 text-[11px] font-bold text-slate-500">{item.package_progress.completed_chapters}/{item.package_progress.total_chapters} Bab selesai</p></div>}
                {item.package_session_progress && <div className="mt-2 flex items-center justify-between gap-3 rounded-xl bg-slate-50 px-3 py-2.5 text-xs font-bold text-slate-600"><span>Sesi selesai</span><span className="text-slate-900">{item.package_session_progress.subject_completed_sessions}/{item.package_session_progress.subject_total_sessions} sesi · {item.package_session_progress.subject_remaining_sessions} tersisa</span></div>}
                {item.completion_steps.check_out && !item.completion_steps.progress && !teacherHistoryStatuses.has(item.status) && (
                  <div className="mt-4 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                    <div className="flex items-start gap-3"><AlertTriangle size={18} className="mt-0.5 shrink-0 text-amber-600" /><div className="min-w-0"><p className="text-[10px] font-black uppercase tracking-[.14em] text-amber-700">Hasil belajar belum diisi</p><p className="mt-1 text-sm font-black text-amber-950">Catat progress pertemuan sekarang</p><p className="mt-1 text-xs leading-5 text-amber-800">Catat materi yang dibahas dan simpan hasil belajar sebelum sesi dapat diselesaikan.</p></div></div>
                    <Button type="button" onClick={() => { setHubReturnClass(item); setHubInitialTab("progress"); setHubBookingId(item.id); }} className="mt-3 min-h-10 w-full rounded-xl bg-amber-500 font-black text-slate-950 hover:bg-amber-600"><BarChart3 size={16} className="mr-2" />Isi Hasil Belajar</Button>
                  </div>
                )}
                {item.can_complete && !teacherHistoryStatuses.has(item.status) && (
                  <div className="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
                    <p className="text-[10px] font-black uppercase tracking-[.14em] text-emerald-700">Administrasi lengkap</p><p className="mt-1 text-sm font-black text-emerald-950">Menunggu keputusan murid</p><p className="mt-1 text-xs leading-5 text-emerald-800">Hasil belajar sudah tersimpan. Murid otomatis diminta mengecek sesi.</p>
                  </div>
                )}
                {item.status === "awaiting_student_approval" && (
                  <div className="mt-4 rounded-2xl border border-amber-200 bg-amber-50 p-4"><p className="text-[10px] font-black uppercase tracking-[.14em] text-amber-700">Menunggu murid</p><p className="mt-1 text-sm font-black text-amber-950">Keputusan sesi sudah diminta</p><p className="mt-1 text-xs leading-5 text-amber-800">Tidak ada tindakan tutor lagi. Murid sedang diminta memilih Sesi Sesuai atau Ada masalah.</p></div>
                )}
                <div className="mt-auto grid gap-3 pt-6 sm:grid-cols-2 [&>*]:min-h-11"><Button variant="outline" onClick={() => { setSelected(item); setMeetingLink(item.meeting_link || ""); }} className="rounded-xl">{teacherHistoryStatuses.has(item.status) ? "Lihat detail" : item.status === "in_progress" && item.completion_steps.attendance && !item.completion_steps.check_out ? "Kelola & selesaikan" : "Kelola sesi"}</Button>{item.package_subject_id ? <Link to={`/guru/progress/package-subject/${item.package_subject_id}`} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-indigo-600 px-3 text-sm font-black text-white hover:bg-indigo-700"><BarChart3 size={16} />Lihat Progress</Link> : <Button type="button" variant="outline" onClick={() => { setHubReturnClass(item); setHubInitialTab("progress"); setHubBookingId(item.id); }} className="rounded-xl border-indigo-200 text-indigo-700"><BarChart3 size={16} className="mr-2" />Progress sesi</Button>}</div>
              </article>
            ))}
          </div>
        )}
        </div>

        {classKind === "all" && <div className="flex items-center gap-3"><span className="h-px flex-1 bg-slate-200" /><h2 className="text-sm font-black uppercase tracking-[.16em] text-slate-500">Kelas Bersama</h2><span className="h-px flex-1 bg-slate-200" /></div>}
        {classKind !== "private" && <div className="pb-6"><TeacherCheapClasses embedded controlledScope={classScope} onScopeChange={setClassScope} refreshToken={groupRefreshToken} /></div>}
      </div>

      <Dialog open={Boolean(selected)} onOpenChange={(open) => { if (!open) { setSelected(null); clearSessionActionQuery(); } }}>
        <DialogContent className="max-h-[92dvh] overflow-y-auto rounded-[2rem] sm:max-w-2xl">
          {selected && <>
            <Button
              type="button"
              variant="ghost"
              onClick={() => setSelected(null)}
              className="w-fit -ml-3 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-indigo-700"
            >
              <ArrowLeft size={16} className="mr-2" />Kembali ke daftar kelas
            </Button>
            <DialogHeader><DialogTitle className="text-2xl">{selected.subject} · {selected.chapter || "Sesi belajar"}</DialogTitle><DialogDescription>{dateTime(selected.start_at)} · Privat</DialogDescription></DialogHeader>
            <div className="grid gap-3 rounded-2xl bg-slate-50 p-4 text-sm sm:grid-cols-2"><Info icon={GraduationCap} text={`${selected.education_level || ""} ${selected.grade || ""}`} /><Info icon={Clock3} text={timeRange(selected.start_at, selected.end_at)} /><Info icon={selected.method === "online" ? Monitor : MapPin} text={selected.method === "online" ? "Online" : selected.address || "Alamat murid"} /><Info icon={WalletCards} text={`Komisi admin ${selected.commission_percent}%`} /></div>
            {selected.learning_goal && <div className="rounded-2xl border border-indigo-100 bg-indigo-50 p-4"><p className="text-xs font-black uppercase tracking-widest text-indigo-500">Target atau materi yang ingin difokuskan</p><p className="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-indigo-900">{selected.learning_goal}</p></div>}
            {selected.method === "online" ? (["confirmed", "in_progress"].includes(selected.status) ? <div><Label className="font-bold">Tautan Google Meet/Zoom</Label><div className="mt-2 flex gap-2"><Input type="url" className="h-11 rounded-xl" value={meetingLink} onChange={(event) => setMeetingLink(event.target.value)} placeholder="https://..." /><Button aria-label="Simpan tautan kelas" onClick={() => saveMeetingLink(selected)} disabled={processing} className="rounded-xl bg-indigo-600"><Link2 size={16} /></Button></div></div> : <div className="rounded-xl border border-slate-100 bg-slate-50 p-3 text-sm text-slate-600"><p className="font-black text-slate-800">Tautan sesi</p>{selected.meeting_link ? <a href={selected.meeting_link} target="_blank" rel="noreferrer" className="mt-2 inline-flex items-center gap-1 font-bold text-indigo-700 underline">Buka tautan kelas <ExternalLink size={13} /></a> : <p className="mt-1 text-xs">Tidak ada tautan tersimpan.</p>}</div>) : selected.maps_link ? <Button asChild className="rounded-xl bg-emerald-600 hover:bg-emerald-700"><a href={selected.maps_link} target="_blank" rel="noreferrer"><MapPin size={16} className="mr-2" />Buka lokasi murid</a></Button> : <div className="rounded-xl border border-amber-100 bg-amber-50 p-3 text-sm text-amber-800">Alamat lengkap dibuka setelah pembayaran dikonfirmasi.</div>}
            <div><p className="text-sm font-black text-slate-800">Peserta</p><div className="mt-2 space-y-2">{selected.participants.length ? selected.participants.map((participant) => <div key={participant.id} className="flex items-center justify-between rounded-xl border border-slate-100 p-3 text-sm"><div><p className="font-bold text-slate-800">{participant.name}</p><p className="text-xs text-slate-400">{participant.status}</p></div><span className="font-bold text-slate-600">{rupiah(participant.amount)}</span></div>) : <p className="rounded-xl bg-slate-50 p-3 text-sm text-slate-500">Data peserta belum tersedia.</p>}</div></div>
            {["confirmed", "in_progress", "awaiting_student_approval", "disputed", "absence_review", "admin_review_required", "completed"].includes(selected.status) && <div className="grid gap-2 sm:grid-cols-2"><Button variant="outline" className={`rounded-xl ${selected.status === "in_progress" && selected.completion_steps.attendance && !selected.completion_steps.check_out ? "border-orange-300 bg-orange-50 text-orange-800" : "border-indigo-200 text-indigo-700"}`} onClick={() => { setHubReturnClass(selected); setHubInitialTab("session"); setHubBookingId(selected.id); setSelected(null); }}><MessageCircle size={16} className="mr-2" />{selected.status === "in_progress" && selected.completion_steps.attendance && !selected.completion_steps.check_out ? "Buka & selesaikan belajar" : "Buka ruang belajar"}</Button>{selected.package_subject_id && <Button asChild variant="outline" className="rounded-xl border-violet-200 text-violet-700"><Link to={`/guru/progress/package-subject/${selected.package_subject_id}`}><BarChart3 size={16} className="mr-2" />Lihat Progress Kelas</Link></Button>}</div>}
            {["confirmed", "in_progress", "awaiting_student_approval", "completed"].includes(selected.status) && <CompletionGuide item={selected} onOpenProgress={() => { setHubReturnClass(selected); setHubInitialTab("progress"); setHubBookingId(selected.id); setSelected(null); }} />}
            {selected.latest_report && <div className="flex gap-3 rounded-2xl border border-amber-100 bg-amber-50 p-4 text-sm text-amber-900"><FileWarning className="shrink-0" /><div><p className="font-black">Laporan {selected.latest_report.status}</p><p className="mt-1 line-clamp-3 leading-6">{selected.latest_report.chronology}</p></div></div>}
            {selected.latest_dispute && <div className="flex gap-3 rounded-2xl border border-rose-100 bg-rose-50 p-4 text-sm text-rose-900"><ShieldAlert className="shrink-0" /><div><p className="font-black">Keberatan murid {selected.latest_dispute.status}</p><p className="mt-1 leading-6">{selected.latest_dispute.reason}</p></div></div>}
            <div className="grid gap-2 sm:grid-cols-2">{selected.can_report_absence && <Button variant="outline" onClick={() => openAction("absence", selected)} className="rounded-xl border-amber-200 text-amber-700"><UserRoundX size={16} className="mr-2" />Murid absen</Button>}{selected.can_report_emergency && <Button variant="outline" onClick={() => openAction("emergency", selected)} className="rounded-xl border-rose-200 text-rose-700"><AlertTriangle size={16} className="mr-2" />Darurat</Button>}</div>
            {selected.status === "completed" && <div className="rounded-2xl border border-emerald-100 bg-emerald-50 p-4 text-sm text-emerald-800">Pendapatan bersih {rupiah(selected.teacher_net_amount)} · status pencairan: {selected.payout_status}.</div>}
          </>}
        </DialogContent>
      </Dialog>

      <Dialog open={Boolean(action)} onOpenChange={(open) => { if (!open) { setAction(null); clearSessionActionQuery(); } }}>
        <DialogContent className="max-h-[92dvh] overflow-y-auto rounded-[2rem] sm:max-w-lg">
          {action && <>
            <DialogHeader><DialogTitle>{action.type === "absence" ? "Laporkan murid tidak hadir" : "Laporkan keadaan darurat"}</DialogTitle><DialogDescription>{action.type === "absence" ? "Laporan dapat diajukan setelah keterlambatan lebih dari 15 menit dan akan diperiksa admin." : "Refund penuh langsung masuk antrean. Bukti dan kronologi akan diperiksa admin."}</DialogDescription></DialogHeader>
            <form onSubmit={submitAction} className="space-y-4">
              {action.type === "emergency" && <><div><Label>Jenis keadaan</Label><Input required maxLength={120} className="mt-2 rounded-xl" value={incidentType} onChange={(event) => setIncidentType(event.target.value)} placeholder="Contoh: kecelakaan dalam perjalanan" /></div><div><Label>Waktu kejadian</Label><Input required type="datetime-local" className="mt-2 rounded-xl" value={incidentAt} onChange={(event) => setIncidentAt(event.target.value)} /></div><div><Label>Lokasi kejadian</Label><Input required maxLength={500} className="mt-2 rounded-xl" value={incidentLocation} onChange={(event) => setIncidentLocation(event.target.value)} /></div><div><Label>Dampak terhadap sesi</Label><Textarea required minLength={20} maxLength={1500} className="mt-2 min-h-24 rounded-xl" value={impact} onChange={(event) => setImpact(event.target.value)} /></div></>}
              <div><Label>Kronologi lengkap</Label><Textarea required minLength={action.type === "absence" ? 30 : 50} maxLength={action.type === "emergency" ? 3000 : 2500} className="mt-2 min-h-36 rounded-xl" value={notes} onChange={(event) => setNotes(event.target.value)} placeholder="Tuliskan kejadian dengan jelas dan masuk akal" /></div>
              <div><Label>Bukti yang dapat dipercaya</Label><Input required className="mt-2" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" onChange={(event) => selectEvidence(event.target.files?.[0])} /></div>
              <Button className={`w-full rounded-xl ${action.type === "emergency" ? "bg-rose-600 hover:bg-rose-700" : "bg-indigo-600 hover:bg-indigo-700"}`} disabled={processing}>{processing && <Loader2 size={16} className="mr-2 animate-spin" />}Kirim</Button>
            </form>
          </>}
        </DialogContent>
      </Dialog>
      {hubBookingId !== null && (
        <Suspense fallback={null}>
          <LearningSessionHub
            bookingId={hubBookingId}
            open
            onOpenChange={(open) => {
              if (!open) {
                setHubBookingId(null);
                setHubReturnClass(null);
                clearSessionActionQuery();
                void loadClasses();
              }
            }}
            initialTab={hubInitialTab}
            backLabel={hubReturnClass ? "Kembali ke detail kelas" : undefined}
            onBack={hubReturnClass ? () => {
              const returnClass = hubReturnClass;
              setHubBookingId(null);
              setSelected(returnClass);
              setMeetingLink(returnClass.meeting_link || "");
              setHubReturnClass(null);
              setHubInitialTab("session");
              void loadClasses();
            } : undefined}
          />
        </Suspense>
      )}
    </TeacherLayout>
  );
}

function CompletionGuide({ item, onOpenProgress }: { item: TeacherClass; onOpenProgress: () => void }) {
  const steps = item.completion_steps;
  const confirmationDone = ["awaiting_student_approval", "completed"].includes(item.status);
  const rows = [
    { label: "Murid sudah mengonfirmasi hadir", done: confirmationDone || steps.check_in },
    { label: "Sesi sudah diakhiri", done: confirmationDone || steps.check_out },
    { label: "Hasil belajar tersimpan", done: confirmationDone || steps.progress },
    { label: "Keputusan murid", done: item.status === "completed" },
  ];
  const canOpenProgress = steps.check_out && !confirmationDone;
  return <section className="rounded-2xl border border-indigo-100 bg-indigo-50/60 p-4 sm:p-5"><div><p className="text-sm font-black text-indigo-950">Penyelesaian sesi</p><p className="mt-1 text-xs leading-5 text-indigo-700">Sesudah belajar, catat waktu selesai di Ruang Belajar lalu isi hasil belajar. BimbelKu otomatis meminta keputusan murid.</p></div><div className="mt-4 space-y-2">{rows.map((row, index) => <div key={row.label} className="flex items-center gap-3 rounded-xl bg-white px-3 py-2.5"><span className={`grid h-7 w-7 shrink-0 place-items-center rounded-lg text-xs font-black ${row.done ? "bg-emerald-100 text-emerald-700" : "bg-slate-100 text-slate-400"}`}>{row.done ? <CheckCircle2 size={15} /> : index + 1}</span><span className={`text-sm font-bold ${row.done ? "text-slate-700" : "text-slate-500"}`}>{row.label}</span></div>)}</div>{!confirmationDone && <Button type="button" variant="outline" onClick={onOpenProgress} disabled={!canOpenProgress} className="mt-4 w-full rounded-xl border-indigo-200 bg-white text-indigo-700"><BarChart3 size={16} className="mr-2" />{steps.progress ? "Lihat Hasil Belajar" : "Isi Hasil Belajar"}</Button>}{!steps.check_out && !confirmationDone && <p className="mt-3 text-xs font-semibold text-slate-500">Hasil belajar dapat diisi setelah waktu selesai dicatat. Gunakan tombol Ruang Belajar di atas saat pembelajaran berakhir.</p>}{confirmationDone && <p className="mt-3 rounded-xl bg-white p-3 text-xs font-bold text-emerald-700">{item.status === "completed" ? "Sesi sudah final." : "Semua langkah Tutor sudah selesai. Menunggu keputusan murid."}</p>}</section>;
}

function Info({ icon: Icon, text }: { icon: typeof Clock3; text: string }) {
  return <div className="flex min-w-0 items-start gap-2 rounded-xl bg-white/70 px-3 py-2.5"><Icon size={15} className="mt-0.5 shrink-0 text-indigo-500" /><span className="min-w-0 break-words leading-5">{text || "-"}</span></div>;
}
