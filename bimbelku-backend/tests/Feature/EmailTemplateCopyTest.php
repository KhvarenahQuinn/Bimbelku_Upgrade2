<?php

namespace Tests\Feature;

use Tests\TestCase;

class EmailTemplateCopyTest extends TestCase
{
    public function test_each_otp_purpose_renders_its_own_copy(): void
    {
        $cases = [
            'email_verification' => ['memverifikasi email', null],
            'payment_pin_reset' => ['mengatur ulang PIN pembayaran', null],
            'payout_bank_change' => ['mengonfirmasi perubahan rekening pencairan', 'mengatur ulang PIN'],
        ];

        foreach ($cases as $purpose => [$expected, $forbidden]) {
            foreach (['emails.verification_code', 'emails.verification_code_text'] as $view) {
                $output = view($view, [
                    'name' => 'Rina Kartika S.Pd.',
                    'code' => '383893',
                    'purpose' => $purpose,
                    'expiresMinutes' => 10,
                ])->render();

                $this->assertStringContainsString($expected, $output, "{$view} ({$purpose})");
                if ($forbidden !== null) {
                    $this->assertStringNotContainsString($forbidden, $output, "{$view} ({$purpose})");
                }
            }
        }
    }

    public function test_unknown_purpose_does_not_claim_to_reset_a_pin(): void
    {
        $output = view('emails.verification_code', [
            'name' => 'Rina',
            'code' => '123456',
            'purpose' => 'something_else',
            'expiresMinutes' => 10,
        ])->render();

        $this->assertStringContainsString('melanjutkan permintaan', $output);
        $this->assertStringNotContainsString('PIN', $output);
    }

    public function test_reset_templates_greet_by_name(): void
    {
        foreach (['emails.reset_password', 'emails.reset_payment_pin', 'emails.reset_password_text', 'emails.reset_payment_pin_text'] as $view) {
            $this->assertStringContainsString(
                'Rina',
                view($view, ['url' => 'http://127.0.0.1:8080/reset', 'name' => 'Rina'])->render(),
                $view
            );
        }
    }

    public function test_reset_templates_fall_back_to_a_neutral_greeting(): void
    {
        $this->assertStringContainsString(
            'Halo!',
            view('emails.reset_password', ['url' => 'http://127.0.0.1:8080/reset', 'name' => null])->render()
        );
    }
}
