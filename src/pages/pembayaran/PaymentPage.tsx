import { notify } from "@/lib/notify";
import { FormEvent, Suspense, lazy, useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import { createPortal } from "react-dom";
import {
  AlertCircle,
  ArrowLeft,
  ArrowRight,
  Building2,
  Check,
  CheckCircle2,
  Clock3,
  Copy,
  CreditCard,
  FileImage,
  ImageOff,
  Loader2,
  RefreshCw,
  QrCode,
  ShieldCheck,
  Upload,
  UserRound,
  WalletCards,
  XCircle,
  X,
} from "lucide-react";
import StudentLayout from "@/components/StudentLayout";
import PaymentDesignPreview from "@/components/PaymentDesignPreview";
import OrderProgress from "@/components/OrderProgress";
import { useConfirmDialog } from "@/components/ConfirmDialogProvider";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import http, { getApiError, getApiErrorDetails } from "@/lib/http";
import { getPaymentReturnOrderId } from "@/lib/paymentReturn";
import { showFilePreview } from "@/lib/filePreview";
import {
  containsLetter,
  isValidAccountNumber,
  isValidPersonName,
  sanitizeDigits,
  sanitizePersonName,
  validateUpload,
} from "@/lib/validation";

const EmbeddedCheckout = lazy(() => import("@/components/EmbeddedCheckout"));

type PaymentPreviewAction = "open" | "close" | "paid" | "pending" | "failed";
declare global {
  interface Window {
    bimbelkuPaymentPreview?: (action: PaymentPreviewAction) => void;
  }
}

interface EmbeddedSession {
  sessionId: string;
  componentsKey: string;
  amount: number;
}

interface PaymentSettings {
  provider?: "xendit";
  xendit_configured?: boolean;
  merchant_name: string;
  bank_name: string;
  account_number: string;
  account_name: string;
  qris_available?: boolean;
  qris_endpoint?: string | null;
}

interface WalletQuote {
  supported: boolean;
  balance: number;
  reserved_balance: number;
  usable_amount: number;
  external_due: number;
  currency: string;
}

interface OrderData {
  orderId: number;
  invoiceId?: string;
  tutorName: string;
  subject: string;
  type: string;
  price: number;
  subtotalAmount?: number;
  discountAmount?: number;
  packageName?: string;
  date?: string;
  paymentDueAt?: string;
  rejectionReason?: string;
  durationHours?: number;
  totalLearningHours?: number;
  orderKind?: "cheap_class" | "package" | "booking";
  enrollmentStatus?: string | null;
  cheapClassStatus?: string | null;
  cheapClassCancellationReason?: string | null;
  canCancel?: boolean;
  canResubmit?: boolean;
  willRefundIfAccepted?: boolean;
  refundReasonIfAccepted?: "class_cancelled" | "capacity_full" | null;
  externalReceivedAmount?: number;
  outstandingAmount?: number;
  reconciliationStatus?: "underpaid" | "exact" | "overpaid" | null;
  topUpDueAt?: string | null;
  gatewayStatus?: string | null;
  gatewayExpiresAt?: string | null;
}

type State = "loading" | "pending" | "submitted" | "paid" | "expired" | "cancelled" | "refund_pending" | "refunded";
type PaymentIssue = {
  message: string;
  title: string;
  tone: "amber" | "rose";
  code?: string;
};

const rupiah = (value: number) => new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(value || 0);
const PAYMENT_ACCOUNT_MIN_DIGITS = 8;
const PAYMENT_ACCOUNT_MAX_DIGITS = 20;

const describePaymentIssue = (error: unknown): PaymentIssue => {
  const details = getApiErrorDetails(error, "Bukti pembayaran gagal dikirim. Coba lagi setelah memeriksa data yang diisi.");

  if (!details.status) {
    return {
      title: "Koneksi ke server terputus",
      message: "Status pengiriman bukti belum dapat dipastikan. Periksa status pembayaran sebelum mengirim ulang agar tidak terjadi pengiriman ganda.",
      tone: "amber",
      code: "network_error",
    };
  }
  if (details.code === "payment_rate_limited" || details.status === 429) {
    return { title: "Tunggu sebentar", message: details.message, tone: "amber", code: details.code };
  }
  if (details.code === "schedule_conflict") {
    return { title: "Jadwal bertabrakan", message: details.message, tone: "rose", code: details.code };
  }
  if (details.code === "payment_expired") {
    return { title: "Batas pembayaran berakhir", message: details.message, tone: "rose", code: details.code };
  }
  if (details.code === "payment_settings_unavailable") {
    return { title: "Metode pembayaran belum siap", message: details.message, tone: "amber", code: details.code };
  }
  if (details.code === "wallet_balance_changed") {
    return { title: "Saldo berubah", message: details.message, tone: "amber", code: details.code };
  }
  if (details.code === "wallet_full_payment_required") {
    return { title: "Saldo belum mencukupi", message: details.message, tone: "amber", code: details.code };
  }
  if (details.code === "wallet_not_supported") {
    return { title: "Saldo belum tersedia", message: details.message, tone: "amber", code: details.code };
  }
  if (details.code === "wallet_integrity_locked") {
    return { title: "Saldo sedang diamankan", message: details.message, tone: "amber", code: details.code };
  }
  if (details.code === "payment_pin_setup_required") {
    return { title: "Buat PIN pembayaran", message: details.message, tone: "amber", code: details.code };
  }
  if (details.code === "payment_pin_invalid") {
    return { title: "PIN pembayaran salah", message: details.message, tone: "rose", code: details.code };
  }
  if (details.code === "invoice_not_payable" || details.code === "package_not_payable" || details.code === "session_inactive") {
    return { title: "Tagihan tidak dapat diproses", message: details.message, tone: "rose", code: details.code };
  }

  return { title: "Bukti belum terkirim", message: details.message, tone: "rose", code: details.code };
};

export default function PaymentPage() {
  const location = useLocation();
  // Visual-only fixture: never available on a public host or in a normal build.
  const designPreview = import.meta.env.VITE_PAYMENT_DESIGN_PREVIEW === "true"
    && ["localhost", "127.0.0.1"].includes(window.location.hostname)
    && location.pathname === "/payment-preview";
  const navigate = useNavigate();
  const confirm = useConfirmDialog();
  const [settings, setSettings] = useState<PaymentSettings | null>(null);
  const [order, setOrder] = useState<OrderData | null>(null);
  const [state, setState] = useState<State>("loading");
  const [loadIssue, setLoadIssue] = useState<string | null>(null);
  const loadGeneration = useRef(0);
  const activeOrderId = useRef<number | null>(null);
  const statusRequestId = useRef(0);
  const [reason, setReason] = useState("");
  const [senderName, setSenderName] = useState("");
  const [bankName, setBankName] = useState("");
  const [senderAccountNumber, setSenderAccountNumber] = useState("");
  const [proof, setProof] = useState<File | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const submitLock = useRef(false);
  const gatewayActionLock = useRef(false);
  const walletActionLock = useRef(false);
  const [submitIssue, setSubmitIssue] = useState<PaymentIssue | null>(null);
  const [now, setNow] = useState(Date.now());
  const [paymentMethod, setPaymentMethod] = useState<"qris" | "bank">("bank");
  const [qrisObjectUrl, setQrisObjectUrl] = useState<string | null>(null);
  const [qrisLoading, setQrisLoading] = useState(false);
  const [qrisLoadFailed, setQrisLoadFailed] = useState(false);
  const [walletQuote, setWalletQuote] = useState<WalletQuote | null>(null);
  const [useWallet, setUseWallet] = useState(false);
  const [paymentPinConfigured, setPaymentPinConfigured] = useState<boolean | null>(null);
  const [accountHasPassword, setAccountHasPassword] = useState(true);
  const [paymentPin, setPaymentPin] = useState("");
  const [pinPassword, setPinPassword] = useState("");
  const [pinConfirmation, setPinConfirmation] = useState("");
  const [settingPin, setSettingPin] = useState(false);
  const [pinResetRequested, setPinResetRequested] = useState(false);
  const [pinResetCode, setPinResetCode] = useState("");
  const [pinResetSent, setPinResetSent] = useState(false);
  const [requestingPinReset, setRequestingPinReset] = useState(false);
  const [embeddedSession, setEmbeddedSession] = useState<EmbeddedSession | null>(null);
  const [checkoutOpen, setCheckoutOpen] = useState(false);
  const [legacyCheckoutUrl, setLegacyCheckoutUrl] = useState<string | null>(null);
  const orderKind = order?.orderKind;
  const isCheapClass = orderKind === "cheap_class";
  const isPackage = orderKind === "package";
  const returnPath = orderKind === "cheap_class"
    ? "/student/kelas-murah"
    : orderKind === "package"
      ? "/student/packages"
      : "/student/history";
  const returnAction = isCheapClass
    ? "Lihat Kelas Bersama"
    : isPackage
      ? "Lihat Proses Kelas"
      : "Lihat Riwayat Transaksi";

  const capturePaymentScope = () => {
    const generation = loadGeneration.current;
    const orderId = activeOrderId.current;
    return () => generation === loadGeneration.current && activeOrderId.current === orderId;
  };

  const checkStatus = useCallback(async (orderId: number, showNotification = true) => {
    if (activeOrderId.current !== orderId) return false;
    const generation = loadGeneration.current;
    const requestId = ++statusRequestId.current;
    const isCurrent = () => generation === loadGeneration.current && activeOrderId.current === orderId && statusRequestId.current === requestId;
    if (!isCurrent()) return false;
    if (designPreview) {
      if (showNotification) notify.warning("Ini pratinjau desain. Tidak ada status pembayaran asli yang diperiksa.");
      return true;
    }
    try {
      try {
        await http.post(`/orders/${orderId}/payment-status-sync`);
      } catch {
        // Webhook tetap menjadi jalur utama; polling ini adalah fallback aman untuk localhost.
      }
      if (!isCurrent()) return false;
      const response = await http.get(`/student/orders/${orderId}/status`);
      if (!isCurrent()) return false;
      if (Number(response.data?.id) !== orderId) throw new Error("Status yang diterima tidak cocok dengan tagihan ini.");
      const status = response.data.status as string;
      setReason(response.data.rejection_reason || "");
      setOrder((current) => current && current.orderId === orderId ? {
        ...current,
        orderKind: response.data.order_kind || current.orderKind,
        enrollmentStatus: response.data.enrollment_status ?? current.enrollmentStatus,
        cheapClassStatus: response.data.cheap_class_status ?? current.cheapClassStatus,
        cheapClassCancellationReason: response.data.cheap_class_cancellation_reason ?? current.cheapClassCancellationReason,
        canCancel: Boolean(response.data.can_cancel),
        canResubmit: Boolean(response.data.can_resubmit),
        willRefundIfAccepted: Boolean(response.data.will_refund_if_accepted),
        refundReasonIfAccepted: response.data.refund_reason_if_accepted ?? current.refundReasonIfAccepted ?? null,
        externalReceivedAmount: Number(response.data.external_received_amount || 0),
        outstandingAmount: Number(response.data.payment_outstanding_amount || 0),
        reconciliationStatus: response.data.payment_reconciliation_status ?? null,
        topUpDueAt: response.data.top_up_due_at ?? null,
        paymentDueAt: response.data.top_up_due_at ?? current.paymentDueAt,
        gatewayStatus: response.data.gateway_status ?? current.gatewayStatus ?? null,
        gatewayExpiresAt: response.data.gateway_expires_at ?? current.gatewayExpiresAt ?? null,
      } : current);
      if (status === "paid") {
        setState("paid");
        sessionStorage.removeItem("bimbelku_payment_order");
      } else if (status === "submitted") {
        setState("submitted");
      } else if (status === "rejected" || status === "pending") {
        setState("pending");
      } else if (status === "partially_paid") {
        setState("pending");
      } else if (status === "cancelled") {
        setState("cancelled");
        sessionStorage.removeItem("bimbelku_payment_order");
      } else if (status === "refund_pending") {
        setState("refund_pending");
        sessionStorage.removeItem("bimbelku_payment_order");
      } else if (status === "refunded") {
        setState("refunded");
        sessionStorage.removeItem("bimbelku_payment_order");
      } else if (status === "expired") {
        setState("expired");
        sessionStorage.removeItem("bimbelku_payment_order");
      } else {
        throw new Error("Status tagihan belum dapat dipastikan. Periksa kembali melalui Riwayat Transaksi.");
      }
      if (showNotification) notify.success("Status pembayaran diperbarui.");
      return true;
    } catch (error) {
      if (!isCurrent()) return false;
      if (showNotification) notify.error(getApiError(error));
      return false;
    }
  }, [designPreview]);

  const initialize = useCallback(async () => {
    const generation = ++loadGeneration.current;
    const isCurrent = () => generation === loadGeneration.current;
    activeOrderId.current = null;
    if (designPreview) {
      activeOrderId.current = 0;
      setSettings({ provider: "xendit", xendit_configured: true, merchant_name: "BimbelKu", bank_name: "", account_number: "", account_name: "" });
      setOrder({
        orderId: 0, invoiceId: "PRATINJAU-DESAIN", tutorName: "Tutor dipilih setelah pembayaran",
        subject: "Matematika", packageName: "Paket Belajar Privat", type: "Privat online",
        price: 396000, subtotalAmount: 440000, discountAmount: 44000, orderKind: "package",
        paymentDueAt: new Date(Date.now() + 45 * 60 * 1000).toISOString(),
      });
      setState("pending");
      return;
    }
    try {
      setState("loading");
      setLoadIssue(null);
      setOrder(null);
      setCheckoutOpen(false);
      setEmbeddedSession(null);
      setLegacyCheckoutUrl(null);
      setWalletQuote(null);
      setUseWallet(false);
      setSubmitting(false);
      setSubmitIssue(null);
      setReason("");
      setProof(null);
      setPaymentPin("");
      const returnOrderId = getPaymentReturnOrderId(location.search);
      let resolved = location.state as OrderData | null;
      if (returnOrderId !== null || !resolved?.orderId) {
        // Sumber kebenaran pertama adalah tagihan aktif dari backend.
        // sessionStorage hanya fallback untuk menampilkan status tagihan lama
        // (mis. bukti sedang diperiksa), sehingga order batal tidak dapat
        // menutupi tagihan baru yang masih aktif.
        // An explicit return ID never falls back to a newer invoice or stored state.
        const response = returnOrderId !== null
          ? await http.get(`/student/orders/${returnOrderId}/status`)
          : await http.get("/active-order");
        if (!isCurrent()) return;
        if (returnOrderId !== null) {
          if (Number(response.data?.id) !== returnOrderId) throw new Error("Tagihan pada tautan pembayaran tidak sesuai.");
          response.data = { ...response.data, order_id: response.data.id };
        }
        if (response.data?.order_id) {
          resolved = {
            orderId: response.data.order_id,
            invoiceId: response.data.order_number,
            tutorName: response.data.tutor_name,
            subject: response.data.subject,
            type: response.data.type,
            price: Number(response.data.amount),
            subtotalAmount: Number(response.data.subtotal_amount || response.data.amount),
            discountAmount: Number(response.data.discount_amount || 0),
            packageName: response.data.package_name || undefined,
            date: response.data.scheduled_at,
            paymentDueAt: response.data.payment_due_at,
            rejectionReason: response.data.rejection_reason,
            durationHours: Number(response.data.duration_hours || 1),
            totalLearningHours: Number(response.data.total_learning_hours || 0),
            orderKind: response.data.order_kind,
            enrollmentStatus: response.data.enrollment_status,
            cheapClassStatus: response.data.cheap_class_status,
            cheapClassCancellationReason: response.data.cheap_class_cancellation_reason,
            canCancel: Boolean(response.data.can_cancel),
            canResubmit: Boolean(response.data.can_resubmit),
            willRefundIfAccepted: Boolean(response.data.will_refund_if_accepted),
            refundReasonIfAccepted: response.data.refund_reason_if_accepted ?? null,
            externalReceivedAmount: Number(response.data.external_received_amount || 0),
            outstandingAmount: Number(response.data.payment_outstanding_amount || 0),
            reconciliationStatus: response.data.payment_reconciliation_status ?? null,
            topUpDueAt: response.data.top_up_due_at ?? null,
            gatewayStatus: response.data.gateway_status ?? null,
            gatewayExpiresAt: response.data.gateway_expires_at ?? null,
          };
        } else {
          const stored = sessionStorage.getItem("bimbelku_payment_order");
          resolved = stored ? JSON.parse(stored) as OrderData : null;
        }
      }
      const settingsResponse = await http.get<PaymentSettings>("/payment-settings");
      if (!isCurrent()) return;
      setSettings(settingsResponse.data);
      if (!resolved?.orderId) {
        setState("expired");
        return;
      }
      setOrder(resolved);
      activeOrderId.current = resolved.orderId;
      sessionStorage.setItem("bimbelku_payment_order", JSON.stringify(resolved));
      try {
        const walletResponse = await http.get<WalletQuote>(`/student/orders/${resolved.orderId}/wallet-quote`);
        if (!isCurrent()) return;
        setWalletQuote(walletResponse.data);
        setUseWallet(false);
      } catch {
        if (!isCurrent()) return;
        setWalletQuote(null);
        setUseWallet(false);
      }
      try {
        const pinResponse = await http.get<{ configured: boolean; has_password?: boolean }>("/student/payment-pin/status");
        if (!isCurrent()) return;
        setPaymentPinConfigured(Boolean(pinResponse.data?.configured));
        setAccountHasPassword(pinResponse.data?.has_password !== false);
      } catch {
        if (!isCurrent()) return;
        // Akun lama tetap dapat memakai transfer jika endpoint PIN belum tersedia.
        setPaymentPinConfigured(false);
      }
      const loaded = await checkStatus(resolved.orderId, false);
      if (!isCurrent()) return;
      if (!loaded) {
        throw new Error("Status tagihan belum dapat dimuat. Jangan membayar ulang; periksa pembayaran melalui Riwayat Transaksi.");
      }
    } catch (error) {
      if (!isCurrent()) return;
      const message = error instanceof Error && !("response" in error) ? error.message : getApiError(error, "Tagihan gagal dimuat.");
      setLoadIssue(message);
      setOrder(null);
      notify.error(message);
      setState("expired");
    }
  }, [checkStatus, designPreview, location.search, location.state]);

  useEffect(() => {
    // Capture the ref objects (request counters, not DOM nodes) for this lifetime.
    const requestScope = loadGeneration;
    const currentOrder = activeOrderId;
    void initialize();
    return () => { ++requestScope.current; currentOrder.current = null; };
  }, [initialize]);

  const fetchQris = useCallback(async () => {
    if (!settings?.qris_available) {
      setQrisObjectUrl(null);
      setQrisLoadFailed(false);
      setPaymentMethod("bank");
      return;
    }

    setQrisLoading(true);
    setQrisLoadFailed(false);
    try {
      const response = await http.get(settings.qris_endpoint || "/payment-settings/qris", {
        responseType: "blob",
      });
      const nextUrl = URL.createObjectURL(response.data as Blob);
      setQrisObjectUrl((current) => {
        if (current) URL.revokeObjectURL(current);
        return nextUrl;
      });
      setPaymentMethod("qris");
    } catch {
      setQrisObjectUrl((current) => {
        if (current) URL.revokeObjectURL(current);
        return null;
      });
      setQrisLoadFailed(true);
      setPaymentMethod("bank");
    } finally {
      setQrisLoading(false);
    }
  }, [settings?.qris_available, settings?.qris_endpoint]);

  useEffect(() => {
    void fetchQris();
  }, [fetchQris]);

  useEffect(() => () => {
    if (qrisObjectUrl) URL.revokeObjectURL(qrisObjectUrl);
  }, [qrisObjectUrl]);

  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, []);
  useEffect(() => {
    if (state !== "submitted" || !order) return;
    const timer = window.setInterval(() => {
      if (document.visibilityState === "visible") void checkStatus(order.orderId, false);
    }, 15000);
    return () => window.clearInterval(timer);
  }, [checkStatus, state, order]);

  const secondsLeft = useMemo(() => {
    if (!order?.paymentDueAt) return 0;
    return Math.max(0, Math.floor((new Date(order.paymentDueAt).getTime() - now) / 1000));
  }, [order?.paymentDueAt, now]);
  const timeText = `${String(Math.floor(secondsLeft / 3600)).padStart(2, "0")}:${String(Math.floor((secondsLeft % 3600) / 60)).padStart(2, "0")}:${String(secondsLeft % 60).padStart(2, "0")}`;
  const bankPaymentReady = Boolean(
    settings?.bank_name?.trim()
      && settings?.account_number?.trim()
      && settings?.account_name?.trim(),
  );
  const qrisPaymentReady = Boolean(settings?.qris_available && qrisObjectUrl && !qrisLoadFailed);
  const walletSupported = Boolean(walletQuote?.supported);
  const walletBalance = Number(walletQuote?.usable_amount || 0);
  const walletAvailable = Boolean(walletSupported && walletBalance > 0.009);
  const walletUsable = Boolean(walletAvailable && walletBalance + 0.009 >= Number(order?.price || 0));
  const walletAmount = useWallet && walletAvailable
    ? Math.min(walletBalance, Number(order?.price || 0))
    : 0;
  const isTopUp = order?.reconciliationStatus === "underpaid" && Number(order?.outstandingAmount || 0) > 0;
  const externalDue = isTopUp
    ? Number(order?.outstandingAmount || 0)
    : Math.max(0, Number(order?.price || 0) - walletAmount);
  const walletOnly = Boolean(useWallet && walletUsable && walletAmount > 0 && externalDue < 0.01);
  const walletFullCoverage = Boolean(walletSupported && !isTopUp && walletUsable);
  const paymentDestinationReady = walletOnly || bankPaymentReady || qrisPaymentReady;
  const paymentExpired = Boolean(order?.paymentDueAt && secondsLeft === 0);

  const copy = async (value: string, label: string) => {
    try {
      await navigator.clipboard.writeText(value);
      notify.success(`${label} berhasil disalin.`);
    } catch {
      notify.error(`${label} tidak dapat disalin otomatis. Salin secara manual.`);
    }
  };

  const setupPaymentPin = async () => {
    if (accountHasPassword && pinPassword.length === 0) {
      notify.error("Masukkan password akun terlebih dahulu.");
      return;
    }
    if (!/^\d{6}$/.test(pinConfirmation) || pinConfirmation !== paymentPin) {
      notify.error("PIN baru harus 6 angka dan konfirmasinya harus sama.");
      return;
    }
    setSettingPin(true);
    try {
      await http.post("/student/payment-pin", {
        ...(accountHasPassword ? { current_password: pinPassword } : {}),
        pin: paymentPin,
        pin_confirmation: pinConfirmation,
      });
      setPaymentPinConfigured(true);
      setPinPassword("");
      setPinConfirmation("");
      notify.success("PIN pembayaran berhasil dibuat. Masukkan PIN tersebut untuk melanjutkan.");
    } catch (error) {
      notify.error(getApiError(error, "PIN pembayaran gagal disimpan."));
    } finally {
      setSettingPin(false);
    }
  };
  const requestPinReset = async () => {
    setRequestingPinReset(true);
    try {
      const response = await http.post("/student/payment-pin/reset/request");
      setPinResetSent(true);
      notify.success(response.data.message);
    } catch (error) {
      notify.error(getApiError(error, "Kode reset PIN belum dapat dikirim."));
    } finally {
      setRequestingPinReset(false);
    }
  };
  const resetPaymentPin = async () => {
    if (!/^\d{6}$/.test(pinResetCode)) {
      notify.error("Masukkan kode OTP 6 angka dari email.");
      return;
    }
    if (!/^\d{6}$/.test(pinConfirmation) || pinConfirmation !== paymentPin) {
      notify.error("PIN baru harus 6 angka dan konfirmasinya harus sama.");
      return;
    }
    setSettingPin(true);
    try {
      await http.post("/student/payment-pin/reset", {
        code: pinResetCode,
        pin: paymentPin,
        pin_confirmation: pinConfirmation,
      });
      setPinResetRequested(false);
      setPinResetSent(false);
      setPinResetCode("");
      setPinConfirmation("");
      setPaymentPin("");
      notify.success("PIN pembayaran berhasil diatur ulang.");
    } catch (error) {
      notify.error(getApiError(error, "PIN pembayaran gagal diatur ulang."));
    } finally {
      setSettingPin(false);
    }
  };
  const upload = async (event: FormEvent) => {
    event.preventDefault();
    if (!order || submitLock.current) return;
    const isCurrent = capturePaymentScope();
    if (useWallet) {
      if (!paymentPinConfigured) {
        notify.error("Buat PIN pembayaran terlebih dahulu.");
        return;
      }
      if (!/^\d{6}$/.test(paymentPin)) {
        notify.error("Masukkan PIN pembayaran 6 angka.");
        return;
      }
    }
    if (externalDue > 0.01) {
      if (!proof) {
        notify.error("Pilih bukti pembayaran untuk sisa tagihan.");
        return;
      }
      if (!isValidPersonName(senderName)) {
        notify.error("Nama pemilik rekening harus berisi huruf dan tidak boleh memuat angka.");
        return;
      }
      if (!containsLetter(bankName)) {
        notify.error("Nama bank atau e-wallet wajib mengandung huruf.");
        return;
      }
      if (
        !isValidAccountNumber(senderAccountNumber)
        || senderAccountNumber.length < PAYMENT_ACCOUNT_MIN_DIGITS
        || senderAccountNumber.length > PAYMENT_ACCOUNT_MAX_DIGITS
      ) {
        notify.error(`Nomor rekening atau e-wallet harus berisi ${PAYMENT_ACCOUNT_MIN_DIGITS}–${PAYMENT_ACCOUNT_MAX_DIGITS} digit.`);
        return;
      }
      if (!paymentDestinationReady) {
        notify.error("Metode pembayaran belum dikonfigurasi admin.");
        return;
      }
    }

    const payload = new FormData();
    payload.append("use_wallet", useWallet ? "1" : "0");
    payload.append("wallet_expected_amount", String(walletAmount));
    if (useWallet) payload.append("payment_pin", paymentPin);
    if (externalDue > 0.01 && proof) {
      payload.append("file", proof);
      payload.append("sender_name", senderName);
      payload.append("bank_name", bankName);
      payload.append("sender_account_number", senderAccountNumber);
    }
    setSubmitIssue(null);
    setSubmitting(true);
    submitLock.current = true;
    try {
      const response = await http.post(`/orders/${order.orderId}/pay`, payload);
      if (!isCurrent()) return;
      notify.success(response.data.message);
      setReason("");
      await checkStatus(order.orderId, false);
    } catch (error) {
      if (!isCurrent()) return;
      const issue = describePaymentIssue(error);
      setSubmitIssue(issue);
      if (issue.tone === "amber") notify.warning(issue.message);
      else notify.error(issue.message);
      if (["payment_expired", "invoice_not_payable", "package_not_payable", "session_inactive", "wallet_balance_changed"].includes(issue.code || "")) {
        if (issue.code === "wallet_balance_changed") {
          try {
            const walletResponse = await http.get<WalletQuote>(`/student/orders/${order.orderId}/wallet-quote`);
            if (!isCurrent()) return;
            setWalletQuote(walletResponse.data);
          } catch { /* status check below remains the fallback */ }
        }
        void checkStatus(order.orderId, false);
      }
    } finally {
      submitLock.current = false;
      if (isCurrent()) setSubmitting(false);
    }
  };

  const selectProof = (file?: File) => {
    setSubmitIssue(null);
    const error = validateUpload(file, {
      label: "Bukti transfer",
      maxSizeMb: 5,
      extensions: ["jpg", "jpeg", "png", "webp"],
    });
    if (error) {
      notify.error(error);
      setProof(null);
      return;
    }
    setProof(file || null);
  };

  const cancel = async () => {
    if (!order) return;
    const isCurrent = capturePaymentScope();
    const approved = await confirm({
      title: isCheapClass ? "Batalkan keikutsertaan Kelas Bersama?" : "Batalkan tagihan?",
      description: isCheapClass
        ? "Kursi akan dilepas. Kamu masih dapat bergabung kembali jika pendaftaran masih dibuka dan kuota tersedia. Pembatalan tidak tersedia setelah bukti pembayaran dikirim."
        : "Tagihan dan pesanan akan dibatalkan. Tindakan ini tidak dapat dipulihkan.",
      confirmText: isCheapClass ? "Batalkan keikutsertaan" : "Ya, batalkan",
      tone: "danger",
    });
    if (!approved || !isCurrent()) return;
    try {
      const response = await http.post(`/orders/${order.orderId}/cancel`);
      if (!isCurrent()) return;
      notify.success(response.data.message);
      sessionStorage.removeItem("bimbelku_payment_order");
      navigate(returnPath, { replace: true });
    } catch (error) {
      if (!isCurrent()) return;
      notify.error(getApiError(error));
    }
  };

  const openXenditCheckout = async () => {
    if (!order || submitting || gatewayActionLock.current) return;
    const isCurrent = capturePaymentScope();
    if (designPreview) {
      setEmbeddedSession({ sessionId: "visual-only", componentsKey: "visual-only", amount: externalDue });
      setCheckoutOpen(true);
      return;
    }
    if (useWallet && walletOnly) {
      await payWithWallet();
      return;
    }
    if (useWallet) {
      if (paymentPinConfigured !== true) {
        notify.error("Buat PIN pembayaran terlebih dahulu.");
        return;
      }
      if (!/^\d{6}$/.test(paymentPin)) {
        notify.error("Masukkan PIN pembayaran 6 angka.");
        return;
      }
    }
    gatewayActionLock.current = true;
    setSubmitting(true);
    setSubmitIssue(null);
    try {
      const mode = window.location.protocol === "https:" ? "components" : "payment_link";
      const response = await http.post(`/orders/${order.orderId}/xendit-session`, {
        mode,
        ...(useWallet ? {
          use_wallet: true,
          wallet_expected_amount: walletAmount,
          payment_pin: paymentPin,
        } : {}),
      });
      if (!isCurrent()) return;
      const data = response.data?.data;
      const key = data?.components_sdk_key;
      if (data?.mode === "COMPONENTS" && typeof key === "string" && key.length > 0) {
        setEmbeddedSession({ sessionId: String(data.session_id), componentsKey: key, amount: Number(data.xendit_amount ?? data.amount ?? externalDue) });
        setCheckoutOpen(true);
        setLegacyCheckoutUrl(null);
      } else if (typeof data?.checkout_url === "string" && data.checkout_url.startsWith("https://")) {
        // Localhost uses hosted checkout because embedded Components requires HTTPS.
        // Reopening the same order reuses its active session, never a second invoice.
        setLegacyCheckoutUrl(data.checkout_url);
      } else {
        throw new Error("Pilihan pembayaran belum dapat dimuat untuk tagihan ini.");
      }
    } catch (error) {
      if (!isCurrent()) return;
      const details = getApiErrorDetails(error, "Halaman pembayaran belum dapat dibuka.");
      setSubmitIssue({
        title: details.code === "xendit_not_configured" ? "Pembayaran otomatis belum tersedia" : "Pembayaran belum terbuka",
        message: details.message,
        tone: "amber",
        code: details.code,
      });
      notify.error(details.message);
    } finally {
      gatewayActionLock.current = false;
      if (isCurrent()) setSubmitting(false);
    }
  };

  const payWithWallet = async () => {
    if (!order || submitting || walletActionLock.current) return;
    if (!walletFullCoverage) {
      notify.error("Saldo BimbelKu belum mencukupi seluruh tagihan. Pilih metode pembayaran lain.");
      return;
    }
    if (paymentPinConfigured !== true) {
      notify.error("Buat PIN pembayaran terlebih dahulu.");
      return;
    }
    if (!/^\d{6}$/.test(paymentPin)) {
      notify.error("Masukkan PIN pembayaran 6 angka.");
      return;
    }

    const isCurrent = capturePaymentScope();
    walletActionLock.current = true;
    setSubmitting(true);
    setSubmitIssue(null);
    try {
      const payload = new FormData();
      payload.append("use_wallet", "1");
      payload.append("wallet_expected_amount", String(Number(walletQuote?.usable_amount || 0)));
      payload.append("payment_pin", paymentPin);
      const response = await http.post(`/orders/${order.orderId}/pay`, payload);
      if (!isCurrent()) return;
      notify.success(response.data.message);
      setUseWallet(false);
      setPaymentPin("");
      await checkStatus(order.orderId, false);
    } catch (error) {
      if (!isCurrent()) return;
      const issue = describePaymentIssue(error);
      setSubmitIssue(issue);
      if (issue.tone === "amber") notify.warning(issue.message);
      else notify.error(issue.message);
      if (["wallet_balance_changed", "wallet_full_payment_required"].includes(issue.code || "")) {
        try {
          const walletResponse = await http.get<WalletQuote>(`/student/orders/${order.orderId}/wallet-quote`);
          if (!isCurrent()) return;
          setWalletQuote(walletResponse.data);
        } catch { /* status check below remains the fallback */ }
      }
      if (["payment_expired", "invoice_not_payable", "package_not_payable", "session_inactive", "wallet_balance_changed", "wallet_full_payment_required"].includes(issue.code || "")) {
        void checkStatus(order.orderId, false);
      }
    } finally {
      walletActionLock.current = false;
      if (isCurrent()) setSubmitting(false);
    }
  };

  const paymentOrderId = order?.orderId;
  useEffect(() => {
    if (!designPreview) return;
    window.bimbelkuPaymentPreview = (action) => {
      if (action === "open") {
        setState("pending");
        setEmbeddedSession({ sessionId: "visual-only", componentsKey: "visual-only", amount: 396000 });
        setCheckoutOpen(true);
      } else if (action === "close") {
        setCheckoutOpen(false);
      } else if (action === "paid") {
        setCheckoutOpen(false);
        setState("paid");
      } else if (action === "pending") {
        setCheckoutOpen(false);
        setSubmitIssue(null);
        setState("pending");
      } else if (action === "failed") {
        setCheckoutOpen(false);
        setState("pending");
        setSubmitIssue({ title: "Pembayaran belum selesai", message: "Ini contoh tampilan saat pembayaran gagal. Tidak ada transaksi yang diproses.", tone: "amber" });
      }
    };
    return () => { delete window.bimbelkuPaymentPreview; };
  }, [designPreview]);
  useEffect(() => {
    if (!checkoutOpen || !paymentOrderId || state !== "pending") return;
    const timer = window.setInterval(() => {
      if (document.visibilityState === "visible") void checkStatus(paymentOrderId, false);
    }, 6000);
    return () => window.clearInterval(timer);
  }, [checkStatus, checkoutOpen, paymentOrderId, state]);

  useEffect(() => {
    if (!checkoutOpen) return;
    const onEscape = (event: KeyboardEvent) => { if (event.key === "Escape") setCheckoutOpen(false); };
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    window.addEventListener("keydown", onEscape);
    return () => { window.removeEventListener("keydown", onEscape); document.body.style.overflow = previousOverflow; };
  }, [checkoutOpen]);

  if (state === "loading") {
    return <StudentLayout title="Pembayaran"><div className="grid min-h-[65vh] place-items-center"><div className="text-center"><Loader2 className="mx-auto h-10 w-10 animate-spin text-indigo-600" /><p className="mt-3 text-sm font-bold text-slate-500">Menyiapkan tagihan…</p></div></div></StudentLayout>;
  }

  const previewNotice = designPreview && <div role="status" className="mx-auto mb-5 w-full max-w-6xl rounded-2xl border-2 border-amber-400 bg-amber-50 px-4 py-3 text-sm font-bold leading-6 text-amber-950">
    PRATINJAU DESAIN LOKAL — bukan tagihan atau pembayaran asli. Perubahan tampilan di halaman ini tidak mengubah database. Pembayaran sungguhan tetap berada di halaman Pembayaran akun murid.
  </div>;

  if (state === "paid") {
    return <StudentLayout title="Pembayaran">
      {previewNotice}
      <div className="mx-auto grid min-h-[65vh] max-w-xl place-items-center pb-16">
        <div className="w-full overflow-hidden rounded-[1.75rem] border border-[#e5ebef] bg-white shadow-[0_24px_80px_-42px_rgba(13,35,54,.38)]">
          <div className="relative overflow-hidden bg-[#102b3f] px-6 pb-8 pt-9 text-center text-white sm:px-9">
            <div className="pointer-events-none absolute -right-12 -top-12 h-48 w-48 rounded-full border border-white/10" />
            <div className="pointer-events-none absolute -left-12 bottom-0 h-32 w-32 rounded-full border border-[#f8b77c]/20" />
            <div className="relative mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-[#dff5e9] text-emerald-700 shadow-lg"><CheckCircle2 size={34} /></div>
            <p className="relative mt-5 text-xs font-black uppercase tracking-[.2em] text-[#f9c49c]">Status pesanan</p>
            <h1 className="relative mt-2 text-2xl font-black sm:text-3xl">Pembayaran diterima</h1>
            <p className="relative mx-auto mt-3 max-w-sm text-sm leading-6 text-slate-200">{isCheapClass ? "Keikutsertaanmu tercatat. Kelas dikonfirmasi setelah pendaftaran ditutup dan kuota minimum terpenuhi." : isPackage ? "Pesananmu aktif. Sistem mulai mencari tutor sesuai jadwal yang kamu pilih." : "Status kelas dan langkah berikutnya dapat dipantau di akunmu."}</p>
          </div>
          <div className="p-6 sm:p-8">
            <p className="text-xs font-black uppercase tracking-[.16em] text-slate-500">Rincian pembayaran</p>
            <dl className="mt-4 space-y-3 text-sm">
              <div className="flex justify-between gap-4 border-b border-slate-100 pb-3"><dt className="text-slate-500">Program</dt><dd className="max-w-[60%] text-right font-bold text-slate-900">{order?.packageName || order?.subject || "Pesanan BimbelKu"}</dd></div>
              <div className="flex justify-between gap-4 border-b border-slate-100 pb-3"><dt className="text-slate-500">Nomor tagihan</dt><dd className="break-all text-right font-semibold text-slate-900">{order?.invoiceId || `#${order?.orderId ?? "–"}`}</dd></div>
              <div className="flex items-end justify-between gap-4 pt-1"><dt className="font-bold text-slate-700">Total dibayar</dt><dd className="text-xl font-black text-[#102b3f]">{rupiah(Number(order?.price || 0))}</dd></div>
            </dl>
            <Button onClick={() => navigate(returnPath)} className="mt-7 min-h-12 w-full rounded-xl bg-[#d45e27] font-black text-white hover:bg-[#b84b1b]">{returnAction}<ArrowRight size={17} className="ml-2" /></Button>
            <button type="button" onClick={() => navigate("/student/dashboard")} className="mt-2 min-h-11 w-full text-sm font-bold text-slate-600 hover:text-slate-900">Ke dashboard</button>
          </div>
        </div>
      </div>
    </StudentLayout>;
  }

  if (loadIssue) {
    return <StudentLayout title="Pembayaran"><Result icon={AlertCircle} color="rose" title="Tagihan belum dapat ditampilkan" text={loadIssue} action="Buka Riwayat Transaksi" onClick={() => navigate("/student/history")} /></StudentLayout>;
  }

  if (state === "refund_pending" || state === "refunded") {
    return <StudentLayout title="Pembayaran"><Result icon={RefreshCw} color="amber" title={state === "refunded" ? "Refund selesai" : "Refund sedang diproses"} text={state === "refunded" ? "Pengembalian dana sudah selesai. Tujuan refund dan mutasi Saldo BimbelKu dapat dilihat pada riwayat transaksi." : "Pembayaranmu masuk antrean refund. Buka riwayat transaksi untuk memilih tujuan: Saldo BimbelKu atau metode pembayaran asal. Pembayaran via Xendit (QRIS, e-wallet, VA) dikembalikan otomatis ke metode asal."} action={state === "refunded" ? "Lihat riwayat transaksi" : "Pilih tujuan refund"} onClick={() => navigate("/student/history")} /></StudentLayout>;
  }

  if (state === "expired" || state === "cancelled" || !order || !settings) {
    return <StudentLayout title="Pembayaran"><Result icon={XCircle} color="rose" title={state === "cancelled" ? (isCheapClass ? "Keikutsertaan dibatalkan" : "Tagihan dibatalkan") : "Tagihan tidak tersedia"} text={state === "cancelled" ? (isCheapClass ? "Kursi sudah dilepas. Kamu dapat bergabung kembali jika pendaftaran masih dibuka dan kuota tersedia." : "Tagihan dan pesanan sudah dibatalkan.") : "Batas pembayaran telah berakhir atau tidak ada tagihan aktif."} action={returnAction} onClick={() => navigate(returnPath)} /></StudentLayout>;
  }

  if (state === "submitted") {
    return (
      <StudentLayout title="Pembayaran">
        <div className="mx-auto grid min-h-[65vh] max-w-2xl place-items-center">
          <div className="w-full rounded-[1.75rem] bg-gradient-to-br from-slate-950 via-indigo-950 to-violet-900 p-5 text-center text-white shadow-2xl sm:rounded-[2.5rem] sm:p-8">
            <div className="relative mx-auto h-36 w-36"><div className="absolute inset-0 animate-ping rounded-full border border-indigo-300/30" /><div className="absolute inset-4 animate-spin rounded-full border-4 border-indigo-400/20 border-t-indigo-300" /><div className="absolute inset-0 grid place-items-center"><ShieldCheck size={42} className="text-indigo-200" /></div></div>
            <p className="mt-6 text-xs font-black uppercase tracking-[.2em] text-indigo-200">Konfirmasi pembayaran otomatis</p><h1 className="mt-3 text-2xl font-black sm:text-3xl">{isCheapClass && order.willRefundIfAccepted ? "Kelas dibatalkan · pembayaran tetap diperiksa" : "Pembayaran sedang dikonfirmasi"}</h1><p className="mx-auto mt-3 max-w-lg text-sm leading-7 text-indigo-100/70 sm:text-base">{isCheapClass && order.willRefundIfAccepted ? "Dana sudah diterima, tetapi kelas tidak dapat dilanjutkan. Sistem sedang menyiapkan refund penuh tanpa mengaktifkan peserta tambahan." : "Jangan membayar ulang. BimbelKu sedang menunggu konfirmasi pembayaran dan akan memperbarui status tagihan secara otomatis."}</p>
            <div className="mt-7 flex flex-col justify-center gap-3 sm:flex-row"><Button variant="outline" onClick={() => checkStatus(order.orderId)} className="rounded-xl border-white/20 bg-white/10 text-white hover:bg-white/20 hover:text-white"><RefreshCw size={16} className="mr-2" />Periksa sekarang</Button><Button onClick={() => navigate("/student/dashboard")} className="rounded-xl bg-white text-indigo-950 hover:bg-indigo-50">Ke dashboard</Button></div>
          </div>
        </div>
      </StudentLayout>
    );
  }

  if (settings.provider === "xendit") {
    const returnedFromGateway = new URLSearchParams(location.search).get("gateway");
    return (
      <StudentLayout title="Pembayaran">
        {previewNotice}
        <div className="mx-auto w-full max-w-6xl space-y-5 pb-20">
          {!designPreview && import.meta.env.VITE_PAYMENT_DESIGN_PREVIEW === "true" && ["localhost", "127.0.0.1"].includes(window.location.hostname) && <div className="rounded-2xl border border-amber-300 bg-amber-50 p-4 text-sm leading-6 text-amber-950">
            <strong className="block">Ingin menilai tampilan pembayaran di dalam BimbelKu?</strong>
            <span>Pada HTTP lokal, tagihan asli membuka checkout Xendit. </span>
            <button type="button" onClick={() => navigate("/payment-preview")} className="font-black text-orange-800 underline underline-offset-2">Buka pratinjau desain tanpa transaksi</button>
            <span>. Pratinjau tidak mengubah status pesanan ini.</span>
          </div>}
          <button type="button" onClick={() => navigate(returnPath)} className="inline-flex min-h-10 items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950"><ArrowLeft size={17} />Kembali ke pesanan</button>
          {returnedFromGateway === "cancelled" && <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm font-semibold text-amber-900">Pembayaran belum selesai. Tagihan tetap aktif selama waktu pembayaran masih tersedia.</div>}
          {returnedFromGateway === "success" && <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900">Pembayaran telah dikirim. Status sedang dipastikan dari sistem pembayaran.</div>}
          {submitIssue && <div role="alert" className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-900"><p className="font-black">{submitIssue.title}</p><p className="mt-1 text-sm leading-6">{submitIssue.message}</p></div>}
          <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1.05fr)_minmax(360px,.95fr)] lg:gap-7">
            <section className="min-w-0 space-y-5">
              <div className="rounded-[1.75rem] border border-[#e3ebef] bg-white p-5 shadow-[0_20px_50px_-36px_rgba(13,35,54,.26)] sm:p-8">
                <span className="inline-flex items-center gap-2 text-xs font-black uppercase tracking-[.17em] text-[#ba5527]"><ShieldCheck size={16} />Pembayaran BimbelKu</span>
                <h1 className="mt-3 max-w-lg text-2xl font-black leading-tight text-[#102b3f] sm:text-4xl">Satu langkah lagi sebelum mulai belajar.</h1>
                <p className="mt-3 max-w-xl text-sm leading-6 text-slate-600">Periksa rincian pesanan, lalu pilih cara bayar yang nyaman. Pencarian tutor dimulai setelah pembayaran terkonfirmasi.</p>
                <div className="relative mt-6 overflow-hidden rounded-[1.4rem] bg-[#102b3f] p-5 text-white sm:p-7">
                  <div className="pointer-events-none absolute -right-12 -top-16 h-52 w-52 rounded-full border border-[#f7b57d]/20" />
                  <div className="pointer-events-none absolute -right-5 -top-9 h-36 w-36 rounded-full border border-[#f7b57d]/30" />
                  <div className="pointer-events-none absolute bottom-0 right-10 h-20 w-20 rotate-12 rounded-2xl bg-white/[.04]" />
                  <div className="relative flex items-start justify-between gap-4"><div><p className="text-xs font-bold uppercase tracking-[.16em] text-[#f9c49c]">Tagihan aktif</p><p className="mt-2 break-all text-sm font-bold text-slate-100">{order.invoiceId || `#${order.orderId}`}</p></div><div className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-white/10"><CreditCard size={21} /></div></div>
                  <p className="relative mt-7 text-sm text-slate-300">Total yang perlu dibayar</p>
                  <p className="relative mt-1 text-3xl font-black tracking-tight sm:text-4xl">{rupiah(Number(order.outstandingAmount) > 0 ? Number(order.outstandingAmount) : order.price)}</p>
                  {order.paymentDueAt && <div className="relative mt-6 flex items-center gap-2 border-t border-white/15 pt-4 text-sm"><Clock3 size={16} className="text-[#f9c49c]" /><span className="text-slate-300">Sisa waktu pembayaran</span><strong className="ml-auto font-mono text-base text-white">{timeText}</strong></div>}
                </div>
                <dl className="mt-6 space-y-3 text-sm">
                  <div className="flex justify-between gap-4 border-b border-slate-100 pb-3"><dt className="text-slate-500">Program</dt><dd className="max-w-[60%] text-right font-bold text-slate-900">{order.packageName || order.subject || "Pesanan BimbelKu"}</dd></div>
                  <div className="flex justify-between gap-4 border-b border-slate-100 pb-3"><dt className="text-slate-500">Jenis kelas</dt><dd className="text-right font-bold text-slate-900">{order.type || "Belajar privat"}</dd></div>
                  {Boolean(order.discountAmount && order.discountAmount > 0) && <div className="flex justify-between gap-4 border-b border-slate-100 pb-3"><dt className="text-slate-500">Diskon</dt><dd className="font-bold text-emerald-700">−{rupiah(order.discountAmount || 0)}</dd></div>}
                </dl>
              </div>
            </section>
            <section className="min-w-0 rounded-[1.75rem] border border-[#e3ebef] bg-white p-5 shadow-[0_20px_50px_-36px_rgba(13,35,54,.26)] sm:p-8">
              <div className="flex items-start gap-3"><div className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-[#fff0e6] text-[#bb5526]"><WalletCards size={21} /></div><div><p className="text-xs font-black uppercase tracking-[.14em] text-[#ba5527]">Cara bayar</p><h2 className="mt-1 text-xl font-black text-[#102b3f]">Pilih metode pembayaran</h2></div></div>
              <p className="mt-4 text-sm leading-6 text-slate-600">Pilih salah satu cara bayar. Saldo BimbelKu dapat dipakai sebagian: sisa tagihan dibayar otomatis lewat Xendit. Data kartu atau akun pembayaran tidak disimpan oleh BimbelKu.</p>
              <div className="mt-6 space-y-3">
                {walletSupported && !isTopUp && (
                  <div className={`rounded-xl border p-4 ${useWallet ? "border-emerald-200 bg-emerald-50" : "border-slate-200 bg-slate-50"}`}>
                    <div className="flex items-start justify-between gap-3">
                      <div className="min-w-0">
                        <p className="flex items-center gap-2 font-black text-slate-900"><WalletCards size={18} className="text-emerald-700" />Saldo BimbelKu</p>
                        <p className="mt-1 text-xs leading-5 text-slate-600">{walletUsable ? "Saldo mencukupi seluruh tagihan. Bayar penuh tanpa Xendit." : "Saldo dapat dipakai sebagian. Sisa tagihan dibayar lewat Xendit."}</p>
                      </div>
                      <p className="shrink-0 font-black text-emerald-700">{rupiah(walletQuote.balance)}</p>
                    </div>
                    {walletAvailable ? (
                      <>
                        <button type="button" disabled={submitting || paymentExpired} onClick={() => setUseWallet((current) => !current)} className={`mt-3 flex min-h-12 w-full items-center justify-between rounded-xl border px-3 text-left text-sm font-black transition disabled:cursor-not-allowed disabled:opacity-50 ${useWallet ? "border-emerald-400 bg-white text-emerald-800" : "border-emerald-300 bg-white text-emerald-800"}`}>
                          <span>{walletUsable ? `Bayar penuh dengan saldo (${rupiah(walletBalance)})` : `Pakai saldo ${rupiah(Math.min(walletBalance, Number(order.price || 0)))}, sisanya via Xendit`}</span>
                          <span className={`grid h-5 w-5 place-items-center rounded-md border ${useWallet ? "border-emerald-600 bg-emerald-600 text-white" : "border-slate-300"}`}>{useWallet ? <Check size={13} /> : null}</span>
                        </button>
                        {useWallet && (
                          <div className="mt-3 space-y-2">
                            {paymentPinConfigured !== true ? (
                              <>
                                <p className="text-xs font-semibold leading-5 text-emerald-900">Buat PIN pembayaran sekali untuk mengamankan pemakaian saldo.</p>
                                {accountHasPassword ? (
                                  <Input type="password" autoComplete="current-password" placeholder="Password akun" className="h-11 rounded-xl bg-white" value={pinPassword} onChange={(event) => setPinPassword(event.target.value)} />
                                ) : (
                                  <div className="rounded-xl border border-emerald-200 bg-white p-3 text-xs leading-5 text-emerald-900">
                                    <p className="font-black">Akun Google belum punya password.</p>
                                    <p className="mt-1">Kamu bisa membuat PIN langsung di sini. Untuk membuat password akun, buka Pengaturan.</p>
                                    <button type="button" onClick={() => navigate("/student/settings")} className="mt-2 font-black text-emerald-700 underline">Buka Pengaturan</button>
                                  </div>
                                )}
                                <Input inputMode="numeric" maxLength={6} placeholder="PIN baru (6 angka)" className="h-11 rounded-xl bg-white" value={paymentPin} onChange={(event) => setPaymentPin(event.target.value.replace(/\D/g, "").slice(0, 6))} />
                                <Input inputMode="numeric" maxLength={6} placeholder="Ulangi PIN baru" className="h-11 rounded-xl bg-white" value={pinConfirmation} onChange={(event) => setPinConfirmation(event.target.value.replace(/\D/g, "").slice(0, 6))} />
                                <Button type="button" disabled={settingPin} onClick={() => void setupPaymentPin()} className="h-11 w-full rounded-xl bg-emerald-700 font-black text-white hover:bg-emerald-800">{settingPin ? <Loader2 size={16} className="mr-2 animate-spin" /> : null}Simpan PIN</Button>
                              </>
                            ) : (
                              <>
                                <Label className="font-black text-emerald-900">PIN pembayaran</Label>
                                <Input type="password" inputMode="numeric" autoComplete="one-time-code" maxLength={6} className="h-12 rounded-xl bg-white" value={paymentPin} onChange={(event) => setPaymentPin(event.target.value.replace(/\D/g, "").slice(0, 6))} placeholder="Masukkan 6 angka" />
                                <div className="rounded-xl bg-white p-3 text-xs">
                                  <div className="flex items-center justify-between gap-3"><span className="font-bold text-slate-500">Dari saldo</span><span className="font-black text-emerald-700">{rupiah(walletAmount)}</span></div>
                                  {externalDue > 0.01 && <div className="mt-1 flex items-center justify-between gap-3"><span className="font-bold text-slate-500">Sisa via Xendit</span><span className="font-black text-[#bb5526]">{rupiah(externalDue)}</span></div>}
                                </div>
                                <Button type="button" disabled={submitting || paymentPin.length !== 6} onClick={() => void (walletOnly ? payWithWallet() : openXenditCheckout())} className="h-12 w-full rounded-xl bg-emerald-700 font-black text-white hover:bg-emerald-800">
                                  {submitting ? <Loader2 size={18} className="mr-2 animate-spin" /> : <WalletCards size={18} className="mr-2" />}
                                  {walletOnly ? `Bayar ${rupiah(walletAmount)} dengan saldo` : `Lanjut bayar sisa ${rupiah(externalDue)} via Xendit`}
                                </Button>
                              </>
                            )}
                            <button type="button" onClick={() => { setUseWallet(false); setPaymentPin(""); }} className="w-full text-xs font-black text-slate-600 underline">Pilih metode lain</button>
                          </div>
                        )}
                      </>
                    ) : (
                      <p className="mt-3 rounded-xl bg-white p-3 text-xs font-semibold leading-5 text-slate-600">Saldo belum tersedia untuk tagihan ini. Gunakan QRIS, VA, atau e-wallet lewat Xendit.</p>
                    )}
                  </div>
                )}
                <div className="flex items-center gap-3 text-[10px] font-black uppercase tracking-widest text-slate-400"><span className="h-px flex-1 bg-slate-200" />atau<span className="h-px flex-1 bg-slate-200" /></div>
                {!useWallet && <button type="button" disabled={submitting || paymentExpired || !settings.xendit_configured} onClick={() => void openXenditCheckout()} className="flex min-h-16 w-full items-center gap-3 rounded-xl border border-slate-200 p-4 text-left transition hover:border-[#d45e27] hover:bg-[#fff8f3] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#d45e27] disabled:cursor-not-allowed disabled:opacity-50"><div className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-[#fff0e6] text-[#bb5526]"><CreditCard size={20} /></div><div className="min-w-0"><p className="font-bold text-slate-900">{submitting ? "Menyiapkan metode…" : "Lanjut ke Xendit"}</p><p className="text-xs leading-5 text-slate-500">Pilih QRIS, VA, atau e-wallet di halaman pembayaran aman</p></div>{submitting ? <Loader2 size={18} className="ml-auto shrink-0 animate-spin text-[#d45e27]" /> : <ArrowRight size={18} className="ml-auto shrink-0 text-[#d45e27]" />}</button>}
                <div className="flex items-start gap-3 rounded-xl bg-[#f4f7fa] p-4 text-sm leading-6 text-slate-600"><CheckCircle2 size={18} className="mt-0.5 shrink-0 text-emerald-600" />Status diperbarui otomatis setelah pembayaran berhasil. Kamu tidak perlu mengunggah bukti transfer.</div>
              </div>
              {legacyCheckoutUrl && <><a href={legacyCheckoutUrl} target="_blank" rel="noopener noreferrer" className="mt-6 flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-[#d45e27] px-4 text-sm font-black text-white hover:bg-[#b84b1b]">Lanjutkan ke halaman pembayaran<ArrowRight size={17} /></a><p className="mt-2 text-center text-xs leading-5 text-slate-500">Di HTTP lokal, pilihan metode dibuka di halaman pembayaran aman. Setelah selesai, kembali ke sini lalu periksa status tagihan. Tautan ini membuka sesi tagihan yang sama.</p></>}
              {!settings.xendit_configured && <p className="mt-3 text-center text-xs font-bold leading-5 text-rose-700">Pembayaran otomatis belum tersedia. Hubungi admin BimbelKu.</p>}
              <button type="button" onClick={() => void checkStatus(order.orderId)} className="mt-2 flex min-h-11 w-full items-center justify-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-900"><RefreshCw size={15} />Periksa status tagihan</button>
              <p className="mt-4 flex items-start gap-2 border-t border-slate-100 pt-4 text-xs leading-5 text-slate-500"><ShieldCheck size={16} className="mt-0.5 shrink-0 text-emerald-600" />Nominal dikunci oleh tagihan. Menutup panel pembayaran tidak membuat tagihan baru.</p>
            </section>
          </div>
        </div>
        {checkoutOpen && embeddedSession && createPortal(
          <div className="fixed inset-0 z-[100] flex items-end justify-center bg-[#0c1f30]/65 sm:items-center sm:p-5" onMouseDown={(event) => { if (event.target === event.currentTarget) setCheckoutOpen(false); }}>
            <section role="dialog" aria-modal="true" aria-labelledby="payment-dialog-title" className="flex max-h-[94dvh] w-full max-w-2xl flex-col overflow-hidden rounded-t-[1.75rem] bg-white shadow-2xl sm:max-h-[90dvh] sm:rounded-[1.75rem]">
              <div className="mx-auto mt-2 h-1.5 w-12 rounded-full bg-slate-200 sm:hidden" />
              <div className="flex items-start justify-between gap-4 border-b border-slate-100 px-5 pb-4 pt-5 sm:px-8 sm:pt-7"><div><p className="text-xs font-black uppercase tracking-[.15em] text-[#ba5527]">Langkah pembayaran</p><h2 id="payment-dialog-title" className="mt-1 text-xl font-black text-[#102b3f] sm:text-2xl">Pilih cara bayar</h2><p className="mt-1 text-sm text-slate-600">Pilih metode di bawah, lalu tekan tombol oranye untuk mengonfirmasi pilihanmu.</p></div><button type="button" onClick={() => setCheckoutOpen(false)} aria-label="Tutup pilihan pembayaran" className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-slate-100 text-slate-700 hover:bg-slate-200"><X size={19} /></button></div>
              <div className="min-h-0 overflow-y-auto px-5 pb-[max(1.5rem,env(safe-area-inset-bottom))] pt-5 sm:px-8 sm:py-7">
                <Suspense fallback={<div className="flex items-center justify-center gap-2 py-10 text-sm text-slate-600"><Loader2 size={18} className="animate-spin" />Memuat pilihan…</div>}>
                  {designPreview ? <PaymentDesignPreview amount={embeddedSession.amount} onComplete={() => { setCheckoutOpen(false); setState("paid"); }} /> : <EmbeddedCheckout
                    key={embeddedSession.sessionId}
                    sessionId={embeddedSession.sessionId}
                    componentsKey={embeddedSession.componentsKey}
                    amount={embeddedSession.amount}
                    resume={new URLSearchParams(location.search).get("checkout") === "resume"}
                    onComplete={() => void checkStatus(order.orderId, false)}
                    onFailure={(message) => { setSubmitIssue({ title: "Pembayaran belum selesai", message, tone: "amber" }); setCheckoutOpen(false); void checkStatus(order.orderId, false); }}
                  />}
                </Suspense>
              </div>
            </section>
          </div>, document.body)}
      </StudentLayout>
    );
  }

  return (
    <StudentLayout title="Pembayaran">
      <div className="mx-auto max-w-6xl space-y-6 pb-12">
        <button type="button" onClick={() => navigate(returnPath)} className="inline-flex items-center gap-2 text-sm font-bold text-slate-500 hover:text-slate-900"><ArrowLeft size={17} />Kembali</button>
        {order.packageName && <div className="rounded-3xl border border-slate-100 bg-white p-5 shadow-sm"><OrderProgress status="awaiting_payment" /></div>}
        {isTopUp && <div className="flex gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-900"><AlertCircle className="shrink-0" /><div><p className="font-black">Pembayaran masih kurang {rupiah(order.outstandingAmount || 0)}</p><p className="mt-1 text-sm leading-6">Dana {rupiah(order.externalReceivedAmount || 0)} sudah aman tercatat. Unggah bukti transfer tambahan hanya untuk sisa tagihan. Saldo BimbelKu yang sudah dialokasikan tidak ditarik ulang.</p></div></div>}
        {reason && !isTopUp && <div className="flex gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-5 text-rose-800"><AlertCircle className="shrink-0" /><div><p className="font-black">Bukti sebelumnya ditolak</p><p className="mt-1 text-sm leading-6">{reason}</p></div></div>}
        {submitIssue && (
          <div className={`flex gap-3 rounded-2xl border p-5 ${submitIssue.tone === "amber" ? "border-amber-200 bg-amber-50 text-amber-900" : "border-rose-200 bg-rose-50 text-rose-800"}`}>
            <AlertCircle className="shrink-0" />
            <div className="min-w-0">
              <p className="font-black">{submitIssue.title}</p>
              <p className="mt-1 break-words text-sm leading-6">{submitIssue.message}</p>
              {submitIssue.code === "network_error" && (
                <Button type="button" variant="outline" size="sm" className="mt-3 rounded-lg bg-white" onClick={() => void checkStatus(order.orderId)}>Periksa status pembayaran</Button>
              )}
            </div>
          </div>
        )}
        {externalDue > 0.01 && !paymentDestinationReady && !qrisLoading && <div className="flex gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-900"><AlertCircle className="shrink-0" /><div><p className="font-black">Metode pembayaran belum tersedia</p><p className="mt-1 text-sm leading-6">Admin perlu mengaktifkan QRIS atau melengkapi rekening tujuan. Jangan membayar sebelum salah satu metode tampil lengkap.</p></div></div>}
        {paymentExpired && <div className="rounded-2xl border border-rose-200 bg-rose-50 p-5 text-sm font-bold text-rose-700">Batas waktu telah berakhir. Muat ulang status untuk menutup tagihan.</div>}

        <div className="grid items-start gap-7 lg:grid-cols-[.9fr_1.1fr]">
          <section className="overflow-hidden rounded-[2rem] border border-slate-100 bg-white shadow-sm">
            <div className="bg-gradient-to-br from-slate-950 to-indigo-950 p-5 text-white sm:p-6"><div className="flex flex-wrap items-start justify-between gap-3"><div className="min-w-0"><p className="text-xs font-bold uppercase tracking-widest text-indigo-200">Tagihan</p><p className="mt-2 break-all font-mono text-lg font-black sm:text-xl">{order.invoiceId || `#${order.orderId}`}</p></div><span className="rounded-full bg-orange-500 px-3 py-1.5 text-xs font-bold">Belum dibayar</span></div></div>
            <div className="space-y-5 p-5 sm:p-6">
              <div><p className="text-xs font-bold uppercase tracking-widest text-slate-500">{order.packageName ? "Paket" : "Kelas"}</p><h1 className="mt-2 text-xl font-black text-slate-900 sm:text-2xl">{order.packageName || order.subject}</h1>{order.packageName && <p className="mt-1 text-sm font-semibold text-slate-600">{order.subject}</p>}<p className="mt-1 text-sm text-slate-500">{order.type}{order.packageName ? ` · ${order.durationHours || 1} jam/pertemuan · tutor dicari setelah pembayaran` : ` · tutor ${order.tutorName}`}</p>{Boolean(order.totalLearningHours) && <p className="mt-2 inline-flex rounded-full bg-indigo-50 px-3 py-1 text-xs font-black text-indigo-700">Total {order.totalLearningHours} jam belajar</p>}</div>
              <div className="flex flex-col gap-2 rounded-2xl bg-indigo-50 p-4 sm:flex-row sm:items-end sm:justify-between">
                <span className="text-sm font-bold text-indigo-700">{walletAmount > 0 ? "Sisa yang ditransfer" : "Total transfer"}</span>
                <div className="text-left sm:text-right">
                  {Boolean(order.discountAmount && order.discountAmount > 0) && (
                    <div className="mb-1 flex items-center gap-2 sm:justify-end">
                      <span className="text-xs font-semibold text-slate-500 line-through">{rupiah(order.subtotalAmount || order.price)}</span>
                      <span className="rounded-full bg-orange-100 px-2 py-0.5 text-[10px] font-black text-orange-700">
                        {Math.round(((order.discountAmount || 0) / Math.max(order.subtotalAmount || order.price, 1)) * 100)}%
                      </span>
                    </div>
                  )}
                  <span className="text-xl font-black text-indigo-950 sm:text-2xl">{rupiah(externalDue)}</span>
                  {Boolean(order.discountAmount && order.discountAmount > 0) && <p className="mt-1 text-[11px] font-bold text-emerald-700">Hemat {rupiah(order.discountAmount || 0)}</p>}
                </div>
              </div>
              {order.paymentDueAt && <div className="flex items-center justify-between rounded-2xl border border-amber-100 bg-amber-50 p-4 text-amber-800"><span className="flex items-center gap-2 text-sm font-bold"><Clock3 size={17} />Sisa waktu</span><span className="font-mono text-lg font-black">{timeText}</span></div>}
              {walletQuote?.supported && !isTopUp && (
                <div className={`rounded-2xl border p-4 ${useWallet && walletUsable ? "border-indigo-200 bg-indigo-50" : "border-slate-100 bg-slate-50"}`}>
                  <div className="flex items-start justify-between gap-4">
                    <div className="min-w-0">
                      <p className="flex items-center gap-2 text-sm font-black text-slate-900"><WalletCards size={17} className="text-indigo-600" />Saldo BimbelKu</p>
                      <p className="mt-1 text-xs leading-5 text-slate-500">Store credit dari refund. Bisa dipakai untuk biaya belajar dan tidak dapat ditarik tunai.</p>
                    </div>
                    <p className="shrink-0 text-base font-black text-indigo-700">{rupiah(walletQuote.balance)}</p>
                  </div>
                  {walletQuote.reserved_balance > 0 && <p className="mt-2 text-xs font-bold text-amber-700">{rupiah(walletQuote.reserved_balance)} sedang dialokasikan ke tagihan lain.</p>}
                  {walletUsable ? (
                    <>
                      <button
                        type="button"
                        onClick={() => setUseWallet((current) => !current)}
                        className={`mt-3 flex min-h-11 w-full items-center justify-between rounded-xl border px-3 text-left text-sm font-black transition ${useWallet ? "border-indigo-400 bg-white text-indigo-800" : "border-slate-200 bg-white text-slate-700"}`}
                      >
                        <span>Bayar penuh dengan saldo ({rupiah(walletQuote.usable_amount)})</span>
                        <span className={`grid h-5 w-5 place-items-center rounded-md border ${useWallet ? "border-indigo-600 bg-indigo-600 text-white" : "border-slate-300"}`}>{useWallet ? <Check size={13} /> : null}</span>
                      </button>
                      {useWallet && <div className="mt-3 rounded-xl bg-white p-3 text-xs"><p className="font-bold text-slate-500">Dari saldo</p><p className="mt-1 font-black text-indigo-700">{rupiah(walletAmount)}</p><p className="mt-1 text-emerald-700">Saldo menutup seluruh tagihan. Tidak perlu transfer.</p></div>}
                    </>
                  ) : (
                    <p className="mt-3 rounded-xl bg-white p-3 text-xs font-semibold leading-5 text-slate-600">Saldo belum mencukupi seluruh tagihan. Pembayaran saldo hanya tersedia bila saldo menutup penuh; gunakan QRIS atau transfer bank untuk tagihan ini.</p>
                  )}
                </div>
              )}
              <div className="rounded-2xl border border-slate-100 p-4">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <p className="text-xs font-bold uppercase tracking-widest text-slate-500">Pilih cara bayar</p>
                    <p className="mt-1 text-sm font-semibold text-slate-700">Bayar memakai QRIS atau transfer bank.</p>
                  </div>
                  {qrisPaymentReady && <span className="rounded-full bg-emerald-50 px-2.5 py-1 text-[10px] font-black text-emerald-700">QRIS aktif</span>}
                </div>

                {walletOnly ? <div className="mt-4 rounded-xl bg-emerald-50 p-4 text-sm font-bold text-emerald-800">Saldo mencukupi seluruh tagihan. Tidak perlu transfer atau unggah bukti pembayaran.</div> : <><div className="mt-4 grid grid-cols-2 gap-2">
                  <button
                    type="button"
                    disabled={!qrisPaymentReady}
                    onClick={() => setPaymentMethod("qris")}
                    className={`rounded-xl border p-3 text-left transition ${paymentMethod === "qris" && qrisPaymentReady ? "border-indigo-500 bg-indigo-50 text-indigo-900" : "border-slate-200 bg-white text-slate-600"} disabled:cursor-not-allowed disabled:opacity-55`}
                  >
                    <span className="flex items-center gap-2 text-sm font-black"><QrCode size={17} />QRIS</span>
                    <span className="mt-1 block text-[11px] font-semibold">{qrisLoading ? "Sedang dimuat" : qrisPaymentReady ? "Scan dari aplikasi pembayaran" : qrisLoadFailed ? "Gagal dimuat" : "Belum diaktifkan admin"}</span>
                  </button>
                  <button
                    type="button"
                    disabled={!bankPaymentReady}
                    onClick={() => setPaymentMethod("bank")}
                    className={`rounded-xl border p-3 text-left transition ${paymentMethod === "bank" && bankPaymentReady ? "border-indigo-500 bg-indigo-50 text-indigo-900" : "border-slate-200 bg-white text-slate-600"} disabled:cursor-not-allowed disabled:opacity-55`}
                  >
                    <span className="flex items-center gap-2 text-sm font-black"><Building2 size={17} />Transfer bank</span>
                    <span className="mt-1 block text-[11px] font-semibold">{bankPaymentReady ? settings.bank_name : "Belum tersedia"}</span>
                  </button>
                </div>

                {paymentMethod === "qris" && qrisPaymentReady && qrisObjectUrl ? (
                  <div className="mt-4 rounded-2xl border-2 border-indigo-100 bg-indigo-50/40 p-4 text-center">
                    <button
                      type="button"
                      aria-label={`Perbesar QRIS ${settings.merchant_name}`}
                      onClick={() => showFilePreview({
                        url: qrisObjectUrl,
                        filename: `QRIS ${settings.merchant_name}`,
                        contentType: "image/png",
                      })}
                      className="group relative block w-full overflow-hidden rounded-xl bg-white p-3 shadow-sm focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200"
                    >
                      <img src={qrisObjectUrl} alt={`QRIS ${settings.merchant_name}`} loading="lazy" decoding="async" className="mx-auto max-h-[22rem] w-full object-contain" />
                      <span className="absolute bottom-4 right-4 rounded-lg bg-slate-950/80 px-3 py-1.5 text-xs font-black text-white shadow-lg transition group-hover:bg-indigo-700">Perbesar</span>
                    </button>
                    <p className="mt-3 text-base font-black text-slate-900">Scan QRIS {settings.merchant_name}</p>
                    <p className="mt-1 text-sm font-bold text-indigo-700">Bayar tepat {rupiah(externalDue)}</p>
                    <Button
                      type="button"
                      variant="outline"
                      className="mt-3 w-full rounded-xl bg-white"
                      onClick={() => showFilePreview({
                        url: qrisObjectUrl,
                        filename: `QRIS ${settings.merchant_name}`,
                        contentType: "image/png",
                      })}
                    >
                      <QrCode size={16} className="mr-2" />Perbesar QRIS
                    </Button>
                    <ol className="mt-4 space-y-2 text-left text-xs leading-5 text-slate-600">
                      <li><strong>1.</strong> Buka aplikasi bank atau e-wallet yang mendukung QRIS.</li>
                      <li><strong>2.</strong> Scan gambar QRIS di atas.</li>
                      <li><strong>3.</strong> Masukkan nominal tepat sesuai sisa tagihan yang harus ditransfer.</li>
                      <li><strong>4.</strong> Simpan bukti pembayaran, lalu unggah pada formulir di sebelahnya.</li>
                    </ol>
                  </div>
                ) : paymentMethod === "bank" && bankPaymentReady ? (
                  <div className="mt-4 rounded-2xl border border-slate-100 p-4">
                    <p className="text-xs font-bold uppercase tracking-widest text-slate-500">Rekening admin</p>
                    <div className="mt-4 space-y-3 text-sm">
                      <Row icon={Building2} label="Bank" value={settings.bank_name} />
                      <Row icon={UserRound} label="Atas nama" value={settings.account_name} />
                      <div className="flex items-center justify-between gap-3 rounded-xl bg-slate-50 p-3">
                        <div><p className="text-xs text-slate-500">Nomor rekening</p><p className="mt-1 font-mono font-black text-slate-900">{settings.account_number}</p></div>
                        <Button type="button" aria-label="Salin nomor rekening" variant="ghost" size="icon" onClick={() => copy(settings.account_number, "Nomor rekening")}><Copy size={17} /></Button>
                      </div>
                    </div>
                  </div>
                ) : null}

                {qrisLoading && <div className="mt-4 flex items-center justify-center gap-2 rounded-xl bg-slate-50 p-4 text-sm font-bold text-slate-600"><Loader2 size={17} className="animate-spin" />Memuat QRIS pembayaran…</div>}
                {qrisLoadFailed && (
                  <div className="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-4 text-left">
                    <div className="flex gap-3 text-rose-800"><ImageOff className="shrink-0" size={19} /><div><p className="text-sm font-black">QRIS gagal dimuat</p><p className="mt-1 text-xs leading-5">Gunakan transfer bank sementara atau muat ulang gambar QRIS.</p></div></div>
                    <Button type="button" variant="outline" size="sm" className="mt-3 w-full rounded-lg border-rose-200 bg-white text-rose-700" onClick={() => void fetchQris()}><RefreshCw size={14} className="mr-2" />Muat ulang QRIS</Button>
                  </div>
                )}
                {!settings.qris_available && <p className="mt-3 rounded-xl bg-amber-50 p-3 text-xs font-semibold leading-5 text-amber-800">QRIS belum diaktifkan admin. Pembayaran tetap dapat dilakukan melalui transfer bank.</p>}
                </>}
              </div>
              <p className="flex gap-2 text-xs leading-5 text-slate-500"><ShieldCheck className="shrink-0 text-emerald-600" size={17} />{walletOnly ? "Pembayaran saldo diverifikasi otomatis oleh backend." : "Transfer eksternal tetap diperiksa admin sebelum pembayaran diterima."}</p>
            </div>
          </section>

          <form onSubmit={upload} className="rounded-[1.75rem] border border-slate-100 bg-white p-5 shadow-sm sm:rounded-[2rem] md:p-8">
            <p className="text-xs font-black uppercase tracking-[.2em] text-indigo-500">Konfirmasi pembayaran</p><h2 className="mt-2 text-2xl font-black text-slate-900">{walletOnly ? "Bayar dengan Saldo BimbelKu" : reason ? "Unggah bukti pengganti" : "Kirim bukti pembayaran"}</h2><p className="mt-2 text-sm leading-6 text-slate-500">{walletOnly ? "Saldo akan dipotong oleh backend dan pembayaran diproses otomatis tanpa pemeriksaan bukti transfer." : walletAmount > 0 ? `Transfer hanya sisa tagihan sebesar ${rupiah(externalDue)}, lalu unggah buktinya.` : "Pastikan nama pengirim, tujuan pembayaran, waktu, dan nominal terlihat jelas."}</p>
            {useWallet && paymentPinConfigured === false && <div className="mt-7 rounded-2xl border border-amber-200 bg-amber-50 p-4">
              <p className="font-black text-amber-900">Buat atau reset PIN pembayaran</p>
              <p className="mt-1 text-xs leading-5 text-amber-800">{accountHasPassword ? "Akun lama cukup membuat PIN sekali. Tidak ada SMS atau biaya tambahan." : "Akun Google belum memiliki password lokal. Kamu bisa membuat PIN langsung di sini, atau membuat password dulu melalui Profil."}</p>
              {accountHasPassword ? (
                <Input type="password" autoComplete="current-password" placeholder="Password akun" className="mt-3 h-11 rounded-xl bg-white" value={pinPassword} onChange={(event) => setPinPassword(event.target.value)} />
              ) : (
                <button type="button" onClick={() => navigate("/student/profile")} className="mt-3 w-full rounded-xl border border-amber-300 bg-white px-3 py-2 text-xs font-black text-amber-900 underline">Buat password akun di Profil</button>
              )}
              <Input inputMode="numeric" maxLength={6} placeholder="PIN baru (6 angka)" className="mt-2 h-11 rounded-xl bg-white" value={paymentPin} onChange={(event) => setPaymentPin(event.target.value.replace(/\D/g, "").slice(0, 6))} />
              <Input inputMode="numeric" maxLength={6} placeholder="Ulangi PIN baru" className="mt-2 h-11 rounded-xl bg-white" value={pinConfirmation} onChange={(event) => setPinConfirmation(event.target.value.replace(/\D/g, "").slice(0, 6))} />
              <Button type="button" disabled={settingPin} onClick={() => void setupPaymentPin()} className="mt-3 h-11 w-full rounded-xl bg-amber-600 font-black hover:bg-amber-700">{settingPin ? <Loader2 size={16} className="mr-2 animate-spin" /> : null}Simpan PIN dan lanjut</Button>{paymentPinConfigured === true && pinResetRequested && <button type="button" className="mt-3 w-full text-xs font-black text-amber-800 underline" onClick={() => { setPinResetRequested(false); setPaymentPin(""); setPinPassword(""); setPinConfirmation(""); }}>Kembali ke masukkan PIN</button>}
            </div>}
            {useWallet && paymentPinConfigured === true && pinResetRequested && <div className="mt-7 rounded-2xl border border-amber-200 bg-amber-50 p-4">
              <p className="font-black text-amber-900">Reset PIN melalui email</p>
              <p className="mt-1 text-xs leading-5 text-amber-800">Kode OTP 6 angka dikirim ke email akunmu dan berlaku selama 10 menit.</p>
              {!pinResetSent ? (
                <Button type="button" disabled={requestingPinReset} onClick={() => void requestPinReset()} className="mt-3 h-11 w-full rounded-xl bg-amber-600 font-black hover:bg-amber-700">
                  {requestingPinReset ? <Loader2 size={16} className="mr-2 animate-spin" /> : null}
                  Kirim kode OTP
                </Button>
              ) : (
                <>
                  <Input inputMode="numeric" autoComplete="one-time-code" maxLength={6} placeholder="Kode OTP dari email" className="mt-3 h-11 rounded-xl bg-white text-center font-black tracking-[.3em]" value={pinResetCode} onChange={(event) => setPinResetCode(event.target.value.replace(/\D/g, "").slice(0, 6))} />
                  <Input type="password" inputMode="numeric" maxLength={6} placeholder="PIN baru (6 angka)" className="mt-2 h-11 rounded-xl bg-white" value={paymentPin} onChange={(event) => setPaymentPin(event.target.value.replace(/\D/g, "").slice(0, 6))} />
                  <Input type="password" inputMode="numeric" maxLength={6} placeholder="Ulangi PIN baru" className="mt-2 h-11 rounded-xl bg-white" value={pinConfirmation} onChange={(event) => setPinConfirmation(event.target.value.replace(/\D/g, "").slice(0, 6))} />
                  <Button type="button" disabled={settingPin} onClick={() => void resetPaymentPin()} className="mt-3 h-11 w-full rounded-xl bg-amber-600 font-black hover:bg-amber-700">
                    {settingPin ? <Loader2 size={16} className="mr-2 animate-spin" /> : null}
                    Simpan PIN baru
                  </Button>
                  <button type="button" disabled={requestingPinReset} className="mt-3 w-full text-xs font-black text-amber-800 underline" onClick={() => void requestPinReset()}>
                    {requestingPinReset ? "Mengirim ulang..." : "Kirim ulang kode"}
                  </button>
                </>
              )}
              <button type="button" className="mt-3 w-full text-xs font-black text-slate-600 underline" onClick={() => { setPinResetRequested(false); setPinResetSent(false); setPinResetCode(""); setPaymentPin(""); setPinConfirmation(""); }}>Batal</button>
            </div>}
            {useWallet && paymentPinConfigured === true && !pinResetRequested && <div className="mt-7 rounded-2xl border border-indigo-100 bg-indigo-50 p-4">
              <Label className="font-black text-indigo-900">PIN pembayaran</Label>
              <Input required type="password" inputMode="numeric" autoComplete="one-time-code" maxLength={6} className="mt-2 h-12 rounded-xl bg-white" value={paymentPin} onChange={(event) => setPaymentPin(event.target.value.replace(/\D/g, "").slice(0, 6))} placeholder="Masukkan 6 angka" />
                            <div className="mt-2 flex items-center justify-between gap-3">
                <p className="text-xs text-indigo-700">PIN hanya diperlukan saat memakai Saldo Bimbelku.</p>
                <button type="button" className="shrink-0 text-xs font-black text-indigo-700 underline" onClick={() => { setPinResetRequested(true); setPaymentPin(""); }}>Lupa PIN?</button>
              </div>
            </div>}
            {!walletOnly ? <div className="mt-7 space-y-5">
              <div><Label className="flex items-center gap-2 font-bold"><UserRound size={16} />Nama pemilik rekening</Label><Input required className="mt-2 h-12 rounded-xl" value={senderName} onChange={(event) => setSenderName(sanitizePersonName(event.target.value, 150))} /></div>
              <div><Label className="flex items-center gap-2 font-bold"><WalletCards size={16} />Bank/e-wallet asal</Label><Input required className="mt-2 h-12 rounded-xl" value={bankName} onChange={(event) => setBankName(event.target.value)} /></div>
              <div><Label className="flex items-center gap-2 font-bold"><CreditCard size={16} />Nomor rekening/e-wallet asal</Label><Input required inputMode="numeric" minLength={PAYMENT_ACCOUNT_MIN_DIGITS} maxLength={PAYMENT_ACCOUNT_MAX_DIGITS} className="mt-2 h-12 rounded-xl" value={senderAccountNumber} onChange={(event) => setSenderAccountNumber(sanitizeDigits(event.target.value, PAYMENT_ACCOUNT_MAX_DIGITS))} /><p className="mt-2 text-xs leading-5 text-slate-500">Masukkan {PAYMENT_ACCOUNT_MIN_DIGITS}–{PAYMENT_ACCOUNT_MAX_DIGITS} digit untuk membantu admin memverifikasi transfer. Jika ada refund, kamu akan memilih tujuan pengembalian dana sendiri.</p></div>
              <label className={`block cursor-pointer rounded-2xl border-2 border-dashed p-6 text-center transition ${proof ? "border-emerald-300 bg-emerald-50" : "border-slate-200 hover:border-indigo-300 hover:bg-indigo-50/40"}`}><Input required type="file" accept=".jpg,.jpeg,.png,.webp" className="hidden" onChange={(event) => selectProof(event.target.files?.[0])} />{proof ? <><FileImage className="mx-auto text-emerald-600" /><p className="mt-3 truncate text-sm font-black text-emerald-800">{proof.name}</p><p className="mt-1 text-xs text-emerald-600">Klik untuk mengganti</p></> : <><Upload className="mx-auto text-indigo-500" /><p className="mt-3 text-sm font-black text-slate-800">Pilih foto bukti pembayaran</p><p className="mt-1 text-xs text-slate-500">JPG, PNG, atau WebP · maks. 5 MB</p></>}</label>
            </div> : <div className="mt-7 rounded-2xl border border-emerald-100 bg-emerald-50 p-5"><p className="font-black text-emerald-900">Saldo yang dipakai: {rupiah(walletAmount)}</p><p className="mt-2 text-xs leading-5 text-emerald-800">Tidak ada biaya yang harus ditransfer. Setelah dikonfirmasi, saldo langsung diterapkan ke tagihan ini.</p></div>}
            <Button disabled={submitting || paymentExpired || pinResetRequested || (useWallet && (paymentPinConfigured !== true || paymentPin.length !== 6)) || (externalDue > 0.01 && (!proof || !paymentDestinationReady))} className="mt-7 h-12 w-full rounded-xl bg-indigo-600 font-black hover:bg-indigo-700">{submitting ? <Loader2 size={18} className="mr-2 animate-spin" /> : walletOnly ? <WalletCards size={18} className="mr-2" /> : <CreditCard size={18} className="mr-2" />}{walletOnly ? "Bayar dengan saldo" : "Kirim untuk diperiksa"}</Button>
            {(!isCheapClass || order.canCancel) && <Button type="button" variant="ghost" onClick={cancel} className="mt-2 w-full rounded-xl text-rose-600 hover:bg-rose-50 hover:text-rose-700">{isCheapClass ? "Batalkan keikutsertaan" : "Batalkan tagihan"}</Button>}
          </form>
        </div>
      </div>
    </StudentLayout>
  );
}

function Row({ icon: Icon, label, value }: { icon: typeof Check; label: string; value: string }) {
  return <div className="flex items-center justify-between gap-3"><span className="flex items-center gap-2 text-slate-500"><Icon size={16} />{label}</span><span className="font-bold text-slate-900">{value}</span></div>;
}

function Result({ icon: Icon, color, title, text, action, onClick }: { icon: typeof CheckCircle2; color: "emerald" | "rose" | "amber"; title: string; text: string; action: string; onClick: () => void }) {
  const tone = color === "emerald"
    ? "bg-emerald-50 text-emerald-600 border-emerald-100"
    : color === "amber"
      ? "bg-amber-50 text-amber-600 border-amber-100"
      : "bg-rose-50 text-rose-600 border-rose-100";
  return <div className="mx-auto grid min-h-[65vh] max-w-xl place-items-center"><div className="rounded-[2rem] border border-slate-100 bg-white p-6 text-center shadow-xl sm:rounded-[2.5rem] sm:p-9"><div className={`mx-auto grid h-20 w-20 place-items-center rounded-full border-4 sm:h-24 sm:w-24 ${tone}`}><Icon size={40} /></div><h1 className="mt-7 text-2xl font-black text-slate-900 sm:text-3xl">{title}</h1><p className="mt-3 leading-7 text-slate-500">{text}</p><Button onClick={onClick} className="mt-7 h-12 w-full rounded-xl bg-slate-950 font-bold hover:bg-slate-800">{action}</Button></div></div>;
}
