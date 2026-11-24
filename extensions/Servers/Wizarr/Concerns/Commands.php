<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The bot's slash commands for customers: /status, /newlink, /stats and
 * /password (in Danish /nytlink and /kodeord). Discord sends them to
 * /wizarr/discord/interactions, signed with the application's public key;
 * the answer is only shown to the customer who asked. Part of Wizarr
 * (Paywarr).
 */
trait Commands
{
    /** The commands, with Danish names and descriptions. */
    public static function commandDefinitions(): array
    {
        return [
            ['name' => 'status', 'description' => 'Your subscription: renewal date, trial and your user', 'name_localizations' => ['da' => 'status'], 'description_localizations' => ['da' => 'Dit abonnement: fornyelse, prøveperiode og din bruger']],
            ['name' => 'newlink', 'description' => 'A new invitation link, if your user isn\'t made yet', 'name_localizations' => ['da' => 'nytlink'], 'description_localizations' => ['da' => 'Et nyt invitationslink, hvis din bruger ikke er oprettet']],
            ['name' => 'stats', 'description' => 'What you watched in the last 7 and 30 days', 'name_localizations' => ['da' => 'stats'], 'description_localizations' => ['da' => 'Hvad du har set de sidste 7 og 30 dage']],
            ['name' => 'password', 'description' => 'A link to choose a new password (Jellyfin, Emby, Silo)', 'name_localizations' => ['da' => 'kodeord'], 'description_localizations' => ['da' => 'Et link til at vælge en ny adgangskode (Jellyfin, Emby, Silo)']],
        ];
    }

