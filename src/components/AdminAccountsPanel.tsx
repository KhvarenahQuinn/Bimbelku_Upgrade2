import { useEffect, useState, type FormEvent } from "react";
import { Loader2, Pencil, Plus, ShieldCheck, Trash2, UserCog, X } from "lucide-react";

import http, { getApiError } from "@/lib/http";
import { notify } from "@/lib/notify";
import PasswordInput from "@/components/PasswordInput";
import { ADMIN_ROLE_PRESET_OPTIONS, adminPresetForPermissions, permissionsForAdminPreset, type AdminRolePreset } from "@/lib/adminRolePresets";

type AdminAccount = {
  id: number;
  name: string;
  email: string;
  status: string;
  is_primary: boolean;
  admin_permissions: string[];
  created_at?: string;
};

type PermissionGroup = { key: string; label: string; permissions: Array<{ code: string; label: string; description: string }> };
type EditForm = {
  name: string;
  email: string;
  password: string;
  superAdminPassword: string;
  adminPermissions: string[];
  preset: AdminRolePreset;
};

export default function AdminAccountsPanel() {
  const [accounts, setAccounts] = useState<AdminAccount[]>([]);
  const [permissionGroups, setPermissionGroups] = useState<PermissionGroup[]>([]);
  const [allowed, setAllowed] = useState(true);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);

  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [passwordError, setPasswordError] = useState("");
  const [editPasswordError, setEditPasswordError] = useState("");
  const [primaryPassword, setPrimaryPassword] = useState("");
  const [newAdminPermissions, setNewAdminPermissions] = useState<string[]>([]);
  const [newAdminPreset, setNewAdminPreset] = useState<AdminRolePreset>("Custom");

  const [statusTarget, setStatusTarget] = useState<AdminAccount | null>(null);
  const [statusPassword, setStatusPassword] = useState("");
  const [editTarget, setEditTarget] = useState<AdminAccount | null>(null);
  const [editForm, setEditForm] = useState<EditForm>({ name: "", email: "", password: "", superAdminPassword: "", adminPermissions: [], preset: "Custom" });
  const [deleteTarget, setDeleteTarget] = useState<AdminAccount | null>(null);
  const [deletePassword, setDeletePassword] = useState("");

  const reload = async () => {
    setLoading(true);
    try {
      const [response, catalog] = await Promise.all([
        http.get<AdminAccount[]>("/admin/accounts"),
        http.get<{ groups: PermissionGroup[] }>("/admin/accounts/permission-catalog"),
      ]);
      setAccounts(response.data);
      setPermissionGroups(catalog.data.groups);
      setAllowed(true);
    } catch (error) {
      const status = (error as { response?: { status?: number } }).response?.status;
      if (status === 403) setAllowed(false);
      else notify.error(getApiError(error, "Akun admin belum dapat dimuat."));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { void reload(); }, []);

  const create = async (event: FormEvent) => {
    event.preventDefault();
    if (password.length < 12) {
      setPasswordError("Kata sandi admin minimal 12 karakter.");
      document.getElementById("new-admin-password")?.focus();
      return;
    }
    setPasswordError("");
    setBusy(true);
    try {
      await http.post("/admin/accounts", { name, email, password, admin_permissions: newAdminPermissions, super_admin_password: primaryPassword });
      notify.success("Akun admin berhasil dibuat.");
      setName("");
      setEmail("");
      setPassword("");
      setPrimaryPassword("");
      setNewAdminPermissions([]);
      setNewAdminPreset("Custom");
      await reload();
    } catch (error) {
      notify.error(getApiError(error, "Akun admin gagal dibuat."));
    } finally {
      setBusy(false);
    }
  };

  const openEdit = (admin: AdminAccount) => {
    setEditTarget(admin);
    const adminPermissions = admin.admin_permissions || [];
    setEditPasswordError("");
    setEditForm({ name: admin.name, email: admin.email, password: "", superAdminPassword: "", adminPermissions, preset: adminPresetForPermissions(adminPermissions) });
  };

  const update = async (event: FormEvent) => {
    event.preventDefault();
    if (!editTarget) return;
    if (editForm.password && editForm.password.length < 12) {
      setEditPasswordError("Kata sandi admin minimal 12 karakter.");
      document.getElementById("edit-admin-password")?.focus();
      return;
    }
    setEditPasswordError("");
    setBusy(true);
    try {
      await http.put(`/admin/accounts/${editTarget.id}`, {
        name: editForm.name,
        email: editForm.email,
        password: editForm.password || null,
        ...(!editTarget.is_primary ? { admin_permissions: editForm.adminPermissions } : {}),
        super_admin_password: editForm.superAdminPassword,
      });
      notify.success("Akun admin berhasil diperbarui.");
      setEditTarget(null);
      await reload();
    } catch (error) {
      notify.error(getApiError(error, "Akun admin gagal diperbarui."));
    } finally {
      setBusy(false);
    }
  };

  const changeStatus = async (event: FormEvent) => {
    event.preventDefault();
    if (!statusTarget) return;
    setBusy(true);
    try {
      await http.patch(`/admin/accounts/${statusTarget.id}/status`, {
        status: statusTarget.status === "active" ? "banned" : "active",
        super_admin_password: statusPassword,
      });
      notify.success("Status admin berhasil diperbarui.");
      setStatusPassword("");
      setStatusTarget(null);
      await reload();
    } catch (error) {
      notify.error(getApiError(error, "Status admin gagal diperbarui."));
    } finally {
      setBusy(false);
    }
  };

  const remove = async (event: FormEvent) => {
    event.preventDefault();
    if (!deleteTarget) return;
    setBusy(true);
    try {
      await http.delete(`/admin/accounts/${deleteTarget.id}`, {
        data: { super_admin_password: deletePassword },
      });
      notify.success("Akun admin berhasil dihapus.");
      setDeletePassword("");
      setDeleteTarget(null);
      await reload();
    } catch (error) {
      notify.error(getApiError(error, "Akun admin gagal dihapus."));
    } finally {
      setBusy(false);
    }
  };

  if (!allowed) {
    return <section className="rounded-2xl border border-slate-200 bg-white p-6 text-sm text-slate-600">Hanya admin utama yang dapat melihat dan mengelola akun admin.</section>;
  }

  return (
    <div className="grid min-w-0 gap-6 pb-8 xl:grid-cols-[minmax(0,1fr)_360px]">
      {editTarget && (
        <Modal title="Edit akun admin" onClose={() => setEditTarget(null)}>
          <form onSubmit={update} className="space-y-4">
            <Field label="Nama"><input required minLength={2} maxLength={120} value={editForm.name} onChange={(event) => setEditForm((value) => ({ ...value, name: event.target.value }))} className="form-field mt-1 min-h-11" /></Field>
            <Field label="Email"><input required type="email" value={editForm.email} onChange={(event) => setEditForm((value) => ({ ...value, email: event.target.value }))} className="form-field mt-1 min-h-11" /></Field>
            <div><label htmlFor="edit-admin-password" className="block text-sm font-bold text-slate-700">Kata sandi baru (opsional)</label><PasswordInput id="edit-admin-password" label="kata sandi baru" autoComplete="new-password" value={editForm.password} onChange={(event) => { const value = event.target.value; setEditForm((current) => ({ ...current, password: value })); if (value.length >= 12 || !value) setEditPasswordError(""); }} onBlur={() => setEditPasswordError(editForm.password && editForm.password.length < 12 ? "Kata sandi admin minimal 12 karakter." : "")} aria-invalid={Boolean(editPasswordError)} aria-describedby={`edit-admin-password-hint${editPasswordError ? " edit-admin-password-error" : ""}`} className="form-field mt-1 min-h-11" /><span id="edit-admin-password-hint" className="mt-1 block text-xs font-medium text-slate-500">Minimal 12 karakter. Kosongkan jika kata sandi tidak diubah.</span>{editPasswordError && <span id="edit-admin-password-error" className="mt-1 block text-xs font-semibold text-red-700" role="alert">{editPasswordError}</span>}</div>
            {!editTarget.is_primary ? <AdminPermissionSelector
              groups={permissionGroups}
              preset={editForm.preset}
              permissions={editForm.adminPermissions}
              onPresetChange={(preset) => setEditForm((value) => ({ ...value, preset, adminPermissions: permissionsForAdminPreset(preset) }))}
              onPermissionsChange={(adminPermissions) => setEditForm((value) => ({ ...value, adminPermissions, preset: adminPresetForPermissions(adminPermissions) }))}
            /> : <p className="rounded-xl border border-orange-200 bg-orange-50 p-3 text-sm font-semibold text-orange-900">Admin utama selalu memiliki semua izin. Hak akses ini tidak dapat dikurangi dari formulir admin.</p>}
            <div><label htmlFor="edit-admin-confirm-password" className="block text-sm font-bold text-slate-700">Konfirmasi kata sandi admin utama</label><PasswordInput id="edit-admin-confirm-password" required label="kata sandi admin utama" autoComplete="current-password" value={editForm.superAdminPassword} onChange={(event) => setEditForm((value) => ({ ...value, superAdminPassword: event.target.value }))} className="form-field mt-1 min-h-11" /></div>
            <ModalActions busy={busy} onCancel={() => setEditTarget(null)} confirm="Simpan perubahan" />
          </form>
        </Modal>
      )}

      {statusTarget && (
        <Modal title={`${statusTarget.status === "active" ? "Nonaktifkan" : "Aktifkan"} ${statusTarget.name}?`} onClose={() => setStatusTarget(null)}>
          <form onSubmit={changeStatus} className="space-y-4">
            <p className="text-sm leading-6 text-slate-600">Perubahan status langsung memengaruhi akses login dan dicatat di audit sistem.</p>
            <div><label htmlFor="status-admin-password" className="block text-sm font-bold text-slate-700">Kata sandi admin utama</label><PasswordInput id="status-admin-password" required label="kata sandi admin utama" autoComplete="current-password" value={statusPassword} onChange={(event) => setStatusPassword(event.target.value)} className="form-field mt-1 min-h-11" /></div>
            <ModalActions busy={busy} onCancel={() => setStatusTarget(null)} confirm="Konfirmasi status" />
          </form>
        </Modal>
      )}

      {deleteTarget && (
        <Modal title={`Hapus akun ${deleteTarget.name}?`} onClose={() => setDeleteTarget(null)}>
          <form onSubmit={remove} className="space-y-4">
            <p className="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold leading-6 text-rose-800">Akun tidak dapat login lagi dan hilang dari daftar. Riwayat audit/operasional tetap disimpan tanpa mengekspos identitas akun yang dihapus.</p>
            <div><label htmlFor="delete-admin-password" className="block text-sm font-bold text-slate-700">Kata sandi admin utama</label><PasswordInput id="delete-admin-password" required label="kata sandi admin utama" autoComplete="current-password" value={deletePassword} onChange={(event) => setDeletePassword(event.target.value)} className="form-field mt-1 min-h-11" /></div>
            <ModalActions busy={busy} onCancel={() => setDeleteTarget(null)} confirm="Hapus permanen" danger />
          </form>
        </Modal>
      )}

      <section className="min-w-0 rounded-2xl border border-slate-200 bg-white p-5">
        <div className="flex items-start gap-3">
          <span className="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-indigo-50 text-indigo-700"><UserCog size={20} /></span>
          <div><h2 className="text-xl font-black text-slate-900">Akun admin</h2><p className="mt-1 text-sm leading-6 text-slate-600">Admin utama dapat menambah, melihat, mengedit, mengubah status, dan menghapus admin lain.</p></div>
        </div>

        <div className="mt-5 space-y-3">
          {loading ? <div className="grid min-h-32 place-items-center text-sm font-bold text-slate-500"><Loader2 className="animate-spin" /></div> : accounts.map((admin) => (
            <article key={admin.id} className="flex min-w-0 flex-col gap-4 rounded-2xl border border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between">
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2"><p className="truncate font-black text-slate-900">{admin.name}</p>{admin.is_primary && <span className="rounded-full bg-orange-100 px-2 py-1 text-[10px] font-black uppercase tracking-wider text-orange-800">Admin utama</span>}</div>
                <p className="mt-1 break-all text-sm text-slate-500">{admin.email}</p>
                <span className={`mt-2 inline-flex rounded-full px-2.5 py-1 text-[10px] font-black uppercase ${admin.status === "active" ? "bg-emerald-50 text-emerald-700" : "bg-rose-50 text-rose-700"}`}>{admin.status === "active" ? "Aktif" : "Nonaktif"}</span>
                <span className="ml-2 mt-2 inline-flex rounded-full bg-indigo-50 px-2.5 py-1 text-[10px] font-black text-indigo-800">Admin · {admin.is_primary ? "Full Admin · Admin utama" : `${adminPresetForPermissions(admin.admin_permissions || [])} · ${admin.admin_permissions?.length || 0} izin`}</span>
              </div>
              <div className="flex flex-wrap gap-2">
                <button type="button" disabled={busy} onClick={() => openEdit(admin)} className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-slate-200 px-3 text-xs font-black text-slate-700 hover:bg-slate-50 disabled:opacity-50"><Pencil size={14} />Edit</button>
                {!admin.is_primary && <button type="button" disabled={busy} onClick={() => setStatusTarget(admin)} className="min-h-10 rounded-xl border border-slate-200 px-3 text-xs font-black text-slate-700 hover:bg-slate-50 disabled:opacity-50">{admin.status === "active" ? "Nonaktifkan" : "Aktifkan"}</button>}
                {!admin.is_primary && <button type="button" disabled={busy} onClick={() => setDeleteTarget(admin)} className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-rose-200 px-3 text-xs font-black text-rose-700 hover:bg-rose-50 disabled:opacity-50"><Trash2 size={14} />Hapus</button>}
              </div>
            </article>
          ))}
        </div>
      </section>

      <form onSubmit={create} className="min-w-0 self-start space-y-4 rounded-2xl border border-slate-200 bg-white p-5 pb-7">
        <div className="flex items-center gap-3"><span className="grid h-10 w-10 place-items-center rounded-2xl bg-orange-100 text-orange-700"><Plus size={18} /></span><div><h2 className="text-lg font-black text-slate-900">Tambah admin</h2><p className="text-xs font-medium text-slate-500">Peran tetap admin; pilih akses modul yang dibutuhkan.</p></div></div>
        <Field label="Nama"><input required minLength={2} maxLength={120} value={name} onChange={(event) => setName(event.target.value)} className="form-field mt-1 min-h-11" /></Field>
        <Field label="Email"><input required type="email" value={email} onChange={(event) => setEmail(event.target.value)} className="form-field mt-1 min-h-11" /></Field>
        <div><label htmlFor="new-admin-password" className="block text-sm font-bold text-slate-700">Kata sandi admin baru</label><PasswordInput id="new-admin-password" required label="kata sandi admin baru" autoComplete="new-password" value={password} onChange={(event) => { setPassword(event.target.value); if (event.target.value.length >= 12 || !event.target.value) setPasswordError(""); }} onBlur={() => setPasswordError(password.length > 0 && password.length < 12 ? "Kata sandi admin minimal 12 karakter." : "")} aria-invalid={Boolean(passwordError)} aria-describedby={`new-admin-password-hint${passwordError ? " new-admin-password-error" : ""}`} className="form-field mt-1 min-h-11" /><span id="new-admin-password-hint" className="mt-1 block text-xs font-medium text-slate-500">Minimal 12 karakter.</span>{passwordError && <span id="new-admin-password-error" className="mt-1 block text-xs font-semibold text-red-700" role="alert">{passwordError}</span>}</div>
        <AdminPermissionSelector
          groups={permissionGroups}
          preset={newAdminPreset}
          permissions={newAdminPermissions}
          onPresetChange={(preset) => { setNewAdminPreset(preset); setNewAdminPermissions(permissionsForAdminPreset(preset)); }}
          onPermissionsChange={(permissions) => { setNewAdminPermissions(permissions); setNewAdminPreset(adminPresetForPermissions(permissions)); }}
        />
        <div><label htmlFor="new-admin-confirm-password" className="block text-sm font-bold text-slate-700">Konfirmasi kata sandi admin utama</label><PasswordInput id="new-admin-confirm-password" required label="kata sandi admin utama" autoComplete="current-password" value={primaryPassword} onChange={(event) => setPrimaryPassword(event.target.value)} className="form-field mt-1 min-h-11" /></div>
        <button type="submit" disabled={busy} className="inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-orange-600 px-4 font-black text-white hover:bg-orange-700 disabled:opacity-50">{busy ? <Loader2 className="animate-spin" size={17} /> : <Plus size={17} />}{busy ? "Menyimpan…" : "Buat akun admin"}</button>
      </form>
    </div>
  );
}

function AdminPermissionSelector({ groups, preset, permissions, onPresetChange, onPermissionsChange }: { groups: PermissionGroup[]; preset: AdminRolePreset; permissions: string[]; onPresetChange: (preset: AdminRolePreset) => void; onPermissionsChange: (permissions: string[]) => void }) {
  const togglePermission = (code: string, checked: boolean) => onPermissionsChange(checked ? [...new Set([...permissions, code])] : permissions.filter((permission) => permission !== code));
  return <fieldset className="space-y-3 rounded-2xl border border-slate-200 p-4">
    <legend className="px-1 text-sm font-black text-slate-900">Admin Role Presets</legend>
    <label className="block text-xs font-bold text-slate-600">Preset akses<select value={preset} onChange={(event) => onPresetChange(event.target.value as AdminRolePreset)} className="form-field mt-1 min-h-11">{ADMIN_ROLE_PRESET_OPTIONS.map((option) => <option key={option} value={option}>{option}</option>)}</select></label>
    <p className="text-xs leading-5 text-slate-500">Preset mengisi izin awal. Centang atau hapus izin di bawah untuk penyesuaian; peran akun tetap <code>admin</code>.</p>
    {groups.map((group) => <section key={group.key} className="rounded-xl bg-slate-50 p-3">
      <h3 className="text-xs font-black uppercase tracking-wide text-slate-700">{group.label}</h3>
      <div className="mt-2 space-y-2">{group.permissions.map((permission) => <label key={permission.code} className="flex min-h-11 cursor-pointer items-start gap-3 rounded-lg bg-white p-2.5 text-sm">
        <input type="checkbox" checked={permissions.includes(permission.code)} onChange={(event) => togglePermission(permission.code, event.target.checked)} className="mt-0.5 h-4 w-4 shrink-0 rounded border-slate-300 text-orange-600 focus:ring-orange-500" />
        <span><span className="block font-bold text-slate-800">{permission.label}</span><span className="mt-0.5 block text-xs leading-5 text-slate-500">{permission.description}</span></span>
      </label>)}</div>
    </section>)}
    <p className="rounded-lg bg-indigo-50 px-3 py-2 text-xs font-bold text-indigo-900" role="status">Ringkasan: {permissions.length} dari {groups.reduce((count, group) => count + group.permissions.length, 0)} izin aktif{preset !== "Custom" ? ` · ${preset}` : " · Custom"}</p>
  </fieldset>;
}

function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: React.ReactNode }) {
  return <div className="fixed inset-0 z-[var(--layer-modal)] grid place-items-center bg-slate-950/65 p-4"><section role="dialog" aria-modal="true" aria-label={title} className="max-h-[calc(100dvh-2rem)] w-full max-w-lg overflow-y-auto rounded-3xl bg-white p-5 shadow-2xl sm:p-6"><div className="mb-5 flex items-start justify-between gap-3"><div><p className="text-xs font-black uppercase tracking-wider text-orange-700">Kelola admin</p><h2 className="mt-1 text-xl font-black text-slate-900">{title}</h2></div><button type="button" onClick={onClose} aria-label="Tutup" className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-slate-100 text-slate-600"><X size={18} /></button></div>{children}</section></div>;
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return <label className="block text-sm font-bold text-slate-700">{label}{children}</label>;
}

function ModalActions({ busy, onCancel, confirm, danger = false }: { busy: boolean; onCancel: () => void; confirm: string; danger?: boolean }) {
  return <div className="flex gap-2 pt-2"><button type="button" onClick={onCancel} className="min-h-11 flex-1 rounded-xl border border-slate-300 font-bold text-slate-700">Batal</button><button disabled={busy} className={`min-h-11 flex-1 rounded-xl px-3 font-black text-white disabled:opacity-50 ${danger ? "bg-rose-600 hover:bg-rose-700" : "bg-orange-600 hover:bg-orange-700"}`}>{busy ? "Memproses…" : confirm}</button></div>;
}
