const MAX_EXCEPTION_RANGE_DAYS = 180;
const DATE_ONLY_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

function parseDateOnly(value: string): Date | null {
  if (!DATE_ONLY_PATTERN.test(value)) return null;
  const date = new Date(`${value}T00:00:00.000Z`);
  return Number.isNaN(date.getTime()) || date.toISOString().slice(0, 10) !== value ? null : date;
}

export function maxExceptionEndDate(startDate: string): string {
  const date = parseDateOnly(startDate);
  if (!date) return "";
  date.setUTCDate(date.getUTCDate() + MAX_EXCEPTION_RANGE_DAYS - 1);
  return date.toISOString().slice(0, 10);
}

export function exceptionDateRangeError(startDate: string, endDate: string): string {
  if (!startDate || !endDate) return "";
  const start = parseDateOnly(startDate);
  const end = parseDateOnly(endDate);
  if (!start || !end) return "Pilih tanggal mulai dan selesai yang valid.";
  if (end < start) return "Tanggal selesai tidak boleh sebelum tanggal mulai.";

  const days = (end.getTime() - start.getTime()) / 86_400_000 + 1;
  return days > MAX_EXCEPTION_RANGE_DAYS
    ? `Rentang tanggal tidak tersedia maksimal ${MAX_EXCEPTION_RANGE_DAYS} hari.`
    : "";
}
