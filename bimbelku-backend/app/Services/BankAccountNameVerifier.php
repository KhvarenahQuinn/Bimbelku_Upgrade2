<?php

namespace App\Services;

use RuntimeException;

/**
 * Bank-owner lookup must come from the banking network, not user-entered text.
 * Xendit Data Services Name Validator is not activated for this installation.
 *
 * For local/testing only, `PAYOUT_BANK_NAME_VERIFICATION_STUB` lets the payout
 * flow be exercised against the Xendit sandbox without the real lookup service.
 * This is an explicit stub, not a bank verification, and it must never be
 * enabled in production.
 */
class BankAccountNameVerifier
{
    public function available(): bool
    {
        return $this->stubEnabled();
    }

    public function matches(string $channelCode, string $accountNumber, string $expectedName): bool
    {
        if (! $this->stubEnabled()) {
            // Fail closed until a provider-backed lookup is implemented and activated.
            return false;
        }

        $digits = preg_replace('/\D+/', '', $accountNumber) ?? '';

        return $digits !== '' && trim($expectedName) !== '';
    }

    private function stubEnabled(): bool
    {
        if (! (bool) config('features.payout_bank_name_stub', false)) {
            return false;
        }

        return app()->environment(['local', 'testing']);
    }

    public function assertNotEnabledInProduction(): void
    {
        if (app()->environment('production') && (bool) config('features.payout_bank_name_stub', false)) {
            throw new RuntimeException('PAYOUT_BANK_NAME_VERIFICATION_STUB tidak boleh aktif di produksi.');
        }
    }
}
