import { useCallback, useEffect, useMemo, useState } from "react";
import { Link, useParams } from "react-router-dom";
import {
  ArrowLeft,
  BookOpen,
  CalendarDays,
  CheckCircle2,
  Circle,
  Clock3,
  GraduationCap,
  History,
  Loader2,
  MessageSquareText,
  RefreshCw,
  RotateCcw,
  UserRound,
  Users,
  WifiOff,
  type LucideIcon,
} from "lucide-react";
import LearningSessionHub from "@/components/LearningSessionHub";
import StudentLayout from "@/components/StudentLayout";
import { Button } from "@/components/ui/button";
import http, { getApiError } from "@/lib/http";
import { notify } from "@/lib/notify";
import {
  CheapClassChapterProgress,
  CheapClassProgress,
  PackageLearningChapterProgress,
  PackageSubjectProgress,
  StudentPackageProgress,
  cheapClassChapterStats,
  materialStatusLabel,
  packageChapterStats,
} from "@/lib/studentProgress";

type ProgressHistoryChapter = {
  chapter: string;
  activity_type?: string | null;
  status_before?: string | null;
  status_after?: string | null;
  needs_review?: boolean;
  notes?: string | null;
};

type StudentClassRow = {
  id: number;
  package_subject_id?: number | null;
  subject: string;
  start_at: string;
  mentor: string;
  workspace?: {
    can_open?: boolean;
    latest_report?: {
      id?: number;
      session_number: number;
      material_covered: string;
      mastered_skills?: string;
      difficulties?: string | null;
      next_exercise?: string;
      notes?: string | null;
      progress_percent: number;
      no_material_change?: boolean;
      no_change_reason?: string | null;
      published_at: string;
      chapters?: ProgressHistoryChapter[];
    } | null;
    report_count?: number;
  };
};

type DetailTab = "material" | "history";

const dateTime = (value?: string | null) => value
  ? new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short" }).format(new Date(value))
  : "-";

const topicStatusLabel = (status?: string | null, needsReview = false) => {
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

export default function LearningProgressDetail() {
  const { kind, id } = useParams();
  const numericId = Number(id);
  const isPackage = kind === "package";
  const isCheapClass = kind === "cheap-class";
  const [packageData, setPackageData] = useState<StudentPackageProgress | null>(null);
  const [cheapClassData, setCheapClassData] = useState<CheapClassProgress | null>(null);
  const [classRows, setClassRows] = useState<StudentClassRow[]>([]);
  const [selectedBookingId, setSelectedBookingId] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);

  const load = useCallback(async (silent = false) => {
    if (!Number.isFinite(numericId) || (!isPackage && !isCheapClass)) {
      setError(true);
      setLoading(false);
      return;
    }
    if (!silent) {
      setLoading(true);
      setError(false);
    }
    try {
      if (isPackage) {
        const [packageResponse, classesResponse] = await Promise.all([
          http.get<StudentPackageProgress>(`/student/packages/${numericId}`),
          http.get<StudentClassRow[]>("/student/classes"),
        ]);
        setPackageData(packageResponse.data);
        setClassRows(Array.isArray(classesResponse.data) ? classesResponse.data : []);
        setCheapClassData(null);
      } else {
        const response = await http.get<CheapClassProgress>(`/student/cheap-classes/${numericId}`, { params: { scope: "progress" } });
        setCheapClassData(response.data);
        setPackageData(null);
        setClassRows([]);
      }
    } catch (requestError) {
      setError(true);
      notify.error(getApiError(requestError, "Detail progress gagal dimuat."));
    } finally {
      if (!silent) setLoading(false);
    }
  }, [isCheapClass, isPackage, numericId]);

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
    return <StudentLayout title="Detail Progress"><div className="grid min-h-[60dvh] place-items-center"><div className="text-center"><Loader2 className="mx-auto animate-spin text-indigo-600" size={36} /><p className="mt-3 text-sm font-bold text-slate-500">Memuat detail progress…</p></div></div></StudentLayout>;
  }

  if (error || (!packageData && !cheapClassData)) {
    return <StudentLayout title="Detail Progress"><div className="mx-auto max-w-3xl rounded-[2rem] border border-rose-100 bg-white px-6 py-16 text-center"><WifiOff className="mx-auto text-rose-300" size={40} /><h1 className="mt-4 text-xl font-black text-slate-800">Detail progress belum dapat dibuka</h1><p className="mt-2 text-sm text-slate-500">Data mungkin sudah tidak tersedia atau koneksi sedang bermasalah.</p><div className="mt-5 flex flex-col justify-center gap-2 sm:flex-row"><Button onClick={() => void load()} className="rounded-xl bg-indigo-600 hover:bg-indigo-700"><RefreshCw size={16} className="mr-2" />Coba lagi</Button><Button asChild variant="outline" className="rounded-xl"><Link to="/student/progress">Kembali ke Progress</Link></Button></div></div></StudentLayout>;
  }

  return (
    <StudentLayout title="Detail Progress">
      {packageData
        ? <PackageProgressDetail item={packageData} classes={classRows} onOpenReport={setSelectedBookingId} />
        : <CheapClassProgressDetail item={cheapClassData!} />}
      <LearningSessionHub bookingId={selectedBookingId} open={selectedBookingId !== null} onOpenChange={(open) => { if (!open) { setSelectedBookingId(null); void load(true); } }} initialTab="progress" />
    </StudentLayout>
  );
}

