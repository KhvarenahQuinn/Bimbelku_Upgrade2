import { notify } from "@/lib/notify";
import { FormEvent, ReactNode, useCallback, useEffect, useState } from "react";
import { KeyRound, Loader2, MailCheck, ShieldCheck } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import http, { getApiError, getApiErrorDetails } from "@/lib/http";

type Mode = "overview" | "set" | "reset";

type ForgotMethod = "password" | "email";

interface PinStatus {
  configured: boolean;
  has_password: boolean;
}

interface PinSetupModalProps {
  open?: boolean;
  onOpenChange?: (open: boolean) => void;
  configured?: boolean;
  hasPassword?: boolean;
  onStatusChange?: (status: PinStatus) => void;
  trigger?: ReactNode;
}

const emptyPinForm = {
  current_password: "",
  code: "",
  pin: "",
  pin_confirmation: "",
};

const resetPinForm = () => ({ ...emptyPinForm });

export default function PinSetupModal({
  open: controlledOpen,
  onOpenChange,
  configured: configuredProp,
  hasPassword: hasPasswordProp,
  onStatusChange,
  trigger,
}: PinSetupModalProps) {
  const [internalOpen, setInternalOpen] = useState(false);
  const open = controlledOpen ?? internalOpen;
  const [status, setStatus] = useState<PinStatus>({
    configured: Boolean(configuredProp),
    has_password: hasPasswordProp !== false,
  });
  const [loadingStatus, setLoadingStatus] = useState(false);
  const [mode, setMode] = useState<Mode>("overview");
  const [forgotMethod, setForgotMethod] = useState<ForgotMethod | null>(null);
  const [form, setForm] = useState(emptyPinForm);
  const [saving, setSaving] = useState(false);
  const [requestingReset, setRequestingReset] = useState(false);
  const [sendingEmailLink, setSendingEmailLink] = useState(false);
  const [emailLinkSent, setEmailLinkSent] = useState(false);
  const [emailLinkCooldown, setEmailLinkCooldown] = useState(0);

  const setOpen = useCallback((nextOpen: boolean) => {
    if (controlledOpen === undefined) setInternalOpen(nextOpen);
    onOpenChange?.(nextOpen);
  }, [controlledOpen, onOpenChange]);

  const resetForm = () => {
    setForm(resetPinForm());
    setMode("overview");
    setForgotMethod(null);
    setEmailLinkSent(false);
    setEmailLinkCooldown(0);
  };

  const loadStatus = useCallback(async () => {
    setLoadingStatus(true);
    try {
      const response = await http.get<PinStatus>("/student/payment-pin/status");
      const next: PinStatus = {
        configured: Boolean(response.data?.configured),
        has_password: response.data?.has_password !== false,
      };
      setStatus(next);
      onStatusChange?.(next);
    } catch {
      // Pertahankan nilai awal jika endpoint status belum tersedia.
    } finally {
      setLoadingStatus(false);
    }
  }, [onStatusChange]);

  useEffect(() => {
    if (!open) {
      resetForm();
      return;
    }
    void loadStatus();
  }, [open, loadStatus]);

  useEffect(() => {
    if (emailLinkCooldown <= 0) return;

    const timer = window.setTimeout(() => {
      setEmailLinkCooldown((seconds) => Math.max(0, seconds - 1));
    }, 1000);

    return () => window.clearTimeout(timer);
  }, [emailLinkCooldown]);

  const changeOpen = (nextOpen: boolean) => {
    setOpen(nextOpen);
    if (!nextOpen) resetForm();
  };

  const update = (key: keyof typeof emptyPinForm, value: string) => {
    setForm((current) => ({ ...current, [key]: value }));
  };

  const validatePin = () => {
    if (!/^\d{6}$/.test(form.pin) || form.pin !== form.pin_confirmation) {
      notify.error("PIN baru harus 6 angka dan konfirmasinya harus sama.");
      return false;
    }
    return true;
  };

  const savePin = async (event: FormEvent) => {
    event.preventDefault();
    if (!validatePin()) return;
    if (status.has_password && !form.current_password) {
      notify.error("Masukkan kata sandi akun terlebih dahulu.");
      return;
    }

    setSaving(true);
    try {
      await http.post("/student/payment-pin", {
        ...(status.has_password ? { current_password: form.current_password } : {}),
        pin: form.pin,
        pin_confirmation: form.pin_confirmation,
      });
      setStatus((current) => ({ ...current, configured: true }));
      onStatusChange?.({ ...status, configured: true });
      notify.success("PIN pembayaran berhasil disimpan.");
      changeOpen(false);
    } catch (error) {
      notify.error(getApiError(error, "PIN pembayaran gagal disimpan."));
    } finally {
      setSaving(false);
    }
  };

  const requestReset = async () => {
    setRequestingReset(true);
    try {
      const response = await http.post<{ verification_method?: ForgotMethod }>("/student/payment-pin/forgot");
      const method = response.data?.verification_method === "password" ? "password" : "email";
      setForgotMethod(method);
      setMode("reset");
      notify.success(response.data?.verification_method === "password"
        ? "Verifikasi kata sandi akun untuk mengatur ulang PIN."
        : "Kode OTP reset PIN telah dikirim ke email akun Anda.");
    } catch (error) {
      notify.error(getApiError(error, "Permintaan reset PIN belum dapat diproses."));
    } finally {
      setRequestingReset(false);
    }
  };

  const requestEmailLink = async () => {
    setSendingEmailLink(true);
    try {
      const response = await http.post<{ message?: string; resend_after_seconds?: number }>(
        "/student/payment-pin/forgot-email",
      );
      setEmailLinkSent(true);
      setEmailLinkCooldown(response.data?.resend_after_seconds ?? 60);
      notify.success(response.data?.message || "Link reset PIN telah dikirim ke email akun Anda.");
    } catch (error) {
      const details = getApiErrorDetails(error, "Link reset PIN belum dapat dikirim.");
      if (details.code === "payment_pin_reset_cooldown") {
        setEmailLinkSent(true);
        setEmailLinkCooldown(details.retryAfterSeconds || 60);
      }
      notify.error(details.message);
    } finally {
      setSendingEmailLink(false);
    }
  };

  const resetPin = async (event: FormEvent) => {
    event.preventDefault();
    if (!validatePin()) return;
    if (forgotMethod === "password" && !form.current_password) {
      notify.error("Masukkan kata sandi akun terlebih dahulu.");
      return;
    }
    if (forgotMethod === "email" && !/^\d{6}$/.test(form.code)) {
      notify.error("Masukkan kode OTP 6 angka dari email.");
      return;
    }

    setSaving(true);
    try {
      await http.post("/student/payment-pin/reset", {
        pin: form.pin,
        pin_confirmation: form.pin_confirmation,
        ...(forgotMethod === "password" ? { current_password: form.current_password } : { code: form.code }),
      });
      setStatus((current) => ({ ...current, configured: true }));
      onStatusChange?.({ ...status, configured: true });
      notify.success("PIN pembayaran berhasil diatur ulang.");
      changeOpen(false);
    } catch (error) {
      notify.error(getApiError(error, "PIN pembayaran gagal diatur ulang."));
    } finally {
      setSaving(false);
    }
  };

  const pinFields = (
    <>
      <div>
        <Label className="mb-2 block font-bold text-slate-700">PIN pembayaran baru</Label>
        <Input
          required
          inputMode="numeric"
          maxLength={6}
          autoComplete="new-password"
          placeholder="6 angka"
          value={form.pin}
          onChange={(event) => update("pin", event.target.value.replace(/\D/g, ""))}
          className="h-12 rounded-xl tracking-[0.3em]"
        />
      </div>
      <div>
        <Label className="mb-2 block font-bold text-slate-700">Ulangi PIN pembayaran</Label>
        <Input
          required
          inputMode="numeric"
          maxLength={6}
          autoComplete="new-password"
          placeholder="6 angka"
          value={form.pin_confirmation}
          onChange={(event) => update("pin_confirmation", event.target.value.replace(/\D/g, ""))}
          className="h-12 rounded-xl tracking-[0.3em]"
        />
      </div>
    </>
  );

  return (
    <Dialog open={open} onOpenChange={changeOpen}>
      {trigger && <DialogTrigger asChild>{trigger}</DialogTrigger>}
      <DialogContent className="max-w-md rounded-3xl border-0 p-7">
        <DialogHeader>
          <div className="mb-3 grid h-12 w-12 place-items-center rounded-2xl bg-indigo-50 text-indigo-600">
            <ShieldCheck size={23} />
          </div>
          <DialogTitle className="text-2xl font-black text-slate-900">
            {mode === "set" ? "Buat PIN pembayaran" : mode === "reset" ? "Reset PIN pembayaran" : "PIN pembayaran"}
          </DialogTitle>
          <DialogDescription className="leading-6">
            {mode === "set"
              ? "PIN 6 angka melindungi setiap pembayaran yang memakai saldo BimbelKu."
              : mode === "reset"
                ? forgotMethod === "email"
                  ? "Masukkan kode OTP yang dikirim ke email akun Anda, lalu buat PIN baru."
                  : "Verifikasi kata sandi akun Anda, lalu buat PIN baru."
                : status.configured
                  ? "PIN pembayaran sudah aktif. Anda dapat menggantinya atau mengatur ulang jika lupa."
                  : "Buat PIN 6 angka untuk mengaktifkan pembayaran memakai saldo BimbelKu."}
          </DialogDescription>
        </DialogHeader>

        {mode === "overview" && (
          <div className="mt-2 space-y-3">
            <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm font-medium text-slate-600">
              {loadingStatus
                ? "Memeriksa status PIN..."
                : status.configured
                  ? "PIN pembayaran aktif."
                  : "PIN pembayaran belum dibuat."}
            </div>
            <Button
              type="button"
              onClick={() => {
                setForm(resetPinForm());
                setMode("set");
              }}
              className="h-11 w-full rounded-xl bg-indigo-600 font-bold hover:bg-indigo-700"
            >
              <KeyRound className="mr-2" size={17} />
              {status.configured ? "Ganti PIN" : "Buat PIN"}
            </Button>
            {status.configured && (
              <Button
                type="button"
                variant="outline"
                disabled={requestingReset}
                onClick={() => void requestReset()}
                className="h-11 w-full rounded-xl font-bold"
              >
                {requestingReset ? <Loader2 className="mr-2 animate-spin" size={17} /> : <MailCheck className="mr-2" size={17} />}
                Lupa PIN
              </Button>
            )}
          </div>
        )}

        {mode === "set" && (
          <form onSubmit={savePin} className="mt-2 space-y-5">
            {status.has_password && (
              <div>
                <Label className="mb-2 block font-bold text-slate-700">Kata sandi akun</Label>
                <Input
                  required
                  type="password"
                  autoComplete="current-password"
                  value={form.current_password}
                  onChange={(event) => update("current_password", event.target.value)}
                  className="h-12 rounded-xl"
                />
              </div>
            )}
            {pinFields}
            <DialogFooter className="gap-2 pt-2">
              <Button type="button" variant="outline" onClick={() => setMode("overview")} className="h-11 rounded-xl">
                Batal
              </Button>
              <Button disabled={saving} className="h-11 rounded-xl bg-indigo-600 font-bold hover:bg-indigo-700">
                {saving && <Loader2 className="mr-2 animate-spin" size={17} />}
                Simpan PIN
              </Button>
            </DialogFooter>
          </form>
        )}

        {mode === "reset" && (
          <form onSubmit={resetPin} className="mt-2 space-y-5">
            {forgotMethod === "email" ? (
              <div>
                <Label className="mb-2 block font-bold text-slate-700">Kode OTP dari email</Label>
                <Input
                  required
                  inputMode="numeric"
                  maxLength={6}
                  autoComplete="one-time-code"
                  placeholder="6 angka"
                  value={form.code}
                  onChange={(event) => update("code", event.target.value.replace(/\D/g, ""))}
                  className="h-12 rounded-xl tracking-[0.3em]"
                />
              </div>
            ) : (
              <div>
                <Label className="mb-2 block font-bold text-slate-700">Kata sandi akun</Label>
                <Input
                  required
                  type="password"
                  autoComplete="current-password"
                  value={form.current_password}
                  onChange={(event) => update("current_password", event.target.value)}
                  className="h-12 rounded-xl"
                />
              </div>
            )}
            {pinFields}
            <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
              <p className="text-xs font-bold leading-5 text-slate-600">
                {forgotMethod === "password"
                  ? "Tidak ingat kata sandi akun? Minta tautan reset PIN ke email."
                  : "Ingin memakai tautan email? Minta link reset PIN baru."}
              </p>
              {emailLinkSent && (
                <p className="mt-2 text-xs font-semibold leading-5 text-emerald-700">
                  Link reset PIN sudah dikirim. Buka tautan di email akun Anda untuk membuat PIN baru.
                </p>
              )}
              <Button
                type="button"
                variant="outline"
                disabled={sendingEmailLink || emailLinkCooldown > 0}
                onClick={() => void requestEmailLink()}
                className="mt-3 h-10 w-full rounded-xl text-xs font-bold"
              >
                {sendingEmailLink ? <Loader2 className="mr-2 animate-spin" size={15} /> : <MailCheck className="mr-2" size={15} />}
                {emailLinkCooldown > 0
                  ? `Kirim ulang dalam ${emailLinkCooldown} detik`
                  : "Kirim link reset PIN ke email"}
              </Button>
            </div>
            <DialogFooter className="gap-2 pt-2">
              <Button type="button" variant="outline" onClick={() => setMode("overview")} className="h-11 rounded-xl">
                Batal
              </Button>
              <Button disabled={saving} className="h-11 rounded-xl bg-indigo-600 font-bold hover:bg-indigo-700">
                {saving && <Loader2 className="mr-2 animate-spin" size={17} />}
                Simpan PIN Baru
              </Button>
            </DialogFooter>
          </form>
        )}
      </DialogContent>
    </Dialog>
  );
}
