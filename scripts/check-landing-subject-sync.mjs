import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), "utf8");

const controller = read("bimbelku-backend/app/Http/Controllers/Api/LearningCatalogController.php");
const section = read("src/components/SubjectsSection.tsx");
const packageBuilder = read("src/pages/students/PackageBuilder.tsx");
const privateRoute = read("src/components/auth/RouteGuard.tsx");
const login = read("src/pages/Login.tsx");
const register = read("src/pages/Register.tsx");
const footer = read("src/components/Footer.tsx");
const app = read("src/App.tsx");
const navbar = read("src/components/Navbar.tsx");
const programCatalog = read("src/pages/ProgramCatalogGrouped.tsx");
const programDetail = read("src/pages/ProgramDetail.tsx");

const requirements = [
  [controller, "'landing_subjects' => $this->landingSubjects($catalogSubjects)", "API belum mengirim landing_subjects"],
  [controller, "->where('is_active', true)", "API belum membatasi mapel aktif"],
  [controller, "->values()", "API belum mengirim seluruh mapel aktif pada landing"],
  [section, "response.data.landing_subjects", "landing page belum memakai kontrak landing_subjects"],
  [section, "Tampilkan {Math.min(8, remainingSubjects)} program lagi", "landing page belum menyediakan aksi muat program bertahap"],
  [section, "/student/packages/new?subject_name=", "kartu mapel belum membuka pembuat paket"],
  [packageBuilder, 'searchParams.get("subject_name")', "pembuat paket belum membaca mapel dari landing"],
  [packageBuilder, "requestedSubjectName", "mapel landing belum dipilih pada pembuat paket"],
  [privateRoute, "redirect=", "tujuan pengguna belum dipertahankan saat login"],
  [login, 'requestedRedirect?.startsWith("/")' , "redirect setelah login belum divalidasi"],
  [login, "registerHref", "tujuan mapel belum diteruskan ke pendaftaran"],
  [register, "loginHref", "tujuan mapel belum dikembalikan ke login setelah pendaftaran"],
  [footer, "/student/packages/new?subject_name=Matematika", "link mapel footer belum membuka Paket Baru"],
  [app, 'path="/program"', "rute katalog program publik belum tersedia"],
  [app, 'path="/program/:slug"', "rute detail program publik belum tersedia"],
  [navbar, '["SD", "SMP", "SMA"]', "dropdown jenjang Program belum tersedia"],
  [section, "programPath(subject, subjectLevel)", "kartu landing belum membuka detail program"],
  [programCatalog, 'getCached<ProgramGroup[]>("/program-groups"', "katalog publik belum memakai grup program backend"],
  [programDetail, "packagePath(program, selectedLevel)", "detail program belum membawa prefill ke Paket Builder"],
];

const failures = requirements
  .filter(([source, needle]) => !source.includes(needle))
  .map(([, , message]) => message);
if (controller.includes("->take(24)")) failures.push("API masih membatasi opsi landing menjadi 24 mapel");

if (failures.length) {
  console.error("Sinkronisasi mapel landing gagal:");
  failures.forEach((failure) => console.error(`- ${failure}`));
  process.exit(1);
}

console.log("Kontrak mapel landing, Paket Baru, dan redirect login sudah sinkron.");
