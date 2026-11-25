import { notify } from "@/lib/notify";
import { useEffect, useMemo, useState } from "react";
import {
  AlertCircle,
  CalendarClock,
  Clock3,
  Loader2,
  Plus,
  Power,
  Save,
  Sparkles,
  Trash2,
  CalendarX2,
} from "lucide-react";
import TeacherLayout from "@/components/TeacherLayout";
import { Button } from "@/components/ui/button";
import { Switch } from "@/components/ui/switch";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import http, { getApiError } from "@/lib/http";
import WorkspacePageIntro from "@/components/WorkspacePageIntro";
import { exceptionDateRangeError, maxExceptionEndDate } from "@/lib/teacherScheduleExceptions";

interface TimeRange {
  start_time: string;
  end_time: string;
}

interface ScheduleItem {
  day: string;
  is_active: boolean;
  ranges: TimeRange[];
}

interface AvailabilityException {
  id: number;
  start_date: string;
  end_date: string;
  reason?: string | null;
}

const DAYS = ["Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu", "Minggu"];
const HOUR_OPTIONS = Array.from({ length: 24 }, (_, hour) => `${String(hour).padStart(2, "0")}:00`);
const localToday = () => { const now = new Date(); return new Date(now.getTime() - now.getTimezoneOffset() * 60_000).toISOString().slice(0, 10); };
const defaultSchedules: ScheduleItem[] = DAYS.map((day) => ({ day, is_active: false, ranges: [] }));

const rangeIsValid = (range: TimeRange) => /^(?:[01]\d|2[0-3]):00$/.test(range.start_time)
  && /^(?:[01]\d|2[0-3]):00$/.test(range.end_time)
  && range.end_time > range.start_time;

const rangesOverlap = (ranges: TimeRange[]) => [...ranges]
  .sort((a, b) => a.start_time.localeCompare(b.start_time))
  .some((range, index, sorted) => index > 0 && sorted[index - 1].end_time > range.start_time);

