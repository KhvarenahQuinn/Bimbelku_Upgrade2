import { useEffect, useMemo, useState } from "react";
import { ArrowRight, BookOpenCheck, Loader2, Search } from "lucide-react";
import { Link, useSearchParams } from "react-router-dom";
import Footer from "@/components/Footer";
import Navbar from "@/components/Navbar";
import StudentPackageLink from "@/components/StudentPackageLink";
import { useWebsiteContent } from "@/components/WebsiteContentProvider";
import { getCached } from "@/lib/http";
import { type CatalogSubject, packagePath, programPath, supportedLevels } from "@/lib/publicPrograms";
import SeoHead from "@/components/SeoHead";
import { seoAbsoluteUrl } from "@/lib/seo";
import { scrollToPublicAnchor } from "@/lib/scrollToPublicAnchor";
import { usePublicSectionMotion } from "@/hooks/usePublicSectionMotion";

type ProgramGroup = { id: number; slug: string; name: string; description: string | null; subjects: CatalogSubject[] };
type LearningProgram = { id: number; name: string; description: string | null; education_level: string; education_levels?: string[] | null; grade: string | null; catalog_category_id: number | null; subjects: Array<{ id: number; name: string }> };
const levels = ["Semua", "SD", "SMP", "SMA", "Umum"];

