import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import vm from "node:vm";
import ts from "typescript";

async function readSource(relativePath) {
  return readFile(new URL(relativePath, import.meta.url), "utf8");
}

async function loadTypeScriptModule(relativePath) {
  const source = await readSource(relativePath);
  const { outputText } = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  });
  const module = { exports: {} };
  vm.runInNewContext(outputText, { module, exports: module.exports });
  return module.exports;
}

test("TeacherProfile names each mode switch from its visible title and describes its purpose", async () => {
  const source = await readSource("../pages/teacher/TeacherProfile.tsx");
  assert.match(source, /const titleId = `\$\{id\}-title`/);
  assert.match(source, /const descriptionId = `\$\{id\}-description`/);
  assert.match(source, /<p id=\{titleId\}[^>]*>\{title\}<\/p>/);
  assert.match(source, /<p id=\{descriptionId\}[^>]*>\{description\}<\/p>/);
  assert.match(source, /<Switch checked=\{checked\} onCheckedChange=\{onChange\} aria-labelledby=\{titleId\} aria-describedby=\{descriptionId\}/);
  for (const title of ["Menerima Paket Privat", "Menerima Kelas Bersama", "Mengajar online", "Mengajar offline"]) {
    assert.ok(source.includes(`title="${title}"`), `missing mode label: ${title}`);
  }
});

test("schedule exception date ranges use the 180-day maximum, including leap years", async () => {
  const { exceptionDateRangeError, maxExceptionEndDate } = await loadTypeScriptModule("./teacherScheduleExceptions.ts");
  assert.equal(maxExceptionEndDate("2024-01-01"), "2024-06-28");
  assert.equal(exceptionDateRangeError("2024-01-01", "2024-06-28"), "");
  assert.equal(exceptionDateRangeError("2024-01-01", "2024-06-29"), "Rentang tanggal tidak tersedia maksimal 180 hari.");
  assert.equal(exceptionDateRangeError("2024-04-01", "2024-03-31"), "Tanggal selesai tidak boleh sebelum tanggal mulai.");
  assert.equal(maxExceptionEndDate("2024-02-30"), "");

  const source = await readSource("../pages/teacher/ManageSchedule.tsx");
  assert.match(source, /max=\{maxExceptionEndDate\(exceptionForm\.start_date\)\}/);
  assert.match(source, /aria-describedby=\{exceptionDateRangeError\(exceptionForm\.start_date, exceptionForm\.end_date\) \? "exception-date-range-error" : undefined\}/);
  assert.match(source, /role="alert"[^>]*>\{exceptionDateRangeError/);
  assert.match(source, /if \(exceptionDateRangeError\(exceptionForm\.start_date, exceptionForm\.end_date\)\) return/);
  assert.match(source, /disabled=\{savingException \|\| Boolean\(exceptionDateRangeError/);
});

test("TeacherLayout exposes mobile drawer state and reserves bottom navigation space once", async () => {
  const source = await readSource("../components/TeacherLayout.tsx");
  assert.match(source, /<aside id="teacher-mobile-navigation"/);
  assert.match(source, /aria-label=\{sidebarOpen \? "Tutup menu" : "Buka menu"\}/);
  assert.match(source, /aria-expanded=\{sidebarOpen\} aria-controls="teacher-mobile-navigation"/);
  assert.match(source, /onClick=\{\(\) => setSidebarOpen\(\(open\) => !open\)\}/);
  assert.match(source, /pb-\[calc\(5rem\+env\(safe-area-inset-bottom\)\)\]/);
  assert.match(source, /className=\{`mx-auto w-full min-w-0 max-w-7xl \$\{lockContentScroll \? "flex h-full min-h-0 flex-col" : "pb-0"\}`\}/);
  assert.doesNotMatch(source, /"pb-20 sm:pb-24 xl:pb-16"/);
});
