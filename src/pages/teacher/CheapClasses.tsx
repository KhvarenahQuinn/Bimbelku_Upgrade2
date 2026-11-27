import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { useLocation } from "react-router-dom";
import { AlertTriangle, BarChart3, CalendarDays, CheckCircle2, ChevronDown, Clock3, ExternalLink, History, Loader2, Send, Users, Video } from "lucide-react";
import TeacherLayout from "@/components/TeacherLayout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import http, { getApiError } from "@/lib/http";
import { notify } from "@/lib/notify";

type ProgressChange = {
  subject_index: number;
  subject_name: string;
  chapter: string;
  status_before: "not_started" | "in_progress" | "completed";
  status_after: "not_started" | "in_progress" | "completed";
  needs_review_before?: boolean;
  needs_review_after?: boolean;
  notes?: string | null;
};

type ClassSession = {
  id: number;
  session_number: number;
  starts_at: string;
  ends_at: string;
  status: string;
  progress_updates?: ProgressChange[];
  progress_notes?: string | null;
  progress_recorded_at?: string | null;
  attended_participants_count?: number | null;
  report_submitted_at?: string | null;
  admin_review_notes?: string | null;
  admin_reviewed_at?: string | null;
  report_revision_count?: number;
  teacher_started_at?: string | null;
  teacher_started_by?: number | null;
};

type ChapterProgress = {
  subject_name: string;
  chapter: string;
  progress_status: "not_started" | "in_progress" | "completed";
  needs_review: boolean;
  progress_notes?: string | null;
  progress_updated_at?: string | null;
};

type CheapClass = {
  id: number;
  subject_name: string;
  education_level: string;
  grade: string;
  chapter: string;
  starts_at: string;
  ends_at: string;
  session_count: number;
  sessions: ClassSession[];
  subjects?: ChapterProgress[];
  progress_summary?: { total_chapters: number; completed_chapters: number; in_progress_chapters: number; progress_percent: number };
  status: string;
  meeting_link?: string | null;
  confirmed_participants_count: number;
  minimum_participants: number;
};

type Scope = "active" | "history";

const historyStatuses = new Set(["completed", "cancelled"]);

const statusText: Record<string, string> = {
  waiting_teacher: "Mencari tutor",
  open: "Mengumpulkan peserta",
  registration_closed: "Pendaftaran ditutup",
  awaiting_verification: "Menunggu verifikasi",
  confirmed: "Kelas dikonfirmasi",
  completed: "Kelas selesai",
  cancelled: "Dibatalkan",
};

const sessionStatusLabel: Record<string, string> = {
  scheduled: "Terjadwal",
  report_required: "Laporan wajib diisi",
  awaiting_admin_verification: "Menunggu verifikasi admin",
  revision_requested: "Perlu diperbaiki",
  completed: "Terverifikasi",
  cancelled: "Dibatalkan",
};

const progressStatusLabel = (status?: string, needsReview = false) => {
  if (status === "completed" && needsReview) return "Selesai · perlu diulang";
  if (status === "completed") return "Selesai";
  if (status === "in_progress") return "Sedang dipelajari";
  return "Belum dimulai";
};

