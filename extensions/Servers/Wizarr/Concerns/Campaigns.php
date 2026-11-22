<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Campaigns (Paywarr → Campaigns), e.g. Black Friday: a percentage off for a
 * while. New customers use a code (a Paymenter coupon, shown in a banner with
 * a countdown in the shop and the client area); existing customers get it
 * off their next renewal in the period. Announced by mail, Discord and the
 * announcements channel when it starts; ends by itself. Part of Wizarr.
 */
trait Campaigns
{
    public static function campaigns(): array
    {
        return self::storeGet('campaigns', []);
    }

    /** The campaign running at $now, or null. */
    public static function activeCampaign(?int $now = null, ?array $campaigns = null): ?array
    {
        $now ??= time();
        foreach ($campaigns ?? self::campaigns() as $campaign) {
            if (empty($campaign['stopped']) && $campaign['starts'] <= $now && $now < $campaign['ends']) {
                return $campaign;
            }
        }

        return null;
    }

    public static function campaignStatus(array $campaign, ?int $now = null): string
    {
        $now ??= time();

        return match (true) {
            !empty($campaign['stopped']) || $now >= $campaign['ends'] => 'ended',
            $now < $campaign['starts'] => 'planned',
            default => 'running',
        };
    }

    /** A code from the title: "Black Friday" → "BLACKFRIDAY25". */
    public static function campaignCode(string $title, int $percent): string
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $title) ?: $title));

        return substr($code !== '' ? $code : 'OFFER', 0, 16) . $percent;
    }

    /** Makes a campaign and its Paymenter coupon. */
    public function createCampaign(array $data): array
    {
        $percent = max(1, min(100, (int) $data['percent']));
        $starts = (int) $data['starts'];
        $ends = (int) $data['ends'];
        if ($ends <= $starts) {
            throw new DisplayException('It has to end after it starts.');
        }
        $code = strtoupper(trim((string) ($data['code'] ?? ''))) ?: self::campaignCode((string) $data['title'], $percent);
        if (\App\Models\Coupon::where('code', $code)->exists()) {
            throw new DisplayException('The code ' . $code . ' is used already.');
        }
        $products = array_values(array_filter(array_map('intval', (array) ($data['products'] ?? []))));
        $coupon = null;
        if (!empty($data['new'])) {
            $coupon = \App\Models\Coupon::create(['type' => 'percentage', 'applies_to' => 'price', 'code' => $code, 'value' => $percent,
                'recurring' => max(1, (int) ($data['periods'] ?? 1)), 'starts_at' => date('Y-m-d H:i:s', $starts), 'expires_at' => date('Y-m-d H:i:s', $ends),
                'max_uses_per_user' => 1]);
            $coupon->products()->sync($products ?: array_keys($this->campaignProducts()));
        }
        $campaign = ['id' => bin2hex(random_bytes(4)), 'title' => trim((string) $data['title']), 'text' => trim((string) ($data['text'] ?? '')), 'percent' => $percent,
            'starts' => $starts, 'ends' => $ends, 'code' => $coupon ? $code : '', 'coupon' => $coupon?->id, 'periods' => max(1, (int) ($data['periods'] ?? 1)),
            'products' => $products, 'existing' => !empty($data['existing']), 'announce' => !empty($data['announce']), 'announced' => null, 'stopped' => null, 'renewals' => []];
        $all = self::campaigns();
        $all[] = $campaign;
        self::storePut('campaigns', $all);

        return $campaign;
    }

    /** Wizarr products a campaign can be on (not gift cards or top-ups). */
    public function campaignProducts(): array
    {
        $options = [];
        foreach (Product::whereHas('server', fn ($q) => $q->where('extension', 'Wizarr'))->with('settings')->get() as $product) {
            $settings = ExtensionHelper::settingsToArray($product->settings);
            if (!$this->isGift($settings) && !$this->isTopUp($settings)) {
                $options[$product->id] = (string) $product->name;
            }
        }

        return $options;
    }

    /** Ends a campaign now: its coupon stops too. */
    public function stopCampaign(string $id): void
    {
        $all = self::campaigns();
        foreach ($all as &$campaign) {
            if ($campaign['id'] === $id) {
                $campaign['stopped'] = time();
                if ($campaign['coupon']) {
                    \App\Models\Coupon::whereKey($campaign['coupon'])->update(['expires_at' => now()]);
                }
            }
        }
        self::storePut('campaigns', $all);
    }

    public function deleteCampaign(string $id): void
    {
        $all = self::campaigns();
        foreach ($all as $i => $campaign) {
            if ($campaign['id'] === $id) {
                if ($campaign['coupon'] && self::campaignStatus($campaign) === 'planned') {
                    \App\Models\Coupon::whereKey($campaign['coupon'])->delete();
                }
                unset($all[$i]);
            }
        }
        self::storePut('campaigns', array_values($all));
    }

    /**
     * InvoiceItem "creating": an existing customer's renewal during a
     * campaign is cheaper, once per subscription per campaign. Not a first
     * order (the code is for that), not a subscription with a coupon.
     */
    public function campaignForItem($item, ?int $now = null): void
    {
        $campaign = self::activeCampaign($now);
        if (!$campaign || empty($campaign['existing']) || ($item->reference_type ?? null) !== Service::class || !($service = $item->reference)) {
            return;
        }
        if (($service->product?->server?->extension ?? null) !== 'Wizarr' || $service->coupon_id || (float) $item->price <= 0
            || ($campaign['products'] && !in_array((int) $service->product_id, $campaign['products'], true))
            || isset($campaign['renewals'][$service->id]) || !in_array($service->status, ['active', 'suspended'], true)
            || !$service->invoices()->where('status', 'paid')->exists()) {
            return;
        }
        $settings = ExtensionHelper::settingsToArray($service->product->settings ?? []);
        if ($this->isGift($settings) || $this->isTopUp($settings)) {
            return;
        }
        $before = (float) $item->price;
        $item->price = round($before * (100 - $campaign['percent']) / 100, 2);
        $item->description = trim((string) $item->description) . ' (' . $campaign['title'] . ': -' . $campaign['percent'] . '%)';
        $all = self::campaigns();
        foreach ($all as &$c) {
            if ($c['id'] === $campaign['id']) {
                $c['renewals'][$service->id] = round(($before - (float) $item->price) * max(1, (int) ($item->quantity ?? 1)), 2);
            }
        }
        self::storePut('campaigns', $all);
    }

    /** Hourly: a campaign that has started is announced once. */
    public function runCampaigns(?int $now = null): array
    {
        $now ??= time();
        $all = self::campaigns();
        $done = [];
        foreach ($all as &$campaign) {
            if (!$campaign['announce'] || $campaign['announced'] || self::campaignStatus($campaign, $now) !== 'running') {
                continue;
            }
            $campaign['announced'] = $now;
            try {
                $this->broadcast('all', $campaign['title'], $this->campaignText($campaign), [self::t('See subscriptions'), $this->shopUrl()], ['mail', 'discord', 'channel'], 'Campaign');
                $done[] = $campaign['id'];
            } catch (Throwable $e) {
                Log::warning('Wizarr: campaign "' . $campaign['title'] . '" not announced: ' . $e->getMessage());
            }
        }
        self::storePut('campaigns', $all);

        return $done;
    }

    private function campaignText(array $campaign, bool $customer = true): string
    {
        $lines = [];
        if ($campaign['text'] !== '') {
            $lines[] = $campaign['text'];
        }
        if ($campaign['code'] !== '') {
            $lines[] = self::t(':percent% off a new subscription with the code :code, until :date.', ['percent' => $campaign['percent'], 'code' => $campaign['code'], 'date' => date(self::t('M j, Y'), $campaign['ends'])]);
        }
        if ($customer && $campaign['existing']) {
            $lines[] = self::t('Already a customer? Your next renewal before :date is :percent% off by itself.', ['percent' => $campaign['percent'], 'date' => date(self::t('M j, Y'), $campaign['ends'])]);
        }

        return implode("\n\n", $lines);
    }

    /** The banner with a countdown, on every page while a campaign runs (also for visitors). */
    public function renderCampaignBanner(): array
    {
        try {
            $campaign = self::activeCampaign();
        } catch (Throwable $e) {
            return [];
        }
        if (!$campaign) {
            return [];
        }
        $customer = Auth::check() && $this->hasSubscription(Auth::user());
        $text = $customer && $campaign['existing'] ? self::t('Your next renewal is :percent% off.', ['percent' => $campaign['percent']])
            : ($campaign['code'] !== '' ? self::t(':percent% off with the code :code', ['percent' => $campaign['percent'], 'code' => $campaign['code']]) : '');
        if ($text === '') {
            return [];
        }
        $id = 'paywarr-campaign-' . $campaign['id'];

        return ['view' => new HtmlString('<div id="' . e($id) . '" style="background:linear-gradient(90deg,#7c3aed,#db2777);color:#fff;padding:.7rem 2.6rem .7rem 1rem;margin:1rem auto;border-radius:.8rem;max-width:72rem;width:calc(100% - 2rem);box-sizing:border-box;font-size:.95rem;display:flex;gap:.8rem;align-items:center;justify-content:center;flex-wrap:wrap;position:relative">'
            . '<strong>' . e($campaign['title']) . '</strong><span>' . e($text) . '</span>'
            . '<span style="font-variant-numeric:tabular-nums;background:rgba(0,0,0,.2);padding:.15rem .5rem;border-radius:.4rem" data-ends="' . (int) $campaign['ends'] . '"></span>'
            . (!$customer ? '<a href="' . e($this->shopUrl()) . '" style="color:#fff;text-decoration:underline;font-weight:600">' . e(self::t('See subscriptions')) . '</a>' : '')
            . '<button type="button" aria-label="' . e(self::t('Close')) . '" style="position:absolute;right:.8rem;background:none;border:0;color:#fff;font-size:1.2rem;cursor:pointer" onclick="try{sessionStorage.setItem(\'' . e($id) . '\',1)}catch(e){};this.parentNode.remove()">×</button></div>'
            . '<script>(function(){var b=document.getElementById(' . json_encode($id) . ');if(!b)return;try{if(sessionStorage.getItem(' . json_encode($id) . ')){b.remove();return;}}catch(e){}'
            . 'var t=document.querySelector("main")||document.body;t.insertBefore(b,t.firstChild);var c=b.querySelector("[data-ends]"),end=+c.dataset.ends*1000,'
            . 'f=' . json_encode(self::t(':d d :h h :m min left')) . ';function tick(){var s=Math.max(0,(end-Date.now())/1000|0);c.textContent=f.replace(":d",s/86400|0).replace(":h",(s%86400)/3600|0).replace(":m",(s%3600)/60|0);if(!s)b.remove();}tick();setInterval(tick,30000);})();</script>')];
    }

    /** New orders with the code, and renewals made cheaper, with what was given. */
    public static function campaignResults(array $campaign): array
    {
        $orders = 0;
        try {
            $orders = $campaign['coupon'] ? Service::where('coupon_id', $campaign['coupon'])->count() : 0;
        } catch (Throwable $e) {
            // Unknown then.
        }

        return ['orders' => $orders, 'renewed' => count($campaign['renewals'] ?? []), 'given' => round(array_sum($campaign['renewals'] ?? []), 2)];
    }
}
