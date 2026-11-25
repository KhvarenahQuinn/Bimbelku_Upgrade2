import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import vm from "node:vm";
import ts from "typescript";

let adminPermissionModule;

async function loadTypeScriptModule(path) {
  const source = await readFile(new URL(path, import.meta.url), "utf8");
  const { outputText } = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  });
  const module = { exports: {} };
  vm.runInNewContext(outputText, {
    module,
    exports: module.exports,
    localStorage: { getItem: () => null },
    require: (specifier) => {
      if (specifier === "@/lib/adminPermissions" && adminPermissionModule) return adminPermissionModule;
      throw new Error(`Unexpected module import: ${specifier}`);
    },
  });
  return module.exports;
}

adminPermissionModule = await loadTypeScriptModule("./adminPermissions.ts");
const { ADMIN_PERMISSIONS } = adminPermissionModule;
const { ADMIN_PERMISSION_PRESETS, ADMIN_ROLE_PRESET_OPTIONS, adminPresetForPermissions, permissionsForAdminPreset } = await loadTypeScriptModule("./adminRolePresets.ts");
const assertJsonEqual = (actual, expected) => assert.equal(JSON.stringify(actual), JSON.stringify(expected));

test("admin role presets map to known catalog permissions", () => {
  assertJsonEqual(permissionsForAdminPreset("Finance Admin"), [
    ADMIN_PERMISSIONS.FINANCE_PAYMENTS,
    ADMIN_PERMISSIONS.FINANCE_PAYOUTS,
    ADMIN_PERMISSIONS.FINANCE_REFUNDS,
    ADMIN_PERMISSIONS.FINANCE_REPORTS,
  ]);
  assertJsonEqual(permissionsForAdminPreset("Tutor & Academic Ops"), [
    ADMIN_PERMISSIONS.OPERATIONS_DASHBOARD,
    ADMIN_PERMISSIONS.MATCHING_MANAGE,
    ADMIN_PERMISSIONS.TEACHERS_MANAGE,
    ADMIN_PERMISSIONS.CLASSES_MANAGE,
    ADMIN_PERMISSIONS.CONTENT_MANAGE,
  ]);
  assertJsonEqual(permissionsForAdminPreset("Customer Support & Cases"), [
    ADMIN_PERMISSIONS.OPERATIONS_DASHBOARD,
    ADMIN_PERMISSIONS.CASES_MANAGE,
    ADMIN_PERMISSIONS.USERS_MANAGE,
    ADMIN_PERMISSIONS.CLASSES_MANAGE,
    ADMIN_PERMISSIONS.SUPPORT_MANAGE,
  ]);
  assertJsonEqual(permissionsForAdminPreset("Content & Pricing Admin"), [
    ADMIN_PERMISSIONS.CONTENT_MANAGE,
    ADMIN_PERMISSIONS.SETTINGS_MANAGE,
  ]);
  assertJsonEqual(permissionsForAdminPreset("Audit & Compliance Viewer"), [
    ADMIN_PERMISSIONS.OPERATIONS_DASHBOARD,
    ADMIN_PERMISSIONS.AUDIT_VIEW,
  ]);
  assertJsonEqual(permissionsForAdminPreset("Full Admin"), Object.values(ADMIN_PERMISSIONS));
  assertJsonEqual(permissionsForAdminPreset("Custom"), []);
});

test("preset recognition ignores permission order and reports modified grants as custom", () => {
  assert.equal(adminPresetForPermissions([...ADMIN_PERMISSION_PRESETS["Finance Admin"]].reverse()), "Finance Admin");
  assert.equal(adminPresetForPermissions([ADMIN_PERMISSIONS.FINANCE_PAYMENTS]), "Custom");
  assertJsonEqual(ADMIN_ROLE_PRESET_OPTIONS, [
    "Finance Admin",
    "Tutor & Academic Ops",
    "Customer Support & Cases",
    "Content & Pricing Admin",
    "Audit & Compliance Viewer",
    "Full Admin",
    "Custom",
  ]);
});
