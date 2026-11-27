import { notify } from "@/lib/notify";
import { FormEvent, useEffect, useState } from "react";
import {
  BookOpenCheck,
  Clock3,
  Image,
  Layers3,
  Loader2,
  Pencil,
  Plus,
  Save,
  Tag,
  Trash2,
  X,
} from "lucide-react";
import AdminLayout from "@/components/AdminLayout";
import { ResponsiveMultiSelect, ResponsiveSelect } from "@/components/ResponsiveSelect";
import { useConfirmDialog } from "@/components/ConfirmDialogProvider";
import { Dialog, DialogContent, DialogDescription, DialogTitle } from "@/components/ui/dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import http, { getApiError, getCached } from "@/lib/http";
import { publicMediaUrl } from "@/lib/apiBase";

type Tab = "plans" | "promotions" | "banners" | "tutorials";
type Plan = { id: number; name: string; slug: string; description?: string; session_count: number; validity_days: number; maximum_subjects: number; sort_order: number; is_active: boolean };
type Promotion = { id: number; title: string; code?: string | null; description?: string; discount_type: "percentage" | "fixed"; discount_value: number; maximum_discount?: number | null; minimum_purchase: number; total_quota?: number | null; per_user_limit: number; used_quota?: number; target_plan_ids?: number[] | null; target_levels?: string[] | null; target_subjects?: string[] | null; target_modes?: string[] | null; new_students_only: boolean; claim_required: boolean; is_active: boolean; starts_at?: string | null; ends_at?: string | null };
type Banner = { id: number; title: string; description?: string; button_text?: string; image_url?: string; image_path?: string | null; audience: string; destination_kind: "internal" | "external"; destination_url: string; sort_order: number; is_active: boolean; starts_at?: string | null; ends_at?: string | null };
type Step = { id?: number; title: string; body: string; image_path?: string | null; sort_order?: number };
type Tutorial = { id: number; role: string; context: string; title: string; description?: string; sort_order: number; is_active: boolean; steps: Step[] };
type SubjectOption = { id: number; name: string };
type TimeSlot = { id: number; start_time: string; label?: string | null; sort_order: number; is_active: boolean };
const FULL_HOUR_OPTIONS = Array.from({ length: 23 }, (_, hour) => `${String(hour).padStart(2, "0")}:00`);

const destinationOptions = [
  ["/student/dashboard", "Beranda Murid"],
  ["/student/dashboard#tutorial", "Buka Tutorial Dashboard"],
  ["/student/packages/new", "Cari Les / Pilih Paket"],
  ["/student/kelas-murah", "Kelas Bersama"],
  ["/student/packages", "Proses Pesanan"],
  ["/student/my-classes", "Kelas Saya - Jadwal Aktif"],
  ["/student/my-classes?tab=history", "Kelas Saya - Riwayat"],
  ["/student/progress", "Progres Belajar"],
  ["/student/messages", "Pesan"],
  ["/student/vouchers", "Voucher"],
  ["/student/history", "Riwayat Pembayaran"],
  ["/student/notifications", "Notifikasi"],
  ["/student/profile", "Profil Murid"],
  ["/student/account", "Pengaturan Akun"],
  ["/student/help", "Pusat Bantuan"],
] as const;

const emptyPlan: Omit<Plan, "id"> = { name: "", slug: "", description: "", session_count: 4, validity_days: 30, maximum_subjects: 1, sort_order: 0, is_active: true };
const emptyPromo: Omit<Promotion, "id"> = { title: "", code: "", description: "", discount_type: "percentage", discount_value: 10, maximum_discount: null, minimum_purchase: 0, total_quota: null, per_user_limit: 1, target_plan_ids: [], target_levels: [], target_subjects: [], target_modes: [], new_students_only: false, claim_required: true, is_active: true };
const emptyBanner: Omit<Banner, "id"> = { title: "", description: "", button_text: "Lihat Selengkapnya", audience: "student", destination_kind: "internal", destination_url: "/student/dashboard", sort_order: 0, is_active: true };
const emptyTutorial: Omit<Tutorial, "id"> = { role: "student", context: "dashboard", title: "", description: "", sort_order: 0, is_active: true, steps: [{ title: "Langkah 1", body: "", image_path: "", sort_order: 0 }] };

