import { notify } from "@/lib/notify";
import { useCallback, useEffect, useMemo, useState } from "react";
import { Banknote, CheckCircle2, Clock3, Landmark, Loader2, LockKeyhole, RefreshCw, Send, ShieldAlert, WalletCards } from "lucide-react";
import TeacherLayout from "@/components/TeacherLayout";
import { Button } from "@/components/ui/button";
import http, { getApiError } from "@/lib/http";
import { useNavigate } from "react-router-dom";

type SalaryData = {
  balances: { held: number; available: number; requested: number; paid: number; overpaid?: number };
  totals: { gross: number; commission: number; net: number };
  ready_sessions: number;
  ready_bookings: Array<{ id: number; subject: string; completed_at: string; gross_amount: number; commission_amount: number; net_amount: number; earned_amount?: number }>;
  bank: { is_complete: boolean; bank_name?: string | null; account_name?: string | null; account_number_masked?: string | null; payout_hold_until?: string | null };
  payout_requests: Array<{ id: number; net_amount: number; requested_amount?: number; tax_amount?: number; gross_amount: number; commission_amount: number; status: string; requested_at: string; processed_at?: string | null; review_notes?: string | null }>;
  history: Array<{ id: number; period: string; amount: number; transfer_date?: string | null; status: string }>;
  withholding_tax_percent: number;
};

const MINIMUM = 10_000;
const rupiah = (value?: number) => new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(value || 0);
const dateTime = (value?: string | null) => value ? new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short" }).format(new Date(value)) : "-";
const labels: Record<string, string> = { pending: "Menunggu sistem", processing: "Sedang dikirim", on_hold: "Menunggu pemeriksaan penyedia", completed: "Berhasil", failed: "Gagal", rejected: "Ditolak", reversed: "Dikembalikan", cancelled: "Dibatalkan" };

