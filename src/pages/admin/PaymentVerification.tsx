import { notify } from "@/lib/notify";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  ArrowLeft,
  CheckCircle2,
  Clock3,
  CreditCard,
  Eye,
  FileWarning,
  Loader2,
  RefreshCw,
  Search,
  X,
  XCircle,
} from "lucide-react";
import AdminLayout from "@/components/AdminLayout";
import ProtectedImage from "@/components/ProtectedImage";
import { ResponsiveSelect } from "@/components/ResponsiveSelect";
import { useConfirmDialog } from "@/components/ConfirmDialogProvider";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import http, { getApiError, getApiErrorDetails } from "@/lib/http";

type Payment = {
  id: number;
  order_id: string;
  student?: { id: number; name: string; email: string } | null;
  title: string;
  amount: number;
  wallet_reserved_amount?: number;
  wallet_applied_amount?: number;
  external_payment_amount?: number;
  external_received_amount?: number;
  payment_outstanding_amount?: number;
  payment_surplus_amount?: number;
  payment_reconciliation_status?: "underpaid" | "exact" | "overpaid" | null;
  top_up_due_at?: string | null;
  status: string;
  payment_proof?: string | null;
  bank_name?: string | null;
  sender_name?: string | null;
  sender_account_number?: string | null;
  payment_rejection_reason?: string | null;
  payment_submitted_at?: string | null;
  class_start_at?: string | null;
  is_cheap_class?: boolean;
  cheap_class_status?: string | null;
  cheap_class_cancellation_reason?: string | null;
  enrollment_status?: string | null;
  can_resubmit?: boolean;
  can_resubmit_if_rejected?: boolean;
  will_refund_if_accepted?: boolean;
  refund_reason_if_accepted?: "class_cancelled" | "capacity_full" | null;
  verified_at?: string | null;
  verifier?: { id: number; name: string } | null;
  refund?: { status: string; amount: number; destination_method?: string | null } | null;
  updated_at?: string;
};

type ResponseData = {
  summary: {
    pending_count: number;
    pending_amount: number;
    accepted_30_days: number;
    rejected_30_days: number;
    refund_pending_count: number;
  };
  pending: Payment[];
  history: Payment[];
  generated_at?: string;
};

const rupiah = (value: number) => new Intl.NumberFormat("id-ID", {
  style: "currency",
  currency: "IDR",
  maximumFractionDigits: 0,
}).format(value || 0);

const dateTime = (value?: string | null) => value
  ? new Date(value).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" })
  : "-";


const waitingDuration = (value?: string | null) => {
  if (!value) return "Menunggu";
  const minutes = Math.max(0, Math.floor((Date.now() - new Date(value).getTime()) / 60_000));
  if (minutes < 60) return `Menunggu ${minutes} menit`;
  const hours = Math.floor(minutes / 60);
  const remaining = minutes % 60;
  return remaining ? `Menunggu ${hours} jam ${remaining} menit` : `Menunggu ${hours} jam`;
};

const needsPriorityReview = (value?: string | null) => {
  if (!value) return false;
  const delta = new Date(value).getTime() - Date.now();
  return delta > -24 * 60 * 60_000 && delta <= 24 * 60 * 60_000;
};

const statusMap: Record<string, { label: string; className: string }> = {
  paid: { label: "Diterima", className: "bg-emerald-50 text-emerald-700" },
  partially_paid: { label: "Kurang bayar", className: "bg-amber-50 text-amber-700" },
  rejected: { label: "Ditolak", className: "bg-rose-50 text-rose-700" },
  refund_pending: { label: "Menunggu refund", className: "bg-amber-50 text-amber-700" },
  refunded: { label: "Sudah direfund", className: "bg-indigo-50 text-indigo-700" },
  cancelled: { label: "Dibatalkan", className: "bg-slate-100 text-slate-600" },
};

