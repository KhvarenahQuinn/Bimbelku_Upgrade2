import { ArrowLeft, LayoutDashboard, ShieldAlert } from "lucide-react";
import { Link } from "react-router-dom";
import { getStoredHomeRedirectPath } from "@/components/auth/guardRedirects";
import { Button } from "@/components/ui/button";

export default function AccessDenied() {
  const dashboard = getStoredHomeRedirectPath();

  return (
    <main className="grid min-h-screen place-items-center bg-gradient-to-br from-slate-950 via-indigo-950 to-violet-900 p-5">
      <section className="w-full max-w-xl rounded-[2.5rem] border border-white/10 bg-white p-8 text-center shadow-2xl sm:p-10">
        <div className="mx-auto grid h-24 w-24 place-items-center rounded-full border-4 border-amber-100 bg-amber-50 text-amber-600">
          <ShieldAlert size={44} />
        </div>
        <p className="mt-7 text-xs font-black uppercase tracking-[.2em] text-indigo-500">Akses dibatasi</p>
        <h1 className="mt-3 text-3xl font-black text-slate-950">Halaman ini bukan untuk peran akunmu</h1>
        <p className="mt-3 leading-7 text-slate-500">
          Sistem telah menghentikan perpindahan halaman agar data admin, tutor, dan murid tidak tercampur.
        </p>
        <div className="mt-8 grid gap-3 sm:grid-cols-2">
          <Button asChild variant="outline" className="h-12 rounded-xl">
            <Link to={dashboard}><ArrowLeft size={17} className="mr-2" />Beranda</Link>
          </Button>
          <Button asChild className="h-12 rounded-xl bg-indigo-600 hover:bg-indigo-700">
            <Link to={dashboard}><LayoutDashboard size={17} className="mr-2" />Dashboard saya</Link>
          </Button>
        </div>
      </section>
    </main>
  );
}
