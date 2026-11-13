import { notify } from "@/lib/notify";
import http, { getApiError } from "@/lib/http";
import { useEffect, useState } from "react";
import AdminLayout from "../../components/AdminLayout";
import { CheckCircle2, Clock3, DollarSign, Loader2, PieChart, RefreshCw, TrendingUp, Wallet } from "lucide-react";
import { Button } from "@/components/ui/button";

function Frame({ embedded, children }: { embedded: boolean; children: React.ReactNode }) {
  return embedded ? <>{children}</> : <AdminLayout title="Pencairan tutor" subtitle="Monitoring pencairan otomatis dan pengecualian yang memerlukan perhatian">{children}</AdminLayout>;
}
const rupiah = (value: number) => new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(value || 0);
const dateTime = (value?: string | null) => value ? new Date(value).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" }) : "-";
type Payout = { id?: number; queueKey?: string; name: string; period: string; netAmount: number; requestedAmount?: number; taxAmount?: number; status: string; statusKey?: string; gatewayStatus?: string | null; gatewayLastCheckedAt?: string | null; createdAt?: string; transferDate?: string };
type Data = { pending: Payout[]; history: Payout[]; stats: { ready_amount: number; requested_amount: number; paid_7days: number; paid_count_7days: number; admin_fee_percent: number } };

