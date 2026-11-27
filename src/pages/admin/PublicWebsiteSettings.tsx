import { FormEvent, useCallback, useEffect, useState } from "react";
import { ArrowDown, ArrowUp, Eye, EyeOff, Loader2, Plus, Save, ShieldCheck, Trash2 } from "lucide-react";
import axios from "axios";
import { Link } from "react-router-dom";

import AdminLayout from "@/components/AdminLayout";
import http, { clearApiCache, getCached } from "@/lib/http";
import { notify } from "@/lib/notify";
import { sanitizePhoneInput } from "@/lib/validation";

type NavigationItem = {
  key: string;
  label: string;
  url: string;
  is_visible?: boolean;
};

type WebsiteSettings = {
  brand_name: string;
  brand_description: string | null;
  information_bar_enabled: boolean;
  information_bar_text: string | null;
  navigation_items: NavigationItem[];
  primary_cta_label: string;
  primary_cta_url: string;
  whatsapp_enabled: boolean;
  whatsapp_number: string | null;
  whatsapp_label: string;
  whatsapp_hours: string | null;
  whatsapp_default_message: string | null;
  contact_email: string | null;
  office_address: string | null;
  google_maps_url: string | null;
  animations_enabled: boolean;
  logo_url: string | null;
  logo_light_url: string | null;
  logo_dark_url: string | null;
  favicon_url: string | null;
  social_share_image_url: string | null;
  hero_desktop_image_url: string | null;
  hero_mobile_image_url: string | null;
  trust_image_1_url: string | null;
  trust_image_2_url: string | null;
  trust_image_3_url: string | null;
  trust_image_4_url: string | null;
  trust_image_5_url: string | null;
  trust_image_6_url: string | null;
  theme: {
    primary: string;
    primary_button: string;
    primary_hover: string;
    cream: string;
    heading: string;
  };
};

type WebsiteSection = {
  id: number;
  section_key: string;
  label: string;
  eyebrow: string | null;
  title: string | null;
  description: string | null;
  content: {
    secondary_cta_label?: string;
    trust_points?: string[];
    status_pending?: string;
    status_found?: string;
    search_placeholder?: string;
    trust_media_titles?: string[];
    trust_media_notes?: string[];
  } | null;
  is_visible: boolean;
  order_locked: boolean;
  sort_order: number;
};

type TrustItem = {
  id: number;
  title: string;
  display_value: string | null;
  resolved_value: string | null;
  description: string | null;
  source_type: "system" | "manual" | "commitment";
  source_key: "active_programs" | "verified_tutors" | "completed_sessions" | "average_rating" | "service_area" | null;
  source_note: string | null;
  source_updated_at: string | null;
  is_visible: boolean;
  sort_order: number;
};

type AdminTestimonial = {
  id?: number;
  client_key: string;
  rating_id: number | null;
  curriculum_subject_id: number | null;
  display_name: string;
  audience_role: string | null;
  quote: string;
  program_name: string | null;
  outcome: string | null;
  institution: string | null;
  major: string | null;
  achievement_year: number | null;
  photo_url: string | null;
  rating: number | null;
  is_verified: boolean;
  consent_confirmed: boolean;
  has_proof: boolean;
  verified_at?: string | null;
  verified_by_name?: string | null;
  is_featured: boolean;
  is_visible: boolean;
  sort_order: number;
};

type WebsitePayload = {
  settings: WebsiteSettings;
  sections: WebsiteSection[];
  trust_items: TrustItem[];
  testimonials: Omit<AdminTestimonial, "client_key">[];
};

type TestimonialFiles = { photo?: File; proof?: File };
type SubjectOption = { id: number; name: string; education_levels?: string[] };
type RatingOption = { id: number; rating: number; review: string | null; student_name: string; teacher_name: string };

const defaultSettings: WebsiteSettings = {
  brand_name: "BimbelKu",
  brand_description: "",
  information_bar_enabled: true,
  information_bar_text: "",
  navigation_items: [],
  primary_cta_label: "Cari Bimbingan",
  primary_cta_url: "/student/packages/new",
  whatsapp_enabled: false,
  whatsapp_number: "",
  whatsapp_label: "Konsultasi WhatsApp",
  whatsapp_hours: "",
  whatsapp_default_message: "",
  contact_email: "",
  office_address: "",
  google_maps_url: "",
  animations_enabled: true,
  logo_url: null,
  logo_light_url: null,
  logo_dark_url: null,
  favicon_url: null,
  social_share_image_url: null,
  hero_desktop_image_url: null,
  hero_mobile_image_url: null,
  trust_image_1_url: null,
  trust_image_2_url: null,
  trust_image_3_url: null,
  trust_image_4_url: null,
  trust_image_5_url: null,
  trust_image_6_url: null,
  theme: {
    primary: "#F97316",
    primary_button: "#C2410C",
    primary_hover: "#9A3412",
    cream: "#FFFBF7",
    heading: "#14213D",
  },
};