export default function StageFiveManagement() {
  const confirm = useConfirmDialog();
  const [tab, setTab] = useState<Tab>("plans");
  const [plans, setPlans] = useState<Plan[]>([]);
  const [subjectOptions, setSubjectOptions] = useState<SubjectOption[]>([]);
  const [timeSlots, setTimeSlots] = useState<TimeSlot[]>([]);
  const [promotions, setPromotions] = useState<Promotion[]>([]);
  const [banners, setBanners] = useState<Banner[]>([]);
  const [tutorials, setTutorials] = useState<Tutorial[]>([]);
  const [loading, setLoading] = useState(true);
  const [editor, setEditor] = useState<{ type: Tab; item: any } | null>(null);

  const load = async () => {
    setLoading(true);
    try {
      const [planRes, promoRes, bannerRes, tutorialRes, catalogRes, slotRes] = await Promise.all([
        getCached<Plan[]>("/admin/stage-five/plans", { maxAgeMs: 5_000, force: true }),
        getCached<Promotion[]>("/admin/stage-five/promotions", { maxAgeMs: 5_000, force: true }),
        getCached<Banner[]>("/admin/stage-five/banners", { maxAgeMs: 5_000, force: true }),
        getCached<Tutorial[]>("/admin/stage-five/tutorials", { maxAgeMs: 5_000, force: true }),
        getCached<{ subject_options: SubjectOption[] }>("/learning-catalog", { params: { compact: 1 }, maxAgeMs: 60_000 }),
        getCached<TimeSlot[]>("/admin/stage-five/time-slots", { maxAgeMs: 5_000, force: true }),
      ]);
      setPlans(planRes.data);
      setPromotions(promoRes.data);
      setBanners(bannerRes.data);
      setTutorials(tutorialRes.data);
      setSubjectOptions(catalogRes.data.subject_options || []);
      setTimeSlots(slotRes.data);
    } catch (error) {
      notify.error(getApiError(error, "Data Tahap 5 gagal dimuat."));
    } finally {
      setLoading(false);
    }
  };
  useEffect(() => { void load(); }, []);

  const openNew = () => {
    const initial = tab === "plans" ? emptyPlan : tab === "promotions" ? emptyPromo : tab === "banners" ? emptyBanner : emptyTutorial;
    setEditor({ type: tab, item: structuredClone(initial) });
  };
  const remove = async (type: Tab, id: number) => {
    const approved = await confirm({
      title: "Hapus atau nonaktifkan data?",
      description: "Data yang sudah mempunyai riwayat transaksi akan dinonaktifkan agar audit tetap utuh.",
      confirmText: "Lanjutkan",
      tone: "danger",
    });
    if (!approved) return;
    try {
      const singular = type === "plans" ? "plans" : type;
      const response = await http.delete(`/admin/stage-five/${singular}/${id}`);
      notify.success(response.data.message);
      await load();
    } catch (error) {
      notify.error(getApiError(error));
    }
  };

  return (
    <AdminLayout title="Paket, Promo & Konten">
      <div className="space-y-6 pb-8 sm:pb-12">
        <section className="rounded-[2rem] bg-gradient-to-br from-slate-950 via-violet-950 to-indigo-900 p-6 text-white sm:p-8">
          <p className="text-xs font-black uppercase tracking-[.2em] text-violet-200">Tahap 5</p>
          <h1 className="mt-3 text-3xl font-black">Pusat pengalaman BimbelKu</h1>
          <p className="mt-2 max-w-2xl text-sm leading-6 text-violet-100/70">Paket, diskon, banner dashboard, dan tutorial dikelola tanpa mengubah kode.</p>
        </section>

        <div className="grid grid-cols-2 gap-2 rounded-2xl bg-slate-100 p-2 md:grid-cols-4">
          <TabButton icon={BookOpenCheck} active={tab === "plans"} onClick={() => setTab("plans")}>Paket</TabButton>
          <TabButton icon={Tag} active={tab === "promotions"} onClick={() => setTab("promotions")}>Promo</TabButton>
          <TabButton icon={Image} active={tab === "banners"} onClick={() => setTab("banners")}>Banner</TabButton>
          <TabButton icon={Layers3} active={tab === "tutorials"} onClick={() => setTab("tutorials")}>Tutorial</TabButton>
        </div>

        <div className="flex items-center justify-between">
          <div><h2 className="text-xl font-black text-slate-900">{tab === "plans" ? "Paket belajar" : tab === "promotions" ? "Promo dan kode" : tab === "banners" ? "Banner dinamis" : "Tutorial peran"}</h2><p className="text-sm text-slate-500">{counts(tab, plans, promotions, banners, tutorials)} data tersimpan</p></div>
          <button type="button" onClick={openNew} className="inline-flex items-center gap-2 rounded-2xl bg-slate-950 px-4 py-3 text-sm font-black text-white"><Plus size={17} /> Tambah</button>
        </div>

        {tab === "plans" && <TimeSlotManager items={timeSlots} changed={load} />}

        {loading ? <div className="grid min-h-52 place-items-center"><Loader2 className="animate-spin text-indigo-600" size={34} /></div> : (
          <div className="grid gap-4 lg:grid-cols-2">
            {tab === "plans" && plans.map((item) => <PlanCard key={item.id} item={item} edit={() => setEditor({ type: tab, item: structuredClone(item) })} remove={() => remove(tab, item.id)} />)}
            {tab === "promotions" && promotions.map((item) => <PromoCard key={item.id} item={item} edit={() => setEditor({ type: tab, item: structuredClone(item) })} remove={() => remove(tab, item.id)} />)}
            {tab === "banners" && banners.map((item) => <BannerCard key={item.id} item={item} edit={() => setEditor({ type: tab, item: structuredClone(item) })} remove={() => remove(tab, item.id)} />)}
            {tab === "tutorials" && tutorials.map((item) => <TutorialCard key={item.id} item={item} edit={() => setEditor({ type: tab, item: structuredClone(item) })} remove={() => remove(tab, item.id)} />)}
          </div>
        )}
      </div>

      {editor && <Editor editor={editor} plans={plans} subjectOptions={subjectOptions} close={() => setEditor(null)} saved={async () => { setEditor(null); await load(); }} />}
    </AdminLayout>
  );
}

