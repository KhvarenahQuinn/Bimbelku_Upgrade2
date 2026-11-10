import { useEffect, useRef, type ReactNode } from "react";
import { Link } from "react-router-dom";
import { ArrowLeft, BookOpenCheck, Check, GraduationCap, Sparkles } from "lucide-react";
import { useWebsiteContent } from "@/components/WebsiteContentProvider";

type AuthExperienceProps = {
  children: ReactNode;
  mode: "login" | "register";
  role: "student" | "teacher" | "all";
};

const authPanelCss = `
  .auth-shell { grid-template-columns: minmax(0, 1fr); }
  .auth-brand-panel {
    background-color: #f2a36f;
    background-image:
      radial-gradient(circle at 72% 48%, rgb(255 247 237 / 0.38), transparent 21rem),
      radial-gradient(circle at 10% 88%, rgb(15 118 110 / 0.13), transparent 18rem),
      linear-gradient(145deg, #f7b681 0%, #ee9667 100%);
  }
  .auth-brand-panel::after {
    content: "";
    position: absolute;
    inset: 0;
    z-index: 0;
    pointer-events: none;
    background-image: radial-gradient(circle, rgb(20 33 61 / 0.18) 1.2px, transparent 1.5px);
    background-size: 30px 30px;
    mask-image: linear-gradient(125deg, transparent 12%, #000 38%, transparent 76%);
  }
  .auth-brand-art {
    position: absolute;
    inset: 0;
    overflow: hidden;
    pointer-events: none;
    opacity: 0.55;
  }
  .auth-brand-art svg { width: 100%; height: 100%; }
  .auth-form-scroll {
    position: relative;
    height: 100dvh;
    min-height: 100dvh;
    overflow-y: auto;
    overscroll-behavior: contain;
    -webkit-overflow-scrolling: touch;
    padding: calc(0.75rem + env(safe-area-inset-top)) 1.25rem calc(1.5rem + env(safe-area-inset-bottom));
    scroll-padding-bottom: calc(4.5rem + env(safe-area-inset-bottom));
    scrollbar-gutter: stable;
    background-color: #f7f1e8;
    background-image:
      radial-gradient(circle at 88% 12%, rgb(249 165 76 / 0.13), transparent 18rem),
      radial-gradient(circle at 8% 82%, rgb(15 118 110 / 0.09), transparent 16rem);
  }
  .auth-form-content { position: relative; z-index: 1; }
  .auth-login-form .auth-control {
    display: flex;
    min-height: 3.5rem;
    align-items: center;
    overflow: hidden;
    border: 2px solid #cbd5e1;
    border-radius: 1rem;
    background: #fff;
    box-shadow: 0 9px 24px rgb(20 33 61 / 0.07);
    transition: border-color 160ms ease, box-shadow 160ms ease, transform 160ms ease;
  }
  .auth-login-form .auth-control:hover { border-color: #94a3b8; }
  .auth-login-form .auth-control:focus-within {
    border-color: #ea580c;
    box-shadow: 0 0 0 4px rgb(249 115 22 / 0.14), 0 12px 28px rgb(20 33 61 / 0.09);
    transform: translateY(-1px);
  }
  .auth-login-form .auth-control[data-invalid="true"] {
    border-color: #dc2626;
    box-shadow: 0 0 0 4px rgb(220 38 38 / 0.1);
  }
  .auth-login-form .auth-control-icon {
    display: grid;
    width: 3.35rem;
    align-self: stretch;
    flex: 0 0 3.35rem;
    place-items: center;
    border-right: 1px solid #e2e8f0;
    background: #fff7ed;
    color: #c2410c;
  }
  .auth-login-form .auth-control input {
    height: 3.35rem;
    min-width: 0;
    flex: 1;
    border: 0;
    background: transparent;
    padding: 0 1rem;
    color: #14213d;
    font-size: 1rem;
    font-weight: 650;
    outline: none;
  }
  .auth-login-form .auth-control input::placeholder { color: #94a3b8; font-weight: 500; }
  .auth-login-form .auth-control-action {
    display: grid;
    width: 3rem;
    align-self: stretch;
    flex: 0 0 3rem;
    place-items: center;
    color: #64748b;
  }
  .auth-login-form .auth-control-action:hover { background: #fff7ed; color: #c2410c; }
  .auth-login-form .auth-control-action:focus-visible { outline: 2px solid #f97316; outline-offset: -4px; }
  .auth-register-form input:not([type="checkbox"]):not([type="file"]):not([role="combobox"]),
  .auth-register-form textarea,
  .auth-register-form button[role="combobox"] {
    border-width: 2px;
    border-color: #cbd5e1;
    background-color: #fff;
    box-shadow: 0 7px 18px rgb(20 33 61 / 0.05);
    transition: border-color 160ms ease, box-shadow 160ms ease;
  }
  .auth-register-form input:not([type="checkbox"]):not([type="file"]):not([role="combobox"]):focus,
  .auth-register-form textarea:focus,
  .auth-register-form button[role="combobox"]:focus {
    border-color: #ea580c;
    box-shadow: 0 0 0 4px rgb(249 115 22 / 0.12);
  }
  .auth-register-form [aria-invalid="true"] { border-color: #dc2626 !important; }
  .auth-panel-art {
    position: sticky;
    top: 0;
    z-index: 0;
    height: 0;
    pointer-events: none;
  }
  .auth-panel-art svg { position: absolute; overflow: visible; }
  .auth-decor-books {
    top: 0.5rem;
    right: -1.75rem;
    width: 15rem;
    color: #c2410c;
    opacity: 0.13;
  }
  .auth-decor-notes {
    top: 66vh;
    left: -2rem;
    width: 13rem;
    color: #0f766e;
    opacity: 0.11;
  }
  @media (min-width: 640px) {
    .auth-form-scroll { padding-inline: 2.5rem; }
  }
  @media (min-width: 1024px) {
    .auth-shell { grid-template-columns: minmax(0, 57%) minmax(0, 43%); }
    .auth-form-scroll { padding-inline: 2.5rem; }
  }
  @media (min-width: 1280px) {
    .auth-form-scroll { padding-inline: 4rem; }
  }
  @media (max-width: 1023px) {
    /* 16px mencegah Safari iOS memperbesar halaman saat input difokuskan. */
    .auth-register-form input:not([type="checkbox"]):not([type="file"]):not([role="combobox"]),
    .auth-register-form textarea,
    .auth-register-form select,
    .auth-register-form button[role="combobox"] { font-size: 1rem; }
  }
  @media (max-width: 639px) {
    .auth-panel-art { display: none; }
  }
`;

