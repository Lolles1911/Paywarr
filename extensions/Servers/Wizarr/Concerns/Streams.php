<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Servers\Wizarr\TracearrClient;
use Throwable;

/**
 * Tracearr in Paywarr: "Max streams at once" on a product (every minute:
 * the newest streams over the limit are stopped, with a message on the
 * screen, and the customer is warned), and Tracearr's own rule breaks (e.g.
 * one account streaming from two places) on the customer card and the
 * Sharing page. Plex, Jellyfin and Emby; Silo is not in Tracearr. Part of
 * Wizarr (Paywarr).
 */
trait Streams
{
    /** Service: the last times it went over its limit (JSON, newest first). */
    public const PROP_STREAMS_OVER = 'streams_over';

    /** Service: when the customer was last warned. */
    private const PROP_STREAMS_WARNED = 'streams_warned_at';

    private ?TracearrClient $tracearr = null;

    public function tracearrConfigured(): bool
    {
        return trim((string) ($this->config('tracearr_url') ?? '')) !== '' && trim((string) ($this->config('tracearr_api_key') ?? '')) !== '';
    }

    public function tracearr(): TracearrClient
    {
        return $this->tracearr ??= new TracearrClient((string) $this->config('tracearr_url'), (string) $this->config('tracearr_api_key'));
    }

    /** This server's Tracearr, or the first Wizarr server's that has one; null when none has. */
    public function tracearrOrAny(): ?TracearrClient
    {
        if ($this->tracearrConfigured()) {
            return $this->tracearr();
        }
        try {
            foreach (\App\Models\Server::where('extension', 'Wizarr')->with('settings')->get() as $server) {
                $ext = new static(self::serverSettings($server));
                if ($ext->tracearrConfigured()) {
                    return $ext->tracearr();
                }
            }
        } catch (Throwable $e) {
            // None then.
        }

        return null;
    }

    /**
     * Whether Tracearr watches a Silo server too (Tracearr 3.3 and newer):
     * then Silo's plays, streams and users come from Tracearr like the others',
     * and Paywarr stops asking Silo itself. Kept an hour.
     */
    public function tracearrHasSilo(): bool
    {
        try {
            return (bool) Cache::remember('paywarr:tracearr:silo', 3600, function () {
                $tracearr = $this->tracearrOrAny();
                if ($tracearr === null) {
                    return 0;
                }
                foreach ($tracearr->servers() as $server) {
                    if (strtolower((string) ($server['server_type'] ?? $server['type'] ?? '')) === 'silo') {
                        return 1;
                    }
                }

                return 0;
            });
        } catch (Throwable $e) {
            return false;
        }
    }

