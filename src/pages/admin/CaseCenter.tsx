import { notify } from "@/lib/notify";
import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import {
  AlertTriangle,
  ArrowLeft,
  CheckCircle2,
  Clock3,
  ExternalLink,
  FileSearch,
  Gavel,
  ListChecks,
  Loader2,
  PackageX,
  RefreshCw,
  RotateCcw,
  Scissors,
  ScrollText,
  ShieldAlert,
  UserRoundX,
  X,
} from "lucide-react";
import AdminLayout from "@/components/AdminLayout";
import { openProtectedFile } from "@/components/ProtectedImage";
import { useConfirmDialog } from "@/components/ConfirmDialogProvider";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { Input } from "@/components/ui/input";
import http, { getApiError } from "@/lib/http";
import {
  TUTOR_EXIT_CONFIRM_TOKEN,
  fetchTutorExitPreview,
  hasRemainingRefundableSessions,
  submitTutorExit,
  type TutorExitPreview,
} from "@/lib/tutorExit";

interface CaseData {
  session_reports: any[];
  disputes: any[];
  completion_reviews: any[];
  teacher_replacements: any[];
  features?: { teacher_replacement: boolean };
}

type CaseType = "report" | "dispute" | "completion" | "replacement";

type RefundScope = "none" | "session" | "remaining_package";

const emptyCases: CaseData = { session_reports: [], disputes: [], completion_reviews: [], teacher_replacements: [], features: { teacher_replacement: false } };

