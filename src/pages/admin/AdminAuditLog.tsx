import { notify } from "@/lib/notify";
import { FormEvent, useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  AlertTriangle,
  CheckCircle2,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  ClipboardCheck,
  Loader2,
  Minus,
  Plus,
  RefreshCw,
  Search,
  ShieldAlert,
  ShieldCheck,
} from "lucide-react";
import AdminLayout from "@/components/AdminLayout";
import AdminActivityLogPanel from "@/pages/admin/AdminActivityLogPanel";
import { ResponsiveSelect } from "@/components/ResponsiveSelect";
import http, { getApiError } from "@/lib/http";

type PermissionDefinition = { code: string; label: string; description: string };
type PermissionGroup = { key: string; label: string; permissions: PermissionDefinition[] };

type AuditItem = {
  id: number;
  request_id: string;
  actor: { id?: number | null; name: string; email?: string | null };
  action: string;
  category: string;
  permission_code?: string | null;
  route_name: string;
  method: string;
  response_status: number;
  target_type?: string | null;
  target_id?: number | null;
  target_role?: string | null;
  reason?: string | null;
  request_payload?: Record<string, unknown> | null;
  before_state?: Record<string, unknown> | null;
  after_state?: Record<string, unknown> | null;
  metadata?: Record<string, unknown> | null;
  ip_address?: string | null;
  user_agent?: string | null;
  entry_hash: string;
  previous_hash?: string | null;
  created_at: string;
};

type AuditDetail = Pick<AuditItem, "request_payload" | "before_state" | "after_state"> & { id: number };
type IntegrityStatus = { valid: boolean; checked: number };
type PageSize = "20" | "50" | "100" | "all";

type AuditResponse = {
  data: AuditItem[];
  pagination: { current_page: number; last_page: number; per_page: number; total: number };
  filters: {
    permission_groups: PermissionGroup[];
    actors: Array<{ id: number; name: string; email: string }>;
  };
};

type FilterState = {
  search: string;
  actor_id: string;
  category: string;
  permission: string;
  status: string;
  date_from: string;
  date_to: string;
};

type ChangeKind = "added" | "changed" | "removed";
type AuditChange = { field: string; kind: ChangeKind; before?: unknown; after?: unknown };

const defaultFilters: FilterState = {
  search: "",
  actor_id: "",
  category: "",
  permission: "",
  status: "",
  date_from: "",
  date_to: "",
};

const categoryLabels: Record<string, string> = {
  operations: "Operasional",
  matching: "Pencarian tutor",
  finance: "Keuangan",
  teachers: "Tutor",
  cases: "Kasus",
  users: "Pengguna",
  classes: "Kelas",
  content: "Konten",
  support: "Bantuan",
  settings: "Pengaturan",
  admins: "Admin",
  audit: "Pengawasan",
  unknown: "Lainnya",
};

const fieldLabels: Record<string, string> = {
  name: "Nama",
  title: "Judul",
  email: "Email",
  role: "Jenis pengguna",
  status: "Status",
  is_active: "Status akun",
  active: "Status aktif",
  description: "Deskripsi",
  content: "Isi",
  notes: "Catatan",
  reason: "Alasan",
  subject: "Mata pelajaran",
  subject_name: "Mata pelajaran",
  chapter: "Bab",
  chapter_name: "Bab",
  price: "Harga",
  amount: "Nominal",
  hourly_rate: "Tarif per jam",
  commission_percentage: "Persentase komisi",
  search_radius_km: "Jarak pencarian tutor",
  learning_mode: "Metode belajar",
  payment_status: "Status pembayaran",
  verification_status: "Status verifikasi",
  image: "Gambar",
  image_url: "Gambar",
  url: "Tautan",
  link: "Tautan",
  position: "Urutan tampil",
  sort_order: "Urutan tampil",
  start_date: "Tanggal mulai",
  end_date: "Tanggal selesai",
};

const technicalFields = new Set([
  "id",
  "uuid",
  "created_at",
  "updated_at",
  "deleted_at",
  "remember_token",
  "email_verified_at",
  "password",
  "token",
  "hash",
  "phone",
  "address",
  "date_of_birth",
  "guardian_name",
  "guardian_phone",
  "guardian_relationship",
  "maps_link",
  "latitude",
  "longitude",
  "account_number",
  "sender_account_number",
  "destination_account_number",
]);

const dateTime = (value: string) => new Date(value).toLocaleString("id-ID", {
  dateStyle: "medium",
  timeStyle: "short",
});

