import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import vm from "node:vm";
import ts from "typescript";

async function loadTypeScriptModule(path) {
  const source = await readFile(new URL(path, import.meta.url), "utf8");
  const { outputText } = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  });
  const module = { exports: {} };
  vm.runInNewContext(outputText, { module, exports: module.exports, localStorage: { getItem: () => null } });
  return module.exports;
}

const permissions = await loadTypeScriptModule("./adminPermissions.ts");
const rates = await loadTypeScriptModule("./hourlyRates.ts");

const restricted = (permission) => ({ role: "admin", admin_permissions: [permission] });

test("each single finance grant can open the shared route and sees only its own tab", () => {
  const cases = [
    [permissions.ADMIN_PERMISSIONS.FINANCE_REPORTS, ["report"]],
    [permissions.ADMIN_PERMISSIONS.FINANCE_PAYMENTS, ["payments"]],
    [permissions.ADMIN_PERMISSIONS.FINANCE_REFUNDS, ["refunds"]],
    [permissions.ADMIN_PERMISSIONS.FINANCE_PAYOUTS, ["payouts"]],
  ];

  for (const [permission, expectedTabs] of cases) {
    const admin = restricted(permission);
    assert.equal(permissions.hasAnyAdminPermission(admin, Object.values(permissions.FINANCE_TAB_PERMISSIONS)), true);
    assert.deepEqual(Array.from(permissions.allowedFinanceTabs(admin)), expectedTabs);
    assert.equal(permissions.firstAllowedAdminPath(admin), `/admin/finance?tab=${expectedTabs[0]}`);
  }
});

test("finance report path is distinct from payouts and admin profile is not dashboard-gated", () => {
  const payoutAdmin = restricted(permissions.ADMIN_PERMISSIONS.FINANCE_PAYOUTS);
  const reportAdmin = restricted(permissions.ADMIN_PERMISSIONS.FINANCE_REPORTS);
  const profileAdmin = restricted(permissions.ADMIN_PERMISSIONS.USERS_MANAGE);

  assert.equal(permissions.permissionForAdminPath("/admin/finance/report"), permissions.ADMIN_PERMISSIONS.FINANCE_REPORTS);
  assert.equal(permissions.permissionForAdminPath("/admin/finance/report.pdf"), permissions.ADMIN_PERMISSIONS.FINANCE_REPORTS);
  assert.equal(permissions.canAdmin(payoutAdmin, permissions.ADMIN_PERMISSIONS.FINANCE_REPORTS), false);
  assert.equal(permissions.canAdmin(reportAdmin, permissions.ADMIN_PERMISSIONS.FINANCE_REPORTS), true);
  assert.equal(permissions.canAdmin(profileAdmin, "admin.profile"), true);
  assert.equal(permissions.canAdmin({ role: "student" }, "admin.profile"), false);
});

test("hourly amount validation matches the API range, including its endpoints", () => {
  assert.equal(rates.hourlyRateAmountError(999), "Minimal Rp1.000 per jam.");
  assert.equal(rates.hourlyRateAmountError(1_000), "");
  assert.equal(rates.hourlyRateAmountError(10_000_000), "");
  assert.equal(rates.hourlyRateAmountError(10_000_001), "Maksimal Rp10.000.000 per jam.");
});

test("route, finance tab, profile menu, and hourly-rate UI remain permission-aware", async () => {
  const [app, finance, layout, ratesPage] = await Promise.all([
    readFile(new URL("../App.tsx", import.meta.url), "utf8"),
    readFile(new URL("../pages/admin/FinanceCenter.tsx", import.meta.url), "utf8"),
    readFile(new URL("../components/AdminLayout.tsx", import.meta.url), "utf8"),
    readFile(new URL("../pages/admin/HourlyRates.tsx", import.meta.url), "utf8"),
  ]);

  assert.match(app, /requiredAnyPermissions=\{\[ADMIN_PERMISSIONS\.FINANCE_PAYMENTS, ADMIN_PERMISSIONS\.FINANCE_PAYOUTS, ADMIN_PERMISSIONS\.FINANCE_REFUNDS, ADMIN_PERMISSIONS\.FINANCE_REPORTS\]\}/);
  assert.match(finance, /allowedFinanceTabs\(admin\)/);
  assert.match(finance, /canAdmin\(admin, FINANCE_TAB_PERMISSIONS\.payments\)/);
  assert.match(finance, /canAdmin\(admin, FINANCE_TAB_PERMISSIONS\.refunds\)/);
  assert.match(layout, /profileTo="\/admin\/profile"/);
  assert.doesNotMatch(layout, /accountTo="\/admin\/settings-display"/);
  assert.match(ratesPage, /MAX_HOURLY_RATE/);
  assert.match(ratesPage, /Nonaktif · tidak diterapkan/);
  assert.match(ratesPage, /min-h-\[44px\] min-w-\[44px\]/);
});
