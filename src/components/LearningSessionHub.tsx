import { notify } from "@/lib/notify";
import { FormEvent, useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  ArrowLeft,
  BarChart3,
  BookOpen,
  CalendarClock,
  CheckCircle2,
  ClipboardCheck,
  Loader2,
  MessageCircle,
  RefreshCw,
  Send,
  UserCheck,
  Video,
  X,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import http, { getApiError } from "@/lib/http";

export type LearningSessionHubTab = "session" | "chat" | "progress";

type HubMessage = {
  id: number;
  body: string;
  sender_name: string;
  sender_role: "student" | "teacher" | "system";
  message_type?: "user" | "system";
  is_mine: boolean;
  created_at: string;
};

type ProgressReport = {
  id: number;
  no_material_change?: boolean;
  no_change_reason?: string | null;
  student_name?: string;
  session_number: number;
  material_covered: string;
  mastered_skills: string;
  difficulties?: string | null;
  next_exercise: string;
  actual_duration_minutes: number;
  progress_percent: number;
  published_at: string;
  chapters?: Array<{
    chapter: string;
    activity_type: "taught" | "continued" | "reviewed" | "mixed";
    status_before: string;
    status_after: string;
    needs_review: boolean;
    notes?: string | null;
  }>;
};

type Attendance = {
  id: number;
  check_in_at: string;
  check_out_at?: string | null;
};

type ScheduleChange = {
  id: number;
  requester_name: string;
  requester_role: string;
  scope: "single" | "future";
  affected_count: number;
  original_start_at: string;
  original_end_at: string;
  proposed_start_at: string;
  proposed_end_at: string;
  reason: string;
  status: string;
  expires_at?: string | null;
  my_decision?: string | null;
  can_respond: boolean;
};

type LearningChapterItem = {
  chapter: string;
  curriculum_chapter_id?: number | null;
  status: "not_started" | "in_progress" | "completed" | "review_needed";
  needs_review?: boolean;
  session_status?: "in_progress" | "completed" | null;
  session_activity?: "taught" | "continued" | "reviewed" | "mixed" | null;
  session_needs_review?: boolean | null;
  session_notes?: string | null;
};

type ScheduleOption = {
  start_at: string;
  end_at: string;
  label: string;
  scope: "single" | "future";
  affected_count: number;
};

type ChapterUpdateState = {
  selected: boolean;
  activity_type: "taught" | "continued" | "reviewed";
  status_after: "in_progress" | "completed";
  needs_review: boolean;
  notes: string;
};

type HubData = {
  role: "student" | "teacher" | "admin";
  booking: {
    id: number;
    subject: string;
    education_level?: string;
    grade?: string;
    chapter?: string;
    requested_goal?: string;
    teacher_name: string;
    student_name: string;
    learning_mode: "online" | "offline";
    class_type: "private";
    status: string;
    start_at: string;
    end_at: string;
    session_started_at?: string | null;
    session_ended_at?: string | null;
    tutor_ready_at?: string | null;
    student_confirmed_at?: string | null;
    session_focus_note?: string | null;
    meeting_link?: string | null;
  };
  messages: HubMessage[];
  progress_reports: ProgressReport[];
  attendance?: Attendance | null;
  schedule_changes: ScheduleChange[];
  learning_chapters: LearningChapterItem[];
  material_progress_percent: number;
  permissions: {
    can_chat: boolean;
    can_mark_ready: boolean;
    can_confirm_presence: boolean;
    can_check_out: boolean;
    can_report_progress: boolean;
    can_request_schedule_change: boolean;
    can_request_future_schedule: boolean;
  };
};

type Props = {
  bookingId: number | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  initialTab?: LearningSessionHubTab;
  backLabel?: string;
  onBack?: () => void;
};

const dateTime = (value?: string | null) => value
  ? new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short" }).format(new Date(value))
  : "-";

const noChangeReasonLabel = (value?: string | null) => ({
  review: "Review materi",
  practice: "Latihan soal",
  evaluation: "Evaluasi / ujian",
  remedial: "Remedial",
  session_disrupted: "Sesi tidak berjalan penuh",
  other: "Lainnya",
}[value || ""] || "Tidak ada perubahan materi");

export default function LearningSessionHub({ bookingId, open, onOpenChange, initialTab = "session", backLabel, onBack }: Props) {
  const [hub, setHub] = useState<HubData | null>(null);
  const [loading, setLoading] = useState(false);
  const [processing, setProcessing] = useState(false);
  const [confirmCheckout, setConfirmCheckout] = useState(false);
  const [tab, setTab] = useState<LearningSessionHubTab>(initialTab);
  const [message, setMessage] = useState("");
  const [focusNote, setFocusNote] = useState("");
  const [chapterUpdates, setChapterUpdates] = useState<Record<string, ChapterUpdateState>>({});
  const [scheduleScope, setScheduleScope] = useState<"single" | "future">("single");
  const [scheduleDate, setScheduleDate] = useState("");
  const [scheduleOptions, setScheduleOptions] = useState<ScheduleOption[]>([]);
  const [proposedStartAt, setProposedStartAt] = useState("");
  const [loadingScheduleOptions, setLoadingScheduleOptions] = useState(false);
  const [scheduleReason, setScheduleReason] = useState("");
  const [scheduleResponseNotes, setScheduleResponseNotes] = useState<Record<number, string>>({});
  const [reportForm, setReportForm] = useState({
    material_covered: "",
    mastered_skills: "",
    next_exercise: "",
    progress_percent: "0",
    no_material_change: false,
    no_change_reason: "",
  });
  const chatScrollRef = useRef<HTMLDivElement | null>(null);
  const chatPinnedToBottom = useRef(true);
  const lastChatMessageId = useRef(0);

  const load = useCallback(async (quiet = false) => {
    if (!bookingId) return;
    if (!quiet) setLoading(true);
    try {
      const response = await http.get<HubData>(`/bookings/${bookingId}/learning-session`);
      setHub(response.data);
      if (!quiet) {
        setFocusNote(response.data.booking.session_focus_note || "");
        const loaded: Record<string, ChapterUpdateState> = {};
        for (const chapter of response.data.learning_chapters || []) {
          loaded[chapter.chapter] = {
            selected: Boolean(chapter.session_status),
            activity_type: chapter.session_activity === "mixed"
              ? "continued"
              : (chapter.session_activity || (chapter.status === "not_started" ? "taught" : "continued")),
            status_after: chapter.session_status || (chapter.status === "completed" ? "completed" : "in_progress"),
            needs_review: Boolean(chapter.session_needs_review ?? chapter.needs_review),
            notes: chapter.session_notes || "",
          };
        }
        setChapterUpdates(loaded);
        setReportForm((current) => ({ ...current, progress_percent: String(response.data.material_progress_percent || 0) }));
        setScheduleOptions([]);
        setProposedStartAt("");
      }
    } catch (error) {
      if (!quiet) notify.error(getApiError(error, "Ruang belajar gagal dimuat."));
    } finally {
      if (!quiet) setLoading(false);
    }
  }, [bookingId]);

  useEffect(() => {
    if (!open || !bookingId) return;
    setTab(initialTab);
    lastChatMessageId.current = 0;
    chatPinnedToBottom.current = true;
    void load();
  }, [open, bookingId, initialTab, load]);

  useEffect(() => {
    if (!open || !bookingId) return;
    // Chat lebih sering diperbarui agar pesan masuk terasa langsung, tanpa
    // mengganggu isian form di tab lain dengan refresh yang terlalu agresif.
    const intervalMs = tab === "chat" ? 5_000 : 15_000;
    const timer = window.setInterval(() => {
      if (document.visibilityState === "visible") void load(true);
    }, intervalMs);
    return () => window.clearInterval(timer);
  }, [open, bookingId, tab, load]);

  useEffect(() => {
    if (tab !== "chat") return;
    const node = chatScrollRef.current;
    const latestId = hub?.messages[hub.messages.length - 1]?.id ?? 0;
    if (!node || !latestId || latestId === lastChatMessageId.current) return;
    const firstBatch = lastChatMessageId.current === 0;
    lastChatMessageId.current = latestId;
    if (!firstBatch && !chatPinnedToBottom.current) return;
    const frame = window.requestAnimationFrame(() => {
      node.scrollTop = node.scrollHeight;
    });
    return () => window.cancelAnimationFrame(frame);
  }, [tab, hub?.messages]);

  const handleChatScroll = () => {
    const node = chatScrollRef.current;
    if (!node) return;
    chatPinnedToBottom.current = node.scrollHeight - node.scrollTop - node.clientHeight < 96;
  };

  const getLocation = () => new Promise<GeolocationPosition>((resolve, reject) => {
    if (!navigator.geolocation) return reject(new Error("Perangkat tidak mendukung lokasi."));
    navigator.geolocation.getCurrentPosition(resolve, reject, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
  });

  const markReady = async () => {
    if (!bookingId || !hub) return;
    setProcessing(true);
    try {
      let locationPayload = {};
      if (hub.booking.learning_mode === "offline") {
        const position = await getLocation();
        locationPayload = {
          latitude: position.coords.latitude,
          longitude: position.coords.longitude,
          accuracy_meters: Math.round(position.coords.accuracy),
        };
      }
      const response = await http.post(`/teacher/bookings/${bookingId}/ready`, { focus_note: focusNote.trim() || null, ...locationPayload });
      notify.success(response.data.message);
      await load();
    } catch (error) {
      const locationError = typeof error === "object" && error !== null && "code" in error && !((error as { response?: unknown }).response);
      notify.error(locationError ? "Izinkan lokasi perangkat untuk sesi offline." : getApiError(error));
    } finally {
      setProcessing(false);
    }
  };

  const confirmPresence = async () => {
    if (!bookingId) return;
    setProcessing(true);
    try {
      const response = await http.post(`/student/bookings/${bookingId}/presence-confirm`);
      notify.success(response.data.message);
      await load();
    } catch (error) {
      notify.error(getApiError(error));
    } finally {
      setProcessing(false);
    }
  };

  const checkOut = async () => {
    if (!bookingId) return;
    setProcessing(true);
    try {
      const response = await http.post(`/teacher/bookings/${bookingId}/check-out`);
      notify.success(response.data.message || "Sesi diakhiri. Isi hasil belajar singkat untuk murid.");
      await load();
      setTab("progress");
    } catch (error) {
      notify.error(getApiError(error));
    } finally {
      setProcessing(false);
    }
  };

  const sendMessage = async (event: FormEvent) => {
    event.preventDefault();
    if (!bookingId || !message.trim()) return;
    setProcessing(true);
    try {
      await http.post(`/bookings/${bookingId}/messages`, { body: message.trim() });
      setMessage("");
      chatPinnedToBottom.current = true;
      await load(true);
    } catch (error) {
      notify.error(getApiError(error));
    } finally {
      setProcessing(false);
    }
  };

  const loadScheduleOptions = async () => {
    if (!bookingId) return;
    setLoadingScheduleOptions(true);
    setProposedStartAt("");
    try {
      const response = await http.get<{ data: ScheduleOption[]; message: string }>(`/bookings/${bookingId}/schedule-options`, {
        params: { scope: scheduleScope, date: scheduleDate || undefined },
      });
      setScheduleOptions(response.data.data || []);
      if (!(response.data.data || []).length) notify.error(response.data.message);
    } catch (error) {
      setScheduleOptions([]);
      notify.error(getApiError(error, "Pilihan jadwal tutor gagal dimuat."));
    } finally {
      setLoadingScheduleOptions(false);
    }
  };

  const requestScheduleChange = async (event: FormEvent) => {
    event.preventDefault();
    if (!bookingId || !proposedStartAt) return notify.error("Pilih salah satu slot tutor yang tersedia.");
    setProcessing(true);
    try {
      const response = await http.post(`/bookings/${bookingId}/schedule-changes`, {
        proposed_start_at: proposedStartAt,
        scope: scheduleScope,
        reason: scheduleReason.trim(),
      });
      notify.success(response.data.message);
      setProposedStartAt("");
      setScheduleOptions([]);
      setScheduleDate("");
      setScheduleReason("");
      await load();
    } catch (error) {
      notify.error(getApiError(error, "Perubahan jadwal gagal diajukan."));
    } finally {
      setProcessing(false);
    }
  };

  const respondScheduleChange = async (changeId: number, decision: "approved" | "rejected") => {
    if (!bookingId) return;
    setProcessing(true);
    try {
      const response = await http.post(`/bookings/${bookingId}/schedule-changes/${changeId}/respond`, {
        decision,
        notes: decision === "rejected" ? scheduleResponseNotes[changeId]?.trim() : null,
      });
      notify.success(response.data.message);
      await load();
    } catch (error) {
      notify.error(getApiError(error, "Jawaban perubahan jadwal gagal disimpan."));
    } finally {
      setProcessing(false);
    }
  };

  const saveProgress = async (event: FormEvent) => {
    event.preventDefault();
    if (!bookingId || !hub) return;
    const selectedChapters = Object.entries(chapterUpdates)
      .filter(([, value]) => value.selected)
      .map(([chapter, value]) => ({
        chapter,
        activity_type: value.activity_type,
        status_after: value.status_after,
        needs_review: value.needs_review,
        notes: value.notes.trim() || null,
      }));
    const automaticMaterial = selectedChapters.map((item) => item.chapter).join(", ")
      || hub.booking.chapter
      || hub.booking.session_focus_note
      || hub.booking.subject;
    setProcessing(true);
    try {
      const response = await http.post(`/teacher/bookings/${bookingId}/progress-reports`, {
        material_covered: hub.learning_chapters.length ? `Pembahasan ${automaticMaterial}` : reportForm.material_covered.trim(),
        mastered_skills: reportForm.mastered_skills.trim(),
        difficulties: null,
        next_exercise: reportForm.next_exercise.trim() || (hub.learning_chapters.length ? "Lanjutkan latihan sesuai progres Bab pada sesi berikutnya." : "Lanjutkan latihan sesuai hasil belajar pada sesi berikutnya."),
        notes: reportForm.mastered_skills.trim(),
        progress_percent: hub.learning_chapters.length ? undefined : Number(reportForm.progress_percent),
        no_material_change: hub.learning_chapters.length > 0 && reportForm.no_material_change,
        no_change_reason: hub.learning_chapters.length > 0 && reportForm.no_material_change ? reportForm.no_change_reason : null,
        chapter_updates: reportForm.no_material_change ? [] : selectedChapters,
      });
      notify.success(response.data.message);
      setReportForm((current) => ({ ...current, material_covered: "", mastered_skills: "", next_exercise: "", no_material_change: false, no_change_reason: "" }));
      await load();
    } catch (error) {
      notify.error(getApiError(error));
    } finally {
      setProcessing(false);
    }
  };

  const waitingForStudent = Boolean(hub?.booking.tutor_ready_at && !hub?.booking.student_confirmed_at);
  const sessionLive = Boolean(hub?.booking.student_confirmed_at && hub?.attendance && !hub.attendance.check_out_at);
  const reportSaved = Boolean(hub?.progress_reports.length);
  const statusLabel = useMemo(() => {
    if (!hub) return "";
    if (hub.booking.status === "awaiting_student_approval") return "Menunggu konfirmasi murid";
    if (reportSaved) return "Hasil belajar tersimpan";
    if (sessionLive) return "Sesi sedang berjalan";
    if (waitingForStudent) return "Menunggu murid";
    return "Siap dijadwalkan";
  }, [hub, reportSaved, sessionLive, waitingForStudent]);

  return (
    <>
      <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent layer="detail" hideCloseButton className="max-h-[calc(100dvh-0.5rem)] w-[calc(100vw-0.5rem)] max-w-5xl overflow-x-hidden overflow-y-auto rounded-[1.35rem] p-3 sm:max-h-[92dvh] sm:w-[calc(100vw-2rem)] sm:rounded-[2rem] sm:p-6">
        <DialogHeader>
          <div className="grid grid-cols-[2.5rem_minmax(0,1fr)_2.5rem] items-start gap-2">
            <Button type="button" variant="ghost" size="icon" className="h-10 w-10 rounded-xl" onClick={onBack ?? (() => onOpenChange(false))} aria-label={backLabel || "Kembali"}><ArrowLeft size={18} /></Button>
            <div className="min-w-0 pt-1">
              <DialogTitle className="px-0">Ruang Belajar</DialogTitle>
              <DialogDescription className="mt-1 text-center">Mulai sesi dengan konfirmasi hadir satu tap. Administrasi detail disimpan otomatis di belakang layar.</DialogDescription>
            </div>
            <Button type="button" variant="ghost" size="icon" className="h-10 w-10 rounded-xl" onClick={() => onOpenChange(false)} aria-label="Tutup Ruang Belajar"><X size={18} /></Button>
          </div>
        </DialogHeader>

        {loading || !hub ? (
          <div className="grid min-h-72 place-items-center"><Loader2 className="animate-spin text-indigo-600" /></div>
        ) : (
          <div className="space-y-5">
            <section className="relative overflow-hidden rounded-[1.6rem] bg-[#102b3f] p-5 text-white sm:p-7">
              <div className="pointer-events-none absolute -right-16 -top-20 h-56 w-56 rounded-full border border-white/10" />
              <div className="pointer-events-none absolute -right-4 -top-8 h-36 w-36 rounded-full border border-[#f6ba89]/20" />
              <div className="relative flex flex-wrap items-start justify-between gap-3">
                <div className="flex min-w-0 items-start gap-3">
                  <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-[#f6ba89] text-[#102b3f]"><BookOpen size={22} /></span>
                  <div className="min-w-0"><p className="text-xs font-black uppercase tracking-[.16em] text-[#f6ba89]">{hub.booking.subject} · Privat</p><h3 className="mt-1 break-words text-xl font-black leading-tight sm:text-2xl">{hub.booking.chapter || "Sesi belajar"}</h3></div>
                </div>
                <span className="rounded-full border border-white/20 bg-white/10 px-3 py-1.5 text-xs font-black">{statusLabel}</span>
              </div>
              <div className="relative mt-6 grid gap-2 sm:grid-cols-2">
                <div className="flex items-start gap-3 rounded-xl bg-white/[.09] p-3.5"><CalendarClock size={18} className="mt-0.5 shrink-0 text-[#f6ba89]" /><div><p className="text-[11px] font-bold text-slate-300">Jadwal belajar</p><p className="mt-1 text-sm font-bold">{dateTime(hub.booking.start_at)}</p></div></div>
                <div className="flex items-start gap-3 rounded-xl bg-white/[.09] p-3.5"><UserCheck size={18} className="mt-0.5 shrink-0 text-[#f6ba89]" /><div><p className="text-[11px] font-bold text-slate-300">{hub.role === "teacher" ? "Murid" : "Tutor"} · {hub.booking.learning_mode === "online" ? "Online" : "Tatap muka"}</p><p className="mt-1 break-words text-sm font-bold">{hub.role === "teacher" ? hub.booking.student_name : hub.booking.teacher_name}</p></div></div>
              </div>
              {hub.booking.session_focus_note && <div className="relative mt-3 border-l-2 border-[#f6ba89] bg-white/[.07] px-4 py-3 text-sm leading-6"><span className="font-black text-[#f6ba89]">Fokus sesi</span><p className="mt-1 text-slate-100">{hub.booking.session_focus_note}</p></div>}
            </section>

            <div className="grid min-w-0 grid-cols-3 gap-1.5 rounded-2xl bg-slate-100 p-1.5 sm:gap-2 sm:p-2">
              <TabButton active={tab === "session"} onClick={() => setTab("session")} icon={ClipboardCheck} label="Sesi" />
              <TabButton active={tab === "chat"} onClick={() => setTab("chat")} icon={MessageCircle} label="Chat" />
              <TabButton active={tab === "progress"} onClick={() => setTab("progress")} icon={BarChart3} label="Hasil" attention={hub.role === "teacher" && hub.permissions.can_report_progress} />
            </div>

            {tab === "session" && (
              <div className="space-y-4">
                {hub.booking.requested_goal && <section className="rounded-2xl border border-indigo-100 bg-indigo-50 p-4"><h4 className="text-sm font-black text-indigo-950">Target atau materi yang ingin difokuskan</h4><p className="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-indigo-800">{hub.booking.requested_goal}</p></section>}
                {hub.role === "teacher" && hub.permissions.can_mark_ready && (
                  <section className="rounded-2xl border border-indigo-200 bg-indigo-50 p-5">
                    <div className="flex items-center gap-2 font-black text-indigo-950"><UserCheck size={19} />Siap mengajar?</div>
                    <p className="mt-2 text-sm leading-6 text-indigo-800">Tekan sekali saat kamu sudah siap. Murid akan mendapat tombol konfirmasi kehadiran.</p>
                    <Label className="mt-4 block text-xs font-black uppercase tracking-wider text-indigo-900">Fokus hari ini <span className="font-semibold normal-case tracking-normal text-indigo-600">(opsional)</span></Label>
                    <Input value={focusNote} onChange={(event) => setFocusNote(event.target.value)} maxLength={500} placeholder={hub.booking.chapter ? `Contoh: latihan ${hub.booking.chapter}` : "Contoh: latihan soal dan penguatan konsep"} className="mt-2 h-11 rounded-xl bg-white" />
                    <Button type="button" onClick={() => void markReady()} disabled={processing} className="mt-4 w-full rounded-xl bg-indigo-600">Saya Siap Mengajar</Button>
                  </section>
                )}

                {hub.role === "student" && hub.permissions.can_confirm_presence && (
                  <section className="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-center">
                    <CheckCircle2 className="mx-auto text-emerald-600" size={32} />
                    <h4 className="mt-3 text-lg font-black text-emerald-950">{hub.booking.teacher_name} sudah siap</h4>
                    <p className="mx-auto mt-2 max-w-xl text-sm leading-6 text-emerald-800">Kalau kamu sudah bersama tutor atau siap di kelas online, cukup konfirmasi sekali.</p>
                    <Button type="button" onClick={() => void confirmPresence()} disabled={processing} className="mt-4 min-h-12 w-full rounded-xl bg-emerald-600 font-black hover:bg-emerald-700">Saya Sudah Hadir</Button>
                    {hub.booking.learning_mode === "online" && hub.booking.meeting_link && (
                      <>
                        <Button asChild variant="outline" className="mt-2 min-h-11 w-full rounded-xl border-emerald-300 bg-white font-black text-emerald-800 hover:bg-emerald-100">
                          <a href={hub.booking.meeting_link} target="_blank" rel="noreferrer"><Video size={17} className="mr-2" />Masuk Ruang Kelas</a>
                        </Button>
                        <p className="mt-2 text-xs font-semibold leading-5 text-emerald-700">Sudah di ruang kelas? Tetap tekan “Saya Sudah Hadir” agar kehadiranmu tercatat.</p>
                      </>
                    )}
                  </section>
                )}

                {waitingForStudent && hub.role === "teacher" && (
                  <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-800"><b>Menunggu murid.</b> Tidak perlu kode atau form tambahan. Sesi akan aktif setelah murid menekan “Saya Sudah Hadir”.</div>
                )}

                {hub.booking.student_confirmed_at && (
                  <section className="rounded-2xl border border-emerald-200 bg-emerald-50 p-5">
                    <div className="flex items-center gap-2 font-black text-emerald-950"><CheckCircle2 size={19} />Kehadiran sudah terkonfirmasi</div>
                    <p className="mt-2 text-sm leading-6 text-emerald-800">Sesi resmi dimulai {dateTime(hub.booking.session_started_at || hub.booking.student_confirmed_at)}. Selama belajar, BimbelKu tidak meminta langkah administrasi tambahan.</p>
                    {hub.role === "student" && hub.booking.learning_mode === "online" && hub.booking.meeting_link && (
                      <>
                        <Button asChild className="mt-4 min-h-12 w-full rounded-xl bg-emerald-600 font-black hover:bg-emerald-700">
                          <a href={hub.booking.meeting_link} target="_blank" rel="noreferrer"><Video size={18} className="mr-2" />Masuk Ruang Kelas</a>
                        </Button>
                        <p className="mt-2 text-xs font-semibold text-emerald-700">Ruang kelas dibuka di tab baru. Kehadiranmu sudah tercatat.</p>
                      </>
                    )}
                  </section>
                )}

                {hub.role === "teacher" && hub.booking.student_confirmed_at && hub.permissions.can_check_out && (
                  <section className="rounded-2xl border border-[#e8d8c8] bg-[#fff9f2] p-4 sm:p-5">
                    <div className="flex items-start gap-3"><span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-[#fce6d4] text-[#b65a2c]"><ClipboardCheck size={20} /></span><div><h4 className="font-black text-[#102b3f]">Sesudah pembelajaran selesai</h4><p className="mt-1 text-sm leading-6 text-slate-600">Catat waktu selesai, lalu isi hasil belajar murid. Langkah ini tetap tersedia jika Ruang Belajar ditutup dan dibuka lagi dari Kelas Saya.</p></div></div>
                    <Button type="button" onClick={() => setConfirmCheckout(true)} disabled={processing} className="mt-4 min-h-11 w-full rounded-xl bg-[#d45e27] font-black text-white hover:bg-[#b84b1b]">Selesaikan pembelajaran & isi hasil</Button>
                  </section>
                )}

                {hub.booking.status === "awaiting_student_approval" && (
                  <div className="rounded-2xl border border-indigo-200 bg-indigo-50 p-4 text-sm leading-6 text-indigo-800"><b>Hasil belajar sudah tersimpan.</b> Murid akan melihat pilihan “Sesi Sesuai” atau “Ada masalah” dari halaman Kelas Saya.</div>
                )}

                {hub.permissions.can_request_schedule_change && (
                  <section className="rounded-2xl border border-slate-200 bg-white p-4">
                    <div className="flex items-center gap-2 font-black text-slate-900"><CalendarClock size={18} />Ubah jadwal</div>
                    <p className="mt-1 text-xs leading-5 text-slate-500">Hanya slot tutor yang benar-benar tersedia yang akan ditampilkan.</p>
                    <form onSubmit={requestScheduleChange} className="mt-4 space-y-3">
                      <div className="grid gap-3 sm:grid-cols-2">
                        <div><Label>Cakupan</Label><Select value={scheduleScope} onValueChange={(value) => { setScheduleScope(value as "single" | "future"); setScheduleOptions([]); setProposedStartAt(""); }}><SelectTrigger className="mt-2 h-11 rounded-xl"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="single">Sesi ini</SelectItem>{hub.permissions.can_request_future_schedule && <SelectItem value="future">Sesi ini + sesi berikutnya</SelectItem>}</SelectContent></Select></div>
                        <div><Label>Tanggal pilihan</Label><Input type="date" value={scheduleDate} onChange={(event) => { setScheduleDate(event.target.value); setScheduleOptions([]); setProposedStartAt(""); }} className="mt-2 h-11 rounded-xl" /></div>
                      </div>
                      <Button type="button" variant="outline" onClick={() => void loadScheduleOptions()} disabled={loadingScheduleOptions} className="w-full rounded-xl">{loadingScheduleOptions ? <Loader2 size={16} className="mr-2 animate-spin" /> : <CalendarClock size={16} className="mr-2" />}Lihat slot tersedia</Button>
                      {scheduleOptions.length > 0 && <div className="grid gap-2 sm:grid-cols-2">{scheduleOptions.map((option) => <label key={option.start_at} className={`cursor-pointer rounded-xl border p-3 text-sm ${proposedStartAt === option.start_at ? "border-indigo-500 bg-indigo-50" : "border-slate-200 bg-white"}`}><input type="radio" name="schedule-option" className="mr-2" checked={proposedStartAt === option.start_at} onChange={() => setProposedStartAt(option.start_at)} />{option.label}</label>)}</div>}
                      <Textarea required minLength={5} maxLength={1000} value={scheduleReason} onChange={(event) => setScheduleReason(event.target.value)} placeholder="Alasan perubahan jadwal" className="min-h-20 rounded-xl" />
                      <Button disabled={processing || !proposedStartAt || scheduleReason.trim().length < 5} className="w-full rounded-xl bg-indigo-600">Ajukan perubahan</Button>
                    </form>
                  </section>
                )}

                {hub.schedule_changes.length > 0 && <section className="space-y-3"><p className="text-xs font-black uppercase tracking-wider text-slate-400">Perubahan jadwal</p>{hub.schedule_changes.map((change) => <article key={change.id} className="rounded-2xl border border-slate-200 bg-white p-4"><div className="flex flex-wrap items-center justify-between gap-2"><p className="font-black text-slate-900">{change.requester_name} · {change.scope === "future" ? `${change.affected_count} sesi` : "1 sesi"}</p><span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-black text-slate-600">{change.status}</span></div><p className="mt-2 text-sm text-slate-600">{dateTime(change.proposed_start_at)} · {change.reason}</p>{change.can_respond && <div className="mt-3 space-y-2"><Input value={scheduleResponseNotes[change.id] || ""} onChange={(event) => setScheduleResponseNotes((current) => ({ ...current, [change.id]: event.target.value }))} placeholder="Catatan jika menolak (opsional)" className="h-10 rounded-xl" /><div className="grid grid-cols-2 gap-2"><Button type="button" onClick={() => void respondScheduleChange(change.id, "approved")} disabled={processing} className="rounded-xl bg-emerald-600">Setujui</Button><Button type="button" variant="outline" onClick={() => void respondScheduleChange(change.id, "rejected")} disabled={processing} className="rounded-xl border-rose-200 text-rose-700">Tolak</Button></div></div>}</article>)}</section>}
              </div>
            )}

            {tab === "chat" && (
              <div className="space-y-4">
                <div className="flex items-center gap-2 px-1 text-[11px] font-bold text-slate-500">
                  <span className="h-2 w-2 shrink-0 rounded-full bg-emerald-500" aria-hidden="true" />
                  Pesan baru muncul otomatis, tanpa perlu muat ulang.
                </div>
                <div ref={chatScrollRef} onScroll={handleChatScroll} className="max-h-[48dvh] space-y-3 overflow-y-auto rounded-2xl bg-slate-50 p-3 sm:p-4">
                  {hub.messages.length === 0 ? <p className="py-12 text-center text-sm text-slate-500">Belum ada pesan.</p> : hub.messages.map((item) => <div key={item.id} className={`flex ${item.message_type === "system" ? "justify-center" : item.is_mine ? "justify-end" : "justify-start"}`}>{item.message_type === "system" ? <div className="max-w-[90%] rounded-xl border border-indigo-100 bg-indigo-50 px-4 py-3 text-center text-xs leading-5 text-indigo-800">{item.body}</div> : <div className={`max-w-[85%] rounded-2xl px-4 py-3 text-sm ${item.is_mine ? "bg-indigo-600 text-white" : "bg-white text-slate-800 shadow-sm"}`}><p className={`mb-1 text-[10px] font-black ${item.is_mine ? "text-indigo-100" : "text-slate-400"}`}>{item.sender_name}</p><p className="whitespace-pre-wrap leading-6">{item.body}</p><p className={`mt-1 text-[10px] ${item.is_mine ? "text-indigo-200" : "text-slate-400"}`}>{dateTime(item.created_at)}</p></div>}</div>)}
                </div>
                <form onSubmit={sendMessage} className="flex gap-2"><Input value={message} onChange={(event) => setMessage(event.target.value)} maxLength={3000} disabled={!hub.permissions.can_chat} placeholder="Tulis pesan..." className="h-11 rounded-xl" /><Button disabled={processing || !message.trim() || !hub.permissions.can_chat} className="h-11 rounded-xl bg-indigo-600"><Send size={16} /></Button></form>
              </div>
            )}

            {tab === "progress" && (
              <div className="space-y-5">
                {hub.role === "teacher" && hub.permissions.can_report_progress && (
                  <form onSubmit={saveProgress} className="grid gap-4 rounded-2xl border border-indigo-100 bg-indigo-50/60 p-4 sm:grid-cols-2 sm:p-5">
                    <div className="sm:col-span-2"><p className="font-black text-indigo-950">Hasil belajar sesi</p><p className="mt-1 text-xs leading-5 text-indigo-700">{hub.learning_chapters.length ? "Catat Bab yang dibahas dan ringkasan hasilnya." : "Catat materi yang dibahas dan hasil belajar murid. Tidak perlu memilih Bab untuk sesi ini."} Penyelesaian baru resmi setelah murid menyatakan sesi sesuai.</p></div>
                    {hub.learning_chapters.length === 0 && <FieldArea label="Materi yang dibahas" value={reportForm.material_covered} onChange={(value) => setReportForm((current) => ({ ...current, material_covered: value }))} required className="sm:col-span-2" />}
                    {hub.learning_chapters.length > 0 && <div className="space-y-3 sm:col-span-2"><div className="flex items-center justify-between"><Label>Progress Bab</Label><span className="text-xs font-black text-indigo-700">{hub.material_progress_percent}% sebelum sesi</span></div>{hub.learning_chapters.map((chapter) => { const value = chapterUpdates[chapter.chapter] || { selected: false, activity_type: "taught" as const, status_after: "in_progress" as const, needs_review: false, notes: "" }; return <div key={chapter.chapter} className={`rounded-xl border p-3 ${value.selected ? "border-indigo-300 bg-white" : "border-slate-200 bg-white/70"}`}><label className="flex cursor-pointer items-start gap-3"><input type="checkbox" checked={value.selected} disabled={reportForm.no_material_change} onChange={(event) => setChapterUpdates((current) => ({ ...current, [chapter.chapter]: { ...value, selected: event.target.checked } }))} className="mt-1" /><span className="min-w-0"><span className="block font-black text-slate-900">{chapter.chapter}</span><span className="text-xs text-slate-500">Status: {chapter.status === "completed" ? "Selesai" : chapter.status === "in_progress" ? "Sedang dipelajari" : "Belum dimulai"}</span></span></label>{value.selected && !reportForm.no_material_change && <div className="mt-3 grid gap-2 sm:grid-cols-2"><Select value={value.activity_type} onValueChange={(next) => setChapterUpdates((current) => ({ ...current, [chapter.chapter]: { ...value, activity_type: next as ChapterUpdateState["activity_type"] } }))}><SelectTrigger className="h-10 rounded-xl"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="taught">Materi baru</SelectItem><SelectItem value="continued">Melanjutkan</SelectItem><SelectItem value="reviewed">Mengulang</SelectItem></SelectContent></Select><Select value={value.status_after} onValueChange={(next) => setChapterUpdates((current) => ({ ...current, [chapter.chapter]: { ...value, status_after: next as ChapterUpdateState["status_after"], needs_review: next === "completed" ? value.needs_review : false } }))}><SelectTrigger className="h-10 rounded-xl"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="in_progress">Masih dipelajari</SelectItem><SelectItem value="completed">Selesai</SelectItem></SelectContent></Select>{value.status_after === "completed" && <label className="flex items-center gap-2 rounded-xl bg-amber-50 px-3 py-2 text-xs font-bold text-amber-800 sm:col-span-2"><input type="checkbox" checked={value.needs_review} onChange={(event) => setChapterUpdates((current) => ({ ...current, [chapter.chapter]: { ...value, needs_review: event.target.checked } }))} />Perlu penguatan lagi</label>}<Input value={value.notes} onChange={(event) => setChapterUpdates((current) => ({ ...current, [chapter.chapter]: { ...value, notes: event.target.value } }))} placeholder="Catatan Bab (opsional)" className="h-10 rounded-xl sm:col-span-2" /></div>}</div>; })}</div>}
                    {hub.learning_chapters.length > 0 && <label className="flex items-center gap-2 rounded-xl border border-slate-200 bg-white p-3 text-xs font-bold text-slate-700 sm:col-span-2"><input type="checkbox" checked={reportForm.no_material_change} onChange={(event) => setReportForm((current) => ({ ...current, no_material_change: event.target.checked, no_change_reason: event.target.checked ? current.no_change_reason : "" }))} />Sesi ini hanya review/latihan, tidak mengubah status Bab</label>}
                    {hub.learning_chapters.length > 0 && reportForm.no_material_change && <div className="sm:col-span-2"><Label>Alasan</Label><Select value={reportForm.no_change_reason} onValueChange={(value) => setReportForm((current) => ({ ...current, no_change_reason: value }))}><SelectTrigger className="mt-2 h-11 rounded-xl bg-white"><SelectValue placeholder="Pilih alasan" /></SelectTrigger><SelectContent><SelectItem value="review">Review materi</SelectItem><SelectItem value="practice">Latihan soal</SelectItem><SelectItem value="evaluation">Evaluasi / ujian</SelectItem><SelectItem value="remedial">Remedial</SelectItem><SelectItem value="session_disrupted">Sesi tidak berjalan penuh</SelectItem><SelectItem value="other">Lainnya</SelectItem></SelectContent></Select></div>}
                    <FieldArea label="Ringkasan hasil belajar" value={reportForm.mastered_skills} onChange={(value) => setReportForm((current) => ({ ...current, mastered_skills: value }))} required className="sm:col-span-2" />
                    <FieldArea label="Catatan untuk sesi berikutnya (opsional)" value={reportForm.next_exercise} onChange={(value) => setReportForm((current) => ({ ...current, next_exercise: value }))} className="sm:col-span-2" />
                    {hub.learning_chapters.length === 0 && <div className="sm:col-span-2"><Label>Perkiraan penguasaan materi (%)</Label><Input type="number" min={0} max={100} step={1} value={reportForm.progress_percent} onChange={(event) => setReportForm((current) => ({ ...current, progress_percent: event.target.value }))} className="mt-2 h-11 rounded-xl bg-white" /><p className="mt-2 text-xs leading-5 text-slate-500">Penilaian tutor terhadap materi sesi ini, bukan jumlah sesi yang sudah selesai.</p></div>}
                    <Button disabled={processing || reportForm.mastered_skills.trim().length < 3 || (hub.learning_chapters.length === 0 && reportForm.material_covered.trim().length < 3) || (!reportForm.no_material_change && hub.learning_chapters.length > 0 && !Object.values(chapterUpdates).some((value) => value.selected)) || (hub.learning_chapters.length > 0 && reportForm.no_material_change && !reportForm.no_change_reason)} className="rounded-xl bg-indigo-600 sm:col-span-2">Simpan Hasil Belajar</Button>
                  </form>
                )}

                {hub.progress_reports.length === 0 ? <p className="rounded-2xl bg-slate-50 py-14 text-center text-sm text-slate-500">Hasil belajar belum tersedia.</p> : hub.progress_reports.map((report) => <article key={report.id} className="rounded-2xl border border-slate-200 p-5"><div className="flex flex-wrap items-center justify-between gap-2"><p className="font-black text-slate-900">Sesi {report.session_number}{report.student_name ? ` · ${report.student_name}` : ""}</p><span className="rounded-full bg-indigo-50 px-3 py-1 text-xs font-black text-indigo-700">{report.progress_percent}%</span></div><p className="mt-1 text-xs text-slate-400">{dateTime(report.published_at)} · {report.actual_duration_minutes} menit</p>{report.no_material_change && <div className="mt-4 rounded-xl border border-amber-100 bg-amber-50 p-3 text-xs font-bold text-amber-800">Tidak ada perubahan status Bab · {noChangeReasonLabel(report.no_change_reason)}</div>}<div className="mt-4 grid gap-3 text-sm sm:grid-cols-2"><Info label="Materi" value={report.material_covered} /><Info label="Hasil" value={report.mastered_skills} /><Info label="Kesulitan" value={report.difficulties || "-"} /><Info label="Berikutnya" value={report.next_exercise} /></div>{report.chapters?.length ? <div className="mt-4 flex flex-wrap gap-2">{report.chapters.map((chapter) => <span key={chapter.chapter} className={`rounded-full px-3 py-1 text-xs font-bold ${chapter.status_after === "completed" ? "bg-emerald-100 text-emerald-800" : "bg-amber-100 text-amber-800"}`}>{chapter.chapter} · {chapter.status_after === "completed" ? "selesai" : "dipelajari"}</span>)}</div> : null}</article>)}
              </div>
            )}

            <Button type="button" variant="outline" onClick={() => void load()} className="w-full rounded-xl"><RefreshCw size={16} className="mr-2" />Muat ulang</Button>
          </div>
        )}
      </DialogContent>
    </Dialog>
    <Dialog open={confirmCheckout} onOpenChange={setConfirmCheckout}>
      <DialogContent overlayClassName="!z-[6000] !bg-slate-950/60" onPointerDownOutside={(event) => event.preventDefault()} onInteractOutside={(event) => event.preventDefault()} className="!z-[6001] max-w-md rounded-2xl">
        <DialogHeader>
          <DialogTitle>Pembelajaran sudah selesai?</DialogTitle>
          <DialogDescription>Waktu selesai akan dicatat sekarang. Setelah itu, isi hasil belajar murid sebelum sesi dikirim untuk konfirmasi.</DialogDescription>
        </DialogHeader>
        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button type="button" variant="outline" className="rounded-xl" onClick={() => setConfirmCheckout(false)}>Batal</Button>
          <Button type="button" className="rounded-xl bg-emerald-600 hover:bg-emerald-700" onClick={() => { setConfirmCheckout(false); void checkOut(); }}>Ya, Lanjut ke hasil belajar</Button>
        </div>
      </DialogContent>
    </Dialog>
    </>
  );
}

function TabButton({ active, onClick, icon: Icon, label, attention = false }: { active: boolean; onClick: () => void; icon: typeof ClipboardCheck; label: string; attention?: boolean }) {
  return <button type="button" onClick={onClick} aria-label={attention && !active ? `${label}, perlu diisi` : label} className={`relative flex min-h-10 min-w-0 items-center justify-center gap-1 rounded-xl px-1.5 text-[11px] font-black leading-tight transition min-[360px]:gap-2 min-[360px]:px-2 min-[360px]:text-xs sm:text-sm ${active ? "bg-white text-indigo-700 shadow-sm" : attention ? "bg-indigo-50 text-indigo-700" : "text-slate-500 hover:text-slate-800"}`}><Icon size={16} />{label}{attention && !active && <span aria-hidden="true" className="absolute right-2 top-2 h-2 w-2 rounded-full bg-amber-500" />}</button>;
}

function Info({ label, value }: { label: string; value: string }) {
  return <div className="rounded-xl bg-slate-50 p-3"><p className="text-[11px] font-black uppercase tracking-wider text-slate-400">{label}</p><p className="mt-1 whitespace-pre-wrap leading-6 text-slate-800">{value}</p></div>;
}

function FieldArea({ label, value, onChange, required, className = "" }: { label: string; value: string; onChange: (value: string) => void; required?: boolean; className?: string }) {
  return <div className={className}><Label>{label}</Label><Textarea required={required} minLength={required ? 3 : undefined} maxLength={3000} value={value} onChange={(event) => onChange(event.target.value)} className="mt-2 min-h-24 rounded-xl bg-white" /></div>;
}
