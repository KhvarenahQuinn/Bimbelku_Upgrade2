import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import {
  AlertCircle,
  Building2,
  CalendarDays,
  CheckCircle2,
  Clock3,
  CreditCard,
  Loader2,
  Receipt,
  RefreshCw,
  RotateCcw,
  ShieldCheck,
  WalletCards,
  WifiOff,
  X,
  XCircle,
} from "lucide-react";
import axios from "axios";
import StudentLayout from "@/components/StudentLayout";
import ProtectedImage from "@/components/ProtectedImage";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import http, { getApiError } from "@/lib/http";
import { notify } from "@/lib/notify";

type ErrorType = "network" | "unauthorized" | "forbidden" | "not_found" | "generic" | null;

interface RefundItem {
  id: number;
  status: string;
  amount: number;
  reason: string;
  proof_url?: string;
  processed_at?: string;
  destination_method?: "bank_transfer" | "bimbelku_balance" | "xendit_original" | null;
  destination_bank_name?: string | null;
  destination_account_name?: string | null;
  destination_account_number?: string | null;
  destination_selected_at?: string | null;
  wallet_funded_amount?: number;
  external_funded_amount?: number;
  gateway_status?: string | null;
  gateway_refund_id?: string | null;
  gateway_failure_code?: string | null;
  gateway_failure_message?: string | null;
  gateway_submitted_at?: string | null;
  gateway_processed_at?: string | null;
  last_reconciled_at?: string | null;
  stage?: string | null;
  stage_label?: string | null;
  status_message?: string | null;
  can_switch_to_balance?: boolean;
  timeline?: RefundTimelineStep[];
  created_at?: string;
  updated_at?: string;
}

interface RefundTimelineStep {
  key: string;
  label: string;
  state: "done" | "current" | "pending" | "failed";
  at?: string | null;
  description: string;
}

interface OrderItem {
  id: number;
  order_id: string;
  amount: number;
  status: string;
  created_at: string;
  verified_at?: string;
  subject: string;
  tutor_name: string;
  type: string;
  schedule?: string;
  payment_proof_url?: string;
  payment_rejection_reason?: string;
  payment_due_at?: string;
  duration_hours?: number;
  total_learning_hours?: number;
  order_kind: "cheap_class" | "package" | "booking";
  package_name?: string | null;
  renewal_of_id?: number | null;
  subtotal_amount?: number;
  discount_amount?: number;
  wallet_reserved_amount?: number;
  wallet_applied_amount?: number;
  external_payment_amount?: number;
  payment_provider?: string | null;
  enrollment_status?: string | null;
  cheap_class_status?: string | null;
  cheap_class_cancellation_reason?: string | null;
  refund?: RefundItem;
}


interface WalletData {
  balance: number;
  reserved_balance: number;
  total_credit: number;
  currency: string;
  withdrawable: boolean;
  usage: string;
  transactions: Array<{
    id: number;
    type: string;
    direction: "credit" | "debit";
    amount: number;
    balance_after: number;
    description: string;
    order?: { id: number; order_id: string; status: string } | null;
    created_at: string;
  }>;
}

const statusInfo: Record<string, { label: string; className: string; icon: typeof Clock3 }> = {
  pending: { label: "Belum dibayar", className: "bg-orange-50 text-orange-700", icon: Clock3 },
  submitted: { label: "Diperiksa admin", className: "bg-sky-50 text-sky-700", icon: RefreshCw },
  paid: { label: "Diterima", className: "bg-emerald-50 text-emerald-700", icon: CheckCircle2 },
  rejected: { label: "Bukti ditolak", className: "bg-rose-50 text-rose-700", icon: XCircle },
  cancelled: { label: "Dibatalkan", className: "bg-slate-100 text-slate-600", icon: XCircle },
  expired: { label: "Kedaluwarsa", className: "bg-slate-100 text-slate-600", icon: Clock3 },
  refund_pending: { label: "Refund diproses", className: "bg-amber-50 text-amber-700", icon: RotateCcw },
  refunded: { label: "Refund selesai", className: "bg-violet-50 text-violet-700", icon: CheckCircle2 },
};

const rupiah = (value: number) => new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(value || 0);
const refundStage = (refund: RefundItem) => {
  if (refund.stage_label) return refund.stage_label;
  if (refund.status === "paid") return "Berhasil";
  if (refund.status === "failed" || refund.gateway_status === "FAILED" || refund.gateway_status === "REJECTED") return "Gagal";
  if (!refund.destination_selected_at) return "Memenuhi syarat";
  if (refund.gateway_status === "SUBMITTING") return "Sedang disiapkan";
  if (refund.gateway_submitted_at && ["REQUESTED", "PENDING", "PROCESSING", "ACCEPTED"].includes(refund.gateway_status || "")) return "Sedang diproses";
  if (refund.gateway_submitted_at) return "Dikirim";
  return "Dipilih murid";
};