    /** Whether a Discord request is signed with $publicKey (hex). */
    public static function discordSignatureValid(string $publicKey, string $signature, string $timestamp, string $body): bool
    {
        if (!function_exists('sodium_crypto_sign_verify_detached') || !ctype_xdigit($publicKey) || strlen($publicKey) !== 64 || !ctype_xdigit($signature) || strlen($signature) !== 128) {
            return false;
        }
        // Old requests are refused (a replay).
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached(hex2bin($signature), $timestamp . $body, hex2bin($publicKey));
        } catch (Throwable $e) {
            return false;
        }
    }

    /** POST /wizarr/discord/interactions. */
    public function discordInteraction()
    {
        $body = (string) request()->getContent();
        $ext = $this->serverSigning((string) request()->header('X-Signature-Ed25519'), (string) request()->header('X-Signature-Timestamp'), $body);
        if ($ext === null) {
            return response('invalid request signature', 401);
        }
        $interaction = json_decode($body, true) ?: [];
        if ((int) ($interaction['type'] ?? 0) === 1) {
            return response()->json(['type' => 1]); // Discord checking the endpoint
        }
        if ((int) ($interaction['type'] ?? 0) !== 2) {
            return response()->json(['type' => 4, 'data' => ['content' => '?', 'flags' => 64]]);
        }
        // Wizarr or Tracearr may take longer than Discord's 3 seconds: "thinking…", then the answer.
        $token = (string) ($interaction['token'] ?? '');
        $appId = (string) ($interaction['application_id'] ?? '');
        $work = function () use ($ext, $interaction, $token, $appId) {
            $text = $ext->answerCommand((string) ($interaction['data']['name'] ?? ''), (string) ($interaction['member']['user']['id'] ?? $interaction['user']['id'] ?? ''), (string) ($interaction['locale'] ?? 'en'));
            try {
                Http::timeout(10)->patch('https://discord.com/api/v10/webhooks/' . rawurlencode($appId) . '/' . rawurlencode($token) . '/messages/@original', ['content' => mb_substr($text, 0, 1900)]);
            } catch (Throwable $e) {
                Log::info('Wizarr: Discord command answer not delivered: ' . $e->getMessage());
            }
        };
        app()->terminating($work);

        return response()->json(['type' => 5, 'data' => ['flags' => 64]]);
    }

    /** The Wizarr server whose public key signed the request, as this extension; null when none did. */
    private function serverSigning(string $signature, string $timestamp, string $body): ?static
    {
        $candidates = [$this];
        try {
            foreach (\App\Models\Server::where('extension', 'Wizarr')->with('settings')->get() as $server) {
                $candidates[] = new static(self::serverSettings($server));
            }
        } catch (Throwable $e) {
            // Only this one then.
        }
        foreach ($candidates as $candidate) {
            $key = trim((string) ($candidate->config('discord_public_key') ?? ''));
            if ($key !== '' && self::discordSignatureValid($key, $signature, $timestamp, $body)) {
                return $candidate;
            }
        }

        return null;
    }

    /** The answer to a command from the Discord user $discordId, in the customer's language. */
    public function answerCommand(string $command, string $discordId, string $locale = 'en'): string
    {
        $previous = function_exists('app') ? app()->getLocale() : 'en';
        if (function_exists('app') && str_starts_with(strtolower($locale), 'da')) {
            app()->setLocale('da');
        }
        try {
            $user = $this->userByDiscord($discordId);
            if (!$user) {
                return self::t('I don\'t know you yet. Connect Discord under your account first: :url', ['url' => $this->accountDiscordUrl()]);
            }
            $services = [];
            foreach (Service::where('user_id', $user->id)->whereHas('product.server', fn ($query) => $query->where('extension', 'Wizarr'))
                ->with(['product.settings', 'product.server', 'properties', 'user'])->get() as $service) {
                $settings = ExtensionHelper::settingsToArray($service->product->settings);
                if (in_array($service->status, ['active', 'suspended', 'pending'], true) && !$this->isGift($settings) && !$this->isTopUp($settings)) {
                    $services[] = $service;
                }
            }

            return match ($command) {
                'status' => $this->commandStatus($services),
                'newlink' => $this->commandNewLink($services),
                'stats' => $this->commandStats($user, $services),
                'password' => $this->commandPassword($services),
                default => self::t('I don\'t know that command.'),
            };
        } catch (Throwable $e) {
            Log::warning('Wizarr: Discord command /' . $command . ' failed: ' . $e->getMessage());

            return self::t('Something went wrong. Please try again in a moment, or write to us.');
        } finally {
            if (function_exists('app')) {
                app()->setLocale($previous);
            }
        }
    }

    private function userByDiscord(string $discordId)
    {
        if (!ctype_digit($discordId) || !class_exists(\App\Models\Property::class)) {
            return null;
        }
        $id = \App\Models\Property::where('key', self::PROP_DISCORD_ID)->where('value', $discordId)->where('model_type', User::class)->value('model_id');

        return $id ? User::find($id) : null;
    }

    private function commandStatus(array $services): string
    {
        if (!$services) {
            return self::t('You have no subscription with us right now. See them here: :url', ['url' => $this->shopUrl()]);
        }
        $lines = [];
        foreach ($services as $service) {
            $ext = $this->forServiceServer($service);
            $properties = ExtensionHelper::getServiceProperties($service);
            $settings = ExtensionHelper::settingsToArray($service->product->settings);
            $line = '**' . ($service->product->name ?? '') . '**: ';
            if (!empty($properties[self::PROP_PAUSED])) {
                $line .= self::t('paused until :date', ['date' => date(self::t('M j, Y'), (int) ($properties[self::PROP_PAUSED_UNTIL] ?? time()))]);
            } elseif ($service->status === 'suspended') {
                $line .= self::t('suspended (an invoice is unpaid)');
            } elseif ($service->status === 'pending') {
                $line .= self::t('waiting for payment');
            } else {
                $ends = strtotime((string) ($properties[self::PROP_TRIAL_ENDS] ?? '')) ?: null;
                $line .= $ext->isTrial($settings) && $ends ? self::t('trial until :date', ['date' => date(self::t('M j, Y'), $ends)])
                    : ($service->expires_at ? self::t('active, renews :date', ['date' => date(self::t('M j, Y'), strtotime((string) $service->expires_at))]) : self::t('active'));
            }
            $users = [];
            foreach ($ext->grantKeys($properties) as $grant) {
                $users = array_merge($users, $ext->withGrant($grant, fn () => array_column($ext->parseUsers((string) ($ext->grantView($properties)[self::PROP_USERS] ?? '')), 'username')));
            }
            $line .= ' · ' . ($users ? self::t('user: :name', ['name' => implode(', ', array_unique($users))]) : self::t('your user isn\'t made yet (/newlink)'));
            $lines[] = $line;
        }

        return implode("\n", $lines) . "\n" . $this->accountUrl();
    }

    private function commandNewLink(array $services): string
    {
        foreach (array_filter($services, fn ($service) => $service->status === 'active') as $service) {
            $ext = $this->forServiceServer($service);
            $properties = ExtensionHelper::getServiceProperties($service);
            $hasUser = false;
            foreach ($ext->grantKeys($properties) ?: [''] as $grant) {
                $hasUser = $hasUser || $ext->withGrant($grant, fn () => !empty($ext->grantView($properties)[self::PROP_USERS]));
            }
            if ($hasUser && empty($properties[self::PROP_NEW_LINK])) {
                continue;
            }
            try {
                $url = $ext->renewInvitation($service, ExtensionHelper::settingsToArray($service->product->settings), $properties);
            } catch (DisplayException $e) {
                return $e->getMessage();
            }
            self::logAdmin($service->user, (int) $service->id, 'Link asked for with /newlink on Discord', 'customer');

            return self::t('Your link for :product (it works once): :url', ['product' => (string) ($service->product->name ?? ''), 'url' => $url]);
        }

        return !$services
            ? self::t('You have no subscription with us right now. See them here: :url', ['url' => $this->shopUrl()])
            : self::t('Your user is already made, so no new link is needed. Forgot the password? Use /password.');
    }

    private function commandPassword(array $services): string
    {
        foreach (array_filter($services, fn ($service) => $service->status === 'active') as $service) {
            $properties = ExtensionHelper::getServiceProperties($service);
            try {
                $url = $this->forServiceServer($service)->resetPassword($service, ExtensionHelper::settingsToArray($service->product->settings), $properties);
            } catch (DisplayException $e) {
                return $e->getMessage();
            }

            return self::t('Choose a new password here: :url', ['url' => $url]);
        }

        return self::t('You have no active subscription with a user to change.');
    }

    private function commandStats($user, array $services): string
    {
        $stats = 'Paymenter\\Extensions\\Others\\PaywarrStats\\PaywarrStats';
        if (!class_exists($stats) || !method_exists($stats, 'statsFor')) {
            return self::t('Stats aren\'t available here.');
        }
        $lists = [];
        foreach ($services as $service) {
            $lists[] = ExtensionHelper::getServiceProperties($service);
        }
        $paywarrStats = new $stats([]);
        $numbers = $paywarrStats->statsFor($paywarrStats->mediaAccounts($lists));
        if (($numbers['state'] ?? '') !== 'ok') {
            return self::t('Nothing watched yet. Enjoy! :url', ['url' => $this->statsUrl()]);
        }
        $time = fn (int $ms) => intdiv($ms, 3600000) > 0 ? self::t(':h h :m min', ['h' => intdiv($ms, 3600000), 'm' => intdiv($ms % 3600000, 60000)]) : self::t(':m min', ['m' => intdiv($ms, 60000)]);
        $w = $numbers['windows'];

        return self::t('Last 7 days: :p7 plays, :t7. Last 30 days: :p30 plays, :t30.', [
            'p7' => $w['last_7']['plays'], 't7' => $time((int) $w['last_7']['watch_time_ms']), 'p30' => $w['last_30']['plays'], 't30' => $time((int) $w['last_30']['watch_time_ms']),
        ]) . "\n" . self::t('More in My stats: :url', ['url' => $this->statsUrl()]);
    }

    private function statsUrl(): string
    {
        try {
            return route('extensions.paywarr-stats');
        } catch (Throwable $e) {
            return url('/stats');
        }
    }

    private function accountDiscordUrl(): string
    {
        try {
            return route('extensions.wizarr.discord');
        } catch (Throwable $e) {
            return url('/wizarr/discord');
        }
    }

    /**
     * Hourly: the commands are registered on your Discord server when the
     * public key is set and they changed (or were never registered).
     */
    public function registerDiscordCommandsIfNeeded(bool $allServers = true): bool
    {
        if ($allServers) {
            $done = false;
            try {
                foreach (\App\Models\Server::where('extension', 'Wizarr')->with('settings')->get() as $server) {
                    $done = (new static(self::serverSettings($server)))->registerDiscordCommandsIfNeeded(false) || $done;
                }
            } catch (Throwable $e) {
                return $this->registerDiscordCommandsIfNeeded(false);
            }

            return $done;
        }
        if (!$this->discordConfigured() || trim((string) ($this->config('discord_public_key') ?? '')) === '') {
            return false;
        }
        $guild = (string) $this->config('discord_guild_id');
        $app = (string) $this->config('discord_client_id');
        $hash = md5(json_encode(self::commandDefinitions()) . $app . $guild);
        if (Cache::get('paywarr:discord-commands:' . $guild) === $hash) {
            return false;
        }
        try {
            $response = Http::withHeaders(['Authorization' => 'Bot ' . $this->config('discord_bot_token')])->timeout(10)
                ->put('https://discord.com/api/v10/applications/' . rawurlencode($app) . '/guilds/' . rawurlencode($guild) . '/commands', self::commandDefinitions());
            if ($response->status() < 200 || $response->status() >= 300) {
                Log::warning('Wizarr: Discord commands not registered (' . $response->status() . '): ' . mb_substr((string) $response->body(), 0, 300));

                return false;
            }
        } catch (Throwable $e) {
            Log::warning('Wizarr: Discord commands not registered: ' . $e->getMessage());

            return false;
        }
        Cache::forever('paywarr:discord-commands:' . $guild, $hash);
        Log::info('Wizarr: Discord commands registered on server ' . $guild . '.');

        return true;
    }
}
