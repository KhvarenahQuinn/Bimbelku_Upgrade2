import { notify } from "@/lib/notify";
import { FormEvent, lazy, Suspense, useEffect, useMemo, useState } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import {
  AlertCircle,
  ArrowLeft,
  ArrowUpDown,
  BarChart3,
  BookOpen,
  CalendarDays,
  CheckCircle2,
  Clock3,
  CreditCard,
  ExternalLink,
  FileCheck2,
  Filter,
  GraduationCap,
  History,
  Loader2,
  MapPin,
  MessageCircle,
  MessageSquareWarning,
  Monitor,
  RefreshCw,
  ShieldAlert,
  Star,
  UserRound,
  Users,
  Video,
  WifiOff,
} from "lucide-react";
import axios from "axios";
import StudentLayout from "@/components/StudentLayout";
import { useConfirmDialog } from "@/components/ConfirmDialogProvider";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import http, { getApiError, getCached } from "@/lib/http";
import { announceNavigationAttentionChanged, unreadIdsForStudentClassTab, type AttentionNotification } from "@/lib/navigationAttention";
import { validateUpload } from "@/lib/validation";
import WorkspacePageIntro from "@/components/WorkspacePageIntro";
import PrivateClassJoinEntry from "@/components/PrivateClassJoinEntry";

const LearningSessionHub = lazy(() => import("@/components/LearningSessionHub"));

interface ClassItem {
  id: number;
  viewer_only?: boolean;
  class_key?: string;
  class_kind?: "private" | "group";
  cheap_class_id?: number;
  cheap_class_session_id?: number;
  session_number?: number;
  session_count?: number;
  teacher_started_at?: string | null;
  group_sessions?: GroupSession[];
  progress_url?: string;
  title: string;
  subject: string;
  education_level?: string;
  grade?: string;
  chapter?: string;
  mentor: string;
  mentor_avatar?: string;
  type: "Kelompok" | "Privat";
  method: "online" | "offline";
  status: string;
  tutor_ready_at?: string | null;
  student_confirmed_at?: string | null;
  session_started_at?: string | null;
  session_ended_at?: string | null;
  actual_duration_minutes?: number | null;
  scheduled_duration_minutes?: number | null;
  session_focus_note?: string | null;
  participant_status: string;
  start_at: string;
  end_at: string;
  address?: string;
  maps_link?: string;
  meeting_link?: string;
  amount: number;
  payment_due_at?: string;
  completion_notes?: string;
  objection_deadline?: string;
  approved_at?: string;
  can_rate: boolean;
  can_approve: boolean;
  can_dispute: boolean;
  can_report_teacher_absence: boolean;
  can_appeal_lapsed_session?: boolean;
  attention?: {
    kind: "review" | "presence";
    title: string;
    message: string;
    button_label: string;
    target_url: string;
  } | null;
  dispute?: { status: string; reason: string; resolution?: string };
  refund?: { status: string; amount: number; proof?: string; reason?: string };
  order?: { id: number; order_id: string; status: string; payment_rejection_reason?: string };
}

type GroupSession = {
  id: number;
  session_number: number;
  starts_at: string;
  ends_at: string;
  status: string;
  teacher_started_at?: string | null;
};

const labels: Record<string, { label: string; className: string }> = {
  awaiting_payment: { label: "Menunggu pembayaran", className: "bg-orange-50 text-orange-700" },
  payment_submitted: { label: "Pembayaran diperiksa", className: "bg-sky-50 text-sky-700" },
  payment_collecting: { label: "Menunggu pembayaran", className: "bg-orange-50 text-orange-700" },
  payment_rejected: { label: "Pembayaran ditolak", className: "bg-rose-50 text-rose-700" },
  confirmed: { label: "Terjadwal", className: "bg-indigo-50 text-indigo-700" },
  in_progress: { label: "Sedang berlangsung", className: "bg-emerald-50 text-emerald-700" },
  scheduled: { label: "Terjadwal", className: "bg-indigo-50 text-indigo-700" },
  report_required: { label: "Menunggu laporan", className: "bg-amber-50 text-amber-700" },
  awaiting_admin_verification: { label: "Laporan diperiksa", className: "bg-violet-50 text-violet-700" },
  revision_requested: { label: "Laporan diperbaiki", className: "bg-amber-50 text-amber-700" },
  awaiting_student_approval: { label: "Butuh keputusanmu", className: "bg-amber-50 text-amber-700" },
  disputed: { label: "Keberatan diperiksa", className: "bg-rose-50 text-rose-700" },
  admin_review_required: { label: "Diperiksa admin", className: "bg-violet-50 text-violet-700" },
  completed: { label: "Selesai", className: "bg-emerald-50 text-emerald-700" },
  cancelled: { label: "Dibatalkan", className: "bg-slate-100 text-slate-700" },
  payment_expired: { label: "Pembayaran berakhir", className: "bg-slate-100 text-slate-700" },
  refund_pending: { label: "Refund diproses", className: "bg-amber-50 text-amber-700" },
  emergency_refund_pending: { label: "Refund darurat", className: "bg-amber-50 text-amber-700" },
  refunded: { label: "Refund selesai", className: "bg-slate-100 text-slate-700" },
  absence_review: { label: "Kehadiran diperiksa", className: "bg-rose-50 text-rose-700" },
};

type ClassListResponse = ClassItem[] | { data?: ClassItem[] };
type ScheduleSort = "nearest" | "farthest";
type KindFilter = "all" | "private" | "group";
type ClassTab = "process" | "schedule" | "history";

const processClassStatuses = new Set([
  "awaiting_payment",
  "payment_submitted",
  "payment_collecting",
  "payment_rejected",
  "disputed",
  "admin_review_required",
  "refund_pending",
  "emergency_refund_pending",
  "absence_review",
]);
const historyClassStatuses = new Set(["completed", "cancelled", "payment_expired", "refunded"]);
const activePastStatuses = new Set([
  "in_progress",
  "report_required",
  "awaiting_admin_verification",
  "revision_requested",
  "awaiting_student_approval",
]);

const classTabFor = (item: ClassItem): ClassTab => {
  if (processClassStatuses.has(item.status)) return "process";
  if (historyClassStatuses.has(item.status)) return "history";

  const endAt = validDate(item.end_at)?.getTime();
  if (endAt && endAt < Date.now() && !activePastStatuses.has(item.status) && !item.attention) return "history";
  return "schedule";
};

type GroupClassResponse = Array<{
  id: number;
  package_code?: string | null;
  subject_name: string;
  education_level?: string;
  grade?: string;
  chapter?: string;
  price_per_student: number;
  session_count: number;
  meeting_link?: string | null;
  status: string;
  enrollment?: {
    id: number;
    status: string;
    seat_expires_at?: string | null;
    order_id?: number | null;
    order_number?: string | null;
    order_status?: string | null;
  } | null;
  teacher?: { name?: string; photo?: string | null } | null;
  sessions: GroupSession[];
}>;