export default function AdminAuditLog() {
  const [activeTab, setActiveTab] = useState<"financial" | "activity">("financial");
  const [data, setData] = useState<AuditResponse | null>(null);
  const [integrity, setIntegrity] = useState<IntegrityStatus | null>(null);
  const [filters, setFilters] = useState<FilterState>(defaultFilters);
  const [applied, setApplied] = useState<FilterState>(defaultFilters);
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState<PageSize>("20");
  const [loading, setLoading] = useState(true);
  const [loadingAll, setLoadingAll] = useState(false);
  const [expanded, setExpanded] = useState<number | null>(null);
  const [details, setDetails] = useState<Record<number, AuditDetail>>({});
  const [loadingDetails, setLoadingDetails] = useState<Set<number>>(() => new Set());
  const [detailErrors, setDetailErrors] = useState<Set<number>>(() => new Set());
  const loadVersion = useRef(0);

  const load = useCallback(async () => {
    const version = ++loadVersion.current;
    setLoading(true);
    setLoadingAll(false);
    setExpanded(null);
    try {
      const requestPage = pageSize === "all" ? 1 : page;
      const perPage = pageSize === "all" ? 100 : Number(pageSize);
      const params = {
        ...Object.fromEntries(Object.entries(applied).filter(([, value]) => value)),
        per_page: perPage,
      };
      const response = await http.get<AuditResponse>("/admin/audit-log", {
        params: { ...params, page: requestPage },
      });
      if (version !== loadVersion.current) return;
      setData(response.data);
      setLoading(false);

      if (pageSize === "all" && response.data.pagination.last_page > 1) {
        setLoadingAll(true);
        const combined = [...response.data.data];
        for (let nextPage = 2; nextPage <= response.data.pagination.last_page; nextPage += 1) {
          const nextResponse = await http.get<AuditResponse>("/admin/audit-log", {
            params: { ...params, page: nextPage },
          });
          if (version !== loadVersion.current) return;
          combined.push(...nextResponse.data.data);
          setData({
            ...response.data,
            data: [...combined],
            pagination: { ...response.data.pagination, current_page: nextPage },
          });
        }
      }
    } catch (error) {
      if (version === loadVersion.current) {
        notify.error(getApiError(error, "Riwayat perubahan tidak dapat dimuat."));
      }
    } finally {
      if (version === loadVersion.current) {
        setLoading(false);
        setLoadingAll(false);
      }
    }
  }, [applied, page, pageSize]);

  const loadIntegrity = useCallback(async () => {
    try {
      const response = await http.get<IntegrityStatus>("/admin/audit-log/integrity");
      setIntegrity(response.data);
    } catch (error) {
      notify.error(getApiError(error, "Pemeriksaan keutuhan catatan tidak dapat dimuat."));
    }
  }, []);

  useEffect(() => {
    if (activeTab === "financial") void load();
  }, [activeTab, load]);

  useEffect(() => {
    if (activeTab !== "financial") return;
    const timer = window.setTimeout(() => void loadIntegrity(), 300);
    return () => window.clearTimeout(timer);
  }, [activeTab, loadIntegrity]);

  const permissions = useMemo(
    () => data?.filters.permission_groups.flatMap((group) => group.permissions) || [],
    [data],
  );

  const submitFilters = (event: FormEvent) => {
    event.preventDefault();
    setPage(1);
    setApplied(filters);
  };

  const resetFilters = () => {
    setFilters(defaultFilters);
    setApplied(defaultFilters);
    setPage(1);
  };

  const changePageSize = (next: string) => {
    if (next === "all" && (data?.pagination.total || 0) > 500) {
      const approved = window.confirm(
        `Ada ${data?.pagination.total.toLocaleString("id-ID")} perubahan. Memuat semuanya dapat membutuhkan waktu. Tetap lanjutkan?`,
      );
      if (!approved) return;
    }
    setPage(1);
    setPageSize(next as PageSize);
  };

  const toggleDetail = async (item: AuditItem) => {
    if (expanded === item.id && !detailErrors.has(item.id)) {
      setExpanded(null);
      return;
    }

    setExpanded(item.id);
    if (!isSuccessful(item) || details[item.id] || loadingDetails.has(item.id)) return;

    setLoadingDetails((current) => new Set(current).add(item.id));
    setDetailErrors((current) => {
      const next = new Set(current);
      next.delete(item.id);
      return next;
    });
    try {
      const response = await http.get<AuditDetail>(`/admin/audit-log/${item.id}`);
      setDetails((current) => ({ ...current, [item.id]: response.data }));
    } catch (error) {
      setDetailErrors((current) => new Set(current).add(item.id));
      notify.error(getApiError(error, "Rincian perubahan tidak dapat dimuat."));
    } finally {
      setLoadingDetails((current) => {
        const next = new Set(current);
        next.delete(item.id);
        return next;
      });
    }
  };

  const refreshAll = () => {
    void load();
    void loadIntegrity();
  };

  return (
    <AdminLayout
      title="Riwayat Perubahan"
      subtitle="Lihat siapa yang mengubah data, bagian yang diubah, dan hasil perubahannya."
    >
      <div className="mb-5 grid grid-cols-2 rounded-2xl border border-slate-200 bg-white p-1.5 shadow-sm" role="tablist" aria-label="Jenis log admin">
        <button type="button" role="tab" aria-selected={activeTab === "financial"} onClick={() => setActiveTab("financial")} className={`min-h-11 rounded-xl px-3 text-sm font-black transition ${activeTab === "financial" ? "bg-slate-950 text-white" : "text-slate-600 hover:bg-slate-50"}`}>Audit Keuangan</button>
        <button type="button" role="tab" aria-selected={activeTab === "activity"} onClick={() => setActiveTab("activity")} className={`min-h-11 rounded-xl px-3 text-sm font-black transition ${activeTab === "activity" ? "bg-slate-950 text-white" : "text-slate-600 hover:bg-slate-50"}`}>Aktivitas Admin</button>
      </div>
      {activeTab === "activity" ? <AdminActivityLogPanel /> : <div className="space-y-5 pb-8 sm:pb-12">
        <section className={`rounded-3xl border p-5 shadow-sm sm:p-6 ${integrity?.valid === false ? "border-rose-300 bg-rose-50" : integrity ? "border-emerald-200 bg-emerald-50" : "border-slate-200 bg-slate-50"}`}>
          <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-start gap-3">
              <div className={`grid h-12 w-12 shrink-0 place-items-center rounded-2xl ${integrity?.valid === false ? "bg-rose-100 text-rose-700" : integrity ? "bg-emerald-100 text-emerald-700" : "bg-white text-slate-500"}`}>
                {integrity?.valid === false ? <ShieldAlert size={25} /> : integrity ? <ShieldCheck size={25} /> : <Loader2 className="animate-spin" size={25} />}
              </div>
              <div>
                <h1 className="text-lg font-black text-slate-950">
                  {integrity?.valid === false ? "Keutuhan catatan perlu diperiksa" : integrity ? "Catatan perubahan aman" : "Sedang memeriksa catatan"}
                </h1>
                <p className="mt-1 text-sm leading-6 text-slate-600">
                  {integrity?.valid === false
                    ? "Ada catatan yang tidak sesuai dengan riwayat aslinya. Segera minta tim teknis memeriksa penyimpanan data."
                    : integrity
                      ? `${integrity.checked} catatan telah diperiksa dan tidak ditemukan perubahan di luar sistem.`
                      : "Daftar tetap dapat digunakan sambil pemeriksaan berjalan di belakang layar."}
                </p>
              </div>
            </div>
            <div className="rounded-2xl bg-white/80 px-4 py-3 text-center shadow-sm">
              <p className="text-2xl font-black text-slate-950">{data?.pagination.total || 0}</p>
              <p className="text-[10px] font-black uppercase tracking-wider text-slate-500">Total perubahan</p>
            </div>
          </div>
        </section>

        <form onSubmit={submitFilters} className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
          <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <label className="relative md:col-span-2">
              <span className="sr-only">Cari riwayat perubahan</span>
              <Search className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
              <input
                value={filters.search}
                onChange={(event) => setFilters({ ...filters, search: event.target.value })}
                placeholder="Cari admin, bagian, atau alasan perubahan"
                className="form-input pl-11"
              />
            </label>
            <ResponsiveSelect
              value={filters.actor_id}
              ariaLabel="Filter admin"
              className="form-input"
              options={[
                { value: "", label: "Semua admin" },
                ...(data?.filters.actors || []).map((actor) => ({ value: actor.id, label: actor.name })),
              ]}
              onValueChange={(next) => setFilters({ ...filters, actor_id: next })}
            />
            <ResponsiveSelect
              value={filters.status}
              ariaLabel="Filter hasil tindakan"
              className="form-input"
              options={[
                { value: "", label: "Semua hasil" },
                { value: "success", label: "Berhasil" },
                { value: "failed", label: "Gagal / ditolak" },
              ]}
              onValueChange={(next) => setFilters({ ...filters, status: next })}
            />
            <ResponsiveSelect
              value={filters.category}
              ariaLabel="Filter bagian website"
              className="form-input"
              options={[
                { value: "", label: "Semua bagian" },
                ...Object.entries(categoryLabels)
                  .filter(([category]) => category !== "unknown")
                  .map(([value, label]) => ({ value, label })),
              ]}
              onValueChange={(next) => setFilters({ ...filters, category: next })}
            />
            <ResponsiveSelect
              value={filters.permission}
              ariaLabel="Filter jenis pekerjaan"
              className="form-input"
              options={[
                { value: "", label: "Semua jenis pekerjaan" },
                ...permissions.map((permission) => ({ value: permission.code, label: permission.label })),
              ]}
              onValueChange={(next) => setFilters({ ...filters, permission: next })}
            />
            <input type="date" aria-label="Tanggal mulai" value={filters.date_from} onChange={(event) => setFilters({ ...filters, date_from: event.target.value })} className="form-input" />
            <input type="date" aria-label="Tanggal akhir" value={filters.date_to} onChange={(event) => setFilters({ ...filters, date_to: event.target.value })} className="form-input" />
          </div>
          <div className="mt-4 flex flex-col gap-2 sm:flex-row sm:justify-end">
            <button type="button" onClick={resetFilters} className="h-11 rounded-2xl border border-slate-200 px-4 text-sm font-black text-slate-700 hover:bg-slate-50">Reset</button>
            <button className="inline-flex h-11 items-center justify-center gap-2 rounded-2xl bg-slate-950 px-5 text-sm font-black text-white">
              <Search size={16} /> Terapkan filter
            </button>
            <button type="button" onClick={refreshAll} className="inline-flex h-11 items-center justify-center gap-2 rounded-2xl border border-slate-200 px-4 text-sm font-black text-slate-700 hover:bg-slate-50">
              <RefreshCw size={16} /> Muat ulang
            </button>
          </div>
        </form>

        <section className="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p className="text-sm font-black text-slate-900">Jumlah yang ditampilkan</p>
            <p className="mt-1 text-xs leading-5 text-slate-500">
              {data ? `${data.data.length} dari ${data.pagination.total} perubahan dimuat.` : "Memuat jumlah perubahan..."}
            </p>
          </div>
          <ResponsiveSelect
            value={pageSize}
            ariaLabel="Jumlah riwayat yang ditampilkan"
            className="form-input sm:w-52"
            options={[
              { value: "20", label: "20 perubahan" },
              { value: "50", label: "50 perubahan" },
              { value: "100", label: "100 perubahan" },
              { value: "all", label: "Semua hasil" },
            ]}
            onValueChange={changePageSize}
          />
        </section>

        {pageSize === "all" && (
          <section className="rounded-2xl border border-indigo-200 bg-indigo-50 p-4">
            <div className="flex items-center justify-between gap-4 text-xs font-black text-indigo-900">
              <span>{loading || loadingAll ? "Memuat semua hasil secara bertahap..." : "Semua hasil sudah dimuat"}</span>
              <span>{data?.data.length || 0} / {data?.pagination.total || 0}</span>
            </div>
            <div className="mt-3 h-2 overflow-hidden rounded-full bg-indigo-100">
              <div
                className="h-full rounded-full bg-indigo-600 transition-[width] duration-300"
                style={{ width: `${data?.pagination.total ? Math.min(100, ((data?.data.length || 0) / data.pagination.total) * 100) : 0}%` }}
              />
            </div>
            <p className="mt-2 text-xs leading-5 text-indigo-700">Gunakan filter tanggal atau bagian website jika jumlah catatan sangat besar.</p>
          </section>
        )}

        {loading ? (
          <div className="grid min-h-72 place-items-center rounded-3xl border border-slate-200 bg-white">
            <Loader2 className="animate-spin text-orange-500" size={34} />
          </div>
        ) : !data?.data.length ? (
          <div className="rounded-3xl border border-dashed border-slate-300 bg-white p-12 text-center">
            <ClipboardCheck className="mx-auto text-slate-300" size={40} />
            <p className="mt-3 text-sm font-black text-slate-600">Belum ada perubahan yang sesuai filter.</p>
          </div>
        ) : (
          <div className="space-y-3">
            {data.data.map((item) => {
              const success = isSuccessful(item);
              const isOpen = expanded === item.id;
              const location = friendlyLocation(item);
              const detail = details[item.id];
              const detailedItem = detail ? { ...item, ...detail } : item;
              const changes = detail ? auditChanges(detailedItem) : [];
              const detailLoading = loadingDetails.has(item.id);
              const detailFailed = detailErrors.has(item.id);
              return (
                <article
                  key={item.id}
                  className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm"
                  style={pageSize === "all" ? { contentVisibility: "auto", containIntrinsicSize: "140px" } : undefined}
                >
                  <button
                    type="button"
                    onClick={() => void toggleDetail(item)}
                    aria-expanded={isOpen}
                    className="flex w-full items-start gap-3 p-4 text-left sm:p-5"
                  >
                    <div className={`mt-0.5 grid h-10 w-10 shrink-0 place-items-center rounded-xl ${success ? "bg-emerald-100 text-emerald-700" : "bg-rose-100 text-rose-700"}`}>
                      {success ? <CheckCircle2 size={20} /> : <AlertTriangle size={20} />}
                    </div>
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className={`rounded-full px-2.5 py-1 text-[10px] font-black ${success ? "bg-emerald-100 text-emerald-700" : "bg-rose-100 text-rose-700"}`}>
                          {success ? "Berhasil" : "Gagal / ditolak"}
                        </span>
                        <span className="rounded-full bg-orange-100 px-2.5 py-1 text-[10px] font-black text-orange-700">
                          {categoryLabels[item.category] || "Lainnya"}
                        </span>
                      </div>
                      <p className="mt-2 text-sm font-black text-slate-950">{friendlyAction(item)}</p>
                      <p className="mt-1 text-xs font-semibold leading-5 text-indigo-700">{location}</p>
                      <p className="mt-1 text-xs leading-5 text-slate-500">{item.actor.name} · {dateTime(item.created_at)}</p>
                      {item.reason && <p className="mt-2 line-clamp-2 text-xs font-semibold text-slate-700">Alasan: {item.reason}</p>}
                    </div>
                    <ChevronDown className={`mt-2 shrink-0 text-slate-400 transition ${isOpen ? "rotate-180" : ""}`} size={19} />
                  </button>

                  {isOpen && (
                    <div className="border-t border-slate-200 bg-slate-50 p-4 sm:p-5">
                      {success && detailLoading ? (
                        <div className="flex min-h-32 items-center justify-center gap-3 rounded-2xl border border-slate-200 bg-white text-sm font-bold text-slate-600">
                          <Loader2 className="animate-spin text-indigo-600" size={20} /> Memuat rincian perubahan...
                        </div>
                      ) : success && detailFailed && !detail ? (
                        <div className="rounded-2xl border border-rose-200 bg-rose-50 p-5 text-center">
                          <p className="text-sm font-bold text-rose-800">Rincian perubahan belum berhasil dimuat.</p>
                          <button type="button" onClick={() => void toggleDetail(item)} className="mt-3 h-10 rounded-xl bg-rose-700 px-4 text-xs font-black text-white">
                            Coba lagi
                          </button>
                        </div>
                      ) : (
                        <>
                          <section className="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
                        <div className="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                          <div>
                            <h3 className="text-sm font-black text-slate-950">Apa yang berubah?</h3>
                            <p className="mt-1 text-xs leading-5 text-slate-500">
                              {item.actor.name} melakukan perubahan di {location.toLowerCase()}.
                            </p>
                          </div>
                          <span className="mt-2 w-fit rounded-full bg-slate-100 px-3 py-1 text-[10px] font-black text-slate-600 sm:mt-0">
                            {dateTime(item.created_at)}
                          </span>
                        </div>

                        {success && detail ? (
                          changes.length > 0 ? (
                            <div className="mt-4 space-y-3">
                              {changes.map((change) => <ChangeRow key={change.field} change={change} />)}
                            </div>
                          ) : (
                            <p className="mt-4 rounded-xl bg-slate-50 px-4 py-3 text-sm font-semibold leading-6 text-slate-600">
                              {fallbackChangeDescription(item)}
                            </p>
                          )
                        ) : !success ? (
                          <p className="mt-4 rounded-xl border border-rose-100 bg-rose-50 px-4 py-3 text-sm font-semibold leading-6 text-rose-800">
                            Perubahan tidak diterapkan karena permintaan gagal atau ditolak oleh sistem.
                          </p>
                        ) : null}
                      </section>

                      {item.reason && (
                        <section className="mt-3 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                          <h3 className="text-xs font-black uppercase tracking-wider text-amber-800">Alasan perubahan</h3>
                          <p className="mt-2 text-sm font-semibold leading-6 text-amber-950">{item.reason}</p>
                        </section>
                      )}
                        </>
                      )}
                    </div>
                  )}
                </article>
              );
            })}
          </div>
        )}

        {data && pageSize !== "all" && data.pagination.last_page > 1 && (
          <div className="flex items-center justify-between rounded-2xl border border-slate-200 bg-white p-3">
            <button disabled={page <= 1 || loading} onClick={() => setPage((current) => current - 1)} className="inline-flex h-10 items-center gap-1 rounded-xl px-3 text-sm font-black text-slate-700 hover:bg-slate-100 disabled:opacity-40">
              <ChevronLeft size={17} /> Sebelumnya
            </button>
            <p className="text-xs font-black text-slate-500">Halaman {data.pagination.current_page} dari {data.pagination.last_page}</p>
            <button disabled={page >= data.pagination.last_page || loading} onClick={() => setPage((current) => current + 1)} className="inline-flex h-10 items-center gap-1 rounded-xl px-3 text-sm font-black text-slate-700 hover:bg-slate-100 disabled:opacity-40">
              Berikutnya <ChevronRight size={17} />
            </button>
          </div>
        )}
      </div>}
    </AdminLayout>
  );
}

