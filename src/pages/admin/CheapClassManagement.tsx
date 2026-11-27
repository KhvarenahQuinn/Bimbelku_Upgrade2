import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { ArrowRight, BookOpen, CalendarRange, CheckCircle2, Clock3, Loader2, Plus, Shuffle, Tag, Trash2, Users } from "lucide-react";
import { Link } from "react-router-dom";
import AdminLayout from "@/components/AdminLayout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import http, { getApiError } from "@/lib/http";
import { notify } from "@/lib/notify";
import { dateInputValue } from "@/lib/date";

type Subject = { id: number; name: string; education_levels: string[]; grades: string[] };
type Chapter = { id: number; curriculum_subject_id: number; subject_name: string; education_level: string; grade: string; title: string };
type SubjectSelection = { subject_name: string; curriculum_chapter_id: string };
type ClassSession = { id: number; session_number: number; starts_at: string; ends_at: string };
type CheapClass = { id: number; package_code?: string; template_code?: string; package_kind?: "recurring" | "one_time"; recurrence_active?: boolean; next_publish_at?: string | null; subject_name: string; grade: string; chapter: string; starts_at: string; status: string; session_count: number; sessions?: ClassSession[]; price_per_student: number; participant_count: number; occupied_seat_count: number; confirmed_participant_count: number; pending_payment_count: number; minimum_participants: number; maximum_participants: number; teacher?: { name: string } | null; teacher_status_message?: string };
type SetupStatus = { ready: boolean; missing: string[]; message?: string | null };