export default function TeacherSalary() {
  const navigate = useNavigate();
  const [data, setData] = useState<SalaryData | null>(null);
  const [loading, setLoading] = useState(true);
  const [amount, setAmount] = useState("");
  const load = useCallback(async () => {
    setLoading(true);
    try { const response = await http.get<SalaryData>("/teacher/salary"); setData(response.data); }
    catch (error) { notify.error(getApiError(error, "Data pendapatan gagal dimuat.")); }
    finally { setLoading(false); }
  }, []);
  useEffect(() => { void load(); }, [load]);

  const heldByBankChange = Boolean(data?.bank.payout_hold_until && new Date(data.bank.payout_hold_until).getTime() > Date.now());
  const numericAmount = useMemo(() => Number(amount.replace(/[^0-9]/g, "")) || 0, [amount]);
  const invalidAmount = Boolean(data && (numericAmount < MINIMUM || numericAmount > data.balances.available));
  const taxAmount = Math.round(numericAmount * (data?.withholding_tax_percent || 0) / 100);
  const continuePayout = () => {
    if (!data || invalidAmount || !amount) return;
    navigate(`/guru/pencairan/rekening?amount=${numericAmount}`);
  };

  return <TeacherLayout title="Dompet Tutor"><div className="space-y-5 pb-10 sm:space-y-7">
    <section className="rounded-[1.7rem] bg-gradient-to-br from-slate-950 via-emerald-950 to-indigo-950 p-5 text-white shadow-xl sm:rounded-[2rem] sm:p-8"><div className="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between"><div><p className="text-xs font-black uppercase tracking-[.18em] text-emerald-200">Dompet tutor</p><h1 className="mt-2 text-2xl font-black sm:text-3xl">Pendapatan yang jelas, pencairan mandiri</h1><p className="mt-2 max-w-xl text-sm leading-6 text-emerald-100/75">Pendapatan masuk setelah sesi sah. Pilih nominal mulai Rp10.000, lalu periksa rekening tujuan pada halaman berikutnya. Transfer menunggu verifikasi pemilik rekening.</p></div><Button variant="outline" onClick={() => void load()} className="h-11 rounded-xl border-white/20 bg-white/10 text-white hover:bg-white/20 hover:text-white"><RefreshCw size={16} className="mr-2" />Muat ulang</Button></div></section>
    {loading ? <div className="grid min-h-80 place-items-center rounded-[2rem] bg-white"><Loader2 className="animate-spin text-indigo-600" size={30} /></div> : !data ? <div className="rounded-[2rem] bg-white p-16 text-center"><ShieldAlert className="mx-auto text-rose-300" /><p className="mt-3 font-black">Data pendapatan belum tersedia.</p></div> : <>
      <section className="grid grid-cols-2 gap-3 lg:grid-cols-4"><Balance icon={LockKeyhole} label="Ditahan" value={data.balances.held} detail="Masa keberatan/kasus" color="bg-amber-500" /><Balance icon={WalletCards} label="Tersedia" value={data.balances.available} detail={`${data.ready_sessions} sesi memiliki saldo`} color="bg-emerald-600" /><Balance icon={Clock3} label="Diproses" value={data.balances.requested} detail="Sedang dikirim" color="bg-indigo-600" /><Balance icon={CheckCircle2} label="Dibayar" value={data.balances.paid} detail="Sudah masuk rekening" color="bg-slate-800" /></section>

      <section className="grid gap-4 lg:grid-cols-[1.15fr_.85fr]">
        <article className="rounded-[1.7rem] border border-slate-100 bg-white p-5 shadow-sm sm:rounded-[2rem] sm:p-6">
          <div className="flex items-start gap-3"><span className="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-emerald-50 text-emerald-700"><Landmark size={19} /></span><div className="min-w-0 flex-1"><p className="font-black text-slate-900">Tarik saldo</p><p className="mt-1 text-sm text-slate-600">Tentukan nominal dulu. Rekening tujuan diisi atau diperiksa pada langkah berikutnya.</p></div></div>
          {heldByBankChange && <div className="mt-4 rounded-xl border border-amber-100 bg-amber-50 p-3 text-xs leading-5 text-amber-800">Pencairan ditahan setelah perubahan rekening sampai {dateTime(data.bank.payout_hold_until)} WIB.</div>}
          {(data.balances.overpaid || 0) > 0 && <div className="mt-4 rounded-xl border border-rose-100 bg-rose-50 p-3 text-xs leading-5 text-rose-800">Ada kelebihan bayar {rupiah(data.balances.overpaid)} akibat sesi yang dikembalikan setelah pencairan. Saldo tidak dapat ditarik sampai kelebihan ini diselesaikan admin.</div>}
          <label className="mt-5 block"><span className="text-xs font-black uppercase tracking-wide text-slate-500">Nominal pencairan</span><div className="mt-2 flex h-12 items-center rounded-xl border border-slate-200 bg-slate-50 px-3 focus-within:border-emerald-500 focus-within:ring-2 focus-within:ring-emerald-100"><span className="font-black text-slate-500">Rp</span><input inputMode="numeric" value={amount} onChange={(event) => setAmount(event.target.value.replace(/[^0-9]/g, ""))} className="h-full min-w-0 flex-1 bg-transparent px-2 text-lg font-black outline-none" placeholder="10.000" /><button type="button" onClick={() => setAmount(String(Math.floor(data.balances.available)))} className="rounded-lg bg-white px-3 py-1.5 text-xs font-black text-emerald-700 shadow-sm">Maksimal</button></div></label>
          <div className="mt-2 flex items-center justify-between text-xs"><span className={invalidAmount && amount ? "text-rose-600" : "text-slate-400"}>Minimum {rupiah(MINIMUM)}</span><span className="font-bold text-slate-500">Tersedia {rupiah(data.balances.available)}</span></div>
          {numericAmount > 0 && <div className="mt-4 rounded-xl bg-slate-50 p-3 text-sm"><div className="flex justify-between"><span>Pajak ({data.withholding_tax_percent || 0}%)</span><span>-{rupiah(taxAmount)}</span></div><div className="mt-2 flex justify-between font-black"><span>Dikirim ke rekening</span><span>{rupiah(numericAmount - taxAmount)}</span></div></div>}
          <Button onClick={continuePayout} disabled={invalidAmount || !amount} className="mt-5 h-11 w-full rounded-xl bg-emerald-600 hover:bg-emerald-700"><Send className="mr-2" size={16} />{data.bank.is_complete ? "Lanjut ke rekening tujuan" : "Lengkapi rekening saat pencairan"}</Button>
        </article>
        <article className="rounded-[1.7rem] border border-slate-100 bg-white p-5 shadow-sm sm:rounded-[2rem] sm:p-6"><p className="font-black text-slate-900">Perhitungan pendapatan</p><Row label="Nilai seluruh sesi" value={data.totals.gross} /><Row label="Komisi admin" value={data.totals.commission} negative /><Row label="Hak bersih tutor" value={data.totals.net} strong /></article>
      </section>

      <section className="rounded-[1.7rem] border border-slate-100 bg-white p-4 shadow-sm sm:rounded-[2rem] sm:p-6"><div className="mb-4"><h2 className="text-lg font-black text-slate-900">Rincian saldo tersedia</h2><p className="mt-1 text-xs text-slate-500">Pencairan sebagian tidak menghilangkan sisa hak dari sesi yang sama.</p></div><div className="space-y-3">{data.ready_bookings.length ? data.ready_bookings.map((item) => <article key={item.id} className="rounded-2xl border border-slate-100 bg-slate-50 p-4"><div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><p className="font-black text-slate-900">{item.subject}</p><p className="mt-1 text-xs text-slate-400">Selesai {dateTime(item.completed_at)}</p></div><div className="grid grid-cols-3 gap-3 text-right text-xs"><div><p className="text-slate-400">Bruto</p><p className="mt-1 font-bold text-slate-700">{rupiah(item.gross_amount)}</p></div><div><p className="text-slate-400">Komisi</p><p className="mt-1 font-bold text-rose-600">-{rupiah(item.commission_amount)}</p></div><div><p className="text-slate-400">Tersisa</p><p className="mt-1 font-black text-emerald-700">{rupiah(item.net_amount)}</p></div></div></div></article>) : <Empty text="Belum ada saldo sesi yang siap dicairkan." />}</div></section>

      <section className="grid gap-4 lg:grid-cols-2"><History title="Proses pencairan">{data.payout_requests.length ? data.payout_requests.map((item) => <article key={item.id} className="rounded-2xl border border-slate-100 p-4"><div className="flex items-start justify-between gap-3"><div><p className="text-lg font-black text-slate-900">{rupiah(item.net_amount)}</p><p className="mt-1 text-xs text-slate-400">Dibuat {dateTime(item.requested_at)}</p></div><Status value={item.status} /></div><div className="mt-3 grid grid-cols-2 gap-2 rounded-xl bg-slate-50 p-3 text-xs"><span>Diminta <b className="mt-1 block">{rupiah(item.requested_amount || item.net_amount)}</b></span><span>Potongan pajak <b className="mt-1 block text-rose-600">-{rupiah(item.tax_amount || 0)}</b></span></div>{item.review_notes && <p className="mt-3 text-xs leading-5 text-rose-600">{item.review_notes}</p>}</article>) : <Empty text="Belum ada pencairan." />}</History><History title="Transfer berhasil">{data.history.length ? data.history.map((item) => <article key={item.id} className="flex items-center gap-3 rounded-2xl border border-slate-100 p-4"><span className="grid h-10 w-10 shrink-0 place-items-center rounded-2xl bg-emerald-50 text-emerald-700"><Banknote size={18} /></span><div className="min-w-0 flex-1"><p className="font-black text-slate-900">{rupiah(item.amount)}</p><p className="mt-1 text-xs text-slate-400">{item.period} · {item.transfer_date || "-"}</p></div></article>) : <Empty text="Belum ada transfer berhasil." />}</History></section>
    </>}
  </div></TeacherLayout>;
}

