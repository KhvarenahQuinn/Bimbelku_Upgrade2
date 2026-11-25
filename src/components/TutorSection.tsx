import { useEffect, useRef, useState } from "react";
import { ArrowRight, BadgeCheck, GraduationCap, ShieldCheck, UserRound } from "lucide-react";
import { Link } from "react-router-dom";

import Reveal from "@/components/Reveal";
import { useWebsiteContent } from "@/components/WebsiteContentProvider";
import { getCached } from "@/lib/http";

const placeholderTones = [
  "bg-teal-800",
  "bg-orange-700",
  "bg-sky-800",
  "bg-indigo-800",
  "bg-emerald-800",
  "bg-amber-700",
];

type TutorCardData = {
  id: number;
  imageUrl: string | null;
  title: string;
  note: string;
  degree: string | null;
};

type GalleryTutor = { id: number; display_name: string; degree: string; description: string | null; photo_url: string | null };
type PublicTutor = { id: number; name: string; degree: string | null; credentials: string | null; experience: string | null; bio: string | null; photo_url: string | null };

export default function TutorSection() {
  const { settings, section } = useWebsiteContent();
  const cms = section("tutors");
  const tickerRef = useRef<HTMLDivElement>(null);
  const [active, setActive] = useState(false);
  const [tutors, setTutors] = useState<TutorCardData[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const legacyTitles = section("trust")?.content?.trust_media_titles || [];
  const legacyNotes = section("trust")?.content?.trust_media_notes || [];
  const legacyPhotos = [settings.trust_image_1_url, settings.trust_image_2_url, settings.trust_image_3_url, settings.trust_image_4_url, settings.trust_image_5_url, settings.trust_image_6_url];
  const legacyTutors: TutorCardData[] = legacyPhotos.flatMap((imageUrl, index) => imageUrl ? [{
    id: -(index + 1),
    imageUrl,
    title: legacyTitles[index] || "Tutor BimbelKu",
    degree: null,
    note: legacyNotes[index] || "Informasi tutor diisi dari admin.",
  }] : []);
  const visibleTutors = tutors.length ? tutors : legacyTutors;

  useEffect(() => {
    let mounted = true;
    const loadTutors = async () => {
      try {
        const gallery = await getCached<GalleryTutor[]>("/website-tutor-gallery", { maxAgeMs: 30_000 });
        const galleryTutors = Array.isArray(gallery.data) ? gallery.data : [];
        if (galleryTutors.length) {
          if (mounted) setTutors(galleryTutors.map((tutor) => ({
            id: tutor.id,
            imageUrl: tutor.photo_url || null,
            title: tutor.display_name,
            degree: tutor.degree,
            note: tutor.description || "Tutor BimbelKu",
          })));
          return;
        }

        const hasLegacyPhotos = [settings.trust_image_1_url, settings.trust_image_2_url, settings.trust_image_3_url, settings.trust_image_4_url, settings.trust_image_5_url, settings.trust_image_6_url].some(Boolean);
        if (hasLegacyPhotos) return;
        const publicResponse = await getCached<PublicTutor[]>("/public-tutors", { maxAgeMs: 30_000 });
        if (!mounted) return;
        setTutors((Array.isArray(publicResponse.data) ? publicResponse.data : []).map((tutor) => ({
          id: tutor.id,
          imageUrl: tutor.photo_url || null,
          title: tutor.name,
          degree: tutor.degree,
          note: tutor.credentials || tutor.experience || tutor.bio || "Tutor BimbelKu",
        })));
      } catch {
        if (mounted) {
          setTutors([]);
          setFailed(true);
        }
      } finally {
        if (mounted) setLoading(false);
      }
    };
    void loadTutors();
    return () => { mounted = false; };
  }, [settings.trust_image_1_url, settings.trust_image_2_url, settings.trust_image_3_url, settings.trust_image_4_url, settings.trust_image_5_url, settings.trust_image_6_url]);

  useEffect(() => {
    const element = tickerRef.current;
    if (!element || !("IntersectionObserver" in window)) {
      setActive(settings.animations_enabled);
      return;
    }

    const observer = new IntersectionObserver(
      ([entry]) => setActive(entry.isIntersecting && settings.animations_enabled),
      { threshold: 0.08 },
    );
    observer.observe(element);
    return () => observer.disconnect();
  }, [settings.animations_enabled, visibleTutors.length]);

  const setTickerPlayState = (playState: "paused" | "") => {
    const track = tickerRef.current?.firstElementChild as HTMLElement | null;
    if (track) track.style.animationPlayState = playState;
  };

  return (
    <section className="overflow-hidden bg-[#14213D] py-20 text-white sm:py-24 lg:py-28">
      <div className="mx-auto max-w-[1200px] px-5 sm:px-8">
        <Reveal width="100%">
          <div className="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
            <div className="max-w-3xl">
              <p className="text-xs font-extrabold uppercase tracking-[0.18em] text-orange-300">{cms?.eyebrow || "Tutor BimbelKu"}</p>
              <h2 className="mt-4 text-balance text-3xl font-extrabold tracking-tight sm:text-4xl">{cms?.title || "Kenali tutor yang siap mendampingi belajar."}</h2>
              <p className="mt-5 max-w-2xl text-base leading-8 text-slate-300">{cms?.description || "Galeri hanya memuat tutor yang telah diverifikasi dan memberikan izin untuk ditampilkan."}</p>
            </div>
            <div className="inline-flex w-fit items-center gap-3 rounded-2xl border border-white/10 bg-white/10 px-4 py-3">
              <BadgeCheck className="text-teal-300" size={22} />
              <div><p className="text-lg font-extrabold">{loading ? "Memuat" : `${visibleTutors.length} tutor`}</p><p className="text-xs font-semibold text-slate-300">kartu galeri yang tampil</p></div>
            </div>
          </div>
        </Reveal>
      </div>

      {visibleTutors.length ? <div
        ref={tickerRef}
        onMouseEnter={() => setTickerPlayState("paused")}
        onMouseLeave={() => setTickerPlayState("")}
        data-active={active ? "true" : "false"}
        className={`${visibleTutors.length >= 3 ? "tutor-ticker" : ""} mt-10 overflow-hidden py-5`}
        role="region"
        aria-label="Galeri tutor BimbelKu"
      >
        <div className={`${visibleTutors.length >= 3 ? "tutor-ticker-track" : "mx-auto w-fit"} flex`}>
          <TutorGroup tutors={visibleTutors} />
          {visibleTutors.length >= 3 && <TutorGroup tutors={visibleTutors} duplicate />}
        </div>
      </div> : !loading && <div className="mx-auto mt-10 max-w-[1200px] px-5 sm:px-8"><div className="rounded-2xl border border-white/15 bg-white/5 p-6 text-sm text-slate-200">{failed ? "Galeri tutor belum dapat dimuat. Coba buka kembali halaman ini." : "Kartu tutor akan muncul setelah admin mengisi dan mengaktifkan galeri."}</div></div>}

      <div className="mx-auto mt-6 max-w-[1200px] px-5 sm:px-8">
        <div className="flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center"><p className="flex max-w-3xl items-start gap-2 text-xs font-semibold leading-5 text-slate-400"><ShieldCheck size={17} className="mt-0.5 shrink-0 text-emerald-300" />Bagian ini khusus galeri tutor. Foto hanya ditampilkan setelah ada persetujuan publik; nomor kontak dan dokumen pribadi tetap tidak dipublikasikan.</p><Link to="/tutor" className="inline-flex min-h-11 shrink-0 items-center gap-2 rounded-xl bg-white px-4 text-sm font-extrabold text-[#14213D]">Lihat semua tutor <ArrowRight size={16} /></Link></div>
      </div>
    </section>
  );
}

function TutorGroup({ tutors, duplicate = false }: { tutors: TutorCardData[]; duplicate?: boolean }) {
  return (
    <div className={`flex shrink-0 gap-4 px-2 ${duplicate ? "tutor-ticker-clone" : ""}`} aria-hidden={duplicate || undefined}>
      {tutors.map((tutor, index) => (
        <article key={`${duplicate ? "copy" : "original"}-${tutor.id}`} tabIndex={duplicate ? -1 : 0} className="group hover-scale-110 relative aspect-[4/5] w-52 shrink-0 overflow-hidden rounded-3xl border border-white/10 bg-slate-800 shadow-2xl transition-transform duration-300 sm:w-56 lg:w-60">
          {tutor.imageUrl ? (
            <img src={tutor.imageUrl} alt={duplicate ? "" : tutor.title} loading="lazy" decoding="async" className="absolute inset-0 h-full w-full object-cover" />
          ) : (
            <div className={`absolute inset-0 grid place-items-center ${placeholderTones[index]}`}>
              <div className="px-4 text-center">
                <UserRound size={48} className="mx-auto text-white/70" />
                <p className="mt-3 text-[11px] font-extrabold">Foto belum tersedia</p>
              </div>
            </div>
          )}
          <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-[#0c1730] via-[#14213D]/90 to-transparent p-5 pt-16">
            <p className="text-base font-extrabold">{tutor.title}</p>
            {tutor.degree && <p className="mt-1 text-xs font-bold text-orange-200">{tutor.degree}</p>}
            <p className="mt-2 flex items-center gap-1.5 text-xs font-extrabold uppercase tracking-wide text-orange-200"><GraduationCap size={13} />Jejak pendidikan & karier</p><p className="mt-1 line-clamp-2 text-xs font-semibold leading-5 text-slate-300">{tutor.note}</p>
          </div>
        </article>
      ))}
    </div>
  );
}
