import { useCallback, useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import {
  ArrowLeft,
  BookOpen,
  CalendarDays,
  CheckCircle2,
  Circle,
  Clock3,
  History,
  Loader2,
  MessageSquareText,
  RefreshCw,
  RotateCcw,
  UserRound,
  WifiOff,
} from "lucide-react";
import LearningSessionHub from "@/components/LearningSessionHub";
import TeacherLayout from "@/components/TeacherLayout";
import { Button } from "@/components/ui/button";
import http, { getApiError } from "@/lib/http";
import { notify } from "@/lib/notify";

type MaterialStatus = "not_started" | "in_progress" | "completed" | "review_needed" | string;

type TeacherProgressData = {
  id: number;
  learning_package_id: number;
  package_code?: string | null;
  package_name: string;
  package_status?: string | null;
  subject_name: string;
  learning_goal?: string | null;
  education_level?: string | null;
  grade?: string | null;
  student: { id?: number | null; name: string };
  allocated_sessions: number;
  progress_summary: {
    total_chapters: number;
    completed_chapters: number;
    in_progress_chapters: number;
    progress_percent: number;
  };
  learning_chapters: Array<{
    chapter: string;
    curriculum_chapter_id?: number | null;
    status: MaterialStatus;
    needs_review: boolean;
    started_at?: string | null;
    completed_at?: string | null;
  }>;
  sessions: Array<{
    id: number;
    sequence: number;
    booking_id?: number | null;
    status: string;
    start_at?: string | null;
    end_at?: string | null;
    progress_report?: {
      id: number;
      session_number: number;
      material_covered: string;
      mastered_skills: string;
      difficulties?: string | null;
      next_exercise: string;
      notes?: string | null;
      progress_percent: number;
      no_material_change?: boolean;
      no_change_reason?: string | null;
      published_at: string;
      chapters?: Array<{
        chapter: string;
        activity_type?: string | null;
        status_before?: string | null;
        status_after?: string | null;
        needs_review?: boolean;
        notes?: string | null;
      }>;
    } | null;
  }>;
};

type Tab = "material" | "history";

const dateTime = (value?: string | null) => value
  ? new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short" }).format(new Date(value))
  : "Jadwal belum tersedia";

const statusLabel = (status?: string | null, needsReview = false) => {
  if (status === "completed" && needsReview) return "Selesai · perlu diulang";
  if (status === "completed") return "Selesai";
  if (status === "in_progress" || status === "review_needed") return "Sedang dipelajari";
  return "Belum dimulai";
};

const noChangeReasonLabel = (value?: string | null) => ({
  review: "Review materi",
  practice: "Latihan soal",
  evaluation: "Evaluasi / ujian",
  remedial: "Remedial",
  session_disrupted: "Sesi tidak berjalan penuh",
  other: "Lainnya",
}[value || ""] || "Tidak ada perubahan materi");

export default function TeacherLearningProgressDetail() {
  const { id } = useParams();
  const subjectId = Number(id);
  const [data, setData] = useState<TeacherProgressData | null>(null);
  const [tab, setTab] = useState<Tab>("material");
  const [selectedBookingId, setSelectedBookingId] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);

  const load = useCallback(async (silent = false) => {
    if (!Number.isFinite(subjectId)) {
      setError(true);
      setLoading(false);
      return;
    }
    if (!silent) {
      setLoading(true);
      setError(false);
    }
    try {
      const response = await http.get<TeacherProgressData>(`/teacher/package-subjects/${subjectId}/progress`);
      setData(response.data);
    } catch (requestError) {
      setError(true);
      notify.error(getApiError(requestError, "Progress kelas belum dapat dimuat."));
    } finally {
      if (!silent) setLoading(false);
    }
  }, [subjectId]);

  useEffect(() => { void load(); }, [load]);

  useEffect(() => {
    const refresh = () => {
      if (document.visibilityState === "visible") void load(true);
    };
    window.addEventListener("focus", refresh);
    window.addEventListener("bimbelku:data-changed", refresh);
    const timer = window.setInterval(refresh, 20_000);
    return () => {
      window.removeEventListener("focus", refresh);
      window.removeEventListener("bimbelku:data-changed", refresh);
      window.clearInterval(timer);
    };
  }, [load]);

  if (loading) {
    return (
      <TeacherLayout title="Progress Kelas">
        <div className="grid min-h-[60dvh] place-items-center">
          <div className="text-center"><Loader2 className="mx-auto animate-spin text-indigo-600" size={36} /><p className="mt-3 text-sm font-bold text-slate-500">Memuat progress kelas…</p></div>
        </div>
      </TeacherLayout>
    );
  }

  if (error || !data) {
    return (
      <TeacherLayout title="Progress Kelas">
        <div className="mx-auto max-w-3xl rounded-[2rem] border border-rose-100 bg-white px-6 py-16 text-center">
          <WifiOff className="mx-auto text-rose-300" size={40} />
          <h1 className="mt-4 text-xl font-black text-slate-800">Progress kelas belum dapat dibuka</h1>
          <p className="mt-2 text-sm text-slate-500">Kelas mungkin sudah tidak menjadi tanggung jawab akun tutor ini atau koneksi sedang bermasalah.</p>
          <div className="mt-5 flex flex-col justify-center gap-2 sm:flex-row">
            <Button onClick={() => void load()} className="rounded-xl bg-indigo-600 hover:bg-indigo-700"><RefreshCw size={16} className="mr-2" />Coba lagi</Button>
            <Button asChild variant="outline" className="rounded-xl"><Link to="/guru/kelas">Kembali ke Kelas Saya</Link></Button>
          </div>
        </div>
      </TeacherLayout>
    );
  }

  const summary = data.progress_summary;
  const tracksChapters = data.learning_chapters.length > 0;
  const completedSessions = data.sessions.filter((session) => session.status === "completed").length;
  const displayedPercent = tracksChapters ? summary.progress_percent : data.allocated_sessions > 0 ? Math.round(completedSessions / data.allocated_sessions * 100) : 0;

  return (
    <TeacherLayout title="Progress Kelas">
      <div className="w-full space-y-6 pb-12">
        <section className="relative overflow-hidden rounded-[1.75rem] bg-gradient-to-br from-slate-950 via-indigo-950 to-blue-800 p-5 text-white shadow-[0_24px_64px_rgba(15,23,42,0.20)] sm:rounded-[2rem] sm:p-8">
          <div aria-hidden="true" className="pointer-events-none absolute -right-20 -top-24 h-64 w-64 rounded-full bg-blue-400/20 blur-3xl" />
          <div aria-hidden="true" className="pointer-events-none absolute -bottom-28 left-1/3 h-56 w-56 rounded-full bg-indigo-400/15 blur-3xl" />
          <div className="relative">
            <Link to="/guru/kelas" className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-white/10 bg-white/[0.08] px-3 text-xs font-black text-white transition hover:bg-white/15"><ArrowLeft size={15} />Kelas Saya</Link>
            <div className="mt-6 flex flex-col justify-between gap-6 md:flex-row md:items-end">
              <div className="min-w-0">
                <p className="text-xs font-black uppercase tracking-[.18em] text-blue-200">{data.package_name} · {data.package_code}</p>
                <h1 className="mt-2 break-words text-2xl font-black tracking-tight sm:text-3xl">{data.subject_name}</h1>
                <div className="mt-3 inline-flex max-w-full items-center gap-3 rounded-2xl border border-white/10 bg-white/[0.07] px-3 py-2.5">
                  <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-blue-400/15 text-blue-200"><UserRound size={17} /></span>
                  <div className="min-w-0"><p className="text-[10px] font-black uppercase tracking-wider text-slate-400">Murid</p><p className="truncate text-sm font-black text-white">{data.student.name}</p><p className="truncate text-[11px] text-slate-400">{[data.education_level, data.grade].filter(Boolean).join(" · ") || "Tingkat belajar"}</p></div>
                </div>
              </div>
              <div className="w-full rounded-2xl border border-white/10 bg-white/[0.08] p-4 backdrop-blur-sm md:max-w-xs">
                <div className="flex items-end justify-between gap-3"><p className="text-xs font-black uppercase tracking-wider text-slate-300">{tracksChapters ? "Progress materi" : "Pertemuan selesai"}</p><p className="text-3xl font-black text-blue-200">{displayedPercent}%</p></div>
                <div className="mt-3 h-2.5 overflow-hidden rounded-full bg-white/10"><div className="h-full rounded-full bg-gradient-to-r from-blue-500 to-indigo-500" style={{ width: `${displayedPercent}%` }} /></div>
              </div>
            </div>
          </div>
        </section>

        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <Summary label={tracksChapters ? "Bab selesai" : "Pertemuan selesai"} value={tracksChapters ? `${summary.completed_chapters}/${summary.total_chapters}` : `${completedSessions}/${data.allocated_sessions}`} icon={CheckCircle2} />
          <Summary label={tracksChapters ? "Sedang dipelajari" : "Pelaporan materi"} value={tracksChapters ? summary.in_progress_chapters : "Per sesi"} icon={BookOpen} />
          <Summary label="Sesi dilaporkan" value={`${data.sessions.filter((session) => session.progress_report).length}/${data.allocated_sessions}`} icon={History} />
          <Summary label="Sesi dialokasikan" value={data.allocated_sessions} icon={CalendarDays} />
        </div>

        <div className="grid grid-cols-2 gap-1.5 rounded-2xl border border-indigo-100 bg-indigo-50/70 p-1.5">
          <button type="button" onClick={() => setTab("material")} className={`min-h-11 rounded-xl px-3 text-sm font-black transition ${tab === "material" ? "bg-indigo-600 text-white shadow-sm" : "text-slate-500 hover:bg-white hover:text-indigo-700"}`}><BookOpen size={16} className="mr-2 inline" />Materi</button>
          <button type="button" onClick={() => setTab("history")} className={`min-h-11 rounded-xl px-3 text-sm font-black transition ${tab === "history" ? "bg-indigo-600 text-white shadow-sm" : "text-slate-500 hover:bg-white hover:text-indigo-700"}`}><History size={16} className="mr-2 inline" />Riwayat Sesi</button>
        </div>

        {tab === "material" ? <MaterialPanel data={data} /> : <HistoryPanel data={data} onOpenSession={setSelectedBookingId} />}
      </div>
      <LearningSessionHub bookingId={selectedBookingId} open={selectedBookingId !== null} onOpenChange={(open) => { if (!open) { setSelectedBookingId(null); void load(true); } }} initialTab="progress" />
    </TeacherLayout>
  );
}

