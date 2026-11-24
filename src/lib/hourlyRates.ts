export const MIN_HOURLY_RATE = 1_000;
export const MAX_HOURLY_RATE = 10_000_000;

export function hourlyRateAmountError(amount: number): string {
  if (amount > MAX_HOURLY_RATE) return "Maksimal Rp10.000.000 per jam.";
  if (amount < MIN_HOURLY_RATE) return "Minimal Rp1.000 per jam.";
  return "";
}
