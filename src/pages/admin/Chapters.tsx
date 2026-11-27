import { notify } from "@/lib/notify";
import { FormEvent, useEffect, useMemo, useState } from "react";
import { BookOpen, GraduationCap, Layers3, Loader2, Pencil, Plus, Search, Trash2 } from "lucide-react";
import AdminLayout from "@/components/AdminLayout";
import SubjectCombobox, { SubjectOption } from "@/components/SubjectCombobox";
import { useConfirmDialog } from "@/components/ConfirmDialogProvider";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Switch } from "@/components/ui/switch";
import http, { getApiError } from "@/lib/http";
import {
  educationDetailLabel,
  GRADES_BY_EDUCATION_LEVEL,
} from "@/lib/educationCatalog";

interface Chapter {
  id: number;
  curriculum_subject_id: number;
  subject: { id: number; name: string; group_name?: string; is_active?: boolean };
  education_level: string;
  grade: string;
  title: string;
  sort_order: number;
  is_active: boolean;
  source_reference?: string | null;
}

const grades = GRADES_BY_EDUCATION_LEVEL;
const emptyForm = { curriculum_subject_id: 0, subject_name: "", education_level: "SD", grade: "Kelas 1", title: "", sort_order: "0", is_active: true };

export default function Chapters() {
  const confirm = useConfirmDialog();
  const [chapters, setChapters] = useState<Chapter[]>([]);
  const [subjects, setSubjects] = useState<SubjectOption[]>([]);
  const [search, setSearch] = useState("");
  const [editing, setEditing] = useState<Chapter | null | undefined>(undefined);
  const [form, setForm] = useState(emptyForm);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  const load = async () => {
    setLoading(true);
    try {
      const [chapterResponse, subjectResponse] = await Promise.all([
        http.get<Chapter[]>("/admin/chapters?all=1"),
        http.get<SubjectOption[]>("/admin/subjects?all=1"),
      ]);
      setChapters(chapterResponse.data || []);
      setSubjects(subjectResponse.data || []);
    } catch (error) {
      notify.error(getApiError(error, "Katalog bab gagal dimuat."));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { void load(); }, []);

  const openForm = (chapter?: Chapter) => {
    setEditing(chapter || null);
    setForm(chapter ? {
      curriculum_subject_id: chapter.curriculum_subject_id,
      subject_name: chapter.subject.name,
      education_level: chapter.education_level,
      grade: chapter.grade,
      title: chapter.title,
      sort_order: String(chapter.sort_order),
      is_active: chapter.is_active,
    } : emptyForm);
  };

  const save = async (event: FormEvent) => {
    event.preventDefault();
    if (!form.curriculum_subject_id) {
      notify.error("Pilih mata pelajaran dari daftar.");
      return;
    }
    if (form.title.trim().length < 2) {
      notify.error("Nama bab minimal 2 karakter.");
      return;
    }
    setSaving(true);
    try {
      const payload = {
        curriculum_subject_id: form.curriculum_subject_id,
        education_level: form.education_level,
        grade: form.grade,
        title: form.title,
        sort_order: Number(form.sort_order),
        is_active: form.is_active,
        source_reference: editing?.source_reference || "SIBI · https://buku.kemendikdasmen.go.id/",
      };
      const response = editing
        ? await http.put(`/admin/chapters/${editing.id}`, payload)
        : await http.post("/admin/chapters", payload);
      notify.success(response.data.message);
      setEditing(undefined);
      await load();
    } catch (error) {
      notify.error(getApiError(error, "Bab gagal disimpan."));
    } finally {
      setSaving(false);
    }
  };

  const remove = async (chapter: Chapter) => {
    const approved = await confirm({
      title: "Hapus atau nonaktifkan bab?",
      description: `${chapter.subject.name} · ${chapter.grade} · ${chapter.title} tidak lagi muncul pada pemesanan baru. Riwayat pemesanan tetap aman.`,
      confirmText: "Lanjutkan",
      tone: "danger",
    });
    if (!approved) return;
    try {
      const response = await http.delete(`/admin/chapters/${chapter.id}`);
      notify.success(response.data.message);
      await load();
    } catch (error) {
      notify.error(getApiError(error));
    }
  };

  const filtered = useMemo(() => chapters.filter((chapter) => (
    `${chapter.subject.name} ${chapter.education_level} ${chapter.grade} ${chapter.title}`
      .toLocaleLowerCase("id-ID")
      .includes(search.toLocaleLowerCase("id-ID"))
  )), [chapters, search]);

  return (
    <AdminLayout title="Materi Kurikulum">
      <div className="space-y-6 pb-12">
        <section className="flex flex-col justify-between gap-5 rounded-[2rem] bg-gradient-to-br from-slate-950 to-indigo-950 p-6 text-white sm:p-8 md:flex-row md:items-end">
          <div><p className="text-xs font-black uppercase tracking-[.2em] text-indigo-200">Katalog pembelajaran</p><h1 className="mt-3 text-2xl font-black sm:text-3xl">Jenjang → tingkat → mapel → bab</h1><p className="mt-2 max-w-2xl text-sm leading-6 text-indigo-100/70">Katalog Bab bersifat opsional sebagai referensi kurikulum. Murid menjelaskan target atau materi belajar saat memesan; mapel tanpa katalog Bab tetap dapat dipesan. Pesanan lama yang memakai Bab tetap menyimpan progress-nya.</p></div>
          <Button onClick={() => openForm()} className="rounded-xl bg-orange-500 font-bold hover:bg-orange-600"><Plus size={16} className="mr-2" />Tambah bab</Button>
        </section>
        <div className="relative rounded-2xl border border-slate-100 bg-white p-4"><Search className="absolute left-7 top-7 text-slate-400" size={17} /><Input value={search} onChange={(event) => setSearch(event.target.value)} className="h-11 rounded-xl pl-10" placeholder="Cari mapel, jenjang, kelas, atau bab" /></div>
        <div className="overflow-hidden rounded-[2rem] border border-slate-100 bg-white">
          {loading ? <div className="grid min-h-64 place-items-center"><Loader2 className="animate-spin text-indigo-600" /></div> : filtered.length === 0 ? <div className="py-20 text-center"><Layers3 className="mx-auto h-10 w-10 text-slate-300" /><p className="mt-3 font-black text-slate-700">Bab tidak ditemukan</p></div> : (
            <div className="divide-y divide-slate-100">
              {filtered.map((chapter) => (
                <div key={chapter.id} className="flex flex-col justify-between gap-4 p-4 hover:bg-slate-50 sm:p-5 lg:flex-row lg:items-center">
                  <div className="flex min-w-0 gap-4"><div className="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-indigo-50 text-indigo-600"><BookOpen /></div><div className="min-w-0"><p className="font-black text-slate-900">{chapter.title}</p><p className="mt-1 text-sm text-slate-500">{chapter.subject.name} · {chapter.education_level} · {chapter.grade}</p><p className="mt-1 text-xs font-bold text-indigo-600">Urutan {chapter.sort_order}{!chapter.is_active ? " · Nonaktif" : ""}</p></div></div>
                  <div className="grid grid-cols-2 gap-2 sm:flex"><Button variant="outline" className="min-h-11 min-w-11 rounded-xl" aria-label={`Ubah bab ${chapter.title}`} onClick={() => openForm(chapter)}><Pencil size={16} className="sm:mr-2" /><span className="hidden sm:inline">Ubah</span></Button><Button variant="ghost" className="min-h-11 min-w-11 rounded-xl text-rose-600 hover:bg-rose-50" aria-label={`Hapus bab ${chapter.title}`} onClick={() => remove(chapter)}><Trash2 size={16} className="sm:mr-2" /><span className="hidden sm:inline">Hapus</span></Button></div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>

      <Dialog open={editing !== undefined} onOpenChange={(open) => !open && setEditing(undefined)}>
        <DialogContent className="overflow-visible rounded-[2rem] sm:max-w-lg">
          <form onSubmit={save} noValidate className="max-h-[calc(100dvh-5rem)] space-y-4 overflow-y-auto overscroll-contain">
            <DialogHeader><DialogTitle>{editing ? "Ubah bab" : "Tambah bab"}</DialogTitle><DialogDescription>Submateri opsional dan dapat ditulis murid pada catatan kebutuhan belajar.</DialogDescription></DialogHeader>
            <div><Label htmlFor="chapter-subject">Mata pelajaran</Label><SubjectCombobox id="chapter-subject" options={subjects} value={form.subject_name} educationLevel={form.education_level} grade={form.grade} onChange={(value, option) => setForm((current) => ({ ...current, subject_name: value, curriculum_subject_id: option?.id || 0 }))} className="mt-2" /></div>
            <div className="grid grid-cols-2 gap-3"><div><Label htmlFor="chapter-level">Jenjang</Label><Select value={form.education_level} onValueChange={(value) => setForm((current) => ({ ...current, education_level: value, grade: grades[value][0], curriculum_subject_id: 0, subject_name: "" }))}><SelectTrigger id="chapter-level" className="mt-2 h-11 rounded-xl"><SelectValue /></SelectTrigger><SelectContent>{Object.keys(grades).map((level) => <SelectItem key={level} value={level}>{level}</SelectItem>)}</SelectContent></Select></div><div><Label htmlFor="chapter-grade">{educationDetailLabel(form.education_level)}</Label><Select value={form.grade} onValueChange={(value) => setForm((current) => ({ ...current, grade: value, curriculum_subject_id: 0, subject_name: "" }))}><SelectTrigger id="chapter-grade" className="mt-2 h-11 rounded-xl"><SelectValue /></SelectTrigger><SelectContent>{grades[form.education_level].map((grade) => <SelectItem key={grade} value={grade}>{grade}</SelectItem>)}</SelectContent></Select></div></div>
            <div><Label htmlFor="chapter-title">Nama bab</Label><Input id="chapter-title" required minLength={2} className="mt-2 h-11 rounded-xl" value={form.title} onChange={(event) => setForm((current) => ({ ...current, title: event.target.value }))} /></div>
            <div><Label htmlFor="chapter-sort-order">Urutan</Label><Input id="chapter-sort-order" type="number" min="0" className="mt-2 h-11 rounded-xl" value={form.sort_order} onChange={(event) => setForm((current) => ({ ...current, sort_order: event.target.value }))} /></div>
            <label className="flex items-center justify-between rounded-xl bg-slate-50 p-4 text-sm font-bold text-slate-700"><span>Bab aktif</span><Switch checked={form.is_active} onCheckedChange={(value) => setForm((current) => ({ ...current, is_active: value }))} /></label>
            <Button disabled={saving} className="h-12 w-full rounded-xl bg-indigo-600 font-black hover:bg-indigo-700">{saving ? <Loader2 size={17} className="mr-2 animate-spin" /> : <GraduationCap size={17} className="mr-2" />}Simpan bab</Button>
          </form>
        </DialogContent>
      </Dialog>
    </AdminLayout>
  );
}