function PackageProgressDetail({ item, classes, onOpenReport }: { item: StudentPackageProgress; classes: StudentClassRow[]; onOpenReport: (id: number) => void }) {
  const [tab, setTab] = useState<DetailTab>("material");
  const stats = packageChapterStats(item);
  const subjectIds = new Set((item.subjects || []).map((subject) => subject.id));
  const relatedClasses = classes
    .filter((row) => row.package_subject_id && subjectIds.has(row.package_subject_id))
    .sort((a, b) => new Date(b.start_at).getTime() - new Date(a.start_at).getTime());
  const reports = relatedClasses.filter((row) => row.workspace?.latest_report);

  return (
    <div className="w-full space-y-6 pb-10">
      <ProgressHeader
        type="Paket Belajar"
        title={item.plan?.name || "Paket Belajar"}
        subtitle={`${(item.subjects || []).map((subject) => subject.name).join(" · ") || "Materi belajar"} · ${item.package_code}`}
        percent={stats.percent}
        progressLabel={stats.tracksChapters ? "Progress materi" : "Pertemuan selesai"}
        accent="brand"
      />

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <SummaryCard label={stats.tracksChapters ? "Bab selesai" : "Mapel dipelajari"} value={stats.tracksChapters ? `${stats.completed}/${stats.total}` : (item.subjects || []).length} icon={CheckCircle2} />
        <SummaryCard label={stats.tracksChapters ? "Sedang dipelajari" : "Laporan tersedia"} value={stats.tracksChapters ? stats.inProgress : reports.length} icon={BookOpen} />
        <SummaryCard label="Pertemuan selesai" value={`${item.used_sessions}/${item.total_sessions}`} icon={CalendarDays} />
        <SummaryCard label="Sisa sesi" value={item.remaining_sessions} icon={Clock3} />
      </div>

      <ProgressTabs value={tab} onChange={setTab} />

      {tab === "material" ? (
        <section className="rounded-[1.75rem] border border-indigo-100 bg-gradient-to-br from-white via-indigo-50/35 to-blue-50/45 p-4 shadow-[0_14px_36px_rgba(30,64,175,0.07)] sm:p-6">
          <div className="flex items-start gap-3">
            <span className="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-indigo-100 text-indigo-700"><GraduationCap size={20} /></span>
            <div><h2 className="text-lg font-black text-slate-900">Target dan hasil belajar</h2><p className="mt-1 text-sm leading-6 text-slate-500">{stats.tracksChapters ? "Progress materi dihitung dari Bab yang ditandai selesai oleh tutor." : "Persentase di atas menunjukkan pertemuan yang sudah selesai, bukan penguasaan materi. Hasil belajar dan penilaian tutor dapat dilihat pada laporan setiap sesi."}</p></div>
          </div>
          <div className="mt-5 space-y-5">
            {(item.subjects || []).map((subject) => <PackageSubjectSection key={subject.id} subject={subject} />)}
          </div>
        </section>
      ) : (
        <section className="rounded-[1.75rem] border border-indigo-100 bg-gradient-to-br from-white via-indigo-50/35 to-blue-50/45 p-4 shadow-[0_14px_36px_rgba(30,64,175,0.07)] sm:p-6">
          <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
            <div><h2 className="flex items-center gap-2 text-lg font-black text-slate-900"><History size={19} className="text-indigo-600" />Riwayat progress per sesi</h2><p className="mt-1 text-sm leading-6 text-slate-500">Lihat materi yang dibahas, hasil belajar, kesulitan, dan latihan berikutnya pada setiap sesi.</p></div>
            <Button asChild variant="outline" className="rounded-xl"><Link to="/student/my-classes">Lihat seluruh sesi</Link></Button>
          </div>
          {reports.length === 0 ? (
            <div className="mt-5 rounded-2xl bg-slate-50 p-5 text-sm leading-6 text-slate-500">Tutor belum menerbitkan laporan sesi untuk paket ini.</div>
          ) : (
            <div className="mt-5 space-y-3">
              {reports.map((row) => {
                const report = row.workspace!.latest_report!;
                return (
                  <article key={row.id} className="rounded-2xl border border-indigo-100 bg-indigo-50/55 p-4 sm:p-5">
                    <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                      <div className="min-w-0"><p className="text-xs font-black uppercase tracking-wider text-indigo-600">{row.subject} · sesi {report.session_number}</p><h3 className="mt-2 break-words text-base font-black text-slate-900">{report.material_covered}</h3><p className="mt-1 text-xs font-semibold text-slate-400">Tutor {row.mentor} · {dateTime(report.published_at)}</p></div>
                      <span className="self-start rounded-full bg-white px-3 py-1.5 text-xs font-black text-indigo-700 ring-1 ring-indigo-100">{report.progress_percent}% setelah sesi</span>
                    </div>
                    {report.no_material_change && <div className="mt-4 rounded-xl border border-amber-100 bg-amber-50 p-3 text-sm font-bold text-amber-800">Tidak ada perubahan status materi · {noChangeReasonLabel(report.no_change_reason)}</div>}
                    {report.chapters?.length ? (
                      <div className="mt-4 space-y-2">
                        {report.chapters.map((chapter) => <ProgressChangeRow key={`${row.id}-${chapter.chapter}`} chapter={chapter} />)}
                      </div>
                    ) : !report.no_material_change ? <p className="mt-4 rounded-xl bg-white p-3 text-sm text-slate-500">Materi dicatat langsung oleh tutor sesuai kebutuhan belajar, tanpa pilihan Bab dari katalog. Buka laporan lengkap untuk melihat hasil dan latihan berikutnya.</p> : null}
                    {report.notes && <p className="mt-3 rounded-xl bg-white p-3 text-sm leading-6 text-slate-600"><b>Catatan tutor:</b> {report.notes}</p>}
                    <Button type="button" variant="outline" onClick={() => onOpenReport(row.id)} className="mt-4 rounded-xl border-indigo-100 hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700"><MessageSquareText size={16} className="mr-2" />Lihat laporan lengkap</Button>
                  </article>
                );
              })}
            </div>
          )}
        </section>
      )}
    </div>
  );
}

