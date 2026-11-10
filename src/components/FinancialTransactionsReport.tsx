import { useCallback, useEffect, useState } from "react";
import { Check, Copy, FileText, Loader2, RefreshCw, Search } from "lucide-react";
import http, { getApiError } from "@/lib/http";
import { notify } from "@/lib/notify";
import { showFilePreview } from "@/lib/filePreview";

type Entry = { id: number; account_code: string; side: "debit" | "credit"; amount: string; currency: string };
type Row = {
  id: number;
  uuid: string;
  event_type: string;
  reference_type?: string | null;
  reference_id?: number | null;
  reference_label?: string | null;
  user_name?: string | null;
  user_email?: string | null;
  external_references?: Array<{ label: string; value: string }>;
  payment_channel?: string | null;
  description: string;
  occurred_at: string;
  total_debit: string | number;
  entries: Entry[];
};
type Report = {
  summary: { payments_received: number; platform_revenue: number; refunds_completed: number; payouts_completed: number; tutor_payable_balance: number };
  event_types: string[];
  account_codes: string[];
  data: Row[];
  meta: { current_page: number; last_page: number; per_page: number; total: number; from?: number | null; to?: number | null };
};
type Filters = { q: string; event_type: string; account_code: string; date_from: string; date_to: string; min_amount: string; max_amount: string; sort: string; per_page: string; page: number };

const money = (value: number | string) => new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(Number(value || 0));
const dateTime = (value: string) => new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Jakarta" }).format(new Date(value));
const start = new Date();
start.setDate(1);
const iso = (value: Date) => value.toISOString().slice(0, 10);
const initial: Filters = { q: "", event_type: "", account_code: "", date_from: iso(start), date_to: iso(new Date()), min_amount: "", max_amount: "", sort: "newest", per_page: "25", page: 1 };

