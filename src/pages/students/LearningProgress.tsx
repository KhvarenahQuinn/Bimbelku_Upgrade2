import { useCallback, useEffect, useMemo, useState, type ReactNode } from "react";
import { Link } from "react-router-dom";
import {
  ArrowRight,
  BarChart3,
  BookOpenCheck,
  CalendarDays,
  GraduationCap,
  History,
  Loader2,
  RefreshCw,
  UserRound,
  Users,
  WifiOff,
} from "lucide-react";
import StudentLayout from "@/components/StudentLayout";
import { Button } from "@/components/ui/button";
import http, { getApiError, getCached } from "@/lib/http";
import { notify } from "@/lib/notify";
import WorkspacePageIntro from "@/components/WorkspacePageIntro";
import {
  CheapClassProgress,
  PackageListResponse,
  StudentPackageProgress,
  cheapClassChapterStats,
  isCheapClassProgressVisible,
  isPackageProgressVisible,
  packageChapterStats,
  readPackageRows,
} from "@/lib/studentProgress";

type Filter = "all" | "package" | "cheap_class";
type ProgressProgram =
  | { kind: "package"; item: StudentPackageProgress }
  | { kind: "cheap_class"; item: CheapClassProgress };

const statusLabel: Record<string, string> = {
  matching: "Tutor sedang dicari",
  teacher_pending: "Menunggu tutor",
  no_teacher: "Tutor belum tersedia",
  active: "Aktif",
  confirmed: "Aktif",
  completed: "Selesai",
};

const statusTone = (status: string) => status === "completed"
  ? "border-slate-200 bg-white text-slate-600"
  : "border-emerald-100 bg-emerald-50 text-emerald-700";

const dateTime = (value?: string | null) => value
  ? new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short" }).format(new Date(value))
  : "Jadwal belum tersedia";

