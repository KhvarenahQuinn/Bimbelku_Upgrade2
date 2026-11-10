<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PublicController extends Controller
{
    public function getFooterSettings(Request $request)
    {
        $settings = Cache::remember('public.footer_settings.v1', now()->addMinutes(10), fn () => Setting::query()
            ->whereIn('key', [
                'footer_address',
                'footer_phone',
                'footer_email',
                'footer_ig',
                'footer_tiktok',
            ])
            ->pluck('value', 'key')
        );

        $response = response()->json($settings);
        $response->setEtag(sha1((string) json_encode($settings)));
        $response->setPublic();
        $response->setMaxAge(300);
        $response->headers->addCacheControlDirective('stale-while-revalidate', '60');
        $response->isNotModified($request);

        return $response;
    }
}
