import { Suspense, lazy, useEffect, useState } from "react";
import { BrowserRouter, Navigate, Routes, Route, useLocation } from "react-router-dom";

// --- IMPORT KOMPONEN KEAMANAN & GLOBAL (Tetap Import Biasa) ---
import { ConfirmDialogProvider } from "./components/ConfirmDialogProvider";
import SessionLifecycle from "./components/SessionLifecycle";
import DeferredToaster from "./components/DeferredToaster";
import { WebsiteContentProvider } from "./components/WebsiteContentProvider";
import { useSessionReady } from "./hooks/useSessionReady";
import RouteSeoManager from "./components/RouteSeoManager";

// =================================================================
// KONFIGURASI LAZY LOAD (Code Splitting)
// Halaman hanya akan didownload saat user membukanya
// =================================================================

const CURRENT_PATH = window.location.pathname.replace(/\/+$/, "") || "/";

const exactPath = (...paths: string[]) => (path: string) => paths.includes(path);
const pathPattern = (pattern: RegExp) => (path: string) => pattern.test(path);

const routeLazy = <T extends React.ComponentType<any>>(
  matchesCurrentRoute: (path: string) => boolean,
  importer: () => Promise<{ default: T }>,
) => {
  // Hanya chunk route yang sedang dibuka yang dimulai seawal mungkin.
  // Route lain tetap lazy sehingga Student tidak mengunduh halaman Admin/Teacher.
  const preloadedModule = matchesCurrentRoute(CURRENT_PATH) ? importer() : null;
  return lazy(() => preloadedModule ?? importer());
};

const Index = routeLazy(exactPath("/"), () => import("./pages/Index"));

// Guard role tidak diperlukan oleh halaman publik. Muat hanya ketika kelompok
// rute terlindungi tersebut benar-benar dirender.
const PrivateRoute = lazy(() => import("./components/PrivateRoute"));
const StudentPackageRoute = lazy(() => import("./components/StudentPackageRoute"));
const GuestPackageRoute = lazy(() => import("./components/GuestPackageRoute"));
const GuestOrderStatus = routeLazy(pathPattern(/^\/pesanan\/[^/]+$/), () => import("./pages/GuestOrderStatus"));

// 1. Halaman Umum
const NotFound = lazy(() => import("./pages/NotFound"));
const WhyUs = routeLazy(exactPath("/why-us"), () => import("./pages/WhyUs"));
const ProgramCatalog = routeLazy(exactPath("/program"), () => import("./pages/ProgramCatalogGrouped"));
const Testimonials = routeLazy(exactPath("/testimonials"), () => import("./pages/Testimonials"));
const TutorCatalog = routeLazy(exactPath("/tutor"), () => import("./pages/TutorCatalog"));
const ServiceAreasPage = routeLazy(exactPath("/area-layanan"), () => import("./pages/ServiceAreasPage"));
const TutorSelection = routeLazy(exactPath("/seleksi-tutor"), () => import("./pages/TutorSelection"));
const ProgramDetail = routeLazy(pathPattern(/^\/program\/[^/]+$/), () => import("./pages/ProgramDetail"));
const LearningSystemPage = routeLazy(pathPattern(/^\/cara-belajar(?:\/[^/]+)?$/), () => import("./pages/LearningSystemPage"));
const AccessDenied = routeLazy(exactPath("/access-denied"), () => import("./pages/AccessDenied"));

// 2. Halaman Rules
const PrivacyPolicy = routeLazy(exactPath("/privacy"), () => import("./pages/rules/PrivacyPolicy"));
const TermsConditions = routeLazy(exactPath("/terms"), () => import("./pages/rules/TermsConditions"));

// 3. Halaman Autentikasi
const Register = routeLazy(exactPath("/register"), () => import("./pages/Register"));
const JadiTutor = routeLazy(exactPath("/jadi-tutor"), () => import("./pages/JadiTutor"));
const Login = routeLazy(exactPath("/login"), () => import("./pages/Login"));
const AdminLogin = routeLazy(exactPath("/admin/login"), () => import("./pages/AdminLogin"));
const ForgotPassword = routeLazy(exactPath("/forgot-password"), () => import("./pages/ForgotPassword"));
const ResetPassword = routeLazy(exactPath("/reset-password"), () => import("./pages/ResetPassword"));
const ResetPaymentPin = routeLazy(exactPath("/reset-pin"), () => import("./pages/ResetPaymentPin"));
const GoogleAuthCallback = routeLazy(exactPath("/oauth/google/callback"), () => import("./pages/GoogleAuthCallback"));
const CompleteGoogleProfile = routeLazy(exactPath("/complete-profile"), () => import("./pages/CompleteGoogleProfile"));

