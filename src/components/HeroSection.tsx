import { useEffect, useRef, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import { ArrowRight, BookOpen, CalendarCheck2, CheckCircle2, MessageCircle, Search, ShieldCheck } from "lucide-react";

import Reveal from "@/components/Reveal";
import LandingAmbientOrbit from "@/components/LandingAmbientOrbit";
import StudentPackageLink from "@/components/StudentPackageLink";
import { useWebsiteContent } from "@/components/WebsiteContentProvider";
import { imageFetchPriority } from "@/lib/imagePriority";
import { whatsappHref } from "@/lib/websiteContent";

const quickLinks = [
  ["Program", "#program"],
  ["Cara kerja", "#cara-kerja"],
  ["Tutor", "#tutor"],
  ["Area", "#area"],
  ["FAQ", "#faq"],
] as const;

export default function HeroSection() {
  const { settings, section } = useWebsiteContent();
  const hero = section("hero");
  const content = hero?.content;
  const visualRef = useRef<HTMLDivElement>(null);
  const [visualActive, setVisualActive] = useState(false);
  const [isMobileViewport, setIsMobileViewport] = useState(() => window.matchMedia("(max-width: 639px)").matches);
  const [query, setQuery] = useState("");
  const consultationUrl = settings.whatsapp_enabled
    ? whatsappHref(settings.whatsapp_number, settings.whatsapp_default_message)
    : null;

  useEffect(() => {
    const element = visualRef.current;
    if (!element) return;
    if (!("IntersectionObserver" in window)) {
      setVisualActive(true);
      return;
    }
    const observer = new IntersectionObserver(([entry]) => setVisualActive(entry.isIntersecting), { threshold: 0.12 });
    observer.observe(element);
    return () => observer.disconnect();
  }, [isMobileViewport]);

  useEffect(() => {
    const media = window.matchMedia("(max-width: 639px)");
    const update = () => setIsMobileViewport(media.matches);
    media.addEventListener("change", update);
    return () => media.removeEventListener("change", update);
  }, []);

  const scrollToSection = (id: string) => {
    const target = document.getElementById(id);
    if (!target) return;
    const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    target.scrollIntoView({ behavior: settings.animations_enabled && !reducedMotion ? "smooth" : "auto", block: "start" });
  };
  const submitSearch = (event: FormEvent) => {
    event.preventDefault();
    window.dispatchEvent(new CustomEvent("bimbelku:program-search", { detail: query.trim() }));
    window.history.replaceState(null, "", "#program");
    scrollToSection("program");
  };

  const primaryUrl = settings.primary_cta_url || "/student/packages/new";
  const primaryButton = (
    <span className="inline-flex min-h-12 w-full items-center justify-center gap-1.5 rounded-xl bg-[#C2410C] px-3 py-3 text-xs font-extrabold text-white shadow-[0_12px_28px_rgba(154,52,18,.2)] transition hover:-translate-y-0.5 hover:bg-[#9A3412] sm:min-h-13 sm:w-auto sm:gap-2 sm:rounded-2xl sm:px-6 sm:py-3.5 sm:text-sm">
      {settings.primary_cta_label || "Cari Bimbingan"}<ArrowRight size={18} aria-hidden="true" />
    </span>
  );

  return (
    <section id="hero" className="relative overflow-visible bg-[#F7F1E8] pb-0 pt-0 sm:pb-24 sm:pt-5 lg:pb-24 lg:pt-5">
      <div className="hidden sm:block">
        <LandingAmbientOrbit variant="trail" color="#0E7475" style={{ width: 220, height: 220, top: 16, right: 20, opacity: 0.56 }} />
        <LandingAmbientOrbit variant="focus" color="#C2410C" style={{ width: 164, height: 164, bottom: 74, left: "42%", opacity: 0.3 }} />
      </div>
      <div className="pointer-events-none absolute inset-0 opacity-70" aria-hidden="true">
        <div className="absolute -left-28 top-16 h-56 w-56 rounded-full border border-orange-300/40" />
        <div className="absolute -left-20 top-24 h-40 w-40 rounded-full border border-orange-300/25" />
        <div className="absolute right-[41%] top-16 h-2 w-2 rounded-full bg-teal-600" />
      </div>

      <div className="relative sm:hidden">
        <div className="relative overflow-hidden bg-[radial-gradient(circle_at_92%_12%,rgba(249,115,22,.22),transparent_13rem),linear-gradient(145deg,#14213D_0%,#0F4F5C_100%)] px-5 pb-0 pt-7 text-center text-white">
          <div className="pointer-events-none absolute inset-0" aria-hidden="true">
            <div className="absolute -right-12 top-8 h-36 w-36 rounded-[2.5rem] border border-white/10 rotate-12" />
            <div className="absolute left-7 top-28 h-px w-14 bg-gradient-to-r from-orange-300/50 to-transparent" />
          </div>

          <div className="relative z-10 mx-auto max-w-[25rem]">
            <p className="mx-auto inline-flex items-center gap-1.5 rounded-full border border-orange-200/25 bg-white/10 px-3 py-1.5 text-[10px] font-extrabold text-orange-100 backdrop-blur">
              <ShieldCheck size={14} aria-hidden="true" />
              {hero?.eyebrow || "Bimbel privat SD–SMA di Yogyakarta"}
            </p>
            <h1 className="mx-auto mt-5 max-w-[15ch] text-balance text-[2.25rem] font-extrabold leading-[1.08] tracking-[-0.04em]">
              {hero?.title || "Bimbel privat yang dimulai dari kebutuhan belajar anak."}
            </h1>
            <p className="mx-auto mt-4 max-w-[34rem] text-[13px] font-medium leading-6 text-slate-200">
              {hero?.description || "Pilih kebutuhan dan jadwalmu. Harga terlihat sejak awal, lalu BimbelKu mencarikan tutor setelah pembayaran terverifikasi."}
            </p>

            <div className="mt-6 grid grid-cols-2 gap-2.5">
              {primaryUrl.startsWith("/student/packages/new") ? (
                <StudentPackageLink to={primaryUrl} className="inline-flex min-h-12 items-center justify-center gap-1.5 rounded-xl bg-[#C2410C] px-3 text-xs font-extrabold text-white shadow-[0_12px_30px_rgba(154,52,18,.24)]">
                  {settings.primary_cta_label || "Cari Bimbingan"}<ArrowRight size={16} aria-hidden="true" />
                </StudentPackageLink>
              ) : (
                <Link to={primaryUrl} className="inline-flex min-h-12 items-center justify-center gap-1.5 rounded-xl bg-[#C2410C] px-3 text-xs font-extrabold text-white shadow-[0_12px_30px_rgba(154,52,18,.24)]">
                  {settings.primary_cta_label || "Cari Bimbingan"}<ArrowRight size={16} aria-hidden="true" />
                </Link>
              )}
              {consultationUrl ? <a href={consultationUrl} target="_blank" rel="noreferrer" className="inline-flex min-h-12 items-center justify-center gap-1.5 rounded-xl border border-white/30 bg-white/10 px-3 text-xs font-extrabold text-white backdrop-blur"><MessageCircle size={16} aria-hidden="true" />{settings.whatsapp_label}</a> : <a href="#cara-kerja" className="inline-flex min-h-12 items-center justify-center rounded-xl border border-white/30 bg-white/10 px-3 text-xs font-extrabold text-white">Lihat cara kerja</a>}
            </div>

            {isMobileViewport && <MobileHeroVisual imageUrl={settings.hero_mobile_image_url || "/hero-bimbelku-character-mobile-optimized.webp"} />}
          </div>
        </div>

        <div className="relative z-20 -mt-1 rounded-t-[28px] bg-white px-4 pb-4 pt-5 shadow-[0_-12px_35px_rgba(20,33,61,.08)]">
          <div className="mx-auto max-w-[25rem]">
            <div className="mb-3 flex items-end justify-between gap-4 px-1"><div><p className="text-[9px] font-black uppercase tracking-[.16em] text-teal-700">Cari program</p><h2 className="mt-1 text-base font-extrabold tracking-tight text-[#14213D]">Mau belajar apa?</h2></div><p className="max-w-[9.5rem] text-right text-[9px] font-semibold leading-4 text-slate-500">Lihat jenjang dan Bab sebelum memesan.</p></div>
            <form onSubmit={submitSearch} className="flex items-center gap-2 rounded-2xl border border-stone-200 bg-[#F7F1E8] p-2 shadow-sm">
              <label className="flex min-h-11 min-w-0 flex-1 items-center gap-2 px-2">
                <Search className="shrink-0 text-teal-700" size={17} aria-hidden="true" />
                <span className="sr-only">Cari program belajar</span>
                <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Cari Matematika, Inggris..." className="min-w-0 flex-1 bg-transparent text-xs font-semibold text-[#14213D] outline-none placeholder:text-slate-400" />
              </label>
              <button type="submit" className="min-h-11 shrink-0 rounded-xl bg-[#147D7E] px-4 text-xs font-extrabold text-white">Cari</button>
            </form>
          </div>
        </div>
      </div>

      <div className="relative mx-auto hidden min-w-0 max-w-[1240px] items-center gap-8 px-8 sm:grid lg:grid-cols-[1.04fr_.96fr] lg:gap-10">
        <div className="relative z-10 min-w-0 w-full max-w-2xl py-1 sm:w-auto sm:py-2 lg:py-4">
          <Reveal eager width="100%">
            <p className="inline-flex items-center gap-1.5 rounded-full border border-teal-200 bg-white/[.88] px-3 py-1.5 text-[10px] font-extrabold text-teal-800 shadow-sm backdrop-blur sm:gap-2 sm:px-4 sm:py-2 sm:text-sm">
              <ShieldCheck size={15} aria-hidden="true" className="sm:h-[17px] sm:w-[17px]" />
              {hero?.eyebrow || "Bimbel privat SD–SMA di Yogyakarta"}
            </p>
          </Reveal>
          <Reveal eager width="100%">
            <h1 className="mt-4 max-w-[15ch] text-balance text-[2.15rem] font-extrabold leading-[1.08] tracking-[-0.035em] text-[#14213D] sm:mt-5 sm:max-w-[14ch] sm:text-[3.35rem] sm:leading-[1.05] sm:tracking-[-0.04em] lg:text-[4.15rem]">
              {hero?.title || "Bimbel privat yang dimulai dari kebutuhan belajar anak."}
            </h1>
          </Reveal>
          <Reveal eager width="100%">
            <p className="mt-3 w-full whitespace-normal break-words text-sm font-medium leading-6 text-slate-600 sm:mt-6 sm:max-w-xl sm:text-lg sm:leading-8">
              {hero?.description || "Pilih kebutuhan dan jadwalmu. Harga terlihat sejak awal, lalu BimbelKu mencarikan tutor setelah pembayaran terverifikasi."}
            </p>
          </Reveal>
          <Reveal eager width="100%">
            <div className="mt-5 grid grid-cols-2 gap-2 sm:mt-8 sm:flex sm:gap-3">
              {primaryUrl.startsWith("/student/packages/new") ? (
                <StudentPackageLink to={primaryUrl}>{primaryButton}</StudentPackageLink>
              ) : (
                <Link to={primaryUrl}>{primaryButton}</Link>
              )}
              {consultationUrl ? (
                <a href={consultationUrl} target="_blank" rel="noreferrer" className="inline-flex min-h-12 w-full items-center justify-center gap-1.5 rounded-xl border border-teal-700/30 bg-white/85 px-3 py-3 text-xs font-extrabold text-[#14213D] transition hover:border-orange-300 hover:bg-white sm:min-h-13 sm:w-auto sm:gap-2 sm:rounded-2xl sm:px-6 sm:py-3.5 sm:text-sm">
                  <MessageCircle size={17} aria-hidden="true" />{settings.whatsapp_label}
                </a>
              ) : (
                <a href="#cara-kerja" className="inline-flex min-h-12 w-full items-center justify-center gap-1.5 rounded-xl border border-teal-700/30 bg-white/85 px-3 py-3 text-xs font-extrabold text-[#14213D] transition hover:border-orange-300 hover:bg-white sm:min-h-13 sm:w-auto sm:gap-2 sm:rounded-2xl sm:px-6 sm:py-3.5 sm:text-sm">
                  Lihat cara kerja
                </a>
              )}
            </div>
          </Reveal>
          <Reveal eager width="100%">
            <ul className="mt-7 hidden w-full min-w-0 gap-x-5 gap-y-2 text-xs font-extrabold text-slate-600 sm:flex sm:max-w-none sm:flex-wrap sm:text-sm">
              {(content?.trust_points?.length ? content.trust_points : ["Harga transparan", "Tutor diverifikasi", "Progress tercatat"]).slice(0, 3).map((point) => (
                <li key={point} className="flex items-center gap-2"><CheckCircle2 className="shrink-0 text-emerald-700" size={16} aria-hidden="true" />{point}</li>
              ))}
            </ul>
          </Reveal>
        </div>

        {!isMobileViewport && <Reveal eager direction="left" width="100%" className="hidden sm:block">
          <div ref={visualRef} data-active={visualActive ? "true" : "false"} className="hero-orbit-scene relative mx-auto h-[540px] min-w-0 w-full max-w-[530px]">
            <div className="hero-orbit hero-orbit-one absolute left-1/2 top-1/2 h-[440px] w-[440px] -translate-x-1/2 -translate-y-1/2 rounded-full border border-dashed border-[#C2410C]/45" aria-hidden="true" />
            <div className="hero-orbit hero-orbit-two absolute left-1/2 top-1/2 h-[345px] w-[500px] -translate-x-1/2 -translate-y-1/2 rounded-[50%] border border-teal-700/25" aria-hidden="true" />
            <div className="hero-orbit hero-orbit-three absolute left-1/2 top-1/2 h-[470px] w-[320px] -translate-x-1/2 -translate-y-1/2 rotate-[24deg] rounded-[50%] border border-[#14213D]/16" aria-hidden="true" />
            <div className="absolute left-1/2 top-[53%] h-[390px] w-[390px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-[#F4C66B] shadow-[0_30px_70px_rgba(180,83,9,.12)]" aria-hidden="true" />
            <div className="absolute left-1/2 top-[53%] h-[330px] w-[330px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-[#F9DEA0]/70" aria-hidden="true" />

            <img
              src={settings.hero_desktop_image_url || "/hero-bimbelku-character-v2.webp"}
              srcSet={settings.hero_desktop_image_url ? undefined : "/hero-bimbelku-character-mobile.webp 720w, /hero-bimbelku-character-v2.webp 900w"}
              sizes="(max-width: 639px) 368px, 570px"
              alt="Siswa mengenakan batik dan membawa tas sekolah"
              loading={window.matchMedia("(min-width: 640px)").matches ? "eager" : "lazy"}
              decoding="async"
              {...imageFetchPriority(window.matchMedia("(min-width: 640px)").matches ? "high" : "low")}
              width={900}
              height={1075}
              className="absolute bottom-0 left-1/2 z-10 h-[520px] w-auto max-w-none -translate-x-1/2 object-contain object-bottom"
            />

            <div className="absolute left-1/2 h-20 w-3/4 -translate-x-1/2 overflow-hidden border-t-4 border-white/90 bg-gradient-to-b from-[#FFF8E8] to-[#F4C66B]" style={{ bottom: "-0.35rem", zIndex: 15, borderRadius: "55% 55% 24px 24px", boxShadow: "0 -10px 35px rgb(20 33 61 / .1), 0 18px 35px rgb(180 83 9 / .12)" }} aria-hidden="true">
              <div className="absolute top-3 h-px bg-orange-700/20" style={{ insetInline: "12%" }} />
              <div className="absolute bottom-3 left-1/2 flex -translate-x-1/2 items-center gap-2 whitespace-nowrap rounded-full border border-orange-900/10 bg-white/80 px-3 py-1.5 text-xs font-extrabold uppercase text-[#14213D] shadow-sm sm:bottom-4">
                <span className="h-2 w-2 rounded-full bg-teal-700" /> Online & tatap muka
              </div>
            </div>

            <div className="hero-status-card absolute right-0 top-[16%] z-20 hidden rounded-2xl border border-white/70 bg-white/95 px-4 py-3 shadow-[0_16px_36px_rgba(20,33,61,.15)] backdrop-blur min-[480px]:block sm:right-2">
              <p className="flex items-center gap-2 text-xs font-extrabold text-[#14213D]"><CalendarCheck2 size={17} className="text-orange-600" aria-hidden="true" />Jadwal dipilih</p>
              <p className="mt-1 text-[11px] font-semibold text-slate-500">Selasa & Kamis · 16.00</p>
            </div>

            <div className="hero-status-card hero-match-card absolute bottom-[7%] left-0 z-20 rounded-2xl border border-emerald-200 bg-white/95 px-4 py-3 shadow-[0_16px_36px_rgba(20,33,61,.15)] backdrop-blur sm:left-4">
              <p className="flex items-center gap-2 text-xs font-extrabold text-[#14213D]"><span className="hero-match-dot h-2.5 w-2.5 shrink-0 rounded-full bg-orange-500" aria-hidden="true" /><span className="relative min-w-[7.5rem]"><span className="hero-status-pending block">{content?.status_pending || "Memeriksa jadwal"}</span><span className="hero-status-found absolute inset-0 opacity-0">{content?.status_found || "Tutor ditemukan"}</span></span></p>
              <p className="mt-1 text-[11px] font-semibold text-slate-500">Sesuai mapel dan waktu</p>
            </div>
          </div>
        </Reveal>}
      </div>

      <div className="absolute inset-x-0 bottom-0 z-30 hidden translate-y-1/2 px-8 sm:block">
        <div className="mx-auto max-w-[1120px] rounded-[22px] border border-stone-200 bg-white p-2.5 shadow-[0_18px_45px_rgba(20,33,61,.14)] sm:rounded-[26px] sm:p-4 sm:shadow-[0_22px_55px_rgba(20,33,61,.13)]">
          <form onSubmit={submitSearch} className="flex items-center gap-2 sm:gap-3">
            <label className="flex min-h-12 min-w-0 flex-1 items-center gap-2 rounded-xl bg-stone-50 px-3 focus-within:ring-4 focus-within:ring-orange-100 sm:min-h-14 sm:gap-3 sm:rounded-2xl sm:px-4">
              <Search className="shrink-0 text-teal-700" size={18} aria-hidden="true" />
              <span className="sr-only">Cari program belajar</span>
              <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Cari Matematika, Inggris..." className="min-w-0 flex-1 bg-transparent text-xs font-semibold text-[#14213D] outline-none placeholder:text-slate-400 sm:text-base" />
            </label>
            <button type="submit" className="inline-flex min-h-12 shrink-0 items-center justify-center gap-2 rounded-xl bg-[#147D7E] px-4 text-xs font-extrabold text-white transition hover:bg-[#0E6566] sm:min-h-14 sm:rounded-2xl sm:px-6 sm:text-sm"><span className="sm:hidden">Cari</span><span className="hidden sm:inline">Temukan program</span><ArrowRight size={16} className="hidden sm:block" /></button>
          </form>
          <nav aria-label="Jelajah cepat" className="mt-3 flex gap-2 overflow-x-auto pb-1 sm:flex-wrap sm:justify-center">
            {quickLinks.map(([label, href]) => <a key={label} href={href} onClick={(event) => { event.preventDefault(); window.history.replaceState(null, "", href); scrollToSection(href.slice(1)); }} className="shrink-0 rounded-full border border-stone-200 px-3.5 py-2 text-xs font-extrabold text-slate-600 transition hover:border-orange-300 hover:bg-orange-50 hover:text-orange-800">{label}</a>)}
          </nav>
        </div>
      </div>
    </section>
  );
}

function MobileHeroVisual({ imageUrl }: { imageUrl: string }) {
  const sceneRef = useRef<HTMLDivElement>(null);
  const [active, setActive] = useState(false);

  useEffect(() => {
    const scene = sceneRef.current;
    if (!scene || !("IntersectionObserver" in window)) {
      setActive(true);
      return;
    }
    const observer = new IntersectionObserver(([entry]) => setActive(entry.isIntersecting), { threshold: 0.1 });
    observer.observe(scene);
    return () => observer.disconnect();
  }, []);

  return (
    <div ref={sceneRef} data-active={active ? "true" : "false"} className="hero-mobile-scene relative mx-auto mt-5 h-[17.5rem] w-full max-w-[22rem] overflow-hidden" aria-label="Siswa BimbelKu siap belajar">
      <div className="hero-mobile-paper absolute inset-x-[11%] bottom-0 top-4 overflow-hidden rounded-t-[8rem] border border-white/55 bg-gradient-to-br from-[#FFF1D4] via-[#F9D994] to-[#F1BB68] shadow-[0_20px_50px_rgba(4,21,39,.25)]" aria-hidden="true">
        <div className="absolute inset-0 bg-[repeating-linear-gradient(0deg,transparent_0px,transparent_26px,rgba(20,33,61,.09)_27px,transparent_28px)] opacity-75" />
        <div className="absolute bottom-0 left-[17%] top-[27%] w-px bg-orange-700/20" />
        <div className="absolute right-7 top-10 h-3 w-3 rounded-full border-2 border-orange-700/30" />
      </div>
      <div className="hero-mobile-writing absolute left-[13%] top-[64%] z-[2] flex flex-col items-start gap-2" aria-hidden="true">
        <span className="hero-mobile-writing-line h-1 w-10 rounded-full bg-[#0F6970]/60" />
        <span className="hero-mobile-writing-line h-1 w-7 rounded-full bg-[#0F6970]/45" />
      </div>
      <div className="hero-mobile-writing hero-mobile-writing-right absolute right-[13%] top-[28%] z-[2] flex flex-col items-end gap-2" aria-hidden="true">
        <span className="hero-mobile-writing-line h-1 w-9 rounded-full bg-[#B45309]/55" />
        <span className="hero-mobile-writing-line h-1 w-6 rounded-full bg-[#B45309]/40" />
      </div>
      <div className="hero-mobile-note hero-mobile-note-one absolute left-[1%] top-[30%] z-[5] flex items-center gap-1.5 rounded-xl border border-white/70 bg-white/95 px-2.5 py-2 text-[10px] font-extrabold text-[#14213D] shadow-[0_10px_24px_rgba(4,21,39,.16)]" aria-hidden="true">
        <BookOpen size={14} className="text-[#C2410C]" />Pilih mapel
      </div>
      <div className="hero-mobile-note hero-mobile-note-two absolute right-[1%] top-[55%] z-[5] flex items-center gap-1.5 rounded-xl border border-white/70 bg-white/95 px-2.5 py-2 text-[10px] font-extrabold text-[#14213D] shadow-[0_10px_24px_rgba(4,21,39,.16)]" aria-hidden="true">
        <CalendarCheck2 size={14} className="text-[#147D7E]" />Atur jadwal
      </div>
      <img
        src={imageUrl}
        alt="Siswa mengenakan batik dan membawa tas sekolah"
        loading="eager"
        decoding="async"
        {...imageFetchPriority("high")}
        width={480}
        height={573}
        className="absolute bottom-0 left-1/2 z-10 h-[17.1rem] w-auto max-w-none -translate-x-1/2 object-contain object-bottom"
      />
      <div className="absolute inset-x-[8%] bottom-0 z-20 h-9 rounded-t-[70%] border-t border-white/60 bg-gradient-to-b from-[#FADFA5] to-[#F3BD70] shadow-[0_-8px_22px_rgba(20,33,61,.1)]" aria-hidden="true" />
    </div>
  );
}
