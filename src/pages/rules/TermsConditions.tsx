import { Link, useLocation, useNavigate } from "react-router-dom";
import { ArrowLeft, CircleAlert, FileCheck2, Scale } from "lucide-react";

const sections = [
  {
    title: "Akun dan kelayakan pengguna",
    content: (
      <>
        <p>Murid wajib memberikan data yang benar dan menjaga keamanan akun. Pendaftaran murid di bawah 18 tahun wajib disetujui orang tua atau wali. Nama, hubungan, nomor kontak, dan waktu persetujuan wali akan dicatat.</p>
        <p>Tutor bertindak sebagai pengajar independen. Tutor wajib memberikan identitas, foto wajah langsung, ijazah atau bukti kualifikasi, satu mata pelajaran utama, jenjang yang dikuasai, serta jadwal kosong yang sebenarnya. Setelah verifikasi email dan dokumen, calon tutor mengikuti seleksi dan tes yang dikoordinasikan melalui WhatsApp. Akun tutor hanya dapat menerima penawaran mengajar setelah hasil seleksi dicatat dan disetujui admin.</p>
      </>
    ),
  },
  {
    title: "Paket Belajar, pencocokan tutor, dan jadwal",
    content: (
      <>
        <p>Murid menyusun Paket Belajar dengan memilih paket, jenjang, kelas atau tingkat, metode online atau offline, mata pelajaran, bab, alokasi sesi, durasi, dan jadwal. Sistem hanya menerima jadwal yang memenuhi batas waktu pemesanan, slot aktif, masa berlaku paket, dan pemeriksaan bentrok yang berlaku pada saat pemesanan.</p>
        <p>Setelah rincian pesanan dikonfirmasi, sistem membuat tagihan terlebih dahulu. Pencarian tutor untuk Paket Belajar baru dimulai setelah pembayaran dinyatakan diterima oleh sistem. Bagian pembayaran melalui transfer diperiksa admin, sedangkan tagihan yang seluruhnya ditutup dengan Saldo BimbelKu dapat diselesaikan otomatis. Murid tidak memilih tutor dari katalog publik; sistem mengirim penawaran kepada tutor yang memenuhi kompetensi, jenjang, ketersediaan, performa, pemerataan, dan kebutuhan jarak untuk sesi offline.</p>
        <p>Data <em>BookingRequest</em> dapat tetap digunakan secara internal untuk mesin pencocokan Paket Belajar dan untuk menyelesaikan transaksi lama yang masih sah. Jalur pembuatan booking lama tidak lagi menjadi jalur pemesanan baru bagi murid.</p>
      </>
    ),
  },
  {
    title: "Harga, transfer, dan Saldo BimbelKu",
    content: (
      <>
        <p>Tarif ditetapkan admin dan dapat berbeda untuk mode online, offline, privat, atau kelompok. Nilai transaksi dan persentase komisi disimpan saat pemesanan sehingga perubahan tarif berikutnya tidak mengubah transaksi lama.</p>
        <p>Untuk pembayaran melalui transfer, murid mentransfer dana ke rekening yang ditampilkan oleh BimbelKu lalu mengunggah bukti. Bagian pembayaran yang berasal dari transfer belum dianggap diterima sebelum admin mencocokkannya dengan mutasi rekening. Bukti yang ditolak dapat dikirim ulang sebelum batas pembayaran.</p>
        <p>Saldo BimbelKu adalah kredit belajar yang berasal dari refund dan tidak dapat ditarik tunai. Saldo dapat dipakai pada pembayaran Paket Belajar dan Kelas Bersama. Jika saldo menutup seluruh tagihan, pembayaran dapat diselesaikan otomatis oleh sistem; jika hanya menutup sebagian, saldo ditahan untuk pesanan tersebut dan murid cukup mentransfer sisa tagihan. Saldo yang ditahan dikembalikan apabila pembayaran dibatalkan, kedaluwarsa, atau ditolak sesuai status transaksi.</p>
        <p>Jika transaksi yang kemudian direfund sebelumnya dibayar menggunakan Saldo BimbelKu, bagian refund yang berasal dari saldo selalu dikembalikan ke Saldo BimbelKu dan tidak dapat dialihkan ke rekening atau e-wallet. Pada pembayaran campuran saldo + transfer, murid dapat memilih rekening/e-wallet hanya untuk bagian yang semula dibayar melalui transfer; bagian saldo tetap kembali ke saldo. Jika seluruh pembayaran berasal dari Saldo BimbelKu, refund seluruhnya kembali ke Saldo BimbelKu.</p>
        <p>Pembayaran resmi diproses melalui metode yang tersedia di halaman tagihan BimbelKu. Status pembayaran, refund, dan pencairan diperbarui melalui sistem; beberapa metode dapat mengarahkan pengguna ke aplikasi pembayaran untuk konfirmasi. Transaksi langsung di luar alur BimbelKu tidak dilindungi.</p>
      </>
    ),
  },
  {
    title: "Kelas Bersama",
    content: (
      <>
        <p>Kelas Bersama dibuat dan dijadwalkan oleh admin. Murid hanya dapat bergabung ketika pendaftaran terbuka, kuota masih tersedia, jadwal tidak bentrok, dan tutor kelas tersedia. Saat bergabung, kursi ditahan sementara sampai batas pembayaran yang ditampilkan sistem.</p>
        <p>Keikutsertaan menjadi terkonfirmasi setelah pembayaran dinyatakan valid. Pembatalan, pergantian tutor, penutupan pendaftaran, kelas yang dibatalkan, dan kondisi yang memerlukan pengembalian dana mengikuti status transaksi yang tercatat pada sistem. Dana yang sudah dinyatakan valid tetapi harus dikembalikan diproses melalui antrean refund admin.</p>
      </>
    ),
  },
  {
    title: "Kehadiran, bukti, dan keadaan darurat",
    content: (
      <>
        <p>Tutor wajib hadir sesuai jadwal dan mencatat hasil belajar. Pada Session Flow terbaru, tutor menandai kesiapan lalu murid mengonfirmasi kehadiran satu kali; sistem menyimpan waktu mulai, waktu selesai, durasi, dan jejak sesi secara otomatis. Sesi historis dapat tetap memakai mekanisme verifikasi lama untuk menjaga kompatibilitas.</p>
        <p>Ketidakhadiran tutor yang terbukti menghasilkan refund penuh dan dapat berujung pada peringatan, pembatasan menerima kelas, atau penonaktifan akun. Keadaan darurat tutor wajib disertai jenis kejadian, kronologi, waktu, lokasi, dampak, dan bukti yang dapat dipercaya. Refund murid langsung masuk antrean; admin kemudian menentukan validitas laporan dan sanksi tutor.</p>
      </>
    ),
  },
  {
    title: "Penyelesaian, keberatan, dan refund",
    content: (
      <>
        <p>Setelah tutor menyimpan hasil belajar dan mengakhiri sesi, murid diberi batas waktu yang ditampilkan pada aplikasi untuk memilih Sesi Sesuai atau melaporkan masalah. Persetujuan penyelesaian bersifat final dan menutup hak keberatan untuk sesi tersebut. Jika tidak ada jawaban sampai batas waktu, kasus masuk pemeriksaan admin—bukan otomatis disetujui.</p>
        <p>Keberatan harus menjelaskan masalah secara jelas dan dapat dilengkapi bukti. Untuk refund yang telah disetujui, murid memilih tujuan pengembalian dana: Saldo BimbelKu atau metode pembayaran asal. Pilihan dapat diperbarui selama refund masih menunggu proses, sedangkan admin tidak dapat mengganti tujuan refund. Refund akibat tutor tidak ditemukan atau pembatalan sebelum kelas terbentuk diproses otomatis tanpa persetujuan admin. Pembayaran melalui Xendit (QRIS, e-wallet, atau VA) dikembalikan otomatis ke metode pembayaran asal; refund ke rekening/e-wallet manual hanya berlaku untuk pembayaran yang memang tidak memiliki jalur pengembalian otomatis. Bagian yang semula dibayar dari Saldo BimbelKu selalu kembali ke saldo. Refund ke Saldo BimbelKu selesai saat kredit saldo tercatat; refund ke metode pembayaran asal selesai setelah penyedia pembayaran mengonfirmasi pengembalian dana.</p>
      </>
    ),
  },
  {
    title: "Performa, larangan, dan penegakan",
    content: (
      <>
        <p>Rating hanya berasal dari murid yang menyelesaikan sesi dan menjadi rekam mutu tutor, bukan sistem poin atau jaminan memperoleh kelas. Penawaran kelas mempertimbangkan kelayakan, kecocokan jadwal dan lokasi, kesinambungan belajar, pemerataan beban, serta riwayat penawaran; rating dipakai sebagai pembeda akhir ketika kandidat lain setara.</p>
        <p>Dilarang memalsukan identitas atau bukti, mengganggu sistem, melakukan penipuan, menyebarkan materi melanggar hukum, melecehkan pengguna lain, atau menyalahgunakan laporan dan refund. Admin dapat memberi peringatan, pembatasan, penolakan verifikasi, atau penonaktifan akun.</p>
      </>
    ),
  },
];