    /** The media server accounts of a subscription, as "type|username" (lowercase); Silo only when Tracearr watches it. */
    public function streamAccounts(array $properties, ?bool $withSilo = null): array
    {
        $withSilo ??= $this->tracearrHasSilo();
        $keys = [];
        foreach ($properties as $key => $value) {
            if ($key === self::PROP_USERS || str_starts_with((string) $key, self::PROP_USERS . ':')) {
                foreach ($this->parseUsers((string) $value) as $user) {
                    if ($withSilo || strtolower($user['type']) !== 'silo') {
                        $keys[] = strtolower($user['type']) . '|' . strtolower($user['username']);
                    }
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Every minute: subscriptions on products with "max_streams" that stream
     * more at once. The newest over the limit are stopped (or, with
     * "streams_over_limit" = warn, left), the customer is told at most once
     * a day, and it is noted on the subscription.
     *
     * @return array<int, int> service ID => streams over the limit
     */
    public function enforceStreamLimits(?array $streams = null, ?iterable $services = null, ?int $now = null, ?TracearrClient $tracearr = null): array
    {
        $now ??= time();
        $tracearr ??= $this->tracearrOrAny();
        if ($tracearr === null) {
            return [];
        }
        $services ??= Service::where('status', 'active')
            ->whereHas('product.server', fn ($query) => $query->where('extension', 'Wizarr'))
            ->whereHas('product.settings', fn ($query) => $query->where('key', 'max_streams')->where('value', '!=', ''))
            ->with(['product.settings', 'product.server', 'properties', 'user'])->get();
        $limited = [];
        foreach ($services as $service) {
            $settings = ExtensionHelper::settingsToArray($service->product->settings);
            $max = (int) ($settings['max_streams'] ?? 0);
            if ($max > 0) {
                $accounts = $this->streamAccounts(ExtensionHelper::getServiceProperties($service));
                // Extra users each have the limit, unless they share it.
                $share = count($accounts) < 2 || $this->truthy($settings['extra_users_share_streams'] ?? false);
                foreach ($accounts as $account) {
                    $limited[$account] = [$service, $max, $share ? (string) $service->id : $service->id . '|' . $account];
                }
            }
        }
        if (!$limited) {
            return [];
        }
        try {
            $streams ??= $tracearr->streams();
        } catch (Throwable $e) {
            Log::info('Wizarr: stream limits not checked, Tracearr: ' . $e->getMessage());

            return [];
        }
        $byService = [];
        foreach ($streams as $stream) {
            $account = strtolower((string) ($stream['server_type'] ?? '')) . '|' . strtolower((string) ($stream['username'] ?? ''));
            if (isset($limited[$account])) {
                [$service, $max, $group] = $limited[$account];
                $byService[$group] ??= ['service' => $service, 'max' => $max, 'streams' => []];
                $byService[$group]['streams'][] = $stream;
            }
        }
        $over = [];
        foreach ($byService as $id => $group) {
            if (count($group['streams']) <= $group['max']) {
                continue;
            }
            try {
                $over[(int) $group['service']->id] = ($over[(int) $group['service']->id] ?? 0) + $this->forServiceServer($group['service'])->handleOverLimit($group['service'], $group['streams'], $group['max'], $now, $tracearr);
            } catch (Throwable $e) {
                Log::warning('Wizarr: service ' . $id . ' is over its stream limit, not handled: ' . $e->getMessage());
            }
        }

        return $over;
    }

    private function handleOverLimit(Service $service, array $streams, int $max, int $now, TracearrClient $tracearr): int
    {
        usort($streams, fn ($a, $b) => strcmp((string) ($b['started_at'] ?? ''), (string) ($a['started_at'] ?? '')));
        $extra = array_slice($streams, 0, count($streams) - $max);
        $stop = ($this->config('streams_over_limit') ?: 'stop') === 'stop';
        $reason = self::t('Your subscription allows :count stream(s) at once. This one was stopped because more were playing.', ['count' => $max]);
        $stopped = [];
        foreach ($extra as $stream) {
            $what = trim((string) ($stream['show_title'] ?? '') !== '' ? $stream['show_title'] . ' · ' . ($stream['media_title'] ?? '') : (string) ($stream['media_title'] ?? ''));
            $where = trim((string) ($stream['device'] ?? '') . ' · ' . (string) ($stream['product'] ?? $stream['player'] ?? ''), ' ·');
            if ($stop) {
                try {
                    $tracearr->terminate((string) $stream['id'], $reason);
                } catch (Throwable $e) {
                    Log::warning('Wizarr: stream ' . ($stream['id'] ?? '?') . ' of service ' . $service->id . ' not stopped: ' . $e->getMessage());

                    continue;
                }
            }
            $stopped[] = $what . ($where !== '' ? ' (' . $where . ')' : '');
        }
        $properties = ExtensionHelper::getServiceProperties($service);
        $log = json_decode((string) ($properties[self::PROP_STREAMS_OVER] ?? '[]'), true) ?: [];
        array_unshift($log, ['at' => $now, 'streams' => count($streams), 'max' => $max, 'stopped' => $stop, 'which' => $stopped]);
        $this->setProperty($service, self::PROP_STREAMS_OVER, 'Over the stream limit', json_encode(array_slice($log, 0, 20)));
        self::logAdmin($service->user, (int) $service->id, count($streams) . ' streams at once (max ' . $max . ')' . ($stop && $stopped ? ', stopped: ' . implode('; ', $stopped) : ''), 'Tracearr');
        Cache::forget('paywarr:admin:sharing');

        if ((int) ($properties[self::PROP_STREAMS_WARNED] ?? 0) < $now - 86400) {
            $this->setProperty($service, self::PROP_STREAMS_WARNED, 'Warned about streams', (string) $now);
            $this->notifyMail($service, 'streams', ['product' => (string) ($service->product->name ?? ''), 'count' => $max, 'n' => count($streams), '_stopped' => $stop ? '1' : ''], $this->shopUrl());
        }

        return count($streams) - $max;
    }

    /**
     * Tracearr's rule breaks of the last $days days, each with the
     * subscription whose user it was (or null): rule, severity, at, username,
     * server, service. Kept 5 minutes.
     */
    public function sharingRows(iterable $services, int $days = 30, ?TracearrClient $tracearr = null): array
    {
        $tracearr ??= $this->tracearrOrAny();
        if ($tracearr === null) {
            return [];
        }
        $violations = Cache::remember('paywarr:tracearr:violations:' . $days, 300, function () use ($days, $tracearr) {
            try {
                return $tracearr->violations(time() - $days * 86400);
            } catch (Throwable $e) {
                Log::info('Wizarr: Tracearr\'s violations could not be read: ' . $e->getMessage());

                return [];
            }
        });
        $owners = [];
        foreach ($services as $service) {
            foreach ($this->streamAccounts(ExtensionHelper::getServiceProperties($service)) as $account) {
                if (!isset($owners[$account]) || $service->status === 'active') {
                    $owners[$account] = $service;
                }
            }
        }
        $rows = [];
        foreach ($violations as $violation) {
            $account = strtolower((string) ($violation['server']['type'] ?? '')) . '|' . strtolower((string) ($violation['user']['username'] ?? ''));
            $rows[] = [
                'id' => (string) ($violation['id'] ?? ''),
                'rule' => (string) ($violation['rule']['name'] ?? 'Rule'),
                'severity' => (string) ($violation['severity'] ?? 'warning'),
                'at' => strtotime((string) ($violation['created_at'] ?? '')) ?: null,
                'username' => (string) ($violation['user']['username'] ?? ''),
                'server' => (string) ($violation['server']['name'] ?? ''),
                'service' => $owners[$account] ?? null,
            ];
        }

        return $rows;
    }
}
