import { Pencil, Trash2 } from "lucide-react";

export type AdminAccountRowData = {
  id: number;
  name: string;
  email: string;
  status: string;
  is_primary: boolean;
  is_primary_admin?: boolean;
  admin_permissions: string[];
};

type Props = {
  admin: AdminAccountRowData;
  busy: boolean;
  currentAdminEmail: string;
  presetForPermissions: (permissions: string[]) => string;
  onEdit: (admin: AdminAccountRowData) => void;
  onStatus: (admin: AdminAccountRowData) => void;
  onDelete: (admin: AdminAccountRowData) => void;
};

export default function AdminAccountRow({
  admin,
  busy,
  currentAdminEmail,
  presetForPermissions,
  onEdit,
  onStatus,
  onDelete,
}: Props) {
  const isPrimary = admin.is_primary_admin === true
    || admin.is_primary === true
    || admin.email.trim().toLowerCase() === currentAdminEmail.trim().toLowerCase();

  return (
    <article className="flex min-w-0 flex-col gap-4 rounded-2xl border border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between">
      <div className="min-w-0">
        <div className="flex flex-wrap items-center gap-2">
          <p className="truncate font-black text-slate-900">{admin.name}</p>
          {isPrimary && <span className="rounded-full bg-orange-100 px-2 py-1 text-[10px] font-black uppercase tracking-wider text-orange-800">Admin utama</span>}
        </div>
        <p className="mt-1 break-all text-sm text-slate-500">{admin.email}</p>
        <span className={`mt-2 inline-flex rounded-full px-2.5 py-1 text-[10px] font-black uppercase ${admin.status === "active" ? "bg-emerald-50 text-emerald-700" : "bg-rose-50 text-rose-700"}`}>{admin.status === "active" ? "Aktif" : "Nonaktif"}</span>
        <span className="ml-2 mt-2 inline-flex rounded-full bg-indigo-50 px-2.5 py-1 text-[10px] font-black text-indigo-800">
          {isPrimary ? "Admin Utama · Semua izin" : `Admin · ${presetForPermissions(admin.admin_permissions || [])} · ${admin.admin_permissions?.length || 0} izin`}
        </span>
      </div>
      {isPrimary ? (
        <span className="inline-flex min-h-10 items-center rounded-xl bg-slate-100 px-3 text-xs font-bold text-slate-600">Akun Utama (Terlindungi)</span>
      ) : (
        <div className="flex flex-wrap gap-2">
          <button type="button" disabled={busy} onClick={() => onEdit(admin)} className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-slate-200 px-3 text-xs font-black text-slate-700 hover:bg-slate-50 disabled:opacity-50"><Pencil size={14} />Edit</button>
          <button type="button" disabled={busy} onClick={() => onStatus(admin)} className="min-h-10 rounded-xl border border-slate-200 px-3 text-xs font-black text-slate-700 hover:bg-slate-50 disabled:opacity-50">{admin.status === "active" ? "Nonaktifkan" : "Aktifkan"}</button>
          <button type="button" disabled={busy} onClick={() => onDelete(admin)} className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-rose-200 px-3 text-xs font-black text-rose-700 hover:bg-rose-50 disabled:opacity-50"><Trash2 size={14} />Hapus</button>
        </div>
      )}
    </article>
  );
}
