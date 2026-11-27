import { Component, CSSProperties, ErrorInfo, ReactNode, useCallback, useEffect, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { CheckCircle2, ChevronLeft, ChevronRight, HelpCircle, X } from "lucide-react";
import { useLocation } from "react-router-dom";

import { getCached } from "@/lib/http";
import { publicMediaUrl } from "@/lib/apiBase";
import { scheduleNonCriticalTask } from "@/lib/schedule";
import { getPackageBuilderTutorialKey, getTutorialAccountKey } from "@/lib/tutorialStorage";

type Role = "student" | "teacher" | "admin";
type Callout = { selector?: string; target?: string } | string | null;
type Step = {
  id?: number;
  title: string;
  body: string;
  image_url?: string | null;
  image_path?: string | null;
  callout?: Callout;
  target?: string;
};
type Tutorial = { id: number; title: string; description?: string | null; steps: Step[] };
type Spotlight = { top: number; left: number; width: number; height: number };

const fallback: Record<Role, Tutorial> = {
  student: {
    id: -1,
    title: "Panduan murid: yuk belajar",
    description: "Ikuti gambar kuningnya. Kita jalan pelan-pelan, ya.",
    steps: [
      {
        title: "Mulai dari Cari Les",
        body: "Tekan tombol ini dulu. Di sini kamu pilih pelajaran, hari, dan jam belajarmu.",
        target: '[data-tour="student-cari-les"]',
      },
      {
        title: "Cek dulu, lalu bayar",
        body: "Sebelum bayar, baca lagi pilihanmu. Kalau ada yang salah, kamu boleh ubah dulu.",
        target: '[data-tour="student-cari-les"]',
      },
      {
        title: "Lihat kelasmu di sini",
        body: "Sesudah bayar, buka Kelas Saya. Di sana ada jadwal, nama tutor, dan kabar kelasmu.",
        target: '[data-tour="student-kelas"]',
      },
      {
        title: "Butuh bantuan? Ke sini",
        body: "Di halaman Saya ada profil, riwayat bayar, voucher, dan tombol bantuan.",
        target: '[data-tour="student-saya"]',
      },
    ],
  },
  teacher: {
    id: -2,
    title: "Panduan tutor: yuk mengajar",
    description: "Ikuti garis kuningnya. Bacanya pelan-pelan saja.",
    steps: [
      { title: "Ada tawaran mengajar", body: "Tekan sini jika ada murid yang minta diajar. Baca hari, jam, pelajaran, dan uangnya dulu.", target: '[href="/guru/permintaan"]' },
      { title: "Kelas yang sudah jadi", body: "Tekan sini untuk masuk ke kelas. Ikuti tombol yang muncul untuk mulai sesi, mengajar, lalu menyimpan hasil belajar.", target: '[href="/guru/kelas"]' },
      { title: "Pesan untuk kelas", body: "Tekan sini untuk ngobrol dengan murid setelah kelas sudah dibayar.", target: '[href="/guru/pesan"]' },
      { title: "Uang dan data kamu", body: "Tekan sini untuk lihat uang, rekening, profil, dan bantuan.", target: '[href="/guru/saya"]' },
    ],
  },
  admin: {
    id: -10,
    title: "Panduan admin: pusat operasional",
    description: "Gunakan menu utama untuk memeriksa kelas, pembayaran, tutor, dan konten.",
    steps: [
      { title: "Mulai dari ringkasan", body: "Buka ringkasan kerja untuk melihat kondisi operasional yang perlu diperiksa.", target: '[href="/admin"]' },
      { title: "Pantau pencarian tutor", body: "Buka pencarian tutor untuk melihat permintaan yang masih mencari pengajar.", target: '[href="/admin/tutor-searches"]' },
      { title: "Periksa pembayaran", body: "Buka pembayaran murid untuk memverifikasi bukti yang masuk.", target: '[href="/admin/pembayaran"]' },
      { title: "Kelola Kelas Bersama", body: "Buka menu Kelas Bersama untuk membuat paket dan memantau tutor serta peserta.", target: '[href="/admin/kelas-murah"]' },
    ],
  },
};

const packageBuilderTutorial: Tutorial = {
  id: -3,
  title: "Cara pilih paket belajar",
  description: "Pilih satu-satu. Tidak perlu buru-buru.",
  steps: [
    {
      title: "Pilih paket",
      body: "Pilih kotak paket yang kamu mau. Kalau mau lebih dari satu pelajaran, pilih paket yang ada tulisan 2 atau 3 mapel.",
      target: '[data-tour="package-plan-picker"]',
    },
    {
      title: "Pilih lama belajar",
      body: "Pilih mau belajar 1 atau 2 jam setiap kali bertemu.",
      target: '[data-tour="package-duration-picker"]',
    },
    {
      title: "Tambah pelajaran",
      body: "Mau belajar dua pelajaran? Tekan tombol ini. Nanti jumlah pertemuannya dibagi oleh sistem.",
      target: '[data-tour="package-add-subject"]',
    },
    {
      title: "Bagi jumlah pertemuan",
      body: "Tombol minus artinya kurangi satu pertemuan. Tombol plus artinya tambah satu pertemuan.",
      target: '[data-tour="package-allocation"]',
    },
    {
      title: "Terakhir, cek pesanan",
      body: "Kalau semua sudah benar, tekan tombol ini. Lalu cek lagi sebelum bayar.",
      target: '[data-tour="package-review-order"]',
    },
  ],
};

const contextualTutorials: Record<Role, Record<string, Tutorial>> = {
  student: {
    "cheap-classes": {
      id: -4,
      title: "Cara ikut Kelas Bersama",
      description: "Kelas ini belajar ramai-ramai. Lihat garis kuning, ya.",
      steps: [
        { title: "Ini Kelas Bersama", body: "Di sini kamu bisa belajar bersama teman lain dengan harga lebih hemat.", target: '[data-tour="cheap-class-hero"]' },
        { title: "Baca kartu kelas", body: "Lihat pelajaran, hari, jam, harga, dan jumlah teman yang sudah ikut.", target: '[data-tour="cheap-class-list"]' },
        { title: "Mau ikut? Tekan Gabung", body: "Kalau waktunya cocok, tekan Gabung Kelas Bersama. Kursimu ditahan sebentar supaya kamu bisa bayar.", target: '[data-tour="cheap-class-list"]' },
      ],
    },
    classes: {
      id: -5,
      title: "Cara melihat kelasmu",
      description: "Semua kelas yang kamu punya ada di halaman ini.",
      steps: [
        { title: "Lihat jadwal di sini", body: "Cari kelas yang mau kamu lihat. Tekan tombol Lihat detail pada kartu kelasnya.", target: '[data-tour="student-classes-hero"]' },
        { title: "Pilih kelas yang mau dilihat", body: "Di kartu kelas ada nama pelajaran, jam, tutor, dan tombol untuk melihat lebih banyak.", target: '[data-tour="student-class-list"]' },
        { title: "Kalau ada yang belum dibayar", body: "Tekan Bayar sekarang. Setelah pembayaran dicek, link kelas akan terbuka saat waktunya tiba.", target: '[data-tour="student-class-list"]' },
      ],
    },
  },
  teacher: {
    "teacher-requests": {
      id: -6,
      title: "Cara jawab tawaran mengajar",
      description: "Jangan langsung tekan Terima. Baca dulu semuanya.",
      steps: [
        { title: "Ini daftar tawaran", body: "Tawaran dari murid akan muncul di sini. Angka ini menunjukkan berapa yang masih menunggu jawaban.", target: '[data-tour="teacher-requests-hero"]' },
        { title: "Baca kartu sampai selesai", body: "Cek pelajaran, hari, jam, cara belajar, dan uang yang kamu dapat.", target: '[data-tour="teacher-offer-card"]' },
        { title: "Baru pilih Terima atau Tolak", body: "Tekan Terima kalau kamu benar-benar bisa mengajar di semua jadwalnya. Kalau tidak bisa, tekan Tolak dan pilih alasannya.", target: '[data-tour="teacher-offer-card"]' },
      ],
    },
    "teacher-classes": {
      id: -7,
      title: "Cara menjalankan kelas",
      description: "Buka kelas yang mau kamu ajar, lalu ikuti tombolnya satu-satu.",
      steps: [
        { title: "Urutan saat mengajar", body: "Untuk sesi baru: tekan Saya Siap Mengajar, tunggu murid mengonfirmasi hadir, lalu fokus mengajar sampai waktunya menyimpan hasil belajar.", target: '[data-tour="teacher-class-steps"]' },
        { title: "Pilih kelas", body: "Tekan Kelola sesi pada kelas yang akan kamu ajar. Di dalamnya ada link Zoom dan tombol tindakan.", target: '[data-tour="teacher-class-list"]' },
        { title: "Simpan hasil belajar", body: "Sesudah kelas selesai, akhiri sesi lalu isi hasil belajar singkat. Murid akan diminta memilih Sesi Sesuai atau Ada masalah.", target: '[data-tour="teacher-class-list"]' },
      ],
    },
    "teacher-schedule": {
      id: -8,
      title: "Cara isi jadwal kosong",
      description: "Isi hanya jam saat kamu benar-benar bisa mengajar.",
      steps: [
        { title: "Nyalakan hari yang bisa", body: "Geser tombol pada hari yang kamu bisa mengajar. Hari yang mati berarti kamu libur.", target: '[data-tour="teacher-schedule-days"]' },
        { title: "Pilih jam mulai dan selesai", body: "Pilih jamnya. Kalau punya dua waktu kosong, tekan Tambah rentang.", target: '[data-tour="teacher-schedule-days"]' },
        { title: "Simpan", body: "Kalau sudah benar, tekan Simpan jadwal. Baru setelah itu sistem boleh memberi tawaran kelas kepadamu.", target: '[data-tour="teacher-schedule-save"]' },
      ],
    },
    "teacher-cheap-classes": {
      id: -9,
      title: "Cara mengajar Kelas Bersama",
      description: "Link Zoom hanya boleh diisi setelah kelas sudah pasti jadi.",
      steps: [
        { title: "Lihat kelas yang ditugaskan", body: "Di sini ada Kelas Bersama yang menjadi tugasmu. Cek hari, jam, dan jumlah muridnya.", target: '[data-tour="teacher-cheap-classes"]' },
        { title: "Isi link Zoom", body: "Jika status kelas sudah dikonfirmasi, isi link Zoom lalu tekan tombol simpan di sebelahnya.", target: '[data-tour="teacher-cheap-list"]' },
      ],
    },
  },
  admin: {},
};

const contextForPath = (path: string, search = "") => {
  if (path.includes("/kelas-murah")) return path.startsWith("/guru") ? "teacher-cheap-classes" : "cheap-classes";
  if (path.includes("/permintaan")) return "teacher-requests";
  if (path.includes("/guru/kelas") && new URLSearchParams(search).get("class_kind") === "group") return "teacher-cheap-classes";
  if (path.includes("/guru/kelas")) return "teacher-classes";
  if (path.includes("/guru/jadwal")) return "teacher-schedule";
  if (path.includes("/packages/new")) return "package-builder";
  if (path.includes("/packages")) return "packages";
  if (path.includes("/vouchers")) return "vouchers";
  if (path.includes("/my-classes") || path.includes("/kelas")) return "classes";
  if (path.includes("/account") || path.includes("/profile")) return "account";
  return "dashboard";
};

const tutorialFor = (role: Role, context: string) =>
  role === "student" && context === "package-builder"
    ? packageBuilderTutorial
    : contextualTutorials[role][context] ?? fallback[role];

const selectorForStep = (activeStep: Step, role: Role) => {
  const callout = activeStep.callout;
  if (typeof callout === "string" && callout.trim()) return callout;
  if (callout && typeof callout === "object") return callout.selector || callout.target || null;
  if (activeStep.target) return activeStep.target;
  if (role !== "student") return null;
  const text = `${activeStep.title} ${activeStep.body}`.toLowerCase();
  if (text.includes("kelas saya") || text.includes("pantau")) return '[data-tour="student-kelas"]';
  if (text.includes("halaman saya") || text.includes("profil")) return '[data-tour="student-saya"]';
  if (text.includes("paket") || text.includes("mapel") || text.includes("jadwal")) return '[data-tour="student-cari-les"]';
  return null;
};

const viewport = () => ({
  width: window.visualViewport?.width ?? window.innerWidth,
  height: window.visualViewport?.height ?? window.innerHeight,
});

const isRendered = (element: HTMLElement) => {
  const rect = element.getBoundingClientRect();
  const style = window.getComputedStyle(element);
  const visualViewport = window.visualViewport;
  const viewportLeft = visualViewport?.offsetLeft ?? 0;
  const viewportWidth = visualViewport?.width ?? window.innerWidth;

  return element.isConnected
    && rect.width > 0
    && rect.height > 0
    && style.display !== "none"
    && style.visibility !== "hidden"
    && rect.right > viewportLeft
    && rect.left < viewportLeft + viewportWidth;
};

const findTargetElement = (selector: string) => {
  try {
    return Array.from(document.querySelectorAll<HTMLElement>(selector)).find(isRendered) ?? null;
  } catch {
    return null;
  }
};


class TutorialBoundary extends Component<{ children: ReactNode }, { hasError: boolean }> {
  state = { hasError: false };

  static getDerivedStateFromError() {
    return { hasError: true };
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    if (import.meta.env.DEV) {
      console.error("Tutorial gagal dirender.", error, info);
    }
  }

  render() {
    return this.state.hasError ? null : this.props.children;
  }
}

const clamp = (value: number, minimum: number, maximum: number) =>
  Math.min(Math.max(value, minimum), Math.max(minimum, maximum));

const autoOpenContexts = new Set([
  "dashboard",
  "package-builder",
  "cheap-classes",
  "classes",
  "teacher-requests",
  "teacher-classes",
  "teacher-schedule",
  "teacher-cheap-classes",
]);

function RoleQuickGuideContent({ role }: { role: Role }) {
  const location = useLocation();
  const context = useMemo(() => contextForPath(location.pathname, location.search), [location.pathname, location.search]);
  const [tutorial, setTutorial] = useState<Tutorial>(() => tutorialFor(role, context));
  const [open, setOpen] = useState(false);
  const [step, setStep] = useState(0);
  const [spotlight, setSpotlight] = useState<Spotlight | null>(null);
  const [viewportSize, setViewportSize] = useState(() => viewport());
  const touchStart = useRef<number | null>(null);
  const dialogRef = useRef<HTMLElement | null>(null);
  const restoreFocusRef = useRef<HTMLElement | null>(null);
  const tutorialAccountKey = useMemo(() => getTutorialAccountKey(), []);
  const firstVisitKey = context === "package-builder"
    ? getPackageBuilderTutorialKey(tutorialAccountKey)
    : `bimbelku.tutorial.${role}.${context}.${tutorialAccountKey}.easy-v1`;
  const dashboardPath = role === "student" ? "/student/dashboard" : role === "teacher" ? "/guru" : "/admin";
  const activeStep = tutorial.steps[step] ?? fallback[role].steps[0];
  const selector = selectorForStep(activeStep, role);
  const last = step >= Math.max(0, tutorial.steps.length - 1);

  const openGuide = useCallback(() => {
    restoreFocusRef.current = document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null;
    setStep(0);
    setSpotlight(null);
    setOpen(true);
  }, []);

  const closeGuide = useCallback(() => {
    // Tutup portal lebih dahulu agar pengukuran spotlight berhenti sebelum fokus dikembalikan.
    setOpen(false);
    setSpotlight(null);
    touchStart.current = null;
    try {
      localStorage.setItem(firstVisitKey, "seen");
    } catch {
      // Tutorial tetap dapat ditutup saat penyimpanan browser dibatasi.
    }

    const previousFocus = restoreFocusRef.current;
    restoreFocusRef.current = null;
    window.requestAnimationFrame(() => {
      try {
        if (previousFocus?.isConnected) previousFocus.focus({ preventScroll: true });
      } catch {
        // Kegagalan mengembalikan fokus tidak boleh menghentikan halaman utama.
      }
    });
  }, [firstVisitKey]);

  useEffect(() => {
    let mounted = true;
    setTutorial(tutorialFor(role, context));
    setStep(0);

    const fetchTutorial = () => {
      void getCached<Tutorial[]>("/content/tutorials", {
        params: { role, context },
        maxAgeMs: 60_000,
      })
        .then((response) => {
          if (!mounted) return;
          setTutorial(response.data[0]?.steps?.length ? response.data[0] : tutorialFor(role, context));
          setStep(0);
        })
        .catch(() => undefined);
    };

    const cancelScheduledFetch = scheduleNonCriticalTask(fetchTutorial);
    return () => {
      mounted = false;
      cancelScheduledFetch();
    };
  }, [context, role]);

  useEffect(() => {
    if (location.pathname !== dashboardPath && !autoOpenContexts.has(context)) return;
    try {
      if (!localStorage.getItem(firstVisitKey)) openGuide();
    } catch {
      // Jangan memaksa tutorial tampil bila storage browser tidak tersedia.
    }
  }, [context, dashboardPath, firstVisitKey, location.pathname, openGuide]);

  useEffect(() => {
    window.addEventListener("bimbelku:open-tutorial", openGuide);
    return () => window.removeEventListener("bimbelku:open-tutorial", openGuide);
  }, [openGuide]);

  useEffect(() => {
    if (!open) return;
    const frame = window.requestAnimationFrame(() => {
      try {
        dialogRef.current?.focus({ preventScroll: true });
      } catch {
        dialogRef.current?.focus();
      }
    });
    return () => window.cancelAnimationFrame(frame);
  }, [open, step]);

  useEffect(() => {
    if (!open) return;
    let frame = 0;
    const refreshViewport = () => {
      window.cancelAnimationFrame(frame);
      frame = window.requestAnimationFrame(() => {
        setViewportSize(viewport());
      });
    };

    refreshViewport();
    window.addEventListener("resize", refreshViewport);
    window.visualViewport?.addEventListener("resize", refreshViewport);
    return () => {
      window.cancelAnimationFrame(frame);
      window.removeEventListener("resize", refreshViewport);
      window.visualViewport?.removeEventListener("resize", refreshViewport);
    };
  }, [open]);

  useEffect(() => {
    if (!open) return;
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") closeGuide();
      const focusableSelector = 'button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
      const keepFocusInsideDialog = () => {
        if (event.key !== "Tab" || !dialogRef.current) return;
        const focusable = Array.from(dialogRef.current.querySelectorAll<HTMLElement>(focusableSelector));
        if (!focusable.length) return;
        const first = focusable[0];
        const lastFocusable = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          lastFocusable.focus();
        } else if (!event.shiftKey && document.activeElement === lastFocusable) {
          event.preventDefault();
          first.focus();
        }
      };
      keepFocusInsideDialog();
      if (event.key === "ArrowRight") setStep((value) => Math.min(Math.max(0, tutorial.steps.length - 1), value + 1));
      if (event.key === "ArrowLeft") setStep((value) => Math.max(0, value - 1));
    };
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, [closeGuide, open, tutorial.steps.length]);

  useEffect(() => {
    if (!open || !selector) {
      setSpotlight(null);
      return;
    }

    let cancelled = false;
    const timers: number[] = [];

    const measure = () => {
      if (cancelled) return;
      const target = findTargetElement(selector);
      if (!target) {
        setSpotlight(null);
        return;
      }
      const rect = target.getBoundingClientRect();
      const currentViewport = viewport();
      const padding = currentViewport.width < 640 ? 6 : 9;
      const top = clamp(rect.top - padding, 4, currentViewport.height - 4);
      const left = clamp(rect.left - padding, 4, currentViewport.width - 4);
      const right = clamp(rect.right + padding, 4, currentViewport.width - 4);
      const bottom = clamp(rect.bottom + padding, 4, currentViewport.height - 4);

      if (right <= left || bottom <= top) {
        setSpotlight(null);
        return;
      }

      setSpotlight({ top, left, width: right - left, height: bottom - top });
    };

    const locateAndScroll = () => {
      if (cancelled) return;
      const target = findTargetElement(selector);
      if (!target) {
        setSpotlight(null);
        return;
      }
      target.scrollIntoView({ behavior: "smooth", block: "center", inline: "nearest" });
      [0, 90, 220, 420, 700].forEach((delay) => {
        timers.push(window.setTimeout(measure, delay));
      });
    };

    const frame = window.requestAnimationFrame(locateAndScroll);
    const retry = window.setInterval(() => {
      if (findTargetElement(selector)) {
        locateAndScroll();
        window.clearInterval(retry);
      }
    }, 120);
    const stopRetry = window.setTimeout(() => window.clearInterval(retry), 1_500);

    window.addEventListener("resize", measure);
    window.addEventListener("scroll", measure, true);
    window.visualViewport?.addEventListener("resize", measure);
    window.visualViewport?.addEventListener("scroll", measure);

    return () => {
      cancelled = true;
      window.cancelAnimationFrame(frame);
      window.clearInterval(retry);
      window.clearTimeout(stopRetry);
      timers.forEach((timer) => window.clearTimeout(timer));
      window.removeEventListener("resize", measure);
      window.removeEventListener("scroll", measure, true);
      window.visualViewport?.removeEventListener("resize", measure);
      window.visualViewport?.removeEventListener("scroll", measure);
    };
  }, [open, selector, step]);

  const dialogStyle = useMemo<CSSProperties>(() => {
    if (typeof window === "undefined") return {};
    const currentViewport = viewportSize;
    const margin = 12;
    const gap = 14;
    const width = Math.min(360, currentViewport.width - margin * 2);
    const comfortablePanelHeight = 410;
    const minimumUsableHeight = 220;
    const viewportMaxHeight = currentViewport.height - margin * 2;

    if (!spotlight) {
      return currentViewport.width < 768
        ? { left: margin, right: margin, bottom: margin, maxHeight: viewportMaxHeight }
        : { left: "50%", top: "50%", width, maxHeight: viewportMaxHeight, transform: "translate(-50%, -50%)" };
    }

    if (currentViewport.width < 768) {
      // Di ponsel kartu selalu sedekat mungkin di bawah/atas tombol, bukan menunggu di ujung layar.
      const roomBelow = currentViewport.height - spotlight.top - spotlight.height - gap - margin;
      const roomAbove = spotlight.top - gap - margin;
      if (roomBelow >= minimumUsableHeight && roomBelow >= roomAbove) {
        return { left: margin, right: margin, top: spotlight.top + spotlight.height + gap, maxHeight: roomBelow };
      }
      if (roomAbove >= minimumUsableHeight) {
        return { left: margin, right: margin, bottom: currentViewport.height - spotlight.top + gap, maxHeight: roomAbove };
      }
      // Bila target terlalu besar, kartu tetap dipasang penuh di layar agar tombol Lanjut tidak hilang.
      return { left: margin, right: margin, top: margin, maxHeight: viewportMaxHeight };
    }

    const roomRight = currentViewport.width - spotlight.left - spotlight.width - gap - margin;
    const roomLeft = spotlight.left - gap - margin;
    const targetCenter = spotlight.top + spotlight.height / 2;
    const alignedTop = clamp(targetCenter - comfortablePanelHeight / 2, margin, currentViewport.height - comfortablePanelHeight - margin);

    // Desktop: utamakan menempel di kanan/kiri elemen yang ditunjuk.
    if (roomRight >= width) return { left: spotlight.left + spotlight.width + gap, top: alignedTop, width, maxHeight: viewportMaxHeight };
    if (roomLeft >= width) return { left: spotlight.left - width - gap, top: alignedTop, width, maxHeight: viewportMaxHeight };

    // Layar sempit: pindah sedikit di bawah/atas elemen, tetap dekat dengan sorotan.
    const roomBelow = currentViewport.height - spotlight.top - spotlight.height - gap - margin;
    const roomAbove = spotlight.top - gap - margin;
    const closeLeft = clamp(spotlight.left, margin, currentViewport.width - width - margin);
    if (roomBelow >= minimumUsableHeight && roomBelow >= roomAbove) return { left: closeLeft, top: spotlight.top + spotlight.height + gap, width, maxHeight: roomBelow };
    if (roomAbove >= minimumUsableHeight) return { left: closeLeft, bottom: currentViewport.height - spotlight.top + gap, width, maxHeight: roomAbove };
    return { left: closeLeft, top: margin, width, maxHeight: viewportMaxHeight };
  }, [spotlight, viewportSize]);

  const tutorialOverlay = open && typeof document !== "undefined" ? (
    <div
      className="fixed inset-0 z-[var(--layer-modal)] touch-none overflow-hidden overscroll-none"
      data-tutorial-portal
      role="presentation"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) closeGuide();
      }}
    >
      {!spotlight && <div className="pointer-events-none fixed inset-0 bg-slate-950/80" />}

      {spotlight && (
        <div
          aria-hidden="true"
          data-spotlight-ring
          className="pointer-events-none fixed rounded-2xl ring-4 ring-amber-300 shadow-[0_0_0_9999px_rgba(2,6,23,0.82),0_0_0_6px_rgba(255,255,255,0.98),0_0_38px_rgba(251,191,36,0.95)] transition-[top,left,width,height] duration-200 ease-out"
          style={spotlight}
        />
      )}

      <section
        ref={dialogRef}
        tabIndex={-1}
        role="dialog"
        aria-modal="true"
        aria-labelledby="tutorial-title"
        data-tutorial-context={context}
        className="fixed z-[var(--layer-modal-content)] flex max-h-[calc(100dvh-1.5rem)] max-w-[calc(100vw-1.5rem)] flex-col overflow-hidden rounded-[1.5rem] bg-white shadow-2xl outline-none sm:rounded-[1.75rem]"
        style={dialogStyle}
        onMouseDown={(event) => event.stopPropagation()}
        onWheel={(event) => event.stopPropagation()}
        onTouchMove={(event) => event.stopPropagation()}
        onTouchStart={(event) => {
          touchStart.current = event.touches[0]?.clientX ?? null;
        }}
        onTouchEnd={(event) => {
          const end = event.changedTouches[0]?.clientX;
          if (touchStart.current !== null && end !== undefined && Math.abs(end - touchStart.current) > 45) {
            setStep((value) => end > touchStart.current!
              ? Math.max(0, value - 1)
              : Math.min(Math.max(0, tutorial.steps.length - 1), value + 1));
          }
          touchStart.current = null;
        }}
      >
        <header className="relative min-h-24 shrink-0 overflow-hidden bg-gradient-to-br from-indigo-700 via-blue-700 to-cyan-600 sm:min-h-36">
          {publicMediaUrl(activeStep.image_path) || activeStep.image_url ? (
            <img src={publicMediaUrl(activeStep.image_path) || activeStep.image_url || ""} alt="" loading="lazy" decoding="async" className="absolute inset-0 h-full w-full object-contain" />
          ) : (
            <div className="absolute -right-8 -top-12 h-48 w-48 rounded-full border-[24px] border-white/10" />
          )}
          <div className="absolute inset-0 bg-gradient-to-t from-slate-950/75 to-transparent" />
          <button type="button" aria-label="Tutup tutorial" onClick={closeGuide} className="absolute right-4 top-4 grid h-10 w-10 place-items-center rounded-full bg-slate-950/35 text-white">
            <X size={19} />
          </button>
          <div className="absolute bottom-4 left-5 right-16 text-white">
            <p className="text-[10px] font-black uppercase tracking-[0.2em] text-white/90">Bagian {step + 1} dari {tutorial.steps.length}</p>
            <h2 id="tutorial-title" className="mt-1 text-xl font-black sm:text-2xl">{activeStep.title}</h2>
          </div>
        </header>

        <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain p-5 sm:p-6">
          <div className="flex gap-1.5">
            {tutorial.steps.map((item, index) => (
              <button key={item.id ?? `${item.title}-${index}`} type="button" aria-label={`Buka bagian ${index + 1}`} onClick={() => setStep(index)} className={`h-2 rounded-full transition-all ${index === step ? "w-8 bg-indigo-600" : "w-2 bg-slate-200"}`} />
            ))}
          </div>
          <h3 className="mt-4 text-lg font-black text-slate-900">{tutorial.title}</h3>
          {step === 0 && tutorial.description && <p className="mt-1 text-sm text-slate-500">{tutorial.description}</p>}
          {selector && <p className="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-xs font-bold leading-5 text-amber-900">Lihat bagian yang diberi garis kuning. Ini yang sedang kita bahas.</p>}
          <p className="mt-3 text-sm font-semibold leading-6 text-slate-700">{activeStep.body}</p>
        </div>

        <footer className="flex shrink-0 items-center justify-between gap-3 border-t border-slate-100 bg-white p-4 sm:px-6">
          <button type="button" onClick={closeGuide} className="min-h-11 px-2 text-sm font-bold text-slate-500 hover:text-slate-700">Nanti saja</button>
          <div className="flex gap-2">
            {step > 0 && (
              <button type="button" onClick={() => setStep((value) => value - 1)} className="grid h-12 w-12 place-items-center rounded-2xl border border-slate-200 text-slate-600" aria-label="Bagian sebelumnya">
                <ChevronLeft size={20} />
              </button>
            )}
            <button type="button" onClick={() => last ? closeGuide() : setStep((value) => value + 1)} className="flex h-12 items-center justify-center gap-2 rounded-2xl bg-indigo-600 px-5 text-sm font-black text-white hover:bg-indigo-700">
              {last ? <><CheckCircle2 size={18} /> Selesai</> : <>Lanjut <ChevronRight size={18} /></>}
            </button>
          </div>
        </footer>
      </section>
    </div>
  ) : null;

  return (
    <>
      <button
        type="button"
        onClick={openGuide}
        aria-label="Buka tutorial"
        className="rounded-full p-2.5 text-slate-500 transition hover:bg-white hover:text-indigo-600 hover-shadow-md"
      >
        <HelpCircle size={20} />
      </button>

      {tutorialOverlay && document.body ? createPortal(tutorialOverlay, document.body) : null}
    </>
  );
}

export default function RoleQuickGuide({ role }: { role: Role }) {
  return (
    <TutorialBoundary>
      <RoleQuickGuideContent role={role} />
    </TutorialBoundary>
  );
}
