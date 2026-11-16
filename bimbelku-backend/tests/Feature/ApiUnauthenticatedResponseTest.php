<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Endpoint API harus selalu menjawab JSON, termasuk saat tamu tidak mengirim
 * header `Accept`. Tanpa penjagaan ini middleware `auth` mencoba
 * `route('login')` — yang tidak ada di aplikasi ini — dan melempar
 * RouteNotFoundException sehingga tamu menerima 500, bukan 401.
 */
class ApiUnauthenticatedResponseTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function protectedEndpoints(): array
    {
        return [
            'conversations' => ['/api/conversations'],
            'user' => ['/api/user'],
            'notifications' => ['/api/notifications'],
            'pending guest packages' => ['/api/student/guest-packages/pending'],
        ];
    }

    #[DataProvider('protectedEndpoints')]
    public function test_guest_without_accept_header_gets_json_401(string $path): void
    {
        $response = $this->call('GET', $path, [], [], [], [
            'HTTP_ACCEPT' => '',
        ]);

        $this->assertSame(
            401,
            $response->getStatusCode(),
            "GET {$path} tanpa header Accept harus 401, bukan 500/redirect."
        );
        $this->assertStringContainsString(
            'application/json',
            (string) $response->headers->get('Content-Type'),
            "GET {$path} harus menjawab JSON."
        );
        $this->assertSame('Unauthenticated.', $response->json('message'));
    }

    #[DataProvider('protectedEndpoints')]
    public function test_guest_with_json_accept_header_still_gets_json_401(string $path): void
    {
        $response = $this->getJson($path);

        $response->assertStatus(401)->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_guest_never_gets_redirected_to_a_login_route(): void
    {
        $response = $this->call('GET', '/api/conversations', [], [], [], [
            'HTTP_ACCEPT' => 'text/html,application/xhtml+xml',
        ]);

        // Redirect berarti aplikasi mencoba route('login'); 500 berarti route
        // itu tidak ada. Keduanya salah untuk endpoint API.
        $this->assertFalse($response->isRedirection(), 'API tidak boleh me-redirect tamu.');
        $this->assertNotSame(500, $response->getStatusCode(), 'API tidak boleh 500 untuk tamu.');
        $this->assertSame(401, $response->getStatusCode());
    }
}