function ChangeRow({ change }: { change: AuditChange }) {
  const added = change.kind === "added";
  const removed = change.kind === "removed";
  return (
    <div className="rounded-xl border border-slate-200 p-3 sm:p-4">
      <div className="flex items-center gap-2">
        <span className={`grid h-7 w-7 shrink-0 place-items-center rounded-lg ${added ? "bg-emerald-100 text-emerald-700" : removed ? "bg-rose-100 text-rose-700" : "bg-indigo-100 text-indigo-700"}`}>
          {added ? <Plus size={15} /> : removed ? <Minus size={15} /> : <RefreshCw size={14} />}
        </span>
        <p className="text-sm font-black text-slate-900">{friendlyField(change.field)}</p>
      </div>
      {added ? (
        <div className="mt-3 rounded-lg bg-emerald-50 px-3 py-2">
          <p className="text-[10px] font-black uppercase tracking-wider text-emerald-700">Ditambahkan</p>
          <p className="mt-1 break-words text-sm font-bold text-emerald-950">{displayAuditValue(change.after, change.field)}</p>
        </div>
      ) : removed ? (
        <div className="mt-3 rounded-lg bg-rose-50 px-3 py-2">
          <p className="text-[10px] font-black uppercase tracking-wider text-rose-700">Dihapus</p>
          <p className="mt-1 break-words text-sm font-bold text-rose-950">{displayAuditValue(change.before, change.field)}</p>
        </div>
      ) : (
        <div className="mt-3 grid gap-2 sm:grid-cols-[1fr_auto_1fr] sm:items-center">
          <ValueBox label="Sebelumnya" value={displayAuditValue(change.before, change.field)} />
          <ChevronRight className="hidden text-slate-300 sm:block" size={18} />
          <ValueBox label="Menjadi" value={displayAuditValue(change.after, change.field)} highlighted />
        </div>
      )}
    </div>
  );
}

