<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "New episodes of your shows": a customer gets the new episodes of the
 * shows they watched in the last 60 days, once a day (or right away, or once
 * a week: "New episodes of their shows" under Settings → Reminders). The show
 * is matched by its id on the media server, from Tracearr. Part of Wizarr
 * (Paywarr).
 */
trait ShowAlerts
{
    /** Whether to send now: the mode and the last time it was sent. */
    public static function showAlertsDue(string $mode, ?int $last, int $now): bool
    {
        return match ($mode) {
            'instant' => true,
            'daily' => (int) date('G', $now) >= 17 && ($last === null || date('Y-m-d', $last) !== date('Y-m-d', $now)),
            'weekly' => (int) date('N', $now) === 7 && (int) date('G', $now) >= 17 && ($last === null || date('o-W', $last) !== date('o-W', $now)),
            default => false,
        };
    }

    /**
     * The new episodes per customer: [user ID => [show title => [episode titles]]],
     * for shows (server type + show id) their accounts watched.
     */
    public static function newEpisodesFor(array $episodes, array $plays, array $accountOwners): array
    {
        $watching = [];
        foreach ($plays as $p) {
            $show = (string) ($p['grandparent_rating_key'] ?? '');
            $account = strtolower((string) ($p['server_type'] ?? '')) . '|' . strtolower((string) ($p['user']['username'] ?? ''));
            if (($p['media_type'] ?? '') !== 'episode' || $show === '' || !isset($accountOwners[$account])) {
                continue;
            }
            $watching[strtolower((string) $p['server_type']) . '|' . $show][$accountOwners[$account]] = (string) ($p['show_title'] ?? '');
        }
        $out = [];
        foreach ($episodes as $ep) {
            $key = strtolower((string) ($ep['server_type'] ?? '')) . '|' . (string) ($ep['grandparent_rating_key'] ?? '');
            foreach ($watching[$key] ?? [] as $userId => $showTitle) {
                $out[$userId][$showTitle ?: '?'][] = (string) ($ep['title'] ?? '');
            }
        }
        foreach ($out as &$shows) {
            foreach ($shows as &$titles) {
                $titles = array_values(array_unique($titles));
            }
            ksort($shows);
        }

        return $out;
    }

    /** "Severance: Hello, Ms. Cobel, Goodbye, Mrs. Selvig" per line. */
    public static function episodeLines(array $shows, int $max = 15): string
    {
        $lines = [];
        foreach ($shows as $show => $titles) {
            $lines[] = '• ' . $show . ': ' . (count($titles) > 3 ? self::t(':n new episodes', ['n' => count($titles)]) : implode(', ', $titles));
        }

        return implode("\n", array_slice($lines, 0, $max));
    }

    /** Hourly: new episodes since the last mail, to the customers watching those shows. */
    public function runShowAlerts(?int $now = null): array
    {
        $now ??= time();
        $mode = (string) ($this->config('new_episodes') ?? 'daily') ?: 'daily';
        $tracearr = $this->tracearrOrAny();
        $last = self::storeGet('show_alerts_last');
        if ($tracearr === null || $mode === 'off' || self::mailIsOff('new_episodes')) {
            return [];
        }
        if ($last === null) {
            self::storePut('show_alerts_last', $now); // from now on; nothing from before

            return [];
        }
        if (!self::showAlertsDue($mode, (int) $last, $now)) {
            return [];
        }
        try {
            $episodes = $tracearr->recentlyAddedEpisodes((int) $last);
            $plays = $episodes ? $tracearr->history($now - 60 * 86400, 20000) : [];
        } catch (Throwable $e) {
            Log::info('Wizarr: new episodes not checked: ' . $e->getMessage());

            return [];
        }
        self::storePut('show_alerts_last', $now);
        if (!$episodes) {
            return [];
        }
        $owners = [];
        $serviceOf = [];
        foreach (Service::where('status', 'active')->whereHas('product.server', fn ($q) => $q->where('extension', 'Wizarr'))
            ->with(['product.server', 'properties', 'user'])->get() as $service) {
            foreach ($this->streamAccounts(ExtensionHelper::getServiceProperties($service)) as $account) {
                $owners[$account] = (int) $service->user_id;
                $serviceOf[(int) $service->user_id] ??= $service;
            }
        }
        $sent = [];
        foreach (self::newEpisodesFor($episodes, $plays, $owners) as $userId => $shows) {
            $service = $serviceOf[$userId] ?? null;
            if (!$service) {
                continue;
            }
            $count = array_sum(array_map('count', $shows));
            try {
                if ($this->forServiceServer($service)->notifyMail($service, 'new_episodes', ['name' => trim((string) ($service->user->first_name ?? '')),
                    '_count' => $count, 'list' => self::episodeLines($shows)], $this->hubUrl())) {
                    $sent[] = $userId;
                }
            } catch (Throwable $e) {
                Log::warning('Wizarr: new episodes mail to user ' . $userId . ' not sent: ' . $e->getMessage());
            }
        }

        return $sent;
    }
}
