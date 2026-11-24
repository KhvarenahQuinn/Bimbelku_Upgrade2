import { notify } from "@/lib/notify";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { BookOpen, Coins, Loader2, Plus, Save, Trash2 } from "lucide-react";
import AdminLayout from "@/components/AdminLayout";
import PrivateParticipantPricingEditor from "@/components/PrivateParticipantPricingEditor";
import { useConfirmDialog } from "@/components/ConfirmDialogProvider";
import SubjectCombobox, { SubjectOption } from "@/components/SubjectCombobox";
import { EDUCATION_LEVELS } from "@/lib/educationCatalog";
import { hourlyRateAmountError, MAX_HOURLY_RATE } from "@/lib/hourlyRates";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import http, { getApiError, getCached } from "@/lib/http";

interface Rate {
  id: number;
  subject_name: string;
  education_level?: string | null;
  class_type: "private";
  learning_mode: "online" | "offline";
  amount: number;
  is_active: boolean;
}

const formatCurrency = (value: number) => new Intl.NumberFormat("id-ID", {
  style: "currency",
  currency: "IDR",
  maximumFractionDigits: 0,
}).format(value);

export default function HourlyRates() {
  const confirm = useConfirmDialog();
  const [rates, setRates] = useState<Rate[]>([]);
  const [defaults, setDefaults] = useState({ private_online: 40000, private_offline: 40000 });
  const [form, setForm] = useState({ subject_name: "", education_level: "all", learning_mode: "online", amount: "" });
  const [subjectOptions, setSubjectOptions] = useState<SubjectOption[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState("");
  const [defaultsError, setDefaultsError] = useState("");
  const customAmount = parseMoney(form.amount);
  const customAmountError = formError || (form.amount.trim() ? hourlyRateAmountError(customAmount) : "");

  const loadRates = useCallback(async () => {
    setLoading(true);
    try {
      const response = await http.get("/admin/hourly-rates");
      setRates(response.data.rates || []);
      setDefaults(response.data.defaults || { private_online: 40000, private_offline: 40000 });
    } catch (error) {
      notify.error(getApiError(error));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void loadRates(); }, [loadRates]);
  useEffect(() => {
    void getCached<{ subject_options?: SubjectOption[] }>("/learning-catalog", {
      params: { compact: 1 },
      maxAgeMs: 5 * 60_000,
    })
      .then((response) => setSubjectOptions(response.data.subject_options || []))
      .catch(() => notify.error("Katalog mata pelajaran belum dapat dimuat."));
  }, []);

  const createSubject = async (name: string): Promise<SubjectOption | null> => {
    try {
      const educationLevels = form.education_level === "all"
        ? [...EDUCATION_LEVELS]
        : [form.education_level];
      const response = await http.post("/admin/subjects", {
        name,
        group_name: "Tambahan admin",
        education_levels: educationLevels,
      });
      const created = response.data.data as SubjectOption;
      setSubjectOptions((current) => [...current.filter((item) => item.id !== created.id), created]);
      notify.success(response.data.message);
      return created;
    } catch (error) {
      notify.error(getApiError(error, "Mata pelajaran gagal ditambahkan."));
      return null;
    }
  };

  const saveRate = async (event: FormEvent) => {
    event.preventDefault();
    const amount = parseMoney(form.amount);
    const amountError = hourlyRateAmountError(amount);
    if (!amount || amountError) {
      const message = amountError || "Nominal minimal Rp1.000 per jam.";
      setFormError(message);
      notify.error(message);
      return;
    }
    setFormError("");
    setSaving(true);
    try {
      const response = await http.post("/admin/hourly-rates", {
        subject_name: form.subject_name,
        education_level: form.education_level === "all" ? null : form.education_level,
        class_type: "private",
        learning_mode: form.learning_mode,
        amount,
      });
      notify.success(response.data.message);
      setForm({ subject_name: "", education_level: "all", learning_mode: "online", amount: "" });
      await loadRates();
    } catch (error) {
      notify.error(getApiError(error));
    } finally {
      setSaving(false);
    }
  };

  const saveDefaults = async () => {
    if ([defaults.private_online, defaults.private_offline].some((amount) => hourlyRateAmountError(amount))) {
      setDefaultsError("Tarif bawaan harus antara Rp1.000 dan Rp10.000.000 per jam.");
      return;
    }
    setDefaultsError("");
    try {
      const response = await http.post("/admin/hourly-rates/defaults", defaults);
      notify.success(response.data.message);
    } catch (error) {
      notify.error(getApiError(error));
    }
  };

  const deleteRate = async (id: number) => {
    const approved = await confirm({
      title: "Hapus tarif khusus?",
      description: "Permintaan baru akan kembali menggunakan tarif bawaan yang sesuai.",
      confirmText: "Hapus tarif",
      tone: "danger",
    });
    if (!approved) return;
    try {
      const response = await http.delete(`/admin/hourly-rates/${id}`);
      notify.success(response.data.message);
      await loadRates();
    } catch (error) {
      notify.error(getApiError(error));
    }
  };

  return (
    <AdminLayout title="Tarif Per Jam">
      <div className="max-w-7xl mx-auto space-y-7 pb-12">
        <section className="rounded-[2rem] bg-gradient-to-br from-slate-950 via-indigo-950 to-violet-900 px-7 py-8 text-white shadow-xl">
          <div className="flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
            <div>
              <div className="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1.5 text-xs font-bold uppercase tracking-widest text-indigo-100"><Coins size={14} /> Harga terpusat</div>
              <h1 className="mt-4 text-3xl font-black">Atur tarif satu jam bimbingan</h1>
              <p className="mt-2 max-w-2xl text-sm leading-6 text-indigo-100/80">Harga online dan offline dapat dibedakan admin, tetapi berlaku seragam bagi semua tutor pada mode yang sama.</p>
            </div>
          </div>
        </section>

        <PrivateParticipantPricingEditor />
        <div className="grid grid-cols-1 xl:grid-cols-[.8fr_1.2fr] gap-7 items-start">
          <div className="space-y-6">
            <section className="rounded-[2rem] border border-slate-100 bg-white p-6 shadow-sm">
              <h2 className="text-xl font-black text-slate-900">Tarif bawaan</h2>
              <p className="mt-1 text-sm text-slate-500">Dipakai ketika tarif khusus belum dibuat.</p>
              <div className="mt-5 space-y-4">
                <MoneyInput label="Privat online / jam" value={defaults.private_online} onChange={(value) => { setDefaults((current) => ({ ...current, private_online: value })); setDefaultsError(""); }} />
                <MoneyInput label="Privat offline / jam" value={defaults.private_offline} onChange={(value) => { setDefaults((current) => ({ ...current, private_offline: value })); setDefaultsError(""); }} />
                {defaultsError && <p role="alert" className="text-sm font-semibold text-rose-700">{defaultsError}</p>}
                <Button onClick={saveDefaults} className="h-12 w-full rounded-xl bg-indigo-600 hover:bg-indigo-700 font-bold"><Save size={17} className="mr-2" /> Simpan tarif bawaan</Button>
              </div>
            </section>

            <form onSubmit={saveRate} className="rounded-[2rem] border border-slate-100 bg-white p-6 shadow-sm">
              <h2 className="text-xl font-black text-slate-900">Tambah tarif khusus</h2>
              <div className="mt-5 space-y-4">
                <div>
                  <Label className="mb-2 block font-bold">Mata pelajaran</Label>
                  <SubjectCombobox
                    options={subjectOptions}
                    value={form.subject_name}
                    educationLevel={form.education_level === "all" ? undefined : form.education_level}
                    allowCreate
                    onCreate={createSubject}
                    onChange={(value) => setForm((current) => ({ ...current, subject_name: value }))}
                    placeholder="Cari atau tambahkan mapel"
                  />
                  <p className="mt-2 text-xs leading-5 text-slate-500">Ketik untuk memfilter. Jika belum ada, pilih tombol tambah yang muncul di daftar.</p>
                </div>
                <div><Label className="mb-2 block font-bold">Jenjang</Label><Select value={form.education_level} onValueChange={(value) => setForm((current) => ({ ...current, education_level: value }))}><SelectTrigger className="h-12 rounded-xl"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="all">Semua jenjang</SelectItem>{EDUCATION_LEVELS.map((level) => <SelectItem key={level} value={level}>{level}</SelectItem>)}</SelectContent></Select></div>
                <div><Label className="mb-2 block font-bold">Mode</Label><Select value={form.learning_mode} onValueChange={(value) => setForm((current) => ({ ...current, learning_mode: value }))}><SelectTrigger className="h-12 rounded-xl"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="online">Online</SelectItem><SelectItem value="offline">Offline</SelectItem></SelectContent></Select></div>
                <div><Label htmlFor="custom-hourly-rate" className="mb-2 block font-bold">Nominal per jam</Label><Input id="custom-hourly-rate" type="text" inputMode="numeric" min="1000" max={MAX_HOURLY_RATE} step="1000" aria-invalid={Boolean(customAmountError)} aria-describedby={customAmountError ? "custom-hourly-rate-error" : undefined} value={form.amount} onChange={(event) => { setForm((current) => ({ ...current, amount: sanitizeMoney(event.target.value) })); setFormError(""); }} className="h-12 rounded-xl" required />{customAmountError && <p id="custom-hourly-rate-error" role="alert" className="mt-1 text-sm font-semibold text-rose-700">{customAmountError}</p>}<p className="mt-1 text-xs text-slate-500">Rp1.000–Rp10.000.000 per jam.</p></div>
                <Button type="submit" disabled={saving} className="h-12 w-full rounded-xl bg-slate-950 hover:bg-indigo-700 font-bold">{saving ? <Loader2 size={17} className="mr-2 animate-spin" /> : <Plus size={17} className="mr-2" />} Simpan tarif</Button>
              </div>
            </form>
          </div>

          <section className="rounded-[2rem] border border-slate-100 bg-white p-6 shadow-sm">
            <div className="flex items-center justify-between gap-4">
              <div><h2 className="text-xl font-black text-slate-900">Daftar tarif khusus</h2><p className="mt-1 text-sm text-slate-500">Hanya override aktif yang dipertimbangkan; harga bawaan dipakai jika tidak ada override aktif yang cocok.</p></div>
              <div className="h-11 w-11 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center"><BookOpen size={20} /></div>
            </div>

            {loading ? <div className="min-h-72 flex items-center justify-center"><Loader2 className="h-8 w-8 animate-spin text-indigo-600" /></div> : rates.length === 0 ? (
              <div className="mt-6 rounded-2xl border-2 border-dashed border-slate-200 py-16 text-center text-sm text-slate-400">Belum ada tarif khusus.</div>
            ) : (
              <div className="mt-6 space-y-3">
                {rates.map((rate) => (
                  <div key={rate.id} className="flex items-center justify-between gap-4 rounded-2xl border border-slate-100 bg-slate-50/70 p-4 transition hover:bg-white hover-shadow-sm">
                    <div className="min-w-0"><div className="flex flex-wrap items-center gap-2"><p className="truncate font-black text-slate-900">{rate.subject_name}</p><span className={`rounded-full px-2.5 py-1 text-[10px] font-black ${rate.is_active ? "bg-emerald-100 text-emerald-800" : "bg-slate-200 text-slate-700"}`}>{rate.is_active ? "Aktif · dapat digunakan" : "Nonaktif · tidak diterapkan"}</span></div><p className="mt-1 text-xs text-slate-500">{rate.education_level || "Semua jenjang"} • Privat • {rate.learning_mode === "online" ? "Online" : "Offline"}</p></div>
                    <div className="flex items-center gap-3"><p className="whitespace-nowrap font-black text-indigo-700">{formatCurrency(Number(rate.amount))}</p><Button aria-label={`Hapus tarif ${rate.subject_name}`} variant="ghost" size="icon" onClick={() => deleteRate(rate.id)} className="min-h-[44px] min-w-[44px] rounded-xl text-rose-500 hover:bg-rose-50 hover:text-rose-600"><Trash2 size={17} /></Button></div>
                  </div>
                ))}
              </div>
            )}
          </section>
        </div>
      </div>
    </AdminLayout>
  );
}

function MoneyInput({ label, value, min = 1000, step = 1000, onChange }: { label: string; value: number; min?: number; step?: number; onChange: (value: number) => void }) {
  const [draft, setDraft] = useState(value > 0 ? formatMoney(value) : "");
  const [focused, setFocused] = useState(false);

  useEffect(() => {
    if (!focused) setDraft(value > 0 ? formatMoney(value) : "");
  }, [focused, value]);

  const id = `money-input-${label.toLowerCase().replace(/[^a-z0-9]+/g, "-")}`;
  const errorId = `${id}-error`;
  const amount = focused ? parseMoney(draft) : value;
  const error = hourlyRateAmountError(amount);

  return <div><Label htmlFor={id} className="mb-2 block font-bold">{label}</Label><Input id={id} type="text" inputMode="numeric" min={min} max={10_000_000} step={step} aria-invalid={Boolean(error)} aria-describedby={error ? errorId : undefined} value={focused ? draft : (value > 0 ? formatMoney(value) : "")} onFocus={() => { setFocused(true); setDraft(value > 0 ? String(value) : ""); }} onChange={(event) => { const raw = sanitizeMoney(event.target.value); setDraft(raw); onChange(parseMoney(raw)); }} onBlur={() => { setFocused(false); const normalized = parseMoney(draft); onChange(normalized); setDraft(normalized > 0 ? formatMoney(normalized) : ""); }} className="h-12 rounded-xl" />{error && <p id={errorId} role="alert" className="mt-1 text-sm font-semibold text-rose-700">{error}</p>}<p className="mt-1 text-xs text-slate-500">Rp1.000–Rp10.000.000 per jam.</p></div>;
}

function sanitizeMoney(value: string) {
  return value.replace(/\D/g, "").replace(/^0+(?=\d)/, "");
}

function parseMoney(value: string) {
  const digits = sanitizeMoney(value);
  return digits ? Number(digits) : 0;
}

function formatMoney(value: number) {
  return new Intl.NumberFormat("id-ID", { maximumFractionDigits: 0 }).format(value);
}
