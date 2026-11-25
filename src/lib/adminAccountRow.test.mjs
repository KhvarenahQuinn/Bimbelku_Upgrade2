import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import { createRequire } from "node:module";
import vm from "node:vm";
import React from "react";
import { renderToStaticMarkup } from "react-dom/server";
import ts from "typescript";

const require = createRequire(import.meta.url);
const source = await readFile(new URL("../components/AdminAccountRow.tsx", import.meta.url), "utf8");
const { outputText } = ts.transpileModule(source, {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.ReactJSX },
});
const module = { exports: {} };
vm.runInNewContext(outputText, {
  module,
  exports: module.exports,
  require: (specifier) => {
    if (specifier === "lucide-react") return require(specifier);
    if (specifier === "react/jsx-runtime") return require(specifier);
    throw new Error(`Unexpected module import: ${specifier}`);
  },
}, { filename: "AdminAccountRow.tsx" });
const AdminAccountRow = module.exports.default;

const baseProps = {
  busy: false,
  currentAdminEmail: "primary@example.test",
  presetForPermissions: () => "Custom",
  onEdit() {},
  onStatus() {},
  onDelete() {},
};

const renderRow = (admin) => renderToStaticMarkup(React.createElement(AdminAccountRow, { ...baseProps, admin }));

test("primary admin row shows all permissions and hides edit, deactivate, and delete actions", () => {
  const html = renderRow({
    id: 1,
    name: "Primary Admin",
    email: "primary@example.test",
    status: "active",
    is_primary: true,
    is_primary_admin: true,
    admin_permissions: [],
  });

  assert.match(html, /Admin Utama · Semua izin/);
  assert.match(html, /Akun Utama \(Terlindungi\)/);
  assert.doesNotMatch(html, />Edit</);
  assert.doesNotMatch(html, />Nonaktifkan</);
  assert.doesNotMatch(html, />Hapus</);
});

test("current primary admin is protected even if the API primary flags are absent", () => {
  const html = renderRow({
    id: 2,
    name: "Primary Admin",
    email: "PRIMARY@example.test",
    status: "active",
    is_primary: false,
    admin_permissions: [],
  });

  assert.match(html, /Admin Utama · Semua izin/);
  assert.match(html, /Akun Utama \(Terlindungi\)/);
  assert.doesNotMatch(html, />Nonaktifkan</);
  assert.doesNotMatch(html, />Hapus</);
});
