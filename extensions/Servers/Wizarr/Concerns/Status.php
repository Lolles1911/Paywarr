<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Service status for customers: a media server that goes down (Tracearr says
 * so twice in a row, or a Silo that doesn't answer) becomes a notice by
 * itself, and is posted in the announcements channel, down and up again; and
 * your own notices (planned maintenance), optionally mailed to the
 * customers on those servers. Notices show as a bar on top of the client
 * area for the customers they concern, and on the public /status page.
 * Part of Wizarr (Paywarr).
 */
trait Status
{
    /** Checks in a row before a server counts as down. */
    private const DOWN_AFTER = 2;

    /** All notices, newest first: id, title, text, kind (outage, maintenance, info), servers (Paymenter server IDs, [] = all), types (media server types), starts, ends, auto, key. */
    public static function notices(): array
    {
        return self::storeGet('status_notices', []);
    }

    private static function saveNotices(array $notices): void
    {
        usort($notices, fn ($a, $b) => $b['starts'] <=> $a['starts']);
        // A month of history is enough.
        $notices = array_values(array_filter($notices, fn ($n) => empty($n['ends']) || $n['ends'] > time() - 30 * 86400));
        self::storePut('status_notices', array_slice($notices, 0, 100));
        Cache::forget('paywarr:status:active');
    }

    /** The notices in force now. */
    public static function activeNotices(?int $now = null): array
    {
        $now ??= time();

        return array_values(array_filter(self::notices(), fn ($n) => $n['starts'] <= $now && (empty($n['ends']) || $n['ends'] > $now)));
    }

    /** Adds your own notice; posts it in the channel and mails the customers it concerns when asked. Returns it. */
    public function addNotice(string $title, string $text, string $kind, array $servers, int $starts, ?int $ends, bool $mail = false, bool $discord = false, string $by = ''): array
    {
        $notice = ['id' => bin2hex(random_bytes(6)), 'title' => $title, 'text' => $text, 'kind' => $kind, 'servers' => array_values(array_map('intval', $servers)),
            'types' => [], 'starts' => $starts, 'ends' => $ends, 'auto' => false, 'key' => '', 'by' => $by];
        $notices = self::notices();
        $notices[] = $notice;
        self::saveNotices($notices);
        if ($discord) {
            $this->announce('**' . $title . '**' . "\n" . $text . "\n" . $this->statusPageUrl());
        }
        if ($mail) {
            $to = $servers ? array_map(fn ($id) => 'server:' . (int) $id, $servers) : ['all'];
            $sent = [];
            foreach ($to as $target) {
                foreach ($this->broadcastRecipients($target) as $userId => $service) {
                    $sent[$userId] = $service;
                }
            }
            $html = $this->mailLayout($title, $this->paragraphs($text), [self::t('See the status'), $this->statusPageUrl()]);
            foreach ($sent as $service) {
                $this->sendMail((string) $service->user->email, $title, $html, (int) $service->user_id);
            }
        }

        return $notice;
    }

    public function endNotice(string $id, ?int $now = null): void
    {
        $notices = self::notices();
        foreach ($notices as &$notice) {
            if ($notice['id'] === $id && (empty($notice['ends']) || $notice['ends'] > ($now ?? time()))) {
                $notice['ends'] = $now ?? time();
            }
        }
        unset($notice);
        self::saveNotices($notices);
    }

    public function deleteNotice(string $id): void
    {
        self::saveNotices(array_values(array_filter(self::notices(), fn ($n) => $n['id'] !== $id)));
    }

    /**
     * Every minute: Tracearr's media servers and every Silo. A server down
     * twice in a row gets a notice (and a post); up again, the notice ends
     * (and a post). Returns the servers' state: key => name, type, up, since.
     */
    public function monitorStatus(?int $now = null, ?array $tracearrServers = null, ?array $siloUp = null): array
    {
        $now ??= time();
        $state = self::storeGet('status_servers', []);
        $seen = [];
        if ($tracearrServers === null && ($tracearr = $this->tracearrOrAny()) !== null) {
            try {
                $tracearrServers = $tracearr->servers();
            } catch (Throwable $e) {
                $tracearrServers = null; // Tracearr itself is away: nothing to say about the servers
            }
        }
        foreach ($tracearrServers ?? [] as $server) {
            if (!empty($server['historical']) || ($server['status'] ?? '') === 'unknown') {
                continue;
            }
            $seen['tracearr:' . $server['server_name']] = ['name' => (string) $server['server_name'], 'type' => strtolower((string) ($server['server_type'] ?? '')), 'up' => ($server['status'] ?? '') !== 'down', 'servers' => []];
        }
        if ($siloUp === null) {
            $siloUp = [];
            try {
                foreach (\App\Models\Server::where('extension', 'Wizarr')->with('settings')->get() as $server) {
                    $ext = new static(ExtensionHelper::settingsToArray($server->settings));
                    if ($ext->isSilo()) {
                        try {
                            $ext->client()->status();
                            $siloUp[$server->id] = [$server->name, true];
                        } catch (Throwable $e) {
                            $siloUp[$server->id] = [$server->name, false];
                        }
                    }
                }
            } catch (Throwable $e) {
                // No servers to read.
            }
        }
        foreach ($siloUp as $id => [$name, $up]) {
            $seen['silo:' . $id] = ['name' => (string) $name, 'type' => 'silo', 'up' => (bool) $up, 'servers' => [(int) $id]];
        }

        $notices = self::notices();
        $changed = false;
        foreach ($seen as $key => $server) {
            $before = $state[$key] ?? ['up' => true, 'down_checks' => 0, 'since' => $now];
            $downChecks = $server['up'] ? 0 : ($before['down_checks'] ?? 0) + 1;
            $wasUp = ($before['down_checks'] ?? 0) < self::DOWN_AFTER;
            $isUp = $downChecks < self::DOWN_AFTER;
            $state[$key] = $server + ['down_checks' => $downChecks, 'since' => $wasUp === $isUp ? ($before['since'] ?? $now) : $now, 'checked' => $now];
            $state[$key]['up'] = $isUp;
            if ($wasUp && !$isUp) {
                $notices[] = ['id' => bin2hex(random_bytes(6)), 'title' => self::t(':name is down', ['name' => $server['name']]), 'text' => self::t('We know, and we are working on it. This message goes away by itself when it is back.'),
                    'kind' => 'outage', 'servers' => $server['servers'], 'types' => $server['servers'] ? [] : [$server['type']], 'starts' => $now, 'ends' => null, 'auto' => true, 'key' => $key, 'by' => ''];
                $changed = true;
                $this->announce('🔴 ' . self::t(':name is down. We are working on it.', ['name' => $server['name']]) . ' ' . $this->statusPageUrl());
                Log::warning('Wizarr: ' . $server['name'] . ' is down; customers are told.');
            } elseif (!$wasUp && $isUp) {
                foreach ($notices as &$notice) {
                    if (!empty($notice['auto']) && $notice['key'] === $key && empty($notice['ends'])) {
                        $notice['ends'] = $now;
                        $changed = true;
                    }
                }
                unset($notice);
                $this->announce('🟢 ' . self::t(':name is back up.', ['name' => $server['name']]));
                Log::info('Wizarr: ' . $server['name'] . ' is back up.');
            }
        }
        self::storePut('status_servers', $state);
        $this->recordUptime($seen, $now);
        if ($changed) {
            self::saveNotices($notices);
        }

        return $state;
    }

    /** Counts each check per server per day (90 days kept): [checks, down]. */
    private function recordUptime(array $seen, int $now): void
    {
        if (!$seen) {
            return;
        }
        $uptime = self::storeGet('status_uptime', []);
        $day = date('Y-m-d', $now);
        $oldest = date('Y-m-d', $now - 90 * 86400);
        foreach ($seen as $key => $server) {
            $uptime[$key]['name'] = $server['name'];
            $uptime[$key]['days'][$day] = [($uptime[$key]['days'][$day][0] ?? 0) + 1, ($uptime[$key]['days'][$day][1] ?? 0) + ($server['up'] ? 0 : 1)];
            $uptime[$key]['days'] = array_filter($uptime[$key]['days'], fn ($d) => $d >= $oldest, ARRAY_FILTER_USE_KEY);
        }
        self::storePut('status_uptime', $uptime);
    }

    /**
     * Uptime per server over $days days: key => name, percent (null: no
     * checks), and per day (oldest first) the percent or null.
     */
    public static function uptime(int $days = 30, ?int $now = null): array
    {
        $now ??= time();
        $result = [];
        foreach (self::storeGet('status_uptime', []) as $key => $row) {
            $checks = 0;
            $down = 0;
            $perDay = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $d = $row['days'][date('Y-m-d', $now - $i * 86400)] ?? null;
                $perDay[] = $d ? round(100 * ($d[0] - $d[1]) / max(1, $d[0]), 2) : null;
                $checks += $d[0] ?? 0;
                $down += $d[1] ?? 0;
            }
            $result[$key] = ['name' => (string) ($row['name'] ?? $key), 'percent' => $checks ? round(100 * ($checks - $down) / $checks, 2) : null, 'days' => $perDay];
        }

        return $result;
    }

    /** Small day bars for uptime (green, orange, red; grey without checks). */
    public static function uptimeBars(array $days): string
    {
        $bars = '';
        foreach ($days as $percent) {
            $colour = $percent === null ? 'rgba(127,127,127,.25)' : ($percent >= 99.5 ? '#22c55e' : ($percent >= 95 ? '#f59e0b' : '#ef4444'));
            $bars .= '<span title="' . e($percent === null ? '—' : $percent . '%') . '" style="flex:1;height:1.6rem;border-radius:2px;background:' . $colour . '"></span>';
        }

        return '<div style="display:flex;gap:2px;margin-top:.4rem">' . $bars . '</div>';
    }

    /** The notices that concern this customer now (their servers and media server types), kept a minute. */
    public function noticesFor($user, ?int $now = null): array
    {
        $active = Cache::remember('paywarr:status:active', 60, fn () => self::activeNotices($now));
        if (!$active || !$user) {
            return [];
        }
        [$servers, $types] = Cache::remember('paywarr:status:who:' . $user->id, 300, fn () => $this->statusTargetsOf($user));

        return array_values(array_filter($active, fn ($n) => (!$n['servers'] && !$n['types'])
            || array_intersect($n['servers'], $servers) || array_intersect($n['types'], $types)));
    }

    /** The customer's Paymenter servers and media server types (also before their user is made). */
    private function statusTargetsOf($user): array
    {
        $servers = [];
        $types = [];
        foreach (Service::where('user_id', $user->id)->whereHas('product.server', fn ($q) => $q->where('extension', 'Wizarr'))->with(['product.server', 'properties'])->get() as $service) {
            if (!in_array($service->status, ['active', 'suspended'], true)) {
                continue;
            }
            $servers[] = (int) ($service->product->server->id ?? 0);
            foreach ($this->streamAccounts(ExtensionHelper::getServiceProperties($service)) as $account) {
                $types[] = explode('|', $account)[0];
            }
            if ((ExtensionHelper::settingsToArray($service->product->server->settings ?? [])['backend'] ?? '') === 'silo') {
                $types[] = 'silo';
            }
            // Invited, user not made yet: the type of each server it is invited to.
            try {
                $ext = $this->forServiceServer($service);
                foreach ($ext->grantKeys(ExtensionHelper::getServiceProperties($service)) as $grant) {
                    if ($grant !== '' && ($type = $ext->serverInfo()[(int) $grant]['type'] ?? '') !== '') {
                        $types[] = $type;
                    }
                }
            } catch (Throwable $e) {
                // Wizarr away: the users' types are enough.
            }
        }

        return [array_values(array_unique($servers)), array_values(array_unique($types))];
    }

    /** The bar on top of the client area (footer hook, as ['view' => …]; moved to the top by a line of script). */
    /** 🟢 all running, 🟠 maintenance, 🔴 a server the customer uses is down (the top bar's Open). */
    public function navStatusDot($user): string
    {
        try {
            $kinds = array_column($this->noticesFor($user), 'kind');
        } catch (Throwable $e) {
            return '🟢';
        }

        return in_array('outage', $kinds, true) ? '🔴' : (in_array('maintenance', $kinds, true) ? '🟠' : '🟢');
    }

    public function renderStatusBar(): array
    {
        if (!Auth::check() || !$this->hasSubscription(Auth::user())) {
            return [];
        }
        try {
            $notices = $this->noticesFor(Auth::user());
        } catch (Throwable $e) {
            return [];
        }
        if (!$notices) {
            return [];
        }
        $n = $notices[0];
        $colour = $n['kind'] === 'outage' ? '#b91c1c' : ($n['kind'] === 'maintenance' ? '#b45309' : '#6d28d9');
        $id = 'paywarr-status-' . $n['id'];

        return ['view' => new HtmlString('<div id="' . e($id) . '" style="background:' . $colour . ';color:#fff;padding:.7rem 2.6rem .7rem 1rem;margin:1rem auto;border-radius:.8rem;max-width:72rem;width:calc(100% - 2rem);box-sizing:border-box;font-size:.95rem;display:flex;gap:.8rem;align-items:center;justify-content:center;flex-wrap:wrap;position:relative">'
            . '<strong>' . e($n['title']) . '</strong><span style="opacity:.9">' . e(mb_substr($n['text'], 0, 200)) . '</span>'
            . '<a href="' . e($this->statusPageUrl()) . '" style="color:#fff;text-decoration:underline">' . e(self::t('Status')) . '</a>'
            . '<button type="button" aria-label="' . e(self::t('Close')) . '" style="position:absolute;right:.8rem;background:none;border:0;color:#fff;font-size:1.2rem;cursor:pointer" onclick="try{sessionStorage.setItem(\'' . e($id) . '\',1)}catch(e){};this.parentNode.remove()">×</button></div>'
            . '<script>(function(){var b=document.getElementById(' . json_encode($id) . ');if(!b)return;try{if(sessionStorage.getItem(' . json_encode($id) . ')){b.remove();return;}}catch(e){}var t=document.querySelector("main")||document.body;t.insertBefore(b,t.firstChild);})();</script>')];
    }

    /** GET /status: every media server, up or down, and the notices; for everyone. */
    public function statusPage()
    {
        $e = fn ($v) => e((string) $v);
        $state = self::storeGet('status_servers', []);
        $active = self::activeNotices();
        $html = '';
        foreach ($active as $n) {
            $html .= '<div style="padding:1rem 1.2rem;border-radius:.9rem;margin-bottom:.8rem;background:' . ($n['kind'] === 'outage' ? 'rgba(239,68,68,.14)' : 'rgba(245,158,11,.14)') . '">'
                . '<div style="font-weight:700">' . $e($n['title']) . '</div><div style="opacity:.85;white-space:pre-line">' . $e($n['text']) . '</div>'
                . '<div style="opacity:.6;font-size:.85rem;margin-top:.3rem">' . $e(self::t('Since :date', ['date' => date(self::t('M j, H:i'), $n['starts'])])) . ($n['ends'] ? ' · ' . $e(self::t('until :date', ['date' => date(self::t('M j, H:i'), $n['ends'])])) : '') . '</div></div>';
        }
        if (!$active) {
            $html .= '<p style="padding:1rem 1.2rem;border-radius:.9rem;background:rgba(34,197,94,.12);margin-bottom:.8rem">' . $e(self::t('Everything is running.')) . '</p>';
        }
        if ($state) {
            $uptime = self::uptime(30);
            $html .= '<div style="display:grid;gap:.5rem;margin:1.2rem 0">';
            foreach ($state as $key => $server) {
                $u = $uptime[$key] ?? null;
                $html .= '<div style="padding:.7rem 1rem;border-radius:.7rem" class="bg-background-secondary"><div style="display:flex;justify-content:space-between;gap:1rem">'
                    . '<span>' . $e($server['name']) . '</span><span><span style="opacity:.7;font-size:.9rem;margin-right:.8rem">' . ($u && $u['percent'] !== null ? $e(self::t(':percent% up, 30 days', ['percent' => $u['percent']])) : '') . '</span>'
                    . '<span style="font-weight:600;color:' . ($server['up'] ? '#22c55e' : '#ef4444') . '">' . $e($server['up'] ? self::t('Running') : self::t('Down')) . '</span></span></div>'
                    . ($u ? self::uptimeBars($u['days']) : '') . '</div>';
            }
            $html .= '</div>';
        }
        $past = array_filter(self::notices(), fn ($n) => !empty($n['ends']) && $n['ends'] <= time() && $n['ends'] > time() - 14 * 86400);
        if ($past) {
            $html .= '<h2 style="font-size:1.1rem;font-weight:700;margin:1.4rem 0 .6rem">' . $e(self::t('The last 14 days')) . '</h2>';
            foreach ($past as $n) {
                $html .= '<div style="padding:.5rem 0;border-top:1px solid rgba(127,127,127,.2)"><span style="font-weight:600">' . $e($n['title']) . '</span> <span style="opacity:.6;font-size:.9rem">· '
                    . $e(date(self::t('M j, H:i'), $n['starts']) . ' – ' . date(self::t('M j, H:i'), $n['ends'])) . '</span></div>';
            }
        }

        return view('layouts.app', ['slot' => new HtmlString($this->pageFrame(self::t('Service status'), $html)), 'title' => self::t('Service status')]);
    }

    private function statusPageUrl(): string
    {
        try {
            return route('extensions.wizarr.status-page');
        } catch (Throwable $e) {
            return url('/status');
        }
    }
}