export default function CaseCenter() {
  const confirm = useConfirmDialog();
  const [data, setData] = useState<CaseData>(emptyCases);
  const [scope, setScope] = useState<"active" | "history">("active");
  const [tab, setTab] = useState<CaseType>("report");
  const [selected, setSelected] = useState<{ type: CaseType; item: any } | null>(null);
  const [decision, setDecision] = useState("");
  const [notes, setNotes] = useState("");
  const [refundAmount, setRefundAmount] = useState("");
  const [costBearer, setCostBearer] = useState("tutor_share");
  const [refundScope, setRefundScope] = useState<RefundScope>("session");
  const [loading, setLoading] = useState(true);
  const [processing, setProcessing] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const response = await http.get<CaseData>("/admin/cases", { params: { scope } });
      setData(response.data);
    } catch (error) {
      notify.error(getApiError(error, "Pusat kasus gagal dimuat."));
    } finally {
      setLoading(false);
    }
  }, [scope]);

  useEffect(() => { void load(); }, [load]);

  const counts = {
    report: data.session_reports.length,
    dispute: data.disputes.length,
    completion: data.completion_reviews.length,
    replacement: data.teacher_replacements.length,
  };
  const items = useMemo(() => {
    if (tab === "report") return data.session_reports;
    if (tab === "dispute") return data.disputes;
    if (tab === "completion") return data.completion_reviews;
    return data.teacher_replacements;
  }, [data, tab]);

  const openCase = (type: CaseType, item: any) => {
    const isLapsedAppeal = type === "dispute" && item.kind === "lapsed_session";
    const isEmergency = type === "report" && item.type === "teacher_emergency";
    setSelected({ type, item });
    const defaultRefund = itemRefundCap(item);
    setRefundAmount(defaultRefund > 0 ? String(Math.floor(defaultRefund)) : "");
    setDecision(
      type === "report"
        ? (isEmergency ? "accepted" : "accepted")
        : type === "dispute"
          ? (isLapsedAppeal ? "full_refund" : "teacher_paid")
          : type === "completion" ? "approve" : "approve",
    );
    setCostBearer("tutor_share");
    setRefundScope("session");
    setNotes("");
  };

  const isLapsedAppeal = selected?.type === "dispute" && selected.item?.kind === "lapsed_session";
  const isEmergencyReport = selected?.type === "report" && selected.item?.type === "teacher_emergency";
  const selectedPackageId = itemPackageId(selected?.item);
  const needsRefundAmount = isLapsedAppeal && decision === "partial_refund";
  const needsCostBearer = (isLapsedAppeal && (decision === "full_refund" || decision === "partial_refund"))
    || (isEmergencyReport && decision === "accepted" && refundScope !== "none");

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (scope === "history") return;
    if (!selected) return;
    const { type, item } = selected;
    const approved = await confirm({
      title: "Simpan keputusan kasus?",
      description: "Keputusan ini dapat mengubah status sesi, hak pembayaran tutor, atau membuat antrean refund.",
      confirmText: "Simpan keputusan",
      tone: "warning",
    });
    if (!approved) return;

    setProcessing(true);
    try {
      let response;
      if (type === "report") {
        const emergencyPayload = item.type === "teacher_emergency"
          ? {
              refund_scope: decision === "rejected" ? "none" : refundScope,
              cost_bearer: costBearer,
            }
          : {};
        response = await http.post(`/admin/session-reports/${item.id}/resolve`, { decision, notes, ...emergencyPayload });
      } else if (type === "dispute") {
        response = await http.post(`/admin/disputes/${item.id}/resolve`, {
          resolution: decision,
          notes,
          refund_amount: needsRefundAmount ? Number(refundAmount.replace(/[^0-9]/g, "")) || 0 : undefined,
          cost_bearer: needsCostBearer ? costBearer : undefined,
        });
      } else if (type === "completion") {
        response = await http.post(`/admin/bookings/${item.id}/completion-review`, { action: decision, notes });
      } else {
        response = await http.post("/admin/teacher-replacements/" + item.id + "/" + decision, {
          notes,
        });
      }
      notify.success(response.data.message);
      setSelected(null);
      await load();
    } catch (error) {
      notify.error(getApiError(error));
    } finally {
      setProcessing(false);
    }
  };

  return (
    <AdminLayout title="Pusat Kasus">
      <div className="space-y-6 pb-12">
        <section className="flex flex-col justify-between gap-5 rounded-[2rem] bg-gradient-to-br from-slate-950 via-rose-950 to-indigo-950 p-7 text-white md:flex-row md:items-end">
          <div><p className="text-xs font-black uppercase tracking-[.2em] text-rose-200">Kontrol penyelesaian</p><h1 className="mt-3 text-3xl font-black">Sengketa dan laporan operasional</h1><p className="mt-2 max-w-2xl text-sm leading-6 text-rose-100/70">Pusat kasus menetapkan keputusan. Transfer refund berikutnya diproses terpisah pada menu Refund & Saldo BimbelKu.</p></div>
          <div className="flex flex-col gap-2 sm:flex-row"><Button asChild variant="outline" className="rounded-xl border-white/20 bg-white/10 text-white hover:bg-white/20 hover:text-white"><Link to="/admin/refunds"><RotateCcw size={16} className="mr-2" />Buka refund & saldo</Link></Button><Button onClick={load} variant="outline" className="rounded-xl border-white/20 bg-white/10 text-white hover:bg-white/20 hover:text-white"><RefreshCw size={16} className="mr-2" />Muat ulang</Button></div>
        </section>

        <section aria-label="Scope pusat kasus" className="grid grid-cols-2 rounded-2xl border border-slate-200 bg-slate-100 p-1">
          <button type="button" onClick={() => { setScope("active"); setSelected(null); }} className={`min-h-11 rounded-xl px-4 text-sm font-black transition ${scope === "active" ? "bg-white text-rose-700 shadow-sm" : "text-slate-500"}`}>Aktif · perlu tindakan</button>
          <button type="button" onClick={() => { setScope("history"); setSelected(null); }} className={`min-h-11 rounded-xl px-4 text-sm font-black transition ${scope === "history" ? "bg-white text-slate-900 shadow-sm" : "text-slate-500"}`}>Riwayat · selesai</button>
        </section>

        <div className={`grid grid-cols-2 gap-3 ${data.features?.teacher_replacement ? "lg:grid-cols-5" : "lg:grid-cols-4"}`}>
          <TabCard active={tab === "report"} attention={scope === "active"} tone="urgent" onClick={() => setTab("report")} icon={AlertTriangle} label="Laporan sesi" count={counts.report} />
          <TabCard active={tab === "dispute"} attention={scope === "active"} tone="urgent" onClick={() => setTab("dispute")} icon={Gavel} label="Keberatan murid" count={counts.dispute} />
          <TabCard active={tab === "completion"} attention={scope === "active"} tone="warning" onClick={() => setTab("completion")} icon={FileSearch} label="Menunggu keputusan" count={counts.completion} />
          {data.features?.teacher_replacement && <TabCard active={tab === "replacement"} attention={scope === "active"} tone="warning" onClick={() => setTab("replacement")} icon={RefreshCw} label="Penggantian Guru" count={counts.replacement} />}
        </div>

        <div className="overflow-hidden rounded-[2rem] border border-slate-100 bg-white">
          {loading ? <div className="grid min-h-64 place-items-center"><Loader2 className="animate-spin text-indigo-600" /></div> : items.length === 0 ? <div className="py-20 text-center"><CheckCircle2 className="mx-auto h-10 w-10 text-emerald-300" /><p className="mt-3 font-black text-slate-700">{scope === "history" ? "Belum ada riwayat pada kategori ini" : "Tidak ada kasus menunggu"}</p></div> : <div className="divide-y divide-slate-100">
            {items.map((item: any) => <CaseRow key={item.id} type={tab} item={item} history={scope === "history"} onOpen={() => openCase(tab, item)} />)}
          </div>}
        </div>
      </div>

      <Dialog open={Boolean(selected)} onOpenChange={(open) => !open && setSelected(null)}>
        <DialogContent layer="detail" hideCloseButton className="max-h-[92dvh] overflow-y-auto rounded-[2rem] sm:max-w-2xl">
          {selected && <form onSubmit={submit} className="space-y-5">
            <DialogHeader>
              <div className="grid grid-cols-[2.5rem_minmax(0,1fr)_2.5rem] items-start gap-2">
                <Button type="button" variant="ghost" size="icon" className="h-10 w-10 rounded-xl" onClick={() => setSelected(null)} aria-label="Kembali ke daftar kasus"><ArrowLeft size={18} /></Button>
                <div className="min-w-0 pt-1"><DialogTitle className="px-0 text-2xl">{caseTitle(selected.type, selected.item)}</DialogTitle><DialogDescription className="mt-1 text-center">{caseSubtitle(selected.item)}</DialogDescription></div>
                <Button type="button" variant="ghost" size="icon" className="h-10 w-10 rounded-xl" onClick={() => setSelected(null)} aria-label="Tutup detail kasus"><X size={18} /></Button>
              </div>
            </DialogHeader>
            <CaseDetail type={selected.type} item={selected.item} />
            {scope === "active" && selectedPackageId && (
              <TutorExitPanel packageId={selectedPackageId} onQueued={load} />
            )}
            {scope === "history" && <HistoryDecision item={selected.item} />}
            <fieldset disabled={scope === "history"} className={scope === "history" ? "hidden" : "space-y-5"}>
            <div><Label>Keputusan</Label><Select value={decision} onValueChange={setDecision}><SelectTrigger className="mt-2 h-12 rounded-xl"><SelectValue /></SelectTrigger><SelectContent>{selected.type === "report" ? (isEmergencyReport ? <><SelectItem value="accepted">Laporan valid · refund sesi</SelectItem><SelectItem value="rejected">Laporan ditolak · sesi tetap berjalan</SelectItem></> : <><SelectItem value="accepted">Laporan valid</SelectItem><SelectItem value="rejected">Laporan ditolak</SelectItem></>) : selected.type === "dispute" ? (isLapsedAppeal ? <><SelectItem value="full_refund">Refund penuh nilai sesi</SelectItem><SelectItem value="partial_refund">Refund sebagian</SelectItem><SelectItem value="teacher_paid">Sesi valid · hak tutor diproses</SelectItem></> : <><SelectItem value="teacher_paid">Sesi valid · hak tutor diproses</SelectItem><SelectItem value="student_refund">Refund penuh murid</SelectItem></>) : selected.type === "replacement" ? <><SelectItem value="approve">Setujui dan mulai pencarian</SelectItem><SelectItem value="reject">Tolak pengajuan</SelectItem></> : <><SelectItem value="approve">Sahkan sesi</SelectItem><SelectItem value="refund">Refund penuh</SelectItem></>}</SelectContent></Select></div>
            {needsRefundAmount && <div><Label>Nominal Refund (Rp)</Label><Input inputMode="numeric" type="number" min={1} max={itemRefundCap(selected?.item)} value={refundAmount} onChange={(event) => setRefundAmount(event.target.value.replace(/[^0-9]/g, ""))} className="mt-2 h-11 rounded-xl" placeholder="1" required /><p className="mt-1 text-xs text-slate-500">Maksimal {new Intl.NumberFormat("id-ID").format(itemRefundCap(selected?.item))} sesuai nilai sesi. Nominal harus lebih dari Rp0.</p></div>}
            {isEmergencyReport && decision === "accepted" && selected && <SessionRefundSummary item={selected.item} />}
            {isEmergencyReport && decision === "accepted" && selectedPackageId && <div><Label>Cakupan refund</Label><Select value={refundScope} onValueChange={(value) => setRefundScope(value as RefundScope)}><SelectTrigger className="mt-2 h-12 rounded-xl"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="session">Refund sesi yang terdampak saja</SelectItem><SelectItem value="remaining_package">Refund seluruh sisa paket</SelectItem><SelectItem value="none">Tanpa refund</SelectItem></SelectContent></Select><p className="mt-1 text-xs text-slate-500">Rincian sesi dan nominal sisa paket ada pada kartu &quot;Hentikan tutor &amp; refund sisa paket&quot; di atas.</p></div>}
            {needsCostBearer && <div><Label>Penanggung biaya</Label><Select value={costBearer} onValueChange={setCostBearer}><SelectTrigger className="mt-2 h-12 rounded-xl"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="tutor_share">Potong hak tutor</SelectItem><SelectItem value="platform">Ditanggung platform</SelectItem></SelectContent></Select></div>}
            {(isLapsedAppeal || isEmergencyReport) && selected && <AttendanceEvidence bookingId={isLapsedAppeal ? selected.item.booking_id ?? selected.item.booking?.id : selected.item.booking_id ?? selected.item.booking?.id} />}
            <div><Label>Catatan keputusan</Label><Textarea required minLength={20} className="mt-2 min-h-32 rounded-xl" value={notes} onChange={(event) => setNotes(event.target.value)} placeholder="Dasar pemeriksaan dan keputusan admin" /></div>
            <Button disabled={processing} className="h-12 w-full rounded-xl bg-indigo-600 font-black hover:bg-indigo-700">{processing && <Loader2 size={17} className="mr-2 animate-spin" />}Simpan keputusan</Button>
            </fieldset>
          </form>}
        </DialogContent>
      </Dialog>
    </AdminLayout>
  );
}