function Editor({ editor, plans, subjectOptions, close, saved }: { editor: { type: Tab; item: any }; plans: Plan[]; subjectOptions: SubjectOption[]; close: () => void; saved: () => void }) {
  const [item, setItem] = useState(editor.item);
  const [image, setImage] = useState<File | null>(null);
  const [tutorialImages, setTutorialImages] = useState<Record<number, File>>({});
  const [saving, setSaving] = useState(false);
  const set = (key: string, value: any) => setItem((current: any) => ({ ...current, [key]: value }));

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    try {
      let response;
      if (editor.type === "banners") {
        const payload = new FormData();
        // Banner pada modul ini tampil di dashboard murid. Tetapkan sasaran dan
        // normalkan tujuan lama agar tidak mengarah ke halaman peran lain.
        const normalizedBanner = { ...item, audience: "student" };
        if (normalizedBanner.destination_kind === "internal" && !destinationOptions.some(([path]) => path === normalizedBanner.destination_url)) {
          normalizedBanner.destination_url = destinationOptions[0][0];
        }
        const bannerPayload = withTimezoneAwareWindow(normalizedBanner);
        Object.entries(bannerPayload).forEach(([key, value]) => {
          if (key === "id" || key === "image_url" || key === "image_path" || value === null || value === undefined) return;
          payload.append(key, typeof value === "boolean" ? (value ? "1" : "0") : String(value));
        });
        if (image) payload.append("image", image);
        response = await http.post(item.id ? `/admin/stage-five/banners/${item.id}` : "/admin/stage-five/banners", payload);
      } else {
        const endpoint = editor.type === "plans" ? "plans" : editor.type;
        const payload = editor.type === "promotions" ? withTimezoneAwareWindow(item) : item;
        response = item.id
          ? await http.put(`/admin/stage-five/${endpoint}/${item.id}`, payload)
          : await http.post(`/admin/stage-five/${endpoint}`, payload);
      }
      if (editor.type === "tutorials" && Object.keys(tutorialImages).length) {
        const savedSteps = response.data.data.steps as Step[];
        await Promise.all(Object.entries(tutorialImages).map(async ([index, file]) => {
          const step = savedSteps[Number(index)];
          if (!step?.id) return;
          const payload = new FormData();
          payload.append("image", file);
          await http.post(`/admin/stage-five/tutorial-steps/${step.id}/image`, payload);
        }));
      }
      notify.success(response.data.message);
      await saved();
    } catch (error) {
      notify.error(getApiError(error));
    } finally {
      setSaving(false);
    }
  };

  const entityLabel = editor.type === "plans"
    ? "Paket"
    : editor.type === "promotions"
      ? "Promo"
      : editor.type === "banners"
        ? "Banner"
        : "Tutorial";

  return (
    <Dialog open onOpenChange={(open) => { if (!open) close(); }}>
      <DialogContent hideCloseButton className="max-h-[calc(100dvh-1.5rem)] w-full gap-0 overflow-y-auto rounded-[2rem] border-0 bg-white px-5 pb-8 pt-5 shadow-2xl sm:max-h-[94dvh] sm:max-w-2xl sm:px-7 sm:pb-10 sm:pt-7">
        <form onSubmit={submit}>
          <div className="flex items-center justify-between gap-3">
            <div>
              <DialogTitle asChild>
                <h2 className="px-0 text-left text-2xl font-black text-slate-900">{item.id ? "Ubah" : "Tambah"} {entityLabel}</h2>
              </DialogTitle>
              <DialogDescription className="sr-only">Formulir pengaturan {entityLabel.toLowerCase()}</DialogDescription>
            </div>
            <button type="button" aria-label="Tutup editor" onClick={close} className="rounded-full bg-slate-100 p-2 text-slate-500"><X size={19} /></button>
          </div>
          <div className="mt-6 grid gap-4 sm:grid-cols-2">
            {editor.type === "plans" && <PlanFields item={item} set={set} />}
            {editor.type === "promotions" && <PromoFields item={item} set={set} plans={plans} subjectOptions={subjectOptions} />}
            {editor.type === "banners" && <BannerFields item={item} set={set} image={image} setImage={setImage} />}
            {editor.type === "tutorials" && <TutorialFields item={item} set={set} setStepImage={(index, file) => setTutorialImages((current) => ({ ...current, [index]: file }))} />}
          </div>
          <button disabled={saving} className="mt-7 flex h-14 w-full items-center justify-center gap-2 rounded-2xl bg-indigo-600 font-black text-white disabled:opacity-50">{saving ? <Loader2 className="animate-spin" size={18} /> : <Save size={18} />} Simpan</button>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function PlanFields({ item, set }: any) {
  return <><Field label="Nama paket"><input required className="form-field" value={item.name} onChange={(e) => { set("name", e.target.value); if (!item.id) set("slug", e.target.value.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/(^-|-$)/g, "")); }} /></Field><Field label="Slug"><input required className="form-field" value={item.slug} onChange={(e) => set("slug", e.target.value)} /></Field><Field label="Jumlah sesi"><input required type="number" min={1} className="form-field" value={item.session_count} onChange={(e) => set("session_count", Number(e.target.value))} /></Field><Field label="Masa aktif (hari)"><input required type="number" min={1} className="form-field" value={item.validity_days} onChange={(e) => set("validity_days", Number(e.target.value))} /></Field><Field label="Maksimal mapel"><input required type="number" min={1} max={5} className="form-field" value={item.maximum_subjects} onChange={(e) => set("maximum_subjects", Number(e.target.value))} /></Field><Field label="Urutan"><input type="number" min={0} className="form-field" value={item.sort_order} onChange={(e) => set("sort_order", Number(e.target.value))} /></Field><Field label="Deskripsi" wide><textarea className="min-h-24 w-full rounded-2xl border border-slate-200 p-4 text-sm" value={item.description || ""} onChange={(e) => set("description", e.target.value)} /></Field><Switch label="Paket aktif" checked={item.is_active} onChange={(value) => set("is_active", value)} /></>;
}