function ValueBox({ label, value, highlighted = false }: { label: string; value: string; highlighted?: boolean }) {
  return (
    <div className={`rounded-lg px-3 py-2 ${highlighted ? "bg-indigo-50" : "bg-slate-50"}`}>
      <p className={`text-[10px] font-black uppercase tracking-wider ${highlighted ? "text-indigo-600" : "text-slate-400"}`}>{label}</p>
      <p className={`mt-1 break-words text-sm font-bold ${highlighted ? "text-indigo-950" : "text-slate-700"}`}>{value}</p>
    </div>
  );
}

function auditChanges(item: AuditItem): AuditChange[] {
  const before = item.before_state || {};
  const after = item.after_state || {};
  const method = item.method.toUpperCase();
  const sourceAfter = Object.keys(after).length > 0 ? after : method === "POST" ? item.request_payload || {} : after;
  const keys = Array.from(new Set([...Object.keys(before), ...Object.keys(sourceAfter)]));

  return keys
    .filter((key) => isReadableField(key))
    .filter((key) => JSON.stringify(before[key]) !== JSON.stringify(sourceAfter[key]))
    .map((field) => {
      const beforeValue = before[field];
      const afterValue = sourceAfter[field];
      if (isEmptyAuditValue(beforeValue) && !isEmptyAuditValue(afterValue)) {
        return { field, kind: "added" as const, after: afterValue };
      }
      if (!isEmptyAuditValue(beforeValue) && isEmptyAuditValue(afterValue)) {
        return { field, kind: "removed" as const, before: beforeValue };
      }
      return { field, kind: "changed" as const, before: beforeValue, after: afterValue };
    });
}