export default function FinanceReport({ embedded = false }: { embedded?: boolean }) {
  const [data, setData] = useState<Data | null>(null);
  const [loading, setLoading] = useState(true);
  const [checkingId, setCheckingId] = useState<number | null>(null);
  const [tab, setTab] = useState<"processing" | "history">("processing");
  const load = async () => {
    setLoading(true);
    try { const response = await http.get<Data>("/admin/finance"); setData(response.data); }
    catch (error) { notify.error(getApiError(error, "Data pencairan tidak dapat dimuat.")); }
    finally { setLoading(false); }
  };
  const checkStatus = async (item: Payout) => {
    if (!item.id) return;
    setCheckingId(item.id);
    try {
      const response = await http.post(`/admin/finance/payouts/${item.id}/reconcile`, {});
      notify.success(response.data?.message || "Status pencairan diperbarui.");
      await load();
      window.dispatchEvent(new Event("bimbelku:data-changed"));
    } catch (error) {
      notify.error(getApiError(error, "Status pencairan belum dapat diperiksa."));
    } finally {
      setCheckingId(null);
    }
  };
  useEffect(() => { void load(); const timer = window.setInterval(() => void load(), 30_000); return () => window.clearInterval(timer); }, []);
  if (loading && !data) return <div className="grid min-h-72 place-items-center rounded-3xl bg-white"><Loader2 className="animate-spin text-orange-600" /></div>;
  const rows = tab === "processing" ? data?.pending || [] : data?.history || [];

  return <Frame embedded={embedded}><div className="space-y-6 pb-16">
    <section className="rounded-[1.75rem] border border-emerald-100 bg-emerald-50 p-5 text-emerald-950"><div className="flex items-start gap-3"><CheckCircle2 className="mt-0.5 shrink-0 text-emerald-600" /><div><h2 className="font-black">Pencairan berjalan otomatis</h2><p className="mt-1 text-sm leading-6 text-emerald-800">Tutor memilih nominal sendiri. Admin hanya memantau status; kegagalan mengembalikan dana ke saldo tutor secara otomatis. Keputusan manual hanya diperlukan jika ada sengketa atau data rekening bermasalah.</p></div></div></section>
    <section className="grid grid-cols-2 gap-3 xl:grid-cols-4">
      <Metric icon={Wallet} label="Saldo tutor tersedia" value={rupiah(data?.stats.ready_amount || 0)} tone="slate" />
      <Metric icon={Clock3} label="Sedang dikirim" value={rupiah(data?.stats.requested_amount || 0)} tone="indigo" />
      <Metric icon={TrendingUp} label="Berhasil 7 hari" value={rupiah(data?.stats.paid_7days || 0)} tone="emerald" />
      <Metric icon={PieChart} label="Komisi admin" value={`${data?.stats.admin_fee_percent || 20}%`} tone="orange" />
    </section>
    <section className="overflow-hidden rounded-[1.75rem] border border-slate-200 bg-white shadow-sm">
      <header className="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5"><div className="grid grid-cols-2 rounded-xl bg-slate-100 p-1"><button onClick={() => setTab("processing")} className={`rounded-lg px-4 py-2 text-xs font-black ${tab === "processing" ? "bg-white text-slate-900 shadow-sm" : "text-slate-500"}`}>Sedang diproses ({data?.pending.length || 0})</button><button onClick={() => setTab("history")} className={`rounded-lg px-4 py-2 text-xs font-black ${tab === "history" ? "bg-white text-slate-900 shadow-sm" : "text-slate-500"}`}>Riwayat</button></div><Button variant="outline" onClick={() => void load()} className="h-10 rounded-xl"><RefreshCw size={15} className="mr-2" />Muat ulang</Button></header>
      <div className="divide-y divide-slate-100">{rows.length ? rows.map((item, index) => <article key={item.id || item.queueKey || index} className="grid gap-3 p-4 sm:grid-cols-[1fr_auto_auto] sm:items-center sm:p-5"><div><p className="font-black text-slate-900">{item.name}</p><p className="mt-1 text-xs text-slate-500">{item.period} · {dateTime(item.createdAt || item.transferDate)}</p>{tab === "processing" && item.id && <p className="mt-1 text-[10px] text-slate-400">Terakhir diperiksa: {dateTime(item.gatewayLastCheckedAt)}</p>}</div><div className="sm:text-right"><p className="text-xs font-bold text-slate-400">Dikirim</p><p className="mt-1 font-black text-slate-900">{rupiah(item.netAmount)}</p>{(item.taxAmount || 0) > 0 && <p className="text-[10px] text-rose-600">Pajak -{rupiah(item.taxAmount)}</p>}</div><div className="flex flex-wrap items-center gap-2 sm:justify-end"><Status value={item.status} gateway={item.gatewayStatus} />{tab === "processing" && <Button variant="outline" disabled={checkingId === item.id} onClick={() => void checkStatus(item)} className="h-10 rounded-xl"><RefreshCw size={15} className={`mr-2 ${checkingId === item.id ? "animate-spin" : ""}`} />Periksa status</Button>}</div></article>) : <div className="p-12 text-center text-sm text-slate-500">Tidak ada pencairan pada tampilan ini.</div>}</div>
    </section>
  </div></Frame>;
}
function Metric({ icon: Icon, label, value, tone }: { icon: typeof DollarSign; label: string; value: string; tone: string }) { const styles: Record<string,string> = { slate: "bg-slate-950 text-white", indigo: "bg-indigo-50 text-indigo-950", emerald: "bg-emerald-50 text-emerald-950", orange: "bg-orange-50 text-orange-950" }; return <article className={`rounded-2xl p-4 ${styles[tone]}`}><Icon size={18} className="opacity-70" /><p className="mt-3 text-[10px] font-black uppercase tracking-wider opacity-60">{label}</p><p className="mt-1 text-lg font-black sm:text-2xl">{value}</p></article>; }
function Status({ value, gateway }: { value: string; gateway?: string | null }) { const ok=value === "Berhasil"; const processing=value === "Sedang diproses"; const hold=value === "Menunggu dokumen Xendit"; const reversed=value === "Dikembalikan bank"; const tone = ok ? "bg-emerald-50 text-emerald-700" : processing ? "bg-amber-50 text-amber-700" : hold ? "bg-sky-50 text-sky-700" : reversed ? "bg-slate-100 text-slate-700" : "bg-rose-50 text-rose-700"; return <span className={`w-fit rounded-full px-3 py-1.5 text-[10px] font-black ${tone}`}>{value || gateway}</span>; }
