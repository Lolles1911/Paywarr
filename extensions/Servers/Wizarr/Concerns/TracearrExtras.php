<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * More from Tracearr: the library in numbers (front page, My page, product
 * pages) and "Was this you?" when a new device plays on a customer's user.
 * Part of Wizarr (Paywarr).
 */
trait TracearrExtras
{
    /** Totals of the libraries given (Tracearr's /libraries), optionally only some server types. */
    public static function libraryTotals(array $libraries, ?array $types = null): array
    {
        $t = ['movies' => 0, 'shows' => 0, 'episodes' => 0, 'uhd' => 0, 'bytes' => 0];
        foreach ($libraries as $lib) {
            if ($types !== null && !in_array(strtolower((string) ($lib['server_type'] ?? '')), $types, true)) {
                continue;
            }
            $t['movies'] += (int) ($lib['movie_count'] ?? 0);
            $t['shows'] += (int) ($lib['show_count'] ?? 0);
            $t['episodes'] += (int) ($lib['episode_count'] ?? 0);
            $t['bytes'] += (int) ($lib['total_file_size'] ?? 0);
            foreach ((array) ($lib['resolutions'] ?? []) as $res => $n) {
                if (in_array(strtolower((string) $res), ['4k', '2160p', '2160', 'uhd'], true)) {
                    $t['uhd'] += (int) $n;
                }
            }
        }

        return $t;
    }

    /** Tracearr's libraries, kept 6 hours ([] without Tracearr). */
    public function trLibraries(): array
    {
        return Cache::remember('paywarr:tr-libraries', 6 * 3600, function () {
            try {
                return $this->tracearrOrAny()?->libraries() ?? [];
            } catch (Throwable $e) {
                return [];
            }
        });
    }

    /** "12,400 movies · 1,300 shows · 2,100 in 4K" (the parts there are). */
    public static function libraryLine(array $t): string
    {
        $n = fn (int $v) => number_format($v, 0, ',', '.');
        $parts = array_filter([
            $t['movies'] ? self::t(':n movies', ['n' => $n($t['movies'])]) : '',
            $t['shows'] ? self::t(':n shows', ['n' => $n($t['shows'])]) : '',
            $t['episodes'] ? self::t(':n episodes', ['n' => $n($t['episodes'])]) : '',
            $t['uhd'] ? self::t(':n in 4K', ['n' => $n($t['uhd'])]) : '',
        ]);

        return implode(' · ', $parts);
    }

    /** On the front page, with the selection for visitors: the library in numbers. */
    public function renderLibraryNumbers(): array
    {
        try {
            if (!$this->showcaseOn() || !($libs = $this->trLibraries())) {
                return [];
            }
            $t = self::libraryTotals($libs);
        } catch (Throwable $e) {
            return [];
        }
        if (!$t['movies'] && !$t['shows']) {
            return [];
        }
        $n = fn (int $v) => e(number_format($v, 0, ',', '.'));
        $tile = fn (string $value, string $label) => '<div style="flex:1 1 9rem;text-align:center;padding:1rem;border-radius:1rem;background:rgba(127,127,127,.12)"><div style="font-size:1.9rem;font-weight:800">' . $value . '</div><div style="opacity:.75">' . e($label) . '</div></div>';
        $tiles = ($t['movies'] ? $tile($n($t['movies']), self::t('movies')) : '') . ($t['shows'] ? $tile($n($t['shows']), self::t('shows')) : '')
            . ($t['episodes'] ? $tile($n($t['episodes']), self::t('episodes')) : '') . ($t['uhd'] ? $tile($n($t['uhd']), self::t('in 4K')) : '');

        return ['view' => new HtmlString('<section class="container" style="margin:3rem auto 0"><div style="display:flex;gap:.8rem;flex-wrap:wrap">' . $tiles . '</div></section>')];
    }

    /** On a Paywarr product's page: what is on its media server(s). */
    public function renderProductLibrary(): array
    {
        try {
            if (!request()->routeIs('products.show', 'products.checkout')) {
                return [];
            }
            $product = request()->route('product');
            if (is_string($product)) {
                $product = Product::where('slug', $product)->first();
            }
            if (!$product instanceof Product || ($product->server->extension ?? null) !== 'Wizarr' || !($libs = $this->trLibraries())) {
                return [];
            }
            $types = $this->productServerTypes($product);
            $line = self::libraryLine(self::libraryTotals($libs, $types ?: null));
        } catch (Throwable $e) {
            return [];
        }
        if ($line === '') {
            return [];
        }
        $html = '<div id="paywarr-library" style="margin:.5rem 0 1rem;opacity:.85">📚 ' . e(self::t('On the server: :line', ['line' => $line])) . '</div>'
            . '<script>(function(){var b=document.getElementById("paywarr-library"),t=document.querySelector("main h1");if(b&&t&&t.parentNode){t.parentNode.insertBefore(b,t.nextSibling);}})();</script>';

        return ['view' => new HtmlString($html)];
    }

