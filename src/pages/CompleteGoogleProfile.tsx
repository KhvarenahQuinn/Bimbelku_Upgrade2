import { useState } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import { ArrowRight, Check, Loader2, ShieldCheck } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import http, { getApiError } from "@/lib/http";
import { notify } from "@/lib/notify";
import { updateStoredSessionUser } from "@/lib/session";

export default function CompleteGoogleProfile() {
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const redirect = safeRedirect(params.get("redirect"));
  const [loading, setLoading] = useState(false);
  const [terms, setTerms] = useState(false);
  const [privacy, setPrivacy] = useState(false);

  const finish = async () => {
    setLoading(true);
    try {
      const response = await http.post("/auth/google/complete-profile", { terms_accepted: terms, privacy_accepted: privacy });
      updateStoredSessionUser(response.data.user);
      notify.success("Akun Google berhasil disiapkan. Lengkapi profil saat siap.");
      if (redirect) {
        navigate(redirect, { replace: true });
      } else {
        const pending = await http.get("/student/guest-packages/pending").catch(() => null);
        const code = pending?.data?.data?.[0]?.code;
        navigate(code ? `/pesanan/${encodeURIComponent(code)}` : "/murid?welcome=google", { replace: true });
      }
    } catch (error) { notify.error(getApiError(error, "Akun belum dapat disiapkan.")); }
    finally { setLoading(false); }
  };

  return <main className="min-h-screen bg-[#F7F1E8] px-4 py-10"><div className="mx-auto grid max-w-4xl overflow-hidden rounded-3xl bg-white shadow-2xl lg:grid-cols-2"><aside className="bg-[#14213D] p-8 text-white sm:p-10"><ShieldCheck size={32} className="text-orange-300" /><p className="mt-10 text-xs font-extrabold uppercase tracking-wider text-orange-300">Email sudah diverifikasi Google</p><h1 className="mt-3 text-3xl font-extrabold">Akunmu siap dipakai tanpa mengisi formulir panjang.</h1><div className="mt-7 space-y-3">{["Pesanan dengan email yang sama dapat dihubungkan", "Data sekolah dan alamat dapat diisi di Profil", "Lokasi baru wajib ketika memilih kelas tatap muka"].map((item) => <p key={item} className="flex gap-3 text-sm font-bold text-slate-200"><Check size={17} className="shrink-0 text-orange-300" />{item}</p>)}</div></aside><section className="p-7 sm:p-10"><p className="text-xs font-extrabold uppercase tracking-wider text-orange-700">Persetujuan akun</p><h2 className="mt-3 text-3xl font-extrabold text-[#14213D]">Satu langkah singkat.</h2><p className="mt-3 text-sm leading-6 text-slate-600">Data tambahan tidak ditahan di halaman ini. Setelah masuk, buka menu Profil untuk melengkapinya.</p><div className="mt-8 space-y-4"><Consent checked={terms} onChange={setTerms}>Saya menyetujui <Link className="font-extrabold text-orange-700" to="/terms">Syarat dan Ketentuan</Link>.</Consent><Consent checked={privacy} onChange={setPrivacy}>Saya menyetujui <Link className="font-extrabold text-orange-700" to="/privacy">Kebijakan Privasi</Link>.</Consent></div><Button type="button" onClick={finish} disabled={loading || !terms || !privacy} className="mt-8 w-full rounded-xl bg-orange-600 py-6 font-extrabold hover:bg-orange-700">{loading ? <Loader2 className="mr-2 animate-spin" size={18} /> : <ArrowRight className="mr-2" size={18} />}Masuk dan lengkapi profil</Button></section></div></main>;
}

function Consent({ checked, onChange, children }: { checked: boolean; onChange: (value: boolean) => void; children: React.ReactNode }) { return <label className="flex cursor-pointer items-start gap-3 text-sm leading-6 text-slate-600"><Checkbox checked={checked} onCheckedChange={(value) => onChange(value === true)} className="mt-1" /><span>{children}</span></label>; }
function safeRedirect(value: string | null) { if (!value?.startsWith("/") || value.startsWith("//")) return null; const path = value.split(/[?#]/, 1)[0]; return path === "/search" || path === "/payment" || path.startsWith("/student/") || path.startsWith("/pesanan/") ? value : null; }