function itemPackageId(item: any): number | null {
  const value = Number(item?.package_id);
  return Number.isFinite(value) && value > 0 ? value : null;
}

/**
 * Satu-satunya pemanggil `tutor-exit-preview` dan `tutor-exit`. Muncul hanya
 * ketika sesi kasus berasal dari paket dan masih ada sesi tersisa yang
 * memenuhi syarat refund; pratinjau dimuat lebih dulu agar admin melihat
 * sesi dan nominal persis sebelum menghentikan tutor.
 */
function TutorExitPanel({ packageId, onQueued }: { packageId: number; onQueued: () => Promise<void> | void }) {
  const [preview, setPreview] = useState<TutorExitPreview | null>(null);
  const [loading, setLoading] = useState(true);
  const [hidden, setHidden] = useState(false);
  const [open, setOpen] = useState(false);

  const loadPreview = useCallback(async () => {
    setLoading(true);
    try {
      const data = await fetchTutorExitPreview(packageId);
      setPreview(data);
      setHidden(false);
    } catch {
      // Admin tanpa izin `finance.refunds`: kartu tidak ditampilkan, kasus
      // tetap bisa diputuskan seperti biasa.
      setHidden(true);
    } finally {
      setLoading(false);
    }
  }, [packageId]);

  useEffect(() => { void loadPreview(); }, [loadPreview]);

  if (hidden) return null;
  if (loading) return <div className="flex items-center gap-2 rounded-2xl border border-slate-100 bg-slate-50 p-4 text-xs font-bold text-slate-500"><Loader2 className="animate-spin" size={15} /> Memeriksa sesi sisa paket...</div>;
  if (!preview) return null;

  if (preview.already_queued) {
    return <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><p className="flex items-center gap-2 font-black"><PackageX size={16} /> Refund sisa paket sudah dibuat</p><p className="mt-1 text-xs font-semibold leading-5 text-amber-800">Paket ini sudah punya antrean refund sisa paket. Selesaikan lewat menu Refund &amp; Saldo BimbelKu, bukan dengan menghentikan tutor lagi.</p><Button asChild type="button" variant="outline" size="sm" className="mt-2 rounded-xl bg-white"><Link to="/admin/refunds">Buka refund &amp; saldo</Link></Button></div>;
  }

  if (!hasRemainingRefundableSessions(preview)) return null;

  return <div className="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-950">
    <p className="flex items-center gap-2 text-[10px] font-black uppercase tracking-[.14em] text-rose-600"><PackageX size={14} /> Sisa paket</p>
    <p className="mt-2 font-black">Hentikan tutor &amp; refund sisa paket</p>
    <p className="mt-1 text-xs font-semibold leading-5 text-rose-900">Pakai tindakan ini bila tutor berhenti mengajar. Refund mencakup seluruh sesi paket yang belum terlaksana, bukan hanya sesi pada kasus ini.</p>
    <TutorExitBreakdown preview={preview} />
    {open
      ? <TutorExitForm packageId={packageId} preview={preview} onCancel={() => setOpen(false)} onQueued={async () => { setOpen(false); await loadPreview(); await onQueued(); }} />
      : <Button type="button" variant="outline" className="mt-3 rounded-xl border-rose-300 bg-white text-rose-700 hover:bg-rose-100" onClick={() => setOpen(true)}><Scissors size={15} className="mr-2" />Hentikan tutor &amp; refund sisa paket</Button>}
  </div>;
}

