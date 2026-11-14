import { notify } from "@/lib/notify";
import { clearBrowserSession } from "@/lib/session";
import { FormEvent, useEffect, useRef, useState } from "react";
import { useNavigate } from "react-router-dom";
import {
  BookOpen,
  Camera,
  Check,
  GraduationCap,
  Loader2,
  LocateFixed,
  MapPin,
  Monitor,
  Plus,
  Save,
  ShieldCheck,
  Sparkles,
  Store,
  Trash2,
  User,
  FileCheck2,
  IdCard,
  ImagePlus,
  ScanSearch,
} from "lucide-react";
import TeacherLayout from "@/components/TeacherLayout";
import { useConfirmDialog } from "@/components/ConfirmDialogProvider";
import CameraCapture from "@/components/CameraCapture";
import { openProtectedFile } from "@/components/ProtectedImage";
import SubjectCombobox, { SubjectOption } from "@/components/SubjectCombobox";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import http, { clearApiCache, getApiError, getCached } from "@/lib/http";
import { publicMediaUrl } from "@/lib/apiBase";
import {
  isValidPersonName,
  isValidPhone,
  isValidRegionName,
  sanitizePersonName,
  sanitizePhoneInput,
  validateUpload,
} from "@/lib/validation";
import { EDUCATION_LEVELS } from "@/lib/educationCatalog";

const levels = [...EDUCATION_LEVELS];

interface TeachingSubjectState {
  name: string;
  levels: string[];
  is_online: boolean;
  is_offline: boolean;
  is_private_active: boolean;
  is_group_active: boolean;
}

interface ProfileState {
  name: string;
  email: string;
  title: string;
  location: string;
  address: string;
  experience: string;
  bio: string;
  whatsapp_number: string;
  latitude: string;
  longitude: string;
  max_travel_km: string;
  is_accepting_requests: boolean;
  public_profile_enabled: boolean;
  public_credentials: string;
  public_degree: string;
  photo: string;
  photoFile: File | null;
  profileCover: string;
  userStatus: string;
  documentUrls: Record<string, string>;
  documentFiles: Record<string, File | null>;
  teachingSubjects: TeachingSubjectState[];
}

const initialState: ProfileState = {
  name: "",
  email: "",
  title: "",
  location: "",
  address: "",
  experience: "",
  bio: "",
  whatsapp_number: "",
  latitude: "",
  longitude: "",
  max_travel_km: "12",
  is_accepting_requests: true,
  public_profile_enabled: false,
  public_credentials: "",
  public_degree: "",
  photo: "",
  photoFile: null,
  profileCover: "",
  userStatus: "",
  documentUrls: {},
  documentFiles: {},
  teachingSubjects: [{ name: "", levels: [], is_online: true, is_offline: true, is_private_active: true, is_group_active: false }],
};

