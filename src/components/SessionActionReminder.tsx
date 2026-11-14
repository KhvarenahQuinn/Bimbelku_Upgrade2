import { useCallback, useEffect, useMemo, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import {
  AlertTriangle,
  BookOpen,
  CheckCircle2,
  ChevronRight,
  ClipboardCheck,
  Clock3,
  Minimize2,
  PlayCircle,
  RefreshCcw,
  UserCheck,
  NotebookPen,
  LogOut,
  X,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import http, { getCached } from "@/lib/http";

type SessionActionKind =
  | "student_confirm_presence"
  | "student_session_started"
  | "student_review_session"
  | "teacher_mark_ready"
  | "teacher_waiting_student_presence"
  | "teacher_check_out"
  | "teacher_report_progress"
  | "teacher_session_ready"
  | "cheap_teacher_report_required"
  | "cheap_teacher_mark_ready"
  | "cheap_teacher_session_started"
  | "cheap_teacher_revision_requested"
  | "cheap_admin_verify_report"
  | "cheap_student_progress_updated"
  | "cheap_student_session_started"
  | "cheap_student_class_completed";

type SessionAction = {
  action_key: string;
  booking_id?: number;
  cheap_class_id?: number | null;
  cheap_class_session_id?: number | null;
  session_number?: number | null;
  notification_id?: number | null;
  kind: SessionActionKind;
  subject: string;
  teacher_name?: string;
  start_at?: string;
  end_at?: string;
  target_url: string;
  admin_review_notes?: string | null;
  attended_participants_count?: number | null;
  informational?: boolean;
  secondary_kind?: "student_teacher_late" | "student_dispute" | null;
};

type SessionActionResponse = {
  data: SessionAction | null;
  meta?: {
    pending_count?: number;
    refreshed_at?: string;
  };
};

const copyFor = (action: SessionAction) => {
  switch (action.kind) {
    case "student_confirm_presence":
      return {
        eyebrow: "Tutor sudah siap",
        title: "Kamu sudah hadir?",
        description: "Konfirmasi dengan satu tap jika kamu sudah bersama tutor atau sudah siap mengikuti kelas online.",
        button: "Saya Sudah Hadir",
        Icon: CheckCircle2,
        tone: "emerald" as const,
      };
    case "student_session_started":
      return {
        eyebrow: "Sesi dimulai",
        title: "Kelasmu sedang berlangsung",
        description: "Kehadiranmu sudah tercatat. Masuk ke ruang belajar dan ikuti arahan tutor sampai sesi selesai.",
        button: "Buka ruang belajar",
        Icon: PlayCircle,
        tone: "emerald" as const,
      };
    case "student_review_session":
      return {
        eyebrow: "Butuh keputusanmu",
        title: "Cek sesi yang baru selesai",
        description: "Tutor sudah menyimpan hasil belajar. Periksa ringkasannya lalu pilih Sesi Sesuai atau Ada masalah.",
        button: "Periksa sesi",
        Icon: CheckCircle2,
        tone: "amber" as const,
      };
    case "teacher_mark_ready":
      return {
        eyebrow: "Sesi segera dimulai",
        title: "Sudah siap mengajar?",
        description: "Kirim status siap ke murid. Sesi resmi dimulai setelah murid mengonfirmasi kehadiran.",
        button: "Saya Siap Mengajar",
        Icon: PlayCircle,
        tone: "indigo" as const,
      };
    case "teacher_waiting_student_presence":
      return {
        eyebrow: "Menunggu murid",
        title: "Kesiapanmu sudah terkirim",
        description: "Tidak perlu melakukan apa-apa lagi. Sesi mulai otomatis setelah murid menekan Saya Sudah Hadir.",
        button: "Lihat ruang belajar",
        Icon: Clock3,
        tone: "amber" as const,
      };
    case "teacher_session_ready":
      return {
        eyebrow: "Persiapan selesai",
        title: "Sesi sedang berlangsung. Selamat mengajar!",
        description: "Kehadiran sudah beres. Fokus mengajar dulu; BimbelKu akan mengingatkan lagi saat waktunya menutup sesi.",
        button: "Buka ruang belajar",
        Icon: CheckCircle2,
        tone: "emerald" as const,
      };
    case "teacher_check_out":
      return {
        eyebrow: "Jadwal sesi hampir/ sudah berakhir",
        title: "Sesi sudah selesai?",
        description: "Kalau kegiatan belajar sudah berakhir, akhiri sesi agar durasi pengajaran tercatat.",
        button: "Akhiri sesi",
        Icon: LogOut,
        tone: "emerald" as const,
      };
    case "teacher_report_progress":
      return {
        eyebrow: "Tinggal satu langkah akademik",
        title: "Isi hasil belajar murid",
        description: "Catat materi dan perkembangan hari ini. Hasil belajar wajib tersimpan sebelum sesi dapat diselesaikan.",
        button: "Isi hasil belajar",
        Icon: NotebookPen,
        tone: "indigo" as const,
      };
    case "cheap_teacher_report_required":
      return {
        eyebrow: "Kelas sudah selesai",
        title: "Isi laporan pertemuan",
        description: `Sesi ${action.session_number || "ini"} sudah berakhir. Isi jumlah murid hadir, progress bab, dan catatan singkat sebelum tugas pertemuan dianggap selesai.`,
        button: "Isi laporan sekarang",
        Icon: ClipboardCheck,
        tone: "indigo" as const,
      };
    case "cheap_teacher_mark_ready":
      return {
        eyebrow: "Waktunya mengajar",
        title: "Konfirmasi hadir dan mulai kelas",
        description: "Tekan satu kali saat kamu sudah siap di Zoom. Jam kehadiran pertama akan tercatat dan seluruh murid diberi tahu.",
        button: "Buka Kelas Bersama",
        Icon: UserCheck,
        tone: "indigo" as const,
      };
    case "cheap_teacher_session_started":
      return {
        eyebrow: "Kehadiran tercatat",
        title: "Kelas Bersama sedang berlangsung",
        description: "Murid sudah diberi tahu. Buka Zoom dan mulai mengajar sesuai materi sesi hari ini.",
        button: "Buka halaman kelas",
        Icon: PlayCircle,
        tone: "emerald" as const,
      };
    case "cheap_teacher_revision_requested":
      return {
        eyebrow: "Perlu diperbaiki",
        title: "Admin mengembalikan laporan",
        description: action.admin_review_notes
          ? `Catatan admin: ${action.admin_review_notes}`
          : "Periksa kembali laporan pertemuan, perbaiki bagian yang diminta, lalu kirim ulang ke admin.",
        button: "Perbaiki laporan",
        Icon: RefreshCcw,
        tone: "amber" as const,
      };
    case "cheap_admin_verify_report":
      return {
        eyebrow: "Perlu dicek",
        title: "Laporan Kelas Bersama menunggu verifikasi",
        description: `${action.teacher_name || "Tutor"} sudah mengirim laporan sesi ${action.session_number || ""}. Cek kehadiran, progress, dan catatannya lalu konfirmasi atau minta perbaikan.`,
        button: "Periksa laporan",
        Icon: ClipboardCheck,
        tone: "amber" as const,
      };
    case "cheap_student_progress_updated":
      return {
        eyebrow: "Progress terbaru",
        title: "Progress kelasmu sudah diperbarui",
        description: `Pertemuan ${action.session_number || "terbaru"} sudah diverifikasi admin. Kamu bisa melihat materi yang sudah dipelajari dan catatan terbarunya.`,
        button: "Lihat progress",
        Icon: BookOpen,
        tone: "emerald" as const,
      };
    case "cheap_student_session_started":
      return {
        eyebrow: "Tutor sudah hadir",
        title: "Kelas Bersama sudah dimulai",
        description: `Sesi ${action.session_number || "ini"} sedang berlangsung. Buka Kelas Saya lalu masuk ke Zoom untuk mulai belajar.`,
        button: "Gabung belajar",
        Icon: PlayCircle,
        tone: "emerald" as const,
      };
    case "cheap_student_class_completed":
      return {
        eyebrow: "Kelas selesai",
        title: "Semua pertemuan sudah selesai",
        description: "Seluruh sesi Kelas Bersama sudah diverifikasi admin. Progress akhir dan riwayat belajarmu tetap bisa dilihat kapan saja.",
        button: "Lihat progress akhir",
        Icon: CheckCircle2,
        tone: "emerald" as const,
      };
  }
};

const acknowledgeOnOpen = new Set<SessionActionKind>([
  "teacher_session_ready",
  "student_session_started",
  "cheap_teacher_session_started",
  "cheap_student_session_started",
]);

const formattedSchedule = (value?: string) => {
  if (!value) return "";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "";
  return new Intl.DateTimeFormat("id-ID", {
    weekday: "short",
    hour: "2-digit",
    minute: "2-digit",
  }).format(date);
};

export default function SessionActionReminder() {
  const navigate = useNavigate();
  const location = useLocation();
  const [action, setAction] = useState<SessionAction | null>(null);
  const [pendingCount, setPendingCount] = useState(0);
  const [collapsed, setCollapsed] = useState(false);
  const [loaded, setLoaded] = useState(false);

  const role = (() => {
    try {
      const stored = JSON.parse(localStorage.getItem("user") || "null") as { role?: string } | null;
      return stored?.role || "";
    } catch {
      return "";
    }
  })();

  const load = useCallback(async (force = false) => {
    if (!localStorage.getItem("token") || !["student", "teacher", "admin"].includes(role)) {
      setAction(null);
      setPendingCount(0);
      setLoaded(true);
      return;
    }

    try {
      // maxAgeMs sudah meredam polling 8 detik. `force` hanya dipakai saat aksi
      // sesi baru selesai, supaya mount + focus + data-changed tidak
      // menghasilkan tiga request beruntun.
      const response = await getCached<SessionActionResponse>("/session-action/next", {
        maxAgeMs: 5_000,
        force,
      });
      const rawNext = response.data?.data || null;
      const next = rawNext && acknowledgeOnOpen.has(rawNext.kind)
        && sessionStorage.getItem(`bimbelku:session-action:ack:${rawNext.action_key}`) === "1"
        ? null
        : rawNext;
      setAction((previous) => {
        if (next?.action_key && next.action_key !== previous?.action_key) {
          const saved = sessionStorage.getItem(`bimbelku:session-action:collapsed:${next.action_key}`);
          setCollapsed(saved === "1");
        }
        if (!next) setCollapsed(false);
        return next;
      });
      setPendingCount(Number(response.data?.meta?.pending_count || 0));
    } catch {
      // Reminder tidak boleh mengganggu halaman utama saat jaringan sedang bermasalah.
    } finally {
      setLoaded(true);
    }
  }, [role]);

  useEffect(() => {
    void load();
    // Fokus jendela dan event data sering datang beruntun; debounce supaya
    // satu burst hanya menghasilkan satu request.
    let debounceTimer: number | null = null;
    const refresh = () => {
      if (debounceTimer) window.clearTimeout(debounceTimer);
      debounceTimer = window.setTimeout(() => void load(), 250);
    };
    // Aksi sesi yang baru selesai harus langsung terlihat, bukan menunggu cache.
    const onDataChanged = () => {
      if (debounceTimer) window.clearTimeout(debounceTimer);
      debounceTimer = null;
      void load(true);
    };
    const onFocus = () => refresh();
    window.addEventListener("bimbelku:data-changed", onDataChanged);
    window.addEventListener("focus", onFocus);
    const timer = window.setInterval(() => {
      if (document.visibilityState === "visible") void load();
    }, 8_000);
    return () => {
      window.removeEventListener("bimbelku:data-changed", onDataChanged);
      window.removeEventListener("focus", onFocus);
      window.clearInterval(timer);
      if (debounceTimer) window.clearTimeout(debounceTimer);
    };
  }, [load]);

  const hideBecauseAlreadyHandling = useMemo(() => {
    if (!action) return false;

    try {
      const params = new URLSearchParams(location.search);
      const target = new URL(action.target_url, window.location.origin);

      if (action.booking_id) {
        return Number(params.get("session")) === action.booking_id
          && Boolean(params.get("session_action"))
          && params.get("session_action") === target.searchParams.get("session_action");
      }

      if (action.cheap_class_id && action.kind.startsWith("cheap_teacher_")) {
        return location.pathname === target.pathname
          && Number(params.get("cheap_class")) === action.cheap_class_id
          && params.get("cheap_action") === target.searchParams.get("cheap_action");
      }

      if (action.kind === "cheap_admin_verify_report") {
        return location.pathname === target.pathname
          && params.get("status") === "awaiting_admin_verification";
      }

      if (action.cheap_class_id && action.kind.startsWith("cheap_student_")) {
        const targetSessionId = Number(target.searchParams.get("cheap_session"));
        return location.pathname === target.pathname
          && Number(params.get("cheap_class")) === action.cheap_class_id
          && (!targetSessionId || Number(params.get("cheap_session")) === targetSessionId);
      }

      if (action.informational) {
        return location.pathname === target.pathname;
      }
    } catch {
      return false;
    }

    return false;
  }, [action, location.pathname, location.search]);

  if (!loaded || !action || hideBecauseAlreadyHandling || ["/login", "/register", "/payment"].includes(location.pathname)) {
    return null;
  }

  const copy = copyFor(action);
  const ToneIcon = copy.Icon;
  const isAmber = copy.tone === "amber";
  const isEmerald = copy.tone === "emerald";
  const accent = isAmber
    ? "border-amber-200 bg-amber-50 text-amber-800"
    : isEmerald
      ? "border-emerald-200 bg-emerald-50 text-emerald-800"
      : "border-indigo-200 bg-indigo-50 text-indigo-800";
  const buttonClass = isAmber
    ? "bg-amber-500 text-slate-950 hover:bg-amber-600"
    : isEmerald
      ? "bg-emerald-600 hover:bg-emerald-700"
      : "bg-indigo-600 hover:bg-indigo-700";

  const dismissInformational = async () => {
    if (action && acknowledgeOnOpen.has(action.kind)) {
      sessionStorage.setItem(`bimbelku:session-action:ack:${action.action_key}`, "1");
    }
    if (!action?.notification_id) {
      setAction(null);
      return;
    }

    try {
      await http.post(`/notifications/${action.notification_id}/read`);
      window.dispatchEvent(new Event("bimbelku:data-changed"));
    } catch {
      // Jika gagal ditandai dibaca, polling berikutnya akan menampilkan lagi.
    } finally {
      setAction(null);
    }
  };

  const minimize = () => {
    sessionStorage.setItem(`bimbelku:session-action:collapsed:${action.action_key}`, "1");
    setCollapsed(true);
  };
  const expand = () => {
    sessionStorage.removeItem(`bimbelku:session-action:collapsed:${action.action_key}`);
    setCollapsed(false);
  };

  if (collapsed) {
    return (
      <button
        type="button"
        onClick={expand}
        className="fixed left-3 right-3 top-[calc(4.75rem+env(safe-area-inset-top))] z-[25] flex min-h-12 items-center justify-between gap-3 rounded-2xl border border-indigo-100 bg-white px-4 py-3 text-left shadow-[0_16px_40px_rgba(15,23,42,.16)] sm:bottom-5 sm:left-5 sm:right-auto sm:top-auto sm:w-[min(360px,calc(100vw-2.5rem))] xl:left-[19rem]"
        aria-label="Buka pengingat sesi"
      >
        <span className="flex min-w-0 items-center gap-3">
          <span className={`grid h-9 w-9 shrink-0 place-items-center rounded-xl border ${accent}`}><ToneIcon size={17} /></span>
          <span className="min-w-0">
            <span className="block truncate text-xs font-black text-slate-900">{copy.title}</span>
            <span className="block truncate text-[11px] font-semibold text-slate-500">{action.subject}{pendingCount > 1 ? ` · ${pendingCount} pengingat` : ""}</span>
          </span>
        </span>
        <ChevronRight size={18} className="shrink-0 text-indigo-500" />
      </button>
    );
  }

  return (
    <aside className="fixed left-3 right-3 top-[calc(4.75rem+env(safe-area-inset-top))] z-[25] sm:bottom-5 sm:left-5 sm:right-auto sm:top-auto sm:w-[min(380px,calc(100vw-2.5rem))] xl:left-[19rem]" aria-live="polite">
      <div className="overflow-hidden rounded-[1.6rem] border border-slate-200 bg-white shadow-[0_24px_60px_rgba(15,23,42,.18)]">
        <div className={`flex items-center justify-between gap-3 border-b px-4 py-3 ${accent}`}>
          <div className="flex min-w-0 items-center gap-2">
            <ToneIcon size={17} className="shrink-0" />
            <span className="truncate text-[10px] font-black uppercase tracking-[.16em]">{copy.eyebrow}</span>
          </div>
          <button
            type="button"
            onClick={() => action.informational ? void dismissInformational() : minimize()}
            className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-white/70 transition hover:bg-white"
            aria-label={action.informational ? "Tutup informasi" : "Minimalkan pengingat sesi"}
          >
            {action.informational ? <X size={15} /> : <Minimize2 size={15} />}
          </button>
        </div>

        <div className="p-4 sm:p-5">
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
              <h3 className="text-lg font-black leading-tight text-slate-950">{copy.title}</h3>
              <p className="mt-2 text-sm leading-6 text-slate-600">{copy.description}</p>
            </div>
            {pendingCount > 1 && <span className="shrink-0 rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-black text-slate-600">{pendingCount} pengingat</span>}
          </div>

          <div className="mt-4 flex min-w-0 items-center gap-2 rounded-xl bg-slate-50 px-3 py-2.5 text-xs text-slate-600">
            <Clock3 size={14} className="shrink-0 text-indigo-500" />
            <span className="min-w-0 truncate"><b>{action.subject}</b>{formattedSchedule(action.start_at) ? ` · ${formattedSchedule(action.start_at)}` : ""}</span>
          </div>

          {action.secondary_kind === "student_teacher_late" && (
            <div className="mt-3 flex gap-2 rounded-xl border border-amber-100 bg-amber-50 p-3 text-xs leading-5 text-amber-800">
              <AlertTriangle size={15} className="mt-0.5 shrink-0" />
              <span>Sudah lewat 15 menit? Kalau tutor memang belum hadir, buka detail sesi untuk mengirim laporan ketidakhadiran.</span>
            </div>
          )}

          <Button type="button" onClick={() => {
            if (acknowledgeOnOpen.has(action.kind)) {
              sessionStorage.setItem(`bimbelku:session-action:ack:${action.action_key}`, "1");
              setAction(null);
            }
            if (action.informational) {
              void dismissInformational();
            }
            navigate(action.target_url);
          }} className={`mt-4 min-h-11 w-full rounded-xl font-black ${buttonClass}`}>
            {copy.button}<ChevronRight size={16} className="ml-2" />
          </Button>
          <p className="mt-3 text-center text-[10px] font-semibold leading-4 text-slate-400">{action.informational ? "Ini hanya informasi. Kamu boleh menutupnya kapan saja." : acknowledgeOnOpen.has(action.kind) ? "Setelah dibuka, pesan ini selesai. BimbelKu akan mengingatkan lagi saat ada langkah berikutnya." : "Boleh diminimalkan, tetapi pengingat tetap tersedia sampai tugas selesai."}</p>
        </div>
      </div>
    </aside>
  );
}
