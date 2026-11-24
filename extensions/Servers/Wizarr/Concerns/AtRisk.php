<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Customers at risk (Paywarr → At risk): paying customers who haven't watched
 * for a while, soonest renewal first, so you can reach them before they
 * cancel. The automatic "we miss you" mail is Paywarr Stats' reminder; it
 * goes out as Paywarr's "Watched nothing for a while" mail, with what is new.
 * Part of Wizarr (Paywarr).
 */
trait AtRisk
{
    /** Last play per "type|username" in the plays given. */
    public static function lastPlayPer(array $history): array
    {
        $last = [];
        foreach ($history as $row) {
            $account = strtolower((string) ($row['server_type'] ?? '')) . '|' . strtolower((string) ($row['user']['username'] ?? ''));
            $at = strtotime((string) ($row['started_at'] ?? '')) ?: 0;
            $last[$account] = max($last[$account] ?? 0, $at);
        }

        return $last;
    }

    /**
     * Paying subscriptions with when they last watched: ['service', 'last'
     * (timestamp, 0 = not in the period, null = can't tell), 'idle' days].
     */
    public function atRiskRows(iterable $services, array $lastPlays, int $since, ?int $now = null): array
    {
        $now ??= time();
        $rows = [];
        foreach ($services as $service) {
            $settings = ExtensionHelper::settingsToArray($service->product->settings ?? []);
            if ((float) $service->price <= 0 || (int) ($settings['trial_days'] ?? 0) > 0 || (int) ($settings['gift_months'] ?? 0) > 0 || (int) ($settings['extra_requests'] ?? 0) > 0) {
                continue;
            }
            $accounts = $this->streamAccounts(ExtensionHelper::getServiceProperties($service));
            if (!$accounts) {
                continue; // no user (yet), or only Silo
            }
            $last = 0;
            foreach ($accounts as $account) {
                $last = max($last, $lastPlays[$account] ?? 0);
            }
            $from = $last ?: max($since, $service->created_at ? (int) strtotime((string) $service->created_at) : $since);
            $rows[(int) $service->id] = ['service' => $service, 'last' => $last, 'idle' => (int) floor(($now - $from) / 86400),
                'renews' => $service->expires_at ? (int) strtotime((string) $service->expires_at) : null];
        }

        return $rows;
    }

    /** The paying subscriptions and their last play in 60 days (Tracearr; kept 10 minutes). */
    public function atRiskNow(): array
    {
        $tracearr = $this->tracearrOrAny();
        $since = time() - 60 * 86400;
        $last = $tracearr ? Cache::remember('paywarr:risk:last', 600, function () use ($tracearr, $since) {
            try {
                return self::lastPlayPer($tracearr->history($since, 20000));
            } catch (Throwable $e) {
                return [];
            }
        }) : [];
        $services = Service::where('status', 'active')->whereHas('product.server', fn ($q) => $q->where('extension', 'Wizarr'))
            ->with(['product.settings', 'product.server', 'properties', 'user'])->get();

        return $this->atRiskRows($services, $last, $since);
    }

    /** "We miss you" with a few new titles; Paywarr Stats' reminder sends this when it can. */
    public function remindInactiveMail(Service $service, int $days): array
    {
        $titles = [];
        try {
            foreach ($this->shelves()['new'] ?? [] as $item) {
                $titles[$item['title']] = true;
                if (count($titles) >= 5) {
                    break;
                }
            }
        } catch (Throwable $e) {
            // Without titles.
        }

        return $this->forServiceServer($service)->notifyMail($service, 'inactive', ['name' => trim((string) ($service->user->first_name ?? '')), 'product' => (string) ($service->product->name ?? ''),
            'days' => $days, 'titles' => implode(', ', array_keys($titles))], $this->hubUrl());
    }
}
