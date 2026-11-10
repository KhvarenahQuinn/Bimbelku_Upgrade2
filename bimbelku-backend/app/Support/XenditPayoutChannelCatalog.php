<?php

namespace App\Support;

final class XenditPayoutChannelCatalog
{
    /** @var array<string, array{label:string,routing_type:string,routing_value:string}> */
    private const CHANNELS = [
        'BCA' => ['label' => 'Bank Central Asia (BCA)', 'routing_type' => 'SWIFT', 'routing_value' => 'CENAIDJA'],
        'MANDIRI' => ['label' => 'Bank Mandiri', 'routing_type' => 'SWIFT', 'routing_value' => 'BMRIIDJA'],
        'BRI' => ['label' => 'Bank Rakyat Indonesia (BRI)', 'routing_type' => 'SWIFT', 'routing_value' => 'BRINIDJA'],
        'BNI' => ['label' => 'Bank Negara Indonesia (BNI)', 'routing_type' => 'SWIFT', 'routing_value' => 'BNINIDJA'],
        'CIMB' => ['label' => 'CIMB Niaga', 'routing_type' => 'SWIFT', 'routing_value' => 'BNIAIDJA'],
        'PERMATA' => ['label' => 'PermataBank', 'routing_type' => 'SWIFT', 'routing_value' => 'BBBAIDJA'],
        'BSI' => ['label' => 'Bank Syariah Indonesia (BSI)', 'routing_type' => 'SWIFT', 'routing_value' => 'BSMDIDJA'],
    ];

    public static function codes(): array
    {
        return array_keys(self::CHANNELS);
    }

    public static function get(string $code): ?array
    {
        return self::CHANNELS[strtoupper(trim($code))] ?? null;
    }

    public static function options(): array
    {
        return collect(self::CHANNELS)->map(fn (array $item, string $code) => [
            'code' => $code,
            'label' => $item['label'],
        ])->values()->all();
    }

    public static function inferCode(?string $bankName): ?string
    {
        $normalized = strtoupper((string) preg_replace('/[^A-Z0-9]+/i', '', $bankName ?? ''));
        if ($normalized === '') {
            return null;
        }
        foreach (self::CHANNELS as $code => $item) {
            $label = strtoupper((string) preg_replace('/[^A-Z0-9]+/i', '', $item['label']));
            if ($normalized === $code || str_contains($normalized, $code) || str_contains($label, $normalized)) {
                return $code;
            }
        }

        return null;
    }
}
