import { notify } from "@/lib/notify";
import { useEffect, useMemo, useState } from "react";
import {
  CheckCircle2,
  Eye,
  FileBadge,
  FileCheck2,
  History,
  IdCard,
  Loader2,
  Search,
  ShieldCheck,
  UserRoundCheck,
  XCircle,
} from "lucide-react";
import AdminLayout from "@/components/AdminLayout";
import { useConfirmDialog } from "@/components/ConfirmDialogProvider";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { openProtectedFile } from "@/components/ProtectedImage";
import http, { getApiError } from "@/lib/http";
import { isFilePreviewOpen } from "@/lib/filePreview";
import { whatsappHref } from "@/lib/websiteContent";

interface Teacher {
  id: number;
  name: string;
  email: string;
  status: string;
  subject?: string;
  levels: string[];
  teaching_method?: string;
  about?: string;
  phone?: string;
  verification_notes?: string;
  verification_stage?: "submitted" | "documents_checked" | "whatsapp_scheduled" | "whatsapp_passed" | "approved" | "rejected";
  photo_url?: string;
  identity_document_url?: string;
  live_selfie_url?: string;
  qualification_document_url?: string;
  certification_document_url?: string;
}

export default function TeacherVerification() {
  const confirm = useConfirmDialog();
  const [tab, setTab] = useState<"pending" | "history">("pending");
  const [pending, setPending] = useState<Teacher[]>([]);
  const [history, setHistory] = useState<Teacher[]>([]);
  const [selected, setSelected] = useState<Teacher | null>(null);
  const [rejecting, setRejecting] = useState(false);
  const [notes, setNotes] = useState("");
  const [screeningPassed, setScreeningPassed] = useState(false);
  const [search, setSearch] = useState("");
  const [loading, setLoading] = useState(true);
  const [processing, setProcessing] = useState(false);

  useEffect(() => { void load(); }, []);

  const load = async () => {
    setLoading(true);
    try {
      const [pendingResponse, historyResponse] = await Promise.all([
        http.get<Teacher[]>("/admin/pending-teachers"),
        http.get<Teacher[]>("/admin/history-teachers"),
      ]);
      setPending(pendingResponse.data);
      setHistory(historyResponse.data);
    } catch (error) {
      notify.error(getApiError(error, "Data verifikasi tutor gagal dimuat."));
    } finally {
      setLoading(false);
    }
  };

  const advanceStage = async () => {
    if (!selected) return;
    const next = selected.verification_stage === "documents_checked"
      ? "whatsapp_scheduled"
      : selected.verification_stage === "whatsapp_scheduled"
        ? "whatsapp_passed"
        : "documents_checked";
    if (next === "whatsapp_passed" && notes.trim().length < 10) {
      notify.error("Tulis ringkasan hasil tes WhatsApp minimal 10 karakter.");
      return;
    }
    setProcessing(true);
    try {
      const response = await http.patch("/admin/teachers/" + selected.id + "/verification-stage", {
        stage: next,
        notes: next === "whatsapp_passed" ? notes.trim() : undefined,
      });
      notify.success(response.data.message);
      setSelected((current) => current ? { ...current, verification_stage: next, verification_notes: next === "whatsapp_passed" ? notes.trim() : current.verification_notes } : current);
      setPending((current) => current.map((item) => item.id === selected.id ? { ...item, verification_stage: next } : item));
      if (next === "whatsapp_passed") setScreeningPassed(true);
    } catch (error) {
      notify.error(getApiError(error));
    } finally {
      setProcessing(false);
    }
  };
  const decide = async (status: "active" | "rejected") => {
    if (!selected) return;
    if (status === "active") {
      if (!screeningPassed || notes.trim().length < 10) {
        notify.error("Centang kelulusan seleksi dan tulis ringkasan hasil tes minimal 10 karakter.");
        return;
      }
      const approved = await confirm({
        title: "Aktifkan akun tutor?",
        description: "Pastikan dokumen sudah cocok serta seleksi dan tes melalui WhatsApp benar-benar dinyatakan lulus.",
        confirmText: "Aktifkan tutor",
        tone: "primary",
      });
      if (!approved) return;
    } else if (notes.trim().length < 10) {
      notify.error("Alasan penolakan minimal 10 karakter.");
      return;
    }
    setProcessing(true);
    try {
      const response = await http.post("/admin/verify-teacher", { user_id: selected.id, status, notes, screening_passed: status === "active" && screeningPassed });
      notify.success(response.data.message);
      setSelected(null);
      setRejecting(false);
      setNotes("");
      setScreeningPassed(false);
      await load();
    } catch (error) {
      notify.error(getApiError(error));
    } finally {
      setProcessing(false);
    }
  };

  const source = tab === "pending" ? pending : history;
  const filtered = useMemo(() => source.filter((item) => `${item.name} ${item.email} ${item.subject}`.toLowerCase().includes(search.toLowerCase())), [source, search]);

  const openDocument = async (url: string, label: string) => {
    try {
      await openProtectedFile(url, label);
    } catch (error) {
      notify.error(getApiError(error, "Dokumen gagal dibuka."));
    }
  };

  return (
    <AdminLayout title="Verifikasi Tutor">
      <div className="space-y-6 pb-12">
        <section className="rounded-[2rem] bg-gradient-to-br from-slate-950 to-indigo-950 p-7 text-white">
          <p className="text-xs font-black uppercase tracking-[.2em] text-indigo-200">Pemeriksaan menyeluruh</p><h1 className="mt-3 text-3xl font-black">Verifikasi dokumen & hasil seleksi</h1><p className="mt-2 max-w-2xl text-sm leading-6 text-indigo-100/70">Admin memeriksa identitas dan kualifikasi, lalu mencatat hasil seleksi serta tes yang dilakukan melalui WhatsApp sebelum akun tutor diaktifkan.</p>
        </section>
        <div className="flex flex-col justify-between gap-4 rounded-2xl border border-slate-100 bg-white p-4 sm:flex-row sm:items-center">
          <div className="flex gap-2"><Tab active={tab === "pending"} onClick={() => setTab("pending")} icon={FileCheck2} label={`Menunggu (${pending.length})`} /><Tab active={tab === "history"} onClick={() => setTab("history")} icon={History} label="Riwayat" /></div>
          <div className="relative"><Search className="absolute left-3 top-3 text-slate-400" size={17} /><Input className="h-11 rounded-xl pl-10 sm:w-72" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Cari nama atau mapel" /></div>
        </div>
        <div className="overflow-hidden rounded-[2rem] border border-slate-100 bg-white">
          {loading ? <div className="grid min-h-64 place-items-center"><Loader2 className="animate-spin text-indigo-600" /></div> : filtered.length === 0 ? <div className="py-20 text-center text-sm text-slate-400">Tidak ada data pada bagian ini.</div> : <div className="divide-y divide-slate-100">
            {filtered.map((teacher) => <div key={teacher.id} className="flex flex-col justify-between gap-4 p-5 hover:bg-slate-50/70 sm:flex-row sm:items-center"><div className="flex gap-4"><div className="grid h-12 w-12 place-items-center overflow-hidden rounded-2xl bg-indigo-50 font-black text-indigo-700">{teacher.photo_url ? <img src={teacher.photo_url} alt="" loading="lazy" decoding="async" className="h-full w-full object-cover" /> : teacher.name.charAt(0)}</div><div><p className="font-black text-slate-900">{teacher.name}</p><p className="text-xs text-slate-400">{teacher.email}</p><div className="mt-2 flex flex-wrap gap-1"><span className="rounded-lg bg-indigo-50 px-2 py-1 text-[11px] font-bold text-indigo-700">{teacher.subject || "Belum ada mapel"}</span>{teacher.levels?.map((level) => <span key={level} className="rounded-lg bg-slate-100 px-2 py-1 text-[11px] font-bold text-slate-600">{level}</span>)}</div></div></div><div className="flex items-center gap-3"><span className={`rounded-full px-3 py-1.5 text-xs font-bold ${teacher.status === "active" ? "bg-emerald-50 text-emerald-700" : teacher.status === "rejected" ? "bg-rose-50 text-rose-700" : "bg-amber-50 text-amber-700"}`}>{teacher.status}</span><Button variant="outline" className="rounded-xl" onClick={() => { setSelected(teacher); setRejecting(false); setNotes(""); setScreeningPassed(false); }}><Eye size={15} className="mr-2" />Periksa</Button></div></div>)}
          </div>}
        </div>
      </div>

      <Dialog
        open={Boolean(selected)}
        onOpenChange={(open) => {
          if (!open && isFilePreviewOpen()) return;
          if (!open) setSelected(null);
        }}
      >
        <DialogContent
          className="max-h-[92dvh] overflow-y-auto rounded-[2rem] sm:max-w-2xl"
          onEscapeKeyDown={(event) => {
            if (isFilePreviewOpen()) event.preventDefault();
          }}
          onPointerDownOutside={(event) => {
            if (isFilePreviewOpen()) event.preventDefault();
          }}
          onInteractOutside={(event) => {
            if (isFilePreviewOpen()) event.preventDefault();
          }}
          onFocusOutside={(event) => {
            if (isFilePreviewOpen()) event.preventDefault();
          }}
        >
          {selected && <>
            <DialogHeader><DialogTitle className="text-2xl">{rejecting ? "Tolak verifikasi tutor" : selected.name}</DialogTitle><DialogDescription>{selected.email} · {selected.subject} · {(selected.levels || []).join(", ")}</DialogDescription></DialogHeader>
            {rejecting ? <div className="space-y-4"><div className="rounded-2xl border border-rose-100 bg-rose-50 p-4 text-sm leading-6 text-rose-800">Jelaskan dokumen, seleksi, atau hasil tes yang belum memenuhi standar. Alasan dikirim kepada tutor.</div><Textarea className="min-h-36 rounded-xl" value={notes} onChange={(event) => setNotes(event.target.value)} placeholder="Alasan penolakan yang jelas" /><div className="flex gap-2"><Button variant="outline" className="flex-1 rounded-xl" onClick={() => setRejecting(false)}>Kembali</Button><Button className="flex-1 rounded-xl bg-rose-600 hover:bg-rose-700" onClick={() => decide("rejected")} disabled={processing}><XCircle size={16} className="mr-2" />Tolak</Button></div></div> : <>
              <div className="grid gap-3 sm:grid-cols-3"><Metric label="Mata pelajaran" value={selected.subject || "-"} /><Metric label="Metode" value={selected.teaching_method || "-"} /><Metric label="Tahap seleksi" value={stageLabel(selected.verification_stage)} /></div>
              {selected.phone && <a href={whatsappHref(selected.phone, `Halo ${selected.name}, kami dari BimbelKu ingin melanjutkan proses seleksi tutor.`) || undefined} target="_blank" rel="noreferrer" className="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl bg-[#15803D] px-4 py-2.5 text-sm font-black text-white hover:bg-green-800">Hubungi kandidat melalui WhatsApp</a>}
              {selected.about && <div className="rounded-2xl bg-slate-50 p-4 text-sm leading-6 text-slate-700">{selected.about}</div>}
              <div className="grid gap-3 sm:grid-cols-2"><Document icon={IdCard} label="Kartu identitas" url={selected.identity_document_url} onOpen={openDocument} /><Document icon={UserRoundCheck} label="Foto wajah langsung" url={selected.live_selfie_url} onOpen={openDocument} /><Document icon={FileCheck2} label="Ijazah / kualifikasi" url={selected.qualification_document_url} onOpen={openDocument} /><Document icon={FileBadge} label="Sertifikat pendukung" url={selected.certification_document_url} optional onOpen={openDocument} /></div>
              {selected.verification_notes && <div className="rounded-2xl border border-slate-200 p-4 text-sm text-slate-600">Catatan keputusan: {selected.verification_notes}</div>}
              {selected.status === "pending" && <div className="space-y-3 rounded-2xl border border-indigo-100 bg-indigo-50/50 p-4"><VerificationSteps stage={selected.verification_stage} /><Textarea className="min-h-24 rounded-xl bg-white" value={notes} onChange={(event) => setNotes(event.target.value)} placeholder="Ringkasan hasil seleksi dan tes WhatsApp (wajib untuk persetujuan)" /><label className="flex cursor-pointer items-start gap-3 text-sm leading-6 text-indigo-950"><Checkbox checked={screeningPassed} disabled={selected.verification_stage !== "whatsapp_passed"} onCheckedChange={(value) => setScreeningPassed(Boolean(value))} className="mt-1" /><span>Saya memastikan kandidat telah mengikuti seleksi dan tes melalui WhatsApp serta dinyatakan lulus.</span></label>{selected.verification_stage !== "whatsapp_passed" && <Button type="button" variant="outline" className="w-full rounded-xl border-indigo-200 bg-white text-indigo-800" onClick={() => void advanceStage()} disabled={processing}>{selected.verification_stage === "documents_checked" ? "Catat jadwal tes WhatsApp" : selected.verification_stage === "whatsapp_scheduled" ? "Nyatakan tes WhatsApp lulus" : "Tandai dokumen sudah diperiksa"}</Button>}<div className="grid grid-cols-2 gap-2"><Button variant="outline" className="rounded-xl border-rose-200 text-rose-700" onClick={() => setRejecting(true)}><XCircle size={16} className="mr-2" />Tolak</Button><Button className="rounded-xl bg-emerald-600 hover:bg-emerald-700" onClick={() => decide("active")} disabled={processing || selected.verification_stage !== "whatsapp_passed" || !screeningPassed || notes.trim().length < 10}><CheckCircle2 size={16} className="mr-2" />Setujui</Button></div></div>}
            </>}
          </>}
        </DialogContent>
      </Dialog>
    </AdminLayout>
  );
}

