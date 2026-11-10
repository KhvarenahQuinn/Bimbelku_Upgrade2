import fs from "node:fs";
import path from "node:path";

const read = (file) => fs.readFileSync(path.resolve(file), "utf8");
const expect = (condition, message) => {
  if (!condition) throw new Error(`Navigation attention gagal: ${message}`);
  console.log(`PASS ${message}`);
};

const helper = read("src/lib/navigationAttention.ts");
const student = read("src/components/StudentLayout.tsx");
const studentClasses = read("src/pages/students/MyClasses.tsx");
const packageProcess = read("src/pages/students/PackageProcess.tsx");
const teacher = read("src/components/TeacherLayout.tsx");
const teacherClasses = read("src/pages/teacher/ManageClasses.tsx");
const admin = read("src/components/AdminLayout.tsx");
const mobile = read("src/components/MobileBottomNav.tsx");
const center = read("src/components/NotificationCenter.tsx");
const notificationController = read("bimbelku-backend/app/Http/Controllers/Api/NotificationController.php");
const apiRoutes = read("bimbelku-backend/routes/api.php");
const auth = read("bimbelku-backend/app/Http/Controllers/Api/AuthController.php");
const order = read("bimbelku-backend/app/Http/Controllers/Api/OrderController.php");
const checkout = read("bimbelku-backend/app/Services/PackageCheckoutService.php");
const matching = read("bimbelku-backend/app/Services/TeacherMatchingService.php");
const adminController = read("bimbelku-backend/app/Http/Controllers/Api/AdminController.php");

expect(helper.includes("unreadIdsForCurrentPage") && helper.includes("hasSidebarAttention") && helper.includes("hasMobileAttention"), "helper pemetaan indikator tersedia");
expect(helper.includes('role === "student"') && helper.includes('role === "teacher"'), "pemetaan role murid dan tutor tersedia");
expect(student.includes("attentionNotifications") && student.includes('hasSidebarAttention("student"'), "layout murid memakai indikator unread di sidebar");
expect(helper.includes("return targetGroup.key === navGroup.key"), "sidebar desktop membedakan indikator proses pesanan dan jadwal belajar");
expect(studentClasses.includes("unreadIdsForStudentClassTab") && studentClasses.includes('/notifications/read-batch'), "jadwal belajar membaca indikator sesuai tab aktif");
expect(packageProcess.includes("unreadIdsForStudentClassTab") && packageProcess.includes('/notifications/read-batch'), "proses pesanan membaca indikator proses tanpa memengaruhi jadwal");
expect(teacher.includes("attentionNotifications") && teacher.includes('hasSidebarAttention("teacher"'), "layout tutor memakai indikator unread di sidebar");
expect(teacherClasses.includes('/notifications/read-batch'), "halaman kelas tutor menandai pembaruan kelas yang sudah dibaca");
expect(admin.includes("attentionNotifications") && admin.includes('unreadIdsForCurrentPage("admin"'), "layout admin memakai indikator unread dan auto-read halaman");
expect(mobile.includes("hasMobileAttention") && mobile.includes("bg-rose-500"), "bottom navigation murid/tutor menampilkan titik merah");
expect(admin.includes("hasAdminMobileMenuAttention") && admin.includes("bg-rose-500"), "bottom navigation admin dan Menu menampilkan titik merah");
expect(notificationController.includes("attention_notifications") && notificationController.includes("'attention_key', 'entity_type', 'entity_id'") && notificationController.includes("->whereNotNull('target_url')"), "payload indikator memuat target dan identitas peristiwa");
expect(notificationController.includes("markManyAsRead") && notificationController.includes("where('user_id', $request->user()->id)"), "batch read dibatasi ke pemilik notifikasi");

const batchIndex = apiRoutes.indexOf("/notifications/read-batch");
const allIndex = apiRoutes.indexOf("/notifications/read-all");
const dynamicIndex = apiRoutes.indexOf("/notifications/{id}/read");
expect(batchIndex >= 0 && allIndex >= 0 && dynamicIndex >= 0 && batchIndex < dynamicIndex && allIndex < dynamicIndex, "route notifikasi statis berada sebelum route id dinamis");
expect(apiRoutes.includes("->whereNumber('id')"), "route read satu notifikasi membatasi id numerik");
expect(center.includes("announceNavigationAttentionChanged"), "Pusat Notifikasi menyinkronkan indikator setelah dibaca");

expect(auth.includes("Pendaftaran tutor baru") && auth.includes("'/admin/guru'"), "pendaftaran tutor menyalakan indikator Verifikasi Tutor admin");
expect(
  (order.includes("Bukti pembayaran baru") && order.includes("'/admin/pembayaran'"))
    || (admin.includes("operationalCountForPath") && adminController.includes("'orders' => $pendingOrders")),
  "bukti pembayaran menyalakan indikator Pembayaran admin",
);
expect(matching.includes("Permintaan bimbel baru") && matching.includes("'/guru/permintaan'"), "permintaan baru menyalakan indikator Permintaan Bimbel tutor");
expect(checkout.includes("Pembayaran diterima") && checkout.includes("'/student/packages'"), "pembayaran paket menyalakan indikator Proses Pesanan tanpa memerahkan Jadwal Belajar");

expect(
  helper.includes("teacher_class_changed") && helper.includes("teacher-classes"),
  "key perubahan kelas tutor dipetakan ke menu Kelas Saya",
);
expect(
  helper.includes("student_refund_status_changed") && helper.includes("student-history"),
  "key refund murid dipetakan ke Riwayat Pembayaran",
);
expect(
  notificationController.includes("whereNull('invalidated_at')"),
  "notifikasi tidak valid tidak dihitung sebagai unread",
);

console.log("Navigation attention Revisi 7 lulus pemeriksaan source-level.");