export default function LearningProgress() {
  const [packages, setPackages] = useState<StudentPackageProgress[]>([]);
  const [cheapClasses, setCheapClasses] = useState<CheapClassProgress[]>([]);
  const [filter, setFilter] = useState<Filter>("all");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);

  const load = useCallback(async (force = false, silent = false) => {
    if (!silent) {
      setLoading(true);
      setError(false);
    }
    try {
      const [packageResponse, cheapClassResponse] = await Promise.all([
        getCached<PackageListResponse>("/student/packages", { params: { per_page: 100 }, maxAgeMs: 10_000, force }),
        http.get<CheapClassProgress[]>("/student/cheap-classes", { params: { scope: "progress" } }),
      ]);
      setPackages(readPackageRows(packageResponse.data).filter(isPackageProgressVisible));
      setCheapClasses((Array.isArray(cheapClassResponse.data) ? cheapClassResponse.data : []).filter(isCheapClassProgressVisible));
    } catch (requestError) {
      if (!silent) {
        setError(true);
        notify.error(getApiError(requestError, "Perkembangan belajar gagal dimuat."));
      }
    } finally {
      if (!silent) setLoading(false);
    }
  }, []);

  useEffect(() => { void load(); }, [load]);

  useEffect(() => {
    const refresh = () => {
      if (document.visibilityState === "visible") void load(true, true);
    };
    window.addEventListener("focus", refresh);
    window.addEventListener("bimbelku:data-changed", refresh);
    const timer = window.setInterval(refresh, 30_000);
    return () => {
      window.removeEventListener("focus", refresh);
      window.removeEventListener("bimbelku:data-changed", refresh);
      window.clearInterval(timer);
    };
  }, [load]);

  const programs = useMemo<ProgressProgram[]>(() => {
    const rows: ProgressProgram[] = [
      ...packages.map((item): ProgressProgram => ({ kind: "package", item })),
      ...cheapClasses.map((item): ProgressProgram => ({ kind: "cheap_class", item })),
    ];
    return rows.filter((row) => filter === "all" || row.kind === filter);
  }, [cheapClasses, filter, packages]);

  const activeCount = packages.filter((item) => item.status !== "completed").length
    + cheapClasses.filter((item) => item.status !== "completed").length;
  const completedCount = packages.filter((item) => item.status === "completed").length
    + cheapClasses.filter((item) => item.status === "completed").length;

  return (
    <StudentLayout title="Perkembangan Belajar">
      <div className="w-full space-y-5 pb-10 sm:space-y-6">
        <WorkspacePageIntro eyebrow="Ruang belajar" title="Perkembangan Belajar" description="Pantau posisi materi tiap program dan buka rincian progress per Bab serta riwayat setiap sesi." icon={BarChart3} metrics={[{ label: "Berjalan", value: activeCount }, { label: "Selesai", value: completedCount }, { label: "Total program", value: packages.length + cheapClasses.length }]} />

        <section className="flex flex-col gap-3 rounded-2xl border border-indigo-100 bg-white p-3 shadow-sm sm:flex-row sm:items-center sm:justify-between sm:p-4">
          <div className="flex min-w-0 gap-2 overflow-x-auto pb-1 sm:pb-0">
            <FilterButton active={filter === "all"} onClick={() => setFilter("all")}>Semua</FilterButton>
            <FilterButton active={filter === "package"} onClick={() => setFilter("package")}>Paket Belajar</FilterButton>
            <FilterButton active={filter === "cheap_class"} onClick={() => setFilter("cheap_class")}>Kelas Bersama</FilterButton>
          </div>
          <button type="button" onClick={() => void load(true)} className="inline-flex min-h-10 shrink-0 items-center justify-center gap-2 rounded-xl px-3 text-xs font-black text-slate-600 transition hover:bg-indigo-50 hover:text-indigo-700">
            <RefreshCw size={15} /> Muat ulang
          </button>
        </section>

        {loading ? (
          <div role="status" className="grid min-h-64 place-items-center rounded-[1.75rem] border border-indigo-100 bg-white">
            <div className="text-center"><Loader2 className="mx-auto animate-spin text-indigo-600" size={34} /><p className="mt-3 text-sm font-bold text-slate-500">Memuat progress…</p></div>
          </div>
        ) : error ? (
          <div className="rounded-[1.75rem] border border-rose-100 bg-white px-5 py-14 text-center">
            <WifiOff className="mx-auto text-rose-300" size={38} />
            <h2 className="mt-4 font-black text-slate-800">Progress belum berhasil dimuat</h2>
            <p className="mt-2 text-sm text-slate-500">Periksa koneksi lalu coba lagi.</p>
            <Button onClick={() => void load(true)} className="mt-5 rounded-xl bg-indigo-600 hover:bg-indigo-700"><RefreshCw size={16} className="mr-2" />Coba lagi</Button>
          </div>
        ) : programs.length === 0 ? (
          <div className="rounded-[1.75rem] border-2 border-dashed border-indigo-100 bg-indigo-50/40 px-5 py-14 text-center sm:py-20">
            <GraduationCap className="mx-auto text-indigo-300" size={42} />
            <h2 className="mt-4 text-lg font-black text-slate-800">Belum ada program yang memiliki progress</h2>
            <p className="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">Progress mulai tersedia setelah pembayaran diterima dan program belajar masuk tahap pencocokan atau sudah aktif.</p>
            <Button asChild className="mt-5 rounded-xl bg-indigo-600 hover:bg-indigo-700"><Link to="/student/packages">Lihat proses paket</Link></Button>
          </div>
        ) : (
          <div className="grid gap-4">
            {programs.map((program) => program.kind === "package"
              ? <PackageProgressCard key={`package-${program.item.id}`} item={program.item} />
              : <CheapClassProgressCard key={`cheap-${program.item.id}`} item={program.item} />)}
          </div>
        )}
      </div>
    </StudentLayout>
  );
}

