import { useEffect, useMemo, useState, type ReactNode } from "react";
import { Link } from "react-router-dom";
import {
  AlertTriangle,
  ArrowRight,
  BookOpen,
  CalendarClock,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  Filter,
  History,
  Loader2,
  Search,
  User,
  X,
} from "lucide-react";
import AdminLayout from "../../components/AdminLayout";
import http, { getApiError } from "@/lib/http";
import { notify } from "@/lib/notify";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";

const SCOPES = [
  { key: "attention", label: "Perlu Tindakan", icon: AlertTriangle, tone: "amber" },
  { key: "active", label: "Aktif", icon: BookOpen, tone: "indigo" },
  { key: "upcoming", label: "Akan Datang", icon: CalendarClock, tone: "blue" },
  { key: "history", label: "Riwayat", icon: History, tone: "slate" },
] as const;

type Scope = (typeof SCOPES)[number]["key"];
type Row = {
  id: number;
  title: string;
  subject: string;
  chapter?: string | null;
  type: string;
  class_type: "private";
  method: "online" | "offline";
  status: string;
  status_label: string;
  teacher_name: string;
  student_count: number;
  progress: number;
  needs_admin_attention: boolean;
  attention_reason?: string | null;
  action?: { label: string; url: string } | null;
  start_at?: string | null;
  end_at?: string | null;
};
type Response = {
  data: Row[];
  meta: { current_page: number; last_page: number; per_page: number; total: number; from?: number | null; to?: number | null; scope: Scope };
  summary: { attention: number; active: number; upcoming: number; history: number };
};

const STATUS_OPTIONS = [
  ["", "Semua status"],
  ["confirmed", "Dikonfirmasi"],
  ["in_progress", "Sedang berlangsung"],
  ["awaiting_student_approval", "Menunggu persetujuan murid"],
  ["disputed", "Dalam keberatan"],
  ["absence_review", "Kehadiran diperiksa"],
  ["admin_review_required", "Menunggu tinjauan admin"],
  ["completed", "Selesai"],
  ["emergency_refund_pending", "Refund keadaan darurat"],
  ["refund_pending", "Refund diproses"],
  ["partially_refunded", "Sebagian direfund"],
  ["refunded", "Direfund"],
] as const;

const dateTime = (value?: string | null) => value
  ? new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short" }).format(new Date(value))
  : "Jadwal belum tersedia";

