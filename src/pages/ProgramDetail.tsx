import { useEffect, useMemo, useState } from "react";
import { ArrowLeft, ArrowRight, BookOpenCheck, CalendarRange, CreditCard, GraduationCap, Laptop2, Loader2, MessageCircle, Radar, RefreshCw, ShieldCheck, Star, Users } from "lucide-react";
import { Link, useParams, useSearchParams } from "react-router-dom";

import Footer from "@/components/Footer";
import Navbar from "@/components/Navbar";
import StudentPackageLink from "@/components/StudentPackageLink";
import SubjectDetailContent, { type SubjectPage, type SubjectTestimonial, type SubjectTutor } from "@/components/SubjectDetailContent";
import { useWebsiteContent } from "@/components/WebsiteContentProvider";
import { getCached } from "@/lib/http";
import { imageFetchPriority } from "@/lib/imagePriority";
import { type CatalogSubject, packagePath, programPath, programSlug, programSummary, supportedLevels } from "@/lib/publicPrograms";
import { whatsappHref } from "@/lib/websiteContent";
import SeoHead from "@/components/SeoHead";
import { seoAbsoluteUrl } from "@/lib/seo";
import { usePublicSectionMotion } from "@/hooks/usePublicSectionMotion";

type CurriculumChapter = {
  id: number;
  subject_id: number;
  subject_name: string;
  education_level: string;
  grade: string;
  title: string;
  sort_order: number;
};

type PackagePlan = {
  id: number;
  name: string;
  session_count: number;
  validity_days: number;
  maximum_subjects: number;
};

