import { ArrowLeft, GraduationCap, LayoutDashboard } from "lucide-react";
import { Link, Navigate, Outlet, useLocation } from "react-router-dom";

import { Button } from "@/components/ui/button";
import { clearBrowserSession } from "@/lib/session";

type StoredUser = {
  name?: string;
  role?: string;
};

const dashboardByRole: Record<string, string> = {
  admin: "/admin",
  teacher: "/guru",
  student: "/student/dashboard",
};

const roleLabel = (role?: string) => role === "admin" ? "admin" : role === "teacher" ? "tutor" : "akun non-murid";

export default function StudentPackageRoute() {
  const location = useLocation();
  const token = localStorage.getItem("token");
  const userString = localStorage.getItem("user");
  let user: StoredUser | null = null;

  try {
    user = userString ? JSON.parse(userString) : null;
  } catch {
    clearBrowserSession();
  }

  if (!token || !user) {
    const returnTo = `${location.pathname}${location.search}${location.hash}`;
    return <Navigate to={`/login?redirect=${encodeURIComponent(returnTo)}`} replace />;
  }

  if (user.role === "student") {
    return <Outlet />;
  }

  const dashboard = dashboardByRole[user.role || ""] || "/";

  return (
    <main className="grid min-h-screen place-items-center bg-gradient-to-br from-emerald-50 via-white to-teal-50 p-5">
      <section className="w-full max-w-xl rounded-[2.5rem] border border-emerald-100 bg-white p-8 text-center shadow-2xl shadow-emerald-100/60 sm:p-10">
        <div className="mx-auto grid h-24 w-24 place-items-center rounded-full border-4 border-emerald-100 bg-emerald-50 text-emerald-700">
          <GraduationCap size={44} aria-hidden="true" />
        </div>
        <p className="mt-7 text-xs font-black uppercase tracking-[.2em] text-emerald-600">Paket Belajar</p>
        <h1 className="mt-3 text-3xl font-black text-slate-950">Fitur belajar khusus murid</h1>
        <p className="mt-3 leading-7 text-slate-500">
          {user.name ? `${user.name} sedang` : "Akun Anda sedang"} login sebagai {roleLabel(user.role)}. Akun ini tidak dapat membeli atau membuat Paket Belajar.
        </p>
        <div className="mt-8 grid gap-3 sm:grid-cols-2">
          <Button asChild variant="outline" className="h-12 rounded-xl">
            <Link to="/"><ArrowLeft size={17} className="mr-2" />Beranda</Link>
          </Button>
          <Button asChild className="h-12 rounded-xl bg-emerald-600 hover:bg-emerald-700">
            <Link to={dashboard}><LayoutDashboard size={17} className="mr-2" />Buka Dashboard</Link>
          </Button>
        </div>
      </section>
    </main>
  );
}