const timelineTone: Record<RefundTimelineStep["state"], { dot: string; line: string; title: string }> = {
  done: { dot: "bg-emerald-500 text-white", line: "bg-emerald-200", title: "text-slate-900" },
  current: { dot: "bg-violet-600 text-white", line: "bg-violet-200", title: "text-violet-900" },
  pending: { dot: "bg-slate-200 text-slate-500", line: "bg-slate-200", title: "text-slate-400" },
  failed: { dot: "bg-rose-600 text-white", line: "bg-rose-200", title: "text-rose-800" },
};

function RefundTimeline({ steps }: { steps: RefundTimelineStep[] }) {
  return (
    <ol className="mt-4 space-y-0">
      {steps.map((step, index) => {
        const tone = timelineTone[step.state] || timelineTone.pending;
        const isLast = index === steps.length - 1;
        return (
          <li key={step.key} className="flex gap-3">
            <div className="flex flex-col items-center">
              <span className={`grid h-6 w-6 shrink-0 place-items-center rounded-full text-[11px] font-black ${tone.dot}`}>
                {step.state === "done" ? <CheckCircle2 size={13} /> : step.state === "failed" ? <XCircle size={13} /> : index + 1}
              </span>
              {!isLast && <span className={`w-0.5 flex-1 ${tone.line}`} aria-hidden="true" />}
            </div>
            <div className={`min-w-0 ${isLast ? "pb-0" : "pb-4"}`}>
              <p className={`text-xs font-black ${tone.title}`}>{step.label}</p>
              <p className="mt-0.5 text-[11px] leading-4 text-slate-500">{step.description}</p>
              {step.at && <p className="mt-0.5 text-[10px] font-bold text-slate-400">{dateTime(step.at)}</p>}
            </div>
          </li>
        );
      })}
    </ol>
  );
}

const dateTime = (value?: string | null) => value
  ? new Date(value).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" })
  : "Belum tersedia";

const orderKindLabel = (kind: OrderItem["order_kind"], renewalOfId?: number | null) => kind === "cheap_class"
  ? "Kelas Bersama"
  : renewalOfId
    ? "Perpanjangan Paket"
  : kind === "package"
    ? "Paket Baru"
    : "Privat lama";