const nullable = (value: string | null | undefined) => value ?? "";
const dateValue = (value: string | null) => value ? value.slice(0, 10) : "";
const testimonialKey = () => typeof crypto !== "undefined" && "randomUUID" in crypto ? crypto.randomUUID() : `new-${Date.now()}-${Math.random()}`;
const emptyTestimonial = (sortOrder: number): AdminTestimonial => ({
  client_key: testimonialKey(), rating_id: null, curriculum_subject_id: null, display_name: "", audience_role: "", quote: "",
  program_name: "", outcome: "", institution: "", major: "", achievement_year: null,
  photo_url: null, rating: null, is_verified: false, consent_confirmed: false, has_proof: false,
  is_featured: false, is_visible: false, sort_order: sortOrder,
});

export default function PublicWebsiteSettings() {
  const [settings, setSettings] = useState<WebsiteSettings>(defaultSettings);
  const [sections, setSections] = useState<WebsiteSection[]>([]);
  const [trustItems, setTrustItems] = useState<TrustItem[]>([]);
  const [testimonials, setTestimonials] = useState<AdminTestimonial[]>([]);
  const [testimonialFiles, setTestimonialFiles] = useState<Record<string, TestimonialFiles>>({});
  const [subjectOptions, setSubjectOptions] = useState<SubjectOption[]>([]);
  const [ratingOptions, setRatingOptions] = useState<RatingOption[]>([]);
  const [deletedTestimonialIds, setDeletedTestimonialIds] = useState<number[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const loadSettings = useCallback(async () => {
    setLoading(true);
    setLoadError(null);
    try {
      const { data } = await http.get<WebsitePayload>("/admin/website-settings");
      if (!data || typeof data !== "object" || !data.settings) {
        const message = "Data pengaturan website publik tidak lengkap. Silakan muat ulang.";
        setLoadError(message);
        notify.error(message);
        return;
      }
      setSettings({
        ...defaultSettings,
        ...data.settings,
        brand_description: nullable(data.settings.brand_description),
        information_bar_text: nullable(data.settings.information_bar_text),
        whatsapp_number: nullable(data.settings.whatsapp_number),
        whatsapp_hours: nullable(data.settings.whatsapp_hours),
        whatsapp_default_message: nullable(data.settings.whatsapp_default_message),
        contact_email: nullable(data.settings.contact_email),
        office_address: nullable(data.settings.office_address),
        google_maps_url: nullable(data.settings.google_maps_url),
        navigation_items: Array.isArray(data.settings.navigation_items)
          ? data.settings.navigation_items.map((item) => ({ ...item, is_visible: item.is_visible !== false }))
          : defaultSettings.navigation_items,
      });
      setSections(Array.isArray(data.sections) ? data.sections : []);
      setTrustItems(Array.isArray(data.trust_items) ? data.trust_items : []);
      setTestimonials(
        Array.isArray(data.testimonials)
          ? data.testimonials.map((item) => ({ ...item, client_key: `saved-${item.id}` }))
          : [],
      );
    } catch {
      const message = "Pengaturan website publik gagal dimuat.";
      setLoadError(message);
      notify.error(message);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadSettings();
  }, [loadSettings]);

  useEffect(() => {
    void Promise.all([
      getCached<{ subject_options?: SubjectOption[] }>("/learning-catalog", { params: { compact: 1 }, maxAgeMs: 5 * 60_000 }),
      http.get<{ data?: RatingOption[] }>("/admin/ratings"),
    ]).then(([catalog, ratings]) => {
      setSubjectOptions(catalog.data.subject_options || []);
      setRatingOptions(ratings.data.data || []);
    }).catch(() => {
      setSubjectOptions([]);
      setRatingOptions([]);
    });
  }, []);

  const updateSetting = <K extends keyof WebsiteSettings>(key: K, value: WebsiteSettings[K]) => {
    setSettings((current) => ({ ...current, [key]: value }));
  };

  const updateNavigation = (index: number, patch: Partial<NavigationItem>) => {
    updateSetting("navigation_items", settings.navigation_items.map((item, itemIndex) => itemIndex === index ? { ...item, ...patch } : item));
  };

  const updateTrust = (index: number, patch: Partial<TrustItem>) => {
    setTrustItems((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, ...patch } : item));
  };

  const updateTestimonial = (index: number, patch: Partial<AdminTestimonial>) => {
    setTestimonials((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, ...patch } : item));
  };

  const removeTestimonial = (index: number) => {
    const target = testimonials[index];
    if (!target) return;
    if (target.id) setDeletedTestimonialIds((current) => [...current, target.id!]);
    setTestimonialFiles((current) => { const next = { ...current }; delete next[target.client_key]; return next; });
    setTestimonials((current) => current.filter((_, itemIndex) => itemIndex !== index));
  };

  const updateSection = (index: number, patch: Partial<WebsiteSection>) => {
    setSections((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, ...patch } : item));
  };

  const moveSection = (index: number, direction: -1 | 1) => {
    setSections((current) => {
      const source = current[index];
      if (!source || source.order_locked) return current;
      const movable = current.map((item, itemIndex) => ({ item, itemIndex }))
        .filter(({ item }) => !item.order_locked)
        .sort((left, right) => left.item.sort_order - right.item.sort_order);
      const position = movable.findIndex(({ itemIndex }) => itemIndex === index);
      const target = movable[position + direction];
      if (!target) return current;
      return current.map((item, itemIndex) => {
        if (itemIndex === index) return { ...item, sort_order: target.item.sort_order };
        if (itemIndex === target.itemIndex) return { ...item, sort_order: source.sort_order };
        return item;
      });
    });
  };

  const updateSectionContent = (index: number, patch: NonNullable<WebsiteSection["content"]>) => {
    setSections((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, content: { ...(item.content || {}), ...patch } } : item));
  };

  const append = (data: FormData, key: string, value: unknown) => {
    data.append(key, value === null || value === undefined ? "" : String(value));
  };

  const save = async (event: FormEvent) => {
    event.preventDefault();
    const payload = new FormData();
    const scalarKeys: Array<keyof WebsiteSettings> = [
      "brand_name", "brand_description", "information_bar_enabled", "information_bar_text",
      "primary_cta_label", "primary_cta_url", "whatsapp_enabled", "whatsapp_number",
      "whatsapp_label", "whatsapp_hours", "whatsapp_default_message", "contact_email",
      "office_address", "google_maps_url", "animations_enabled",
    ];
    scalarKeys.forEach((key) => append(payload, key, typeof settings[key] === "boolean" ? (settings[key] ? 1 : 0) : settings[key]));
    settings.navigation_items.forEach((item, index) => {
      append(payload, `navigation_items[${index}][key]`, item.key);
      append(payload, `navigation_items[${index}][label]`, item.label);
      append(payload, `navigation_items[${index}][url]`, item.url);
      append(payload, `navigation_items[${index}][is_visible]`, item.is_visible === false ? 0 : 1);
    });
    sections.forEach((section, index) => {
      append(payload, `sections[${index}][id]`, section.id);
      append(payload, `sections[${index}][eyebrow]`, section.eyebrow);
      append(payload, `sections[${index}][title]`, section.title);
      append(payload, `sections[${index}][description]`, section.description);
      if (section.content?.secondary_cta_label !== undefined) append(payload, `sections[${index}][content][secondary_cta_label]`, section.content.secondary_cta_label);
      if (section.content?.search_placeholder !== undefined) append(payload, `sections[${index}][content][search_placeholder]`, section.content.search_placeholder);
      if (section.content?.status_pending !== undefined) append(payload, `sections[${index}][content][status_pending]`, section.content.status_pending);
      if (section.content?.status_found !== undefined) append(payload, `sections[${index}][content][status_found]`, section.content.status_found);
      section.content?.trust_points?.forEach((point, pointIndex) => append(payload, `sections[${index}][content][trust_points][${pointIndex}]`, point));
      section.content?.trust_media_titles?.forEach((title, titleIndex) => append(payload, `sections[${index}][content][trust_media_titles][${titleIndex}]`, title));
      section.content?.trust_media_notes?.forEach((note, noteIndex) => append(payload, `sections[${index}][content][trust_media_notes][${noteIndex}]`, note));
      append(payload, `sections[${index}][is_visible]`, section.is_visible ? 1 : 0);
      append(payload, `sections[${index}][sort_order]`, section.sort_order);
    });
    trustItems.forEach((item, index) => {
      append(payload, `trust_items[${index}][id]`, item.id);
      append(payload, `trust_items[${index}][title]`, item.title);
      append(payload, `trust_items[${index}][display_value]`, item.display_value);
      append(payload, `trust_items[${index}][description]`, item.description);
      append(payload, `trust_items[${index}][source_type]`, item.source_type);
      append(payload, `trust_items[${index}][source_key]`, item.source_key);
      append(payload, `trust_items[${index}][source_note]`, item.source_note);
      append(payload, `trust_items[${index}][source_updated_at]`, dateValue(item.source_updated_at));
      append(payload, `trust_items[${index}][is_visible]`, item.is_visible ? 1 : 0);
      append(payload, `trust_items[${index}][sort_order]`, item.sort_order);
    });
    testimonials.forEach((item, index) => {
      if (item.id) append(payload, `testimonials[${index}][id]`, item.id);
      append(payload, `testimonials[${index}][rating_id]`, item.rating_id);
      append(payload, `testimonials[${index}][curriculum_subject_id]`, item.curriculum_subject_id);
      append(payload, `testimonials[${index}][display_name]`, item.display_name);
      append(payload, `testimonials[${index}][audience_role]`, item.audience_role);
      append(payload, `testimonials[${index}][quote]`, item.quote);
      append(payload, `testimonials[${index}][program_name]`, item.program_name);
      append(payload, `testimonials[${index}][outcome]`, item.outcome);
      append(payload, `testimonials[${index}][institution]`, item.institution);
      append(payload, `testimonials[${index}][major]`, item.major);
      append(payload, `testimonials[${index}][achievement_year]`, item.achievement_year);
      append(payload, `testimonials[${index}][consent_confirmed]`, item.consent_confirmed ? 1 : 0);
      append(payload, `testimonials[${index}][verified]`, item.is_verified ? 1 : 0);
      append(payload, `testimonials[${index}][is_featured]`, item.is_featured ? 1 : 0);
      append(payload, `testimonials[${index}][is_visible]`, item.is_visible ? 1 : 0);
      append(payload, `testimonials[${index}][sort_order]`, item.sort_order);
      const selected = testimonialFiles[item.client_key];
      if (selected?.photo) payload.append(`testimonials[${index}][photo]`, selected.photo);
      if (selected?.proof) payload.append(`testimonials[${index}][proof]`, selected.proof);
    });
    deletedTestimonialIds.forEach((id, index) => append(payload, `deleted_testimonial_ids[${index}]`, id));

    setSaving(true);
    try {
      const { data } = await http.post<{ message?: string; data?: Partial<WebsitePayload> }>("/admin/website-settings", payload);
      const saved = data?.data;
      if (saved?.settings) {
        setSettings((current) => ({ ...current, ...saved.settings }));
      }
      if (Array.isArray(saved?.sections)) {
        setSections(saved.sections);
      }
      if (Array.isArray(saved?.trust_items)) {
        setTrustItems(saved.trust_items);
      }
      if (Array.isArray(saved?.testimonials)) {
        setTestimonials(saved.testimonials.map((item) => ({ ...item, client_key: `saved-${item.id}` })));
      }
      setTestimonialFiles({});
      setDeletedTestimonialIds([]);
      clearApiCache("/website-content");
      window.dispatchEvent(new Event("bimbelku:website-content-changed"));
      notify.success(data?.message || "Pengaturan website publik berhasil disimpan.");
    } catch (error) {
      const message = axios.isAxiosError(error)
        ? error.response?.data?.message
        : null;
      notify.error(message || "Pengaturan website publik gagal disimpan.");
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return <AdminLayout title="Website Publik"><div className="grid min-h-64 place-items-center"><Loader2 className="animate-spin text-orange-600" /></div></AdminLayout>;
  }

  if (loadError) {
    return (
      <AdminLayout title="Website Publik">
        <div className="mx-auto my-10 max-w-md rounded-3xl border border-rose-200 bg-rose-50 p-6 text-center">
          <p className="text-base font-black text-rose-900">Pengaturan website publik gagal dimuat</p>
          <p className="mt-2 text-sm leading-6 text-rose-800">{loadError}</p>
          <button type="button" onClick={() => void loadSettings()} className="mt-5 inline-flex min-h-11 items-center justify-center rounded-xl bg-[#C2410C] px-5 text-sm font-bold text-white hover:bg-[#9A3412]">
            Coba lagi
          </button>
        </div>
      </AdminLayout>
    );
  }

  return (
    <AdminLayout title="Website Publik" subtitle="Fondasi identitas, navigasi, konsultasi, dan bukti yang tampil kepada pengunjung.">
      <form onSubmit={save} className="mx-auto max-w-6xl space-y-6 pb-24">
        <header className="rounded-3xl border border-stone-200 bg-[#FFFBF7] p-6 sm:p-8">
          <p className="text-xs font-bold uppercase tracking-[0.18em] text-orange-700">Warm, Clear, Trusted</p>
          <div className="mt-3 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
              <h1 className="text-3xl font-extrabold text-[#14213D]">Fondasi website publik</h1>
              <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-600">Struktur desain dikunci agar konsisten. Di sini admin mengelola teks, visibilitas, dan sumber bukti; gambar diatur pada halaman Media Website.</p>
            </div>
            <button type="submit" disabled={saving} className="inline-flex min-h-12 items-center justify-center gap-2 rounded-2xl bg-[#C2410C] px-6 font-bold text-white transition hover:bg-[#9A3412] disabled:opacity-60">
              {saving ? <Loader2 size={18} className="animate-spin" /> : <Save size={18} />} Simpan perubahan
            </button>
          </div>
        </header>

        <Panel title="Identitas dan kontak" description="Dipakai bersama oleh header, footer, metadata, dan tombol konsultasi.">
          <div className="grid gap-4 md:grid-cols-2">
            <Field label="Nama brand"><input value={settings.brand_name} onChange={(event) => updateSetting("brand_name", event.target.value)} maxLength={80} required className="form-field" /></Field>
            <Field label="Email publik"><input type="email" value={nullable(settings.contact_email)} onChange={(event) => updateSetting("contact_email", event.target.value)} className="form-field" /></Field>
            <Field label="Deskripsi singkat" wide><textarea value={nullable(settings.brand_description)} onChange={(event) => updateSetting("brand_description", event.target.value)} maxLength={300} rows={3} className="form-textarea" /></Field>
            <Field label="Alamat kantor"><textarea value={nullable(settings.office_address)} onChange={(event) => updateSetting("office_address", event.target.value)} rows={3} className="form-textarea" /></Field>
            <Field label="Tautan Google Maps"><input type="url" value={nullable(settings.google_maps_url)} onChange={(event) => updateSetting("google_maps_url", event.target.value)} placeholder="https://maps.google.com/..." className="form-field" /></Field>
          </div>
        </Panel>

        <Panel title="Bar informasi dan CTA" description="Bar dapat disembunyikan. Tujuan menu dan CTA dibatasi ke path internal agar navigasi aman.">
          <div className="grid gap-4 md:grid-cols-2">
            <Toggle label="Tampilkan bar informasi" checked={settings.information_bar_enabled} onChange={(checked) => updateSetting("information_bar_enabled", checked)} />
            <Toggle label="Aktifkan animasi terarah" checked={settings.animations_enabled} onChange={(checked) => updateSetting("animations_enabled", checked)} />
            <Field label="Isi bar informasi" wide><input value={nullable(settings.information_bar_text)} onChange={(event) => updateSetting("information_bar_text", event.target.value)} maxLength={180} className="form-field" /></Field>
            <Field label="Teks CTA utama"><input value={settings.primary_cta_label} onChange={(event) => updateSetting("primary_cta_label", event.target.value)} className="form-field" /></Field>
            <Field label="Tujuan CTA"><input value={settings.primary_cta_url} onChange={(event) => updateSetting("primary_cta_url", event.target.value)} pattern="/.*" className="form-field" /></Field>
          </div>
        </Panel>

        <Panel title="Konsultasi WhatsApp" description="Nomor hanya menjadi jalur konsultasi; transaksi tetap diselesaikan di aplikasi.">
          <div className="grid gap-4 md:grid-cols-2">
            <Toggle label="Tampilkan WhatsApp di header dan konten" checked={settings.whatsapp_enabled} onChange={(checked) => updateSetting("whatsapp_enabled", checked)} />
            <div className="hidden md:block" />
            <Field label="Nomor WhatsApp"><input value={nullable(settings.whatsapp_number)} onChange={(event) => updateSetting("whatsapp_number", sanitizePhoneInput(event.target.value))} placeholder="6281234567890" className="form-field" /></Field>
            <Field label="Jam layanan"><input value={nullable(settings.whatsapp_hours)} onChange={(event) => updateSetting("whatsapp_hours", event.target.value)} className="form-field" /></Field>
            <Field label="Teks tombol"><input value={settings.whatsapp_label} onChange={(event) => updateSetting("whatsapp_label", event.target.value)} className="form-field" /></Field>
            <Field label="Pesan pembuka"><textarea value={nullable(settings.whatsapp_default_message)} onChange={(event) => updateSetting("whatsapp_default_message", event.target.value)} rows={3} className="form-textarea" /></Field>
          </div>
        </Panel>

        <Panel title="Navigasi" description="Menu dapat disembunyikan tanpa menghapus konfigurasi dan hanya diarahkan ke halaman internal.">
          <div className="space-y-3">
            {settings.navigation_items.map((item, index) => (
              <div key={item.key} className="grid gap-3 rounded-2xl border border-stone-200 p-4 sm:grid-cols-[1fr_1.4fr_auto] sm:items-end">
                <Field label="Label"><input value={item.label} onChange={(event) => updateNavigation(index, { label: event.target.value })} className="form-field" /></Field>
                <Field label="Tujuan"><input value={item.url} onChange={(event) => updateNavigation(index, { url: event.target.value })} pattern="/.*" className="form-field" /></Field>
                <button type="button" onClick={() => updateNavigation(index, { is_visible: item.is_visible === false })} className="inline-flex h-12 items-center justify-center gap-2 rounded-2xl border border-stone-200 px-4 text-sm font-bold text-slate-700">
                  {item.is_visible === false ? <EyeOff size={17} /> : <Eye size={17} />} {item.is_visible === false ? "Tersembunyi" : "Tampil"}
                </button>
              </div>
            ))}
          </div>
        </Panel>

        <div className="flex flex-col gap-3 rounded-3xl border border-teal-200 bg-teal-50 p-5 sm:flex-row sm:items-center sm:justify-between"><p className="text-sm leading-6 text-teal-950"><strong>Ingin mengganti logo atau foto?</strong> Semua gambar aktif dan pratinjau penggantinya kini ada di halaman terpisah.</p><Link to="/admin/website-media" className="inline-flex min-h-11 shrink-0 items-center justify-center rounded-xl bg-teal-800 px-4 text-sm font-bold text-white">Buka Media Website</Link></div>

        <Panel title="Konten utama landing page" description="Teks penting dapat diperbarui tanpa mengubah struktur, alur transaksi, atau komponen desain.">
          <div className="space-y-5">
            {sections.map((section, index) => ({ section, index })).map(({ section, index }) => (
              <article key={section.id} className="rounded-2xl border border-stone-200 bg-stone-50/60 p-4 sm:p-5">
                <div className="mb-4 flex items-center justify-between gap-3"><div><h3 className="font-extrabold text-[#14213D]">{section.label}</h3><p className="mt-1 text-xs text-slate-500">Struktur dan posisi komponen tetap dikunci.</p></div><Toggle label="Tampil" compact checked={section.is_visible} onChange={(checked) => updateSection(index, { is_visible: checked })} /></div>
                <div className="grid gap-3 md:grid-cols-2">
                  <Field label="Label kecil"><input value={nullable(section.eyebrow)} onChange={(event) => updateSection(index, { eyebrow: event.target.value })} maxLength={100} className="form-field" /></Field>
                  <Field label="Judul"><input value={nullable(section.title)} onChange={(event) => updateSection(index, { title: event.target.value })} maxLength={180} className="form-field" /></Field>
                  <Field label="Deskripsi" wide><textarea value={nullable(section.description)} onChange={(event) => updateSection(index, { description: event.target.value })} maxLength={1000} rows={3} className="form-textarea" /></Field>
                  {section.section_key === "hero" && <>
                    <Field label="Status proses"><input value={nullable(section.content?.status_pending)} onChange={(event) => updateSectionContent(index, { status_pending: event.target.value })} maxLength={80} className="form-field" /></Field>
                    <Field label="Status berhasil"><input value={nullable(section.content?.status_found)} onChange={(event) => updateSectionContent(index, { status_found: event.target.value })} maxLength={80} className="form-field" /></Field>
                    {[0, 1, 2].map((pointIndex) => <Field key={pointIndex} label={`Poin kepercayaan ${pointIndex + 1}`}><input value={section.content?.trust_points?.[pointIndex] || ""} onChange={(event) => { const points = [...(section.content?.trust_points || [])]; points[pointIndex] = event.target.value; updateSectionContent(index, { trust_points: points }); }} maxLength={80} className="form-field" /></Field>)}
                  </>}
                  {section.section_key === "trust" && <p className="rounded-xl bg-teal-50 p-4 text-sm text-teal-900 md:col-span-2">Foto, nama, gelar, dan jejak pendidikan tutor beranda dapat diisi manual di <Link to="/admin/website-media" className="font-black underline">Media Website → Galeri tutor</Link>. Pilihan akun tutor tersedia untuk mengisi data lebih cepat; animasi ticker tetap memakai desain sebelumnya.</p>}
                  {section.section_key === "programs" && <Field label="Placeholder pencarian" wide><input value={nullable(section.content?.search_placeholder)} onChange={(event) => updateSectionContent(index, { search_placeholder: event.target.value })} maxLength={120} className="form-field" /></Field>}
                  {section.section_key === "final_cta" && <Field label="Teks CTA konsultasi"><input value={nullable(section.content?.secondary_cta_label)} onChange={(event) => updateSectionContent(index, { secondary_cta_label: event.target.value })} maxLength={60} className="form-field" /></Field>}
                </div>
              </article>
            ))}
          </div>
        </Panel>

        <Panel title="Struktur landing page" description="Bagian inti yang mengikuti alur transaksi dikunci urutannya. Pengaktifan konten tetap dapat diatur.">
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {sections.map((section, index) => (
              <article key={section.id} className={`rounded-2xl border p-3 ${section.is_visible ? "border-orange-200 bg-orange-50" : "border-stone-200 bg-white"}`}>
                <button type="button" onClick={() => setSections((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, is_visible: !item.is_visible } : item))} className="flex w-full items-center justify-between gap-3 rounded-xl p-1 text-left">
                  <span><span className="block font-bold text-[#14213D]">{section.label}</span><span className="mt-1 block text-xs text-slate-500">{section.order_locked ? "Urutan sistem dikunci" : `Urutan ${section.sort_order + 1}`}</span></span>
                  {section.is_visible ? <Eye size={18} className="text-orange-700" /> : <EyeOff size={18} className="text-slate-400" />}
                </button>
                {!section.order_locked && <div className="mt-2 flex justify-end gap-2 border-t border-stone-200 pt-2">
                  <button type="button" onClick={() => moveSection(index, -1)} aria-label={`Naikkan ${section.label}`} className="grid h-9 w-9 place-items-center rounded-lg border border-stone-200 bg-white text-slate-600"><ArrowUp size={16} /></button>
                  <button type="button" onClick={() => moveSection(index, 1)} aria-label={`Turunkan ${section.label}`} className="grid h-9 w-9 place-items-center rounded-lg border border-stone-200 bg-white text-slate-600"><ArrowDown size={16} /></button>
                </div>}
              </article>
            ))}
          </div>
        </Panel>

        <Panel title="Testimoni publik" description="Jumlah data tidak dibatasi. Landing menampilkan tiga unggulan dan maksimal sembilan cerita tambahan; sisanya tersedia di halaman semua cerita.">
          <div className="mb-5 flex flex-col gap-3 rounded-2xl bg-orange-50 p-4 text-sm text-slate-700 sm:flex-row sm:items-center sm:justify-between">
            <p className="max-w-2xl leading-6"><b>Aturan publikasi:</b> foto asli, izin publikasi, dan verifikasi admin wajib. Bukti hasil disimpan privat dan tidak pernah dikirim ke halaman publik.</p>
            <button type="button" onClick={() => setTestimonials((current) => [...current, emptyTestimonial(current.length)])} className="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-[#14213D] px-4 font-extrabold text-white"><Plus size={17} /> Tambah testimoni</button>
          </div>
          <div className="space-y-5">
            {testimonials.length === 0 && <div className="rounded-2xl border border-dashed border-stone-300 p-8 text-center text-sm font-semibold text-slate-500">Belum ada testimoni. Tambahkan data nyata setelah bukti dan izin tersedia.</div>}
            {testimonials.map((item, index) => {
              const selected = testimonialFiles[item.client_key] || {};
              return <article key={item.client_key} className="rounded-2xl border border-stone-200 p-4 sm:p-5">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><div className="flex min-w-0 items-center gap-3">{item.photo_url ? <img src={item.photo_url} alt="" loading="lazy" decoding="async" className="h-16 w-16 rounded-2xl object-cover" /> : <span className="grid h-16 w-16 shrink-0 place-items-center rounded-2xl bg-stone-100 text-xs font-extrabold text-stone-400">FOTO</span>}<div><p className="font-extrabold text-[#14213D]">{item.display_name || `Testimoni baru ${index + 1}`}</p><p className="mt-1 text-xs font-semibold text-slate-500">{item.is_visible ? "Siap tampil setelah semua syarat terpenuhi" : "Draft / tidak tampil"}</p>{item.verified_at && <p className="mt-1 text-xs text-slate-500">Diperiksa {item.verified_by_name || "belum tercatat"} · {item.verified_at}</p>}</div></div><button type="button" onClick={() => removeTestimonial(index)} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-rose-200 px-3 text-xs font-extrabold text-rose-700"><Trash2 size={15} /> Hapus</button></div>
                <div className="mt-5 grid gap-3 md:grid-cols-2">
                  <Field label="Nama publik"><input required value={item.display_name} onChange={(event) => updateTestimonial(index, { display_name: event.target.value })} maxLength={100} className="form-field" /></Field>
                  <Field label="Peran"><input value={nullable(item.audience_role)} onChange={(event) => updateTestimonial(index, { audience_role: event.target.value })} placeholder="Siswa kelas 12 / Orang tua murid" maxLength={120} className="form-field" /></Field>
                  <Field label="Kutipan dari rating murid" wide><textarea required readOnly value={item.quote} maxLength={1000} rows={3} className="form-textarea bg-stone-50" /></Field>
                  <Field label="Program"><input value={nullable(item.program_name)} onChange={(event) => updateTestimonial(index, { program_name: event.target.value })} maxLength={150} className="form-field" /></Field>
                  <Field label="Terkait halaman mapel (opsional)"><select value={item.curriculum_subject_id || ""} onChange={(event) => updateTestimonial(index, { curriculum_subject_id: event.target.value ? Number(event.target.value) : null })} className="form-field"><option value="">Testimoni umum</option>{subjectOptions.map((subject) => <option key={subject.id} value={subject.id}>{subject.name}{subject.education_levels?.length ? ` · ${subject.education_levels.join("/")}` : ""}</option>)}</select></Field>
                  <Field label="Hasil terverifikasi"><input value={nullable(item.outcome)} onChange={(event) => updateTestimonial(index, { outcome: event.target.value })} placeholder="Contoh: Diterima melalui SNBT" maxLength={180} className="form-field" /></Field>
                  <Field label="Sekolah / kampus"><input value={nullable(item.institution)} onChange={(event) => updateTestimonial(index, { institution: event.target.value })} maxLength={150} className="form-field" /></Field>
                  <Field label="Jurusan"><input value={nullable(item.major)} onChange={(event) => updateTestimonial(index, { major: event.target.value })} maxLength={150} className="form-field" /></Field>
                  <Field label="Tahun hasil"><input type="number" min={2000} max={new Date().getFullYear() + 1} value={item.achievement_year || ""} onChange={(event) => updateTestimonial(index, { achievement_year: event.target.value ? Number(event.target.value) : null })} className="form-field" /></Field>
                  <Field label="Rating murid nyata (wajib saat tampil)"><select value={item.rating_id || ""} onChange={(event) => { const rating = ratingOptions.find((option) => option.id === Number(event.target.value)); updateTestimonial(index, { rating_id: rating?.id || null, quote: rating?.review || "" }); }} className="form-field"><option value="">Pilih ulasan sistem</option>{ratingOptions.map((rating) => <option key={rating.id} value={rating.id} disabled={!rating.review?.trim()}>{rating.rating}★ · {rating.student_name} → {rating.teacher_name}{rating.review ? ` · ${rating.review.slice(0, 60)}` : " · tanpa ulasan teks"}</option>)}</select></Field>
                  <Field label="Foto publik"><input type="file" accept=".png,.jpg,.jpeg,.webp" onChange={(event) => { const photo = event.target.files?.[0]; if (photo) setTestimonialFiles((current) => ({ ...current, [item.client_key]: { ...current[item.client_key], photo } })); }} className="block w-full text-xs file:mr-3 file:rounded-lg file:border-0 file:bg-orange-100 file:px-3 file:py-2 file:font-bold file:text-orange-800" />{selected.photo && <span className="mt-1 block truncate text-xs text-emerald-700">{selected.photo.name}</span>}</Field>
                  <Field label="Bukti privat"><input type="file" accept=".png,.jpg,.jpeg,.webp,.pdf" onChange={(event) => { const proof = event.target.files?.[0]; if (proof) setTestimonialFiles((current) => ({ ...current, [item.client_key]: { ...current[item.client_key], proof } })); }} className="block w-full text-xs file:mr-3 file:rounded-lg file:border-0 file:bg-emerald-100 file:px-3 file:py-2 file:font-bold file:text-emerald-800" />{(selected.proof || item.has_proof) && <span className="mt-1 block truncate text-xs text-emerald-700">{selected.proof?.name || "Bukti tersimpan privat"}</span>}</Field>
                </div>
                <div className="mt-4 grid gap-3 sm:grid-cols-3"><Toggle label="Izin publikasi" compact checked={item.consent_confirmed} onChange={(checked) => updateTestimonial(index, { consent_confirmed: checked })} /><Toggle label="Sudah diverifikasi" compact checked={item.is_verified} onChange={(checked) => updateTestimonial(index, { is_verified: checked })} /><Toggle label="Testimoni unggulan" compact checked={item.is_featured} onChange={(checked) => updateTestimonial(index, { is_featured: checked })} /><Toggle label="Tampilkan ke publik" compact checked={item.is_visible} onChange={(checked) => updateTestimonial(index, { is_visible: checked })} /><Field label="Urutan"><input type="number" min={0} max={65535} value={item.sort_order} onChange={(event) => updateTestimonial(index, { sort_order: Number(event.target.value) || 0 })} className="form-field" /></Field></div>
              </article>;
            })}
          </div>
        </Panel>
        <Panel title="Aturan bukti kepercayaan" description="Data manual wajib mempunyai catatan sumber dan tanggal. Nilai sistem dihitung otomatis dan tidak dapat diketik.">
          <div className="space-y-4">
            {trustItems.map((item, index) => (
              <article key={item.id} className="rounded-2xl border border-stone-200 p-4 sm:p-5">
                <div className="flex items-start justify-between gap-4">
                  <div className="flex min-w-0 items-center gap-3"><span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-orange-100 text-orange-700"><ShieldCheck size={19} /></span><div><p className="font-bold text-[#14213D]">Bukti {index + 1}</p><p className="text-xs text-slate-500">Nilai publik: {item.resolved_value || "tidak menampilkan angka"}</p></div></div>
                  <Toggle label="Tampil" compact checked={item.is_visible} onChange={(checked) => updateTrust(index, { is_visible: checked })} />
                </div>
                <div className="mt-4 grid gap-3 md:grid-cols-2">
                  <Field label="Judul"><input value={item.title} onChange={(event) => updateTrust(index, { title: event.target.value })} className="form-field" /></Field>
                  <Field label="Jenis sumber"><select value={item.source_type} onChange={(event) => updateTrust(index, { source_type: event.target.value as TrustItem["source_type"] })} className="form-field"><option value="system">Data sistem</option><option value="manual">Data manual terverifikasi</option><option value="commitment">Komitmen tanpa angka</option></select></Field>
                  {item.source_type === "system" && <Field label="Sumber sistem"><select value={item.source_key || ""} onChange={(event) => updateTrust(index, { source_key: event.target.value as TrustItem["source_key"] })} className="form-field"><option value="">Pilih sumber</option><option value="active_programs">Program belajar aktif</option><option value="verified_tutors">Tutor terverifikasi</option><option value="completed_sessions">Sesi selesai</option><option value="average_rating">Rating rata-rata</option><option value="service_area">Area layanan</option></select></Field>}
                  {item.source_type === "manual" && <><Field label="Nilai yang ditampilkan"><input value={nullable(item.display_value)} onChange={(event) => updateTrust(index, { display_value: event.target.value })} className="form-field" /></Field><Field label="Tanggal pembaruan"><input type="date" max={new Date().toISOString().slice(0, 10)} value={dateValue(item.source_updated_at)} onChange={(event) => updateTrust(index, { source_updated_at: event.target.value })} className="form-field" /></Field><Field label="Catatan sumber" wide><textarea value={nullable(item.source_note)} onChange={(event) => updateTrust(index, { source_note: event.target.value })} rows={2} className="form-textarea" /></Field></>}
                  <Field label="Deskripsi" wide><input value={nullable(item.description)} onChange={(event) => updateTrust(index, { description: event.target.value })} className="form-field" /></Field>
                </div>
              </article>
            ))}
          </div>
        </Panel>
      </form>
    </AdminLayout>
  );
}

