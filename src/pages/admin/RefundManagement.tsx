import { notify } from "@/lib/notify";
import { useCallback, useEffect, useMemo, useState } from "react";
import {
  ArrowDownToLine,
  CheckCircle2,
  Clock3,
  CreditCard,
  FileText,
  Landmark,
  Loader2,
  RefreshCw,
  Search,
  WalletCards,
  X,
  XCircle,
} from "lucide-react";
import AdminLayout from "@/components/AdminLayout";
import { useConfirmDialog } from "@/components/ConfirmDialogProvider";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { openProtectedFile } from "@/components/ProtectedImage";
import http, { getApiError } from "@/lib/http";
import { validateUpload } from "@/lib/validation";

type RefundItem = {
  id: number;
  order_id?: string | null;
  student?: { id: number; name: string; email: string } | null;
  subject?: string | null;
  amount: number;
  wallet_funded_amount: number;
  external_funded_amount: number;
  reason: string;
  status: string;
  needs_action?: boolean;
  action_label?: string | null;
  destination_method?: "bank_transfer" | "bimbelku_balance" | "xendit_original" | null;
  payment_provider?: string | null;
  gateway_status?: string | null;
  gateway_failure_code?: string | null;
  gateway_failure_message?: string | null;
  gateway_refund_id?: string | null;
  gateway_submitted_at?: string | null;
  gateway_processed_at?: string | null;
  last_reconciled_at?: string | null;
  stage?: string | null;
  stage_label?: string | null;
  status_message?: string | null;
  destination_selected_at?: string | null;
  destination_selection_version?: number;
  bank_destination: {
    bank_name?: string | null;
    account_name?: string | null;
    account_number?: string | null;
    is_complete: boolean;
  };
  wallet_balance_after?: number | null;
  proof_url?: string | null;
  notes?: string | null;
  processor?: { id: number; name: string } | null;
  processed_at?: string | null;
  created_at: string;
  updated_at?: string | null;
};

type RefundResponse = {
  summary: {
    pending_count: number;
    pending_amount: number;
    failed_count?: number;
    bank_paid_30_days: number;
    wallet_credited_30_days: number;
    wallet_liability: number;
  };
  pending: RefundItem[];
  history: RefundItem[];
};

const rupiah = (value: number) => new Intl.NumberFormat("id-ID", {
  style: "currency",
  currency: "IDR",
  maximumFractionDigits: 0,
}).format(value || 0);

const dateTime = (value?: string | null) => value
  ? new Date(value).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" })
  : "-";

