import { useEffect, useMemo, useRef, useState } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import {
  AlertCircle,
  BookOpenCheck,
  CalendarPlus,
  Check,
  Clock3,
  FileCheck2,
  HelpCircle,
  Loader2,
  MapPin,
  Minus,
  Plus,
  RefreshCw,
  Sparkles,
  Tag,
  Trash2,
  WifiOff,
  X,
} from "lucide-react";

import PackageBuilderShell from "@/components/PackageBuilderShell";
import GuestOrderIdentity, { type GuestLocation } from "@/components/GuestOrderIdentity";
import PrivateParticipantsPicker from "@/components/PrivateParticipantsPicker";
import { emptyParticipantDetail, type PrivateParticipantDetail } from "@/lib/privateParticipants";
import SubjectCombobox, { SubjectOption } from "@/components/SubjectCombobox";
import { ResponsiveSelect } from "@/components/ResponsiveSelect";
import { EDUCATION_LEVELS, GRADES_BY_EDUCATION_LEVEL } from "@/lib/educationCatalog";
import { notify } from "@/lib/notify";
import LearningFocusPicker, { type LearningFocusMode } from "@/components/LearningFocusPicker";
import { maxWeekdaysForSessions, normalizePackageWeekdays, normalizeWeekdays } from "@/lib/packageWeekdays";

type Plan = {
  id: number;
  name: string;
  description?: string;
  session_count: number;
  validity_days: number;
  maximum_subjects: number;
};
type LearningProgram = { id: number; name: string; education_level: string; education_levels?: string[] | null; grade: string | null; subjects: Array<{ id: number; name: string; education_levels?: string[]; grades?: string[] }> };
const programLevels = (program: LearningProgram) => program.education_levels?.length ? program.education_levels : [program.education_level];
const programSupports = (program: LearningProgram, level: string, grade?: string) =>
  programLevels(program).includes(level)
  && (!grade || !program.grade || program.grade === grade)
  && (!grade || program.subjects.some((subject) =>
    (!subject.education_levels?.length || subject.education_levels.includes(level))
    && (!subject.grades?.length || subject.grades.includes(grade))));
type TimeSlot = { id: number; start_time: string; label?: string | null };
type BookingRules = {
  booking_lead_hours: number;
  renewal_booking_lead_hours: number;
  maximum_search_hours: number;
  matching_cutoff_hours: number;
  teacher_response_minutes: number;
};
type ScheduleRecommendation = {
  time: string;
  candidate_count: number;
  availability: "very_high" | "high" | "limited" | "unavailable";
  schedules: string[];
};
type ScheduleRecommendationResponse = {
  mode: "preserve_days";
  weekdays: number[];
  lead_hours: number;
  recommendations: ScheduleRecommendation[];
  message: string;
};
type Voucher = {
  id: number;
  status: string;
  promotion?: { id: number; title: string; discount_type: "percentage" | "fixed"; discount_value: number; ends_at?: string | null } | null;
};
type DurationHours = 1 | 2;
type LearningPath = "program" | "subject";
type DraftSubject = {
  key: string;
  curriculum_subject_id: number | "";
  subject_name: string;
  session_count: number;
  schedules: string[];
  schedule_start_date: string;
  schedule_time: string;
  weekdays: number[];
  curriculum_chapter_ids: number[];
  chapter: string;
  learning_goal: string;
  focus_mode?: LearningFocusMode;
  preferred_teacher_id?: number;
};
type Quote = {
  lines: Array<{ curriculum_subject_id: number; subject_name: string; session_count: number; duration_hours: DurationHours; unit_price: number; meeting_price: number; subtotal_amount: number }>;
  duration_hours: DurationHours;
  total_learning_hours: number;
  subtotal_amount: number;
  discount_amount: number;
  group_discount_amount?: number;
  participant_count?: number;
  total_amount: number;
  promotion?: { title: string } | null;
};
type ErrorType = "network" | "unauthorized" | "forbidden" | "not_found" | "generic" | null;
type StudentProfile = {
  id?: number;
  address?: string | null;
  maps_link?: string | null;
  latitude?: number | string | null;
  longitude?: number | string | null;
  location_consent_at?: string | null;
};
type SavedDraft = {
  saved_at: string;
  learning_path?: LearningPath;
  learning_program_id?: number | null;
  plan_id: number | "";
  level: string;
  grade: string;
  mode: "online" | "offline";
  duration_hours: DurationHours;
  subjects: DraftSubject[];
  promo_code: string;
  voucher_id: number | "";
  participant_count?: number;
  purchaser_participates?: boolean;
  participant_details?: PrivateParticipantDetail[];
};

const DRAFT_KEY = "bimbelku.package-builder.stage-6a";
const MULTI_SUBJECT_TUTORIAL_KEY = "bimbelku.tutorial.student.multi-subject.v1";

const draftKeyForCurrentUser = () => {
  try {
    const user = JSON.parse(localStorage.getItem("user") || "null") as { id?: number | string } | null;
    const userId = Number(user?.id);
    return Number.isInteger(userId) && userId > 0 ? `${DRAFT_KEY}.${userId}` : null;
  } catch {
    return null;
  }
};

const rupiah = (value: number) =>
  new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(value || 0);

const dateInput = (date: Date) => {
  const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);
  return local.toISOString().slice(0, 10);
};

const earliestScheduleDate = (time: string, leadHours = 24) => {
  const threshold = new Date(Date.now() + leadHours * 60 * 60 * 1000);
  const [hour] = time.split(":").map(Number);
  const candidate = new Date(threshold);
  candidate.setHours(hour || 0, 0, 0, 0);
  if (candidate < threshold) candidate.setDate(candidate.getDate() + 1);
  return dateInput(candidate);
};

const normalizeDurationHours = (value: unknown): DurationHours => Number(value) === 2 ? 2 : 1;

const slotSupportsDuration = (time: string, durationHours: DurationHours) => {
  const hour = Number(time.slice(0, 2));
  return Number.isInteger(hour) && hour >= 0 && hour + durationHours <= 23;
};

const slotsForDuration = (slots: TimeSlot[], durationHours: DurationHours) =>
  slots.filter((slot) => slotSupportsDuration(slot.start_time.slice(0, 5), durationHours));

const preferredSlotTime = (slots: TimeSlot[], durationHours: DurationHours) => {
  const compatible = slotsForDuration(slots, durationHours);
  return compatible.find((slot) => slot.start_time.slice(0, 5) === "18:00")?.start_time.slice(0, 5)
    || compatible[0]?.start_time.slice(0, 5)
    || "18:00";
};

const timeRange = (value: string, durationHours: DurationHours) => {
  const start = new Date(value);
  const end = new Date(start.getTime() + durationHours * 3_600_000);
  return `${start.toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" })}–${end.toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" })}`;
};

const fullSchedule = (value: string, durationHours: DurationHours) =>
  `${new Date(value).toLocaleDateString("id-ID", { weekday: "short", day: "numeric", month: "short" })} · ${timeRange(value, durationHours)} WIB`;

const WEEKDAYS = [
  { value: 1, label: "Senin", short: "Sen" },
  { value: 2, label: "Selasa", short: "Sel" },
  { value: 3, label: "Rabu", short: "Rab" },
  { value: 4, label: "Kamis", short: "Kam" },
  { value: 5, label: "Jumat", short: "Jum" },
  { value: 6, label: "Sabtu", short: "Sab" },
  { value: 7, label: "Minggu", short: "Min" },
];

const isoWeekday = (date: Date) => ((date.getDay() + 6) % 7) + 1;

const nextSlots = (count: number, time = "18:00", startDate?: string, weekdays: number[] = []) => {
  const result: string[] = [];
  if (!weekdays.length) return result;
  const cursor = startDate ? new Date(`${startDate}T00:00:00`) : new Date();
  if (!startDate) cursor.setDate(cursor.getDate() + 4);
  const [hour] = time.split(":").map(Number);
  cursor.setHours(hour || 0, 0, 0, 0);
  const selectedDays = new Set(weekdays);
  let guard = 0;
  while (result.length < count && guard < 730) {
    if (selectedDays.has(isoWeekday(cursor))) {
      const local = new Date(cursor.getTime() - cursor.getTimezoneOffset() * 60_000);
      result.push(local.toISOString().slice(0, 16));
    }
    cursor.setDate(cursor.getDate() + 1);
    guard += 1;
  }
  return result;
};

const createSubject = (sessionCount = 1, time = "", offsetDays = 0, withSchedule = false, leadHours = 24): DraftSubject => {
  const scheduleBase = withSchedule ? new Date(`${earliestScheduleDate(time || "18:00", leadHours)}T00:00:00`) : null;
  if (scheduleBase && offsetDays > 0) scheduleBase.setDate(scheduleBase.getDate() + offsetDays);
  const scheduleStartDate = scheduleBase ? dateInput(scheduleBase) : "";
  const weekdays = withSchedule ? normalizeWeekdays(sessionCount >= 10 ? [1, 3, 5] : [2, 4], maxWeekdaysForSessions(sessionCount)) : [];
  return {
    key: `${Date.now()}-${Math.random()}`,
    curriculum_subject_id: "",
    subject_name: "",
    session_count: sessionCount,
    schedule_start_date: scheduleStartDate,
    schedule_time: time,
    weekdays,
    schedules: withSchedule ? nextSlots(sessionCount, time, scheduleStartDate, weekdays) : [],
    curriculum_chapter_ids: [],
    chapter: "",
    learning_goal: "",
  };
};

const rebuildSchedules = (subject: DraftSubject, count = subject.session_count) => ({
  ...subject,
  session_count: count,
  schedules: subject.schedule_time && subject.schedule_start_date && subject.weekdays.length
    ? nextSlots(count, subject.schedule_time, subject.schedule_start_date, subject.weekdays)
    : [],
});

const rebalance = (items: DraftSubject[], total: number) => {
  const base = Math.floor(total / items.length);
  const remainder = total % items.length;
  return items.map((item, index) => rebuildSchedules(item, base + (index < remainder ? 1 : 0)));
};

function PackageBuilderIntro({ renewal }: { renewal: boolean }) {
  return (
    <header className="min-w-0 border-b border-slate-200 pb-5">
      <h1 className="text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">{renewal ? "Lanjutkan paket belajarmu" : "Susun paket belajarmu"}</h1>
      <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-600">{renewal ? "Target sebelumnya tersimpan. Perbarui target belajar bila perlu dan tentukan jadwal paket baru." : "Pilih kebutuhan dan jadwalmu. Periksa biaya sebelum membayar; tutor dicari setelah pembayaran terkonfirmasi."}</p>
    </header>
  );
}
function PackageBuilderSkeleton() {
  return (
    <div role="status" aria-live="polite" aria-label="Memuat formulir paket belajar" className="space-y-5 sm:space-y-6">
      <div className="rounded-3xl border border-slate-100 bg-white p-3 shadow-sm" aria-hidden="true">
        <div className="h-12 animate-pulse rounded-2xl bg-slate-100 motion-reduce:animate-none" />
      </div>
      <section aria-hidden="true">
        <div className="mb-3 h-6 w-48 rounded-lg bg-slate-200" />
        <div className="grid grid-cols-1 gap-3 min-[360px]:grid-cols-2 xl:grid-cols-4">
          {[0, 1, 2, 3].map((item) => (
            <div key={item} className="min-h-40 animate-pulse rounded-3xl border border-slate-100 bg-white p-5 motion-reduce:animate-none">
              <div className="h-4 w-20 rounded bg-indigo-100" />
              <div className="mt-4 h-6 w-3/4 rounded-lg bg-slate-200" />
              <div className="mt-3 h-4 w-full rounded bg-slate-100" />
            </div>
          ))}
        </div>
      </section>
      <span className="sr-only">Formulir paket belajar sedang dimuat.</span>
    </div>
  );
}

