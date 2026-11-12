import { notify } from "@/lib/notify";
import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Bell, ChevronRight, CircleHelp, FileText, Landmark, Loader2, ShieldCheck, UserRound } from "lucide-react";
import TeacherLayout from "@/components/TeacherLayout";
import LogoutButton from "@/components/LogoutButton";
import { getCached } from "@/lib/http";

type ProfileData = {
  user?: { name?: string; email?: string; status?: string };
  profile?: { photo_url?: string | null; expertise?: string; is_accepting_requests?: boolean; bank_name?: string | null; account_number?: string | null; payout_hold_until?: string | null };
};

const links = [
  { to: "/guru/profil", label: "Data diri", detail: "Identitas, kompetensi, dan dokumen pendukung", icon: UserRound, color: "bg-indigo-50 text-indigo-600" },
  { to: "/guru/keamanan", label: "Keamanan akun", detail: "Kata sandi dan akses masuk akun", icon: ShieldCheck, color: "bg-emerald-50 text-emerald-600" },
  { to: "/guru/pencairan/rekening", label: "Rekening pencairan", detail: "Nomor rekening tujuan transfer pendapatan", icon: Landmark, color: "bg-sky-50 text-sky-600" },
  { to: "/guru/notifikasi", label: "Notifikasi", detail: "Semua pemberitahuan pekerjaan tutor", icon: Bell, color: "bg-rose-50 text-rose-600" },
  { to: "/guru/bantuan", label: "Pusat bantuan", detail: "Panduan dan bantuan penggunaan", icon: CircleHelp, color: "bg-slate-100 text-slate-600" },
  { to: "/privacy", label: "Privasi data", detail: "Cara Bimbelku menjaga informasi Anda", icon: FileText, color: "bg-orange-50 text-orange-600" },
];

