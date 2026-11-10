import { useNavigate } from "react-router-dom";
import { useConfirmDialog } from "@/components/ConfirmDialogProvider";
import { notify } from "@/lib/notify";
import { clearBrowserSession } from "@/lib/session";

export type LogoutScope = "current" | "all";

export function useLogout() {
  const navigate = useNavigate();
  const confirm = useConfirmDialog();

  return async (scope: LogoutScope = "current") => {
    const allDevices = scope === "all";
    const approved = await confirm({
      title: allDevices ? "Keluar dari semua perangkat?" : "Keluar dari akun?",
      description: allDevices
        ? "Semua sesi BimbelKu yang masih aktif akan diputus, termasuk pada perangkat ini. Anda perlu masuk lagi di setiap perangkat."
        : "Sesi pada perangkat ini akan ditutup. Data yang sudah tersimpan tetap aman.",
      confirmText: allDevices ? "Putus semua sesi" : "Ya, keluar",
      cancelText: "Tetap masuk",
      tone: "danger",
    });

    if (!approved) return;

    const { default: http, clearApiCache, getApiError } = await import("@/lib/http");

    if (allDevices) {
      try {
        await http.post("/logout-all");
      } catch (error) {
        notify.error(getApiError(error, "Semua sesi belum dapat diputus. Coba lagi."));
        return;
      }
    } else {
      try {
        await http.post("/logout");
      } catch {
        // Sesi lokal tetap dibersihkan jika token di perangkat ini sudah kedaluwarsa.
      }
    }

    clearApiCache();
    clearBrowserSession();
    notify.success(allDevices
      ? "Semua sesi akun telah diputus."
      : "Anda telah keluar dari akun.");
    navigate("/", { replace: true });
  };
}
