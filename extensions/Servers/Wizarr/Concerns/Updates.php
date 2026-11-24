<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Is there a newer Paywarr (or Paywarr Stats)? Asks GitHub for the version
 * on main and the changelog, every 6 hours, for Paywarr → Updates.
 * Part of Wizarr (Paywarr).
 */
trait Updates
{
    public const UPDATE_SOURCES = [
        'paywarr' => ['name' => 'Paywarr', 'repo' => 'Lolles1911/Paywarr', 'file' => 'extensions/Servers/Wizarr/Wizarr.php', 'folder' => '/opt/paywarr',
            'class' => \Paymenter\Extensions\Servers\Wizarr\Wizarr::class],
        'stats' => ['name' => 'Paywarr Stats', 'repo' => 'Lolles1911/Paywarr-stats', 'file' => 'extensions/Others/PaywarrStats/PaywarrStats.php', 'folder' => '/opt/paywarr-stats',
            'class' => 'Paymenter\\Extensions\\Others\\PaywarrStats\\PaywarrStats'],
    ];

    /** The version in an extension's PHP file ("version: '1.2.3'"). */
    public static function versionIn(string $php): ?string
    {
        return preg_match("/version:\\s*'(\\d+\\.\\d+\\.\\d+)'/", $php, $m) ? $m[1] : null;
    }

    /** The changelog's sections newer than $installed, as [version => markdown]. */
    public static function newerSections(string $changelog, string $installed): array
    {
        $sections = [];
        $parts = preg_split('/^## /m', $changelog);
        foreach (array_slice($parts, 1) as $part) {
            if (preg_match('/^(\d+\.\d+\.\d+)/', $part, $m) && version_compare($m[1], $installed, '>')) {
                $sections[$m[1]] = trim(preg_replace('/^[^\n]*\n/', '', $part, 1));
            }
        }

        return $sections;
    }

    /** What is installed and what is on GitHub: [key => [name, installed, latest, newer (sections), folder, error]]. */
    public static function updateStatus(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget('paywarr:updates');
        }

        return Cache::remember('paywarr:updates', 6 * 3600, function () {
            $out = [];
            foreach (self::UPDATE_SOURCES as $key => $source) {
                if (!class_exists($source['class'])) {
                    continue;
                }
                try {
                    $installed = self::versionIn((string) file_get_contents((new \ReflectionClass($source['class']))->getFileName())) ?? '0.0.0';
                } catch (Throwable $e) {
                    continue;
                }
                $row = ['name' => $source['name'], 'installed' => $installed, 'latest' => null, 'newer' => [], 'folder' => $source['folder'], 'repo' => $source['repo'], 'error' => ''];
                try {
                    $raw = 'https://raw.githubusercontent.com/' . $source['repo'] . '/main/';
                    $php = Http::timeout(10)->get($raw . $source['file']);
                    $log = Http::timeout(10)->get($raw . 'CHANGELOG.md');
                    $row['latest'] = $php->successful() ? self::versionIn($php->body()) : null;
                    $row['newer'] = $log->successful() ? self::newerSections($log->body(), $installed) : [];
                    if ($row['latest'] === null) {
                        $row['error'] = 'GitHub answered ' . $php->status() . '.';
                    }
                } catch (Throwable $e) {
                    $row['error'] = 'GitHub could not be reached: ' . $e->getMessage();
                }
                $out[$key] = $row;
            }

            return $out;
        });
    }

    /** How many have a newer version (for the menu badge). */
    public static function updatesWaiting(): int
    {
        try {
            return count(array_filter(self::updateStatus(), fn ($r) => $r['latest'] && version_compare($r['latest'], $r['installed'], '>')));
        } catch (Throwable $e) {
            return 0;
        }
    }
}