export default function TeacherAccount() {
  const [data, setData] = useState<ProfileData | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    getCached<ProfileData>("/teacher/profile", { maxAgeMs: 60_000 })
      .then((response) => setData(response.data))
      .catch(() => notify.error("Data akun tutor gagal dimuat."))
      .finally(() => setLoading(false));
  }, []);

  return (
    <TeacherLayout title="Saya">
      <div className="space-y-5 pb-8 sm:space-y-7">
        <section className="relative overflow-hidden rounded-[1.7rem] border border-[#174861] bg-gradient-to-br from-[#14213D] via-[#174861] to-[#116B70] p-5 text-white shadow-[0_18px_38px_rgba(20,33,61,.19)] sm:rounded-[2rem] sm:p-8">
          <div className="pointer-events-none absolute -right-10 -top-12 h-40 w-40 rounded-full border-8 border-[#F6B94A]/35" />
          <div className="pointer-events-none absolute bottom-0 left-[38%] h-44 w-44 rounded-full bg-[#F6B94A]/10 blur-3xl" />
          {loading ? <div className="grid min-h-32 place-items-center"><Loader2 className="animate-spin text-orange-500" /></div> : (
            <div className="relative grid gap-5 lg:grid-cols-[1fr_auto] lg:items-center">
              <div className="flex min-w-0 items-center gap-4 sm:gap-5">
                <span className="grid h-16 w-16 shrink-0 place-items-center overflow-hidden rounded-[1.4rem] bg-slate-950 text-xl font-black text-white shadow-md sm:h-20 sm:w-20">
                  {data?.profile?.photo_url ? <img src={data.profile.photo_url} alt="Foto profil tutor" loading="lazy" decoding="async" className="h-full w-full object-cover" /> : data?.user?.name?.charAt(0) || "T"}
                </span>
                <div className="min-w-0 flex-1">
                  <p className="text-[10px] font-black uppercase tracking-[0.18em] text-[#F6B94A]">Akun tutor</p>
                  <p className="break-words text-xl font-black leading-tight text-white [overflow-wrap:anywhere] sm:text-2xl">{data?.user?.name || "Tutor BimbelKu"}</p>
                  <p className="mt-1 truncate text-xs text-white/80 sm:text-sm">{data?.user?.email}</p>
                  <div className="mt-3 flex flex-wrap gap-2"><span className="rounded-full bg-emerald-100 px-3 py-1 text-[10px] font-black uppercase tracking-wider text-emerald-800"><ShieldCheck size={12} className="mr-1 inline" />Tutor terverifikasi</span></div>
                </div>
              </div>
              <Link to="/guru/profil" className="inline-flex min-h-11 items-center justify-center rounded-full bg-[#F6B94A] px-5 text-sm font-black text-[#14213D] transition hover:-translate-y-0.5 hover:bg-[#FFD77E]">Edit profil</Link>
              <div className="grid gap-3 sm:grid-cols-2 lg:col-span-2">
                <div className="rounded-2xl border border-white/20 bg-white/10 p-4 backdrop-blur-sm"><p className="text-[10px] font-black uppercase tracking-wider text-[#F6B94A]">Bidang mengajar</p><p className="mt-1 font-extrabold text-white">{data?.profile?.expertise || "Lengkapi bidang keahlian"}</p></div>
                <div className="rounded-2xl border border-white/20 bg-white/10 p-4 backdrop-blur-sm"><p className="text-[10px] font-black uppercase tracking-wider text-[#F6B94A]">Status permintaan</p><p className="mt-1 font-extrabold text-white">{data?.profile?.is_accepting_requests ? "Sedang menerima murid" : "Tidak menerima sementara"}</p></div>
              </div>
            </div>
          )}
        </section>

        {!loading && (
          <section className="rounded-[1.4rem] border border-slate-100 bg-white p-4 shadow-sm sm:p-5">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <div className="flex min-w-0 items-start gap-3">
                <span className="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-sky-50 text-sky-600"><Landmark size={19} /></span>
                <div className="min-w-0">
                  <p className="text-sm font-black text-slate-900">Rekening pencairan</p>
                  <p className="mt-1 text-xs leading-5 text-slate-500">
                    {data?.profile?.bank_name && data?.profile?.account_number
                      ? `${data.profile.bank_name} · ••••${String(data.profile.account_number).replace(/\D+/g, "").slice(-4)}`
                      : "Rekening belum lengkap. Rekening disimpan saat pertama kali mencairkan dana."}
                  </p>
                  {data?.profile?.payout_hold_until && new Date(data.profile.payout_hold_until).getTime() > Date.now() && (
                    <p className="mt-1 text-xs font-bold text-amber-700">Pencairan ditahan sampai {new Date(data.profile.payout_hold_until).toLocaleString("id-ID")}.</p>
                  )}
                </div>
              </div>
              <Link to="/guru/pencairan/rekening" className="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-slate-900 px-4 text-sm font-black text-white transition hover:bg-slate-800">
                {data?.profile?.bank_name && data?.profile?.account_number ? "Periksa saat pencairan" : "Lengkapi rekening"}
                <ChevronRight size={16} />
              </Link>
            </div>
          </section>
        )}

        <section>
          <div className="mb-4"><h2 className="text-xl font-black text-slate-950">Pengaturan akun</h2><p className="mt-1 text-sm text-slate-600">Jadwal, kelas, pendapatan, dan performa tetap tersedia di navigasi utama agar tidak berulang di halaman ini.</p></div>
          <div className="grid gap-3 sm:grid-cols-2">
            {links.map(({ to, label, detail, icon: Icon, color }) => <Link key={to} to={to} className="group flex min-w-0 items-center gap-3 rounded-[1.4rem] border border-slate-100 bg-white p-4 shadow-sm transition hover-rise-half hover:border-orange-100 hover-shadow-lg sm:p-5"><span className={`grid h-11 w-11 shrink-0 place-items-center rounded-2xl ${color}`}><Icon size={19} /></span><span className="min-w-0 flex-1"><span className="block text-sm font-black text-slate-900">{label}</span><span className="mt-1 block text-xs leading-5 text-slate-500">{detail}</span></span><ChevronRight size={18} className="shrink-0 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-orange-500" /></Link>)}
            {/* Disamakan dengan tata letak murid: tombol keluar menjadi bagian grid,
                bukan kotak terpisah yang tampak menggantung di sisi kanan. */}
            <LogoutButton accent="teacher" className="sm:col-span-2" />
          </div>
        </section>
      </div>
    </TeacherLayout>
  );
}
