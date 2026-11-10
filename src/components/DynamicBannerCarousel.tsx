import { useEffect, useRef, useState } from "react";
import { ChevronLeft, ChevronRight, Image as ImageIcon, MoveHorizontal } from "lucide-react";
import { Link } from "react-router-dom";
import StudentPackageLink from "@/components/StudentPackageLink";

import caraMemesanTutorBanner from "@/assets/banners/cara-memesan-tutor.webp";
import { getCached } from "@/lib/http";
import { imageFetchPriority } from "@/lib/imagePriority";

export type DynamicBanner = {
  id: number;
  title: string;
  description?: string | null;
  button_text?: string | null;
  image_url?: string | null;
  image_path?: string | null;
  destination_kind: "internal" | "external";
  destination_url: string;
};

const fallback: DynamicBanner[] = [
  {
    id: -1,
    title: "Cara memesan tutor",
    description: "Pilih kebutuhanmu, atur jadwal, lalu bayar. Kami bantu carikan tutor yang cocok.",
    button_text: "Pesan Tutor",
    destination_kind: "internal",
    destination_url: "/student/packages/new",
  },
  {
    id: -2,
    title: "Atur jadwal sesuai kebutuhan",
    description: "Pilih mata pelajaran, hari, dan jam belajar sebelum memesan.",
    button_text: "Lihat program",
    destination_kind: "internal",
    destination_url: "/program",
  },
];

const withFallback = (banners: DynamicBanner[], audience: string) =>
  audience === "student" && banners.length < 2
    ? [...banners, ...fallback.filter((item) => !banners.some((banner) => banner.id === item.id)).slice(0, 2 - banners.length)]
    : banners;

