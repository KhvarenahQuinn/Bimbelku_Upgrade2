import fs from "node:fs";
import path from "node:path";

const read = (file) => fs.readFileSync(path.resolve(file), "utf8");
const expect = (condition, message) => {
  if (!condition) throw new Error(`Auth guards gagal: ${message}`);
  console.log(`PASS ${message}`);
};

const login = read("src/pages/Login.tsx");
const adminLogin = read("src/pages/AdminLogin.tsx");
const register = read("src/pages/Register.tsx");
const forgotPassword = read("src/pages/ForgotPassword.tsx");
const validation = read("src/lib/validation.ts");
const googleController = read("bimbelku-backend/app/Http/Controllers/Api/GoogleAuthController.php");

// C1 — submit ganda dijaga ref sinkron, bukan hanya state React.
expect(
  login.includes("loadingRef") && login.includes("if (loadingRef.current) return;"),
  "Login memakai ref sinkron untuk menahan submit ganda",
);
expect(
  adminLogin.includes("loadingRef") && adminLogin.includes("if (loadingRef.current) return;"),
  "AdminLogin memakai ref sinkron untuk menahan submit ganda",
);
expect(
  register.includes("submittingRef") && register.includes("if (submittingRef.current) return;"),
  "Register memakai ref sinkron untuk menahan submit ganda",
);
expect(
  /finally\s*\{[\s\S]{0,200}loadingRef\.current\s*=\s*false/.test(login),
  "Login melepas guard di blok finally",
);
expect(
  /finally\s*\{[\s\S]{0,200}submittingRef\.current\s*=\s*false/.test(register),
  "Register melepas guard di blok finally",
);

// C2 — satu validator email bersama dipakai semua form auth.
expect(
  validation.includes("export function isValidEmail"),
  "helper isValidEmail tunggal tersedia di src/lib/validation.ts",
);
expect(
  login.includes("isValidEmail(formData.email)") && login.includes('from "@/lib/validation"'),
  "Login memakai isValidEmail bersama sebelum submit",
);
expect(
  adminLogin.includes("isValidEmail(form.email)") && adminLogin.includes('from "@/lib/validation"'),
  "AdminLogin memakai isValidEmail bersama sebelum submit",
);
expect(
  register.includes("isValidEmail(form.email)") && register.includes('from "@/lib/validation"'),
  "Register memakai isValidEmail bersama sebelum submit",
);
expect(
  forgotPassword.includes("isValidEmail(email)") && forgotPassword.includes('from "@/lib/validation"'),
  "ForgotPassword memakai isValidEmail bersama sebelum submit",
);
expect(
  !/\/\^\\S\+@\\S\+/.test(register),
  "Register tidak lagi memakai regex email ad-hoc",
);

// C5 — redirect pasca-login hanya boleh ke path internal.
expect(
  login.includes('requestedRedirect?.startsWith("/") && !requestedRedirect.startsWith("//")'),
  "Login menolak URL eksternal dan protocol-relative untuk redirect",
);
expect(
  googleController.includes("str_starts_with($value, '//')"),
  "safeRedirect backend menolak protocol-relative redirect",
);
expect(
  googleController.includes("str_starts_with($path, '/student/')")
    && googleController.includes("str_starts_with($path, '/guru/')"),
  "safeRedirect backend mempertahankan target role yang sah",
);

console.log("Auth guards C1/C2/C5 lulus pemeriksaan source-level.");
