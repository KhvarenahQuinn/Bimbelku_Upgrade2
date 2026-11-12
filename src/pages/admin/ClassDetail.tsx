import { notify } from "@/lib/notify";
import { useState, useEffect } from "react";
import { useParams, useNavigate } from "react-router-dom";
import AdminLayout from "../../components/AdminLayout";
import http, { getApiError } from "@/lib/http";
import {
  ArrowLeft,
  Calendar,
  MapPin,
  Video,
  CheckCircle2,
  Clock,
  Loader2,
  Mail,
  Phone,
  WalletCards,
  Users,
  GraduationCap,
} from "lucide-react";

const rupiah = (value: number) =>
  new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(value || 0);

const dateTimeRange = (start: string, end: string) => {
  const startDate = new Date(start);
  const endDate = new Date(end);
  return `${startDate.toLocaleDateString("id-ID", { day: "numeric", month: "short", year: "numeric" })} · ${startDate.toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" })}–${endDate.toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" })}`;
};

export default function ClassDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [data, setData] = useState<any>(null);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    const fetchData = async () => {
      try {
        const res = await http.get(`/admin/classes/${id}`);
        setData(res.data);
      } catch (error) {
        notify.error(getApiError(error, "Detail kelas gagal dimuat."));
      } finally {
        setIsLoading(false);
      }
    };
    void fetchData();
  }, [id]);

  if (isLoading) return (
    <AdminLayout title="Detail Kelas">
      <div className="flex h-[70vh] items-center justify-center"><Loader2 className="animate-spin text-indigo-500" size={40} /></div>
    </AdminLayout>
  );

  if (!data) return (
    <AdminLayout title="Detail Kelas">
      <div className="grid min-h-[60vh] place-items-center text-center">
        <div><p className="font-black text-slate-800">Kelas tidak dapat ditampilkan</p><button onClick={() => navigate(-1)} className="mt-3 text-sm font-bold text-indigo-600">Kembali</button></div>
      </div>
    </AdminLayout>
  );

  const progress = Math.max(0, Math.min(100, Number(data.info.progress) || 0));
  const commission = Number(data.info.gross_amount) - Number(data.info.teacher_net_amount);
  const students = data.students || [];
  const sessions = data.sessions || [];

  return (
    <AdminLayout title="Detail Kelas">
      <div className="w-full space-y-6 animate-in fade-in slide-in-from-bottom-3 duration-300">
        <button
          onClick={() => navigate(-1)}
          className="inline-flex min-h-11 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-black text-slate-600 shadow-sm transition hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700"
        >
          <ArrowLeft size={17} /> Kembali ke Monitoring
        </button>

        <section className="overflow-hidden rounded-[2rem] border border-slate-100 bg-white shadow-[0_18px_55px_rgba(15,23,42,0.06)]">
          <div className="grid gap-6 p-5 sm:p-7 lg:grid-cols-[minmax(0,1fr)_220px] lg:items-center lg:p-8">
            <div className="min-w-0">
              <div className="flex flex-wrap gap-2">
                <span className={`rounded-full px-3 py-1 text-[10px] font-black uppercase tracking-wider ${data.info.method === "online" ? "bg-blue-50 text-blue-700" : "bg-emerald-50 text-emerald-700"}`}>
                  {data.info.method}
                </span>
                <span className="rounded-full bg-slate-100 px-3 py-1 text-[10px] font-black uppercase tracking-wider text-slate-600">{data.info.type}</span>
                <span className="rounded-full bg-slate-100 px-3 py-1 font-mono text-[10px] font-black tracking-wider text-slate-600">Sesi #{data.info.id}</span>
                <span className="rounded-full bg-indigo-50 px-3 py-1 text-[10px] font-black text-indigo-700">{data.info.status_label}</span>
              </div>

              <h1 className="mt-4 break-words text-2xl font-black leading-tight text-slate-950 sm:text-3xl">{data.info.title}</h1>
              <div className="mt-4 flex flex-col gap-2 text-sm font-semibold text-slate-500 sm:flex-row sm:flex-wrap sm:gap-x-5">
                <span className="inline-flex min-w-0 items-center gap-2">
                  {data.info.method === "online" ? <Video size={16} className="shrink-0 text-indigo-500" /> : <MapPin size={16} className="shrink-0 text-emerald-500" />}
                  <span className="break-words">{data.info.location}</span>
                </span>
                <span className="inline-flex items-center gap-2"><Clock size={15} className="shrink-0 text-slate-400" />{dateTimeRange(data.info.start_at, data.info.end_at)}</span>
              </div>
            </div>

            <div className="rounded-2xl border border-indigo-100 bg-indigo-50/70 p-4 sm:p-5">
              <div className="flex items-end justify-between gap-3 lg:block">
                <div>
                  <p className="text-[10px] font-black uppercase tracking-[.16em] text-indigo-400">Progress kelas</p>
                  <p className="mt-1 text-3xl font-black text-indigo-700 sm:text-4xl">{progress}%</p>
                </div>
                <span className="rounded-full bg-white px-2.5 py-1 text-[10px] font-black text-indigo-700 shadow-sm lg:mt-2 lg:inline-flex">{data.info.status_label}</span>
              </div>
              <div className="mt-4 h-2 overflow-hidden rounded-full bg-white">
                <div className="h-full rounded-full bg-indigo-600 transition-all" style={{ width: `${progress}%` }} />
              </div>
            </div>
          </div>

          <div className="border-t border-slate-100 bg-slate-50/60 p-4 sm:p-5 lg:px-8">
            <div className="mb-3 flex items-center gap-2">
              <WalletCards size={16} className="text-slate-500" />
              <h2 className="text-xs font-black uppercase tracking-[.14em] text-slate-500">Ringkasan keuangan</h2>
            </div>
            <div className="grid grid-cols-1 gap-px overflow-hidden rounded-2xl border border-slate-200 bg-slate-200 min-[360px]:grid-cols-2 lg:grid-cols-4">
              <Metric label="Nilai per murid" value={rupiah(Number(data.info.price))} />
              <Metric label="Dana terverifikasi" value={rupiah(Number(data.info.gross_amount))} />
              <Metric label={`Komisi admin (${Number(data.info.commission_percent)}%)`} value={rupiah(commission)} />
              <Metric label="Hak tutor" value={rupiah(Number(data.info.teacher_net_amount))} accent />
            </div>
          </div>
        </section>

        <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1.35fr)_minmax(19rem,.65fr)]">
          <section className="overflow-hidden rounded-[2rem] border border-slate-100 bg-white shadow-sm">
            <header className="flex items-center justify-between gap-4 border-b border-slate-100 px-5 py-5 sm:px-6">
              <div className="flex min-w-0 items-center gap-3">
                <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-indigo-50 text-indigo-600"><Calendar size={19} /></span>
                <div className="min-w-0"><h2 className="font-black text-slate-900">Timeline Pertemuan</h2><p className="mt-0.5 text-xs font-semibold text-slate-500">{sessions.length} sesi dalam kelas ini</p></div>
              </div>
              <span className="hidden rounded-full bg-slate-100 px-3 py-1 text-[10px] font-black uppercase tracking-wider text-slate-500 sm:inline-flex">Jadwal & status</span>
            </header>

            <div className="p-4 sm:p-6">
              {sessions.length === 0 ? (
                <div className="rounded-2xl border border-dashed border-slate-200 px-5 py-10 text-center text-sm font-semibold text-slate-500">Belum ada jadwal pertemuan.</div>
              ) : (
                <ol className="space-y-3">
                  {sessions.map((sess: any, idx: number) => (
                    <li key={sess.id} className="flex min-w-0 flex-wrap gap-3 rounded-2xl border border-slate-100 bg-slate-50/70 p-4 sm:flex-nowrap sm:items-center">
                      <span className={`mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-xl text-xs font-black sm:mt-0 ${sess.is_completed ? "bg-emerald-100 text-emerald-700" : "bg-white text-slate-500 shadow-sm"}`}>{idx + 1}</span>
                      <div className="min-w-0 flex-1">
                        <p className="break-words text-sm font-black text-slate-800">Sesi #{sess.id} · Pertemuan {idx + 1}: {sess.title}</p>
                        <p className="mt-1 flex items-center gap-1.5 text-xs font-semibold text-slate-500"><Clock size={12} />{sess.date}</p>
                      </div>
                      <span className={`ml-12 max-w-full break-words rounded-full px-3 py-1.5 text-[11px] font-black sm:ml-0 sm:shrink-0 ${sess.is_completed ? "bg-emerald-100 text-emerald-700" : "bg-slate-200 text-slate-600"}`}>
                        {sess.is_completed ? <span className="inline-flex items-center gap-1.5"><CheckCircle2 size={12} />Selesai</span> : "Belum mulai"}
                      </span>
                    </li>
                  ))}
                </ol>
              )}
            </div>
          </section>

          <aside className="space-y-5">
            <section className="rounded-[2rem] border border-slate-100 bg-white p-5 shadow-sm sm:p-6">
              <div className="mb-5 flex items-center justify-between gap-3">
                <div><p className="text-[10px] font-black uppercase tracking-[.16em] text-slate-400">Pengajar</p><h2 className="mt-1 font-black text-slate-900">Tutor kelas</h2></div>
                <span className="grid h-10 w-10 place-items-center rounded-xl bg-indigo-50 text-indigo-600"><GraduationCap size={18} /></span>
              </div>
              <div className="flex items-center gap-4">
                {data.teacher.photo ? (
                  <img src={data.teacher.photo} alt={`Foto ${data.teacher.name}`} loading="lazy" decoding="async" className="h-12 w-12 rounded-2xl object-cover shadow-sm" />
                ) : (
                  <div className="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-indigo-100 font-black text-indigo-700">{data.teacher.name.charAt(0)}</div>
                )}
                <div className="min-w-0 flex-1"><h3 className="truncate font-black text-slate-900">{data.teacher.name}</h3><p className="mt-1 text-xs font-semibold text-slate-500">Tutor terhubung ke kelas ini</p></div>
              </div>
              {(data.teacher.phone || data.teacher.email) && <div className="mt-4 grid grid-cols-1 gap-2 min-[360px]:grid-cols-2">
                {data.teacher.phone && <a href={`https://wa.me/${String(data.teacher.phone).replace(/\D/g, "").replace(/^0/, "62")}`} target="_blank" rel="noreferrer" className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-emerald-50 text-xs font-black text-emerald-700 hover:bg-emerald-100"><Phone size={14} />WhatsApp</a>}
                {data.teacher.email && <a href={`mailto:${data.teacher.email}`} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-blue-50 text-xs font-black text-blue-700 hover:bg-blue-100"><Mail size={14} />Email</a>}
              </div>}
            </section>

            <section className="overflow-hidden rounded-[2rem] border border-slate-100 bg-white shadow-sm">
              <header className="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-5 sm:px-6">
                <div className="flex items-center gap-3"><span className="grid h-10 w-10 place-items-center rounded-xl bg-orange-50 text-orange-600"><Users size={18} /></span><div><p className="text-[10px] font-black uppercase tracking-[.16em] text-slate-400">Peserta</p><h2 className="font-black text-slate-900">Daftar Murid</h2></div></div>
                <span className="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-black text-slate-600">{students.length} murid</span>
              </header>
              <div className="max-h-[28rem] divide-y divide-slate-100 overflow-y-auto px-4 sm:px-5">
                {students.length === 0 ? <p className="py-8 text-center text-sm font-semibold text-slate-500">Belum ada murid di kelas ini.</p> : students.map((student: any) => (
                  <div key={student.id} className="flex items-center gap-3 py-4">
                    <div className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-orange-100 text-xs font-black text-orange-700">{student.name.charAt(0)}</div>
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-black text-slate-800">{student.name}</p>
                      <p className="mt-0.5 truncate text-[10px] font-medium text-slate-400">{student.email}</p>
                      <p className="mt-1 text-[10px] font-black text-indigo-600">{student.status} · {student.order_status || "tanpa tagihan"}</p>
                    </div>
                    {student.phone && <a href={`https://wa.me/${String(student.phone).replace(/\D/g, "").replace(/^0/, "62")}`} target="_blank" rel="noreferrer" aria-label={`Hubungi ${student.name} melalui WhatsApp`} className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-emerald-50 text-emerald-600 hover:bg-emerald-100"><Phone size={14} /></a>}
                  </div>
                ))}
              </div>
            </section>
          </aside>
        </div>
      </div>
    </AdminLayout>
  );
}

function Metric({ label, value, accent = false }: { label: string; value: string; accent?: boolean }) {
  return (
    <div className="min-w-0 bg-white p-4 sm:p-5">
      <p className="text-[9px] font-black uppercase tracking-[.14em] text-slate-400 sm:text-[10px]">{label}</p>
      <p className={`mt-2 break-words text-sm font-black sm:text-base ${accent ? "text-emerald-700" : "text-slate-900"}`}>{value}</p>
    </div>
  );
}
