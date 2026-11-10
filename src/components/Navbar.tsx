import { useEffect, useMemo, useRef, useState, type FormEvent, type ReactNode } from "react";
import { Link, useLocation, useNavigate } from "react-router-dom";
import { clearBrowserSession } from "@/lib/session";
import {
  ArrowRight,
  BookOpenCheck,
  BriefcaseBusiness,
  CalendarCheck,
  ChevronDown,
  LayoutDashboard,
  MapPin,
  Menu,
  MessageCircle,
  MonitorPlay,
  Search,
  ShieldCheck,
  Sparkles,
  User,
  UserRoundCheck,
  Users,
  WalletCards,
  X,
} from "lucide-react";

import StudentPackageLink from "@/components/StudentPackageLink";
import { useWebsiteContent } from "@/components/WebsiteContentProvider";
import { programPath, type CatalogSubject, supportedLevels } from "@/lib/publicPrograms";
import { whatsappHref, whatsappMessageForPath } from "@/lib/websiteContent";

type StoredUser = { name?: string; role?: string };
type ProgramGroup = { id: number; slug: string; name: string; description?: string | null; subjects: CatalogSubject[] };
type MenuKey = "program" | "services" | "guides" | "about";

const favoriteNames = ["Matematika", "Bahasa Inggris", "Bahasa Indonesia", "Fisika", "Kimia", "Biologi"];

const readStoredUser = (): StoredUser | null => {
  try {
    return JSON.parse(localStorage.getItem("user") || "null");
  } catch {
    clearBrowserSession();
    return null;
  }
};