const VerifyEmail = routeLazy(exactPath('/verify-email'), () => import('./pages/VerifyEmail'));

// 4. Halaman Admin
const DashboardOverview = routeLazy(exactPath("/admin"), () => import("./pages/admin/DashboardOverview"));
const AdminProfile = routeLazy(exactPath("/admin/profile"), () => import("./pages/admin/AdminProfile"));
const TutorSearchMonitoring = routeLazy(exactPath("/admin/tutor-searches"), () => import("./pages/admin/TutorSearchMonitoring"));
const TeacherVerification = routeLazy(exactPath("/admin/guru"), () => import("./pages/admin/TeacherVerification"));
const UserManagement = routeLazy(exactPath("/admin/users"), () => import("./pages/admin/UserManagement"));
const FinanceCenter = routeLazy(exactPath("/admin/finance"), () => import("./pages/admin/FinanceCenter"));
const EditFooter = routeLazy(exactPath("/admin/settings-footer"), () => import("./pages/admin/EditFooter"));
const PublicWebsiteSettings = routeLazy(exactPath("/admin/website"), () => import("./pages/admin/PublicWebsiteSettings"));
const WebsiteMediaSettings = routeLazy(exactPath("/admin/website-media"), () => import("./pages/admin/WebsiteMediaSettings"));
const PublicTutorDirectoryAdmin = routeLazy(exactPath("/admin/public-tutors"), () => import("./pages/admin/PublicTutorDirectoryAdmin"));
const SendMessage = routeLazy(exactPath("/admin/notifikasi"), () => import("./pages/admin/SendMessage"));
const ClassMonitoring = routeLazy(exactPath("/admin/classes"), () => import("./pages/admin/ClassMonitoring"));
const ClassDetail = routeLazy(pathPattern(/^\/admin\/classes\/[^/]+$/), () => import("./pages/admin/ClassDetail"));
const AdminNotes = routeLazy(exactPath("/admin/notes"), () => import("./pages/admin/AdminNotes"));
const SettingsDisplay = routeLazy(exactPath("/admin/settings-display"), () => import("./pages/admin/SettingsDisplay"));
const AdminRatings = routeLazy(exactPath("/admin/ratings"), () => import("./pages/admin/AdminRatings"));
const HourlyRates = routeLazy(exactPath("/admin/hourly-rates"), () => import("./pages/admin/HourlyRates"));
const Chapters = routeLazy(exactPath("/admin/chapters"), () => import("./pages/admin/Chapters"));
const SubjectManagement = routeLazy(exactPath("/admin/subjects"), () => import("./pages/admin/SubjectManagement"));
const SubjectPageContentEditor = routeLazy(pathPattern(/^\/admin\/subjects\/\d+\/page$/), () => import("./pages/admin/SubjectPageContentEditor"));
const CaseCenter = routeLazy(exactPath("/admin/cases"), () => import("./pages/admin/CaseCenter"));
const StageFiveManagement = routeLazy(exactPath("/admin/stage-five"), () => import("./pages/admin/StageFiveManagement"));
const AdminAuditLog = routeLazy(exactPath("/admin/audit-log"), () => import("./pages/admin/AdminAuditLog"));
const CheapClassManagement = routeLazy(exactPath("/admin/kelas-murah"), () => import("./pages/admin/CheapClassManagement"));
const CheapClassSchedule = routeLazy(exactPath("/admin/kelas-murah/jadwal"), () => import("./pages/admin/CheapClassSchedule"));
const CheapClassRecurring = routeLazy(exactPath("/admin/kelas-murah/berulang"), () => import("./pages/admin/CheapClassRecurring"));

