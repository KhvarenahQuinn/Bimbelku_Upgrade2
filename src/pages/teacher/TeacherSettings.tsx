import { notify } from "@/lib/notify";
import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { ArrowLeft, KeyRound, Loader2, MailCheck, ShieldCheck, Wallet } from "lucide-react";
import TeacherLayout from "@/components/TeacherLayout";
import ChangePasswordDialog from "@/components/ChangePasswordDialog";
import { formatAccountDate } from "@/lib/date";
import http, { getApiError, getCached } from "@/lib/http";
import { Input } from "@/components/ui/input";
import { useConfirmDialog } from "@/components/ConfirmDialogProvider";

type AccountSecurity = {
  name?: string;
  email?: string;
  has_password?: boolean;
  password_updated_at?: string | null;
};

type ProfileData = {
  user?: AccountSecurity;
  account_security?: { has_password?: boolean; password_updated_at?: string | null };
  profile?: { payout_verification_method?: "otp" | "password" | null };
};

type PayoutMethod = "otp" | "password";

export default function TeacherSettings() {
  const confirm = useConfirmDialog();
  const [user, setUser] = useState<AccountSecurity | null>(null);
  const [loading, setLoading] = useState(true);
  const [resetLinkSending, setResetLinkSending] = useState(false);
  const [resetLinkSent, setResetLinkSent] = useState(false);
  const [resetLinkCooldown, setResetLinkCooldown] = useState(0);
  const [payoutMethod, setPayoutMethod] = useState<PayoutMethod>("otp");
  const [methodPassword, setMethodPassword] = useState("");
  const [methodCode, setMethodCode] = useState("");
  const [methodSending, setMethodSending] = useState(false);
  const [otpCooldown, setOtpCooldown] = useState(0);

  useEffect(() => {
    getCached<ProfileData>("/teacher/profile", { maxAgeMs: 60_000 })
      .then((response) => {
        const { user, account_security, profile } = response.data ?? {};
        setUser(user ? { ...user, ...account_security } : null);
        setPayoutMethod(profile?.payout_verification_method === "password" ? "password" : "otp");
      })
      .catch(() => notify.error("Data keamanan akun gagal dimuat."))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    if (otpCooldown <= 0) return;
    const timer = window.setInterval(() => setOtpCooldown((seconds) => Math.max(0, seconds - 1)), 1000);
    return () => window.clearInterval(timer);
  }, [otpCooldown]);

  const sendPayoutOtp = async () => {
    if (otpCooldown > 0 || methodSending) return;
    setMethodSending(true);
    try {
      await http.post("/teacher/payout-verification/otp", {});
      setOtpCooldown(60);
      notify.success("Kode verifikasi rekening dikirim ke email akun.");
    } catch (error) {
      notify.error(getApiError(error, "Kode verifikasi gagal dikirim."));
    } finally {
      setMethodSending(false);
    }
  };

  const changePayoutMethod = async (next: PayoutMethod) => {
    if (next === payoutMethod || methodSending) return;
    if (!methodPassword) {
      notify.error("Masukkan kata sandi akun untuk mengganti metode verifikasi pencairan.");
      return;
    }
    if (next === "otp" && methodCode.length !== 6) {
      notify.error("Masukkan 6 digit kode verifikasi email untuk kembali ke verifikasi OTP.");
      return;
    }
    const approved = await confirm({
      title: "Ganti metode verifikasi pencairan?",
      description: next === "otp"
        ? "Rekening pencairan baru akan diverifikasi dengan kode email."
        : "Rekening pencairan baru akan diverifikasi dengan kata sandi akun.",
      confirmText: "Ganti metode",
      tone: "warning",
    });
    if (!approved) return;
    setMethodSending(true);
    try {
      await http.put("/teacher/payout-verification/method", {
        method: next,
        current_password: methodPassword,
        code: next === "otp" ? methodCode : undefined,
      });
      setPayoutMethod(next);
      setMethodPassword("");
      setMethodCode("");
      notify.success(next === "otp" ? "Verifikasi pencairan memakai kode email." : "Verifikasi pencairan memakai kata sandi.");
    } catch (error) {
      notify.error(getApiError(error, "Metode verifikasi gagal diubah."));
    } finally {
      setMethodSending(false);
    }
  };

  useEffect(() => {
    if (resetLinkCooldown <= 0) return;
    const timer = window.setInterval(() => {
      setResetLinkCooldown((seconds) => Math.max(0, seconds - 1));
    }, 1000);
    return () => window.clearInterval(timer);
  }, [resetLinkCooldown]);

  const requestPasswordResetLink = async () => {
    if (!user?.email || resetLinkSending || resetLinkCooldown > 0) return;
    setResetLinkSending(true);
    try {
      await http.post("/forgot-password", { email: user.email });
      setResetLinkSent(true);
      setResetLinkCooldown(60);
    } catch {
      notify.error("Link reset kata sandi gagal dikirim. Coba lagi beberapa saat.");
    } finally {
      setResetLinkSending(false);
    }
  };

  return (
    <TeacherLayout title="Keamanan">
      <div className="mx-auto max-w-3xl space-y-5 pb-8">
        <div>
          <Link
            to="/guru/saya"
            className="inline-flex min-h-11 items-center gap-2 text-sm font-black text-slate-600 transition hover:text-slate-900"
          >
            <ArrowLeft size={17} />
            Kembali ke akun
          </Link>
          <h1 className="mt-3 text-xl font-black text-slate-950 sm:text-2xl">Keamanan akun</h1>
          <p className="mt-1 text-sm leading-6 text-slate-600">
            Kelola kata sandi dan akses masuk akun tutor Anda. Data diri dan kompetensi diatur di menu Data diri.
          </p>
        </div>

        <section className="overflow-hidden rounded-[1.75rem] border border-slate-100 bg-white shadow-sm">
          <header className="border-b border-slate-100 px-5 py-4 sm:px-6">
            <h2 className="text-sm font-black text-slate-900">Kata sandi akun</h2>
            <p className="mt-1 text-xs font-medium leading-5 text-slate-500">
              {loading
                ? "Memuat status kata sandi..."
                : user?.has_password === false
                  ? "Akun Google belum memiliki kata sandi lokal. Buat sekarang agar bisa masuk dengan email."
                  : `Perbarui kata sandi secara berkala${user?.password_updated_at ? ` • terakhir ${formatAccountDate(user.password_updated_at)}` : ""}.`}
            </p>
          </header>

          <div className="space-y-4 px-5 py-5 sm:px-6">
            {loading ? (
              <div className="grid min-h-16 place-items-center">
                <Loader2 className="animate-spin text-slate-400" size={20} />
              </div>
            ) : (
              <>
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                  <div className="flex min-w-0 items-start gap-3">
                    <span className="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-slate-100 text-slate-600">
                      <ShieldCheck size={20} />
                    </span>
                    <div className="min-w-0">
                      <p className="truncate text-sm font-black text-slate-800">{user?.email || "Email akun"}</p>
                      <p className="mt-0.5 text-xs font-medium text-slate-500">
                        {user?.has_password === false ? "Kata sandi belum dibuat" : "Kata sandi aktif"}
                      </p>
                    </div>
                  </div>
                  <ChangePasswordDialog
                    hasPassword={user?.has_password !== false}
                    passwordUpdatedAt={user?.password_updated_at ?? null}
                    onUpdated={(timestamp) =>
                      setUser((current) =>
                        current ? { ...current, has_password: true, password_updated_at: timestamp } : current
                      )
                    }
                  />
                </div>

                {user?.has_password !== false && (
                  <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4">
                    <div className="min-w-0">
                      <p className="text-xs font-bold text-slate-600">Tidak ingat kata sandi saat ini?</p>
                      <p className="mt-0.5 text-xs font-medium leading-5 text-slate-500">
                        Kami dapat mengirim link reset ke email akun Anda.
                      </p>
                    </div>
                    <button
                      type="button"
                      disabled={resetLinkSending || resetLinkCooldown > 0 || !user?.email}
                      onClick={() => void requestPasswordResetLink()}
                      className="inline-flex min-h-10 w-full items-center justify-center rounded-xl border border-slate-200 px-4 text-xs font-black text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
                    >
                      {resetLinkSending ? (
                        <Loader2 className="mr-2 animate-spin" size={15} />
                      ) : (
                        <MailCheck className="mr-2" size={15} />
                      )}
                      {resetLinkCooldown > 0 ? `Kirim ulang dalam ${resetLinkCooldown} detik` : "Lupa kata sandi?"}
                    </button>
                  </div>
                )}

                {resetLinkSent && user?.email && (
                  <p className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs font-semibold leading-5 text-emerald-800">
                    Link reset kata sandi sudah dikirim ke {user.email}. Buka tautan di email tersebut untuk membuat kata
                    sandi baru.
                  </p>
                )}
              </>
            )}
          </div>
        </section>

        <section className="overflow-hidden rounded-[1.75rem] border border-slate-100 bg-white shadow-sm">
          <header className="border-b border-slate-100 px-5 py-4 sm:px-6">
            <h2 className="text-sm font-black text-slate-900">Verifikasi pencairan</h2>
            <p className="mt-1 text-xs font-medium leading-5 text-slate-500">
              Cara membuktikan identitas saat rekening pencairan disimpan. Mengubah metode wajib memakai kata sandi akun.
            </p>
          </header>
          <div className="space-y-4 px-5 py-5 sm:px-6">
            <div className="grid gap-3 sm:grid-cols-2">
              <button
                type="button"
                onClick={() => void changePayoutMethod("otp")}
                aria-pressed={payoutMethod === "otp"}
                className={`flex min-h-16 items-start gap-3 rounded-2xl border p-4 text-left transition ${payoutMethod === "otp" ? "border-emerald-300 bg-emerald-50" : "border-slate-200 hover:bg-slate-50"}`}
              >
                <MailCheck size={18} className={payoutMethod === "otp" ? "text-emerald-700" : "text-slate-400"} />
                <span className="min-w-0">
                  <span className="block text-sm font-black text-slate-900">Kode email (disarankan)</span>
                  <span className="mt-0.5 block text-xs font-medium leading-5 text-slate-500">Kode 6 digit dikirim ke email akun.</span>
                </span>
              </button>
              <button
                type="button"
                onClick={() => void changePayoutMethod("password")}
                aria-pressed={payoutMethod === "password"}
                className={`flex min-h-16 items-start gap-3 rounded-2xl border p-4 text-left transition ${payoutMethod === "password" ? "border-emerald-300 bg-emerald-50" : "border-slate-200 hover:bg-slate-50"}`}
              >
                <KeyRound size={18} className={payoutMethod === "password" ? "text-emerald-700" : "text-slate-400"} />
                <span className="min-w-0">
                  <span className="block text-sm font-black text-slate-900">Kata sandi akun</span>
                  <span className="mt-0.5 block text-xs font-medium leading-5 text-slate-500">Cocok bila Anda rutin memakai kata sandi.</span>
                </span>
              </button>
            </div>

            <div className="space-y-3 border-t border-slate-100 pt-4">
              <Input
                type="password"
                autoComplete="current-password"
                placeholder="Kata sandi akun saat ini"
                value={methodPassword}
                onChange={(event) => setMethodPassword(event.target.value)}
                className="h-11 rounded-xl border-slate-200 bg-slate-50"
              />
              <div className="flex gap-2">
                <Input
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  placeholder="Kode email 6 digit (untuk kembali ke OTP)"
                  maxLength={6}
                  value={methodCode}
                  onChange={(event) => setMethodCode(event.target.value.replace(/[^0-9]/g, "").slice(0, 6))}
                  className="h-11 rounded-xl border-slate-200 bg-slate-50 font-mono tracking-[.25em]"
                />
                <button
                  type="button"
                  onClick={() => void sendPayoutOtp()}
                  disabled={methodSending || otpCooldown > 0}
                  className="ml-2 inline-flex min-h-11 shrink-0 items-center justify-center rounded-xl border border-slate-200 px-3 text-xs font-black text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  {methodSending ? <Loader2 className="animate-spin" size={15} /> : otpCooldown > 0 ? `Kirim ulang (${otpCooldown})` : "Kirim kode"}
                </button>
              </div>
              <p className="flex items-center gap-2 text-xs font-medium text-slate-500">
                <Wallet size={14} /> Metode aktif: {payoutMethod === "otp" ? "kode email" : "kata sandi akun"}.
              </p>
            </div>
          </div>
        </section>
      </div>
    </TeacherLayout>
  );
}