const presenceProblemOptions = [
  "Tutor tidak mengajar selama durasi yang seharusnya.",
  "Tutor tidak hadir penuh selama sesi berlangsung.",
  "Materi yang diajarkan tidak sesuai dengan sesi.",
  "Ada masalah dengan sikap atau perilaku tutor.",
  "Ada masalah teknis yang mengganggu pembelajaran.",
  "Ada masalah lain yang perlu diperiksa oleh admin.",
] as const;

const rupiah = (value: number) => new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(value || 0);

const validDate = (value?: string | null): Date | null => {
  if (!value) return null;
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? null : date;
};

const dateTime = (value?: string | null) => {
  const date = validDate(value);
  return date
    ? new Intl.DateTimeFormat("id-ID", { dateStyle: "full", timeStyle: "short" }).format(date)
    : "Jadwal belum tersedia";
};

const timeOnly = (value?: string | null) => {
  const date = validDate(value);
  return date
    ? date.toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" })
    : "--.--";
};

const readClassRows = (payload: ClassListResponse): ClassItem[] | null => {
  if (Array.isArray(payload)) return payload;
  return Array.isArray(payload?.data) ? payload.data : null;
};

const groupClassRows = (groups: GroupClassResponse): ClassItem[] => groups.map((group) => {
    const sessions = [...group.sessions].sort((left, right) => left.session_number - right.session_number);
    const liveSession = sessions.find((session) => session.status === "in_progress" || (
      Boolean(session.teacher_started_at) && (validDate(session.ends_at)?.getTime() ?? 0) > Date.now()
    ));
    const referenceSession = liveSession
      || sessions.find((session) => (validDate(session.ends_at)?.getTime() ?? 0) > Date.now())
      || sessions[sessions.length - 1];
    const orderStatus = group.enrollment?.order_status || undefined;
    const enrollmentStatus = group.enrollment?.status || "unknown";
    const enrollmentDisplayStatus: Record<string, string> = {
      seat_held: "awaiting_payment",
      payment_submitted: "payment_submitted",
      payment_rejected: "payment_rejected",
      cancellation_pending: "refund_pending",
      refund_pending: "refund_pending",
      refunded: "refunded",
      payment_expired: "payment_expired",
      cancelled: "cancelled",
    };
    const displayStatus = liveSession
      ? "in_progress"
      : orderStatus === "pending"
        ? "awaiting_payment"
        : orderStatus === "submitted"
          ? "payment_submitted"
          : orderStatus === "rejected"
            ? "payment_rejected"
            : orderStatus === "refund_pending"
              ? "refund_pending"
              : orderStatus === "refunded"
                ? "refunded"
                : enrollmentDisplayStatus[enrollmentStatus] || group.status;
    const hasLearningAccess = group.enrollment?.status === "confirmed" && orderStatus === "paid";
    return {
      id: group.id,
      class_key: `group-${group.id}`,
      class_kind: "group",
      cheap_class_id: group.id,
      cheap_class_session_id: referenceSession?.id,
      session_number: referenceSession?.session_number,
      session_count: group.session_count,
      teacher_started_at: liveSession?.teacher_started_at || referenceSession?.teacher_started_at,
      group_sessions: sessions,
      title: `${group.subject_name} · Paket ${group.session_count} sesi`,
      subject: group.subject_name,
      education_level: group.education_level,
      grade: group.grade,
      chapter: group.chapter,
      mentor: group.teacher?.name || "Tutor BimbelKu",
      mentor_avatar: group.teacher?.photo || undefined,
      type: "Kelompok" as const,
      method: "online" as const,
      status: displayStatus,
      participant_status: enrollmentStatus,
      start_at: referenceSession?.starts_at || "",
      end_at: referenceSession?.ends_at || "",
      meeting_link: group.meeting_link || undefined,
      amount: Number(group.price_per_student || 0),
      payment_due_at: group.enrollment?.seat_expires_at || undefined,
      order: group.enrollment?.order_id ? {
        id: group.enrollment.order_id,
        order_id: group.enrollment.order_number || "",
        status: orderStatus || "unknown",
      } : undefined,
      can_rate: false,
      can_approve: false,
      can_dispute: false,
      can_report_teacher_absence: false,
      can_appeal_lapsed_session: false,
      progress_url: hasLearningAccess && ["confirmed", "completed"].includes(group.status)
        ? `/student/progress/cheap-class/${group.id}`
        : undefined,
    };
});

