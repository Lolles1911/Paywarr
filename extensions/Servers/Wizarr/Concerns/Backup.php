<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

/**
 * Backup of Paywarr's own data (Paywarr → Backup): settings (without keys,
 * secrets and tokens), mail texts and design, campaigns, movie nights,
 * challenges, waiting list, help page, logs and the rest Paywarr keeps.
 * Customers, subscriptions and invoices are Paymenter's: back up its database
 * for those. Part of Wizarr (Paywarr).
 */
trait Backup
{
    /** Everything Paywarr keeps, without secrets: ['paywarr' => version, 'made' => time, 'data' => [key => value]]. */
    public static function backupData(?array $rows = null, ?string $version = null): array
    {
        $rows ??= \App\Models\Property::where('model_type', 'paywarr')->where('model_id', 0)->pluck('value', 'key')->all();
        $data = [];
        foreach ($rows as $key => $value) {
            $decoded = json_decode((string) $value, true);
            $data[$key] = $decoded ?? $value;
        }
        if (isset($data['settings']) && is_array($data['settings'])) {
            $data['settings'] = array_diff_key($data['settings'], array_flip(self::secretKeys()));
        }

        return ['paywarr' => $version ?? (self::versionIn((string) @file_get_contents(__DIR__ . '/../Wizarr.php')) ?? ''), 'made' => time(), 'data' => $data];
    }

    /**
     * Puts a backup back: every key in it replaces what is there; the keys,
     * secrets and tokens you have now are kept. Returns how many were restored.
     */
    public static function restoreBackup(array $backup): int
    {
        if (!isset($backup['data']) || !is_array($backup['data']) || !array_key_exists('paywarr', $backup)) {
            throw new \App\Exceptions\DisplayException('This is not a Paywarr backup.');
        }
        $count = 0;
        foreach ($backup['data'] as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-z0-9_:-]{1,100}$/i', $key)) {
                continue;
            }
            if ($key === 'settings' && is_array($value)) {
                $current = self::storeGet('settings', []);
                $value = array_diff_key($value, array_flip(self::secretKeys())) + array_intersect_key(is_array($current) ? $current : [], array_flip(self::secretKeys()));
            }
            self::storePut($key, $value);
            $count++;
        }
        self::forgetGlobalSettings();
        \Illuminate\Support\Facades\Cache::forget('paywarr:settings-moved');

        return $count;
    }
}
