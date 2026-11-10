import { notify } from "@/lib/notify";
import http, { getApiErrorDetails } from "@/lib/http";
import { FormEvent, useEffect, useState } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import { ArrowLeft, CheckCircle2, KeyRound, Loader2, ShieldCheck } from "lucide-react";

export default function ResetPaymentPin() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();

  const token = searchParams.get("token") || "";
  const email = searchParams.get("email") || "";
  const hasSession = Boolean(localStorage.getItem("token"));

  const [pin, setPin] = useState("");
  const [pinConfirmation, setPinConfirmation] = useState("");
  const [isLoading, setIsLoading] = useState(false);
  const [isDone, setIsDone] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    if (!token || !email) {
      notify.error("Link reset PIN tidak valid.");
      navigate("/login", { replace: true });
    }
  }, [token, email, navigate]);

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();
    setError("");

    if (!/^\d{6}$/.test(pin) || pin !== pinConfirmation) {
      const message = "PIN baru harus 6 angka dan konfirmasinya harus sama.";
      setError(message);
      notify.error(message);
      return;
    }

    setIsLoading(true);
    try {
      await http.post("/payment-pin/reset-with-token", {
        email,
        token,
        pin,
        pin_confirmation: pinConfirmation,
      });
      setIsDone(true);
      notify.success("PIN pembayaran berhasil diatur ulang.");
    } catch (submitError) {
      const details = getApiErrorDetails(submitError, "PIN pembayaran gagal diatur ulang.");
      setError(details.message);
      notify.error(details.message);
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-50 p-4">
      <div className="w-full max-w-md rounded-2xl border border-slate-100 bg-white p-8 shadow-xl">
        <Link to="/login" className="mb-6 flex items-center gap-1 text-sm font-bold text-slate-400 hover:text-slate-600">
          <ArrowLeft size={16} /> Kembali ke Login
        </Link>

        <div className="mb-8 text-center">
          <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-indigo-50 text-indigo-600">
            {isDone ? <CheckCircle2 size={32} /> : <KeyRound size={32} />}
          </div>
          <h1 className="text-2xl font-black text-slate-800">
            {isDone ? "PIN Berhasil Diubah" : "Buat PIN Pembayaran Baru"}
          </h1>
          <p className="mt-2 text-sm text-slate-500">
            {isDone
              ? "Gunakan PIN baru saat membayar dengan Saldo BimbelKu."
              : `Masukkan PIN 6 angka untuk akun ${email || "Anda"}.`}
          </p>
        </div>

        {isDone ? (
          <div className="space-y-4">
            <div className="rounded-xl border border-green-100 bg-green-50 p-4 text-center text-sm font-bold text-green-700">
              PIN pembayaran baru sudah aktif dan tautan ini tidak dapat dipakai lagi.
            </div>
            <Link
              to={hasSession ? "/student/settings" : "/login"}
              className="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-5 text-sm font-black text-white transition hover:bg-indigo-700"
            >
              <ShieldCheck size={18} />
              {hasSession ? "Buka Pengaturan" : "Masuk ke Akun"}
            </Link>
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="space-y-5">
            {error && (
              <p role="alert" className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-700">
                {error}
              </p>
            )}
            <div>
              <label htmlFor="reset-pin" className="mb-2 block text-sm font-bold text-slate-700">PIN Baru</label>
              <input
                id="reset-pin"
                type="password"
                inputMode="numeric"
                autoComplete="new-password"
                maxLength={6}
                required
                value={pin}
                onChange={(event) => setPin(event.target.value.replace(/\D/g, "").slice(0, 6))}
                className="w-full rounded-xl border border-slate-200 px-4 py-3 font-medium tracking-[0.3em] focus:outline-none focus:ring-2 focus:ring-indigo-500"
                placeholder="6 angka"
              />
            </div>
            <div>
              <label htmlFor="reset-pin-confirmation" className="mb-2 block text-sm font-bold text-slate-700">Ulangi PIN Baru</label>
              <input
                id="reset-pin-confirmation"
                type="password"
                inputMode="numeric"
                autoComplete="new-password"
                maxLength={6}
                required
                value={pinConfirmation}
                onChange={(event) => setPinConfirmation(event.target.value.replace(/\D/g, "").slice(0, 6))}
                className="w-full rounded-xl border border-slate-200 px-4 py-3 font-medium tracking-[0.3em] focus:outline-none focus:ring-2 focus:ring-indigo-500"
                placeholder="Ulangi 6 angka"
              />
            </div>
            <button
              type="submit"
              disabled={isLoading || pin.length !== 6 || pinConfirmation.length !== 6}
              className="flex w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 py-3 font-bold text-white transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {isLoading ? <Loader2 className="animate-spin" size={18} /> : <ShieldCheck size={18} />}
              Simpan PIN Baru
            </button>
            <p className="text-center text-xs font-medium leading-5 text-slate-500">
              Tautan ini kedaluwarsa dalam 60 menit. Jika sudah kedaluwarsa, minta link baru dari halaman Pengaturan.
            </p>
          </form>
        )}
      </div>
    </div>
  );
}