function friendlyLocation(item: AuditItem): string {
  const path = normalizePath(item.route_name || item.action);
  const role = auditRole(item);

  if (/^users(?:\/|$)/.test(path)) {
    if (role === "student") return "Halaman Siswa (Admin)";
    if (role === "teacher") return "Halaman Tutor (Admin)";
    return "Halaman Pengguna (Admin)";
  }

  const pages: Array<[RegExp, string]> = [
    [/^tutor-searches(?:\/|$)/, "Halaman Pencarian Tutor (Admin)"],
    [/^(?:pending-payments|finance\/payments|verify-payment|orders)(?:\/|$)/, "Halaman Pembayaran Murid (Admin)"],
    [/^payment-settings(?:\/|$)/, "Halaman Rekening Penerimaan (Admin)"],
    [/^(?:finance\/refunds|refunds)(?:\/|$)/, "Halaman Refund dan Saldo (Admin)"],
    [/^(?:finance|payout|commission-setting)(?:\/|$)/, "Halaman Pencairan Tutor (Admin)"],
    [/^(?:pending-teachers|history-teachers|verify-teacher)(?:\/|$)/, "Halaman Verifikasi Tutor (Admin)"],
    [/^(?:cases|disputes|session-reports)(?:\/|$)/, "Halaman Pusat Kasus (Admin)"],
    [/^bookings\/.+\/completion-review(?:\/|$)/, "Halaman Pusat Kasus (Admin)"],
    [/^classes(?:\/|$)/, "Halaman Monitoring Kelas (Admin)"],
    [/^ratings(?:\/|$)/, "Halaman Ulasan Kelas (Admin)"],
    [/^notifications(?:\/|$)/, "Halaman Pesan dan Notifikasi (Admin)"],
    [/^tickets(?:\/|$)/, "Halaman Pusat Bantuan (Admin)"],
    [/^hourly-rates(?:\/|$)/, "Halaman Tarif Belajar (Admin)"],
    [/^(?:subjects|chapters)(?:\/|$)/, "Halaman Mata Pelajaran dan Bab (Admin)"],
    [/^(?:stage-five|cheap-class-templates|cheap-classes)(?:\/|$)/, "Halaman Program Belajar (Admin)"],
    [/^settings\/footer(?:\/|$)/, "Halaman Footer Website (Admin)"],
    [/^(?:admin-socials|socials)(?:\/|$)/, "Halaman Media Sosial (Admin)"],
    [/^settings\/teacher-cover(?:\/|$)/, "Halaman Tampilan Tutor (Admin)"],
    [/^notes(?:\/|$)/, "Halaman Catatan Internal (Admin)"],
    [/^audit-log(?:\/|$)/, "Halaman Riwayat Perubahan (Admin)"],
  ];
  return pages.find(([pattern]) => pattern.test(path))?.[1]
    || `Bagian ${categoryLabels[item.category] || "Administrasi"} (Admin)`;
}

