import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import vm from "node:vm";
import ts from "typescript";
import React from "react";
import { renderToString } from "react-dom/server";
import { MemoryRouter } from "react-router-dom";

async function readSource(relativePath) {
  return readFile(new URL(relativePath, import.meta.url), "utf8");
}

async function loadTypeScriptModule(relativePath, requireOverrides = {}, globals = {}) {
  const source = await readSource(relativePath);
  const { outputText } = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.ReactJSX },
  });
  const module = { exports: {} };
  const requireModule = (name) => {
    if (Object.hasOwn(requireOverrides, name)) return requireOverrides[name];
    return require(name);
  };
  vm.runInNewContext(outputText, {
    module,
    exports: module.exports,
    require: requireModule,
    localStorage: { getItem: () => null },
    ...globals,
  }, { filename: relativePath });
  return module.exports;
}

const permissions = await loadTypeScriptModule("./adminPermissions.ts");
const redirects = await loadTypeScriptModule("../components/auth/guardRedirects.ts", {
  "@/lib/adminPermissions": permissions,
});

const memoryStorage = (values) => ({ getItem: (key) => values[key] ?? null });

test("FinanceCenter renders in a router with its admin permissions module", async () => {
  const layout = ({ children, title }) => React.createElement("div", { "data-testid": "admin-layout" }, title, children);
  const icon = () => null;
  const renderPermissions = await loadTypeScriptModule("./adminPermissions.ts", {}, {
    localStorage: memoryStorage({ user: JSON.stringify({ role: "admin", admin_type: "super_admin" }) }),
  });
  const financeModule = await loadTypeScriptModule("../pages/admin/FinanceCenter.tsx", {
    "react": {
      ...React,
      useMemo: (callback) => callback(),
      useState: (initial) => [initial, () => {}],
      useCallback: (callback) => callback,
      useEffect: (callback) => { callback(); },
      lazy: () => () => null,
    },
    "react/jsx-runtime": await import("react/jsx-runtime"),
    "react-router-dom": { useSearchParams: () => [new URLSearchParams(), () => {}] },
    "lucide-react": Object.fromEntries(["Banknote", "FileBarChart", "Loader2", "ReceiptText", "Undo2", "WalletCards"].map((name) => [name, icon])),
    "@/components/AdminLayout": { __esModule: true, default: layout },
    "@/lib/adminPermissions": renderPermissions,
    "@/lib/http": { getCached: async () => ({ data: { counts: {} } }) },
  }, {
    localStorage: memoryStorage({ user: JSON.stringify({ role: "admin", admin_type: "super_admin" }) }),
    window: { addEventListener() {}, removeEventListener() {}, setInterval() { return 1; }, clearInterval() {} },
  });
  const FinanceCenter = financeModule.default;
  const html = renderToString(FinanceCenter());
  assert.match(html, /Pusat keuangan/);
  assert.match(html, /Laporan/);
  assert.match(html, /Pembayaran/);
});

test("stored Home destination is role-aware and safe when storage or router context is unavailable", () => {
  assert.equal(redirects.getStoredHomeRedirectPath(memoryStorage({})), "/");
  assert.equal(redirects.getStoredHomeRedirectPath(memoryStorage({ token: "session", user: "{bad json" })), "/");
  assert.equal(redirects.getStoredHomeRedirectPath(memoryStorage({ token: "session", user: JSON.stringify({ role: "teacher" }) })), "/guru");
  assert.equal(redirects.getStoredHomeRedirectPath(memoryStorage({ token: "session", user: JSON.stringify({ role: "student" }) })), "/student/dashboard");
  assert.equal(redirects.getStoredHomeRedirectPath(memoryStorage({ token: "session", user: JSON.stringify({ role: "admin", admin_type: "super_admin" }) })), "/admin");
  assert.equal(redirects.getStoredHomeRedirectPath(memoryStorage({ token: "session", user: JSON.stringify({ role: "admin", admin_permissions: [permissions.ADMIN_PERMISSIONS.FINANCE_REPORTS] }) })), "/admin/finance?tab=report");
});