function Tab({ active, onClick, icon: Icon, label }: { active: boolean; onClick: () => void; icon: typeof History; label: string }) {
  return <button onClick={onClick} className={`flex items-center gap-2 rounded-xl px-4 py-2.5 text-xs font-black ${active ? "bg-indigo-600 text-white" : "bg-slate-50 text-slate-500"}`}><Icon size={15} />{label}</button>;
}

function Metric({ label, value }: { label: string; value: string }) {
  return <div className="rounded-2xl bg-slate-50 p-4"><p className="text-[10px] font-bold uppercase tracking-widest text-slate-400">{label}</p><p className="mt-2 font-black capitalize text-slate-800">{value}</p></div>;
}

function Document({ icon: Icon, label, url, optional, onOpen }: { icon: typeof IdCard; label: string; url?: string; optional?: boolean; onOpen: (url: string, label: string) => void }) {
  return <div className={`rounded-2xl border p-4 ${url ? "border-emerald-100 bg-emerald-50/50" : optional ? "border-slate-100 bg-slate-50" : "border-rose-100 bg-rose-50"}`}><div className="flex items-center gap-3"><Icon className={url ? "text-emerald-600" : "text-slate-400"} /><div><p className="text-sm font-black text-slate-800">{label}</p><p className="text-xs text-slate-400">{url ? "Berkas tersedia" : optional ? "Tidak dilampirkan" : "Berkas wajib tidak ada"}</p></div></div>{url && <Button type="button" variant="outline" size="sm" className="mt-3 w-full rounded-xl" onClick={() => onOpen(url, label)}><ShieldCheck size={14} className="mr-2" />Buka dokumen</Button>}</div>;
}

function stageLabel(stage?: Teacher["verification_stage"]) {
  return stage === "documents_checked" ? "Dokumen diperiksa"
    : stage === "whatsapp_scheduled" ? "Tes WA dijadwalkan"
      : stage === "whatsapp_passed" ? "Tes WA lulus"
        : stage === "approved" ? "Siap mengajar"
          : stage === "rejected" ? "Ditolak"
            : "Pendaftaran masuk";
}

function VerificationSteps({ stage }: { stage?: Teacher["verification_stage"] }) {
  const order = ["submitted", "documents_checked", "whatsapp_scheduled", "whatsapp_passed"] as const;
  const active = Math.max(0, order.indexOf((stage || "submitted") as typeof order[number]));
  return <ol className="grid grid-cols-4 gap-1" aria-label="Tahap verifikasi tutor">{["Masuk", "Dokumen", "Tes WA", "Lulus"].map((label, index) => <li key={label} className={index <= active ? "rounded-lg bg-indigo-600 px-2 py-2 text-center text-[10px] font-black text-white" : "rounded-lg bg-white px-2 py-2 text-center text-[10px] font-black text-slate-400"}>{label}</li>)}</ol>;
}