export default function Navbar() {
  const { settings } = useWebsiteContent();
  const [mobileOpen, setMobileOpen] = useState(false);
  const [activeMenu, setActiveMenu] = useState<MenuKey | null>(null);
  const [searchOpen, setSearchOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState("");
  const [scrolled, setScrolled] = useState(false);
  const [subjects, setSubjects] = useState<CatalogSubject[]>([]);
  const [programGroups, setProgramGroups] = useState<ProgramGroup[]>([]);
  const [programMenuLoaded, setProgramMenuLoaded] = useState(false);
  const [user] = useState<StoredUser | null>(() => readStoredUser());
  const headerRef = useRef<HTMLElement>(null);
  const location = useLocation();
  const navigate = useNavigate();

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 12);
    onScroll();
    window.addEventListener("scroll", onScroll, { passive: true });
    return () => window.removeEventListener("scroll", onScroll);
  }, []);

  useEffect(() => {
    if (programMenuLoaded || (activeMenu !== 'program' && !mobileOpen && !searchOpen)) return;
    let active = true;
    const loadProgramGroups = async () => {
      const { getCached } = await import("@/lib/http");
      return getCached<ProgramGroup[]>("/program-groups", { maxAgeMs: 5 * 60_000 });
    };
    void loadProgramGroups().then((groups) => {
      if (!active) return;
      const nextGroups = Array.isArray(groups.data) ? groups.data : [];
      const uniqueSubjects = new Map<number, CatalogSubject>();
      nextGroups.flatMap((group) => group.subjects || []).forEach((subject) => {
        if (Number.isInteger(subject.id) && subject.id > 0 && subject.name?.trim()) uniqueSubjects.set(subject.id, subject);
      });
      setSubjects([...uniqueSubjects.values()]);
      setProgramGroups(nextGroups);
      setProgramMenuLoaded(true);
    }).catch(() => undefined);
    return () => { active = false; };
  }, [activeMenu, mobileOpen, programMenuLoaded, searchOpen]);

  useEffect(() => {
    setMobileOpen(false);
    setActiveMenu(null);
    setSearchOpen(false);
  }, [location.pathname, location.search, location.hash]);

  useEffect(() => {
    const closeOnOutside = (event: PointerEvent) => {
      if (headerRef.current && !headerRef.current.contains(event.target as Node)) setActiveMenu(null);
    };
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        setActiveMenu(null);
        setSearchOpen(false);
      }
    };
    document.addEventListener("pointerdown", closeOnOutside);
    document.addEventListener("keydown", closeOnEscape);
    return () => {
      document.removeEventListener("pointerdown", closeOnOutside);
      document.removeEventListener("keydown", closeOnEscape);
    };
  }, []);

  const levelCounts = useMemo(() => Object.fromEntries(["SD", "SMP", "SMA"].map((level) => [
    level,
    subjects.filter((subject) => supportedLevels(subject).includes(level)).length,
  ])), [subjects]);
  const popular = useMemo(() => {
    const selected = favoriteNames.map((name) => subjects.find((subject) => subject.name === name)).filter(Boolean) as CatalogSubject[];
    return (selected.length ? selected : subjects).slice(0, 6);
  }, [subjects]);
  const catalogPreview = useMemo(() => [...subjects].sort((left, right) => left.name.localeCompare(right.name, "id-ID")).slice(0, 8), [subjects]);

  const dashboardLink = user?.role === "admin" ? "/admin" : user?.role === "teacher" ? "/guru" : "/student/dashboard";
  const primaryUrl = settings.primary_cta_url || "/student/packages/new";
  const logo = settings.logo_dark_url || settings.logo_url;
  const consultationUrl = settings.whatsapp_enabled ? whatsappHref(settings.whatsapp_number, whatsappMessageForPath(location.pathname, settings.whatsapp_default_message)) : null;

  const closeMenus = () => {
    setActiveMenu(null);
    setMobileOpen(false);
    setSearchOpen(false);
  };

  const submitSearch = (event: FormEvent) => {
    event.preventDefault();
    const query = searchQuery.trim();
    navigate(query ? `/program?q=${encodeURIComponent(query)}` : "/program");
    closeMenus();
  };

  const whatsappCta = (compact = false) => consultationUrl ? (
    <a href={consultationUrl} target="_blank" rel="noreferrer" aria-label={settings.whatsapp_label} className={compact
      ? "grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-[#15803D] text-white transition hover:bg-green-800"
      : "inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-green-200 bg-green-50 px-4 text-sm font-extrabold text-green-800 transition hover:bg-green-100"}>
      <MessageCircle size={compact ? 19 : 17} aria-hidden="true" />
      {!compact && <span>WhatsApp</span>}
    </a>
  ) : null;

  const primaryCta = (className: string) => primaryUrl.startsWith("/student/packages/new") ? (
    <StudentPackageLink to={primaryUrl} className={className} onNavigate={closeMenus}><Search size={17} aria-hidden="true" />{settings.primary_cta_label}</StudentPackageLink>
  ) : (
    <Link to={primaryUrl} className={className} onClick={closeMenus}><Search size={17} aria-hidden="true" />{settings.primary_cta_label}</Link>
  );

  const toggleMenu = (menu: MenuKey) => {
    setSearchOpen(false);
    setActiveMenu((current) => current === menu ? null : menu);
  };

  return (
    <header ref={headerRef} className="sticky top-0 z-50 bg-white" onMouseLeave={() => setActiveMenu(null)}>

      <nav aria-label="Navigasi utama" className={`border-b bg-white transition ${scrolled || activeMenu || searchOpen ? "border-stone-200 shadow-sm" : "border-stone-100"}`}>
        <div className="mx-auto flex min-h-[4.75rem] max-w-[1200px] items-center justify-between gap-4 px-5 sm:px-8">
          <Link to="/" onClick={closeMenus} className="inline-flex shrink-0 items-center gap-2.5 rounded-lg" aria-label={`${settings.brand_name}, halaman utama`}>
            {logo ? <img src={logo} alt="" loading="eager" decoding="async" className="h-10 w-auto max-w-[10rem] object-contain" /> : <span className="grid h-10 w-10 place-items-center rounded-xl bg-orange-100 text-orange-700"><BookOpenCheck size={22} aria-hidden="true" /></span>}
            {!logo && <span className="text-xl font-extrabold tracking-tight text-[#14213D]">{settings.brand_name}</span>}
          </Link>

          <div className="hidden items-center gap-0.5 xl:flex">
            <NavButton label="Program" menu="program" active={activeMenu === "program"} onToggle={toggleMenu} />
            <NavButton label="Layanan" menu="services" active={activeMenu === "services"} onToggle={toggleMenu} />
            <NavButton label="Wawasan" menu="guides" active={activeMenu === "guides"} onToggle={toggleMenu} />
            <NavButton label="Tentang" menu="about" active={activeMenu === "about"} onToggle={toggleMenu} />
          </div>

          <div className="hidden items-center gap-2 xl:flex">
            <button type="button" onClick={() => { setActiveMenu(null); setSearchOpen((value) => !value); }} aria-expanded={searchOpen} aria-label="Cari program" className="grid h-11 w-11 place-items-center rounded-xl border border-stone-200 text-[#14213D] transition hover:border-orange-300 hover:bg-orange-50"><Search size={19} /></button>
            {whatsappCta()}
            {user ? <Link to={dashboardLink} className="inline-flex min-h-11 items-center gap-2 rounded-xl border border-stone-200 px-4 text-sm font-extrabold text-[#14213D] hover:bg-stone-50"><LayoutDashboard size={17} />Dashboard</Link> : <Link to="/login" className="inline-flex min-h-11 items-center px-3 text-sm font-extrabold text-slate-700 hover:text-orange-700">Masuk</Link>}
            {primaryCta("inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-[#C2410C] px-4 py-2.5 text-sm font-extrabold text-white transition hover:bg-[#9A3412]")}
          </div>

          <div className="flex shrink-0 items-center gap-2 xl:hidden">
            {whatsappCta(true)}
            {primaryCta("hidden min-h-10 items-center justify-center gap-1.5 rounded-xl bg-[#C2410C] px-3 text-xs font-extrabold text-white sm:inline-flex")}
            <button type="button" onClick={() => setMobileOpen((value) => !value)} aria-expanded={mobileOpen} aria-controls="mobile-navigation" aria-label={mobileOpen ? "Tutup menu" : "Buka menu"} className="grid h-10 w-10 place-items-center rounded-xl border border-stone-200 bg-white text-[#14213D]">{mobileOpen ? <X size={20} /> : <Menu size={20} />}</button>
          </div>
        </div>
      </nav>

      {searchOpen && <div className="absolute inset-x-0 top-full border-b border-stone-200 bg-white shadow-2xl"><div className="mx-auto max-w-[980px] px-5 py-6 sm:px-8">
        <div className="flex items-start justify-between gap-4"><div><p className="text-xs font-extrabold uppercase tracking-[.16em] text-orange-700">Cari les di BimbelKu</p><h2 className="mt-1 text-xl font-extrabold text-[#14213D]">Mulai dari mapel, kategori, atau program.</h2><p className="mt-1 text-sm text-slate-500">Buka profil mapel untuk melihat Bab, jenjang, cara belajar, dan alur pemesanan.</p></div><button type="button" onClick={() => setSearchOpen(false)} aria-label="Tutup pencarian" className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-stone-100 text-slate-600"><X size={18} /></button></div>
        <form onSubmit={submitSearch} className="mt-5 flex items-center gap-3"><Search className="absolute ml-4 shrink-0 text-orange-600" /><label className="sr-only" htmlFor="header-program-search">Cari program</label><input id="header-program-search" autoFocus value={searchQuery} onChange={(event) => setSearchQuery(event.target.value)} placeholder="Contoh: Matematika kelas 9 atau Kimia SMA" className="h-12 min-w-0 flex-1 rounded-2xl border border-stone-300 py-3 pl-12 pr-4 text-sm font-semibold outline-none focus:border-orange-400 focus:ring-4 focus:ring-orange-100" /><button type="submit" className="min-h-12 rounded-xl bg-[#14213D] px-6 text-sm font-extrabold text-white">Tampilkan hasil</button></form>
        <div className="mt-5 grid gap-5 border-t border-stone-100 pt-5 md:grid-cols-[1fr_280px]"><section><p className="text-xs font-extrabold uppercase tracking-wider text-slate-400">Mapel yang sering dicari</p><div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">{popular.slice(0, 6).map((subject) => <Link key={subject.id} to={programPath(subject, supportedLevels(subject)[0])} onClick={closeMenus} className="flex min-h-11 items-center gap-2 rounded-xl border border-stone-200 px-3 text-sm font-bold text-[#14213D] transition hover:border-orange-300 hover:bg-orange-50"><BookOpenCheck size={15} className="text-teal-700" /><span className="truncate">{subject.name}</span></Link>)}</div></section><aside className="rounded-2xl bg-[#F7F1E8] p-4"><p className="text-xs font-extrabold uppercase tracking-wider text-slate-500">Jelajahi kategori</p><div className="mt-2 flex flex-wrap gap-2">{programGroups.slice(0, 5).map((group) => <Link key={group.id} to={`/program?group=${encodeURIComponent(group.slug)}#g-${encodeURIComponent(group.slug)}`} onClick={closeMenus} className="rounded-full bg-white px-3 py-1.5 text-xs font-bold text-slate-700">{group.name}</Link>)}</div><Link to="/program" onClick={closeMenus} className="mt-4 inline-flex items-center gap-2 text-sm font-extrabold text-orange-700">Buka seluruh katalog <ArrowRight size={15} /></Link></aside></div>
      </div></div>}


      {activeMenu === "program" && <ProgramMenu groups={programGroups} preview={catalogPreview} popular={popular} levelCounts={levelCounts} close={closeMenus} />}
      {activeMenu === "services" && <ServicesMenu close={closeMenus} />}
      {activeMenu === "guides" && <GuidesMenu close={closeMenus} />}
      {activeMenu === "about" && <AboutMenu consultationUrl={consultationUrl} close={closeMenus} />}

      {mobileOpen && <MobileNavigation user={user} dashboardLink={dashboardLink} consultationUrl={consultationUrl} programGroups={programGroups} close={closeMenus} />}
    </header>
  );
}