test("error boundary Home button resets error state and navigates to the stored role home", async () => {
  let assigned = "";
  const module = await loadTypeScriptModule("../components/AppErrorBoundary.tsx", {
    react: React,
    "react/jsx-runtime": await import("react/jsx-runtime"),
    "@/components/auth/guardRedirects": { getStoredHomeRedirectPath: () => "/guru" },
    "@/lib/session": { clearBrowserSession() {} },
  }, {
    window: { location: { assign(path) { assigned = path; }, reload() {} } },
    sessionStorage: { removeItem() {} },
  });
  const boundary = new module.default({ children: null });
  boundary.state = { hasError: true };
  boundary.setState = (update, callback) => {
    boundary.state = { ...boundary.state, ...(typeof update === "function" ? update(boundary.state) : update) };
    callback?.();
  };
  const findHomeButton = (node) => {
    if (Array.isArray(node)) return node.map(findHomeButton).find(Boolean);
    if (!React.isValidElement(node)) return undefined;
    const children = React.Children.toArray(node.props.children);
    const text = (child) => React.isValidElement(child)
      ? React.Children.toArray(child.props.children).map(text).join("")
      : typeof child === "string" || typeof child === "number" ? String(child) : "";
    if (node.type === "button" && children.map(text).join("").includes("Beranda")) return node;
    return children.map(findHomeButton).find(Boolean);
  };
  const homeButton = findHomeButton(boundary.render());
  assert.ok(homeButton, "expected the boundary Home button");
  homeButton.props.onClick();
  assert.equal(boundary.state.hasError, false);
  assert.equal(assigned, "/guru");
});

test("404 page renders with a role-aware Home link and wildcard route uses it", async () => {
  const [app, pageModule] = await Promise.all([
    readSource("../App.tsx"),
    loadTypeScriptModule("../pages/NotFound.tsx", {
      react: React,
      "react/jsx-runtime": await import("react/jsx-runtime"),
      "react-router-dom": await import("react-router-dom"),
      "@/components/auth/guardRedirects": { getStoredHomeRedirectPath: () => "/student/dashboard" },
    }),
  ]);
  assert.match(app, /<Route path="\*" element=\{<NotFound \/>\} \/>/);
  const html = renderToString(React.createElement(MemoryRouter, null, React.createElement(pageModule.default)));
  assert.match(html, /Halaman tidak ditemukan/);
  assert.ok(html.includes('href="/student/dashboard"'));
});

test("homepage tutor section falls back to public directory only without gallery or legacy photos", async () => {
  const source = await readSource("../components/TutorSection.tsx");
  assert.match(source, /galleryTutors\.length/);
  assert.match(source, /if \(hasLegacyPhotos\) return/);
  assert.match(source, /getCached<PublicTutor\[]>\("\/public-tutors"/);
});

test("login lockout countdown ticks, cleans up, and cooperates with Turnstile readiness", async () => {
  const source = await readSource("../pages/Login.tsx");
  assert.match(source, /retry_after_seconds/);
  assert.match(source, /setInterval\(\(\) => \{\s*setRemainingSeconds\(\(seconds\) => Math\.max\(0, seconds - 1\)\)/);
  assert.match(source, /return \(\) => window\.clearInterval\(timer\)/);
  assert.match(source, /remainingSeconds > 0 \|\| \(turnstileEnabled && !turnstileToken\.trim\(\)\)/);
  assert.match(source, /Coba lagi dalam \{remainingSeconds\} detik/);
});

test("registration links to canonical terms and legacy slug redirects", async () => {
  const [app, register] = await Promise.all([readSource("../App.tsx"), readSource("../pages/Register.tsx")]);
  assert.match(register, /to="\/terms"[^>]*>Syarat & Ketentuan/);
  assert.match(app, /<Route path="\/syarat-dan-ketentuan" element=\{<Navigate to="\/terms" replace \/>\} \/>/);
});