export default function FinancialTransactionsReport() {
  const [filters, setFilters] = useState<Filters>(initial);
  const [applied, setApplied] = useState<Filters>(initial);
  const [data, setData] = useState<Report | null>(null);
  const [loading, setLoading] = useState(true);
  const [exporting, setExporting] = useState(false);

  const paramsFor = (value: Filters) => {
    const params = new URLSearchParams();
    Object.entries(value).forEach(([key, item]) => { if (item !== "") params.set(key, String(item)); });
    return params;
  };

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const response = await http.get<Report>("/admin/finance/report?" + paramsFor(applied).toString());
      setData(response.data);
    } catch (error) {
      notify.error(getApiError(error, "Laporan keuangan gagal dimuat."));
    } finally {
      setLoading(false);
    }
  }, [applied]);

  useEffect(() => { void load(); }, [load]);

  const exportPdf = async () => {
    setExporting(true);
    try {
      const params = paramsFor(applied);
      params.delete("page");
      params.delete("per_page");
      const response = await http.get<Blob>("/admin/finance/report.pdf?" + params.toString(), { responseType: "blob", timeout: 120_000 });
      const pdf = new Blob([response.data], { type: "application/pdf" });
      if (!pdf.size || (await pdf.slice(0, 5).text()) !== "%PDF-") {
        throw new Error("Respons laporan bukan berkas PDF. Muat ulang halaman dan coba lagi.");
      }
      const url = URL.createObjectURL(pdf);
      showFilePreview({
        url,
        blob: pdf,
        filename: "laporan-keuangan-bimbelku.pdf",
        contentType: "application/pdf",
        release: () => URL.revokeObjectURL(url),
      });
    } catch (error) {
      notify.error(error instanceof Error && error.message.startsWith("Respons laporan") ? error.message : getApiError(error, "PDF gagal dibuat."));
    } finally {
      setExporting(false);
    }
  };

  const set = (key: keyof Filters, value: string | number) => setFilters((current) => ({ ...current, [key]: value }));
  const summary = data?.summary;

  return <div className="space-y-5">
    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
      {[
        ["Pembayaran diterima", summary?.payments_received, "bg-emerald-50 text-emerald-800"],
        ["Pendapatan admin", summary?.platform_revenue, "bg-indigo-50 text-indigo-800"],
        ["Refund selesai", summary?.refunds_completed, "bg-rose-50 text-rose-800"],
        ["Pencairan tutor", summary?.payouts_completed, "bg-orange-50 text-orange-800"],
        ["Hak tutor tersisa", summary?.tutor_payable_balance, "bg-slate-100 text-slate-800"],
      ].map(([label, value, tone]) => <div key={String(label)} className={"rounded-2xl p-4 " + tone}><p className="text-xs font-black uppercase tracking-wide opacity-70">{label}</p><p className="mt-2 text-xl font-black">{money(Number(value || 0))}</p></div>)}
    </div>

    <form onSubmit={(event) => { event.preventDefault(); setApplied({ ...filters, page: 1 }); }} className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 md:grid-cols-2 xl:grid-cols-4">
      <label className="xl:col-span-2"><span className="text-xs font-black text-slate-600">Cari keterangan / UUID</span><div className="relative mt-1"><Search className="absolute left-3 top-3 text-slate-400" size={17} /><input value={filters.q} onChange={(event) => set("q", event.target.value)} className="h-11 w-full rounded-xl border border-slate-200 pl-10 pr-3 text-sm" placeholder="Cari transaksi…" /></div></label>
      <Filter label="Jenis transaksi"><select value={filters.event_type} onChange={(event) => set("event_type", event.target.value)} className="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800"><option value="">Semua jenis</option>{data?.event_types.map((item) => <option key={item} value={item}>{item.replaceAll("_", " ")}</option>)}</select></Filter>
      <Filter label="Akun buku besar"><select value={filters.account_code} onChange={(event) => set("account_code", event.target.value)} className="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800"><option value="">Semua akun</option>{data?.account_codes.map((item) => <option key={item} value={item}>{item.replaceAll("_", " ")}</option>)}</select></Filter>
      <Filter label="Dari tanggal"><input type="date" value={filters.date_from} onChange={(event) => set("date_from", event.target.value)} className="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800" /></Filter>
      <Filter label="Sampai tanggal"><input type="date" value={filters.date_to} onChange={(event) => set("date_to", event.target.value)} className="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800" /></Filter>
      <Filter label="Nilai minimum"><input type="number" min="0" value={filters.min_amount} onChange={(event) => set("min_amount", event.target.value)} className="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800" placeholder="Rp 0" /></Filter>
      <Filter label="Nilai maksimum"><input type="number" min="0" value={filters.max_amount} onChange={(event) => set("max_amount", event.target.value)} className="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800" placeholder="Tanpa batas" /></Filter>
      <Filter label="Urutan"><select value={filters.sort} onChange={(event) => set("sort", event.target.value)} className="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800"><option value="newest">Terbaru</option><option value="oldest">Terlama</option><option value="amount_desc">Nilai terbesar</option><option value="amount_asc">Nilai terkecil</option></select></Filter>
      <Filter label="Maksimal per halaman"><select value={filters.per_page} onChange={(event) => set("per_page", event.target.value)} className="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800"><option value="25">25 baris</option><option value="50">50 baris</option><option value="100">100 baris</option></select></Filter>
      <div className="flex items-end gap-2"><button className="inline-flex h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-slate-950 px-4 text-sm font-black text-white"><RefreshCw size={16} />Terapkan</button><button type="button" onClick={() => void exportPdf()} disabled={exporting} className="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-slate-300 px-4 text-sm font-black text-slate-700 disabled:opacity-50">{exporting ? <Loader2 className="animate-spin" size={16} /> : <FileText size={16} />}Lihat PDF</button></div>
    </form>

    <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
      <div className="overflow-x-auto">
        <table className="w-full min-w-[1240px] text-left text-sm">
          <thead className="bg-slate-950 text-xs uppercase tracking-wide text-white">
            <tr>
              <th className="px-4 py-3">Waktu</th>
              <th className="px-4 py-3">Jenis / referensi</th>
              <th className="px-4 py-3">Pengguna</th>
              <th className="px-4 py-3">Referensi Xendit</th>
              <th className="px-4 py-3">Keterangan</th>
              <th className="px-4 py-3">Jurnal</th>
              <th className="px-4 py-3 text-right">Nilai</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr><td colSpan={7} className="p-12 text-center"><Loader2 className="mx-auto animate-spin text-orange-600" /></td></tr>
            ) : data?.data.length ? data.data.map((row) => (
              <tr key={row.id} className="border-b border-slate-100 align-top">
                <td className="whitespace-nowrap px-4 py-4 text-xs font-semibold text-slate-600">{dateTime(row.occurred_at)}</td>
                <td className="px-4 py-4">
                  <p className="font-black text-slate-900">{row.event_type.replaceAll("_", " ")}</p>
                  <p className="mt-1 text-xs text-slate-500">{row.reference_label || `#${row.reference_id || "-"}`}</p>
                  {row.payment_channel && <span className="mt-2 inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-black text-slate-600">{row.payment_channel}</span>}
                </td>
                <td className="px-4 py-4">
                  {row.user_name ? (
                    <>
                      <p className="font-bold text-slate-900">{row.user_name}</p>
                      <p className="mt-1 break-all text-xs text-slate-500">{row.user_email || "Email tidak tersedia"}</p>
                    </>
                  ) : <span className="text-xs text-slate-400">Tidak terkait pengguna</span>}
                </td>
                <td className="max-w-[260px] px-4 py-4">
                  {row.external_references?.length ? row.external_references.map((reference) => (
                    <ExternalReference key={`${reference.label}-${reference.value}`} label={reference.label} value={reference.value} />
                  )) : <span className="text-xs text-slate-400">Tanpa referensi eksternal</span>}
                </td>
                <td className="max-w-sm px-4 py-4">
                  <p className="font-semibold text-slate-700">{row.description}</p>
                  <p className="mt-1 truncate text-[10px] text-slate-400">{row.uuid}</p>
                </td>
                <td className="px-4 py-4">{row.entries.map((entry) => <p key={entry.id} className="mb-1 text-xs"><span className="font-black text-slate-700">{entry.account_code.replaceAll("_", " ")}</span> · {entry.side === "debit" ? "Debit" : "Kredit"} · {money(entry.amount)}</p>)}</td>
                <td className="whitespace-nowrap px-4 py-4 text-right font-black text-slate-900">{money(row.total_debit)}</td>
              </tr>
            )) : <tr><td colSpan={7} className="p-12 text-center text-slate-500">Tidak ada transaksi untuk filter ini.</td></tr>}
          </tbody>
        </table>
      </div>
      <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 p-4 text-sm"><p className="text-slate-500">Menampilkan {data?.meta.from || 0}–{data?.meta.to || 0} dari {data?.meta.total || 0} jurnal</p><div className="flex items-center gap-2"><button disabled={!data || data.meta.current_page <= 1} onClick={() => setApplied((current) => ({ ...current, page: current.page - 1 }))} className="rounded-lg border px-3 py-2 font-bold disabled:opacity-40">Sebelumnya</button><span className="font-black">Halaman {data?.meta.current_page || 1} / {data?.meta.last_page || 1}</span><button disabled={!data || data.meta.current_page >= data.meta.last_page} onClick={() => setApplied((current) => ({ ...current, page: current.page + 1 }))} className="rounded-lg border px-3 py-2 font-bold disabled:opacity-40">Berikutnya</button></div></div>
    </div>
  </div>;
}

function ExternalReference({ label, value }: { label: string; value: string }) {
  const [copied, setCopied] = useState(false);

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1500);
    } catch {
      notify.error("Tidak dapat menyalin — coba salin manual.");
    }
  };

  return (
    <p className="mb-1 flex items-start gap-1 font-mono text-xs leading-5 text-slate-700">
      <span className="font-sans font-bold text-slate-500">{label}: </span>
      <span className="min-w-0 break-all">{value}</span>
      <button
        type="button"
        onClick={copy}
        aria-label={`Salin ${label}`}
        title={`Salin ${label}`}
        className="shrink-0 rounded-md border border-slate-200 bg-white p-0.5 text-slate-400 transition hover:border-orange-300 hover:text-orange-600"
      >
        {copied ? <Check size={12} className="text-emerald-600" /> : <Copy size={12} />}
      </button>
    </p>
  );
}

function Filter({ label, children }: { label: string; children: React.ReactNode }) {
  return <label><span className="text-xs font-black text-slate-600">{label}</span><div className="mt-1">{children}</div></label>;
}