function Panel({ title, description, children }: { title: string; description: string; children: React.ReactNode }) {
  return <section className="rounded-3xl border border-stone-200 bg-white p-5 shadow-sm sm:p-7"><div className="mb-5"><h2 className="text-xl font-extrabold text-[#14213D]">{title}</h2><p className="mt-1 text-sm leading-6 text-slate-500">{description}</p></div>{children}</section>;
}

function Field({ label, wide = false, children }: { label: string; wide?: boolean; children: React.ReactNode }) {
  return <label className={`block text-sm font-bold text-slate-700 ${wide ? "md:col-span-2" : ""}`}><span className="mb-1.5 block">{label}</span>{children}</label>;
}

function Toggle({ label, checked, onChange, compact = false }: { label: string; checked: boolean; onChange: (checked: boolean) => void; compact?: boolean }) {
  return <label className={`flex cursor-pointer items-center justify-between gap-3 rounded-2xl border border-stone-200 ${compact ? "px-3 py-2 text-xs" : "min-h-12 px-4 py-3 text-sm"} font-bold text-slate-700`}><span>{label}</span><input type="checkbox" checked={checked} onChange={(event) => onChange(event.target.checked)} className="h-5 w-5 rounded border-stone-300 text-orange-600 focus:ring-orange-500" /></label>;
}
