import { useCallback, useEffect, useState, type FormEvent } from "react";
import { ChevronLeft, ChevronRight, Loader2, Search } from "lucide-react";
import http, { getApiError } from "@/lib/http";
import { notify } from "@/lib/notify";

type ActivityItem = {
  id: number;
  actor: { id: number | null; name: string };
  module: string;
  action: string;
  summary: string;
  target_type: string | null;
  target_id: number | null;
  metadata: Record<string, unknown> | null;
  outcome: "success" | "failed";
  created_at: string;
};
type ActivityResponse = {
  data: ActivityItem[];
  pagination: { current_page: number; last_page: number; total: number };
  filters: { actors: Array<{ id: number; name: string }>; modules: string[] };
};
type Filters = { search: string; actor_id: string; module: string; outcome: string; date_from: string; date_to: string };
const emptyFilters: Filters = { search: "", actor_id: "", module: "", outcome: "", date_from: "", date_to: "" };
const dateTime = (value: string) => new Date(value).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" });

export default function AdminActivityLogPanel() {
  const [filters, setFilters] = useState<Filters>(emptyFilters);
  const [applied, setApplied] = useState<Filters>(emptyFilters);
  const [page, setPage] = useState(1);
  const [response, setResponse] = useState<ActivityResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [expanded, setExpanded] = useState<number | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const params = Object.fromEntries(Object.entries(applied).filter(([, value]) => value));
      const result = await http.get<ActivityResponse>("/admin/activity-logs", { params: { ...params, page, per_page: 20 } });
      setResponse(result.data);
    } catch (error) {
      notify.error(getApiError(error, "Aktivitas admin tidak dapat dimuat."));
    } finally {
      setLoading(false);
    }
  }, [applied, page]);

  useEffect(() => { void load(); }, [load]);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    setPage(1);
    setApplied(filters);
  };
  const reset = () => {
    setFilters(emptyFilters);
    setApplied(emptyFilters);
    setPage(1);
  };

  return <div className="space-y-5">
    <form onSubmit={submit} className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
      <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        <label className="relative md:col-span-2 xl:col-span-1"><span className="sr-only">Cari aktivitas admin</span><Search className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" size={18} /><input value={filters.search} onChange={(event) => setFilters({ ...filters, search: event.target.value })} placeholder="Cari ringkasan aktivitas" className="form-input pl-11" /></label>
        <select aria-label="Filter admin" value={filters.actor_id} onChange={(event) => setFilters({ ...filters, actor_id: event.target.value })} className="form-input"><option value="">Semua admin</option>{response?.filters.actors.map((actor) => <option key={actor.id} value={actor.id}>{actor.name}</option>)}</select>
        <select aria-label="Filter modul" value={filters.module} onChange={(event) => setFilters({ ...filters, module: event.target.value })} className="form-input"><option value="">Semua modul</option>{response?.filters.modules.map((module) => <option key={module} value={module}>{moduleLabel(module)}</option>)}</select>
        <select aria-label="Filter hasil" value={filters.outcome} onChange={(event) => setFilters({ ...filters, outcome: event.target.value })} className="form-input"><option value="">Semua hasil</option><option value="success">Berhasil</option><option value="failed">Gagal</option></select>
        <input type="date" aria-label="Tanggal mulai" value={filters.date_from} onChange={(event) => setFilters({ ...filters, date_from: event.target.value })} className="form-input" />
        <input type="date" aria-label="Tanggal akhir" value={filters.date_to} onChange={(event) => setFilters({ ...filters, date_to: event.target.value })} className="form-input" />
      </div>
      <div className="mt-4 flex flex-col gap-2 sm:flex-row sm:justify-end"><button type="button" onClick={reset} className="h-11 rounded-2xl border border-slate-200 px-4 text-sm font-black text-slate-700">Reset</button><button className="inline-flex h-11 items-center justify-center gap-2 rounded-2xl bg-slate-950 px-5 text-sm font-black text-white"><Search size={16} />Terapkan filter</button></div>
    </form>

    {loading ? <div className="grid min-h-64 place-items-center rounded-3xl border border-slate-200 bg-white"><Loader2 className="animate-spin text-orange-500" size={32} /></div> : !response?.data.length ? <div className="rounded-3xl border border-dashed border-slate-300 bg-white p-12 text-center text-sm font-bold text-slate-600">Belum ada aktivitas admin yang sesuai filter.</div> : <div className="space-y-3">
      {response.data.map((item) => <article key={item.id} className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        <button type="button" onClick={() => setExpanded(expanded === item.id ? null : item.id)} aria-expanded={expanded === item.id} className="flex w-full items-start gap-3 p-4 text-left sm:p-5">
          <span className={`mt-0.5 rounded-full px-2.5 py-1 text-[10px] font-black ${item.outcome === "success" ? "bg-emerald-100 text-emerald-700" : "bg-rose-100 text-rose-700"}`}>{item.outcome === "success" ? "Berhasil" : "Gagal"}</span>
          <span className="min-w-0 flex-1"><span className="flex flex-wrap items-center gap-2"><span className="text-sm font-black text-slate-950">{item.summary}</span><span className="rounded-full bg-indigo-50 px-2.5 py-1 text-[10px] font-black text-indigo-700">{moduleLabel(item.module)}</span></span><span className="mt-1 block text-xs leading-5 text-slate-500">{item.actor.name} · {dateTime(item.created_at)}</span></span>
          <span className="text-xs font-bold text-slate-400">{expanded === item.id ? "Tutup" : "Rincian"}</span>
        </button>
        {expanded === item.id && <div className="border-t border-slate-200 bg-slate-50 p-4 sm:p-5"><div className="grid gap-3 sm:grid-cols-2"><Detail label="Jenis tindakan" value={item.action} /><Detail label="Target" value={item.target_type ? `${item.target_type}${item.target_id ? ` #${item.target_id}` : ""}` : "—"} /></div>{item.metadata && Object.keys(item.metadata).length > 0 && <details className="mt-3 rounded-2xl border border-slate-200 bg-white p-4"><summary className="cursor-pointer text-sm font-black text-slate-800">Lihat metadata sebelum / sesudah</summary><pre className="mt-3 max-h-96 overflow-auto whitespace-pre-wrap break-words rounded-xl bg-slate-50 p-3 text-xs leading-5 text-slate-700">{JSON.stringify(item.metadata, null, 2)}</pre></details>}</div>}
      </article>)}
    </div>}

    {response && response.pagination.last_page > 1 && <div className="flex items-center justify-between rounded-2xl border border-slate-200 bg-white p-3"><button disabled={page <= 1 || loading} onClick={() => setPage((value) => value - 1)} className="inline-flex h-10 items-center gap-1 rounded-xl px-3 text-sm font-black text-slate-700 disabled:opacity-40"><ChevronLeft size={17} />Sebelumnya</button><p className="text-xs font-black text-slate-500">Halaman {page} dari {response.pagination.last_page} · {response.pagination.total} aktivitas</p><button disabled={page >= response.pagination.last_page || loading} onClick={() => setPage((value) => value + 1)} className="inline-flex h-10 items-center gap-1 rounded-xl px-3 text-sm font-black text-slate-700 disabled:opacity-40">Berikutnya<ChevronRight size={17} /></button></div>}
  </div>;
}

function Detail({ label, value }: { label: string; value: string }) {
  return <div className="rounded-xl border border-slate-200 bg-white p-3"><p className="text-[10px] font-black uppercase tracking-wider text-slate-400">{label}</p><p className="mt-1 break-words text-sm font-bold text-slate-800">{value}</p></div>;
}

function moduleLabel(module: string) {
  const labels: Record<string, string> = { admins: "Admin & akses", auth: "Autentikasi", pricing: "Tarif & harga", tutors: "Tutor", users: "Pengguna" };
  return labels[module] || module;
}
