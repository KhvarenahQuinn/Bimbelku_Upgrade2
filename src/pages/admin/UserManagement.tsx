import { notify } from "@/lib/notify";
import http, { API_BASE_URL, getApiError } from "@/lib/http";
import { useCallback, useState, useEffect } from "react";
import AdminLayout from "../../components/AdminLayout";
import AdminAccountsPanel from "@/components/AdminAccountsPanel";
import TeacherScheduleAdminPanel from "@/components/TeacherScheduleAdminPanel";
import { 
  Users, Search, Ban, Unlock, GraduationCap, School, 
  Eye, X, Mail, Calendar, Building2, BookOpen, Linkedin, FileText, CheckCircle, Loader2, ExternalLink, Wifi, MapPin,
  Phone, UserRoundCheck,
} from "lucide-react";
import axios from "axios";
import { useConfirmDialog } from "@/components/ConfirmDialogProvider";
import { openProtectedFile } from "@/components/ProtectedImage";
import { validateUpload } from "@/lib/validation";

export default function UserManagement() {
  const confirmDialog = useConfirmDialog();
  const [users, setUsers] = useState<any[]>([]);
  const [canManageAdmins] = useState(() => {
    try {
      const stored = JSON.parse(localStorage.getItem("user") || "null") as { is_primary_admin?: boolean; admin_type?: string } | null;
      return stored?.is_primary_admin === true || stored?.admin_type === "super_admin";
    } catch {
      return false;
    }
  });
  const [activeTab, setActiveTab] = useState<"student" | "teacher" | "admin">("student");
  const [isLoading, setIsLoading] = useState(true);
  const [searchTerm, setSearchTerm] = useState("");
  const [appliedSearch, setAppliedSearch] = useState("");
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 });
  const [subjectFilter, setSubjectFilter] = useState("");
  
  const [subjectOptions, setSubjectOptions] = useState<string[]>([]);
  // State Modal Detail
  const [modalOpen, setModalOpen] = useState(false);
  const [selectedUser, setSelectedUser] = useState<any>(null);
  const [savingTeacherCover, setSavingTeacherCover] = useState(false);

  useEffect(() => {
    const timeoutId = window.setTimeout(() => {
      setAppliedSearch(searchTerm.trim());
      setPage(1);
    }, 350);
    return () => window.clearTimeout(timeoutId);
  }, [searchTerm]);
  // 1. FETCH DATA DARI API
  const fetchUsers = useCallback(async () => {
    if (activeTab === "admin") { setIsLoading(false); return; }
    setIsLoading(true);
    try {
      const token = localStorage.getItem("token");
      const params = new URLSearchParams({ role: activeTab, page: String(page), per_page: "20" });
      if (subjectFilter) params.set("subject", subjectFilter);
      if (appliedSearch) params.set("q", appliedSearch);
      const response = await axios.get(API_BASE_URL + "/admin/users?" + params.toString(), { headers: { Authorization: "Bearer " + token } });
      setUsers(response.data.data ?? []);
      setMeta(response.data.meta ?? { current_page: 1, last_page: 1, total: 0 });
      if (activeTab === "teacher") setSubjectOptions(response.data.subject_options ?? []);
    } catch (error) { console.error(error); notify.error("Gagal memuat data pengguna."); }
    finally { setIsLoading(false); }
  }, [activeTab, subjectFilter, appliedSearch, page]);
  useEffect(() => {
    void fetchUsers();
  }, [fetchUsers]);

  // 2. FUNGSI BLOKIR / AKTIFKAN
  const toggleStatus = async (user: any) => {
    const newStatus = user.status === 'active' ? 'banned' : 'active';
    const confirmMessage = newStatus === 'banned' 
      ? `Yakin ingin memblokir ${user.name}? Mereka tidak akan bisa login.` 
      : `Aktifkan kembali akun ${user.name}?`;

    const approved = await confirmDialog({
      title: newStatus === "banned" ? "Blokir akun?" : "Aktifkan kembali akun?",
      description: confirmMessage,
      confirmText: newStatus === "banned" ? "Blokir" : "Aktifkan",
      tone: newStatus === "banned" ? "danger" : "primary",
    });
    if (!approved) return;

    try {
      const token = localStorage.getItem("token");
      await axios.post(`${API_BASE_URL}/admin/users/status`, {
        user_id: user.id,
        status: newStatus
      }, {
        headers: { Authorization: `Bearer ${token}` }
      });

      notify.success(newStatus === 'banned' ? "User berhasil diblokir" : "User berhasil diaktifkan");
      fetchUsers(); 

    } catch (error: any) {
      notify.error(error.response?.data?.message || "Gagal mengubah status pengguna.");
    }
  };

  const openModal = (user: any) => {
    setSelectedUser(user);
    setModalOpen(true);
  };

  const updateTeacherCover = async (file?: File) => {
    if (!file || !selectedUser || selectedUser.role !== "teacher") return;
    const error = validateUpload(file, {
      label: "Sampul tutor",
      maxSizeMb: 5,
      extensions: ["jpg", "jpeg", "png", "webp"],
    });
    if (error) return notify.error(error);

    setSavingTeacherCover(true);
    try {
      const formData = new FormData();
      formData.append("profile_cover", file);
      const response = await http.post<{ message: string; profile_cover_url: string }>(`/admin/users/${selectedUser.id}/profile-cover`, formData);
      const profile_cover_url = response.data.profile_cover_url;
      setSelectedUser((current: any) => ({ ...current, profile_cover_url, personal_profile_cover_url: profile_cover_url, profile_cover_use_default: false }));
      setUsers((current) => current.map((user) => user.id === selectedUser.id ? { ...user, profile_cover_url, personal_profile_cover_url: profile_cover_url, profile_cover_use_default: false } : user));
      notify.success(response.data.message);
    } catch (error) {
      notify.error(getApiError(error, "Sampul tutor gagal disimpan."));
    } finally {
      setSavingTeacherCover(false);
    }
  };

  const resetTeacherCover = async () => {
    if (!selectedUser || selectedUser.role !== "teacher") return;
    const approved = await confirmDialog({
      title: "Pakai sampul default lagi?",
      description: "Profil tutor akan memakai sampul default. Foto pribadinya tetap tersimpan dan dapat dipakai lagi.",
      confirmText: "Pakai sampul default",
      tone: "primary",
    });
    if (!approved) return;

    setSavingTeacherCover(true);
    try {
      const response = await http.delete<{ message: string }>(`/admin/users/${selectedUser.id}/profile-cover`);
      setSelectedUser((current: any) => ({ ...current, profile_cover_url: null, profile_cover_use_default: true }));
      setUsers((current) => current.map((user) => user.id === selectedUser.id ? { ...user, profile_cover_url: null, profile_cover_use_default: true } : user));
      notify.success(response.data.message);
    } catch (error) {
      notify.error(getApiError(error, "Sampul tutor gagal dikembalikan ke default."));
    } finally {
      setSavingTeacherCover(false);
    }
  };

  const restoreTeacherCover = async () => {
    if (!selectedUser || selectedUser.role !== "teacher") return;
    setSavingTeacherCover(true);
    try {
      const response = await http.post<{ message: string; profile_cover_url: string }>(`/admin/users/${selectedUser.id}/profile-cover/restore`);
      const profile_cover_url = response.data.profile_cover_url;
      setSelectedUser((current: any) => ({ ...current, profile_cover_url, profile_cover_use_default: false }));
      setUsers((current) => current.map((user) => user.id === selectedUser.id ? { ...user, profile_cover_url, profile_cover_use_default: false } : user));
      notify.success(response.data.message);
    } catch (error) {
      notify.error(getApiError(error, "Sampul pribadi gagal dipulihkan."));
    } finally {
      setSavingTeacherCover(false);
    }
  };

  const filteredUsers = users;

  return (
    <AdminLayout title="Manajemen Akun Pengguna">
      
      {/* MODAL POPUP LIHAT DATA */}
      {modalOpen && selectedUser && (
        <div className="fixed inset-0 z-[var(--layer-modal)] flex items-end justify-center bg-black/55 p-3 pb-[max(env(safe-area-inset-bottom),0.75rem)] animate-in fade-in sm:items-center sm:p-4">
          <div className="relative flex max-h-[calc(100dvh-1.5rem)] w-full min-w-0 max-w-lg flex-col overflow-hidden rounded-[1.5rem] bg-white shadow-2xl animate-in slide-in-from-bottom-4 sm:rounded-2xl sm:zoom-in-95">
            
            <div className="flex min-w-0 shrink-0 items-center justify-between gap-3 border-b border-gray-100 bg-gray-50 px-4 py-4 sm:px-6">
              <h3 className="flex min-w-0 items-center gap-2 break-words text-base font-bold text-gray-900 sm:text-lg">
                {selectedUser.role === "student" ? <School size={20} className="text-blue-500"/> : <GraduationCap size={20} className="text-orange-500"/>}
                Detail {selectedUser.role === 'student' ? 'Murid' : 'Tutor'}
              </h3>
              <button onClick={() => setModalOpen(false)}><X size={24} className="text-gray-400 hover:text-gray-600" /></button>
            </div>

            <div className="min-h-0 min-w-0 flex-1 overflow-x-hidden overflow-y-auto p-4 sm:p-6">
               {/* HEADER PROFIL */}
               <div className="mb-6 flex min-w-0 items-center gap-3 sm:gap-4">
                  <div className={`w-16 h-16 rounded-full flex items-center justify-center text-2xl font-bold border-2 border-white shadow-sm ${selectedUser.role === 'teacher' ? 'bg-orange-100 text-orange-600' : 'bg-blue-100 text-blue-600'}`}>
                    {selectedUser.name.charAt(0).toUpperCase()}
                  </div>
                  <div className="min-w-0 flex-1">
                    <h4 className="break-words text-lg font-bold text-gray-900 sm:text-xl">{selectedUser.name}</h4>
                    <div className="mt-1 flex min-w-0 items-start gap-2 break-all text-sm text-gray-500">
                       <Mail size={14} /> {selectedUser.email}
                    </div>
                    <span className={`inline-block mt-2 px-2 py-0.5 rounded text-xs font-bold ${selectedUser.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>
                        Status: {selectedUser.status.toUpperCase()}
                    </span>
                  </div>
               </div>

               <div className="border-t border-gray-100 pt-4 space-y-4">
                  
                  {/* --- TAMPILAN KHUSUS MURID (LENGKAP) --- */}
                  {selectedUser.role === "student" && (
                    <div className="grid grid-cols-1 gap-4">
                        <div className="bg-blue-50 p-4 rounded-xl border border-blue-100">
                            <label className="text-xs text-blue-500 uppercase font-bold flex items-center gap-1 mb-1">
                                <Building2 size={14} /> Asal Sekolah
                            </label>
                            <p className="font-bold text-gray-900">{selectedUser.school_name || "-"}</p>
                        </div>
                        <div className="bg-gray-50 p-4 rounded-xl border border-gray-100">
                            <label className="text-xs text-gray-500 uppercase font-bold flex items-center gap-1 mb-1">
                                <CheckCircle size={14} /> Jenjang / Kelas
                            </label>
                            <p className="font-bold text-gray-900">{selectedUser.grade || "-"}</p>
                        </div>
                        <div className="bg-gray-50 p-4 rounded-xl border border-gray-100">
                            <label className="text-xs text-gray-500 uppercase font-bold flex items-center gap-1 mb-1">
                                <Calendar size={14} /> Tanggal Lahir
                            </label>
                            <p className="font-bold text-gray-900">{selectedUser.student_birth_date || "Data lama belum dilengkapi"}</p>
                        </div>
                        {selectedUser.guardian && (
                          <div className="rounded-xl border border-amber-100 bg-amber-50 p-4">
                            <label className="mb-2 flex items-center gap-1 text-xs font-bold uppercase text-amber-700">
                              <UserRoundCheck size={14} /> Orang Tua / Wali
                            </label>
                            <p className="font-bold text-gray-900">{selectedUser.guardian.name}</p>
                            <p className="mt-1 text-sm text-gray-600">{guardianRelationship(selectedUser.guardian.relationship)}</p>
                            <p className="mt-2 flex items-center gap-2 text-sm text-gray-700">
                              <Phone size={14} /> {selectedUser.guardian.phone}
                            </p>
                            <p className="mt-2 text-xs font-semibold text-emerald-700">Persetujuan wali telah tercatat.</p>
                          </div>
                        )}
                    </div>
                  )}

                  {/* --- TAMPILAN KHUSUS GURU (LENGKAP) --- */}
                  {selectedUser.role === "teacher" && selectedUser.teacher_profile && (
                    <div className="space-y-4">
                      <div className="min-w-0 rounded-xl border border-orange-100 bg-orange-50/60 p-3 sm:p-4">
                        <p className="text-sm font-black text-slate-900">Sampul profil tutor</p>
                        <p className="mt-1 text-xs leading-5 text-slate-600">Pilih sampul yang dipakai tutor ini. Beralih ke default tidak menghapus foto pribadinya.</p>
                        {selectedUser.profile_cover_url ? <img src={selectedUser.profile_cover_url} alt={`Sampul ${selectedUser.name}`} className="mt-3 aspect-[3/1] w-full rounded-xl object-cover" /> : <div className="mt-3 grid aspect-[3/1] w-full place-items-center rounded-xl bg-gradient-to-r from-slate-950 to-indigo-900 px-3 text-center text-xs font-bold text-white">Memakai sampul default</div>}
                        <div className="mt-3 flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center">
                          <label className="min-w-0 flex-1 text-xs font-bold text-slate-700">Unggah sampul baru (JPG, PNG, WebP; maks. 5 MB)
                            <input type="file" accept=".jpg,.jpeg,.png,.webp" disabled={savingTeacherCover} onChange={(event) => { void updateTeacherCover(event.target.files?.[0]); event.target.value = ""; }} className="mt-2 block min-h-11 w-full min-w-0 rounded-xl border border-slate-200 bg-white p-2 text-xs file:mr-2 file:rounded-lg file:border-0 file:bg-orange-100 file:px-2 file:py-1 file:font-bold file:text-orange-900" />
                          </label>
                          {selectedUser.profile_cover_url && <button type="button" disabled={savingTeacherCover} onClick={() => void resetTeacherCover()} className="min-h-11 w-full min-w-0 rounded-xl border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-50 sm:w-auto">Pakai default</button>}
                          {selectedUser.profile_cover_use_default && selectedUser.personal_profile_cover_url && <button type="button" disabled={savingTeacherCover} onClick={() => void restoreTeacherCover()} className="min-h-11 w-full min-w-0 rounded-xl border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-50 sm:w-auto">Pakai foto pribadi</button>}
                        </div>
                        {savingTeacherCover && <p role="status" className="mt-2 text-xs font-bold text-orange-800">Menyimpan sampul…</p>}
                      </div>
                      {/* Grid Keahlian & Metode */}
                      <div className="grid grid-cols-1 gap-3 min-[360px]:grid-cols-2 sm:gap-4">
                          <div className="bg-gray-50 p-3 rounded-xl border border-gray-100">
                             <label className="text-xs text-gray-500 uppercase font-bold flex items-center gap-1 mb-1">
                                <BookOpen size={14} /> Keahlian
                             </label>
                             <p className="font-bold text-gray-900 text-sm">{selectedUser.teacher_profile.expertise || "-"}</p>
                          </div>
                          <div className="bg-blue-50 p-3 rounded-xl border border-blue-100">
                             <label className="text-xs text-blue-500 uppercase font-bold flex items-center gap-1 mb-1">
                                {selectedUser.teacher_profile.teaching_method === 'Online' ? <Wifi size={14}/> : <MapPin size={14}/>} Metode
                             </label>
                             <p className="font-bold text-blue-900 text-sm capitalize">{selectedUser.teacher_profile.teaching_method || "-"}</p>
                          </div>
                      </div>
                      
                      <TeacherScheduleAdminPanel teacherId={Number(selectedUser.id)} />

                      {/* LinkedIn */}
                      {selectedUser.teacher_profile.linkedin && (
                        <div>
                            <label className="text-xs text-gray-500 uppercase font-bold flex items-center gap-1 mb-1.5"><Linkedin size={14}/> LinkedIn</label>
                            <a href={selectedUser.teacher_profile.linkedin} target="_blank" rel="noreferrer" className="block break-all rounded-lg border border-gray-200 bg-gray-50 p-2 text-sm text-blue-600 hover:underline">
                                {selectedUser.teacher_profile.linkedin}
                            </a>
                        </div>
                      )}

                      {/* Preview CV */}
                      <div>
                        <label className="text-xs text-gray-500 uppercase font-bold flex items-center gap-1 mb-2">
                          <FileText size={14} /> Dokumen CV
                        </label>
                        {selectedUser.teacher_profile.cv_url ? (
                             <button
                                type="button"
                                onClick={() => void openProtectedFile(
                                  selectedUser.teacher_profile.cv_url,
                                  `cv-${selectedUser.name}.pdf`,
                                ).catch(() => notify.error("CV gagal dibuka."))}
                                className="flex items-center gap-3 p-3 border border-gray-200 rounded-xl hover:bg-orange-50 hover:border-orange-200 transition group cursor-pointer bg-white"
                             >
                                <div className="p-2 bg-red-100 text-red-600 rounded-lg">
                                    <FileText size={20} />
                                </div>
                                <div className="flex-1">
                                    <p className="text-sm font-bold text-gray-900">Lihat CV</p>
                                    <p className="text-xs text-gray-500">Buka pratinjau PDF tanpa mengunduh</p>
                                </div>
                                <ExternalLink size={18} className="text-gray-400 group-hover:text-orange-600"/>
                             </button>
                        ) : (
                            <div className="p-3 bg-gray-100 rounded-xl text-sm text-gray-500 italic">Tidak ada file CV.</div>
                        )}
                      </div>
                    </div>
                  )}
               </div>

               <div className="pt-4 mt-4 border-t border-gray-100">
                 <button onClick={() => setModalOpen(false)} className="w-full py-3 bg-gray-100 text-gray-700 font-bold rounded-xl hover:bg-gray-200 transition-colors">
                   Tutup
                 </button>
               </div>
            </div>
          </div>
        </div>
      )}

      {/* --- TABEL UTAMA --- */}
      <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        
        {/* HEADER & FILTER */}
        <div className="p-6 border-b border-gray-100 flex flex-col sm:flex-row justify-between items-center gap-4">
           {/* TAB SWITCHER */}
           <div className="flex bg-gray-100/80 p-1 rounded-xl w-full sm:w-auto">
              <button 
                onClick={() => { setActiveTab("student"); setPage(1); }}
                className={`flex-1 sm:flex-none flex items-center justify-center gap-2 px-4 py-2 rounded-lg text-sm font-bold transition-all ${activeTab === "student" ? "bg-white text-orange-600 shadow-sm" : "text-gray-500 hover:text-gray-700"}`}
              >
                <School size={16} /> Data Murid
              </button>
              <button 
                onClick={() => { setActiveTab("teacher"); setPage(1); }}
                className={`flex-1 sm:flex-none flex items-center justify-center gap-2 px-4 py-2 rounded-lg text-sm font-bold transition-all ${activeTab === "teacher" ? "bg-white text-orange-600 shadow-sm" : "text-gray-500 hover:text-gray-700"}`}
              >
                <GraduationCap size={16} /> Data Tutor
              </button>
              {canManageAdmins && <button onClick={() => { setActiveTab("admin"); setPage(1); }} className={`flex flex-1 items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-bold transition-all ${activeTab === "admin" ? "bg-white text-orange-600 shadow-sm" : "text-gray-500 hover:text-gray-700"}`}><Users size={16} /> Data Admin</button>}
           </div>

           {activeTab === "teacher" && <select value={subjectFilter} onChange={(e) => { setSubjectFilter(e.target.value); setPage(1); }} className="w-full rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-sm sm:w-52"><option value="">Semua mapel</option>{subjectOptions.map((subject) => <option key={subject} value={subject}>{subject}</option>)}</select>}

           {/* SEARCH BOX */}
           {activeTab !== "admin" && <div className="relative w-full sm:w-64">
             <input 
               type="text" 
               placeholder={activeTab === 'student' ? "Cari murid..." : "Cari tutor..."} 
               value={searchTerm}
               onChange={(e) => setSearchTerm(e.target.value)}
               className="w-full pl-10 pr-4 py-2 bg-gray-50 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-orange-500 focus:bg-white transition-all" 
             />
             <Search className="absolute left-3 top-2.5 text-gray-400" size={16} />
           </div>}
        </div>

        {activeTab === "admin" ? <div className="border-t border-gray-100 p-4 sm:p-6"><AdminAccountsPanel /></div> : <>
        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 px-6 py-4 text-sm">
          <span className="text-gray-500">Menampilkan {users.length} dari {meta.total} data</span>
          <div className="flex items-center gap-2"><button type="button" disabled={page <= 1} onClick={() => setPage((value) => Math.max(1, value - 1))} className="rounded-lg border border-gray-200 px-3 py-2 font-bold text-gray-600 disabled:opacity-40">Sebelumnya</button><span className="font-bold text-gray-700">Halaman {meta.current_page} / {meta.last_page}</span><button type="button" disabled={page >= meta.last_page} onClick={() => setPage((value) => Math.min(meta.last_page, value + 1))} className="rounded-lg border border-gray-200 px-3 py-2 font-bold text-gray-600 disabled:opacity-40">Berikutnya</button></div>
        </div>
        {/* Kartu mobile */}
        <div className="divide-y divide-gray-100 md:hidden">
          {isLoading ? <div className="p-10 text-center text-sm text-gray-500"><Loader2 className="mr-2 inline animate-spin" />Memuat data...</div> : filteredUsers.length > 0 ? filteredUsers.map((u) => (
            <article key={u.id} className={`p-4 ${u.status === "banned" ? "bg-red-50/50" : "bg-white"}`}>
              <div className="flex min-w-0 items-start gap-3"><div className={`grid h-10 w-10 shrink-0 place-items-center rounded-full text-sm font-bold ${u.role === "teacher" ? "bg-orange-100 text-orange-700" : "bg-blue-100 text-blue-700"}`}>{u.name.charAt(0).toUpperCase()}</div><div className="min-w-0 flex-1"><p className="break-words font-black text-gray-900">{u.name}</p><p className="mt-1 break-all text-xs text-gray-500">{u.email}</p></div><span className={`shrink-0 rounded-full px-2.5 py-1 text-[10px] font-black ${u.status === "active" ? "bg-green-100 text-green-700" : u.status === "rejected" ? "bg-amber-100 text-amber-800" : "bg-red-100 text-red-700"}`}>{u.status === "active" ? "Aktif" : u.status === "rejected" ? "Ditolak" : "Diblokir"}</span></div>
              <div className="mt-4 grid grid-cols-2 gap-2"><button type="button" onClick={() => openModal(u)} className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-gray-100 px-3 text-xs font-black text-gray-700"><Eye size={14} />Detail</button>{u.status === "rejected" ? <button type="button" disabled className="inline-flex min-h-11 cursor-not-allowed items-center justify-center gap-2 rounded-xl border border-gray-200 bg-gray-50 px-3 text-xs font-black text-gray-400" title="Tutor ditolak hanya dapat diproses ulang lewat menu Verifikasi Tutor."><Ban size={14} />Tidak aktif</button> : <button type="button" onClick={() => toggleStatus(u)} className={`inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border px-3 text-xs font-black ${u.status === "active" ? "border-red-200 text-red-600" : "border-green-200 text-green-700"}`}>{u.status === "active" ? <><Ban size={14} />Blokir</> : <><CheckCircle size={14} />Aktifkan</>}</button>}</div>
            </article>
          )) : <div className="p-10 text-center text-sm text-gray-500">Data tidak ditemukan.</div>}
        </div>

        {/* Tabel desktop */}
        <div className="hidden overflow-x-auto md:block">
          <table className="min-w-[760px] w-full text-left">
            <thead className="bg-gray-50 text-xs uppercase text-gray-500 font-semibold">
              <tr>
                <th className="px-6 py-4">Nama Lengkap</th>
                <th className="px-6 py-4">Email</th>
                {activeTab === "teacher" && <th className="px-6 py-4">Mapel</th>}<th className="px-6 py-4">Status</th>
                <th className="px-6 py-4 text-center">Detail</th>
                <th className="px-6 py-4 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {isLoading ? (
                  <tr><td colSpan={activeTab === "teacher" ? 6 : 5} className="px-6 py-10 text-center text-gray-500"><Loader2 className="animate-spin inline mr-2"/> Memuat data...</td></tr>
              ) : filteredUsers.length > 0 ? (
                filteredUsers.map((u) => (
                  <tr key={u.id} className={`hover:bg-gray-50 transition-colors ${u.status === 'banned' ? 'bg-red-50/50' : ''}`}>
                    <td className="px-6 py-4">
                      <div className="flex items-center gap-3">
                        <div className={`w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold border border-white shadow-sm ${u.role === 'teacher' ? 'bg-orange-100 text-orange-700' : 'bg-blue-100 text-blue-700'}`}>
                          {u.name.charAt(0).toUpperCase()}
                        </div>
                        <span className="font-medium text-gray-900">{u.name}</span>
                      </div>
                    </td>
                    <td className="px-6 py-4 text-sm text-gray-600">{u.email}</td>
                    {activeTab === "teacher" && <td className="px-6 py-4 text-sm font-semibold text-gray-700"><span>{u.subject_name || "Belum diisi"}</span>{u.subject_data_warning && <span className="mt-1 block rounded-lg bg-amber-100 px-2 py-1 text-[10px] font-black leading-4 text-amber-800">{u.subject_data_warning}</span>}</td>}
                    <td className="px-6 py-4">
                      <span className={`flex items-center gap-1.5 text-xs font-bold px-2 py-1 rounded-full w-fit ${u.status === 'active' ? 'bg-green-100 text-green-700' : u.status === 'rejected' ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-700'}`}>
                        <span className={`w-1.5 h-1.5 rounded-full ${u.status === 'active' ? 'bg-green-600' : u.status === 'rejected' ? 'bg-amber-600' : 'bg-red-600'}`}></span>
                        {u.status === 'active' ? 'Aktif' : u.status === 'rejected' ? 'Ditolak' : 'Blokir'}
                      </span>
                    </td>
                    <td className="px-6 py-4 text-center">
                      <button 
                        onClick={() => openModal(u)}
                        className="inline-flex items-center gap-2 px-3 py-1.5 bg-gray-100 text-gray-700 rounded-lg text-xs font-bold hover:bg-orange-100 hover:text-orange-700 transition-colors"
                      >
                        <Eye size={14} /> Lihat
                      </button>
                    </td>
                    <td className="px-6 py-4 text-right">
                      {u.status === 'rejected' ? (
                        <button
                          type="button"
                          disabled
                          title="Tutor ditolak hanya dapat diproses ulang lewat menu Verifikasi Tutor."
                          className="inline-flex cursor-not-allowed items-center gap-1 rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-bold text-gray-400"
                        >
                          <Ban size={14} /> Tidak aktif
                        </button>
                      ) : u.status === 'active' ? (
                        <button 
                          onClick={() => toggleStatus(u)}
                          className="inline-flex items-center gap-1 px-3 py-1.5 border border-red-200 text-red-600 rounded-lg text-xs font-bold hover:bg-red-50 transition-colors"
                        >
                          <Ban size={14} /> Blokir
                        </button>
                      ) : (
                        <button 
                          onClick={() => toggleStatus(u)}
                          className="inline-flex items-center gap-1 px-3 py-1.5 border border-green-200 text-green-600 rounded-lg text-xs font-bold hover:bg-green-50 transition-colors"
                        >
                          <Unlock size={14} /> Aktifkan
                        </button>
                      )}
                    </td>
                  </tr>
                ))
              ) : (
                <tr>
                  <td colSpan={activeTab === "teacher" ? 6 : 5} className="px-6 py-10 text-center text-gray-500">
                    Tidak ditemukan data {activeTab === 'student' ? 'murid' : 'tutor'} yang cocok.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        </>}
      </div>
    </AdminLayout>
  );
}

function guardianRelationship(value?: string) {
  return {
    orang_tua: "Orang tua",
    wali_keluarga: "Wali keluarga",
    wali_resmi: "Wali resmi lainnya",
  }[value || ""] || "Wali";
}
