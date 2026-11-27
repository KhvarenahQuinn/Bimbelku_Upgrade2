export const PACKAGE_BUILDER_TUTORIAL_KEY = "bimbelku.tutorial.student.multi-subject.v1";

export function getTutorialAccountKey(): string {
  try {
    const storedUser = JSON.parse(localStorage.getItem("user") || "null") as {
      id?: number | string;
      email?: string;
    } | null;
    const identity = storedUser?.id ?? storedUser?.email;
    return identity !== undefined && identity !== null && String(identity).trim()
      ? String(identity).trim()
      : "anonymous";
  } catch {
    return "anonymous";
  }
}

export function getPackageBuilderTutorialKey(accountKey = getTutorialAccountKey()): string {
  return `${PACKAGE_BUILDER_TUTORIAL_KEY}.${accountKey}.easy-v2`;
}
