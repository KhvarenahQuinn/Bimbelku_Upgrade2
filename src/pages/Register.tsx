import { notify } from "@/lib/notify";
import { FormEvent, lazy, Suspense, useEffect, useRef, useState } from "react";
import { Link, useLocation, useNavigate, useSearchParams } from "react-router-dom";
import {
  ArrowLeft,
  ArrowRight,
  BookOpen,
  BriefcaseBusiness,
  CalendarDays,
  Check,
  Eye,
  EyeOff,
  FileBadge,
  FileCheck2,
  GraduationCap,
  Loader2,
  Lock,
  Mail,
  MapPin,
  ShieldCheck,
  Upload,
  User,
  UserRoundCheck,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import DateOfBirthInput from "@/components/DateOfBirthInput";
import { useWebsiteContent } from "@/components/WebsiteContentProvider";
import { whatsappHref } from "@/lib/websiteContent";
import GoogleAuthButton from "@/components/GoogleAuthButton";
import AuthExperience from "@/components/AuthExperience";


const CameraCapture = lazy(() => import("@/components/CameraCapture"));
const SubjectCombobox = lazy(() => import("@/components/SubjectCombobox"));
import type { SubjectOption } from "@/components/SubjectCombobox";
import { EDUCATION_LEVELS, GRADES_BY_EDUCATION_LEVEL } from "@/lib/educationCatalog";
import http, { getApiError, getApiValidationErrors, getCached } from "@/lib/http";
import {
  isValidHttpUrl,
  isValidPersonName,
  isValidPhone,
  sanitizePersonName,
  sanitizePhoneInput,
  validateUpload,
} from "@/lib/validation";

type Role = "student" | "teacher";
type FileKey = "identity_document" | "live_selfie" | "qualification_document" | "certification_document";

const fallbackSubjects: SubjectOption[] = [
  "Matematika", "Bahasa Indonesia", "Bahasa Inggris", "IPA", "IPS",
  "Fisika", "Kimia", "Biologi", "Ekonomi", "Akuntansi",
].map((name, index) => ({ id: -(index + 1), name }));

const initialForm = {
  name: "",
  email: "",
  phone: "",
  password: "",
  password_confirmation: "",
  school_name: "",
  student_education_level: "",
  grade: "",
  date_of_birth: "",
  guardian_name: "",
  guardian_phone: "",
  guardian_relationship: "",
  address: "",
  maps_link: "",
  expertise: "",
  public_degree: "",
  linkedin: "",
  teaching_method: "hybrid",
};

export default function Register({ role = "student" }: { role?: Role }) {
  const navigate = useNavigate();
  const location = useLocation();
  const [searchParams] = useSearchParams();
  const guestEmail = typeof (location.state as { email?: unknown } | null)?.email === "string"
    ? String((location.state as { email?: string }).email)
    : "";
  const requestedRedirect = searchParams.get("redirect");
  const safeRedirect = requestedRedirect?.startsWith("/") && !requestedRedirect.startsWith("//")
    ? requestedRedirect
    : null;
  const loginHref = safeRedirect
    ? `/login?redirect=${encodeURIComponent(safeRedirect)}`
    : "/login";
  const { settings } = useWebsiteContent();

  const [form, setForm] = useState(() => ({ ...initialForm, email: searchParams.get("email") || guestEmail }));
  const [levels, setLevels] = useState<string[]>([]);
  const [files, setFiles] = useState<Partial<Record<FileKey, File>>>({});
  const [terms, setTerms] = useState(false);
  const [privacy, setPrivacy] = useState(false);
  const [guardianConsent, setGuardianConsent] = useState(false);
  const [publicProfileConsent, setPublicProfileConsent] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const submittingRef = useRef(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [summaryError, setSummaryError] = useState("");
  const [subjects, setSubjects] = useState<SubjectOption[]>(fallbackSubjects);
  const [step, setStep] = useState(1);
  const isMinorStudent = role === "student" && isUnderEighteen(form.date_of_birth);
  const today = localDateInputValue(new Date());
  const totalSteps = 3;
  const stepLabels = role === "teacher"
    ? ["Akun", "Keahlian", "Verifikasi"]
    : ["Akun", "Profil", "Konfirmasi"];

  useEffect(() => {
    let active = true;
    void getCached<{ subject_options?: SubjectOption[] }>("/learning-catalog", {
      params: { compact: 1 },
      maxAgeMs: 5 * 60_000,
    })
      .then((response) => {
        const available = response.data.subject_options?.filter((item) => item.name);
        if (active && available?.length) setSubjects(available);
      })
      .catch(() => {
        // Daftar bawaan tetap dapat dipakai saat API katalog belum aktif.
      });

    return () => {
      active = false;
    };
  }, []);

  const setValue = (key: keyof typeof form, value: string) => {
    setForm((current) => ({ ...current, [key]: value }));
    setErrors((current) => ({ ...current, [key]: "" }));
  };
  const setFile = (key: FileKey, file?: File) => {
    const isLiveSelfie = key === "live_selfie";
    const error = validateUpload(file, {
      label: isLiveSelfie ? "Foto wajah langsung" : "Dokumen verifikasi",
      maxSizeMb: 5,
      extensions: isLiveSelfie ? ["jpg", "jpeg", "png", "webp"] : ["jpg", "jpeg", "png", "webp", "pdf"],
    });
    if (error) {
      notify.error(error);
      setErrors((current) => ({ ...current, [key]: error }));
      setFiles((current) => ({ ...current, [key]: undefined }));
      return;
    }
    setErrors((current) => ({ ...current, [key]: "" }));
    setFiles((current) => ({ ...current, [key]: file }));
  };

  const toggleLevel = (level: string) => {
    setErrors((current) => ({ ...current, levels: "" }));
    setLevels((current) => current.includes(level) ? current.filter((item) => item !== level) : [...current, level]);
  };

  const accountErrors = () => {
    const nextErrors: Record<string, string> = {};
    if (!isValidPersonName(form.name)) nextErrors.name = "Nama lengkap harus berisi huruf dan tidak boleh memuat angka.";
    if (!/^\S+@\S+\.\S+$/.test(form.email.trim())) nextErrors.email = "Masukkan alamat email yang valid.";
    if (!isValidPhone(form.phone)) nextErrors.phone = "Nomor WhatsApp/telepon harus berisi 8–15 angka.";
    if (form.password.length < 8) nextErrors.password = "Kata sandi minimal 8 karakter.";
    if (form.password !== form.password_confirmation) nextErrors.password_confirmation = "Konfirmasi kata sandi belum sama.";
    return nextErrors;
  };

  const profileErrors = () => {
    const nextErrors: Record<string, string> = {};
    if (role === "student") {
      if (!form.date_of_birth) nextErrors.date_of_birth = "Tanggal lahir murid wajib diisi.";
      if (isMinorStudent && !isValidPersonName(form.guardian_name)) nextErrors.guardian_name = "Nama orang tua atau wali harus berisi huruf dan tidak boleh memuat angka.";
      if (isMinorStudent && !isValidPhone(form.guardian_phone)) nextErrors.guardian_phone = "Nomor orang tua atau wali harus berisi 8–15 angka.";
      if (isMinorStudent && !form.guardian_relationship) nextErrors.guardian_relationship = "Pilih hubungan orang tua atau wali dengan murid.";
      if (isMinorStudent && !guardianConsent) nextErrors.guardian_consent = "Persetujuan orang tua atau wali wajib diberikan.";
    } else {
      if (!form.expertise) nextErrors.expertise = "Pilih satu mata pelajaran utama.";
      if (!form.public_degree.trim()) nextErrors.public_degree = "Gelar atau pendidikan terakhir wajib diisi.";
      if (levels.length === 0) nextErrors.levels = "Pilih minimal satu jenjang yang dapat diajar.";
      if (!isValidHttpUrl(form.linkedin)) nextErrors.linkedin = "Tautan LinkedIn atau portofolio belum valid.";
    }
    return nextErrors;
  };

  const confirmationErrors = () => {
    const nextErrors: Record<string, string> = {};
    if (role === "student" && !isValidHttpUrl(form.maps_link)) nextErrors.maps_link = "Tautan Google Maps harus diawali http:// atau https://.";
    if (role === "teacher") {
      if (!files.identity_document) nextErrors.identity_document = "Kartu identitas wajib dilengkapi.";
      if (!files.live_selfie) nextErrors.live_selfie = "Foto wajah langsung wajib dilengkapi.";
      if (!files.qualification_document) nextErrors.qualification_document = "Ijazah atau dokumen kualifikasi wajib dilengkapi.";
    }
    if (!terms) nextErrors.terms_accepted = "Persetujuan syarat dan ketentuan wajib diberikan.";
    if (!privacy) nextErrors.privacy_accepted = "Persetujuan kebijakan privasi wajib diberikan.";
    return nextErrors;
  };

  const focusFirstError = (nextErrors: Record<string, string>) => {
    window.requestAnimationFrame(() => {
      document.getElementById(`register-${Object.keys(nextErrors)[0]?.replaceAll("_", "-")}`)?.focus();
    });
  };

  const goToNextStep = () => {
    const nextErrors = step === 1 ? accountErrors() : profileErrors();
    if (Object.keys(nextErrors).length) {
      setErrors((current) => ({ ...current, ...nextErrors }));
      setSummaryError("Lengkapi isian pada langkah ini sebelum melanjutkan.");
      focusFirstError(nextErrors);
      return;
    }
    setErrors({});
    setSummaryError("");
    setStep((current) => Math.min(totalSteps, current + 1));
    document.querySelector(".auth-form-scroll")?.scrollTo({ top: 0, behavior: "smooth" });
  };

  const goToPreviousStep = () => {
    setErrors({});
    setSummaryError("");
    setStep((current) => Math.max(1, current - 1));
    document.querySelector(".auth-form-scroll")?.scrollTo({ top: 0, behavior: "smooth" });
  };


  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (submittingRef.current) return;
    const nextErrors: Record<string, string> = {
      ...accountErrors(),
      ...profileErrors(),
      ...confirmationErrors(),
    };
    if (Object.keys(nextErrors).length) {
      setErrors(nextErrors);
      setSummaryError("Periksa kembali isian yang ditandai sebelum melanjutkan.");
      const firstError = Object.keys(nextErrors)[0];
      const accountKeys = ["name", "email", "phone", "password", "password_confirmation"];
      const profileKeys = role === "teacher"
        ? ["expertise", "public_degree", "levels", "linkedin"]
        : ["date_of_birth", "guardian_name", "guardian_phone", "guardian_relationship", "guardian_consent"];
      setStep(accountKeys.includes(firstError) ? 1 : profileKeys.includes(firstError) ? 2 : 3);
      focusFirstError(nextErrors);
      return;
    }
    setErrors({});
    setSummaryError("");

    const payload = new FormData();
    payload.append("role", role);
    payload.append("terms_accepted", terms ? "1" : "0");
    payload.append("privacy_accepted", privacy ? "1" : "0");
    if (isMinorStudent) payload.append("guardian_consent", guardianConsent ? "1" : "0");
    Object.entries(form).forEach(([key, value]) => payload.append(key, value));
    if (role === "teacher") {
      payload.append("public_profile_consent", publicProfileConsent ? "1" : "0");
      levels.forEach((level, index) => payload.append(`levels[${index}]`, level));
      Object.entries(files).forEach(([key, file]) => file && payload.append(key, file));
    }

    setSubmitting(true);
    submittingRef.current = true;
    try {
      const response = await http.post("/register", payload);
      notify.success(response.data.message);
      if (role === "teacher") {
        const methodLabel = (value?: string | null) => value === "online"
          ? "Online"
          : value === "offline"
            ? "Tatap muka"
            : "Online dan tatap muka";
        // Ringkasan berasal dari server agar hanya data tersimpan yang dikirim.
        // Email, kata sandi, dan dokumen privat tidak pernah masuk pesan.
        const summary = response.data?.registration_summary && typeof response.data.registration_summary === "object"
          ? response.data.registration_summary as Record<string, unknown>
          : null;
        const applicationCode = String(summary?.application_code || response.data?.application_code || "");
        const summaryLevels = Array.isArray(summary?.levels)
          ? summary.levels.map((level) => String(level)).filter(Boolean)
          : levels;
        const summaryLines = [
          summary ? `Nama: ${String(summary.name || form.name).trim()}` : `Nama: ${form.name.trim()}`,
          `Nomor WhatsApp: ${String(summary?.whatsapp || form.phone).trim()}`,
          `Mata pelajaran utama: ${String(summary?.subject || form.expertise)}`,
          `Gelar/pendidikan terakhir: ${String(summary?.degree || form.public_degree).trim()}`,
          `Jenjang yang diajar: ${summaryLevels.join(", ")}`,
          `Metode mengajar: ${methodLabel(String(summary?.teaching_method || form.teaching_method))}`,
          String(summary?.linkedin || form.linkedin).trim() ? `LinkedIn/portofolio: ${String(summary?.linkedin || form.linkedin).trim()}` : null,
        ].filter(Boolean);
        const message = [
          `Halo ${settings.brand_name || "BimbelKu"}, saya sudah mengisi dan mengirim formulir pendaftaran tutor.`,
          "",
          applicationCode ? `Kode pendaftaran: ${applicationCode}` : null,
          ...summaryLines,
          "Dokumen identitas, foto wajah langsung, dan ijazah/kualifikasi sudah dikirim melalui formulir.",
          "",
          "Mohon lanjutkan proses seleksi dan tes tutor.",
        ].filter(Boolean).join("\n");
        const selectionUrl = settings.whatsapp_enabled
          ? whatsappHref(settings.whatsapp_number, message)
          : null;

        try {
          sessionStorage.setItem("bimbelku_teacher_registration", JSON.stringify({
            id: applicationCode || null,
            email: form.email.trim().toLowerCase(),
            submitted_at: new Date().toISOString(),
          }));
        } catch {
          // Penyimpanan lokal tidak boleh menghambat kelanjutan seleksi.
        }

        if (selectionUrl) {
          window.location.assign(selectionUrl);
          return;
        }
        notify.warning("Data tutor sudah tersimpan. WhatsApp belum tersedia; lanjutkan verifikasi email.");
      }
      if (response.data.requires_email_verification) {
        const email = String(response.data.email || form.email);
        const verifyParams = new URLSearchParams({ email });
        if (safeRedirect) verifyParams.set("redirect", safeRedirect);
        navigate('/verify-email?' + verifyParams.toString(), {
          replace: true,
          state: {
            email,
            resendAfterSeconds: Number(response.data.resend_after_seconds || 0),
            redirect: safeRedirect,
          },
        });
        return;
      }
      navigate(loginHref, { replace: true });
    } catch (error) {
      const message = getApiError(error, "Pendaftaran gagal. Periksa kembali data Anda.");
      setErrors(getApiValidationErrors(error));
      setSummaryError(message);
      notify.error(message);
    } finally {
      submittingRef.current = false;
      setSubmitting(false);
    }
  };

  return (
    <AuthExperience mode="register" role={role}>
      <section className="min-w-0">
        <div className="flex items-start justify-between gap-4">
          <div><p className="text-xs font-black uppercase tracking-[.2em] text-orange-600">Buat akun</p><h2 className="mt-2 text-3xl font-black text-slate-900">{role === "teacher" ? "Daftar jadi tutor" : "Mulai bersama BimbelKu"}</h2><p className="mt-2 text-sm text-slate-500">Sudah punya akun? <Link to={loginHref} state={guestEmail ? { email: guestEmail } : undefined} className="font-bold text-indigo-600">Masuk melalui halaman akun yang sama</Link></p></div>
        </div>

        <ol className="mt-6 grid grid-cols-3 gap-2" aria-label={`Langkah pendaftaran, langkah ${step} dari ${totalSteps}`}>
          {stepLabels.map((label, index) => {
            const number = index + 1;
            const complete = number < step;
            const active = number === step;
            return <li key={label} className="relative min-w-0"><div className={`h-1.5 rounded-full ${number <= step ? "bg-orange-500" : "bg-slate-200"}`} /><div className={`mt-2 flex items-center gap-2 text-[11px] font-black sm:text-xs ${active ? "text-orange-700" : complete ? "text-teal-700" : "text-slate-400"}`}><span className={`grid h-6 w-6 shrink-0 place-items-center rounded-full ${active ? "bg-orange-100 text-orange-700" : complete ? "bg-teal-100 text-teal-700" : "bg-slate-100"}`}>{complete ? <Check size={14} strokeWidth={3} /> : number}</span><span className="truncate">{label}</span></div></li>;
          })}
        </ol>

        {role === "student" && step === 1 && <div className="mt-6 rounded-2xl border border-orange-100 bg-orange-50/50 p-4"><GoogleAuthButton redirect={safeRedirect} /><p className="mt-3 text-center text-xs leading-5 text-slate-500">Google memverifikasi email. Data lain dapat dilengkapi setelah masuk melalui Profil.</p></div>}
        {role === "student" && step === 1 && <div className="my-5 flex items-center gap-3 text-xs font-bold uppercase tracking-wider text-slate-400"><span className="h-px flex-1 bg-slate-200" /><span>atau daftar dengan email</span><span className="h-px flex-1 bg-slate-200" /></div>}
        {summaryError && <p role="alert" aria-live="assertive" className="mt-5 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">{summaryError}</p>}

        <form onSubmit={step === totalSteps ? submit : (event) => { event.preventDefault(); goToNextStep(); }} className="auth-register-form mt-6 w-full min-w-0 space-y-6">
          {step === 1 && <div className="space-y-5">
            <div className="grid gap-4 sm:grid-cols-2">
              <FormField label="Nama lengkap" icon={User} id="register-name"><Input id="register-name" name="name" autoComplete="name" required aria-invalid={Boolean(errors.name)} aria-describedby={errors.name ? "register-name-error" : undefined} className="h-12 rounded-xl" value={form.name} onChange={(event) => setValue("name", sanitizePersonName(event.target.value))} /><FieldError id="register-name-error" message={errors.name} /></FormField>
              <FormField label="Email aktif" icon={Mail} id="register-email"><Input id="register-email" name="email" autoComplete="email" required type="email" aria-invalid={Boolean(errors.email)} aria-describedby={errors.email ? "register-email-error" : undefined} className="h-12 rounded-xl" value={form.email} onChange={(event) => setValue("email", event.target.value)} /><FieldError id="register-email-error" message={errors.email} /></FormField>
            </div>
            <FormField label="Nomor WhatsApp/telepon aktif" icon={User} id="register-phone"><Input id="register-phone" name="phone" required inputMode="tel" autoComplete="tel" maxLength={16} aria-invalid={Boolean(errors.phone)} aria-describedby={errors.phone ? "register-phone-error" : undefined} className="h-12 rounded-xl" value={form.phone} onChange={(event) => setValue("phone", sanitizePhoneInput(event.target.value))} placeholder="Contoh: 0812 3456 7890" /><FieldError id="register-phone-error" message={errors.phone} /></FormField>
            <div className="grid gap-4 sm:grid-cols-2">
              <FormField label="Kata sandi" icon={Lock} id="register-password"><div className="relative"><Input id="register-password" required minLength={8} autoComplete="new-password" type={showPassword ? "text" : "password"} aria-invalid={Boolean(errors.password)} aria-describedby={errors.password ? "register-password-error" : undefined} className="h-12 rounded-xl pr-12" value={form.password} onChange={(event) => setValue("password", event.target.value)} placeholder="Minimal 8 karakter" /><button type="button" onClick={() => setShowPassword((value) => !value)} className="absolute inset-y-0 right-0 px-4 text-slate-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-500" aria-controls="register-password" aria-label={showPassword ? "Sembunyikan kata sandi" : "Tampilkan kata sandi"}>{showPassword ? <EyeOff size={18} /> : <Eye size={18} />}</button></div><FieldError id="register-password-error" message={errors.password} /></FormField>
              <FormField label="Ulangi kata sandi" icon={Lock} id="register-password-confirmation"><Input id="register-password-confirmation" required minLength={8} autoComplete="new-password" type={showPassword ? "text" : "password"} aria-invalid={Boolean(errors.password_confirmation)} aria-describedby={errors.password_confirmation ? "register-password-confirmation-error" : undefined} className="h-12 rounded-xl" value={form.password_confirmation} onChange={(event) => setValue("password_confirmation", event.target.value)} placeholder="Harus sama" /><FieldError id="register-password-confirmation-error" message={errors.password_confirmation} /></FormField>
            </div>
          </div>}

          {step === 2 && role === "student" && <div className="space-y-5 rounded-2xl border border-orange-100 bg-orange-50/40 p-4 sm:p-5">
            <div className="grid gap-4 sm:grid-cols-2">
              <FormField label="Sekolah" icon={GraduationCap}><Input className="h-12 rounded-xl bg-white" value={form.school_name} onChange={(event) => setValue("school_name", event.target.value)} placeholder="Opsional" /></FormField>
              <FormField label="Jenjang" icon={BookOpen}><Select value={form.student_education_level} onValueChange={(value) => { setValue("student_education_level", value); setValue("grade", ""); }}><SelectTrigger className="h-12 rounded-xl bg-white"><SelectValue placeholder="Pilih jenjang" /></SelectTrigger><SelectContent>{EDUCATION_LEVELS.map((item) => <SelectItem key={item} value={item}>{item}</SelectItem>)}</SelectContent></Select></FormField>
              <FormField label={form.student_education_level === "Umum" ? "Tingkat" : "Kelas"} icon={GraduationCap}><Select disabled={!form.student_education_level} value={form.grade} onValueChange={(value) => setValue("grade", value)}><SelectTrigger className="h-12 rounded-xl bg-white"><SelectValue placeholder={form.student_education_level ? "Pilih kelas/tingkat" : "Pilih jenjang dahulu"} /></SelectTrigger><SelectContent>{(GRADES_BY_EDUCATION_LEVEL[form.student_education_level] || []).map((item) => <SelectItem key={item} value={item}>{item}</SelectItem>)}</SelectContent></Select></FormField>
              <FormField label="Tanggal lahir murid" icon={CalendarDays} id="register-date-of-birth"><DateOfBirthInput value={form.date_of_birth} max={today} onChange={(value) => setValue("date_of_birth", value)} className="h-12 rounded-xl bg-white font-bold tracking-wide" /><FieldError message={errors.date_of_birth} /></FormField>
            </div>
            {isMinorStudent && <div className="space-y-4 rounded-2xl border border-amber-200 bg-amber-50 p-4"><div className="flex gap-3 text-sm leading-6 text-amber-900"><UserRoundCheck className="mt-0.5 shrink-0" size={20} /><p>Murid di bawah 18 tahun memerlukan data dan persetujuan orang tua atau wali.</p></div><div className="grid gap-4 sm:grid-cols-2"><FormField label="Nama orang tua/wali" icon={User} id="register-guardian-name"><Input id="register-guardian-name" required className="h-12 rounded-xl bg-white" value={form.guardian_name} onChange={(event) => setValue("guardian_name", sanitizePersonName(event.target.value))} /><FieldError message={errors.guardian_name} /></FormField><FormField label="Nomor orang tua/wali" icon={UserRoundCheck} id="register-guardian-phone"><Input id="register-guardian-phone" required inputMode="tel" autoComplete="tel" maxLength={16} className="h-12 rounded-xl bg-white" value={form.guardian_phone} onChange={(event) => setValue("guardian_phone", sanitizePhoneInput(event.target.value))} placeholder="Contoh: 0812 3456 7890" /><FieldError message={errors.guardian_phone} /></FormField></div><FormField label="Hubungan dengan murid" icon={UserRoundCheck}><Select value={form.guardian_relationship} onValueChange={(value) => setValue("guardian_relationship", value)}><SelectTrigger className="h-12 rounded-xl bg-white"><SelectValue placeholder="Pilih hubungan" /></SelectTrigger><SelectContent><SelectItem value="orang_tua">Orang tua</SelectItem><SelectItem value="wali_keluarga">Wali keluarga</SelectItem><SelectItem value="wali_resmi">Wali resmi lainnya</SelectItem></SelectContent></Select><FieldError message={errors.guardian_relationship} /></FormField><Consent checked={guardianConsent} onChange={setGuardianConsent}>Saya menyatakan orang tua atau wali telah menyetujui pendaftaran dan penggunaan layanan BimbelKu.</Consent><FieldError message={errors.guardian_consent} /></div>}
          </div>}

          {step === 2 && role === "teacher" && <div className="space-y-5 rounded-2xl border border-indigo-100 bg-indigo-50/40 p-4 sm:p-5">
            <div className="rounded-xl border border-indigo-100 bg-white p-4 text-sm leading-6 text-indigo-900"><div className="flex gap-3"><ShieldCheck className="mt-0.5 shrink-0" size={20} /><div><p className="font-black">Ceritakan bidang ajar Anda.</p><p className="mt-1 text-indigo-800">Data ini dipakai admin untuk menilai kecocokan tutor sebelum tes melalui WhatsApp.</p></div></div></div>
            <div className="grid gap-4 sm:grid-cols-2"><FormField label="Satu mata pelajaran" icon={BookOpen}><Suspense fallback={<div className="h-12 rounded-xl bg-white" aria-hidden="true" />}><SubjectCombobox options={subjects} value={form.expertise} onChange={(value) => setValue("expertise", value)} placeholder="Cari mapel utama" className="bg-white" /></Suspense><FieldError message={errors.expertise} /></FormField><FormField label="Metode mengajar" icon={BriefcaseBusiness}><Select value={form.teaching_method} onValueChange={(value) => setValue("teaching_method", value)}><SelectTrigger className="h-12 rounded-xl bg-white"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="online">Online</SelectItem><SelectItem value="offline">Offline</SelectItem><SelectItem value="hybrid">Online & offline</SelectItem></SelectContent></Select></FormField></div>
            <FormField label="Gelar atau pendidikan terakhir" icon={GraduationCap} id="register-public-degree"><Input id="register-public-degree" required maxLength={120} aria-invalid={Boolean(errors.public_degree)} className="h-12 rounded-xl bg-white" value={form.public_degree} onChange={(event) => setValue("public_degree", event.target.value)} placeholder="Contoh: S.Pd. / Mahasiswa S1 Pendidikan Matematika" /><FieldError message={errors.public_degree} /><p className="mt-1 text-xs text-slate-500">Tuliskan sesuai ijazah atau status pendidikan saat ini. Tidak harus sudah memiliki gelar sarjana.</p></FormField>
            <div><Label className="font-bold text-slate-700">Jenjang yang dapat diajar</Label><div className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">{EDUCATION_LEVELS.map((level) => <button type="button" key={level} onClick={() => toggleLevel(level)} className={`min-w-0 break-words rounded-xl border px-3 py-2 text-sm font-bold transition ${levels.includes(level) ? "border-indigo-600 bg-indigo-600 text-white" : "border-slate-200 bg-white text-slate-600"}`}>{level}</button>)}</div><FieldError message={errors.levels} /></div>
            <Input id="register-linkedin" type="url" aria-invalid={Boolean(errors.linkedin)} className="h-12 rounded-xl bg-white" value={form.linkedin} onChange={(event) => setValue("linkedin", event.target.value)} placeholder="LinkedIn atau portofolio, opsional" /><FieldError message={errors.linkedin} />
          </div>}

          {step === 3 && <div className="space-y-5">
            {role === "student" ? <div className="space-y-4 rounded-2xl border border-orange-100 bg-orange-50/40 p-4 sm:p-5"><div><p className="font-black text-slate-900">Lokasi belajar</p><p className="mt-1 text-xs leading-5 text-slate-500">Boleh dilewati sekarang dan dilengkapi saat memesan kelas tatap muka.</p></div><FormField label="Alamat rumah" icon={MapPin}><Textarea className="min-h-20 rounded-xl bg-white" value={form.address} onChange={(event) => setValue("address", event.target.value)} placeholder="Opsional saat daftar" /></FormField><Input id="register-maps-link" type="url" aria-invalid={Boolean(errors.maps_link)} className="h-12 rounded-xl bg-white" value={form.maps_link} onChange={(event) => setValue("maps_link", event.target.value)} placeholder="Tautan Google Maps, opsional" /><FieldError message={errors.maps_link} /></div> : <div className="space-y-5 rounded-2xl border border-indigo-100 bg-indigo-50/40 p-4 sm:p-5"><div className="rounded-xl border border-indigo-100 bg-white p-4 text-sm leading-6 text-indigo-900"><div className="flex gap-3"><ShieldCheck className="mt-0.5 shrink-0" size={20} /><div><p className="font-black">Dokumen diperiksa oleh admin.</p><p className="mt-1 text-indigo-800">Setelah data tersimpan, WhatsApp terbuka untuk melanjutkan seleksi dan tes.</p></div></div></div><div className="grid gap-3 sm:grid-cols-2"><div id="register-identity-document"><FileInput required label="Kartu identitas" icon={FileCheck2} file={files.identity_document} accept=".jpg,.jpeg,.png,.webp,.pdf" onChange={(file) => setFile("identity_document", file)} /><FieldError message={errors.identity_document} /></div><div id="register-live-selfie"><Suspense fallback={<div className="h-24 rounded-xl bg-white" aria-hidden="true" />}><CameraCapture required file={files.live_selfie} onCapture={(file) => setFile("live_selfie", file)} /></Suspense><FieldError message={errors.live_selfie} /></div><div id="register-qualification-document"><FileInput required label="Ijazah/kualifikasi" icon={GraduationCap} file={files.qualification_document} accept=".jpg,.jpeg,.png,.webp,.pdf" onChange={(file) => setFile("qualification_document", file)} /><FieldError message={errors.qualification_document} /></div><FileInput label="Sertifikat pendukung" icon={FileBadge} file={files.certification_document} accept=".jpg,.jpeg,.png,.webp,.pdf" onChange={(file) => setFile("certification_document", file)} /></div></div>}
            {role === "teacher" && <div className="rounded-2xl border border-teal-200 bg-teal-50 p-4 text-sm leading-6 text-teal-950"><Consent checked={publicProfileConsent} onChange={setPublicProfileConsent}>Saya mengizinkan nama, foto profil, mapel yang diajar, dan ringkasan pendidikan/karier saya ditampilkan di halaman Kenali Tutor setelah akun terverifikasi dan disetujui admin.</Consent><p className="mt-2 text-xs text-teal-800">Opsional. Nomor kontak, alamat rinci, dan dokumen verifikasi tidak ditampilkan. Izin ini bisa dicabut melalui Profil Tutor.</p></div>}
            <div className="space-y-3 rounded-2xl border border-slate-200 bg-white p-4"><Consent checked={terms} onChange={(value) => { setTerms(value); setErrors((current) => ({ ...current, terms_accepted: "" })); }}>Saya menyetujui <Link to="/terms" state={{ from: "/register" }} className="font-bold text-indigo-600">Syarat dan Ketentuan</Link>.</Consent><Consent checked={privacy} onChange={(value) => { setPrivacy(value); setErrors((current) => ({ ...current, privacy_accepted: "" })); }}>Saya menyetujui <Link to="/privacy" state={{ from: "/register" }} className="font-bold text-indigo-600">Kebijakan Privasi</Link>.</Consent><FieldError message={errors.terms_accepted || errors.privacy_accepted} /></div>
          </div>}

          <div className="flex gap-3 border-t border-slate-200 pt-5">
            {step > 1 && <Button type="button" variant="outline" onClick={goToPreviousStep} className="h-12 min-w-0 flex-1 rounded-2xl border-slate-300 font-black"><ArrowLeft className="mr-2" size={18} />Kembali</Button>}
            {step < totalSteps ? <Button type="button" onClick={goToNextStep} className="h-12 min-w-0 flex-1 rounded-2xl bg-[#14213D] font-black hover:bg-slate-800">Lanjutkan<ArrowRight className="ml-2" size={18} /></Button> : <Button disabled={submitting} className="h-12 min-w-0 flex-1 rounded-2xl bg-[#14213D] font-black hover:bg-slate-800"><span className="inline-flex items-center justify-center">{submitting ? <Loader2 className="mr-2 animate-spin" size={19} /> : <ArrowRight className="mr-2" size={19} />}{submitting ? "Memproses…" : role === "teacher" ? "Simpan & lanjut WhatsApp" : "Buat akun murid"}</span></Button>}
          </div>
        </form>
        {role === "student" && <p className="mt-6 text-center text-sm text-slate-500">Ingin menjadi tutor? <Link to="/jadi-tutor" className="font-bold text-indigo-700 hover:underline">Lihat pendaftaran tutor</Link></p>}
      </section>
    </AuthExperience>
  );
}

function isUnderEighteen(value: string) {
  if (!value) return false;
  const birthDate = new Date(`${value}T00:00:00`);
  if (Number.isNaN(birthDate.getTime())) return false;
  const today = new Date();
  let age = today.getFullYear() - birthDate.getFullYear();
  const monthDifference = today.getMonth() - birthDate.getMonth();
  if (monthDifference < 0 || (monthDifference === 0 && today.getDate() < birthDate.getDate())) age -= 1;
  return age < 18;
}

function localDateInputValue(date: Date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

function FormField({ label, icon: Icon, children, id }: { label: string; icon: typeof User; children: React.ReactNode; id?: string }) {
  return <div className="min-w-0 max-w-full"><Label htmlFor={id} className="mb-2 flex min-w-0 items-center gap-2 font-bold text-slate-700"><Icon className="shrink-0" size={16} /><span className="min-w-0 break-words">{label}</span></Label>{children}</div>;
}

function FieldError({ id, message }: { id?: string; message?: string }) {
  return message ? <p id={id} className="mt-1 text-xs font-semibold text-red-700">{message}</p> : null;
}

function FileInput({ label, icon: Icon, file, accept, capture, required, onChange }: { label: string; icon: typeof Upload; file?: File; accept: string; capture?: "user" | "environment"; required?: boolean; onChange: (file?: File) => void }) {
  return <label className="flex min-h-24 w-full min-w-0 max-w-full cursor-pointer items-center gap-3 overflow-hidden rounded-xl border border-dashed border-indigo-200 bg-white p-3 hover:border-indigo-400"><Icon className="shrink-0 text-indigo-600" size={20} /><span className="min-w-0 flex-1"><span className="block break-words text-xs font-bold text-slate-700">{label}{required ? " *" : ""}</span><span className="mt-1 block max-w-full truncate text-[11px] text-slate-400">{file?.name || "Pilih berkas · maks. 5 MB"}</span></span><Input type="file" accept={accept} capture={capture} className="hidden" onChange={(event) => onChange(event.target.files?.[0])} /></label>;
}

function Consent({ checked, onChange, children }: { checked: boolean; onChange: (value: boolean) => void; children: React.ReactNode }) {
  return <label className="flex min-w-0 cursor-pointer items-start gap-3 text-xs leading-5 text-slate-600"><Checkbox checked={checked} onCheckedChange={(value) => onChange(Boolean(value))} className="mt-0.5 shrink-0" /><span className="min-w-0 flex-1 break-words">{children}</span></label>;
}
