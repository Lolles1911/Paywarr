<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Models\Plan;
use App\Models\Service;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Price changes with notice (Paywarr → Price changes): the plan's price
 * changes at once for new customers; customers on it are told by mail and
 * pay the new price from a date at least 30 days away (their invoices made
 * from then on). Part of Wizarr (Paywarr).
 */
trait PriceChanges
{
    public const PRICE_NOTICE_DAYS = 30;

    /** [id => ['id', 'product', 'plan', 'currency', 'old', 'new', 'date', 'created', 'told', 'applied', 'cancelled']]. */
    public static function priceChanges(): array
    {
        return self::storeGet('price_changes', []);
    }

    /** The plan's price in a currency (null: none). */
    public static function planPrice(Plan $plan, string $currency): ?float
    {
        $row = $plan->prices()->where('currency_code', $currency)->first();

        return $row ? (float) $row->price : null;
    }

    /**
     * Plans a change: the plan's price now, a mail to everyone on it, the new
     * price for them from $date. Returns the change (with how many were told).
     */
    public function planPriceChange(Plan $plan, string $currency, float $new, int $date, ?int $now = null): array
    {
        $now ??= time();
        if ($date < $now + self::PRICE_NOTICE_DAYS * 86400 - 3600) {
            throw new \App\Exceptions\DisplayException('The date must be at least ' . self::PRICE_NOTICE_DAYS . ' days away, so customers are told in time.');
        }
        $old = self::planPrice($plan, $currency);
        if ($old === null) {
            throw new \App\Exceptions\DisplayException('The plan has no price in ' . $currency . '.');
        }
        if (abs($old - $new) < 0.005) {
            throw new \App\Exceptions\DisplayException('That is the price already.');
        }
        $plan->prices()->where('currency_code', $currency)->update(['price' => $new]);
        $change = ['id' => bin2hex(random_bytes(4)), 'product' => (int) $plan->priceable_id, 'plan' => (int) $plan->id, 'currency' => $currency, 'old' => $old, 'new' => $new,
            'date' => $date, 'created' => $now, 'told' => 0, 'applied' => null, 'cancelled' => null];
        foreach (Service::where('plan_id', $plan->id)->where('currency_code', $currency)->whereIn('status', ['active', 'suspended'])->with(['product', 'user'])->get() as $service) {
            try {
                if ($this->forServiceServer($service)->notifyMail($service, 'price_change', ['product' => (string) ($service->product->name ?? ''),
                    'old' => number_format((float) $service->price, 2, ',', '.') . ' ' . $currency, 'new' => number_format($new, 2, ',', '.') . ' ' . $currency,
                    'date' => date(self::t('M j, Y'), $date)], $this->accountUrl(), discord: false)) {
                    $change['told']++;
                }
            } catch (Throwable $e) {
                Log::warning('Wizarr: price change not told to service ' . $service->id . ': ' . $e->getMessage());
            }
        }
        $all = self::priceChanges();
        $all[$change['id']] = $change;
        self::storePut('price_changes', $all);

        return $change;
    }

    /** Before the date: back to the old price for new customers, nothing changes for anyone. */
    public static function cancelPriceChange(string $id): void
    {
        $all = self::priceChanges();
        $change = $all[$id] ?? null;
        if (!$change || $change['applied'] || $change['cancelled']) {
            return;
        }
        Plan::find($change['plan'])?->prices()->where('currency_code', $change['currency'])->update(['price' => $change['old']]);
        $all[$id]['cancelled'] = time();
        self::storePut('price_changes', $all);
    }

    /** Hourly: changes whose date has come; the subscriptions on the plan get the new price (with their discounts). */
    public function applyPriceChanges(?int $now = null): array
    {
        $now ??= time();
        $all = self::priceChanges();
        $done = [];
        foreach ($all as $id => $change) {
            if ($change['applied'] || $change['cancelled'] || $change['date'] > $now) {
                continue;
            }
            $count = 0;
            foreach (Service::where('plan_id', $change['plan'])->where('currency_code', $change['currency'])->whereIn('status', ['active', 'suspended'])->with('plan')->get() as $service) {
                try {
                    $price = $service->calculatePrice();
                    if ((string) $service->price !== (string) $price) {
                        $service->price = $price;
                        $service->save();
                        self::logAdmin($service->user, (int) $service->id, 'New price from the price change: ' . $price . ' ' . $change['currency'], 'Paywarr');
                    }
                    $count++;
                } catch (Throwable $e) {
                    Log::warning('Wizarr: new price not set on service ' . $service->id . ': ' . $e->getMessage());
                }
            }
            $all[$id]['applied'] = $now;
            $all[$id]['services'] = $count;
            $done[] = $id;
        }
        if ($done) {
            self::storePut('price_changes', $all);
        }

        return $done;
    }
}