function MaterialPanel({ data }: { data: TeacherProgressData }) {
  return (
    <section className="rounded-[1.75rem] border border-indigo-100 bg-gradient-to-br from-white via-indigo-50/35 to-blue-50/45 p-4 shadow-[0_14px_36px_rgba(30,64,175,0.07)] sm:p-6">
      <div><h2 className="text-lg font-black text-slate-900">Target dan hasil belajar</h2><p className="mt-1 text-sm leading-6 text-slate-500">{data.learning_chapters.length ? "Progress ditampilkan per Bab. Riwayat Sesi menjelaskan kapan perubahan tersebut terjadi." : "Materi disesuaikan dengan kebutuhan murid. Persentase pertemuan selesai bukan penilaian penguasaan materi; penilaian tutor dicatat pada laporan masing-masing sesi."}</p></div>
      {data.learning_goal && <div className="mt-4 rounded-xl bg-white p-4"><p className="text-xs font-black text-indigo-700">Target atau materi yang ingin difokuskan</p><p className="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-slate-700">{data.learning_goal}</p></div>}
      <div className="mt-5 grid gap-3 sm:grid-cols-2">
        {data.learning_chapters.map((chapter) => <ChapterRow key={`${chapter.curriculum_chapter_id || chapter.chapter}`} chapter={chapter} />)}
        {data.learning_chapters.length === 0 && <p className="rounded-xl bg-slate-50 p-5 text-sm text-slate-500 sm:col-span-2">Pesanan ini tidak memakai pilihan Bab. Catat materi yang dibahas, hasil belajar, dan latihan berikutnya pada laporan sesi.</p>}
      </div>
    </section>
  );
}

