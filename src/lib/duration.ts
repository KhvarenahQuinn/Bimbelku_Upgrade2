/**
 * Format durasi pencarian tutor agar ramah dibaca.
 *
 * API mengirim `search_age_seconds` (akurat) dan `search_age_minutes`
 * (kompatibilitas). Nilai menit sering berupa pecahan (mis. 3.45678) sehingga
 * harus dibulatkan sebelum ditampilkan — jangan pernah mencetak angka mentah.
 */
export function formatSearchAge(seconds?: number | null, minutes?: number | null): string {
  const rawSeconds = Number(seconds);
  const rawMinutes = Number(minutes);

  const totalSeconds = Number.isFinite(rawSeconds)
    ? Math.max(0, Math.floor(rawSeconds))
    : Number.isFinite(rawMinutes)
      ? Math.max(0, Math.round(rawMinutes * 60))
      : 0;

  if (totalSeconds < 60) return `${totalSeconds} detik`;

  const wholeMinutes = Math.floor(totalSeconds / 60);
  if (wholeMinutes < 60) return `${wholeMinutes} menit`;

  const hours = Math.floor(wholeMinutes / 60);
  const remainingMinutes = wholeMinutes % 60;
  if (hours < 24) return remainingMinutes ? `${hours} jam ${remainingMinutes} menit` : `${hours} jam`;

  const days = Math.floor(hours / 24);
  return `${days} hari ${hours % 24} jam`;
}