export default function ClassMonitoring() {
  const [rows, setRows] = useState<Row[]>([]);
  const [summary, setSummary] = useState<Response["summary"]>({ attention: 0, active: 0, upcoming: 0, history: 0 });
  const [meta, setMeta] = useState<Response["meta"]>({ current_page: 1, last_page: 1, per_page: 20, total: 0, scope: "attention" });
  const [scope, setScope] = useState<Scope>("attention");
  const [search, setSearch] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [status, setStatus] = useState("");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedSearch(search.trim()), 400);
    return () => window.clearTimeout(timer);
  }, [search]);

  useEffect(() => { setPage(1); }, [scope, debouncedSearch, status, dateFrom, dateTo]);

  useEffect(() => {
    let active = true;
    const load = async () => {
      setLoading(true);
      try {
        const response = await http.get<Response>("/admin/classes", {
          params: {
            scope,
            search: debouncedSearch || undefined,
            status: status || undefined,
            date_from: dateFrom || undefined,
            date_to: dateTo || undefined,
            page,
            per_page: 20,
          },
        });
        if (!active) return;
        setRows(response.data.data || []);
        setSummary(response.data.summary || { attention: 0, active: 0, upcoming: 0, history: 0 });
        setMeta(response.data.meta || { current_page: 1, last_page: 1, per_page: 20, total: 0, scope });
      } catch (error) {
        if (active) notify.error(getApiError(error, "Data pemantauan kelas gagal dimuat."));
      } finally {
        if (active) setLoading(false);
      }
    };
    void load();
    return () => { active = false; };
  }, [dateFrom, dateTo, debouncedSearch, page, scope, status]);

  const hasFilters = Boolean(search || status || dateFrom || dateTo);
  const pageNumbers = useMemo(() => {
    const start = Math.max(1, Math.min(meta.current_page - 2, Math.max(1, meta.last_page - 4)));
    return Array.from({ length: Math.min(5, meta.last_page) }, (_, index) => start + index);
  }, [meta.current_page, meta.last_page]);

  const resetFilters = () => {
    setSearch("");
    setDebouncedSearch("");
    setStatus("");
    setDateFrom("");
    setDateTo("");
  };

  return (
    <AdminLayout title="Monitoring Kelas">
      <div className="mx-auto max-w-[1500px] space-y-5 pb-12 sm:space-y-6">
        <section className="rounded-[1.75rem] bg-gradient-to-br from-slate-950 via-indigo-950 to-blue-900 p-5 text-white shadow-xl sm:p-7">
          <div className="max-w-3xl">
            <p className="text-[11px] font-black uppercase tracking-[.18em] text-blue-200">Operasional kelas</p>
            <h1 className="mt-2 text-2xl font-black tracking-tight sm:text-3xl">Fokus pada data yang perlu dilihat sekarang</h1>
            <p className="mt-2 text-sm leading-6 text-slate-300">Monitoring tidak lagi memuat seluruh data ke browser. Daftar dibatasi 20 kelas per halaman, difilter di server, dan detail lengkap baru dimuat saat dibuka.</p>
          </div>
        </section>

        <section className="grid grid-cols-2 gap-3 xl:grid-cols-4">
          {SCOPES.map(({ key, label, icon: Icon, tone }) => {
            const active = scope === key;
            const count = summary[key];
            return (
              <button
                key={key}
                type="button"
                onClick={() => { setScope(key); setStatus(""); }}
                className={`min-w-0 rounded-2xl border p-4 text-left transition sm:p-5 ${active ? key === "attention" ? "border-rose-300 bg-rose-50 shadow-sm ring-2 ring-rose-100" : "border-indigo-300 bg-indigo-50 shadow-sm ring-2 ring-indigo-100" : "border-slate-100 bg-white hover:border-indigo-200 hover:shadow-sm"}`}
              >
                <div className="flex items-start justify-between gap-2">
                  <div className={`grid h-10 w-10 place-items-center rounded-xl ${tone === "amber" ? "bg-amber-50 text-amber-600" : tone === "indigo" ? "bg-indigo-50 text-indigo-600" : tone === "blue" ? "bg-blue-50 text-blue-600" : "bg-slate-100 text-slate-600"}`}><Icon size={19} /></div>
                  {active && <CheckCircle2 size={17} className="text-indigo-600" />}
                </div>
                <p className="mt-4 text-2xl font-black text-slate-900">{count}</p>
                <p className="mt-1 text-xs font-black uppercase tracking-wider text-slate-500">{label}</p>
              </button>
            );
          })}
        </section>

        <section className="rounded-[1.5rem] border border-slate-100 bg-white p-3 shadow-sm sm:p-4">
          <div className="grid gap-3 xl:grid-cols-[minmax(300px,1fr)_220px_160px_160px]">
            <label className="relative min-w-0">
              <Search className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
              <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Cari mapel, Bab, atau tutor…" className="h-12 w-full rounded-xl border border-slate-200 bg-slate-50 pl-11 pr-10 text-sm font-semibold text-slate-700 outline-none transition focus:border-indigo-300 focus:bg-white focus:ring-2 focus:ring-indigo-100" />
              {search && <button type="button" aria-label="Hapus pencarian" onClick={() => setSearch("")} className="absolute right-2 top-1/2 grid h-9 w-9 -translate-y-1/2 place-items-center rounded-lg text-slate-400 hover:bg-white hover:text-slate-700"><X size={16} /></button>}
            </label>
            <Select value={status || "all"} onValueChange={(value) => setStatus(value === "all" ? "" : value)}>
              <SelectTrigger aria-label="Filter status kelas" className="h-12 min-w-0 rounded-xl border-slate-200 bg-white px-3 text-sm font-bold text-slate-700 focus:ring-indigo-100">
                <SelectValue />
              </SelectTrigger>
              <SelectContent align="start" className="w-[var(--radix-select-trigger-width)] max-w-[calc(100dvw-1.5rem)] rounded-xl">
                <SelectItem value="all">Semua status</SelectItem>
                {STATUS_OPTIONS.slice(1).map(([value, label]) => <SelectItem key={value} value={value}>{label}</SelectItem>)}
              </SelectContent>
            </Select>
            <label className="min-w-0"><span className="sr-only">Dari tanggal</span><input type="date" value={dateFrom} onChange={(event) => setDateFrom(event.target.value)} className="h-12 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-600 outline-none focus:border-indigo-300 focus:ring-2 focus:ring-indigo-100" /></label>
            <label className="min-w-0"><span className="sr-only">Sampai tanggal</span><input type="date" value={dateTo} min={dateFrom || undefined} onChange={(event) => setDateTo(event.target.value)} className="h-12 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-600 outline-none focus:border-indigo-300 focus:ring-2 focus:ring-indigo-100" /></label>
          </div>
          <div className="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs font-semibold text-slate-500">
            <span className="inline-flex items-center gap-2"><Filter size={14} />{meta.total} data pada filter ini · maksimum 20 data dimuat sekaligus</span>
            {hasFilters && <button type="button" onClick={resetFilters} className="inline-flex min-h-10 items-center gap-2 rounded-xl px-3 font-black text-indigo-600 hover:bg-indigo-50"><X size={14} />Reset filter</button>}
          </div>
        </section>

        <section className="overflow-hidden rounded-[1.75rem] border border-slate-100 bg-white shadow-[0_14px_40px_rgba(15,23,42,0.05)]">
          {loading ? (
            <div className="grid min-h-72 place-items-center"><div className="text-center"><Loader2 className="mx-auto animate-spin text-indigo-600" size={30} /><p className="mt-3 text-sm font-bold text-slate-500">Memuat halaman {page}…</p></div></div>
          ) : rows.length === 0 ? (
            <div className="px-5 py-16 text-center sm:py-20"><BookOpen className="mx-auto text-slate-300" size={38} /><h2 className="mt-4 font-black text-slate-800">Tidak ada data pada tampilan ini</h2><p className="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">{scope === "attention" ? "Bagus, saat ini tidak ada kelas yang membutuhkan tindakan admin." : "Coba ubah filter atau rentang tanggal."}</p>{hasFilters && <button type="button" onClick={resetFilters} className="mt-5 min-h-11 rounded-xl bg-indigo-600 px-5 text-sm font-black text-white">Reset filter</button>}</div>
          ) : (
            <>
              <div className="divide-y divide-slate-100 md:hidden">
                {rows.map((item) => <MobileClassCard key={item.id} item={item} />)}
              </div>
              <div className="hidden overflow-x-auto md:block">
                <table className="w-full min-w-[980px] text-left">
                  <thead className="border-b border-slate-100 bg-slate-50/80"><tr><th className="px-6 py-4 text-[11px] font-black uppercase tracking-wider text-slate-400">Kelas</th><th className="px-5 py-4 text-[11px] font-black uppercase tracking-wider text-slate-400">Sesi</th><th className="px-5 py-4 text-[11px] font-black uppercase tracking-wider text-slate-400">Tutor</th><th className="px-5 py-4 text-[11px] font-black uppercase tracking-wider text-slate-400">Jadwal</th><th className="px-5 py-4 text-[11px] font-black uppercase tracking-wider text-slate-400">Peserta</th><th className="px-5 py-4 text-[11px] font-black uppercase tracking-wider text-slate-400">Status</th><th className="px-5 py-4 text-right text-[11px] font-black uppercase tracking-wider text-slate-400">Aksi</th></tr></thead>
                  <tbody className="divide-y divide-slate-100">{rows.map((item) => <DesktopClassRow key={item.id} item={item} />)}</tbody>
                </table>
              </div>
            </>
          )}

          {!loading && meta.last_page > 1 && (
            <div className="flex flex-col gap-3 border-t border-slate-100 bg-slate-50/60 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
              <p className="text-xs font-semibold text-slate-500">Menampilkan {meta.from ?? 0}–{meta.to ?? 0} dari {meta.total}</p>
              <div className="flex items-center justify-center gap-1">
                <PageButton disabled={meta.current_page <= 1} onClick={() => setPage((value) => Math.max(1, value - 1))} label="Sebelumnya"><ChevronLeft size={16} /></PageButton>
                <div className="hidden items-center gap-1 sm:flex">{pageNumbers.map((number) => <button key={number} type="button" onClick={() => setPage(number)} className={`grid h-10 min-w-10 place-items-center rounded-xl px-2 text-xs font-black ${number === meta.current_page ? "bg-indigo-600 text-white" : "text-slate-600 hover:bg-white"}`}>{number}</button>)}</div>
                <span className="px-2 text-xs font-black text-slate-500 sm:hidden">{meta.current_page}/{meta.last_page}</span>
                <PageButton disabled={meta.current_page >= meta.last_page} onClick={() => setPage((value) => Math.min(meta.last_page, value + 1))} label="Berikutnya"><ChevronRight size={16} /></PageButton>
              </div>
            </div>
          )}
        </section>
      </div>
    </AdminLayout>
  );
}