function AuthBrandBackdrop() {
  return (
    <div className="auth-brand-art" aria-hidden="true">
      <svg viewBox="0 0 820 1000" preserveAspectRatio="none" fill="none">
        <g stroke="#14213D" strokeWidth="1.5">
          <circle cx="714" cy="170" r="132" opacity=".16" />
          <circle cx="714" cy="170" r="86" opacity=".12" />
          <path d="M-60 766C105 646 225 702 330 814s234 120 386-4" opacity=".15" strokeDasharray="8 13" />
          <path d="M34 210h132M34 230h92M635 874h130M681 894h84" opacity=".13" strokeLinecap="round" />
          <path d="M83 608c26-12 49-8 70 8v70c-23-15-45-18-70-10v-68Zm70 8c21-16 44-20 70-8v68c-25-8-47-5-70 10v-70Z" opacity=".17" strokeWidth="3" />
          <path d="M153 616v70" opacity=".17" strokeWidth="3" />
        </g>
        <g fill="#14213D" opacity=".22">
          <circle cx="92" cy="152" r="3" /><circle cx="125" cy="152" r="3" /><circle cx="158" cy="152" r="3" />
          <circle cx="92" cy="185" r="3" /><circle cx="125" cy="185" r="3" /><circle cx="158" cy="185" r="3" />
          <circle cx="659" cy="744" r="3" /><circle cx="692" cy="744" r="3" /><circle cx="725" cy="744" r="3" />
          <circle cx="659" cy="777" r="3" /><circle cx="692" cy="777" r="3" /><circle cx="725" cy="777" r="3" />
        </g>
        <g stroke="#0F766E" strokeWidth="3" opacity=".22">
          <path d="m665 294 6 15 15 6-15 6-6 15-6-15-15-6 15-6 6-15Z" />
          <path d="M284 108c46 9 79 34 99 75" strokeLinecap="round" />
          <path d="m508 122 37-19 37 19-37 19-37-19Zm13 8v26c17 10 32 10 48 0v-26" strokeLinejoin="round" />
          <path d="M545 141v32" strokeLinecap="round" />
          <path d="m610 688 70-34-24 72-17-27-29-11Zm29 11 17-18" strokeLinejoin="round" />
          <rect x="278" y="792" width="76" height="50" rx="8" transform="rotate(-8 278 792)" />
          <rect x="298" y="810" width="76" height="50" rx="8" transform="rotate(6 298 810)" />
        </g>
        <g fill="#14213D" opacity=".2" fontFamily="Plus Jakarta Sans, sans-serif" fontWeight="800">
          <text x="62" y="360" fontSize="24">A+</text>
          <text x="704" y="536" fontSize="26">π</text>
          <text x="430" y="912" fontSize="18">01 · 02 · 03</text>
        </g>
        <path d="m656 600 4 10 10 4-10 4-4 10-4-10-10-4 10-4 4-10Z" fill="#14213D" opacity=".3" />
      </svg>
    </div>
  );
}

