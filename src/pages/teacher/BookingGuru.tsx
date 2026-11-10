import { notify } from "@/lib/notify";
import { useCallback, useEffect, useMemo, useState } from "react";
import { useSearchParams } from "react-router-dom";
import {
  AlertTriangle,
  BookOpen,
  CalendarDays,
  Check,
  Clock3,
  History,
  Loader2,
  MapPin,
  Monitor,
  RefreshCw,
  TimerReset,
  UserRound,
  X,
} from "lucide-react";
import TeacherLayout from "@/components/TeacherLayout";
import { useConfirmDialog } from "@/components/ConfirmDialogProvider";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import http, { getApiError } from "@/lib/http";
import WorkspacePageIntro from "@/components/WorkspacePageIntro";

interface Offer {
  id: number;
  status: string;
  distance_km?: number | null;
  offered_at: string;
  expires_at: string;
  rejection_reason?: string | null;
  booking_request: {
    id: number;
    subject_name: string;
    education_level: string;
    grade?: string;
    chapter?: string;
    learning_goal?: string;
    learning_mode: "online" | "offline";
    class_type: "private";
    scheduled_date: string;
    start_time: string;
    end_time: string;
    duration_hours: number;
    address?: string;
    maps_link?: string;
    hourly_rate?: number;
    total_amount?: number;
    commission_percent?: number;
    estimated_net_amount?: number;
    student: { id: number; name: string };
    package_subject?: {
      id: number;
      allocated_sessions: number;
      subtotal_amount: number;
      package?: {
        package_code: string;
        plan?: { name: string };
      };
      sessions: Array<{
        id: number;
        sequence: number;
        scheduled_start_at: string;
        scheduled_end_at: string;
      }>;
    };
  };
}

const statusClasses: Record<string, string> = {
  pending: "bg-amber-50 text-amber-700 border-amber-100",
  accepted: "bg-emerald-50 text-emerald-700 border-emerald-100",
  rejected: "bg-rose-50 text-rose-700 border-rose-100",
  student_rejected: "bg-rose-50 text-rose-700 border-rose-100",
  cancelled: "bg-slate-100 text-slate-600 border-slate-200",
  expired: "bg-slate-100 text-slate-600 border-slate-200",
};

const statusLabels: Record<string, string> = {
  pending: "Menunggu jawaban",
  accepted: "Diterima",
  rejected: "Ditolak",
  student_rejected: "Ditolak murid",
  cancelled: "Dibatalkan",
  expired: "Kedaluwarsa",
};

const historyFilters = [
  { value: "all", label: "Semua" },
  { value: "accepted", label: "Diterima" },
  { value: "rejected", label: "Ditolak" },
  { value: "expired", label: "Kedaluwarsa" },
] as const;

type HistoryFilter = (typeof historyFilters)[number]["value"];

const formatCurrency = (value?: number) =>
  new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(value || 0);

