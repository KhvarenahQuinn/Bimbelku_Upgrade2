import { readFileSync } from "node:fs";

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), "utf8");
const expect = (condition, message) => {
  if (!condition) throw new Error(`Kontrak Tahap 6C-D gagal: ${message}`);
};

const routes = read("bimbelku-backend/routes/api.php");
const catalog = read("bimbelku-backend/app/Support/AdminPermissionCatalog.php");
const permissionMiddleware = read("bimbelku-backend/app/Http/Middleware/EnsureAdminPermission.php");
const auditMiddleware = read("bimbelku-backend/app/Http/Middleware/AuditAdminAction.php");
const auditService = read("bimbelku-backend/app/Services/AdminAuditService.php");
const auditController = read("bimbelku-backend/app/Http/Controllers/Api/AdminAccessController.php");
const auditModel = read("bimbelku-backend/app/Models/AdminAuditLog.php");
const userModel = read("bimbelku-backend/app/Models/User.php");
const migration = read("bimbelku-backend/database/migrations/2026_08_01_000600_build_stage_six_c_admin_access_and_audit.php");
const test = read("bimbelku-backend/tests/Feature/StageSixCAccessAuditTest.php");
const app = read("src/App.tsx");
const layout = read("src/components/AdminLayout.tsx");
const privateRoute = read("src/components/auth/RouteGuard.tsx");
const frontendPermissions = read("src/lib/adminPermissions.ts");
const auditPage = read("src/pages/admin/AdminAuditLog.tsx");
const dashboard = read("src/pages/admin/DashboardOverview.tsx");

expect(
  routes.includes("middleware(['role:admin', 'admin.audit', 'admin.permission'])"),
  "seluruh grup admin dilindungi audit dan pemeriksaan akses",
);
expect(routes.includes("Route::get('/audit-log'"), "endpoint audit tetap tersedia");
expect(routes.includes("Route::get('/audit-log/integrity'") && routes.includes("Route::get('/audit-log/{adminAuditLog}'"), "integritas dan detail audit dimuat melalui endpoint terpisah");
expect(auditController.includes("Rule::in([20, 50, 100])") && auditController.includes("formatAuditSummary"), "daftar audit memakai batas pilihan dan respons ringkas");
expect(auditController.includes("auditDetail") && auditController.includes("auditIntegrity"), "snapshot dan pemeriksaan integritas tidak membebani respons daftar");
expect(!routes.includes("Route::get('/access-control'"), "endpoint daftar admin sudah dihapus");
expect(!routes.includes("Route::post('/access-control'"), "endpoint pembuatan admin sudah dihapus");
expect(!routes.includes("Route::put('/access-control/{admin}'"), "endpoint perubahan admin sudah dihapus");

for (const permission of [
  "operations.dashboard", "matching.manage", "finance.payments", "finance.payouts",
  "finance.refunds", "teachers.manage", "cases.manage",
  "users.manage", "classes.manage", "content.manage", "support.manage",
  "settings.manage", "audit.view",
]) expect(catalog.includes(`'${permission}'`), `katalog memuat ${permission}`);

expect(permissionMiddleware.includes("Rute admin ini belum memiliki kebijakan akses"), "rute admin tanpa aturan ditolak fail-closed");
expect(permissionMiddleware.includes("required_admin_permission"), "kode akses diteruskan ke audit");
expect(userModel.includes("isPrimaryAdmin") && userModel.includes("primary_admin_email"), "model menentukan satu admin utama");
expect(userModel.includes("return $this->role === 'admin' && $this->status === 'active';"), "admin aktif memiliki akses modul operasional");

expect(auditMiddleware.includes("DB::transaction") && auditMiddleware.includes("$next($request)"), "mutasi admin dan audit berada dalam transaksi");
expect(auditMiddleware.includes("in_array($request->method(), ['GET', 'HEAD', 'OPTIONS']"), "hanya mutasi yang membuat catatan perubahan");
expect(auditService.includes("previous_hash") && auditService.includes("entry_hash"), "audit memakai rantai hash");
expect(auditService.includes("[REDACTED]") && auditService.includes("account_number"), "data pribadi dan rekening disamarkan");
expect(auditModel.includes("ImmutableFinancialRecord"), "catatan audit tidak dapat diubah atau dihapus melalui model");
expect(migration.includes("admin_audit_logs"), "migrasi audit tersedia");
expect(migration.includes("entry_hash") && migration.includes("previous_hash"), "skema menyimpan rantai integritas");

expect(!app.includes('lazy(() => import("./pages/admin/AdminAccessControl"))'), "halaman pengelolaan admin tidak dimuat");
expect(app.includes('<Route path="/admin/access-control" element={<Navigate to="/admin" replace />} />'), "alamat lama diarahkan ke dashboard");
expect(!layout.includes("Kontrol akses admin"), "menu pengelolaan admin disembunyikan");
expect(layout.includes('admin.is_primary_admin') && layout.includes('? "Admin utama" : "Admin"'), "identitas sidebar membedakan admin utama dan admin biasa");
expect(privateRoute.includes("permissionForAdminPath") && privateRoute.includes("canAdmin"), "rute frontend tetap memeriksa role admin");
// canAdmin kini menegakkan admin_permissions, bukan lagi meloloskan semua admin.
expect(
  frontendPermissions.includes("granted.includes(permission)") && frontendPermissions.includes('user?.role !== "admin"'),
  "frontend menegakkan admin_permissions per halaman",
);
expect(
  auditPage.includes("Catatan perubahan aman")
    && auditPage.includes("Sebelumnya")
    && auditPage.includes("Menjadi")
    && auditPage.includes("Ditambahkan")
    && auditPage.includes("Dihapus"),
  "viewer audit menjelaskan perubahan dengan bahasa yang mudah dipahami",
);
expect(
  !auditPage.includes("Hash entri")
    && !auditPage.includes("Request ID")
    && !auditPage.includes("Jejak permintaan"),
  "detail teknis tidak ditampilkan kepada admin umum",
);
expect(auditPage.includes('type PageSize = "20" | "50" | "100" | "all"') && auditPage.includes("Memuat semua hasil secara bertahap"), "jumlah hasil dapat dipilih dan semua hasil dimuat bertahap");
expect(auditPage.includes("loadingDetails") && auditPage.includes('`/admin/audit-log/${item.id}`'), "rincian perubahan dimuat hanya saat kartu dibuka");
expect(dashboard.includes("visible_sections"), "dashboard tetap menggunakan respons backend");

for (const scenario of [
  "test_active_single_admin_can_access_all_admin_modules",
  "test_admin_management_endpoints_are_not_available",
  "test_inactive_admin_cannot_use_admin_modules",
  "test_admin_mutation_is_recorded_and_hash_chain_is_valid",
  "test_hash_chain_reports_direct_database_tampering",
]) expect(test.includes(scenario), `tes admin tunggal memuat ${scenario}`);

console.log("Kontrak Tahap 6C-D lulus (admin utama dan admin biasa, akses operasional, serta audit tetap aktif).");