export default function MyClasses() {
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const confirm = useConfirmDialog();
  const [classes, setClasses] = useState<ClassItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [processing, setProcessing] = useState<number | null>(null);
  const [selected, setSelected] = useState<ClassItem | null>(null);
  const [dispute, setDispute] = useState<ClassItem | null>(null);
  const [disputeReason, setDisputeReason] = useState("");
  const [disputeCategory, setDisputeCategory] = useState("");
  const [disputeEvidence, setDisputeEvidence] = useState<File | null>(null);
  const [appeal, setAppeal] = useState<ClassItem | null>(null);
  const [appealReason, setAppealReason] = useState("");
  const [appealEvidence, setAppealEvidence] = useState<File | null>(null);
  const [absenceReport, setAbsenceReport] = useState<ClassItem | null>(null);
  const [absenceReason, setAbsenceReason] = useState("");
  const [absenceEvidence, setAbsenceEvidence] = useState<File | null>(null);
  const [ratingClass, setRatingClass] = useState<ClassItem | null>(null);
  const [rating, setRating] = useState(5);
  const [review, setReview] = useState("");
  const [hubBookingId, setHubBookingId] = useState<number | null>(null);
  const [hubInitialTab, setHubInitialTab] = useState<"session">("session");
  const [hubReturnClass, setHubReturnClass] = useState<ClassItem | null>(null);
  const [classAttention, setClassAttention] = useState<AttentionNotification[]>([]);
  const [subjectFilter, setSubjectFilter] = useState("all");
  const [scheduleSort, setScheduleSort] = useState<ScheduleSort>("nearest");
  const [activeTab, setActiveTab] = useState<Exclude<ClassTab, "process">>(() => {
    const requested = searchParams.get("tab");
    return requested === "history" ? "history" : "schedule";
  });
  const [kindFilter, setKindFilter] = useState<KindFilter>(() => {
    const requested = searchParams.get("class_kind");
    return requested === "group" || requested === "private" ? requested : "all";
  });

  const changeKindFilter = (value: KindFilter) => {
    setKindFilter(value);
    const next = new URLSearchParams(searchParams);
    if (value !== "all") next.set("class_kind", value);
    else next.delete("class_kind");
    if (value !== "group") {
      next.delete("cheap_class");
      next.delete("cheap_session");
    }
    next.delete("session_action");
    setSearchParams(next, { replace: true });
  };

  const changeTab = (value: Exclude<ClassTab, "process">) => {
    setActiveTab(value);
    setSubjectFilter("all");
    setKindFilter("all");
    const next = new URLSearchParams(searchParams);
    if (value === "schedule") next.delete("tab");
    else next.set("tab", value);
    next.delete("class_kind");
    next.delete("cheap_class");
    next.delete("cheap_session");
    next.delete("session_action");
    setSearchParams(next, { replace: true });
  };

  const openTab = async (value: Exclude<ClassTab, "process">) => {
    changeTab(value);
    const ids = unreadIdsForStudentClassTab(value, classAttention);
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

  useEffect(() => {
    const requestedTab = searchParams.get("tab");
    if (requestedTab === "process") {
      navigate("/student/packages", { replace: true });
      return;
    }
    const nextTab: Exclude<ClassTab, "process"> = requestedTab === "history" ? "history" : "schedule";
    setActiveTab((current) => current === nextTab ? current : nextTab);

    const requested = searchParams.get("class_kind");
    const nextFilter: KindFilter = requested === "group" || requested === "private"
      ? requested
      : "all";
    setKindFilter((current) => current === nextFilter ? current : nextFilter);
  }, [navigate, searchParams]);

  const subjectOptions = useMemo(() => {
    return Array.from(
      new Set(
        classes
          .map((item) => item.subject?.trim())
          .filter((subject): subject is string => Boolean(subject))
      )
    ).sort((left, right) => left.localeCompare(right, "id-ID"));
  }, [classes]);

  const phaseClasses = useMemo(() => classes.filter((item) => {
    const tab = classTabFor(item);
    if (activeTab === "history") return tab === "history";
    return tab === activeTab;
  }), [activeTab, classes]);

  const visibleClasses = useMemo(() => {
    const now = Date.now();
    const byKind = phaseClasses.filter((item) => {
      if (kindFilter === "group") return item.class_kind === "group";
      if (kindFilter === "private") return item.class_kind !== "group";
      return true;
    });
    const filtered = subjectFilter === "all"
      ? byKind
      : byKind.filter((item) => item.subject === subjectFilter);

    return [...filtered].sort((left, right) => {
      const leftInProgress = left.status === "in_progress";
      const rightInProgress = right.status === "in_progress";

      // Kelas yang sedang berjalan selalu harus paling mudah ditemukan.
      if (leftInProgress !== rightInProgress) return leftInProgress ? -1 : 1;

      const leftTime = validDate(left.start_at)?.getTime();
      const rightTime = validDate(right.start_at)?.getTime();

      if (leftTime === undefined && rightTime === undefined) return left.id - right.id;
      if (leftTime === undefined) return 1;
      if (rightTime === undefined) return -1;

      const leftUpcoming = leftTime >= now;
      const rightUpcoming = rightTime >= now;

      // Jadwal yang masih akan datang selalu diprioritaskan sebelum riwayat.
      if (leftUpcoming !== rightUpcoming) return leftUpcoming ? -1 : 1;

      if (scheduleSort === "nearest") {
        return leftUpcoming ? leftTime - rightTime : rightTime - leftTime;
      }

      return leftUpcoming ? rightTime - leftTime : leftTime - rightTime;
    });
  }, [kindFilter, phaseClasses, scheduleSort, subjectFilter]);

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

  useEffect(() => {
    const sessionId = Number(searchParams.get("session"));
    const action = searchParams.get("session_action");
    if (!Number.isFinite(sessionId) || !action || classes.length === 0) return;
    const item = classes.find((row) => row.class_kind === "private" && row.id === sessionId);
    if (!item) return;

    if (action === "presence") {
      setHubReturnClass(item);
      setHubInitialTab("session");
      setHubBookingId(item.id);
      setSelected(null);
      return;
    }

    if (action === "review" || action === "late") {
      setSelected(item);
      return;
    }

    if (action === "started") {
      setActiveTab("schedule");
      setSelected(item);
    }
  }, [classes, searchParams]);

  useEffect(() => {
    const cheapSessionId = Number(searchParams.get("cheap_session"));
    const cheapClassId = Number(searchParams.get("cheap_class"));
    if (classes.length === 0 || (!cheapSessionId && !cheapClassId)) return;
    const item = classes.find((row) => row.class_kind === "group" && (
      (cheapSessionId && (row.cheap_class_session_id === cheapSessionId || row.group_sessions?.some((session) => session.id === cheapSessionId)))
      || (!cheapSessionId && row.cheap_class_id === cheapClassId)
    ));
    if (!item) return;
    const sessionAction = searchParams.get("session_action");
    setActiveTab(classTabFor(item));
    if (searchParams.get("class_kind") === "group" || sessionAction === "started") {
      setKindFilter("group");
    }
    setSelected(item);
    window.setTimeout(() => document.getElementById(item.class_key || "")?.scrollIntoView({ behavior: "smooth", block: "center" }), 80);
  }, [classes, searchParams]);

  const clearSessionActionQuery = () => {
    const next = new URLSearchParams(searchParams);
    next.delete("session");
    next.delete("cheap_class");
    next.delete("cheap_session");
    next.delete("class_kind");
    next.delete("session_action");
    setSearchParams(next, { replace: true });
  };

  const openAttention = (item: ClassItem) => {
    if (!item.attention) return;
    const next = new URLSearchParams(searchParams);
    next.set("session", String(item.id));
    next.set("session_action", item.attention.kind);
    setSearchParams(next);
  };

  const loadClasses = async ({ silent = false }: { silent?: boolean } = {}) => {
    if (!silent) {
      setLoading(true);
      setError(null);
    }
    try {
      const [privateResponse, groupResponse] = await Promise.all([
        getCached<ClassListResponse>("/student/classes", { maxAgeMs: 2_000, force: true }),
        getCached<GroupClassResponse>("/student/cheap-classes?scope=owned", { maxAgeMs: 2_000, force: true }),
      ]);
      const privateRows = readClassRows(privateResponse.data);
      if (!privateRows || !Array.isArray(groupResponse.data)) throw new Error("Format daftar kelas tidak dikenali.");
      setClasses([
        ...privateRows.map((item) => ({ ...item, class_key: `private-${item.id}`, class_kind: "private" as const })),
        ...groupClassRows(groupResponse.data),
      ]);
    } catch (err) {
      if (!silent) {
        if (axios.isAxiosError(err)) {
          if (!err.response) {
            setError("network");
          } else if (err.response.status === 401) {
            setError("unauthorized");
          } else if (err.response.status === 403) {
            setError("forbidden");
          } else if (err.response.status === 404) {
            setError("not_found");
          } else {
            setError("generic");
          }
        } else {
          setError("generic");
        }
        notify.error(getApiError(err, "Kelas gagal dimuat."));
      }
    } finally {
      if (!silent) setLoading(false);
    }
  };

  const retry = () => {
    setError(null);
    void loadClasses();
  };

  const approve = async (item: ClassItem) => {
    const accepted = await confirm({
      title: "Sesi tadi sudah sesuai?",
      description: "Pastikan tutor hadir dan sesi berjalan sesuai. Setelah dikonfirmasi, keberatan tidak dapat diajukan lagi dari halaman ini.",
      confirmText: "Ya, sesi sesuai",
      tone: "warning",
    });
    if (!accepted) return;
    setProcessing(item.id);
    try {
      const response = await http.post(`/student/bookings/${item.id}/approve`);
      notify.success(response.data.message);
      setSelected(null);
      clearSessionActionQuery();
      await loadClasses();
    } catch (error) {
      notify.error(getApiError(error));
    } finally {
      setProcessing(null);
    }
  };

  const submitDispute = async (event: FormEvent) => {
    event.preventDefault();
    if (!dispute) return;
    const payload = new FormData();
    if (!disputeCategory) {
      notify.error("Pilih dulu masalah yang terjadi pada sesi.");
      return;
    }
    const reason = `${disputeCategory}${disputeReason.trim() ? ` Detail: ${disputeReason.trim()}` : ""}`;
    payload.append("reason", reason);
    if (disputeEvidence) payload.append("evidence", disputeEvidence);
    setProcessing(dispute.id);
    try {
      const response = await http.post(`/student/bookings/${dispute.id}/dispute`, payload);
      notify.success(response.data.message);
      setDispute(null);
      setSelected(null);
      clearSessionActionQuery();
      setDisputeReason("");
      setDisputeCategory("");
      setDisputeEvidence(null);
      await loadClasses();
    } catch (error) {
      notify.error(getApiError(error));
    } finally {
      setProcessing(null);
    }
  };

  const submitAppeal = async (event: FormEvent) => {
    event.preventDefault();
    if (!appeal) return;
    if (appealReason.trim().length < 30) {
      notify.error("Jelaskan kronologi minimal 30 karakter.");
      return;
    }
    const payload = new FormData();
    payload.append("reason", appealReason.trim());
    if (appealEvidence) payload.append("evidence", appealEvidence);
    setProcessing(appeal.id);
    try {
      const response = await http.post(`/student/bookings/${appeal.id}/appeal`, payload);
      notify.success(response.data.message);
      setAppeal(null);
      setSelected(null);
      setAppealReason("");
      setAppealEvidence(null);
      await loadClasses();
    } catch (error) {
      notify.error(getApiError(error));
    } finally {
      setProcessing(null);
    }
  };

  const submitRating = async () => {
    if (!ratingClass) return;
    setProcessing(ratingClass.id);
    try {
      const response = await http.post("/ratings", { booking_id: ratingClass.id, rating, review });
      notify.success(response.data.message);
      setRatingClass(null);
      setReview("");
      setRating(5);
      await loadClasses();
    } catch (error) {
      notify.error(getApiError(error));
    } finally {
      setProcessing(null);
    }
  };

  const submitTeacherAbsence = async (event: FormEvent) => {
    event.preventDefault();
    if (!absenceReport || !absenceEvidence) return;
    const payload = new FormData();
    payload.append("chronology", absenceReason);
    payload.append("evidence", absenceEvidence);
    setProcessing(absenceReport.id);
    try {
      const response = await http.post(`/student/bookings/${absenceReport.id}/teacher-absence`, payload);
      notify.success(response.data.message);
      setAbsenceReport(null);
      setSelected(null);
      setAbsenceReason("");
      setAbsenceEvidence(null);
      await loadClasses();
    } catch (error) {
      notify.error(getApiError(error));
    } finally {
      setProcessing(null);
    }
  };

  const selectDisputeEvidence = (file?: File) => {
    const error = validateUpload(file, {
      label: "Bukti keberatan",
      maxSizeMb: 5,
      extensions: ["jpg", "jpeg", "png", "webp", "pdf"],
    });
    if (error) {
      notify.error(error);
      setDisputeEvidence(null);
      return;
    }
    setDisputeEvidence(file || null);
  };

  const selectAbsenceEvidence = (file?: File) => {
    const error = validateUpload(file, {
      label: "Bukti ketidakhadiran tutor",
      maxSizeMb: 5,
      extensions: ["jpg", "jpeg", "png", "webp", "pdf"],
    });
    if (error) {
      notify.error(error);
      setAbsenceEvidence(null);
      return;
    }
    setAbsenceEvidence(file || null);
  };

  const openPayment = (item: ClassItem) => {
    if (!item.order?.id) {
      notify.error("Data tagihan belum tersedia.");
      return;
    }
    navigate("/payment", {
      state: {
        orderId: item.order.id,
        invoiceId: item.order.order_id,
        tutorName: item.mentor,
        subject: item.subject,
        type: `${item.method === "online" ? "Online" : "Offline"} · ${item.type}`,
        price: Number(item.amount),
        date: item.start_at,
        paymentDueAt: item.payment_due_at,
        rejectionReason: item.order.payment_rejection_reason,
      },
    });
  };

  return (
    <StudentLayout title="Jadwal Belajar" onAttentionNotificationsChange={setClassAttention}>
      <div className="mx-auto max-w-7xl space-y-5 pb-4 sm:space-y-7 sm:pb-12">
        <div data-tour="student-classes-hero"><WorkspacePageIntro eyebrow="Kalender belajar" title="Jadwal Belajar" description="Buka kelas yang akan datang atau lihat kembali kelas yang telah selesai. Proses pesanan berada di halaman tersendiri." icon={CalendarDays} actions={<><Button asChild className="min-h-11 flex-1 rounded-xl bg-indigo-600 font-black hover:bg-indigo-700 lg:flex-none"><Link to="/student/packages/new">Cari les privat</Link></Button><Button asChild variant="outline" className="min-h-11 flex-1 rounded-xl lg:flex-none"><Link to="/student/kelas-murah">Kelas bersama</Link></Button></>} /></div>

        <PrivateClassJoinEntry />

        <nav aria-label="Bagian Jadwal Belajar" className="rounded-[1.5rem] border border-slate-100 bg-white p-1.5 shadow-sm">
          <div className="grid grid-cols-2 gap-1.5">
            {([
              ["schedule", "Jadwal Aktif", CalendarDays],
              ["history", "Riwayat", History],
            ] as const).map(([value, label, Icon]) => (
              <button
                key={value}
                type="button"
                aria-pressed={activeTab === value}
                onClick={() => void openTab(value)}
                className={`relative flex min-h-12 min-w-0 items-center justify-center gap-1.5 rounded-xl px-2 text-xs font-black transition sm:text-sm ${activeTab === value ? "bg-indigo-600 text-white shadow-sm" : "text-slate-500 hover:bg-slate-50 hover:text-slate-800"}`}
              >
                <Icon className="shrink-0" size={16} />
                <span className="truncate">{label}</span>
                {unreadIdsForStudentClassTab(value, classAttention).length > 0 && <span aria-label={`Ada pembaruan baru di ${label}`} className={`absolute right-2 top-2 h-2.5 w-2.5 rounded-full bg-rose-500 ${activeTab === value ? "ring-2 ring-indigo-600" : "ring-2 ring-white"}`} />}
              </button>
            ))}
          </div>
        </nav>

        {phaseClasses.length > 0 && (
          <section className="rounded-[1.7rem] border border-slate-100 bg-white p-4 shadow-sm sm:p-5">
            <div className="flex items-center justify-between gap-3">
              <div className="flex min-w-0 items-center gap-2">
                <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-indigo-50 text-indigo-600">
                  <Filter size={17} />
                </span>
                <div className="min-w-0">
                  <p className="font-black text-slate-900">Filter kelas</p>
                  <p className="truncate text-xs text-slate-500">{visibleClasses.length} dari {phaseClasses.length} kelas ditampilkan</p>
                </div>
              </div>
            </div>

            <div className="mt-4 grid gap-3 sm:grid-cols-2 sm:items-end">
              <div className="min-w-0 max-w-full">
                <Label className="mb-2 flex items-center gap-2 text-xs font-black uppercase tracking-widest text-slate-500">
                  <Users size={14} /> Jenis kelas
                </Label>
                <Select value={kindFilter} onValueChange={(value) => changeKindFilter(value as KindFilter)}>
                  <SelectTrigger className="h-12 w-full rounded-xl border-slate-200 bg-white text-left font-bold">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent className="max-w-[calc(100vw-2rem)]">
                    <SelectItem value="all">Semua kelas</SelectItem>
                    <SelectItem value="private">Kelas privat</SelectItem>
                    <SelectItem value="group">Kelas bersama</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div>
                <Label className="mb-2 flex items-center gap-2 text-xs font-black uppercase tracking-widest text-slate-500">
                  <GraduationCap size={14} /> Mata pelajaran
                </Label>
                <Select value={subjectFilter} onValueChange={setSubjectFilter}>
                  <SelectTrigger className="h-12 w-full rounded-xl border-slate-200 bg-white text-left font-bold">
                    <SelectValue placeholder="Semua mata pelajaran" />
                  </SelectTrigger>
                  <SelectContent className="max-w-[calc(100vw-2rem)]">
                    <SelectItem value="all">Semua mata pelajaran</SelectItem>
                    {subjectOptions.map((subject) => (
                      <SelectItem key={subject} value={subject}>{subject}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div>
                <Label className="mb-2 flex items-center gap-2 text-xs font-black uppercase tracking-widest text-slate-500">
                  <ArrowUpDown size={14} /> Urutan waktu
                </Label>
                <Select value={scheduleSort} onValueChange={(value) => setScheduleSort(value as ScheduleSort)}>
                  <SelectTrigger className="h-12 w-full rounded-xl border-slate-200 bg-white text-left font-bold">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent align="end" className="max-w-[calc(100vw-2rem)]">
                    <SelectItem value="nearest">{activeTab === "history" ? "Paling baru" : "Jadwal paling dekat"}</SelectItem>
                    <SelectItem value="farthest">{activeTab === "history" ? "Paling lama" : "Jadwal paling jauh"}</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
          </section>
        )}

        {(activeTab !== "history" || phaseClasses.length > 0) && (error ? (
          <ErrorState error={error} onRetry={retry} />
        ) : loading ? (
          <div role="status" aria-live="polite" className="grid min-h-56 place-items-center rounded-[2rem] border border-slate-100 bg-white shadow-sm">
            <div className="text-center"><Loader2 className="mx-auto h-9 w-9 animate-spin text-indigo-600" /><p className="mt-3 text-sm font-bold text-slate-500">Memuat jadwal kelas…</p></div>
          </div>
        ) : phaseClasses.length === 0 ? (
          <div className="rounded-[2rem] border-2 border-dashed border-slate-200 bg-white px-5 py-12 text-center sm:py-16">
            {activeTab === "history" ? <History className="mx-auto h-11 w-11 text-slate-300" /> : <BookOpen className="mx-auto h-11 w-11 text-slate-300" />}
            <p className="mt-4 font-black text-slate-800">{activeTab === "history" ? "Belum ada riwayat kelas" : "Belum ada jadwal belajar"}</p>
            <p className="mx-auto mt-2 max-w-lg text-sm leading-6 text-slate-500">{activeTab === "history" ? "Kelas yang selesai, dibatalkan, atau sudah melewati waktunya akan tersimpan di bagian ini." : "Kelas akan masuk ke Jadwal setelah pembayaran dan pencarian tutor selesai."}</p>
            {activeTab === "schedule" && <div className="mt-5 flex flex-col justify-center gap-2 sm:flex-row"><Button asChild className="rounded-xl bg-indigo-600"><Link to="/student/packages/new">Cari les privat</Link></Button><Button asChild variant="outline" className="rounded-xl"><Link to="/student/kelas-murah">Lihat kelas bersama</Link></Button></div>}
          </div>
        ) : visibleClasses.length === 0 ? (
          <div className="rounded-[2rem] border-2 border-dashed border-slate-200 bg-white px-5 py-14 text-center">
            <Filter className="mx-auto h-10 w-10 text-slate-300" />
            <p className="mt-4 font-black text-slate-800">Tidak ada kelas sesuai filter</p>
            <p className="mt-1 text-sm text-slate-500">Ubah jenis kelas atau pilih mata pelajaran lain.</p>
            <Button type="button" variant="outline" className="mt-5 rounded-xl" onClick={() => { setSubjectFilter("all"); changeKindFilter("all"); }}>Tampilkan semua kelas</Button>
          </div>
        ) : (
          <div data-tour="student-class-list" className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            {visibleClasses.map((item) => {
              const status = labels[item.status] || { label: item.status, className: "bg-slate-100 text-slate-600" };
              return (
                <article id={item.class_key} key={item.class_key || item.id} className="render-auto scroll-mt-28 flex min-w-0 max-w-full flex-col overflow-hidden rounded-[1.6rem] border border-slate-100 bg-white p-4 shadow-sm transition hover-rise hover-shadow-xl sm:rounded-[2rem] sm:p-5">
                  <div className="flex items-start justify-between gap-3"><div className="grid h-12 w-12 place-items-center rounded-2xl bg-indigo-50 text-indigo-600">{item.method === "online" ? <Monitor /> : <MapPin />}</div><span className={`rounded-full px-3 py-1.5 text-[11px] font-bold ${status.className}`}>{status.label}</span></div>
                  <p className="mt-5 min-w-0 break-words text-xs font-bold uppercase tracking-[.14em] text-indigo-500 sm:tracking-widest">{item.subject} · {item.type}</p><h2 className="mt-1 min-w-0 break-words text-lg font-black leading-snug text-slate-900 sm:line-clamp-2 sm:text-xl">{item.title}</h2>
                  <div className="mt-4 flex min-w-0 items-center gap-3"><div className="grid h-10 w-10 shrink-0 place-items-center overflow-hidden rounded-xl bg-slate-100 text-slate-500">{item.mentor_avatar ? <img src={item.mentor_avatar} alt="" loading="lazy" decoding="async" className="h-full w-full object-cover" /> : <UserRound size={18} />}</div><div className="min-w-0"><p className="text-xs text-slate-400">Tutor</p><p className="break-words text-sm font-bold text-slate-800">{item.mentor}</p></div></div>
                  {item.class_kind === "group" ? (
                    <div className="mt-4 rounded-2xl bg-slate-50 p-3">
                      <div className="flex items-center justify-between gap-3"><p className="text-[11px] font-black uppercase tracking-wider text-slate-400">Jadwal paket</p><span className="text-xs font-black text-indigo-700">{item.group_sessions?.length || item.session_count || 0} sesi</span></div>
                      <div className="mt-2 space-y-2">
                        {item.group_sessions?.map((session) => <div key={session.id} className={`flex items-start justify-between gap-3 rounded-xl bg-white px-3 py-2 text-xs ${session.status === "in_progress" ? "ring-2 ring-emerald-200" : ""}`}><span className="font-black text-slate-700">Sesi {session.session_number}</span><span className="text-right font-semibold text-slate-500">{dateTime(session.starts_at)}<br />{timeOnly(session.starts_at)}–{timeOnly(session.ends_at)}</span></div>)}
                      </div>
                      <div className="mt-3"><Info icon={Users} text={`${rupiah(item.amount)} untuk seluruh paket`} /></div>
                    </div>
                  ) : <div className="mt-4 space-y-2 text-xs text-slate-600"><Info icon={CalendarDays} text={dateTime(item.start_at)} /><Info icon={Clock3} text={`${timeOnly(item.start_at)}–${timeOnly(item.end_at)}`} /><Info icon={Users} text={item.viewer_only ? "Peserta undangan · dibayar pemesan" : rupiah(item.amount)} /></div>}
                  {item.attention && (
                    <div className="mt-4 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                      <div className="flex items-start gap-3">
                        <AlertCircle size={18} className="mt-0.5 shrink-0 text-amber-600" />
                        <div className="min-w-0">
                          <p className="text-[10px] font-black uppercase tracking-[.14em] text-amber-700">Perlu tindakan</p>
                          <p className="mt-1 text-sm font-black text-amber-950">{item.attention.title}</p>
                          <p className="mt-1 text-xs leading-5 text-amber-800">{item.attention.message}</p>
                        </div>
                      </div>
                      <Button type="button" onClick={() => openAttention(item)} className="mt-3 min-h-10 w-full rounded-xl bg-amber-500 font-black text-slate-950 hover:bg-amber-600">
                        {item.attention.button_label}
                      </Button>
                    </div>
                  )}
                  <div className="mt-auto pt-5">
                    {item.status === "in_progress" && item.method === "online" && item.meeting_link && <Button asChild className="mb-2 w-full rounded-xl bg-emerald-600 font-black hover:bg-emerald-700"><a href={item.meeting_link} target="_blank" rel="noreferrer"><Video size={16} className="mr-2" />Gabung Zoom sekarang</a></Button>}
                    <Button onClick={() => setSelected(item)} variant="outline" className="w-full rounded-xl">Lihat detail</Button>
                    {item.class_kind === "group" && item.progress_url && <Button asChild variant="outline" className="mt-2 w-full rounded-xl border-violet-200 text-violet-700"><Link to={item.progress_url}><BarChart3 size={16} className="mr-2" />Lihat progress</Link></Button>}
                    {["pending", "rejected"].includes(item.order?.status || "") && <Button onClick={() => openPayment(item)} className="mt-2 w-full rounded-xl bg-orange-500 hover:bg-orange-600"><CreditCard size={16} className="mr-2" />{item.order?.status === "rejected" ? "Unggah ulang bukti" : "Bayar sekarang"}</Button>}
                    {item.can_rate && <Button onClick={() => setRatingClass(item)} className="mt-2 w-full rounded-xl bg-amber-400 text-slate-950 hover:bg-amber-500"><Star size={16} className="mr-2 fill-current" />Beri ulasan</Button>}
                  </div>
                </article>
              );
            })}
          </div>
        ))}

      </div>

      <Dialog open={Boolean(selected)} onOpenChange={(open) => { if (!open) { setSelected(null); clearSessionActionQuery(); } }}>
        <DialogContent className="max-h-[90dvh] overflow-y-auto rounded-[2rem] sm:max-w-2xl">
          {selected && <>
            <Button
              type="button"
              variant="ghost"
              onClick={() => setSelected(null)}
              className="w-fit -ml-3 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-indigo-700"
            >
              <ArrowLeft size={16} className="mr-2" />Kembali ke daftar kelas
            </Button>
            <DialogHeader><DialogTitle className="text-2xl">{selected.title}</DialogTitle><DialogDescription>{selected.mentor} · {dateTime(selected.start_at)}</DialogDescription></DialogHeader>
            <div className="grid gap-3 rounded-2xl bg-slate-50 p-4 text-sm sm:grid-cols-2"><Info icon={GraduationCap} text={`${selected.education_level || ""} ${selected.grade || ""}`} /><Info icon={Users} text={selected.viewer_only ? "Peserta undangan · dibayar pemesan" : `${selected.type} · ${rupiah(selected.amount)}`} /><Info icon={selected.method === "online" ? Monitor : MapPin} text={selected.method === "online" ? "Kelas online" : selected.address || "Alamat dibuka setelah pembayaran"} /><Info icon={Clock3} text={`${timeOnly(selected.start_at)}–${timeOnly(selected.end_at)}`} /></div>
            {selected.class_kind === "group" && <div className="rounded-2xl border border-slate-200 bg-white p-4"><p className="text-xs font-black uppercase tracking-wider text-slate-500">Seluruh jadwal pertemuan</p><div className="mt-3 space-y-2">{selected.group_sessions?.map((session) => <div key={session.id} className={`flex items-start justify-between gap-3 rounded-xl p-3 text-sm ${session.status === "in_progress" ? "bg-emerald-50 text-emerald-900 ring-1 ring-emerald-200" : "bg-slate-50 text-slate-700"}`}><div><p className="font-black">Sesi {session.session_number}</p><p className="mt-1 text-xs font-semibold">{labels[session.status]?.label || session.status}</p></div><p className="text-right text-xs font-semibold leading-5">{dateTime(session.starts_at)}<br />{timeOnly(session.starts_at)}–{timeOnly(session.ends_at)}</p></div>)}</div></div>}
            {["pending", "rejected"].includes(selected.order?.status || "") && <Button onClick={() => openPayment(selected)} className="rounded-xl bg-orange-500 hover:bg-orange-600"><CreditCard size={16} className="mr-2" />{selected.order?.status === "rejected" ? "Unggah ulang bukti pembayaran" : "Bayar kelas sekarang"}</Button>}
            {selected.class_kind === "group" && selected.status !== "in_progress" && <div className="rounded-2xl border border-indigo-100 bg-indigo-50 p-4 text-sm font-bold leading-6 text-indigo-800">{selected.teacher_started_at ? "Sesi ini sudah berakhir. Progress tetap dapat dilihat di bawah." : "Tautan Zoom aktif setelah tutor menekan Saya Hadir & Mulai Mengajar. Kamu akan mendapat popup saat kelas dimulai."}</div>}
            {(selected.maps_link || (selected.class_kind === "group" ? selected.status === "in_progress" && selected.meeting_link : selected.meeting_link)) && <Button asChild className={`rounded-xl ${selected.class_kind === "group" ? "bg-emerald-600 hover:bg-emerald-700" : "bg-indigo-600"}`}><a href={selected.meeting_link || selected.maps_link} target="_blank" rel="noreferrer"><ExternalLink size={16} className="mr-2" />{selected.class_kind === "group" ? "Gabung Zoom sekarang" : selected.method === "online" ? "Buka ruang kelas" : "Buka lokasi"}</a></Button>}
            {selected.class_kind === "group" && selected.progress_url && <Button asChild variant="outline" className="rounded-xl border-violet-200 text-violet-700"><Link to={selected.progress_url}><BarChart3 size={16} className="mr-2" />Lihat progress kelas</Link></Button>}
            {selected.class_kind !== "group" && selected.viewer_only && <p className="rounded-xl bg-indigo-50 p-3 text-xs font-bold leading-5 text-indigo-800">Kamu dapat mengikuti jadwal ini. Absen dan konfirmasi sesi dilakukan oleh akun pemesan.</p>}
            {selected.class_kind !== "group" && !selected.viewer_only && selected.order?.status === "paid" && <Button variant="outline" className="rounded-xl border-indigo-200 text-indigo-700" onClick={() => { setHubReturnClass(selected); setHubBookingId(selected.id); setSelected(null); }}><MessageCircle size={16} className="mr-2" />Buka ruang belajar</Button>}
            {selected.can_report_teacher_absence && <Button variant="outline" className="rounded-xl border-rose-200 text-rose-700" onClick={() => setAbsenceReport(selected)}><ShieldAlert size={16} className="mr-2" />Tutor belum hadir setelah 15 menit</Button>}
            {selected.can_appeal_lapsed_session && <Button variant="outline" className="rounded-xl border-amber-200 text-amber-800" onClick={() => setAppeal(selected)}><MessageSquareWarning size={16} className="mr-2" />Sesi tidak terlaksana</Button>}
            {selected.completion_notes && <div className="rounded-2xl border border-emerald-100 bg-emerald-50 p-4"><div className="flex items-center gap-2 font-black text-emerald-900"><FileCheck2 size={18} />Hasil belajar dari tutor</div><div className="mt-3 flex flex-wrap gap-2"><span className="rounded-full bg-white px-3 py-1.5 text-xs font-black text-emerald-800">Durasi aktual {selected.actual_duration_minutes ?? "-"} menit</span>{selected.scheduled_duration_minutes ? <span className="rounded-full bg-white px-3 py-1.5 text-xs font-bold text-slate-600">Jadwal {selected.scheduled_duration_minutes} menit</span> : null}</div><p className="mt-3 text-sm leading-6 text-emerald-800">{selected.completion_notes}</p>{selected.objection_deadline && <p className="mt-2 text-xs font-bold text-emerald-700">Batas keputusan: {dateTime(selected.objection_deadline)}</p>}</div>}
            {selected.dispute && <div className="flex gap-3 rounded-2xl border border-rose-100 bg-rose-50 p-4 text-sm text-rose-800"><MessageSquareWarning className="shrink-0" /><div><p className="font-black">Keberatan {selected.dispute.status}</p><p className="mt-1 leading-6">{selected.dispute.reason}</p></div></div>}
            {selected.refund && <div className="flex gap-3 rounded-2xl border border-amber-100 bg-amber-50 p-4 text-sm text-amber-800"><ShieldAlert className="shrink-0" /><div><p className="font-black">Refund {selected.refund.status} · {rupiah(selected.refund.amount)}</p><p className="mt-1">{selected.refund.reason}</p></div></div>}
            {(selected.can_approve || selected.can_dispute) && <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4"><div className="flex gap-3 text-sm text-amber-900"><AlertCircle className="shrink-0" /><p>Cek singkat hasil belajar dan durasi sesi. Pilih Sesi Sesuai jika semuanya wajar, atau Ada masalah jika ada hal yang perlu diperiksa admin.</p></div><div className="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">{selected.can_dispute && <Button variant="outline" className="rounded-xl border-rose-200 text-rose-700" onClick={() => setDispute(selected)}>Ada masalah</Button>}{selected.can_approve && <Button className="rounded-xl bg-emerald-600 hover:bg-emerald-700" onClick={() => approve(selected)} disabled={processing === selected.id}><CheckCircle2 size={16} className="mr-2" />Sesi Sesuai</Button>}</div></div>}
          </>}
        </DialogContent>
      </Dialog>

      <Dialog open={Boolean(dispute)} onOpenChange={(open) => {
        if (!open) {
          setDispute(null);
          setDisputeCategory("");
          setDisputeReason("");
          setDisputeEvidence(null);
        }
      }}>
        <DialogContent className="rounded-[2rem] sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>Ada masalah dengan sesi?</DialogTitle>
            <DialogDescription>Pilih masalah yang paling sesuai. Admin akan memeriksa sesi dan hak tutor ditahan sementara.</DialogDescription>
          </DialogHeader>
          <form onSubmit={submitDispute} className="space-y-4">
            <>
              <div className="grid gap-2">
                {presenceProblemOptions.map((option) => (
                  <button type="button" key={option} onClick={() => setDisputeCategory(option)} className={`rounded-xl border px-4 py-3 text-left text-sm font-bold transition ${disputeCategory === option ? "border-rose-300 bg-rose-50 text-rose-800" : "border-slate-200 bg-white text-slate-700 hover:border-slate-300"}`}>{option}</button>
                ))}
              </div>
              <div><Label>Ceritakan sedikit lagi <span className="font-normal text-slate-400">(opsional)</span></Label><Textarea maxLength={2500} className="mt-2 min-h-24 rounded-xl" value={disputeReason} onChange={(event) => setDisputeReason(event.target.value)} placeholder="Contoh: sesi berhenti setelah sekitar 20 menit." /></div>
            </>
            <div><Label>Bukti tambahan (opsional)</Label><Input className="mt-2" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" onChange={(event) => selectDisputeEvidence(event.target.files?.[0])} /></div>
            <Button className="w-full rounded-xl bg-rose-600 hover:bg-rose-700" disabled={processing === dispute?.id || !disputeCategory}>Kirim ke Admin</Button>
          </form>
        </DialogContent>
      </Dialog>

      <Dialog open={Boolean(appeal)} onOpenChange={(open) => {
        if (!open) {
          setAppeal(null);
          setAppealReason("");
          setAppealEvidence(null);
        }
      }}>
        <DialogContent className="rounded-[2rem] sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>Sesi tidak terlaksana</DialogTitle>
            <DialogDescription>Ceritakan kronologi. Admin akan memeriksa bukti kehadiran kedua pihak sebelum memutus refund.</DialogDescription>
          </DialogHeader>
          <form onSubmit={submitAppeal} className="space-y-4">
            <div><Label>Kronologi</Label><Textarea required minLength={30} maxLength={3000} className="mt-2 min-h-32 rounded-xl" value={appealReason} onChange={(event) => setAppealReason(event.target.value)} placeholder="Contoh: saya menunggu di ruang kelas 20 menit, tetapi tutor tidak pernah masuk dan tidak mengirim pesan." /></div>
            <div><Label>Bukti tambahan <span className="font-normal text-slate-400">(opsional)</span></Label><Input className="mt-2" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" onChange={(event) => setAppealEvidence(event.target.files?.[0] || null)} /></div>
            <Button className="w-full rounded-xl bg-amber-600 hover:bg-amber-700" disabled={processing === appeal?.id || appealReason.trim().length < 30}>Kirim banding</Button>
          </form>
        </DialogContent>
      </Dialog>

      <Dialog open={Boolean(ratingClass)} onOpenChange={(open) => !open && setRatingClass(null)}>
        <DialogContent className="rounded-[2rem] sm:max-w-md"><DialogHeader><DialogTitle>Nilai sesi tutor</DialogTitle><DialogDescription>Penilaian hanya dapat dikirim satu kali.</DialogDescription></DialogHeader><div className="flex justify-center gap-2 py-3">{[1, 2, 3, 4, 5].map((value) => <button type="button" key={value} onClick={() => setRating(value)} className={value <= rating ? "text-amber-400" : "text-slate-200"}><Star size={34} className="fill-current" /></button>)}</div><Textarea className="min-h-28 rounded-xl" value={review} onChange={(event) => setReview(event.target.value)} placeholder="Ceritakan pengalaman belajar, opsional" /><Button onClick={submitRating} disabled={processing === ratingClass?.id} className="w-full rounded-xl bg-amber-500 text-slate-950 hover:bg-amber-600">Kirim {rating} bintang</Button></DialogContent>
      </Dialog>

      <Dialog open={Boolean(absenceReport)} onOpenChange={(open) => !open && setAbsenceReport(null)}>
        <DialogContent className="rounded-[2rem] sm:max-w-lg">
          <DialogHeader><DialogTitle>Laporkan tutor tidak hadir</DialogTitle><DialogDescription>Laporan tersedia setelah tutor terlambat lebih dari 15 menit. Admin akan memeriksa bukti sebelum refund penuh dan sanksi diputuskan.</DialogDescription></DialogHeader>
          <form onSubmit={submitTeacherAbsence} className="space-y-4">
            <Textarea required minLength={30} maxLength={2500} className="min-h-36 rounded-xl" value={absenceReason} onChange={(event) => setAbsenceReason(event.target.value)} placeholder="Jelaskan waktu menunggu, upaya menghubungi tutor, dan keadaan di lokasi/ruang online" />
            <div><Label>Bukti yang dapat diperiksa</Label><Input required className="mt-2" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" onChange={(event) => selectAbsenceEvidence(event.target.files?.[0])} /></div>
            <Button disabled={processing === absenceReport?.id} className="w-full rounded-xl bg-rose-600 hover:bg-rose-700">Kirim laporan</Button>
          </form>
        </DialogContent>
      </Dialog>
      {hubBookingId !== null && (
        <Suspense fallback={null}>
          <LearningSessionHub
            bookingId={hubBookingId}
            open
            initialTab={hubInitialTab}
            onOpenChange={(open) => {
              if (!open) {
                setHubBookingId(null);
                setHubInitialTab("session");
                setHubReturnClass(null);
                clearSessionActionQuery();
                void loadClasses({ silent: true });
              }
            }}
            backLabel={hubReturnClass ? "Kembali ke detail kelas" : undefined}
            onBack={hubReturnClass ? () => {
              setHubBookingId(null);
              setHubInitialTab("session");
              setSelected(hubReturnClass);
              setHubReturnClass(null);
            } : undefined}
          />
        </Suspense>
      )}
    </StudentLayout>
  );
}

function Info({ icon: Icon, text }: { icon: typeof Clock3; text: string }) {
  return <div className="flex min-w-0 items-start gap-2 rounded-xl bg-white/70 px-3 py-2.5"><Icon size={15} className="mt-0.5 shrink-0 text-indigo-500" /><span className="min-w-0 break-words leading-5">{text}</span></div>;
}

function ErrorState({ error, onRetry }: { error: string; onRetry: () => void }) {
  if (error === "network") {
    return (
      <div className="grid min-h-[400px] place-items-center px-4 text-center">
        <div>
          <WifiOff className="mx-auto text-slate-300" size={48} />
          <h2 className="mt-4 text-xl font-black text-slate-800">Koneksi Terputus</h2>
          <p className="mt-2 text-sm text-slate-500">Periksa koneksi internet Anda dan coba lagi.</p>
          <button onClick={onRetry} className="mt-5 inline-flex items-center gap-2 rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-black text-white hover:bg-indigo-700 transition-colors">
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
          <Link to="/student/dashboard" className="mt-5 inline-flex rounded-2xl bg-slate-900 px-5 py-3 text-sm font-black text-white hover:bg-slate-800 transition-colors">
            Kembali ke Beranda
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
          <p className="mt-2 text-sm text-slate-500">Kelas tidak dapat dimuat saat ini.</p>
          <button onClick={onRetry} className="mt-5 inline-flex items-center gap-2 rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-black text-white hover:bg-indigo-700 transition-colors">
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
        <p className="mt-2 text-sm text-slate-500">Kelas tidak dapat dimuat. Silakan coba lagi.</p>
        <button onClick={onRetry} className="mt-5 inline-flex items-center gap-2 rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-black text-white hover:bg-indigo-700 transition-colors">
          <RefreshCw size={16} /> Coba Lagi
        </button>
      </div>
    </div>
  );
}