    /** The media server types a product gives (jellyfin, plex, emby, silo), from Wizarr's servers. */
    private function productServerTypes(Product $product): array
    {
        return Cache::remember('paywarr:product-types:' . $product->id, 3600, function () use ($product) {
            try {
                $backend = \Paymenter\Extensions\Servers\Wizarr\Admin\PaywarrInventory::backendFor((int) $product->server_id);
                if (!$backend || !$backend['ok']) {
                    return [];
                }
                if ($backend['kind'] === 'silo') {
                    return ['silo'];
                }
                $ids = $this->idList(\App\Helpers\ExtensionHelper::settingsToArray($product->settings ?? [])['server_ids'] ?? null);
                $types = [];
                foreach ($backend['servers'] as $server) {
                    if (!$ids || in_array((int) $server['id'], $ids, true)) {
                        $types[] = $server['type'];
                    }
                }

                return array_values(array_unique(array_filter($types)));
            } catch (Throwable $e) {
                return [];
            }
        });
    }

    /* -------------------------------------------------------- new devices */

    public const PROP_KNOWN_DEVICES = 'paywarr_known_devices';

    /** A device's key: what it is, and the app on it. */
    public static function deviceKey(array $play): string
    {
        return strtolower(trim((string) ($play['device'] ?? '')) . '|' . trim((string) ($play['player'] ?? '')) . '|' . trim((string) ($play['platform'] ?? '')));
    }

    /**
     * New devices per user in these plays: [user ID => [play, ...]] for
     * devices not in $known (user ID => [key => time]). $known is updated.
     */
    public static function newDevices(array $plays, array $accountOwners, array &$known, int $now): array
    {
        $new = [];
        usort($plays, fn ($a, $b) => strcmp((string) ($a['started_at'] ?? ''), (string) ($b['started_at'] ?? '')));
        foreach ($plays as $play) {
            $account = strtolower((string) ($play['server_type'] ?? '')) . '|' . strtolower((string) ($play['user']['username'] ?? $play['username'] ?? ''));
            $userId = $accountOwners[$account] ?? null;
            $key = self::deviceKey($play);
            if ($userId === null || $key === '||') {
                continue;
            }
            if (!isset($known[$userId][$key])) {
                $new[$userId][] = $play;
            }
            $known[$userId][$key] = $now;
        }

        return $new;
    }

    /**
     * Hourly: plays on a device a customer's users haven't used before get
     * "Was this you?" (once per device). The first run only learns the
     * devices of the last 90 days, without mails.
     */
    public function checkNewDevices(?int $now = null): array
    {
        $now ??= time();
        $tracearr = $this->tracearrOrAny();
        if ($tracearr === null || self::mailIsOff('new_device')) {
            return [];
        }
        $owners = [];
        foreach (Service::whereIn('status', ['active', 'suspended'])->whereHas('product.server', fn ($q) => $q->where('extension', 'Wizarr'))
            ->with(['product.server', 'properties'])->get() as $service) {
            foreach ($this->streamAccounts(\App\Helpers\ExtensionHelper::getServiceProperties($service)) as $account) {
                $owners[$account] = (int) $service->user_id;
            }
        }
        $first = !self::storeGet('devices_learned');
        try {
            $plays = $tracearr->history($first ? $now - 90 * 86400 : $now - 2 * 3600, $first ? 20000 : 2000);
        } catch (Throwable $e) {
            return [];
        }
        $known = [];
        foreach (array_unique(array_values($owners)) as $userId) {
            $user = User::find($userId);
            $known[$userId] = $user ? (json_decode((string) ($this->propertyValue($user, self::PROP_KNOWN_DEVICES) ?? '[]'), true) ?: []) : [];
        }
        $new = self::newDevices($plays, $owners, $known, $now);
        $mailed = [];
        foreach ($known as $userId => $devices) {
            $user = User::find($userId);
            if (!$user) {
                continue;
            }
            arsort($devices);
            $user->properties()->updateOrCreate(['key' => self::PROP_KNOWN_DEVICES], ['name' => 'Devices seen', 'value' => json_encode(array_slice($devices, 0, 50, true))]);
            if ($first || empty($new[$userId])) {
                continue;
            }
            $play = end($new[$userId]);
            $service = Service::where('user_id', $userId)->whereIn('status', ['active', 'suspended'])->whereHas('product.server', fn ($q) => $q->where('extension', 'Wizarr'))->first();
            if (!$service) {
                continue;
            }
            try {
                $this->notifyMail($service, 'new_device', ['device' => trim((string) ($play['device'] ?? '')) ?: '?', 'app' => trim((string) ($play['player'] ?? '')),
                    'server' => ucfirst((string) ($play['server_type'] ?? '')), 'time' => date(self::t('M j, H:i'), strtotime((string) ($play['started_at'] ?? '')) ?: $now)],
                    $this->routeOr('extensions.wizarr.devices', '/wizarr/devices'));
                $mailed[] = $userId;
            } catch (Throwable $e) {
                Log::warning('Wizarr: new device mail to user ' . $userId . ' not sent: ' . $e->getMessage());
            }
        }
        if ($first) {
            self::storePut('devices_learned', $now);
        }

        return $mailed;
    }
}
