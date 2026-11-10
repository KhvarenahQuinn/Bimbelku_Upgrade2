import { Component, ErrorInfo, ReactNode } from "react";
import { clearBrowserSession } from "@/lib/session";

type AppErrorBoundaryProps = {
  children: ReactNode;
};

type AppErrorBoundaryState = {
  hasError: boolean;
};

/**
 * Menahan error render/lazy-load agar pengguna tidak berakhir pada layar kosong.
 * Error event-handler dan request async tetap ditangani oleh halaman masing-masing.
 */
export default class AppErrorBoundary extends Component<
  AppErrorBoundaryProps,
  AppErrorBoundaryState
> {
  state: AppErrorBoundaryState = { hasError: false };

  static getDerivedStateFromError(): AppErrorBoundaryState {
    return { hasError: true };
  }

  componentDidCatch(_error: Error, _info: ErrorInfo): void {
    // Detail error sengaja tidak ditampilkan atau disimpan di browser agar data
    // request/pengguna tidak ikut terbawa. Logging produksi dapat ditambahkan
    // melalui layanan observabilitas yang sudah disetujui kemudian.
  }

  private reload = () => {
    window.location.reload();
  };

  private goHome = () => {
    window.location.assign("/");
  };

  private goToLogin = () => {
    clearBrowserSession();
    sessionStorage.removeItem("bimbelku_payment_order");
    window.location.assign("/login");
  };

  render() {
    if (!this.state.hasError) return this.props.children;

    return (
      <main className="grid min-h-dvh place-items-center bg-gradient-to-br from-orange-50 via-white to-indigo-50 p-4">
        <section
          role="alert"
          aria-live="assertive"
          className="w-full max-w-lg rounded-[2rem] border border-white bg-white p-6 text-center shadow-2xl shadow-slate-200/70 sm:p-8"
        >
          <span className="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-amber-100 text-amber-700">
            <span aria-hidden="true" className="text-2xl font-black">!</span>
          </span>
          <h1 className="mt-5 text-2xl font-black text-slate-950">
            Halaman tidak berhasil dimuat
          </h1>
          <p className="mx-auto mt-3 max-w-md text-sm leading-6 text-slate-600">
            Data akun tidak dihapus. Muat ulang terlebih dahulu. Bila halaman tetap
            gagal, masuk kembali agar sesi dan berkas aplikasi dimuat secara bersih.
          </p>

          <div className="mt-6 grid gap-3 sm:grid-cols-3">
            <button
              type="button"
              onClick={this.reload}
              className="inline-flex min-h-12 items-center justify-center gap-2 rounded-xl bg-slate-950 px-4 text-sm font-black text-white transition hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-slate-300"
            >
              <span aria-hidden="true" className="text-base">↻</span>
              Muat ulang
            </button>
            <button
              type="button"
              onClick={this.goHome}
              className="inline-flex min-h-12 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-black text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-slate-200"
            >
              <span aria-hidden="true" className="text-base">⌂</span>
              Beranda
            </button>
            <button
              type="button"
              onClick={this.goToLogin}
              className="inline-flex min-h-12 items-center justify-center gap-2 rounded-xl border border-indigo-200 bg-indigo-50 px-4 text-sm font-black text-indigo-800 transition hover:bg-indigo-100 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200"
            >
              <span aria-hidden="true" className="text-base">→</span>
              Masuk kembali
            </button>
          </div>
        </section>
      </main>
    );
  }
}