export default function RefundManagement({ embedded = false }: { embedded?: boolean }) {
  const confirm = useConfirmDialog();
  const [data, setData] = useState<RefundResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [tab, setTab] = useState<"pending" | "history">("pending");
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState("");
  const [selected, setSelected] = useState<RefundItem | null>(null);
  const [proof, setProof] = useState<File | null>(null);
  const [notes, setNotes] = useState("");
  const [processing, setProcessing] = useState(false);

  const automaticAction = async (item: RefundItem, action: "reconcile" | "retry") => {
    setProcessing(true);
    try {
      const response = await http.post(`/admin/refunds/${item.id}/${action}`, {}, {
        headers: action === "retry" ? { "Idempotency-Key": `refund-retry-${item.id}-${item.updated_at || item.gateway_status}` } : undefined,
      });
      notify.success(response.data?.message || "Status refund diperbarui.");
      await load(true);
      window.dispatchEvent(new Event("bimbelku:data-changed"));
    } catch (error) {
      notify.error(getApiError(error, "Status refund belum dapat diperbarui."));
    } finally {
      setProcessing(false);
    }
  };

  const load = useCallback(async (silent = false) => {
    if (!silent) setLoading(true);
    try {
      const response = await http.get<RefundResponse>("/admin/finance/refunds");
      setData(response.data);
    } catch (error) {
      if (!silent) notify.error(getApiError(error, "Data refund tidak dapat dimuat."));
    } finally {
      if (!silent) setLoading(false);
    }
  }, []);

  useEffect(() => {
    const refresh = () => {
      if (document.visibilityState === "visible") void load(true);
    };

    void load();
    const timer = window.setInterval(refresh, 15_000);
    window.addEventListener("focus", refresh);
    document.addEventListener("visibilitychange", refresh);
    window.addEventListener("bimbelku:data-changed", refresh);

    return () => {
      window.clearInterval(timer);
      window.removeEventListener("focus", refresh);
      document.removeEventListener("visibilitychange", refresh);
      window.removeEventListener("bimbelku:data-changed", refresh);
    };
  }, [load]);

  const filteredRows = useMemo(() => {
    const source = tab === "pending" ? data?.pending || [] : data?.history || [];
    const needle = search.trim().toLowerCase();
    if (!needle) return source;
    return source.filter((item) => [item.order_id, item.student?.name, item.student?.email, item.subject, item.reason]
      .filter(Boolean)
      .some((value) => String(value).toLowerCase().includes(needle)));
  }, [data, search, tab]);
  const pendingActionCount = data?.pending.filter((item) => item.needs_action).length || 0;

  useEffect(() => setPage(1), [search, tab]);
  const lastPage = Math.max(1, Math.ceil(filteredRows.length / 25));
  const rows = filteredRows.slice((page - 1) * 25, page * 25);

  const openProcess = (item: RefundItem) => {
    if (!item.destination_method || !item.destination_selected_at) {
      notify.warning("Murid belum memilih tujuan refund.");
      return;
    }
    setSelected(item);
    setProof(null);
    setNotes("");
  };

  const chooseProof = (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    if (!file) return;
    const error = validateUpload(file, {
      label: "Bukti refund",
      maxSizeMb: 5,
      extensions: ["jpg", "jpeg", "png", "webp"],
    });
    if (error) {
      notify.error(error);
      event.target.value = "";
      return;
    }
    setProof(file);
  };

  const complete = async () => {
    if (!selected?.destination_method || !selected.destination_selection_version) {
      notify.warning("Pilihan tujuan refund perlu dimuat ulang sebelum diproses.");
      await load(true);
      return;
    }
    const bankTransfer = selected.destination_method === "bank_transfer";
    const xenditRefund = selected.destination_method === "xendit_original";
    if (bankTransfer && !selected.bank_destination.is_complete) {
      notify.error("Tujuan rekening/e-wallet refund belum lengkap.");
      return;
    }
    if (bankTransfer && selected.external_funded_amount > 0.009 && !proof) {
      notify.error("Bukti transfer bagian refund rekening/e-wallet wajib diunggah.");
      return;
    }

    const approved = await confirm({
      title: xenditRefund ? "Kembalikan ke metode pembayaran asal?" : bankTransfer ? "Catat transfer refund?" : "Masukkan refund ke Saldo BimbelKu?",
      description: xenditRefund
        ? `${rupiah(selected.external_funded_amount)} akan dikembalikan ke metode pembayaran asal. Status akhir diperbarui oleh sistem.`
        : bankTransfer
        ? selected.wallet_funded_amount > 0.009
          ? `Pastikan ${rupiah(selected.external_funded_amount)} sudah ditransfer ke rekening/e-wallet. ${rupiah(selected.wallet_funded_amount)} yang semula dibayar dari Saldo BimbelKu akan otomatis kembali ke saldo murid.`
          : `Pastikan ${rupiah(selected.external_funded_amount)} sudah ditransfer ke rekening/e-wallet yang dipilih murid.`
        : `${rupiah(selected.amount)} akan masuk ke Saldo BimbelKu sesuai pilihan murid dan tercatat sebagai mutasi permanen.`,
      confirmText: xenditRefund ? "Proses refund" : bankTransfer ? "Sudah ditransfer" : "Masukkan ke saldo",
      tone: "warning",
    });
    if (!approved) return;

    const form = new FormData();
    form.append("destination_selection_version", String(selected.destination_selection_version));
    if (proof) form.append("proof", proof);
    if (notes.trim()) form.append("notes", notes.trim());

    setProcessing(true);
    try {
      const response = await http.post(`/admin/refunds/${selected.id}/complete`, form);
      notify.success(response.data?.message || "Refund berhasil diselesaikan.");
      setSelected(null);
      await load();
      window.dispatchEvent(new Event("bimbelku:data-changed"));
    } catch (error) {
      notify.error(getApiError(error, "Refund tidak dapat diproses."));
      await load(true);
    } finally {
      setProcessing(false);
    }
  };

  return (
    <FinanceSectionFrame embedded={embedded} title="Refund & Saldo BimbelKu" subtitle="Refund otomatis dipantau sistem; admin menangani kegagalan dan refund manual">
      <div className="space-y-6 pb-16">
        <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
          <Metric icon={Clock3} label="Menunggu" value={String(data?.summary.pending_count || 0)} detail={rupiah(data?.summary.pending_amount || 0)} tone="amber" />
          <Metric icon={XCircle} label="Gagal" value={String(data?.summary.failed_count || 0)} detail="Perlu coba ulang atau pemeriksaan" tone="rose" />
          <Metric icon={Landmark} label="Transfer 30 hari" value={rupiah(data?.summary.bank_paid_30_days || 0)} detail="Hanya bagian yang semula dibayar lewat transfer" tone="emerald" />
          <Metric icon={WalletCards} label="Kredit saldo 30 hari" value={rupiah(data?.summary.wallet_credited_30_days || 0)} detail="Termasuk pengembalian bagian saldo dari refund campuran" tone="indigo" />
          <Metric icon={CreditCard} label="Total kewajiban saldo" value={rupiah(data?.summary.wallet_liability || 0)} detail="Saldo tersedia + saldo tertahan" tone="slate" />
          <Metric icon={CheckCircle2} label="Aturan nominal" value="Refund penuh" detail="Admin tidak dapat mengganti nominal atau tujuan" tone="rose" />
        </section>

        <section className="overflow-hidden rounded-[1.75rem] border border-slate-200 bg-white shadow-sm">
          <div className="flex flex-col gap-4 border-b border-slate-100 p-4 sm:p-5 lg:flex-row lg:items-center lg:justify-between">
            <div className="flex rounded-xl bg-slate-100 p-1">
              <button type="button" onClick={() => setTab("pending")} className={`flex min-h-10 items-center gap-2 rounded-lg px-4 text-sm font-black ${tab === "pending" ? "bg-white text-slate-950 shadow-sm" : "text-slate-500"}`}>Perlu diproses ({data?.pending.length || 0}){pendingActionCount > 0 && <span className="inline-flex items-center gap-1 rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-black text-rose-700" aria-label={`${pendingActionCount} refund perlu tindakan`}><span className="h-2 w-2 rounded-full bg-rose-600" />{pendingActionCount}</span>}</button>
              <button type="button" onClick={() => setTab("history")} className={`min-h-10 rounded-lg px-4 text-sm font-black ${tab === "history" ? "bg-white text-slate-950 shadow-sm" : "text-slate-500"}`}>Riwayat</button>
            </div>
            <div className="flex flex-col gap-2 sm:flex-row">
              <label className="relative sm:w-72"><Search className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={16} /><Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Cari invoice atau murid" className="h-11 rounded-xl pl-9" /></label>
              <Button variant="outline" onClick={() => void load()} disabled={loading} className="h-11 rounded-xl"><RefreshCw size={16} className={loading ? "mr-2 animate-spin" : "mr-2"} />Muat ulang</Button>
            </div>
          </div>

          {loading ? (
            <div className="grid min-h-72 place-items-center"><Loader2 className="animate-spin text-indigo-600" /></div>
          ) : rows.length === 0 ? (
            <div className="grid min-h-72 place-items-center p-8 text-center"><div><CheckCircle2 className="mx-auto text-emerald-500" size={40} /><p className="mt-3 font-black text-slate-800">Tidak ada refund pada tampilan ini.</p></div></div>
          ) : (
            <div className="divide-y divide-slate-100">
              {rows.map((item) => (
                <article key={item.id} className={`grid gap-4 p-4 sm:p-5 xl:grid-cols-[1fr_.9fr_.8fr_auto] xl:items-center ${tab === "pending" && item.needs_action ? "border-l-4 border-l-rose-500 bg-rose-50/30" : ""}`}>
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2"><p className="font-black text-slate-950">{item.student?.name || "Murid"}</p><span className="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-black text-slate-500">{item.order_id || `Refund #${item.id}`}</span>{tab === "pending" && item.needs_action && <span className="inline-flex items-center gap-1.5 rounded-full bg-rose-100 px-2.5 py-1 text-[11px] font-black text-rose-700"><span className="h-2 w-2 rounded-full bg-rose-600" />{item.action_label || "Perlu tindakan"}</span>}</div>
                    <p className="mt-1 truncate text-sm font-semibold text-slate-600">{item.subject || "Pesanan belajar"}</p>
                    <p className="mt-2 text-xs leading-5 text-slate-500">{item.reason}</p>
                  </div>
                  <div>
                    <p className="text-xs font-black uppercase tracking-wide text-slate-400">Nominal penuh</p>
                    <p className="mt-1 text-lg font-black text-rose-700">{rupiah(item.amount)}</p>
                    {item.wallet_funded_amount > 0 && <p className="mt-1 text-xs leading-5 text-indigo-600">Asal pembayaran: {rupiah(item.wallet_funded_amount)} saldo + {rupiah(item.external_funded_amount)} transfer.</p>}
                    <p className="mt-1 text-xs text-slate-400">Dibuat {dateTime(item.created_at)}</p>
                  </div>
                  <div>
                    <p className="text-xs font-black uppercase tracking-wide text-slate-400">Pilihan murid</p>
                    {!item.destination_method || !item.destination_selected_at ? (
                      <><p className="mt-1 text-sm font-black text-amber-700">Belum dipilih</p><p className="mt-1 text-xs text-slate-500">Admin menunggu murid memilih tujuan refund.</p></>
                    ) : item.destination_method === "bimbelku_balance" ? (
                      <><p className="mt-1 text-sm font-black text-indigo-700">Saldo BimbelKu</p><p className="mt-1 text-xs text-slate-500">Tidak dapat ditarik tunai.</p></>
                    ) : item.destination_method === "xendit_original" ? (
                      <><p className="mt-1 text-sm font-black text-sky-700">Metode pembayaran asal</p><p className="mt-1 text-xs text-slate-500">Diproses otomatis oleh sistem{item.gateway_status ? ` · ${item.gateway_status}` : ""}.</p><p className="mt-1 break-all text-[11px] text-slate-400">Ref: {item.gateway_refund_id || "belum diterima"}</p><p className="mt-1 text-[11px] text-slate-400">Dikirim {dateTime(item.gateway_submitted_at)} · diperiksa {dateTime(item.last_reconciled_at)}</p>{item.status_message && <p className={`mt-1 text-xs leading-5 ${["failed", "needs_review"].includes(item.stage || "") ? "font-bold text-rose-700" : "text-slate-500"}`}>{item.status_message}</p>}</>
                    ) : (
                      <><p className="mt-1 text-sm font-bold text-slate-700">{item.bank_destination.bank_name || "Rekening/e-wallet"}</p><p className="mt-1 break-all text-xs text-slate-500">{item.bank_destination.account_name || "-"} · {item.bank_destination.account_number || "-"}</p>{item.wallet_funded_amount > 0 && <p className="mt-1 text-xs font-bold leading-5 text-indigo-600">Admin transfer {rupiah(item.external_funded_amount)} · {rupiah(item.wallet_funded_amount)} otomatis kembali ke saldo.</p>}</>
                    )}
                    {tab === "history" && <><p className="mt-2 text-xs text-slate-400">{item.processor?.name || "Admin"} · {dateTime(item.processed_at)}</p>{item.destination_method === "bimbelku_balance" && item.wallet_balance_after !== null && item.wallet_balance_after !== undefined && <p className="mt-1 text-xs font-bold text-indigo-600">Saldo setelah refund: {rupiah(item.wallet_balance_after)}</p>}</>}
                  </div>
                  <div className="flex flex-wrap gap-2 xl:justify-end">
                    {tab === "pending" ? item.destination_method === "xendit_original" ? (
                      <Button disabled={processing} onClick={() => void automaticAction(item, ["FAILED", "REJECTED", "SUBMITTING"].includes(item.gateway_status || "") ? "retry" : "reconcile")} className={`h-10 rounded-xl ${["FAILED", "REJECTED"].includes(item.gateway_status || "") ? "bg-rose-700 hover:bg-rose-800" : "bg-sky-700 hover:bg-sky-800"}`}><RefreshCw size={15} className="mr-2" />{["FAILED", "REJECTED", "SUBMITTING"].includes(item.gateway_status || "") ? "Coba ulang" : "Periksa status"}</Button>
                    ) : (
                      <Button disabled={!item.destination_method || !item.destination_selected_at} onClick={() => openProcess(item)} className="h-10 rounded-xl bg-slate-950 disabled:bg-slate-300"><ArrowDownToLine size={15} className="mr-2" />{item.destination_method && item.destination_selected_at ? "Proses refund manual" : "Menunggu murid"}</Button>
                    ) : (
                      <>{item.proof_url && <Button variant="outline" className="h-10 rounded-xl" onClick={() => void openProtectedFile(item.proof_url!, `bukti-refund-${item.id}`).catch(() => notify.error("Bukti refund tidak dapat dibuka."))}><FileText size={15} className="mr-2" />Bukti</Button>}<span className={`inline-flex h-10 items-center rounded-xl px-3 text-xs font-black ${item.destination_method === "bimbelku_balance" ? "bg-indigo-50 text-indigo-700" : "bg-emerald-50 text-emerald-700"}`}>{item.destination_method === "bimbelku_balance" ? "Masuk saldo" : "Refund selesai"}</span></>
                    )}
                  </div>
                </article>
              ))}
            </div>
          )}
          {!loading && filteredRows.length > 0 && <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-4 py-3 text-sm"><span className="text-slate-500">Maksimal 25 data per halaman · total {filteredRows.length}</span><div className="flex items-center gap-2"><button type="button" disabled={page <= 1} onClick={() => setPage((value) => Math.max(1, value - 1))} className="rounded-lg border px-3 py-2 font-bold disabled:opacity-40">Sebelumnya</button><span className="font-black">{page} / {lastPage}</span><button type="button" disabled={page >= lastPage} onClick={() => setPage((value) => Math.min(lastPage, value + 1))} className="rounded-lg border px-3 py-2 font-bold disabled:opacity-40">Berikutnya</button></div></div>}
        </section>
      </div>

      {selected && (
        <div className="fixed inset-0 z-[var(--layer-detail)] grid place-items-center bg-slate-950/60 p-3 backdrop-blur-sm sm:p-4">
          <div role="dialog" aria-modal="true" className="max-h-[92dvh] w-full max-w-2xl overflow-y-auto rounded-[1.75rem] bg-white p-5 shadow-2xl sm:p-6">
            <div className="grid grid-cols-[2.5rem_minmax(0,1fr)_2.5rem] items-start gap-2">
              <span aria-hidden="true" className="h-10 w-10" />
              <div className="min-w-0 text-center"><p className="break-words text-lg font-black text-slate-950">Selesaikan refund {selected.order_id}</p><p className="mt-1 text-sm text-slate-500">Nominal terkunci: <strong>{rupiah(selected.amount)}</strong></p></div>
              <button type="button" aria-label="Tutup" onClick={() => setSelected(null)} className="grid h-10 w-10 place-items-center rounded-xl hover:bg-slate-100"><X size={18} /></button>
            </div>

            <div className={`mt-5 rounded-2xl border p-4 ${selected.destination_method === "bimbelku_balance" ? "border-indigo-200 bg-indigo-50" : "border-emerald-200 bg-emerald-50"}`}>
              <div className="flex gap-3">
                {selected.destination_method === "bimbelku_balance" ? <WalletCards className="shrink-0 text-indigo-700" /> : <Landmark className="shrink-0 text-emerald-700" />}
                <div><p className="font-black text-slate-950">Tujuan refund: {selected.destination_method === "bimbelku_balance" ? "Saldo BimbelKu" : selected.destination_method === "xendit_original" ? "Metode pembayaran asal" : "Rekening/e-wallet"}</p><p className="mt-1 text-xs leading-5 text-slate-600">Admin tidak dapat mengganti sumber tujuan refund. Bagian yang semula dibayar dari Saldo BimbelKu selalu kembali ke saldo.</p></div>
              </div>
            </div>

            {selected.destination_method === "bank_transfer" && (
              <div className="mt-5 rounded-2xl bg-slate-50 p-4">
                <p className="text-xs font-black uppercase tracking-wide text-slate-400">Tujuan transfer eksternal</p>
                <p className="mt-2 text-lg font-black text-emerald-700">{rupiah(selected.external_funded_amount)}</p>
                {selected.wallet_funded_amount > 0 && <p className="mt-1 text-xs leading-5 text-indigo-700">{rupiah(selected.wallet_funded_amount)} otomatis kembali ke Saldo BimbelKu. Jangan ikut ditransfer ke rekening.</p>}
                <p className="mt-3 font-black text-slate-900">{selected.bank_destination.bank_name || "Belum lengkap"}</p>
                <p className="mt-1 break-all text-sm text-slate-600">{selected.bank_destination.account_name || "-"} · {selected.bank_destination.account_number || "-"}</p>
                <label className="mt-4 block text-xs font-black text-slate-600">Bukti transfer {rupiah(selected.external_funded_amount)}<input type="file" accept=".jpg,.jpeg,.png,.webp" onChange={chooseProof} className="mt-2 block w-full text-sm text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-white file:px-3 file:py-2 file:text-xs file:font-black file:text-indigo-700" /></label>
              </div>
            )}

            <label className="mt-5 block text-xs font-black text-slate-600">Catatan admin<textarea value={notes} onChange={(event) => setNotes(event.target.value)} maxLength={1000} className="mt-2 min-h-24 w-full rounded-xl border border-slate-200 p-3 text-sm outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100" placeholder="Opsional: nomor referensi transfer atau catatan pemeriksaan" /></label>
            <div className="mt-5 flex justify-end gap-2"><Button variant="outline" onClick={() => setSelected(null)} className="rounded-xl">Batal</Button><Button onClick={() => void complete()} disabled={processing} className="rounded-xl bg-slate-950">{processing && <Loader2 size={15} className="mr-2 animate-spin" />}{selected.destination_method === "bimbelku_balance" ? "Masukkan ke saldo" : selected.destination_method === "xendit_original" ? "Proses refund" : `Catat transfer ${rupiah(selected.external_funded_amount)}`}</Button></div>
          </div>
        </div>
      )}
    </FinanceSectionFrame>
  );
}

function FinanceSectionFrame({ embedded, title, subtitle, children }: { embedded: boolean; title: string; subtitle: string; children: React.ReactNode }) {
  return embedded ? <>{children}</> : <AdminLayout title={title} subtitle={subtitle}>{children}</AdminLayout>;
}

function Metric({ icon: Icon, label, value, detail, tone }: { icon: typeof CreditCard; label: string; value: string; detail: string; tone: "amber" | "emerald" | "indigo" | "slate" | "rose" }) {
  const styles = { amber: "bg-amber-50 text-amber-700", emerald: "bg-emerald-50 text-emerald-700", indigo: "bg-indigo-50 text-indigo-700", slate: "bg-slate-100 text-slate-700", rose: "bg-rose-50 text-rose-700" }[tone];
  return <article className="rounded-[1.5rem] border border-slate-100 bg-white p-5 shadow-sm"><span className={`grid h-11 w-11 place-items-center rounded-2xl ${styles}`}><Icon size={20} /></span><p className="mt-4 text-xs font-black uppercase tracking-wide text-slate-400">{label}</p><p className="mt-1 break-words text-xl font-black text-slate-950">{value}</p><p className="mt-1 text-xs leading-5 text-slate-500">{detail}</p></article>;
}
