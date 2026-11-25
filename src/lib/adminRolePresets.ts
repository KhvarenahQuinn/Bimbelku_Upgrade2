import { ADMIN_PERMISSIONS } from "@/lib/adminPermissions";

export const ADMIN_PERMISSION_PRESETS = {
  "Finance Admin": [
    ADMIN_PERMISSIONS.FINANCE_PAYMENTS,
    ADMIN_PERMISSIONS.FINANCE_PAYOUTS,
    ADMIN_PERMISSIONS.FINANCE_REFUNDS,
    ADMIN_PERMISSIONS.FINANCE_REPORTS,
  ],
  "Tutor & Academic Ops": [
    ADMIN_PERMISSIONS.OPERATIONS_DASHBOARD,
    ADMIN_PERMISSIONS.MATCHING_MANAGE,
    ADMIN_PERMISSIONS.TEACHERS_MANAGE,
    ADMIN_PERMISSIONS.CLASSES_MANAGE,
    ADMIN_PERMISSIONS.CONTENT_MANAGE,
  ],
  "Customer Support & Cases": [
    ADMIN_PERMISSIONS.OPERATIONS_DASHBOARD,
    ADMIN_PERMISSIONS.CASES_MANAGE,
    ADMIN_PERMISSIONS.USERS_MANAGE,
    ADMIN_PERMISSIONS.CLASSES_MANAGE,
    ADMIN_PERMISSIONS.SUPPORT_MANAGE,
  ],
  "Content & Pricing Admin": [
    ADMIN_PERMISSIONS.CONTENT_MANAGE,
    ADMIN_PERMISSIONS.SETTINGS_MANAGE,
  ],
  "Audit & Compliance Viewer": [
    ADMIN_PERMISSIONS.OPERATIONS_DASHBOARD,
    ADMIN_PERMISSIONS.AUDIT_VIEW,
  ],
  "Full Admin": Object.values(ADMIN_PERMISSIONS),
} as const;

export type AdminRolePreset = keyof typeof ADMIN_PERMISSION_PRESETS | "Custom";

const normalized = (permissions: readonly string[]) => [...new Set(permissions)].sort();

export const permissionsForAdminPreset = (preset: AdminRolePreset): string[] =>
  preset === "Custom" ? [] : [...ADMIN_PERMISSION_PRESETS[preset]];

export const adminPresetForPermissions = (permissions: readonly string[]): AdminRolePreset => {
  const selected = normalized(permissions);
  for (const [preset, presetPermissions] of Object.entries(ADMIN_PERMISSION_PRESETS)) {
    if (JSON.stringify(selected) === JSON.stringify(normalized(presetPermissions))) return preset as AdminRolePreset;
  }
  return "Custom";
};

export const ADMIN_ROLE_PRESET_OPTIONS: AdminRolePreset[] = [
  ...Object.keys(ADMIN_PERMISSION_PRESETS) as Array<keyof typeof ADMIN_PERMISSION_PRESETS>,
  "Custom",
];
