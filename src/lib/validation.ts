export interface UploadRule {
  label: string;
  maxSizeMb: number;
  extensions: string[];
}

const phonePattern = /^\+?[0-9]{8,15}$/;
const personNamePattern = /^[\p{L}\p{M}.'’\-\s]+$/u;
const regionPattern = /^[\p{L}\p{M}0-9\s.,'’()\-/]+$/u;

export function validateUpload(file: File | null | undefined, rule: UploadRule): string | null {
  if (!file) return null;

  const extension = file.name.includes(".")
    ? file.name.split(".").pop()?.toLowerCase() || ""
    : "";

  if (!rule.extensions.includes(extension)) {
    return `${rule.label} harus berformat ${rule.extensions.map((item) => item.toUpperCase()).join(", ")}.`;
  }

  const maximumBytes = rule.maxSizeMb * 1024 * 1024;
  if (file.size > maximumBytes) {
    return `${rule.label} maksimal ${rule.maxSizeMb} MB.`;
  }

  return null;
}

export function sanitizePhoneInput(value: string): string {
  const trimmed = value.trimStart();
  const leadingPlus = trimmed.startsWith("+");
  const digits = value.replace(/\D/g, "").slice(0, 15);
  return `${leadingPlus ? "+" : ""}${digits}`;
}

export function sanitizeDigits(value: string, maxLength = 50): string {
  return value.replace(/\D/g, "").slice(0, maxLength);
}

export function sanitizePersonName(value: string, maxLength = 255): string {
  return value
    .replace(/[^\p{L}\p{M}.'’\-\s]/gu, "")
    .replace(/\s{2,}/g, " ")
    .slice(0, maxLength);
}

export function containsLetter(value: string): boolean {
  return /\p{L}/u.test(value);
}

export function isValidPhone(value: string): boolean {
  const normalized = value.trim();
  return normalized === sanitizePhoneInput(normalized) && phonePattern.test(normalized);
}

export function isValidPersonName(value: string): boolean {
  const normalized = value.trim();
  return normalized.length >= 2
    && normalized.length <= 255
    && personNamePattern.test(normalized)
    && containsLetter(normalized);
}

export function isValidRegionName(value: string): boolean {
  const normalized = value.trim();
  if (!normalized) return true;
  return normalized.length <= 255
    && containsLetter(normalized)
    && regionPattern.test(normalized);
}

export function isValidAccountNumber(value: string): boolean {
  return /^[0-9]{6,50}$/.test(value.trim());
}

export function isValidHttpUrl(value: string): boolean {
  if (!value.trim()) return true;

  try {
    const url = new URL(value);
    return url.protocol === "http:" || url.protocol === "https:";
  } catch {
    return false;
  }
}

/**
 * Pemeriksaan email praktis yang selaras dengan aturan backend (`email` rule
 * Laravel): wajib ada nama lokal, satu `@`, domain bertitik, tanpa spasi,
 * tanpa dua titik berurutan, dan label domain tidak boleh diawali/diakhiri
 * tanda hubung. Tanda `+` pada nama lokal tetap diperbolehkan.
 */
const emailPattern = /^[A-Za-z0-9!#$%&'*+/=?^_`{|}~-]+(?:\.[A-Za-z0-9!#$%&'*+/=?^_`{|}~-]+)*@(?:[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\.)+[A-Za-z]{2,}$/;
const consumerEmailDomains = new Set([
  "gmail.com", "googlemail.com", "yahoo.com", "yahoo.co.id", "ymail.com",
  "outlook.com", "hotmail.com", "live.com", "msn.com", "icloud.com",
  "me.com", "mac.com", "proton.me", "protonmail.com", "zoho.com", "aol.com",
]);
export const registrationEmailGuidance = "Gunakan alamat email dari provider terpercaya (Gmail, Yahoo, Outlook, iCloud) atau email institusi pendidikan (.ac.id, .sch.id, belajar.id).";

export function isTrustedRegistrationEmailDomain(domain: string): boolean {
  const normalized = domain.toLowerCase().replace(/\\.$/, "");
  return consumerEmailDomains.has(normalized)
    || normalized === "belajar.id"
    || [".ac.id", ".sch.id", ".edu", ".belajar.id"].some((suffix) => normalized.endsWith(suffix));
}

export function isValidRegistrationEmail(value: string): boolean {
  const normalized = value.trim();
  if (!isValidEmail(normalized)) return false;
  return isTrustedRegistrationEmailDomain(normalized.slice(normalized.lastIndexOf("@") + 1));
}

export function isValidEmail(value: string): boolean {
  const normalized = value.trim();
  if (!normalized || normalized.length > 255) return false;
  if (normalized.includes("..")) return false;
  if (!normalized.includes(".")) return false;
  return emailPattern.test(normalized);
}