function AuthPanelDecor({ mode }: { mode: "login" | "register" }) {
  return (
    <div className="auth-panel-art" aria-hidden="true">
      <svg className="auth-decor-books" viewBox="0 0 240 210" fill="none">
        <circle cx="162" cy="72" r="54" stroke="currentColor" strokeWidth="2" strokeDasharray="5 8" />
        <path d="M70 57c29-9 52-4 72 10v79c-23-13-47-17-72-9V57Zm72 10c20-14 43-19 72-10v80c-25-8-49-4-72 9V67Z" stroke="currentColor" strokeWidth="4" strokeLinejoin="round" />
        <path d="M142 67v79M84 77c18-3 31 0 44 7M84 94c18-2 31 1 44 8M156 84c14-7 27-9 43-6M156 102c14-6 27-8 43-5" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
        <path d="m46 42 4 10 10 4-10 4-4 10-4-10-10-4 10-4 4-10Zm151 116 3 8 8 3-8 3-3 8-3-8-8-3 8-3 3-8Z" fill="currentColor" />
        <text x="20" y="190" fill="currentColor" fontSize="18" fontWeight="700">{mode === "login" ? "a² + b²" : "A · B · C"}</text>
      </svg>
      <svg className="auth-decor-notes" viewBox="0 0 210 190" fill="none">
        <path d="M24 132c24-9 37-26 41-51M139 38c23 7 37 22 43 44" stroke="currentColor" strokeWidth="4" strokeLinecap="round" strokeDasharray="7 9" />
        <rect x="62" y="59" width="82" height="98" rx="14" stroke="currentColor" strokeWidth="4" transform="rotate(-8 62 59)" />
        <path d="m117 42 18 7-30 70-11 10v-15l23-72Z" stroke="currentColor" strokeWidth="4" strokeLinejoin="round" />
        <circle cx="35" cy="48" r="12" stroke="currentColor" strokeWidth="4" />
        <path d="M165 126h26M178 113v26" stroke="currentColor" strokeWidth="4" strokeLinecap="round" />
      </svg>
    </div>
  );
}

