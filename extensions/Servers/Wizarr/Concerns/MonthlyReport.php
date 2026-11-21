<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A report per month (Paywarr → Monthly reports): money, subscriptions,
 * trials, what was watched, streams and sharing, and what Paywarr did for
 * customers. Made on the 1st for the month before and kept two years; the
 * current month can be made at any time. Part of Wizarr (Paywarr).
 */
trait MonthlyReport
{
    /** Hourly: on the 1st, the month before is made (once). */
    public function makeMonthlyReportIfDue(?int $now = null): ?string
    {
        $now ??= time();
        $previous = date('Y-m', strtotime(date('Y-m-01', $now) . ' -1 month'));
        $reports = self::storeGet('monthly_reports', []);
        if (isset($reports[$previous])) {
            return null;
        }
        try {
            $this->saveMonthlyReport($this->monthlyReport($previous, $now));
        } catch (Throwable $e) {
            Log::warning('Wizarr: monthly report for ' . $previous . ' not made, will retry: ' . $e->getMessage());

            return null;
        }

        return $previous;
    }

    public function saveMonthlyReport(array $report): void
    {
        $reports = self::storeGet('monthly_reports', []);
        $reports[$report['month']] = $report;
        krsort($reports);
        self::storePut('monthly_reports', array_slice($reports, 0, 24, true));
    }

