import { notify } from "@/lib/notify";
import { API_BASE_URL } from "@/lib/apiBase";
import { useRef, useState } from "react";
import { Link, useLocation, useNavigate, useSearchParams } from "react-router-dom";
import { ArrowRight, Eye, EyeOff, Loader2, Lock, Mail } from "lucide-react";
import GoogleAuthButton from "@/components/GoogleAuthButton";
import AuthExperience from "@/components/AuthExperience";
import { storeBrowserSession } from "@/lib/session";

export default function Login() {
  const navigate = useNavigate();
  const location = useLocation();
  const [searchParams] = useSearchParams();
  const requestedRedirect = searchParams.get("redirect");
  const guestEmail = typeof (location.state as { email?: unknown } | null)?.email === "string" ? String((location.state as { email?: string }).email) : "";
  const registerHref = requestedRedirect ? `/register?redirect=${encodeURIComponent(requestedRedirect)}` : "/register";
  const [showPassword, setShowPassword] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const loadingRef = useRef(false);
  const [rememberDevice, setRememberDevice] = useState(true);
  const [formData, setFormData] = useState({ email: searchParams.get("email") || guestEmail, password: "" });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [summaryError, setSummaryError] = useState("");
  const googleError = searchParams.get("google_error");

  const handleLogin = async (event: React.FormEvent) => {
    event.preventDefault();
    if (loadingRef.current) return;
    loadingRef.current = true;
    setErrors({});
    setSummaryError("");
    setIsLoading(true);
    let axiosModule: typeof import("axios") | null = null;
    try {
      axiosModule = await import("axios");
      const response = await axiosModule.default.post(`${API_BASE_URL}/login`, {
        ...formData,
        login_portal: "user",
        remember_device: rememberDevice,
      }, { withCredentials: true });
      const { access_token, user, pending_guest_order } = response.data;
      if (user.role === "admin") {
        notify.error("Akun admin harus masuk melalui halaman login admin.");
        navigate("/admin/login", { replace: true });
        return;
      }
      const dashboardByRole: Record<string, string> = { teacher: "/guru", student: "/murid" };
      const dashboard = dashboardByRole[user.role];
      if (!dashboard) return notify.error("Peran akun tidak dikenali. Hubungi admin BimbelKu.");
      storeBrowserSession(user, access_token);
      const requestedPath = requestedRedirect?.split(/[?#]/, 1)[0] || "";
      const allowed = Boolean(requestedRedirect?.startsWith("/") && !requestedRedirect.startsWith("//") && ((user.role === "student" && (requestedPath === "/murid" || requestedPath === "/search" || requestedPath === "/payment" || requestedPath.startsWith("/student/") || requestedPath.startsWith("/pesanan/"))) || (user.role === "teacher" && requestedPath.startsWith("/guru"))));
      let destination = allowed ? requestedRedirect! : dashboard;
      if (user.role === "student" && !allowed) {
        const serverCode = typeof pending_guest_order === "string" ? pending_guest_order : "";
        if (serverCode) {
          destination = `/pesanan/${encodeURIComponent(serverCode)}`;
        } else {
          try {
            const pending = await axiosModule.default.get(`${API_BASE_URL}/student/guest-packages/pending`, {
              headers: { Authorization: `Bearer ${access_token}` },
              withCredentials: true,
            });
            const code = pending.data?.data?.[0]?.code;
            if (code) destination = `/pesanan/${encodeURIComponent(code)}`;
          } catch {
            // Penemuan draf tidak boleh menggagalkan login utama.
          }
        }
      }
      notify.success(`Selamat datang, ${user.name}!`);
      navigate(destination, { replace: true });
    } catch (error: unknown) {
      const axios = axiosModule?.default;
      const isAxiosError = axios ? axios.isAxiosError(error) : false;
      const data = isAxiosError ? (error as { response?: { data?: { error_code?: string; email?: string; message?: string; errors?: Record<string, string[]> } } }).response?.data : undefined;
      if (data?.error_code === "email_not_verified" && data.email) {
        const params = new URLSearchParams({ email: data.email });
        if (requestedRedirect) params.set("redirect", requestedRedirect);
        navigate(`/verify-email?${params}`, { state: { email: data.email, redirect: requestedRedirect } });
        return;
      }
      if (data?.error_code === "admin_portal_required") {
        const params = new URLSearchParams();
        if (formData.email) params.set("email", formData.email);
        if (requestedRedirect) params.set("redirect", requestedRedirect);
        notify.warning("Akun admin harus masuk melalui halaman login admin.");
        navigate(`/admin/login${params.toString() ? `?${params}` : ""}`, { replace: true });
        return;
      }
      const message = data?.message || "Periksa email dan kata sandi, lalu coba lagi.";
      setSummaryError(message);
      setErrors(Object.fromEntries(Object.entries(data?.errors || {}).map(([key, value]) => [key, value[0] || message])));
      notify.error("Gagal masuk", { description: message });
    } finally {
      loadingRef.current = false;
      setIsLoading(false);
    }
  };

  return <AuthExperience mode="login" role="all">
      <section>
        <p className="mt-8 text-xs font-extrabold uppercase tracking-wider text-orange-700">Selamat datang kembali</p>
        <h2 className="mt-2 text-3xl font-extrabold text-[#14213D]">Masuk ke BimbelKu</h2>
        <p className="mt-2 text-sm leading-6 text-slate-500">Lanjutkan belajar, lihat jadwal, dan kelola kelasmu dalam satu akun.</p>
        {googleError && <p role="alert" className="mt-5 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">{googleError}</p>}
        {summaryError && <p role="alert" aria-live="assertive" className="mt-5 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">{summaryError}</p>}
        <form onSubmit={handleLogin} className="auth-login-form mt-7 space-y-5">
          <label htmlFor="login-email" className="block text-sm font-bold text-slate-700">Email<div className="auth-control mt-2" data-invalid={Boolean(errors.email)}><span className="auth-control-icon" aria-hidden="true"><Mail size={19} /></span><input id="login-email" name="email" type="email" autoComplete="email" required aria-invalid={Boolean(errors.email)} aria-describedby={errors.email ? "login-email-error" : undefined} value={formData.email} onChange={(event) => { setFormData((value) => ({ ...value, email: event.target.value })); setErrors((value) => ({ ...value, email: "" })); }} placeholder="nama@email.com" /></div>{errors.email && <span id="login-email-error" className="mt-1 block text-xs font-semibold text-red-700">{errors.email}</span>}</label>
          <label htmlFor="login-password" className="block text-sm font-bold text-slate-700">Kata sandi<div className="auth-control mt-2" data-invalid={Boolean(errors.password)}><span className="auth-control-icon" aria-hidden="true"><Lock size={19} /></span><input id="login-password" name="password" type={showPassword ? "text" : "password"} autoComplete="current-password" required aria-invalid={Boolean(errors.password)} aria-describedby={errors.password ? "login-password-error" : undefined} value={formData.password} onChange={(event) => { setFormData((value) => ({ ...value, password: event.target.value })); setErrors((value) => ({ ...value, password: "" })); }} placeholder="Masukkan kata sandi" /><button type="button" onClick={() => setShowPassword((value) => !value)} className="auth-control-action" aria-controls="login-password" aria-label={showPassword ? "Sembunyikan kata sandi" : "Tampilkan kata sandi"}>{showPassword ? <EyeOff size={18} /> : <Eye size={18} />}</button></div>{errors.password && <span id="login-password-error" className="mt-1 block text-xs font-semibold text-red-700">{errors.password}</span>}</label>
          <div className="flex flex-wrap items-center justify-between gap-3"><label className="inline-flex min-h-11 cursor-pointer items-center gap-2 text-sm font-bold text-slate-600"><input type="checkbox" checked={rememberDevice} onChange={(event) => setRememberDevice(event.target.checked)} className="h-4 w-4 rounded border-slate-300 text-orange-600 focus:ring-orange-500" /><span>Ingat perangkat ini selama 30 hari</span></label><Link to="/forgot-password" className="inline-flex min-h-11 items-center text-sm font-bold text-orange-700">Lupa kata sandi?</Link></div>
          <button disabled={isLoading} className="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-[#14213D] px-5 text-sm font-extrabold text-white hover:bg-slate-800 disabled:opacity-50">{isLoading ? <Loader2 className="animate-spin" size={18} /> : <ArrowRight size={18} />}{isLoading ? "Memproses…" : "Masuk"}</button>
          <div className="flex items-center gap-3 py-2 text-xs font-bold uppercase tracking-wider text-slate-400"><span className="h-px flex-1 bg-slate-200" />atau<span className="h-px flex-1 bg-slate-200" /></div><GoogleAuthButton redirect={requestedRedirect} rememberDevice={rememberDevice} />
        </form>
          <>
            <p className="mt-7 text-center text-sm text-slate-500">Belum punya akun untuk belajar? <Link to={registerHref} state={guestEmail ? { email: guestEmail } : undefined} className="inline-flex min-h-11 items-center font-extrabold text-orange-700">Daftar murid</Link></p>
            <p className="mt-3 text-center text-sm text-slate-500">Ingin mengajar? <Link to="/jadi-tutor" className="inline-flex min-h-11 items-center font-extrabold text-teal-700">Daftar sebagai tutor</Link></p>
          </>
      </section>
  </AuthExperience>;
}
