<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeacherAvailability extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'slots' => 'array',
        'is_active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function isFullHour(?string $time): bool
    {
        return is_string($time)
            && preg_match('/^(?:[01]\\d|2[0-3]):00(?::00)?$/', $time) === 1;
    }

    /**
     * @return array<int, array{start_time:string,end_time:string}>
     */
    public function normalizedRanges(): array
    {
        $ranges = collect($this->slots ?? [])
            ->map(function ($slot) {
                if (is_array($slot)) {
                    $start = $slot['start_time'] ?? $slot['start'] ?? null;
                    $end = $slot['end_time'] ?? $slot['end'] ?? null;
                } elseif (is_string($slot) && str_contains($slot, '-')) {
                    [$start, $end] = array_map('trim', explode('-', $slot, 2));
                } elseif (is_string($slot) && self::isFullHour($slot)) {
                    $start = substr($slot, 0, 5);
                    $hour = (int) substr($start, 0, 2);
                    $end = $hour < 23 ? sprintf('%02d:00', $hour + 1) : null;
                } else {
                    return null;
                }

                $start = is_string($start) ? substr($start, 0, 5) : null;
                $end = is_string($end) ? substr($end, 0, 5) : null;

                if (! self::isFullHour($start) || ! self::isFullHour($end) || $end <= $start) {
                    return null;
                }

                return ['start_time' => $start, 'end_time' => $end];
            })
            ->filter()
            ->sortBy('start_time')
            ->values();

        if ($ranges->isNotEmpty()) {
            return $ranges->all();
        }

        $start = $this->getRawOriginal('start_time');
        $end = $this->getRawOriginal('end_time');
        $start = is_string($start) ? substr($start, 0, 5) : null;
        $end = is_string($end) ? substr($end, 0, 5) : null;

        if (self::isFullHour($start) && self::isFullHour($end) && $end > $start) {
            return [['start_time' => $start, 'end_time' => $end]];
        }

        return [];
    }

    public function covers(string $startTime, string $endTime): bool
    {
        $startTime = substr($startTime, 0, 5);
        $endTime = substr($endTime, 0, 5);

        if (! self::isFullHour($startTime) || ! self::isFullHour($endTime) || $endTime <= $startTime) {
            return false;
        }

        return collect($this->normalizedRanges())->contains(
            fn (array $range) => $range['start_time'] <= $startTime && $range['end_time'] >= $endTime
        );
    }
}
