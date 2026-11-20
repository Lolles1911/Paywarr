<?php

namespace Paymenter\Extensions\Servers\Wizarr\Admin;

use App\Helpers\ExtensionHelper;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Wizarr\Wizarr;

/**
 * What the Paywarr pages in admin show, asked from every Wizarr and Silo
 * behind your Paymenter servers, kept for a minute (Wizarr::adminInventory()).
 */
class PaywarrInventory
{
    private const KEY = 'paywarr:admin:inventory';

    /** @return array<int, array> one entry per Wizarr or Silo */
    public static function get(): array
    {
        return Cache::remember(self::KEY, 60, fn () => (new Wizarr([]))->adminInventory(
            Server::where('extension', 'Wizarr')->with('settings')->get(),
            self::services(),
        ));
    }

    /** After a change: the next view asks again. */
    public static function forget(): void
    {
        Cache::forget(self::KEY);
    }

    public static function services(): iterable
    {
        return Service::query()
            ->whereHas('product.server', fn ($query) => $query->where('extension', 'Wizarr'))
            ->with(['user', 'product.server.settings', 'properties'])
            ->get();
    }

    /** The Wizarr or Silo behind Paymenter server $serverId. */
    public static function backendFor(int $serverId): ?array
    {
        foreach (self::get() as $backend) {
            if (isset($backend['paymenter'][$serverId])) {
                return $backend;
            }
        }

        return null;
    }

    /** Paywarr set up for a backend from get(). */
    public static function wizarrFor(array $backend): Wizarr
    {
        return new Wizarr(ExtensionHelper::settingsToArray(Server::findOrFail($backend['server_id'])->settings));
    }

    /** A label for a backend: "Wizarr (wizarr.example.com)" or "Silo (silo.example.com)". */
    public static function label(array $backend): string
    {
        return ($backend['kind'] === 'silo' ? 'Silo' : 'Wizarr') . ' (' . (parse_url($backend['host'], PHP_URL_HOST) ?: $backend['host']) . ')';
    }
}