function TutorExitBreakdown({ preview }: { preview: TutorExitPreview }) {
  return <div className="mt-3 rounded-xl border border-rose-200 bg-white p-3">
    <p className="flex items-center gap-2 text-[10px] font-black uppercase tracking-[.14em] text-rose-500"><ListChecks size={13} /> {preview.sessions.length} sesi tersisa</p>
    <div className="mt-2 space-y-1 text-xs">
      {preview.sessions.map((session) => <div key={session.booking_id} className="flex flex-wrap items-baseline justify-between gap-2 border-b border-dashed border-slate-100 pb-1 last:border-0">
        <span className="min-w-0 font-semibold text-slate-700">{session.subject || "Sesi"} · {session.start_at ? new Date(session.start_at).toLocaleString("id-ID") : "jadwal menyusul"}</span>
        <span className="font-black text-slate-900">{rupiah(session.amount)}</span>
      </div>)}
    </div>
    <div className="mt-2 grid gap-1 rounded-lg bg-slate-50 p-2 text-xs">
      <p className="flex justify-between"><span>Total refund</span><strong>{rupiah(preview.total_amount)}</strong></p>
      <p className="flex justify-between text-slate-500"><span>Dari Saldo BimbelKu</span><span>{rupiah(preview.wallet_amount)}</span></p>
      <p className="flex justify-between text-slate-500"><span>Dari pembayaran Xendit/transfer</span><span>{rupiah(preview.external_amount)}</span></p>
    </div>
    <p className="mt-2 text-[11px] font-semibold leading-5 text-slate-500">Tujuan refund dipilih murid setelah antrean dibuat.</p>
  </div>;
}