    /**
     * The report for $month ("2026-11"). $services, $invoices and $plays are
     * for tests: Paymenter's and Tracearr's when null.
     */
    public function monthlyReport(string $month, ?int $now = null, ?iterable $services = null, ?iterable $invoices = null, ?array $plays = null, ?array $violations = null): array
    {
        $now ??= time();
        $from = strtotime($month . '-01 00:00:00');
        $to = min($now, strtotime(date('Y-m-01', $from) . ' +1 month'));
        $in = fn (?int $at) => $at !== null && $at >= $from && $at < $to;
        $services ??= Service::whereHas('product.server', fn ($query) => $query->where('extension', 'Wizarr'))
            ->with(['product.settings', 'product.server', 'properties', 'user', 'cancellation'])->get();

        $subs = ['new' => 0, 'trials' => 0, 'converted' => 0, 'cancelled' => 0, 'paying' => 0, 'suspended' => 0, 'gifts' => 0];
        $streams = ['over' => 0, 'stopped' => 0];
        $byProduct = [];
        $serviceIds = [];
        $users = [];
        foreach ($services as $service) {
            $settings = ExtensionHelper::settingsToArray($service->product->settings ?? []);
            $properties = ExtensionHelper::getServiceProperties($service);
            $serviceIds[(int) $service->id] = true;
            $users[$service->user_id] = $service->user;
            $created = $this->timestampOf($service->created_at ?? null);
            if ($this->isGift($settings)) {
                $subs['gifts'] += $in($created) ? 1 : 0;

                continue;
            }
            if ($this->isTopUp($settings)) {
                continue;
            }
            $trial = $this->isTrial($settings);
            if ($in($created)) {
                $trial ? $subs['trials']++ : $subs['new']++;
                if (!$trial) {
                    $byProduct[(string) ($service->product->name ?? '?')] = ($byProduct[(string) ($service->product->name ?? '?')] ?? 0) + 1;
                }
            }
            if ($trial && !empty($properties[self::PROP_TRIAL_CONVERTED]) && $in($this->timestampOf($service->updated_at ?? null))) {
                $subs['converted']++;
            }
            if ($service->status === 'cancelled' && $in($this->timestampOf($service->cancellation->created_at ?? $service->updated_at ?? null))) {
                $subs['cancelled']++;
            }
            if (!$trial && $service->status === 'active') {
                $subs['paying']++;
            }
            if ($service->status === 'suspended') {
                $subs['suspended']++;
            }
            foreach (json_decode((string) ($properties[self::PROP_STREAMS_OVER] ?? '[]'), true) ?: [] as $event) {
                if ($in((int) $event['at'])) {
                    $streams['over']++;
                    $streams['stopped'] += !empty($event['stopped']) ? count($event['which'] ?? []) : 0;
                }
            }
        }
        arsort($byProduct);

        // Money: paid invoices of the month, the lines for Paywarr subscriptions.
        $money = [];
        if ($invoices === null && class_exists(\App\Models\Invoice::class)) {
            try {
                $invoices = \App\Models\Invoice::with('items')->where('status', 'paid')
                    ->whereBetween('updated_at', [date('Y-m-d H:i:s', $from), date('Y-m-d H:i:s', $to)])->get();
            } catch (Throwable $e) {
                $invoices = [];
            }
        }
        foreach ($invoices ?? [] as $invoice) {
            foreach ($invoice->items ?? [] as $item) {
                if (($item->reference_type ?? null) === Service::class && isset($serviceIds[(int) $item->reference_id])) {
                    $currency = (string) ($invoice->currency_code ?? '');
                    $money[$currency] = round(($money[$currency] ?? 0) + (float) $item->price * max(1, (int) ($item->quantity ?? 1)), 2);
                }
            }
        }

        // What Paywarr did for customers.
        $care = ['pauses' => 0, 'winbacks' => 0, 'referrals' => 0, 'anniversaries' => 0];
        foreach ($users as $user) {
            if (!$user || !method_exists($user, 'properties')) {
                continue;
            }
            foreach (json_decode((string) ($this->propertyValue($user, self::PROP_SELF_PAUSES) ?? '[]'), true) ?: [] as $at) {
                $care['pauses'] += $in((int) $at) ? 1 : 0;
            }
            $care['winbacks'] += $in((int) ($this->propertyValue($user, self::PROP_WINBACK_AT) ?? 0)) ? 1 : 0;
            foreach (json_decode((string) ($this->propertyValue($user, self::PROP_LOG) ?? '[]'), true) ?: [] as $entry) {
                if ($in((int) ($entry['at'] ?? 0))) {
                    $care['referrals'] += str_starts_with((string) $entry['what'], 'A friend joined') ? 1 : 0;
                    $care['anniversaries'] += str_starts_with((string) $entry['what'], 'Anniversary') ? 1 : 0;
                }
            }
        }

        // Watching, from Tracearr.
        $watching = null;
        $tracearr = $plays === null ? $this->tracearrOrAny() : null;
        if ($plays === null && $tracearr !== null) {
            try {
                $plays = array_values(array_filter($tracearr->history($from, 20000), fn ($p) => (strtotime((string) ($p['started_at'] ?? '')) ?: 0) < $to));
                $violations ??= array_values(array_filter($tracearr->violations($from), fn ($v) => (strtotime((string) ($v['created_at'] ?? '')) ?: 0) < $to));
            } catch (Throwable $e) {
                $plays = null;
            }
        }
        if ($plays !== null) {
            $counted = array_values(array_filter($plays, fn ($p) => (int) ($p['duration_ms'] ?? 0) >= 120000 || !empty($p['watched'])));
            $titles = [];
            foreach ($counted as $p) {
                $title = (string) (($p['media_type'] ?? '') === 'episode' ? ($p['show_title'] ?? $p['media_title'] ?? '') : ($p['media_title'] ?? ''));
                $titles[$title] = ($titles[$title] ?? 0) + 1;
            }
            arsort($titles);
            $watching = [
                'plays' => count($counted),
                'hours' => (int) round(array_sum(array_map(fn ($p) => (int) ($p['duration_ms'] ?? 0), $counted)) / 3600000),
                'viewers' => count(array_unique(array_map(fn ($p) => strtolower(($p['server_type'] ?? '') . '|' . ($p['user']['username'] ?? '')), $counted))),
                'top' => array_slice($titles, 0, 5, true),
            ];
        }
        $streams['rule_breaks'] = $violations === null ? null : count($violations);

        return [
            'month' => $month,
            'made' => $now,
            'partial' => $to < strtotime(date('Y-m-01', $from) . ' +1 month'),
            'money' => $money,
            'subscriptions' => $subs,
            'new_by_product' => $byProduct,
            'care' => $care,
            'watching' => $watching,
            'streams' => $streams,
        ];
    }
}
