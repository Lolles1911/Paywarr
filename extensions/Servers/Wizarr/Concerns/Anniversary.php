<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A thank-you on a customer's anniversary: every "anniversary_months" months
 * as a paying customer, free days on their subscription, a discount code or
 * just a thank-you, by mail and Discord. Part of Wizarr (Paywarr).
 */
trait Anniversary
{
    /** User: how many anniversaries were celebrated. */
    public const PROP_ANNIVERSARY = 'paywarr_anniversary';

    /**
     * Daily (from the hourly run). Customers who were already past an
     * anniversary when it was switched on are not rewarded backwards.
     *
     * @return int[] the users thanked
     */
    public function sendAnniversaries(?iterable $services = null, ?int $now = null, ?callable $makeCoupon = null): array
    {
        if (($ext = $this->anyServer('anniversary_months')) !== $this) {
            return $ext->sendAnniversaries($services, $now, $makeCoupon);
        }
        $now ??= time();
        $every = (int) ($this->config('anniversary_months') ?? 0);
        if ($every < 1) {
            return [];
        }
        $services ??= Service::whereHas('product.server', fn ($query) => $query->where('extension', 'Wizarr'))
            ->with(['product.settings', 'product.server', 'user'])->get();
        $byUser = [];
        foreach ($services as $service) {
            $settings = ExtensionHelper::settingsToArray($service->product->settings);
            if (!$service->user || $this->isTrial($settings) || $this->isGift($settings) || $this->isTopUp($settings)) {
                continue;
            }
            $byUser[$service->user_id][] = $service;
        }
        $thanked = [];
        foreach ($byUser as $userId => $list) {
            $active = array_values(array_filter($list, fn ($s) => $s->status === 'active'));
            $created = array_filter(array_map(fn ($s) => $this->timestampOf($s->created_at ?? null), $list));
            if (!$active || !$created) {
                continue;
            }
            $since = min($created);
            $milestone = intdiv($this->monthsBetween($since, $now), $every);
            $user = $list[0]->user;
            $done = $this->propertyValue($user, self::PROP_ANNIVERSARY);
            if ($milestone < 1 || ($done !== null && (int) $done >= $milestone)) {
                continue;
            }
            $date = strtotime('+' . ($milestone * $every) . ' months', $since);
            if ($now - $date > 7 * 86400) {
                // Passed before this was on: noted, not rewarded backwards.
                $user->properties()->updateOrCreate(['key' => self::PROP_ANNIVERSARY], ['name' => 'Anniversaries celebrated', 'value' => (string) $milestone]);

                continue;
            }
            try {
                $this->celebrate($user, $active, $milestone * $every, $now, $makeCoupon);
                $user->properties()->updateOrCreate(['key' => self::PROP_ANNIVERSARY], ['name' => 'Anniversaries celebrated', 'value' => (string) $milestone]);
                $thanked[] = $userId;
            } catch (Throwable $e) {
                Log::warning('Wizarr: anniversary of user ' . $userId . ' not celebrated, will retry: ' . $e->getMessage());
            }
        }

        return $thanked;
    }

    private function celebrate($user, array $active, int $months, int $now, ?callable $makeCoupon): void
    {
        $reward = (string) ($this->config('anniversary_reward') ?: 'days');
        $amount = max(1, (int) ($this->config('anniversary_amount') ?: ($reward === 'coupon' ? 20 : 7)));
        $period = $months % 12 === 0 ? ($months === 12 ? self::t('a year') : self::t(':count years', ['count' => intdiv($months, 12)])) : self::t(':count months', ['count' => $months]);
        usort($active, fn ($a, $b) => (strtotime((string) $a->expires_at) ?: PHP_INT_MAX) <=> (strtotime((string) $b->expires_at) ?: PHP_INT_MAX));
        $service = $active[0];
        $gift = '';
        if ($reward === 'days' && $service->expires_at) {
            $service->expires_at = date('Y-m-d H:i:s', strtotime((string) $service->expires_at) + $amount * 86400);
            if (method_exists($service, 'save')) {
                $service->save();
            }
            $gift = self::t('As a thank-you we added :days days to :product, which now renews on :date.', ['days' => $amount, 'product' => (string) ($service->product->name ?? ''), 'date' => date(self::t('M j, Y'), strtotime((string) $service->expires_at))]);
        } elseif ($reward === 'coupon') {
            $code = 'THANKS-' . $this->giftChars(4) . '-' . $this->giftChars(4);
            ($makeCoupon ?? fn (array $coupon, array $products) => $this->makeCoupon($coupon, $products))([
                'code' => $code, 'type' => 'percentage', 'value' => min(100, $amount), 'applies_to' => 'price', 'recurring' => 1,
                'max_uses' => 1, 'max_uses_per_user' => 1, 'starts_at' => date('Y-m-d H:i:s', $now), 'expires_at' => date('Y-m-d H:i:s', $now + 60 * 86400),
            ], $this->giftableProductIds());
            $gift = self::t('As a thank-you, here is :percent% off a period: the code :code, until :date.', ['percent' => min(100, $amount), 'code' => $code, 'date' => date(self::t('M j, Y'), $now + 60 * 86400)]);
        }
        $name = trim((string) ($user->first_name ?? ''));
        $this->notifyCustomer($service, self::t('Thank you for :period with us', ['period' => $period]),
            ($name !== '' ? self::t('Hi :name,', ['name' => $name]) . "\n\n" : '') . self::t('Today it is :period since you joined. Thank you for watching with us!', ['period' => $period]) . ($gift !== '' ? "\n\n" . $gift : ''),
            [self::t('See your subscription'), $this->accountUrl()], kind: 'stats');
        self::logAdmin($user, (int) $service->id, 'Anniversary (' . $months . ' months): ' . ($reward === 'days' ? $amount . ' days added' : ($reward === 'coupon' ? $amount . '% code' : 'a thank-you')), 'Paywarr');
    }

    private function monthsBetween(int $from, int $to): int
    {
        $months = ((int) date('Y', $to) - (int) date('Y', $from)) * 12 + (int) date('n', $to) - (int) date('n', $from);
        if ((int) date('j', $to) < (int) date('j', $from)) {
            $months--;
        }

        return max(0, $months);
    }

    private function timestampOf(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        return $value ? (strtotime((string) $value) ?: null) : null;
    }
}