function friendlyAction(item: AuditItem): string {
  const target = friendlyTarget(item);
  const verb = actionVerb(item);
  if (!isSuccessful(item)) return `Gagal ${verb.toLowerCase()} ${target.toLowerCase()}`;
  return `${verb} ${target.toLowerCase()}`;
}

function actionVerb(item: AuditItem): string {
  const path = normalizePath(item.route_name || item.action);
  if (/(?:^|\/)(?:verify|verify-payment|verify-teacher)(?:\/|$)/.test(path)) return "Memverifikasi";
  if (/(?:^|\/)synchronize(?:\/|$)/.test(path)) return "Menyinkronkan";
  if (/(?:^|\/)assign(?:\/|$)/.test(path)) return "Menetapkan";
  if (/(?:^|\/)expand(?:\/|$)/.test(path)) return "Memperluas";
  if (/(?:^|\/)approve(?:\/|$)/.test(path)) return "Menyetujui";
  if (/(?:^|\/)reject(?:\/|$)/.test(path)) return "Menolak";
  if (/(?:^|\/)cancel(?:\/|$)/.test(path)) return "Membatalkan";
  if (/(?:^|\/)send(?:\/|$)/.test(path)) return "Mengirim";
  if (/(?:^|\/)(?:complete|completion-review|resolve)(?:\/|$)/.test(path)) return "Menyelesaikan";
  if (/(?:^|\/)(?:toggle-status|activate|deactivate|block|unblock|pin|unpin)(?:\/|$)/.test(path)) return "Mengubah";
  if (/^(?:finance\/refunds|refunds)(?:\/|$)/.test(path)) return "Memproses";
  if (item.method.toUpperCase() === "DELETE") return "Menghapus";
  if (item.method.toUpperCase() === "POST" && !/\/[0-9]+(?:\/|$)/.test(path)) return "Menambahkan";
  if (item.method.toUpperCase() === "POST") return "Memproses";
  return "Mengubah";
}