export default function ManageSchedule() {
  const [schedules, setSchedules] = useState<ScheduleItem[]>(defaultSchedules);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [exceptions, setExceptions] = useState<AvailabilityException[]>([]);
  const [exceptionForm, setExceptionForm] = useState({ start_date: "", end_date: "", reason: "" });
  const [savingException, setSavingException] = useState(false);

  useEffect(() => {
    void fetchSchedule();
  }, []);

  const activeDays = useMemo(() => schedules.filter((item) => item.is_active).length, [schedules]);
  const totalAvailableHours = useMemo(() => schedules.reduce((total, item) => {
    if (!item.is_active) return total;
    return total + item.ranges.reduce((subtotal, range) => {
      if (!rangeIsValid(range)) return subtotal;
      return subtotal + Number(range.end_time.slice(0, 2)) - Number(range.start_time.slice(0, 2));
    }, 0);
  }, 0), [schedules]);

  const fetchSchedule = async () => {
    setLoading(true);
    try {
      const [response, exceptionResponse] = await Promise.all([
        http.get<ScheduleItem[]>("/teacher/schedule"),
        http.get<AvailabilityException[]>("/teacher/schedule-exceptions"),
      ]);
      setSchedules(response.data.map((item) => ({ ...item, ranges: item.ranges || [] })));
      setExceptions(exceptionResponse.data || []);
    } catch (error) {
      notify.error(getApiError(error, "Jadwal gagal dimuat."));
    } finally {
      setLoading(false);
    }
  };

  const updateItem = (index: number, changes: Partial<ScheduleItem>) => {
    setSchedules((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, ...changes } : item));
  };

  const toggleDay = (index: number, checked: boolean) => {
    setSchedules((current) => current.map((item, itemIndex) => {
      if (itemIndex !== index) return item;
      return {
        ...item,
        is_active: checked,
        ranges: checked && item.ranges.length === 0 ? [{ start_time: "08:00", end_time: "12:00" }] : item.ranges,
      };
    }));
  };

  const updateRange = (dayIndex: number, rangeIndex: number, changes: Partial<TimeRange>) => {
    setSchedules((current) => current.map((item, itemIndex) => {
      if (itemIndex !== dayIndex) return item;
      return {
        ...item,
        ranges: item.ranges.map((range, index) => index === rangeIndex ? { ...range, ...changes } : range),
      };
    }));
  };

  const addRange = (dayIndex: number) => {
    let added = false;
    setSchedules((current) => current.map((item, itemIndex) => {
      if (itemIndex !== dayIndex) return item;
      const occupied = [...item.ranges].sort((a, b) => a.start_time.localeCompare(b.start_time));
      let candidate: TimeRange | null = null;
      for (let startHour = 0; startHour <= 22; startHour += 1) {
        const preferredEnd = Math.min(23, startHour + 2);
        const next: TimeRange = {
          start_time: `${String(startHour).padStart(2, "0")}:00`,
          end_time: `${String(preferredEnd).padStart(2, "0")}:00`,
        };
        if (next.end_time > next.start_time && !occupied.some((range) => next.start_time < range.end_time && next.end_time > range.start_time)) {
          candidate = next;
          break;
        }
      }
      if (!candidate) return item;
      added = true;
      return { ...item, ranges: [...item.ranges, candidate].sort((a, b) => a.start_time.localeCompare(b.start_time)) };
    }));
    window.setTimeout(() => { if (!added) notify.error("Semua jam pada hari tersebut sudah terisi."); }, 0);
  };

  const removeRange = (dayIndex: number, rangeIndex: number) => {
    setSchedules((current) => current.map((item, itemIndex) => itemIndex === dayIndex
      ? { ...item, ranges: item.ranges.filter((_, index) => index !== rangeIndex) }
      : item));
  };

  const saveSchedule = async () => {
    const invalid = schedules.find((item) => item.is_active && (
      item.ranges.length === 0
      || item.ranges.some((range) => !rangeIsValid(range))
      || rangesOverlap(item.ranges)
    ));
    if (invalid) {
      notify.error(`Periksa kembali seluruh rentang waktu ${invalid.day}.`);
      return;
    }

    setSaving(true);
    try {
      const response = await http.post("/teacher/schedule", {
        schedules: schedules.map((item) => ({
          ...item,
          ranges: [...item.ranges].sort((a, b) => a.start_time.localeCompare(b.start_time)),
        })),
      });
      notify.success(response.data.message);
      await fetchSchedule();
    } catch (error) {
      notify.error(getApiError(error, "Jadwal gagal disimpan."));
    } finally {
      setSaving(false);
    }
  };

  const saveException = async () => {
    if (!exceptionForm.start_date || !exceptionForm.end_date) {
      notify.error("Pilih tanggal mulai dan selesai.");
      return;
    }
    if (exceptionDateRangeError(exceptionForm.start_date, exceptionForm.end_date)) return;
    setSavingException(true);
    try {
      const response = await http.post("/teacher/schedule-exceptions", {
        ...exceptionForm,
        reason: exceptionForm.reason.trim() || null,
      });
      notify.success(response.data.message);
      setExceptionForm({ start_date: "", end_date: "", reason: "" });
      await fetchSchedule();
    } catch (error) {
      notify.error(getApiError(error, "Tanggal tidak tersedia gagal disimpan."));
    } finally {
      setSavingException(false);
    }
  };

  const deleteException = async (id: number) => {
    try {
      const response = await http.delete(`/teacher/schedule-exceptions/${id}`);
      notify.success(response.data.message);
      setExceptions((current) => current.filter((item) => item.id !== id));
    } catch (error) {
      notify.error(getApiError(error, "Tanggal tidak tersedia gagal dihapus."));
    }
  };

  return (
    <TeacherLayout title="Jadwal Mengajar">
      <div className="mx-auto max-w-7xl space-y-7 pb-12">
        <WorkspacePageIntro eyebrow="Beberapa rentang per hari" title="Jadwal Mengajar" description="Atur jam yang benar-benar tersedia. Sistem menggunakan data ini untuk menyaring penawaran dan mencegah benturan kelas." icon={CalendarClock} metrics={[{ label: "Hari aktif", value: activeDays }, { label: "Total mingguan", value: `${totalAvailableHours} jam` }]} />

        <div data-tour="teacher-schedule-days" className="rounded-[2rem] border border-slate-100 bg-white p-5 shadow-sm md:p-7">
          {loading ? (
            <div className="flex min-h-[420px] items-center justify-center"><Loader2 className="h-9 w-9 animate-spin text-indigo-600" /></div>
          ) : (
            <div className="space-y-4">
              {schedules.map((item, dayIndex) => {
                const overlap = rangesOverlap(item.ranges);
                return (
                  <div key={item.day} className={`rounded-2xl border p-5 transition ${item.is_active ? "border-indigo-100 bg-indigo-50/35 shadow-sm" : "border-slate-100 bg-slate-50/70"}`}>
                    <div className="grid grid-cols-1 gap-5 lg:grid-cols-[220px_1fr] lg:items-start">
                      <div className="flex items-center justify-between gap-4 lg:border-r lg:border-slate-200 lg:pr-6">
                        <div>
                          <p className={`text-xl font-black ${item.is_active ? "text-slate-900" : "text-slate-400"}`}>{item.day}</p>
                          <p className={`mt-1 text-[10px] font-bold uppercase tracking-widest ${item.is_active ? "text-indigo-600" : "text-slate-400"}`}>{item.is_active ? `${item.ranges.length} rentang` : "Libur"}</p>
                        </div>
                        <Switch checked={item.is_active} onCheckedChange={(checked) => toggleDay(dayIndex, checked)} />
                      </div>

                      {item.is_active ? (
                        <div className="space-y-3">
                          {item.ranges.map((range, rangeIndex) => (
                            <div key={`${item.day}-${rangeIndex}`} className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-[1fr_auto_1fr_auto] sm:items-end">
                              <TimeSelect kind="start" label="Mulai" value={range.start_time} onChange={(value) => updateRange(dayIndex, rangeIndex, { start_time: value })} />
                              <div className="hidden h-12 items-center justify-center px-1 text-slate-300 sm:flex"><Clock3 size={19} /></div>
                              <TimeSelect kind="end" label="Selesai" value={range.end_time} onChange={(value) => updateRange(dayIndex, rangeIndex, { end_time: value })} />
                              <button type="button" aria-label={`Hapus rentang ${item.day}`} onClick={() => removeRange(dayIndex, rangeIndex)} className="grid h-12 w-full place-items-center rounded-xl bg-rose-50 text-rose-600 hover:bg-rose-100 sm:w-12">
                                <Trash2 size={18} />
                              </button>
                            </div>
                          ))}
                          {overlap && <p className="flex items-center gap-2 text-xs font-bold text-rose-600"><AlertCircle size={15} />Rentang pada hari ini bertabrakan.</p>}
                          <Button type="button" variant="outline" onClick={() => addRange(dayIndex)} className="h-11 rounded-xl border-dashed border-indigo-200 text-indigo-700">
                            <Plus size={17} className="mr-2" /> Tambah rentang
                          </Button>
                        </div>
                      ) : (
                        <div className="flex min-h-20 items-center justify-center rounded-2xl border border-dashed border-slate-200 bg-white/50 text-sm text-slate-400">
                          <Power size={16} className="mr-2" /> Aktifkan hari untuk mengatur jam kosong.
                        </div>
                      )}
                    </div>
                  </div>
                );
              })}

              <div className="mt-6 flex flex-col gap-4 rounded-2xl border border-amber-100 bg-amber-50 p-5 md:flex-row md:items-center md:justify-between">
                <div className="flex items-start gap-3 text-amber-900">
                  <AlertCircle size={20} className="mt-0.5 shrink-0" />
                  <div>
                    <p className="font-bold">Aturan rentang</p>
                    <p className="mt-1 text-sm leading-6 text-amber-800">Rentang boleh bersentuhan, seperti 08.00–12.00 dan 12.00–15.00. Rentang tidak boleh saling menimpa.</p>
                  </div>
                </div>
                <Button data-tour="teacher-schedule-save" onClick={saveSchedule} disabled={saving} className="h-12 shrink-0 rounded-xl bg-indigo-600 px-7 font-bold shadow-lg shadow-indigo-200 hover:bg-indigo-700">
                  {saving ? <Loader2 size={18} className="mr-2 animate-spin" /> : <Save size={18} className="mr-2" />}
                  Simpan jadwal
                </Button>
              </div>
            </div>
          )}
        </div>

        <section className="rounded-[2rem] border border-slate-100 bg-white p-5 shadow-sm md:p-7">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div><div className="flex items-center gap-2"><CalendarX2 className="text-rose-500" size={20} /><h2 className="text-xl font-black text-slate-900">Tanggal tidak tersedia</h2></div><p className="mt-2 text-sm leading-6 text-slate-500">Tambahkan cuti, libur, atau keperluan khusus. Sistem tidak akan menawarkan tutor pada tanggal tersebut.</p></div>
            <span className="rounded-full bg-rose-50 px-3 py-1 text-xs font-black text-rose-700">{exceptions.length} pengecualian</span>
          </div>
          <div className="mt-5 grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 md:grid-cols-[1fr_1fr_1.4fr_auto] md:items-end">
            <label><span className="mb-2 block text-xs font-black uppercase tracking-wider text-slate-500">Mulai</span><input type="date" min={localToday()} value={exceptionForm.start_date} onChange={(event) => setExceptionForm((current) => ({ ...current, start_date: event.target.value, end_date: current.end_date && (current.end_date < event.target.value || current.end_date > maxExceptionEndDate(event.target.value)) ? event.target.value : current.end_date }))} className="form-field" /></label>
            <label><span className="mb-2 block text-xs font-black uppercase tracking-wider text-slate-500">Selesai</span><input type="date" min={exceptionForm.start_date || localToday()} max={maxExceptionEndDate(exceptionForm.start_date)} value={exceptionForm.end_date} onChange={(event) => setExceptionForm((current) => ({ ...current, end_date: event.target.value }))} aria-describedby={exceptionDateRangeError(exceptionForm.start_date, exceptionForm.end_date) ? "exception-date-range-error" : undefined} className="form-field" /></label>
            <label><span className="mb-2 block text-xs font-black uppercase tracking-wider text-slate-500">Alasan (opsional)</span><input value={exceptionForm.reason} maxLength={255} onChange={(event) => setExceptionForm((current) => ({ ...current, reason: event.target.value }))} className="form-field" placeholder="Contoh: cuti keluarga" /></label>
            <Button type="button" onClick={() => void saveException()} disabled={savingException || Boolean(exceptionDateRangeError(exceptionForm.start_date, exceptionForm.end_date))} className="h-12 rounded-xl bg-rose-600 px-5 hover:bg-rose-700">{savingException ? <Loader2 className="mr-2 animate-spin" size={17} /> : <Plus className="mr-2" size={17} />}Tambah</Button>
          </div>
          {exceptionDateRangeError(exceptionForm.start_date, exceptionForm.end_date) && <p id="exception-date-range-error" role="alert" className="mt-2 text-sm font-medium text-rose-700">{exceptionDateRangeError(exceptionForm.start_date, exceptionForm.end_date)}</p>}
          <div className="mt-4 space-y-2">
            {exceptions.length ? exceptions.map((item) => <div key={item.id} className="flex flex-col gap-3 rounded-2xl border border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between"><div className="min-w-0"><p className="font-black text-slate-900">{new Date(`${item.start_date}T00:00:00`).toLocaleDateString("id-ID", { dateStyle: "long" })}{item.end_date !== item.start_date ? ` – ${new Date(`${item.end_date}T00:00:00`).toLocaleDateString("id-ID", { dateStyle: "long" })}` : ""}</p><p className="mt-1 text-xs text-slate-500">{item.reason || "Tidak tersedia"}</p></div><button type="button" onClick={() => void deleteException(item.id)} className="grid min-h-11 place-items-center rounded-xl bg-rose-50 px-4 text-rose-600 hover:bg-rose-100 sm:w-11 sm:px-0" aria-label="Hapus tanggal tidak tersedia"><Trash2 size={17} /></button></div>) : <p className="rounded-2xl border border-dashed border-slate-200 py-10 text-center text-sm text-slate-400">Belum ada tanggal khusus yang diblokir.</p>}
          </div>
        </section>
      </div>
    </TeacherLayout>
  );
}

