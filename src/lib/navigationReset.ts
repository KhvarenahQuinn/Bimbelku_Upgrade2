/**
 * Menekan item navbar yang sedang aktif tidak mengubah route, sehingga
 * komponen di halaman itu tidak di-remount. Halaman yang punya state internal
 * "sedang membuka detail" perlu mendengar sinyal ini untuk kembali ke
 * tampilan daftar.
 */
export const ACTIVE_NAV_TAPPED_EVENT = "bimbelku:active-nav-tapped";

export type ActiveNavTappedDetail = { to: string };

export const notifyActiveNavTapped = (to: string) => {
  if (typeof window === "undefined") return;
  window.dispatchEvent(
    new CustomEvent<ActiveNavTappedDetail>(ACTIVE_NAV_TAPPED_EVENT, { detail: { to } }),
  );
};
