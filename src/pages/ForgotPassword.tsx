import { notify } from "@/lib/notify";
import { API_BASE_URL } from "@/lib/apiBase";
import { getApiErrorDetails } from "@/lib/http";
import { isValidEmail } from "@/lib/validation";
import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Loader2, ArrowLeft, Mail } from "lucide-react";
import TurnstileWidget from "@/components/TurnstileWidget";

const ForgotPassword = () => {
  const [email, setEmail] = useState("");
  const [turnstileToken, setTurnstileToken] = useState("");
  const [isLoading, setIsLoading] = useState(false);
  const [isSent, setIsSent] = useState(false);
  const [cooldownSeconds, setCooldownSeconds] = useState(0);
  const turnstileEnabled = import.meta.env.VITE_TURNSTILE_SITE_KEY?.trim() !== "";

  // Memoize Turnstile token handler to prevent infinite loop
  const handleTurnstileToken = useCallback((token: string) => {
    setTurnstileToken(token);
  }, []);

  useEffect(() => {
    if (cooldownSeconds <= 0) return;

    const timer = window.setTimeout(() => {
      setCooldownSeconds((seconds) => Math.max(0, seconds - 1));
    }, 1000);

    return () => window.clearTimeout(timer);
  }, [cooldownSeconds]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!isValidEmail(email)) {
      notify.error("Masukkan alamat email yang valid.");
      return;
    }
    if (turnstileEnabled && !turnstileToken.trim()) {
      notify.error("Selesaikan verifikasi keamanan Cloudflare terlebih dahulu.");
      return;
    }
    setIsLoading(true);

    try {
      const { default: axios } = await import("axios");
      await axios.post(`${API_BASE_URL}/forgot-password`, { 
        email,
        'cf-turnstile-response': turnstileToken
      });
      setCooldownSeconds(60);
      setIsSent(true);
      notify.success("Email terkirim! Cek inbox/spam Anda.");
    } catch (error: unknown) {
      const details = getApiErrorDetails(error, "Gagal mengirim permintaan.");
      if (details.code === "password_reset_cooldown") {
        setCooldownSeconds(details.retryAfterSeconds || 60);
        setIsSent(true);
      }
      notify.error(details.message);
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-slate-50 p-4">
      <div className="bg-white p-8 rounded-2xl shadow-xl border border-slate-100 w-full max-w-md">
        <Link to="/login" className="flex items-center gap-1 text-slate-400 text-sm hover:text-slate-600 mb-6 font-bold">
          <ArrowLeft size={16} /> Kembali ke Login
        </Link>

        <div className="text-center mb-8">
          <div className="w-16 h-16 bg-indigo-50 text-indigo-600 rounded-full flex items-center justify-center mx-auto mb-4">
            <Mail size={32} />
          </div>
          <h1 className="text-2xl font-black text-slate-800">Lupa Password?</h1>
          <p className="text-slate-500 text-sm mt-2">
            Masukkan email Anda, kami akan mengirimkan link untuk mereset password.
          </p>
        </div>

        {!isSent ? (
          <form onSubmit={handleSubmit} className="space-y-5">
            <div>
              <label className="block text-sm font-bold text-slate-700 mb-2">Email Terdaftar</label>
              <input
                type="email"
                required
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="nama@email.com"
                className="w-full px-4 py-3 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 font-medium"
              />
            </div>
            <div className="mt-4">
              <TurnstileWidget onTokenChange={handleTurnstileToken} />
              {turnstileEnabled && !turnstileToken.trim() && (
                <p className="mt-1 text-xs text-red-600 text-center" role="alert">Selesaikan verifikasi keamanan Cloudflare terlebih dahulu.</p>
              )}
              <p className="mt-1 text-xs text-slate-500 text-center">Verifikasi keamanan oleh Cloudflare</p>
            </div>
            <button
              type="submit"
              disabled={isLoading || (turnstileEnabled && !turnstileToken.trim())}
              className="w-full py-3 bg-indigo-600 text-white rounded-xl font-bold hover:bg-indigo-700 transition-all flex justify-center items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {isLoading ? <Loader2 className="animate-spin" /> : "Kirim Link Reset"}
            </button>
          </form>
        ) : (
          <div className="text-center p-6 bg-green-50 rounded-xl border border-green-100">
            <p className="text-green-700 font-bold text-sm">
              Link reset telah dikirim ke <span className="underline">{email}</span>. Silakan cek email Anda.
            </p>
            <button 
              onClick={() => setIsSent(false)} 
              type="button"
              disabled={cooldownSeconds > 0}
              className="mt-4 text-xs font-bold text-green-600 hover:underline disabled:cursor-not-allowed disabled:text-green-400 disabled:no-underline"
            >
              {cooldownSeconds > 0
                ? `Kirim ulang dalam ${cooldownSeconds} detik`
                : "Kirim ulang"}
            </button>
          </div>
        )}
      </div>
    </div>
  );
};

export default ForgotPassword;