export default function BookingGuru() {
  const [searchParams, setSearchParams] = useSearchParams();
  const confirm = useConfirmDialog();
  const [offers, setOffers] = useState<Offer[]>([]);
  const [loading, setLoading] = useState(true);
  const [processingId, setProcessingId] = useState<number | null>(null);
  const [rejectingOffer, setRejectingOffer] = useState<Offer | null>(null);
  const [reason, setReason] = useState("too_far");
  const [note, setNote] = useState("");
  const [now, setNow] = useState(Date.now());
  const scope = searchParams.get("scope") === "history" ? "history" : "active";
  const requestedStatus = searchParams.get("status");
  const historyFilter: HistoryFilter =
    requestedStatus === "accepted" || requestedStatus === "rejected" || requestedStatus === "expired"
      ? requestedStatus
      : "all";
  const [counts, setCounts] = useState({ active: 0, history: 0 });

  const pendingCount = useMemo(() => offers.filter((offer) => offer.status === "pending").length, [offers]);

  const fetchOffers = useCallback(async (showLoader = true) => {
    if (showLoader) setLoading(true);
    try {
      const response = await http.get("/teacher/offers", {
        params: scope === "history" && historyFilter !== "all"
          ? { scope, status: historyFilter }
          : { scope },
      });
      setOffers(response.data.data || []);
      setCounts({
        active: Number(response.data.active_count || 0),
        history: Number(response.data.history_count || 0),
      });
    } catch (error) {
      notify.error(getApiError(error, "Permintaan bimbel gagal dimuat."));
    } finally {
      if (showLoader) setLoading(false);
    }
  }, [scope, historyFilter]);

  const selectHistoryFilter = (value: HistoryFilter) => {
    setSearchParams(
      value === "all" ? { scope: "history" } : { scope: "history", status: value },
      { replace: true }
    );
  };

  useEffect(() => {
    const refreshWhenVisible = () => {
      if (document.visibilityState === "visible") void fetchOffers(false);
    };

    void fetchOffers();
    const countdownTimer = window.setInterval(() => setNow(Date.now()), 1000);
    const refreshTimer = window.setInterval(refreshWhenVisible, 15000);
    window.addEventListener("focus", refreshWhenVisible);
    document.addEventListener("visibilitychange", refreshWhenVisible);

    return () => {
      window.clearInterval(countdownTimer);
      window.clearInterval(refreshTimer);
      window.removeEventListener("focus", refreshWhenVisible);
      document.removeEventListener("visibilitychange", refreshWhenVisible);
    };
  }, [fetchOffers]);

  const acceptOffer = async (offer: Offer) => {
    const packageSessions = offer.booking_request.package_subject?.sessions?.length || 0;
    const approved = await confirm({
      title: "Terima permintaan ini?",
      description: packageSessions
        ? `Pastikan Anda tersedia untuk seluruh ${packageSessions} jadwal paket. Paket sudah dibayar; setelah diterima, Anda langsung ditetapkan untuk sesi ini.`
        : `Pastikan Anda menguasai materi dan tersedia pada ${offer.booking_request.start_time.slice(0, 5)}–${offer.booking_request.end_time.slice(0, 5)} WIB. Slot akan ditahan untuk proses pembayaran.`,
      confirmText: "Terima permintaan",
      tone: "primary",
    });
    if (!approved) return;

    setProcessingId(offer.id);
    try {
      const response = await http.post(`/teacher/offers/${offer.id}/accept`);
      notify.success(response.data.message);
      await fetchOffers();
    } catch (error) {
      notify.error(getApiError(error));
    } finally {
      setProcessingId(null);
    }
  };

  const rejectOffer = async () => {
    if (!rejectingOffer) return;
    setProcessingId(rejectingOffer.id);
    try {
      const response = await http.post(`/teacher/offers/${rejectingOffer.id}/reject`, { reason, note });
      notify.success(response.data.message);
      setRejectingOffer(null);
      setNote("");
      await fetchOffers();
    } catch (error) {
      notify.error(getApiError(error));
    } finally {
      setProcessingId(null);
    }
  };

  return (
    <TeacherLayout title="Permintaan Bimbel">
      <div className="max-w-7xl mx-auto space-y-7 pb-12">
        <div data-tour="teacher-requests-hero"><WorkspacePageIntro eyebrow="Persetujuan wajib" title="Permintaan Bimbel" description="Periksa kebutuhan, jadwal, dan lokasi sebelum menerima. Jadwal baru dikunci setelah permintaan disetujui." icon={TimerReset} metrics={[{ label: "Menunggu jawaban", value: pendingCount }]} actions={<Button onClick={fetchOffers} variant="outline" className="h-11 w-full rounded-xl sm:w-auto"><RefreshCw size={17} className={loading ? "mr-2 animate-spin" : "mr-2"} />Muat ulang</Button>} /></div>

        <section className="grid grid-cols-2 gap-2 rounded-2xl border border-slate-100 bg-white p-2 shadow-sm">
          <button type="button" onClick={() => setSearchParams({}, { replace: true })} className={`min-h-11 rounded-xl px-4 text-sm font-black transition ${scope === "active" ? "bg-indigo-600 text-white" : "text-slate-500 hover:bg-slate-50"}`}>
            Permintaan aktif ({counts.active})
          </button>
          <button type="button" onClick={() => setSearchParams({ scope: "history" }, { replace: true })} className={`inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-4 text-sm font-black transition ${scope === "history" ? "bg-slate-900 text-white" : "text-slate-500 hover:bg-slate-50"}`}>
            <History size={16} /> Riwayat ({counts.history})
          </button>
        </section>

        {scope === "history" && (
          <section aria-label="Filter riwayat permintaan" className="grid grid-cols-2 gap-2 rounded-2xl border border-slate-100 bg-white p-2 shadow-sm sm:grid-cols-4">
            {historyFilters.map((filter) => {
              const active = historyFilter === filter.value;
              return (
                <button
                  key={filter.value}
                  type="button"
                  onClick={() => selectHistoryFilter(filter.value)}
                  aria-pressed={active}
                  className={`min-h-11 rounded-xl px-4 text-sm font-black transition ${active ? "bg-slate-900 text-white" : "text-slate-500 hover:bg-slate-50"}`}
                >
                  {filter.label}
                </button>
              );
            })}
          </section>
        )}

        {loading ? (
          <div className="min-h-[360px] flex items-center justify-center rounded-[2rem] border border-slate-100 bg-white">
            <Loader2 className="h-9 w-9 animate-spin text-indigo-600" />
          </div>
        ) : offers.length === 0 ? (
          <div className="rounded-[2rem] border-2 border-dashed border-slate-200 bg-white px-6 py-20 text-center">
            <BookOpen className="mx-auto h-11 w-11 text-slate-300" />
            <h2 className="mt-4 text-xl font-black text-slate-800">
              {scope === "active"
                ? "Belum ada permintaan aktif"
                : historyFilter === "all"
                  ? "Riwayat masih kosong"
                  : "Tidak ada riwayat pada filter ini"}
            </h2>
            <p className="mt-2 text-sm text-slate-500">
              {scope === "active"
                ? "Permintaan yang sesuai jadwal dan mata pelajaran akan muncul di sini."
                : historyFilter === "all"
                  ? "Penawaran yang selesai, ditolak, atau kedaluwarsa akan disimpan di sini."
                  : "Coba pilih status riwayat lain atau kembali ke semua riwayat."}
            </p>
          </div>
        ) : (
          <div className="grid grid-cols-1 xl:grid-cols-2 gap-6">
            {offers.map((offer) => (
              <OfferCard
                key={offer.id}
                offer={offer}
                now={now}
                processing={processingId === offer.id}
                onAccept={() => acceptOffer(offer)}
                onReject={() => {
                  setRejectingOffer(offer);
                  setReason(offer.booking_request.learning_mode === "offline" ? "too_far" : "schedule");
                }}
              />
            ))}
          </div>
        )}
      </div>

      <Dialog open={Boolean(rejectingOffer)} onOpenChange={(open) => !open && setRejectingOffer(null)}>
        <DialogContent className="rounded-[2rem] sm:max-w-lg">
          <DialogHeader>
            <DialogTitle className="text-xl font-black">Tolak permintaan</DialogTitle>
            <DialogDescription>Alasan disimpan agar pemindahan kepada tutor berikutnya dapat diproses.</DialogDescription>
          </DialogHeader>
          <div className="space-y-4 pt-2">
            <Select value={reason} onValueChange={setReason}>
              <SelectTrigger className="h-12 rounded-xl"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="too_far">Jarak terlalu berat</SelectItem>
                <SelectItem value="schedule">Jadwal tidak memungkinkan</SelectItem>
                <SelectItem value="material">Materi tidak dikuasai</SelectItem>
                <SelectItem value="personal">Kendala pribadi</SelectItem>
                <SelectItem value="other">Alasan lain</SelectItem>
              </SelectContent>
            </Select>
            <Textarea value={note} onChange={(event) => setNote(event.target.value)} className="min-h-28 rounded-xl" placeholder="Catatan tambahan, opsional" />
            {reason === "too_far" && (
              <div className="flex gap-3 rounded-xl border border-emerald-100 bg-emerald-50 p-4 text-sm leading-6 text-emerald-800">
                <AlertTriangle size={18} className="mt-0.5 shrink-0" /> Penolakan karena jarak tidak menurunkan rating tutor.
              </div>
            )}
            <div className="flex justify-end gap-3">
              <Button variant="outline" className="rounded-xl" onClick={() => setRejectingOffer(null)}>Kembali</Button>
              <Button className="rounded-xl bg-rose-600 hover:bg-rose-700" onClick={rejectOffer} disabled={processingId === rejectingOffer?.id}>
                {processingId === rejectingOffer?.id ? <Loader2 size={17} className="mr-2 animate-spin" /> : <X size={17} className="mr-2" />}
                Tolak permintaan
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </TeacherLayout>
  );
}

