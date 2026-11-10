import { notify } from "@/lib/notify";
import { useState, useEffect, useRef } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import StudentLayout from "../../components/StudentLayout";
import { 
  AlertCircle, User, Mail, Save, Loader2, Camera, ShieldCheck, ImagePlus, CalendarDays, UserRoundCheck, RefreshCw, WifiOff,
  MapPin, FileText, HelpCircle, MessageCircle, Shield, ExternalLink, LocateFixed, ArrowLeft,
} from "lucide-react";
import { ResponsiveSelect } from "@/components/ResponsiveSelect";
import { formatDateOnly } from "@/lib/date";
import http, { getCached } from "@/lib/http";
import {
  isValidPersonName,
  isValidPhone,
  sanitizePersonName,
  sanitizePhoneInput,
  validateUpload,
} from "@/lib/validation";
import axios from "axios";
import { EDUCATION_LEVELS, GRADES_BY_EDUCATION_LEVEL } from "@/lib/educationCatalog";

const savedText = (value: unknown, fallback: string): string => {
  if (value === undefined) return fallback;
  if (value === null) return "";
  return String(value);
};

export default function Profile() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const locationSectionRef = useRef<HTMLDivElement>(null);
  const addressInputRef = useRef<HTMLTextAreaElement>(null);
  const [isLocating, setIsLocating] = useState(false);
  const locationSetupRequested = searchParams.get("section") === "location";
  const requestedReturnTo = searchParams.get("returnTo");
  const returnTo = requestedReturnTo?.startsWith("/") && !requestedReturnTo.startsWith("//")
    ? requestedReturnTo
    : null;

  // State User Data
  const [user, setUser] = useState({
    name: "",
    email: "",
    phone: "",
    avatar_url: "" as string | null,
    profile_cover_url: "" as string | null,
    has_password: true,
    password_updated_at: "" as string | null,
    student_birth_date: "" as string | null,
    student_education_level: "SD",
    grade: "Kelas 1",
    school_name: "",
    learning_needs: "",
    address: "",
    maps_link: "",
    latitude: "",
    longitude: "",
    location_consent_at: "" as string | null,
    guardian: null as null | {
      name: string;
      phone: string;
      relationship: string;
      consent_at: string;
    },
  });
  
  // State File Foto (Untuk Upload)
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [selectedCover, setSelectedCover] = useState<File | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const coverInputRef = useRef<HTMLInputElement>(null);
  const avatarPreviewUrlRef = useRef<string | null>(null);
  const coverPreviewUrlRef = useRef<string | null>(null);

  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // 1. FETCH DATA USER
  useEffect(() => {
    const fetchUser = async () => {
      setError(null);
      try {
        const [response, defaultCover] = await Promise.all([
          getCached("/user", { maxAgeMs: 60_000, force: true }),
          getCached<{ url?: string }>("/settings/teacher-cover", { maxAgeMs: 5 * 60_000 }).catch(() => ({ data: {} })),
        ]);
        
        // Asumsi response backend mengembalikan { name, email, avatar_url }
        // Jika backend mengirim 'photo' atau 'avatar', sesuaikan di sini
        const userData = response.data;
        setUser({
            name: sanitizePersonName(userData.name || ""),
            email: userData.email,
            phone: sanitizePhoneInput(userData.phone || ""),
            avatar_url: userData.avatar_url || userData.photo_url || null,
            profile_cover_url: userData.profile_cover_url || defaultCover.data?.url || null,
            has_password: userData.has_password !== false,
            password_updated_at: userData.password_updated_at || null,
            student_birth_date: userData.student_birth_date || null,
            student_education_level: userData.student_education_level || "SD",
            grade: userData.grade || "Kelas 1",
            school_name: userData.school_name || "",
            learning_needs: userData.learning_needs || "",
            address: userData.address || "",
            maps_link: userData.maps_link || "",
            latitude: userData.latitude?.toString() || "",
            longitude: userData.longitude?.toString() || "",
            location_consent_at: userData.location_consent_at || null,
            guardian: userData.guardian || null,
        });

      } catch (err) {
        console.error("Gagal load profile:", err);
        if (axios.isAxiosError(err)) {
          if (!err.response) {
            setError("network");
          } else if (err.response.status === 401) {
            setError("unauthorized");
          } else if (err.response.status === 403) {
            setError("forbidden");
          } else if (err.response.status === 404) {
            setError("not_found");
          } else {
            setError("generic");
          }
        } else {
          setError("generic");
        }
        notify.error("Gagal memuat data profil.");
      } finally {
        setIsLoading(false);
      }
    };
    fetchUser();

    return () => {
      if (avatarPreviewUrlRef.current) URL.revokeObjectURL(avatarPreviewUrlRef.current);
      if (coverPreviewUrlRef.current) URL.revokeObjectURL(coverPreviewUrlRef.current);
    };
  }, []);

  const retryLoad = () => {
    setError(null);
    setIsLoading(true);
    // Trigger re-fetch by remounting via key trick isn't needed;
    // call fetchUser directly via a helper
    const fetchUser = async () => {
      try {
        const [response, defaultCover] = await Promise.all([
          getCached("/user", { maxAgeMs: 60_000, force: true }),
          getCached<{ url?: string }>("/settings/teacher-cover", { maxAgeMs: 5 * 60_000 }).catch(() => ({ data: {} })),
        ]);
        const userData = response.data;
        setUser({
            name: sanitizePersonName(userData.name || ""),
            email: userData.email,
            phone: sanitizePhoneInput(userData.phone || ""),
            avatar_url: userData.avatar_url || userData.photo_url || null,
            profile_cover_url: userData.profile_cover_url || defaultCover.data?.url || null,
            has_password: userData.has_password !== false,
            password_updated_at: userData.password_updated_at || null,
            student_birth_date: userData.student_birth_date || null,
            student_education_level: userData.student_education_level || "SD",
            grade: userData.grade || "Kelas 1",
            school_name: userData.school_name || "",
            learning_needs: userData.learning_needs || "",
            address: userData.address || "",
            maps_link: userData.maps_link || "",
            latitude: userData.latitude?.toString() || "",
            longitude: userData.longitude?.toString() || "",
            location_consent_at: userData.location_consent_at || null,
            guardian: userData.guardian || null,
        });
      } catch (err) {
        if (axios.isAxiosError(err)) {
          if (!err.response) {
            setError("network");
          } else if (err.response.status === 401) {
            setError("unauthorized");
          } else if (err.response.status === 403) {
            setError("forbidden");
          } else if (err.response.status === 404) {
            setError("not_found");
          } else {
            setError("generic");
          }
        } else {
          setError("generic");
        }
        notify.error("Gagal memuat data profil.");
      } finally {
        setIsLoading(false);
      }
    };
    void fetchUser();
  };

  useEffect(() => {
    if (isLoading || !locationSetupRequested) return;

    const frame = window.requestAnimationFrame(() => {
      locationSectionRef.current?.scrollIntoView({ behavior: "smooth", block: "start" });
      window.setTimeout(() => addressInputRef.current?.focus(), 450);
    });

    return () => window.cancelAnimationFrame(frame);
  }, [isLoading, locationSetupRequested]);

  const useCurrentLocation = () => {
    if (!navigator.geolocation) {
      notify.error("Perangkat atau browser ini tidak mendukung pengambilan lokasi.");
      return;
    }

    setIsLocating(true);
    navigator.geolocation.getCurrentPosition(
      ({ coords }) => {
        const latitude = coords.latitude.toFixed(7);
        const longitude = coords.longitude.toFixed(7);
        setUser((current) => ({
          ...current,
          latitude,
          longitude,
          maps_link: `https://www.google.com/maps?q=${latitude},${longitude}`,
          location_consent_at: current.location_consent_at || new Date().toISOString(),
        }));
        setIsLocating(false);
        notify.success("Titik lokasi berhasil diambil. Lengkapi alamat sebelum menyimpan.");
        window.setTimeout(() => addressInputRef.current?.focus(), 100);
      },
      (locationError) => {
        setIsLocating(false);
        const message = locationError.code === locationError.PERMISSION_DENIED
          ? "Izin lokasi ditolak. Aktifkan izin lokasi browser, lalu coba lagi."
          : locationError.code === locationError.TIMEOUT
            ? "Pengambilan lokasi terlalu lama. Pastikan GPS atau lokasi perangkat aktif."
            : "Lokasi belum berhasil diambil. Pastikan GPS/lokasi perangkat aktif lalu coba lagi.";
        notify.error(message);
      },
      { enableHighAccuracy: true, timeout: 15_000, maximumAge: 60_000 },
    );
  };

  // 2. HANDLER GANTI FOTO (PREVIEW)
  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.files && e.target.files[0]) {
      const file = e.target.files[0];
      const error = validateUpload(file, {
        label: "Foto profil",
        maxSizeMb: 2,
        extensions: ["jpg", "jpeg", "png", "webp"],
      });
      if (error) {
        notify.error(error);
        e.target.value = "";
        return;
      }

      setSelectedFile(file);
      
      // Buat URL preview sementara
      if (avatarPreviewUrlRef.current) URL.revokeObjectURL(avatarPreviewUrlRef.current);
      const previewUrl = URL.createObjectURL(file);
      avatarPreviewUrlRef.current = previewUrl;
      setUser(prev => ({ ...prev, avatar_url: previewUrl }));
    }
  };

  const handleCoverChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    const error = validateUpload(file, {
      label: "Sampul profil",
      maxSizeMb: 5,
      extensions: ["jpg", "jpeg", "png", "webp"],
    });
    if (error) {
      notify.error(error);
      e.target.value = "";
      return;
    }
    if (!file) return;

    setSelectedCover(file);
    if (coverPreviewUrlRef.current) URL.revokeObjectURL(coverPreviewUrlRef.current);
    const previewUrl = URL.createObjectURL(file);
    coverPreviewUrlRef.current = previewUrl;
    setUser((current) => ({ ...current, profile_cover_url: previewUrl }));
  };

  // 3. HANDLER SIMPAN PERUBAHAN
  const handleUpdateProfile = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!isValidPersonName(user.name)) {
      notify.error("Nama lengkap harus berisi huruf dan tidak boleh memuat angka.");
      return;
    }
    if (!isValidPhone(user.phone)) {
      notify.error("Nomor WhatsApp/telepon harus berisi 8–15 angka.");
      return;
    }
    if (locationSetupRequested) {
      if (!user.address.trim()) {
        notify.error("Alamat lengkap wajib diisi untuk kelas offline.");
        addressInputRef.current?.focus();
        return;
      }
      if (!user.latitude.trim() || !user.longitude.trim() || !Number.isFinite(Number(user.latitude)) || !Number.isFinite(Number(user.longitude))) {
        notify.error("Tekan tombol Atur Lokasi agar titik lokasi perangkat tersimpan.");
        return;
      }
      if (!user.location_consent_at) {
        notify.error("Centang persetujuan penggunaan lokasi terlebih dahulu.");
        return;
      }
    }

    setIsSaving(true);

    try {
      // Gunakan FormData untuk kirim File + Text
      const formData = new FormData();
      formData.append("name", user.name);
      formData.append("phone", user.phone);
      formData.append("student_education_level", user.student_education_level);
      formData.append("grade", user.grade);
      formData.append("school_name", user.school_name);
      formData.append("learning_needs", user.learning_needs);
      const submittedAddress = user.address.trim();
      const keepsLocation = submittedAddress.length > 0;
      formData.append("address", submittedAddress);
      formData.append("maps_link", keepsLocation ? user.maps_link : "");
      formData.append("latitude", keepsLocation ? user.latitude : "");
      formData.append("longitude", keepsLocation ? user.longitude : "");
      formData.append("location_consent", keepsLocation && user.location_consent_at ? "1" : "0");
      
      // Trik Laravel: Gunakan POST tapi simulasi PUT agar file terbaca
      formData.append("_method", "PUT"); 

      if (selectedFile) {
        formData.append("avatar", selectedFile);
      }
      if (selectedCover) {
        formData.append("profile_cover", selectedCover);
      }

      const response = await http.post("/user", formData, {
        headers: { 
            "Content-Type": "multipart/form-data" 
        }
      });

      const savedData = response.data?.data;
      const savedAddress = savedText(savedData?.address, submittedAddress);
      const savedMapsLink = savedText(savedData?.maps_link, keepsLocation ? user.maps_link : "");
      const savedLatitude = savedText(savedData?.latitude, keepsLocation ? user.latitude : "");
      const savedLongitude = savedText(savedData?.longitude, keepsLocation ? user.longitude : "");
      const savedLocationConsent = savedData?.location_consent_at === undefined
        ? (keepsLocation ? user.location_consent_at : null)
        : savedData.location_consent_at
          ? String(savedData.location_consent_at)
          : null;

      setSelectedFile(null);
      setSelectedCover(null);
      setUser((current) => ({
        ...current,
        name: savedText(savedData?.name, current.name),
        phone: savedText(savedData?.phone, current.phone),
        avatar_url: savedData?.avatar_url ?? current.avatar_url,
        profile_cover_url: savedData?.profile_cover_url ?? current.profile_cover_url,
        student_education_level: savedText(savedData?.student_education_level, current.student_education_level),
        grade: savedText(savedData?.grade, current.grade),
        school_name: savedText(savedData?.school_name, current.school_name),
        learning_needs: savedText(savedData?.learning_needs, current.learning_needs),
        address: savedAddress,
        maps_link: savedMapsLink,
        latitude: savedLatitude,
        longitude: savedLongitude,
        location_consent_at: savedLocationConsent,
      }));
      if (avatarPreviewUrlRef.current) {
        URL.revokeObjectURL(avatarPreviewUrlRef.current);
        avatarPreviewUrlRef.current = null;
      }
      if (coverPreviewUrlRef.current) {
        URL.revokeObjectURL(coverPreviewUrlRef.current);
        coverPreviewUrlRef.current = null;
      }

      const locationReady = Boolean(
        savedAddress?.trim()
        && savedLatitude.trim()
        && savedLongitude.trim()
        && Number.isFinite(Number(savedLatitude))
        && Number.isFinite(Number(savedLongitude)),
      );
      if (locationSetupRequested && returnTo && locationReady) {
        notify.success("Lokasi tersimpan. Kamu dikembalikan ke pemesanan.");
        navigate(returnTo, { replace: true });
      } else {
        notify.success("Profil berhasil diperbarui!");
      }

    } catch (error: any) {
      console.error(error);
      const msg = error.response?.data?.message || "Gagal memperbarui profil.";
      notify.error(msg);
    } finally {
      setIsSaving(false);
    }
  };

  if (isLoading) return (
    <StudentLayout title="Profil Saya">
        <div className="h-[70vh] flex flex-col items-center justify-center gap-4">
            <Loader2 className="animate-spin text-blue-600" size={40}/>
            <p className="text-slate-400 font-medium animate-pulse">Memuat profil...</p>
        </div>
    </StudentLayout>
  );

  if (error) return (
    <StudentLayout title="Profil Saya">
      <ProfileErrorState error={error} onRetry={retryLoad} />
    </StudentLayout>
  );

  return (
    <StudentLayout title="Profil Saya">
      <div className="mx-auto max-w-5xl pb-28 md:pb-20">
        
        <form id="student-profile-form" onSubmit={handleUpdateProfile} className="space-y-5 animate-in fade-in slide-in-from-bottom-4 duration-700 sm:space-y-8">
            
            {/* === 1. HEADER & AVATAR === */}
            <div className="relative overflow-hidden rounded-[2rem] border border-slate-100 bg-white p-5 shadow-xl shadow-slate-200/50 sm:p-8 md:rounded-[2.5rem] md:p-10">
                <div
                  className="absolute left-0 top-0 h-32 w-full bg-gradient-to-r from-slate-700 to-slate-500 bg-cover bg-center"
                  style={user.profile_cover_url ? {
                    backgroundImage: `linear-gradient(90deg, rgba(15,23,42,.42), rgba(71,85,105,.36)), url("${user.profile_cover_url}")`,
                  } : undefined}
                />
                <div className="absolute top-0 right-0 w-64 h-64 bg-white opacity-10 rounded-full blur-3xl -mr-16 -mt-16 pointer-events-none"></div>
                <button
                  type="button"
                  onClick={() => coverInputRef.current?.click()}
                  className="absolute right-4 top-4 z-20 inline-flex min-h-11 items-center gap-2 rounded-xl border border-white/30 bg-slate-950/60 px-3 py-2 text-xs font-bold text-white shadow-lg backdrop-blur transition hover:bg-slate-950/75 sm:right-5 sm:top-5"
                >
                  <ImagePlus size={15} />
                  Ubah sampul
                </button>
                <input
                  ref={coverInputRef}
                  type="file"
                  accept=".jpg,.jpeg,.png,.webp"
                  onChange={handleCoverChange}
                  className="hidden"
                />

                <div className="relative z-10 mt-14 flex flex-col items-center gap-5 md:mt-12 md:flex-row md:gap-8">
                    {/* Avatar Circle */}
                    <div className="relative group shrink-0 mx-auto md:mx-0">
                        <div className="h-28 w-28 rounded-full bg-white p-1.5 shadow-2xl ring-4 ring-slate-200 sm:h-36 sm:w-36">
                            <div className="w-full h-full rounded-full bg-slate-100 overflow-hidden relative">
                                {user.avatar_url ? (
                                    <img src={user.avatar_url} alt="Foto profil murid" loading="lazy" decoding="async" className="w-full h-full object-cover"/>
                                ) : (
                                    <div className="w-full h-full flex items-center justify-center text-slate-300 bg-slate-50">
                                        <User size={64} />
                                    </div>
                                )}
                                
                                {/* Overlay Upload */}
                                <button
                                    type="button"
                                    aria-label="Ubah foto profil"
                                    className="absolute inset-0 hidden flex-col items-center justify-center bg-black/40 text-white opacity-0 backdrop-blur-[2px] transition-all md:flex md:group-hover:opacity-100 md:focus:opacity-100"
                                    onClick={() => fileInputRef.current?.click()}
                                >
                                    <Camera size={24} className="mb-1"/>
                                    <span className="text-[10px] font-bold uppercase tracking-widest">Ubah Foto</span>
                                </button>
                            </div>
                        </div>
                        {/* Hidden Input File */}
                        <input 
                            type="file" 
                            ref={fileInputRef} 
                            onChange={handleFileChange} 
                            className="hidden" 
                            accept=".jpg,.jpeg,.png,.webp"
                        />
                        <button 
                            type="button"
                            aria-label="Ubah foto profil"
                            onClick={() => fileInputRef.current?.click()}
                            className="absolute bottom-2 right-2 p-2.5 bg-slate-900 text-white rounded-full shadow-lg hover:bg-indigo-600 transition-all md:hidden"
                        >
                            <Camera size={16}/>
                        </button>
                    </div>

                    {/* Text Info */}
                    <div className="mb-2 min-w-0 flex-1 text-center md:mb-0 md:translate-y-7 md:text-left">
                        <h1 className="mb-1 break-words text-2xl font-black text-slate-900 sm:text-3xl">{user.name}</h1>
                        <p className="mb-4 flex min-w-0 items-center justify-center gap-2 break-all text-sm font-medium text-slate-500 md:justify-start">
                            <Mail size={16}/> {user.email}
                        </p>
                        <div className="inline-flex items-center gap-2 px-4 py-2 bg-emerald-50 text-emerald-700 rounded-full text-xs font-bold border border-emerald-100 shadow-sm">
                            <ShieldCheck size={14}/> Akun murid aktif
                        </div>
                    </div>

                    {/* Tombol Simpan Desktop */}
                    <div className="hidden md:block">
                        <button 
                            type="submit" 
                            disabled={isSaving}
                            className="flex items-center gap-3 px-8 py-4 bg-slate-900 text-white rounded-2xl font-bold shadow-xl hover:bg-indigo-700 hover:shadow-indigo-200 hover-rise transition-all active:scale-95 disabled:opacity-70 disabled:cursor-not-allowed"
                        >
                            {isSaving ? <Loader2 className="animate-spin" size={20}/> : <Save size={20}/>}
                            Simpan Perubahan
                        </button>
                    </div>
                </div>
            </div>

            {/* === 2. FORM BIODATA === */}
            <div className="grid grid-cols-1 gap-5">
                
                {/* Info Dasar */}
                <div className="rounded-[2rem] border border-slate-100 bg-white p-5 shadow-lg shadow-slate-200/40 sm:p-8 md:rounded-[2.5rem]">
                    <div className="mb-5 flex items-center gap-3 sm:mb-8 sm:gap-4">
                        <div className="w-12 h-12 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center">
                            <User size={24}/>
                        </div>
                        <div>
                            <h3 className="text-lg font-black text-slate-800">Biodata Diri</h3>
                            <p className="text-xs text-slate-400 font-medium">Informasi dasar akun Anda</p>
                        </div>
                    </div>

                    <div className="space-y-5 sm:space-y-6">
                        <div className="space-y-2">
                            <label className="text-xs font-black text-slate-400 uppercase tracking-widest ml-1">Nama Lengkap</label>
                            <input 
                                type="text" 
                                value={user.name}
                                onChange={(e) => setUser({...user, name: sanitizePersonName(e.target.value)})}
                                className="w-full p-4 bg-slate-50 rounded-2xl font-bold text-slate-700 outline-none focus:bg-white focus:ring-4 focus:ring-blue-50 border border-transparent focus:border-blue-200 transition-all placeholder:text-slate-300"
                                placeholder="Masukkan nama lengkap"
                            />
                        </div>

                        <div className="space-y-2">
                            <label className="text-xs font-black text-slate-400 uppercase tracking-widest ml-1">Nomor WhatsApp/telepon</label>
                            <input
                                type="tel"
                                inputMode="tel"
                                autoComplete="tel"
                                maxLength={16}
                                value={user.phone}
                                onChange={(e) => setUser({...user, phone: sanitizePhoneInput(e.target.value)})}
                                className="w-full p-4 bg-slate-50 rounded-2xl font-bold text-slate-700 outline-none focus:bg-white focus:ring-4 focus:ring-blue-50 border border-transparent focus:border-blue-200 transition-all placeholder:text-slate-300"
                                placeholder="Nomor yang dapat dihubungi saat kelas offline"
                            />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                          <div className="space-y-2">
                            <label className="text-xs font-black text-slate-400 uppercase tracking-widest ml-1">Jenjang</label>
                            <ResponsiveSelect
                              value={user.student_education_level}
                              ariaLabel="Pilih jenjang pendidikan"
                              options={EDUCATION_LEVELS.map((item) => ({ value: item, label: item }))}
                              className="border-transparent bg-slate-50 focus:border-blue-200 focus:bg-white focus:ring-blue-50"
                              onValueChange={(next) => setUser({ ...user, student_education_level: next, grade: GRADES_BY_EDUCATION_LEVEL[next][0] })}
                            />
                          </div>
                          <div className="space-y-2">
                            <label className="text-xs font-black text-slate-400 uppercase tracking-widest ml-1">{user.student_education_level === "Umum" ? "Tingkat" : "Kelas"}</label>
                            <ResponsiveSelect
                              value={user.grade}
                              ariaLabel={user.student_education_level === "Umum" ? "Pilih tingkat" : "Pilih kelas"}
                              options={GRADES_BY_EDUCATION_LEVEL[user.student_education_level].map((item) => ({ value: item, label: item }))}
                              className="border-transparent bg-slate-50 focus:border-blue-200 focus:bg-white focus:ring-blue-50"
                              onValueChange={(next) => setUser({ ...user, grade: next })}
                            />
                          </div>
                        </div>

                        <div className="space-y-2">
                          <label className="text-xs font-black text-slate-400 uppercase tracking-widest ml-1">Sekolah atau lembaga</label>
                          <input value={user.school_name} onChange={(event) => setUser({ ...user, school_name: event.target.value })} className="w-full p-4 bg-slate-50 rounded-2xl font-bold text-slate-700 outline-none focus:bg-white focus:ring-4 focus:ring-blue-50 border border-transparent focus:border-blue-200 transition-all" placeholder="Opsional" />
                        </div>

                        <div className="space-y-2">
                          <label className="text-xs font-black text-slate-400 uppercase tracking-widest ml-1">Kebutuhan belajar</label>
                          <textarea value={user.learning_needs} onChange={(event) => setUser({ ...user, learning_needs: event.target.value })} rows={3} className="w-full resize-y p-4 bg-slate-50 rounded-2xl font-medium text-slate-700 outline-none focus:bg-white focus:ring-4 focus:ring-blue-50 border border-transparent focus:border-blue-200 transition-all" placeholder="Contoh: perlu penguatan konsep dan latihan soal cerita" />
                        </div>
                        
                        <div className="space-y-2">
                            <label className="text-xs font-black text-slate-400 uppercase tracking-widest ml-1">Alamat Email</label>
                            <div className="relative group cursor-not-allowed">
                                <input 
                                    type="email" 
                                    value={user.email}
                                    disabled
                                    className="w-full p-4 pl-12 bg-slate-100 rounded-2xl font-bold text-slate-500 border border-transparent cursor-not-allowed"
                                />
                                <Mail size={20} className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"/>
                                <div className="absolute inset-0 bg-white/50 opacity-0 group-hover:opacity-100 flex items-center justify-center text-xs font-bold text-slate-500 transition-opacity rounded-2xl backdrop-blur-[1px]">
                                    Hubungi Admin untuk ubah email
                                </div>
                            </div>
                        </div>

                        {user.student_birth_date && (
                          <div className="rounded-2xl border border-blue-100 bg-blue-50 p-4">
                            <p className="flex items-center gap-2 text-xs font-black uppercase tracking-widest text-blue-600">
                              <CalendarDays size={16} /> Tanggal lahir murid
                            </p>
                            <p className="mt-2 font-bold text-slate-800">{formatDateOnly(user.student_birth_date)}</p>
                          </div>
                        )}

                        {user.guardian && (
                          <div className="rounded-2xl border border-amber-100 bg-amber-50 p-4">
                            <p className="flex items-center gap-2 text-xs font-black uppercase tracking-widest text-amber-700">
                              <UserRoundCheck size={16} /> Orang tua / wali
                            </p>
                            <p className="mt-2 font-bold text-slate-800">{user.guardian.name}</p>
                            <p className="mt-1 text-sm text-slate-600">{guardianRelationship(user.guardian.relationship)} · {user.guardian.phone}</p>
                            <p className="mt-2 text-xs font-semibold text-emerald-700">Persetujuan wali telah tercatat.</p>
                            <p className="mt-1 text-xs text-slate-500">Perubahan data wali diajukan melalui Pusat Bantuan.</p>
                          </div>
                        )}
                    </div>
                </div>

            </div>

            {/* === 3. LOKASI === */}
            <div ref={locationSectionRef} id="student-location" className={`scroll-mt-24 rounded-[2rem] border bg-white p-5 shadow-lg shadow-slate-200/40 sm:p-8 md:rounded-[2.5rem] ${locationSetupRequested ? "border-emerald-300 ring-4 ring-emerald-100" : "border-slate-100"}`}>
              {locationSetupRequested && (
                <div className="mb-5 flex items-start gap-3 rounded-2xl border border-blue-200 bg-blue-50 p-4 text-blue-900">
                  <MapPin className="mt-0.5 shrink-0" size={19} />
                  <div className="min-w-0 flex-1">
                    <p className="font-black">Lengkapi lokasi untuk melanjutkan pesanan offline</p>
                    <p className="mt-1 text-sm leading-6 text-blue-700">Isi alamat lengkap, tekan Atur Lokasi, centang persetujuan, lalu simpan.</p>
                  </div>
                  {returnTo && (
                    <button type="button" onClick={() => navigate(returnTo)} className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-white text-blue-700" aria-label="Kembali ke pemesanan">
                      <ArrowLeft size={18} />
                    </button>
                  )}
                </div>
              )}
              <div className="mb-5 flex flex-wrap items-center gap-3 sm:mb-6 sm:gap-4">
                <div className="w-12 h-12 bg-emerald-50 text-emerald-600 rounded-2xl flex items-center justify-center">
                  <MapPin size={24}/>
                </div>
                <div className="min-w-0 flex-1">
                  <h3 className="text-lg font-black text-slate-800">Lokasi</h3>
                  <p className="text-xs text-slate-400 font-medium">Digunakan untuk sesi belajar offline</p>
                </div>
                <button
                  type="button"
                  onClick={useCurrentLocation}
                  disabled={isLocating}
                  className="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 text-sm font-black text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto"
                >
                  {isLocating ? <Loader2 className="animate-spin" size={17} /> : <LocateFixed size={17} />}
                  {isLocating ? "Mengatur lokasi…" : user.latitude && user.longitude ? "Atur Ulang Lokasi" : "Atur Lokasi"}
                </button>
              </div>
              <div className="grid gap-4 md:grid-cols-2">
                <div className="space-y-2 md:col-span-2">
                  <label className="text-xs font-black uppercase tracking-widest text-slate-400">Alamat lengkap</label>
                  <textarea ref={addressInputRef} value={user.address} onChange={(event) => setUser({ ...user, address: event.target.value })} rows={3} className="w-full resize-y rounded-2xl border border-transparent bg-slate-50 p-4 font-medium text-slate-700 outline-none transition focus:border-emerald-200 focus:bg-white focus:ring-4 focus:ring-emerald-50" placeholder="Alamat belajar untuk kelas offline" />
                </div>
                <div className="md:col-span-2">
                  <div className={`flex items-start gap-3 rounded-2xl border p-4 ${user.latitude && user.longitude ? "border-emerald-200 bg-emerald-50" : "border-amber-200 bg-amber-50"}`}>
                    <LocateFixed className={`mt-0.5 shrink-0 ${user.latitude && user.longitude ? "text-emerald-600" : "text-amber-600"}`} size={18} />
                    <div className="min-w-0">
                      <p className={`text-sm font-black ${user.latitude && user.longitude ? "text-emerald-800" : "text-amber-800"}`}>
                        {user.latitude && user.longitude ? "Lokasi perangkat sudah diatur" : "Lokasi perangkat belum diatur"}
                      </p>
                      <p className={`mt-1 text-xs leading-5 ${user.latitude && user.longitude ? "text-emerald-700" : "text-amber-700"}`}>
                        Koordinat disimpan otomatis oleh sistem dan tidak perlu kamu isi atau pahami.
                      </p>
                    </div>
                  </div>
                </div>
              </div>
              <label className="mt-4 flex cursor-pointer items-start gap-3 rounded-2xl border border-emerald-100 bg-emerald-50 p-4 text-sm font-semibold leading-6 text-emerald-900">
                <input type="checkbox" checked={Boolean(user.location_consent_at)} onChange={(event) => setUser({ ...user, location_consent_at: event.target.checked ? new Date().toISOString() : null })} className="mt-1 h-5 w-5 rounded border-emerald-300 text-emerald-600" />
                Saya mengizinkan lokasi ini dipakai untuk pencocokan tutor dan pelaksanaan kelas offline.
              </label>
              <div className="mt-4 space-y-3 rounded-2xl border border-slate-100 bg-slate-50 p-4 sm:p-5">
                <p className="text-sm leading-6 text-slate-600">
                  Lokasi dipakai sistem untuk mencocokkan tutor offline terdekat. Kamu cukup mengatur lokasi dari perangkat; data teknisnya disimpan otomatis.
                </p>
                <div className="flex items-center gap-2 rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700">
                  <Shield size={16} />
                  Lokasi kamu dilindungi. Tidak pernah ditampilkan ke publik.
                </div>

              </div>
              {locationSetupRequested && (
                <button
                  type="submit"
                  disabled={isSaving || isLocating}
                  className="mt-5 inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-2xl bg-slate-950 px-5 font-black text-white transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto"
                >
                  {isSaving ? <Loader2 className="animate-spin" size={18} /> : <Save size={18} />}
                  {isSaving ? "Menyimpan lokasi…" : "Simpan lokasi & kembali ke pemesanan"}
                </button>
              )}
            </div>

            {/* === 4. KEBIJAKAN === */}
            <div className="rounded-[2rem] border border-slate-100 bg-white p-5 shadow-lg shadow-slate-200/40 sm:p-8 md:rounded-[2.5rem]">
              <div className="mb-5 flex items-center gap-3 sm:mb-6 sm:gap-4">
                <div className="w-12 h-12 bg-indigo-50 text-indigo-600 rounded-2xl flex items-center justify-center">
                  <FileText size={24}/>
                </div>
                <div>
                  <h3 className="text-lg font-black text-slate-800">Kebijakan & Persetujuan</h3>
                  <p className="text-xs text-slate-400 font-medium">Dokumen yang mengatur penggunaan layanan</p>
                </div>
              </div>
              <div className="space-y-3">
                <Link
                  to="/terms"
                  state={{ from: "/student/profile" }}
                  className="flex min-h-14 items-center justify-between gap-3 rounded-2xl border border-slate-100 bg-slate-50 px-4 py-3 transition hover:bg-slate-100 sm:px-5 sm:py-4"
                >
                  <div className="flex items-center gap-3">
                    <FileText size={18} className="text-indigo-500" />
                    <span className="font-bold text-slate-800 text-sm">Syarat & Ketentuan Layanan</span>
                  </div>
                  <ExternalLink size={16} className="text-slate-400" />
                </Link>
                <Link
                  to="/privacy"
                  state={{ from: "/student/profile" }}
                  className="flex min-h-14 items-center justify-between gap-3 rounded-2xl border border-slate-100 bg-slate-50 px-4 py-3 transition hover:bg-slate-100 sm:px-5 sm:py-4"
                >
                  <div className="flex items-center gap-3">
                    <Shield size={18} className="text-emerald-500" />
                    <span className="font-bold text-slate-800 text-sm">Kebijakan Privasi</span>
                  </div>
                  <ExternalLink size={16} className="text-slate-400" />
                </Link>
                <div className="flex items-center gap-2 rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700">
                  <ShieldCheck size={16} />
                  Kamu telah menyetujui kebijakan ini saat mendaftar.
                </div>
              </div>
            </div>

            {/* === 5. BANTUAN === */}
            <div className="rounded-[2rem] border border-slate-100 bg-white p-5 shadow-lg shadow-slate-200/40 sm:p-8 md:rounded-[2.5rem]">
              <div className="mb-5 flex items-center gap-3 sm:mb-6 sm:gap-4">
                <div className="w-12 h-12 bg-amber-50 text-amber-600 rounded-2xl flex items-center justify-center">
                  <HelpCircle size={24}/>
                </div>
                <div>
                  <h3 className="text-lg font-black text-slate-800">Bantuan & Support</h3>
                  <p className="text-xs text-slate-400 font-medium">Panduan dan kontak jika kamu butuh bantuan</p>
                </div>
              </div>
              <div className="space-y-3">
                <a
                  href="/student/help"
                  className="flex min-h-14 items-center justify-between gap-3 rounded-2xl border border-slate-100 bg-slate-50 px-4 py-3 transition hover:bg-slate-100 sm:px-5 sm:py-4"
                >
                  <div className="flex items-center gap-3">
                    <HelpCircle size={18} className="text-amber-500" />
                    <span className="font-bold text-slate-800 text-sm">Pusat Bantuan</span>
                  </div>
                  <ExternalLink size={16} className="text-slate-400" />
                </a>
                <a
                  href="/student/help"
                  className="flex min-h-14 items-center justify-between gap-3 rounded-2xl border border-slate-100 bg-slate-50 px-4 py-3 transition hover:bg-slate-100 sm:px-5 sm:py-4"
                >
                  <div className="flex items-center gap-3">
                    <MessageCircle size={18} className="text-blue-500" />
                    <span className="font-bold text-slate-800 text-sm">Kirim Pengaduan</span>
                  </div>
                  <ExternalLink size={16} className="text-slate-400" />
                </a>
                <a
                  href="/student/help"
                  className="flex min-h-14 items-center justify-between gap-3 rounded-2xl border border-slate-100 bg-slate-50 px-4 py-3 transition hover:bg-slate-100 sm:px-5 sm:py-4"
                >
                  <div className="flex items-center gap-3">
                    <MessageCircle size={18} className="text-emerald-500" />
                    <span className="font-bold text-slate-800 text-sm">Hubungi Admin</span>
                  </div>
                  <ExternalLink size={16} className="text-slate-400" />
                </a>
              </div>
            </div>

        </form>
        <div className="fixed inset-x-3 bottom-[calc(4.75rem+env(safe-area-inset-bottom))] z-20 mx-auto max-w-md rounded-2xl border border-white/20 bg-slate-950/95 p-2 shadow-2xl backdrop-blur md:hidden">
          <button form="student-profile-form" type="submit" disabled={isSaving} className="flex min-h-12 w-full items-center justify-center gap-3 rounded-xl bg-white px-5 font-black text-slate-950 transition active:scale-[.98] disabled:cursor-not-allowed disabled:opacity-70">
            {isSaving ? <Loader2 className="animate-spin" size={20}/> : <Save size={20}/>}
            {isSaving ? "Menyimpan…" : "Simpan Perubahan"}
          </button>
        </div>
      </div>
    </StudentLayout>
  );
}

