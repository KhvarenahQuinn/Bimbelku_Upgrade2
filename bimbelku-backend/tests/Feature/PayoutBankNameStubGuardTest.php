<?php

namespace Tests\Feature;

use App\Services\BankAccountNameVerifier;
use RuntimeException;
use Tests\TestCase;

class PayoutBankNameStubGuardTest extends TestCase
{
    public function test_stub_is_unavailable_when_feature_flag_is_off(): void
    {
        config(['features.payout_bank_name_stub' => false]);

        $verifier = app(BankAccountNameVerifier::class);

        $this->assertFalse($verifier->available());
        $this->assertFalse($verifier->matches('BCA', '1234567890', 'Rina Kartika'));
    }

    public function test_stub_is_available_in_testing_when_flag_is_on(): void
    {
        config(['features.payout_bank_name_stub' => true]);

        $verifier = app(BankAccountNameVerifier::class);

        $this->assertTrue($verifier->available());
        $this->assertTrue($verifier->matches('BCA', '0001234567', 'Rina Kartika'));
    }

    public function test_stub_requires_an_account_number_and_name(): void
    {
        config(['features.payout_bank_name_stub' => true]);
        $verifier = app(BankAccountNameVerifier::class);

        $this->assertFalse($verifier->matches('BCA', '', 'Rina Kartika'));
        $this->assertFalse($verifier->matches('BCA', '---', 'Rina Kartika'));
        $this->assertFalse($verifier->matches('BCA', '0001234567', '   '));
    }

    public function test_stub_fails_closed_outside_local_and_testing(): void
    {
        config(['features.payout_bank_name_stub' => true]);
        app()->detectEnvironment(fn () => 'staging');

        try {
            $verifier = app(BankAccountNameVerifier::class);
            $this->assertFalse($verifier->available());
            $this->assertFalse($verifier->matches('BCA', '0001234567', 'Rina Kartika'));
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_production_boot_guard_rejects_enabled_stub(): void
    {
        config(['features.payout_bank_name_stub' => true]);
        app()->detectEnvironment(fn () => 'production');

        try {
            $this->expectException(RuntimeException::class);
            app(BankAccountNameVerifier::class)->assertNotEnabledInProduction();
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }
}
