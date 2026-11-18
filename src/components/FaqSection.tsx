import { ChevronDown, MessageCircle } from "lucide-react";

import Reveal from "@/components/Reveal";
import LandingAmbientOrbit from "@/components/LandingAmbientOrbit";
import { useWebsiteContent } from "@/components/WebsiteContentProvider";
import { whatsappHref } from "@/lib/websiteContent";

const faqs = [
  ["Apakah pembayaran dilakukan sebelum tutor ditemukan?", "Ya. Murid menyusun kebutuhan dan melihat ringkasan biaya terlebih dahulu. Proses matching tutor dimulai setelah pembayaran terverifikasi."],
  ["Bagaimana jika tutor belum ditemukan?", "Akun akan menampilkan status serta pilihan lanjutan yang tersedia, termasuk melanjutkan pencarian atau proses pengembalian sesuai kebijakan dan kondisi pesanan."],
  ["Apakah harga sama untuk semua mata pelajaran?", "Tidak selalu. Harga final dihitung dari paket, mata pelajaran, jumlah sesi, dan komponen biaya aktif. Seluruh rinciannya tampil sebelum pembayaran."],
  ["Bisakah memilih jadwal sejak awal?", "Bisa. Hari dan jam dipilih ketika menyusun paket dan menjadi salah satu syarat saat sistem mencocokkan tutor."],
  ["Apakah tutor bisa diganti?", "Permintaan penggantian mengikuti status kelas dan kebijakan yang berlaku. Detail kasus diproses melalui alur penggantian tutor di akun."],
  ["Apa yang dapat dilihat orang tua setelah belajar?", "Progress materi per Bab, jadwal, dan laporan sesi dapat dilihat di akun murid ketika data tersebut sudah dicatat di sistem."],
  ["Bagaimana jika tutor diganti di tengah paket?", "Penggantian tutor dapat diajukan melalui akun. Jika kelas belum dimulai, penggantian otomatis. Jika sudah berjalan, memerlukan persetujuan admin dan murid."],
  ["Bagaimana jika terjadi sengketa sesi?", "Sengketa dapat diajukan lewat akun. Admin akan memverifikasi bukti (bukti kehadiran, log sesi) dan memutuskan selanjutnya."],
  ["Bagaimana refund otomatis jika tutor tidak ditemukan?", "Jika dalam 60 menit tidak ada tutor yang menerima, paket dibatalkan otomatis. Refund ke Saldo BimbelKu instan; ke metode asal via penyedia pembayaran (QRIS, e-wallet, VA) tanpa bukti transfer."],
  ["Apakah Saldo BimbelKu bisa ditarik tunai?", "Tidak. Saldo BimbelKu hanya untuk pembayaran paket les dan Kelas Bersama (non-cashable). Tidak bisa ditarik ke rekening bank atau e-wallet."],
] as const;

export default function FaqSection() {
  const { settings, section } = useWebsiteContent();
  const cms = section("faq");
  const consultationUrl = settings.whatsapp_enabled ? whatsappHref(settings.whatsapp_number, settings.whatsapp_default_message) : null;

  return (
    <section className="relative overflow-hidden bg-[#F7F1E8] py-20 sm:py-24 lg:py-28">
      <LandingAmbientOrbit variant="spark" color="#147D7E" style={{ width: 238, height: 238, bottom: 10, right: 18, opacity: 0.84 }} />
      <div className="relative mx-auto max-w-[1100px] px-5 sm:px-8">
        <Reveal width="100%">
          <div className="mx-auto max-w-3xl text-center">
            <p className="text-xs font-extrabold uppercase tracking-[0.18em] text-orange-700">{cms?.eyebrow || "Sebelum kamu memesan"}</p>
            <h2 className="mt-4 text-balance text-3xl font-extrabold tracking-tight text-[#14213D] sm:text-4xl">{cms?.title || "Pertanyaan penting, dijawab tanpa disembunyikan."}</h2>
            <p className="mt-5 text-base leading-8 text-slate-600">{cms?.description || "Termasuk urutan pembayaran, proses matching, serta pilihan ketika tutor belum ditemukan."}</p>
          </div>
        </Reveal>
        <div className="mt-10 grid gap-3">
          {faqs.map(([question, answer], index) => (
            <Reveal key={question} delay={index * .035} width="100%">
              <details className="faq-item group rounded-2xl border border-stone-200 bg-white px-5 py-1 shadow-sm open:border-orange-300 open:shadow-md sm:px-6">
                <summary className="flex min-h-16 cursor-pointer list-none items-center justify-between gap-5 py-3 text-left font-extrabold text-[#14213D] marker:hidden">
                  <span>{question}</span><ChevronDown size={20} className="shrink-0 text-orange-700 transition-transform group-open:rotate-180" />
                </summary>
                <p className="border-t border-stone-100 pb-5 pt-4 text-sm leading-7 text-slate-600">{answer}</p>
              </details>
            </Reveal>
          ))}
        </div>
        {consultationUrl && <p className="mt-7 text-center text-sm font-bold text-slate-600">Belum menemukan jawaban? <a href={consultationUrl} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 text-orange-800 underline decoration-orange-300 underline-offset-4"><MessageCircle size={16} />Tanya lewat WhatsApp</a></p>}
      </div>
    </section>
  );
}