export default function TermsConditions() {
  const location = useLocation();
  const navigate = useNavigate();

  const goBack = () => {
    const state = location.state as { from?: unknown } | null;
    const from = typeof state?.from === "string" && state.from.startsWith("/") && !state.from.startsWith("//")
      ? state.from
      : null;

    if (from) {
      navigate(from);
      return;
    }
    if (window.history.length > 1) {
      navigate(-1);
      return;
    }

    try {
      const role = JSON.parse(localStorage.getItem("user") || "null")?.role;
      navigate(role === "student" ? "/student/account" : role === "teacher" ? "/guru/saya" : role === "admin" ? "/admin" : "/");
    } catch {
      navigate("/");
    }
  };

  return (
    <div className="min-h-screen bg-slate-100 pb-16 text-slate-600">
      <nav className="sticky top-0 z-40 border-b border-slate-200/70 bg-slate-100/90 backdrop-blur">
        <div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-5">
          <Link to="/" className="text-xl font-black tracking-tight text-slate-950">BimbelKu</Link>
          <button type="button" onClick={goBack} className="inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-bold text-slate-600 hover:bg-white hover:text-slate-950">
            <ArrowLeft size={16} /> Kembali
          </button>
        </div>
      </nav>

      <main className="mx-auto mt-10 max-w-4xl overflow-hidden rounded-[2.5rem] border border-white bg-white px-6 py-10 shadow-xl shadow-slate-200/60 md:px-14 md:py-14">
        <div className="mx-auto max-w-2xl text-center">
          <span className="inline-flex items-center gap-2 rounded-full border border-indigo-100 bg-indigo-50 px-4 py-1.5 text-xs font-bold uppercase tracking-widest text-indigo-700">
            <Scale size={14} /> Versi 15 Agustus 2026
          </span>
          <h1 className="mt-6 text-4xl font-black tracking-tight text-slate-950 md:text-5xl">Syarat & Ketentuan</h1>
          <p className="mt-4 leading-7 text-slate-500">Aturan operasional BimbelKu untuk murid, tutor, dan admin.</p>
        </div>

        <div className="mt-10 flex gap-4 rounded-2xl border border-indigo-100 bg-indigo-50 p-5 text-sm leading-6 text-indigo-950">
          <FileCheck2 className="mt-0.5 shrink-0 text-indigo-600" />
          <p>Dengan membuat akun atau menggunakan layanan, Anda menyetujui ketentuan ini dan Kebijakan Privasi. Simpan bukti transaksi dan gunakan pusat bantuan apabila menemukan data yang tidak sesuai.</p>
        </div>

        <div className="mt-12 space-y-10">
          {sections.map((section, index) => (
            <section key={section.title} className="grid gap-4 md:grid-cols-[3rem_1fr]">
              <span className="grid h-10 w-10 place-items-center rounded-xl bg-slate-950 text-sm font-black text-white">{index + 1}</span>
              <div>
                <h2 className="text-xl font-black text-slate-950">{section.title}</h2>
                <div className="mt-3 space-y-3 text-sm leading-7 text-slate-600">{section.content}</div>
              </div>
            </section>
          ))}
        </div>

        <div className="mt-12 flex gap-4 rounded-2xl border border-amber-100 bg-amber-50 p-5 text-sm leading-6 text-amber-950">
          <CircleAlert className="mt-0.5 shrink-0 text-amber-600" />
          <p>Perubahan ketentuan berlaku pada versi kebijakan berikutnya dan tidak mengubah nilai transaksi yang sudah tercatat. Pertanyaan dapat dikirim melalui Pusat Bantuan di dalam aplikasi.</p>
        </div>
      </main>
    </div>
  );
}