function TutorExitForm({ packageId, preview, onCancel, onQueued }: { packageId: number; preview: TutorExitPreview; onCancel: () => void; onQueued: () => Promise<void> | void }) {
  const [reason, setReason] = useState("");
  const [evidence, setEvidence] = useState("");
  const [token, setToken] = useState("");
  const [processing, setProcessing] = useState(false);

  const submit = async () => {
    setProcessing(true);
    try {
      const result = await submitTutorExit(packageId, {
        reason,
        evidenceNote: evidence,
        bookingIds: preview.sessions.map((session) => session.booking_id),
      });
      notify.success(result.message);
      await onQueued();
    } catch (error) {
      notify.error(getApiError(error, "Refund sisa paket gagal dibuat."));
    } finally {
      setProcessing(false);
    }
  };

  return <div className="mt-3 space-y-3 rounded-xl border border-rose-300 bg-white p-3">
    <p className="text-xs font-semibold leading-5 text-rose-900">Tindakan ini menghentikan tutor untuk {preview.sessions.length} sesi tersisa sekaligus dan mengantrekan refund sebesar {rupiah(preview.total_amount)}.</p>
    <div><Label>Alasan penghentian</Label><Textarea minLength={20} maxLength={500} className="mt-2 min-h-20 rounded-xl" value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Contoh: tutor mengundurkan diri dan tidak dapat melanjutkan paket" /></div>
    <div><Label>Catatan bukti</Label><Textarea minLength={50} maxLength={3000} className="mt-2 min-h-28 rounded-xl" value={evidence} onChange={(event) => setEvidence(event.target.value)} placeholder="Uraikan bukti penghentian: pesan tutor, tanggal, dan sesi yang belum terlaksana" /><p className="mt-1 text-xs text-slate-500">Minimal 50 karakter. Tersimpan pada jejak audit dan keputusan refund.</p></div>
    <div><Label>Konfirmasi</Label><Input className="mt-2 h-11 rounded-xl" value={token} onChange={(event) => setToken(event.target.value)} placeholder={TUTOR_EXIT_CONFIRM_TOKEN} /><p className="mt-1 text-xs text-slate-500">Ketik <strong>{TUTOR_EXIT_CONFIRM_TOKEN}</strong> untuk mengonfirmasi refund seluruh sisa paket.</p></div>
    <div className="flex flex-col gap-2 sm:flex-row sm:justify-end"><Button type="button" variant="outline" className="h-11 rounded-xl" onClick={onCancel}>Batal</Button><Button type="button" disabled={processing || token.trim() !== TUTOR_EXIT_CONFIRM_TOKEN || reason.trim().length < 20 || evidence.trim().length < 50} onClick={() => void submit()} className="h-11 rounded-xl bg-rose-600 font-black hover:bg-rose-700">{processing && <Loader2 size={16} className="mr-2 animate-spin" />}Hentikan tutor &amp; refund</Button></div>
  </div>;
}

function paidParticipantAmounts(item: any): number[] {
  const booking = item?.booking;
  return (booking?.participants || [])
    .filter((participant: any) => participant.order?.status === "paid")
    .map((participant: any) => Number(participant.amount || 0))
    .filter((amount: number) => Number.isFinite(amount) && amount > 0);
}

function rupiah(amount: number): string {
  return `Rp${new Intl.NumberFormat("id-ID").format(amount)}`;
}

