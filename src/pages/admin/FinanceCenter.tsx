import { lazy, Suspense, useCallback, useEffect, useMemo, useState } from "react";
import { Banknote, FileBarChart, Loader2, ReceiptText, Undo2, WalletCards } from "lucide-react";
import { useSearchParams } from "react-router-dom";
import AdminLayout from "@/components/AdminLayout";
import { ADMIN_PERMISSIONS, allowedFinanceTabs, canAdmin, FINANCE_TAB_PERMISSIONS, readStoredAdmin, type FinanceTab } from "@/lib/adminPermissions";
import { getCached } from "@/lib/http";

const FinancialTransactionsReport = lazy(() => import("@/components/FinancialTransactionsReport"));
const PaymentVerification = lazy(() => import("./PaymentVerification"));
const RefundManagement = lazy(() => import("./RefundManagement"));
const PayoutReport = lazy(() => import("./FinanceReport"));

const tabs: Array<{ id: FinanceTab; label: string; detail: string; icon: typeof FileBarChart }> = [
  { id: "report", label: "Laporan", detail: "Ringkasan & buku besar", icon: FileBarChart },
  { id: "payments", label: "Pembayaran", detail: "Status & pengecualian", icon: ReceiptText },
  { id: "refunds", label: "Refund", detail: "Keputusan & proses", icon: Undo2 },
  { id: "payouts", label: "Pencairan", detail: "Monitor otomatis", icon: WalletCards },
];

export default function FinanceCenter() {
  const [params, setParams] = useSearchParams();
  const [refundsNeedingAction, setRefundsNeedingAction] = useState(0);
  const admin = useMemo(readStoredAdmin, []);
  const permittedTabs = useMemo(() => {
    const allowed = new Set(allowedFinanceTabs(admin));
    return tabs.filter((item) => allowed.has(item.id));
  }, [admin]);
  const requested = params.get("tab") as FinanceTab | null;
  const active: FinanceTab = permittedTabs.some((item) => item.id === requested)
    ? requested as FinanceTab
    : permittedTabs[0]?.id ?? "report";

  const refreshRefundAttention = useCallback(async (force = false) => {
    try {
      const response = await getCached<{ counts?: { refunds?: number } }>("/admin/dashboard-stats", { force, maxAgeMs: 15_000 });
      setRefundsNeedingAction(Number(response.data.counts?.refunds || 0));
    } catch {
      // Penanda pendukung tidak boleh menghalangi penggunaan pusat keuangan.
    }
  }, []);

  useEffect(() => {
    if (!canAdmin(admin, ADMIN_PERMISSIONS.OPERATIONS_DASHBOARD)) return;
    void refreshRefundAttention();
    const onRefresh = () => void refreshRefundAttention(true);
    window.addEventListener("focus", onRefresh);
    window.addEventListener("bimbelku:data-changed", onRefresh);
    const timer = window.setInterval(() => {
      if (!document.hidden) void refreshRefundAttention(true);
    }, 30_000);
    return () => {
      window.removeEventListener("focus", onRefresh);
      window.removeEventListener("bimbelku:data-changed", onRefresh);
      window.clearInterval(timer);
    };
  }, [admin, refreshRefundAttention]);

  useEffect(() => {
    if (params.get("tab") !== active) setParams({ tab: active }, { replace: true });
  }, [active, params, setParams]);

  return <AdminLayout title="Pusat keuangan" subtitle="Pembayaran, refund, pencairan tutor, buku besar, dan ekspor laporan dalam satu halaman">
    <div className="mb-6 grid gap-2 rounded-[1.75rem] border border-slate-200 bg-white p-2 shadow-sm sm:grid-cols-2 xl:grid-cols-4">
      {permittedTabs.map((item) => {
        const Icon = item.icon;
        const selected = item.id === active;
        return <button key={item.id} type="button" onClick={() => setParams({ tab: item.id }, { replace: true })} className={"flex min-h-16 items-center gap-3 rounded-2xl px-4 py-3 text-left transition " + (selected ? "bg-slate-950 text-white shadow-lg" : "text-slate-600 hover:bg-slate-50")}><span className={"grid h-10 w-10 shrink-0 place-items-center rounded-xl " + (selected ? "bg-orange-500 text-white" : "bg-slate-100 text-slate-700")}><Icon size={19} /></span><span><span className="flex items-center gap-2 text-sm font-black">{item.label}{item.id === "refunds" && refundsNeedingAction > 0 && <span className="relative flex h-2.5 w-2.5 shrink-0" role="status" aria-label={`${refundsNeedingAction} refund perlu ditanggapi`}><span className="absolute inline-flex h-full w-full rounded-full bg-red-500/35" /><span className="relative inline-flex h-2.5 w-2.5 rounded-full bg-red-500 ring-2 ring-white" /></span>}</span><span className={"mt-0.5 block text-[11px] " + (selected ? "text-slate-300" : "text-slate-400")}>{item.detail}</span></span></button>;
      })}
    </div>
    <Suspense fallback={<div className="flex min-h-72 items-center justify-center gap-2 rounded-3xl bg-white text-sm font-black text-slate-500"><Loader2 className="animate-spin text-orange-600" /><Banknote size={18} />Memuat modul keuangan…</div>}>
      {canAdmin(admin, FINANCE_TAB_PERMISSIONS.report) && active === "report" && <FinancialTransactionsReport />}
      {canAdmin(admin, FINANCE_TAB_PERMISSIONS.payments) && active === "payments" && <PaymentVerification embedded />}
      {canAdmin(admin, FINANCE_TAB_PERMISSIONS.refunds) && active === "refunds" && <RefundManagement embedded />}
      {canAdmin(admin, FINANCE_TAB_PERMISSIONS.payouts) && active === "payouts" && <PayoutReport embedded />}
    </Suspense>
  </AdminLayout>;
}
