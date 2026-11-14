import { useRef, useState } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import SeoHead from "@/components/SeoHead";
import { ArrowLeft, ArrowRight, Eye, EyeOff, Loader2, Lock, Mail, ShieldCheck } from "lucide-react";
import { API_BASE_URL } from "@/lib/apiBase";
import { notify } from "@/lib/notify";
import { storeBrowserSession } from "@/lib/session";

type LoginResponse = {
  access_token?: string;
  user?: { role?: string; name?: string };
  message?: string;
  error_code?: string;
  errors?: Record<string, string[]>;
};

const GENERIC_LOGIN_ERROR = "Email atau kata sandi salah.";

/** Portal admin terpisah dari login murid/tutor. */
export default function AdminLogin() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const requestedRedirect = searchParams.get("redirect");
  const [form, setForm] = useState({ email: searchParams.get("email") || "", password: "" });
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const loadingRef = useRef(false);
  const [summaryError, setSummaryError] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});

  const safeAdminRedirect = (value: string | null) => {
    if (!value?.startsWith("/") || value.startsWith("//")) return null;
    const path = value.split(/[?#]/, 1)[0] || "";
    if (path === "/admin/login") return null;
    return path === "/admin" || path.startsWith("/admin/") ? value : null;
  };

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (loadingRef.current) return;
    loadingRef.current = true;
    setErrors({});
    setSummaryError("");
    setLoading(true);
    try {
      const { default: axios } = await import("axios");
      const response = await axios.post<LoginResponse>(
        `${API_BASE_URL}/login/admin`,
        {
          email: form.email,
          password: form.password,
          login_portal: "admin",
          // Sesi admin selalu pendek; server mengabaikan "ingat perangkat".
          remember_device: false,
        },
        { withCredentials: true },
      );
      const user = response.data.user;
      // Server sudah menolak portal ini untuk non-admin. Kalau respons tetap
      // tidak berisi admin, itu kegagalan tak terduga, bukan pesan untuk
      // membocorkan apakah email terdaftar.
      if (!user || user.role !== "admin") {
        setSummaryError(GENERIC_LOGIN_ERROR);
        return;
      }
      storeBrowserSession(user, response.data.access_token);
      notify.success(`Selamat datang, ${user.name || "Admin"}!`);
      navigate(safeAdminRedirect(requestedRedirect) || "/admin", { replace: true });
    } catch (error: unknown) {
      const axios = (await import("axios")).default;
      const data = axios.isAxiosError(error)
        ? (error.response?.data as LoginResponse | undefined)
        : undefined;
      // Pesan seragam: jangan pernah membedakan "bukan admin" dari "kredensial
      // salah", supaya URL portal ini tidak bisa dipakai menebak role akun.
      const message = data?.error_code === "admin_rate_limited"
        ? (data?.message || "Terlalu banyak percobaan. Coba lagi nanti.")
        : GENERIC_LOGIN_ERROR;
      setSummaryError(message);
      setErrors(Object.fromEntries(Object.entries(data?.errors || {}).map(([key, value]) => [key, value[0] || message])));
    } finally {
      loadingRef.current = false;
      setLoading(false);
    }
  };

  return (
    <>
    <SeoHead
      title="Login Admin | BimbelKu"
      description="Portal khusus administrator BimbelKu."
      canonicalPath="/admin/login"
      robots="noindex,nofollow,noarchive"
    />
    <main className="grid min-h-dvh w-full bg-slate-950 lg:h-dvh lg:min-h-0 lg:grid-cols-[minmax(0,1fr)_minmax(0,440px)] lg:overflow-hidden">
      <aside className="relative hidden flex-col justify-between overflow-hidden bg-slate-900 px-12 py-10 text-white lg:flex">
        <div aria-hidden="true" className="pointer-events-none absolute inset-0 opacity-70" style={{ backgroundImage: "radial-gradient(circle at 20% 20%, rgb(249 115 22 / 0.18), transparent 24rem), radial-gradient(circle at 90% 90%, rgb(20 184 166 / 0.14), transparent 22rem)" }} />
        <Link to="/" className="relative z-10 inline-flex min-h-12 items-center gap-3 text-xl font-black tracking-tight text-white">
          <span className="grid h-11 w-11 place-items-center rounded-2xl bg-orange-500 text-white"><ShieldCheck size={24} /></span>
          BimbelKu Admin
        </Link>
        <div className="relative z-10 max-w-md">
          <p className="text-xs font-extrabold uppercase tracking-[0.2em] text-orange-300">Ruang operasional</p>
          <h1 className="mt-4 text-4xl font-black leading-tight tracking-tight">
            Kelola kelas, pembayaran, dan tutor dari satu tempat.
          </h1>
          <p className="mt-4 max-w-sm text-sm leading-6 text-slate-300">
            Portal ini khusus admin. Akun murid dan tutor tetap masuk melalui halaman pengguna.
          </p>
        </div>
        <p className="relative z-10 text-xs font-semibold text-slate-400">Sesi admin aktif maksimal 12 jam.</p>
      </aside>

      <section className="flex min-h-dvh flex-col overflow-y-auto bg-white px-5 py-6 sm:px-8 lg:h-dvh lg:min-h-0">
        <Link to="/" className="inline-flex w-fit min-h-11 items-center gap-2 text-sm font-bold text-slate-500 hover:text-orange-700">
          <ArrowLeft size={17} /> Kembali ke beranda
        </Link>
        <div className="mx-auto my-auto w-full max-w-sm py-8">
          <span className="inline-flex items-center gap-2 rounded-full bg-orange-50 px-3 py-1 text-xs font-extrabold uppercase tracking-wider text-orange-700">
            <ShieldCheck size={15} /> Login admin
          </span>
          <h2 className="mt-5 text-3xl font-extrabold text-[#14213D]">Masuk sebagai admin</h2>
          <p className="mt-2 text-sm leading-6 text-slate-500">Gunakan akun admin BimbelKu. Sesi berakhir otomatis setelah batas waktu.</p>

          {summaryError && (
            <p role="alert" aria-live="assertive" className="mt-5 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">
              {summaryError}
            </p>
          )}

          <form onSubmit={handleSubmit} className="mt-7 space-y-5">
            <label htmlFor="admin-email" className="block text-sm font-bold text-slate-700">
              Email admin
              <div className="mt-2 flex items-center overflow-hidden rounded-xl border-2 border-slate-300 bg-white focus-within:border-orange-500 focus-within:ring-4 focus-within:ring-orange-500/15" data-invalid={Boolean(errors.email)}>
                <span className="grid h-14 w-12 shrink-0 place-items-center border-r border-slate-200 bg-orange-50 text-orange-700" aria-hidden="true"><Mail size={19} /></span>
                <input
                  id="admin-email"
                  name="email"
                  type="email"
                  autoComplete="email"
                  inputMode="email"
                  required
                  aria-invalid={Boolean(errors.email)}
                  aria-describedby={errors.email ? "admin-email-error" : undefined}
                  value={form.email}
                  onChange={(event) => {
                    setForm((value) => ({ ...value, email: event.target.value }));
                    setErrors((value) => ({ ...value, email: "" }));
                  }}
                  placeholder="admin@bimbelku.com"
                  className="h-14 min-w-0 flex-1 border-0 bg-transparent px-4 text-base text-[#14213D] outline-none"
                />
              </div>
              {errors.email && <span id="admin-email-error" className="mt-1 block text-xs font-semibold text-red-700">{errors.email}</span>}
            </label>

            <label htmlFor="admin-password" className="block text-sm font-bold text-slate-700">
              Kata sandi
              <div className="mt-2 flex items-center overflow-hidden rounded-xl border-2 border-slate-300 bg-white focus-within:border-orange-500 focus-within:ring-4 focus-within:ring-orange-500/15" data-invalid={Boolean(errors.password)}>
                <span className="grid h-14 w-12 shrink-0 place-items-center border-r border-slate-200 bg-orange-50 text-orange-700" aria-hidden="true"><Lock size={19} /></span>
                <input
                  id="admin-password"
                  name="password"
                  type={showPassword ? "text" : "password"}
                  autoComplete="current-password"
                  required
                  aria-invalid={Boolean(errors.password)}
                  aria-describedby={errors.password ? "admin-password-error" : undefined}
                  value={form.password}
                  onChange={(event) => {
                    setForm((value) => ({ ...value, password: event.target.value }));
                    setErrors((value) => ({ ...value, password: "" }));
                  }}
                  placeholder="Masukkan kata sandi"
                  className="h-14 min-w-0 flex-1 border-0 bg-transparent px-4 text-base text-[#14213D] outline-none"
                />
                <button
                  type="button"
                  onClick={() => setShowPassword((value) => !value)}
                  className="grid h-14 w-12 shrink-0 place-items-center text-slate-500 hover:bg-orange-50 hover:text-orange-700"
                  aria-controls="admin-password"
                  aria-label={showPassword ? "Sembunyikan kata sandi" : "Tampilkan kata sandi"}
                >
                  {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                </button>
              </div>
              {errors.password && <span id="admin-password-error" className="mt-1 block text-xs font-semibold text-red-700">{errors.password}</span>}
            </label>

            <div className="flex justify-end">
              <Link to="/forgot-password" className="inline-flex min-h-9 items-center text-sm font-bold text-orange-700">
                Lupa kata sandi?
              </Link>
            </div>

            <button
              type="submit"
              disabled={loading}
              className="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-[#14213D] px-5 text-sm font-extrabold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {loading ? <Loader2 className="animate-spin motion-reduce:animate-none" size={18} /> : <ArrowRight size={18} />}
              {loading ? "Memeriksa akun…" : "Masuk ke dashboard admin"}
            </button>
          </form>

          <p className="mt-7 border-t border-slate-200 pt-5 text-center text-sm text-slate-500">
            Murid atau tutor? <Link to="/login" className="inline-flex min-h-11 items-center font-extrabold text-orange-700 hover:underline">Masuk di halaman pengguna</Link>
          </p>
        </div>
      </section>
    </main>
    </>
  );
}