// 5. Halaman Guru
const TeacherDashboard = routeLazy(exactPath("/guru"), () => import("./pages/teacher/TeacherDashboard"));
const ManageClasses = routeLazy(exactPath("/guru/kelas"), () => import("./pages/teacher/ManageClasses"));
const TeacherProfile = routeLazy(exactPath("/guru/profil"), () => import("./pages/teacher/TeacherProfile"));
const TeacherSalary = routeLazy(exactPath("/guru/dompet"), () => import("./pages/teacher/TeacherSalary"));
const TeacherPayoutDestination = routeLazy(exactPath("/guru/pencairan/rekening"), () => import("./pages/teacher/TeacherPayoutDestination"));
const ManageSchedule = routeLazy(exactPath("/guru/jadwal"), () => import("./pages/teacher/ManageSchedule"));
const BookingGuru = routeLazy(exactPath("/guru/permintaan"), () => import("./pages/teacher/BookingGuru"));
const TeacherMessages = routeLazy(exactPath("/guru/pesan"), () => import("./pages/teacher/TeacherMessages"));
const TeacherAccount = routeLazy(exactPath("/guru/saya"), () => import("./pages/teacher/TeacherAccount"));
const TeacherPerformance = routeLazy(exactPath("/guru/performa"), () => import("./pages/teacher/TeacherPerformance"));
const TeacherNotifications = routeLazy(exactPath("/guru/notifikasi"), () => import("./pages/teacher/TeacherNotifications"));
const TeacherLearningProgressDetail = routeLazy(pathPattern(/^\/guru\/progress\/package-subject\/[^/]+$/), () => import("./pages/teacher/TeacherLearningProgressDetail"));

// 6. Halaman Murid
const Dashboard = routeLazy(exactPath("/student/dashboard"), () => import("./pages/students/Dashboard"));
const MyClasses = routeLazy(exactPath("/student/my-classes"), () => import("./pages/students/MyClasses"));
const PackageProcess = routeLazy(exactPath("/student/packages"), () => import("./pages/students/PackageProcess"));
const TransactionHistory = routeLazy(exactPath("/student/history"), () => import("./pages/students/TransactionHistory"));
const Profile = routeLazy(exactPath("/student/profile"), () => import("./pages/students/Profile"));
const Account = routeLazy(exactPath("/student/account"), () => import("./pages/students/Account"));
const Settings = routeLazy(exactPath("/student/settings"), () => import("./pages/students/Settings"));
const PackageBuilder = routeLazy(exactPath("/student/packages/new", "/search", "/student/find", "/pesan"), () => import("./pages/students/PackageBuilder"));
const PackageReschedule = routeLazy(pathPattern(/^\/student\/packages\/[^/]+\/reschedule$/), () => import("./pages/students/PackageReschedule"));
const Vouchers = routeLazy(exactPath("/student/vouchers"), () => import("./pages/students/Vouchers"));
const PromotionDetail = routeLazy(pathPattern(/^\/student\/offers\/[^/]+$/), () => import("./pages/students/PromotionDetail"));
const Messages = routeLazy(exactPath("/student/messages"), () => import("./pages/students/Messages"));
const LearningProgress = routeLazy(exactPath("/student/progress"), () => import("./pages/students/LearningProgress"));
const LearningProgressDetail = routeLazy(pathPattern(/^\/student\/progress\/(?:package|cheap-class)\/[^/]+$/), () => import("./pages/students/LearningProgressDetail"));
const StudentNotifications = routeLazy(exactPath("/student/notifications"), () => import("./pages/students/Notifications"));
const CheapClasses = routeLazy(exactPath("/student/kelas-murah"), () => import("./pages/students/CheapClasses"));

// 7. Payment
const PaymentPage = routeLazy(exactPath("/payment", "/payment-preview"), () => import("./pages/pembayaran/PaymentPage"));

// 8. Halaman Common (Bantuan)
const StudentHelp = routeLazy(exactPath("/student/help"), () => import("./pages/students/Help"));
const TeacherHelp = routeLazy(exactPath("/guru/bantuan"), () => import("./pages/teacher/Help"));
const PendingPaymentPopup = lazy(() => import("./components/PendingPaymentPopup"));
const SessionActionReminder = lazy(() => import("./components/SessionActionReminder"));
const FilePreviewProvider = lazy(() => import("./components/FilePreviewProvider"));

const DeferredFilePreviewProvider = () => {
  const [ready, setReady] = useState(false);

  useEffect(() => {
    const activate = () => setReady(true);
    window.addEventListener("bimbelku:file-preview-needed", activate, { once: true });

    return () => {
      window.removeEventListener("bimbelku:file-preview-needed", activate);
    };
  }, []);

  return ready ? (
    <Suspense fallback={null}>
      <FilePreviewProvider />
    </Suspense>
  ) : null;
};

