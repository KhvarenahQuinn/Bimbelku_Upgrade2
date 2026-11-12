import { notify } from "@/lib/notify";
import React, { useState, useEffect } from "react";
import TeacherLayout from "../../components/TeacherLayout"; 
import { 
  CreditCard, Send, Building, User, Wallet,
  AlertCircle, ShieldCheck, CheckCircle2, Loader2, Mail, KeyRound
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { useConfirmDialog } from "@/components/ConfirmDialogProvider";
import http, { getApiError, getCached } from "@/lib/http";
import { isValidAccountNumber, sanitizeDigits } from "@/lib/validation";

const PAYOUT_ACCOUNT_MIN_DIGITS = 8;
const PAYOUT_ACCOUNT_MAX_DIGITS = 20;
const PAYOUT_CHANNELS = [
  ["BCA", "Bank Central Asia (BCA)"],
  ["MANDIRI", "Bank Mandiri"],
  ["BRI", "Bank Rakyat Indonesia (BRI)"],
  ["BNI", "Bank Negara Indonesia (BNI)"],
  ["CIMB", "CIMB Niaga"],
  ["PERMATA", "PermataBank"],
  ["BSI", "Bank Syariah Indonesia (BSI)"],
] as const;
const OTP_RESEND_SECONDS = 60;

export function TeacherBankSettings({ onSaved, payoutAmount, payoutAvailable = false, onPayoutCompleted }: {
  onSaved?: () => void;
  payoutAmount?: number;
  payoutAvailable?: boolean;
  onPayoutCompleted?: () => void;
}) {
  const confirm = useConfirmDialog();
  const [payoutChannelCode, setPayoutChannelCode] = useState("");
  const [accountNumber, setAccountNumber] = useState("");
  const [accountHolder, setAccountHolder] = useState("");
  const [legalName, setLegalName] = useState("");
  const [currentPassword, setCurrentPassword] = useState("");
  const [otpCode, setOtpCode] = useState("");
  const [verificationMethod, setVerificationMethod] = useState<"otp" | "password">("otp");
  const [otpCooldown, setOtpCooldown] = useState(0);
  const [isSendingOtp, setIsSendingOtp] = useState(false);
  const [payoutHoldUntil, setPayoutHoldUntil] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);

  useEffect(() => {
    fetchBankData();
  }, []);

  useEffect(() => {
    if (otpCooldown <= 0) return;
    const timer = window.setTimeout(() => setOtpCooldown((value) => Math.max(0, value - 1)), 1000);
    return () => window.clearTimeout(timer);
  }, [otpCooldown]);

  const fetchBankData = async () => {
    try {
        const response = await getCached("/teacher/profile", { maxAgeMs: 60_000 });
        
        const profileData = response.data.profile;
        setLegalName(String(response.data.user?.name || "").trim());
        if (profileData) {
            setPayoutChannelCode(profileData.payout_channel_code || "");
            setAccountNumber(sanitizeDigits(profileData.account_number || "", 50));
            setAccountHolder(String(profileData.account_name || "").trim());
            setPayoutHoldUntil(profileData.payout_hold_until || null);
            setVerificationMethod(profileData.payout_verification_method === "password" ? "password" : "otp");
        }
    } catch (error) {
        console.error("Gagal load data bank", error);
        notify.error("Gagal memuat data rekening.");
    } finally {
        setIsLoading(false);
    }
  };

  const requestOtp = async () => {
    if (isSendingOtp || otpCooldown > 0) return;
    setIsSendingOtp(true);
    try {
        await http.post("/teacher/payout-verification/otp", {});
        setOtpCooldown(OTP_RESEND_SECONDS);
        notify.success("Kode verifikasi dikirim ke email akun.");
    } catch (error) {
        notify.error(getApiError(error, "Kode verifikasi gagal dikirim."));
    } finally {
        setIsSendingOtp(false);
    }
  };

  const handleSave = async () => {
    if (isSaving) return;
    if (payoutAmount === undefined) return;
    if (!payoutAvailable) {
        notify.error("Pencairan belum dapat dikirim karena verifikasi nama pemilik rekening dari bank belum aktif. Saldo tetap aman; rekening tidak diubah.");
        return;
    }
    if(!payoutChannelCode || !accountNumber || !legalName) {
        notify.error("Mohon lengkapi semua data rekening.");
        return;
    }
    if (
        !isValidAccountNumber(accountNumber)
        || accountNumber.length < PAYOUT_ACCOUNT_MIN_DIGITS
        || accountNumber.length > PAYOUT_ACCOUNT_MAX_DIGITS
    ) {
        notify.error(`Nomor rekening atau e-wallet harus berisi ${PAYOUT_ACCOUNT_MIN_DIGITS}–${PAYOUT_ACCOUNT_MAX_DIGITS} digit.`);
        return;
    }
    if (verificationMethod === "otp" && otpCode.length !== 6) {
        notify.error("Masukkan 6 digit kode verifikasi email.");
        return;
    }
    if (verificationMethod === "password" && !currentPassword) {
        notify.error("Masukkan kata sandi akun untuk memverifikasi pencairan.");
        return;
    }
    const approved = await confirm({
        title: "Konfirmasi pencairan dana?",
        description: `Cairkan Rp${new Intl.NumberFormat("id-ID").format(payoutAmount)} ke rekening ${payoutChannelCode} berakhir ${accountNumber.slice(-4)}. Rekening hanya disimpan jika pencairan berhasil dibuat.`,
        confirmText: "Cairkan dana",
        tone: "warning",
    });
    if (!approved) return;

    setIsSaving(true);
    try {
        const verification =
            verificationMethod === "otp"
                ? { otp: otpCode }
                : { password: currentPassword };
        const response = await http.post("/teacher/payout-requests", {
            amount: payoutAmount,
            bank_details: {
                payout_channel_code: payoutChannelCode,
                account_number: accountNumber,
                account_name: legalName,
            },
            verification,
        });
        setCurrentPassword("");
        setOtpCode("");
        setPayoutHoldUntil(response.data?.data?.payout_hold_until || null);
        onSaved?.();
        notify.success("Permintaan pencairan dikirim. Pantau status transfer di Dompet Tutor.");
        onPayoutCompleted?.();
    } catch (error) {
        notify.error(getApiError(error, "Pencairan belum dapat diproses. Rekening dan saldo tidak diubah."));
    } finally {
        setIsSaving(false);
    }
  };

  if (isLoading) return <div className="grid min-h-60 place-items-center"><Loader2 className="animate-spin text-indigo-600"/></div>;

  const payoutMode = payoutAmount !== undefined;

  return (
      <div id="rekening" className="mx-auto max-w-5xl scroll-mt-6 space-y-6 pb-10 sm:space-y-8">
          
          <div>
            <h1 className="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Rekening Pencairan</h1>
            <p className="text-slate-500 mt-1">
              {payoutMode
                ? "Rekening disimpan bersamaan dengan pencairan setelah verifikasi berhasil."
                : "Rekening hanya dapat diubah melalui proses pencairan dana."}
            </p>
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-2 gap-8 items-start">
            
            {/* --- KOLOM KIRI: FORMULIR --- */}
            <Card className="rounded-[2rem] border-none shadow-sm bg-white h-fit order-2 lg:order-1">
               <CardHeader className="pb-4 border-b border-slate-50">
                  <div className="flex items-center gap-3">
                     <div className="p-2.5 bg-indigo-50 text-indigo-600 rounded-xl">
                        <Wallet size={24} />
                     </div>
                     <div>
                        <CardTitle className="text-lg font-bold text-slate-900">Data Rekening</CardTitle>
                        <p className="text-xs text-slate-500">Pastikan data sesuai buku tabungan</p>
                     </div>
                  </div>
               </CardHeader>
               <CardContent className="space-y-5 p-4 sm:p-6">
                  <div className="space-y-2">
                     <label className="text-sm font-bold text-slate-700 flex items-center gap-2">
                        <Building size={16} className="text-slate-400" /> Bank tujuan pencairan
                     </label>
                     <select value={payoutChannelCode} onChange={(event) => setPayoutChannelCode(event.target.value)} className="h-12 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm font-semibold text-slate-800 outline-none focus:border-indigo-400 focus:bg-white">
                       <option value="">Pilih bank</option>
                       {PAYOUT_CHANNELS.map(([code, label]) => <option key={code} value={code}>{label}</option>)}
                     </select>
                     <p className="text-xs leading-5 text-slate-500">Daftar dibatasi pada bank yang mendukung pencairan otomatis.</p>
                  </div>

                  <div className="space-y-2">
                     <label className="text-sm font-bold text-slate-700 flex items-center gap-2">
                        <CreditCard size={16} className="text-slate-400" /> Nomor Rekening
                     </label>
                     <Input 
                        placeholder="Contoh: 1234567890"
                        inputMode="numeric"
                        minLength={PAYOUT_ACCOUNT_MIN_DIGITS}
                        maxLength={PAYOUT_ACCOUNT_MAX_DIGITS}
                        autoComplete="off"
                        value={accountNumber}
                        onChange={(e) => setAccountNumber(sanitizeDigits(e.target.value, PAYOUT_ACCOUNT_MAX_DIGITS))}
                        className="h-12 rounded-xl border-slate-200 focus:bg-white bg-slate-50 transition font-mono tracking-wide text-lg"
                     />
                     <p className="text-xs leading-5 text-slate-500">
                        Masukkan {PAYOUT_ACCOUNT_MIN_DIGITS}–{PAYOUT_ACCOUNT_MAX_DIGITS} digit tanpa spasi atau tanda baca.
                     </p>
                  </div>

                  <div className="space-y-2">
                     <label className="text-sm font-bold text-slate-700 flex items-center gap-2">
                        <User size={16} className="text-slate-400" /> Atas Nama (Wajib Sesuai KTP)
                     </label>
                     <Input value={legalName} readOnly className="h-12 rounded-xl border-slate-200 bg-slate-100 text-slate-700" />
                     {accountHolder && accountHolder.toLocaleLowerCase("id-ID") !== legalName.toLocaleLowerCase("id-ID") && <p className="text-xs text-amber-700">Nama pada rekening tersimpan berbeda dari nama akun. Periksa kembali sebelum melanjutkan.</p>}
                     <p className="text-xs leading-5 text-slate-500">Nama ini diambil dari akun tutor. Jika tidak sama dengan KTP atau rekening bank, perbaiki identitas akun terlebih dahulu.</p>
                  </div>

                  {verificationMethod === "otp" ? (
                     <div className="space-y-2">
                        <label className="text-sm font-bold text-slate-700 flex items-center gap-2">
                           <Mail size={16} className="text-slate-400" /> Kode verifikasi email
                        </label>
                        <div className="flex gap-2">
                           <Input
                              inputMode="numeric"
                              autoComplete="one-time-code"
                              placeholder="6 digit"
                              maxLength={6}
                              value={otpCode}
                              onChange={(e) => setOtpCode(sanitizeDigits(e.target.value, 6))}
                              className="h-12 flex-1 rounded-xl border-slate-200 focus:bg-white bg-slate-50 transition font-mono tracking-[.3em]"
                           />
                           <Button
                              type="button"
                              variant="outline"
                              onClick={() => void requestOtp()}
                              disabled={isSendingOtp || otpCooldown > 0}
                              className="h-12 shrink-0 rounded-xl"
                           >
                              {isSendingOtp ? <Loader2 className="animate-spin" size={16} /> : otpCooldown > 0 ? `Kirim ulang (${otpCooldown})` : "Kirim kode"}
                           </Button>
                        </div>
                        <p className="text-xs leading-5 text-slate-500">Kode berlaku 10 menit dan hanya dikirim ke email akun tutor.</p>
                     </div>
                  ) : (
                     <div className="space-y-2">
                        <label className="text-sm font-bold text-slate-700 flex items-center gap-2">
                           <KeyRound size={16} className="text-slate-400" /> Kata sandi akun
                        </label>
                        <Input
                           type="password"
                           autoComplete="current-password"
                           placeholder="Kata sandi akun tutor"
                           value={currentPassword}
                           onChange={(e) => setCurrentPassword(e.target.value)}
                           className="h-12 rounded-xl border-slate-200 focus:bg-white bg-slate-50 transition"
                        />
                        <p className="text-xs leading-5 text-slate-500">Metode verifikasi dapat diubah dari halaman Keamanan Akun.</p>
                     </div>
                  )}

                  {payoutHoldUntil && new Date(payoutHoldUntil) > new Date() && (
                     <div className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-800">
                        Pencairan berikutnya ditahan sampai {new Date(payoutHoldUntil).toLocaleString("id-ID")} setelah perubahan rekening.
                     </div>
                  )}

                  <div className="pt-4">
                     {payoutMode ? (
                        <Button 
                           onClick={handleSave} 
                           disabled={isSaving || !payoutAvailable}
                           title={!payoutAvailable ? "Menunggu aktivasi verifikasi nama rekening dari bank" : undefined}
                           className="w-full h-12 rounded-xl bg-slate-900 hover:bg-indigo-600 font-bold text-base shadow-lg shadow-slate-900/20 transition-all"
                        >
                           {isSaving ? <span className="flex items-center gap-2"><Loader2 className="animate-spin"/> Memproses pencairan...</span> : (
                              <span className="flex items-center gap-2"><Send size={18}/> {payoutAvailable ? "Cairkan dana" : "Cairkan dana · menunggu verifikasi"}</span>
                           )}
                        </Button>
                     ) : (
                        <div className="rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs leading-5 text-slate-600">
                           Pilih nominal pencairan di halaman Dompet Tutor. Rekening baru disimpan bersamaan dengan pencairan dana.
                        </div>
                     )}
                  </div>
               </CardContent>
            </Card>

            {/* --- KOLOM KANAN: INFORMASI KEAMANAN --- */}
            <div className="space-y-6 order-1 lg:order-2 flex flex-col items-center lg:items-start">
               {/* Info Box */}
               <div className="w-full bg-amber-50 border border-amber-100 rounded-2xl p-5 flex gap-3 items-start">
                  <AlertCircle size={20} className="text-amber-600 shrink-0 mt-0.5" />
                  <div className="space-y-1">
                     <h4 className="font-bold text-amber-800 text-sm">Penting:</h4>
                     <ul className="text-xs text-amber-700 space-y-1 list-disc ml-4">
                        <li>Pastikan nama pemilik rekening sama dengan nama di profil tutor Anda.</li>
                        <li>Rekening hanya disimpan saat pencairan dana dinyatakan berhasil dibuat.</li>
                        <li>Pencairan hanya dapat dikirim setelah layanan verifikasi nama rekening diaktifkan dan pemeriksaan berhasil.</li>
                     </ul>
                  </div>
               </div>

               <div className="flex items-center gap-2 justify-center w-full text-slate-400 text-xs">
                  <ShieldCheck size={14} />
                  <span>Akses data rekening dibatasi untuk proses pencairan.</span>
               </div>
            </div>

          </div>
      </div>
  );
}

export default function TeacherBankSettingsPage() {
  return <TeacherLayout title="Dompet Tutor"><TeacherBankSettings /></TeacherLayout>;
}
