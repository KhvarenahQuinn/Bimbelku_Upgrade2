import { FormEvent, useEffect, useState } from "react";
import PasswordInput from "@/components/PasswordInput";
import { KeyRound, Loader2, Mail, Phone, ShieldCheck, UserRound } from "lucide-react";
import AdminLayout from "@/components/AdminLayout";
import LogoutButton from "@/components/LogoutButton";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import http, { getApiError } from "@/lib/http";
import { notify } from "@/lib/notify";

type AdminProfileData = {
  name: string;
  email: string;
  phone?: string | null;
  avatar_url?: string | null;
  role: string;
};

export default function AdminProfile() {
  const [profile, setProfile] = useState<AdminProfileData | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [form, setForm] = useState({ current_password: "", password: "", password_confirmation: "" });
  const [passwordError, setPasswordError] = useState("");

  useEffect(() => {
    let active = true;
    void http.get<AdminProfileData>("/user")
      .then((response) => { if (active) setProfile(response.data); })
      .catch((error) => { if (active) notify.error(getApiError(error, "Profil admin gagal dimuat.")); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, []);

  const updatePassword = async (event: FormEvent) => {
    event.preventDefault();
    if (form.password.length < 12) {
      setPasswordError("Kata sandi admin minimal 12 karakter.");
      document.getElementById("admin-new-password")?.focus();
      return;
    }
    setPasswordError("");
    if (form.password !== form.password_confirmation) {
      notify.error("Konfirmasi kata sandi belum sama.");
      return;
    }
    setSaving(true);
    try {
      const response = await http.put("/user/password", form);
      notify.success(response.data?.message || "Kata sandi berhasil diperbarui.");
      setForm({ current_password: "", password: "", password_confirmation: "" });
      setPasswordError("");
    } catch (error) {
      notify.error(getApiError(error, "Kata sandi tidak dapat diperbarui."));
    } finally {
      setSaving(false);
    }
  };

  return (
    <AdminLayout title="Profil Saya" subtitle="Identitas akun dan keamanan administrator">
      {loading ? (
        <div className="grid min-h-72 place-items-center"><Loader2 className="animate-spin text-orange-600" /></div>
      ) : (
        <div className="mx-auto grid max-w-5xl gap-5 pb-12 lg:grid-cols-[minmax(0,.9fr)_minmax(0,1.1fr)]">
          <section className="rounded-[1.75rem] border border-slate-100 bg-white p-5 shadow-sm sm:p-7">
            <div className="flex min-w-0 items-center gap-4">
              <div className="grid h-16 w-16 shrink-0 place-items-center overflow-hidden rounded-2xl bg-gradient-to-br from-orange-500 to-rose-600 text-xl font-black text-white">
                {profile?.avatar_url ? <img src={profile.avatar_url} alt="Foto profil admin" loading="lazy" decoding="async" className="h-full w-full object-cover" /> : (profile?.name || "A").charAt(0).toUpperCase()}
              </div>
              <div className="min-w-0"><h1 className="break-words text-xl font-black text-slate-950">{profile?.name || "Administrator"}</h1><p className="mt-1 text-xs font-black uppercase tracking-[.14em] text-orange-600">Admin utama</p></div>
            </div>
            <div className="mt-6 space-y-3">
              <ProfileRow icon={UserRound} label="Nama" value={profile?.name || "-"} />
              <ProfileRow icon={Mail} label="Email" value={profile?.email || "-"} />
              <ProfileRow icon={Phone} label="Telepon" value={profile?.phone || "Belum diisi"} />
              <ProfileRow icon={ShieldCheck} label="Hak akses" value="Seluruh operasional BimbelKu" />
            </div>
            <p className="mt-5 rounded-2xl bg-slate-50 p-4 text-xs font-semibold leading-5 text-slate-500">Perubahan identitas admin dilakukan melalui pengelolaan akun server agar jejak keamanan tetap terkontrol.</p>
          </section>

          <form onSubmit={updatePassword} className="rounded-[1.75rem] border border-slate-100 bg-white p-5 shadow-sm sm:p-7">
            <div className="flex items-center gap-3"><span className="grid h-11 w-11 place-items-center rounded-2xl bg-orange-50 text-orange-700"><KeyRound size={20} /></span><div><h2 className="font-black text-slate-950">Keamanan akun</h2><p className="text-xs text-slate-500">Ganti kata sandi dan tutup sesi lama.</p></div></div>
            <div className="mt-6 space-y-4">
              <div><Label htmlFor="admin-current-password">Kata sandi saat ini</Label><PasswordInput id="admin-current-password" label="kata sandi saat ini" autoComplete="current-password" required value={form.current_password} onChange={(event) => setForm((value) => ({ ...value, current_password: event.target.value }))} className="mt-2 h-12 rounded-xl" /></div>
              <div><Label htmlFor="admin-new-password">Kata sandi baru</Label><PasswordInput id="admin-new-password" label="kata sandi baru" autoComplete="new-password" required value={form.password} onChange={(event) => { setForm((value) => ({ ...value, password: event.target.value })); if (event.target.value.length >= 12) setPasswordError(""); }} onBlur={() => setPasswordError(form.password.length > 0 && form.password.length < 12 ? "Kata sandi admin minimal 12 karakter." : "")} aria-invalid={Boolean(passwordError)} aria-describedby={`admin-password-hint${passwordError ? " admin-password-error" : ""}`} className="mt-2 h-12 rounded-xl" /><p id="admin-password-hint" className="mt-1 text-xs text-slate-500">Minimal 12 karakter.</p>{passwordError && <p id="admin-password-error" role="alert" className="mt-1 text-xs font-semibold text-red-700">{passwordError}</p>}</div>
              <div><Label htmlFor="admin-confirm-password">Ulangi kata sandi baru</Label><PasswordInput id="admin-confirm-password" label="ulangi kata sandi baru" autoComplete="new-password" required value={form.password_confirmation} onChange={(event) => setForm((value) => ({ ...value, password_confirmation: event.target.value }))} className="mt-2 h-12 rounded-xl" /></div>
            </div>
            <Button disabled={saving} className="mt-6 h-12 w-full rounded-xl bg-slate-950 font-black hover:bg-slate-800">{saving && <Loader2 size={17} className="mr-2 animate-spin" />}Perbarui kata sandi</Button>
          </form>
          <LogoutButton accent="admin" className="lg:col-start-2" />
        </div>
      )}
    </AdminLayout>
  );
}

function ProfileRow({ icon: Icon, label, value }: { icon: typeof UserRound; label: string; value: string }) {
  return <div className="flex min-w-0 items-center gap-3 rounded-2xl border border-slate-100 p-3"><span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-slate-100 text-slate-600"><Icon size={16} /></span><div className="min-w-0"><p className="text-[10px] font-black uppercase tracking-wider text-slate-400">{label}</p><p className="mt-0.5 break-words text-sm font-bold text-slate-800">{value}</p></div></div>;
}
