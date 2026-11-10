<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Validation\ValidationException;

class PrivateParticipantPricing
{
    public const KEY = 'private_participant_pricing_v1';

    public const MAXIMUM_KEY = 'private_participant_maximum_v1';

    public const DEFAULT_MAXIMUM = 8;

    public const HARD_MAXIMUM = 100;

    public function maximumParticipants(): int
    {
        $stored = (int) Setting::query()->where('key', self::MAXIMUM_KEY)->value('value');

        return min(self::HARD_MAXIMUM, max(2, $stored ?: self::DEFAULT_MAXIMUM));
    }

    public function tiers(): array
    {
        $decoded = json_decode((string) Setting::query()->where('key', self::KEY)->value('value'), true);

        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)->map(fn ($tier) => [
            'discount_percent' => is_array($tier) && is_numeric($tier['discount_percent'] ?? null)
                ? (float) $tier['discount_percent']
                : null,
        ])->all();
    }

    public function isReady(): bool
    {
        $tiers = $this->tiers();
        foreach (range(2, $this->maximumParticipants()) as $count) {
            if (! is_numeric($tiers[(string) $count]['discount_percent'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    public function forCount(int $count): array
    {
        if ($count === 1) {
            return ['discount_percent' => 0.0];
        }

        $maximum = $this->maximumParticipants();
        if ($count < 2 || $count > $maximum || ! $this->isReady()) {
            throw ValidationException::withMessages([
                'participant_count' => "Pemesanan privat lebih dari satu peserta belum tersedia. Admin perlu melengkapi diskon untuk 2–{$maximum} peserta.",
            ]);
        }

        return $this->tiers()[(string) $count];
    }

    public function customerHourlyTotal(float $baseHourlyRate, int $count): int
    {
        $tier = $this->forCount($count);

        return (int) round($baseHourlyRate * $count * (100 - $tier['discount_percent']) / 100);
    }

    /** Nilai bruto transaksi per jam; hak tutor dihitung 80% saat booking dibuat. */
    public function tutorHourlyGross(float $baseHourlyRate, int $count): int
    {
        return $this->customerHourlyTotal($baseHourlyRate, $count);
    }
}