export default function TeacherCheapClasses({
  embedded = false,
  controlledScope,
  onScopeChange,
  refreshToken = 0,
}: {
  embedded?: boolean;
  controlledScope?: Scope;
  onScopeChange?: (scope: Scope) => void;
  refreshToken?: number;
}) {
  const location = useLocation();
  const [classes, setClasses] = useState<CheapClass[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState<number | null>(null);
  const [links, setLinks] = useState<Record<number, string>>({});
  const [localScope, setLocalScope] = useState<Scope>("active");
  const [openProgressId, setOpenProgressId] = useState<number | null>(null);

  const openProgressForm = useCallback((classId: number) => {
    setOpenProgressId(classId);
    window.setTimeout(() => {
      const form = document.getElementById("cheap-class-report-" + classId);
      form?.scrollIntoView({ behavior: "smooth", block: "start" });
      form?.focus({ preventScroll: true });
    }, 80);
  }, []);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const response = await http.get<CheapClass[]>("/teacher/cheap-classes");
      setClasses(response.data);
      setLinks(Object.fromEntries(response.data.map((item) => [item.id, item.meeting_link || ""])));
    } catch (error) { notify.error(getApiError(error, "Kelas Bersama belum dapat dimuat.")); }
    finally { setLoading(false); }
  }, []);

  useEffect(() => { void load(); }, [load, refreshToken]);

  const changeScope = useCallback((nextScope: Scope) => {
    setLocalScope(nextScope);
    onScopeChange?.(nextScope);
  }, [onScopeChange]);

  useEffect(() => {
    const params = new URLSearchParams(location.search);
    const requestedClassId = Number(params.get("cheap_class"));
    const requestedAction = params.get("cheap_action");
    if (!requestedClassId || !["start", "live", "report", "revision"].includes(requestedAction || "")) return;
    if (!classes.some((item) => item.id === requestedClassId)) return;

    changeScope("active");
    if (["report", "revision"].includes(requestedAction || "")) setOpenProgressId(requestedClassId);
    window.setTimeout(() => {
      document.getElementById(`cheap-class-${requestedClassId}`)?.scrollIntoView({ behavior: "smooth", block: "start" });
    }, 80);
  }, [changeScope, classes, location.search]);

  const activeClasses = useMemo(() => classes.filter((item) => !historyStatuses.has(item.status)), [classes]);
  const historyClasses = useMemo(() => classes.filter((item) => historyStatuses.has(item.status)), [classes]);
  const scope = controlledScope ?? localScope;
  const visibleClasses = scope === "active" ? activeClasses : historyClasses;

  const startSession = async (item: CheapClass) => {
    try {
      const response = await http.post(`/teacher/cheap-classes/${item.id}/start-session`);
      notify.success(response.data.message);
      await load();
    } catch (error) { notify.error(getApiError(error)); }
  };

  const saveLink = async (event: FormEvent, item: CheapClass) => {
    event.preventDefault();
    setSaving(item.id);
    try {
      const response = await http.put(`/teacher/cheap-classes/${item.id}/meeting-link`, { meeting_link: links[item.id] });
      notify.success(response.data.message);
      await load();
    } catch (error) { notify.error(getApiError(error)); }
    finally { setSaving(null); }
  };

  const content = <div data-tour="teacher-cheap-classes" className={`w-full space-y-7 ${embedded ? "max-w-none" : "pb-12"}`}>
      {!embedded && <section data-tour="teacher-cheap-hero" className="rounded-[2rem] bg-gradient-to-br from-indigo-700 to-violet-800 px-6 py-8 text-white shadow-xl sm:px-7"><p className="text-xs font-black uppercase tracking-[.2em] text-indigo-100">Kelas online bersama</p><h1 className="mt-3 text-3xl font-black">Kelas Bersama yang ditugaskan</h1><p className="mt-2 max-w-3xl text-sm leading-6 text-indigo-100">Kelas aktif dipisahkan dari riwayat. Setelah setiap sesi berakhir, kirim kehadiran, progress per bab, dan catatan. Sesi baru final setelah admin memverifikasi laporan.</p></section>}

      {!embedded && !loading && classes.length > 0 && <section className="rounded-[1.5rem] border border-slate-100 bg-white p-2 shadow-sm"><div className="grid grid-cols-2 gap-1.5 rounded-2xl bg-slate-100 p-1.5"><button type="button" onClick={() => changeScope("active")} className={`min-h-11 rounded-xl text-sm font-black ${scope === "active" ? "bg-white text-indigo-700 shadow-sm" : "text-slate-500"}`}>Aktif ({activeClasses.length})</button><button type="button" onClick={() => changeScope("history")} className={`min-h-11 rounded-xl text-sm font-black ${scope === "history" ? "bg-white text-indigo-700 shadow-sm" : "text-slate-500"}`}>Riwayat ({historyClasses.length})</button></div></section>}

      {loading ? <div className="grid min-h-56 place-items-center rounded-[2rem] bg-white"><Loader2 className="animate-spin text-indigo-600" /></div> : classes.length === 0 ? <Empty title="Belum ada Kelas Bersama yang ditugaskan." /> : visibleClasses.length === 0 ? <Empty title={scope === "history" ? "Riwayat Kelas Bersama masih kosong." : "Tidak ada Kelas Bersama aktif."} /> : <div data-tour="teacher-cheap-list" className="grid gap-5">{visibleClasses.map((item) => {
        const percent = Number(item.progress_summary?.progress_percent || 0);
        const actionSession = item.sessions.find((session) => session.status === "revision_requested") || item.sessions.find((session) => session.status === "report_required");
        const waitingSession = item.sessions.find((session) => session.status === "awaiting_admin_verification");
        const liveSession = item.sessions.find((session) => session.status === "in_progress");
        const startableSession = item.sessions.find((session) => session.status === "scheduled" && new Date(session.starts_at).getTime() <= Date.now() + 10 * 60_000 && new Date(session.ends_at).getTime() > Date.now());
        return <article id={`cheap-class-${item.id}`} key={item.id} className="scroll-mt-24 rounded-[2rem] border border-slate-100 bg-white p-5 shadow-sm sm:p-6">
          <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start"><div className="min-w-0"><p className="text-xs font-black uppercase tracking-widest text-indigo-600">Kelas Bersama · {item.session_count} sesi</p><h2 className="mt-2 break-words text-xl font-black text-slate-900">{item.subject_name}</h2><p className="mt-1 break-words text-sm font-bold text-slate-500">{item.education_level} · {item.grade} · {item.chapter}</p></div><span className={`self-start rounded-full px-3 py-1.5 text-xs font-black ${item.status === "confirmed" ? "bg-emerald-100 text-emerald-700" : item.status === "completed" ? "bg-slate-100 text-slate-600" : item.status === "cancelled" ? "bg-rose-50 text-rose-600" : "bg-indigo-50 text-indigo-700"}`}>{statusText[item.status] || item.status}</span></div>
          <div className="mt-5 grid gap-3 text-sm"><div className="flex gap-2 text-slate-700"><CalendarDays className="mt-0.5 shrink-0 text-indigo-500" size={17} /><div className="space-y-1 font-semibold">{item.sessions.map((session) => <p key={session.id} className={session.status === "completed" ? "text-slate-400" : ""}>Sesi {session.session_number}: {new Date(session.starts_at).toLocaleString("id-ID", { weekday: "long", day: "numeric", month: "long", hour: "2-digit", minute: "2-digit" })}{` · ${sessionStatusLabel[session.status] || session.status}` }</p>)}</div></div><p className="flex gap-2 font-semibold text-slate-700"><Users className="text-indigo-500" size={17} />{item.confirmed_participants_count} peserta terkonfirmasi · minimum {item.minimum_participants}</p></div>
          {actionSession && <div className={`mt-5 rounded-2xl border-2 p-4 ${actionSession.status === "revision_requested" ? "border-amber-200 bg-amber-50" : "border-indigo-200 bg-indigo-50"}`}><p className={`text-[11px] font-black uppercase tracking-wider ${actionSession.status === "revision_requested" ? "text-amber-600" : "text-indigo-600"}`}>{actionSession.status === "revision_requested" ? "Perlu perbaikan" : "Langkah setelah mengajar"}</p><p className="mt-1 font-black text-slate-950">{actionSession.status === "revision_requested" ? `Perbaiki laporan sesi ${actionSession.session_number}` : `Sesi ${actionSession.session_number} sudah berakhir`}</p><p className="mt-1 text-xs leading-5 text-slate-600">{actionSession.status === "revision_requested" ? (actionSession.admin_review_notes || "Admin meminta laporan diperbaiki lalu dikirim ulang.") : "Sebelum tugas pertemuan ini dianggap selesai, catat jumlah murid hadir, progress bab, dan catatan sesi."}</p><Button type="button" onClick={() => openProgressForm(item.id)} className="mt-3 h-10 rounded-xl bg-indigo-600 font-black hover:bg-indigo-700"><BarChart3 size={15} className="mr-2" />{actionSession.status === "revision_requested" ? "Perbaiki Laporan" : "Isi & Kirim Laporan"}</Button></div>}
          {!actionSession && waitingSession && <div className="mt-5 rounded-2xl border border-indigo-100 bg-indigo-50 p-4 text-xs font-bold leading-5 text-indigo-800"><Clock3 size={16} className="mr-2 inline" />Laporan sesi {waitingSession.session_number} sudah dikirim. Admin sedang memeriksa; kamu tidak perlu melakukan apa-apa sampai ada hasil verifikasi.</div>}

          {["confirmed", "completed"].includes(item.status) && <div className="mt-5 rounded-2xl bg-violet-50 p-4"><div className="flex items-center justify-between gap-3"><div><p className="text-xs font-black uppercase tracking-wider text-violet-500">Progress bab</p><p className="mt-1 text-sm font-black text-violet-950">{item.progress_summary?.completed_chapters || 0}/{item.progress_summary?.total_chapters || 0} bab selesai</p></div><span className="text-xl font-black text-violet-700">{percent}%</span></div><div className="mt-3 h-2 overflow-hidden rounded-full bg-white"><div className="h-full rounded-full bg-violet-700" style={{ width: `${percent}%` }} /></div></div>}

          {item.status === "confirmed" ? <form onSubmit={(event) => saveLink(event, item)} className="mt-5 rounded-2xl border border-indigo-100 bg-indigo-50 p-4"><Label className="text-sm font-black text-indigo-950">Tautan Zoom untuk seluruh sesi</Label><div className="mt-2 flex flex-col gap-2 sm:flex-row"><Input required type="url" aria-label="Tautan Zoom untuk seluruh sesi" value={links[item.id] || ""} onChange={(event) => setLinks((current) => ({ ...current, [item.id]: event.target.value }))} placeholder="https://zoom.us/j/..." className="bg-white" /><Button disabled={saving === item.id} type="submit" aria-label="Simpan tautan Zoom" className="min-h-11 min-w-11 rounded-xl bg-indigo-600 font-black hover:bg-indigo-700">{saving === item.id ? <Loader2 className="animate-spin" size={16} /> : <Video size={16} />}</Button></div><p className="mt-2 text-xs font-semibold text-indigo-700">Gunakan tautan resmi zoom.us. Satu tautan dipakai untuk seluruh sesi paket.</p>{item.meeting_link && <a href={item.meeting_link} target="_blank" rel="noreferrer" className="mt-3 inline-flex items-center gap-1 text-xs font-black text-indigo-700 underline">Periksa tautan <ExternalLink size={13} /></a>}{liveSession ? <div className="mt-4 rounded-xl border border-emerald-200 bg-emerald-100 px-4 py-3 text-sm font-black text-emerald-800">Kehadiran tercatat{liveSession.teacher_started_at ? ` ${new Date(liveSession.teacher_started_at).toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" })}` : ""} · Kelas sedang berlangsung</div> : <><Button type="button" onClick={() => startSession(item)} disabled={!item.meeting_link || !startableSession} className="mt-4 min-h-11 w-full rounded-xl bg-emerald-600 font-black hover:bg-emerald-700">Saya Hadir &amp; Mulai Mengajar</Button>{!startableSession && <p className="mt-2 text-center text-xs font-bold text-slate-500">Absensi dibuka 10 menit sebelum jadwal sesi.</p>}</>}</form> : item.status === "completed" ? <div className="mt-5 rounded-2xl bg-slate-50 p-4 text-sm font-bold text-slate-600">Kelas telah selesai. Riwayat progress setiap sesi tetap dapat dilihat di bawah.</div> : null}

          {["confirmed", "completed"].includes(item.status) && <Button type="button" variant="outline" onClick={() => openProgressId === item.id ? setOpenProgressId(null) : openProgressForm(item.id)} className="mt-4 w-full rounded-xl border-violet-200 text-violet-700"><BarChart3 size={16} className="mr-2" />{openProgressId === item.id ? "Tutup Progress" : item.status === "completed" ? "Lihat Progress & Riwayat" : "Isi / Lihat Progress"}<ChevronDown size={16} className={`ml-2 transition ${openProgressId === item.id ? "rotate-180" : ""}`} /></Button>}
          {openProgressId === item.id && ["confirmed", "completed"].includes(item.status) && <ChapterProgressEditor item={item} onSaved={load} />}
        </article>;
      })}</div>}
    </div>;

  return embedded ? content : <TeacherLayout title="Kelas Bersama">{content}</TeacherLayout>;
}

