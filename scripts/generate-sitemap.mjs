import { readFileSync, writeFileSync } from "node:fs";
import { execFileSync } from "node:child_process";
import path from "node:path";
import { fileURLToPath } from "node:url";

const option = process.argv.find((value) => value.startsWith("--site-url="));
const rawSiteUrl = option?.slice("--site-url=".length) || process.env.VITE_SITE_URL || "https://bimbelcerdas.com";
if (!/^https:\/\//i.test(rawSiteUrl)) {
  console.error("Sitemap tidak dibuat: isi VITE_SITE_URL dengan domain HTTPS final, misalnya https://bimbelku.example.");
  process.exit(1);
}

const siteUrl = rawSiteUrl.replace(/\/+$/, "");
const staticPaths = [
  "/", "/program", "/cara-belajar", "/cara-belajar/privat-online",
  "/cara-belajar/privat-tatap-muka", "/cara-belajar/kelas-bersama",
  "/cara-belajar/panduan-memilih-program", "/cara-belajar/cara-pemesanan",
  "/cara-belajar/sistem-matching-tutor", "/cara-belajar/pembayaran-dan-refund",
  "/tutor", "/seleksi-tutor", "/area-layanan", "/testimonials", "/why-us", "/privacy", "/terms",
];
/**
 * Rute privat tidak boleh pernah masuk sitemap. Daftar ini dijaga terpisah
 * dari staticPaths agar penambahan rute publik di masa depan tidak bisa
 * diam-diam membocorkan portal admin.
 */
const privatePrefixes = [
  "/admin", "/admin/login", "/guru", "/student", "/payment", "/pesanan",
  "/login", "/register", "/forgot-password", "/reset-password",
  "/verify-email", "/oauth", "/complete-profile", "/access-denied",
];

const isPrivatePath = (pathname) =>
  privatePrefixes.some((prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`));

const allowedPaths = staticPaths.filter((pathname) => {
  if (!isPrivatePath(pathname)) return true;
  console.warn(`Sitemap: melewati rute privat ${pathname}`);
  return false;
});

const urls = new Set(allowedPaths.map((path) => `${siteUrl}${path}`));

const catalogCandidates = [
  process.env.SEO_PROGRAM_API,
  process.env.VITE_API_BASE_URL ? `${process.env.VITE_API_BASE_URL.replace(/\/+$/, "")}/program-groups` : null,
  "http://127.0.0.1:8000/api/program-groups",
].filter(Boolean);
let catalog = null;
for (const endpoint of [...new Set(catalogCandidates)]) {
  try {
    const response = await fetch(endpoint, { headers: { Accept: "application/json" }, signal: AbortSignal.timeout(2500) });
    if (!response.ok) continue;
    catalog = await response.json();
    break;
  } catch {
    // Build tetap dapat berjalan saat backend lokal tidak aktif. Deployment harus
    // mengisi SEO_PROGRAM_API agar seluruh profil mapel masuk sitemap produksi.
  }
}

if (catalog) {
    const subjects = Array.isArray(catalog?.subject_options)
      ? catalog.subject_options
      : (Array.isArray(catalog?.data) ? catalog.data.flatMap((group) => group.subjects || [])
        : (Array.isArray(catalog) ? catalog.flatMap((group) => group.subjects || []) : []));
    for (const subject of subjects) {
      if (!subject?.name) continue;
      const slug = subject.name.normalize("NFKD").replace(/[\u0300-\u036f]/g, "").toLocaleLowerCase("id-ID").replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "");
      const levels = (subject.education_levels || []).filter((level) => ["SD", "SMP", "SMA", "Umum"].includes(level));
      for (const level of levels.length ? levels : ["Umum"]) {
        urls.add(`${siteUrl}/program/${slug}?level=${encodeURIComponent(level)}`);
      }
    }
} else {
  // Build frontend juga dijalankan saat Laragon/backend sedang mati. Daftar
  // mapel bawaan tetap tersedia dari seeder tanpa perlu membuka database.
  try {
    const backendDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../bimbelku-backend");
    const php = String.raw`require "vendor/autoload.php"; $seeder = new \Database\Seeders\CurriculumCatalogSeeder(); $reflection = new \ReflectionClass($seeder); $result = []; foreach (["subjects", "flexibleSubjects"] as $methodName) { foreach ($reflection->getMethod($methodName)->invoke($seeder) as $subject) { $levels = $subject["levels"] ?? array_values(array_unique(array_map(fn ($grade) => $grade <= 6 ? "SD" : ($grade <= 9 ? "SMP" : "SMA"), $subject["grades"]))); $result[] = ["name" => $subject["name"], "education_levels" => $levels]; } } echo json_encode($result, JSON_UNESCAPED_UNICODE);`;
    const fallbackSubjects = JSON.parse(execFileSync("php", ["-r", php], { cwd: backendDir, encoding: "utf8", timeout: 5_000 }));
    for (const subject of fallbackSubjects) {
      const slug = subject.name.normalize("NFKD").replace(/[\u0300-\u036f]/g, "").toLocaleLowerCase("id-ID").replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "");
      for (const level of subject.education_levels) urls.add(`${siteUrl}/program/${slug}?level=${encodeURIComponent(level)}`);
    }
    console.warn("Katalog API tidak terjangkau; sitemap memakai mapel bawaan dari seeder. Isi SEO_PROGRAM_API untuk data aktif produksi.");
  } catch {
    console.warn("Katalog program dan seeder tidak terjangkau; sitemap statis tetap dibuat. Isi SEO_PROGRAM_API saat build deployment.");
  }
}

// Only emit lastmod when the actual page content timestamp is known. A build
// date on every URL would claim that unchanged pages were updated today.
const finalUrls = [...urls].filter((url) => !isPrivatePath(url.slice(siteUrl.length).split(/[?#]/)[0]));
const xml = `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${finalUrls.map((url) => `  <url><loc>${url.replace(/&/g, "&amp;")}</loc></url>`).join("\n")}\n</urlset>\n`;
writeFileSync(new URL("../public/sitemap.xml", import.meta.url), xml, "utf8");
const robotsPath = new URL("../public/robots.txt", import.meta.url);
const robots = readFileSync(robotsPath, "utf8").replace(/^Sitemap:.*(?:\r?\n)?/gim, "").trimEnd();
writeFileSync(robotsPath, `${robots}\n\nSitemap: ${siteUrl}/sitemap.xml\n`, "utf8");
console.log(`Sitemap dibuat: ${urls.size} URL untuk ${siteUrl}`);
