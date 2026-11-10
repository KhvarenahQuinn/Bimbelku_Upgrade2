import { notify } from "@/lib/notify";
import { formatSearchAge } from "@/lib/duration";
import { useCallback, useEffect, useMemo, useState } from "react";
import type { ReactNode } from "react";
import { useSearchParams } from "react-router-dom";
import {
  AlertTriangle,
  ArrowLeft,
  ArrowRight,
  CalendarClock,
  CheckCircle2,
  CircleDot,
  Clock3,
  History,
  Loader2,
  MapPin,
  Radar,
  RefreshCw,
  Search,
  SearchCheck,
  ShieldCheck,
  UserCheck,
  UserRound,
  Wifi,
} from "lucide-react";
import AdminLayout from "@/components/AdminLayout";
import { ResponsiveSelect } from "@/components/ResponsiveSelect";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import http, { getApiError } from "@/lib/http";

interface PersonSummary {
  id: number | null;
  name: string;
  email?: string | null;
}

interface ActiveOffer {
  id: number;
  teacher_id: number;
  teacher_name: string;
  distance_km?: number | null;
  offered_at?: string | null;
  expires_at?: string | null;
  is_overdue: boolean;
}

interface SearchItem {
  id: number;
  student: PersonSummary;
  subject_name: string;
  education_level: string;
  grade?: string | null;
  learning_mode: "online" | "offline";
  class_type: "private";
  scheduled_at?: string | null;
  status: "matching" | "teacher_pending" | "no_teacher" | "expired";
  status_label: string;
  status_description: string;
  search_radius_km: number | null;
  matching_attempts: number;
  search_started_at?: string | null;
  search_expires_at?: string | null;
  search_window_minutes?: number | null;
  teacher_response_deadline?: string | null;
  next_matching_at?: string | null;
  active_offer_count: number;
  search_age_minutes: number;
  search_age_seconds?: number | null;
  needs_attention: boolean;
  attention_reason?: string | null;
  can_synchronize: boolean;
  next_radius_km?: number | null;
  can_expand_radius: boolean;
  can_assign_manually: boolean;
  matched_teacher?: PersonSummary | null;
  active_offer?: ActiveOffer | null;
  active_offers?: ActiveOffer[];
  offer_totals: {
    total: number;
    rejected: number;
    expired: number;
    accepted: number;
  };
  source: {
    type: "package";
    label: "Paket Belajar";
    is_legacy: false;
    package_id?: number | null;
    package_status?: string | null;
  };
}

interface OfferHistory {
  id: number;
  teacher: PersonSummary & {
    rating_average: number;
    rating_count: number;
    max_travel_km?: number | null;
  };
  status: string;
  status_label: string;
  distance_km?: number | null;
  offered_at?: string | null;
  expires_at?: string | null;
  responded_at?: string | null;
  rejection_reason?: string | null;
  is_overdue: boolean;
}

interface OperationHistory {
  id: number;
  action: "radius_expanded" | "teacher_assigned_manually" | string;
  action_label: string;
  reason?: string | null;
  before_state?: Record<string, unknown> | null;
  after_state?: Record<string, unknown> | null;
  metadata?: Record<string, unknown> | null;
  actor?: PersonSummary | null;
  created_at?: string | null;
}

interface ManualCandidate {
  id: number;
  name: string;
  email?: string | null;
  rating_average: number;
    rating_count: number;
  distance_km?: number | null;
  max_travel_km?: number | null;
  previous_offer?: {
    status: string;
    responded_at?: string | null;
    rejection_reason?: string | null;
  } | null;
}

interface SearchDetail extends SearchItem {
  available_candidate_count: number;
  automatic_candidate_count?: number | null;
  manual_candidate_count: number;
  operation_history: OperationHistory[];
  schedule: {
    date?: string | null;
    start_time?: string | null;
    end_time?: string | null;
    duration_hours: number;
  };
  request_details: {
    chapter?: string | null;
    learning_goal?: string | null;
    address_available: boolean;
    coordinates_available: boolean;
  };
  offer_history: OfferHistory[];
}

interface SearchResponse {
  data: SearchItem[];
  summary: {
    all: number;
    matching: number;
    teacher_pending: number;
    no_teacher: number;
    expired: number;
    attention: number;
  };
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
  system_health: {
    scheduler_healthy: boolean;
    last_heartbeat_at?: string | null;
  };
}

type StatusFilter = "all" | "attention" | SearchItem["status"];
type ModeFilter = "all" | SearchItem["learning_mode"];
type SearchScope = "active" | "history";

const emptyResponse: SearchResponse = {
  data: [],
  summary: { all: 0, matching: 0, teacher_pending: 0, no_teacher: 0, expired: 0, attention: 0 },
  meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
  system_health: { scheduler_healthy: false, last_heartbeat_at: null },
};

const activeStatusOptions: { value: StatusFilter; label: string }[] = [
  { value: "all", label: "Semua pencarian" },
  { value: "attention", label: "Perlu perhatian" },
  { value: "matching", label: "Mencari tutor" },
  { value: "teacher_pending", label: "Menunggu tutor" },
  { value: "no_teacher", label: "Tutor belum ditemukan" },
];
const historyStatusOptions: { value: StatusFilter; label: string }[] = [
  { value: "all", label: "Semua riwayat" },
  { value: "expired", label: "Pencarian berakhir" },
];

