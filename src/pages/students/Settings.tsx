import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import {
  ArrowLeft,
  ChevronRight,
  CreditCard,
  KeyRound,
  Loader2,
  MailCheck,
  ShieldCheck,
  WalletCards,
} from "lucide-react";

import StudentLayout from "@/components/StudentLayout";
import ChangePasswordDialog from "@/components/ChangePasswordDialog";
import PinSetupModal from "@/components/PinSetupModal";
import http, { getApiErrorDetails } from "@/lib/http";
import { formatAccountDate } from "@/lib/date";
import { notify } from "@/lib/notify";

const rupiah = (value: number) => new Intl.NumberFormat("id-ID", {
  style: "currency",
  currency: "IDR",
  maximumFractionDigits: 0,
}).format(value || 0);

interface AccountUser {
  name?: string;
  email?: string;
  has_password?: boolean;
  password_updated_at?: string | null;
}

interface WalletSnapshot {
  balance: number;
  reserved_balance: number;
  total_credit: number;
}

interface PinStatus {
  configured: boolean;
  has_password: boolean;
}

export default function Settings() {
  const [user, setUser] = useState<AccountUser | null>(null);
  const [wallet, setWallet] = useState<WalletSnapshot | null>(null);
  const [pinStatus, setPinStatus] = useState<PinStatus>({ configured: false, has_password: true });
  const [loading, setLoading] = useState(true);
  const [pinModalOpen, setPinModalOpen] = useState(false);
  const [resetLinkSending, setResetLinkSending] = useState(false);
  const [resetLinkSent, setResetLinkSent] = useState(false);
  const [resetLinkCooldown, setResetLinkCooldown] = useState(0);

  useEffect(() => {
    let active = true;

    void Promise.allSettled([
      http.get<AccountUser>("/user"),
      http.get<WalletSnapshot>("/student/wallet"),
      http.get<PinStatus>("/student/payment-pin/status"),
    ]).then(([userResult, walletResult, pinResult]) => {
      if (!active) return;
      if (userResult.status === "fulfilled") setUser(userResult.value.data);
      if (walletResult.status === "fulfilled") setWallet(walletResult.value.data);
      if (pinResult.status === "fulfilled") {
        setPinStatus({
          configured: Boolean(pinResult.value.data?.configured),
          has_password: pinResult.value.data?.has_password !== false,
        });
      }
    }).finally(() => {
      if (active) setLoading(false);
    });

    return () => {
      active = false;
    };
  }, []);

  useEffect(() => {
    if (resetLinkCooldown <= 0) return;

    const timer = window.setTimeout(() => {
      setResetLinkCooldown((seconds) => Math.max(0, seconds - 1));
    }, 1000);

    return () => window.clearTimeout(timer);
  }, [resetLinkCooldown]);

  const requestPasswordResetLink = async () => {
    const email = user?.email?.trim();
    if (!email) {
      notify.error("Email akun belum tersedia. Muat ulang halaman lalu coba lagi.");
      return;
    }

    setResetLinkSending(true);
    try {
      await http.post("/forgot-password", { email });
      setResetLinkSent(true);
      setResetLinkCooldown(60);
      notify.success("Link reset kata sandi telah dikirim ke email akun Anda.");
    } catch (error) {
      const details = getApiErrorDetails(error, "Link reset kata sandi belum dapat dikirim.");
      if (details.code === "password_reset_cooldown") {
        setResetLinkSent(true);
        setResetLinkCooldown(details.retryAfterSeconds || 60);
      }
      notify.error(details.message);
    } finally {
      setResetLinkSending(false);
    }
  };

  return (
    <StudentLayout title="Pengaturan">
      <div className="mx-auto max-w-3xl space-y-5">
        <div className="flex items-center gap-3">
          <Link
            to="/student/account"
            className="grid h-11 w-11 place-items-center rounded-2xl border border-slate-200 bg-white text-slate-600 transition hover:bg-slate-50"
            aria-label="Kembali ke akun"
          >
            <ArrowLeft size={19} />
          </Link>
          <div>
            <p className="text-xs font-black uppercase tracking-[0.18em] text-indigo-600">Pengaturan akun</p>
            <h1 className="text-xl font-black text-slate-950 sm:text-2xl">Keamanan dan pembayaran</h1>
          </div>
        </div>

        <section className="overflow-hidden rounded-[1.75rem] border border-slate-100 bg-white shadow-sm">
          <header className="border-b border-slate-100 px-5 py-4 sm:px-6">
            <h2 className="text-sm font-black text-slate-900">PIN pembayaran</h2>
            <p className="mt-1 text-xs font-medium leading-5 text-slate-500">
              PIN 6 angka dipakai saat membayar memakai saldo BimbelKu.
            </p>
          </header>
          <div className="flex flex-col gap-4 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <div className="flex items-start gap-3">
              <span className="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-indigo-50 text-indigo-600">
                <KeyRound size={20} />
              </span>
              <div>
                <p className="text-sm font-black text-slate-800">
                  {loading ? "Memeriksa status..." : pinStatus.configured ? "PIN aktif" : "PIN belum dibuat"}
                </p>
                <p className="mt-0.5 text-xs font-medium leading-5 text-slate-500">
                  {pinStatus.configured
                    ? "Ganti PIN kapan saja, atau kirim link reset ke email jika lupa."
                    : "Buat PIN untuk mulai memakai saldo BimbelKu."}
                </p>
              </div>
            </div>
            <button
              type="button"
              onClick={() => setPinModalOpen(true)}
              className="inline-flex min-h-11 items-center justify-center rounded-xl bg-indigo-600 px-5 text-sm font-black text-white transition hover:bg-indigo-700"
            >
              <ShieldCheck className="mr-2" size={17} />
              {pinStatus.configured ? "Kelola PIN" : "Buat PIN"}
            </button>
          </div>
        </section>

        <section className="overflow-hidden rounded-[1.75rem] border border-slate-100 bg-white shadow-sm">
          <header className="border-b border-slate-100 px-5 py-4 sm:px-6">
            <h2 className="text-sm font-black text-slate-900">Kata sandi akun</h2>
            <p className="mt-1 text-xs font-medium leading-5 text-slate-500">
              {user?.has_password === false
                ? "Akun Google belum memiliki kata sandi lokal. Buat sekarang agar bisa masuk dengan email."
                : `Perbarui kata sandi secara berkala${user?.password_updated_at ? ` • terakhir ${formatAccountDate(user.password_updated_at)}` : ""}.`}
            </p>
          </header>
          <div className="space-y-4 px-5 py-5 sm:px-6">
            <div className="flex items-center justify-between gap-4">
              <div className="flex items-start gap-3">
                <span className="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-slate-100 text-slate-600">
                  <ShieldCheck size={20} />
                </span>
                <div>
                  <p className="text-sm font-black text-slate-800">{user?.email || "Email akun"}</p>
                  <p className="mt-0.5 text-xs font-medium text-slate-500">
                    {user?.has_password === false ? "Kata sandi belum dibuat" : "Kata sandi aktif"}
                  </p>
                </div>
              </div>
              <ChangePasswordDialog
                hasPassword={user?.has_password !== false}
                passwordUpdatedAt={user?.password_updated_at ?? null}
                onUpdated={(timestamp) => setUser((current) => current ? {
                  ...current,
                  has_password: true,
                  password_updated_at: timestamp,
                } : current)}
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
                  disabled={resetLinkSending || resetLinkCooldown > 0 || loading || !user?.email}
                  onClick={() => void requestPasswordResetLink()}
                  className="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-200 px-4 text-xs font-black text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  {resetLinkSending ? <Loader2 className="mr-2 animate-spin" size={15} /> : <MailCheck className="mr-2" size={15} />}
                  {resetLinkCooldown > 0 ? `Kirim ulang dalam ${resetLinkCooldown} detik` : "Lupa kata sandi?"}
                </button>
              </div>
            )}
            {resetLinkSent && user?.email && (
              <p className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs font-semibold leading-5 text-emerald-800">
                Link reset kata sandi sudah dikirim ke {user.email}. Buka tautan di email tersebut untuk membuat kata sandi baru.
              </p>
            )}
          </div>
        </section>

        <section className="overflow-hidden rounded-[1.75rem] border border-slate-100 bg-white shadow-sm">
          <header className="flex items-center justify-between border-b border-slate-100 px-5 py-4 sm:px-6">
            <div>
              <h2 className="text-sm font-black text-slate-900">Saldo BimbelKu</h2>
              <p className="mt-1 text-xs font-medium text-slate-500">Saldo dapat dipakai untuk pembayaran paket belajar.</p>
            </div>
            <span className="grid h-11 w-11 place-items-center rounded-2xl bg-emerald-50 text-emerald-600">
              <WalletCards size={20} />
            </span>
          </header>
          <div className="space-y-4 px-5 py-5 sm:px-6">
            {loading ? (
              <div className="flex items-center gap-2 text-sm font-medium text-slate-500">
                <Loader2 className="animate-spin" size={16} /> Memuat saldo...
              </div>
            ) : (
              <>
                <div className="grid gap-3 sm:grid-cols-2">
                  <div className="rounded-2xl bg-slate-50 p-4">
                    <p className="text-[10px] font-black uppercase tracking-wider text-slate-400">Saldo tersedia</p>
                    <p className="mt-1 text-lg font-black text-slate-900">{rupiah(wallet?.balance ?? 0)}</p>
                  </div>
                  <div className="rounded-2xl bg-slate-50 p-4">
                    <p className="text-[10px] font-black uppercase tracking-wider text-slate-400">Sedang ditahan</p>
                    <p className="mt-1 text-lg font-black text-slate-900">{rupiah(wallet?.reserved_balance ?? 0)}</p>
                  </div>
                </div>
                <Link
                  to="/student/history"
                  className="flex min-h-12 items-center justify-between rounded-2xl border border-slate-200 px-4 text-sm font-black text-slate-700 transition hover:bg-slate-50"
                >
                  <span className="inline-flex items-center gap-2">
                    <CreditCard size={17} className="text-slate-400" /> Lihat riwayat transaksi
                  </span>
                  <ChevronRight size={17} className="text-slate-300" />
                </Link>
              </>
            )}
          </div>
        </section>
      </div>

      <PinSetupModal
        open={pinModalOpen}
        onOpenChange={setPinModalOpen}
        configured={pinStatus.configured}
        hasPassword={pinStatus.has_password}
        onStatusChange={setPinStatus}
      />
    </StudentLayout>
  );
}