const RouteScrollReset = () => {
  const { pathname, hash } = useLocation();

  useEffect(() => {
    if (hash) return;
    window.scrollTo({ top: 0, left: 0, behavior: "auto" });
  }, [pathname, hash]);

  return null;
};

const LegacyPackageRedirect = () => {
  const location = useLocation();
  const legacyParams = new URLSearchParams(location.search);
  const subjectName = legacyParams.get("subject_name") || legacyParams.get("subject");

  legacyParams.delete("subject");
  if (subjectName) legacyParams.set("subject_name", subjectName);

  const query = legacyParams.toString();
  return <Navigate to={`/student/packages/new${query ? `?${query}` : ""}`} replace />;
};

const LegacyTeacherCheapClassRedirect = () => {
  const location = useLocation();
  const params = new URLSearchParams(location.search);
  params.set("class_kind", "group");
  return <Navigate to={`/guru/kelas?${params.toString()}`} replace />;
};

const StudentRuntime = () => {
  // Widget pembayaran hanya dipasang setelah sesi murid benar-benar siap.
  // Halaman login/transisi OAuth tidak boleh memicu request latar yang masih
  // memakai token lama.
  const ready = useSessionReady(["student"]);

  return ready ? (
    <Suspense fallback={null}>
      <PendingPaymentPopup />
    </Suspense>
  ) : null;
};


const SessionActionRuntime = () => {
  const ready = useSessionReady(["student", "teacher", "admin"]);

  return ready ? (
    <Suspense fallback={null}>
      <SessionActionReminder />
    </Suspense>
  ) : null;
};

// --- KOMPONEN LOADING PAGE ---
const PageLoader = () => (
  <div
    role="status"
    aria-live="polite"
    className="flex h-dvh w-full flex-col items-center justify-center bg-gradient-to-br from-orange-50 via-white to-indigo-50"
  >
    <div className="relative grid h-20 w-20 place-items-center rounded-3xl bg-slate-950 text-white shadow-2xl shadow-indigo-200">
      <div className="absolute inset-0 animate-ping rounded-3xl border border-indigo-300/40 motion-reduce:animate-none" />
      <span aria-hidden="true" className="relative text-2xl font-black text-orange-300">B</span>
    </div>
    <p className="mt-5 text-sm font-black text-slate-700">Menyiapkan BimbelKu</p>
    <div className="mt-3 h-1.5 w-36 overflow-hidden rounded-full bg-slate-200">
      <div className="h-full w-2/3 animate-pulse rounded-full bg-gradient-to-r from-orange-500 to-indigo-600 motion-reduce:animate-none" />
    </div>
  </div>
);