function PackageProgressCard({ item }: { item: StudentPackageProgress }) {
  const stats = packageChapterStats(item);
  const subjects = item.subjects || [];
  const names = subjects.map((subject) => subject.name).filter(Boolean).join(" · ");
  const teachers = subjects.map((subject) => subject.teacher).filter((teacher): teacher is NonNullable<typeof teacher> => Boolean(teacher));
  const firstTeacher = teachers[0];
  const teacherLabel = firstTeacher ? (teachers.length > 1 ? `${firstTeacher.name} +${teachers.length - 1} tutor` : firstTeacher.name) : "Tutor akan ditampilkan setelah terhubung";
  const activeChapters = subjects.flatMap((subject) => (subject.learning_chapters || []).filter((chapter) => chapter.status === "in_progress" || chapter.status === "review_needed"));
  const nextChapter = activeChapters[0] || subjects.flatMap((subject) => subject.learning_chapters || []).find((chapter) => chapter.status !== "completed");

  return (
    <article className="group overflow-hidden rounded-[1.55rem] border border-indigo-100 bg-gradient-to-br from-white via-indigo-50/55 to-blue-50/70 shadow-sm transition duration-300 hover:-translate-y-0.5 hover:border-indigo-200 hover:shadow-lg">
      <div className="h-1 bg-gradient-to-r from-blue-500 via-indigo-600 to-violet-500" />
      <div className="p-4 sm:p-5">
        <div className="flex items-start gap-3 sm:gap-4">
          <div className="grid h-12 w-12 shrink-0 place-items-center overflow-hidden rounded-2xl bg-indigo-100 text-indigo-700 ring-1 ring-indigo-200/70 sm:h-14 sm:w-14">
            {firstTeacher?.avatar_url ? <img src={firstTeacher.avatar_url} alt="" loading="lazy" decoding="async" className="h-full w-full object-cover" /> : <UserRound size={22} />}
          </div>
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
              <p className="text-[10px] font-black uppercase tracking-[.16em] text-indigo-600">{names || "Paket Belajar"}</p>
              <span className="text-indigo-200">•</span>
              <span className="text-[10px] font-black uppercase tracking-wider text-slate-400">Paket Belajar</span>
            </div>
            <div className="mt-1 flex flex-wrap items-start justify-between gap-2">
              <h2 className="min-w-0 break-words text-base font-black text-slate-900 sm:text-lg">{item.plan?.name || "Paket Belajar"}</h2>
              <span className={`shrink-0 rounded-full border px-3 py-1 text-[10px] font-black uppercase ${statusTone(item.status)}`}>{statusLabel[item.status] || item.status}</span>
            </div>
            <p className="mt-1 text-xs font-semibold text-slate-500">Tutor {teacherLabel}</p>
          </div>
        </div>

        <div className="mt-4 rounded-2xl border border-indigo-100/80 bg-white/85 p-4 shadow-[0_8px_24px_rgba(79,70,229,0.06)] backdrop-blur-sm">
          <div className="flex items-center justify-between gap-3"><span className="text-xs font-black text-slate-700">{stats.tracksChapters ? "Progress materi" : "Pertemuan selesai"}</span><span className="text-sm font-black text-indigo-700">{stats.percent}%</span></div>
          <div className="mt-2 h-2.5 overflow-hidden rounded-full bg-indigo-50"><div className="h-full rounded-full bg-gradient-to-r from-blue-500 to-indigo-600 transition-[width] duration-500" style={{ width: `${stats.percent}%` }} /></div>
          <div className="mt-3 grid gap-2 border-t border-indigo-100 pt-3 sm:grid-cols-[1fr_auto] sm:items-end">
            <div className="min-w-0">
              <p className="text-[10px] font-black uppercase tracking-wider text-slate-400">Fokus materi saat ini</p>
              <p className="mt-1 line-clamp-2 text-sm font-bold leading-5 text-slate-700">{nextChapter ? nextChapter.chapter : stats.total > 0 ? "Seluruh Bab sudah selesai" : (item.subjects || []).map((subject) => subject.learning_goal).filter(Boolean).join(" · ") || "Materi disesuaikan bersama tutor"}</p>
            </div>
            <p className="text-[11px] font-bold text-slate-400">{stats.tracksChapters ? `${stats.completed}/${stats.total} Bab selesai` : "Hasil belajar dicatat per sesi"}</p>
          </div>
        </div>

        <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs font-semibold text-slate-500">
          <span className="inline-flex items-center gap-1.5"><CalendarDays size={14} className="text-indigo-500" />{item.used_sessions}/{item.total_sessions} pertemuan selesai</span>
          {stats.tracksChapters && <span className="inline-flex items-center gap-1.5"><History size={14} className="text-indigo-500" />{stats.inProgress} Bab sedang dipelajari</span>}
        </div>

        <div className="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <p className="break-all text-[11px] font-semibold text-slate-400">{item.package_code}</p>
          <Link to={`/student/progress/package/${item.id}`} className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-indigo-600 px-5 text-sm font-black text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 focus-visible:ring-offset-2">
            Lihat laporan lengkap <ArrowRight size={16} />
          </Link>
        </div>
      </div>
    </article>
  );
}