function NavButton({ label, menu, active, onToggle }: { label: string; menu: MenuKey; active: boolean; onToggle: (menu: MenuKey) => void }) {
  return <button type="button" onClick={() => onToggle(menu)} aria-expanded={active} className={`inline-flex min-h-11 items-center gap-1.5 rounded-xl px-3 text-sm font-extrabold transition ${active ? "bg-[#14213D] text-white" : "text-slate-700 hover:bg-orange-50 hover:text-[#14213D]"}`}>{label}<ChevronDown size={14} className={`transition-transform ${active ? "rotate-180" : ""}`} /></button>;
}

function ProgramMenu({ groups, preview, popular, levelCounts, close }: { groups: ProgramGroup[]; preview: CatalogSubject[]; popular: CatalogSubject[]; levelCounts: Record<string, number>; close: () => void }) {
  return <div className="absolute top-full overflow-y-auto rounded-3xl border border-stone-200 bg-white shadow-2xl" style={{ left: "50%", width: "min(1040px, calc(100vw - 48px))", maxHeight: "calc(100dvh - 9rem)", transform: "translateX(-50%)" }}><div className="grid grid-cols-[190px_1fr_210px] gap-0 px-6 py-6">
    <aside className="rounded-3xl bg-[#14213D] p-5 text-white"><p className="text-[11px] font-extrabold uppercase tracking-[.16em] text-orange-300">Kategori katalog</p><div className="mt-3 space-y-1">{groups.length ? groups.slice(0, 8).map((group) => <Link key={group.id} to={`/program?group=${encodeURIComponent(group.slug)}#g-${encodeURIComponent(group.slug)}`} onClick={close} className="flex items-center justify-between rounded-xl px-3 py-2 text-sm font-extrabold transition hover:bg-white/10"><span className="truncate">{group.name}</span><span className="ml-2 rounded-full bg-white/10 px-2 py-1 text-[10px] text-indigo-100">{group.subjects.length}</span></Link>) : ["SD", "SMP", "SMA"].map((level) => <Link key={level} to={`/program?level=${level}`} onClick={close} className="flex items-center justify-between rounded-xl px-3 py-3 font-extrabold transition hover:bg-white/10"><span>{level}</span><span className="rounded-full bg-white/10 px-2 py-1 text-[10px] text-indigo-100">{levelCounts[level] || 0} mapel</span></Link>)}</div><Link to="/program" onClick={close} className="mt-4 inline-flex items-center gap-2 text-sm font-extrabold text-orange-300">Semua kategori <ArrowRight size={15} /></Link></aside>
    <section className="px-7"><div className="flex items-end justify-between"><div><p className="text-[11px] font-extrabold uppercase tracking-[.16em] text-orange-700">Katalog aktif</p><h2 className="mt-2 text-xl font-extrabold text-[#14213D]">Jelajahi mata pelajaran</h2></div><span className="text-xs font-bold text-slate-400">{preview.length ? "Data sistem" : "Memuat..."}</span></div><div className="mt-5 grid grid-cols-2 gap-2">{preview.map((subject) => <Link key={subject.id} to={programPath(subject, supportedLevels(subject)[0])} onClick={close} className="group flex min-h-14 items-center gap-3 rounded-2xl border border-stone-100 px-3 py-2.5 transition hover:border-orange-200 hover:bg-orange-50"><span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-stone-100 text-[#147D7E] group-hover:bg-white"><BookOpenCheck size={17} /></span><span className="min-w-0"><span className="block truncate text-sm font-extrabold text-[#14213D]">{subject.name}</span><span className="mt-0.5 block truncate text-[11px] font-semibold text-slate-400">{supportedLevels(subject).join(" · ")}</span></span></Link>)}</div></section>
    <aside className="border-l border-stone-200 pl-6"><p className="text-[11px] font-extrabold uppercase tracking-[.16em] text-teal-700">Paling dicari</p><div className="mt-4 space-y-1">{popular.map((subject) => <Link key={subject.id} to={programPath(subject, supportedLevels(subject)[0])} onClick={close} className="flex items-center justify-between rounded-xl px-3 py-2.5 text-sm font-bold text-slate-600 transition hover:bg-teal-50 hover:text-teal-900"><span className="truncate">{subject.name}</span><ArrowRight size={14} /></Link>)}</div><Link to="/program" onClick={close} className="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-teal-200 bg-teal-50 text-sm font-extrabold text-teal-900">Lihat program populer</Link></aside>
    <div className="col-span-3 mt-6 flex items-center justify-between gap-4 border-t border-stone-200 pt-4"><p className="text-xs font-semibold text-slate-500">Semua program berasal dari katalog yang aktif di sistem BimbelKu.</p><div className="flex gap-2"><Link to="/program" onClick={close} className="inline-flex min-h-11 items-center gap-2 rounded-xl bg-[#14213D] px-5 text-sm font-extrabold text-white">Lihat semua program <ArrowRight size={16} /></Link><Link to="/program?q=" onClick={close} className="inline-flex min-h-11 items-center gap-2 rounded-xl border border-stone-200 px-5 text-sm font-extrabold text-[#14213D]"><Search size={16} />Cari program</Link></div></div>
  </div></div>;
}

function ServicesMenu({ close }: { close: () => void }) {
  const cards = [
    { icon: Users, title: "Privat tatap muka", text: "Belajar langsung dengan jadwal dan lokasi yang dipilih.", to: "/cara-belajar/privat-tatap-muka" },
    { icon: MonitorPlay, title: "Privat online", text: "Belajar online sendiri atau bersama peserta dalam satu pesanan.", to: "/cara-belajar/privat-online" },
    { icon: Sparkles, title: "Susun paket", text: "Pilih mapel, jumlah sesi, durasi, serta jadwal belajar.", to: "/student/packages/new", package: true },
    { icon: Users, title: "Kelas Bersama", text: "Kelas online berjadwal; pahami kuota, biaya, dan statusnya.", to: "/cara-belajar/kelas-bersama" },
  ];
  return <CompactPanel width={880}><div className="grid grid-cols-[1fr_240px] gap-7"><section><p className="text-[11px] font-extrabold uppercase tracking-[.16em] text-orange-700">Cara belajar</p><div className="mt-4 grid grid-cols-2 gap-3">{cards.map((item) => { const Icon = item.icon; const className = "group rounded-2xl border border-stone-200 p-4 transition hover:border-orange-300 hover:bg-orange-50"; const body = <><span className="grid h-10 w-10 place-items-center rounded-xl bg-[#14213D] text-white"><Icon size={18} /></span><span className="mt-4 block font-extrabold text-[#14213D]">{item.title}</span><span className="mt-1 block text-xs leading-5 text-slate-500">{item.text}</span></>; return item.package ? <StudentPackageLink key={item.title} to={item.to} onNavigate={close} className={className}>{body}</StudentPackageLink> : <Link key={item.title} to={item.to} onClick={close} className={className}>{body}</Link>; })}</div></section><aside className="rounded-3xl bg-orange-50 p-5"><p className="text-sm font-extrabold text-orange-900">Alur yang transparan</p><ol className="mt-4 space-y-3 text-xs font-bold leading-5 text-slate-600">{["Tentukan kebutuhan", "Pilih paket dan jadwal", "Selesaikan pembayaran", "Sistem mencari tutor"].map((item, index) => <li key={item} className="flex gap-3"><span className="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-orange-600 text-[10px] text-white">{index + 1}</span>{item}</li>)}</ol><Link to="/cara-belajar" onClick={close} className="mt-5 inline-flex items-center gap-2 text-sm font-extrabold text-orange-800">Semua cara belajar <ArrowRight size={15} /></Link></aside></div></CompactPanel>;
}

function GuidesMenu({ close }: { close: () => void }) {
  const links = [
    { icon: BookOpenCheck, title: "Panduan memilih program", text: "Tentukan jenjang, mapel, Bab, dan target belajar yang tepat.", to: "/cara-belajar/panduan-memilih-program" },
    { icon: CalendarCheck, title: "Cara pemesanan", text: "Pahami empat tahap form sampai pesanan tersimpan.", to: "/cara-belajar/cara-pemesanan" },
    { icon: ShieldCheck, title: "Sistem matching tutor", text: "Lihat syarat kandidat dan cara penawaran tutor berjalan.", to: "/cara-belajar/sistem-matching-tutor" },
    { icon: WalletCards, title: "Pembayaran dan refund", text: "Status pembayaran, pengembalian dana, dan tindak lanjutnya.", to: "/cara-belajar/pembayaran-dan-refund" },
  ];
  return <CompactPanel width={860}><div><p className="text-[11px] font-extrabold uppercase tracking-[.16em] text-teal-700">Wawasan BimbelKu</p><h2 className="mt-2 text-xl font-extrabold text-[#14213D]">Panduan untuk setiap tahap keputusan</h2></div><div className="mt-5 grid grid-cols-2 gap-x-5 gap-y-2">{links.map((item) => { const Icon = item.icon; return <Link key={item.title} to={item.to} onClick={close} className="flex gap-3 rounded-2xl p-3 transition hover:bg-stone-50"><span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-teal-50 text-teal-800"><Icon size={18} /></span><span><span className="block text-sm font-extrabold text-[#14213D]">{item.title}</span><span className="mt-1 block text-xs leading-5 text-slate-500">{item.text}</span></span></Link>; })}</div><div className="mt-5 flex gap-5 border-t border-stone-200 pt-4 text-xs font-bold text-slate-500"><Link to="/privacy" onClick={close} className="hover:text-orange-700">Kebijakan privasi</Link><Link to="/terms" onClick={close} className="hover:text-orange-700">Syarat dan ketentuan</Link></div></CompactPanel>;
}
function AboutMenu({ consultationUrl, close }: { consultationUrl: string | null; close: () => void }) {
  const links = [
    { icon: ShieldCheck, title: "Tentang BimbelKu", text: "Cara kerja, komitmen, dan alasan sistem ini dibangun.", to: "/why-us" },
    { icon: Users, title: "Kenali tutor", text: "Profil tutor terverifikasi yang setuju ditampilkan secara publik.", to: "/tutor" },
    { icon: UserRoundCheck, title: "Seleksi tutor", text: "Dari dokumen dan tes sampai kesiapan menerima kelas.", to: "/seleksi-tutor" },
    { icon: MapPin, title: "Area layanan", text: "Cakupan online, wilayah tatap muka, dan cara memeriksa alamat.", to: "/area-layanan" },
  ];
  return <CompactPanel width={800}><div className="grid grid-cols-[1fr_250px] gap-7"><section><p className="text-[11px] font-extrabold uppercase tracking-[.16em] text-orange-700">Mengenal BimbelKu</p><div className="mt-4 grid grid-cols-2 gap-2">{links.map((item) => { const Icon = item.icon; return <Link key={item.title} to={item.to} onClick={close} className="flex gap-3 rounded-2xl p-3 transition hover:bg-orange-50"><span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-orange-100 text-orange-800"><Icon size={18} /></span><span><span className="block text-sm font-extrabold text-[#14213D]">{item.title}</span><span className="mt-1 block text-xs leading-5 text-slate-500">{item.text}</span></span></Link>; })}</div></section><aside className="rounded-3xl bg-[#14213D] p-5 text-white"><p className="text-sm font-extrabold">Hubungi dan bergabung</p><p className="mt-2 text-xs leading-5 text-indigo-100">Konsultasikan kebutuhan belajar atau pelajari proses seleksi tutor.</p>{consultationUrl && <a href={consultationUrl} target="_blank" rel="noopener noreferrer" className="mt-5 flex min-h-11 items-center justify-center gap-2 rounded-xl bg-[#15803D] px-4 text-sm font-extrabold"><MessageCircle size={16} />Konsultasi WhatsApp</a>}<Link to="/jadi-tutor" onClick={close} className="mt-2 flex min-h-11 items-center justify-center gap-2 rounded-xl border border-white/20 px-4 text-sm font-extrabold"><BriefcaseBusiness size={16} />Daftar tutor</Link></aside></div></CompactPanel>;
}

function CompactPanel({ width, children }: { width: number; children: ReactNode }) {
  return <div className="absolute top-full overflow-y-auto rounded-3xl border border-stone-200 bg-white shadow-2xl" style={{ left: "50%", width: `min(${width}px, calc(100vw - 48px))`, maxHeight: "calc(100dvh - 9rem)", transform: "translateX(-50%)" }}><div className="px-7 py-6">{children}</div></div>;
}

function MobileNavigation({ user, dashboardLink, consultationUrl, programGroups, close }: { user: StoredUser | null; dashboardLink: string; consultationUrl: string | null; programGroups: ProgramGroup[]; close: () => void }) {
  return <div id="mobile-navigation" className="max-h-[calc(100dvh-7rem)] overflow-y-auto border-b border-stone-200 bg-white px-5 py-4 shadow-xl xl:hidden"><div className="mx-auto max-w-[1200px] space-y-2">
    <MobileGroup label="Kategori & program">{programGroups.length ? programGroups.map((group) => <MobileLink key={group.id} to={`/program?group=${encodeURIComponent(group.slug)}#g-${encodeURIComponent(group.slug)}`} close={close}>{group.name}</MobileLink>) : <><MobileLink to="/program?level=SD" close={close}>Program SD</MobileLink><MobileLink to="/program?level=SMP" close={close}>Program SMP</MobileLink><MobileLink to="/program?level=SMA" close={close}>Program SMA</MobileLink></>}<MobileLink to="/program" close={close}>Semua kategori & program</MobileLink></MobileGroup>
    <MobileGroup label="Cara Belajar"><MobileLink to="/cara-belajar" close={close}>Ringkasan cara belajar</MobileLink><MobileLink to="/cara-belajar/privat-tatap-muka" close={close}>Privat tatap muka</MobileLink><MobileLink to="/cara-belajar/privat-online" close={close}>Privat online</MobileLink><MobileLink to="/cara-belajar/kelas-bersama" close={close}>Kelas Bersama</MobileLink></MobileGroup>
    <MobileGroup label="Wawasan"><MobileLink to="/cara-belajar/panduan-memilih-program" close={close}>Panduan memilih program</MobileLink><MobileLink to="/cara-belajar/cara-pemesanan" close={close}>Cara pemesanan</MobileLink><MobileLink to="/cara-belajar/sistem-matching-tutor" close={close}>Sistem matching tutor</MobileLink><MobileLink to="/cara-belajar/pembayaran-dan-refund" close={close}>Pembayaran dan refund</MobileLink></MobileGroup>
    <MobileGroup label="Tentang"><MobileLink to="/why-us" close={close}>Tentang BimbelKu</MobileLink><MobileLink to="/tutor" close={close}>Kenali tutor</MobileLink><MobileLink to="/seleksi-tutor" close={close}>Seleksi tutor</MobileLink><MobileLink to="/area-layanan" close={close}>Area layanan</MobileLink></MobileGroup>
    <form onSubmit={(event) => { event.preventDefault(); const input = new FormData(event.currentTarget).get("q")?.toString().trim(); window.location.assign(input ? `/program?q=${encodeURIComponent(input)}` : "/program"); }} className="flex gap-2 border-t border-stone-100 pt-4"><input name="q" placeholder="Cari program" className="h-11 min-w-0 flex-1 rounded-xl border border-stone-300 px-3 text-sm font-semibold" /><button className="grid h-11 w-11 place-items-center rounded-xl bg-[#14213D] text-white" aria-label="Cari"><Search size={17} /></button></form>
    {consultationUrl && <a href={consultationUrl} target="_blank" rel="noreferrer" className="flex min-h-11 items-center justify-center gap-2 rounded-xl bg-green-50 text-sm font-extrabold text-green-800"><MessageCircle size={17} />WhatsApp</a>}
    <div className="border-t border-stone-100 pt-3">{user ? <Link to={dashboardLink} onClick={close} className="flex items-center gap-2 rounded-xl px-3 py-3 text-sm font-extrabold text-[#14213D]"><LayoutDashboard size={18} />Dashboard {user.name ? `· ${user.name.split(" ")[0]}` : ""}</Link> : <Link to="/login" onClick={close} className="flex items-center gap-2 rounded-xl px-3 py-3 text-sm font-extrabold text-[#14213D]"><User size={18} />Masuk ke akun</Link>}</div>
  </div></div>;
}

function MobileGroup({ label, children }: { label: string; children: ReactNode }) {
  return <details className="rounded-2xl border border-stone-200"><summary className="flex min-h-12 cursor-pointer list-none items-center justify-between px-4 text-sm font-extrabold text-[#14213D]">{label}<ChevronDown size={16} /></summary><div className="border-t border-stone-100 p-2">{children}</div></details>;
}

function MobileLink({ to, close, children }: { to: string; close: () => void; children: ReactNode }) {
  return <Link to={to} onClick={close} className="block rounded-xl px-3 py-2.5 text-sm font-bold text-slate-600 hover:bg-orange-50">{children}</Link>;
}