function PackageSubjectSection({ subject }: { subject: PackageSubjectProgress }) {
  const chapters = subject.learning_chapters || [];
  const completed = chapters.filter((chapter) => chapter.status === "completed").length;

  return (
    <div className="rounded-2xl border border-indigo-100 bg-indigo-50/55 p-3 sm:p-4">
      <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
        <div>
          <p className="text-xs font-black uppercase tracking-wider text-indigo-600">Mata pelajaran</p>
          <h3 className="mt-1 text-lg font-black text-slate-900">{subject.name}</h3>
          {subject.teacher?.name && <p className="mt-1 flex items-center gap-1.5 text-xs font-semibold text-slate-500"><UserRound size={13} /> Tutor {subject.teacher.name}</p>}
        </div>
        <span className="self-start rounded-full bg-white px-3 py-1.5 text-[10px] font-black text-indigo-600 ring-1 ring-indigo-100">{chapters.length ? `${completed}/${chapters.length} Bab` : `${subject.allocated_sessions} sesi`}</span>
      </div>
      {subject.learning_goal && <div className="mt-3 rounded-xl bg-white p-4"><p className="text-xs font-bold text-indigo-700">Target atau materi yang ingin difokuskan</p><p className="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-slate-700">{subject.learning_goal}</p></div>}
      <div className="mt-4 grid gap-2 sm:grid-cols-2">
        {chapters.map((chapter) => <PackageChapterRow key={`${subject.id}-${chapter.curriculum_chapter_id || chapter.chapter}`} chapter={chapter} />)}
        {chapters.length === 0 && <p className="rounded-xl bg-white p-4 text-sm text-slate-500 sm:col-span-2">Tutor menyesuaikan materi dengan target belajar. Hasil pembelajaran dicatat pada laporan setiap sesi.</p>}
      </div>
    </div>
  );
}