function MobileClassCard({ item }: { item: Row }) {
  return (
    <article className="p-4 sm:p-5">
      <div className="flex items-start justify-between gap-3"><div className="min-w-0"><div className="flex flex-wrap gap-1.5"><Badge>{item.type}</Badge><Badge>{item.method === "online" ? "Online" : "Offline"}</Badge>{item.needs_admin_attention && <span className="rounded-full bg-amber-100 px-2.5 py-1 text-[10px] font-black text-amber-800">Perlu tindakan</span>}</div><h3 className="mt-3 break-words text-base font-black text-slate-900">{item.title}</h3><p className="mt-1 text-xs font-semibold text-slate-500">{dateTime(item.start_at)}</p></div><div className="flex shrink-0 gap-2"><Link to={`/admin/classes/${item.id}`} aria-label={`Buka ${item.title}`} className="grid h-11 w-11 shrink-0 place-items-center rounded-xl border border-slate-200 text-slate-500 hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700"><ArrowRight size={18} /></Link>{item.action && <Link to={item.action.url} className="inline-flex min-h-11 shrink-0 items-center justify-center whitespace-nowrap rounded-xl border border-amber-200 bg-amber-50 px-3 text-xs font-black text-amber-700 hover:bg-amber-100" title={item.action.label}>{item.action.label}</Link>}</div></div>
      <div className="mt-4 grid grid-cols-2 gap-2"><MiniInfo label="Sesi" value={`#${item.id}`} /><MiniInfo label="Tutor" value={item.teacher_name} /><MiniInfo label="Peserta" value={`${item.student_count} murid`} /></div>
      <div className="mt-3 flex items-center justify-between gap-3 rounded-xl bg-slate-50 px-3 py-2.5"><span className="text-[11px] font-black uppercase tracking-wider text-slate-400">Status</span><span className={`text-right text-xs font-black ${item.needs_admin_attention ? "text-amber-700" : "text-indigo-700"}`}>{item.status_label}</span></div>
      {item.attention_reason && <p className="mt-2 break-words rounded-xl bg-rose-50 px-3 py-2 text-xs font-bold leading-5 text-rose-700">{item.attention_reason}</p>}
    </article>
  );
}