function ChapterProgressEditor({ item, onSaved }: { item: CheapClass; onSaved: () => Promise<void> }) {
  const source = useMemo(() => item.subjects || [], [item.subjects]);
  const [rows, setRows] = useState<ChapterProgress[]>(source);
  const reportableSessions = useMemo(() => item.sessions.filter((session) =>
    ["report_required", "revision_requested", "awaiting_admin_verification", "completed"].includes(session.status)
  ), [item.sessions]);
  const defaultSession = [...reportableSessions].sort((a, b) => b.session_number - a.session_number)[0];
  const [sessionId, setSessionId] = useState(String(defaultSession?.id || ""));
  const selectedSession = item.sessions.find((session) => String(session.id) === sessionId) || defaultSession;
  const [sessionNotes, setSessionNotes] = useState(selectedSession?.progress_notes || "");
  const [attendedCount, setAttendedCount] = useState(String(selectedSession?.attended_participants_count ?? item.confirmed_participants_count));
  const [saving, setSaving] = useState(false);

  const rowsForSession = useCallback((session?: ClassSession) => source.map((row, index) => {
    const proposed = session?.progress_updates?.find((change) => Number(change.subject_index) === index);
    if (!proposed) return { ...row };
    return {
      ...row,
      progress_status: proposed.status_after || row.progress_status,
      needs_review: proposed.status_after === "completed" ? Boolean(proposed.needs_review_after) : false,
      progress_notes: proposed.notes ?? row.progress_notes,
    };
  }), [source]);

  useEffect(() => {
    const actionable = item.sessions.filter((session) => ["revision_requested", "report_required"].includes(session.status));
    const next = [...(actionable.length ? actionable : reportableSessions)].sort((a, b) => b.session_number - a.session_number)[0] || item.sessions[0];
    setSessionId(String(next?.id || ""));
    setSessionNotes(next?.progress_notes || "");
    setRows(rowsForSession(next));
  }, [item.id, item.sessions, reportableSessions, rowsForSession]);

  useEffect(() => {
    setSessionNotes(selectedSession?.progress_notes || "");
    setAttendedCount(String(selectedSession?.attended_participants_count ?? item.confirmed_participants_count));
    setRows(rowsForSession(selectedSession));
  }, [selectedSession, item.confirmed_participants_count, rowsForSession]);

  const editable = Boolean(selectedSession && ["report_required", "revision_requested"].includes(selectedSession.status));
  const percent = useMemo(() => rows.length === 0 ? 0 : Math.round((rows.filter((row) => row.progress_status === "completed").length / rows.length) * 100), [rows]);

  const updateRow = (index: number, patch: Partial<ChapterProgress>) => setRows((current) => current.map((row, rowIndex) => rowIndex === index ? { ...row, ...patch } : row));

  const save = async () => {
    if (rows.length === 0 || !selectedSession) return;

    const normalizedAttendedCount = Number(attendedCount);
    if (attendedCount.trim() === "") {
      notify.error("Isi jumlah murid yang hadir sebelum mengirim laporan.");
      return;
    }
    if (!Number.isInteger(normalizedAttendedCount) || normalizedAttendedCount < 0 || normalizedAttendedCount > item.confirmed_participants_count) {
      notify.error(`Jumlah murid hadir harus 0 sampai ${item.confirmed_participants_count}.`);
      return;
    }
    if (sessionNotes.trim().length < 10) {
      notify.error("Catatan sesi wajib diisi minimal 10 karakter.");
      return;
    }

    setSaving(true);
    try {
      const response = await http.put(`/teacher/cheap-classes/${item.id}/progress`, {
        session_id: selectedSession.id,
        attended_participants_count: normalizedAttendedCount,
        session_notes: sessionNotes.trim(),
        updates: rows.map((row, subjectIndex) => ({
          subject_index: subjectIndex,
          progress_status: row.progress_status,
          needs_review: row.progress_status === "completed" ? row.needs_review : false,
          progress_notes: row.progress_notes || null,
        })),
      });
      notify.success(response.data.message);
      await onSaved();
    } catch (error) { notify.error(getApiError(error, "Laporan sesi gagal dikirim.")); }
    finally { setSaving(false); }
  };

  const history = [...item.sessions].filter((session) => session.progress_recorded_at).sort((a, b) => b.session_number - a.session_number);

  return <section id={"cheap-class-report-" + item.id} tabIndex={-1} className="scroll-mt-24 mt-4 rounded-2xl border border-violet-100 bg-violet-50/60 p-4 outline-none sm:p-5">
    <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center"><div><p className="flex items-center gap-2 text-sm font-black text-violet-950"><BarChart3 size={17} />Progress bab bersama</p><p className="mt-1 text-xs leading-5 text-violet-700">Setelah jadwal berakhir, isi kehadiran dan progress bab lalu kirim laporan ke admin. Sesi baru final setelah admin memverifikasi.</p></div><span className="self-start rounded-full bg-white px-3 py-1.5 text-xs font-black text-violet-700">{percent}% selesai</span></div>
    <div className="mt-4 rounded-xl bg-white p-3">
      <Label className="text-xs font-black text-slate-600">Sesi yang dilaporkan</Label><Select value={sessionId} onValueChange={setSessionId}><SelectTrigger className="mt-1 h-11 rounded-xl"><SelectValue placeholder="Pilih sesi" /></SelectTrigger><SelectContent>{reportableSessions.map((session) => <SelectItem key={session.id} value={String(session.id)}>Sesi {session.session_number} · {new Date(session.starts_at).toLocaleDateString("id-ID", { day: "numeric", month: "short", year: "numeric" })}{` · ${sessionStatusLabel[session.status] || session.status}`}</SelectItem>)}</SelectContent></Select>
      {selectedSession?.status === "revision_requested" && <div className="mt-3 flex gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs font-bold leading-5 text-amber-800"><AlertTriangle size={16} className="mt-0.5 shrink-0" /><span><b>Admin meminta perbaikan.</b> {selectedSession.admin_review_notes || "Periksa kembali laporan lalu kirim ulang."}</span></div>}
      {selectedSession?.status === "awaiting_admin_verification" && <div className="mt-3 flex gap-2 rounded-xl bg-indigo-50 p-3 text-xs font-bold text-indigo-800"><Clock3 size={16} className="shrink-0" />Laporan sudah dikirim dan sedang menunggu verifikasi admin.</div>}
      <div className="mt-3 grid gap-3 sm:grid-cols-2"><div><Label className="text-xs font-black text-slate-600">Jumlah murid hadir</Label><Input type="number" min={0} max={item.confirmed_participants_count} value={attendedCount} onChange={(event) => setAttendedCount(event.target.value)} disabled={selectedSession?.status === "awaiting_admin_verification" || selectedSession?.status === "completed"} className="mt-1 h-11 rounded-xl" /><p className="mt-1 text-[11px] text-slate-400">Dari {item.confirmed_participants_count} peserta terkonfirmasi.</p></div><div><Label className="text-xs font-black text-slate-600">Catatan sesi</Label><Textarea value={sessionNotes} onChange={(event) => setSessionNotes(event.target.value)} maxLength={1500} disabled={selectedSession?.status === "awaiting_admin_verification" || selectedSession?.status === "completed"} className="mt-1 min-h-20 rounded-xl" placeholder="Wajib: materi, respons kelas, evaluasi, atau tindak lanjut" /></div></div>
    </div>
    <div className="mt-4 space-y-3">{rows.map((row, index) => <div key={`${row.subject_name}-${row.chapter}-${index}`} className="rounded-xl border border-violet-100 bg-white p-3 sm:p-4"><div className="flex items-start gap-3"><span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-violet-50 text-violet-700"><CheckCircle2 size={17} /></span><div className="min-w-0 flex-1"><p className="text-[10px] font-black uppercase tracking-wider text-violet-500">{row.subject_name}</p><p className="mt-1 break-words text-sm font-black text-slate-900">{row.chapter || "Bab belum diberi nama"}</p></div></div><div className="mt-3 grid gap-3 sm:grid-cols-2"><div><Label className="text-xs">Status bab</Label><Select disabled={!editable} value={row.progress_status} onValueChange={(value) => updateRow(index, { progress_status: value as ChapterProgress["progress_status"], needs_review: value === "completed" ? row.needs_review : false })}><SelectTrigger className="mt-1 h-10 rounded-xl bg-white"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="not_started">Belum dimulai</SelectItem><SelectItem value="in_progress">Sedang dipelajari</SelectItem><SelectItem value="completed">Selesai</SelectItem></SelectContent></Select></div><div><Label className="text-xs">Catatan bab</Label><Textarea disabled={!editable} value={row.progress_notes || ""} onChange={(event) => updateRow(index, { progress_notes: event.target.value })} maxLength={1000} className="mt-1 min-h-20 resize-y rounded-xl" placeholder="Opsional, misalnya bagian yang perlu dilatih lagi" /></div></div>{row.progress_status === "completed" && <label className="mt-3 flex items-start gap-2 rounded-xl bg-amber-50 px-3 py-2.5 text-xs font-bold leading-5 text-amber-800"><input type="checkbox" disabled={!editable} checked={row.needs_review} onChange={(event) => updateRow(index, { needs_review: event.target.checked })} className="mt-0.5" />Materi selesai tetapi perlu diulang lagi</label>}</div>)}</div>
    {editable && <><p className="mt-3 text-xs font-semibold text-violet-700">Jumlah hadir wajib diisi dan catatan sesi minimal 10 karakter.</p><Button type="button" onClick={() => void save()} disabled={saving || rows.length === 0 || !selectedSession} className="mt-2 h-11 w-full rounded-xl bg-violet-700 font-black hover:bg-violet-800 sm:w-auto">{saving ? <Loader2 className="mr-2 animate-spin" size={16} /> : <Send className="mr-2" size={16} />}Kirim Laporan Sesi</Button></>}
    <div className="mt-6 border-t border-violet-100 pt-5"><p className="flex items-center gap-2 text-sm font-black text-violet-950"><History size={16} />Riwayat sesi</p>{history.length === 0 ? <p className="mt-3 rounded-xl bg-white p-3 text-sm text-slate-500">Belum ada progress sesi yang disimpan.</p> : <div className="mt-3 space-y-2">{history.map((session) => <details key={session.id} className="group rounded-xl bg-white p-3"><summary className="flex cursor-pointer list-none items-center justify-between gap-3 marker:hidden"><div><p className="text-sm font-black text-slate-800">Sesi {session.session_number}</p><p className="mt-1 text-xs text-slate-400">{new Date(session.progress_recorded_at!).toLocaleString("id-ID")}</p></div><ChevronDown size={16} className="text-slate-400 transition group-open:rotate-180" /></summary><div className="mt-3 space-y-2 border-t border-slate-100 pt-3">{session.progress_updates?.length ? session.progress_updates.map((change) => <div key={`${session.id}-${change.subject_index}`} className="rounded-lg bg-slate-50 p-3"><p className="text-xs font-black text-slate-800">{change.chapter}</p><p className="mt-1 text-[11px] font-semibold text-slate-500">{progressStatusLabel(change.status_before, Boolean(change.needs_review_before))} → <span className="font-black text-violet-700">{progressStatusLabel(change.status_after, Boolean(change.needs_review_after))}</span></p></div>) : <p className="text-xs text-slate-500">Tidak ada perubahan status bab pada sesi ini.</p>}{session.progress_notes && <p className="text-xs leading-5 text-slate-500">{session.progress_notes}</p>}</div></details>)}</div>}</div>
  </section>;
}

function Empty({ title }: { title: string }) {
  return <div className="rounded-[2rem] border-2 border-dashed border-slate-200 bg-white p-14 text-center"><Users className="mx-auto text-slate-300" size={38} /><p className="mt-4 font-black text-slate-700">{title}</p></div>;
}
