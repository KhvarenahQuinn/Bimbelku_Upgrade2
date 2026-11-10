import { notify } from "@/lib/notify";
import { useState, useEffect } from "react";
import { useNavigate, useLocation } from "react-router-dom";
import { X, Clock, CreditCard, Loader2, AlertTriangle, ChevronRight, Sparkles } from "lucide-react";
import { Button } from "@/components/ui/button";
import http, { getCached } from "@/lib/http";
import { clearBrowserSession } from "@/lib/session";

export default function PendingPaymentPopup() {
  const navigate = useNavigate();
  const location = useLocation(); 
  
  const [order, setOrder] = useState<any>(null);
  const [timeLeft, setTimeLeft] = useState(0);
  const [isVisible, setIsVisible] = useState(false);
  const [paymentWindowSeconds, setPaymentWindowSeconds] = useState(60 * 60);
  
  // State untuk Loading & Modal Konfirmasi
  const [isCancelling, setIsCancelling] = useState(false);
  const [showConfirmCancel, setShowConfirmCancel] = useState(false);
  const orderKind = order?.order_kind as "cheap_class" | "package" | "booking" | undefined;
  const isCheapClassOrder = orderKind === "cheap_class";
  const returnPath = orderKind === "cheap_class"
    ? "/student/kelas-murah"
    : orderKind === "package"
      ? "/student/packages"
      : "/student/history";

  // 1. Cek Tagihan Aktif ke Backend (Dengan pengaman token)
  const checkActiveOrder = async () => {
    const token = localStorage.getItem("token");
    if (!token) return;

    try {
        let storedUser: { role?: string } | null = null;
        try {
          storedUser = JSON.parse(localStorage.getItem("user") || "null");
        } catch {
          clearBrowserSession();
          setIsVisible(false);
          return;
        }

        if (storedUser?.role !== "student") {
            setIsVisible(false);
            return;
        }

        const response = await getCached("/active-order", {
          maxAgeMs: 10_000,
          force: true,
        });
        if (response.data && response.data.order_id) {
            setOrder(response.data);
            setIsVisible(true);
        } else {
            setIsVisible(false);
        }
    } catch (error: any) {
        if (error.response?.status === 401) setIsVisible(false);
    }
};

  useEffect(() => {
    void checkActiveOrder();
    const handleDataChanged = () => void checkActiveOrder();
    window.addEventListener("bimbelku:data-changed", handleDataChanged);
    // Cek berkala saat halaman aktif agar beban server tetap ringan
    const intervalCheck = setInterval(() => {
      if (document.visibilityState === "visible") void checkActiveOrder();
    }, 60000);
    return () => {
      clearInterval(intervalCheck);
      window.removeEventListener("bimbelku:data-changed", handleDataChanged);
    };
  }, []);

  // 2. Penghitung mundur sesuai batas pembayaran dari server
  useEffect(() => {
    if (!order || !order.created_at) return;

    const orderTime = new Date(order.created_at).getTime();
    const deadline = order.payment_due_at
      ? new Date(order.payment_due_at).getTime()
      : orderTime + (60 * 60 * 1000);

    if (isNaN(orderTime) || isNaN(deadline)) {
        setIsVisible(false);
        return;
    }

    setPaymentWindowSeconds(Math.max(1, Math.floor((deadline - orderTime) / 1000)));

    const updateCountdown = () => {
      const now = new Date().getTime();
      const diff = Math.floor((deadline - now) / 1000);
      
      if (diff <= 0) {
        setIsVisible(false);
        setTimeLeft(0);
      } else {
        setTimeLeft(diff);
      }
    };

    updateCountdown();
    const timer = setInterval(updateCountdown, 1000);

    return () => clearInterval(timer);
  }, [order]);

  useEffect(() => {
    if (!showConfirmCancel) return;
    const handleEscape = (event: KeyboardEvent) => {
      if (event.key === "Escape" && !isCancelling) setShowConfirmCancel(false);
    };
    window.addEventListener("keydown", handleEscape);
    return () => window.removeEventListener("keydown", handleEscape);
  }, [showConfirmCancel, isCancelling]);

  const formatTime = (s: number) => {
    if (isNaN(s)) return "00:00:00";
    const h = Math.floor(s / 3600).toString().padStart(2, '0');
    const m = Math.floor((s % 3600) / 60).toString().padStart(2, '0');
    const sec = (s % 60).toString().padStart(2, '0');
    return `${h}:${m}:${sec}`;
  };

  const handlePayNow = () => {
    if (!order) return;
    // Data yang dikirim disesuaikan dengan kebutuhan PaymentPage
    navigate("/payment", {
      state: {
        orderId: order.order_id,      // ID Database
        invoiceId: order.order_number, // String INV-xxx
        tutorName: order.tutor_name,
        subject: order.subject,
        type: order.type,
        price: Number(order.amount),
        createdAt: order.created_at,
        paymentDueAt: order.payment_due_at,
        durationHours: Number(order.duration_hours || 1),
        totalLearningHours: Number(order.total_learning_hours || 0),
        orderKind: order.order_kind,
        enrollmentStatus: order.enrollment_status,
        cheapClassStatus: order.cheap_class_status,
        cheapClassCancellationReason: order.cheap_class_cancellation_reason,
        canCancel: Boolean(order.can_cancel),
        canResubmit: Boolean(order.can_resubmit),
        willRefundIfAccepted: Boolean(order.will_refund_if_accepted),
        refundReasonIfAccepted: order.refund_reason_if_accepted ?? null,
        date: "Segera"
      }
    });
  };

  const processCancel = async () => {
    if (!order) return;
    
    setIsCancelling(true);
    try {
        await http.post(`/orders/${order.order_id}/cancel`);
        
        setIsVisible(false);
        setOrder(null);
        setShowConfirmCancel(false);
        sessionStorage.removeItem("bimbelku_payment_order");
        notify.success(isCheapClassOrder ? "Keikutsertaan Kelas Bersama berhasil dibatalkan." : "Pesanan berhasil dibatalkan.");
        
        // Jika sedang di halaman payment, tendang ke luar
        if (location.pathname === '/payment') {
            navigate(returnPath);
        }
    } catch (error) {
        notify.error("Gagal membatalkan pesanan.");
    } finally {
        setIsCancelling(false);
    }
  };

  // Render Guard: Jangan tampilkan widget jika...
  if (
    !isVisible || 
    !order || 
    location.pathname === '/payment' || // Sembunyikan jika sudah di halaman bayar
    location.pathname === '/login' ||   // Sembunyikan di login
    isNaN(timeLeft) || 
    timeLeft <= 0
  ) {
      return null;
  }

  return (
    <>
      {/* === WIDGET STICKY (POJOK KANAN BAWAH) === */}
      <div className="fixed bottom-[calc(4.75rem+env(safe-area-inset-bottom))] left-3 right-3 z-[60] min-w-0 overflow-visible animate-in slide-in-from-bottom-5 fade-in duration-500 sm:left-auto sm:right-4 sm:w-[min(340px,calc(100vw-2rem))] xl:bottom-6 xl:right-6">
        <div className="group relative max-h-[calc(100dvh-5.75rem-env(safe-area-inset-bottom))] min-w-0 w-full overflow-x-hidden overflow-y-auto rounded-[1.5rem] border border-slate-100 bg-white p-4 shadow-[0_20px_50px_rgba(0,0,0,0.1)] transition-all hover-scale-102 sm:rounded-[2rem] sm:p-5">
          
          {/* Progress Bar Sisa Waktu */}
          <div className="absolute top-0 left-0 h-1.5 bg-indigo-600 transition-all duration-1000" 
               style={{ width: `${Math.min(100, (timeLeft / paymentWindowSeconds) * 100)}%` }} 
          />

          <div className="mb-4 flex min-w-0 items-start justify-between gap-3">
             <div className="flex min-w-0 items-center gap-2 rounded-full bg-indigo-50 px-3 py-1.5 text-xs font-black text-indigo-600">
                <Clock size={14} />
                <span className="min-w-0 font-mono tracking-tighter">{formatTime(timeLeft)}</span>
             </div>
             <button aria-label="Tutup pengingat tagihan" onClick={() => setIsVisible(false)} className="p-1.5 hover:bg-slate-50 rounded-full text-slate-300 hover:text-slate-500 transition">
                <X size={18} />
             </button>
          </div>

          <div className="mb-4 space-y-1 sm:mb-5">
             <div className="flex items-center gap-2 mb-2">
                <Sparkles size={14} className="text-orange-400 fill-orange-400"/>
                <span className="text-[10px] font-black text-orange-500 uppercase tracking-widest">Tagihan Menunggu</span>
             </div>
             <h4 className="line-clamp-2 break-words text-lg font-black leading-tight text-slate-900">{order.subject}</h4>
             <p className="break-words text-xs font-medium text-slate-500">Bersama <span className="font-bold text-indigo-600">{order.tutor_name}</span></p>
             {order.duration_hours && (
               <p className="text-xs font-bold text-slate-500">
                 {order.duration_hours} jam/pertemuan{order.total_learning_hours ? ` · ${order.total_learning_hours} jam belajar` : ""}
               </p>
             )}
             <div className="mt-3 flex min-w-0 flex-col items-start gap-1 min-[360px]:flex-row min-[360px]:items-end min-[360px]:justify-between min-[360px]:gap-3">
                 <p className="max-w-full break-words text-xl font-black tracking-tighter text-slate-900">
                    Rp {new Intl.NumberFormat('id-ID').format(order.amount)}
                 </p>
                 <span className="max-w-full break-words text-[9px] font-bold uppercase tracking-tighter text-slate-400 min-[360px]:text-right">{order.type}</span>
             </div>
          </div>

          <div className="flex min-w-0 flex-col gap-2 min-[340px]:flex-row sm:gap-3">
             {(!isCheapClassOrder || order.can_cancel) && <button 
               onClick={() => setShowConfirmCancel(true)} 
               className="min-h-11 min-w-0 flex-1 rounded-xl border border-slate-100 px-3 py-3 text-[10px] font-black uppercase tracking-wider text-slate-500 transition-all hover:border-rose-100 hover:bg-rose-50 hover:text-rose-500 sm:px-4 sm:tracking-widest"
             >
               Batal
             </button>}
             <button 
               onClick={handlePayNow} 
               className="flex min-h-11 min-w-0 flex-[2] items-center justify-center gap-1 rounded-xl bg-slate-900 px-3 py-3 text-[10px] font-black uppercase tracking-wider text-white shadow-lg shadow-slate-200 transition-all hover:bg-indigo-600 hover:shadow-indigo-200 sm:gap-2 sm:px-4 sm:tracking-widest"
             >
               Bayar Sekarang <ChevronRight size={14}/>
             </button>
          </div>
        </div>
      </div>

      {/* === MODAL KONFIRMASI PEMBATALAN === */}
      {showConfirmCancel && (
        <div role="dialog" aria-modal="true" aria-labelledby="cancel-order-title" className="fixed inset-0 z-[var(--layer-modal)] flex items-end justify-center bg-slate-900/45 p-3 pb-[max(env(safe-area-inset-bottom),0.75rem)] animate-in fade-in duration-300 sm:items-center sm:p-4">
           <div className="min-w-0 w-full max-w-sm overflow-hidden rounded-[1.75rem] bg-white p-5 shadow-2xl animate-in slide-in-from-bottom-4 duration-300 sm:rounded-[2.5rem] sm:p-8 sm:zoom-in-95">
              <div className="text-center">
                 <div className="w-20 h-20 bg-rose-50 rounded-full flex items-center justify-center mx-auto mb-6 text-rose-500">
                    <AlertTriangle size={40} />
                 </div>
                 <h3 id="cancel-order-title" className="text-2xl font-black text-slate-900 mb-2">{isCheapClassOrder ? "Batalkan Keikutsertaan?" : "Batalkan Pesanan?"}</h3>
                 <p className="text-sm text-slate-500 mb-8 leading-relaxed">
                    {isCheapClassOrder ? <>Kursi kelas <strong>{order.subject}</strong> akan dilepas. Kamu masih dapat bergabung kembali jika pendaftaran masih dibuka dan kuota tersedia.</> : <>Tagihan kelas <strong>{order.subject}</strong> akan dibatalkan dan slot tutor akan dilepas. Tindakan ini tidak dapat diurungkan.</>}
                 </p>
                 
                 <div className="flex flex-col gap-3">
                    <Button 
                       className="w-full py-6 rounded-2xl font-black text-sm uppercase tracking-widest bg-rose-600 hover:bg-rose-700 text-white shadow-xl shadow-rose-200"
                       onClick={processCancel}
                       disabled={isCancelling}
                    >
                       {isCancelling ? <Loader2 className="animate-spin mr-2" size={18}/> : isCheapClassOrder ? "Ya, Lepas Kursi" : "Ya, Batalkan Pesanan"}
                    </Button>
                    <button 
                       className="w-full py-4 font-bold text-slate-400 hover:text-slate-600 transition"
                       onClick={() => setShowConfirmCancel(false)}
                       disabled={isCancelling}
                    >
                       Kembali
                    </button>
                 </div>
              </div>
           </div>
        </div>
      )}
    </>
  );
}
