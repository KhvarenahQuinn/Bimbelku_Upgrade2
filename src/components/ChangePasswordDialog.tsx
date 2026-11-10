import { notify } from "@/lib/notify";
import { FormEvent, useState } from "react";
import { Eye, EyeOff, KeyRound, Loader2, ShieldCheck } from "lucide-react";
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
import { formatAccountDate } from "@/lib/date";
import http, { getApiError } from "@/lib/http";

interface ChangePasswordDialogProps {
  hasPassword?: boolean;
  passwordUpdatedAt?: string | null;
  onUpdated?: (timestamp: string | null) => void;
}

const emptyForm = {
  current_password: "",
  password: "",
  password_confirmation: "",
};

export default function ChangePasswordDialog({
  hasPassword = true,
  passwordUpdatedAt,
  onUpdated,
}: ChangePasswordDialogProps) {
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(emptyForm);
  const [showCurrent, setShowCurrent] = useState(false);
  const [showNew, setShowNew] = useState(false);
  const [saving, setSaving] = useState(false);

  const reset = () => {
    setForm(emptyForm);
    setShowCurrent(false);
    setShowNew(false);
  };

  const changeOpen = (nextOpen: boolean) => {
    setOpen(nextOpen);
    if (!nextOpen) reset();
  };

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (form.password.length < 8) {
      notify.error("Kata sandi baru minimal 8 karakter.");
      return;
    }
    if (hasPassword && form.password === form.current_password) {
      notify.error("Kata sandi baru harus berbeda dari kata sandi saat ini.");
      return;
    }
    if (form.password !== form.password_confirmation) {
      notify.error("Konfirmasi kata sandi baru belum sama.");
      return;
    }

    setSaving(true);
    try {
      const response = hasPassword
        ? await http.put("/user/password", form)
        : await http.post("/user/set-initial-password", {
            new_password: form.password,
            new_password_confirmation: form.password_confirmation,
          });
      notify.success(response.data.message);
      onUpdated?.(response.data.password_updated_at || null);
      changeOpen(false);
    } catch (error) {
      notify.error(getApiError(
        error,
        hasPassword ? "Kata sandi gagal diperbarui." : "Kata sandi gagal dibuat.",
      ));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={changeOpen}>
      <DialogTrigger asChild>
        <Button type="button" className="h-11 rounded-xl bg-slate-900 px-5 font-bold hover:bg-indigo-700">
          <KeyRound className="mr-2" size={17} />
          {hasPassword ? "Ubah Kata Sandi" : "Buat Kata Sandi"}
        </Button>
      </DialogTrigger>
      <DialogContent className="max-w-md rounded-3xl border-0 p-7">
        <DialogHeader>
          <div className="mb-3 grid h-12 w-12 place-items-center rounded-2xl bg-indigo-50 text-indigo-600">
            <ShieldCheck size={23} />
          </div>
          <DialogTitle className="text-2xl font-black text-slate-900">
            {hasPassword ? "Ubah kata sandi" : "Buat Password Akun"}
          </DialogTitle>
          <DialogDescription className="leading-6">
            {hasPassword
              ? "Kata sandi disimpan melalui proses terpisah. Sesi lain akan ditutup setelah perubahan berhasil."
              : "Akun yang masuk lewat Google belum memiliki kata sandi lokal. Buat sekarang agar bisa masuk dengan email dan mengatur PIN pembayaran."}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={submit} className="mt-2 space-y-5">
          {hasPassword && (
            <PasswordField
              label="Kata sandi saat ini"
              value={form.current_password}
              visible={showCurrent}
              onVisibleChange={setShowCurrent}
              onChange={(value) => setForm((current) => ({ ...current, current_password: value }))}
              autoComplete="current-password"
            />
          )}
          <PasswordField
            label="Kata sandi baru"
            value={form.password}
            visible={showNew}
            onVisibleChange={setShowNew}
            onChange={(value) => setForm((current) => ({ ...current, password: value }))}
            autoComplete="new-password"
          />
          <div>
            <Label className="mb-2 block font-bold text-slate-700">Konfirmasi kata sandi baru</Label>
            <Input
              required
              minLength={8}
              type={showNew ? "text" : "password"}
              autoComplete="new-password"
              value={form.password_confirmation}
              onChange={(event) => setForm((current) => ({
                ...current,
                password_confirmation: event.target.value,
              }))}
              className="h-12 rounded-xl"
            />
          </div>
          <DialogFooter className="gap-2 pt-2">
            <Button type="button" variant="outline" onClick={() => changeOpen(false)} className="h-11 rounded-xl">
              Batal
            </Button>
            <Button disabled={saving} className="h-11 rounded-xl bg-indigo-600 font-bold hover:bg-indigo-700">
              {saving && <Loader2 className="mr-2 animate-spin" size={17} />}
              {hasPassword ? "Perbarui Kata Sandi" : "Buat Kata Sandi"}
            </Button>
          </DialogFooter>
        </form>

        {hasPassword && passwordUpdatedAt && (
          <p className="text-xs text-slate-400">
            Pembaruan terakhir: {formatAccountDate(passwordUpdatedAt)}
          </p>
        )}
      </DialogContent>
    </Dialog>
  );
}

function PasswordField({
  label,
  value,
  visible,
  onVisibleChange,
  onChange,
  autoComplete,
}: {
  label: string;
  value: string;
  visible: boolean;
  onVisibleChange: (value: boolean) => void;
  onChange: (value: string) => void;
  autoComplete: string;
}) {
  return (
    <div>
      <Label className="mb-2 block font-bold text-slate-700">{label}</Label>
      <div className="relative">
        <Input
          required
          type={visible ? "text" : "password"}
          autoComplete={autoComplete}
          value={value}
          onChange={(event) => onChange(event.target.value)}
          className="h-12 rounded-xl pr-12"
        />
        <button
          type="button"
          onClick={() => onVisibleChange(!visible)}
          className="absolute inset-y-0 right-0 px-4 text-slate-400 hover:text-indigo-600"
          aria-label={visible ? `Sembunyikan ${label.toLowerCase()}` : `Tampilkan ${label.toLowerCase()}`}
        >
          {visible ? <EyeOff size={18} /> : <Eye size={18} />}
        </button>
      </div>
    </div>
  );
}
