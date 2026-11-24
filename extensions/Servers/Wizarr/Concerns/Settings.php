<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Helpers\ExtensionHelper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Paywarr → Settings: everything that is for the whole shop lives on one
 * page with tabs; the Wizarr server in Paymenter keeps only how to reach
 * Wizarr or Silo. config() reads a shop-wide setting from here, so the rest
 * of Paywarr doesn't need to know. The values that were on the servers are
 * moved here once, by themselves. Part of Wizarr (Paywarr).
 */
trait Settings
{
    /** What stays on the Wizarr server in Paymenter. */
    public const CONNECTION_KEYS = ['backend', 'host', 'public_url', 'api_key'];

    /** The tabs of Paywarr → Settings and their settings, in order. */
    public const SETTING_TABS = [
        'Shop' => ['refund_action', 'welcome_mail', 'welcome_text', 'showcase', 'coming_soon', 'app_icon', 'streams_over_limit', 'requests_page_for', 'more_requests_url', 'report_department'],
        'Reminders' => ['remind_trial', 'remind_link', 'remind_renewal', 'cart_reminder_hours', 'cart_reminder_again_hours', 'news_day', 'news_mail', 'survey'],
        'Loyalty' => ['pause_per_year', 'pause_max_days', 'winback_days', 'winback_percent', 'winback_valid_days', 'referral_percent', 'referral_products', 'referral_reward_months',
            'anniversary_months', 'anniversary_reward', 'anniversary_amount', 'friend_pass'],
        'Discord' => ['discord_invite_url', 'discord_guild_id', 'discord_client_id', 'discord_client_secret', 'discord_bot_token', 'discord_public_key', 'announce_channel_id',
            'discord_gate_shop', 'discord_suspend_leavers', 'discord_grace_hours'],
        'Connections' => ['seerr_url', 'seerr_public_url', 'seerr_api_key', 'seerr2_url', 'seerr2_public_url', 'seerr2_api_key', 'seerr3_url', 'seerr3_public_url', 'seerr3_api_key', 'tracearr_url', 'tracearr_api_key', 'sonarr_url', 'sonarr_api_key', 'radarr_url', 'radarr_api_key'],
        'Alerts and clean-up' => ['alert_webhook', 'heartbeat_url', 'cleanup_disable_days', 'cleanup_delete_days'],
    ];

    private static ?array $settingsMemo = null;

    private static int $settingsMemoAt = 0;

    /** The shop-wide settings (secrets decrypted, only those with a value); null before they are moved here. */
    public static function globalSettings(): ?array
    {
        if (self::$settingsMemoAt > 0 && time() - self::$settingsMemoAt < 30) {
            return self::$settingsMemo;
        }
        self::$settingsMemoAt = time();
        $stored = self::storeGet('settings');
        if (!is_array($stored)) {
            return self::$settingsMemo = null;
        }
        $values = [];
        foreach ($stored as $key => $value) {
            $values[$key] = is_string($value) && str_starts_with($value, 'enc:') ? self::decryptSetting(substr($value, 4)) : $value;
        }

        return self::$settingsMemo = $values;
    }

    public static function forgetGlobalSettings(): void
    {
        self::$settingsMemo = null;
        self::$settingsMemoAt = 0;
    }

    /** The settings kept encrypted: keys, secrets, tokens, the alert webhook. */
    public static function secretKeys(): array
    {
        return array_values(array_filter(self::globalKeys(), fn ($k) => str_ends_with($k, '_key') || str_ends_with($k, '_secret') || str_ends_with($k, '_token') || $k === 'alert_webhook'));
    }

    /** Saves the shop-wide settings; $secret keys are kept encrypted. */
    public static function saveGlobalSettings(array $values, array $secret = []): void
    {
        $stored = [];
        foreach ($values as $key => $value) {
            if (!in_array($key, self::globalKeys(), true)) {
                continue;
            }
            if (is_array($value)) {
                $value = json_encode(array_values($value));
            } elseif (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            $value = $value === null ? '' : (string) $value;
            if ($value === '' || $value === '[]') {
                continue; // empty: not set
            }
            $stored[$key] = in_array($key, $secret, true) && $value !== '' ? 'enc:' . self::encryptSetting($value) : $value;
        }
        self::storePut('settings', $stored);
        self::forgetGlobalSettings();
    }

    private static function encryptSetting(string $value): string
    {
        try {
            return \Illuminate\Support\Facades\Crypt::encryptString($value);
        } catch (Throwable $e) {
            return 'plain:' . $value;
        }
    }

    private static function decryptSetting(string $value): string
    {
        if (str_starts_with($value, 'plain:')) {
            return substr($value, 6);
        }
        try {
            return \Illuminate\Support\Facades\Crypt::decryptString($value);
        } catch (Throwable $e) {
            return '';
        }
    }

    /** One shop-wide setting (also for Paywarr Stats). */
    public static function setting(string $key): mixed
    {
        return (self::globalSettings() ?? [])[$key] ?? null;
    }

    /** A setting: the connection from the server, the rest from Paywarr → Settings (or the server, before they are moved). */
    public function config($key)
    {
        if (!in_array($key, self::CONNECTION_KEYS, true) && in_array($key, self::globalKeys(), true) && ($global = self::globalSettings()) !== null) {
            return $global[$key] ?? null;
        }

        return parent::config($key);
    }

    /** A Paymenter server's settings with the shop-wide ones on top. */
    public static function serverSettings($server): array
    {
        $own = ExtensionHelper::settingsToArray($server->settings ?? []);
        $global = self::globalSettings();

        return $global === null ? $own : array_diff_key($own, array_flip(self::globalKeys())) + $global;
    }

    /** Every shop-wide setting's name. */
    public static function globalKeys(): array
    {
        return array_merge(...array_values(self::SETTING_TABS));
    }

    /**
     * Once: the shop-wide settings from the Wizarr servers to Paywarr →
     * Settings (the first server with a value wins). Returns what was moved.
     */
    public static function migrateSettings(?iterable $servers = null): ?array
    {
        try {
            if (Cache::get('paywarr:settings-moved') || self::storeGet('settings') !== null) {
                Cache::forever('paywarr:settings-moved', true);

                return null;
            }
            $servers ??= \App\Models\Server::where('extension', 'Wizarr')->with('settings')->get();
            if (count(is_array($servers) ? $servers : iterator_to_array($servers)) === 0) {
                return null; // no server yet: nothing to move
            }
            $values = [];
            foreach ($servers as $server) {
                foreach (ExtensionHelper::settingsToArray($server->settings) as $key => $value) {
                    if (in_array($key, self::globalKeys(), true) && !isset($values[$key]) && $value !== null && $value !== '' && $value !== []) {
                        $values[$key] = $value;
                    }
                }
            }
            self::saveGlobalSettings($values, self::secretKeys());
            Cache::forever('paywarr:settings-moved', true);
            Log::info('Wizarr: ' . count($values) . ' settings moved from the Wizarr server(s) to Paywarr -> Settings.');

            return $values;
        } catch (Throwable $e) {
            Log::warning('Wizarr: settings not moved to Paywarr -> Settings yet: ' . $e->getMessage());

            return null;
        }
    }
}