function Balance({ icon: Icon, label, value, detail, color }: { icon: typeof Banknote; label: string; value: number; detail: string; color: string }) { return <div className="min-w-0 rounded-[1.35rem] border border-slate-100 bg-white p-4 shadow-sm"><span className={`grid h-9 w-9 place-items-center rounded-xl text-white ${color}`}><Icon size={16} /></span><p className="mt-3 text-[10px] font-black uppercase tracking-wider text-slate-400">{label}</p><p className="mt-1 truncate text-lg font-black text-slate-900 sm:text-xl">{rupiah(value)}</p><p className="mt-1 truncate text-[10px] text-slate-500">{detail}</p></div>; }
function Row({ label, value, negative, strong }: { label: string; value: number; negative?: boolean; strong?: boolean }) { return <div className={`mt-3 flex items-center justify-between gap-3 rounded-xl px-3 py-3 text-sm ${strong ? "bg-emerald-50" : "bg-slate-50"}`}><span className={`font-bold ${strong ? "text-emerald-800" : "text-slate-500"}`}>{label}</span><span className={`text-right font-black ${negative ? "text-rose-600" : strong ? "text-emerald-800" : "text-slate-900"}`}>{negative ? "-" : ""}{rupiah(value)}</span></div>; }
function History({ title, children }: { title: string; children: React.ReactNode }) { return <article className="rounded-[1.7rem] border border-slate-100 bg-white p-4 shadow-sm sm:rounded-[2rem] sm:p-6"><h2 className="mb-4 text-lg font-black text-slate-900">{title}</h2><div className="space-y-3">{children}</div></article>; }
function Status({ value }: { value: string }) { return <span className={`shrink-0 rounded-full px-3 py-1 text-[10px] font-black ${value === "completed" ? "bg-emerald-50 text-emerald-700" : ["failed", "rejected", "reversed"].includes(value) ? "bg-rose-50 text-rose-700" : value === "on_hold" ? "bg-sky-50 text-sky-700" : "bg-amber-50 text-amber-700"}`}>{labels[value] || value}</span>; }
function Empty({ text }: { text: string }) { return <p className="py-10 text-center text-sm text-slate-500">{text}</p>; }
