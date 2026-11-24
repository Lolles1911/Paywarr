<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * The client area as an app on the phone's home screen: a web app manifest,
 * icons made from the site's favicon or logo, the colours, and a small
 * service worker (no push, no offline copies). Part of Wizarr (Paywarr).
 */
trait HomeScreen
{
    /** Background and top bar colour of the app. */
    public const APP_BACKGROUND = '#0b0a14';

    /** In every page's <head>: the manifest, the icon and the colours. */
    public function renderHomeScreenHead(): array
    {
        try {
            $name = e($this->siteName());
            $manifest = e($this->routeOr('extensions.wizarr.manifest', '/wizarr/app.webmanifest'));
            $icon = e($this->appIconUrl(180));
        } catch (Throwable $e) {
            return [];
        }

        return ['view' => new HtmlString('<link rel="manifest" href="' . $manifest . '"><meta name="theme-color" content="' . self::APP_BACKGROUND . '">'
            . '<link rel="apple-touch-icon" href="' . $icon . '"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="mobile-web-app-capable" content="yes">'
            . '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"><meta name="apple-mobile-web-app-title" content="' . $name . '">'
            . '<script>if("serviceWorker" in navigator){window.addEventListener("load",function(){navigator.serviceWorker.register(' . json_encode(url('/paywarr-sw.js')) . ',{scope:"/"}).catch(function(){});});}</script>')];
    }

    private function appIconUrl(int $size): string
    {
        try {
            return route('extensions.wizarr.app-icon', ['size' => $size]);
        } catch (Throwable $e) {
            return url('/wizarr/app-icon/' . $size);
        }
    }

    /** The manifest's content. */
    public function appManifest(): array
    {
        $name = $this->siteName();

        return [
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'start_url' => $this->hubUrl(),
            'scope' => url('/') . '/',
            'display' => 'standalone',
            'background_color' => self::APP_BACKGROUND,
            'theme_color' => self::APP_BACKGROUND,
            'icons' => [
                ['src' => $this->appIconUrl(192), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $this->appIconUrl(512), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $this->appIconUrl(512), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];
    }

    /** GET /wizarr/app.webmanifest */
    public function manifestFile()
    {
        return response()->json($this->appManifest(), 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600']);
    }

    /** GET /paywarr-sw.js: lets phones install the app; the network does the rest. */
    public function serviceWorkerFile()
    {
        $js = "self.addEventListener('install',function(){self.skipWaiting();});self.addEventListener('activate',function(e){e.waitUntil(self.clients.claim());});"
            . "self.addEventListener('fetch',function(e){if(e.request.mode==='navigate'){e.respondWith(fetch(e.request).catch(function(){return new Response('<meta name=viewport content=\"width=device-width\"><body style=\"background:" . self::APP_BACKGROUND . ";color:#fff;font-family:sans-serif;padding:2rem\"><h1>Offline</h1><p>No connection right now.</p>',{headers:{'Content-Type':'text/html'}});}));}});";

        return response($js, 200, ['Content-Type' => 'text/javascript', 'Service-Worker-Allowed' => '/', 'Cache-Control' => 'no-cache']);
    }

    /** GET /wizarr/app-icon/{size}: the favicon (or logo) on the app's background, square, as PNG. */
    public function appIconFile($size)
    {
        $size = in_array((int) $size, [180, 192, 512], true) ? (int) $size : 192;
        $png = Cache::remember('paywarr:app-icon:' . $size . ':' . md5((string) (self::setting('app_icon') . config('settings.favicon') . config('settings.logo_dark') . config('settings.logo'))), 86400,
            fn () => base64_encode(self::squareIcon(self::siteImageBytes(), $size, self::APP_BACKGROUND, mb_strtoupper(mb_substr($this->siteName(), 0, 1)), self::mailAccent())));

        return response(base64_decode($png), 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=86400']);
    }

    /** The favicon, else the dark logo, else the logo (bytes), or null. */
    private static function siteImageBytes(): ?string
    {
        foreach (['app_icon', 'settings.favicon', 'settings.logo_dark', 'settings.logo'] as $key) {
            $file = (string) (($key === 'app_icon' ? self::setting('app_icon') : config($key)) ?: '');
            if ($file === '') {
                continue;
            }
            foreach (['public', null] as $disk) {
                try {
                    $storage = $disk ? Storage::disk($disk) : Storage::disk();
                    if ($storage->exists($file)) {
                        return $storage->get($file);
                    }
                } catch (Throwable $e) {
                    // The next one.
                }
            }
        }

        return null;
    }

    /** A square PNG: $image (any GD format) at 72% on $background; without one, $letter in $colour. */
    public static function squareIcon(?string $image, int $size, string $background, string $letter = '', string $colour = '#ffffff'): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            // 1x1 of the background, as PNG, when GD is missing.
            return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        }
        $canvas = imagecreatetruecolor($size, $size);
        [$r, $g, $b] = sscanf($background, '#%02x%02x%02x');
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, $r, $g, $b));
        $source = $image !== null ? @imagecreatefromstring($image) : false;
        if ($source !== false) {
            imagealphablending($canvas, true);
            $w = imagesx($source);
            $h = imagesy($source);
            $box = (int) round($size * 0.72);
            $scale = min($box / max(1, $w), $box / max(1, $h));
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
            imagecopyresampled($canvas, $source, (int) (($size - $nw) / 2), (int) (($size - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
        } elseif ($letter !== '' && function_exists('imagettftext')) {
            foreach (['/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf'] as $font) {
                if (is_file($font)) {
                    [$cr, $cg, $cb] = sscanf($colour, '#%02x%02x%02x');
                    $points = $size * 0.42;
                    $box = imagettfbbox($points, 0, $font, $letter);
                    $x = (int) (($size - ($box[2] - $box[0])) / 2 - $box[0]);
                    $y = (int) (($size - ($box[1] - $box[7])) / 2 - $box[7]);
                    imagettftext($canvas, $points, 0, $x, $y, imagecolorallocate($canvas, $cr, $cg, $cb), $font, $letter);
                    break;
                }
            }
        }
        ob_start();
        imagepng($canvas);

        return (string) ob_get_clean();
    }
}