export default function TransactionHistory() {
  const navigate = useNavigate();
  const [orders, setOrders] = useState<OrderItem[]>([]);
  const [wallet, setWallet] = useState<WalletData>({ balance: 0, reserved_balance: 0, total_credit: 0, currency: "IDR", withdrawable: false, usage: "learning_payments", transactions: [] });
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState("all");
  const [proof, setProof] = useState<string | null>(null);
  const [error, setError] = useState<ErrorType>(null);
  const [refundBank, setRefundBank] = useState<RefundItem | null>(null);
  const [refundBankName, setRefundBankName] = useState("");
  const [refundAccountName, setRefundAccountName] = useState("");
  const [refundAccountNumber, setRefundAccountNumber] = useState("");
  const [refundSubmitting, setRefundSubmitting] = useState<number | null>(null);
  const [refundChoice, setRefundChoice] = useState<{ refund: RefundItem; method: "bimbelku_balance" | "xendit_original" } | null>(null);

  useEffect(() => {
    void load();
    // Keep an open history page current so a refund that finishes on the
    // provider side appears without the student reloading the page.
    const refresh = () => {
      if (document.visibilityState === "visible") void load(true);
    };
    const timer = window.setInterval(refresh, 15_000);
    window.addEventListener("focus", refresh);
    document.addEventListener("visibilitychange", refresh);
    return () => {
      window.clearInterval(timer);
      window.removeEventListener("focus", refresh);
      document.removeEventListener("visibilitychange", refresh);
    };
  }, []);

  const load = async (silent = false) => {
    if (!silent) setLoading(true);
    setError(null);
    try {
      const [ordersResponse, walletResponse] = await Promise.all([
        http.get("/orders"),
        http.get("/student/wallet"),
      ]);
      setOrders(ordersResponse.data.data || []);
      setWallet(walletResponse.data || { balance: 0, reserved_balance: 0, total_credit: 0, currency: "IDR", withdrawable: false, usage: "learning_payments", transactions: [] });
    } catch (err: unknown) {
      if (axios.isAxiosError(err)) {
        if (!err.response) setError("network");
        else if (err.response.status === 401) setError("unauthorized");
        else if (err.response.status === 403) setError("forbidden");
        else if (err.response.status === 404) setError("not_found");
        else setError("generic");
      } else {
        setError("generic");
      }
    } finally {
      if (!silent) setLoading(false);
    }
  };

  const handleRetry = () => { setError(null); void load(); };

  const confirmRefundChoice = async () => {
    if (!refundChoice || refundSubmitting !== null) return;
    const switchAfterFailure = refundChoice.refund.status === "failed" && refundChoice.method === "bimbelku_balance";
    if (refundChoice.refund.status !== "pending" && !switchAfterFailure) return;
    setRefundSubmitting(refundChoice.refund.id);
    try {
      const response = await http.post(`/student/refunds/${refundChoice.refund.id}/destination`, {
        destination_method: refundChoice.method,
      });
      notify.success(response.data?.message || "Tujuan refund berhasil dikonfirmasi.");
      setRefundChoice(null);
      await load();
    } catch (err) {
      notify.error(getApiError(err, "Tujuan refund tidak dapat disimpan."));
    } finally {
      setRefundSubmitting(null);
    }
  };

  const openBankRefund = (refund: RefundItem) => {
    const externalRefund = refund.external_funded_amount ?? refund.amount;
    if (externalRefund <= 0.009) {
      notify.warning("Pembayaran ini seluruhnya berasal dari Saldo BimbelKu. Refund wajib kembali ke Saldo BimbelKu dan tidak dapat ditarik ke rekening/e-wallet.");
      return;
    }
    setRefundBank(refund);
    setRefundBankName(refund.destination_bank_name || "");
    setRefundAccountName(refund.destination_account_name || "");
    setRefundAccountNumber(refund.destination_account_number || "");
  };

  const submitBankRefund = async () => {
    if (!refundBank || refundSubmitting !== null) return;
    setRefundSubmitting(refundBank.id);
    try {
      const response = await http.post(`/student/refunds/${refundBank.id}/destination`, {
        destination_method: "bank_transfer",
        bank_name: refundBankName.trim(),
        account_name: refundAccountName.trim(),
        account_number: refundAccountNumber.trim(),
      });
      notify.success(response.data?.message || "Tujuan refund berhasil disimpan.");
      setRefundBank(null);
      await load();
    } catch (err) {
      notify.error(getApiError(err, "Tujuan refund tidak dapat disimpan."));
    } finally {
      setRefundSubmitting(null);
    }
  };

  const filtered = useMemo(
    () => filter === "all" ? orders : orders.filter((order) => filter === "refund" ? order.status.includes("refund") : order.status === filter),
    [filter, orders],
  );

  return (
    <StudentLayout title="Riwayat Pembayaran">
      <div className="w-full space-y-6 pb-12">
        <section className="grid gap-4 rounded-[1.75rem] bg-gradient-to-br from-slate-950 to-indigo-950 p-5 text-white sm:rounded-[2rem] sm:p-7 lg:grid-cols-[1fr_20rem] lg:items-end">
          <div><p className="text-xs font-black uppercase tracking-[.2em] text-indigo-200">Keuangan murid</p><h1 className="mt-3 text-2xl font-black sm:text-3xl">Tagihan, transfer, refund, dan saldo</h1><p className="mt-2 text-sm text-indigo-100/70">Semua nominal dan status keputusan admin tercatat di sini.</p><Button onClick={load} variant="outline" className="mt-5 w-full rounded-xl border-white/20 bg-white/10 text-white hover:bg-white/20 hover:text-white sm:w-auto"><RefreshCw size={16} className="mr-2" />Muat ulang</Button></div>
          <div className="rounded-2xl border border-white/15 bg-white/10 p-5 backdrop-blur-sm"><div className="flex items-center gap-3"><span className="grid h-11 w-11 place-items-center rounded-2xl bg-white/10 text-indigo-200"><WalletCards size={20} /></span><div><p className="text-xs font-black uppercase tracking-wide text-indigo-200">Saldo BimbelKu</p><p className="mt-1 text-2xl font-black">{rupiah(wallet.balance)}</p></div></div>{wallet.reserved_balance > 0 && <p className="mt-3 rounded-xl bg-amber-300/10 px-3 py-2 text-xs font-bold text-amber-100">{rupiah(wallet.reserved_balance)} sedang ditahan untuk pembayaran yang diperiksa.</p>}<p className="mt-3 text-xs leading-5 text-indigo-100/70">Store credit dari refund untuk Paket Belajar atau Kelas Bersama. Saldo tidak dapat ditarik tunai dan seluruh mutasinya tercatat otomatis.</p></div>
        </section>
        {wallet.transactions.length > 0 && (
          <section className="overflow-hidden rounded-[1.5rem] border border-indigo-100 bg-white">
            <div className="border-b border-indigo-100 bg-indigo-50/70 px-4 py-3 sm:px-5">
              <p className="text-sm font-black text-indigo-950">Riwayat mutasi Saldo BimbelKu</p>
              <p className="mt-1 text-xs text-indigo-700">Setiap perubahan saldo dibuat oleh sistem dan tidak dapat diedit.</p>
            </div>
            <div className="divide-y divide-slate-100">
              {wallet.transactions.slice(0, 20).map((transaction) => (
                <div key={transaction.id} className="flex flex-col justify-between gap-3 px-4 py-4 sm:flex-row sm:items-center sm:px-5">
                  <div>
                    <p className="text-sm font-black text-slate-800">{transaction.description}</p>
                    <p className="mt-1 text-xs text-slate-400">{new Date(transaction.created_at).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" })}</p>
                  </div>
                  <div className="text-left sm:text-right">
                    <p className={`font-black ${transaction.direction === "debit" ? "text-rose-700" : "text-emerald-700"}`}>{transaction.direction === "debit" ? "−" : "+"}{rupiah(transaction.amount)}</p>
                    <p className="mt-1 text-[11px] text-slate-500">Saldo tersedia setelah mutasi {rupiah(transaction.balance_after)}</p>
                    {transaction.order?.order_id && <p className="mt-1 text-[10px] font-bold text-slate-400">{transaction.order.order_id}</p>}
                  </div>
                </div>
              ))}
            </div>
          </section>
        )}
        <div className="flex gap-2 overflow-x-auto rounded-2xl border border-slate-100 bg-white p-2">
          {[["all", "Semua"], ["pending", "Belum dibayar"], ["submitted", "Diperiksa"], ["paid", "Diterima"], ["rejected", "Ditolak"], ["refund", "Refund"]].map(([value, label]) => <button key={value} onClick={() => setFilter(value)} className={`whitespace-nowrap rounded-xl px-4 py-2 text-xs font-black ${filter === value ? "bg-indigo-600 text-white" : "text-slate-500 hover:bg-slate-50"}`}>{label}</button>)}
        </div>
        {loading ? (
          <div className="grid min-h-64 place-items-center"><Loader2 className="animate-spin text-indigo-600" /></div>
        ) : error ? (
          <ErrorState type={error} onRetry={handleRetry} />
        ) : filtered.length === 0 ? (
          <div className="rounded-[2rem] border-2 border-dashed border-slate-200 bg-white py-20 text-center"><Receipt className="mx-auto h-10 w-10 text-slate-300" /><p className="mt-3 font-black text-slate-700">Belum ada transaksi</p></div>
        ) : (
          <div className="space-y-4">
            {filtered.map((order) => {
              const status = statusInfo[order.status] || statusInfo.pending;
              const Icon = status.icon;
              const refund = order.refund;
              // Only real refund records belong in the history. Cancelled or
              // rejected attempts must not resurface refund destination cards.
              const showRefund = Boolean(refund) && !["cancelled", "rejected"].includes(refund!.status);
              const walletRefundAmount = refund?.wallet_funded_amount ?? 0;
              const externalRefundAmount = refund?.external_funded_amount ?? refund?.amount ?? 0;
              const canBankRefund = externalRefundAmount > 0.009;
              return <article key={order.id} className="rounded-[1.5rem] border border-slate-100 bg-white p-4 shadow-sm sm:rounded-[2rem] sm:p-5">
                <div className="flex flex-col justify-between gap-5 md:flex-row md:items-center">
                  <div className="flex min-w-0 gap-4"><div className="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-indigo-50 text-indigo-600"><Receipt /></div><div className="min-w-0"><div className="flex flex-wrap items-center gap-2"><p className="text-[11px] font-bold uppercase tracking-widest text-slate-400">{order.order_id}</p><span className="rounded-full bg-slate-100 px-2 py-1 text-[9px] font-black uppercase tracking-wide text-slate-600">{orderKindLabel(order.order_kind, order.renewal_of_id)}</span></div><h2 className="mt-1 truncate text-lg font-black text-slate-900">{order.subject}</h2><p className="mt-1 text-sm text-slate-500">{order.tutor_name} · {order.type}</p></div></div>
                  <div className="flex flex-wrap items-center justify-between gap-3 md:justify-end"><div><p className="text-left text-xl font-black text-slate-900 md:text-right">{rupiah(order.amount)}</p><p className="mt-1 flex items-center gap-1 text-[11px] text-slate-400 md:justify-end"><CalendarDays size={12} />{new Date(order.created_at).toLocaleDateString("id-ID")}</p></div><span className={`inline-flex items-center gap-1 rounded-full px-3 py-1.5 text-xs font-bold ${status.className}`}><Icon size={14} />{status.label}</span></div>
                </div>
                {Boolean((order.wallet_reserved_amount || 0) + (order.wallet_applied_amount || 0)) && <div className="mt-4 grid grid-cols-2 gap-2 rounded-xl border border-indigo-100 bg-indigo-50 p-3 text-xs"><div><p className="font-bold text-indigo-500">Saldo BimbelKu</p><p className="mt-1 font-black text-indigo-800">{rupiah((order.wallet_reserved_amount || 0) + (order.wallet_applied_amount || 0))}</p></div><div><p className="font-bold text-slate-500">Transfer eksternal</p><p className="mt-1 font-black text-slate-800">{rupiah(order.external_payment_amount ?? Math.max(0, order.amount - ((order.wallet_reserved_amount || 0) + (order.wallet_applied_amount || 0))) )}</p></div></div>}
                {order.payment_rejection_reason && <div className="mt-4 rounded-xl border border-rose-100 bg-rose-50 p-3 text-xs leading-5 text-rose-700">Alasan admin: {order.payment_rejection_reason}</div>}
                {showRefund && order.refund && (
                  <div className="mt-4 rounded-2xl border border-violet-100 bg-violet-50 p-4 text-sm text-violet-950">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                      <div>
                        <p className="font-black">Refund · {rupiah(order.refund.amount)}</p>
                        <p className="mt-1 text-xs leading-5 text-violet-700">{order.refund.reason}</p>
                      </div>
                      <span className="w-fit rounded-full bg-white px-3 py-1 text-[10px] font-black uppercase tracking-wide text-violet-700 shadow-sm">
                        {refundStage(order.refund)}
                      </span>
                    </div>

                    {order.refund.status_message && (
                      <p className="mt-3 rounded-xl border border-violet-200 bg-white/80 p-3 text-xs font-semibold leading-5 text-violet-900">{order.refund.status_message}</p>
                    )}

                    {order.refund.timeline?.length ? (
                      <RefundTimeline steps={order.refund.timeline} />
                    ) : (
                      <dl className="mt-3 grid gap-2 rounded-xl border border-violet-200 bg-white/80 p-3 text-xs sm:grid-cols-3">
                        <div><dt className="font-bold text-slate-400">Diajukan</dt><dd className="mt-1 font-black text-slate-700">{dateTime(order.refund.created_at)}</dd></div>
                        <div><dt className="font-bold text-slate-400">Dikirim</dt><dd className="mt-1 font-black text-slate-700">{dateTime(order.refund.gateway_submitted_at)}</dd></div>
                        <div><dt className="font-bold text-slate-400">Diperbarui</dt><dd className="mt-1 font-black text-slate-700">{dateTime(order.refund.last_reconciled_at || order.refund.gateway_processed_at || order.refund.updated_at)}</dd></div>
                      </dl>
                    )}
                    <p className="mt-2 text-[10px] font-bold text-slate-400">Terakhir diperiksa {dateTime(order.refund.last_reconciled_at || order.refund.updated_at)}</p>

                    {order.refund.can_switch_to_balance && (
                      <div className="mt-3 rounded-xl border border-rose-200 bg-rose-50 p-3">
                        <p className="text-xs font-bold leading-5 text-rose-700">{order.refund.gateway_failure_message || "Pengembalian ke metode pembayaran asal gagal."}</p>
                        <Button
                          type="button"
                          size="sm"
                          onClick={() => setRefundChoice({ refund: order.refund!, method: "bimbelku_balance" })}
                          disabled={refundSubmitting === order.refund.id}
                          className="mt-2 min-h-11 w-full rounded-xl bg-rose-600 text-left hover:bg-rose-700 sm:w-auto"
                        >
                          {refundSubmitting === order.refund.id ? <Loader2 size={16} className="mr-2 shrink-0 animate-spin" /> : <WalletCards size={16} className="mr-2 shrink-0" />}
                          Pindahkan ke Saldo BimbelKu
                        </Button>
                        <p className="mt-2 rounded-lg bg-white/80 px-2 py-1.5 text-[11px] font-medium leading-4 text-slate-600">
                          Saldo BimbelKu dapat langsung digunakan untuk pembelian paket les berikutnya, namun tidak dapat ditarik kembali (non-cashable) ke rekening bank/e-wallet.
                        </p>
                      </div>
                    )}

                    {walletRefundAmount > 0 && (
                      <div className="mt-3 rounded-xl border border-indigo-100 bg-indigo-50/80 p-3 text-xs leading-5 text-indigo-950">
                        <p className="font-black">Sumber pembayaran refund</p>
                        {canBankRefund ? (
                          <p className="mt-1">{rupiah(walletRefundAmount)} berasal dari Saldo BimbelKu dan <strong>selalu kembali ke saldo</strong>. Hanya {rupiah(externalRefundAmount)} yang berasal dari transfer dan dapat dikembalikan ke rekening/e-wallet.</p>
                        ) : (
                          <p className="mt-1">Seluruh {rupiah(walletRefundAmount)} berasal dari Saldo BimbelKu, jadi refund wajib kembali ke Saldo BimbelKu dan tidak dapat dicairkan ke rekening/e-wallet.</p>
                        )}
                      </div>
                    )}

                    {order.refund.status === "pending" && !order.refund.destination_selected_at && (
                      <div className="mt-4 rounded-xl border border-violet-200 bg-white p-3">
                        <p className="font-black text-slate-900">Mau menerima refund ke mana?</p>
                        <p className="mt-1 text-xs leading-5 text-slate-500">Pilih dulu lalu konfirmasi. Saldo BimbelKu tidak dapat ditarik tunai. Bagian pembayaran yang berasal dari saldo selalu kembali ke saldo.</p>
                        <div className="mt-3 grid gap-2 sm:grid-cols-2">
                          <div className="min-w-0">
                            <Button
                              type="button"
                              onClick={() => setRefundChoice({ refund: order.refund!, method: "bimbelku_balance" })}
                              disabled={refundSubmitting === order.refund.id}
                              className="min-h-12 w-full justify-start rounded-xl bg-indigo-600 px-3 text-left hover:bg-indigo-700"
                            >
                              {refundSubmitting === order.refund.id ? <Loader2 size={16} className="mr-2 shrink-0 animate-spin" /> : <WalletCards size={16} className="mr-2 shrink-0" />}
                              <span><span className="block font-black">Saldo BimbelKu</span><span className="block text-[10px] font-medium text-indigo-100">Lebih cepat · untuk bayar belajar · tidak bisa ditarik</span></span>
                            </Button>
                            <p className="mt-1.5 rounded-lg bg-indigo-50 px-2 py-1.5 text-[11px] font-medium leading-4 text-indigo-900">
                              Saldo BimbelKu dapat langsung digunakan untuk pembelian paket les berikutnya, namun tidak dapat ditarik kembali (non-cashable) ke rekening bank/e-wallet.
                            </p>
                          </div>
                          <Button
                            type="button"
                            variant="outline"
                            onClick={() => order.payment_provider === "xendit" ? setRefundChoice({ refund: order.refund!, method: "xendit_original" }) : openBankRefund(order.refund!)}
                            disabled={!canBankRefund}
                            className="min-h-12 justify-start rounded-xl border-violet-200 bg-white px-3 text-left text-violet-900 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400"
                          >
                            <Building2 size={16} className="mr-2 shrink-0" />
                            <span><span className="block font-black">{order.payment_provider === "xendit" ? "Metode pembayaran asal" : "Rekening / e-wallet"}</span><span className="block text-[10px] font-medium">{canBankRefund ? (order.payment_provider === "xendit" ? `${rupiah(externalRefundAmount)} kembali lewat penyedia pembayaran` : `Admin transfer ${rupiah(externalRefundAmount)} ke data yang kamu isi`) : "Tidak tersedia untuk pembayaran 100% saldo"}</span></span>
                          </Button>
                        </div>
                      </div>
                    )}

                    {order.refund.destination_selected_at && (
                      <div className="mt-3 rounded-xl border border-violet-200 bg-white/80 p-3">
                        <div className="flex items-start gap-2">
                          <ShieldCheck size={17} className="mt-0.5 shrink-0 text-violet-600" />
                          <div className="min-w-0">
                            <p className="text-xs font-black text-slate-900">Tujuan refund tersimpan</p>
                            {order.refund.destination_method === "bimbelku_balance" ? (
                              <p className="mt-1 text-xs leading-5 text-slate-600">Saldo BimbelKu · dapat dipakai untuk Paket Belajar/Kelas Bersama dan tidak dapat ditarik kembali (non-cashable) ke rekening bank/e-wallet.</p>
                            ) : order.refund.destination_method === "xendit_original" ? (
                              <p className="mt-1 text-xs leading-5 text-slate-600">Dana eksternal akan dikembalikan otomatis ke metode pembayaran asal. Bagian Saldo BimbelKu otomatis kembali ke saldo.</p>
                            ) : (
                              <><p className="mt-1 break-words text-xs leading-5 text-slate-600">{order.refund.destination_bank_name} · {order.refund.destination_account_number} · a.n. {order.refund.destination_account_name}</p>{walletRefundAmount > 0 && <p className="mt-1 text-xs leading-5 text-indigo-700">{rupiah(walletRefundAmount)} kembali ke Saldo BimbelKu · admin mentransfer {rupiah(externalRefundAmount)}.</p>}</>
                            )}
                          </div>
                        </div>
                      </div>
                    )}
                  </div>
                )}
                <div className="mt-4 flex flex-wrap gap-2">
                  {["pending", "rejected"].includes(order.status) && (
                    <Button
                      size="sm"
                      className="min-h-11 flex-1 rounded-xl bg-orange-500 hover:bg-orange-600 sm:flex-none"
                      onClick={() => navigate("/payment", { state: {
                        orderId: order.id,
                        invoiceId: order.order_id,
                        tutorName: order.tutor_name,
                        subject: order.subject,
                        type: order.type,
                        price: Number(order.amount),
                        date: order.schedule,
                        paymentDueAt: order.payment_due_at,
                        rejectionReason: order.payment_rejection_reason,
                        durationHours: Number(order.duration_hours || 1),
                        totalLearningHours: Number(order.total_learning_hours || 0),
                        orderKind: order.order_kind,
                        packageName: order.package_name || undefined,
                        subtotalAmount: Number(order.subtotal_amount || order.amount),
                        discountAmount: Number(order.discount_amount || 0),
                        enrollmentStatus: order.enrollment_status,
                        cheapClassStatus: order.cheap_class_status,
                        cheapClassCancellationReason: order.cheap_class_cancellation_reason,
                      } })}
                    >
                      <CreditCard size={15} className="mr-2" />{order.status === "rejected" ? "Unggah ulang bukti" : "Bayar sekarang"}
                    </Button>
                  )}
                  {order.payment_proof_url && (
                    <Button
                      variant="outline"
                      size="sm"
                      className="rounded-xl"
                      onClick={() => setProof(order.payment_proof_url!)}
                    >
                      <CreditCard size={15} className="mr-2" />Bukti pembayaran
                    </Button>
                  )}
                  {order.refund?.proof_url && (
                    <Button
                      variant="outline"
                      size="sm"
                      className="rounded-xl"
                      onClick={() => setProof(order.refund!.proof_url!)}
                    >
                      <RotateCcw size={15} className="mr-2" />Bukti refund
                    </Button>
                  )}
                </div>
              </article>;
            })}
          </div>
        )}
      </div>
      {refundChoice && <div role="presentation" className="fixed inset-0 z-[var(--layer-modal)] grid place-items-center bg-slate-950/70 p-3 backdrop-blur-sm" onClick={() => setRefundChoice(null)}><div role="dialog" aria-modal="true" aria-labelledby="refund-choice-title" className="w-full max-w-md rounded-[1.5rem] bg-white p-5 shadow-2xl sm:p-6" onClick={(event) => event.stopPropagation()}><h2 id="refund-choice-title" className="text-xl font-black text-slate-950">Konfirmasi tujuan refund</h2><p className="mt-3 text-sm leading-6 text-slate-600">{refundChoice.method === "bimbelku_balance" ? `Refund ${rupiah(refundChoice.refund.amount)} akan masuk ke Saldo BimbelKu dan dapat langsung dipakai untuk paket les berikutnya. Saldo tidak dapat ditarik kembali (non-cashable) ke rekening bank/e-wallet.` : `Bagian pembayaran eksternal akan dikembalikan ke metode pembayaran asal. Bagian yang dibayar dari Saldo BimbelKu tetap kembali ke saldo.`}</p><p className="mt-3 rounded-xl bg-amber-50 p-3 text-xs font-semibold leading-5 text-amber-900">Pastikan pilihanmu benar. Setelah pengembalian mulai diproses, tujuan tidak dapat diubah.</p><div className="mt-5 flex flex-col gap-2 sm:flex-row"><Button type="button" variant="outline" className="min-h-12 flex-1 rounded-xl" onClick={() => setRefundChoice(null)} disabled={refundSubmitting !== null}>Kembali</Button><Button type="button" className="min-h-12 flex-1 rounded-xl bg-indigo-600" onClick={() => void confirmRefundChoice()} disabled={refundSubmitting !== null}>{refundSubmitting !== null && <Loader2 size={16} className="mr-2 animate-spin" />}Konfirmasi tujuan</Button></div></div></div>}
      {refundBank && (
        <div role="presentation" className="fixed inset-0 z-[var(--layer-modal)] grid place-items-center bg-slate-950/70 p-3 backdrop-blur-sm" onClick={() => setRefundBank(null)}>
          <div role="dialog" aria-modal="true" aria-labelledby="refund-bank-title" className="max-h-[92dvh] w-full max-w-lg overflow-y-auto rounded-[1.5rem] bg-white p-4 shadow-2xl sm:rounded-[2rem] sm:p-6" onClick={(event) => event.stopPropagation()}>
            <div className="flex items-start justify-between gap-4">
              <div>
                <p id="refund-bank-title" className="text-lg font-black text-slate-950">Tujuan refund rekening/e-wallet</p>
                <p className="mt-1 text-xs leading-5 text-slate-500">Pastikan data milik kamu benar. Admin hanya mentransfer bagian pembayaran eksternal; bagian yang berasal dari Saldo BimbelKu otomatis kembali ke saldo.</p>
              </div>
              <button type="button" aria-label="Tutup" onClick={() => setRefundBank(null)} className="grid h-10 w-10 shrink-0 place-items-center rounded-xl text-slate-500 hover:bg-slate-100"><X size={18} /></button>
            </div>
            {(refundBank.wallet_funded_amount || 0) > 0 && (
              <div className="mt-4 rounded-xl border border-indigo-100 bg-indigo-50 p-3 text-xs leading-5 text-indigo-900">
                {rupiah(refundBank.wallet_funded_amount || 0)} kembali otomatis ke Saldo BimbelKu. Admin hanya mentransfer {rupiah(refundBank.external_funded_amount ?? refundBank.amount)} ke rekening/e-wallet ini.
              </div>
            )}
            <div className="mt-5 space-y-4">
              <label className="block">
                <span className="text-xs font-black text-slate-700">Bank atau e-wallet</span>
                <Input value={refundBankName} onChange={(event) => setRefundBankName(event.target.value)} placeholder="Contoh: BCA, BRI, DANA" className="mt-2 h-11 rounded-xl" maxLength={100} />
              </label>
              <label className="block">
                <span className="text-xs font-black text-slate-700">Nama pemilik</span>
                <Input value={refundAccountName} onChange={(event) => setRefundAccountName(event.target.value)} placeholder="Nama sesuai rekening/e-wallet" className="mt-2 h-11 rounded-xl" maxLength={150} />
              </label>
              <label className="block">
                <span className="text-xs font-black text-slate-700">Nomor rekening / e-wallet</span>
                <Input inputMode="numeric" value={refundAccountNumber} onChange={(event) => setRefundAccountNumber(event.target.value.replace(/\D/g, ""))} placeholder="Hanya angka" className="mt-2 h-11 rounded-xl" maxLength={20} />
              </label>
              <div className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-800">Tujuan dapat diubah sebelum refund dikirim ke penyedia pembayaran. Setelah dikirim, tujuan terkunci. Jika pengembalian ke metode asal gagal, kamu tetap dapat memilih Saldo BimbelKu.</div>
              <Button type="button" className="min-h-12 w-full rounded-xl bg-slate-950" disabled={refundSubmitting === refundBank.id} onClick={() => void submitBankRefund()}>
                {refundSubmitting === refundBank.id && <Loader2 size={16} className="mr-2 animate-spin" />}Simpan tujuan refund
              </Button>
            </div>
          </div>
        </div>
      )}
      {proof && <div role="presentation" className="fixed inset-0 z-[var(--layer-detail)] grid place-items-center bg-slate-950/75 p-3 backdrop-blur-sm sm:p-4" onClick={() => setProof(null)}><div role="dialog" aria-modal="true" aria-label="Detail transaksi" className="max-h-[calc(100dvh-1.5rem)] w-full max-w-xl overflow-y-auto rounded-[2rem] bg-white p-3 shadow-2xl" onClick={(event) => event.stopPropagation()}><ProtectedImage source={proof} alt="Bukti transaksi" className="max-h-[78dvh] w-full rounded-2xl object-contain" /><Button variant="ghost" className="mt-2 w-full rounded-xl" onClick={() => setProof(null)}>Tutup</Button></div></div>}
    </StudentLayout>
  );
}

function ErrorState({ type, onRetry }: { type: ErrorType; onRetry: () => void }) {
  const config = {
    network: { icon: WifiOff, color: "text-slate-500", bg: "bg-slate-50", border: "border-slate-200", title: "Tidak ada koneksi", desc: "Periksa koneksi internet kamu, lalu coba lagi." },
    unauthorized: { icon: AlertCircle, color: "text-orange-600", bg: "bg-orange-50", border: "border-orange-200", title: "Sesi berakhir", desc: "Silakan masuk kembali ke akun kamu." },
    forbidden: { icon: AlertCircle, color: "text-amber-600", bg: "bg-amber-50", border: "border-amber-200", title: "Akses ditolak", desc: "Kamu tidak memiliki izin untuk halaman ini." },
    not_found: { icon: AlertCircle, color: "text-slate-500", bg: "bg-slate-50", border: "border-slate-200", title: "Tidak ditemukan", desc: "Data yang kamu cari tidak tersedia." },
    generic: { icon: AlertCircle, color: "text-rose-600", bg: "bg-rose-50", border: "border-rose-200", title: "Terjadi kesalahan", desc: "Gagal memuat data. Silakan coba lagi." },
  };
  if (!type) return null;
  const { icon: Icon, color, bg, border, title, desc } = config[type];
  return (
    <div className={`flex flex-col items-center justify-center gap-4 rounded-3xl border-2 ${border} ${bg} px-6 py-16 text-center`}>
      <Icon size={40} className={color} />
      <div>
        <p className={`text-lg font-black ${color}`}>{title}</p>
        <p className="mt-1 text-sm text-slate-500">{desc}</p>
      </div>
      <button onClick={onRetry} className="inline-flex items-center gap-2 rounded-xl bg-white px-5 py-2.5 text-sm font-black text-slate-700 shadow-sm border border-slate-200 hover:bg-slate-50 transition">
        <RefreshCw size={15} /> Coba lagi
      </button>
    </div>
  );
}