function TimeSelect({ kind, label, value, onChange }: { kind: "start" | "end"; label: string; value: string; onChange: (value: string) => void }) {
  const options = kind === "start" ? HOUR_OPTIONS.slice(0, -1) : HOUR_OPTIONS.slice(1);
  return (
    <label className="min-w-0">
      <span className="mb-2 flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-slate-500"><CalendarClock size={14} /> {label}</span>
      <Select value={value} onValueChange={onChange}>
        <SelectTrigger
          aria-label={`Jam ${label.toLowerCase()}`}
          className="h-12 w-full min-w-0 rounded-xl border-slate-200 bg-white px-3 font-bold text-slate-800 focus:ring-2 focus:ring-indigo-100 focus:ring-offset-0"
        >
          <SelectValue placeholder="Pilih jam" />
        </SelectTrigger>
        <SelectContent
          position="popper"
          sideOffset={6}
          collisionPadding={12}
          className="z-[var(--layer-detail-popover)] max-h-[min(18rem,calc(100dvh-2rem))] rounded-2xl border-slate-200 bg-white shadow-2xl"
        >
          {options.map((time) => (
            <SelectItem key={time} value={time} className="min-h-10 rounded-xl py-2.5 text-sm font-bold">
              {time.replace(":", ".")}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </label>
  );
}

function Stat({ value, label }: { value: string; label: string }) {
  return (
    <div className="min-w-28 rounded-2xl border border-white/10 bg-white/10 px-5 py-3 text-center backdrop-blur">
      <p className="text-xl font-black">{value}</p>
      <p className="mt-1 text-[10px] font-bold uppercase tracking-widest text-indigo-100">{label}</p>
    </div>
  );
}