function CheapClassProgressCard({ item }: { item: CheapClassProgress }) {
  const stats = cheapClassChapterStats(item);
  const teacherName = item.teacher?.name || "Tutor BimbelKu";
  const sessions = item.sessions || [];
  const latestProgressSession = [...sessions].filter((session) => session.progress_recorded_at).sort((a, b) => b.session_number - a.session_number)[0];
  const activeChapter = (item.subjects || []).find((chapter) => chapter.progress_status === "in_progress")
    || (item.subjects || []).find((chapter) => chapter.progress_status !== "completed");

  return (
    <article className="group overflow-hidden rounded-[1.55rem] border border-indigo-100 bg-gradient-to-br from-white via-blue-50/55 to-indigo-50/70 shadow-sm transition duration-300 hover:-translate-y-0.5 hover:border-indigo-200 hover:shadow-lg">
      <div className="h-1 bg-gradient-to-r from-cyan-500 via-blue-500 to-indigo-600" />
      <div className="p-4 sm:p-5">
        <div className="flex items-start gap-3 sm:gap-4">
          <div className="grid h-12 w-12 shrink-0 place-items-center overflow-hidden rounded-2xl bg-blue-100 text-blue-700 ring-1 ring-blue-200/70 sm:h-14 sm:w-14">
            {item.teacher?.photo ? <img src={item.teacher.photo} alt="" loading="lazy" decoding="async" className="h-full w-full object-cover" /> : <Users size={22} />}
          </div>
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2"><p className="text-[10px] font-black uppercase tracking-[.16em] text-indigo-600">{item.subject_name}</p><span className="text-indigo-200">•</span><span className="text-[10px] font-black uppercase tracking-wider text-slate-400">Kelas Bersama</span></div>
            <div className="mt-1 flex flex-wrap items-start justify-between gap-2">
              <h2 className="min-w-0 break-words text-base font-black text-slate-900 sm:text-lg">{[item.education_level, item.grade].filter(Boolean).join(" · ") || "Kelas belajar bersama"}</h2>
              <span className={`shrink-0 rounded-full border px-3 py-1 text-[10px] font-black uppercase ${statusTone(item.status)}`}>{statusLabel[item.status] || item.status}</span>
            </div>
            <p className="mt-1 text-xs font-semibold text-slate-500">Tutor {teacherName} · {dateTime(item.starts_at)}</p>
          </div>
        </div>

        <div className="mt-4 rounded-2xl border border-indigo-100/80 bg-white/85 p-4 shadow-[0_8px_24px_rgba(79,70,229,0.06)] backdrop-blur-sm">
          <div className="flex items-center justify-between gap-3"><span className="text-xs font-black text-slate-700">Progress bab</span><span className="text-sm font-black text-indigo-700">{stats.percent}%</span></div>
          <div className="mt-2 h-2.5 overflow-hidden rounded-full bg-indigo-50"><div className="h-full rounded-full bg-gradient-to-r from-blue-500 to-indigo-600 transition-[width] duration-500" style={{ width: `${stats.percent}%` }} /></div>
          <div className="mt-3 border-t border-indigo-100 pt-3">
            <p className="text-[10px] font-black uppercase tracking-wider text-slate-400">{latestProgressSession ? `Catatan sesi ${latestProgressSession.session_number}` : "Fokus materi saat ini"}</p>
            <p className="mt-1 line-clamp-2 text-sm font-bold leading-5 text-slate-700">{latestProgressSession?.progress_notes || (activeChapter ? activeChapter.chapter : stats.total > 0 ? "Seluruh bab sudah selesai" : "Bab belum disusun")}</p>
            <p className="mt-2 text-[11px] font-bold text-slate-400">{stats.completed}/{stats.total} bab selesai · {sessions.filter((session) => session.status === "completed").length}/{item.session_count} sesi selesai</p>
          </div>
        </div>

        <div className="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <p className="break-all text-[11px] font-semibold text-slate-400">{item.package_code || "Progress berlaku untuk peserta kelas ini"}</p>
          <Link to={`/student/progress/cheap-class/${item.id}`} className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-indigo-600 px-5 text-sm font-black text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 focus-visible:ring-offset-2">
            Lihat laporan lengkap <ArrowRight size={16} />
          </Link>
        </div>
      </div>
    </article>
  );
}

function FilterButton({ active, onClick, children }: { active: boolean; onClick: () => void; children: ReactNode }) {
  return <button type="button" onClick={onClick} className={`min-h-10 shrink-0 rounded-xl px-4 text-xs font-black transition ${active ? "bg-indigo-600 text-white shadow-sm" : "bg-slate-50 text-slate-600 hover:bg-indigo-50 hover:text-indigo-700"}`}>{children}</button>;
}