export default function TeacherProfile() {
  const navigate = useNavigate();
  const confirm = useConfirmDialog();
  const [profile, setProfile] = useState<ProfileState>(initialState);
  const [loading, setLoading] = useState(true);
  const [savingProfile, setSavingProfile] = useState(false);
  const [savingCover, setSavingCover] = useState(false);
  const [savingSubject, setSavingSubject] = useState(false);
  const [locating, setLocating] = useState(false);
  const [subjectOptions, setSubjectOptions] = useState<SubjectOption[]>([]);
  const photoPreviewUrlRef = useRef<string | null>(null);
  const coverPreviewUrlRef = useRef<string | null>(null);
  const coverInputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    void loadProfile();
    return () => {
      if (photoPreviewUrlRef.current) URL.revokeObjectURL(photoPreviewUrlRef.current);
      if (coverPreviewUrlRef.current) URL.revokeObjectURL(coverPreviewUrlRef.current);
    };
  }, []);

  const loadProfile = async () => {
    setLoading(true);
    try {
      const [response, coverResponse, catalogResponse] = await Promise.all([
        getCached("/teacher/profile", { force: true }),
        getCached("/settings/teacher-cover", { force: true }).catch(() => null),
        getCached<{ subject_options?: SubjectOption[] }>("/learning-catalog", {
          params: { compact: 1 },
          maxAgeMs: 5 * 60_000,
        }).catch(() => null),
      ]);
      setSubjectOptions(catalogResponse?.data.subject_options || []);
      const { user, profile: teacherProfile } = response.data;
      const teachingSubjects: TeachingSubjectState[] = Array.isArray(teacherProfile.subjects) && teacherProfile.subjects.length > 0
        ? teacherProfile.subjects.slice(0, 1).map((subject: any) => ({
            name: subject?.name || "",
            levels: Array.isArray(subject?.levels) ? subject.levels : [],
            is_online: subject?.is_online ?? true,
            is_offline: subject?.is_offline ?? true,
            is_private_active: subject?.is_private_active ?? true,
            is_group_active: subject?.is_group_active ?? false,
          }))
        : [{ name: "", levels: [], is_online: true, is_offline: true, is_private_active: true, is_group_active: false }];
      setProfile({
        name: sanitizePersonName(user.name || ""),
        email: user.email || "",
        title: teacherProfile.title || "",
        location: teacherProfile.location || "",
        address: user.address || "",
        experience: teacherProfile.experience || "",
        bio: teacherProfile.bio || "",
        whatsapp_number: sanitizePhoneInput(teacherProfile.whatsapp_number || user.phone || ""),
        latitude: teacherProfile.latitude?.toString() || "",
        longitude: teacherProfile.longitude?.toString() || "",
        max_travel_km: teacherProfile.max_travel_km?.toString() || "12",
        is_accepting_requests: teacherProfile.is_accepting_requests ?? true,
        public_profile_enabled: teacherProfile.public_profile_enabled ?? false,
        public_credentials: teacherProfile.public_credentials || "",
        public_degree: teacherProfile.public_degree || "",
        photo: publicMediaUrl(teacherProfile.photo) || "",
        photoFile: null,
        profileCover: teacherProfile.profile_cover_url || coverResponse?.data?.url || "",
        userStatus: user.status || "",
        documentUrls: {
          identity_document: teacherProfile.identity_document_url || "",
          live_selfie: teacherProfile.live_selfie_url || "",
          qualification_document: teacherProfile.qualification_document_url || "",
          certification_document: teacherProfile.certification_document_url || "",
        },
        documentFiles: {},
        teachingSubjects,
      });
    } catch (error) {
      notify.error(getApiError(error, "Profil tutor gagal dimuat."));
    } finally {
      setLoading(false);
    }
  };

  const handlePhoto = (file?: File) => {
    if (!file) return;
    const error = validateUpload(file, {
      label: "Foto profil",
      maxSizeMb: 5,
      extensions: ["jpg", "jpeg", "png", "webp"],
    });
    if (error) {
      notify.error(error);
      return;
    }
    if (photoPreviewUrlRef.current) URL.revokeObjectURL(photoPreviewUrlRef.current);
    const previewUrl = URL.createObjectURL(file);
    photoPreviewUrlRef.current = previewUrl;
    setProfile((current) => ({ ...current, photoFile: file, photo: previewUrl }));
  };

  const handleCover = async (file?: File) => {
    if (!file) return;
    const error = validateUpload(file, {
      label: "Sampul profil",
      maxSizeMb: 5,
      extensions: ["jpg", "jpeg", "png", "webp"],
    });
    if (error) {
      notify.error(error);
      return;
    }
    const previousCover = profile.profileCover;
    if (coverPreviewUrlRef.current) URL.revokeObjectURL(coverPreviewUrlRef.current);
    const previewUrl = URL.createObjectURL(file);
    coverPreviewUrlRef.current = previewUrl;
    setProfile((current) => ({ ...current, profileCover: previewUrl }));
    setSavingCover(true);
    try {
      const formData = new FormData();
      formData.append("profile_cover", file);
      const response = await http.post<{ message: string; profile_cover_url: string }>("/teacher/profile/cover", formData);
      clearApiCache("/teacher/profile");
      setProfile((current) => ({ ...current, profileCover: response.data.profile_cover_url }));
      notify.success(response.data.message);
    } catch (error) {
      setProfile((current) => ({ ...current, profileCover: previousCover }));
      notify.error(getApiError(error, "Sampul gagal disimpan. Coba lagi."));
    } finally {
      URL.revokeObjectURL(previewUrl);
      if (coverPreviewUrlRef.current === previewUrl) coverPreviewUrlRef.current = null;
      setSavingCover(false);
    }
  };

  const setDocument = (
    key: "identity_document" | "live_selfie" | "qualification_document" | "certification_document",
    file: File | null,
  ) => {
    const selfieOnly = key === "live_selfie";
    const error = validateUpload(file, {
      label: selfieOnly ? "Foto wajah langsung" : "Dokumen verifikasi",
      maxSizeMb: 5,
      extensions: selfieOnly ? ["jpg", "jpeg", "png", "webp"] : ["jpg", "jpeg", "png", "webp", "pdf"],
    });
    if (error) {
      notify.error(error);
      return;
    }
    setProfile((current) => ({
      ...current,
      documentFiles: { ...current.documentFiles, [key]: file },
    }));
  };

  const saveProfile = async (event: FormEvent) => {
    event.preventDefault();
    if (!isValidPersonName(profile.name)) {
      notify.error("Nama lengkap harus berisi huruf dan tidak boleh memuat angka.");
      return;
    }
    if (!isValidPhone(profile.whatsapp_number)) {
      notify.error("Nomor WhatsApp/telepon harus berisi 8–15 angka.");
      return;
    }
    if (!isValidRegionName(profile.location)) {
      notify.error("Kota atau wilayah wajib mengandung huruf dan tidak boleh hanya berisi angka atau simbol.");
      return;
    }
    if (profile.address.trim().length > 500) {
      notify.error("Alamat lengkap maksimal 500 karakter.");
      return;
    }
    setSavingProfile(true);
    try {
      const formData = new FormData();
      formData.append("name", profile.name);
      formData.append("title", profile.title);
      formData.append("location", profile.location);
      formData.append("address", profile.address);
      formData.append("experience", profile.experience);
      formData.append("bio", profile.bio);
      formData.append("whatsapp_number", profile.whatsapp_number);
      if (profile.latitude) formData.append("latitude", profile.latitude);
      if (profile.longitude) formData.append("longitude", profile.longitude);
      formData.append("max_travel_km", profile.max_travel_km);
      formData.append("is_accepting_requests", profile.is_accepting_requests ? "1" : "0");
      formData.append("public_profile_enabled", profile.public_profile_enabled ? "1" : "0");
      formData.append("public_credentials", profile.public_credentials);
      formData.append("public_degree", profile.public_degree);
      if (profile.photoFile) formData.append("photo", profile.photoFile);
      Object.entries(profile.documentFiles).forEach(([key, file]) => {
        if (file) formData.append(key, file);
      });

      const response = await http.post("/teacher/profile", formData);
      notify.success(response.data.message);
      if (response.data.reverification_required) {
        clearBrowserSession();
        navigate("/login", { replace: true });
        return;
      }
      if (photoPreviewUrlRef.current) {
        URL.revokeObjectURL(photoPreviewUrlRef.current);
        photoPreviewUrlRef.current = null;
      }
      if (coverPreviewUrlRef.current) {
        URL.revokeObjectURL(coverPreviewUrlRef.current);
        coverPreviewUrlRef.current = null;
      }
      clearApiCache("/teacher/profile");
      await loadProfile();
    } catch (error) {
      notify.error(getApiError(error, "Profil gagal disimpan."));
    } finally {
      setSavingProfile(false);
    }
  };

  const saveSubject = async () => {
    const entries = profile.teachingSubjects;
    if (entries.length !== 1) {
      notify.error("Pilih tepat 1 mata pelajaran sesuai bidang utama kamu.");
      return;
    }
    const names = entries.map((item) => item.name.trim());
    if (names.some((name) => !name)) {
      notify.error("Semua mata pelajaran wajib diisi.");
      return;
    }
    if (new Set(names.map((name) => name.toLocaleLowerCase("id-ID"))).size !== names.length) {
      notify.error("Mata pelajaran tidak boleh duplikat.");
      return;
    }
    if (entries.some((item) => item.levels.length === 0)) {
      notify.error("Pilih minimal satu jenjang untuk setiap mata pelajaran.");
      return;
    }
    if (entries.some((item) => !item.is_online && !item.is_offline)) {
      notify.error("Aktifkan minimal satu mode mengajar pada setiap mata pelajaran.");
      return;
    }

    const approved = await confirm({
      title: "Simpan kompetensi tutor?",
      description: "Perubahan daftar mata pelajaran atau jenjang harus diperiksa ulang oleh admin. Jika kompetensi berubah, sesi login akan ditutup setelah data tersimpan.",
      confirmText: "Ya, simpan",
      tone: "warning",
    });
    if (!approved) return;

    setSavingSubject(true);
    try {
      const response = await http.post("/teacher/subjects", {
        subjects: entries.map((item) => ({
          name: item.name.trim(),
          levels: item.levels,
          is_online: item.is_online,
          is_offline: item.is_offline,
          is_private_active: item.is_private_active,
          is_group_active: item.is_group_active,
        })),
      });
      notify.success(response.data.message);
      if (response.data.reverification_required) {
        clearBrowserSession();
        navigate("/login", { replace: true });
        return;
      }
      await loadProfile();
    } catch (error) {
      notify.error(getApiError(error, "Mata pelajaran gagal disimpan."));
    } finally {
      setSavingSubject(false);
    }
  };

  const updateTeachingSubject = (index: number, patch: Partial<TeachingSubjectState>) => {
    setProfile((current) => ({
      ...current,
      teachingSubjects: current.teachingSubjects.map((item, itemIndex) =>
        itemIndex === index ? { ...item, ...patch } : item
      ),
    }));
  };

  const toggleSubjectLevel = (index: number, level: string) => {
    setProfile((current) => ({
      ...current,
      teachingSubjects: current.teachingSubjects.map((item, itemIndex) => {
        if (itemIndex !== index) return item;
        return {
          ...item,
          levels: item.levels.includes(level)
            ? item.levels.filter((value) => value !== level)
            : [...item.levels, level],
        };
      }),
    }));
  };

  const addTeachingSubject = () => {
    setProfile((current) => {
      if (current.teachingSubjects.length >= 1) return current;
      return {
        ...current,
        teachingSubjects: [
          ...current.teachingSubjects,
          { name: "", levels: [], is_online: true, is_offline: true, is_private_active: true, is_group_active: false },
        ],
      };
    });
  };

  const removeTeachingSubject = (index: number) => {
    setProfile((current) => {
      if (current.teachingSubjects.length <= 1) return current;
      return {
        ...current,
        teachingSubjects: current.teachingSubjects.filter((_, itemIndex) => itemIndex !== index),
      };
    });
  };

  const detectLocation = () => {
    if (!navigator.geolocation) {
      notify.error("Perangkat ini tidak mendukung deteksi lokasi.");
      return;
    }

    setLocating(true);
    navigator.geolocation.getCurrentPosition(
      (position) => {
        setProfile((current) => ({
          ...current,
          latitude: position.coords.latitude.toFixed(7),
          longitude: position.coords.longitude.toFixed(7),
        }));
        setLocating(false);
        notify.success("Titik lokasi tutor berhasil diisi.");
      },
      () => {
        setLocating(false);
        notify.error("Lokasi tidak dapat dibaca. Aktifkan izin lokasi pada browser.");
      },
      { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 },
    );
  };

  if (loading) {
    return (
      <TeacherLayout title="Profil Tutor">
        <div className="min-h-[60vh] flex items-center justify-center"><Loader2 className="h-9 w-9 animate-spin text-indigo-600" /></div>
      </TeacherLayout>
    );
  }

  return (
    <TeacherLayout title="Profil Tutor">
      <div className="max-w-7xl mx-auto space-y-7 pb-12">
        <section
          className="relative overflow-hidden rounded-[1.7rem] bg-gradient-to-br from-slate-950 via-indigo-950 to-violet-900 bg-cover bg-center p-5 text-white shadow-xl animate-in fade-in slide-in-from-bottom-3 duration-500 sm:rounded-[2rem] sm:px-7 sm:py-8"
          style={profile.profileCover ? {
            backgroundImage: `url("${profile.profileCover}")`,
          } : undefined}
        >
          <div className="absolute -right-16 -top-20 h-64 w-64 rounded-full bg-indigo-400/20 blur-3xl" />
          <button type="button" disabled={savingCover} onClick={() => coverInputRef.current?.click()} className={`absolute right-5 top-5 z-20 inline-flex min-h-11 items-center gap-2 rounded-xl border border-white/20 bg-slate-950/75 px-3 py-2 text-xs font-bold text-white shadow-lg backdrop-blur transition hover:bg-slate-950 ${savingCover ? "cursor-wait opacity-70" : "cursor-pointer"}`}>
            {savingCover ? <Loader2 size={15} className="animate-spin" /> : <ImagePlus size={15} />}
            {savingCover ? "Menyimpan sampul…" : "Ubah sampul"}
          </button>
          <input ref={coverInputRef} type="file" accept=".jpg,.jpeg,.png,.webp" disabled={savingCover} className="sr-only" onChange={(event) => { void handleCover(event.target.files?.[0]); event.target.value = ""; }} />
          <div className="relative flex flex-col gap-6 md:flex-row md:items-end">
            <label className="group relative h-28 w-28 shrink-0 cursor-pointer overflow-hidden rounded-[2rem] border-4 border-white/15 bg-white/10 shadow-xl">
              {profile.photo ? <img src={profile.photo} alt={profile.name} loading="lazy" decoding="async" className="h-full w-full object-cover" /> : <div className="h-full w-full flex items-center justify-center"><User size={38} className="text-indigo-200" /></div>}
              <div className="absolute inset-0 flex items-center justify-center bg-slate-950/60 opacity-0 transition group-hover:opacity-100"><Camera size={24} /></div>
              <input type="file" accept=".jpg,.jpeg,.png,.webp" className="hidden" onChange={(event) => handlePhoto(event.target.files?.[0])} />
            </label>
            <div className="min-w-0 flex-1 md:translate-y-3">
              <div className="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1.5 text-xs font-bold uppercase tracking-widest text-indigo-100"><Sparkles size={14} /> Profil pencocokan</div>
              <h1 className="mt-4 break-words text-2xl font-black leading-tight drop-shadow-[0_2px_3px_rgba(0,0,0,.45)] [overflow-wrap:anywhere] sm:text-3xl">{profile.name || "Profil Tutor"}</h1>
              <p className="mt-2 max-w-2xl text-sm leading-6 text-indigo-100/80">Data ini dipakai sistem untuk menilai kecocokan mata pelajaran, jenjang, mode, dan jarak kelas offline.</p>
            </div>
            <div className="rounded-2xl border border-white/10 bg-white/10 px-5 py-4 backdrop-blur">
              <div className="flex items-center gap-3"><ShieldCheck size={22} className="text-emerald-300" /><div><p className="text-sm font-black">Profil diverifikasi admin</p><p className="text-[10px] uppercase tracking-widest text-indigo-100">{profile.userStatus === "active" ? "Tutor terverifikasi" : "Menunggu pemeriksaan"}</p></div></div>
            </div>
          </div>
        </section>

        <div className="grid grid-cols-1 xl:grid-cols-[1.1fr_.9fr] gap-7 items-start">
          <form onSubmit={saveProfile} className="rounded-[2rem] border border-slate-100 bg-white p-6 md:p-8 shadow-sm animate-in fade-in slide-in-from-left-3 duration-500">
            <div className="flex items-start justify-between gap-4"><div><h2 className="text-2xl font-black text-slate-900">Identitas dan wilayah</h2><p className="mt-1 text-sm text-slate-500">Alamat lengkap murid hanya muncul setelah pembayaran dikonfirmasi.</p></div><div className="h-11 w-11 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center"><User size={20} /></div></div>

            <div className="mt-6 grid grid-cols-1 md:grid-cols-2 gap-5">
              <Field label="Nama lengkap"><Input value={profile.name} onChange={(event) => setProfile((current) => ({ ...current, name: sanitizePersonName(event.target.value) }))} className="h-12 rounded-xl" required /></Field>
              <Field label="Email"><Input value={profile.email} className="h-12 rounded-xl bg-slate-50" disabled /></Field>
              <Field label="Judul profil"><Input value={profile.title} onChange={(event) => setProfile((current) => ({ ...current, title: event.target.value }))} className="h-12 rounded-xl" placeholder="Contoh: Tutor Matematika" /></Field>
              <Field label="Gelar atau pendidikan terakhir"><Input value={profile.public_degree} onChange={(event) => setProfile((current) => ({ ...current, public_degree: event.target.value }))} maxLength={120} className="h-12 rounded-xl" placeholder="Contoh: S.Pd. / Mahasiswa S1 Pendidikan Matematika" /></Field>
              <Field label="Pengalaman"><Input value={profile.experience} onChange={(event) => setProfile((current) => ({ ...current, experience: event.target.value }))} className="h-12 rounded-xl" placeholder="Contoh: 4 tahun" /></Field>
              <div className="md:col-span-2"><Field label="Nomor WhatsApp/telepon"><Input required inputMode="tel" autoComplete="tel" maxLength={16} value={profile.whatsapp_number} onChange={(event) => setProfile((current) => ({ ...current, whatsapp_number: sanitizePhoneInput(event.target.value) }))} className="h-12 rounded-xl" placeholder="Contoh: 0812 3456 7890" /></Field></div>
              <div className="md:col-span-2"><Field label="Kota atau wilayah tinggal"><Input value={profile.location} onChange={(event) => setProfile((current) => ({ ...current, location: event.target.value }))} className="h-12 rounded-xl" placeholder="Contoh: Jakarta Selatan" /></Field></div>
              <div className="md:col-span-2">
                <Field label="Alamat lengkap (sesuai KTP)">
                  <textarea
                    value={profile.address}
                    onChange={(event) => setProfile((current) => ({ ...current, address: event.target.value }))}
                    maxLength={500}
                    rows={3}
                    className="w-full resize-y rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100"
                    placeholder="Contoh: Jl. Melati No. 12, RT 03/RW 05, Kel. Sukamaju, Kec. Cilandak"
                  />
                </Field>
                <p className="mt-1.5 text-[11px] leading-4 text-slate-500">Dipakai untuk transfer pencairan saldo. Tidak ditampilkan di profil publik.</p>
              </div>
              <div className="md:col-span-2 rounded-2xl border border-indigo-100 bg-indigo-50/70 p-4 sm:p-5">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                  <div className="flex min-w-0 items-start gap-3">
                    <span className={`grid h-10 w-10 shrink-0 place-items-center rounded-xl ${profile.latitude && profile.longitude ? "bg-emerald-100 text-emerald-700" : "bg-white text-indigo-600"}`}>
                      <MapPin size={19} />
                    </span>
                    <div className="min-w-0">
                      <p className="font-black text-slate-900">Lokasi untuk kelas offline</p>
                      <p className="mt-1 text-xs leading-5 text-slate-600">
                        {profile.latitude && profile.longitude
                          ? "Titik lokasi perangkat sudah tersimpan. Data teknis tidak perlu diisi manual."
                          : "Atur lokasi dari perangkat agar sistem dapat menghitung jarak kelas offline."}
                      </p>
                    </div>
                  </div>
                  <Button type="button" variant="outline" onClick={detectLocation} disabled={locating} className="h-11 shrink-0 rounded-xl border-indigo-200 bg-white text-indigo-700 hover:bg-indigo-100 hover:text-indigo-800">
                    {locating ? <Loader2 size={17} className="mr-2 animate-spin" /> : <LocateFixed size={17} className="mr-2" />}
                    {profile.latitude && profile.longitude ? "Atur ulang lokasi" : "Atur lokasi"}
                  </Button>
                </div>
              </div>
              <Field label="Batas perjalanan offline (maks. 12 km)"><Input type="number" min="1" max="12" value={profile.max_travel_km} onChange={(event) => setProfile((current) => ({ ...current, max_travel_km: event.target.value }))} className="h-12 rounded-xl" /></Field>
              <div className="rounded-2xl border border-slate-100 bg-slate-50 p-4 flex items-center justify-between gap-4"><div><p className="font-bold text-slate-800">Terima permintaan baru</p><p className="mt-1 text-xs text-slate-500">Matikan saat sedang tidak tersedia.</p></div><Switch checked={profile.is_accepting_requests} onCheckedChange={(checked) => setProfile((current) => ({ ...current, is_accepting_requests: checked }))} /></div>
            </div>

            <div className="mt-5"><Field label="Tentang saya"><Textarea maxLength={3000} value={profile.bio} onChange={(event) => setProfile((current) => ({ ...current, bio: event.target.value }))} className="min-h-32 rounded-xl" placeholder="Jelaskan pendekatan mengajar secara ringkas." /></Field></div>

            <div className="mt-6 rounded-2xl border border-teal-200 bg-teal-50 p-5">
              <div className="flex items-start justify-between gap-4"><div><p className="font-black text-slate-900">Izin profil publik</p><p className="mt-1 text-xs leading-5 text-slate-600">Jika diaktifkan, nama, foto profil, judul, mapel, pengalaman, dan jejak pendidikan/karier di bawah dapat tampil di halaman Kenali Tutor dan mapel setelah akun lulus verifikasi serta disetujui admin. Nomor kontak, lokasi rinci, dan dokumen tidak pernah dipublikasikan.</p></div><Switch checked={profile.public_profile_enabled} onCheckedChange={(checked) => setProfile((current) => ({ ...current, public_profile_enabled: checked }))} /></div>
              <div className="mt-4"><Field label="Jejak pendidikan, karier, atau prestasi publik"><Textarea maxLength={300} value={profile.public_credentials} onChange={(event) => setProfile((current) => ({ ...current, public_credentials: event.target.value }))} className="min-h-24 rounded-xl bg-white" placeholder="Contoh: Alumni Pendidikan Matematika UNY · Finalis Olimpiade Matematika 2024" /></Field></div>
              <p className="mt-3 text-xs font-semibold leading-5 text-teal-900">Mengaktifkan pilihan ini memberi izin publikasi, bukan langsung menayangkan profil. Admin meninjau sebelum tampil. Perubahan informasi publik memerlukan persetujuan ulang, dan izin dapat dicabut kapan saja.</p>
            </div>

            <div className="mt-6 rounded-2xl border border-slate-100 bg-slate-50 p-5">
              <div className="flex items-start gap-3"><FileCheck2 className="mt-0.5 shrink-0 text-indigo-600" /><div><p className="font-black text-slate-800">Dokumen verifikasi</p><p className="mt-1 text-xs leading-5 text-slate-500">Mengganti dokumen pada akun aktif memicu pemeriksaan ulang dan menutup sesi login setelah disimpan.</p></div></div>
              <div className="mt-4 grid gap-3 sm:grid-cols-2">
                <DocumentInput label="Kartu identitas" icon={IdCard} current={profile.documentUrls.identity_document} accept=".jpg,.jpeg,.png,.webp,.pdf" onChange={(file) => setDocument("identity_document", file)} />
                <div className="space-y-2">
                  <CameraCapture
                    file={profile.documentFiles.live_selfie}
                    currentAvailable={Boolean(profile.documentUrls.live_selfie)}
                    onCapture={(file) => setDocument("live_selfie", file)}
                  />
                  {profile.documentUrls.live_selfie && (
                    <Button
                      type="button"
                      variant="outline"
                      className="h-9 w-full rounded-xl text-xs font-bold"
                      onClick={() => void openProtectedFile(profile.documentUrls.live_selfie, "Foto wajah langsung").catch(() => notify.error("Foto tidak dapat dibuka."))}
                    >
                      <ScanSearch size={14} className="mr-2" />Lihat foto tersimpan
                    </Button>
                  )}
                </div>
                <DocumentInput label="Ijazah / kualifikasi" icon={GraduationCap} current={profile.documentUrls.qualification_document} accept=".jpg,.jpeg,.png,.webp,.pdf" onChange={(file) => setDocument("qualification_document", file)} />
                <DocumentInput label="Sertifikat pendukung" icon={FileCheck2} current={profile.documentUrls.certification_document} accept=".jpg,.jpeg,.png,.webp,.pdf" onChange={(file) => setDocument("certification_document", file)} />
              </div>
            </div>

            <Button type="submit" disabled={savingProfile} className="mt-7 h-12 w-full rounded-xl bg-indigo-600 hover:bg-indigo-700 font-bold shadow-lg shadow-indigo-200">{savingProfile ? <Loader2 size={18} className="mr-2 animate-spin" /> : <Save size={18} className="mr-2" />} Simpan profil</Button>
          </form>

          <section className="rounded-[2rem] border border-slate-100 bg-white p-6 md:p-8 shadow-sm animate-in fade-in slide-in-from-right-3 duration-500 xl:sticky xl:top-24">
            <div className="flex items-start justify-between gap-4"><div><h2 className="text-2xl font-black text-slate-900">Kompetensi mengajar</h2><p className="mt-1 text-sm text-slate-500">Tutor memilih satu mapel sesuai bidang utama. Privat aktif secara bawaan; kelompok dapat diaktifkan dari pengaturan layanan.</p></div><div className="h-11 w-11 rounded-xl bg-violet-50 text-violet-600 flex items-center justify-center"><BookOpen size={20} /></div></div>

            <div className="mt-6 space-y-5">
              {profile.teachingSubjects.map((subject, index) => (
                <div key={`${index}-${subject.name}`} className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                  <div className="flex items-center justify-between gap-3">
                    <p className="font-black text-slate-800">Mata pelajaran {index + 1}</p>
                    <Button type="button" variant="outline" aria-label={`Hapus mata pelajaran ${index + 1}`} disabled={profile.teachingSubjects.length === 1} onClick={() => removeTeachingSubject(index)} className="h-9 rounded-xl border-rose-200 px-3 text-rose-600 hover:bg-rose-50">
                      <Trash2 size={15} />
                    </Button>
                  </div>

                  <div className="mt-4">
                    <SubjectCombobox
                      options={subjectOptions.filter((option) => option.name === subject.name || !profile.teachingSubjects.some((item, itemIndex) => itemIndex !== index && item.name === option.name))}
                      value={subject.name}
                      onChange={(value) => updateTeachingSubject(index, { name: value, levels: [] })}
                      placeholder="Cari mata pelajaran"
                    />
                  </div>

                  <div className="mt-4">
                    <Label className="mb-3 flex items-center gap-2 font-bold text-slate-700"><GraduationCap size={16} /> Jenjang yang dikuasai</Label>
                    <div className="grid grid-cols-2 gap-2">
                      {levels.map((level) => {
                        const active = subject.levels.includes(level);
                        return <button type="button" key={level} onClick={() => toggleSubjectLevel(index, level)} className={`h-11 rounded-xl border text-sm font-bold transition ${active ? "border-indigo-500 bg-indigo-50 text-indigo-700 shadow-sm" : "border-slate-200 bg-white text-slate-500 hover:border-indigo-200"}`}>{active && <Check size={14} className="mr-1.5 inline" />}{level}</button>;
                      })}
                    </div>
                  </div>

                  <div className="mt-4 space-y-3">
                    <ModeRow icon={BookOpen} title="Menerima Paket Privat" description="Tutor menerima permintaan bimbingan personal." checked={subject.is_private_active} onChange={(checked) => updateTeachingSubject(index, { is_private_active: checked })} />
                    <ModeRow icon={BookOpen} title="Menerima Kelas Bersama" description="Tutor menerima penugasan Kelas Bersama." checked={subject.is_group_active} onChange={(checked) => updateTeachingSubject(index, { is_group_active: checked })} />
                    <ModeRow icon={Monitor} title="Mengajar online" description="Kelas menggunakan ruang pertemuan daring." checked={subject.is_online} onChange={(checked) => updateTeachingSubject(index, { is_online: checked })} />
                    <ModeRow icon={Store} title="Mengajar offline" description="Tutor mendatangi alamat murid tanpa biaya perjalanan." checked={subject.is_offline} onChange={(checked) => updateTeachingSubject(index, { is_offline: checked })} />
                  </div>
                </div>
              ))}

              <Button type="button" variant="outline" onClick={addTeachingSubject} disabled className="hidden">
                <Plus size={16} className="mr-2" /> Tambah mata pelajaran (satu mapel utama)
              </Button>

              <div className="rounded-2xl border border-amber-100 bg-amber-50 p-4 flex items-start gap-3 text-amber-900"><MapPin size={18} className="mt-0.5 shrink-0" /><p className="text-sm leading-6">Sistem mengutamakan jarak dekat. Perubahan daftar kompetensi dapat memerlukan verifikasi ulang admin.</p></div>

              <Button type="button" onClick={saveSubject} disabled={savingSubject} className="h-12 w-full rounded-xl bg-slate-950 hover:bg-violet-700 font-bold">{savingSubject ? <Loader2 size={18} className="mr-2 animate-spin" /> : <Save size={18} className="mr-2" />} Simpan kompetensi</Button>
            </div>
          </section>
        </div>
      </div>
    </TeacherLayout>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return <div><Label className="mb-2 block font-bold text-slate-700">{label}</Label>{children}</div>;
}