export default function PaymentVerification({ embedded = false }: { embedded?: boolean }) {
  const confirm = useConfirmDialog();
  const [data, setData] = useState<ResponseData | null>(null);
  const [loading, setLoading] = useState(true);
  const [processingId, setProcessingId] = useState<number | null>(null);
  const [tab, setTab] = useState<"pending" | "history">("pending");
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("all");
  const [proof, setProof] = useState<Payment | null>(null);
  const [rejecting, setRejecting] = useState<Payment | null>(null);
  const [rejectReason, setRejectReason] = useState("");
  const [reconciling, setReconciling] = useState<Payment | null>(null);
  const [actualReceived, setActualReceived] = useState("");
  const rejectTextareaRef = useRef<HTMLTextAreaElement | null>(null);
  const reconcileInputRef = useRef<HTMLInputElement | null>(null);

  const load = useCallback(async (silent = false) => {
    if (!silent) setLoading(true);
    try {
      const response = await http.get<ResponseData>("/admin/finance/payments");
      setData(response.data);
    } catch (error) {
      if (!silent) notify.error(getApiError(error, "Data pembayaran tidak dapat dimuat."));
    } finally {
      if (!silent) setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
    const refresh = window.setInterval(() => void load(true), 30_000);
    const onDataChanged = () => void load(true);
    window.addEventListener("bimbelku:data-changed", onDataChanged);
    return () => {
      window.clearInterval(refresh);
      window.removeEventListener("bimbelku:data-changed", onDataChanged);
    };
  }, [load]);

  const filteredRows = useMemo(() => {
    const source = tab === "pending" ? data?.pending || [] : data?.history || [];
    const needle = search.trim().toLowerCase();
    return source.filter((item) => {
      const matchesSearch = !needle || [item.order_id, item.student?.name, item.student?.email, item.title, item.sender_name]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(needle));
      const matchesStatus = tab === "pending" || status === "all" || item.status === status;
      return matchesSearch && matchesStatus;
    });
  }, [data, search, status, tab]);

  useEffect(() => setPage(1), [search, status, tab]);
  const lastPage = Math.max(1, Math.ceil(filteredRows.length / 25));
  const rows = filteredRows.slice((page - 1) * 25, page * 25);

  const verify = async (payment: Payment, nextStatus: "paid" | "rejected", reason?: string, actualAmount?: number) => {
    setProcessingId(payment.id);
    try {
      const response = await http.post("/admin/verify-payment", {
        order_id: payment.id,
        status: nextStatus,
        reason: nextStatus === "rejected" ? reason : null,
        actual_received_amount: nextStatus === "paid" ? actualAmount : null,
      });
      notify.success(response.data?.message || "Pembayaran berhasil diproses.");
      setProof(null);
      setRejecting(null);
      setRejectReason("");
      setReconciling(null);
      setActualReceived("");
      await load();
    } catch (error) {
      const details = getApiErrorDetails(error, "Pembayaran tidak dapat diproses.");
      notify.error(details.message);
      // Jika status order berubah dari tab lain / aksi murid, sinkronkan daftar admin
      // agar tombol lama tidak terus terlihat dan memicu aksi pada data stale.
      if (details.status === 409 || details.status === 422) {
        await load();
      }
    } finally {
      setProcessingId(null);
    }
  };

  const accept = (payment: Payment) => {
    setReconciling(payment);
    setActualReceived("");
  };

  const reconcile = async () => {
    if (!reconciling) return;
    const amount = Number(actualReceived.replace(/[^0-9]/g, ""));
    if (!Number.isFinite(amount) || amount <= 0) {
      notify.error("Masukkan nominal dana yang benar-benar terlihat di mutasi rekening.");
      return;
    }
    const required = reconciling.payment_outstanding_amount && reconciling.payment_outstanding_amount > 0
      ? reconciling.payment_outstanding_amount
      : (reconciling.external_payment_amount ?? reconciling.amount);
    const difference = amount - required;
    const approved = await confirm({
      title: difference < 0 ? "Catat pembayaran kurang?" : difference > 0 ? "Catat kelebihan pembayaran?" : "Konfirmasi pembayaran tepat?",
      description: difference < 0
        ? `Dana masuk ${rupiah(amount)}. Masih kurang ${rupiah(Math.abs(difference))}; pesanan belum aktif dan murid akan diminta top-up.`
        : difference > 0
          ? `Dana masuk ${rupiah(amount)}. Kelebihan ${rupiah(difference)} akan masuk ke Saldo BimbelKu dan pesanan diproses.`
          : `Dana masuk tepat ${rupiah(amount)}. Pesanan akan diproses setelah konfirmasi.`,
      confirmText: "Konfirmasi nominal",
      tone: "warning",
    });
    if (approved) await verify(reconciling, "paid", undefined, amount);
  };

  const reject = async () => {
    if (!rejecting || rejectReason.trim().length < 10) {
      notify.error("Alasan penolakan minimal 10 karakter.");
      return;
    }
    const approved = await confirm({
      title: "Tolak bukti pembayaran?",
      description: rejecting.will_refund_if_accepted
        ? rejecting.refund_reason_if_accepted === "capacity_full"
          ? "Kapasitas peserta terverifikasi sudah penuh. Jika bukti ditolak, kursi anomali akan diakhiri tanpa refund karena transfer dianggap tidak valid."
          : "Kelas Bersama sudah dibatalkan. Jika bukti ditolak, pembatalan selesai tanpa refund dan murid tidak dapat mengunggah ulang bukti untuk kelas ini."
        : rejecting.is_cheap_class && !rejecting.can_resubmit_if_rejected
          ? "Jika bukti ditolak, kursi akan berakhir karena masa pembayaran, pendaftaran, atau kapasitas tidak lagi memungkinkan unggah ulang."
          : "Murid akan menerima alasan ini dan dapat mengunggah ulang bukti sebelum tenggat.",
      confirmText: "Tolak bukti",
      tone: "danger",
    });
    if (approved) await verify(rejecting, "rejected", rejectReason.trim());
  };

  return (
    <FinanceSectionFrame embedded={embedded} title="Pembayaran murid" subtitle="Verifikasi dana masuk murid dan rekonsiliasi pembayaran">
      <div className="space-y-6 pb-16">
        <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <SummaryCard icon={Clock3} label="Menunggu pemeriksaan" value={String(data?.summary.pending_count || 0)} detail={rupiah(data?.summary.pending_amount || 0)} tone="amber" />
          <SummaryCard icon={CheckCircle2} label="Diterima 30 hari" value={rupiah(data?.summary.accepted_30_days || 0)} detail="Transfer eksternal yang sudah diverifikasi" tone="emerald" />
          <SummaryCard icon={XCircle} label="Ditolak 30 hari" value={String(data?.summary.rejected_30_days || 0)} detail="Bukti perlu diperbaiki murid" tone="rose" />
          <SummaryCard icon={FileWarning} label="Masuk antrean refund" value={String(data?.summary.refund_pending_count || 0)} detail="Diproses pada menu Refund & saldo" tone="indigo" />
        </section>

        <section className="overflow-hidden rounded-[1.75rem] border border-slate-200 bg-white shadow-sm">
          <div className="flex flex-col gap-4 border-b border-slate-100 p-4 sm:p-5 lg:flex-row lg:items-center lg:justify-between">
            <div className="flex rounded-xl bg-slate-100 p-1">
              <button type="button" onClick={() => setTab("pending")} className={`min-h-10 rounded-lg px-4 text-sm font-black ${tab === "pending" ? "bg-white text-slate-950 shadow-sm" : "text-slate-500"}`}>Perlu diperiksa ({data?.pending.length || 0})</button>
              <button type="button" onClick={() => setTab("history")} className={`min-h-10 rounded-lg px-4 text-sm font-black ${tab === "history" ? "bg-white text-slate-950 shadow-sm" : "text-slate-500"}`}>Riwayat</button>
            </div>
            <div className="flex flex-col gap-2 sm:flex-row">
              <label className="relative min-w-0 sm:w-72">
                <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={16} />
                <Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Cari invoice atau murid" className="h-11 rounded-xl pl-9" />
              </label>
              {tab === "history" && (
                <ResponsiveSelect
                  value={status}
                  ariaLabel="Filter status pembayaran"
                  className="h-11 min-w-44 rounded-xl border-slate-200 bg-white text-sm text-slate-600"
                  options={[
                    { value: "all", label: "Semua status" },
                    { value: "paid", label: "Diterima" },
                    { value: "partially_paid", label: "Kurang bayar" },
                    { value: "rejected", label: "Ditolak" },
                    { value: "refund_pending", label: "Menunggu refund" },
                    { value: "refunded", label: "Sudah direfund" },
                    { value: "cancelled", label: "Dibatalkan" },
                  ]}
                  onValueChange={setStatus}
                />
              )}
              <Button type="button" variant="outline" onClick={() => void load(false)} disabled={loading} className="h-11 shrink-0 whitespace-nowrap rounded-xl"><RefreshCw size={16} className={loading ? "mr-2 animate-spin" : "mr-2"} />Muat ulang</Button>
            </div>
            <p className="text-[11px] font-bold text-slate-400 lg:ml-auto">Antrean diperbarui otomatis setiap 30 detik.</p>
          </div>

          {loading ? (
            <div className="grid min-h-72 place-items-center"><Loader2 className="animate-spin text-indigo-600" /></div>
          ) : rows.length === 0 ? (
            <div className="grid min-h-72 place-items-center p-8 text-center"><div><CheckCircle2 className="mx-auto text-emerald-500" size={40} /><p className="mt-3 font-black text-slate-800">Tidak ada data pada tampilan ini.</p><p className="mt-1 text-sm text-slate-500">Ubah filter atau muat ulang halaman.</p></div></div>
          ) : (
            <div className="divide-y divide-slate-100">
              {rows.map((payment) => (
                <article key={payment.id} className="grid gap-4 p-4 sm:p-5 lg:grid-cols-[minmax(16rem,1.25fr)_minmax(12rem,.9fr)_minmax(11rem,.8fr)_minmax(18rem,auto)] lg:items-start">
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2"><p className="font-black text-slate-950">{payment.student?.name || "Murid"}</p><span className="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-black text-slate-500">{payment.order_id}</span></div>
                    <p className="mt-1 truncate text-sm font-semibold text-slate-600">{payment.title}</p>
                    <p className="mt-1 text-xs text-slate-400">Dikirim {dateTime(payment.payment_submitted_at || payment.updated_at)}</p>
                    {tab === "pending" && <><div className="mt-2 flex flex-wrap gap-2"><span className="rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-black text-amber-700">{waitingDuration(payment.payment_submitted_at || payment.updated_at)}</span>{payment.is_cheap_class && needsPriorityReview(payment.class_start_at) && <span className="rounded-full bg-rose-50 px-2.5 py-1 text-[11px] font-black text-rose-700">Prioritas · kelas segera dimulai</span>}{payment.will_refund_if_accepted && <span className="rounded-full bg-violet-50 px-2.5 py-1 text-[11px] font-black text-violet-700">{payment.refund_reason_if_accepted === "capacity_full" ? "Kuota penuh · valid = refund penuh" : "Kelas batal · valid = refund penuh"}</span>}</div>{payment.will_refund_if_accepted && <p className="mt-2 max-w-xl text-xs font-bold leading-5 text-violet-700">Bukti tetap harus diperiksa. Terima hanya jika transfer benar-benar masuk; hasilnya otomatis diarahkan ke refund, bukan {payment.refund_reason_if_accepted === "capacity_full" ? "menambah peserta melebihi kuota" : "mengaktifkan kelas"}.</p>}</>}
                  </div>
                  <div><p className="text-xs font-black uppercase tracking-wide text-slate-400">Pengirim</p><p className="mt-1 text-sm font-bold text-slate-700">{payment.sender_name || "Belum tercatat"}</p><p className="mt-1 text-xs text-slate-500">{payment.bank_name || "-"} · {payment.sender_account_number || "-"}</p></div>
                  <div><p className="text-xs font-black uppercase tracking-wide text-slate-400">{(payment.wallet_reserved_amount || payment.wallet_applied_amount) ? "Transfer eksternal" : "Nominal"}</p><p className="mt-1 text-lg font-black text-emerald-700">{rupiah(payment.external_payment_amount ?? payment.amount)}</p>{Boolean((payment.wallet_reserved_amount || 0) + (payment.wallet_applied_amount || 0)) && <p className="mt-1 text-[11px] font-bold text-indigo-600">Saldo BimbelKu: {rupiah((payment.wallet_reserved_amount || 0) + (payment.wallet_applied_amount || 0))} · total {rupiah(payment.amount)}</p>}{Boolean(payment.discount_amount) && <div className="mt-2 rounded-xl border border-orange-100 bg-orange-50 p-2.5 text-[11px] font-bold leading-5 text-orange-800"><p>Harga normal: {rupiah(payment.subtotal_amount ?? payment.amount)}</p><p>Voucher {payment.promotion?.code || payment.promotion?.title || "promo"}: -{rupiah(payment.discount_amount || 0)}</p><p className="font-black text-slate-800">Total tagihan: {rupiah(payment.amount)}</p></div>}{tab === "history" && <span className={`mt-2 inline-flex rounded-full px-3 py-1 text-xs font-black ${statusMap[payment.status]?.className || "bg-slate-100 text-slate-600"}`}>{statusMap[payment.status]?.label || payment.status}</span>}</div>
                  <div className="min-w-0">
                    {payment.payment_proof ? <Button type="button" variant="outline" onClick={() => setProof(payment)} className="h-10 w-full shrink-0 whitespace-nowrap rounded-xl"><Eye size={15} className="mr-2" />Bukti</Button> : <span className="block h-10" aria-hidden="true" />}
                  </div>
                  <div className="min-w-0">
                    {tab === "pending" ? <div className="flex min-w-0 flex-wrap gap-2"><Button type="button" variant="outline" onClick={() => { setRejecting(payment); setRejectReason(""); }} className="h-10 shrink-0 whitespace-nowrap rounded-xl border-rose-200 text-rose-700">Tolak</Button><Button type="button" onClick={() => void accept(payment)} disabled={processingId === payment.id} className="h-10 shrink-0 whitespace-nowrap rounded-xl bg-slate-950">{processingId === payment.id && <Loader2 size={15} className="mr-2 animate-spin" />}Terima</Button></div> : <div className="min-w-0 text-left text-xs leading-5 text-slate-500">{payment.verifier?.name ? `Diproses ${payment.verifier.name} · ${dateTime(payment.verified_at || payment.updated_at)}` : dateTime(payment.updated_at)}{payment.payment_rejection_reason && <p className="mt-1 text-rose-600">{payment.payment_rejection_reason}</p>}</div>}
                  </div>
                </article>
              ))}
            </div>
          )}
          {!loading && filteredRows.length > 0 && <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-4 py-3 text-sm"><span className="text-slate-500">Maksimal 25 data per halaman · total {filteredRows.length}</span><div className="flex items-center gap-2"><button type="button" disabled={page <= 1} onClick={() => setPage((value) => Math.max(1, value - 1))} className="rounded-lg border px-3 py-2 font-bold disabled:opacity-40">Sebelumnya</button><span className="font-black">{page} / {lastPage}</span><button type="button" disabled={page >= lastPage} onClick={() => setPage((value) => Math.min(lastPage, value + 1))} className="rounded-lg border px-3 py-2 font-bold disabled:opacity-40">Berikutnya</button></div></div>}
        </section>
      </div>

      {proof && <ProofModal payment={proof} onClose={() => setProof(null)} onAccept={() => void accept(proof)} onReject={() => { setRejecting(proof); setRejectReason(""); setProof(null); }} pending={tab === "pending"} />}
      <Dialog open={Boolean(rejecting)} onOpenChange={(open) => { if (!open) setRejecting(null); }}>
        <DialogContent hideCloseButton onOpenAutoFocus={(event) => { event.preventDefault(); rejectTextareaRef.current?.focus(); }} className="w-full max-w-lg gap-0 rounded-[1.75rem] border-0 bg-white p-5 shadow-2xl sm:p-6">
          {rejecting && (
            <>
              <div className="grid grid-cols-[2.5rem_minmax(0,1fr)_2.5rem] items-start gap-2">
                <button type="button" aria-label="Kembali ke daftar pembayaran" onClick={() => setRejecting(null)} className="grid h-10 w-10 place-items-center rounded-xl hover:bg-slate-100"><ArrowLeft size={18} /></button>
                <div className="min-w-0 text-center">
                  <DialogTitle asChild><p className="px-0 text-lg font-black text-slate-950">Tolak bukti pembayaran</p></DialogTitle>
                  <DialogDescription asChild><p className="mt-1 break-words text-sm text-slate-500">{rejecting.will_refund_if_accepted ? (rejecting.refund_reason_if_accepted === "capacity_full" ? "Kapasitas peserta terverifikasi sudah penuh. Penolakan akan mengakhiri kursi anomali tanpa refund." : "Kelas sudah dibatalkan. Penolakan akan menyelesaikan pembatalan tanpa refund.") : rejecting.is_cheap_class && !rejecting.can_resubmit_if_rejected ? "Masa unggah ulang tidak tersedia; penolakan akan mengakhiri kursi." : "Tuliskan alasan yang dapat dipahami murid."}</p></DialogDescription>
                </div>
                <button type="button" aria-label="Tutup" onClick={() => setRejecting(null)} className="grid h-10 w-10 place-items-center rounded-xl hover:bg-slate-100"><X size={18} /></button>
              </div>
              <Label htmlFor="payment-reject-reason" className="mt-5 block text-sm font-black text-slate-800">Alasan penolakan pembayaran</Label>
              <textarea ref={rejectTextareaRef} id="payment-reject-reason" value={rejectReason} onChange={(event) => setRejectReason(event.target.value)} maxLength={500} className="mt-2 min-h-32 w-full rounded-xl border border-slate-200 p-4 text-sm outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100" placeholder="Contoh: nominal pada bukti tidak sesuai dengan tagihan..." />
              <div className="mt-5 flex justify-end gap-2"><Button variant="outline" onClick={() => setRejecting(null)} className="rounded-xl">Batal</Button><Button onClick={() => void reject()} disabled={processingId === rejecting.id} className="rounded-xl bg-rose-600 hover:bg-rose-700">Tolak bukti</Button></div>
            </>
          )}
        </DialogContent>
      </Dialog>
      <Dialog open={Boolean(reconciling)} onOpenChange={(open) => { if (!open) setReconciling(null); }}>
        <DialogContent hideCloseButton onOpenAutoFocus={(event) => { event.preventDefault(); reconcileInputRef.current?.focus(); }} className="w-full max-w-lg gap-0 rounded-[1.75rem] border-0 bg-white p-5 shadow-2xl sm:p-6">
          {reconciling && (
            <>
              <div className="flex items-start justify-between gap-3">
                <div>
                  <DialogTitle asChild><p className="px-0 text-left text-lg font-black text-slate-950">Rekonsiliasi pembayaran</p></DialogTitle>
                  <DialogDescription asChild><p className="mt-1 text-sm text-slate-500">{reconciling.order_id} · {reconciling.student?.name}</p></DialogDescription>
                </div>
                <button type="button" aria-label="Tutup" onClick={() => setReconciling(null)} className="grid h-10 w-10 place-items-center rounded-xl hover:bg-slate-100"><X size={18} /></button>
              </div>
              <div className="mt-5 grid grid-cols-2 gap-3 rounded-2xl bg-slate-50 p-4 text-sm">
                <div><p className="text-xs font-bold text-slate-400">Total tagihan</p><p className="mt-1 font-black">{rupiah(reconciling.amount)}</p></div>
                <div><p className="text-xs font-bold text-slate-400">Transfer yang diperlukan</p><p className="mt-1 font-black">{rupiah(reconciling.payment_outstanding_amount && reconciling.payment_outstanding_amount > 0 ? reconciling.payment_outstanding_amount : (reconciling.external_payment_amount ?? reconciling.amount))}</p></div>
                {Boolean(reconciling.external_received_amount) && <div className="col-span-2"><p className="text-xs font-bold text-slate-400">Sudah diterima sebelumnya</p><p className="mt-1 font-black text-indigo-700">{rupiah(reconciling.external_received_amount || 0)}</p></div>}
              </div>
              <label className="mt-5 block"><span className="text-sm font-black text-slate-800">Nominal dana yang masuk</span><div className="mt-2 flex h-12 items-center rounded-xl border border-slate-200 px-4 focus-within:border-indigo-400 focus-within:ring-2 focus-within:ring-indigo-100"><span className="mr-2 text-sm font-bold text-slate-500">Rp</span><input ref={reconcileInputRef} inputMode="numeric" value={actualReceived} onChange={(event) => setActualReceived(event.target.value.replace(/[^0-9]/g, ""))} className="min-w-0 flex-1 bg-transparent text-lg font-black outline-none" placeholder="0" /></div></label>
              {actualReceived && (() => {
                const required = reconciling.payment_outstanding_amount && reconciling.payment_outstanding_amount > 0 ? reconciling.payment_outstanding_amount : (reconciling.external_payment_amount ?? reconciling.amount);
                const difference = Number(actualReceived) - required;
                return <div className={`mt-3 rounded-xl p-3 text-sm font-bold ${difference < 0 ? "bg-amber-50 text-amber-800" : difference > 0 ? "bg-indigo-50 text-indigo-800" : "bg-emerald-50 text-emerald-800"}`}>{difference < 0 ? `Kurang ${rupiah(Math.abs(difference))}` : difference > 0 ? `Lebih ${rupiah(difference)} · masuk Saldo BimbelKu` : "Nominal tepat"}</div>;
              })()}
              <div className="mt-5 flex justify-end gap-2"><Button variant="outline" onClick={() => setReconciling(null)} className="rounded-xl">Batal</Button><Button onClick={() => void reconcile()} disabled={processingId === reconciling.id} className="rounded-xl bg-slate-950">Konfirmasi nominal</Button></div>
            </>
          )}
        </DialogContent>
      </Dialog>
    </FinanceSectionFrame>
  );
}

function FinanceSectionFrame({ embedded, title, subtitle, children }: { embedded: boolean; title: string; subtitle: string; children: React.ReactNode }) {
  return embedded ? <>{children}</> : <AdminLayout title={title} subtitle={subtitle}>{children}</AdminLayout>;
}

function SummaryCard({ icon: Icon, label, value, detail, tone }: { icon: typeof CreditCard; label: string; value: string; detail: string; tone: "amber" | "emerald" | "rose" | "indigo" }) {
  const styles = { amber: "bg-amber-50 text-amber-700", emerald: "bg-emerald-50 text-emerald-700", rose: "bg-rose-50 text-rose-700", indigo: "bg-indigo-50 text-indigo-700" }[tone];
  return <article className="rounded-[1.5rem] border border-slate-100 bg-white p-5 shadow-sm"><span className={`grid h-11 w-11 place-items-center rounded-2xl ${styles}`}><Icon size={20} /></span><p className="mt-4 text-xs font-black uppercase tracking-wide text-slate-400">{label}</p><p className="mt-1 break-words text-2xl font-black text-slate-950">{value}</p><p className="mt-1 text-xs leading-5 text-slate-500">{detail}</p></article>;
}

function ProofModal({ payment, onClose, onAccept, onReject, pending }: { payment: Payment; onClose: () => void; onAccept: () => void; onReject: () => void; pending: boolean }) {
  return (
    <Dialog open onOpenChange={(open) => { if (!open) onClose(); }}>
      <DialogContent layer="detail" hideCloseButton className="max-h-[calc(100dvh-1.5rem)] w-full max-w-2xl gap-0 overflow-y-auto rounded-[1.75rem] border-0 bg-white p-0 shadow-2xl sm:max-h-[92dvh]">
        <div className="sticky top-0 z-10 grid grid-cols-[2.5rem_minmax(0,1fr)_2.5rem] items-start gap-2 border-b border-slate-100 bg-white/95 p-3 backdrop-blur sm:p-4">
          <button type="button" aria-label="Kembali ke daftar pembayaran" onClick={onClose} className="grid h-10 w-10 place-items-center rounded-xl hover:bg-slate-100"><ArrowLeft size={18} /></button>
          <div className="min-w-0 text-center">
            <DialogTitle asChild><p className="break-words px-0 font-black text-slate-950">Bukti transfer {payment.order_id}</p></DialogTitle>
            <DialogDescription asChild><p className="mt-1 break-words text-xs text-slate-500">{payment.student?.name} · transfer {rupiah(payment.external_payment_amount ?? payment.amount)}{(payment.wallet_reserved_amount || 0) + (payment.wallet_applied_amount || 0) ? ` · saldo ${rupiah((payment.wallet_reserved_amount || 0) + (payment.wallet_applied_amount || 0))}` : ""}</p></DialogDescription>
          </div>
          <button type="button" aria-label="Tutup" onClick={onClose} className="grid h-10 w-10 place-items-center rounded-xl hover:bg-slate-100"><X size={18} /></button>
        </div>
        <div className="p-4 sm:p-6">
          {payment.will_refund_if_accepted && <div className="mb-4 rounded-xl border border-violet-200 bg-violet-50 p-4 text-sm font-bold leading-6 text-violet-800">{payment.refund_reason_if_accepted === "capacity_full" ? "Kapasitas peserta terverifikasi sudah penuh. Jika bukti ini diterima, dana otomatis masuk antrean refund penuh dan peserta tidak ditambahkan." : "Kelas Bersama sudah dibatalkan. Jika bukti ini diterima, dana otomatis masuk antrean refund penuh. Jika ditolak, pembatalan selesai tanpa refund."}</div>}
          <div className="rounded-2xl bg-slate-100 p-2"><ProtectedImage source={payment.payment_proof || ""} alt={`Bukti pembayaran ${payment.order_id}`} className="mx-auto max-h-[60dvh] w-full rounded-xl object-contain" /></div>
          {pending && <div className="mt-5 grid gap-2 sm:grid-cols-2"><Button variant="outline" onClick={onReject} className="h-11 rounded-xl border-rose-200 text-rose-700">Tolak</Button><Button onClick={onAccept} className="h-11 rounded-xl bg-slate-950">Terima pembayaran</Button></div>}
        </div>
      </DialogContent>
    </Dialog>
  );
}
