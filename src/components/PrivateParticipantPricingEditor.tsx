import { useEffect, useMemo, useState } from "react";
import { AlertCircle, Save, Users } from "lucide-react";
import { notify } from "@/lib/notify";
import http, { getApiError } from "@/lib/http";

type Tier = { discount_percent: string };
type Response = {
  tiers: Record<string, { discount_percent: number | null }>;
  maximum_participants: number;
  multi_participant_ready: boolean;
  tutor_share_percent?: number;
  admin_share_percent?: number;
};

const MIN_PARTICIPANTS = 2;
const HARD_MAX_PARTICIPANTS = 100;
const countsFor = (maximum: number) => Array.from({ length: maximum - 1 }, (_, index) => index + 2);
const emptyTier = (): Tier => ({ discount_percent: "" });

export default function PrivateParticipantPricingEditor() {
  const [maximumParticipants, setMaximumParticipants] = useState(8);
  const [tiers, setTiers] = useState<Record<string, Tier>>({});
  const [ready, setReady] = useState(false);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const counts = useMemo(() => countsFor(maximumParticipants), [maximumParticipants]);

  useEffect(() => {
    void http.get<Response>("/admin/private-participant-pricing").then(({ data }) => {
      const maximum = Math.min(HARD_MAX_PARTICIPANTS, Math.max(MIN_PARTICIPANTS, Number(data.maximum_participants || 8)));
      const next: Record<string, Tier> = {};
      for (const count of countsFor(maximum)) {
        const discount = data.tiers[String(count)]?.discount_percent;
        next[String(count)] = { discount_percent: discount === null || discount === undefined ? "" : String(discount) };
      }
      setMaximumParticipants(maximum);
      setTiers(next);
      setReady(data.multi_participant_ready);
    }).catch((error) => notify.error(getApiError(error))).finally(() => setLoading(false));
  }, []);

  const changeMaximum = (value: number) => {
    const maximum = Math.min(HARD_MAX_PARTICIPANTS, Math.max(MIN_PARTICIPANTS, value || MIN_PARTICIPANTS));
    setMaximumParticipants(maximum);
    setTiers((current) => Object.fromEntries(countsFor(maximum).map((count) => [String(count), current[String(count)] || emptyTier()])));
    setReady(false);
  };

  const save = async () => {
    setSaving(true);
    try {
      const payload = Object.fromEntries(counts.map((count) => {
        const value = tiers[String(count)]?.discount_percent ?? "";
        return [String(count), { discount_percent: value === "" ? null : Number(value) }];
      }));
      const { data } = await http.put<Response & { message: string }>("/admin/private-participant-pricing", {
        maximum_participants: maximumParticipants,
        tiers: payload,
      });
      setReady(data.multi_participant_ready);
      notify.success(data.message);
    } catch (error) {
      notify.error(getApiError(error));
    } finally {
      setSaving(false);
    }
  };

  return <section className="rounded-[2rem] border border-slate-100 bg-white p-6 shadow-sm">
    <div className="flex flex-wrap items-start justify-between gap-4">
      <div><h2 className="text-xl font-black text-slate-900">Privat bersama teman (2–{maximumParticipants} peserta)</h2><p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">Harga akhir = tarif dasar × jumlah peserta − diskon. Dari harga akhir tersebut, 80% menjadi hak tutor dan 20% menjadi bagian admin.</p></div>
      <span className={`rounded-full px-3 py-1.5 text-xs font-black ${ready ? "bg-emerald-100 text-emerald-800" : "bg-amber-100 text-amber-800"}`}>{ready ? "Siap menerima pesanan" : "Belum aktif"}</span>
    </div>

    <div className="mt-5 rounded-2xl border border-indigo-100 bg-indigo-50/70 p-4">
      <label htmlFor="private-maximum-participants" className="flex items-center gap-2 text-sm font-black text-indigo-950"><Users size={17} />Batas maksimal peserta privat</label>
      <div className="mt-3 flex flex-wrap items-center gap-2">
        <input id="private-maximum-participants" type="number" min={MIN_PARTICIPANTS} max={HARD_MAX_PARTICIPANTS} value={maximumParticipants} onChange={(event) => changeMaximum(Number(event.target.value))} disabled={loading} className="h-11 w-28 rounded-xl border border-indigo-200 bg-white px-3 font-black text-slate-900" />
        {[8, 10, 12].map((value) => <button key={value} type="button" onClick={() => changeMaximum(value)} disabled={loading} className={`h-11 rounded-xl border px-4 text-sm font-black ${maximumParticipants === value ? "border-indigo-600 bg-indigo-600 text-white" : "border-indigo-200 bg-white text-indigo-700"}`}>{value} orang</button>)}
        <span className="text-xs font-semibold text-indigo-700">Boleh diatur 2–100; admin menanggung konsekuensi operasional kapasitas yang dipilih.</span>
      </div>
    </div>

    <p className="mt-4 flex gap-2 rounded-2xl bg-amber-50 p-4 text-sm leading-6 text-amber-900"><AlertCircle className="mt-0.5 shrink-0" size={18} />Semua diskon 2–{maximumParticipants} peserta harus terisi. Tidak ada lagi pengaturan “kenaikan bruto tutor”; pembagian transaksi selalu 80/20.</p>
    <p className="mt-3 text-xs font-semibold text-slate-500 sm:hidden">Geser tabel ke samping untuk melihat semua kolom.</p>
    <div className="mt-5 max-h-[36rem] overflow-auto rounded-2xl border border-slate-100"><table className="w-full min-w-[640px] text-left text-sm"><thead className="sticky top-0 z-10 bg-white shadow-sm"><tr className="border-b border-slate-200 text-slate-500"><th className="px-4 py-3">Peserta</th><th className="px-4 py-3">Diskon total normal</th><th className="px-4 py-3">Pengali harga akhir</th><th className="px-4 py-3">Pembagian</th></tr></thead><tbody>{counts.map((count) => {
      const discount = Number(tiers[String(count)]?.discount_percent || 0);
      const multiplier = count * (1 - discount / 100);
      return <tr key={count} className="border-b border-slate-100"><th className="px-4 py-3 font-black text-slate-900">{count} orang</th><td className="px-4 py-3"><input aria-label={`Diskon pelanggan ${count} peserta`} type="number" inputMode="decimal" min="0" max="90" step="0.01" value={tiers[String(count)]?.discount_percent ?? ""} onChange={(event) => { setTiers((current) => ({ ...current, [String(count)]: { discount_percent: event.target.value } })); setReady(false); }} disabled={loading} placeholder="Belum diisi" className="h-11 w-full rounded-xl border border-slate-200 px-3 disabled:bg-slate-50" /></td><td className="px-4 py-3 font-bold text-slate-700">{multiplier.toLocaleString("id-ID", { maximumFractionDigits: 3 })}× tarif dasar</td><td className="px-4 py-3"><span className="font-black text-emerald-700">Tutor 80%</span><span className="mx-2 text-slate-300">•</span><span className="font-black text-indigo-700">Admin 20%</span></td></tr>;
    })}</tbody></table></div>
    <button type="button" onClick={() => void save()} disabled={loading || saving} className="mt-5 inline-flex min-h-12 items-center gap-2 rounded-xl bg-slate-950 px-5 text-sm font-black text-white disabled:opacity-50"><Save size={17} />{saving ? "Menyimpan…" : "Simpan batas & diskon peserta"}</button>
  </section>;
}