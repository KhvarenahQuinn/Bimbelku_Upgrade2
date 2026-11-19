<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicMediaController extends Controller
{
    public function __invoke(string $path): BinaryFileResponse
    {
        $path = ltrim(str_replace('\\', '/', rawurldecode($path)), '/');
        abort_if(
            $path === ''
                || str_contains($path, '..')
                || preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $path) !== 1,
            404
        );

        $extension = mb_strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        abort_unless(in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'ico'], true), 404);

        $disk = Storage::disk('public');
        abort_unless($disk->exists($path), 404, 'File gambar tidak ditemukan.');

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($disk->path($path)) ?: 'application/octet-stream';
        abort_unless(in_array($mime, [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
            'image/x-icon',
            'image/vnd.microsoft.icon',
        ], true), 404);

        return response()->file($disk->path($path), [
            'Content-Type' => $mime,
            // Nama file upload selalu unik dan tidak pernah ditimpa, sehingga
            // browser aman menyimpan media ini dalam cache jangka panjang.
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]);
    }
}
