<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * "My devices" (/wizarr/devices): what plays on the customer's users now
 * (with Stop), the devices used in the last 30 days (from Tracearr), and a
 * new password for Jellyfin and Emby users. Part of Wizarr (Paywarr).
 */
trait Devices
{
    /** The customer's active Paywarr subscriptions. */
    private function deviceServices($user): array
    {
        return Service::where('user_id', $user->id)->where('status', 'active')->whereHas('product.server', fn ($q) => $q->where('extension', 'Wizarr'))
            ->with(['product.server', 'product.settings', 'properties', 'user'])->get()->all();
    }

    /** "type|username" => service, for the customer's users. */
    private function deviceAccounts(array $services): array
    {
        $accounts = [];
        foreach ($services as $service) {
            foreach ($this->streamAccounts(ExtensionHelper::getServiceProperties($service)) as $account) {
                $accounts[$account] = $service;
            }
        }

        return $accounts;
    }

    /** Streams now on these accounts. */
    public static function streamsFor(array $streams, array $accounts): array
    {
        return array_values(array_filter($streams, fn ($s) => isset($accounts[strtolower((string) ($s['server_type'] ?? '')) . '|' . strtolower((string) ($s['username'] ?? ''))])));
    }

    /** Devices in the plays of these accounts: [device, player, platform, server, last, plays], newest first. */
    public static function devicesFrom(array $history, array $accounts): array
    {
        $devices = [];
        foreach ($history as $row) {
            $account = strtolower((string) ($row['server_type'] ?? '')) . '|' . strtolower((string) ($row['user']['username'] ?? ''));
            if (!isset($accounts[$account])) {
                continue;
            }
            $device = trim((string) ($row['device'] ?? '')) ?: trim((string) ($row['platform'] ?? '')) ?: '?';
            $player = trim((string) ($row['player'] ?? ''));
            $key = strtolower($device . '|' . $player);
            $at = strtotime((string) ($row['started_at'] ?? '')) ?: 0;
            $devices[$key] ??= ['device' => $device, 'player' => $player, 'platform' => (string) ($row['platform'] ?? ''), 'server' => ucfirst((string) ($row['server_type'] ?? '')), 'last' => 0, 'plays' => 0, 'profiles' => []];
            $devices[$key]['plays']++;
            if (trim((string) ($row['profile'] ?? '')) !== '') {
                $devices[$key]['profiles'][trim((string) $row['profile'])] = true;
            }
            $devices[$key]['last'] = max($devices[$key]['last'], $at);
        }
        usort($devices, fn ($a, $b) => $b['last'] <=> $a['last']);

        return $devices;
    }

