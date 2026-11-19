<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UploadSecurityService
{
    private const IMAGE_MIMES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public function store(UploadedFile $file, string $directory, string $disk = 'local', ?string $namePrefix = null): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $bytes = @file_get_contents($file->getRealPath());
        if (! is_string($bytes) || $bytes === '') {
            $this->reject('empty_or_unreadable', $extension);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (str_contains($extension, 'svg') || in_array($mime, ['image/svg+xml', 'text/html', 'application/xhtml+xml'], true)
            || preg_match('/^\s*(?:<\?php|<\?=|<!doctype\s+html|<html|<script)/i', $bytes) === 1) {
            $this->reject('active_content_or_svg', $extension, $mime);
        }

        if (isset(self::IMAGE_MIMES[$extension])) {
            if ($mime !== self::IMAGE_MIMES[$extension]
                || preg_match('/<\?php|<\?=|<script/i', $bytes) === 1
                || @getimagesizefromstring($bytes) === false) {
                $this->reject('invalid_or_polyglot_image', $extension, $mime);
            }
            $bytes = $this->reencodeImage($bytes, $extension);
        } elseif ($extension === 'pdf') {
            if ($mime !== 'application/pdf'
                || ! str_starts_with($bytes, '%PDF-')
                || preg_match('/\/JavaScript|\/JS\b|\/OpenAction|\/AA\b|\/Launch|\/RichMedia|\/EmbeddedFile|<\?php|<script/i', $bytes) === 1) {
                $this->reject('invalid_or_active_pdf', $extension, $mime);
            }
        } else {
            $this->reject('unsupported_file_type', $extension, $mime);
        }

        $target = Storage::disk($disk);
        $target->makeDirectory($directory);
        $filename = ($namePrefix ? $namePrefix.'_' : '').Str::uuid().'.'.$extension;
        $path = trim($directory, '/').'/'.$filename;
        if (! $target->put($path, $bytes)) {
            throw new \RuntimeException('File gagal disimpan.');
        }

        return $path;
    }

    private function reencodeImage(string $bytes, string $extension): string
    {
        if (! extension_loaded('gd')) {
            return $bytes;
        }

        $source = @imagecreatefromstring($bytes);
        if (! $source) {
            $this->reject('image_decode_failed', $extension);
        }
        $width = imagesx($source);
        $height = imagesy($source);
        $canvas = imagecreatetruecolor($width, $height);
        if (! $canvas) {
            imagedestroy($source);
            $this->reject('image_canvas_failed', $extension);
        }
        if ($extension === 'png' || $extension === 'webp') {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefilledrectangle($canvas, 0, 0, $width, $height, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        }
        imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);
        ob_start();
        $saved = match ($extension) {
            'jpg', 'jpeg' => imagejpeg($canvas, null, 90),
            'png' => imagepng($canvas),
            'webp' => function_exists('imagewebp') ? imagewebp($canvas, null, 90) : false,
        };
        $encoded = ob_get_clean();
        imagedestroy($source);
        imagedestroy($canvas);
        if (! $saved || ! is_string($encoded) || $encoded === '') {
            $this->reject('image_reencode_failed', $extension);
        }

        return $encoded;
    }

    private function reject(string $reason, string $extension, ?string $mime = null): never
    {
        Log::channel('security')->warning('Malicious upload rejected.', [
            'reason' => $reason,
            'extension' => $extension,
            'mime' => $mime,
        ]);
        throw ValidationException::withMessages([
            'file' => 'File tidak valid atau mengandung konten berbahaya.',
        ]);
    }
}