function friendlyTarget(item: AuditItem): string {
  const path = normalizePath(item.route_name || item.action);
  const role = auditRole(item);
  if (/^users(?:\/|$)/.test(path)) {
    if (role === "student") return "data siswa";
    if (role === "teacher") return "data tutor";
    return "data pengguna";
  }

  const targets: Array<[RegExp, string]> = [
    [/^tutor-searches(?:\/|$)/, "pencarian tutor"],
    [/^(?:pending-payments|finance\/payments|verify-payment|orders)(?:\/|$)/, "pembayaran murid"],
    [/^payment-settings(?:\/|$)/, "rekening penerimaan"],
    [/^(?:finance\/refunds|refunds)(?:\/|$)/, "refund atau saldo murid"],
    [/^(?:finance|payout|commission-setting)(?:\/|$)/, "pencairan tutor"],
    [/^(?:pending-teachers|history-teachers|verify-teacher)(?:\/|$)/, "verifikasi tutor"],
    [/^(?:cases|disputes|session-reports|bookings)(?:\/|$)/, "penanganan kasus"],
    [/^classes(?:\/|$)/, "data kelas"],
    [/^ratings(?:\/|$)/, "ulasan kelas"],
    [/^notifications(?:\/|$)/, "pesan atau notifikasi"],
    [/^tickets(?:\/|$)/, "tiket bantuan"],
    [/^hourly-rates(?:\/|$)/, "tarif belajar"],
    [/^subjects(?:\/|$)/, "mata pelajaran"],
    [/^chapters(?:\/|$)/, "bab pelajaran"],
    [/^(?:stage-five|cheap-class-templates|cheap-classes)(?:\/|$)/, "program belajar"],
    [/^settings\/footer(?:\/|$)/, "footer website"],
    [/^(?:admin-socials|socials)(?:\/|$)/, "media sosial"],
    [/^settings\/teacher-cover(?:\/|$)/, "tampilan tutor"],
    [/^notes(?:\/|$)/, "catatan internal"],
  ];
  return targets.find(([pattern]) => pattern.test(path))?.[1]
    || `data ${categoryLabels[item.category]?.toLowerCase() || "administrasi"}`;
}