export const AppContent = () => (
  <ConfirmDialogProvider>
        {/* Toast dimuat setelah konten awal selesai atau saat pertama kali dibutuhkan. */}
        <DeferredToaster />
        <DeferredFilePreviewProvider />
        
        <RouteScrollReset />
        <RouteSeoManager />
        <SessionLifecycle />
        
        {/* === GLOBAL COMPONENTS === */}
        <StudentRuntime />
        <SessionActionRuntime />

        {/* Suspense Wajib Ada untuk Lazy Loading */}
        <Suspense fallback={<PageLoader />}>
          <Routes>
            
            {/* =========================================
                1. RUTE PUBLIK 
               ========================================= */}
            <Route path="/" element={<WebsiteContentProvider><Index /></WebsiteContentProvider>} />
            <Route path="/program" element={<WebsiteContentProvider><ProgramCatalog /></WebsiteContentProvider>} />
            <Route path="/testimonials" element={<WebsiteContentProvider><Testimonials /></WebsiteContentProvider>} />
            <Route path="/tutor" element={<WebsiteContentProvider><TutorCatalog /></WebsiteContentProvider>} />
            <Route path="/area-layanan" element={<WebsiteContentProvider><ServiceAreasPage /></WebsiteContentProvider>} />
            <Route path="/seleksi-tutor" element={<WebsiteContentProvider><TutorSelection /></WebsiteContentProvider>} />
            <Route path="/program/:slug" element={<WebsiteContentProvider><ProgramDetail /></WebsiteContentProvider>} />
            <Route path="/cara-belajar" element={<WebsiteContentProvider><LearningSystemPage /></WebsiteContentProvider>} />
            <Route path="/cara-belajar/pemesanan-dan-matching" element={<Navigate to="/cara-belajar/sistem-matching-tutor" replace />} />
            <Route path="/cara-belajar/:slug" element={<WebsiteContentProvider><LearningSystemPage /></WebsiteContentProvider>} />
            <Route path="/pesanan/:code" element={<WebsiteContentProvider><GuestOrderStatus /></WebsiteContentProvider>} />
            <Route path="/register" element={<WebsiteContentProvider><Register /></WebsiteContentProvider>} />
            <Route path="/jadi-tutor" element={<WebsiteContentProvider><JadiTutor /></WebsiteContentProvider>} />
            <Route path="/login" element={<WebsiteContentProvider><Login /></WebsiteContentProvider>} />
            <Route path="/admin/login" element={<AdminLogin />} />
            <Route path="/forgot-password" element={<ForgotPassword />} />
            <Route path="/reset-password" element={<ResetPassword />} />
            <Route path="/reset-pin" element={<ResetPaymentPin />} />
            <Route path="/oauth/google/callback" element={<GoogleAuthCallback />} />
            <Route path="/complete-profile" element={<CompleteGoogleProfile />} />
            

            <Route path="/privacy" element={<PrivacyPolicy />} />
            <Route path="/terms" element={<TermsConditions />} />
            <Route path="/why-us" element={<WebsiteContentProvider><WhyUs /></WebsiteContentProvider>} />
            <Route path="/access-denied" element={<AccessDenied />} />
            {import.meta.env.VITE_PAYMENT_DESIGN_PREVIEW === "true" && ["localhost", "127.0.0.1"].includes(window.location.hostname) && <Route path="/payment-preview" element={<PaymentPage />} />}

            <Route path={'/verify-email'} element={<VerifyEmail />} />

            {/* =========================================
                2. RUTE ADMIN (Role: admin)
               ========================================= */}
            <Route element={<PrivateRoute allowedRoles={['admin']} />}>
              <Route path="/admin" element={<DashboardOverview />} />
              <Route path="/admin/profile" element={<AdminProfile />} />
              <Route path="/admin/tutor-searches" element={<TutorSearchMonitoring />} />
              <Route path="/admin/guru" element={<TeacherVerification />} />
              <Route path="/admin/pembayaran" element={<Navigate to="/admin/finance?tab=payments" replace />} />
              <Route path="/admin/users" element={<UserManagement />} />
              <Route path="/admin/settings-payment" element={<Navigate to="/admin/finance?tab=payments" replace />} />
              <Route path="/admin/settings-footer" element={<EditFooter />} />
              <Route path="/admin/website" element={<PublicWebsiteSettings />} />
              <Route path="/admin/website-media" element={<WebsiteMediaSettings />} />
              <Route path="/admin/public-tutors" element={<PublicTutorDirectoryAdmin />} />
              <Route path="/admin/finance" element={<FinanceCenter />} />
              <Route path="/admin/refunds" element={<Navigate to="/admin/finance?tab=refunds" replace />} />
              <Route path="/admin/finance-security" element={<Navigate to="/admin/pembayaran" replace />} />
              <Route path="/admin/pesan" element={<Navigate to="/admin" replace />} />
              <Route path="/admin/notifikasi" element={<SendMessage />} /> 
              <Route path="/admin/classes" element={<ClassMonitoring />} />
              <Route path="/admin/classes/:id" element={<ClassDetail />} />
              <Route path="/admin/notes" element={<AdminNotes />} />
              <Route path="/admin/settings-display" element={<SettingsDisplay />} />
              <Route path="/admin/ratings" element={<AdminRatings />} />
              <Route path="/admin/hourly-rates" element={<HourlyRates />} />
              <Route path="/admin/chapters" element={<Chapters />} />
              <Route path="/admin/subjects" element={<SubjectManagement />} />
              <Route path="/admin/subjects/:id/page" element={<SubjectPageContentEditor />} />
              <Route path="/admin/programs" element={<Navigate to="/admin/subjects#programs" replace />} />
              <Route path="/admin/cases" element={<CaseCenter />} />
              <Route path="/admin/stage-five" element={<StageFiveManagement />} />
              <Route path="/admin/access-control" element={<Navigate to="/admin" replace />} />
              <Route path="/admin/audit-log" element={<AdminAuditLog />} />
              <Route path="/admin/kelas-murah" element={<CheapClassManagement />} />
              <Route path="/admin/kelas-murah/jadwal" element={<CheapClassSchedule />} />
              <Route path="/admin/kelas-murah/berulang" element={<CheapClassRecurring />} />
            </Route>

            {/* =========================================
                3. RUTE GURU (Role: teacher)
               ========================================= */}
            <Route element={<PrivateRoute allowedRoles={['teacher']} />}>
              <Route path="/guru" element={<TeacherDashboard />} />
              <Route path="/guru/kelas" element={<ManageClasses />} />
              <Route path="/guru/profil" element={<TeacherProfile />} />
              <Route path="/guru/jadwal" element={<ManageSchedule />} />
              <Route path="/guru/permintaan" element={<BookingGuru />} />
              <Route path="/guru/dompet" element={<TeacherSalary />} />
              <Route path="/guru/pencairan/rekening" element={<TeacherPayoutDestination />} />
              <Route path="/guru/rekening" element={<Navigate to="/guru/pencairan/rekening" replace />} />
              <Route path="/guru/gaji" element={<Navigate to="/guru/dompet" replace />} />
              <Route path="/guru/pesan" element={<TeacherMessages />} />
              <Route path="/guru/saya" element={<TeacherAccount />} />
              <Route path="/guru/performa" element={<TeacherPerformance />} />
              <Route path="/guru/notifikasi" element={<TeacherNotifications />} />
              <Route path="/guru/kelas-murah" element={<LegacyTeacherCheapClassRedirect />} />
              <Route path="/guru/progress/package-subject/:id" element={<TeacherLearningProgressDetail />} />
              
              <Route path="/guru/bantuan" element={<TeacherHelp />} />
              <Route path="/guru/help" element={<Navigate to="/guru/bantuan" replace />} />
            </Route>

            {/* Alias lama selalu dinormalisasi lebih dulu ke URL Paket Baru.
                Setelah URL canonical terbentuk, guard /student/packages/new yang
                menentukan apakah akun boleh membuat Paket Belajar. */}
            <Route path="/search" element={<LegacyPackageRedirect />} />
            <Route path="/student/find" element={<LegacyPackageRedirect />} />

            {/* Jalur pembelian Paket Belajar mempunyai guard khusus:
                student boleh masuk, sedangkan admin/tutor mendapat penjelasan ramah
                alih-alih diarahkan ke halaman Access Denied. */}
            <Route element={<GuestPackageRoute />}><Route path="/pesan" element={<WebsiteContentProvider><PackageBuilder /></WebsiteContentProvider>} /></Route>
            <Route element={<StudentPackageRoute />}>
              <Route path="/student/packages/new" element={<PackageBuilder />} />
            </Route>

            {/* =========================================
                4. RUTE MURID (Role: student)
               ========================================= */}
            <Route element={<PrivateRoute allowedRoles={['student']} />}>
              <Route path="/student/dashboard" element={<Dashboard />} />
              <Route path="/murid" element={<Dashboard />} />
              <Route path="/student/packages" element={<PackageProcess />} />
              <Route path="/student/my-classes" element={<MyClasses />} />
              <Route path="/student/messages" element={<Messages />} />
              <Route path="/student/progress" element={<LearningProgress />} />
              <Route path="/student/progress/:kind/:id" element={<LearningProgressDetail />} />
              <Route path="/student/notifications" element={<StudentNotifications />} />
              <Route path="/student/kelas-murah" element={<CheapClasses />} />
              <Route path="/student/history" element={<TransactionHistory />} />
              <Route path="/student/profile" element={<Profile />} />
              <Route path="/student/account" element={<Account />} />
              <Route path="/student/settings" element={<Settings />} />
              <Route path="/student/packages/:id/reschedule" element={<PackageReschedule />} />
              <Route path="/student/vouchers" element={<Vouchers />} />
              <Route path="/student/offers/:id" element={<PromotionDetail />} />
              
              <Route path="/student/help" element={<StudentHelp />} />
              <Route path="/student/bantuan" element={<Navigate to="/student/help" replace />} />
              
              <Route path="/payment" element={<PaymentPage />} />
            </Route>

            {/* =========================================
                5. RUTE 404 
               ========================================= */}
            <Route path="*" element={<NotFound />} />

          </Routes>
        </Suspense>
  </ConfirmDialogProvider>
);

const App = () => <BrowserRouter><AppContent /></BrowserRouter>;

export default App;