function guardianRelationship(value: string) {
  return {
    orang_tua: "Orang tua",
    wali_keluarga: "Wali keluarga",
    wali_resmi: "Wali resmi lainnya",
  }[value] || "Wali";
}

function ProfileErrorState({ error, onRetry }: { error: string; onRetry: () => void }) {
  if (error === "network") {
    return (
      <div className="grid min-h-[400px] place-items-center px-4 text-center">
        <div>
          <WifiOff className="mx-auto text-slate-300" size={48} />
          <h2 className="mt-4 text-xl font-black text-slate-800">Koneksi Terputus</h2>
          <p className="mt-2 text-sm text-slate-500">Periksa koneksi internet Anda dan coba lagi.</p>
          <button onClick={onRetry} className="mt-5 inline-flex items-center gap-2 rounded-2xl bg-blue-600 px-5 py-3 text-sm font-black text-white hover:bg-blue-700 transition-colors">
            <RefreshCw size={16} /> Coba Lagi
          </button>
        </div>
      </div>
    );
  }

  if (error === "forbidden") {
    return (
      <div className="grid min-h-[400px] place-items-center px-4 text-center">
        <div>
          <AlertCircle className="mx-auto text-amber-500" size={48} />
          <h2 className="mt-4 text-xl font-black text-slate-800">Akses Ditolak</h2>
          <p className="mt-2 text-sm text-slate-500">Anda tidak memiliki izin untuk mengakses halaman ini.</p>
        </div>
      </div>
    );
  }

  if (error === "not_found") {
    return (
      <div className="grid min-h-[400px] place-items-center px-4 text-center">
        <div>
          <AlertCircle className="mx-auto text-slate-300" size={48} />
          <h2 className="mt-4 text-xl font-black text-slate-800">Data Tidak Ditemukan</h2>
          <p className="mt-2 text-sm text-slate-500">Profil tidak dapat dimuat saat ini.</p>
          <button onClick={onRetry} className="mt-5 inline-flex items-center gap-2 rounded-2xl bg-blue-600 px-5 py-3 text-sm font-black text-white hover:bg-blue-700 transition-colors">
            <RefreshCw size={16} /> Coba Lagi
          </button>
        </div>
      </div>
    );
  }

  if (error === "unauthorized") {
    return (
      <div className="grid min-h-[400px] place-items-center px-4 text-center">
        <div>
          <AlertCircle className="mx-auto text-orange-500" size={48} />
          <h2 className="mt-4 text-xl font-black text-slate-800">Sesi Berakhir</h2>
          <p className="mt-2 text-sm text-slate-500">Sesi Anda telah berakhir. Silakan login kembali.</p>
          <a href="/login" className="mt-5 inline-flex rounded-2xl bg-blue-600 px-5 py-3 text-sm font-black text-white hover:bg-blue-700 transition-colors">
            Login Kembali
          </a>
        </div>
      </div>
    );
  }

  // Generic error
  return (
    <div className="grid min-h-[400px] place-items-center px-4 text-center">
      <div>
        <AlertCircle className="mx-auto text-rose-500" size={48} />
        <h2 className="mt-4 text-xl font-black text-slate-800">Terjadi Kesalahan</h2>
        <p className="mt-2 text-sm text-slate-500">Profil tidak dapat dimuat. Silakan coba lagi.</p>
        <button onClick={onRetry} className="mt-5 inline-flex items-center gap-2 rounded-2xl bg-blue-600 px-5 py-3 text-sm font-black text-white hover:bg-blue-700 transition-colors">
          <RefreshCw size={16} /> Coba Lagi
        </button>
      </div>
    </div>
  );
}