    /** GET and POST /wizarr/devices */
    public function devicesPage()
    {
        $user = Auth::user();
        $services = $this->deviceServices($user);
        $accounts = $this->deviceAccounts($services);
        $tracearr = $this->tracearrOrAny();
        $notice = $error = '';
        $streams = [];
        if ($tracearr && $accounts) {
            try {
                $streams = self::streamsFor($tracearr->streams(), $accounts);
            } catch (Throwable $e) {
                Log::info('Wizarr: streams for user ' . $user->id . ' not read: ' . $e->getMessage());
            }
        }

        if (request()->isMethod('post')) {
            $key = 'wizarr-devices:' . $user->id;
            if (class_exists(RateLimiter::class) && RateLimiter::tooManyAttempts($key, 6)) {
                $error = self::t('That was a few times now. Please try again in an hour.');
            } else {
                if (class_exists(RateLimiter::class)) {
                    RateLimiter::hit($key, 3600);
                }
                if (($stop = (string) request()->input('stop')) !== '' && $tracearr) {
                    if (in_array($stop, array_column($streams, 'id'), true)) {
                        try {
                            $tracearr->terminate($stop, self::t('Stopped from your account.'));
                            $streams = array_values(array_filter($streams, fn ($s) => $s['id'] !== $stop));
                            $notice = self::t('Stopped.');
                            self::logAdmin($user, null, 'Stopped a stream from My devices', 'customer');
                        } catch (Throwable $e) {
                            $error = self::t('It could not be stopped right now. Please try again in a moment.');
                        }
                    }
                } elseif (($id = (int) request()->input('password')) > 0) {
                    $service = array_values(array_filter($services, fn ($s) => (int) $s->id === $id))[0] ?? null;
                    try {
                        $links = $service ? $this->forServiceServer($service)->adminPasswordReset($service, false) : [];
                        if ($links) {
                            self::logAdmin($user, (int) $service->id, 'Asked for a new password from My devices', 'customer');

                            return redirect()->away((string) array_values($links)[0]);
                        }
                    } catch (Throwable $e) {
                        $error = self::t('A new password can\'t be made here for this user. On Plex, the password is your own Plex account\'s.');
                    }
                }
            }
        }

        $devices = [];
        if ($tracearr && $accounts) {
            try {
                $history = Cache::remember('paywarr:devices:' . $user->id, 300, fn () => $tracearr->history(time() - 30 * 86400, 1500));
                $devices = self::devicesFrom($history, $accounts);
            } catch (Throwable $e) {
                Log::info('Wizarr: devices for user ' . $user->id . ' not read: ' . $e->getMessage());
            }
        }

        $e = fn ($v) => e((string) $v);
        $card = fn (string $title, string $body) => '<div class="bg-background-secondary" style="border-radius:1rem;padding:1.1rem 1.3rem;margin-bottom:1rem"><div style="font-weight:700;font-size:1.1rem;margin-bottom:.6rem">' . $e($title) . '</div>' . $body . '</div>';
        $line = 'display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:.6rem 0;border-top:1px solid rgba(127,127,127,.2)';

        $now = '';
        foreach ($streams as $s) {
            $title = trim((string) ($s['show_title'] ?? '')) !== '' ? $s['show_title'] . ' · ' . ($s['media_title'] ?? '') : (string) ($s['media_title'] ?? '');
            $now .= '<div style="' . $line . '"><div><div style="font-weight:600">' . $e($title) . '</div><div style="opacity:.7;font-size:.9rem">'
                . $e(trim(($s['device'] ?? '') . ' · ' . ($s['player'] ?? ''), ' ·')) . (trim((string) ($s['profile'] ?? '')) !== '' ? ' · ' . $e(self::t('profile :name', ['name' => $s['profile']])) : '') . ' · ' . $e(self::t('since :time', ['time' => date('H:i', strtotime((string) ($s['started_at'] ?? 'now')))])) . '</div></div>'
                . '<form method="post">' . csrf_field() . '<input type="hidden" name="stop" value="' . $e($s['id']) . '"><button type="submit" class="border border-neutral rounded-lg px-3 py-1">' . $e(self::t('Stop')) . '</button></form></div>';
        }
        $html = $card(self::t('Playing now'), $now !== '' ? $now . '<p style="opacity:.7;font-size:.85rem;margin-top:.6rem">' . $e(self::t('Something you don\'t recognise? Stop it and choose a new password below.')) . '</p>'
            : '<p style="opacity:.8">' . $e($tracearr ? self::t('Nothing is playing on your users right now.') : self::t('This isn\'t available on this server.')) . '</p>');

        $list = '';
        foreach (array_slice($devices, 0, 30) as $d) {
            $list .= '<div style="' . $line . '"><div><div style="font-weight:600">' . $e($d['device']) . '</div><div style="opacity:.7;font-size:.9rem">' . $e(trim($d['player'] . ' · ' . $d['server'], ' ·'))
                . (!empty($d['profiles']) ? ' · ' . $e(self::t('profiles: :list', ['list' => implode(', ', array_keys($d['profiles']))])) : '') . '</div></div>'
                . '<div style="text-align:right;opacity:.8;font-size:.9rem">' . $e(date(self::t('M j, H:i'), $d['last'])) . '<br>' . $e($d['plays'] === 1 ? self::t('1 play') : self::t(':count plays', ['count' => $d['plays']])) . '</div></div>';
        }
        $html .= $card(self::t('Devices, last 30 days'), $list !== '' ? $list : '<p style="opacity:.8">' . $e(self::t('No plays in the last 30 days.')) . '</p>');

        $pw = '';
        foreach ($services as $service) {
            $pw .= '<div style="' . $line . '"><div style="font-weight:600">' . $e($service->product->name ?? '') . '</div><form method="post">' . csrf_field()
                . '<input type="hidden" name="password" value="' . (int) $service->id . '"><button type="submit" class="bg-primary text-white font-semibold rounded-lg px-3 py-1">' . $e(self::t('Choose a new password')) . '</button></form></div>';
        }
        $html .= $card(self::t('Password'), '<p style="opacity:.8;margin-bottom:.4rem">' . $e(self::t('Opens a page where you choose a new password for your user (Jellyfin and Emby). Devices then need to log in again. On Plex, change the password of your Plex account.')) . '</p>'
            . ($pw !== '' ? $pw : '<p style="opacity:.8">' . $e(self::t('No subscription yet.')) . '</p>'));

        return view('layouts.app', ['slot' => new HtmlString($this->pageFrame(self::t('My devices'), $html, $notice, $error)), 'title' => self::t('My devices')]);
    }
}