function BookScene() {
  const sceneRef = useRef<SVGSVGElement>(null);
  const floatRef = useRef<SVGGElement>(null);
  const shadowRef = useRef<SVGEllipseElement>(null);
  const pageRef = useRef<SVGGElement>(null);
  const pencilRef = useRef<SVGGElement>(null);
  const starRef = useRef<SVGGElement>(null);

  useEffect(() => {
    const scene = sceneRef.current;
    const floatingBook = floatRef.current;
    const shadow = shadowRef.current;
    const page = pageRef.current;
    const pencil = pencilRef.current;
    const star = starRef.current;
    if (!scene || !floatingBook || !shadow || !page || !pencil || !star) return;

    const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");
    let visible = true;
    let frame = 0;
    let running = false;

    const draw = (time: number) => {
      const floating = Math.sin(time / 1080);
      const turnProgress = (1 - Math.cos(time / 1850)) / 2;
      const pageWidth = Math.cos(turnProgress * Math.PI);
      floatingBook.setAttribute("transform", `translate(0 ${floating * 8}) rotate(${floating * 0.55} 260 210)`);
      shadow.setAttribute("rx", String(174 - floating * 7));
      shadow.setAttribute("opacity", String(0.2 - floating * 0.025));
      page.setAttribute("transform", `translate(258 0) scale(${pageWidth} 1) translate(-258 0)`);
      page.setAttribute("opacity", String(0.9 + Math.abs(pageWidth) * 0.1));
      pencil.setAttribute("transform", `translate(0 ${Math.sin(time / 1050) * 2}) rotate(${Math.sin(time / 1500) * 1.5} 392 83)`);
      star.setAttribute("opacity", String(0.55 + (Math.sin(time / 650) + 1) * 0.2));
      frame = window.requestAnimationFrame(draw);
    };

    const sync = () => {
      const shouldRun = visible && !document.hidden && !reducedMotion.matches;
      if (shouldRun && !running) {
        running = true;
        frame = window.requestAnimationFrame(draw);
      } else if (!shouldRun && running) {
        running = false;
        window.cancelAnimationFrame(frame);
      }
      if (reducedMotion.matches) {
        floatingBook.removeAttribute("transform");
        shadow.setAttribute("rx", "174");
        shadow.setAttribute("opacity", "0.2");
        page.removeAttribute("transform");
        page.setAttribute("opacity", "1");
        pencil.removeAttribute("transform");
        star.setAttribute("opacity", "0.75");
      }
    };

    const observer = new IntersectionObserver(([entry]) => {
      visible = entry.isIntersecting;
      sync();
    }, { threshold: 0.05 });
    observer.observe(scene);
    document.addEventListener("visibilitychange", sync);
    reducedMotion.addEventListener("change", sync);
    sync();

    return () => {
      observer.disconnect();
      document.removeEventListener("visibilitychange", sync);
      reducedMotion.removeEventListener("change", sync);
      window.cancelAnimationFrame(frame);
    };
  }, []);

  return (
    <svg ref={sceneRef} viewBox="0 0 520 380" className="h-auto w-full" aria-hidden="true" focusable="false">
      <defs>
        <linearGradient id="auth-book-cover" x1="0" y1="0" x2="1" y2="1"><stop stopColor="#268B86" /><stop offset="1" stopColor="#0F4F5C" /></linearGradient>
        <linearGradient id="auth-book-page" x1="0" y1="0" x2="1" y2="1"><stop stopColor="#FFFFFF" /><stop offset="1" stopColor="#F9EDD9" /></linearGradient>
        <linearGradient id="auth-book-edge" x1="0" y1="0" x2="0" y2="1"><stop stopColor="#F4E9D8" /><stop offset="1" stopColor="#CDBB9F" /></linearGradient>
        <linearGradient id="auth-cover-depth" x1="0" y1="0" x2="0" y2="1"><stop stopColor="#164D59" /><stop offset="1" stopColor="#0A2936" /></linearGradient>
        <filter id="auth-book-shadow" x="-20%" y="-20%" width="140%" height="150%"><feDropShadow dx="0" dy="8" stdDeviation="7" floodColor="#14213D" floodOpacity=".2" /></filter>
      </defs>
      <ellipse ref={shadowRef} cx="260" cy="327" rx="174" ry="18" fill="#14213D" opacity=".2" />
      <g ref={floatRef}>
       <g transform="rotate(-2 260 210)" filter="url(#auth-book-shadow)">
        <path d="M62 275c58-17 119-15 195 4 69-20 144-21 205-4l-11 24c-74-15-135-10-194 8-66-20-123-22-185-8Z" fill="url(#auth-cover-depth)" />
        <path d="M70 260c65-20 126-16 188 7 61-23 120-27 194-7v22c-73-16-132-9-194 12-62-21-122-27-188-12Z" fill="url(#auth-book-cover)" />
        <path d="M79 106c77-16 127-8 180 23v138c-65-27-122-33-180-14Z" fill="#E6DDD0" />
        <path d="M259 129c57-31 111-39 182-23v147c-61-17-122-13-182 14Z" fill="#E6DDD0" />
        <path d="M87 239c58-15 115-7 171 18v22c-61-23-116-29-171-14Z" fill="url(#auth-book-edge)" />
        <path d="M258 257c55-25 114-33 175-18v23c-64-15-119-8-175 17Z" fill="url(#auth-book-edge)" />
        <path d="M91 249c57-13 112-5 163 17M91 256c57-12 110-3 163 18M263 266c53-20 108-27 165-16M263 273c53-19 108-25 165-15" fill="none" stroke="#BCA98C" strokeWidth="2" opacity=".7" />
        <path d="M87 99c73-13 124-3 171 27v138c-54-27-112-34-171-17Z" fill="url(#auth-book-page)" />
        <path d="M258 126c54-30 110-40 175-27v148c-60-17-119-10-175 17Z" fill="url(#auth-book-page)" />
        <path d="M258 126v138" stroke="#BFA98B" strokeWidth="7" />
        <path d="M261 128v133" stroke="#FFF9EF" strokeWidth="2" opacity=".85" />
        <path d="M105 133c45-5 91 2 130 23M105 155c47-4 89 5 130 25M105 178c48-2 89 8 130 28M105 201c50-2 91 9 130 29" fill="none" stroke="#C9D9D7" strokeLinecap="round" strokeWidth="5" />
        <path d="M106 121h54" stroke="#EF7E3B" strokeLinecap="round" strokeWidth="7" />
        <g ref={pageRef}>
          <path d="M259 126c54-30 110-40 175-27v148c-60-17-119-10-175 17Z" fill="url(#auth-book-page)" stroke="#D7C6AD" strokeWidth="2" />
          <path d="M263 130c44-23 91-32 146-26-37 7-74 20-108 40-17 10-30 4-38-14Z" fill="#FFFFFF" opacity=".55" />
          <path d="M281 155c39-18 83-24 130-21M281 177c42-17 87-21 130-17M281 200c43-15 86-17 130-14M281 223c45-13 88-14 130-11" fill="none" stroke="#C9D9D7" strokeLinecap="round" strokeWidth="5" />
          <path d="M281 139h55" stroke="#EF7E3B" strokeLinecap="round" strokeWidth="7" />
          <path d="M258 126v138" stroke="#D5C4AE" strokeWidth="3" />
        </g>
        <path d="M361 104V76l11 7 11-7v29" fill="#E96929" />
        <g ref={pencilRef}>
          <path d="m389 31 27 10-49 112-13 13-1-19Z" fill="#FFCA72" stroke="#F29B3C" strokeWidth="3" />
          <path d="m389 31 27 10-8 17-27-11Z" fill="#E96929" />
          <path d="m353 147 14 6-13 13Z" fill="#203746" />
          <path d="m381 49 27 10" stroke="#FFF3DC" strokeWidth="3" />
        </g>
        <g ref={starRef} fill="#FFC674">
          <path d="m57 109 5 12 12 5-12 5-5 12-5-12-12-5 12-5Z" />
          <path d="m452 53 3 8 8 3-8 3-3 8-3-8-8-3 8-3Z" />
          <circle cx="475" cy="171" r="4" />
        </g>
        <path d="M31 219c17-7 28-20 31-37M458 219c11 6 20 15 24 26" fill="none" stroke="#5E9C9E" strokeLinecap="round" strokeWidth="4" opacity=".6" />
       </g>
      </g>
    </svg>
  );
}