const isStudentPackageDestination = (destination: string) => {
  const path = destination.split(/[?#]/, 1)[0];
  return path === "/student/packages/new" || path === "/search" || path === "/student/find";
};

const isDashboardTutorialDestination = (destination: string) =>
  destination === "/student/dashboard#tutorial";

const bannerCacheKey = (audience: string) => `bimbelku:dashboard-banners:${audience}`;

const readCachedBanners = (audience: string): DynamicBanner[] | null => {
  try {
    const cached = JSON.parse(sessionStorage.getItem(bannerCacheKey(audience)) || "null");
    return Array.isArray(cached) ? cached : null;
  } catch {
    return null;
  }
};

const writeCachedBanners = (audience: string, banners: DynamicBanner[]) => {
  try {
    sessionStorage.setItem(bannerCacheKey(audience), JSON.stringify(banners));
  } catch {
    // Storage bisa diblokir browser; carousel tetap memakai cache memori HTTP.
  }
};

export default function DynamicBannerCarousel({ audience = "student" }: { audience?: string }) {
  // Banner bawaan hanya ditujukan bagi murid. Tutor dan admin hanya melihat
  // banner yang memang dibuat admin untuk perannya, sehingga pesan tidak salah sasaran.
  const [items, setItems] = useState<DynamicBanner[]>(() => (
    withFallback(readCachedBanners(audience) ?? [], audience)
  ));
  const [active, setActive] = useState(0);
  const [paused, setPaused] = useState(false);
  const [visible, setVisible] = useState(true);
  const sectionRef = useRef<HTMLElement>(null);
  const trackRef = useRef<HTMLDivElement>(null);
  const autoplayStarted = useRef(false);

  useEffect(() => {
    let mounted = true;
    autoplayStarted.current = false;
    setItems(withFallback(readCachedBanners(audience) ?? [], audience));
    setActive(0);
    trackRef.current?.scrollTo({ left: 0 });
    void getCached<DynamicBanner[]>("/content/banners", {
      params: { audience },
      // Cache singkat mencegah request berulang saat berpindah halaman. Mutasi
      // admin tetap mengosongkan cache HTTP melalui interceptor global.
      maxAgeMs: 60_000,
    })
      .then((response) => {
        const nextItems = Array.isArray(response.data) ? response.data : [];
        if (mounted) {
          const resolved = withFallback(nextItems, audience);
          writeCachedBanners(audience, resolved);
          setItems(resolved);
          setActive(0);
          trackRef.current?.scrollTo({ left: 0 });
        }
      })
      .catch(() => undefined);

    return () => {
      mounted = false;
    };
  }, [audience]);

  useEffect(() => {
    const observer = new IntersectionObserver(([entry]) => setVisible(entry.isIntersecting && !document.hidden));
    if (sectionRef.current) observer.observe(sectionRef.current);
    const onVisibilityChange = () => {
      const bounds = sectionRef.current?.getBoundingClientRect();
      setVisible(!document.hidden && Boolean(bounds && bounds.bottom > 0 && bounds.top < window.innerHeight));
    };
    document.addEventListener("visibilitychange", onVisibilityChange);
    return () => {
      observer.disconnect();
      document.removeEventListener("visibilitychange", onVisibilityChange);
    };
  }, []);

  useEffect(() => {
    if (paused || !visible || document.hidden || window.matchMedia("(prefers-reduced-motion: reduce)").matches || items.length < 2) return;

    // Jangan mengganti kandidat gambar terbesar saat Lighthouse/browser masih
    // mengukur fase load awal. Tombol desktop dan swipe mobile tetap langsung.
    const timer = window.setTimeout(() => {
      autoplayStarted.current = true;
      goTo(active + 1);
    }, autoplayStarted.current ? 5_000 : 12_000);

    return () => window.clearTimeout(timer);
  }, [active, items.length, paused, visible]);

  const move = (direction: number) => {
    autoplayStarted.current = true;
    goTo(active + direction);
  };
  const openBanner = (index: number) => {
    autoplayStarted.current = true;
    goTo(index);
  };
  const goTo = (index: number) => {
    if (!items.length) return;
    const next = (index + items.length) % items.length;
    const track = trackRef.current;
    const slide = track?.children[next] as HTMLElement | undefined;
    if (track && slide) track.scrollTo({ left: slide.offsetLeft - track.offsetLeft, behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth" });
    setActive(next);
  };
  const renderBanner = (banner: DynamicBanner, index: number) => {
  // Banner admin tetap bisa memakai gambar sendiri. Jika belum ada gambar,
  // gunakan ilustrasi ringan ini agar area dashboard tetap informatif.
  // Backend hanya mengirim image_url jika file benar-benar tersedia. Jangan
  // membentuk URL lagi dari image_path karena path yatim akan memicu 404 pada LCP.
  const bannerImage = banner.image_url
    || (banner.id === -1 ? caraMemesanTutorBanner : null);

  const content = (
    <div
      className="group relative min-h-[180px] w-full min-w-0 select-none overflow-hidden rounded-[1.5rem] bg-gradient-to-br from-blue-700 via-indigo-700 to-violet-800 text-white shadow-xl sm:min-h-[260px] sm:rounded-[2rem]"
      onDragStart={(event) => event.preventDefault()}
    >
      {bannerImage ? (
        <img
          src={bannerImage}
          alt=""
          loading={index === 0 ? "eager" : "lazy"}
          {...imageFetchPriority(index === 0 ? "high" : "auto")}
          decoding="async"
          draggable={false}
          className="absolute inset-0 h-full w-full object-cover transition duration-700 group-hover:scale-[1.025]"
        />
      ) : (
        <>
          <div className="absolute -right-12 -top-16 h-56 w-56 rounded-full border-[30px] border-white/10 sm:h-72 sm:w-72" />
          <div className="absolute right-6 top-12 grid h-20 w-20 place-items-center rounded-3xl bg-white/10 backdrop-blur sm:right-12 sm:top-16 sm:h-24 sm:w-24">
            <ImageIcon size={34} className="text-white/90 sm:size-[38px]" />
          </div>
        </>
      )}

      <div className="absolute inset-0 bg-gradient-to-t from-slate-950/95 via-slate-950/48 to-slate-950/5" />
      <div className="absolute inset-0 bg-gradient-to-r from-slate-950/50 via-transparent to-transparent" />

      <div className="relative flex min-h-[180px] w-full min-w-0 flex-col justify-end px-4 pb-10 pt-12 sm:min-h-[260px] sm:max-w-3xl sm:px-8 sm:pb-12 sm:pt-16">
        <div className="min-w-0 max-w-[92%] sm:max-w-2xl">
          <h2 className="break-words text-lg font-black leading-tight tracking-tight drop-shadow-sm sm:text-3xl">
            {banner.title}
          </h2>
          {banner.description && (
            <p className="mt-1.5 line-clamp-2 break-words text-[11px] font-medium leading-4 text-white/95 sm:mt-2 sm:text-base sm:leading-6">
              {banner.description}
            </p>
          )}
          {banner.button_text && (
            <span className="relative z-10 mt-3 inline-flex min-h-11 w-fit max-w-full items-center justify-center rounded-xl bg-white px-4 py-2 text-sm font-black text-slate-950 shadow-lg shadow-slate-950/10 transition group-hover:bg-indigo-50 group-hover:text-indigo-800 sm:mt-4">
              <span className="truncate">{banner.button_text}</span>
            </span>
          )}
        </div>
      </div>
    </div>
  );

  return (
    <div key={banner.id} className="min-w-0 basis-[88%] shrink-0 snap-start sm:basis-full">
      {banner.destination_kind === "external" ? (
        <a
          href={banner.destination_url}
          target="_blank"
          rel="noreferrer"
          className="block w-full min-w-0 cursor-pointer rounded-[1.5rem] outline-none focus-visible:ring-4 focus-visible:ring-indigo-300 sm:rounded-[2rem]"
        >
          {content}
        </a>
      ) : isDashboardTutorialDestination(banner.destination_url) ? (
        <button
          type="button"
          aria-label={banner.button_text || "Buka tutorial dashboard"}
          className="block w-full min-w-0 cursor-pointer rounded-[1.5rem] text-left outline-none focus-visible:ring-4 focus-visible:ring-indigo-300 sm:rounded-[2rem]"
          onClick={() => {
            window.dispatchEvent(new CustomEvent("bimbelku:open-tutorial", {
              detail: { context: "dashboard", source: "banner" },
            }));
          }}
        >
          {content}
        </button>
      ) : isStudentPackageDestination(banner.destination_url) ? (
        <StudentPackageLink
          to={banner.destination_url}
          className="block w-full min-w-0 cursor-pointer rounded-[1.5rem] outline-none focus-visible:ring-4 focus-visible:ring-indigo-300 sm:rounded-[2rem]"
        >
          {content}
        </StudentPackageLink>
      ) : (
        <Link
          to={banner.destination_url}
          className="block w-full min-w-0 cursor-pointer rounded-[1.5rem] outline-none focus-visible:ring-4 focus-visible:ring-indigo-300 sm:rounded-[2rem]"
        >
          {content}
        </Link>
      )}
    </div>
  );
  };

  if (!items.length) return null;
  return <section ref={sectionRef} className="relative w-full min-w-0" aria-label="Informasi BimbelKu" onPointerDown={() => setPaused(true)} onPointerLeave={() => setPaused(false)} onFocusCapture={() => setPaused(true)} onBlurCapture={(event) => { if (!event.currentTarget.contains(event.relatedTarget as Node | null)) setPaused(false); }}>
    <div className="mb-3 flex items-center justify-between gap-3 sm:hidden">
      <span className="inline-flex items-center gap-2 text-xs font-black text-slate-500"><MoveHorizontal size={16} />Geser untuk melihat banner lain</span>
      <span className="rounded-full bg-slate-900 px-2.5 py-1 text-[10px] font-black text-white">{active + 1}/{items.length}</span>
    </div>
    <div className="relative">
      <div ref={trackRef} tabIndex={0} className="flex snap-x gap-3 overflow-x-auto scroll-smooth pb-3 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200" onKeyDown={(event) => { if (event.key === "ArrowLeft") move(-1); if (event.key === "ArrowRight") move(1); }} onScroll={(event) => {
        const track = event.currentTarget;
        const first = track.firstElementChild as HTMLElement | null;
        if (first) setActive(Math.min(items.length - 1, Math.max(0, Math.round(track.scrollLeft / (first.offsetWidth + 12)))));
      }}>{items.map(renderBanner)}</div>
    </div>

      {items.length > 1 && (
        <div
          role="group"
          className="mt-3 flex items-center justify-center gap-3"
          aria-label={`${active + 1} dari ${items.length} banner`}
        >
          <button
            type="button"
            aria-label="Banner sebelumnya"
            onClick={() => move(-1)}
            className="grid h-10 w-10 place-items-center rounded-full border border-slate-200 bg-white text-slate-700 shadow-sm transition hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700"
          >
            <ChevronLeft size={18} />
          </button>

          <div className="flex items-center gap-1.5">
            {items.map((item, index) => (
              <button
                key={item.id}
                type="button"
                aria-label={`Buka banner ${index + 1}`}
                aria-current={index === active ? "true" : undefined}
                onClick={() => openBanner(index)}
                className={`h-2 rounded-full transition-all ${index === active ? "w-6 bg-indigo-600" : "w-2 bg-slate-300 hover:bg-slate-400"}`}
              />
            ))}
          </div>

          <button
            type="button"
            aria-label="Banner berikutnya"
            onClick={() => move(1)}
            className="grid h-10 w-10 place-items-center rounded-full border border-slate-200 bg-white text-slate-700 shadow-sm transition hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700"
          >
            <ChevronRight size={18} />
          </button>
        </div>
      )}
    </section>;
}