function itemRefundCap(item: any): number {
  const paidParticipants = paidParticipantAmounts(item);
  if (paidParticipants.length) return Math.floor(Math.min(...paidParticipants));
  const bookingCap = Number(item?.booking?.gross_amount || item?.booking?.total_amount || 0);
  return Number.isFinite(bookingCap) && bookingCap > 0 ? Math.floor(bookingCap) : 0;
}

/**
 * Laporan keadaan darurat selalu merefund nilai sesi penuh. Backend memakai
 * nominal yang benar-benar dibayar tiap murid, jadi admin hanya melihat
 * ringkasannya dan tidak lagi mengetik angka.
 */
function SessionRefundSummary({ item }: { item: any }) {
  const amounts = paidParticipantAmounts(item);
  const total = amounts.reduce((sum, amount) => sum + amount, 0);
  return <div className="rounded-2xl border border-emerald-100 bg-emerald-50 p-4 text-sm text-emerald-900">
    <p className="font-black">Refund otomatis sesuai nilai sesi</p>
    <p className="mt-1 text-xs font-semibold leading-5 text-emerald-800">Nominal tidak dapat diubah. Setiap murid yang sudah membayar menerima refund penuh sebesar nominal yang dibayarkannya.</p>
    {amounts.length === 1 && <p className="mt-2 text-xs font-black">Refund penuh: {rupiah(amounts[0])}</p>}
    {amounts.length > 1 && <><p className="mt-2 text-xs font-black">Refund per murid: {amounts.map(rupiah).join(" · ")}</p><p className="mt-1 text-xs font-bold text-emerald-700">Total {rupiah(total)} untuk {amounts.length} murid</p></>}
    {amounts.length === 0 && <p className="mt-2 text-xs font-bold text-amber-700">Belum ada peserta berbayar. Keputusan refund akan ditolak sampai ada pembayaran.</p>}
  </div>;
}

function TabCard({ active, attention, tone, onClick, icon: Icon, label, count }: { active: boolean; attention: boolean; tone: "urgent" | "warning"; onClick: () => void; icon: typeof Gavel; label: string; count: number }) {
  const activeClass = attention
    ? tone === "urgent" ? "border-rose-600 bg-rose-600 text-white shadow-lg shadow-rose-200" : "border-amber-500 bg-amber-500 text-white shadow-lg shadow-amber-200"
    : "border-slate-800 bg-slate-800 text-white shadow-lg shadow-slate-200";
  const badgeClass = attention && count > 0
    ? tone === "urgent" ? "bg-rose-100 text-rose-700" : "bg-amber-100 text-amber-800"
    : "bg-slate-100 text-slate-600";
  return <button type="button" onClick={onClick} className={`min-w-0 rounded-2xl border p-4 text-left transition ${active ? activeClass : "border-slate-100 bg-white text-slate-700 hover:border-slate-300"}`}><div className="flex items-center justify-between gap-2"><Icon size={20} className="shrink-0" /><span className={`rounded-full px-2 py-0.5 text-xs font-black ${active ? "bg-white/20 text-white" : badgeClass}`}>{count}</span></div><p className="mt-3 break-words text-sm font-black">{label}</p></button>;
}

function CaseRow({ type, item, history, onOpen }: { type: CaseType; item: any; history: boolean; onOpen: () => void }) {
  const icon = type === "report" ? (item.type === "student_absence" ? UserRoundX : AlertTriangle) : type === "dispute" ? ShieldAlert : type === "completion" ? Clock3 : type === "replacement" ? RefreshCw : Gavel;
  const Icon = icon;
  const name = type === "report" ? (item.reporter?.name || item.teacher?.name) : type === "dispute" || type === "replacement" ? item.student?.name : item.teacher?.name;
  if (type === "replacement") {
    const detail = `${item.subject?.subject_name || "Mapel"} · ${item.sessions?.length || 0} sesi tersisa · ${String(item.reason_code || "lainnya").replace(/_/g, " ")}`;
    return <div className="flex flex-col justify-between gap-4 p-5 hover:bg-slate-50 sm:flex-row sm:items-center"><div className="flex min-w-0 gap-4"><div className={`grid h-12 w-12 shrink-0 place-items-center rounded-2xl ${history ? "bg-slate-100 text-slate-500" : "bg-rose-50 text-rose-600"}`}><Icon /></div><div className="min-w-0"><p className="break-words font-black text-slate-900">{name || "Pengguna"} · Kasus #{item.id}</p><p className="mt-1 line-clamp-2 break-words text-sm leading-6 text-slate-500">{detail}</p></div></div><Button variant="outline" className="rounded-xl" onClick={onOpen}>{history ? "Lihat riwayat" : "Periksa"}</Button></div>;
  }
  const detail = type === "report" ? (item.incident_type || (item.type === "student_absence" ? "Murid tidak hadir" : item.type === "teacher_absence" ? "Tutor tidak hadir" : "Keadaan darurat")) : type === "dispute" ? item.reason : "Masa keputusan murid berakhir";
  return <div className="flex flex-col justify-between gap-4 p-5 hover:bg-slate-50 sm:flex-row sm:items-center"><div className="flex min-w-0 gap-4"><div className={`grid h-12 w-12 shrink-0 place-items-center rounded-2xl ${history ? "bg-slate-100 text-slate-500" : "bg-rose-50 text-rose-600"}`}><Icon /></div><div className="min-w-0"><p className="break-words font-black text-slate-900">{name || "Pengguna"} · Kasus #{item.id}</p><p className="mt-1 line-clamp-2 break-words text-sm leading-6 text-slate-500">{detail}</p></div></div><Button variant="outline" className="rounded-xl" onClick={onOpen}>{history ? "Lihat riwayat" : "Periksa"}</Button></div>;
}