function ChapterRow({ chapter }: { chapter: TeacherProgressData["learning_chapters"][number] }) {
  const done = chapter.status === "completed";
  const active = chapter.status === "in_progress" || chapter.status === "review_needed";
  const Icon = done ? (chapter.needs_review ? RotateCcw : CheckCircle2) : active ? BookOpen : Circle;
  return (
    <article className={`flex min-h-24 items-start gap-3 rounded-2xl border p-4 ${done ? "border-emerald-100 bg-emerald-50" : active ? "border-indigo-100 bg-white" : "border-slate-100 bg-white"}`}>
      <Icon size={18} className={`mt-0.5 shrink-0 ${done ? (chapter.needs_review ? "text-amber-600" : "text-emerald-600") : active ? "text-indigo-600" : "text-slate-300"}`} />
      <div className="min-w-0"><p className="break-words text-sm font-black text-slate-800">{chapter.chapter}</p><p className={`mt-1 text-xs font-bold ${done && chapter.needs_review ? "text-amber-700" : done ? "text-emerald-700" : active ? "text-indigo-700" : "text-slate-400"}`}>{statusLabel(chapter.status, chapter.needs_review)}</p>{chapter.completed_at && <p className="mt-2 text-[11px] font-semibold text-slate-400">Selesai {dateTime(chapter.completed_at)}</p>}</div>
    </article>
  );
}