const todayValue = () => dateInputValue(new Date());
const minimumStart = (closingMinutes = 60) => {
  const date = new Date();
  date.setMinutes(date.getMinutes() + closingMinutes + 1, 0, 0);
  if (date.getMinutes() > 0) date.setHours(date.getHours() + 1, 0, 0, 0);
  return date;
};
const timeInputValue = (date: Date) => `${String(date.getHours()).padStart(2, "0")}:00`;
const money = (value: number) => new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(value);
const dateTime = (value: string) => new Date(value).toLocaleString("id-ID", { weekday: "short", day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" });
const fullDateTime = (value: Date) => value.toLocaleString("id-ID", { weekday: "long", day: "numeric", month: "long", year: "numeric", hour: "2-digit", minute: "2-digit" });
const WEEKDAYS = [
  { value: 1, label: "Senin", short: "Sen" },
  { value: 2, label: "Selasa", short: "Sel" },
  { value: 3, label: "Rabu", short: "Rab" },
  { value: 4, label: "Kamis", short: "Kam" },
  { value: 5, label: "Jumat", short: "Jum" },
  { value: 6, label: "Sabtu", short: "Sab" },
  { value: 7, label: "Minggu", short: "Min" },
];
const isoWeekday = (date: Date) => date.getDay() === 0 ? 7 : date.getDay();
const firstEligibleSession = (minimum: Date, weekdays: number[], time: string) => {
  if (!weekdays.length || !time || Number.isNaN(minimum.getTime())) return null;
  const [hour, minute] = time.split(":").map(Number);
  const cursor = new Date(minimum);
  cursor.setHours(0, 0, 0, 0);
  for (let offset = 0; offset <= 7; offset += 1) {
    const candidate = new Date(cursor);
    candidate.setDate(candidate.getDate() + offset);
    candidate.setHours(hour, minute, 0, 0);
    if (weekdays.includes(isoWeekday(candidate)) && candidate.getTime() >= minimum.getTime()) return candidate;
  }
  return null;
};
const sessionSchedulePreview = (first: Date | null, weekdays: number[], sessionCount: number) => {
  if (!first || !weekdays.length || sessionCount < 1) return [];
  const result = [new Date(first)];
  const cursor = new Date(first);
  while (result.length < sessionCount) {
    cursor.setDate(cursor.getDate() + 1);
    if (weekdays.includes(isoWeekday(cursor))) result.push(new Date(cursor));
  }
  return result;
};

const subjectLimitForSessionCount = (sessionCount: number) => sessionCount >= 12 ? 3 : sessionCount >= 8 ? 2 : 1;

const initialForm = () => {
  const opening = minimumStart(0);
  const suggestedLearningDay = new Date(opening);
  suggestedLearningDay.setDate(suggestedLearningDay.getDate() + 2);
  return ({
  education_level: "",
  grade: "",
  subjects: [{ subject_name: "", curriculum_chapter_id: "" }] as SubjectSelection[],
  topic: "",
  registration_open_date: dateInputValue(opening),
  registration_open_time: timeInputValue(opening),
  start_time: timeInputValue(opening),
  duration_minutes: 60,
  session_count: "4",
  weekdays: [isoWeekday(suggestedLearningDay)],
  price_per_session: "25000",
  use_custom_price: false,
  custom_price_per_student: "",
  minimum_participants: "2",
  maximum_participants: "8",
  registration_window_hours: "24",
  registration_closes_before_minutes: "60",
  payment_window_minutes: "60",
  recurrence_enabled: false,
  });
};

export default function CheapClassManagement() {
  const [subjects, setSubjects] = useState<Subject[]>([]);
  const [chapters, setChapters] = useState<Chapter[]>([]);
  const [levels, setLevels] = useState<string[]>([]);
  const [gradesByLevel, setGradesByLevel] = useState<Record<string, string[]>>({});
  const [classes, setClasses] = useState<CheapClass[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [setup, setSetup] = useState<SetupStatus | null>(null);
  const [loadError, setLoadError] = useState("");
  const [form, setForm] = useState(initialForm);
  const [reviewOpen, setReviewOpen] = useState(false);

  const availableGrades = form.education_level ? gradesByLevel[form.education_level] || [] : [];
  const compatibleSubjects = useMemo(() => subjects.filter((item) =>
    (!form.education_level || item.education_levels.includes(form.education_level))
      && (!form.grade || item.grades.includes(form.grade))
  ), [subjects, form.education_level, form.grade]);
  const selectedSubjectNames = useMemo(() => form.subjects.map((item) => item.subject_name).filter(Boolean), [form.subjects]);
  const maximumSubjects = subjectLimitForSessionCount(Number(form.session_count || 1));
  const subjectsReady = form.subjects.length >= 1 && form.subjects.length <= maximumSubjects
    && form.subjects.every((item) => Boolean(item.subject_name && item.curriculum_chapter_id));
  const normalTotal = Number(form.price_per_session || 0) * Number(form.session_count || 0);
  const finalTotal = form.use_custom_price ? Number(form.custom_price_per_student || 0) : normalTotal;
  const maximumWeekdays = Math.min(4, Number(form.session_count || 1));
  const firstSessionPreview = useMemo(() => {
    const opensAt = new Date(`${form.registration_open_date}T${form.registration_open_time}:00`);
    if (Number.isNaN(opensAt.getTime())) return null;
    const minimum = new Date(opensAt.getTime()
      + Number(form.registration_window_hours || 0) * 3_600_000
      + Number(form.registration_closes_before_minutes || 0) * 60_000);
    return firstEligibleSession(minimum, form.weekdays, form.start_time);
  }, [form.registration_closes_before_minutes, form.registration_open_date, form.registration_open_time, form.registration_window_hours, form.start_time, form.weekdays]);
  const registrationOpensPreview = useMemo(() => {
    const value = new Date(`${form.registration_open_date}T${form.registration_open_time}:00`);
    return Number.isNaN(value.getTime()) ? null : value;
  }, [form.registration_open_date, form.registration_open_time]);
  const registrationDeadlinePreview = useMemo(() => registrationOpensPreview
    ? new Date(registrationOpensPreview.getTime() + Number(form.registration_window_hours || 0) * 3_600_000)
    : null, [form.registration_window_hours, registrationOpensPreview]);
  const sessionsPreview = useMemo(() => sessionSchedulePreview(
    firstSessionPreview,
    form.weekdays,
    Number(form.session_count || 0),
  ), [firstSessionPreview, form.session_count, form.weekdays]);
  const subjectsPreview = useMemo(() => form.subjects.map((selection) => ({
    subject: selection.subject_name || "Mapel belum dipilih",
    chapter: chapters.find((chapter) => chapter.id === Number(selection.curriculum_chapter_id))?.title || "Bab belum dipilih",
  })), [chapters, form.subjects]);
  const scheduleError = useMemo(() => {
    if (!form.registration_open_date || !form.registration_open_time) return "Pilih tanggal dan jam pembukaan pendaftaran.";
    if (!form.start_time) return "Pilih jam belajar.";
    if (!form.weekdays.length) return "Pilih sedikitnya satu hari belajar.";
    if (form.weekdays.length > maximumWeekdays) return Number(form.session_count) === 1 ? "Paket 1 sesi hanya boleh memakai 1 hari belajar." : `Hari belajar maksimal ${maximumWeekdays}.`;
    const opensAt = new Date(`${form.registration_open_date}T${form.registration_open_time}:00`);
    if (Number.isNaN(opensAt.getTime())) return "Tanggal atau jam pembukaan belum benar.";
    if (form.start_time >= "23:00") return "Sesi harus selesai pada hari yang sama. Pilih jam paling lambat 22.00.";
    if (opensAt.getTime() <= Date.now()) return "Waktu pembukaan sudah lewat. Pilih jam berikutnya.";
    if (!firstSessionPreview) return "Sesi pertama belum dapat dihitung dari hari belajar yang dipilih.";
    return "";
  }, [firstSessionPreview, form.registration_open_date, form.registration_open_time, form.session_count, form.start_time, form.weekdays, maximumWeekdays]);
  const formReady = Boolean(
    setup?.ready !== false
    && form.education_level
    && form.grade
    && subjectsReady
    && !scheduleError
    && sessionsPreview.length === Number(form.session_count)
    && finalTotal >= 1000
    && Number(form.minimum_participants) >= 2
    && Number(form.maximum_participants) >= Number(form.minimum_participants)
    && Number(form.maximum_participants) <= 12
    && Number(form.payment_window_minutes) === 60
    && Number(form.registration_closes_before_minutes) >= 30
  );
  const upcomingSessionCount = useMemo(() => classes.reduce((total, item) => total + (item.sessions?.length || item.session_count || 0), 0), [classes]);
  const waitingTeacherCount = useMemo(() => classes.filter((item) => item.status === "waiting_teacher").length, [classes]);
  const closestClass = useMemo(() => [...classes].sort((left, right) => new Date(left.starts_at).getTime() - new Date(right.starts_at).getTime())[0], [classes]);

  const load = useCallback(async () => {
    setLoading(true);
    setLoadError("");
    try {
      const [formResult, listResult] = await Promise.allSettled([
        http.get("/admin/cheap-class-templates/form"),
        http.get("/admin/cheap-class-templates"),
      ]);
      const formData = formResult.status === "fulfilled" ? formResult.value.data : null;
      const listData = listResult.status === "fulfilled" ? listResult.value.data : null;
      setSetup(listData?.setup || formData?.setup || { ready: true, missing: [] });
      setSubjects(formData?.subjects || []);
      setChapters(formData?.chapters || []);
      setLevels(formData?.education_levels || []);
      setGradesByLevel(formData?.grades_by_level || {});
      setClasses(listData?.classes || []);
      const errors = [
        formResult.status === "rejected" ? getApiError(formResult.reason, "Pilihan formulir belum dapat dimuat.") : "",
        listResult.status === "rejected" ? getApiError(listResult.reason, "Daftar paket Kelas Bersama belum dapat dimuat.") : "",
      ].filter(Boolean);
      if (errors.length) {
        const message = errors.join(" ");
        setLoadError(message);
        notify.error(message);
      }
    } catch (error) {
      const message = getApiError(error, "Data Kelas Bersama belum dapat dimuat.");
      setLoadError(message);
      notify.error(message);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void load(); }, [load]);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (!formReady) {
      notify.error("Lengkapi dan perbaiki data paket sebelum membuka ringkasan.");
      return;
    }
    setReviewOpen(true);
  };

  const createPackage = async () => {
    if (!formReady || saving) return;
    setSaving(true);
    try {
      const response = await http.post("/admin/cheap-class-templates", {
        ...form,
        subjects: form.subjects.map((item) => ({
          subject_name: item.subject_name,
          curriculum_chapter_id: Number(item.curriculum_chapter_id),
        })),
        session_count: Number(form.session_count),
        weekdays: form.weekdays,
        price_per_session: Number(form.price_per_session),
        custom_price_per_student: form.use_custom_price ? Number(form.custom_price_per_student) : null,
        minimum_participants: Number(form.minimum_participants),
        maximum_participants: Number(form.maximum_participants),
        registration_window_hours: Number(form.registration_window_hours),
        registration_closes_before_minutes: Number(form.registration_closes_before_minutes),
        payment_window_minutes: Number(form.payment_window_minutes),
      });
      notify.success(response.data.message);
      setReviewOpen(false);
      setForm(initialForm());
      await load();
    } catch (error) {
      notify.error(getApiError(error));
    } finally {
      setSaving(false);
    }
  };

  return <AdminLayout title="Kelas Bersama">
    <div className="w-full space-y-7 pb-12">
      <section className="rounded-[2rem] bg-gradient-to-br from-indigo-800 via-violet-800 to-fuchsia-800 px-7 py-8 text-white shadow-xl">
        <p className="text-xs font-black uppercase tracking-[.2em] text-indigo-100">Kelas online bersama</p>
        <h1 className="mt-3 text-3xl font-black">Kelas Bersama dikelola admin</h1>
        <p className="mt-2 max-w-3xl text-sm leading-6 text-indigo-100">Admin memilih 1–3 mapel beserta babnya, hari belajar, jumlah sesi, harga, dan kuota. Sistem mencari satu tutor yang mampu mengajar seluruh mapel dan tersedia pada semua sesi.</p>
      </section>

      {setup?.ready === false && <section className="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-950"><h2 className="font-black">Database Kelas Bersama perlu diperbarui</h2><p className="mt-2 text-sm font-semibold leading-6">{setup.message}</p><code className="mt-3 block w-fit rounded-xl bg-amber-950 px-3 py-2 text-xs font-bold text-white">php artisan migrate</code>{setup.missing.length > 0 && <p className="mt-3 text-xs">Data yang belum tersedia: {setup.missing.join(", ")}</p>}</section>}
      {loadError && <section className="flex flex-col gap-4 rounded-2xl border border-rose-200 bg-rose-50 p-5 text-rose-950 sm:flex-row sm:items-center sm:justify-between"><div><h2 className="font-black">Sebagian data belum dapat dimuat</h2><p className="mt-2 text-sm font-semibold leading-6">{loadError}</p></div><Button type="button" variant="outline" onClick={() => void load()} className="shrink-0 rounded-xl border-rose-200 bg-white text-rose-700 hover:bg-rose-100">Coba muat ulang</Button></section>}

      <div className="grid gap-7 xl:grid-cols-[.95fr_1.05fr]">
        <form onSubmit={submit} noValidate className="rounded-[2rem] border border-slate-100 bg-white p-6 shadow-sm">
          <div className="flex items-center gap-3"><span className="grid h-11 w-11 place-items-center rounded-2xl bg-indigo-50 text-indigo-600"><Plus size={20} /></span><div><h2 className="text-xl font-black text-slate-900">Buat paket</h2><p className="text-sm text-slate-500">{form.recurrence_enabled ? "Paket pertama dibuat sekarang, lalu paket baru diterbitkan setiap minggu." : "Satu kali simpan membuat satu paket beserta seluruh sesinya."}</p></div></div>
          <div className="mt-6 grid gap-4 sm:grid-cols-2">
            <Field label="Jenjang" htmlFor="cc-level"><Select value={form.education_level} onValueChange={(value) => setForm((current) => ({ ...current, education_level: value, grade: "", subjects: [{ subject_name: "", curriculum_chapter_id: "" }] }))}><SelectTrigger id="cc-level"><SelectValue placeholder="Pilih jenjang" /></SelectTrigger><SelectContent>{levels.map((level) => <SelectItem key={level} value={level}>{level}</SelectItem>)}</SelectContent></Select></Field>
            <Field label="Kelas" htmlFor="cc-grade"><Select value={form.grade} onValueChange={(value) => setForm((current) => ({ ...current, grade: value, subjects: [{ subject_name: "", curriculum_chapter_id: "" }] }))} disabled={!form.education_level}><SelectTrigger id="cc-grade"><SelectValue placeholder="Pilih kelas" /></SelectTrigger><SelectContent>{availableGrades.map((grade) => <SelectItem key={grade} value={grade}>{grade}</SelectItem>)}</SelectContent></Select></Field>
          </div>

          <div className="mt-5 rounded-2xl border border-slate-200 p-4">
            <div className="flex flex-wrap items-center justify-between gap-3"><div><p className="text-sm font-black text-slate-900">Mata pelajaran paket</p><p className="mt-1 text-xs text-slate-500">Paket 1 atau 4 sesi memakai 1 mapel, paket 8 sesi maksimal 2 mapel, dan paket 12 sesi maksimal 3 mapel.</p></div><span className="rounded-full bg-indigo-50 px-3 py-1 text-xs font-black text-indigo-700">{form.subjects.length}/{maximumSubjects} mapel</span></div>
            <div className="mt-4 space-y-3">
              {form.subjects.map((selection, index) => {
                const availableChapters = chapters.filter((item) => item.subject_name === selection.subject_name && item.education_level === form.education_level && item.grade === form.grade);
                return <div key={index} className="grid gap-3 rounded-xl bg-slate-50 p-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                  <Field label={`Mapel ${index + 1}`} htmlFor={`cc-subject-${index}`}><Select value={selection.subject_name} onValueChange={(value) => setForm((current) => ({ ...current, subjects: current.subjects.map((item, itemIndex) => itemIndex === index ? { subject_name: value, curriculum_chapter_id: "" } : item) }))} disabled={!form.grade}><SelectTrigger id={`cc-subject-${index}`}><SelectValue placeholder={form.grade ? "Pilih mapel" : "Pilih jenjang dan kelas"} /></SelectTrigger><SelectContent>{compatibleSubjects.filter((item) => item.name === selection.subject_name || !selectedSubjectNames.includes(item.name)).map((item) => <SelectItem key={item.id} value={item.name}>{item.name}</SelectItem>)}</SelectContent></Select></Field>
                  <Field label="Bab" htmlFor={`cc-chapter-${index}`}><Select value={selection.curriculum_chapter_id} onValueChange={(value) => setForm((current) => ({ ...current, subjects: current.subjects.map((item, itemIndex) => itemIndex === index ? { ...item, curriculum_chapter_id: value } : item) }))} disabled={!selection.subject_name}><SelectTrigger id={`cc-chapter-${index}`}><SelectValue placeholder={selection.subject_name ? "Pilih bab" : "Pilih mapel dahulu"} /></SelectTrigger><SelectContent>{availableChapters.map((chapter) => <SelectItem key={chapter.id} value={String(chapter.id)}>{chapter.title}</SelectItem>)}</SelectContent></Select></Field>
                  <Button type="button" variant="outline" aria-label={`Hapus mapel ${index + 1}`} disabled={form.subjects.length === 1} onClick={() => setForm((current) => ({ ...current, subjects: current.subjects.filter((_, itemIndex) => itemIndex !== index) }))} className="h-10 rounded-xl border-rose-200 text-rose-600 hover:bg-rose-50"><Trash2 size={16} /></Button>
                </div>;
              })}
            </div>
            <Button type="button" variant="outline" disabled={!form.grade || form.subjects.length >= maximumSubjects || compatibleSubjects.length <= form.subjects.length} onClick={() => setForm((current) => ({ ...current, subjects: [...current.subjects, { subject_name: "", curriculum_chapter_id: "" }] }))} className="mt-3 w-full rounded-xl border-indigo-200 text-indigo-700 hover:bg-indigo-50"><Plus className="mr-2" size={16} />Tambah mapel</Button>
          </div>

          <div className="mt-5 grid gap-4 sm:grid-cols-2">
            <Field label="Jumlah sesi dalam satu paket" htmlFor="cc-session-count"><Select value={form.session_count} onValueChange={(value) => setForm((current) => ({ ...current, session_count: value, subjects: current.subjects.slice(0, subjectLimitForSessionCount(Number(value))), weekdays: current.weekdays.slice(0, Math.min(4, Number(value))) }))}><SelectTrigger id="cc-session-count"><SelectValue /></SelectTrigger><SelectContent>{[1, 4, 8, 12].map((count) => <SelectItem key={count} value={String(count)}>{count} sesi</SelectItem>)}</SelectContent></Select></Field>
            <Field label="Tanggal pembukaan pendaftaran" htmlFor="cc-open-date"><Input id="cc-open-date" required type="date" min={todayValue()} value={form.registration_open_date} onChange={(event) => setForm((current) => ({ ...current, registration_open_date: event.target.value }))} /></Field>
            <Field label="Jam pembukaan pendaftaran" htmlFor="cc-open-time"><Input id="cc-open-time" required type="time" step="3600" value={form.registration_open_time} onChange={(event) => setForm((current) => ({ ...current, registration_open_time: event.target.value }))} /></Field>
            <Field label="Jam seluruh sesi" htmlFor="cc-start-time"><Input id="cc-start-time" required type="time" step="3600" value={form.start_time} onChange={(event) => setForm((current) => ({ ...current, start_time: event.target.value }))} /></Field>
          </div>

          <div className="mt-5 rounded-2xl border border-slate-200 p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div><p className="text-sm font-black text-slate-900">Hari belajar</p><p className="mt-1 text-xs text-slate-500">Pilih 1–{maximumWeekdays} hari. Paket satu sesi hanya memakai satu hari.</p></div>
              <span className="rounded-full bg-indigo-50 px-3 py-1 text-xs font-black text-indigo-700">{form.weekdays.length}/{maximumWeekdays} hari</span>
            </div>
            <div className="mt-4 grid grid-cols-4 gap-2 sm:grid-cols-7">
              {WEEKDAYS.map((day) => {
                const selected = form.weekdays.includes(day.value);
                const disabled = !selected && form.weekdays.length >= maximumWeekdays;
                return <button
                  key={day.value}
                  type="button"
                  disabled={disabled}
                  aria-pressed={selected}
                  aria-label={day.label}
                  onClick={() => setForm((current) => {
                    const exists = current.weekdays.includes(day.value);
                    if (exists && current.weekdays.length === 1) return current;
                    if (!exists && current.weekdays.length >= Math.min(4, Number(current.session_count))) return current;
                    return {
                      ...current,
                      weekdays: exists
                        ? current.weekdays.filter((value) => value !== day.value)
                        : [...current.weekdays, day.value].sort((left, right) => left - right),
                    };
                  })}
                  className={`h-11 rounded-xl border text-xs font-black transition ${selected ? "border-indigo-600 bg-indigo-600 text-white shadow-sm" : "border-slate-200 bg-white text-slate-600 hover:border-indigo-300"} disabled:cursor-not-allowed disabled:opacity-40`}
                >{day.short}</button>;
              })}
            </div>
            <p className="mt-3 text-xs font-semibold text-slate-600">Dipilih: {WEEKDAYS.filter((day) => form.weekdays.includes(day.value)).map((day) => day.label).join(", ")}.</p>
          </div>

          <div className="mt-5 rounded-2xl border border-indigo-100 bg-indigo-50 p-4">
            <div className="flex gap-3"><Shuffle className="mt-0.5 shrink-0 text-indigo-600" size={19} /><div><p className="text-sm font-black text-indigo-950">Satu paket, 1–3 mapel, satu tutor, satu pembayaran</p><p className="mt-1 text-xs leading-5 text-indigo-800">Sistem mencari satu tutor yang mampu mengajar seluruh mapel pilihan dan cocok untuk seluruh sesi. Jika belum ada, pencarian berjalan lagi saat jadwal tutor berubah dan setiap menit lewat scheduler.</p><p className="mt-1 text-xs font-bold text-indigo-900">Sesi disusun berurutan pada hari yang dipilih, dengan jam yang sama.</p></div></div>
          </div>

          <div className={`mt-5 rounded-2xl border p-4 ${form.recurrence_enabled ? "border-violet-200 bg-violet-50" : "border-slate-200 bg-white"}`}>
            <div className="flex items-start justify-between gap-4">
              <div><p className="text-sm font-black text-slate-900">Ulangi paket otomatis setiap minggu</p><p className="mt-1 text-xs leading-5 text-slate-600">Setiap minggu menjadi paket baru yang terpisah: peserta, pembayaran, tutor acak, kuota, Zoom, dan refund tidak digabung dengan paket sebelumnya.</p></div>
              <Switch checked={form.recurrence_enabled} onCheckedChange={(checked) => setForm((current) => ({ ...current, recurrence_enabled: checked }))} aria-label="Ulangi paket otomatis setiap minggu" />
            </div>
            <p className={`mt-3 text-xs font-black ${form.recurrence_enabled ? "text-violet-700" : "text-slate-500"}`}>{form.recurrence_enabled ? "Aktif · paket berikutnya mengikuti pola hari dan jam yang sama." : "Nonaktif · paket hanya dibuat sekali."}</p>
          </div>

          <div className="mt-5 rounded-2xl border border-slate-200 p-4">
            <div className="flex items-center gap-2"><Tag className="text-indigo-600" size={18} /><p className="text-sm font-black text-slate-900">Atur harga</p></div>
            <div className="mt-4 grid gap-4 sm:grid-cols-2">
              <Field label="Harga acuan per sesi" htmlFor="cc-price"><Input id="cc-price" required type="number" min="1000" step="1000" value={form.price_per_session} onChange={(event) => setForm((current) => ({ ...current, price_per_session: event.target.value }))} /></Field>
              <div className="rounded-xl bg-slate-50 p-3"><p className="text-xs font-bold text-slate-500">Total harga normal</p><p className="mt-1 text-lg font-black text-slate-900">{money(normalTotal)}</p></div>
            </div>
            <label className="mt-4 flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 bg-white p-3"><input type="checkbox" checked={form.use_custom_price} onChange={(event) => setForm((current) => ({ ...current, use_custom_price: event.target.checked, custom_price_per_student: event.target.checked ? current.custom_price_per_student : "" }))} className="h-4 w-4 accent-indigo-600" /><span><span className="block text-sm font-black text-slate-800">Gunakan harga custom</span><span className="block text-xs text-slate-500">Harga akhir paket ditentukan langsung oleh admin.</span></span></label>
            {form.use_custom_price && <Field label="Harga custom per murid" htmlFor="cc-custom-price" className="mt-4"><Input id="cc-custom-price" required type="number" min="1000" step="1000" value={form.custom_price_per_student} onChange={(event) => setForm((current) => ({ ...current, custom_price_per_student: event.target.value }))} placeholder="Contoh: 75000" /></Field>}
            <div className="mt-4 flex items-center justify-between rounded-xl bg-indigo-600 px-4 py-3 text-white"><span className="text-sm font-bold">Harga paket · dibayar sekali</span><span className="text-lg font-black">{money(finalTotal)}</span></div>
          </div>

          <div className="mt-5 grid gap-4 sm:grid-cols-2">
            <Field label="Batas kuota (2–12 peserta)" htmlFor="cc-min-participants"><div className="flex items-center gap-2"><Input id="cc-min-participants" aria-label="Kuota minimal peserta" required type="number" min="2" max="12" value={form.minimum_participants} onChange={(event) => setForm((current) => ({ ...current, minimum_participants: event.target.value }))} /><span className="font-bold text-slate-400">s.d.</span><Input aria-label="Kuota maksimal peserta" required type="number" min="2" max="12" value={form.maximum_participants} onChange={(event) => setForm((current) => ({ ...current, maximum_participants: event.target.value }))} /></div></Field>
            <Field label="Waktu pembayaran"><div className="flex min-h-10 items-center rounded-md border border-slate-200 bg-slate-50 px-3 text-sm font-black text-slate-700">1 jam · tetap</div></Field>
            <Field label="Durasi pendaftaran"><div className="flex h-10 items-center rounded-md border border-slate-200 bg-slate-50 px-3 text-sm font-black text-slate-700">24 jam · tetap</div></Field>
            <Field label="Jeda setelah pendaftaran tutup (menit)"><Input required type="number" min="30" value={form.registration_closes_before_minutes} onChange={(event) => setForm((current) => ({ ...current, registration_closes_before_minutes: event.target.value }))} /></Field>
          </div>
          <Field label="Catatan materi" className="mt-4"><Textarea value={form.topic} onChange={(event) => setForm((current) => ({ ...current, topic: event.target.value }))} placeholder="Tujuan atau penjelasan singkat kelas" /></Field>

          <div className={`mt-5 rounded-2xl p-4 ${scheduleError ? "bg-amber-50 text-amber-900" : "bg-emerald-50 text-emerald-900"}`}><p className="text-sm font-black">{scheduleError ? "Jadwal belum bisa disimpan" : "Jadwal siap disimpan"}</p><p className="mt-1 text-xs leading-5">{scheduleError || <>Pendaftaran dibuka {dateTime(`${form.registration_open_date}T${form.registration_open_time}:00`)}. Sesi pertama otomatis pada <b>{firstSessionPreview ? dateTime(firstSessionPreview.toISOString()) : "-"}</b>. {form.recurrence_enabled ? "Pola ini diterbitkan ulang setiap minggu sebagai paket terpisah." : "Paket ini hanya dibuat sekali."}</>}</p></div>
          <Button type="submit" disabled={saving || !formReady} className="mt-6 h-12 w-full rounded-xl bg-indigo-600 font-black hover:bg-indigo-700">{saving && <Loader2 className="mr-2 animate-spin" size={17} />}{formReady ? "Tinjau sebelum dibuat" : "Lengkapi data untuk meninjau"}</Button>
        </form>

        <div className="space-y-7">
          <section className="rounded-[2rem] border border-slate-100 bg-white p-6 shadow-sm"><h2 className="text-xl font-black text-slate-900">Paket yang sudah dibuat</h2><p className="mt-1 text-xs text-slate-500">Label membedakan paket sekali dibuat dan paket dari pengaturan mingguan.</p>{loading ? <Loading /> : classes.length ? <div className="mt-5 space-y-3">{classes.slice(0, 4).map((item) => <div key={item.id} className="rounded-2xl border border-slate-100 bg-slate-50/70 p-4"><div className="flex items-start justify-between gap-3"><div><div className="flex flex-wrap items-center gap-2"><p className="font-black text-slate-900">{item.subject_name} · {item.grade}</p><span className={`rounded-full px-2 py-1 text-[10px] font-black ${item.package_kind === "recurring" ? (item.recurrence_active ? "bg-violet-100 text-violet-700" : "bg-slate-200 text-slate-600") : "bg-white text-slate-500"}`}>{item.package_kind === "recurring" ? `Berulang · ${item.recurrence_active ? "Aktif" : "Nonaktif"}` : "Sekali dibuat"}</span></div><p className="mt-1 text-xs text-slate-500">{item.chapter} · {dateTime(item.starts_at)}</p><p className="mt-2 text-xs font-bold text-indigo-600">{item.session_count} sesi · {money(Number(item.price_per_student))} sekali bayar</p>{item.package_kind === "recurring" && item.recurrence_active && item.next_publish_at && <p className="mt-1 text-xs font-bold text-violet-700">Penerbitan berikutnya: {dateTime(item.next_publish_at)}</p>}<p className={`mt-2 text-xs font-bold ${item.teacher ? "text-emerald-700" : "text-amber-700"}`}>{item.teacher?.name ? `Tutor: ${item.teacher.name}` : "Tutor sedang dicari"}</p></div><span className="rounded-full bg-white px-2.5 py-1 text-[10px] font-black text-slate-600">{item.occupied_seat_count}/{item.maximum_participants} kursi · {item.confirmed_participant_count} terverifikasi</span></div></div>)}</div> : <Empty text="Belum ada paket." />}</section>
          <section className="rounded-[2rem] border border-slate-100 bg-white p-6 shadow-sm">
            <div className="flex items-center gap-3"><span className="grid h-10 w-10 place-items-center rounded-xl bg-indigo-50 text-indigo-600"><CalendarRange size={19} /></span><div><h2 className="text-xl font-black text-slate-900">Jadwal Kelas Bersama</h2><p className="text-xs text-slate-500">Ringkasan paket dan sesi mendatang.</p></div></div>
            {loading ? <Loading /> : <>
              <div className="mt-5 grid grid-cols-3 gap-2 sm:gap-3">
                <SummaryStat value={classes.length} label="Paket" />
                <SummaryStat value={upcomingSessionCount} label="Sesi" />
                <SummaryStat value={waitingTeacherCount} label="Dicari otomatis" warning={waitingTeacherCount > 0} />
              </div>
              <div className="mt-4 rounded-xl bg-slate-50 px-4 py-3 text-xs text-slate-600">{closestClass ? <>Jadwal terdekat: <b className="text-slate-900">{dateTime(closestClass.starts_at)}</b></> : "Belum ada kelas terjadwal."}</div>
              <Link to="/admin/kelas-murah/jadwal" className="mt-4 inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-4 text-sm font-black text-white hover:bg-indigo-700">Lihat semua jadwal <ArrowRight size={16} /></Link>
            </>}
          </section>
          <section className="rounded-[2rem] border border-indigo-100 bg-indigo-50 p-5"><div className="flex gap-3"><Users className="shrink-0 text-indigo-600" size={20} /><div><p className="font-black text-indigo-950">Kelas tanpa tutor tidak ditawarkan</p><p className="mt-1 text-xs leading-5 text-indigo-800">Status Mencari tutor hanya terlihat oleh admin. Sistem mencoba lagi otomatis; murid baru melihat kelas setelah tutor berhasil dikunci.</p></div></div></section>
          <section className="rounded-[2rem] border border-violet-100 bg-violet-50 p-5"><div className="flex gap-3"><Shuffle className="shrink-0 text-violet-600" size={20} /><div className="flex-1"><p className="font-black text-violet-950">Kelola pengulangan di halaman terpisah</p><p className="mt-1 text-xs leading-5 text-violet-800">Aktifkan atau hentikan penerbitan mingguan tanpa mengubah paket yang sudah terbit.</p><Link to="/admin/kelas-murah/berulang" className="mt-4 inline-flex h-10 items-center rounded-xl bg-violet-600 px-4 text-xs font-black text-white hover:bg-violet-700">Buka Paket Berulang <ArrowRight className="ml-2" size={15} /></Link></div></div></section>
        </div>
      </div>
    </div>

    {reviewOpen && <ScheduleReviewModal
      educationLevel={form.education_level}
      grade={form.grade}
      subjects={subjectsPreview}
      registrationOpens={registrationOpensPreview!}
      registrationDeadline={registrationDeadlinePreview!}
      sessions={sessionsPreview}
      durationMinutes={Number(form.duration_minutes)}
      weekdays={form.weekdays}
      sessionTime={form.start_time}
      minimumParticipants={Number(form.minimum_participants)}
      maximumParticipants={Number(form.maximum_participants)}
      paymentWindowMinutes={Number(form.payment_window_minutes)}
      price={finalTotal}
      recurring={form.recurrence_enabled}
      saving={saving}
      onBack={() => setReviewOpen(false)}
      onContinue={() => void createPackage()}
    />}
  </AdminLayout>;
}

function ScheduleReviewModal({
  educationLevel,
  grade,
  subjects,
  registrationOpens,
  registrationDeadline,
  sessions,
  durationMinutes,
  weekdays,
  sessionTime,
  minimumParticipants,
  maximumParticipants,
  paymentWindowMinutes,
  price,
  recurring,
  saving,
  onBack,
  onContinue,
}: {
  educationLevel: string;
  grade: string;
  subjects: { subject: string; chapter: string }[];
  registrationOpens: Date;
  registrationDeadline: Date;
  sessions: Date[];
  durationMinutes: number;
  weekdays: number[];
  sessionTime: string;
  minimumParticipants: number;
  maximumParticipants: number;
  paymentWindowMinutes: number;
  price: number;
  recurring: boolean;
  saving: boolean;
  onBack: () => void;
  onContinue: () => void;
}) {
  const selectedDays = WEEKDAYS.filter((day) => weekdays.includes(day.value)).map((day) => day.label).join(", ");
  return <div className="fixed inset-0 z-[var(--layer-modal)] grid place-items-center bg-slate-950/70 p-3 backdrop-blur-sm sm:p-5">
    <section role="dialog" aria-modal="true" aria-labelledby="cheap-class-review-title" className="flex max-h-[94dvh] w-full max-w-4xl flex-col overflow-hidden rounded-[2rem] bg-white shadow-2xl">
    <div className="shrink-0 bg-gradient-to-br from-slate-950 via-indigo-950 to-violet-900 p-5 text-white sm:p-6">
      <div className="flex items-start justify-between gap-4">
        <div><p className="text-[10px] font-black uppercase tracking-[.2em] text-indigo-200">Langkah terakhir</p><h2 id="cheap-class-review-title" className="mt-2 text-xl font-black sm:text-2xl">Konfirmasi paket Kelas Bersama</h2><p className="mt-1 text-xs leading-5 text-indigo-100/75">Periksa seluruh data sebelum paket dan sesinya dibuat.</p></div>
        <span className="shrink-0 rounded-full bg-white/10 px-3 py-1 text-[10px] font-black text-indigo-100">Belum tersimpan</span>
      </div>
      <div className="mt-5 grid grid-cols-3 gap-2">
        <PreviewMetric value={`${sessions.length || 0}`} label="Sesi" />
        <PreviewMetric value={money(price)} label="Harga" />
        <PreviewMetric value={`${minimumParticipants || 0}–${maximumParticipants || 0}`} label="Kuota" />
      </div>
    </div>

    <div className="min-h-0 flex-1 space-y-5 overflow-y-auto p-5 sm:p-6">
      <div>
        <div className="flex items-center gap-2 text-slate-900"><BookOpen size={17} className="text-indigo-600" /><p className="text-sm font-black">Materi</p></div>
        <p className="mt-1 text-xs font-bold text-slate-500">{educationLevel || "Jenjang belum dipilih"} · {grade || "Kelas belum dipilih"}</p>
        <div className="mt-3 grid gap-2 sm:grid-cols-2">{subjects.map((item, index) => <div key={`${item.subject}-${index}`} className="rounded-xl bg-slate-50 p-3"><p className="text-xs font-black text-slate-800">{index + 1}. {item.subject}</p><p className="mt-1 text-[11px] leading-4 text-slate-500">{item.chapter}</p></div>)}</div>
      </div>

      <div className="grid gap-3 sm:grid-cols-2">
        <PreviewInfo icon={<CalendarRange size={16} />} label="Pendaftaran dibuka" value={fullDateTime(registrationOpens)} />
        <PreviewInfo icon={<Clock3 size={16} />} label="Pendaftaran ditutup" value={fullDateTime(registrationDeadline)} />
        <PreviewInfo icon={<CalendarRange size={16} />} label="Pola hari" value={selectedDays || "Belum dipilih"} />
        <PreviewInfo icon={<Clock3 size={16} />} label="Jam & pembayaran" value={`${sessionTime || "--:--"} · batas bayar ${paymentWindowMinutes || 0} menit`} />
      </div>

      <div>
        <div className="flex items-center justify-between gap-3"><div><p className="text-sm font-black text-slate-900">Urutan sesi</p><p className="mt-1 text-[11px] text-slate-500">Tanggal ini mengikuti perhitungan yang dikirim ke backend.</p></div><span className={`rounded-full px-2.5 py-1 text-[10px] font-black ${recurring ? "bg-violet-100 text-violet-700" : "bg-slate-100 text-slate-600"}`}>{recurring ? "Berulang mingguan" : "Sekali dibuat"}</span></div>
        <div className="mt-3 grid gap-2 sm:grid-cols-2">{sessions.map((session, index) => {
          const endsAt = new Date(session.getTime() + durationMinutes * 60_000);
          return <div key={`${session.toISOString()}-${index}`} className="flex gap-3 rounded-xl border border-slate-100 bg-slate-50/80 p-3"><span className="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-indigo-600 text-xs font-black text-white">{index + 1}</span><div><p className="text-xs font-black text-slate-800">{session.toLocaleDateString("id-ID", { weekday: "short", day: "numeric", month: "short", year: "numeric" })}</p><p className="mt-1 text-[11px] text-slate-500">{session.toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" })}–{endsAt.toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" })}</p></div></div>;
        })}</div>
      </div>

      <div className="flex gap-3 rounded-xl bg-emerald-50 p-3 text-xs leading-5 text-emerald-800"><CheckCircle2 className="mt-0.5 shrink-0" size={16} /><p className="font-bold">Data sudah memenuhi syarat pembuatan. Pilih Kembali untuk mengubah data atau Lanjutkan untuk membuat paket.</p></div>
    </div>
    <div className="flex shrink-0 flex-col-reverse gap-2 border-t border-slate-100 bg-white p-4 sm:flex-row sm:justify-end sm:px-6">
      <Button type="button" variant="outline" disabled={saving} onClick={onBack} className="h-11 rounded-xl px-6 font-black">Kembali</Button>
      <Button type="button" disabled={saving} onClick={onContinue} className="h-11 rounded-xl bg-indigo-600 px-7 font-black hover:bg-indigo-700">{saving && <Loader2 className="mr-2 animate-spin" size={16} />}Lanjutkan</Button>
    </div>
    </section>
  </div>;
}

function PreviewMetric({ value, label }: { value: string; label: string }) { return <div className="rounded-xl bg-white/10 px-2 py-3 text-center backdrop-blur-sm"><p className="truncate text-sm font-black sm:text-base">{value}</p><p className="mt-1 text-[9px] font-bold uppercase tracking-wide text-indigo-200">{label}</p></div>; }
function PreviewInfo({ icon, label, value }: { icon: React.ReactNode; label: string; value: string }) { return <div className="rounded-xl border border-slate-100 p-3"><div className="flex items-center gap-2 text-indigo-600">{icon}<p className="text-[10px] font-black uppercase tracking-wide text-slate-400">{label}</p></div><p className="mt-2 text-xs font-bold leading-5 text-slate-700">{value}</p></div>; }

function Field({ label, htmlFor, children, className = "" }: { label: string; htmlFor?: string; children: React.ReactNode; className?: string }) { return <div className={className}><Label htmlFor={htmlFor} className="mb-2 block text-sm font-bold">{label}</Label>{children}</div>; }
function SummaryStat({ value, label, warning = false }: { value: number; label: string; warning?: boolean }) { return <div className={`rounded-xl p-3 text-center ${warning ? "bg-amber-50" : "bg-slate-50"}`}><p className={`text-xl font-black ${warning ? "text-amber-700" : "text-slate-900"}`}>{value}</p><p className="mt-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-500">{label}</p></div>; }
function Loading() { return <div className="grid min-h-32 place-items-center"><Loader2 className="animate-spin text-indigo-600" /></div>; }
function Empty({ text }: { text: string }) { return <p className="mt-5 rounded-2xl bg-slate-50 p-5 text-sm font-semibold text-slate-500">{text}</p>; }
