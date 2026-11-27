import { useMemo } from "react";
import { useLocation } from "react-router-dom";
import SeoHead from "@/components/SeoHead";

const privatePath = /^(?:\/admin(?:\/|$)|\/guru(?:\/|$)|\/student(?:\/|$)|\/murid(?:\/|$)|\/payment(?:-preview)?$|\/pesanan\/|\/pesan$|\/login$|\/register$|\/forgot-password$|\/reset-password$|\/reset-pin$|\/verify-email$|\/oauth\/|\/complete-profile$|\/access-denied$)/;

const privateRouteMeta: Array<{ match: RegExp; title: string; description: string }> = [
  { match: /^\/login$/, title: "Masuk | BimbelKu", description: "Masuk ke akun murid atau tutor BimbelKu." },
  { match: /^\/admin\/login$/, title: "Masuk Admin | BimbelKu", description: "Portal masuk administrator BimbelKu." },
  { match: /^\/register$/, title: "Daftar Akun Murid | BimbelKu", description: "Buat akun murid untuk memesan les privat dan kelas bersama di BimbelKu." },
  { match: /^\/forgot-password$/, title: "Lupa Kata Sandi | BimbelKu", description: "Minta tautan pengaturan ulang kata sandi akun BimbelKu." },
  { match: /^\/reset-password$/, title: "Atur Ulang Kata Sandi | BimbelKu", description: "Buat kata sandi baru untuk akun BimbelKu Anda." },
  { match: /^\/reset-pin$/, title: "Atur Ulang PIN Pembayaran | BimbelKu", description: "Buat PIN pembayaran baru untuk transaksi Saldo BimbelKu." },
  { match: /^\/verify-email$/, title: "Verifikasi Email | BimbelKu", description: "Verifikasi alamat email akun BimbelKu Anda." },
  { match: /^\/oauth\//, title: "Autentikasi Google | BimbelKu", description: "Menyelesaikan proses masuk melalui akun Google." },
  { match: /^\/complete-profile$/, title: "Lengkapi Profil Akun | BimbelKu", description: "Setujui ketentuan layanan untuk mulai menggunakan akun BimbelKu." },
  { match: /^\/access-denied$/, title: "Akses Ditolak | BimbelKu", description: "Halaman ini tidak tersedia untuk peran akun Anda." },
  { match: /^\/(?:murid|student\/dashboard)$/, title: "Dashboard Murid | BimbelKu", description: "Ringkasan kelas, pesanan, dan progres belajar murid BimbelKu." },
  { match: /^\/(?:pesan|student\/packages\/new)$/, title: "Susun Paket Belajar | BimbelKu", description: "Pilih mapel, jenjang, mode belajar, dan jadwal bimbingan di BimbelKu." },
  { match: /^\/student\/packages$/, title: "Proses Pesanan | BimbelKu", description: "Pantau status pembayaran dan pencarian tutor pesanan Anda." },
  { match: /^\/student\/my-classes$/, title: "Jadwal Belajar Murid | BimbelKu", description: "Lihat jadwal sesi les privat dan kelas bersama yang aktif." },
  { match: /^\/student\/messages$/, title: "Pesan Murid | BimbelKu", description: "Percakapan dengan tutor untuk kelas aktif Anda." },
  { match: /^\/student\/progress/, title: "Perkembangan Belajar | BimbelKu", description: "Laporan hasil belajar dan progres materi per sesi." },
  { match: /^\/student\/history$/, title: "Riwayat Transaksi & Saldo | BimbelKu", description: "Riwayat pembayaran, pengembalian dana, dan Saldo BimbelKu." },
  { match: /^\/student\/profile$/, title: "Profil Murid | BimbelKu", description: "Kelola data diri, sekolah, dan lokasi kelas tatap muka murid." },
  { match: /^\/student\/account$/, title: "Akun Murid | BimbelKu", description: "Informasi akun dan ringkasan identitas murid BimbelKu." },
  { match: /^\/student\/settings$/, title: "Keamanan Akun Murid | BimbelKu", description: "Pengaturan kata sandi dan PIN pembayaran akun murid." },
  { match: /^\/student\/kelas-murah$/, title: "Kelas Bersama | BimbelKu", description: "Jelajahi dan ikuti paket Kelas Bersama BimbelKu." },
  { match: /^\/student\/vouchers$/, title: "Promo & Voucher | BimbelKu", description: "Daftar penawaran dan voucher belajar yang tersedia untuk murid." },
  { match: /^\/student\/notifications$/, title: "Notifikasi Murid | BimbelKu", description: "Pembaruan pesanan, jadwal, dan pesan dari admin." },
  { match: /^\/student\/help$/, title: "Pusat Bantuan Murid | BimbelKu", description: "Panduan penggunaan layanan dan pengiriman pengaduan murid." },
  { match: /^\/student\//, title: "Ruang Murid | BimbelKu", description: "Halaman akun dan pembelajaran murid BimbelKu." },
  { match: /^\/guru$/, title: "Dashboard Tutor | BimbelKu", description: "Ringkasan jadwal mengajar, permintaan kelas, dan dompet tutor." },
  { match: /^\/guru\/kelas$/, title: "Kelola Kelas Tutor | BimbelKu", description: "Kelola sesi belajar aktif, ruang kelas, dan laporan hasil belajar." },
  { match: /^\/guru\/profil$/, title: "Profil Tutor | BimbelKu", description: "Kelola biodata, dokumen verifikasi, dan kompetensi mengajar tutor." },
  { match: /^\/guru\/jadwal$/, title: "Jadwal Mengajar Tutor | BimbelKu", description: "Atur ketersediaan mingguan dan tanggal tidak tersedia tutor." },
  { match: /^\/guru\/permintaan$/, title: "Permintaan Kelas Tutor | BimbelKu", description: "Tinjau dan tanggapi penawaran kelas masuk untuk tutor." },
  { match: /^\/guru\/dompet$/, title: "Dompet & Pencairan Tutor | BimbelKu", description: "Pantau penghasilan mengajar dan ajukan pencairan dana tutor." },
  { match: /^\/guru\/pencairan\/rekening$/, title: "Rekening Pencairan Tutor | BimbelKu", description: "Kelola rekening bank tujuan pencairan dana tutor." },
  { match: /^\/guru\/pesan$/, title: "Pesan Tutor | BimbelKu", description: "Percakapan dengan murid terkait kelas yang diampu." },
  { match: /^\/guru\/saya$/, title: "Akun Tutor | BimbelKu", description: "Ringkasan status verifikasi dan profil akun tutor BimbelKu." },
  { match: /^\/guru\/keamanan$/, title: "Keamanan Akun Tutor | BimbelKu", description: "Pengaturan kata sandi dan keamanan akun tutor BimbelKu." },
  { match: /^\/guru\/performa$/, title: "Performa Tutor | BimbelKu", description: "Pantau penilaian, kehadiran, dan statistik mengajar tutor." },
  { match: /^\/guru\/notifikasi$/, title: "Notifikasi Tutor | BimbelKu", description: "Pemberitahuan kelas, pencairan, dan info admin untuk tutor." },
  { match: /^\/guru\/bantuan$/, title: "Pusat Bantuan Tutor | BimbelKu", description: "Panduan operasional dan bantuan untuk tutor BimbelKu." },
  { match: /^\/guru\//, title: "Ruang Tutor | BimbelKu", description: "Halaman operasional tutor BimbelKu." },
  { match: /^\/admin$/, title: "Dashboard Admin | BimbelKu", description: "Ringkasan operasional, kelas, keuangan, dan tugas admin BimbelKu." },
  { match: /^\/admin\/users$/, title: "Manajemen Akun Pengguna | BimbelKu", description: "Kelola akun murid, tutor, dan administrator BimbelKu." },
  { match: /^\/admin\/guru$/, title: "Verifikasi Tutor | BimbelKu", description: "Pemeriksaan dokumen, seleksi, dan persetujuan akun tutor." },
  { match: /^\/admin\/finance$/, title: "Pusat Keuangan Admin | BimbelKu", description: "Verifikasi pembayaran, refund, pencairan tutor, dan laporan keuangan." },
  { match: /^\/admin\/classes/, title: "Pemantauan Kelas Admin | BimbelKu", description: "Pantau sesi berjalan, kehadiran, dan status kelas BimbelKu." },
  { match: /^\/admin\/cases$/, title: "Pusat Kasus Admin | BimbelKu", description: "Penanganan laporan sesi, keberatan murid, dan penggantian tutor." },
  { match: /^\/admin\/chapters$/, title: "Materi Kurikulum Admin | BimbelKu", description: "Kelola katalog bab pembelajaran per jenjang dan mata pelajaran." },
  { match: /^\/admin\/subjects/, title: "Manajemen Mata Pelajaran | BimbelKu", description: "Kelola daftar mata pelajaran dan konten halaman program." },
  { match: /^\/admin\/kelas-murah/, title: "Manajemen Kelas Bersama | BimbelKu", description: "Buat dan pantau paket, jadwal, dan kelas berulang Kelas Bersama." },
  { match: /^\/admin\/website/, title: "Pengaturan Website Publik | BimbelKu", description: "Kelola konten halaman publik, media, dan galeri tutor." },
  { match: /^\/admin\/audit-log$/, title: "Log Audit Admin | BimbelKu", description: "Catatan aktivitas dan jejak audit operasional admin BimbelKu." },
  { match: /^\/admin\//, title: "Panel Admin | BimbelKu", description: "Halaman administrasi dan operasional BimbelKu." },
  { match: /^\/pesanan\//, title: "Status Pesanan | BimbelKu", description: "Pantau rincian dan status pesanan paket belajar BimbelKu." },
  { match: /^\/payment(?:-preview)?$/, title: "Pembayaran | BimbelKu", description: "Selesaikan pembayaran pesanan belajar BimbelKu." },
];

function resolvePrivateMeta(pathname: string) {
  const matched = privateRouteMeta.find((entry) => entry.match.test(pathname));
  return {
    title: matched?.title ?? "BimbelKu",
    description: matched?.description ?? "Halaman akun dan operasional BimbelKu.",
    robots: "noindex,nofollow,noarchive",
  };
}

const publicMeta: Record<string, { title: string; description: string }> = {
  "/": {
    title: "Bimbel Privat SD, SMP & SMA di Yogyakarta | BimbelKu",
    description: "Bimbel privat online dan tatap muka untuk SD, SMP, dan SMA di Yogyakarta. Pilih mapel, jelaskan target, jadwal, serta mode belajar sebelum memesan tutor.",
  },
  "/program": {
    title: "Program Bimbel dan Les Privat SD–SMA | BimbelKu",
    description: "Jelajahi program bimbel dan les privat SD, SMP, serta SMA. Buka profil mapel, materi, jenjang, tutor, dan pilihan belajar yang tersedia.",
  },
  "/tutor": {
    title: "Tutor Bimbel Terverifikasi Berdasarkan Bidang | BimbelKu",
    description: "Kenali tutor BimbelKu yang telah melalui pemeriksaan, memberikan izin tampil, dan disetujui admin, dikelompokkan berdasarkan bidang mapel.",
  },
  "/area-layanan": {
    title: "Area Layanan Bimbel Online dan Tatap Muka | BimbelKu",
    description: "Pelajari cakupan belajar online dan cara ketersediaan tutor tatap muka diperiksa berdasarkan alamat, mapel, dan jadwal.",
  },
  "/seleksi-tutor": {
    title: "Bagaimana Tutor BimbelKu Diseleksi | BimbelKu",
    description: "Lihat proses dokumen, tes WhatsApp, persetujuan admin, dan cara tutor dicocokkan dengan kebutuhan belajar murid.",
  },
  "/testimonials": {
    title: "Cerita dan Ulasan Siswa BimbelKu",
    description: "Baca cerita belajar dan ulasan siswa yang dipublikasikan setelah persetujuan serta pemeriksaan admin BimbelKu.",
  },
  "/cara-belajar": {
    title: "Cara Belajar Privat Online dan Tatap Muka | BimbelKu",
    description: "Pahami alur bimbel privat BimbelKu, mulai dari memilih kebutuhan, pembayaran otomatis, pencarian tutor, jadwal, sampai laporan perkembangan.",
  },
  "/why-us": {
    title: "Apa Itu BimbelKu? Cara Belajar, Tutor, dan Pemesanan",
    description: "Kenali BimbelKu lewat 10 jawaban sederhana: siapa yang belajar, pilihan privat atau kelas bersama, cara memilih pelajaran, biaya, tutor, dan jadwal kelas.",
  },
  "/jadi-tutor": {
    title: "Daftar Tutor BimbelKu | Seleksi melalui WhatsApp",
    description: "Pelajari proses pendaftaran dan seleksi tutor BimbelKu. Kandidat tutor menjalani pemeriksaan data dan tes yang dikoordinasikan melalui WhatsApp.",
  },
  "/privacy": { title: "Kebijakan Privasi | BimbelKu", description: "Kebijakan pengelolaan dan perlindungan data pengguna BimbelKu." },
  "/terms": { title: "Syarat dan Ketentuan | BimbelKu", description: "Syarat penggunaan layanan, pemesanan, pembayaran, kelas, dan akun BimbelKu." },
};

const learningLabels: Record<string, string> = {
  "panduan-memilih-program": "Panduan Memilih Program Bimbel",
  "cara-pemesanan": "Cara Memesan Bimbel",
  "sistem-matching-tutor": "Sistem Matching Tutor",
  "pembayaran-dan-refund": "Pembayaran dan Refund",
  "privat-tatap-muka": "Les Privat Tatap Muka",
  "privat-online": "Les Privat Online",
};

export default function RouteSeoManager() {
  const { pathname } = useLocation();
  const metadata = useMemo(() => {
    // These pages provide CMS-aware metadata and structured data themselves.
    if (pathname === "/" || pathname === "/program" || pathname.startsWith("/program/")) return null;
    if (privatePath.test(pathname)) {
      return resolvePrivateMeta(pathname);
    }
    if (pathname.startsWith("/cara-belajar/")) {
      const slug = pathname.split("/").filter(Boolean).at(-1) || "";
      const label = learningLabels[slug] || "Sistem Belajar";
      return {
        title: `${label} | BimbelKu`,
        description: `Pelajari ${label.toLowerCase()} di BimbelKu, termasuk pemesanan, pembayaran, jadwal, dan pencarian tutor.`,
      };
    }
    return publicMeta[pathname] || {
      title: "Halaman Tidak Ditemukan | BimbelKu",
      description: "Halaman yang dicari tidak tersedia di BimbelKu.",
      robots: "noindex,follow",
    };
  }, [pathname]);

  return metadata ? <SeoHead canonicalPath={pathname} {...metadata} /> : null;
}