function HistoryPanel({ data, onOpenSession }: { data: TeacherProgressData; onOpenSession: (bookingId: number) => void }) {
  return (
    <section className="rounded-[1.75rem] border border-indigo-100 bg-gradient-to-br from-white via-indigo-50/35 to-blue-50/45 p-4 shadow-[0_14px_36px_rgba(30,64,175,0.07)] sm:p-6">
      <div><h2 className="text-lg font-black text-slate-900">Riwayat progress per sesi</h2><p className="mt-1 text-sm leading-6 text-slate-500">Setiap kartu menunjukkan perubahan sebelum → sesudah yang dicatat pada sesi tersebut.</p></div>
      <div className="mt-5 space-y-3">
        {[...data.sessions].sort((a, b) => b.sequence - a.sequence).map((session) => {
          const report = session.progress_report;
          return (
            <article key={session.id} className="rounded-2xl border border-indigo-100 bg-indigo-50/55 p-4 sm:p-5">
              <div className="flex flex-col justify-between gap-2 sm:flex-row sm:items-start">
                <div><p className="text-xs font-black uppercase tracking-wider text-indigo-600">Sesi {session.sequence}</p><p className="mt-1 text-sm font-bold text-slate-800">{dateTime(session.start_at)}</p></div>
                <span className={`self-start rounded-full border px-3 py-1.5 text-[10px] font-black uppercase ${report ? "border-emerald-100 bg-emerald-50 text-emerald-700" : "border-indigo-100 bg-white text-slate-500"}`}>{report ? `${report.progress_percent}% setelah sesi` : session.status === "completed" ? "Tanpa laporan" : "Belum dilaporkan"}</span>
              </div>
              {report ? (
                <>
                  <h3 className="mt-4 text-sm font-black text-slate-900">{report.material_covered}</h3>
                  {report.no_material_change && <div className="mt-3 rounded-xl border border-amber-100 bg-amber-50 p-3 text-xs font-bold text-amber-800">Tidak ada perubahan status materi · {noChangeReasonLabel(report.no_change_reason)}</div>}
                  {report.chapters?.length ? (
                    <div className="mt-3 space-y-2">
                      {report.chapters.map((chapter) => (
                        <div key={`${session.id}-${chapter.chapter}`} className="rounded-xl border border-indigo-100 bg-white p-3">
                          <p className="text-sm font-black text-slate-900">{chapter.chapter}</p>
                          <p className="mt-1 text-xs font-semibold text-slate-500">{statusLabel(chapter.status_before)} <span className="px-1 text-indigo-400">→</span> <span className="font-black text-indigo-700">{statusLabel(chapter.status_after, Boolean(chapter.needs_review))}</span></p>
                          {chapter.notes && <p className="mt-2 text-xs leading-5 text-slate-500">{chapter.notes}</p>}
                        </div>
                      ))}
                    </div>
                  ) : !report.no_material_change ? <p className="mt-3 rounded-xl bg-white p-3 text-sm text-slate-500">{report.mastered_skills} Latihan berikutnya: {report.next_exercise}</p> : null}
                  {report.notes && <p className="mt-3 rounded-xl bg-white p-3 text-sm leading-6 text-slate-600"><b>Catatan:</b> {report.notes}</p>}
                  {session.booking_id && <Button type="button" variant="outline" onClick={() => onOpenSession(session.booking_id!)} className="mt-4 rounded-xl border-indigo-100 hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700"><MessageSquareText size={16} className="mr-2" />Lihat laporan lengkap</Button>}
                </>
              ) : <p className="mt-4 rounded-xl bg-white p-3 text-sm text-slate-500">Progress sesi ini belum dicatat.</p>}
            </article>
          );
        })}
      </div>
    </section>
  );
}

function Summary({ label, value, icon: Icon }: { label: string; value: string | number; icon: typeof Clock3 }) {
  return <div className="rounded-2xl border border-indigo-100 bg-gradient-to-br from-white to-indigo-50/60 p-4 shadow-[0_10px_28px_rgba(15,23,42,0.04)]"><div className="flex items-center gap-2 text-slate-400"><Icon size={16} /><span className="text-[10px] font-black uppercase tracking-wider">{label}</span></div><p className="mt-2 break-words text-lg font-black text-slate-900">{value}</p></div>;
}