function PromoFields({ item, set, plans, subjectOptions }: any) {
  const toggle = (key: string, value: string | number) => {
    const current = Array.isArray(item[key]) ? item[key] : [];
    set(key, current.includes(value) ? current.filter((entry: string | number) => entry !== value) : [...current, value]);
  };
  return <><PromoStatusControl active={Boolean(item.is_active)} onChange={(value) => set("is_active", value)} /><Field label="Judul promo"><input required className="form-field" value={item.title} onChange={(e) => set("title", e.target.value)} /></Field><Field label="Kode promo"><input className="form-field uppercase" value={item.code || ""} onChange={(e) => set("code", e.target.value.toUpperCase())} placeholder="BELAJAR20" /></Field><Field label="Jenis potongan"><ResponsiveSelect value={item.discount_type} ariaLabel="Pilih jenis potongan" options={[{ value: "percentage", label: "Persentase" }, { value: "fixed", label: "Nominal" }]} onValueChange={(value) => set("discount_type", value)} /></Field><Field label={item.discount_type === "percentage" ? "Persentase (%)" : "Nominal (Rp)"}><input required type="number" min={1} max={item.discount_type === "percentage" ? 100 : undefined} className="form-field" value={item.discount_value} onChange={(e) => set("discount_value", Number(e.target.value))} /></Field><Field label="Maksimal potongan"><input type="number" min={0} className="form-field" value={item.maximum_discount ?? ""} onChange={(e) => set("maximum_discount", e.target.value ? Number(e.target.value) : null)} /></Field><Field label="Minimal transaksi"><input type="number" min={0} className="form-field" value={item.minimum_purchase || 0} onChange={(e) => set("minimum_purchase", Number(e.target.value))} /></Field><Field label="Kuota total"><input type="number" min={1} className="form-field" value={item.total_quota ?? ""} onChange={(e) => set("total_quota", e.target.value ? Number(e.target.value) : null)} /></Field><Field label="Batas per akun"><input required type="number" min={1} className="form-field" value={item.per_user_limit} onChange={(e) => set("per_user_limit", Number(e.target.value))} /></Field><Field label="Mulai tayang"><input type="datetime-local" className="form-field" value={toLocal(item.starts_at)} onChange={(e) => set("starts_at", e.target.value || null)} /></Field><Field label="Selesai tayang"><input type="datetime-local" className="form-field" value={toLocal(item.ends_at)} onChange={(e) => set("ends_at", e.target.value || null)} /></Field><Field label="Deskripsi" wide><textarea className="min-h-24 w-full rounded-2xl border border-slate-200 p-4 text-sm" value={item.description || ""} onChange={(e) => set("description", e.target.value)} /></Field><Field label="Sasaran paket (kosong = semua)" wide><div className="grid gap-2 sm:grid-cols-2">{plans.map((plan: Plan) => <CheckOption key={plan.id} label={plan.name} checked={(item.target_plan_ids || []).includes(plan.id)} onChange={() => toggle("target_plan_ids", plan.id)} />)}</div></Field><Field label="Sasaran jenjang (kosong = semua)" wide><div className="grid grid-cols-2 gap-2 sm:grid-cols-4">{["SD", "SMP", "SMA", "Umum"].map((level) => <CheckOption key={level} label={level} checked={(item.target_levels || []).includes(level)} onChange={() => toggle("target_levels", level)} />)}</div></Field><Field label="Sasaran metode (kosong = semua)" wide><div className="grid grid-cols-2 gap-2"><CheckOption label="Online" checked={(item.target_modes || []).includes("online")} onChange={() => toggle("target_modes", "online")} /><CheckOption label="Offline" checked={(item.target_modes || []).includes("offline")} onChange={() => toggle("target_modes", "offline")} /></div></Field><Field label="Sasaran mapel (kosong = semua)" wide><ResponsiveMultiSelect values={item.target_subjects || []} onChange={(values) => set("target_subjects", values)} options={subjectOptions.map((subject: SubjectOption) => ({ value: subject.name, label: subject.name }))} placeholder="Semua mata pelajaran" title="Pilih sasaran mata pelajaran" description="Daftar dibatasi di dalam layar. Kosongkan pilihan untuk memakai semua mata pelajaran." /><button type="button" onClick={() => set("target_subjects", [])} className="mt-2 text-xs font-black text-indigo-600">Gunakan semua mapel</button></Field><Switch label="Khusus murid baru" checked={item.new_students_only} onChange={(value) => set("new_students_only", value)} /><Switch label="Perlu diklaim" checked={item.claim_required} onChange={(value) => set("claim_required", value)} /></>;
}