const formatDateTime = (value?: string | null) => {
  if (!value) return "Belum tersedia";
  return new Intl.DateTimeFormat("id-ID", {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(value));
};

const formatBoundedSearchAge = (
  seconds?: number | null,
  minutes?: number | null,
  windowMinutes = 60,
) => {
  const maxSeconds = Math.max(1, windowMinutes) * 60;
  const boundedSeconds = seconds != null ? Math.min(Math.max(0, seconds), maxSeconds) : seconds;
  const boundedMinutes = minutes != null ? Math.min(Math.max(0, minutes), windowMinutes) : minutes;

  return formatSearchAge(boundedSeconds, boundedMinutes);
};

export default function TutorSearchMonitoring() {
  const [searchParams, setSearchParams] = useSearchParams();
  const initialStatus = (searchParams.get("status") || "all") as StatusFilter;
  const [scope, setScope] = useState<SearchScope>(initialStatus === "expired" ? "history" : "active");
  const [status, setStatus] = useState<StatusFilter>(
    [...activeStatusOptions, ...historyStatusOptions].some((option) => option.value === initialStatus) ? initialStatus : "all",
  );
  const [mode, setMode] = useState<ModeFilter>("all");
  const [searchText, setSearchText] = useState("");
  const [appliedSearch, setAppliedSearch] = useState("");
  const [page, setPage] = useState(1);
  const [result, setResult] = useState<SearchResponse>(emptyResponse);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState("");
  const [detail, setDetail] = useState<SearchDetail | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [syncing, setSyncing] = useState(false);
  const [expandOpen, setExpandOpen] = useState(false);
  const [expandReason, setExpandReason] = useState("");
  const [expanding, setExpanding] = useState(false);
  const [assignOpen, setAssignOpen] = useState(false);
  const [candidateSearch, setCandidateSearch] = useState("");
  const [candidates, setCandidates] = useState<ManualCandidate[]>([]);
  const [candidatesLoading, setCandidatesLoading] = useState(false);
  const [selectedTeacherId, setSelectedTeacherId] = useState<number | null>(null);
  const [assignmentReason, setAssignmentReason] = useState("");
  const [confirmedTeacherConsent, setConfirmedTeacherConsent] = useState(false);
  const [assigning, setAssigning] = useState(false);
  const openId = searchParams.get("open");

  useEffect(() => {
    const timeoutId = window.setTimeout(() => {
      setAppliedSearch(searchText.trim());
      setPage(1);
    }, 350);
    return () => window.clearTimeout(timeoutId);
  }, [searchText]);

  const fetchSearches = useCallback(async () => {
    setIsLoading(true);
    setError("");
    try {
      const response = await http.get<SearchResponse>("/admin/tutor-searches", {
        params: {
          status,
          scope,
          learning_mode: mode,
          q: appliedSearch || undefined,
          page,
          per_page: 20,
        },
      });
      setResult(response.data);
    } catch (requestError) {
      setError(getApiError(requestError, "Data pencarian tutor gagal dimuat."));
    } finally {
      setIsLoading(false);
    }
  }, [appliedSearch, mode, page, scope, status]);

  const loadDetail = useCallback(async (id: number) => {
    setDetailLoading(true);
    setDetail(null);

    try {
      const response = await http.get<{ data: SearchDetail }>(`/admin/tutor-searches/${id}`);
      setDetail(response.data.data);
    } catch (requestError) {
      notify.error(getApiError(requestError, "Detail pencarian tutor gagal dimuat."));
    } finally {
      setDetailLoading(false);
    }
  }, []);

  const openDetail = (id: number) => {
    const next = new URLSearchParams(searchParams);
    next.set("open", String(id));
    setSearchParams(next, { replace: true });
  };

  useEffect(() => {
    void fetchSearches();
  }, [fetchSearches]);

  useEffect(() => {
    if (openId && Number.isInteger(Number(openId)) && Number(openId) > 0) {
      void loadDetail(Number(openId));
    }
  }, [loadDetail, openId]);

  const updateStatus = (value: StatusFilter) => {
    setStatus(value);
    setPage(1);
    const next = new URLSearchParams(searchParams);
    if (value === "all") next.delete("status");
    else next.set("status", value);
    next.delete("open");
    setSearchParams(next, { replace: true });
  };

  const closeDetail = () => {
    setDetail(null);
    setExpandOpen(false);
    setAssignOpen(false);
    setCandidates([]);
    setSelectedTeacherId(null);
    const next = new URLSearchParams(searchParams);
    next.delete("open");
    setSearchParams(next, { replace: true });
  };

  const updateScope = (value: SearchScope) => {
    setScope(value);
    setStatus("all");
    setPage(1);
    setDetail(null);
    setExpandOpen(false);
    setAssignOpen(false);
    const next = new URLSearchParams(searchParams);
    next.delete("status");
    next.delete("open");
    setSearchParams(next, { replace: true });
  };

  const showAttention = () => {
    setScope("active");
    setMode("all");
    setSearchText("");
    setAppliedSearch("");
    updateStatus("attention");
  };

  const synchronize = async () => {
    if (!detail) return;
    setSyncing(true);
    try {
      const response = await http.post<{ message: string }>(`/admin/tutor-searches/${detail.id}/synchronize`);
      notify.success(response.data.message);
      await Promise.all([fetchSearches(), loadDetail(detail.id)]);
    } catch (requestError) {
      notify.error(getApiError(requestError, "Pencarian tutor gagal disinkronkan."));
    } finally {
      setSyncing(false);
    }
  };


  const expandRadius = async () => {
    if (!detail || !detail.next_radius_km) return;
    if (expandReason.trim().length < 10) {
      notify.error("Tuliskan alasan perluasan radius minimal 10 karakter.");
      return;
    }

    setExpanding(true);
    try {
      const response = await http.post<{ message: string }>(
        `/admin/tutor-searches/${detail.id}/expand-radius`,
        { reason: expandReason.trim() },
      );
      notify.success(response.data.message);
      setExpandOpen(false);
      setExpandReason("");
      await Promise.all([fetchSearches(), loadDetail(detail.id)]);
    } catch (requestError) {
      notify.error(getApiError(requestError, "Radius pencarian gagal diperluas."));
    } finally {
      setExpanding(false);
    }
  };

  const loadCandidates = useCallback(async (id: number, query = "") => {
    setCandidatesLoading(true);
    try {
      const response = await http.get<{ data: ManualCandidate[] }>(
        `/admin/tutor-searches/${id}/candidates`,
        { params: { q: query.trim() || undefined } },
      );
      setCandidates(response.data.data);
      setSelectedTeacherId((current) => (
        current && response.data.data.some((candidate) => candidate.id === current)
          ? current
          : null
      ));
    } catch (requestError) {
      setCandidates([]);
      notify.error(getApiError(requestError, "Kandidat tutor gagal dimuat."));
    } finally {
      setCandidatesLoading(false);
    }
  }, []);

  useEffect(() => {
    if (!assignOpen || !detail) return;
    const timer = window.setTimeout(() => {
      void loadCandidates(detail.id, candidateSearch);
    }, 300);
    return () => window.clearTimeout(timer);
  }, [assignOpen, candidateSearch, detail, loadCandidates]);

  const openAssignment = () => {
    if (!detail) return;
    setCandidateSearch("");
    setCandidates([]);
    setSelectedTeacherId(null);
    setAssignmentReason("");
    setConfirmedTeacherConsent(false);
    setAssignOpen(true);
  };

  const assignTeacher = async () => {
    if (!detail || !selectedTeacherId) {
      notify.error("Pilih tutor yang akan ditetapkan.");
      return;
    }
    if (assignmentReason.trim().length < 10) {
      notify.error("Tuliskan alasan penetapan minimal 10 karakter.");
      return;
    }
    if (!confirmedTeacherConsent) {
      notify.error("Konfirmasi bahwa tutor sudah menyatakan bersedia.");
      return;
    }

    setAssigning(true);
    try {
      const response = await http.post<{ message: string }>(
        `/admin/tutor-searches/${detail.id}/assign-teacher`,
        {
          teacher_id: selectedTeacherId,
          reason: assignmentReason.trim(),
          confirmed_teacher_consent: true,
        },
      );
      notify.success(response.data.message);
      setAssignOpen(false);
      closeDetail();
      await fetchSearches();
    } catch (requestError) {
      notify.error(getApiError(requestError, "Tutor gagal ditetapkan."));
    } finally {
      setAssigning(false);
    }
  };

  const activeFilterCount = useMemo(
    () => Number(status !== "all") + Number(mode !== "all") + Number(Boolean(appliedSearch)),
    [appliedSearch, mode, status],
  );

  return (
    <AdminLayout
      title="Pencarian tutor"
      subtitle="Pantau penawaran, batas jawaban, riwayat kandidat, dan kegagalan pencocokan."
    >
      <div className="space-y-6">
        <section className="rounded-3xl bg-slate-950 p-5 text-white shadow-xl shadow-slate-300 sm:p-7">
          <div className="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
            <div className="max-w-2xl">
              <div className="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1.5 text-xs font-black text-orange-200">
                <SearchCheck size={15} /> Mesin pencocokan tutor
              </div>
              <h1 className="mt-4 text-2xl font-black sm:text-3xl">Pantau, perluas radar, atau tetapkan tutor dengan jejak keputusan yang jelas.</h1>
              <p className="mt-2 text-sm leading-6 text-slate-300">
                Radius offline hanya bertambah berurutan 3, 5, 8, lalu 12 km. Penetapan manual tetap memeriksa kompetensi, jadwal, status tutor, dan jangkauan sebelum diproses.
              </p>
            </div>
            <button
              type="button"
              onClick={() => void fetchSearches()}
              disabled={isLoading}
              className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-white/15 bg-white/10 px-4 py-2.5 text-sm font-black hover:bg-white/15 disabled:opacity-60"
            >
              <RefreshCw size={17} className={isLoading ? "animate-spin" : ""} /> Perbarui
            </button>
          </div>

          <div className="mt-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
            <SummaryBox label="Semua" value={result.summary.all} />
            <SummaryBox label="Perhatian" value={result.summary.attention} alert onClick={showAttention} />
            <SummaryBox label="Mencari" value={result.summary.matching} />
            <SummaryBox label="Menunggu" value={result.summary.teacher_pending} />
            <SummaryBox label="Tidak ditemukan" value={result.summary.no_teacher} alert />
            <SummaryBox label="Berakhir" value={result.summary.expired} />
          </div>
          <p className="mt-3 text-xs text-slate-300">Angka ringkasan mencakup semua pencarian. Klik Perhatian untuk membuka seluruh item yang perlu tindakan, termasuk jawaban tutor yang terlambat.</p>
          <div className={`mt-4 rounded-2xl border px-4 py-3 text-xs font-bold ${result.system_health.scheduler_healthy ? "border-emerald-400/30 bg-emerald-400/10 text-emerald-100" : "border-rose-400/30 bg-rose-400/10 text-rose-100"}`}>
            Scheduler pencarian: {result.system_health.scheduler_healthy ? "aktif" : "belum terdeteksi"} · heartbeat terakhir {formatDateTime(result.system_health.last_heartbeat_at)}
          </div>
        </section>

        <section className="grid grid-cols-2 gap-2 rounded-2xl border border-slate-200 bg-white p-2 shadow-sm">
          <button type="button" onClick={() => updateScope("active")} className={`min-h-11 rounded-xl px-4 text-sm font-black transition ${scope === "active" ? "bg-orange-500 text-white" : "text-slate-500 hover:bg-slate-50"}`}>
            Pencarian aktif
          </button>
          <button type="button" onClick={() => updateScope("history")} className={`inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-4 text-sm font-black transition ${scope === "history" ? "bg-slate-950 text-white" : "text-slate-500 hover:bg-slate-50"}`}>
            <History size={16} /> Riwayat pencarian
          </button>
        </section>

        <section className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
          <div className="grid gap-3 lg:grid-cols-[minmax(14rem,1fr)_12rem_12rem]">
            <label className="relative block">
              <span className="sr-only">Cari pencarian tutor</span>
              <Search className="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
              <input
                value={searchText}
                onChange={(event) => setSearchText(event.target.value)}
                placeholder="Cari murid, tutor, mapel, atau jenjang"
                className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-11 pr-4 text-sm font-medium outline-none transition focus:border-orange-400 focus:bg-white focus:ring-4 focus:ring-orange-100"
              />
            </label>
            <FilterSelect
              label="Status"
              value={status}
              onChange={(value) => updateStatus(value as StatusFilter)}
              options={scope === "history" ? historyStatusOptions : activeStatusOptions}
            />
            <FilterSelect
              label="Mode"
              value={mode}
              onChange={(value) => { setMode(value as ModeFilter); setPage(1); }}
              options={[
                { value: "all", label: "Semua mode" },
                { value: "online", label: "Online" },
                { value: "offline", label: "Offline" },
              ]}
            />

          </div>
          <div className="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
            <p>{activeFilterCount > 0 ? `${activeFilterCount} filter aktif` : scope === "active" ? "Menampilkan proses pencarian yang masih berjalan" : "Menampilkan pencarian yang sudah berakhir"}</p>
            <p>{result.meta.total} permintaan ditemukan</p>
          </div>
        </section>

        <section>
          {error ? (
            <div className="rounded-3xl border border-rose-200 bg-white p-8 text-center">
              <AlertTriangle className="mx-auto text-rose-500" size={38} />
              <h2 className="mt-3 text-lg font-black text-slate-950">Data pencarian gagal dimuat</h2>
              <p className="mt-2 text-sm text-slate-600">{error}</p>
              <button
                type="button"
                onClick={() => void fetchSearches()}
                className="mt-5 inline-flex min-h-11 items-center gap-2 rounded-xl bg-slate-950 px-5 text-sm font-black text-white"
              >
                <RefreshCw size={17} /> Coba lagi
              </button>
            </div>
          ) : isLoading ? (
            <div className="grid min-h-72 place-items-center rounded-3xl border border-slate-200 bg-white">
              <div className="text-center">
                <Loader2 className="mx-auto animate-spin text-orange-600" size={34} />
                <p className="mt-3 text-sm font-bold text-slate-500">Memeriksa proses pencocokan...</p>
              </div>
            </div>
          ) : result.data.length === 0 ? (
            <div className="rounded-3xl border border-slate-200 bg-white p-10 text-center">
              <CheckCircle2 className="mx-auto text-emerald-600" size={42} />
              <h2 className="mt-4 text-lg font-black text-slate-950">Tidak ada pencarian pada filter ini</h2>
              <p className="mt-2 text-sm text-slate-500">Ubah filter atau pencarian untuk melihat data lainnya.</p>
              {result.summary.attention > 0 && (
                <button type="button" onClick={showAttention} className="mt-4 min-h-11 rounded-xl bg-slate-950 px-5 text-sm font-black text-white">
                  Lihat {result.summary.attention} pencarian yang perlu perhatian
                </button>
              )}
            </div>
          ) : (
            <div className="space-y-3">
              {result.data.map((item) => (
                <SearchCard key={item.id} item={item} onOpen={() => openDetail(item.id)} />
              ))}
            </div>
          )}
        </section>

        {result.meta.last_page > 1 && (
          <nav className="flex items-center justify-between rounded-2xl border border-slate-200 bg-white p-3" aria-label="Navigasi halaman">
            <button
              type="button"
              disabled={page <= 1 || isLoading}
              onClick={() => setPage((current) => Math.max(1, current - 1))}
              className="inline-flex min-h-10 items-center gap-2 rounded-xl px-3 text-sm font-black text-slate-700 hover:bg-slate-100 disabled:opacity-40"
            >
              <ArrowLeft size={17} /> Sebelumnya
            </button>
            <p className="text-xs font-black text-slate-500">
              Halaman {result.meta.current_page} dari {result.meta.last_page}
            </p>
            <button
              type="button"
              disabled={page >= result.meta.last_page || isLoading}
              onClick={() => setPage((current) => Math.min(result.meta.last_page, current + 1))}
              className="inline-flex min-h-10 items-center gap-2 rounded-xl px-3 text-sm font-black text-slate-700 hover:bg-slate-100 disabled:opacity-40"
            >
              Berikutnya <ArrowRight size={17} />
            </button>
          </nav>
        )}
      </div>

      <Dialog open={Boolean(openId)} onOpenChange={(open) => { if (!open) closeDetail(); }}>
        <DialogContent className="max-w-4xl gap-0 overflow-hidden rounded-3xl border-0 p-0">
          <DialogHeader className="border-b border-slate-200 bg-slate-50 px-5 py-5 pr-12 sm:px-7">
            <DialogTitle className="text-xl font-black text-slate-950">Detail pencarian tutor</DialogTitle>
            <DialogDescription>Riwayat penawaran dan kondisi mesin pencocokan untuk satu permintaan.</DialogDescription>
          </DialogHeader>

          <div className="max-h-[calc(100dvh-10rem)] overflow-y-auto p-5 sm:p-7">
            {detailLoading ? (
              <div className="grid min-h-80 place-items-center">
                <Loader2 className="animate-spin text-orange-600" size={36} />
              </div>
            ) : detail ? (
              <div className="space-y-6">
                <div className="flex flex-col gap-4 rounded-3xl bg-slate-950 p-5 text-white sm:flex-row sm:items-start sm:justify-between">
                  <div>
                    <StatusBadge status={detail.status} attention={detail.needs_attention} />
                    <h2 className="mt-3 text-2xl font-black">{detail.subject_name}</h2>
                    <p className="mt-1 text-sm text-slate-300">
                      {detail.student.name} · {detail.education_level}{detail.grade ? ` · ${detail.grade}` : ""}
                    </p>
                  </div>
                  <div className="grid grid-cols-2 gap-2 sm:min-w-72">
                    <div className="rounded-2xl bg-white/10 px-4 py-3 text-sm">
                      <p className="text-xs font-bold text-slate-300">Kandidat otomatis</p>
                      <p className="mt-1 text-2xl font-black">
                        {detail.automatic_candidate_count ?? detail.available_candidate_count}
                      </p>
                    </div>
                    <div className="rounded-2xl bg-white/10 px-4 py-3 text-sm">
                      <p className="text-xs font-bold text-slate-300">Layak ditetapkan</p>
                      <p className="mt-1 text-2xl font-black">{detail.manual_candidate_count}</p>
                    </div>
                  </div>
                </div>

                {detail.attention_reason && (
                  <div className="flex gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-rose-800">
                    <AlertTriangle className="mt-0.5 shrink-0" size={20} />
                    <div>
                      <p className="font-black">Perlu perhatian admin</p>
                      <p className="mt-1 text-sm leading-5">{detail.attention_reason}</p>
                    </div>
                  </div>
                )}

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                  <InfoBox icon={CalendarClock} label="Jadwal" value={formatDateTime(detail.scheduled_at)} />
                  <InfoBox icon={detail.learning_mode === "online" ? Wifi : MapPin} label="Mode" value={detail.learning_mode === "online" ? "Online" : `Offline · ${detail.search_radius_km} km`} />
                  <InfoBox icon={UserRound} label="Jenis" value="Privat" />
                  <InfoBox icon={Clock3} label="Lama pencarian" value={formatBoundedSearchAge(detail.search_age_seconds, detail.search_age_minutes, detail.search_window_minutes ?? 60)} />
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                  <DetailPanel title="Kondisi pencarian">
                    <DetailRow label="Status" value={detail.status_label} />
                    {detail.learning_mode === "offline" && <DetailRow label="Radius saat ini" value={`${detail.search_radius_km ?? 3} km`} />}
                    <DetailRow label="Jumlah percobaan" value={String(detail.matching_attempts)} />
                    <DetailRow label="Mulai mencari" value={formatDateTime(detail.search_started_at)} />
                    <DetailRow label="Batas pencarian" value={formatDateTime(detail.search_expires_at)} />
                    <DetailRow label="Batas jawaban tutor" value={formatDateTime(detail.teacher_response_deadline)} />
                  </DetailPanel>

                  <DetailPanel title="Permintaan belajar">
                    <DetailRow label="Mapel" value={detail.subject_name} />
                    <DetailRow label="Jenjang" value={`${detail.education_level}${detail.grade ? ` · ${detail.grade}` : ""}`} />
                    <DetailRow label="Bab" value={detail.request_details.chapter || "Tidak diisi"} />
                    <DetailRow label="Sumber" value={sourceLabel(detail)} />
                    <DetailRow label="Lokasi lengkap" value={detail.request_details.address_available && detail.request_details.coordinates_available ? "Tersedia dan terlindungi" : "Belum lengkap"} />
                  </DetailPanel>
                </div>

                <section>
                  <div className="mb-3 flex items-center justify-between gap-4">
                    <div>
                      <h3 className="text-lg font-black text-slate-950">Riwayat kontrol admin</h3>
                      <p className="text-sm text-slate-500">Perluasan radius dan penetapan manual tersimpan bersama alasan serta pelakunya.</p>
                    </div>
                    <History size={20} className="text-slate-400" />
                  </div>
                  {detail.operation_history.length === 0 ? (
                    <div className="rounded-2xl border border-dashed border-slate-300 p-5 text-center text-sm text-slate-500">
                      Belum ada perubahan pencarian yang dilakukan admin atau murid.
                    </div>
                  ) : (
                    <div className="space-y-3">
                      {detail.operation_history.map((operation) => (
                        <div key={operation.id} className="rounded-2xl border border-slate-200 p-4">
                          <div className="flex items-start gap-3">
                            <div className={`grid h-10 w-10 shrink-0 place-items-center rounded-xl ${operation.action === "teacher_assigned_manually" ? "bg-emerald-50 text-emerald-700" : "bg-orange-50 text-orange-700"}`}>
                              {operation.action === "teacher_assigned_manually" ? <UserCheck size={19} /> : <Radar size={19} />}
                            </div>
                            <div className="min-w-0 flex-1">
                              <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                <p className="font-black text-slate-900">{operation.action_label}</p>
                                <p className="text-xs text-slate-400">{formatDateTime(operation.created_at)}</p>
                              </div>
                              <p className="mt-1 text-xs font-bold text-slate-500">
                                Oleh {operation.actor?.name || "Sistem"}
                              </p>
                              {operation.reason && <p className="mt-3 rounded-xl bg-slate-50 p-3 text-sm leading-5 text-slate-700">{operation.reason}</p>}
                              {operation.action === "radius_expanded" && (
                                <p className="mt-2 text-xs font-black text-orange-700">
                                  Radius {String(operation.before_state?.search_radius_km ?? "-")} km → {String(operation.after_state?.search_radius_km ?? "-")} km
                                </p>
                              )}
                              {operation.action === "teacher_assigned_manually" && (
                                <p className="mt-2 text-xs font-black text-emerald-700">
                                  Tutor: {String(operation.metadata?.teacher_name ?? "Tutor")}
                                </p>
                              )}
                              {operation.action === "offers_dispatched" && (
                                <p className="mt-2 text-xs font-black text-indigo-700">
                                  {String(operation.metadata?.offer_count ?? 0)} tutor menerima penawaran
                                  {operation.metadata?.offer_expires_at
                                    ? ` · jawab sebelum ${formatDateTime(String(operation.metadata.offer_expires_at))}`
                                    : ""}
                                </p>
                              )}
                              {operation.action === "offer_expired" && (
                                <p className="mt-2 text-xs font-black text-amber-700">
                                  Penawaran tutor ditutup karena batas waktu.
                                </p>
                              )}
                            </div>
                          </div>
                        </div>
                      ))}
                    </div>
                  )}
                </section>

                <section>
                  <div className="mb-3 flex items-center justify-between gap-4">
                    <div>
                      <h3 className="text-lg font-black text-slate-950">Riwayat penawaran</h3>
                      <p className="text-sm text-slate-500">Tutor yang sudah ditawari, jawaban, dan batas waktunya.</p>
                    </div>
                    <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-black text-slate-600">
                      {detail.offer_history.length} penawaran
                    </span>
                  </div>

                  {detail.offer_history.length === 0 ? (
                    <div className="rounded-2xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                      Belum ada tutor yang menerima penawaran dari mesin pencocokan.
                    </div>
                  ) : (
                    <div className="space-y-3">
                      {detail.offer_history.map((offer) => (
                        <div key={offer.id} className="rounded-2xl border border-slate-200 p-4">
                          <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div className="flex min-w-0 gap-3">
                              <div className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-indigo-50 text-indigo-600">
                                <UserRound size={19} />
                              </div>
                              <div className="min-w-0">
                                <p className="truncate font-black text-slate-900">{offer.teacher.name}</p>
                                <p className="mt-0.5 truncate text-xs text-slate-500">{offer.teacher.email || "Email tidak tersedia"}</p>
                                <p className="mt-2 text-xs font-bold text-slate-500">
                                  {offer.distance_km != null ? `${offer.distance_km} km · ` : ""}{offer.teacher.rating_count ? offer.teacher.rating_average.toFixed(1) + " ★ · " + offer.teacher.rating_count + " ulasan" : "Belum ada rating"}
                                </p>
                              </div>
                            </div>
                            <div className="sm:text-right">
                              <OfferBadge status={offer.status} overdue={offer.is_overdue} label={offer.status_label} />
                              <p className="mt-2 text-xs text-slate-400">Dikirim {formatDateTime(offer.offered_at)}</p>
                            </div>
                          </div>
                          {offer.rejection_reason && (
                            <p className="mt-3 rounded-xl bg-slate-50 p-3 text-sm text-slate-600">
                              Alasan: {offer.rejection_reason}
                            </p>
                          )}
                        </div>
                      ))}
                    </div>
                  )}
                </section>

                <div className="rounded-3xl border border-slate-200 bg-slate-50 p-4 sm:p-5">
                  <div className="flex items-start gap-3">
                    <ShieldCheck className="mt-0.5 shrink-0 text-slate-700" size={21} />
                    <div>
                      <p className="font-black text-slate-950">Kontrol operasional</p>
                      <p className="mt-1 text-xs leading-5 text-slate-500">
                        Perluasan radius hanya tersedia setelah kandidat pada radius saat ini habis. Penetapan manual mewajibkan admin memastikan tutor sudah bersedia dan backend akan memeriksa seluruh syarat kembali.
                      </p>
                    </div>
                  </div>
                  <div className="mt-4 grid gap-2 sm:grid-cols-3">
                    <button
                      type="button"
                      onClick={() => void synchronize()}
                      disabled={!detail.can_synchronize || syncing}
                      className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-4 text-sm font-black text-slate-800 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-45"
                    >
                      {syncing ? <Loader2 className="animate-spin" size={17} /> : <RefreshCw size={17} />}
                      Sinkronkan
                    </button>
                    <button
                      type="button"
                      onClick={() => setExpandOpen(true)}
                      disabled={!detail.can_expand_radius || !detail.next_radius_km}
                      className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-orange-600 px-4 text-sm font-black text-white hover:bg-orange-700 disabled:cursor-not-allowed disabled:opacity-45"
                    >
                      <Radar size={17} />
                      {detail.next_radius_km ? `Perluas ke ${detail.next_radius_km} km` : "Radius maksimum"}
                    </button>
                    <button
                      type="button"
                      onClick={openAssignment}
                      disabled={!detail.can_assign_manually || detail.manual_candidate_count < 1}
                      className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-emerald-700 px-4 text-sm font-black text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-45"
                    >
                      <UserCheck size={17} /> Tetapkan tutor
                    </button>
                  </div>
                </div>
              </div>
            ) : (
              <div className="p-8 text-center text-sm text-slate-500">Detail tidak tersedia.</div>
            )}
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={expandOpen} onOpenChange={(open) => { if (!expanding) setExpandOpen(open); }}>
        <DialogContent className="max-w-lg rounded-3xl">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2 text-xl font-black text-slate-950">
              <Radar className="text-orange-600" size={22} /> Perluas radius pencarian
            </DialogTitle>
            <DialogDescription>
              Mesin akan mencari ulang pada radius berikutnya. Perubahan ini dicatat dan tidak dapat mengecilkan radius kembali.
            </DialogDescription>
          </DialogHeader>
          {detail && (
            <div className="space-y-4">
              <div className="grid grid-cols-[1fr_auto_1fr] items-center gap-3 rounded-2xl bg-orange-50 p-4 text-center">
                <div>
                  <p className="text-xs font-bold text-orange-700">Radius sekarang</p>
                  <p className="mt-1 text-2xl font-black text-slate-950">{detail.search_radius_km} km</p>
                </div>
                <ArrowRight className="text-orange-500" size={22} />
                <div>
                  <p className="text-xs font-bold text-orange-700">Radius berikutnya</p>
                  <p className="mt-1 text-2xl font-black text-slate-950">{detail.next_radius_km ?? "-"} km</p>
                </div>
              </div>
              <label className="block">
                <span className="text-sm font-black text-slate-800">Alasan perluasan</span>
                <textarea
                  value={expandReason}
                  onChange={(event) => setExpandReason(event.target.value)}
                  maxLength={500}
                  rows={4}
                  placeholder="Contoh: kandidat pada radius 3 km sudah habis dan jadwal murid semakin dekat."
                  className="mt-2 w-full rounded-2xl border border-slate-300 px-4 py-3 text-sm outline-none focus:border-orange-500 focus:ring-4 focus:ring-orange-100"
                />
                <span className="mt-1 block text-right text-xs text-slate-400">{expandReason.trim().length}/500</span>
              </label>
              <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button
                  type="button"
                  onClick={() => setExpandOpen(false)}
                  disabled={expanding}
                  className="min-h-11 rounded-xl border border-slate-300 px-5 text-sm font-black text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                >
                  Batal
                </button>
                <button
                  type="button"
                  onClick={() => void expandRadius()}
                  disabled={expanding || expandReason.trim().length < 10}
                  className="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-orange-600 px-5 text-sm font-black text-white hover:bg-orange-700 disabled:opacity-50"
                >
                  {expanding ? <Loader2 className="animate-spin" size={17} /> : <Radar size={17} />}
                  Perluas dan cari ulang
                </button>
              </div>
            </div>
          )}
        </DialogContent>
      </Dialog>

      <Dialog open={assignOpen} onOpenChange={(open) => { if (!assigning) setAssignOpen(open); }}>
        <DialogContent className="max-w-3xl gap-0 overflow-hidden rounded-3xl border-0 p-0">
          <DialogHeader className="border-b border-slate-200 bg-slate-50 px-5 py-5 pr-12 sm:px-7">
            <DialogTitle className="flex items-center gap-2 text-xl font-black text-slate-950">
              <UserCheck className="text-emerald-700" size={22} /> Tetapkan tutor secara manual
            </DialogTitle>
            <DialogDescription>
              Hanya tutor yang masih memenuhi kompetensi, jadwal, status akun, rating, mode, dan radius yang dapat dipilih.
            </DialogDescription>
          </DialogHeader>
          <div className="max-h-[calc(100dvh-9rem)] space-y-5 overflow-y-auto p-5 sm:p-7">
            <label className="relative block">
              <Search className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
              <input
                value={candidateSearch}
                onChange={(event) => setCandidateSearch(event.target.value)}
                placeholder="Cari nama atau email tutor"
                className="h-11 w-full rounded-xl border border-slate-300 pl-10 pr-4 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-100"
              />
            </label>

            <section>
              <div className="mb-3 flex items-center justify-between gap-3">
                <div>
                  <h3 className="font-black text-slate-950">Kandidat yang layak</h3>
                  <p className="text-xs text-slate-500">Klik satu tutor untuk memilihnya.</p>
                </div>
                <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-black text-slate-600">{candidates.length} tutor</span>
              </div>

              {candidatesLoading ? (
                <div className="grid min-h-44 place-items-center rounded-2xl border border-slate-200">
                  <Loader2 className="animate-spin text-emerald-700" size={30} />
                </div>
              ) : candidates.length === 0 ? (
                <div className="rounded-2xl border border-dashed border-slate-300 p-7 text-center">
                  <UserRound className="mx-auto text-slate-400" size={34} />
                  <p className="mt-3 font-black text-slate-800">Tidak ada tutor yang memenuhi seluruh syarat</p>
                  <p className="mt-1 text-sm text-slate-500">Perluas radius terlebih dahulu untuk kelas offline atau periksa jadwal dan mapel.</p>
                </div>
              ) : (
                <div className="max-h-72 space-y-2 overflow-y-auto pr-1">
                  {candidates.map((candidate) => {
                    const selected = selectedTeacherId === candidate.id;
                    const previousStatus = candidate.previous_offer?.status;
                    return (
                      <button
                        key={candidate.id}
                        type="button"
                        onClick={() => setSelectedTeacherId(candidate.id)}
                        className={`w-full rounded-2xl border p-4 text-left transition ${selected ? "border-emerald-500 bg-emerald-50 ring-2 ring-emerald-100" : "border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50"}`}
                      >
                        <div className="flex items-start gap-3">
                          <div className={`grid h-11 w-11 shrink-0 place-items-center rounded-xl ${selected ? "bg-emerald-700 text-white" : "bg-slate-100 text-slate-600"}`}>
                            <UserRound size={20} />
                          </div>
                          <div className="min-w-0 flex-1">
                            <div className="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                              <div>
                                <p className="font-black text-slate-950">{candidate.name}</p>
                                <p className="truncate text-xs text-slate-500">{candidate.email || "Email tidak tersedia"}</p>
                              </div>
                              {selected && <span className="rounded-full bg-emerald-700 px-2.5 py-1 text-[10px] font-black uppercase text-white">Dipilih</span>}
                            </div>
                            <div className="mt-2 flex flex-wrap gap-2 text-xs font-bold text-slate-600">
                              <span className="rounded-full bg-white px-2.5 py-1">{candidate.rating_count ? candidate.rating_average.toFixed(1) + " ★ · " + candidate.rating_count + " ulasan" : "Belum ada rating"}</span>
                              {candidate.distance_km != null && <span className="rounded-full bg-white px-2.5 py-1">{candidate.distance_km} km</span>}
                              {candidate.max_travel_km != null && <span className="rounded-full bg-white px-2.5 py-1">Jangkauan {candidate.max_travel_km} km</span>}
                            </div>
                            {previousStatus && (
                              <p className={`mt-2 text-xs font-bold ${previousStatus === "rejected" ? "text-rose-700" : "text-amber-700"}`}>
                                Pernah ditawari: {offerStatusText(previousStatus)}. Pastikan tutor benar-benar bersedia sebelum ditetapkan.
                              </p>
                            )}
                          </div>
                        </div>
                      </button>
                    );
                  })}
                </div>
              )}
            </section>

            <label className="block">
              <span className="text-sm font-black text-slate-800">Alasan penetapan manual</span>
              <textarea
                value={assignmentReason}
                onChange={(event) => setAssignmentReason(event.target.value)}
                maxLength={500}
                rows={4}
                placeholder="Tuliskan alasan operasional dan hasil konfirmasi dengan tutor."
                className="mt-2 w-full rounded-2xl border border-slate-300 px-4 py-3 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-100"
              />
              <span className="mt-1 block text-right text-xs text-slate-400">{assignmentReason.trim().length}/500</span>
            </label>

            <label className="flex cursor-pointer items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4">
              <input
                type="checkbox"
                checked={confirmedTeacherConsent}
                onChange={(event) => setConfirmedTeacherConsent(event.target.checked)}
                className="mt-1 h-4 w-4 rounded border-amber-400 text-emerald-700 focus:ring-emerald-600"
              />
              <span className="text-sm leading-6 text-amber-950">
                Saya sudah menghubungi tutor dan memastikan tutor bersedia menerima seluruh jadwal. Penetapan ini akan langsung membatalkan penawaran lain dan membuka tahapan berikutnya.
              </span>
            </label>

            <div className="flex flex-col-reverse gap-2 border-t border-slate-200 pt-5 sm:flex-row sm:justify-end">
              <button
                type="button"
                onClick={() => setAssignOpen(false)}
                disabled={assigning}
                className="min-h-11 rounded-xl border border-slate-300 px-5 text-sm font-black text-slate-700 hover:bg-slate-50 disabled:opacity-50"
              >
                Batal
              </button>
              <button
                type="button"
                onClick={() => void assignTeacher()}
                disabled={assigning || !selectedTeacherId || assignmentReason.trim().length < 10 || !confirmedTeacherConsent}
                className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-emerald-700 px-5 text-sm font-black text-white hover:bg-emerald-800 disabled:opacity-50"
              >
                {assigning ? <Loader2 className="animate-spin" size={17} /> : <UserCheck size={17} />}
                Tetapkan tutor
              </button>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </AdminLayout>
  );
}

function SearchCard({ item, onOpen }: { item: SearchItem; onOpen: () => void }) {
  return (
    <article className={`rounded-3xl border bg-white p-4 shadow-sm sm:p-5 ${item.needs_attention ? "border-rose-200" : "border-slate-200"}`}>
      <div className="flex flex-col gap-4 xl:flex-row xl:items-center">
        <div className={`grid h-12 w-12 shrink-0 place-items-center rounded-2xl ${item.needs_attention ? "bg-rose-50 text-rose-600" : "bg-indigo-50 text-indigo-600"}`}>
          {item.needs_attention ? <AlertTriangle size={23} /> : <SearchCheck size={23} />}
        </div>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="font-black text-slate-950">{item.subject_name}</h2>
            <StatusBadge status={item.status} attention={item.needs_attention} />
            <span className="rounded-full bg-slate-100 px-2 py-1 text-[10px] font-black uppercase tracking-wide text-slate-600">
              {item.source.label}
            </span>
          </div>
          <p className="mt-1 text-sm text-slate-600">
            {item.student.name} · {item.education_level}{item.grade ? ` · ${item.grade}` : ""}
          </p>
          <div className="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-xs font-medium text-slate-500">
            <span className="inline-flex items-center gap-1.5"><CalendarClock size={14} /> {formatDateTime(item.scheduled_at)}</span>
            <span className="inline-flex items-center gap-1.5">{item.learning_mode === "online" ? <Wifi size={14} /> : <MapPin size={14} />} {item.learning_mode === "online" ? "Online" : `Offline · ${item.search_radius_km} km`}</span>
            <span className="inline-flex items-center gap-1.5"><CircleDot size={14} /> {item.matching_attempts} penawaran dicoba</span>
          </div>
          {item.attention_reason && <p className="mt-3 text-sm font-bold text-rose-700">{item.attention_reason}</p>}
        </div>
        <div className="grid gap-2 sm:grid-cols-3 xl:w-[27rem]">
          <MiniStat label="Penawaran aktif" value={item.active_offer_count ? `${item.active_offer_count} tutor dihubungi` : "Belum ada"} />
          <MiniStat label="Batas jawaban" value={formatDateTime(item.teacher_response_deadline)} />
          <MiniStat label="Lama mencari" value={formatBoundedSearchAge(item.search_age_seconds, item.search_age_minutes, item.search_window_minutes ?? 60)} />
        </div>
        <button
          type="button"
          onClick={onOpen}
          className="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-slate-950 px-4 text-sm font-black text-white hover:bg-slate-800"
        >
          Detail <ArrowRight size={17} />
        </button>
      </div>
    </article>
  );
}

function SummaryBox({ label, value, alert = false, onClick }: { label: string; value: number; alert?: boolean; onClick?: () => void }) {
  const className = `rounded-2xl border p-3 ${alert && value > 0 ? "border-rose-400/30 bg-rose-500/15" : "border-white/10 bg-white/5"}`;
  const content = <><p className="text-2xl font-black">{value}</p><p className="mt-1 text-[11px] font-bold text-slate-300">{label}</p></>;
  if (onClick) return <button type="button" onClick={onClick} className={`${className} text-left transition hover:bg-white/15 focus-visible:outline focus-visible:outline-2 focus-visible:outline-orange-300`} aria-label={`Lihat ${value} pencarian perlu perhatian`}>{content}</button>;
  return (
    <div className={className}>{content}</div>
  );
}

function FilterSelect({ label, value, onChange, options }: { label: string; value: string; onChange: (value: string) => void; options: { value: string; label: string }[] }) {
  return (
    <div>
      <span className="sr-only">{label}</span>
      <ResponsiveSelect
        ariaLabel={label}
        value={value}
        options={options}
        onValueChange={onChange}
        className="h-11 rounded-xl border-slate-200 bg-slate-50 text-sm focus:border-orange-400 focus:bg-white focus:ring-orange-100"
      />
    </div>
  );
}

function StatusBadge({ status, attention }: { status: SearchItem["status"]; attention: boolean }) {
  const className = attention
    ? "bg-rose-100 text-rose-700"
    : status === "teacher_pending"
      ? "bg-amber-100 text-amber-700"
      : status === "matching"
        ? "bg-indigo-100 text-indigo-700"
        : "bg-slate-100 text-slate-700";
  const label = status === "matching"
    ? "Mencari tutor"
    : status === "teacher_pending"
      ? "Menunggu tutor"
      : status === "no_teacher"
        ? "Tutor belum ditemukan"
        : "Pencarian berakhir";
  return <span className={`rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-wide ${className}`}>{label}</span>;
}

function offerStatusText(status: string) {
  return status === "rejected"
    ? "ditolak"
    : status === "expired"
      ? "tidak dijawab"
      : status === "cancelled"
        ? "dibatalkan sistem"
        : status === "accepted"
          ? "diterima"
          : status;
}

function OfferBadge({ status, overdue, label }: { status: string; overdue: boolean; label: string }) {
  const className = overdue
    ? "bg-rose-100 text-rose-700"
    : status === "accepted"
      ? "bg-emerald-100 text-emerald-700"
      : status === "pending"
        ? "bg-amber-100 text-amber-700"
        : "bg-slate-100 text-slate-700";
  return <span className={`inline-flex rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-wide ${className}`}>{overdue ? "Terlambat" : label}</span>;
}

function MiniStat({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-xl bg-slate-50 px-3 py-2.5">
      <p className="text-[10px] font-black uppercase tracking-wide text-slate-400">{label}</p>
      <p className="mt-1 line-clamp-2 text-xs font-bold text-slate-700">{value}</p>
    </div>
  );
}

function InfoBox({ icon: Icon, label, value }: { icon: typeof CalendarClock; label: string; value: string }) {
  return (
    <div className="rounded-2xl border border-slate-200 p-4">
      <Icon size={19} className="text-orange-600" />
      <p className="mt-3 text-[10px] font-black uppercase tracking-wide text-slate-400">{label}</p>
      <p className="mt-1 text-sm font-black text-slate-900">{value}</p>
    </div>
  );
}

function DetailPanel({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="rounded-2xl border border-slate-200 p-4 sm:p-5">
      <h3 className="font-black text-slate-950">{title}</h3>
      <div className="mt-4 divide-y divide-slate-100">{children}</div>
    </section>
  );
}

function DetailRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-start justify-between gap-4 py-2.5 text-sm">
      <span className="text-slate-500">{label}</span>
      <span className="max-w-[65%] text-right font-bold text-slate-800">{value}</span>
    </div>
  );
}

function sourceLabel(detail: SearchDetail) {
  return `Paket Belajar #${detail.source.package_id || "-"}`;
}
