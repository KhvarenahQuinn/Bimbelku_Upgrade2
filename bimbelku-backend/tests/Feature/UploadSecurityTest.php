<?php

namespace Tests\Feature;

use App\Services\UploadSecurityService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UploadSecurityTest extends TestCase
{
    public function test_clean_jpeg_is_reencoded_and_stored_with_random_name(): void
    {
        Storage::fake('local');
        $service = app(UploadSecurityService::class);
        $file = UploadedFile::fake()->image('avatar.jpg', 8, 8);

        $path = $service->store($file, 'avatars');

        $this->assertMatchesRegularExpression('#^avatars/[0-9a-f-]{36}\.jpg$#', $path);
        Storage::disk('local')->assertExists($path);
        $this->assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->buffer(Storage::disk('local')->get($path)));
    }

    public function test_jpeg_with_embedded_php_is_rejected_and_logged(): void
    {
        Storage::fake('local');
        Log::shouldReceive('channel')->with('security')->andReturnSelf();
        Log::shouldReceive('warning')->once()->with('Malicious upload rejected.', \Mockery::on(
            fn (array $context): bool => $context['reason'] === 'invalid_or_polyglot_image'
        ));

        $path = tempnam(sys_get_temp_dir(), 'polyglot-jpeg');
        $image = imagecreatetruecolor(8, 8);
        imagejpeg($image, $path);
        imagedestroy($image);
        file_put_contents($path, '<?php echo 1; ?>', FILE_APPEND);

        try {
            $file = new UploadedFile($path, 'payload.jpg', 'image/jpeg', null, true);
            try {
                app(UploadSecurityService::class)->store($file, 'uploads');
                $this->fail('Polyglot JPEG should be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('file', $exception->errors());
            }
        } finally {
            @unlink($path);
        }
    }

    public function test_pdf_with_active_javascript_action_is_rejected_and_logged(): void
    {
        Storage::fake('local');
        Log::shouldReceive('channel')->with('security')->andReturnSelf();
        Log::shouldReceive('warning')->once()->with('Malicious upload rejected.', \Mockery::on(
            fn (array $context): bool => $context['reason'] === 'invalid_or_active_pdf'
        ));

        $file = UploadedFile::fake()->createWithContent(
            'active.pdf',
            "%PDF-1.7\n1 0 obj << /Type /Catalog /OpenAction << /S /JavaScript >> >>\n%%EOF"
        );
        try {
            app(UploadSecurityService::class)->store($file, 'uploads');
            $this->fail('Active PDF should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }
    }

    public function test_clean_pdf_is_accepted(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->createWithContent('document.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >>\n%%EOF");

        $path = app(UploadSecurityService::class)->store($file, 'documents');

        Storage::disk('local')->assertExists($path);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($path));
    }
}