export default function PackageBuilder() {
  const navigate = useNavigate();
  const isGuest = !localStorage.getItem("token");
  const [searchParams] = useSearchParams();
  const renewalId = Number(searchParams.get("renew") || 0) || undefined;
  const renewalSubjectId = Number(searchParams.get("subject") || 0) || undefined;
  const requestedLearningProgramId = Number(searchParams.get("learning_program") || 0) || undefined;
  const requestedSubjectName = (searchParams.get("subject_name") || "").trim();
  const requestedEducationLevel = (searchParams.get("education_level") || "").trim();
  const requestedLearningMode = searchParams.get("mode") === "online" ? "online" : searchParams.get("mode") === "offline" ? "offline" : "";
  const [plans, setPlans] = useState<Plan[]>([]);
  const [learningPath, setLearningPath] = useState<LearningPath | "">(
    renewalId || requestedSubjectName ? "subject" : requestedLearningProgramId ? "program" : "",
  );
  const [learningProgram, setLearningProgram] = useState<LearningProgram | null>(null);
  const [learningPrograms, setLearningPrograms] = useState<LearningProgram[]>([]);
  const [catalog, setCatalog] = useState<SubjectOption[]>([]);
  const [timeSlots, setTimeSlots] = useState<TimeSlot[]>([]);
  const [bookingRules, setBookingRules] = useState<BookingRules>({
    booking_lead_hours: 24,
    renewal_booking_lead_hours: 12,
    maximum_search_hours: 24,
    matching_cutoff_hours: 2,
    teacher_response_minutes: 60,
  });
  const [vouchers, setVouchers] = useState<Voucher[]>([]);
  const [planId, setPlanId] = useState<number | "">("");
  const [level, setLevel] = useState("");
  const [grade, setGrade] = useState("");
  const [mode, setMode] = useState<"" | "online" | "offline">(requestedLearningMode);
  const [durationHours, setDurationHours] = useState<DurationHours | "">("");
  const [subjects, setSubjects] = useState<DraftSubject[]>([createSubject(0)]);
  const [promoCode, setPromoCode] = useState("");
  const [voucherId, setVoucherId] = useState<number | "">("");
  const [participantCount, setParticipantCount] = useState(1);
  const [purchaserParticipates, setPurchaserParticipates] = useState(true);
  const [participantDetails, setParticipantDetails] = useState<PrivateParticipantDetail[]>([]);
  const [multiParticipantReady, setMultiParticipantReady] = useState(false);
  const [maximumParticipants, setMaximumParticipants] = useState(8);
  const [participantDiscounts, setParticipantDiscounts] = useState<Record<string, number>>({});
  const [quote, setQuote] = useState<Quote | null>(null);
  const [promoError, setPromoError] = useState<string | null>(null);
  const [studentProfile, setStudentProfile] = useState<StudentProfile | null>(null);
  const [guestName, setGuestName] = useState("");
  const [guestEmail, setGuestEmail] = useState("");
  const [guestPhone, setGuestPhone] = useState("");
  const [guestLocation, setGuestLocation] = useState<GuestLocation>({ address: "", maps_link: "", latitude: null, longitude: null, location_consent: false });
  const [scheduleRecommendations, setScheduleRecommendations] = useState<Record<string, ScheduleRecommendationResponse>>({});
  const [recommendationLoading, setRecommendationLoading] = useState<Record<string, boolean>>({});
  const [summaryOpen, setSummaryOpen] = useState(false);
  const [step, setStep] = useState(0);
  const [draftSavedAt, setDraftSavedAt] = useState<string | null>(null);
  const [draftRestored, setDraftRestored] = useState(false);
  const [pendingDraft, setPendingDraft] = useState<SavedDraft | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<ErrorType>(null);
  const [retryKey, setRetryKey] = useState(0);
  const [quoting, setQuoting] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [hasMultiSubjectPackage, setHasMultiSubjectPackage] = useState(false);
  const [tutorialStatusLoaded, setTutorialStatusLoaded] = useState(false);
  const multiSubjectGuideOpened = useRef(false);
  const quoteRequestIdRef = useRef(0);
  const draftStorageKey = useMemo(() => {
    const base = draftKeyForCurrentUser();
    return base && requestedLearningProgramId ? `${base}.program.${requestedLearningProgramId}` : base;
  }, [requestedLearningProgramId]);

  useEffect(() => {
    void import("@/lib/http").then(({ getCached }) => getCached<{ multi_participant_ready: boolean; maximum_participants: number; tiers?: Record<string, { discount_percent?: number | null }> }>("/private-participant-pricing", { maxAgeMs: 60_000 })
      .then(({ data }) => {
        setMultiParticipantReady(Boolean(data.multi_participant_ready));
        setMaximumParticipants(Math.max(2, Number(data.maximum_participants || 8)));
        setParticipantDiscounts(Object.fromEntries(Object.entries(data.tiers || {}).map(([count, tier]) => [count, Number(tier?.discount_percent || 0)])));
      })
      .catch(() => {
        setMultiParticipantReady(false);
        setMaximumParticipants(8);
        setParticipantDiscounts({});
      }));
  }, []);

  const plan = plans.find((item) => item.id === planId);
  const weekdayLimit = maxWeekdaysForSessions(Number(plan?.session_count || 0));
  useEffect(() => {
    if (!plan?.session_count) return;
    setSubjects((current) => {
      const normalized = normalizePackageWeekdays(current, plan.session_count);
      if (normalized.every((item, index) => item === current[index])) return current;
      return normalized.map((item, index) => item === current[index] ? item : rebuildSchedules(item));
    });
    setScheduleRecommendations({});
  }, [plan?.session_count]);
  const packageWeekdays = new Set(subjects.flatMap((item) => item.weekdays));
  // Nilai aman hanya dipakai untuk menghitung tampilan. Pilihan pengguna tetap kosong sampai dipilih.
  const effectiveDurationHours: DurationHours = durationHours || 1;
  const availableTimeSlots = useMemo(
    () => slotsForDuration(timeSlots, effectiveDurationHours),
    [effectiveDurationHours, timeSlots],
  );
  const defaultSlotTime = preferredSlotTime(timeSlots, effectiveDurationHours);
  const renewalKeepsSameTutor = Boolean(renewalId)
    && subjects.length > 0
    && subjects.every((item) => Number(item.preferred_teacher_id || 0) > 0);
  const bookingLeadHours = renewalKeepsSameTutor
    ? bookingRules.renewal_booking_lead_hours
    : bookingRules.booking_lead_hours;
  const selectedSessions = subjects.reduce((sum, item) => sum + item.session_count, 0);
  const selectedSubjectIds = subjects.map((item) => item.curriculum_subject_id).filter(Boolean);
  const subjectsAreUnique = new Set(selectedSubjectIds).size === selectedSubjectIds.length;
  const scheduleTimestamps = subjects.flatMap((item) => item.schedules).filter(Boolean);
  const sortedScheduleStarts = scheduleTimestamps
    .map((value) => new Date(value).getTime())
    .filter(Number.isFinite)
    .sort((a, b) => a - b);
  const schedulesDoNotOverlap = sortedScheduleStarts.every((start, index) =>
    index === sortedScheduleStarts.length - 1 || start + effectiveDurationHours * 3_600_000 <= sortedScheduleStarts[index + 1]
  );
  const scheduleDates = scheduleTimestamps
    .map((value) => {
      const date = new Date(value);
      date.setHours(0, 0, 0, 0);
      return date.getTime();
    })
    .filter(Number.isFinite);
  const scheduleRangeDays = scheduleDates.length
    ? (Math.max(...scheduleDates) - Math.min(...scheduleDates)) / 86_400_000
    : 0;
  const availableSubjects = useMemo(
    () => catalog.filter((item) =>
      (!item.education_levels?.length || item.education_levels.includes(level))
      && (!item.grades?.length || item.grades.includes(grade))),
    [catalog, grade, level],
  );
  const availablePrograms = learningPrograms.filter((program) => programSupports(program, level, grade));
  const subjectsForPicker = (item: DraftSubject) => availableSubjects.filter((subject) =>
    (!learningProgram || learningProgram.subjects.some((allowed) => allowed.id === subject.id))
    && !subjects.some((other) => other.key !== item.key && other.curriculum_subject_id === subject.id),
  );
  const selectedLocation = isGuest ? guestLocation : studentProfile;
  const expectedParticipantDetails = Math.max(0, participantCount - (purchaserParticipates ? 1 : 0));
  const participantDetailsValid = participantDetails.slice(0, expectedParticipantDetails).length === expectedParticipantDetails
    && participantDetails.slice(0, expectedParticipantDetails).every((detail) => detail.full_name.trim().length >= 2
      && detail.nickname.trim().length >= 1
      && Boolean(detail.birth_date)
      && (detail.gender === "male" || detail.gender === "female"));
  const hasOfflineLocation = Boolean(
    selectedLocation?.address?.trim()
    && selectedLocation?.latitude !== null
    && selectedLocation?.latitude !== undefined
    && String(selectedLocation.latitude).trim() !== ""
    && Number.isFinite(Number(selectedLocation.latitude))
    && selectedLocation?.longitude !== null
    && selectedLocation?.longitude !== undefined
    && String(selectedLocation.longitude).trim() !== ""
    && Number.isFinite(Number(selectedLocation.longitude)),
  );
  const draftValid = Boolean(
    learningPath
    && (learningPath === "subject" || learningProgram)
    && plan
    && level
    && grade
    && durationHours
    && (participantCount === 1 || multiParticipantReady) && participantDetailsValid
    && availableTimeSlots.length
    && selectedSessions === plan.session_count
    && (!learningProgram || (programSupports(learningProgram, level, grade)
      && (!learningProgram.grade || grade === learningProgram.grade)
      && subjects.length <= learningProgram.subjects.length
      && selectedSubjectIds.every((selected) => learningProgram.subjects.some((allowed) => allowed.id === selected))))
    && subjectsAreUnique
    && schedulesDoNotOverlap
    && scheduleRangeDays <= plan.validity_days
    && (mode === "online" || hasOfflineLocation)
    && (!isGuest || (guestName.trim().length >= 2 && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(guestEmail.trim()) && guestPhone.trim().length >= 8 && (mode !== "offline" || guestLocation.location_consent)))
    && packageWeekdays.size <= weekdayLimit
    && subjects.every((item) => item.curriculum_subject_id
      && item.subject_name
      && (learningProgram || item.learning_goal.trim().length > 0 || item.curriculum_chapter_ids.length > 0)
      && item.weekdays.length > 0
      && item.weekdays.length <= weekdayLimit
      && item.schedules.length === item.session_count
      && item.schedules.every((schedule) => availableTimeSlots.some((slot) => slot.start_time.slice(0, 5) === schedule.slice(11, 16)))
      && availableTimeSlots.some((slot) => slot.start_time.slice(0, 5) === item.schedule_time)),
  );

  useEffect(() => {
    let active = true;

    const loadSecondaryData = async () => {
      const { getCached } = await import("@/lib/http");
      const [voucherResult, profileResult, tutorialResult] = await Promise.allSettled([
        getCached<{ data: Voucher[] }>("/student/vouchers", {
          params: { compact: 1 },
          maxAgeMs: 60_000,
        }),
        getCached<StudentProfile>("/user", { maxAgeMs: 60_000 }),
        getCached<{ has_multi_subject_package: boolean }>("/student/packages/tutorial-status", { maxAgeMs: 60_000 }),
      ]);
      if (!active) return;

      if (voucherResult.status === "fulfilled") {
        const availableVouchers = (voucherResult.value.data.data ?? []).filter(
          (item): item is Voucher & { promotion: NonNullable<Voucher["promotion"]> } =>
            item.status === "available" && Boolean(item.promotion?.id),
        );
        setVouchers(availableVouchers);
        setVoucherId((current) => current && !availableVouchers.some((item) => item.id === current) ? "" : current);
      }

      if (profileResult.status === "fulfilled") {
        setStudentProfile(profileResult.value.data);
      }

      if (tutorialResult.status === "fulfilled") {
        setHasMultiSubjectPackage(Boolean(tutorialResult.value.data.has_multi_subject_package));
        setTutorialStatusLoaded(true);
      }
    };

    const load = async () => {
      setLoading(true);
      setLoadError(null);
      setTutorialStatusLoaded(false);
      try {
        const { default: http, getCached } = await import("@/lib/http");
        const [plansResponse, catalogResponse, slotsResponse, rulesResponse, renewalResponse, productsResponse] = await Promise.all([
          getCached<Plan[]>("/package-plans", { maxAgeMs: 5 * 60_000 }),
          getCached<{ subject_options: SubjectOption[] }>("/learning-catalog", { params: { compact: 1 }, maxAgeMs: 5 * 60_000 }),
          getCached<TimeSlot[]>("/learning-time-slots", { maxAgeMs: 5 * 60_000 }),
          getCached<BookingRules>("/package-booking-rules", { maxAgeMs: 5 * 60_000 }),
          renewalId ? http.get(`/student/packages/${renewalId}`) : Promise.resolve(null),
          getCached<LearningProgram[]>("/learning-programs", { maxAgeMs: 60_000 }),
        ]);
        if (!active) return;

        setPlans(plansResponse.data);
        const activeCatalog = (catalogResponse.data.subject_options || []).filter((item) => item.is_active !== false);
        setCatalog(activeCatalog);
        const fullHourSlots = slotsResponse.data.filter((slot) => slot.start_time.slice(3, 5) === "00");
        setTimeSlots(fullHourSlots);
        setBookingRules(rulesResponse.data);
        const defaultPlan = plansResponse.data.find((item) => item.session_count === 4) || plansResponse.data[0];

        if (renewalResponse) {
          const previous = renewalResponse.data;
          setLevel(previous.education_level);
          setGrade(previous.grade);
          setMode(previous.learning_mode);
          const renewalDuration = normalizeDurationHours(previous.duration_hours);
          const renewalDefaultTime = preferredSlotTime(fullHourSlots, renewalDuration);
          setDurationHours(renewalDuration);
          const selectedOld = renewalSubjectId
            ? previous.subjects.filter((item: any) => item.id === renewalSubjectId)
            : previous.subjects;
          const renewalPlan = plansResponse.data.find((item) => item.session_count === 4) || defaultPlan;
          if (renewalPlan && selectedOld.length) {
            setPlanId(renewalPlan.id);
            const allocation = Math.max(1, Math.floor(renewalPlan.session_count / selectedOld.length));
            setSubjects(selectedOld.slice(0, renewalPlan.maximum_subjects).map((item: any, index: number) => {
              const count = index === selectedOld.length - 1
                ? renewalPlan.session_count - allocation * index
                : allocation;
              return {
                ...createSubject(
                  count,
                  renewalDefaultTime,
                  index,
                  true,
                  item.teacher?.id
                    ? rulesResponse.data.renewal_booking_lead_hours
                    : rulesResponse.data.booking_lead_hours,
                ),
                curriculum_subject_id: item.curriculum_subject_id,
                subject_name: item.subject_name || item.name || "",
                preferred_teacher_id: item.teacher?.id,
                // Simpan konteks pesanan lama tanpa mewajibkan katalog Bab baru.
                curriculum_chapter_ids: [],
                chapter: "",
                learning_goal: item.learning_goal || item.chapter || "",
                focus_mode: "text",
              };
            }));
          }
        } else {
          setLearningPrograms(productsResponse.data);
          const requestedProgram = productsResponse.data.find((item) => item.id === requestedLearningProgramId);
          if (requestedLearningProgramId && !requestedProgram) throw new Error("Program tidak tersedia.");
          if (requestedProgram) {
            setLearningPath("program");
            setLearningProgram(requestedProgram);
            setLevel(requestedProgram.education_level);
            setGrade(requestedProgram.grade || "");
            setSubjects([createSubject(0)]);
          } else {
          setLearningProgram(null);
          if (["SD", "SMP", "SMA"].includes(requestedEducationLevel)) {
            setLevel(requestedEducationLevel);
          }
          const requestedSubject = activeCatalog.find(
            (item) => item.name.trim().toLocaleLowerCase("id-ID") === requestedSubjectName.toLocaleLowerCase("id-ID"),
          );
          if (requestedSubject) {
            setLearningPath("subject");
            setSubjects([{
              ...createSubject(0),
              curriculum_subject_id: requestedSubject.id,
              subject_name: requestedSubject.name,
            }]);
          }
          }
          try {
            const raw = draftStorageKey ? localStorage.getItem(draftStorageKey) : null;
            const saved = raw ? JSON.parse(raw) as SavedDraft : null;
            const savedPlan = plansResponse.data.find((item) => item.id === saved?.plan_id);
            if (!requestedProgram && saved && savedPlan && saved.subjects.length && saved.subjects.length <= savedPlan.maximum_subjects
              && (!saved.learning_program_id || productsResponse.data.some((item) => item.id === saved.learning_program_id))) {
              setPendingDraft(saved);
              if (!requestedSubjectName) setLearningPath(saved.learning_program_id ? "program" : saved.learning_path || "subject");
            }
          } catch {
            if (draftStorageKey) localStorage.removeItem(draftStorageKey);
          }
        }

        setLoading(false);
        if (!isGuest) void loadSecondaryData();
      } catch (err: unknown) {
        if (!active) return;
        const response = (err as { response?: { status?: number } } | null)?.response;
        if (!response) setLoadError("network");
        else if (response.status === 401) setLoadError("unauthorized");
        else if (response.status === 403) setLoadError("forbidden");
        else if (response.status === 404) setLoadError("not_found");
        else setLoadError("generic");
        setLoading(false);
      }
    };

    void load();
    return () => {
      active = false;
    };
  }, [draftStorageKey, isGuest, renewalId, renewalSubjectId, requestedEducationLevel, requestedSubjectName, requestedLearningProgramId, retryKey]);

  const handleRetry = () => { setLoadError(null); setLoading(true); setRetryKey((k) => k + 1); };
  const openLocationSetup = () => {
    if (isGuest) { document.getElementById("guest-location")?.scrollIntoView({ behavior: "smooth", block: "center" }); return; }
    const returnTo = `${window.location.pathname}${window.location.search}`;
    navigate(`/student/profile?section=location&returnTo=${encodeURIComponent(returnTo)}`);
  };

  useEffect(() => {
    if (!catalog.length) return;
    setSubjects((current) => current.map((item) => {
      if (!item.curriculum_subject_id) return item;
      const option = catalog.find((candidate) => candidate.id === item.curriculum_subject_id);
      const eligible = option
        && (!level || !option.education_levels?.length || option.education_levels.includes(level))
        && (!grade || !option.grades?.length || option.grades.includes(grade));
      return eligible ? item : { ...item, curriculum_subject_id: "", subject_name: "", curriculum_chapter_ids: [], chapter: "", learning_goal: "" };
    }));
  }, [catalog, grade, level]);


  useEffect(() => {
    if (loading || !planId || !mode || !durationHours || renewalId) return;
    const timer = window.setTimeout(() => {
      const savedAt = new Date().toISOString();
      const draft: SavedDraft = {
        saved_at: savedAt,
        learning_path: learningPath || undefined,
        learning_program_id: learningProgram?.id,
        plan_id: planId,
        level,
        grade,
        mode,
        duration_hours: effectiveDurationHours,
        subjects,
        promo_code: promoCode,
        voucher_id: voucherId,
        participant_count: participantCount,
        purchaser_participates: purchaserParticipates,
        participant_details: participantDetails,
      };
      try {
        if (!draftStorageKey) return;
        localStorage.setItem(draftStorageKey, JSON.stringify(draft));
        setDraftSavedAt(savedAt);
        setPendingDraft(null);
      } catch {
        // Form tetap dapat digunakan saat penyimpanan lokal tidak tersedia.
      }
    }, 600);
    return () => window.clearTimeout(timer);
  }, [draftStorageKey, durationHours, effectiveDurationHours, grade, level, learningPath, learningProgram, loading, mode, planId, promoCode, purchaserParticipates, renewalId, subjects, voucherId, participantCount, participantDetails]);

  const openMultiSubjectGuide = () => {
    window.dispatchEvent(new Event("bimbelku:open-tutorial"));
  };

  useEffect(() => {
    if (loading || !tutorialStatusLoaded || hasMultiSubjectPackage || !plan || plan.maximum_subjects < 2 || multiSubjectGuideOpened.current) return;
    try {
      if (localStorage.getItem(MULTI_SUBJECT_TUTORIAL_KEY)) return;
    } catch {
      return;
    }
    multiSubjectGuideOpened.current = true;
    const timer = window.setTimeout(openMultiSubjectGuide, 450);
    return () => window.clearTimeout(timer);
  }, [hasMultiSubjectPackage, loading, plan, tutorialStatusLoaded]);

  useEffect(() => {
    if (!summaryOpen) return;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    const onEscape = (event: KeyboardEvent) => {
      if (event.key === "Escape" && !submitting) setSummaryOpen(false);
    };
    window.addEventListener("keydown", onEscape);
    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener("keydown", onEscape);
    };
  }, [submitting, summaryOpen]);

  useEffect(() => {
    if (!draftValid) {
      quoteRequestIdRef.current += 1;
      setQuote(null);
      setQuoting(false);
      return;
    }

    const controller = new AbortController();
    const timer = window.setTimeout(async () => {
      const requestId = ++quoteRequestIdRef.current;
      setQuoting(true);
      const { default: http, getApiError } = await import("@/lib/http");
      try {
        const response = await http.post<Quote>(isGuest ? "/guest/packages/quote" : "/student/packages/quote", {
          package_plan_id: planId,
          education_level: level,
          learning_mode: mode,
          duration_hours: durationHours,
          participant_count: participantCount,
          code: isGuest ? undefined : promoCode.trim() || undefined,
          promotion_claim_id: isGuest ? undefined : voucherId || undefined,
          subjects: subjects.map((item) => ({
            curriculum_subject_id: item.curriculum_subject_id,
            session_count: item.session_count,
          })),
        }, {
          signal: controller.signal,
        });

        if (requestId !== quoteRequestIdRef.current) return;
        setQuote(response.data);
        setPromoError(null);
      } catch (error) {
        if (controller.signal.aborted || requestId !== quoteRequestIdRef.current) return;
        setQuote(null);
        if (promoCode.trim() || voucherId) {
          const fallback = promoCode.trim()
            ? "Kode promo tidak ditemukan atau sudah tidak berlaku."
            : "Voucher tidak ditemukan atau sudah tidak berlaku.";
          setPromoError(getApiError(error, fallback));
        } else {
          setPromoError(null);
          notify.error(getApiError(error, "Harga paket tidak dapat dihitung."));
        }
      } finally {
        if (requestId === quoteRequestIdRef.current) {
          setQuoting(false);
        }
      }
    }, 450);

    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [draftValid, durationHours, effectiveDurationHours, isGuest, level, mode, planId, promoCode, subjects, voucherId, participantCount]);

  const changeDuration = (nextDuration: DurationHours) => {
    if (nextDuration === durationHours) return;
    const compatibleSlots = slotsForDuration(timeSlots, nextDuration);
    if (!compatibleSlots.length) {
      notify.error(`Belum ada jam aktif yang dapat dipakai untuk sesi ${nextDuration} jam.`);
      return;
    }
    const fallbackTime = preferredSlotTime(timeSlots, nextDuration);
    setDurationHours(nextDuration);
    setSubjects((current) => current.map((item) => {
      const isStillAvailable = compatibleSlots.some((slot) => slot.start_time.slice(0, 5) === item.schedule_time);
      return isStillAvailable ? item : rebuildSchedules({ ...item, schedule_time: fallbackTime });
    }));
    setQuote(null);
  };

  const choosePlan = (selected: Plan) => {
    setPlanId(selected.id);
    setSubjects((current) => {
      const fresh = createSubject(selected.session_count);
      const first = current[0];
      if (!first?.curriculum_subject_id || !first.subject_name) return [fresh];
      return [{
        ...fresh,
        curriculum_subject_id: first.curriculum_subject_id,
        subject_name: first.subject_name,
      }];
    });
    setQuote(null);
  };
  const chooseLearningPath = (next: LearningPath) => {
    if (learningPath === next) return;
    setLearningPath(next);
    setLearningProgram(null);
    setLevel("");
    setGrade("");
    setSubjects([createSubject(plan?.session_count || 0)]);

    setQuote(null);
    setStep(0);
  };
  const chooseLearningProgram = (programId: string) => {
    const next = learningPrograms.find((item) => item.id === Number(programId)) || null;
    if (next && !programSupports(next, level, grade)) return;
    setLearningProgram(next);
    setSubjects([createSubject(plan?.session_count || 0)]);

    setQuote(null);
  };
  const updateSubject = (key: string, patch: Partial<DraftSubject>) => {
    if (patch.schedule_start_date !== undefined || patch.schedule_time !== undefined || patch.weekdays !== undefined) {
      setScheduleRecommendations((current) => {
        const next = { ...current };
        delete next[key];
        return next;
      });
    }
    setSubjects((current) => current.map((item) => {
      if (item.key !== key) return item;
      const normalizedPatch = patch.weekdays !== undefined
        ? { ...patch, weekdays: normalizeWeekdays(patch.weekdays, weekdayLimit) }
        : patch;
      const next = { ...item, ...normalizedPatch };
      if (patch.session_count !== undefined || patch.schedule_start_date !== undefined || patch.schedule_time !== undefined || patch.weekdays !== undefined) {
        return rebuildSchedules(next);
      }
      return next;
    }));
  };
  const recommendSchedule = async (item: DraftSubject) => {
    if (!item.curriculum_subject_id || !level || !grade || !mode || !durationHours || !item.schedule_start_date || !item.weekdays.length) {
      notify.error("Pilih mapel, jenjang, mode, tanggal mulai, dan hari belajar terlebih dahulu.");
      return;
    }
    setRecommendationLoading((current) => ({ ...current, [item.key]: true }));
    const { default: http, getApiError } = await import("@/lib/http");
    try {
      const response = await http.post<ScheduleRecommendationResponse>("/student/schedule-recommendations", {
        curriculum_subject_id: item.curriculum_subject_id,
        education_level: level,
        grade,
        learning_mode: mode,
        duration_hours: durationHours,
        schedule_start_date: item.schedule_start_date,
        weekdays: item.weekdays,
        session_count: item.session_count,
        current_time: item.schedule_time || undefined,
      });
      setScheduleRecommendations((current) => ({ ...current, [item.key]: response.data }));
    } catch (error) {
      notify.error(getApiError(error, "Rekomendasi jadwal belum dapat dihitung."));
    } finally {
      setRecommendationLoading((current) => ({ ...current, [item.key]: false }));
    }
  };
  const applyScheduleRecommendation = (key: string, recommendation: ScheduleRecommendation) => {
    setSubjects((current) => current.map((item) => item.key === key
      ? { ...item, schedule_time: recommendation.time, schedules: recommendation.schedules }
      : item));
    setScheduleRecommendations((current) => {
      const next = { ...current };
      delete next[key];
      return next;
    });
    notify.success(`Jadwal ${recommendation.time} diterapkan tanpa mengubah hari belajar.`);
  };
  const distribute = () => {
    if (!plan) return;
    setSubjects((current) => rebalance(current, plan.session_count));
  };
  const addSubject = () => {
    if (!plan || subjects.length >= plan.maximum_subjects || subjects.length >= plan.session_count
      || (learningProgram && subjects.length >= learningProgram.subjects.length)) return;
    setSubjects((current) => rebalance([...current, createSubject(1, defaultSlotTime, current.length)], plan.session_count));
  };
  const removeSubject = (key: string) => {
    if (!plan || subjects.length <= 1) return;
    setSubjects((current) => rebalance(current.filter((item) => item.key !== key), plan.session_count));
  };
  const adjustAllocation = (key: string, delta: -1 | 1) => {
    setSubjects((current) => {
      const targetIndex = current.findIndex((item) => item.key === key);
      if (targetIndex < 0 || current.length < 2) return current;
      const otherIndexes = current.map((_, index) => index).filter((index) => index !== targetIndex);
      const transferIndex = delta > 0
        ? otherIndexes.sort((a, b) => current[b].session_count - current[a].session_count).find((index) => current[index].session_count > 1)
        : otherIndexes.sort((a, b) => current[a].session_count - current[b].session_count)[0];
      if (transferIndex === undefined || (delta < 0 && current[targetIndex].session_count <= 1)) return current;
      return current.map((item, index) => {
        if (index === targetIndex) return rebuildSchedules(item, item.session_count + delta);
        if (index === transferIndex) return rebuildSchedules(item, item.session_count - delta);
        return item;
      });
    });
  };
  const resetDraft = () => {
    try {
      if (draftStorageKey) localStorage.removeItem(draftStorageKey);
    } catch {
      // Form tetap dapat direset pada state halaman.
    }
    setPlanId("");
    setLearningPath(renewalId ? "subject" : "");
    setLearningProgram(null);
    setSubjects([createSubject(0)]);
    setLevel("");
    setGrade("");
    setMode("");
    setDurationHours("");
    setPromoCode("");
    setVoucherId("");
    setQuote(null);
    setDraftRestored(false);
    setDraftSavedAt(null);
    setPendingDraft(null);
  };

  const restoreDraft = () => {
    if (!pendingDraft) return;
    const savedPlan = plans.find((item) => item.id === pendingDraft.plan_id);
    if (!savedPlan || !pendingDraft.subjects.length || pendingDraft.subjects.length > savedPlan.maximum_subjects) {
      resetDraft();
      notify.error("Draf lama tidak lagi cocok dengan pilihan paket yang tersedia.");
      return;
    }

    const restoredDuration = normalizeDurationHours(pendingDraft.duration_hours);
    const restoredDefaultTime = preferredSlotTime(timeSlots, restoredDuration);
    setPlanId(pendingDraft.plan_id);
    const restoredProgram = learningPrograms.find((item) => item.id === pendingDraft.learning_program_id) || null;
    setLearningPath(restoredProgram ? "program" : pendingDraft.learning_path || "subject");
    setLearningProgram(restoredProgram);
    setLevel(pendingDraft.level);
    setGrade(pendingDraft.grade);
    setMode(pendingDraft.mode);
    setDurationHours(restoredDuration);
    const allowedRestoredDays = new Set(normalizeWeekdays(pendingDraft.subjects.flatMap((item) =>
      Array.isArray(item.weekdays) && item.weekdays.length ? item.weekdays : (item.schedules || []).map((schedule) => isoWeekday(new Date(schedule))),
    ), maxWeekdaysForSessions(savedPlan.session_count)));
    setSubjects(pendingDraft.subjects.map((item, index) => {
      const restoredDays = normalizeWeekdays(
        Array.isArray(item.weekdays) && item.weekdays.length
          ? item.weekdays
          : (item.schedules || []).map((schedule) => isoWeekday(new Date(schedule))),
      ).filter((day) => allowedRestoredDays.has(day));
      const candidateTime = item.schedule_time?.slice(3, 5) === "00" ? item.schedule_time : restoredDefaultTime;
      const restoredTime = timeSlots.some((slot) => slot.start_time.slice(0, 5) === candidateTime)
        && slotSupportsDuration(candidateTime, restoredDuration)
        ? candidateTime
        : restoredDefaultTime;
      const restored: DraftSubject = {
        ...item,
        key: item.key || `${Date.now()}-${index}`,
        schedule_start_date: item.schedule_start_date || item.schedules[0]?.slice(0, 10) || earliestScheduleDate(restoredTime),
        schedule_time: restoredTime,
        weekdays: restoredDays,
        curriculum_chapter_ids: item.focus_mode === "chapters" ? item.curriculum_chapter_ids || [] : [],
        chapter: item.focus_mode === "chapters" ? item.chapter || "" : "",
        learning_goal: item.learning_goal || (item.focus_mode === "chapters" ? "" : item.chapter || ""),
        schedules: Array.isArray(item.schedules) ? item.schedules : [],
      };
      const savedSchedulesAreValid = restored.schedules.length === restored.session_count
        && restored.schedules.every((schedule) => {
          const date = new Date(schedule);
          return Number.isFinite(date.getTime())
            && timeSlots.some((slot) => slot.start_time.slice(0, 5) === schedule.slice(11, 16) && slotSupportsDuration(schedule.slice(11, 16), restoredDuration))
            && restored.weekdays.includes(isoWeekday(date));
        });
      return savedSchedulesAreValid
        ? { ...restored, schedules: [...restored.schedules].sort((a, b) => new Date(a).getTime() - new Date(b).getTime()) }
        : rebuildSchedules(restored);
    }));
    setPromoCode(pendingDraft.promo_code || "");
    setVoucherId(pendingDraft.voucher_id || "");
    setParticipantCount(multiParticipantReady && Number(pendingDraft.participant_count) >= 2 ? Math.min(maximumParticipants, Number(pendingDraft.participant_count)) : 1);
    setPurchaserParticipates(pendingDraft.purchaser_participates !== false);
    setParticipantDetails(Array.isArray(pendingDraft.participant_details) ? pendingDraft.participant_details : []);
    setDraftSavedAt(pendingDraft.saved_at);
    setDraftRestored(true);
    setPendingDraft(null);
    notify.success("Draf dilanjutkan.");
  };
  const submit = async () => {
    if (!draftValid || !quote) {
      notify.error("Lengkapi pembagian sesi dan jadwal terlebih dahulu.");
      return;
    }
    setSubmitting(true);
    const { default: http, getApiError } = await import("@/lib/http");
    try {
      const response = await http.post(isGuest ? "/guest/packages" : "/student/packages", {
        name: isGuest ? guestName.trim() : undefined,
        email: isGuest ? guestEmail.trim().toLowerCase() : undefined,
        phone: isGuest ? guestPhone.trim() : undefined,
        address: isGuest && mode === "offline" ? guestLocation.address.trim() : undefined,
        maps_link: isGuest && mode === "offline" ? guestLocation.maps_link : undefined,
        latitude: isGuest && mode === "offline" ? guestLocation.latitude : undefined,
        longitude: isGuest && mode === "offline" ? guestLocation.longitude : undefined,
        location_consent: isGuest && mode === "offline" ? guestLocation.location_consent : undefined,
        package_plan_id: planId,
        learning_program_id: learningProgram?.id,
        education_level: level,
        grade,
        learning_mode: mode,
        duration_hours: durationHours,
        participant_count: participantCount,
        purchaser_participates: purchaserParticipates,
        participant_details: participantDetails.slice(0, Math.max(0, participantCount - (purchaserParticipates ? 1 : 0))).map((detail) => ({
          full_name: detail.full_name.trim(),
          nickname: detail.nickname.trim(),
          birth_date: detail.birth_date,
          gender: detail.gender,
        })),
        promotion_code: isGuest ? undefined : promoCode.trim() || undefined,
        promotion_claim_id: isGuest ? undefined : voucherId || undefined,
        renewal_of_id: renewalId,
        subjects: subjects.map((item) => ({
          curriculum_subject_id: item.curriculum_subject_id,
          learning_goal: item.learning_goal.trim() || undefined,
          curriculum_chapter_ids: learningProgram ? [] : item.curriculum_chapter_ids,
          preferred_teacher_id: item.preferred_teacher_id,
          weekdays: item.weekdays,
          schedules: item.schedules,
        })),
      });
      notify.success(response.data.message);
      if (isGuest) {
        navigate("/pesanan/" + response.data.code, { replace: true, state: { email: guestEmail.trim().toLowerCase() } });
        return;
      }
      try {
        if (draftStorageKey) localStorage.removeItem(draftStorageKey);
      } catch {
        // Draf kedaluwarsa tidak menghambat perpindahan ke pembayaran.
      }
      const order = response.data.order;
      navigate("/payment", {
        replace: true,
        state: {
          orderId: order.order_id,
          invoiceId: order.order_number,
          tutorName: order.tutor_name,
          subject: order.subject,
          type: order.type,
          price: Number(order.amount),
          subtotalAmount: Number(order.subtotal_amount || order.amount),
          discountAmount: Number(order.discount_amount || 0),
          packageName: order.package_name,
          date: order.scheduled_at,
          paymentDueAt: order.payment_due_at,
          durationHours: Number(order.duration_hours || effectiveDurationHours),
          totalLearningHours: Number(order.total_learning_hours || (plan?.session_count || 0) * effectiveDurationHours),
          orderKind: order.order_kind || "package",
        },
      });
    } catch (error) {
      notify.error(getApiError(error, "Paket gagal dibuat."));
    } finally {
      setSubmitting(false);
    }
  };

  const completedSteps = [
    Boolean(learningPath && plan && durationHours && level && grade && mode
      && (learningPath !== "program" || availablePrograms.length > 0)
      && (!isGuest || (guestName.trim().length >= 2 && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(guestEmail.trim()) && guestPhone.trim().length >= 8))
      && (participantCount === 1 || multiParticipantReady) && participantDetailsValid),
    Boolean(plan && (learningPath === "subject" || learningProgram) && selectedSessions === plan.session_count && subjectsAreUnique
      && subjects.every((item) => item.curriculum_subject_id && (learningProgram || item.learning_goal.trim().length > 0 || item.curriculum_chapter_ids.length > 0))),
    Boolean(draftValid),
    Boolean(draftValid && quote),
  ];
  const planMissingItems: string[] = [];
  if (!learningPath) planMissingItems.push("Pilih program/persiapan tes atau mata pelajaran");
  if (step >= 1 && learningPath === "program" && !learningProgram) planMissingItems.push("Pilih program pada tahap mapel");
  if (!plan) planMissingItems.push("Pilih paket belajar");
  if (!durationHours || !level || !grade || !mode) planMissingItems.push("Lengkapi durasi, jenjang, kelas, dan metode");
  if (isGuest && !(guestName.trim().length >= 2 && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(guestEmail.trim()) && guestPhone.trim().length >= 8)) planMissingItems.push("Lengkapi identitas pemesan");
  if (!participantDetailsValid || (participantCount > 1 && !multiParticipantReady)) planMissingItems.push("Lengkapi data seluruh peserta");
  if (!plan || selectedSessions !== plan.session_count || !subjectsAreUnique || subjects.some((item) => !item.curriculum_subject_id || (!learningProgram && !item.learning_goal.trim() && !item.curriculum_chapter_ids.length))) planMissingItems.push(learningProgram ? "Pilih mapel program dan alokasi sesi" : "Pilih mapel, bab atau materi sendiri, dan alokasi sesi");
  if (!draftValid) planMissingItems.push(mode === "offline" && !hasOfflineLocation ? "Lengkapi jadwal dan lokasi belajar" : "Lengkapi seluruh jadwal belajar");
  const selectedSubjectSummary = subjects.map((item) => item.subject_name).filter(Boolean).join(", ");
  const participantDiscountPercent = participantCount > 1 ? Number(participantDiscounts[String(participantCount)] || 0) : 0;
  const activeStepIndex = step;
  const moveStep = (next: number) => {
    if (next > step && !completedSteps[step]) {
      notify.error([!learningPath ? "Pilih jalur belajar terlebih dahulu." : learningPath === "program" && !availablePrograms.length ? "Belum ada program untuk jenjang dan tingkat ini. Ubah pilihan atau pilih Mata pelajaran." : "Lengkapi pilihan paket, jenjang, metode, dan peserta.", learningPath === "program" && !learningProgram ? "Pilih program pada tahap mapel terlebih dahulu." : learningProgram ? "Pilih mapel program dan alokasikan seluruh sesi." : "Pilih bab atau tulis materi setiap mapel, lalu alokasikan seluruh sesi.", "Lengkapi jadwal dan lokasi belajar."][step] || "Lengkapi tahap ini dahulu.");
      return;
    }
    setStep(Math.max(0, Math.min(3, next)));
    const scrollRoot = document.getElementById("student-scroll-container");
    if (scrollRoot) scrollRoot.scrollTo({ top: 0, behavior: "smooth" });
    else window.scrollTo({ top: 0, behavior: "smooth" });
  };
  if (loading) {
    return (
      <PackageBuilderShell guest={isGuest} title={renewalId ? "Perpanjang Paket" : "Pilih Paket Belajar"}>
        <div className="package-builder-form mx-auto w-full min-w-0 max-w-6xl space-y-5 pb-20 sm:space-y-6">
          <PackageBuilderIntro renewal={Boolean(renewalId)} />
          <PackageBuilderSkeleton />
        </div>
      </PackageBuilderShell>
    );
  }

  if (loadError) {
    return (
      <PackageBuilderShell guest={isGuest} title={renewalId ? "Perpanjang Paket" : "Pilih Paket Belajar"}>
        <div className="package-builder-form mx-auto w-full min-w-0 max-w-6xl space-y-5 pb-20 sm:space-y-6">
          <PackageBuilderIntro renewal={Boolean(renewalId)} />
          <div className="mx-auto max-w-xl py-10 sm:py-16">
            <ErrorState type={loadError} onRetry={handleRetry} />
          </div>
        </div>
      </PackageBuilderShell>
    );
  }

  return (
    <PackageBuilderShell guest={isGuest} title={renewalId ? "Perpanjang Paket" : "Pilih Paket Belajar"}>
      <div className="package-builder-form mx-auto w-full min-w-0 max-w-6xl space-y-5 pb-20 sm:space-y-6">
        <PackageBuilderIntro renewal={Boolean(renewalId)} />
        {!renewalId && <section aria-labelledby="learning-path-title" className="rounded-[1.75rem] border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
          <p className="text-[11px] font-black uppercase tracking-[.18em] text-orange-700">Mulai dari kebutuhanmu</p>
          <h2 id="learning-path-title" className="mt-1 text-xl font-black text-slate-950">Mau belajar lewat jalur apa?</h2>
          <p className="mt-1 text-sm leading-6 text-slate-600">Untuk target ujian, pilih program. Untuk les biasa, langsung pilih mata pelajaran.</p>
          <div className="mt-5 grid gap-3 sm:grid-cols-2">
            <button type="button" aria-pressed={learningPath === "program"} disabled={!learningPrograms.length} onClick={() => chooseLearningPath("program")} className={`min-h-32 rounded-2xl border p-4 text-left transition disabled:cursor-not-allowed disabled:opacity-50 ${learningPath === "program" ? "border-orange-500 bg-orange-50 ring-2 ring-orange-100" : "border-slate-200 bg-white hover:border-orange-300 hover:bg-orange-50/50"}`}>
              <span className="flex items-center justify-between gap-3"><Sparkles className="text-orange-600" size={22} />{learningPath === "program" && <Check className="text-orange-600" size={20} />}</span>
              <span className="mt-3 block text-base font-black text-slate-900">Program / persiapan tes</span>
              <span className="mt-1 block text-xs leading-5 text-slate-600">Contohnya TKA atau UTBK. Program dan mapelnya dipilih pada tahap kedua.</span>
            </button>
            <button type="button" aria-pressed={learningPath === "subject"} onClick={() => chooseLearningPath("subject")} className={`min-h-32 rounded-2xl border p-4 text-left transition ${learningPath === "subject" ? "border-indigo-600 bg-indigo-50 ring-2 ring-indigo-100" : "border-slate-200 bg-white hover:border-indigo-300 hover:bg-indigo-50/50"}`}>
              <span className="flex items-center justify-between gap-3"><BookOpenCheck className="text-indigo-600" size={22} />{learningPath === "subject" && <Check className="text-indigo-600" size={20} />}</span>
              <span className="mt-3 block text-base font-black text-slate-900">Mata pelajaran</span>
              <span className="mt-1 block text-xs leading-5 text-slate-600">Pilih satu atau beberapa mapel sesuai kebutuhanmu, tanpa memilih program.</span>
            </button>
          </div>
        </section>}
        {(Boolean(renewalId) || Boolean(learningPath)) && <>

        <nav aria-label="Tahapan pemesanan" className="min-w-0 overflow-hidden rounded-[1.75rem] border border-slate-200 bg-white shadow-sm">
          <div className="flex min-w-0 flex-wrap items-end justify-between gap-3 border-b border-slate-100 bg-gradient-to-r from-orange-50 via-white to-indigo-50 px-4 py-4 sm:px-5">
            <div className="min-w-0"><p className="text-[10px] font-black uppercase tracking-[.18em] text-orange-700">Susun pesanan</p><h2 className="mt-1 break-words text-base font-black text-slate-950 sm:text-lg">Empat tahap, semua pilihan terlihat jelas.</h2></div>
            <div className="shrink-0 text-right"><p className="text-xs font-black text-slate-900">Tahap {activeStepIndex + 1} dari 4</p><p className="mt-0.5 text-[10px] font-bold text-slate-500">{Math.round(((activeStepIndex + 1) / 4) * 100)}% posisi alur</p></div>
            <div className="h-1.5 w-full overflow-hidden rounded-full bg-slate-200" aria-hidden="true"><span className="block h-full rounded-full bg-gradient-to-r from-orange-500 to-indigo-600 transition-all" style={{ width: `${((activeStepIndex + 1) / 4) * 100}%` }} /></div>
          </div>
          <ol className="grid min-w-0 grid-cols-2 gap-2 p-3 sm:grid-cols-4 sm:p-4">
            {[
              { label: "Kebutuhan", detail: "Paket & peserta" },
              { label: "Mapel", detail: learningPath === "program" ? "Program & sesi" : "Materi & sesi" },
              { label: "Jadwal", detail: "Hari & lokasi" },
              { label: "Konfirmasi", detail: "Harga akhir" },
            ].map((item, index) => {
              const active = index === activeStepIndex;
              const done = completedSteps[index] && !active;
              return <li key={item.label} className="min-w-0">
                <button type="button" disabled={index > activeStepIndex} onClick={() => moveStep(index)} aria-current={active ? "step" : undefined} className={`flex min-h-[4.75rem] w-full min-w-0 items-start gap-3 rounded-2xl border p-3 text-left transition ${active ? "border-slate-950 bg-slate-950 text-white shadow-lg shadow-slate-200" : done ? "border-emerald-200 bg-emerald-50 text-emerald-900 hover:border-emerald-300" : "border-slate-200 bg-slate-50 text-slate-500"}`}>
                  <span className={`grid h-8 w-8 shrink-0 place-items-center rounded-xl text-xs font-black ${active ? "bg-orange-500 text-white" : done ? "bg-emerald-600 text-white" : "bg-white text-slate-500"}`}>{done ? <Check size={15} strokeWidth={3} /> : index + 1}</span>
                  <span className="min-w-0"><span className="block break-words text-xs font-black sm:text-sm">{item.label}</span><span className={`mt-1 block break-words text-[10px] font-semibold leading-4 ${active ? "text-slate-300" : done ? "text-emerald-700" : "text-slate-400"}`}>{item.detail}</span></span>
                </button>
              </li>;
            })}
          </ol>
        </nav>

        {!renewalId && pendingDraft && (
          <div className="flex flex-col gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <p className="font-black">Ada draf yang belum selesai</p>
              <p className="mt-0.5 text-xs text-amber-800">Form tetap kosong. Tekan “Lanjutkan draf” jika ingin memakai pilihan yang disimpan {new Date(pendingDraft.saved_at).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" })}.</p>
            </div>
            <div className="flex shrink-0 flex-col gap-2 sm:flex-row">
              <button type="button" onClick={resetDraft} className="min-h-11 rounded-xl border border-amber-200 bg-white px-4 text-xs font-black text-amber-800 hover:bg-amber-100">Hapus draf</button>
              <button type="button" onClick={restoreDraft} className="min-h-11 rounded-xl bg-amber-900 px-4 text-xs font-black text-white hover:bg-amber-950">Lanjutkan draf</button>
            </div>
          </div>
        )}

        {!renewalId && !pendingDraft && draftSavedAt && (
          <div className="flex flex-col gap-3 rounded-2xl border border-blue-100 bg-blue-50 p-4 text-sm text-blue-900 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <p className="font-black">{draftRestored ? "Draf terakhir dipulihkan" : "Draf tersimpan otomatis"}</p>
              <p className="mt-0.5 text-xs text-blue-700">Terakhir disimpan {new Date(draftSavedAt).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" })}.</p>
            </div>
            <button type="button" onClick={resetDraft} className="min-h-11 rounded-xl border border-blue-200 bg-white px-4 text-xs font-black text-blue-700 hover:bg-blue-100">Mulai ulang</button>
          </div>
        )}

        <div className={step < 3 ? "grid min-w-0 items-start gap-5 lg:grid-cols-[minmax(0,1fr)_320px]" : ""}>
          <div className="min-w-0 space-y-5">
        <section className={step === 0 ? "" : "hidden"}>
          <h2 className="mb-3 text-lg font-black text-slate-900">Pilih Paket Belajar</h2>
          <div className="grid min-w-0 grid-cols-1 gap-3 min-[360px]:grid-cols-2 xl:grid-cols-4">
            {plans.map((item) => (
              <button
                key={item.id}
                data-tour={item.maximum_subjects >= 2 ? "package-plan-picker" : undefined}
                type="button"
                onClick={() => choosePlan(item)}
                className={`min-h-40 min-w-0 overflow-hidden rounded-3xl border p-4 text-left transition sm:p-5 ${planId === item.id ? "border-indigo-600 bg-indigo-50 ring-2 ring-indigo-100" : "border-slate-200 bg-white hover:border-indigo-300"}`}
              >
                <div className="flex items-start justify-between">
                  <BookOpenCheck className={planId === item.id ? "text-indigo-600" : "text-slate-500"} />
                  {planId === item.id && <Check className="text-indigo-600" size={20} />}
                </div>
                <h3 className="mt-4 font-black text-slate-900">{item.name}</h3>
                <p className="mt-1 text-2xl font-black text-indigo-700">{item.session_count} sesi</p>
                <p className="mt-2 text-xs leading-5 text-slate-500">{item.validity_days} hari · maksimal {item.maximum_subjects} mapel</p>
              </button>
            ))}
          </div>
        </section>

        {isGuest && <div hidden={step !== 0}><GuestOrderIdentity section="identity" name={guestName} email={guestEmail} phone={guestPhone} mode={mode} location={guestLocation} onIdentityChange={(field, value) => { if (field === "name") setGuestName(value); else if (field === "email") setGuestEmail(value); else setGuestPhone(value); }} onLocationChange={setGuestLocation} /></div>}
        <div hidden={step !== 0}><PrivateParticipantsPicker count={participantCount} purchaserParticipates={purchaserParticipates} details={participantDetails} ready={multiParticipantReady && !renewalId} maximumParticipants={maximumParticipants} discounts={participantDiscounts} onCountChange={(count) => { setParticipantCount(count); const required = Math.max(0, count - (purchaserParticipates ? 1 : 0)); setParticipantDetails((current) => Array.from({ length: required }, (_, index) => current[index] || emptyParticipantDetail())); }} onPurchaserParticipatesChange={(value) => { setPurchaserParticipates(value); const required = Math.max(0, participantCount - (value ? 1 : 0)); setParticipantDetails((current) => Array.from({ length: required }, (_, index) => current[index] || emptyParticipantDetail())); }} onDetailChange={(index, patch) => setParticipantDetails((current) => { const next = Array.from({ length: expectedParticipantDetails }, (_, itemIndex) => current[itemIndex] || emptyParticipantDetail()); next[index] = { ...next[index], ...patch }; return next; })} /></div>

        <section data-tour="package-duration-picker" className={step === 0 ? "render-auto rounded-[2rem] border border-slate-100 bg-white p-5 shadow-sm sm:p-7" : "hidden"}>
          <div className="flex min-w-0 items-start gap-3">
            <div className="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-indigo-50 text-indigo-600"><Clock3 size={20} /></div>
            <div className="min-w-0">
              <h2 className="break-words text-lg font-black text-slate-900">Durasi setiap pertemuan</h2>
              <p className="mt-1 text-sm leading-6 text-slate-500">Pilih lama belajar untuk setiap sesi dalam paket ini.</p>
            </div>
          </div>
          <div className="mt-4 grid gap-3 sm:grid-cols-2">
            {([1, 2] as DurationHours[]).map((hours) => {
              const active = durationHours === hours;
              return (
                <button
                  key={hours}
                  type="button"
                  aria-pressed={active}
                  onClick={() => changeDuration(hours)}
                  className={`min-h-28 rounded-2xl border px-5 py-4 text-left transition ${active ? "border-indigo-600 bg-indigo-600 text-white shadow-lg shadow-indigo-100" : "border-slate-200 bg-white text-slate-800 hover:border-indigo-300 hover:bg-indigo-50"}`}
                >
                  <span className="text-3xl font-black">{hours} jam</span>
                  <p className={`mt-1 text-xs font-bold leading-5 ${active ? "text-indigo-100" : "text-slate-500"}`}>
                    {hours === 1 ? "Cocok untuk sesi rutin yang lebih ringan." : "Cocok untuk pembahasan dan latihan yang lebih panjang."}
                  </p>
                </button>
              );
            })}
          </div>
          <p className="mt-3 rounded-2xl bg-indigo-50 px-4 py-3 text-xs font-bold leading-5 text-indigo-800">
            {plan && durationHours ? `${plan.session_count} sesi × ${durationHours} jam = ${plan.session_count * durationHours} jam belajar` : "Pilih paket dan lama belajar untuk melihat total jam belajar."}
          </p>
          <p className="mt-2 text-xs font-medium leading-5 text-slate-500">Jam mulai tetap memakai menit 00. Sistem otomatis menghitung jam selesai dan memeriksa benturan.</p>
        </section>

        <section className={step === 0 ? "render-auto rounded-[2rem] border border-slate-100 bg-white p-5 shadow-sm sm:p-7" : "hidden"}>
          <h2 className="text-lg font-black text-slate-900">Jenjang dan metode</h2>
          <div className="mt-4 grid gap-4 md:grid-cols-3">
            <Field label="Jenjang">
              <ResponsiveSelect
                value={level}
                ariaLabel="Pilih jenjang"
                placeholder="Pilih jenjang"
                tone="emerald"
                options={EDUCATION_LEVELS.filter((item) => learningPath !== "program" || learningPrograms.some((program) =>
                  (GRADES_BY_EDUCATION_LEVEL[item] || []).some((candidate) => programSupports(program, item, candidate)))).map((item) => ({ value: item, label: item }))}
                className={level ? "border-emerald-300 bg-emerald-50 text-emerald-800" : ""}
                onValueChange={(next) => { setLevel(next); setGrade(""); setLearningProgram(null); setSubjects(learningPath === "program" ? [createSubject(plan?.session_count || 0)] : subjects.map((item) => ({ ...item, curriculum_chapter_ids: [], chapter: "" }))); setQuote(null); }}
              />
            </Field>
            <Field label={level === "Umum" ? "Tingkat" : "Kelas"}>
              <ResponsiveSelect
                value={grade}
                ariaLabel={level === "Umum" ? "Pilih tingkat" : "Pilih kelas"}
                placeholder={level ? (level === "Umum" ? "Pilih tingkat" : "Pilih kelas") : "Pilih jenjang dulu"}
                tone="emerald"
                disabled={!level}
                options={(GRADES_BY_EDUCATION_LEVEL[level] || []).filter((item) => learningPath !== "program" || learningPrograms.some((program) => programSupports(program, level, item))).map((item) => ({ value: item, label: item }))}
                className={grade ? "border-emerald-300 bg-emerald-50 text-emerald-800" : "disabled:bg-slate-100"}
                onValueChange={(next) => { setGrade(next); if (learningProgram?.grade && learningProgram.grade !== next) setLearningProgram(null); setSubjects(learningPath === "program" ? [createSubject(plan?.session_count || 0)] : subjects.map((item) => ({ ...item, curriculum_chapter_ids: [], chapter: "" }))); setQuote(null); }}
              />
            </Field>
            <Field label="Metode belajar">
              <ResponsiveSelect
                value={mode}
                ariaLabel="Pilih metode belajar"
                placeholder="Pilih metode belajar"
                tone="emerald"
                options={[{ value: "online", label: "Online" }, { value: "offline", label: "Offline" }]}
                className={mode ? "border-emerald-300 bg-emerald-50 text-emerald-800" : ""}
                onValueChange={(next) => setMode(next as "online" | "offline")}
              />
            </Field>
          </div>
          {learningPath === "program" && <p className="mt-3 text-xs font-semibold text-slate-600">Jenjang dan tingkat yang ditampilkan hanya yang memiliki program aktif. Misalnya TKA dapat khusus SMA, sedangkan program lintas usia dapat tersedia di Umum atau beberapa jenjang.</p>}

          {mode === "offline" && !isGuest && (
            <div className={`mt-5 rounded-2xl border p-4 sm:p-5 ${hasOfflineLocation ? "border-emerald-200 bg-emerald-50" : "border-amber-200 bg-amber-50"}`}>
              <div className="flex min-w-0 items-start gap-3">
                <div className={`grid h-11 w-11 shrink-0 place-items-center rounded-2xl ${hasOfflineLocation ? "bg-emerald-600 text-white" : "bg-amber-100 text-amber-700"}`}>
                  <MapPin size={20} />
                </div>
                <div className="min-w-0 flex-1">
                  <p className={`font-black ${hasOfflineLocation ? "text-emerald-900" : "text-amber-900"}`}>
                    {hasOfflineLocation ? "Lokasi kelas offline sudah siap" : "Tambahkan lokasi untuk kelas offline"}
                  </p>
                  <p className={`mt-1 break-words text-sm leading-6 ${hasOfflineLocation ? "text-emerald-700" : "text-amber-800"}`}>
                    {hasOfflineLocation
                      ? selectedLocation?.address
                      : "Alamat dan titik lokasi diperlukan agar sistem dapat mencari tutor di sekitar kamu."}
                  </p>
                  <button
                    type="button"
                    onClick={openLocationSetup}
                    className={`mt-3 inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-4 text-sm font-black transition ${hasOfflineLocation ? "border border-emerald-200 bg-white text-emerald-700 hover:bg-emerald-100" : "bg-amber-700 text-white hover:bg-amber-800"}`}
                  >
                    <MapPin size={17} /> {hasOfflineLocation ? "Ubah lokasi" : "Tambahkan lokasi sekarang"}
                  </button>
                </div>
              </div>
            </div>
          )}
        </section>

        <section className={step === 1 || step === 2 ? "render-auto rounded-[2rem] border border-slate-100 bg-white p-5 shadow-sm sm:p-7" : "hidden"}>
          {step === 1 && learningPath === "program" && <div className="mb-6 rounded-2xl border border-orange-200 bg-orange-50/70 p-4">
            <p className="text-sm font-black text-slate-900">Pilih program yang ingin diikuti</p>
            <p className="mt-1 text-xs leading-5 text-slate-600">Pilihan menyesuaikan jenjang dan kelas dari tahap pertama. Setelah itu, pilih mapel program dan pembagian sesinya.</p>
            <div className="mt-3"><ResponsiveSelect value={learningProgram?.id || ""} ariaLabel="Pilih program belajar" placeholder="Pilih program" disabled={!availablePrograms.length} options={[{ value: "", label: "Pilih program" }, ...availablePrograms.map((item) => ({ value: item.id, label: item.name }))]} onValueChange={chooseLearningProgram} /></div>
            {!availablePrograms.length && <p className="mt-2 text-xs font-semibold text-orange-800">Belum ada program untuk jenjang dan tingkat ini. Kembali ke tahap pertama untuk memilih pilihan yang tersedia, atau pilih jalur Mata pelajaran.</p>}
            {learningProgram && <p className="mt-3 text-xs leading-5 text-orange-900">{learningProgram.name} adalah satu pesanan. Satu tutor berbeda akan dicari untuk setiap mapel yang kamu pilih setelah pembayaran.</p>}
          </div>}
          {(learningPath !== "program" || Boolean(learningProgram)) ? <>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h2 className="text-lg font-black text-slate-900">Pilih mapel dan bagikan sesi</h2>
              <p className={`mt-1 text-sm font-bold ${selectedSessions === plan?.session_count ? "text-emerald-700" : "text-orange-700"}`}>
                Total: {selectedSessions} dari {plan?.session_count || 0} sesi
              </p>
            </div>
            <div className="flex w-full flex-wrap gap-2 sm:w-auto">
              <button type="button" onClick={openMultiSubjectGuide} className="inline-flex min-h-11 flex-1 items-center justify-center gap-1 rounded-xl border border-indigo-100 bg-indigo-50 px-3 py-2 text-xs font-black text-indigo-700 sm:flex-none"><HelpCircle size={14} /> Panduan 2+ mapel</button>
              {subjects.length > 1 && <button type="button" onClick={distribute} className="min-h-11 flex-1 rounded-xl bg-slate-100 px-3 py-2 text-xs font-black text-slate-700 sm:flex-none">Bagi merata</button>}
              <button data-tour="package-add-subject" type="button" onClick={addSubject} disabled={!plan || subjects.length >= plan.maximum_subjects || subjects.length >= plan.session_count || Boolean(learningProgram && subjects.length >= learningProgram.subjects.length)} className="inline-flex min-h-11 flex-1 items-center justify-center gap-1 rounded-xl bg-indigo-600 px-3 py-2 text-xs font-black text-white disabled:opacity-40 sm:flex-none">
                <Plus size={14} /> Tambah mapel
              </button>
            </div>
          </div>

          <div className="mt-5 space-y-5">
            {!timeSlots.length && <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-bold text-amber-800">Admin belum mengaktifkan slot jadwal belajar.</div>}
            {subjects.map((item, subjectIndex) => (
              <div key={item.key} className="rounded-3xl border border-slate-200 bg-slate-50 p-4 sm:p-5">
                <div className="flex items-center justify-between gap-3">
                  <h3 className="font-black text-slate-800">Mata pelajaran {subjectIndex + 1}</h3>
                  {subjects.length > 1 && (
                    <button type="button" aria-label={`Hapus mata pelajaran ${subjectIndex + 1}`} onClick={() => removeSubject(item.key)} className="grid h-11 w-11 place-items-center rounded-xl text-rose-500 hover:bg-rose-50">
                      <Trash2 size={17} />
                    </button>
                  )}
                </div>
                <div className="mt-4 grid gap-4 md:grid-cols-[1fr_220px]">
                  <Field label="Mata pelajaran">
                    <SubjectCombobox
                      options={subjectsForPicker(item)}
                      value={item.subject_name}
                      educationLevel={level}
                      grade={grade}
                      placeholder="Cari mata pelajaran"
                      disabled={Boolean(renewalId && renewalSubjectId)}
                      onChange={(name, option) =>
                        updateSubject(item.key, {
                          subject_name: name,
                          curriculum_subject_id: option?.id ?? "",
                          preferred_teacher_id: undefined,
                          curriculum_chapter_ids: [],
                          chapter: "",
                          learning_goal: "",
                          focus_mode: undefined,
                        })
                      }
                    />
                  </Field>
                  <div>
                    <span className="mb-2 block text-xs font-black uppercase tracking-wider text-slate-500">Alokasi sesi</span>
                    <div data-tour={subjectIndex === 0 ? "package-allocation" : undefined} className="flex min-h-12 items-center justify-between rounded-2xl border border-slate-200 bg-white p-1.5">
                      <button type="button" aria-label={`Kurangi sesi ${item.subject_name || `mapel ${subjectIndex + 1}`}`} disabled={subjects.length < 2 || item.session_count <= 1} onClick={() => adjustAllocation(item.key, -1)} className="grid h-11 w-11 place-items-center rounded-xl bg-slate-100 text-slate-700 disabled:cursor-not-allowed disabled:opacity-35"><Minus size={18} /></button>
                      <div className="text-center"><span className="text-xl font-black text-indigo-700">{item.session_count}</span><span className="ml-1 text-xs font-bold text-slate-500">sesi</span></div>
                      <button type="button" aria-label={`Tambah sesi ${item.subject_name || `mapel ${subjectIndex + 1}`}`} disabled={subjects.length < 2 || !subjects.some((subject) => subject.key !== item.key && subject.session_count > 1)} onClick={() => adjustAllocation(item.key, 1)} className="grid h-11 w-11 place-items-center rounded-xl bg-indigo-600 text-white disabled:cursor-not-allowed disabled:opacity-35"><Plus size={18} /></button>
                    </div>
                  </div>
                </div>
                {subjects.length > 1 && <p className="mt-2 text-xs font-medium leading-5 text-slate-500">Tombol + memindahkan satu sesi dari mapel lain. Total paket selalu tetap {plan?.session_count} sesi.</p>}
                <div className="mt-4 grid gap-4">
                  <div className="min-w-0">
                    <LearningFocusPicker key={`${item.curriculum_subject_id}-${level}-${grade}`} id={item.key} subjectId={item.curriculum_subject_id} level={level} grade={grade} showChapters={catalog.find((subject) => subject.id === item.curriculum_subject_id)?.show_chapter_selection !== false} isProgram={Boolean(learningProgram)} value={item} onChange={(patch) => updateSubject(item.key, patch)} />
                  </div>
                </div>
                <div className={step === 2 ? "mt-5 rounded-2xl border border-indigo-100 bg-white p-4" : "hidden"}>
                  <p className="mb-1 flex items-center gap-2 text-sm font-black text-slate-700"><CalendarPlus size={17} /> Atur pola jadwal sekali</p>
                  <p className="mb-3 text-xs font-semibold leading-5 text-slate-500">
                    Jadwal pertama dapat dimulai minimal {bookingLeadHours} jam dari sekarang. Setelah pembayaran terkonfirmasi, pencarian tutor berlangsung maksimal {bookingRules.maximum_search_hours} jam dan dihentikan {bookingRules.matching_cutoff_hours} jam sebelum kelas.
                  </p>
                  <div className="grid gap-4 md:grid-cols-2">
                    <Field label="Mulai belajar">
                      <input type="date" min={earliestScheduleDate(item.schedule_time || defaultSlotTime, bookingLeadHours)} value={item.schedule_start_date} onChange={(event) => updateSubject(item.key, { schedule_start_date: event.target.value })} className="form-field" />
                    </Field>
                    <div>
                      <span className="mb-2 block text-xs font-black uppercase tracking-wider text-slate-500">Jam belajar</span>
                      <ScheduleTimePicker
                        value={item.schedule_time}
                        options={availableTimeSlots}
                        disabled={!durationHours}
                        onChange={(value) => updateSubject(item.key, { schedule_time: value })}
                      />
                    </div>
                    <div className="md:col-span-2">
                      <span className="mb-2 block text-xs font-black uppercase tracking-wider text-slate-500">Hari belajar</span>
                      <div className="grid grid-cols-4 gap-2 sm:grid-cols-7">
                        {WEEKDAYS.map((day) => {
                          const active = item.weekdays.includes(day.value);
                          const limitReached = packageWeekdays.size >= weekdayLimit;
                          const replaceSingleDay = weekdayLimit === 1 && subjects.length === 1;
                          const disabled = !active && !packageWeekdays.has(day.value) && limitReached && !replaceSingleDay;
                          return <button
                            key={day.value}
                            type="button"
                            disabled={disabled}
                            aria-disabled={disabled}
                            onClick={() => {
                              if (disabled) return;
                              const weekdays = active
                                ? item.weekdays.filter((value) => value !== day.value)
                                : replaceSingleDay
                                  ? [day.value]
                                  : [...item.weekdays, day.value].sort((a, b) => a - b);
                              updateSubject(item.key, { weekdays });
                            }}
                            className={`min-h-11 rounded-xl border px-2 text-xs font-black transition ${active ? "border-indigo-600 bg-indigo-600 text-white" : disabled ? "cursor-not-allowed border-slate-100 bg-slate-100 text-slate-300" : "border-slate-200 bg-white text-slate-600 hover:border-indigo-300 hover:text-indigo-700"}`}
                            title={disabled ? `Maksimal ${weekdayLimit} hari belajar` : replaceSingleDay && !active && limitReached ? `Ganti ke ${day.label}` : day.label}
                          >
                            {day.short}
                          </button>;
                        })}
                      </div>
                      <div className="mt-2 flex flex-wrap items-center justify-between gap-1.5 text-xs font-medium">
                        <p className={packageWeekdays.size >= weekdayLimit ? "font-bold text-amber-700" : "text-slate-500"}>
                          Maksimal {weekdayLimit} hari unik untuk seluruh paket · {packageWeekdays.size}/{weekdayLimit} dipilih
                        </p>
                        {packageWeekdays.size >= weekdayLimit && <span className="rounded-full bg-amber-100 px-2 py-1 font-black text-amber-800">Batas tercapai</span>}
                      </div>
                      <p className="mt-1 text-xs font-medium text-slate-500">{weekdayLimit === 1 ? "Ketuk hari lain untuk langsung mengganti pilihan. Ketuk hari aktif untuk mengosongkannya." : "Jam yang dipilih berlaku sama pada seluruh hari. Tanggal tertentu dapat digeser sebelum tutor dicari."}</p>
                    </div>
                  </div>
                  <div className="mt-4 rounded-2xl border border-violet-200 bg-violet-50 p-4">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                      <div>
                        <p className="flex items-center gap-2 text-sm font-black text-violet-950"><Sparkles size={17} /> Rekomendasikan jadwal terbaik</p>
                        <p className="mt-1 text-xs font-semibold leading-5 text-violet-700">Hari pilihanmu tetap. Sistem hanya membandingkan jam dan memastikan guru yang sama berpotensi tersedia untuk seluruh sesi.</p>
                      </div>
                      <button
                        type="button"
                        onClick={() => void recommendSchedule(item)}
                        disabled={Boolean(recommendationLoading[item.key]) || !item.curriculum_subject_id || !level || !grade || !mode || !durationHours || !item.schedule_start_date || !item.weekdays.length}
                        className="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-violet-700 px-4 py-2.5 text-xs font-black text-white disabled:cursor-not-allowed disabled:opacity-40"
                      >
                        {recommendationLoading[item.key] ? <Loader2 size={16} className="animate-spin" /> : <Sparkles size={16} />}
                        {recommendationLoading[item.key] ? "Menghitung..." : "Cari jam terbaik"}
                      </button>
                    </div>
                    {scheduleRecommendations[item.key] && (
                      <div className="mt-4 space-y-2">
                        <p className="text-xs font-semibold leading-5 text-violet-800">{scheduleRecommendations[item.key].message}</p>
                        {scheduleRecommendations[item.key].recommendations.map((recommendation) => {
                          const label = recommendation.availability === "very_high"
                            ? "Sangat tinggi"
                            : recommendation.availability === "high"
                              ? "Tinggi"
                              : recommendation.availability === "limited"
                                ? "Terbatas"
                                : "Belum tersedia";
                          return (
                            <div key={recommendation.time} className="flex flex-col gap-2 rounded-xl border border-violet-100 bg-white p-3 sm:flex-row sm:items-center sm:justify-between">
                              <div>
                                <p className="text-sm font-black text-slate-900">{recommendation.time} WIB <span className="text-violet-700">· Peluang {label}</span></p>
                                <p className="mt-0.5 text-xs font-semibold text-slate-500">
                                  {recommendation.candidate_count > 0
                                    ? `Sekitar ${recommendation.candidate_count} guru berpotensi memenuhi seluruh sesi.`
                                    : "Belum terlihat guru untuk seluruh rangkaian sesi pada jam ini."}
                                </p>
                              </div>
                              <button
                                type="button"
                                disabled={recommendation.candidate_count < 1}
                                onClick={() => applyScheduleRecommendation(item.key, recommendation)}
                                className="min-h-10 rounded-xl border border-violet-200 px-3 text-xs font-black text-violet-800 disabled:cursor-not-allowed disabled:opacity-40"
                              >
                                Gunakan jam ini
                              </button>
                            </div>
                          );
                        })}
                      </div>
                    )}
                  </div>
                  <div className="mt-4 rounded-2xl bg-indigo-50 p-4 text-sm text-indigo-900">
                    <p className="font-black">{item.subject_name || `Mapel ${subjectIndex + 1}`} · {item.schedules.length} pertemuan otomatis</p>
                    <p className="mt-1 text-xs font-medium leading-5 text-indigo-700">
                      {item.schedules.length ? `${item.weekdays.map((day) => WEEKDAYS.find((option) => option.value === day)?.label).filter(Boolean).join(", ")} · ${new Date(item.schedules[0]).toLocaleDateString("id-ID", { dateStyle: "medium" })} sampai ${new Date(item.schedules[item.schedules.length - 1]).toLocaleDateString("id-ID", { dateStyle: "medium" })}. Jam tiap pertemuan dapat diubah di bawah.` : "Jadwal belum dipilih."}
                    </p>
                    <details className="mt-3">
                      <summary className="cursor-pointer text-xs font-black">Lihat atau ubah tanggal dan jam tiap pertemuan</summary>
                      <ol className="mt-2 grid gap-1.5 sm:grid-cols-2">
                        {item.schedules.map((schedule, index) => (
                          <li key={`${item.key}-${index}`} className="grid min-w-0 gap-2 rounded-xl bg-white px-3 py-2 text-xs font-bold text-slate-700 sm:grid-cols-[1fr_150px] sm:items-center">
                            <span className="min-w-0 break-words">{index + 1}. {fullSchedule(schedule, effectiveDurationHours)}</span>
                            <input
                              type="date"
                              aria-label={`Ubah tanggal sesi ${index + 1}`}
                              min={earliestScheduleDate(item.schedule_time, bookingLeadHours)}
                              value={schedule.slice(0, 10)}
                              onChange={(event) => {
                                const nextSchedules = [...item.schedules];
                                nextSchedules[index] = `${event.target.value}T${schedule.slice(11, 16)}`;
                                nextSchedules.sort((a, b) => new Date(a).getTime() - new Date(b).getTime());
                                updateSubject(item.key, { schedules: nextSchedules });
                              }}
                              className="min-h-10 min-w-0 rounded-lg border border-slate-200 bg-slate-50 px-2 text-xs font-bold text-slate-700"
                            />
                            <details className="sm:col-span-2">
                              <summary className="cursor-pointer text-xs text-indigo-700">Ubah jam sesi {index + 1}</summary>
                              <div className="mt-2"><TimeSlotOptions value={schedule.slice(11, 16)} options={availableTimeSlots} onChoose={(time) => {
                                const nextSchedules = [...item.schedules];
                                nextSchedules[index] = `${schedule.slice(0, 10)}T${time}`;
                                nextSchedules.sort((a, b) => new Date(a).getTime() - new Date(b).getTime());
                                updateSubject(item.key, { schedules: nextSchedules });
                              }} /></div>
                            </details>
                          </li>
                        ))}
                      </ol>
                    </details>
                  </div>
                </div>
              </div>
            ))}
          </div>
          {!schedulesDoNotOverlap && <div className="mt-4 flex gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-bold text-rose-700"><AlertCircle className="shrink-0" size={19} />Ada jadwal yang bertumpang tindih. Ubah tanggal atau jam salah satunya.</div>}
          {!subjectsAreUnique && <div className="mt-4 flex gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-bold text-rose-700"><AlertCircle className="shrink-0" size={19} />Satu mata pelajaran tidak boleh dipilih dua kali.</div>}
          {Boolean(plan && scheduleRangeDays > plan.validity_days) && <div className="mt-4 flex gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-bold text-rose-700"><AlertCircle className="shrink-0" size={19} />Rentang jadwal melebihi masa paket {plan?.validity_days} hari. Tingkatkan frekuensi belajar atau ubah tanggal mulai.</div>}
          </> : <p className="rounded-2xl border border-dashed border-orange-200 bg-white p-4 text-sm font-semibold text-slate-600">Pilih program di atas untuk melihat mapel yang tersedia.</p>}
        </section>

        {isGuest && mode === "offline" && <div hidden={step !== 2}><GuestOrderIdentity section="location" name={guestName} email={guestEmail} phone={guestPhone} mode={mode} location={guestLocation} onIdentityChange={(field, value) => { if (field === "name") setGuestName(value); else if (field === "email") setGuestEmail(value); else setGuestPhone(value); }} onLocationChange={setGuestLocation} /></div>}

        <section className={step === 3 ? "render-auto-tall grid min-w-0 gap-5 lg:grid-cols-[minmax(0,1fr)_360px]" : "hidden"}>
          <div className="min-w-0 overflow-hidden rounded-[1.5rem] border border-slate-100 bg-white p-4 shadow-sm sm:rounded-[2rem] sm:p-7">
            <h2 className="break-words text-lg font-black text-slate-900">Voucher atau kode promo</h2>
            <p className="mt-1 text-sm text-slate-500">{isGuest ? "Voucher dan kode promo dapat diperiksa setelah masuk. Harga draf belum memakai promo." : "Satu transaksi hanya memakai satu voucher atau kode."}</p>
            <div className={isGuest ? "hidden" : "mt-4 grid gap-4 md:grid-cols-2"}>
              <Field label="Voucher Saya">
                <ResponsiveSelect
                  value={voucherId}
                  ariaLabel="Pilih voucher"
                  placeholder="Tanpa voucher"
                  options={[
                    { value: "", label: "Tanpa voucher" },
                    ...vouchers.map((item) => ({ value: item.id, label: item.promotion?.title || "Voucher tidak tersedia" })),
                  ]}
                  onValueChange={(next) => { setVoucherId(Number(next) || ""); setPromoCode(""); setPromoError(null); }}
                />
              </Field>
              <Field label="Masukkan kode promo">
                <div className="relative">
                  <Tag className="absolute left-3 top-3.5 text-slate-500" size={17} />
                  <input value={promoCode} disabled={Boolean(voucherId)} onChange={(event) => { setPromoCode(event.target.value.toUpperCase()); setPromoError(null); }} className="form-field pl-10 uppercase disabled:bg-slate-100" placeholder="BELAJAR20" />
                </div>
              </Field>
            </div>
            {promoError && (
              <div className="mt-4 flex min-w-0 items-start gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-bold text-rose-700">
                <AlertCircle className="mt-0.5 shrink-0" size={18} />
                <p className="min-w-0 break-words">{promoError}</p>
              </div>
            )}
          </div>

          <aside className="min-w-0 overflow-hidden rounded-[2rem] bg-slate-950 p-5 text-white shadow-xl sm:p-6">
            <div className="flex items-center gap-2 text-indigo-100"><Clock3 size={17} /><span className="text-xs font-black uppercase tracking-wider">Ringkasan harga</span></div>
            {quoting ? (
              <div className="grid h-32 place-items-center"><Loader2 className="animate-spin" /></div>
            ) : quote ? (
              <>
                <div className="mt-5 space-y-3 text-sm">
                  {quote.lines.map((line) => (
                    <div key={line.curriculum_subject_id} className="flex min-w-0 items-start justify-between gap-3 text-slate-300">
                      <span className="min-w-0 break-words">{line.subject_name} · {line.session_count} sesi × {line.duration_hours} jam</span>
                      <span className="shrink-0 text-right">{rupiah(line.subtotal_amount)}</span>
                    </div>
                  ))}
                  <div className="border-t border-white/10 pt-3">
                    <div className="flex justify-between text-slate-300"><span>Harga normal</span><span className={quote.discount_amount ? "text-xs line-through" : ""}>{rupiah(quote.subtotal_amount)}</span></div>
                    {quote.discount_amount > 0 && <div className="mt-2 flex justify-between text-emerald-300"><span>Potongan</span><span>-{rupiah(quote.discount_amount)}</span></div>}
                  </div>
                </div>
                <p className="mt-5 text-xs font-bold text-slate-300">Total pembayaran</p>
                <p className="mt-1 break-all text-2xl font-black sm:text-3xl">{rupiah(quote.total_amount)}</p>
                {quote.promotion && <span className="mt-2 inline-flex rounded-full bg-emerald-400/15 px-2.5 py-1 text-[10px] font-black text-emerald-300">🏷️ {quote.promotion.title}</span>}
              </>
            ) : (
              <p className="mt-5 text-sm leading-6 text-slate-300">Lengkapi alokasi sesi untuk melihat harga akhir.</p>
            )}
            <button data-tour="package-review-order" type="button" disabled={!draftValid || !quote || submitting} onClick={() => setSummaryOpen(true)} className="mt-6 flex h-14 w-full items-center justify-center gap-2 rounded-2xl bg-white font-black text-slate-950 transition hover:bg-indigo-50 disabled:cursor-not-allowed disabled:opacity-40">
              <FileCheck2 size={18} />
              Periksa Pesanan
            </button>
          </aside>
        </section>

        <div className="grid min-w-0 grid-cols-1 gap-3 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm min-[420px]:grid-cols-2">
          {step > 0 ? <button type="button" onClick={() => moveStep(step - 1)} className="min-h-12 min-w-0 rounded-xl border border-slate-200 px-4 text-sm font-black text-slate-700">Kembali</button> : <p className="flex min-h-12 min-w-0 items-center break-words text-sm leading-5 text-slate-500">Lengkapi tahap pertama untuk melanjutkan.</p>}
          {step < 3 && <button type="button" onClick={() => moveStep(step + 1)} className="min-h-12 min-w-0 break-words rounded-xl bg-indigo-600 px-4 py-2 text-sm font-black leading-5 text-white">Lanjut: {["Mapel & materi", "Jadwal & lokasi", "Periksa pesanan"][step]}</button>}
        </div>
          </div>

          {step < 3 && (
            <aside className="hidden min-w-0 self-start overflow-hidden rounded-[2rem] border border-orange-100 bg-white shadow-[0_24px_60px_-36px_rgba(15,23,42,.45)] lg:sticky lg:top-24 lg:block">
              <div className="border-b border-orange-100 bg-gradient-to-br from-orange-50 via-amber-50 to-white p-5">
                <div className="flex items-center justify-between gap-3">
                  <div><p className="text-[10px] font-black uppercase tracking-[.2em] text-orange-600">Ringkasan langsung</p><h2 className="mt-1 text-xl font-black text-slate-950">Rencana Anda</h2></div>
                  <span className="grid h-11 w-11 place-items-center rounded-2xl bg-orange-500 text-white"><FileCheck2 size={20} /></span>
                </div>
                <div className="mt-4 grid grid-cols-4 gap-1.5" aria-label={`Langkah ${step + 1} dari 4`}>
                  {[0, 1, 2, 3].map((index) => <span key={index} className={`h-1.5 rounded-full ${index <= step ? "bg-orange-500" : "bg-orange-100"}`} />)}
                </div>
              </div>

              <div className="max-h-[calc(100dvh-13rem)] overflow-y-auto p-5">
                <dl className="space-y-3 text-sm">
                  <div className="flex items-start justify-between gap-4"><dt className="text-slate-500">Paket belajar</dt><dd className="max-w-[11rem] break-words text-right font-black text-slate-900">{plan?.name || "Belum dipilih"}</dd></div>
                  {learningProgram && <div className="flex items-start justify-between gap-4"><dt className="text-slate-500">Program</dt><dd className="max-w-[11rem] break-words text-right font-black text-slate-900">{learningProgram.name}</dd></div>}
                  <div className="flex items-start justify-between gap-4"><dt className="text-slate-500">Peserta</dt><dd className="text-right font-black text-slate-900">{participantCount} orang{participantDiscountPercent > 0 && <span className="mt-1 block text-[10px] font-black text-emerald-700">Diskon {participantDiscountPercent.toLocaleString("id-ID", { maximumFractionDigits: 2 })}%</span>}</dd></div>
                  <div className="flex items-start justify-between gap-4"><dt className="text-slate-500">Jenjang</dt><dd className="max-w-[11rem] break-words text-right font-black text-slate-900">{level && grade ? `${level} · ${grade}` : "Belum dipilih"}</dd></div>
                  <div className="flex items-start justify-between gap-4"><dt className="text-slate-500">Mapel</dt><dd className="max-w-[11rem] break-words text-right font-black text-slate-900">{selectedSubjectSummary || "Belum dipilih"}</dd></div>
                  <div className="flex items-start justify-between gap-4"><dt className="text-slate-500">Metode</dt><dd className="text-right font-black text-slate-900">{mode === "online" ? "Online" : mode === "offline" ? "Tatap muka" : "Belum dipilih"}</dd></div>
                  <div className="flex items-start justify-between gap-4"><dt className="text-slate-500">Durasi</dt><dd className="text-right font-black text-slate-900">{durationHours ? `${durationHours} jam/sesi` : "Belum dipilih"}</dd></div>
                  <div className="flex items-start justify-between gap-4"><dt className="text-slate-500">Jadwal</dt><dd className="max-w-[11rem] break-words text-right font-black text-slate-900">{plan ? `${scheduleTimestamps.length} dari ${plan.session_count} sesi` : "Menunggu paket"}</dd></div>
                  <div className="flex items-start justify-between gap-4"><dt className="text-slate-500">Lokasi</dt><dd className="max-w-[11rem] break-words text-right font-black text-slate-900">{mode === "online" ? "Tidak diperlukan" : mode === "offline" ? (hasOfflineLocation ? "Lokasi siap" : "Belum lengkap") : "Menunggu metode"}</dd></div>
                </dl>

                <div className="mt-5 border-t border-slate-100 pt-5">
                  <div className="flex items-end justify-between gap-3"><span className="text-xs font-black uppercase tracking-wider text-slate-400">Estimasi total</span><span className="break-all text-right text-xl font-black text-slate-950">{quote ? rupiah(quote.total_amount) : "Belum dihitung"}</span></div>
                  {!quote && <p className="mt-1 text-xs leading-5 text-slate-500">Harga muncul setelah paket, materi, jadwal, dan lokasi lengkap.</p>}
                </div>

                {planMissingItems.length > 0 && (
                  <div className="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                    <p className="text-xs font-black uppercase tracking-wider text-amber-800">Yang masih perlu diisi</p>
                    <ul className="mt-2 space-y-2 text-xs font-semibold leading-5 text-amber-900">{planMissingItems.slice(0, 5).map((item) => <li key={item} className="flex gap-2"><span className="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-orange-500" />{item}</li>)}</ul>
                  </div>
                )}

              </div>
            </aside>
          )}
        </div>

        {summaryOpen && quote && plan && (
          <div className="fixed inset-0 z-[var(--layer-modal)] flex items-end justify-center bg-slate-950/70 p-3 pb-[max(env(safe-area-inset-bottom),0.75rem)] sm:items-center sm:p-4" onMouseDown={(event) => { if (event.target === event.currentTarget && !submitting) setSummaryOpen(false); }}>
            <section role="dialog" aria-modal="true" aria-labelledby="order-summary-title" className="flex max-h-[calc(100dvh-1.5rem)] w-full max-w-3xl flex-col overflow-hidden rounded-[2rem] bg-white shadow-2xl sm:max-h-[94dvh]">
              <header className="flex min-w-0 shrink-0 items-start justify-between gap-4 border-b border-slate-100 p-5 sm:p-7">
                <div className="min-w-0">
                  <p className="break-words text-xs font-black uppercase tracking-[.18em] text-indigo-600">Langkah terakhir sebelum pembayaran</p>
                  <h2 id="order-summary-title" className="mt-2 text-2xl font-black text-slate-900">Periksa rincian bimbel</h2>
                  <p className="mt-1 text-sm text-slate-500">Pastikan paket, mapel, dan seluruh tanggal sudah tepat.</p>
                </div>
                <button type="button" disabled={submitting} aria-label="Tutup ringkasan" onClick={() => setSummaryOpen(false)} className="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-slate-100 text-slate-500 hover:bg-slate-200"><X size={20} /></button>
              </header>

              <div className="min-h-0 space-y-5 overflow-y-auto p-5 sm:p-7">
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                  <SummaryItem label="Paket" value={`${plan.name} · ${plan.session_count} sesi`} />
                  <SummaryItem label="Durasi" value={`${effectiveDurationHours} jam/pertemuan`} />
                  <SummaryItem label="Jenjang" value={`${level} · ${grade}`} />
                  <SummaryItem label="Metode" value={mode === "online" ? "Online" : "Offline"} />
                </div>
                <div className="rounded-2xl border border-slate-200 p-4">
                  <p className="text-xs font-black uppercase tracking-wider text-slate-500">Peserta belajar</p>
                  <p className="mt-2 text-sm font-bold text-slate-800">
                    {participantCount} peserta · {purchaserParticipates ? "pemesan ikut belajar" : "pemesan tidak ikut belajar"}
                  </p>
                  {participantDetails.slice(0, expectedParticipantDetails).length > 0 && (
                    <ul className="mt-3 grid gap-2 sm:grid-cols-2">
                      {participantDetails.slice(0, expectedParticipantDetails).map((detail, index) => (
                        <li key={`${detail.full_name}-${index}`} className="rounded-xl bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-600">
                          {detail.full_name}{detail.nickname ? ` · ${detail.nickname}` : ""}
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
                {mode === "offline" && <div className="rounded-2xl border border-slate-200 p-4"><p className="text-xs font-black uppercase tracking-wider text-slate-500">Lokasi belajar</p><p className="mt-2 text-sm font-bold leading-6 text-slate-800">{selectedLocation?.address}</p>{selectedLocation?.maps_link && <a href={selectedLocation.maps_link} target="_blank" rel="noreferrer" className="mt-2 inline-block text-xs font-black text-indigo-600">Buka peta</a>}</div>}
                <div>
                  <h3 className="text-sm font-black text-slate-900">Mapel, pembagian, dan jadwal</h3>
                  <div className="mt-3 space-y-3">
                    {subjects.map((item) => (
                      <div key={item.key} className="rounded-2xl border border-slate-200 p-4">
                        <div className="flex flex-wrap items-start justify-between gap-2"><div className="min-w-0"><p className="font-black text-slate-900">{item.subject_name}</p>{item.chapter && <p className="mt-1 break-words text-xs font-medium text-indigo-600">Bab: {item.chapter}</p>}{item.learning_goal && <p className="mt-1 whitespace-pre-wrap break-words text-xs font-medium text-slate-500">{item.learning_goal}</p>}</div><span className="rounded-full bg-indigo-50 px-3 py-1 text-xs font-black text-indigo-700">{item.session_count} sesi</span></div>
                        <ol className="mt-3 grid gap-1.5 sm:grid-cols-2">
                          {item.schedules.map((schedule, index) => <li key={`${item.key}-summary-${schedule}-${index}`} className="rounded-xl bg-slate-50 px-3 py-2 text-xs font-bold text-slate-600">{index + 1}. {fullSchedule(schedule, effectiveDurationHours)}</li>)}
                        </ol>
                      </div>
                    ))}
                  </div>
                </div>
                <div className="rounded-2xl bg-slate-950 p-5 text-white">
                  <p className="mb-3 text-xs font-bold text-slate-300">Total waktu belajar: {plan.session_count * effectiveDurationHours} jam</p>
                  <div className="flex justify-between text-sm text-slate-300"><span>Harga normal</span><span>{rupiah(quote.subtotal_amount)}</span></div>
                  {quote.discount_amount > 0 && <div className="mt-2 flex justify-between text-sm text-emerald-300"><span>Potongan</span><span>-{rupiah(quote.discount_amount)}</span></div>}
                  <div className="mt-4 flex flex-wrap items-end justify-between gap-2 border-t border-white/10 pt-4"><span className="text-sm font-black">Total pembayaran</span><span className="break-all text-right text-xl font-black sm:text-2xl">{rupiah(quote.total_amount)}</span></div>
                </div>
                <div className="rounded-2xl border border-blue-100 bg-blue-50 p-4 text-sm font-semibold leading-6 text-blue-900">Setelah dikonfirmasi, kamu langsung menuju pembayaran. Pencarian tutor baru dimulai setelah pembayaran dinyatakan diterima oleh sistem.</div>
              </div>

              <footer className="grid shrink-0 gap-3 border-t border-slate-100 bg-white p-4 sm:grid-cols-2 sm:px-7">
                <button type="button" disabled={submitting} onClick={() => setSummaryOpen(false)} className="min-h-12 rounded-2xl border border-slate-200 px-5 text-sm font-black text-slate-700 hover:bg-slate-50">Kembali & Ubah</button>
                <button type="button" disabled={submitting} onClick={submit} className="flex min-h-12 items-center justify-center gap-2 rounded-2xl bg-indigo-600 px-5 text-sm font-black text-white hover:bg-indigo-700 disabled:opacity-60">{submitting ? <Loader2 className="animate-spin" size={18} /> : <FileCheck2 size={18} />}{submitting ? (isGuest ? "Menyimpan draf…" : "Membuat tagihan…") : (isGuest ? "Simpan pesanan" : "Konfirmasi & Bayar")}</button>
              </footer>
            </section>
          </div>
        )}
        </>}
      </div>
    </PackageBuilderShell>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return <label className="block min-w-0 max-w-full"><span className="mb-2 block text-xs font-black uppercase tracking-wider text-slate-500">{label}</span>{children}</label>;
}

function timeSlotLabel(slot: TimeSlot) {
  if (slot.label?.trim()) return slot.label;
  return `${slot.start_time.slice(0, 5).replace(":", ".")} WIB`;
}

function timePeriodLabel(time: string) {
  const hour = Number(time.slice(0, 2));
  if (hour < 11) return "Pagi";
  if (hour < 16) return "Siang";
  if (hour < 19) return "Sore";
  return "Malam";
}

function ScheduleTimePicker({ value, options, disabled = false, onChange }: { value: string; options: TimeSlot[]; disabled?: boolean; onChange: (value: string) => void }) {
  return <div className="min-w-0 rounded-xl border border-slate-200 bg-slate-50 p-3">
    {disabled || !options.length ? <p className="text-xs text-slate-500">Pilih durasi terlebih dahulu.</p> : <TimeSlotOptions value={value} options={options} onChoose={onChange} />}
  </div>;
}

function TimeSlotOptions({ value, options, onChoose }: { value: string; options: TimeSlot[]; onChoose: (value: string) => void }) {
  return (
    <div className="space-y-4" role="listbox" aria-label="Daftar jam belajar">
      {["Pagi", "Siang", "Sore", "Malam"].map((period) => {
        const periodOptions = options.filter((slot) => timePeriodLabel(slot.start_time.slice(0, 5)) === period);
        if (!periodOptions.length) return null;
        return (
          <div key={period}>
            <p className="mb-2 text-[10px] font-black uppercase tracking-[.16em] text-slate-400">{period}</p>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
              {periodOptions.map((slot) => {
                const slotValue = slot.start_time.slice(0, 5);
                const active = slotValue === value;
                return (
                  <button
                    key={slot.id}
                    type="button"
                    role="option"
                    aria-selected={active}
                    onClick={() => onChoose(slotValue)}
                    className={`relative min-h-11 min-w-0 rounded-xl border px-2 py-2 text-center text-xs font-black transition ${active ? "border-indigo-600 bg-indigo-600 text-white shadow-sm" : "border-slate-200 bg-white text-slate-700 hover:border-indigo-300 hover:bg-indigo-50"}`}
                  >
                    <span className="block whitespace-normal break-words leading-tight">{timeSlotLabel(slot)}</span>
                    {active && <Check className="absolute right-1.5 top-1.5" size={11} aria-hidden="true" />}
                  </button>
                );
              })}
            </div>
          </div>
        );
      })}
    </div>
  );
}


function SummaryItem({ label, value }: { label: string; value: string }) {
  return <div className="rounded-2xl border border-slate-200 p-4"><p className="text-[10px] font-black uppercase tracking-wider text-slate-500">{label}</p><p className="mt-2 text-sm font-black text-slate-900">{value}</p></div>;
}

function ErrorState({ type, onRetry }: { type: ErrorType; onRetry: () => void }) {
  const config = {
    network: { icon: WifiOff, color: "text-slate-500", bg: "bg-slate-50", border: "border-slate-200", title: "Tidak ada koneksi", desc: "Periksa koneksi internet kamu, lalu coba lagi." },
    unauthorized: { icon: AlertCircle, color: "text-orange-600", bg: "bg-orange-50", border: "border-orange-200", title: "Sesi berakhir", desc: "Silakan masuk kembali ke akun kamu." },
    forbidden: { icon: AlertCircle, color: "text-amber-600", bg: "bg-amber-50", border: "border-amber-200", title: "Akses ditolak", desc: "Kamu tidak memiliki izin untuk halaman ini." },
    not_found: { icon: AlertCircle, color: "text-slate-500", bg: "bg-slate-50", border: "border-slate-200", title: "Tidak ditemukan", desc: "Data yang kamu cari tidak tersedia." },
    generic: { icon: AlertCircle, color: "text-rose-600", bg: "bg-rose-50", border: "border-rose-200", title: "Terjadi kesalahan", desc: "Gagal memuat data. Silakan coba lagi." },
  };
  if (!type) return null;
  const { icon: Icon, color, bg, border, title, desc } = config[type];
  return (
    <div className={`flex flex-col items-center justify-center gap-4 rounded-3xl border-2 ${border} ${bg} px-6 py-16 text-center`}>
      <Icon size={40} className={color} />
      <div>
        <p className={`text-lg font-black ${color}`}>{title}</p>
        <p className="mt-1 text-sm text-slate-500">{desc}</p>
      </div>
      <button type="button" onClick={onRetry} className="inline-flex items-center gap-2 rounded-xl bg-white px-5 py-2.5 text-sm font-black text-slate-700 shadow-sm border border-slate-200 hover:bg-slate-50 transition">
        <RefreshCw size={15} /> Coba lagi
      </button>
    </div>
  );
}