export default function ProgramDetail() {
  const { slug = "" } = useParams();
  const [searchParams, setSearchParams] = useSearchParams();
  const { settings } = useWebsiteContent();
  const [subjects, setSubjects] = useState<CatalogSubject[]>([]);
  const [plans, setPlans] = useState<PackagePlan[]>([]);
  const [chapters, setChapters] = useState<CurriculumChapter[]>([]);
  const [pageContent, setPageContent] = useState<SubjectPage | null>(null);
  const [testimonials, setTestimonials] = useState<SubjectTestimonial[]>([]);
  const [tutors, setTutors] = useState<SubjectTutor[]>([]);
  const [loading, setLoading] = useState(true);
  const motionRef = usePublicSectionMotion(loading);
  const [chapterLoading, setChapterLoading] = useState(false);
  const [failed, setFailed] = useState(false);

  const program = useMemo(() => subjects.find((subject) => programSlug(subject.name) === slug), [slug, subjects]);
  const levels = program ? supportedLevels(program) : [];
  const requestedLevel = searchParams.get("level") || "";
  const selectedLevel = levels.includes(requestedLevel) ? requestedLevel : levels[0] || "";

  const load = (force = false) => {
    setLoading(true);
    setFailed(false);
    // Package choices are supplemental. Do not hold the subject hero behind a
    // second API request when the catalog response is already available.
    const catalog = !force && window.__bimbelkuProgramCatalog
      ? window.__bimbelkuProgramCatalog.then((subjects) => subjects ?? getCached<{ subject_options?: CatalogSubject[] }>("/learning-catalog", { params: { compact: 1 }, maxAgeMs: 5 * 60_000 }).then((response) => response.data.subject_options || []))
      : getCached<{ subject_options?: CatalogSubject[] }>("/learning-catalog", { params: { compact: 1 }, maxAgeMs: 5 * 60_000, force }).then((response) => response.data.subject_options || []);
    void catalog.then((items) => {
      setSubjects(items.filter((item) => Number.isInteger(item.id) && item.id > 0 && item.name?.trim()));
    }).catch(() => setFailed(true)).finally(() => setLoading(false));
    void getCached<PackagePlan[]>("/package-plans", { maxAgeMs: 5 * 60_000, force })
      .then((response) => setPlans(Array.isArray(response.data) ? response.data : []))
      .catch(() => setPlans([]));
  };

  useEffect(() => load(), []);

  useEffect(() => {
    if (!program || !selectedLevel) return;
    setChapterLoading(true);
    void getCached<{ chapters?: CurriculumChapter[] }>("/learning-catalog", {
      params: { curriculum_subject_id: program.id, subject_name: program.name, education_level: selectedLevel },
      maxAgeMs: 5 * 60_000,
    }).then((response) => setChapters(response.data.chapters || []))
      .catch(() => setChapters([]))
      .finally(() => setChapterLoading(false));
  }, [program, selectedLevel]);

  useEffect(() => {
    if (!program) return;
    setPageContent(null);
    setTestimonials([]);
    setTutors([]);
    void getCached<{ content: SubjectPage | null; testimonials: SubjectTestimonial[]; tutors: SubjectTutor[] }>("/subject-pages/" + program.id, { maxAgeMs: 5 * 60_000 })
      .then((response) => {
        setPageContent(response.data.content || null);
        setTestimonials(response.data.testimonials || []);
        setTutors(response.data.tutors || []);
      })
      .catch(() => { setPageContent(null); setTestimonials([]); setTutors([]); });
  }, [program]);

  const related = useMemo(() => {
    if (!program) return [];
    return subjects.filter((subject) => subject.id !== program.id && supportedLevels(subject).includes(selectedLevel)).slice(0, 3);
  }, [program, selectedLevel, subjects]);
  const ratingValues = testimonials.map((item) => item.rating).filter((value): value is number => typeof value === "number");
  const averageRating = ratingValues.length ? ratingValues.reduce((total, value) => total + value, 0) / ratingValues.length : null;
  const chaptersByGrade = useMemo(() => Object.entries(chapters.reduce<Record<string, CurriculumChapter[]>>((groups, chapter) => {
    const key = chapter.grade || selectedLevel;
    (groups[key] ||= []).push(chapter);
    return groups;
  }, {})), [chapters, selectedLevel]);
  const heroImage = pageContent?.hero_image_url || settings.hero_desktop_image_url || "/hero-bimbelku-character-v2.webp";
  const seoSchemas = useMemo(() => {
    if (!program || !selectedLevel) return [];
    const canonical = programPath(program, selectedLevel);
    return [
      {
        "@context": "https://schema.org",
        "@type": "BreadcrumbList",
        itemListElement: [
          { "@type": "ListItem", position: 1, name: "Beranda", item: seoAbsoluteUrl("/") },
          { "@type": "ListItem", position: 2, name: "Program Bimbel", item: seoAbsoluteUrl("/program") },
          { "@type": "ListItem", position: 3, name: `Les ${program.name} ${selectedLevel}`, item: seoAbsoluteUrl(canonical) },
        ],
      },
      {
        "@context": "https://schema.org",
        "@type": "Course",
        name: `Les ${program.name} untuk ${selectedLevel}`,
        description: `Bimbingan ${program.name} untuk jenjang ${selectedLevel}.`,
        url: seoAbsoluteUrl(canonical),
        inLanguage: "id-ID",
        provider: { "@type": "EducationalOrganization", name: settings.brand_name, sameAs: seoAbsoluteUrl("/") },
      },
      {
        "@context": "https://schema.org",
        "@type": "Service",
        name: `Bimbel privat ${program.name} ${selectedLevel}`,
        description: pageContent?.hero_intro || programSummary(program),
        provider: { "@type": "EducationalOrganization", name: settings.brand_name, url: seoAbsoluteUrl("/") },
        areaServed: { "@type": "AdministrativeArea", name: "Daerah Istimewa Yogyakarta" },
        availableChannel: [
          { "@type": "ServiceChannel", serviceUrl: seoAbsoluteUrl(canonical), name: "Bimbel online" },
          { "@type": "ServiceChannel", serviceLocation: { "@type": "Place", name: "Yogyakarta" }, name: "Bimbel tatap muka" },
        ],
      },
    ];
  }, [pageContent?.hero_intro, program, selectedLevel, settings.brand_name]);

  const chooseLevel = (value: string) => {
    const params = new URLSearchParams(searchParams);
    params.set("level", value);
    setSearchParams(params, { replace: true });
  };

  if (loading) {
    const previewName = slug.split("-").filter(Boolean).map((part) => /^(tka|utbk|sd|smp|sma|ipa|ips)$/i.test(part) ? part.toUpperCase() : part.charAt(0).toLocaleUpperCase("id-ID") + part.slice(1)).join(" ");
    const previewLevel = ["SD", "SMP", "SMA", "Umum"].includes(requestedLevel) ? requestedLevel : "";
    return <div className="public-site min-h-screen bg-[#FFFBF7]"><Navbar /><main><section className="bg-[#0B6F78] pb-24 pt-16 text-white sm:pb-28 sm:pt-20"><div className="mx-auto max-w-[1200px] px-5 sm:px-8"><nav aria-label="Breadcrumb" className="text-sm font-bold text-teal-100"><Link to="/">Beranda</Link><span aria-hidden="true"> / </span><Link to="/program">Program</Link></nav><div className="mt-9 grid gap-10 lg:grid-cols-2 lg:items-center"><div><p className="text-xs font-extrabold uppercase tracking-wider text-orange-200">Program {previewLevel}</p><h1 className="mt-3 text-4xl font-extrabold tracking-tight sm:text-5xl">Les {previewName} {previewLevel}</h1><p className="mt-6 max-w-3xl text-lg leading-8 text-teal-50">Pilih materi, jadwal, mode belajar, dan periksa harga sebelum memesan.</p><span className="mt-7 inline-flex items-center gap-2 text-sm text-teal-100"><Loader2 className="animate-spin" size={18} />Memuat rincian program</span></div><div aria-hidden="true" className="min-h-[300px] rounded-3xl bg-[#14213D] lg:min-h-[430px]" /></div></div></section></main></div>;
  }

  if (failed) {
    return <div className="public-site min-h-screen bg-[#FFFBF7]"><Navbar /><main className="grid min-h-80 place-items-center px-5 text-center"><div><p className="text-xl font-extrabold text-[#14213D]">Detail program belum dapat dimuat.</p><button type="button" onClick={() => load(true)} className="mt-5 inline-flex min-h-11 items-center gap-2 rounded-xl bg-[#C2410C] px-5 text-sm font-bold text-white"><RefreshCw size={17} />Coba lagi</button></div></main><Footer /></div>;
  }

  if (!program) {
    return <div className="public-site min-h-screen bg-[#FFFBF7]"><Navbar /><main className="mx-auto grid min-h-80 max-w-2xl place-items-center px-5 text-center"><div><BookOpenCheck size={42} className="mx-auto text-orange-600" /><h1 className="mt-5 text-3xl font-extrabold text-[#14213D]">Program tidak ditemukan</h1><p className="mt-3 leading-7 text-slate-600">Program mungkin sudah tidak aktif atau alamatnya berubah.</p><Link to="/program" className="mt-6 inline-flex min-h-11 items-center gap-2 rounded-xl bg-[#14213D] px-5 text-sm font-extrabold text-white"><ArrowLeft size={17} />Kembali ke katalog</Link></div></main><Footer /></div>;
  }

  const builderUrl = packagePath(program, selectedLevel);
  const consultationMessage = `Halo ${settings.brand_name}, saya ingin berkonsultasi mengenai Les ${program.name} ${selectedLevel} di Yogyakarta.`;
  const consultationUrl = settings.whatsapp_enabled ? whatsappHref(settings.whatsapp_number, consultationMessage) : null;

  return (
    <div className="public-site min-h-screen bg-white">
      <SeoHead
        title={`Les ${program.name} ${selectedLevel} di Yogyakarta | ${settings.brand_name}`}
        description={`${pageContent?.hero_intro || programSummary(program)} Jelaskan target belajar, pilih jadwal, online atau tatap muka sebelum memesan.`.slice(0, 158)}
        canonicalPath={programPath(program, selectedLevel)}
        image={heroImage}
        schemas={seoSchemas}
      />
      <Navbar />
      <main ref={motionRef}>
        <section className="relative overflow-hidden bg-[#0B6F78] pb-24 pt-16 text-white sm:pb-28 sm:pt-20">
          <div className="mx-auto max-w-[1200px] px-5 sm:px-8">
            <nav aria-label="Breadcrumb" className="text-sm font-bold text-teal-100"><Link to="/" className="hover:text-orange-200">Beranda</Link><span aria-hidden="true"> / </span><Link to="/program" className="hover:text-orange-200">Program</Link><span aria-hidden="true"> / </span><span className="text-white">{program.name}</span></nav>
            <div className="mt-9 grid gap-10 lg:grid-cols-2 lg:items-center">
              <div>
                <p className="text-xs font-extrabold uppercase tracking-wider text-orange-200">Program {selectedLevel}</p>
                <h1 className="mt-3 text-4xl font-extrabold tracking-tight text-white sm:text-5xl">Les {program.name} untuk {selectedLevel}</h1>
                <p className="mt-6 max-w-3xl text-lg leading-8 text-teal-50">{pageContent?.hero_intro || programSummary(program)} Materi, jadwal, mode belajar, dan harga final dipilih di dalam penyusunan paket.</p>
                <div className="mt-5 flex flex-wrap gap-2"><span className="inline-flex min-h-9 items-center gap-2 rounded-full border border-stone-300 bg-white px-3 text-xs font-extrabold text-slate-700"><BookOpenCheck size={15} className="text-orange-700" />{chapterLoading ? "Memuat bab" : `${chapters.length} bab aktif`}</span>{averageRating !== null && <span className="inline-flex min-h-9 items-center gap-2 rounded-full border border-stone-300 bg-white px-3 text-xs font-extrabold text-slate-700"><Star size={15} className="fill-amber-400 text-amber-500" />{averageRating.toFixed(1)} dari {ratingValues.length} ulasan mapel</span>}{tutors.length > 0 && <span className="inline-flex min-h-9 items-center gap-2 rounded-full border border-stone-300 bg-white px-3 text-xs font-extrabold text-slate-700"><Users size={15} className="text-teal-700" />{tutors.length} tutor berizin publik</span>}</div>
                <div className="mt-7 flex flex-wrap gap-2" role="group" aria-label="Pilih jenjang program">
                  {levels.map((level) => <button key={level} type="button" onClick={() => chooseLevel(level)} aria-pressed={selectedLevel === level} className={`min-h-10 rounded-xl px-4 text-sm font-extrabold ${selectedLevel === level ? "bg-[#14213D] text-white" : "border border-stone-300 bg-white text-slate-700"}`}>{level}</button>)}
                </div>
                <div className="mt-8 flex flex-col gap-3 sm:flex-row">
                  <StudentPackageLink to={builderUrl} className="inline-flex min-h-13 items-center justify-center gap-2 rounded-2xl bg-[#C2410C] px-6 py-3 text-sm font-extrabold text-white hover:bg-[#9A3412]">Susun Paket <ArrowRight size={18} /></StudentPackageLink>
                  {consultationUrl && <a href={consultationUrl} target="_blank" rel="noreferrer" className="inline-flex min-h-13 items-center justify-center gap-2 rounded-2xl border border-stone-300 bg-white px-6 py-3 text-sm font-extrabold text-[#14213D] hover:border-orange-300"><MessageCircle size={18} />Konsultasi WhatsApp</a>}
                </div>
              </div>
              <aside className="relative min-h-[430px] overflow-hidden rounded-3xl bg-[#14213D] shadow-2xl ring-1 ring-white/20">
                <picture className="absolute inset-0 block h-full w-full">
                  {heroImage === "/hero-bimbelku-character-v2.webp" && <source media="(max-width: 639px)" srcSet="/hero-bimbelku-character-mobile-optimized.webp" type="image/webp" />}
                  <img src={heroImage} alt={`Suasana belajar ${program.name}`} loading="eager" decoding="async" {...imageFetchPriority("high")} className="h-full w-full object-cover object-center" />
                </picture>
                <div className="absolute inset-0 bg-gradient-to-t from-[#071A2D] via-[#071A2D]/20 to-transparent" aria-hidden="true" />
                <div className="absolute left-5 top-5 max-w-[72%] rounded-3xl bg-white/95 p-5 text-[#14213D] shadow-xl sm:left-7 sm:top-7 sm:p-6 lg:backdrop-blur">
                  <p className="text-xs font-extrabold uppercase tracking-wider text-teal-700">Les privat · {selectedLevel}</p><p className="mt-2 text-2xl font-extrabold leading-tight">{program.name}</p><p className="mt-3 text-xs font-semibold leading-5 text-slate-600">Jelaskan materi atau target, pilih jadwal dan metode belajar sebelum memesan.</p>
                </div>
                <div className="absolute inset-x-5 bottom-5 grid gap-2 sm:inset-x-7 sm:grid-cols-2"><p className="flex gap-2 rounded-2xl bg-[#14213D]/90 p-3 text-xs font-bold leading-5 text-white lg:backdrop-blur"><CreditCard size={16} className="shrink-0 text-orange-300" />Harga terlihat sebelum bayar</p><p className="flex gap-2 rounded-2xl bg-[#14213D]/90 p-3 text-xs font-bold leading-5 text-white lg:backdrop-blur"><Radar size={16} className="shrink-0 text-teal-300" />Matching setelah verifikasi</p></div>
              </aside>
            </div>
          </div>
          <svg className="absolute inset-x-0 bottom-0 h-14 w-full text-white" viewBox="0 0 1440 80" preserveAspectRatio="none" aria-hidden="true"><path fill="currentColor" d="M0,54 C260,5 520,8 720,34 C980,68 1180,78 1440,24 L1440,80 L0,80 Z" /></svg>
        </section>

        <section className="bg-white py-16 sm:py-20">
          <div className="mx-auto grid max-w-[1200px] gap-10 px-5 sm:px-8 lg:grid-cols-2">
            <div>
              <p className="text-xs font-extrabold uppercase tracking-wider text-teal-700">Yang dapat dibantu</p>
              <h2 className="mt-3 text-3xl font-extrabold text-[#14213D]">Belajar dari kebutuhan yang spesifik.</h2>
              <div className="mt-7 grid gap-3">
                {[
                  "Menguatkan konsep yang belum dipahami di kelas.",
                  "Membahas latihan dan kesalahan langkah secara terarah.",
                  "Menyiapkan ulangan, asesmen, atau target materi tertentu.",
                ].map((item) => <p key={item} className="flex gap-3 rounded-2xl border border-stone-200 bg-[#FFFBF7] p-4 text-sm font-semibold leading-6 text-slate-700"><ShieldCheck size={19} className="mt-0.5 shrink-0 text-teal-700" />{item}</p>)}
              </div>
              <div className="mt-8 rounded-2xl border border-orange-200 bg-orange-50 p-5">
                <p className="font-extrabold text-orange-950">Mode belajar</p>
                <div className="mt-3 flex flex-wrap gap-2"><span className="inline-flex items-center gap-2 rounded-full bg-white px-3 py-2 text-sm font-bold text-slate-700"><Laptop2 size={17} />Online</span><span className="inline-flex items-center gap-2 rounded-full bg-white px-3 py-2 text-sm font-bold text-slate-700"><GraduationCap size={17} />Tatap muka di area Yogyakarta</span></div>
                <p className="mt-3 text-xs leading-5 text-orange-900">Ketersediaan aktual mengikuti mapel, alamat, jadwal, dan tutor pada saat matching.</p>
              </div>
            </div>

            <div>
              <div className="flex items-end justify-between gap-4"><div><p className="text-xs font-extrabold uppercase tracking-wider text-orange-700">Contoh materi</p><h2 className="mt-3 text-3xl font-extrabold text-[#14213D]">Bab aktif di sistem</h2></div>{chapterLoading && <Loader2 className="animate-spin text-orange-600" size={20} />}</div>
              {chapters.length ? (
                <div className="mt-7 space-y-3">
                  {chaptersByGrade.map(([gradeLabel, gradeChapters], index) => <details key={gradeLabel} open={index === 0} className="group overflow-hidden rounded-2xl border border-stone-200 bg-[#FFFBF7]"><summary className="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 font-extrabold text-[#14213D]"><span>{gradeLabel}</span><span className="rounded-full bg-orange-100 px-3 py-1 text-xs text-orange-800">{gradeChapters.length} Bab</span></summary><div className="grid gap-2 border-t border-stone-200 bg-white p-4 sm:grid-cols-2">{gradeChapters.map((chapter) => <p key={chapter.id} className="rounded-xl bg-stone-50 px-4 py-3 text-sm font-bold leading-6 text-slate-700">{chapter.title}</p>)}</div></details>)}
                </div>
              ) : !chapterLoading && <div className="mt-7 rounded-2xl border border-stone-200 bg-stone-50 p-6 text-sm leading-7 text-slate-600">Daftar Bab belum dipublikasikan untuk jenjang ini. Pilihan materi yang aktif tetap akan muncul saat menyusun paket.</div>}
            </div>
          </div>
        </section>

        <div className="relative bg-[#E8F4F2] pt-12"><div className="absolute inset-x-0 top-0 flex h-12 overflow-hidden text-[#147D7E]" aria-hidden="true">{Array.from({ length: 18 }).map((_, index) => <span key={index} className="-mt-6 h-12 min-w-20 flex-1 rounded-b-full bg-white" />)}</div><SubjectDetailContent name={program.name} content={pageContent} testimonials={testimonials} tutors={tutors} /></div>

        <section className="relative overflow-hidden bg-[#FFFBF7] py-16 sm:py-20 before:absolute before:inset-x-0 before:top-0 before:h-2 before:bg-gradient-to-r before:from-orange-500 before:via-amber-300 before:to-teal-600">
          <div className="mx-auto max-w-[1200px] px-5 sm:px-8">
            <div className="max-w-3xl"><p className="text-xs font-extrabold uppercase tracking-wider text-orange-700">Pilihan paket</p><h2 className="mt-3 text-3xl font-extrabold text-[#14213D]">Pilih ritme; harga dihitung dari pilihan nyata.</h2><p className="mt-4 leading-7 text-slate-600">Harga tidak dipukul rata. Total mengikuti jenjang, mata pelajaran, durasi, mode, dan jumlah sesi, lalu ditampilkan sebelum pembayaran.</p></div>
            {plans.length ? (
              <div className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                {plans.map((plan) => <article key={plan.id} className="rounded-3xl border border-stone-200 bg-white p-5 shadow-sm"><CalendarRange size={22} className="text-orange-700" /><h3 className="mt-4 font-extrabold text-[#14213D]">{plan.name}</h3><p className="mt-2 text-2xl font-extrabold text-[#14213D]">{plan.session_count} sesi</p><p className="mt-2 text-sm leading-6 text-slate-500">Masa aktif {plan.validity_days} hari · maks. {plan.maximum_subjects} mapel</p></article>)}
              </div>
            ) : <div className="mt-8 rounded-2xl border border-stone-200 bg-white p-6 text-sm leading-7 text-slate-600">Paket aktif belum tersedia untuk ditampilkan. Hubungi admin melalui WhatsApp atau periksa kembali saat paket sudah diaktifkan.</div>}
          </div>
        </section>

        <section className="py-16 sm:py-20">
          <div className="mx-auto max-w-[1100px] px-5 sm:px-8">
            <div className="text-center"><p className="text-xs font-extrabold uppercase tracking-wider text-teal-700">Alur transparan</p><h2 className="mt-3 text-3xl font-extrabold text-[#14213D]">Dari pilihan program sampai mulai belajar.</h2></div>
            <ol className="mt-9 grid gap-4 md:grid-cols-5">
              {[["01", "Isi peserta", "Tulis identitas dan email, tanpa harus login lebih dulu."], ["02", "Pilih program", "Jelaskan target atau materi, tentukan paket, durasi, serta jumlah peserta."], ["03", "Atur jadwal", "Pilih mode, hari, waktu, dan lokasi belajar."], ["04", "Simpan pesanan", "Periksa harga dan ketentuan; pesanan tersimpan dengan kode."], ["05", "Masuk & bayar", "Gunakan email yang sama, lanjutkan pembayaran, lalu matching dimulai setelah verifikasi."]].map(([number, title, text]) => <li key={number} className="rounded-3xl border border-stone-200 p-5"><span className="text-sm font-extrabold text-orange-700">{number}</span><h3 className="mt-3 font-extrabold text-[#14213D]">{title}</h3><p className="mt-2 text-sm leading-6 text-slate-600">{text}</p></li>)}
            </ol>
            <div className="mt-9 flex flex-col items-center justify-center gap-3 sm:flex-row"><StudentPackageLink to={builderUrl} className="inline-flex min-h-12 items-center gap-2 rounded-2xl bg-[#C2410C] px-6 text-sm font-extrabold text-white">Susun Paket {program.name}<ArrowRight size={17} /></StudentPackageLink><Link to="/#tutor" className="inline-flex min-h-12 items-center px-5 text-sm font-extrabold text-[#14213D]">Lihat galeri tutor</Link></div>
          </div>
        </section>

        {related.length > 0 && <section className="border-t border-stone-200 bg-[#FFFBF7] py-16"><div className="mx-auto max-w-[1200px] px-5 sm:px-8"><h2 className="text-2xl font-extrabold text-[#14213D]">Program lain untuk {selectedLevel}</h2><div className="mt-6 grid gap-3 sm:grid-cols-3">{related.map((subject) => <Link key={subject.id} to={programPath(subject, selectedLevel)} className="rounded-2xl border border-stone-200 bg-white p-5 font-extrabold text-[#14213D] hover:border-orange-300">{subject.name}<ArrowRight size={16} className="mt-3 text-orange-700" /></Link>)}</div></div></section>}
        <section className="bg-orange-600 py-14 text-white"><div className="mx-auto flex max-w-[1100px] flex-col gap-6 px-5 sm:px-8 lg:flex-row lg:items-center lg:justify-between"><div><p className="text-xs font-extrabold uppercase tracking-[.18em] text-orange-100">Siap menentukan kebutuhan?</p><h2 className="mt-2 text-3xl font-extrabold">Mulai dari mapel dan bab yang ingin dipelajari.</h2><p className="mt-3 max-w-2xl leading-7 text-orange-50">Pesanan dapat dibuat tanpa login. Harga dan data yang masih kurang diperlihatkan sebelum Anda melanjutkan.</p></div><div className="flex shrink-0 flex-col gap-3 sm:flex-row"><StudentPackageLink to={builderUrl} className="inline-flex min-h-12 items-center justify-center gap-2 rounded-2xl bg-[#14213D] px-6 text-sm font-extrabold text-white">Mulai pendaftaran <ArrowRight size={17} /></StudentPackageLink>{consultationUrl && <a href={consultationUrl} target="_blank" rel="noreferrer" className="inline-flex min-h-12 items-center justify-center gap-2 rounded-2xl border border-white/60 px-6 text-sm font-extrabold text-white"><MessageCircle size={17} />Tanya WhatsApp</a>}</div></div></section>
      </main>
      <Footer />
    </div>
  );
}
