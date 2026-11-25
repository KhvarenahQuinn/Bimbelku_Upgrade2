import { Link } from "react-router-dom";
import { getStoredHomeRedirectPath } from "@/components/auth/guardRedirects";

const NotFound = () => {
  const homePath = getStoredHomeRedirectPath();

  return (
    <main className="grid min-h-dvh place-items-center bg-gradient-to-br from-orange-50 via-white to-indigo-50 p-5">
      <section className="w-full max-w-xl rounded-[2rem] border border-slate-200 bg-white p-8 text-center shadow-xl sm:p-10">
        <p className="text-sm font-black uppercase tracking-[.2em] text-orange-700">Kesalahan 404</p>
        <h1 className="mt-3 text-4xl font-black text-slate-950">Halaman tidak ditemukan</h1>
        <p className="mx-auto mt-4 max-w-md text-sm leading-6 text-slate-600">
          Alamat halaman ini mungkin sudah berubah atau tidak tersedia. Periksa kembali tautan yang dibuka.
        </p>
        <Link to={homePath} className="mt-7 inline-flex min-h-12 items-center justify-center rounded-xl bg-slate-950 px-6 text-sm font-black text-white transition hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-slate-300">
          Kembali ke Beranda
        </Link>
      </section>
    </main>
  );
};

export default NotFound;