function OfferCard({
  offer,
  now,
  processing,
  onAccept,
  onReject,
}: {
  offer: Offer;
  now: number;
  processing: boolean;
  onAccept: () => void;
  onReject: () => void;
}) {
  const request = offer.booking_request;
  const remaining = Math.max(0, new Date(offer.expires_at).getTime() - now);
  const totalSeconds = Math.floor(remaining / 1000);
  const hours = Math.floor(totalSeconds / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;
  const countdown = hours > 0
    ? `${hours}j ${String(minutes).padStart(2, "0")}m ${String(seconds).padStart(2, "0")}d`
    : `${String(minutes).padStart(2, "0")}:${String(seconds).padStart(2, "0")}`;
  const isPending = offer.status === "pending" && remaining > 0;

  return (
    <article data-tour="teacher-offer-card" className={`relative min-w-0 max-w-full overflow-hidden rounded-[1.6rem] border bg-white p-4 shadow-sm transition duration-300 hover-rise hover-shadow-xl sm:rounded-[2rem] sm:p-6 ${isPending ? "border-indigo-100" : "border-slate-100"}`}>
      {isPending && <div className="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-indigo-500 to-violet-500" />}
      <div className="flex min-w-0 items-start justify-between gap-3 sm:gap-4">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <p className="text-xs font-bold uppercase tracking-widest text-indigo-500">Permintaan #{request.id}</p>
          </div>
          <h2 className="mt-1 min-w-0 break-words text-xl font-black leading-snug text-slate-900 sm:text-2xl">{request.subject_name}</h2>
          <p className="mt-1 text-sm text-slate-500">{request.education_level}{request.grade ? ` • ${request.grade}` : ""}</p>
        </div>
        <div className={`max-w-[45%] shrink-0 break-words rounded-full border px-2.5 py-1.5 text-center text-[10px] font-bold leading-tight sm:max-w-none sm:px-3 sm:text-[11px] ${statusClasses[offer.status] || statusClasses.expired}`}>
          {statusLabels[offer.status] || offer.status}
        </div>
      </div>

      <div className="mt-5 grid grid-cols-1 gap-3 min-[360px]:grid-cols-2">
        <Info icon={UserRound} label="Murid" value={request.student.name} />
        <Info icon={UserRound} label="Kelas" value="Privat" />
        <Info icon={CalendarDays} label="Tanggal" value={new Intl.DateTimeFormat("id-ID", { dateStyle: "medium" }).format(new Date(`${request.scheduled_date.substring(0, 10)}T00:00:00`))} />
        <Info icon={Clock3} label="Waktu" value={`${request.start_time.slice(0, 5)}–${request.end_time.slice(0, 5)}`} />
        <Info icon={request.learning_mode === "online" ? Monitor : MapPin} label="Mode" value={request.learning_mode === "online" ? "Online" : "Offline"} />
        <Info icon={Clock3} label="Durasi" value={`${request.duration_hours} jam`} />
      </div>

      {request.package_subject && (
        <div className="mt-4 rounded-2xl border border-indigo-100 bg-indigo-50/70 p-4">
          <div className="flex min-w-0 flex-col gap-3 min-[360px]:flex-row min-[360px]:items-start min-[360px]:justify-between">
            <div className="min-w-0">
              <p className="text-[10px] font-black uppercase tracking-widest text-indigo-500">Penawaran paket</p>
              <p className="mt-1 text-sm font-black text-indigo-950">
                {request.package_subject.package?.plan?.name || "Paket Belajar"} · {request.package_subject.allocated_sessions} sesi
              </p>
            </div>
            <span className="max-w-full self-start break-all rounded-full bg-white px-2.5 py-1 text-[10px] font-bold text-indigo-700">
              {request.package_subject.package?.package_code}
            </span>
          </div>
          <div className="mt-3 space-y-2">
            {request.package_subject.sessions.map((session) => (
              <div key={session.id} className="flex items-center justify-between gap-3 rounded-xl bg-white px-3 py-2 text-xs">
                <span className="font-bold text-slate-500">Sesi {session.sequence}</span>
                <span className="text-right font-black text-slate-800">
                  {new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short" }).format(new Date(session.scheduled_start_at))}
                </span>
              </div>
            ))}
          </div>
          <p className="mt-3 text-xs leading-5 text-indigo-700">Menerima penawaran berarti menyetujui seluruh jadwal di atas.</p>
        </div>
      )}

      {(request.chapter || request.learning_goal) && (
        <div className="mt-4 rounded-2xl bg-slate-50 p-4">
          <p className="text-[10px] font-bold uppercase tracking-widest text-slate-400">Kebutuhan belajar</p>
          {request.chapter && <p className="mt-2 text-sm font-black text-slate-800">{request.chapter}</p>}
          {request.learning_goal && <p className="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-slate-700"><span className="font-bold">Target belajar:</span> {request.learning_goal}</p>}
        </div>
      )}

      {request.learning_mode === "offline" && (
        <div className="mt-4 rounded-2xl border border-emerald-100 bg-emerald-50 p-4">
          <div className="flex items-start gap-3">
            <MapPin size={18} className="mt-0.5 shrink-0 text-emerald-600" />
            <div>
              <p className="text-xs font-bold text-emerald-900">Lokasi murid</p>
              <p className="mt-1 text-sm leading-5 text-emerald-800">{request.address || "Alamat lengkap ditampilkan setelah pembayaran dikonfirmasi."}</p>
              {offer.distance_km !== null && offer.distance_km !== undefined && <p className="mt-2 text-xs font-bold text-emerald-700">Perkiraan jarak {offer.distance_km} km</p>}
              {offer.status === "accepted" && request.maps_link && <a href={request.maps_link} target="_blank" rel="noreferrer" className="mt-2 inline-block text-xs font-bold text-emerald-700 underline">Buka peta</a>}
            </div>
          </div>
        </div>
      )}

      <div className="mt-5 flex min-w-0 flex-col gap-3 rounded-2xl border border-slate-100 bg-white px-4 py-3 min-[360px]:flex-row min-[360px]:items-center min-[360px]:justify-between">
        <div className="min-w-0">
          <p className="text-[10px] font-bold uppercase tracking-widest text-slate-400">Estimasi pendapatan tutor</p>
          <p className="mt-1 text-lg font-black text-slate-900">{formatCurrency(request.estimated_net_amount)}</p>
          <p className="mt-1 text-[10px] font-medium text-slate-400">
            {request.package_subject ? "Alokasi seluruh sesi mapel ini (bukan total program)" : "Nilai sesi"} {formatCurrency(request.total_amount)} · komisi platform {request.commission_percent ?? 20}%
          </p>
        </div>
        {isPending && (
          <div className="self-start text-left min-[360px]:self-auto min-[360px]:text-right">
            <p className="text-[10px] font-bold uppercase tracking-widest text-rose-500">Sisa waktu</p>
            <p className="mt-1 font-mono text-lg font-black text-rose-600">{countdown}</p>
          </div>
        )}
      </div>

      {isPending && (
        <>
          <div className="mt-4 flex items-start gap-2 rounded-xl bg-amber-50 p-3 text-xs font-semibold leading-5 text-amber-900">
            <AlertTriangle size={15} className="mt-0.5 shrink-0" />
            <span>Menolak secara langsung tidak menurunkan rating. Satu kali terlambat merespons belum menangguhkan akun, tetapi pola tidak merespons pada beberapa permintaan berbeda dapat memberi jeda sementara.</span>
          </div>
          <div className="mt-3 grid grid-cols-2 gap-3">
            <Button variant="outline" className="h-12 rounded-xl border-rose-200 text-rose-600 hover:bg-rose-50 hover:text-rose-700" onClick={onReject} disabled={processing}>
              <X size={17} className="mr-2" /> Tolak
            </Button>
            <Button className="h-12 rounded-xl bg-indigo-600 hover:bg-indigo-700 font-bold shadow-lg shadow-indigo-200" onClick={onAccept} disabled={processing}>
              {processing ? <Loader2 size={17} className="mr-2 animate-spin" /> : <Check size={17} className="mr-2" />}
              Terima
            </Button>
          </div>
        </>
      )}
    </article>
  );
}

function Info({ icon: Icon, label, value }: { icon: typeof Clock3; label: string; value: string }) {
  return (
    <div className="min-w-0 rounded-xl border border-slate-100 bg-slate-50/70 p-3">
      <div className="flex items-center gap-2 text-slate-400"><Icon size={14} /><span className="text-[10px] font-bold uppercase tracking-wider">{label}</span></div>
      <p className="mt-1.5 break-words text-sm font-bold leading-5 text-slate-800">{value}</p>
    </div>
  );
}
