import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import vm from "node:vm";
import ts from "typescript";

async function readSource(relativePath) {
  return readFile(new URL(relativePath, import.meta.url), "utf8");
}

async function loadTsModule(relativePath, requireOverrides = {}, globals = {}) {
  const source = await readSource(relativePath);
  const { outputText } = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
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

test("isTurnstileEnabled returns boolean and is used across all four auth pages", async () => {
  const source = await readSource("./turnstile.ts");
  const replaced = source.replace(
    "import.meta.env.VITE_TURNSTILE_SITE_KEY",
    "globalThis.__TEST_TURNSTILE_KEY",
  );
  const { outputText } = ts.transpileModule(replaced, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  });
  const module = { exports: {} };
  const ctx = { module, exports: module.exports, globalThis: {} };
  vm.runInNewContext(outputText, ctx);
  const { isTurnstileEnabled } = module.exports;

  assert.equal(isTurnstileEnabled(undefined), false);
  assert.equal(isTurnstileEnabled(""), false);
  assert.equal(isTurnstileEnabled("   "), false);
  assert.equal(isTurnstileEnabled("0x4AAAAAAGGQ7nv5gZZOxoZk"), true);

  ctx.globalThis.__TEST_TURNSTILE_KEY = undefined;
  assert.equal(isTurnstileEnabled(), false);

  for (const page of ["../pages/ForgotPassword.tsx", "../pages/Register.tsx", "../pages/Login.tsx", "../pages/AdminLogin.tsx"]) {
    const pageSource = await readSource(page);
    assert.match(pageSource, /isTurnstileEnabled\(/, `${page} must use isTurnstileEnabled()`);
    assert.doesNotMatch(pageSource, /VITE_TURNSTILE_SITE_KEY\?\.trim\(\)\s*!==\s*""/, `${page} must not compare undefined !== ""`);
  }
});

test("notify queues pre-mount toasts FIFO, dedupes before mount, caps at 5, and clears dedup after flush", async () => {
  const fired = [];
  const dispatched = [];
  const fakeToast = {
    success: (msg) => fired.push(["success", msg]),
    error: (msg) => fired.push(["error", msg]),
    warning: (msg) => fired.push(["warning", msg]),
  };

  const notifyMod = await loadTsModule(
    "./notify.ts",
    { sonner: { toast: fakeToast } },
    {
      window: { dispatchEvent: (evt) => dispatched.push(evt.type) },
      Event: class Event {
        constructor(type) { this.type = type; }
      },
    },
  );

  // Queue 6 distinct items + 1 pre-mount duplicate before DeferredToaster mounts
  notifyMod.notify.error("First error");
  notifyMod.notify.error("First error"); // deduped while queued
  notifyMod.notify.success("Second");
  notifyMod.notify.warning("Third");
  notifyMod.notify.error("Fourth");
  notifyMod.notify.success("Fifth");
  notifyMod.notify.error("Sixth"); // pushes "First error" out (max 5 cap)

  assert.equal(fired.length, 0);
  assert.ok(dispatched.includes("bimbelku:toast-needed"));

  notifyMod.markToasterReady();
  await new Promise((resolve) => setTimeout(resolve, 10));

  assert.deepEqual(fired, [
    ["success", "Second"],
    ["warning", "Third"],
    ["error", "Fourth"],
    ["success", "Fifth"],
    ["error", "Sixth"],
  ]);

  // Repeated legitimate toasts after mount must NOT be suppressed by pre-mount dedup set
  notifyMod.notify.success("Second");
  notifyMod.notify.success("Second");
  await new Promise((resolve) => setTimeout(resolve, 10));

  assert.deepEqual(fired.slice(5), [
    ["success", "Second"],
    ["success", "Second"],
  ]);
});

test("tutorialStorage key parity between RoleQuickGuide and PackageBuilder", async () => {
  const storageMod = await loadTsModule("./tutorialStorage.ts", {}, {
    localStorage: { getItem: (k) => (k === "user" ? JSON.stringify({ id: 42 }) : null) },
  });
  assert.equal(storageMod.getPackageBuilderTutorialKey(), "bimbelku.tutorial.student.multi-subject.v1.42.easy-v2");

  const guideSrc = await readSource("../components/RoleQuickGuide.tsx");
  const builderSrc = await readSource("../pages/students/PackageBuilder.tsx");
  assert.match(guideSrc, /getPackageBuilderTutorialKey/);
  assert.match(builderSrc, /getPackageBuilderTutorialKey/);
});

test("http.ts validationMessageAliases includes Indonesian fallbacks for audit keys", async () => {
  const httpSrc = await readSource("./http.ts");
  for (const key of [
    "validation.min.string",
    "validation.min.numeric",
    "validation.max.string",
    "validation.after_or_equal",
    "validation.in",
  ]) {
    assert.ok(httpSrc.includes(`"${key}"`), `http.ts must alias ${key}`);
  }
});