function HistoryDecision({ item }: { item: any }) {
  const notes = item.review_notes || item.resolution_notes || item.completion_notes;
  const decidedAt = item.reviewed_at || item.resolved_at || item.updated_at;
  return <div className="rounded-2xl border border-emerald-100 bg-emerald-50 p-4 text-sm text-emerald-900"><p className="font-black">Kasus sudah selesai · {String(item.status || "selesai").replace(/_/g, " ")}</p>{notes && <p className="mt-2 break-words leading-6"><strong>Catatan keputusan:</strong> {notes}</p>}{decidedAt && <p className="mt-2 text-xs font-bold text-emerald-700">Diproses {new Date(decidedAt).toLocaleString("id-ID")}</p>}</div>;
}

function CaseDetail({ type, item }: { type: CaseType; item: any }) {
  const evidence = type === "report"
    ? item.evidence_url
    : type === "dispute"
      ? item.evidence_url
      : type === "completion"
        ? null
        : item.evidence_url;
  const booking = type === "dispute" ? item.booking : type === "completion" ? item : item.booking;
  const startedAt = booking?.session_started_at || booking?.student_confirmed_at;
  const endedAt = booking?.session_ended_at;
  const durationMinutes = startedAt && endedAt
    ? Math.max(0, Math.round((new Date(endedAt).getTime() - new Date(startedAt).getTime()) / 60000))
    : booking?.latest_learning_progress_report?.actual_duration_minutes;
  const progressReport = booking?.latest_learning_progress_report || item.latest_learning_progress_report;

  return <div className="space-y-3 rounded-2xl border border-slate-100 bg-slate-50 p-4 text-sm text-slate-700">
    {type === "report" && <><p><strong>Kronologi:</strong> {item.chronology}</p>{item.incident_at && <p><strong>Waktu:</strong> {new Date(item.incident_at).toLocaleString("id-ID")}</p>}{item.incident_location && <p><strong>Lokasi:</strong> {item.incident_location}</p>}{item.impact && <p><strong>Dampak:</strong> {item.impact}</p>}</>}
    {type === "dispute" && <p><strong>Masalah yang dilaporkan murid:</strong> {item.reason}</p>}
    {type === "completion" && <p><strong>Catatan tutor:</strong> {item.completion_notes || "-"}</p>}
    {type === "replacement" && <>
      <div className="grid gap-2 sm:grid-cols-2">
        <p><strong>Paket:</strong> {item.package?.package_code || "-"}</p>
        <p><strong>Mapel:</strong> {item.subject?.subject_name || "-"}</p>
        <p><strong>Guru lama:</strong> {item.old_teacher?.name || "-"}</p>
        <p><strong>Jumlah sesi:</strong> {item.sessions?.length || 0}</p>
      </div>
      <p><strong>Alasan:</strong> {String(item.reason_code || "lainnya").replace(/_/g, " ")}</p>
      <p><strong>Penjelasan murid:</strong> {item.reason_detail || "-"}</p>
      {item.sessions?.length > 0 && <div className="rounded-xl border border-indigo-100 bg-white p-3"><p className="text-[10px] font-black uppercase tracking-[.14em] text-indigo-500">Sesi yang akan dialihkan</p><div className="mt-2 space-y-1">{item.sessions.map((row: any) => <p key={row.id}>{row.package_session?.scheduled_start_at ? new Date(row.package_session.scheduled_start_at).toLocaleString("id-ID") : "Sesi #" + row.package_session_id}</p>)}</div></div>}
      {item.sessions?.some((row: any) => (row.old_booking?.reports?.length || 0) + (row.old_booking?.disputes?.length || 0) > 0) && <div className="rounded-xl border border-amber-200 bg-amber-50 p-3"><p className="font-black text-amber-900">Kasus sesi terkait</p>{item.sessions.map((row: any) => { const reports = row.old_booking?.reports?.length || 0; const disputes = row.old_booking?.disputes?.length || 0; return reports + disputes > 0 ? <p key={row.id} className="mt-1 text-amber-800">Sesi #{row.package_session_id}: {reports} laporan · {disputes} keberatan</p> : null; })}</div>}
    </>}
    {booking && <div className="rounded-xl border border-indigo-100 bg-white p-3">
      <p className="text-[10px] font-black uppercase tracking-[.14em] text-indigo-500">Jejak sesi otomatis</p>
      <div className="mt-2 grid gap-2 sm:grid-cols-2">
        <p><strong>Tutor siap:</strong> {booking?.tutor_ready_at ? new Date(booking.tutor_ready_at).toLocaleString("id-ID") : "-"}</p>
        <p><strong>Murid hadir:</strong> {booking?.student_confirmed_at ? new Date(booking.student_confirmed_at).toLocaleString("id-ID") : "-"}</p>
        <p><strong>Sesi berakhir:</strong> {endedAt ? new Date(endedAt).toLocaleString("id-ID") : "-"}</p>
        <p><strong>Durasi aktual:</strong> {durationMinutes ?? "-"}{durationMinutes !== undefined && durationMinutes !== null ? " menit" : ""}</p>
      </div>
      {booking?.session_focus_note && <p className="mt-2"><strong>Fokus:</strong> {booking.session_focus_note}</p>}
      {progressReport?.mastered_skills && <p className="mt-2"><strong>Hasil belajar:</strong> {progressReport.mastered_skills}</p>}
      {durationMinutes !== undefined && durationMinutes !== null && booking?.duration_hours && durationMinutes < Number(booking.duration_hours) * 60 * 0.6 && <p className="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs font-bold text-amber-800">⚠ Durasi aktual jauh di bawah jadwal. Periksa alasan kedua pihak sebelum memutuskan.</p>}
    </div>}
    {evidence && <Button type="button" variant="outline" size="sm" className="rounded-xl bg-white" onClick={() => void openProtectedFile(evidence, `bukti-kasus-${item.id}`).catch(() => notify.error("Bukti tidak dapat dibuka."))}><ExternalLink size={14} className="mr-2" />Buka bukti</Button>}
  </div>;
}

function AttendanceEvidence({ bookingId }: { bookingId?: number }) {
  const [data, setData] = useState<any>(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (!bookingId) return;
    let active = true;
    setLoading(true);
    setError("");
    http.get(`/admin/bookings/${bookingId}/attendance-evidence`)
      .then((response) => { if (active) setData(response.data?.data ?? null); })
      .catch(() => { if (active) setError("Bukti kehadiran gagal dimuat."); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [bookingId]);

  if (!bookingId) return null;
  const time = (value?: string | null) => (value ? new Date(value).toLocaleString("id-ID") : "-");

  return <div className="rounded-2xl border border-indigo-100 bg-indigo-50/60 p-4 text-sm text-indigo-950">
    <p className="flex items-center gap-2 text-[10px] font-black uppercase tracking-[.14em] text-indigo-600"><ScrollText size={14} /> Bukti kehadiran</p>
    {loading ? <p className="mt-2 flex items-center gap-2 text-xs font-bold"><Loader2 className="animate-spin" size={14} /> Memuat bukti...</p> : error ? <p className="mt-2 text-xs font-bold text-rose-700">{error}</p> : data ? <>
      <p className="mt-2 rounded-xl bg-white px-3 py-2 text-xs font-black">Kesimpulan bukti: {String(data.evidence_state || "unclear").replace(/_/g, " ")}</p>
      <div className="mt-2 grid gap-1 text-xs">
        <p>Tutor siap: {time(data.tutor_ready_at)}</p>
        <p>Murid konfirmasi: {time(data.student_confirmed_at)}</p>
        <p>Catatan belajar terakhir: {time(data.learning_progress_report?.published_at)}</p>
        <p>Absensi peserta: {data.participant_attendances?.length || 0} baris · absensi sesi: {data.session_attendances?.length || 0} baris</p>
      </div>
      <p className="mt-2 text-[11px] font-semibold leading-5 text-indigo-700">{data.decision_hint}</p>
    </> : null}
  </div>;
}

function caseTitle(type: CaseType, item: any) {
  if (type === "report") return item.type === "student_absence" ? "Laporan murid tidak hadir" : item.type === "teacher_absence" ? "Laporan tutor tidak hadir" : "Laporan keadaan darurat";
  if (type === "dispute") return "Keberatan murid";
  if (type === "completion") return "Tinjau sesi";
  if (type === "replacement") return "Pengajuan ganti guru";
  return "Kasus operasional";
}

function caseSubtitle(item: any) {
  return `Kasus #${item.id} · keputusan disimpan dalam jejak administrasi`;
}
