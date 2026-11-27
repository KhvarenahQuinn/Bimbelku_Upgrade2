<?php

namespace Tests\Feature;

use App\Models\Promotion;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class WaveOneExceptionHygieneTest extends TestCase
{
    use RefreshDatabase;

    private function assertNoDebugLeak($response): void
    {
        $response
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('file')
            ->assertJsonMissingPath('line')
            ->assertJsonMissingPath('trace');
    }

    public function test_api_http_exceptions_and_validation_never_serialize_debug_traces_even_when_debug_enabled(): void
    {
        config(['app.debug' => true]);

        // 401 unauthenticated
        $unauthenticated = $this->getJson('/api/user')->assertUnauthorized();
        $this->assertNoDebugLeak($unauthenticated);

        // 403 forbidden role / controller abort
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Sanctum::actingAs($student);
        $forbidden = $this->getJson('/api/admin/classes')->assertForbidden();
        $this->assertNoDebugLeak($forbidden);

        // 404 model not found (guest order lookup) & unknown route
        $notFoundModel = $this->getJson('/api/guest/packages/INVALID-CODE-999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Data tidak ditemukan.']);
        $this->assertNoDebugLeak($notFoundModel);
        $this->assertStringNotContainsString('GuestPackageOrder', $notFoundModel->getContent());
        $this->assertStringNotContainsString('No query results', $notFoundModel->getContent());

        // Wrapped ModelNotFoundException for Promotion preserves specific Indonesian 404 copy
        Route::middleware('api')->get('/api/_wave2-promotion-missing', function () {
            $modelException = (new ModelNotFoundException)->setModel(Promotion::class, [999]);
            throw new NotFoundHttpException('No query results for model [App\\Models\\Promotion].', $modelException);
        });
        $notFoundPromotion = $this->getJson('/api/_wave2-promotion-missing')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Promo tidak ditemukan atau sudah dihapus.']);
        $this->assertNoDebugLeak($notFoundPromotion);

        // Custom HttpException 404 preserves explicit Indonesian copy
        Route::middleware('api')->get('/api/_wave2-custom-404', fn () => abort(404, 'Halaman khusus tidak ditemukan.'));
        $custom404 = $this->getJson('/api/_wave2-custom-404')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Halaman khusus tidak ditemukan.']);
        $this->assertNoDebugLeak($custom404);

        $notFoundRoute = $this->getJson('/api/does-not-exist-wave1')->assertNotFound();
        $this->assertNoDebugLeak($notFoundRoute);

        // 422 validation error preserves errors bag without debug keys
        $validationFailed = $this->postJson('/api/login', [])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors']);
        $this->assertNoDebugLeak($validationFailed);

        // 422 HttpException from abort(422, ...)
        Route::middleware('api')->get('/api/_wave1-abort-422', fn () => abort(422, 'Input tidak valid.'));
        $http422 = $this->getJson('/api/_wave1-abort-422')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Input tidak valid.']);
        $this->assertNoDebugLeak($http422);
    }

    public function test_unhandled_server_exceptions_on_api_never_leak_stack_traces_when_debug_enabled(): void
    {
        config(['app.debug' => true]);

        Route::middleware('api')->get('/api/_wave1-boom-500', function () {
            throw new \RuntimeException('Sensitive SQL or internal detail should stay server-side');
        });

        $response = $this->getJson('/api/_wave1-boom-500')
            ->assertStatus(500)
            ->assertExactJson(['message' => 'Terjadi kesalahan pada server.']);

        $this->assertNoDebugLeak($response);
        $this->assertStringNotContainsString('Sensitive SQL', $response->getContent());
    }
}