function auditRole(item: AuditItem): string {
  return String(item.target_role ?? item.after_state?.role ?? item.before_state?.role ?? item.request_payload?.role ?? "").toLowerCase();
}

function fallbackChangeDescription(item: AuditItem): string {
  if (item.method.toUpperCase() === "DELETE") return `${capitalize(friendlyTarget(item))} telah dihapus.`;
  if (item.method.toUpperCase() === "POST") return `${capitalize(friendlyTarget(item))} telah ditambahkan.`;
  return "Perubahan berhasil disimpan. Tidak ada rincian nilai yang perlu ditampilkan.";
}

function friendlyField(value: string): string {
  return fieldLabels[value] || value.replace(/[_-]+/g, " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function displayAuditValue(value: unknown, field = ""): string {
  if (value === null || value === undefined || value === "") return "Kosong";
  if (typeof value === "boolean") return value ? "Ya" : "Tidak";
  if ((field === "is_active" || field === "active") && (value === 1 || value === 0 || value === "1" || value === "0")) {
    return String(value) === "1" ? "Aktif" : "Tidak aktif";
  }
  if (field === "search_radius_km") return `${value} km`;
  if (["price", "amount", "hourly_rate"].includes(field) && !Number.isNaN(Number(value))) {
    return new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(Number(value));
  }
  if (Array.isArray(value)) return value.map((entry) => displayAuditValue(entry)).join(", ") || "Kosong";
  if (typeof value === "object") {
    return Object.entries(value as Record<string, unknown>)
      .filter(([key]) => isReadableField(key))
      .map(([key, entry]) => `${friendlyField(key)}: ${displayAuditValue(entry, key)}`)
      .join(" · ") || "Data diperbarui";
  }
  const translated: Record<string, string> = {
    student: "Siswa",
    teacher: "Tutor",
    admin: "Admin",
    active: "Aktif",
    inactive: "Tidak aktif",
    pending: "Menunggu",
    approved: "Disetujui",
    rejected: "Ditolak",
    completed: "Selesai",
    cancelled: "Dibatalkan",
    online: "Online",
    offline: "Tatap muka",
    hybrid: "Online dan tatap muka",
  };
  return translated[String(value).toLowerCase()] || String(value);
}

function isReadableField(field: string): boolean {
  const normalized = field.toLowerCase();
  return !technicalFields.has(normalized)
    && !normalized.endsWith("_id")
    && !normalized.includes("token")
    && !normalized.includes("hash")
    && !normalized.includes("secret")
    && !normalized.includes("password");
}

function isEmptyAuditValue(value: unknown): boolean {
  return value === null || value === undefined || value === "";
}

function isSuccessful(item: AuditItem): boolean {
  return item.response_status >= 200 && item.response_status < 400;
}

function normalizePath(value: string): string {
  return value
    .replace(/^[A-Z]+:/i, "")
    .replace(/^\/?api\/admin\/?/i, "")
    .replace(/^\/?admin\/?/i, "")
    .replace(/^\//, "");
}

function capitalize(value: string): string {
  return value.charAt(0).toUpperCase() + value.slice(1);
}