function DesktopClassRow({ item }: { item: Row }) {
  return (
    <tr className="transition hover:bg-slate-50/70">
      <td className="px-6 py-5"><div className="max-w-md font-black text-slate-900">{item.title}</div><div className="mt-2 flex flex-wrap gap-1.5"><Badge>{item.type}</Badge><Badge>{item.method === "online" ? "Online" : "Offline"}</Badge>{item.needs_admin_attention && <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-black text-amber-800">Perlu tindakan</span>}</div></td>
      <td className="px-5 py-5"><span className="font-mono text-xs font-black text-slate-600">Sesi #{item.id}</span></td>
      <td className="px-5 py-5"><div className="flex items-center gap-2"><span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-indigo-50 text-xs font-black text-indigo-700">{item.teacher_name.charAt(0).toUpperCase()}</span><span className="text-sm font-semibold text-slate-600">{item.teacher_name}</span></div></td>
      <td className="px-5 py-5 text-sm font-semibold text-slate-600">{dateTime(item.start_at)}</td>
      <td className="px-5 py-5"><span className="inline-flex items-center gap-2 text-sm font-bold text-slate-600"><User size={15} className="text-slate-400" />{item.student_count}</span></td>
      <td className="px-5 py-5"><span className={`text-xs font-black ${item.needs_admin_attention ? "text-amber-700" : "text-slate-600"}`}>{item.status_label}</span>{item.attention_reason && <p className="mt-1 max-w-64 text-[11px] font-semibold leading-4 text-rose-600">{item.attention_reason}</p>}</td>
      <td className="px-5 py-5 text-right"><div className="flex justify-end gap-2"><Link to={`/admin/classes/${item.id}`} className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-slate-200 px-3 text-xs font-black text-slate-600 hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700">Detail <ArrowRight size={15} /></Link>{item.action && <Link to={item.action.url} className="inline-flex min-h-10 whitespace-nowrap items-center justify-center rounded-xl border border-amber-200 bg-amber-50 px-4 text-xs font-black text-amber-700 hover:bg-amber-100">{item.action.label}</Link>}</div></td>
    </tr>
  );
}

function Badge({ children }: { children: ReactNode }) {
  return <span className="rounded-full bg-indigo-50 px-2.5 py-1 text-[10px] font-black uppercase tracking-wide text-indigo-700">{children}</span>;
}

function MiniInfo({ label, value }: { label: string; value: string }) {
  return <div className="min-w-0 rounded-xl border border-slate-100 bg-white p-3"><p className="text-[10px] font-black uppercase tracking-wider text-slate-400">{label}</p><p className="mt-1 break-words text-xs font-black leading-5 text-slate-700">{value}</p></div>;
}

function PageButton({ disabled, onClick, label, children }: { disabled: boolean; onClick: () => void; label: string; children: ReactNode }) {
  return <button type="button" disabled={disabled} onClick={onClick} aria-label={label} className="grid h-10 w-10 place-items-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:border-indigo-200 hover:text-indigo-700 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:border-slate-200 disabled:hover:text-slate-600">{children}</button>;
}
