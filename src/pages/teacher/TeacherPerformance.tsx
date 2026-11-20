import { notify } from "@/lib/notify";
import { useCallback, useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { Loader2, RefreshCw, ShieldAlert, Star } from "lucide-react";
import TeacherLayout from "@/components/TeacherLayout";
import { Button } from "@/components/ui/button";
import http, { getApiError } from "@/lib/http";
import WorkspacePageIntro from "@/components/WorkspacePageIntro";

type RatingItem = { id: number; student_name: string; subject?: string | null; rating: number; review?: string | null; created_at: string };
type PerformanceData = {
  suspended_until?: string | null;
  rating: { average: number; count: number; distribution: Record<string, number> };
  ratings: RatingItem[];
};

const dateTime = (value?: string | null) => value
  ? new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short" }).format(new Date(value))
  : "-";

export default function TeacherPerformance() {
  const [data, setData] = useState<PerformanceData | null>(null);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setFailed(false);
    try {
      const response = await http.get<PerformanceData>("/teacher/performance");
      setData(response.data);
    } catch (error) {
      setFailed(true);
      notify.error(getApiError(error, "Performa tutor gagal dimuat."));
    } finally {
      setLoading(false);
    }
  }, []);
  useEffect(() => { void load(); }, [load]);

  const maxDistribution = useMemo(
    () => Math.max(1, ...Object.values(data?.rating.distribution || {})),
    [data],
  );

  return (
    <TeacherLayout title="Performa">
      <div className="space-y-5 pb-10 sm:space-y-7">
        <WorkspacePageIntro eyebrow="Kualitas mengajar" title="Rating dan ulasan" description="Nilai berasal dari murid yang menyelesaikan sesi. Rating menjadi rekam mutu, bukan satu-satunya penentu penawaran kelas." icon={Star} tone="orange" actions={<Button variant="outline" onClick={() => void load()} className="h-11 w-full rounded-xl sm:w-auto"><RefreshCw size={16} className="mr-2" />Muat ulang</Button>} />

        {loading ? (
          <div className="grid min-h-80 place-items-center rounded-[2rem] bg-white"><Loader2 className="animate-spin text-indigo-600" size={30} /></div>
        ) : failed || !data ? (
          <div className="rounded-[2rem] bg-white p-16 text-center"><ShieldAlert className="mx-auto text-rose-300" /><p className="mt-3 font-black">Data belum dapat dimuat</p><Button onClick={() => void load()} className="mt-4 rounded-xl bg-indigo-600">Coba lagi</Button></div>
        ) : (
          <>
            <section className="rounded-[1.7rem] border border-indigo-100 bg-indigo-50/70 p-4 sm:rounded-[2rem] sm:p-6">
              <p className="text-xs font-black uppercase tracking-[.16em] text-indigo-600">Banding akun</p>
              <h2 className="mt-2 text-lg font-black text-slate-900">Butuh peninjauan akun?</h2>
              <p className="mt-1 max-w-3xl text-sm leading-6 text-slate-600">Pengajuan banding akun belum tersedia melalui halaman ini. Hubungi admin lewat Pusat Bantuan agar status akun dan langkah selanjutnya dapat diperiksa.</p>
              <Link to="/guru/bantuan" className="mt-4 inline-flex min-h-11 items-center justify-center rounded-xl bg-indigo-600 px-4 text-sm font-black text-white hover:bg-indigo-700">Buka Pusat Bantuan</Link>
            </section>
            <section className="grid gap-3 sm:grid-cols-2">
              <Metric label="Nilai rata-rata" value={data.rating.count ? data.rating.average.toFixed(1) + " / 5" : "-"} detail={data.rating.count + " penilaian terverifikasi"} />
              <Metric label="Status menerima kelas" value={data.suspended_until ? "Dijeda sementara" : "Aktif"} detail={data.suspended_until ? "Sampai " + dateTime(data.suspended_until) + " WIB" : "Tidak ada pembatasan respons"} />
            </section>
            <section className="grid gap-5 rounded-[1.7rem] border border-slate-200 bg-white p-4 shadow-sm sm:rounded-[2rem] sm:p-6 lg:grid-cols-[16rem_1fr]">
              <div className="rounded-2xl bg-slate-50 p-5">
                <p className="text-sm font-black text-slate-900">Sebaran nilai</p>
                <div className="mt-4 space-y-3">{[5, 4, 3, 2, 1].map((star) => {
                  const count = data.rating.distribution[String(star)] || 0;
                  return <div key={star} className="grid grid-cols-[2rem_1fr_2rem] items-center gap-2 text-xs"><span className="font-black text-amber-600">{star}★</span><span className="h-2 overflow-hidden rounded-full bg-white"><span className="block h-full rounded-full bg-amber-400" style={{ width: count / maxDistribution * 100 + "%" }} /></span><span className="text-right font-bold text-slate-500">{count}</span></div>;
                })}</div>
              </div>
              <div className="space-y-3">{data.ratings.length ? data.ratings.map((item) => (
                <article key={item.id} className="rounded-2xl border border-slate-100 p-4">
                  <div className="flex items-start justify-between gap-3"><div><p className="font-black text-slate-900">{item.student_name}</p><p className="mt-1 text-xs text-slate-400">{item.subject || "Bimbingan"} · {dateTime(item.created_at)}</p></div><span className="shrink-0 rounded-full bg-amber-50 px-3 py-1 text-xs font-black text-amber-700">{item.rating} ★</span></div>
                  <p className="mt-3 break-words text-sm leading-6 text-slate-600">{item.review || "Tidak ada ulasan tertulis."}</p>
                </article>
              )) : <div className="py-16 text-center text-sm text-slate-500">Belum ada penilaian murid.</div>}</div>
            </section>
          </>
        )}
      </div>
    </TeacherLayout>
  );
}

function Metric({ label, value, detail }: { label: string; value: string; detail: string }) {
  return <div className="flex min-w-0 items-center gap-3 rounded-[1.4rem] border border-slate-100 bg-white p-4 shadow-sm sm:p-5"><span className="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-amber-500 text-white"><Star size={19} /></span><span className="min-w-0"><span className="block text-[10px] font-black uppercase tracking-wider text-slate-400">{label}</span><span className="mt-1 block text-xl font-black text-slate-900">{value}</span><span className="mt-1 block break-words text-[11px] text-slate-500">{detail}</span></span></div>;
}