function PromoStatusControl({ active, onChange }: { active: boolean; onChange: (value: boolean) => void }) {
  return (
    <div className={`sm:col-span-2 flex min-w-0 flex-col gap-4 overflow-hidden rounded-2xl border p-4 sm:flex-row sm:items-center sm:justify-between ${active ? "border-emerald-200 bg-emerald-50/70" : "border-slate-200 bg-slate-50"}`}>
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-xs font-black uppercase tracking-wider text-slate-500">Status promo</span>
          <span className={`rounded-full px-2.5 py-1 text-[10px] font-black ${active ? "bg-emerald-600 text-white" : "bg-slate-200 text-slate-600"}`}>{active ? "AKTIF" : "NONAKTIF"}</span>
        </div>
        <p className="mt-2 text-sm font-bold text-slate-800">{active ? "Promo diizinkan tampil selama periode tanggalnya berlaku." : "Promo dimatikan manual dan tidak dapat dipakai meskipun tanggalnya sedang berlaku."}</p>
      </div>
      <button
        type="button"
        role="switch"
        aria-checked={active}
        onClick={() => onChange(!active)}
        className={`flex min-h-12 w-full min-w-0 items-center justify-between gap-3 rounded-2xl border px-4 py-2.5 text-sm font-black transition sm:w-auto sm:min-w-[8.5rem] ${active ? "border-emerald-300 bg-white text-emerald-700 shadow-sm" : "border-slate-300 bg-white text-slate-600"}`}
      >
        <span className="min-w-0">{active ? "Aktif" : "Nonaktif"}</span>
        <span className={`relative h-6 w-11 shrink-0 rounded-full transition ${active ? "bg-emerald-500" : "bg-slate-300"}`}>
          <span className={`absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform ${active ? "translate-x-5" : "translate-x-0"}`} />
        </span>
      </button>
    </div>
  );
}

function BannerFields({ item, set, image, setImage }: any) {
  const selectedInternalDestination = destinationOptions.some(([path]) => path === item.destination_url)
    ? item.destination_url
    : destinationOptions[0][0];

  const changeDestinationKind = (value: string) => {
    set("destination_kind", value);
    set("destination_url", value === "internal" ? destinationOptions[0][0] : "");
  };

  return <>
    <Field label="Judul banner"><input required className="form-field" value={item.title} onChange={(e) => set("title", e.target.value)} /></Field>
    <Field label="Teks tombol"><input className="form-field" value={item.button_text || ""} onChange={(e) => set("button_text", e.target.value)} placeholder="Contoh: Cari Les Sekarang" /></Field>
    <Field label="Tampil untuk">
      <div className="flex min-h-12 items-center rounded-2xl border border-indigo-100 bg-indigo-50 px-4 text-sm font-black text-indigo-800">Murid - Dashboard</div>
    </Field>
    <Field label="Jenis tujuan">
      <ResponsiveSelect
        value={item.destination_kind}
        ariaLabel="Pilih jenis tujuan banner"
        options={[{ value: "internal", label: "Halaman murid" }, { value: "external", label: "Tautan luar" }]}
        onValueChange={changeDestinationKind}
      />
    </Field>
    <Field label={item.destination_kind === "internal" ? "Halaman tujuan" : "Alamat tautan"} wide>
      {item.destination_kind === "internal" ? (
        <>
          <ResponsiveSelect
            value={selectedInternalDestination}
            ariaLabel="Pilih halaman murid tujuan banner"
            placeholder="Pilih halaman murid"
            options={destinationOptions.map(([value, label]) => ({ value, label }))}
            onValueChange={(value) => set("destination_url", value)}
            contentClassName="sm:min-w-[24rem]"
          />
          <p className="mt-2 text-sm leading-5 text-slate-500">Hanya halaman yang aman dan dapat dibuka murid yang tersedia.</p>
        </>
      ) : (
        <>
          <input required type="url" inputMode="url" autoCapitalize="none" className="form-field" value={item.destination_url} onChange={(e) => set("destination_url", e.target.value)} placeholder="https://contoh.com" />
          <p className="mt-2 text-sm leading-5 text-slate-500">Gunakan alamat lengkap dengan HTTPS. Tautan akan dibuka di tab baru.</p>
        </>
      )}
    </Field>
    <Field label="Deskripsi" wide><textarea className="min-h-24 w-full rounded-2xl border border-slate-200 p-4 text-sm" value={item.description || ""} onChange={(e) => set("description", e.target.value)} /></Field>
    <Field label={item.id ? "Ganti gambar 16:9 (opsional)" : "Gambar banner 16:9"} wide><input required={!item.id} type="file" accept="image/jpeg,image/png,image/webp" onChange={(e) => setImage(e.target.files?.[0] || null)} className="block w-full rounded-2xl border border-dashed border-slate-300 p-4 text-sm" /><p className="mt-2 text-xs font-medium leading-5 text-slate-500">Disarankan 1600×900 tanpa teks pada gambar. Judul, deskripsi, dan tombol ditampilkan oleh sistem.</p>{image && <p className="mt-2 break-all text-xs font-bold text-emerald-600">{image.name}</p>}</Field>
    <Field label="Mulai tayang"><input type="datetime-local" className="form-field" value={toLocal(item.starts_at)} onChange={(e) => set("starts_at", e.target.value || null)} /></Field>
    <Field label="Selesai tayang"><input type="datetime-local" className="form-field" value={toLocal(item.ends_at)} onChange={(e) => set("ends_at", e.target.value || null)} /></Field>
    <Field label="Urutan"><input type="number" min={0} className="form-field" value={item.sort_order} onChange={(e) => set("sort_order", Number(e.target.value))} /></Field>
    <Switch label="Banner aktif" checked={item.is_active} onChange={(value) => set("is_active", value)} />
  </>;
}