function PackageChapterRow({ chapter }: { chapter: PackageLearningChapterProgress }) {
  const done = chapter.status === "completed";
  const active = chapter.status === "in_progress" || chapter.status === "review_needed";
  const Icon = done ? (chapter.needs_review ? RotateCcw : CheckCircle2) : active ? BookOpen : Circle;
  return (
    <article className={`flex min-h-24 items-start gap-3 rounded-xl border p-4 ${done ? "border-emerald-100 bg-emerald-50/80" : active ? "border-indigo-100 bg-white" : "border-slate-100 bg-white"}`}>
      <Icon size={18} className={`mt-0.5 shrink-0 ${done ? (chapter.needs_review ? "text-amber-600" : "text-emerald-600") : active ? "text-indigo-600" : "text-slate-300"}`} />
      <div className="min-w-0"><p className="break-words text-sm font-black text-slate-800">{chapter.chapter}</p><p className={`mt-1 text-xs font-bold ${done && chapter.needs_review ? "text-amber-700" : done ? "text-emerald-700" : active ? "text-indigo-700" : "text-slate-400"}`}>{materialStatusLabel(chapter.status, Boolean(chapter.needs_review))}</p>{chapter.completed_at && <p className="mt-2 text-[11px] font-semibold text-slate-400">Selesai {dateTime(chapter.completed_at)}</p>}</div>
    </article>
  );
}