function ModeRow({ icon: Icon, title, description, checked, onChange }: { icon: typeof Monitor; title: string; description: string; checked: boolean; onChange: (checked: boolean) => void }) {
  return <div className="rounded-2xl border border-slate-100 bg-slate-50 p-4 flex items-center justify-between gap-4"><div className="flex items-start gap-3"><div className="h-10 w-10 shrink-0 rounded-xl bg-white text-indigo-600 flex items-center justify-center"><Icon size={19} /></div><div><p className="font-bold text-slate-800">{title}</p><p className="mt-1 text-xs leading-5 text-slate-500">{description}</p></div></div><Switch checked={checked} onCheckedChange={onChange} /></div>;
}

function DocumentInput({ label, icon: Icon, current, accept, capture, onChange }: { label: string; icon: typeof IdCard; current?: string; accept: string; capture?: "user" | "environment"; onChange: (file: File | null) => void }) {
  return (
    <div className="rounded-xl border border-dashed border-slate-200 bg-white p-3">
      <div className="flex items-center gap-3">
        <Icon size={18} className="text-indigo-600" />
        <div className="min-w-0">
          <p className="text-xs font-bold text-slate-700">{label}</p>
          <p className="truncate text-[11px] text-slate-400">{current ? "Berkas tersimpan" : "Belum ada berkas"}</p>
        </div>
      </div>
      <div className="mt-3 grid grid-cols-2 gap-2">
        {current ? (
          <button
            type="button"
            className="inline-flex h-9 items-center justify-center rounded-lg border border-indigo-100 bg-indigo-50 text-xs font-bold text-indigo-700 hover:bg-indigo-100"
            onClick={() => void openProtectedFile(current, label).catch(() => notify.error("Dokumen tidak dapat dibuka."))}
          >
            <ScanSearch size={14} className="mr-1.5" />Lihat
          </button>
        ) : null}
        <label className={`inline-flex h-9 cursor-pointer items-center justify-center rounded-lg border border-slate-200 text-xs font-bold text-slate-600 hover:border-indigo-200 hover:text-indigo-700 ${current ? "" : "col-span-2"}`}>
          {current ? "Ganti" : "Pilih"}
          <Input type="file" accept={accept} capture={capture} className="hidden" onChange={(event) => onChange(event.target.files?.[0] || null)} />
        </label>
      </div>
    </div>
  );
}