export default function ProgramCatalogGrouped() {
  const motionRef = usePublicSectionMotion();
  const { settings } = useWebsiteContent();
  const [params, setParams] = useSearchParams();
  const [subjects, setSubjects] = useState<CatalogSubject[]>([]);
  const [groups, setGroups] = useState<ProgramGroup[]>([]);
  const [learningPrograms, setLearningPrograms] = useState<LearningProgram[]>([]);
  const [query, setQuery] = useState(params.get("q") || "");
  const [level, setLevel] = useState(params.get("level") || "Semua");
  const [group, setGroup] = useState(params.get("group") || "all");
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);

  const load = async (force = false) => {
    setLoading(true); setFailed(false);
    try {
      const [programGroups, products] = await Promise.all([
        getCached<ProgramGroup[]>("/program-groups", { maxAgeMs: 300_000, force }),
        getCached<LearningProgram[]>("/learning-programs", { maxAgeMs: 300_000, force }),
      ]);
      const nextGroups = Array.isArray(programGroups.data) ? programGroups.data : [];
      const uniqueSubjects = new Map<number, CatalogSubject>();
      nextGroups.flatMap((item) => item.subjects || []).forEach((subject) => {
        if (subject.id > 0 && subject.name?.trim()) uniqueSubjects.set(subject.id, subject);
      });
      setSubjects([...uniqueSubjects.values()]);
      setGroups(nextGroups);
      setLearningPrograms(Array.isArray(products.data) ? products.data : []);
    } catch { setFailed(true); }
    finally { setLoading(false); }
  };

  useEffect(() => { void load(); }, []);
  useEffect(() => {
    if (loading || group === "all") return;
    const target = document.getElementById(`g-${group}`);
    if (!target) return;
    void scrollToPublicAnchor(target);
  }, [group, loading]);

  useEffect(() => {
    const next = new URLSearchParams();
    if (query.trim()) next.set("q", query.trim());
    if (level !== "Semua") next.set("level", level);
    if (group !== "all") next.set("group", group);
    setParams(next, { replace: true });
  }, [group, level, query, setParams]);

  const displayGroups = useMemo(() => {
    const source: ProgramGroup[] = groups.length ? groups : [{ id: 0, slug: "mapel", name: "Mata pelajaran tersedia", description: "Pilih mata pelajaran yang sesuai dengan kebutuhan belajar.", subjects }];
    const search = query.trim().toLocaleLowerCase("id-ID");
    return source
      .filter((item) => group === "all" || item.slug === group)
      .map((item) => ({
        ...item,
        subjects: item.subjects.filter((subject) =>
          (level === "Semua" || supportedLevels(subject).includes(level))
          && (!search || `${subject.name} ${item.name}`.toLocaleLowerCase("id-ID").includes(search))),
      })).filter((item) => item.subjects.length > 0);
  }, [group, groups, level, query, subjects]);
  const totalUnique = new Set(displayGroups.flatMap((item) => item.subjects.map((subject) => subject.id))).size;
  const visibleProducts = learningPrograms.filter((item) =>
    (level === "Semua" || (item.education_levels?.length ? item.education_levels : [item.education_level]).includes(level))
    && (group === "all" || groups.some((category) => category.slug === group && category.id === item.catalog_category_id))
    && (!query.trim() || `${item.name} ${item.subjects.map((subject) => subject.name).join(" ")}`.toLocaleLowerCase("id-ID").includes(query.trim().toLocaleLowerCase("id-ID"))));
  const seoSchemas = useMemo(() => {
    const catalogSubjects = [...new Map(groups.flatMap((item) => item.subjects).map((subject) => [subject.id, subject])).values()];
    const breadcrumbs = {
      "@context": "https://schema.org",
      "@type": "BreadcrumbList",
      itemListElement: [
        { "@type": "ListItem", position: 1, name: "Beranda", item: seoAbsoluteUrl("/") },
        { "@type": "ListItem", position: 2, name: "Program Bimbel", item: seoAbsoluteUrl("/program") },
      ],
    };
    if (catalogSubjects.length < 3) return [breadcrumbs];
    return [breadcrumbs, {
      "@context": "https://schema.org",
      "@type": "ItemList",
      itemListElement: catalogSubjects.slice(0, 50).map((subject, index) => {
        const firstLevel = supportedLevels(subject)[0] || "SD";
        return {
          "@type": "ListItem",
          position: index + 1,
          url: seoAbsoluteUrl(programPath(subject, firstLevel)),
          item: {
            "@type": "Course",
            name: `Les ${subject.name} ${firstLevel}`,
            description: `Bimbingan ${subject.name} untuk jenjang ${firstLevel}.`,
            provider: { "@type": "EducationalOrganization", name: settings.brand_name, sameAs: seoAbsoluteUrl("/") },
          },
        };
      }),
    }];
  }, [groups, settings.brand_name]);

  return <div className="public-site min-h-screen bg-[#FFFBF7]">
    <SeoHead title={`Program Bimbel dan Les Privat SD–SMA | ${settings.brand_name}`} description="Jelajahi program bimbel dan les privat SD, SMP, serta SMA. Buka profil mapel, materi, jenjang, tutor, dan pilihan belajar yang tersedia." canonicalPath="/program" image={settings.social_share_image_url} schemas={seoSchemas} />
    <Navbar />
    <main ref={motionRef}>
      <section className="border-b border-stone-200 bg-[#F7F1E8] py-16 sm:py-20">
        <div className="mx-auto max-w-[1200px] px-5 sm:px-8">
          <nav aria-label="Breadcrumb" className="text-sm font-bold text-slate-500"><Link to="/">Beranda</Link><span aria-hidden="true"> / </span><span className="text-[#14213D]">Program</span></nav>
          <div className="mt-9 grid gap-8 lg:grid-cols-[1fr_360px] lg:items-end">
            <div><p className="text-xs font-extrabold uppercase tracking-wider text-orange-700">Katalog BimbelKu</p><h1 className="mt-3 max-w-3xl text-4xl font-extrabold tracking-tight text-[#14213D] sm:text-5xl">Temukan bidang belajar yang tepat.</h1><p className="mt-5 max-w-2xl text-base leading-8 text-slate-600">Cari mapel menurut kategori, atau ambil program terpadu yang berisi beberapa mapel dalam satu pesanan.</p></div>
            <label className="flex min-h-14 items-center gap-3 rounded-2xl border border-stone-300 bg-white px-4 focus-within:border-orange-500"><Search size={20} className="text-slate-500" /><span className="sr-only">Cari program atau mapel</span><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Cari Kimia, Bahasa Inggris..." className="h-12 min-w-0 flex-1 bg-transparent font-semibold outline-none" /></label>
          </div>
        </div>
      </section>

      <section className="py-10 sm:py-14">
        <div className="mx-auto max-w-[1200px] px-5 sm:px-8">
          <div className="flex flex-wrap gap-2" role="group" aria-label="Filter jenjang">{levels.map((item) => <button key={item} aria-pressed={level === item} onClick={() => setLevel(item)} className={`min-h-10 rounded-xl px-4 text-sm font-extrabold ${level === item ? "bg-[#14213D] text-white" : "border border-stone-200 bg-white text-slate-600"}`}>{item}</button>)}</div>
          {groups.length > 0 && <div className="mt-5 flex gap-2 overflow-x-auto pb-2" role="group" aria-label="Filter kategori katalog"><button aria-pressed={group === "all"} onClick={() => setGroup("all")} className={`shrink-0 rounded-full px-4 py-2 text-sm font-bold ${group === "all" ? "bg-[#147D7E] text-white" : "border border-stone-200 bg-white"}`}>Semua kategori</button>{groups.map((item) => <button key={item.id} aria-pressed={group === item.slug} onClick={() => setGroup(item.slug)} className={`shrink-0 rounded-full px-4 py-2 text-sm font-bold ${group === item.slug ? "bg-[#147D7E] text-white" : "border border-stone-200 bg-white"}`}>{item.name} <span className="opacity-70">{item.subjects.length}</span></button>)}</div>}
          {visibleProducts.length > 0 && <section className="mt-7"><h2 className="text-xl font-extrabold text-[#14213D]">Program belajar terpadu</h2><div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{visibleProducts.map((item) => <article key={item.id} className="rounded-3xl border border-orange-200 bg-white p-5 shadow-sm"><p className="text-xs font-extrabold uppercase tracking-wider text-orange-700">Pilih mapel & sesi sendiri</p><h3 className="mt-2 text-xl font-extrabold text-[#14213D]">{item.name}</h3><p className="mt-2 text-sm text-slate-600">{(item.education_levels?.length ? item.education_levels : [item.education_level]).join(", ")}{item.grade ? ` · ${item.grade}` : ""}</p><p className="mt-3 text-sm font-bold text-slate-700">Pilihan mapel: {item.subjects.map((subject) => subject.name).join(", ")}</p>{item.description && <p className="mt-3 line-clamp-3 text-sm leading-6 text-slate-600">{item.description}</p>}<StudentPackageLink to={`/student/packages/new?learning_program=${item.id}`} className="mt-5 inline-flex min-h-11 items-center gap-2 rounded-xl bg-orange-600 px-4 text-sm font-extrabold text-white">Susun program <ArrowRight size={16} /></StudentPackageLink></article>)}</div></section>}
          {displayGroups.length > 0 && <p className="mt-6 text-sm font-bold text-slate-500">{totalUnique} mapel dalam {displayGroups.length} kategori katalog</p>}
          {loading ? <div className="mt-6 flex min-h-52 items-center justify-center gap-2 rounded-3xl bg-white"><Loader2 className="animate-spin" size={18} />Memuat katalog...</div> : failed ? <div className="mt-6 rounded-3xl bg-white p-8 text-center"><p className="font-bold">Katalog belum dapat dimuat.</p><button onClick={() => void load(true)} className="mt-3 text-orange-700">Coba lagi</button></div> : displayGroups.length === 0 ? visibleProducts.length === 0 && <p className="mt-6 rounded-3xl bg-white p-8 text-slate-600">Belum ada mapel yang cocok. Coba kata pencarian atau jenjang lain.</p> : <div className="mt-6 space-y-6">{displayGroups.map((item) => <section key={item.id} id={`g-${item.slug}`} className="scroll-mt-28 rounded-3xl border border-stone-200 bg-white p-6 shadow-sm sm:p-8"><div className="flex flex-wrap items-end justify-between gap-3"><div><p className="text-xs font-extrabold uppercase tracking-wider text-orange-700">Kategori · {item.subjects.length} mapel</p><h2 className="mt-2 text-2xl font-extrabold text-[#14213D]">{item.name}</h2>{item.description && <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-600">{item.description}</p>}</div></div><div className="mt-6 flex flex-wrap gap-2">{item.subjects.map((subject) => <Link key={subject.id} to={programPath(subject, level === "Semua" ? supportedLevels(subject)[0] : level)} className="inline-flex min-h-11 items-center gap-2 rounded-xl border border-stone-200 bg-[#FFFBF7] px-4 text-sm font-bold text-[#14213D] transition hover:border-orange-300 hover:bg-orange-50">{subject.name}<ArrowRight size={15} className="text-orange-700" /></Link>)}</div></section>)}</div>}
        </div>
      </section>

      <section className="bg-white py-14"><div className="mx-auto grid max-w-[1200px] gap-8 px-5 sm:px-8 lg:grid-cols-2"><div><p className="text-xs font-extrabold uppercase tracking-wider text-teal-700">Cara memilih</p><h2 className="mt-2 text-3xl font-extrabold text-[#14213D]">Mulai dari kebutuhan, bukan nama paket.</h2><p className="mt-4 leading-7 text-slate-600">Pilih jenjang dan mapel, jelaskan target atau materi yang ingin difokuskan, lalu tentukan jumlah sesi, jadwal, serta metode belajar pada formulir. Tidak perlu mengetahui nama Bab. Harga final dihitung dari pilihan itu sebelum pembayaran.</p></div><div className="rounded-3xl bg-[#14213D] p-7 text-white"><BookOpenCheck size={28} className="text-orange-300" /><h3 className="mt-4 text-xl font-extrabold">Belum yakin memilih Mapel?</h3><p className="mt-2 text-sm leading-6 text-slate-200">Ceritakan kebutuhanmu melalui WhatsApp atau buka detail Mapel terlebih dahulu. Pencarian tutor baru dimulai setelah pembayaran terverifikasi.</p><StudentPackageLink to="/student/packages/new" className="mt-5 inline-flex min-h-11 items-center gap-2 rounded-xl bg-orange-600 px-5 text-sm font-bold text-white">Susun paket <ArrowRight size={16} /></StudentPackageLink></div></div></section>
    </main><Footer />
  </div>;
}