function CheapClassProgressDetail({ item }: { item: CheapClassProgress }) {
  const [tab, setTab] = useState<DetailTab>("material");
  const stats = cheapClassChapterStats(item);
  const sessions = item.sessions || [];
  return (
    <div className="w-full space-y-6 pb-10">
      <ProgressHeader type="Kelas Bersama" title={item.subject_name} subtitle={[item.education_level, item.grade, item.package_code].filter(Boolean).join(" · ")} percent={stats.percent} accent="brand" />

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <SummaryCard label="Bab selesai" value={`${stats.completed}/${stats.total}`} icon={CheckCircle2} />
        <SummaryCard label="Sedang dipelajari" value={stats.inProgress} icon={BookOpen} />
        <SummaryCard label="Sesi selesai" value={`${sessions.filter((session) => session.status === "completed").length}/${item.session_count}`} icon={CalendarDays} />
        <SummaryCard label="Tutor" value={item.teacher?.name || "BimbelKu"} icon={UserRound} />
      </div>

      <ProgressTabs value={tab} onChange={setTab} />

      {tab === "material" ? (
        <section className="rounded-[1.75rem] border border-indigo-100 bg-gradient-to-br from-white via-indigo-50/35 to-blue-50/45 p-4 shadow-[0_14px_36px_rgba(30,64,175,0.07)] sm:p-6">
          <div className="flex items-start gap-3"><span className="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-indigo-100 text-indigo-700"><Users size={20} /></span><div><h2 className="text-lg font-black text-slate-900">Progress per bab</h2><p className="mt-1 text-sm leading-6 text-slate-500">Progress dicatat per Bab dan berlaku sama untuk seluruh peserta Kelas Bersama.</p></div></div>
          <div className="mt-5 space-y-3">
            {(item.subjects || []).map((chapter, index) => <CheapChapterRow key={`${chapter.subject_name}-${chapter.chapter}-${index}`} chapter={chapter} />)}
            {(item.subjects || []).length === 0 && <div className="rounded-2xl bg-slate-50 p-5 text-sm text-slate-500">Bab belum tersedia pada kelas ini.</div>}
          </div>
        </section>
      ) : (
        <section className="rounded-[1.75rem] border border-indigo-100 bg-gradient-to-br from-white via-indigo-50/35 to-blue-50/45 p-4 shadow-[0_14px_36px_rgba(30,64,175,0.07)] sm:p-6">
          <div><h2 className="flex items-center gap-2 text-lg font-black text-slate-900"><History size={19} className="text-indigo-600" />Riwayat progress per sesi</h2><p className="mt-1 text-sm leading-6 text-slate-500">Perubahan bab dicatat pada sesi ketika tutor menyimpan progress, sehingga riwayat tidak ditebak dari jumlah pertemuan.</p></div>
          <div className="mt-5 space-y-3">
            {[...sessions].sort((a, b) => b.session_number - a.session_number).map((session) => (
              <article key={session.id} className="rounded-2xl border border-indigo-100 bg-indigo-50/55 p-4">
                <div className="flex flex-col justify-between gap-2 sm:flex-row sm:items-start"><div><p className="text-xs font-black uppercase tracking-wider text-indigo-700">Sesi {session.session_number}</p><p className="mt-1 text-sm font-bold text-slate-800">{dateTime(session.starts_at)}</p></div><span className={`self-start rounded-full px-3 py-1 text-[10px] font-black uppercase ${session.progress_recorded_at ? "bg-emerald-100 text-emerald-700" : "bg-white text-slate-500"}`}>{session.progress_recorded_at ? "Progress terverifikasi" : session.status === "awaiting_admin_verification" ? "Menunggu admin" : session.status === "revision_requested" ? "Laporan diperbaiki" : session.status === "report_required" ? "Menunggu laporan tutor" : session.status === "completed" ? "Sesi selesai" : "Terjadwal"}</span></div>
                {session.progress_updates?.length ? <div className="mt-4 space-y-2">{session.progress_updates.map((change) => <div key={`${session.id}-${change.subject_index}`} className="rounded-xl bg-white p-3"><p className="text-sm font-black text-slate-900">{change.chapter}</p><p className="mt-1 text-xs font-semibold text-slate-500">{topicStatusLabel(change.status_before, Boolean(change.needs_review_before))} <span className="px-1 text-indigo-400">→</span> <span className="font-black text-indigo-700">{topicStatusLabel(change.status_after, Boolean(change.needs_review_after))}</span></p>{change.notes && <p className="mt-2 text-xs leading-5 text-slate-500">{change.notes}</p>}</div>)}</div> : <p className="mt-4 rounded-xl bg-white p-3 text-sm text-slate-500">Belum ada perubahan progress yang dicatat untuk sesi ini.</p>}
                {session.progress_notes && <p className="mt-3 text-sm leading-6 text-slate-600"><b>Catatan sesi:</b> {session.progress_notes}</p>}
                {session.progress_recorded_at && <p className="mt-2 text-[11px] font-semibold text-slate-400">Dicatat {dateTime(session.progress_recorded_at)}</p>}
              </article>
            ))}
          </div>
        </section>
      )}
    </div>
  );
}

function CheapChapterRow({ chapter }: { chapter: CheapClassChapterProgress }) {
  const done = chapter.progress_status === "completed";
  const active = chapter.progress_status === "in_progress";
  const Icon = done ? (chapter.needs_review ? RotateCcw : CheckCircle2) : active ? BookOpen : Circle;
  return <article className={`rounded-2xl border p-4 ${done ? "border-emerald-100 bg-emerald-50/60" : active ? "border-indigo-100 bg-indigo-50/70" : "border-slate-100 bg-slate-50"}`}><div className="flex items-start gap-3"><span className={`grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-white ${done ? (chapter.needs_review ? "text-amber-600" : "text-emerald-600") : active ? "text-indigo-700" : "text-slate-300"}`}><Icon size={18} /></span><div className="min-w-0 flex-1"><p className="text-[10px] font-black uppercase tracking-wider text-slate-400">{chapter.subject_name}</p><h3 className="mt-1 break-words font-black text-slate-900">{chapter.chapter || "Bab belum diberi nama"}</h3><p className={`mt-1 text-xs font-bold ${done && chapter.needs_review ? "text-amber-700" : done ? "text-emerald-700" : active ? "text-indigo-700" : "text-slate-400"}`}>{materialStatusLabel(chapter.progress_status, chapter.needs_review)}</p>{chapter.progress_notes && <p className="mt-3 rounded-xl bg-white/80 p-3 text-sm leading-6 text-slate-600">{chapter.progress_notes}</p>}{chapter.progress_updated_at && <p className="mt-2 text-[11px] font-semibold text-slate-400">Diperbarui {dateTime(chapter.progress_updated_at)}</p>}</div></div></article>;
}

