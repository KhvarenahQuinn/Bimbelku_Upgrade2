import { FormEvent, useEffect, useState } from 'react';
import { ArrowLeft, CheckCircle2, Loader2, Mail, ShieldCheck } from 'lucide-react';
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { notify } from '@/lib/notify';
import { getApiError } from '@/lib/http';
import { API_BASE_URL } from '@/lib/apiBase';

export default function VerifyEmail() {
  const location = useLocation();
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const state = location.state as { email?: string; resendAfterSeconds?: number; redirect?: string | null } | null;
  const requestedRedirect = params.get('redirect') || state?.redirect || null;
  const safeRedirect = requestedRedirect?.startsWith('/') && !requestedRedirect.startsWith('//')
    ? requestedRedirect
    : null;
  const loginHref = safeRedirect
    ? `/login?redirect=${encodeURIComponent(safeRedirect)}`
    : '/login';
  const initialEmail = params.get('email') || state?.email || '';
  const verifyReason = params.get('reason') || null;
  const [email] = useState(initialEmail);
  const [code, setCode] = useState('');
  const [loading, setLoading] = useState(false);
  const [resending, setResending] = useState(false);
  const [cooldown, setCooldown] = useState(Math.max(0, Number(state?.resendAfterSeconds || 0)));

  useEffect(() => {
    if (cooldown <= 0) return;
    const timer = window.setInterval(() => setCooldown((value) => Math.max(0, value - 1)), 1000);
    return () => window.clearInterval(timer);
  }, [cooldown]);

  const verify = async (event: FormEvent) => {
    event.preventDefault();
    if (!/^\d{6}$/.test(code)) {
      notify.error('Masukkan kode OTP 6 angka.');
      return;
    }
    setLoading(true);
    try {
      const axios = (await import('axios')).default;
      const response = await axios.post(`${API_BASE_URL}/email/verification/verify`, { email, code });
      notify.success(response.data.message);
      navigate(loginHref, { replace: true });
    } catch (error) {
      notify.error(getApiError(error, 'Kode OTP tidak dapat diverifikasi.'));
    } finally {
      setLoading(false);
    }
  };

  const resend = async () => {
    if (!email || cooldown > 0) return;
    setResending(true);
    try {
      const axios = (await import('axios')).default;
      const response = await axios.post(`${API_BASE_URL}/email/verification/resend`, { email });
      setCooldown(Number(response.data.resend_after_seconds || 60));
      notify.success(response.data.message);
    } catch (error) {
      notify.error(getApiError(error, 'Kode belum dapat dikirim ulang.'));
    } finally {
      setResending(false);
    }
  };

  return (
    <main className={'min-h-screen bg-orange-50/50 px-4 py-8'}>
      <section className={'mx-auto w-full max-w-md rounded-3xl border border-orange-100 bg-white p-6 shadow-xl sm:p-9'}>
        <Link to={loginHref} className={'inline-flex items-center gap-2 text-sm font-bold text-slate-600'}>
          <ArrowLeft size={17} /> Kembali ke login
        </Link>
        <div className={'mt-7 flex h-16 w-16 items-center justify-center rounded-2xl bg-orange-100 text-orange-600'}>
          <Mail size={30} />
        </div>
        <h1 className={'mt-5 text-2xl font-black text-slate-900'}>Verifikasi email</h1>
        <p className={'mt-2 text-sm leading-6 text-slate-600'}>
          Masukkan kode 6 angka yang dikirim ke email. Kode berlaku selama 10 menit.
        </p>
        {verifyReason === 'email_changed' && (
          <p role={'status'} className={'mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs font-semibold leading-5 text-amber-900'}>
            Email akun ini baru diubah oleh admin dan semua sesi lama sudah dihentikan. Verifikasi email baru dengan kode OTP di bawah; login Google tidak melewati langkah ini.
          </p>
        )}
        <form onSubmit={verify} className={'mt-6 space-y-4'}>
          <div className="space-y-2">
            <label className="text-xs font-bold text-slate-700">Alamat email (tidak dapat diubah)</label>
            <Input type={'email'} required value={email} readOnly
              className={'h-12 rounded-xl bg-slate-50 cursor-not-allowed'} placeholder={'Alamat email'} autoComplete={'email'} />
          </div>
          <Input required inputMode={'numeric'} autoComplete={'one-time-code'} maxLength={6}
            value={code} onChange={(event) => setCode(event.target.value.replace(/\D/g, '').slice(0, 6))}
            className={'h-14 rounded-xl text-center text-2xl font-black tracking-[.45em]'}
            placeholder={'000000'} />
          <Button disabled={loading || code.length !== 6} className={'h-12 w-full rounded-xl bg-orange-600 font-black hover:bg-orange-700'}>
            {loading ? <Loader2 className={'mr-2 animate-spin'} size={18} /> : <CheckCircle2 className={'mr-2'} size={18} />}
            Verifikasi sekarang
          </Button>
        </form>
        <button type={'button'} disabled={resending || cooldown > 0 || !email} onClick={() => void resend()}
          className={'mt-4 w-full text-sm font-bold text-orange-700 disabled:text-slate-400'}>
          {resending ? 'Mengirim...' : cooldown > 0 ? `Kirim ulang dalam ${cooldown} detik` : 'Kirim ulang kode'}
        </button>
        <p className={'mt-6 flex gap-2 rounded-xl bg-slate-50 p-3 text-xs leading-5 text-slate-500'}>
          <ShieldCheck className={'shrink-0 text-emerald-600'} size={17} />
          Jangan berikan kode OTP kepada siapa pun, termasuk pihak yang mengaku sebagai admin.
        </p>
      </section>
    </main>
  );
}