export default function AuthExperience({ children, mode, role }: AuthExperienceProps) {
  const { settings } = useWebsiteContent();
  const isTeacher = role === "teacher";
  const isUnified = role === "all";
  const brandName = settings.brand_name || "BimbelKu";
  const logo = settings.logo_dark_url || settings.logo_url;
  const mobileLogo = settings.logo_dark_url || settings.logo_url;
  const steps = isUnified
      ? ["Lanjutkan pesanan dan kegiatan belajarmu", "Temukan jadwal dan kelas dalam satu akun", "Sesi aman tetap aktif di perangkat"]
      : isTeacher
      ? ["Lengkapi dan kirim seluruh data", "Lanjutkan seleksi serta tes via WhatsApp", "Akun aktif setelah disetujui admin"]
      : ["Pilih kebutuhan belajar", "Atur pesanan dengan jelas", "Pantau proses di akunmu"];

  return (
    <main className="auth-shell relative grid h-dvh min-h-0 w-full overflow-hidden bg-white">
      <style>{authPanelCss}</style>
      <aside className="auth-brand-panel relative hidden h-dvh min-w-0 flex-col px-12 py-8 text-[#14213D] lg:flex">
        <AuthBrandBackdrop />
        <div className="relative z-10 flex items-center justify-between gap-4">
          <Link to="/" className="inline-flex min-h-12 items-center gap-3 text-xl font-black tracking-tight text-[#14213D]" aria-label={`${brandName}, kembali ke beranda`}>
            {logo ? <img src={logo} alt="" loading="eager" decoding="async" className="h-11 object-contain" style={{ maxWidth: 144 }} /> : <span className="grid h-11 w-11 place-items-center rounded-2xl bg-[#14213D] text-white"><BookOpenCheck size={24} /></span>}
            {!logo && <span>{brandName}</span>}
          </Link>
          <span className="rounded-full border border-slate-300 px-3 py-1 text-xs font-bold text-[#14213D]">{isUnified ? "Ruang belajar" : isTeacher ? "Ruang tutor" : "Ruang belajar"}</span>
        </div>

        <div className="relative z-10 w-full max-w-md" style={{ marginBlock: "auto", paddingRight: "2rem" }}>
          <div>
            <p className="inline-flex items-center gap-2 text-xs font-extrabold uppercase tracking-widest text-teal-800"><Sparkles size={16} /> {isUnified ? "Belajar bersama BimbelKu" : isTeacher ? "Tumbuh bersama BimbelKu" : "Belajar bersama BimbelKu"}</p>
            <h1 className="mt-4 text-5xl font-black leading-tight tracking-tight">
              {isUnified ? <>Selamat datang kembali. <span className="text-teal-800">Lanjutkan langkah belajarmu.</span></> : isTeacher ? <>Bantu siswa maju. <span className="text-teal-800">Mulai dari sini.</span></> : <>Setiap langkah belajar <span className="text-teal-800">punya arah.</span></>}
            </h1>
            <p className="mt-4 max-w-md text-sm leading-6 text-slate-800 sm:text-base sm:leading-7">
              {isUnified
                  ? "Kembali ke kegiatan belajar dan mengajar dengan lebih mudah. Jadwal, pesanan, dan perkembangan kelas tersimpan di akunmu."
                : isTeacher
                  ? "Lengkapi seluruh data terlebih dahulu, lalu lanjutkan proses seleksi dan tes melalui WhatsApp."
                  : "Temukan bimbingan yang sesuai, susun kebutuhanmu, dan lanjutkan prosesnya dengan tenang dalam satu akun."}
            </p>
          </div>
          <div className="mt-8 grid gap-2">
            {steps.map((step, index) => <div key={step} className="flex items-center gap-2 text-xs font-semibold leading-5 text-[#14213D]"><span className="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-[#14213D] text-white"><Check size={14} strokeWidth={3} /></span><span><span className="sr-only">Langkah {index + 1}: </span>{step}</span></div>)}
          </div>
        </div>
        <div className="pointer-events-none absolute right-0 z-30" style={{ top: "55%", width: "clamp(270px, 26vw, 380px)", transform: "translate(8%, -50%)" }}><BookScene /></div>
        <p className="relative z-10 mt-6 hidden text-xs font-semibold text-slate-800 lg:block">BimbelKu · belajar lebih terarah, bersama tutor yang tepat.</p>
      </aside>

      <section className="auth-form-scroll min-w-0">
        <AuthPanelDecor mode={mode} />
        <div className={`auth-form-content mx-auto w-full pb-8 pt-2 sm:pt-3 ${mode === "login" ? "flex min-h-full max-w-md flex-col lg:justify-center" : "max-w-2xl"}`}>
          <div className="mb-7 flex items-center justify-between gap-3 lg:hidden">
            <Link to="/" className="inline-flex min-h-12 items-center gap-3 text-xl font-black tracking-tight text-[#14213D]">
              {mobileLogo ? <img src={mobileLogo} alt="" loading="eager" decoding="async" className="h-10 object-contain" style={{ maxWidth: 144 }} /> : <span className="grid h-11 w-11 place-items-center rounded-2xl bg-orange-500 text-white"><BookOpenCheck size={24} /></span>}
              {!mobileLogo && <span>{brandName}</span>}
            </Link>
            <span className="rounded-full border border-slate-200 px-3 py-1 text-xs font-bold text-orange-700">{isUnified ? "Ruang belajar" : isTeacher ? "Tutor" : "Murid"}</span>
          </div>
          <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
            <Link to="/" className="inline-flex min-h-11 items-center gap-2 rounded-xl text-sm font-bold text-slate-500 hover:text-orange-700"><ArrowLeft size={17} /> Kembali ke beranda</Link>

          </div>
          <div className="mb-6 flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-orange-700"><GraduationCap size={17} /> {isUnified ? "Akun BimbelKu" : isTeacher ? "Pendaftaran tutor" : "Akun murid"} <span className="h-px flex-1 bg-orange-200" /></div>
          {children}
          <p className="mt-10 border-t border-slate-200 pt-5 text-center text-xs leading-5 text-slate-500">Ingin tahu alurnya? <Link to="/cara-belajar" className="font-bold text-orange-700 hover:underline">Lihat cara belajar di BimbelKu</Link></p>
        </div>
      </section>
    </main>
  );
}
