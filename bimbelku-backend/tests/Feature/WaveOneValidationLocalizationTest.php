<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WaveOneValidationLocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('id');
        config(['app.locale' => 'id', 'app.fallback_locale' => 'id']);
    }

    public function test_google_exchange_and_admin_filters_return_indonesian_validation_messages(): void
    {
        $oauthResponse = $this->postJson('/api/auth/google/exchange', [
            'code' => 'short',
        ])->assertStatus(422);

        $oauthMsg = (string) $oauthResponse->json('errors.code.0');
        $this->assertNotSame('validation.min.string', $oauthMsg);
        $this->assertStringContainsString('karakter', $oauthMsg);

        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        Sanctum::actingAs($admin);

        foreach ([
            '/api/admin/finance/report?date_from=2026-11-25&date_to=2026-11-01',
            '/api/admin/audit-log?date_from=2026-11-25&date_to=2026-11-01',
            '/api/admin/activity-logs?date_from=2026-11-25&date_to=2026-11-01',
        ] as $url) {
            $res = $this->getJson($url)->assertStatus(422);
            $dateMsg = (string) $res->json('errors.date_to.0');
            $this->assertNotSame('validation.after_or_equal', $dateMsg);
            $this->assertStringContainsString('setelah', $dateMsg);
        }

        $numericAndIn = $this->getJson('/api/admin/finance/report?min_amount=-5&per_page=7')
            ->assertStatus(422);
        $minMsg = (string) $numericAndIn->json('errors.min_amount.0');
        $inMsg = (string) $numericAndIn->json('errors.per_page.0');
        $this->assertNotSame('validation.min.numeric', $minMsg);
        $this->assertNotSame('validation.in', $inMsg);
        $this->assertStringContainsString('minimal', $minMsg);
        $this->assertStringContainsString('tidak valid', $inMsg);
    }
}