function TutorialFields({ item, set, setStepImage }: any) {
  const updateStep = (index: number, patch: Partial<Step>) => set("steps", item.steps.map((step: Step, stepIndex: number) => stepIndex === index ? { ...step, ...patch } : step));
  return <><Field label="Peran"><ResponsiveSelect value={item.role} ariaLabel="Pilih peran tutorial" options={[{ value: "student", label: "Murid" }, { value: "teacher", label: "Tutor" }, { value: "admin", label: "Admin" }]} onValueChange={(value) => set("role", value)} /></Field><Field label="Konteks halaman"><input required className="form-field" value={item.context} onChange={(e) => set("context", e.target.value)} placeholder="dashboard" /></Field><Field label="Judul tutorial" wide><input required className="form-field" value={item.title} onChange={(e) => set("title", e.target.value)} /></Field><Field label="Deskripsi" wide><textarea className="min-h-20 w-full rounded-2xl border border-slate-200 p-4 text-sm" value={item.description || ""} onChange={(e) => set("description", e.target.value)} /></Field><div className="sm:col-span-2 space-y-3"><div className="flex items-center justify-between"><h3 className="font-black text-slate-800">Langkah tutorial</h3><button type="button" onClick={() => set("steps", [...item.steps, { title: `Langkah ${item.steps.length + 1}`, body: "", image_path: "", sort_order: item.steps.length }])} className="inline-flex items-center gap-1 rounded-xl bg-indigo-50 px-3 py-2 text-xs font-black text-indigo-700"><Plus size={14} /> Langkah</button></div>{item.steps.map((step: Step, index: number) => <div key={step.id || index} className="rounded-2xl bg-slate-50 p-4"><div className="flex gap-3"><span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-white text-xs font-black text-indigo-600">{index + 1}</span><div className="flex-1 space-y-3"><input required className="form-field" value={step.title} onChange={(e) => updateStep(index, { title: e.target.value, sort_order: index })} placeholder="Judul langkah" /><textarea required className="min-h-20 w-full rounded-2xl border border-slate-200 p-3 text-sm" value={step.body} onChange={(e) => updateStep(index, { body: e.target.value })} placeholder="Penjelasan singkat" /><input className="form-field" value={step.image_path || ""} onChange={(e) => updateStep(index, { image_path: e.target.value })} placeholder="URL gambar opsional" /><input type="file" accept="image/jpeg,image/png,image/webp" onChange={(e) => e.target.files?.[0] && setStepImage(index, e.target.files[0])} className="block w-full rounded-xl border border-dashed border-slate-300 p-3 text-xs" /></div>{item.steps.length > 1 && <button type="button" onClick={() => set("steps", item.steps.filter((_: Step, stepIndex: number) => stepIndex !== index))} className="h-9 rounded-xl p-2 text-rose-500"><Trash2 size={16} /></button>}</div></div>)}</div><Switch label="Tutorial aktif" checked={item.is_active} onChange={(value) => set("is_active", value)} /></>;
}

function PlanCard({ item, edit, remove }: { item: Plan; edit: () => void; remove: () => void }) { return <CardShell active={item.is_active} edit={edit} remove={remove}><BookOpenCheck className="text-indigo-600" /><h3 className="mt-4 text-xl font-black text-slate-900">{item.name}</h3><p className="mt-1 text-sm font-bold text-indigo-600">{item.session_count} sesi · {item.validity_days} hari</p><p className="mt-2 text-sm text-slate-500">Maksimal {item.maximum_subjects} mapel</p></CardShell>; }
function PromoCard({ item, edit, remove }: { item: Promotion; edit: () => void; remove: () => void }) { return <CardShell active={item.is_active} edit={edit} remove={remove}><Tag className="text-orange-500" /><div className="mt-4 flex items-start justify-between gap-3"><div><h3 className="text-xl font-black text-slate-900">{item.title}</h3><p className="mt-1 text-sm font-bold text-orange-600">{item.code || "Tanpa kode"} · {item.discount_type === "percentage" ? `${item.discount_value}%` : `Rp${item.discount_value}`}</p></div><span className="rounded-full bg-slate-100 px-2 py-1 text-[10px] font-black">{item.used_quota || 0}/{item.total_quota || "∞"}</span></div></CardShell>; }
function BannerCard({ item, edit, remove }: { item: Banner; edit: () => void; remove: () => void }) { const image = publicMediaUrl(item.image_path) || item.image_url; return <CardShell active={item.is_active} edit={edit} remove={remove}><div className="relative h-36 overflow-hidden rounded-2xl bg-gradient-to-br from-blue-700 to-violet-700">{image && <img src={image} alt="" loading="lazy" decoding="async" className="h-full w-full object-cover" />}<div className="absolute inset-0 bg-gradient-to-t from-slate-950/80 to-transparent" /><p className="absolute bottom-3 left-4 right-4 font-black text-white">{item.title}</p></div><p className="mt-3 text-xs font-bold text-slate-500">{item.audience} → {item.destination_url}</p></CardShell>; }
function TutorialCard({ item, edit, remove }: { item: Tutorial; edit: () => void; remove: () => void }) { return <CardShell active={item.is_active} edit={edit} remove={remove}><Layers3 className="text-violet-600" /><h3 className="mt-4 text-xl font-black text-slate-900">{item.title}</h3><p className="mt-1 text-sm font-bold text-violet-600">{item.role} · {item.context}</p><p className="mt-2 text-sm text-slate-500">{item.steps.length} langkah</p></CardShell>; }

function CardShell({ children, active, edit, remove }: { children: React.ReactNode; active: boolean; edit: () => void; remove: () => void }) { return <article className="rounded-[2rem] border border-slate-100 bg-white p-5 shadow-sm"><div className="flex items-start justify-between gap-3"><span className={`rounded-full px-2.5 py-1 text-[10px] font-black ${active ? "bg-emerald-50 text-emerald-700" : "bg-slate-100 text-slate-500"}`}>{active ? "Aktif" : "Nonaktif"}</span><div className="flex gap-1"><button type="button" aria-label="Ubah item" onClick={edit} className="rounded-xl p-2 text-indigo-600 hover:bg-indigo-50"><Pencil size={16} /></button><button type="button" aria-label="Hapus item" onClick={remove} className="rounded-xl p-2 text-rose-500 hover:bg-rose-50"><Trash2 size={16} /></button></div></div>{children}</article>; }
function TabButton({ icon: Icon, active, onClick, children }: { icon: typeof Tag; active: boolean; onClick: () => void; children: React.ReactNode }) { return <button type="button" onClick={onClick} className={`flex items-center justify-center gap-2 rounded-xl px-3 py-3 text-xs font-black transition sm:text-sm ${active ? "bg-white text-slate-950 shadow-sm" : "text-slate-500"}`}><Icon size={17} />{children}</button>; }
function Field({ label, children, wide = false }: { label: string; children: React.ReactNode; wide?: boolean }) { return <label className={wide ? "sm:col-span-2" : ""}><span className="mb-2 block text-xs font-black uppercase tracking-wider text-slate-500">{label}</span>{children}</label>; }
function Switch({ label, checked, onChange }: { label: string; checked: boolean; onChange: (value: boolean) => void }) { return <label className="flex items-center gap-3 rounded-2xl bg-slate-50 p-4"><input type="checkbox" checked={checked} onChange={(event) => onChange(event.target.checked)} className="h-5 w-5 accent-indigo-600" /><span className="text-sm font-black text-slate-700">{label}</span></label>; }
function CheckOption({ label, checked, onChange }: { label: string; checked: boolean; onChange: () => void }) { return <label className="flex items-center gap-2 rounded-xl border border-slate-200 bg-white p-3"><input type="checkbox" checked={checked} onChange={onChange} className="h-4 w-4 accent-indigo-600" /><span className="text-xs font-bold text-slate-700">{label}</span></label>; }
function TimeSlotManager({ items, changed }: { items: TimeSlot[]; changed: () => Promise<void> }) {
  const confirm = useConfirmDialog();
  const [time, setTime] = useState("08:00");
  const [label, setLabel] = useState("");
  const [saving, setSaving] = useState(false);
  const add = async (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    try {
      const response = await http.post("/admin/stage-five/time-slots", {
        start_time: time,
        label: label || `${time.replace(":", ".")} WIB`,
        sort_order: Number(time.slice(0, 2)) * 60 + Number(time.slice(3, 5)),
        is_active: true,
      });
      notify.success(response.data.message);
      setLabel("");
      await changed();
    } catch (error) {
      notify.error(getApiError(error));
    } finally {
      setSaving(false);
    }
  };
  const toggle = async (slot: TimeSlot) => {
    try {
      const response = await http.put(`/admin/stage-five/time-slots/${slot.id}`, {
        start_time: slot.start_time.slice(0, 5),
        label: slot.label,
        sort_order: slot.sort_order,
        is_active: !slot.is_active,
      });
      notify.success(response.data.message);
      await changed();
    } catch (error) {
      notify.error(getApiError(error));
    }
  };
  const remove = async (slot: TimeSlot) => {
    const approved = await confirm({
      title: "Hapus pilihan slot?",
      description: "Jadwal lama tetap tersimpan, tetapi jam ini tidak dapat dipilih untuk paket baru.",
      confirmText: "Hapus slot",
      tone: "danger",
    });
    if (!approved) return;
    try {
      const response = await http.delete(`/admin/stage-five/time-slots/${slot.id}`);
      notify.success(response.data.message);
      await changed();
    } catch (error) {
      notify.error(getApiError(error));
    }
  };
  return <section className="rounded-[2rem] border border-slate-100 bg-white p-5 shadow-sm sm:p-6"><div className="flex items-center gap-3"><div className="grid h-11 w-11 place-items-center rounded-2xl bg-indigo-50 text-indigo-600"><Clock3 size={20} /></div><div><h3 className="font-black text-slate-900">Pilihan slot 60 menit</h3><p className="text-xs text-slate-500">Murid hanya dapat memilih jam penuh yang aktif di sini.</p></div></div><form onSubmit={add} className="mt-4 grid gap-3 sm:grid-cols-[140px_1fr_auto]"><Select value={time} onValueChange={setTime}><SelectTrigger aria-label="Jam slot belajar" className="form-field h-12 min-w-0 focus:ring-2 focus:ring-indigo-100 focus:ring-offset-0"><SelectValue placeholder="Pilih jam" /></SelectTrigger><SelectContent position="popper" sideOffset={6} collisionPadding={12} className="z-[300] max-h-[min(18rem,calc(100dvh-2rem))] rounded-2xl border-slate-200 bg-white shadow-2xl">{FULL_HOUR_OPTIONS.map((option) => <SelectItem key={option} value={option} className="min-h-10 rounded-xl py-2.5 text-sm font-bold">{option.replace(":", ".")}</SelectItem>)}</SelectContent></Select><input value={label} onChange={(event) => setLabel(event.target.value)} className="form-field" placeholder="Label, contoh: Pagi" /><button disabled={saving} className="rounded-2xl bg-indigo-600 px-4 py-3 text-sm font-black text-white disabled:opacity-50">{saving ? "Menyimpan…" : "Tambah Slot"}</button></form><div className="mt-4 flex flex-wrap gap-2">{items.map((slot) => <div key={slot.id} className={`flex items-center gap-2 rounded-2xl border px-3 py-2 ${slot.is_active ? "border-emerald-200 bg-emerald-50" : "border-slate-200 bg-slate-50 opacity-60"}`}><button type="button" onClick={() => toggle(slot)} className="text-left"><p className="text-sm font-black text-slate-800">{slot.start_time.slice(0, 5).replace(":", ".")}</p><p className="text-[10px] font-bold text-slate-500">{slot.is_active ? "Aktif" : "Nonaktif"} · {slot.label || "Tanpa label"}</p></button><button type="button" aria-label={`Hapus slot ${slot.start_time}`} onClick={() => remove(slot)} className="inline-flex h-[32px] w-[32px] shrink-0 items-center justify-center rounded-lg text-rose-500 hover:bg-rose-50"><Trash2 size={14} /></button></div>)}</div></section>;
}
function counts(tab: Tab, plans: Plan[], promotions: Promotion[], banners: Banner[], tutorials: Tutorial[]) { return tab === "plans" ? plans.length : tab === "promotions" ? promotions.length : tab === "banners" ? banners.length : tutorials.length; }
function toLocal(value?: string | null) { if (!value) return ""; const date = new Date(value); return new Date(date.getTime() - date.getTimezoneOffset() * 60_000).toISOString().slice(0, 16); }
function toApiDateTime(value?: string | null) { if (!value) return null; const date = new Date(value); return Number.isNaN(date.getTime()) ? value : date.toISOString(); }
function withTimezoneAwareWindow<T extends { starts_at?: string | null; ends_at?: string | null }>(item: T): T {
  return { ...item, starts_at: toApiDateTime(item.starts_at), ends_at: toApiDateTime(item.ends_at) };
}