function ProgressTabs({ value, onChange }: { value: DetailTab; onChange: (value: DetailTab) => void }) {
  return <div className="grid grid-cols-2 gap-1.5 rounded-2xl border border-indigo-100 bg-indigo-50/70 p-1.5"><button type="button" onClick={() => onChange("material")} className={`min-h-11 rounded-xl px-3 text-sm font-black transition ${value === "material" ? "bg-indigo-600 text-white shadow-sm" : "text-slate-500 hover:bg-white hover:text-indigo-700"}`}><BookOpen size={16} className="mr-2 inline" />Materi</button><button type="button" onClick={() => onChange("history")} className={`min-h-11 rounded-xl px-3 text-sm font-black transition ${value === "history" ? "bg-indigo-600 text-white shadow-sm" : "text-slate-500 hover:bg-white hover:text-indigo-700"}`}><History size={16} className="mr-2 inline" />Riwayat Sesi</button></div>;
}

function ProgressChangeRow({ chapter }: { chapter: ProgressHistoryChapter }) {
  return <div className="rounded-xl bg-white p-3"><p className="text-sm font-black text-slate-900">{chapter.chapter}</p><p className="mt-2 text-xs font-semibold text-slate-500">{topicStatusLabel(chapter.status_before)} <span className="px-1 text-indigo-400">→</span> <span className="font-black text-indigo-700">{topicStatusLabel(chapter.status_after, Boolean(chapter.needs_review))}</span></p>{chapter.notes && <p className="mt-2 text-xs leading-5 text-slate-500">{chapter.notes}</p>}</div>;
}

function ProgressHeader({ type, title, subtitle, percent, accent: _accent, progressLabel = "Progress materi" }: { type: string; title: string; subtitle: string; percent: number; accent: "brand"; progressLabel?: string }) {
  return <section className="relative overflow-hidden rounded-[1.75rem] bg-gradient-to-br from-slate-950 via-indigo-950 to-blue-800 p-5 text-white shadow-[0_24px_64px_rgba(15,23,42,0.20)] sm:rounded-[2rem] sm:p-8"><div aria-hidden="true" className="pointer-events-none absolute -right-20 -top-24 h-64 w-64 rounded-full bg-blue-400/20 blur-3xl" /><div aria-hidden="true" className="pointer-events-none absolute -bottom-28 left-1/3 h-56 w-56 rounded-full bg-indigo-400/15 blur-3xl" /><div className="relative"><Link to="/student/progress" className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-white/10 bg-white/[0.08] px-3 text-xs font-black text-white transition hover:bg-white/15"><ArrowLeft size={15} /> Semua Progress</Link><div className="mt-6 flex flex-col justify-between gap-6 md:flex-row md:items-end"><div className="min-w-0"><p className="text-xs font-black uppercase tracking-[.18em] text-blue-200">{type}</p><h1 className="mt-2 break-words text-2xl font-black tracking-tight sm:text-3xl">{title}</h1><p className="mt-2 break-words text-sm text-slate-300">{subtitle}</p></div><div className="w-full rounded-2xl border border-white/10 bg-white/[0.08] p-4 backdrop-blur-sm md:max-w-xs"><div className="flex items-end justify-between gap-3"><p className="text-xs font-black uppercase tracking-wider text-slate-300">{progressLabel}</p><p className="text-3xl font-black text-blue-200">{percent}%</p></div><div className="mt-3 h-2.5 overflow-hidden rounded-full bg-white/10"><div className="h-full rounded-full bg-gradient-to-r from-blue-500 to-indigo-500 transition-[width] duration-500" style={{ width: `${percent}%` }} /></div></div></div></div></section>;
}

function SummaryCard({ label, value, icon: Icon }: { label: string; value: string | number; icon: LucideIcon }) {
  return <div className="min-w-0 rounded-2xl border border-indigo-100 bg-gradient-to-br from-white to-indigo-50/60 p-4 shadow-[0_10px_28px_rgba(15,23,42,0.04)]"><div className="flex items-center gap-2 text-slate-400"><Icon size={16} /><p className="text-[10px] font-black uppercase tracking-wider">{label}</p></div><p className="mt-2 break-words text-lg font-black text-slate-900">{value}</p></div>;
}
