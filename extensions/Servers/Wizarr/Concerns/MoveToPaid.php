<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Move to paid" on Paywarr → Free access: free users, chosen with a filter,
 * get a paid subscription with an invoice and a deadline. They keep their
 * user: a free subscription becomes the paid product, a user without a
 * subscription is put on a new one (when their mail address has an account).
 * Unpaid by the deadline, Paymenter suspends the subscription as with any
 * unpaid invoice. A user without an account gets a mail with the shop instead,
 * and loses the access at the deadline unless a subscription has it by then.
 * Part of Wizarr (Paywarr).
 */
trait MoveToPaid
{
    /** Who the filter takes: kinds (subscription, user), statuses, current products ([] = all). */
    public static function paidFilter(array $rows, array $filter): array
    {
        $kinds = $filter['kinds'] ?? ['subscription', 'user'];
        $statuses = $filter['statuses'] ?? ['active', 'new'];
        $products = array_values(array_filter((array) ($filter['products'] ?? [])));

        return array_filter($rows, fn ($row) => in_array($row['kind'], $kinds, true) && in_array($row['status'], $statuses, true)
            && (!$products || in_array($row['product'], $products, true)));
    }

    /**
     * The plan and price to charge, from plans as arrays (id, type, period,
     * unit, prices: currency => price): the same period as now if it has a
     * price, else the first recurring plan with one; the currency kept when
     * the plan has it. Null when the product has no paid plan.
     *
     * @return array{0: int, 1: float, 2: string}|null plan ID, price, currency
     */
    public static function paidPlanChoice(array $plans, ?array $current, string $currency): ?array
    {
        $paid = array_values(array_filter($plans, fn ($p) => ($p['type'] ?? 'recurring') === 'recurring' && array_filter($p['prices'] ?? [], fn ($v) => (float) $v > 0)));
        if (!$paid) {
            return null;
        }
        $plan = null;
        foreach ($paid as $p) {
            if ($current && (int) $p['period'] === (int) ($current['period'] ?? 0) && (string) $p['unit'] === (string) ($current['unit'] ?? '')) {
                $plan = $p;

                break;
            }
        }
        $plan ??= $paid[0];
        $prices = array_filter($plan['prices'], fn ($v) => (float) $v > 0);
        $code = isset($prices[$currency]) ? $currency : (string) array_key_first($prices);

        return [(int) $plan['id'], (float) $prices[$code], $code];
    }

    /** Products a free user can be moved to: Wizarr products with a paid recurring plan (not trials, gift cards, top-ups or free access). */
    public function paidProducts(): array
    {
        $options = [];
        foreach (Product::whereHas('server', fn ($q) => $q->where('extension', 'Wizarr'))->with(['settings', 'plans.prices'])->get() as $product) {
            $settings = ExtensionHelper::settingsToArray($product->settings);
            if ($this->isTrial($settings) || $this->isGift($settings) || $this->isTopUp($settings) || $this->truthy($settings['free_access'] ?? false)) {
                continue;
            }
            if (self::paidPlanChoice(self::planArrays($product), null, '') !== null) {
                $options[$product->id] = (string) $product->name;
            }
        }

        return $options;
    }

    private static function planArrays($product): array
    {
        $plans = [];
        foreach ($product->plans as $plan) {
            $prices = [];
            foreach ($plan->prices as $price) {
                $prices[(string) $price->currency_code] = (float) $price->price;
            }
            $plans[] = ['id' => (int) $plan->id, 'type' => (string) ($plan->type ?? 'recurring'), 'period' => (int) ($plan->billing_period ?? 1), 'unit' => (string) ($plan->billing_unit ?? 'month'), 'prices' => $prices];
        }

        return $plans;
    }

    /**
     * The paid product with the same media server: on the same Paymenter
     * server, including $servers (Wizarr server IDs; [] = any), the one with
     * the fewest servers first.
     */
    private function samePaidProduct(int $paymenterServer, array $servers): Product
    {
        $paid = $this->paidProducts();
        $best = null;
        $bestCount = PHP_INT_MAX;
        foreach (Product::whereIn('id', array_keys($paid) ?: [0])->where('server_id', $paymenterServer)->with('settings')->orderBy('id')->get() as $product) {
            $has = $this->idList(ExtensionHelper::settingsToArray($product->settings)['server_ids'] ?? null);
            if ($has && array_diff($servers, $has)) {
                continue;
            }
            $count = $has ? count($has) : 1000;
            if ($count < $bestCount) {
                [$best, $bestCount] = [$product, $count];
            }
        }
        if (!$best) {
            throw new DisplayException('No paid product with the same media server');
        }

        return $best;
    }

    /**
     * Moves one free member to paid. $productId null: the same media server.
     *
     * @return string what was done, for the log
     */
    public function moveToPaid(array $row, ?int $productId, int $days, ?int $now = null): string
    {
        $now ??= time();
        $days = max(1, $days);
        $due = date('Y-m-d H:i:s', $now + $days * 86400);
        if ($row['kind'] === 'subscription') {
            $service = $row['service'];
            $ext = $this->forServiceServer($service);
            $settings = ExtensionHelper::settingsToArray($service->product->settings ?? []);
            $before = $ext->chosenServerIds($settings, ExtensionHelper::getServiceProperties($service));
            $product = $productId ? Product::with(['settings', 'plans.prices'])->findOrFail($productId)
                : $this->samePaidProduct((int) $service->product->server_id, $ext->isSilo() ? [] : $before);
            $product->loadMissing(['settings', 'plans.prices']);
            $current = $service->plan ? ['period' => (int) $service->plan->billing_period, 'unit' => (string) $service->plan->billing_unit] : null;
            [$planId, $price, $currency] = self::paidPlanChoice(self::planArrays($product), $current, (string) $service->currency_code)
                ?? throw new DisplayException($product->name . ' has no price');
            $service->update(['product_id' => $product->id, 'plan_id' => $planId, 'price' => $price, 'currency_code' => $currency, 'expires_at' => $due]);
            $service = $service->fresh(['product.settings', 'product.server', 'properties', 'user', 'plan']);
            $newSettings = ExtensionHelper::settingsToArray($service->product->settings);
            if (!$ext->isSilo() && $ext->chosenServerIds($newSettings, ExtensionHelper::getServiceProperties($service)) !== $before) {
                $ext->upgradeServer($service, $newSettings, ExtensionHelper::getServiceProperties($service));
            }
        } else {
            $user = $row['email'] !== '' ? User::where('email', $row['email'])->first() : null;
            if (!$user) {
                return $this->offerPaid($row, $days, $now);
            }
            $ext = $this->instanceForServer((int) $row['backend']['server_id']);
            $grant = null;
            foreach ($ext->isSilo() ? [] : $ext->serverInfo() as $id => $info) {
                if ((string) ($info['name'] ?? '') === (string) $row['user']['server']) {
                    $grant = (int) $id;
                }
            }
            $product = $productId ? Product::with(['settings', 'plans.prices'])->findOrFail($productId)
                : $this->samePaidProduct((int) $row['backend']['server_id'], $grant !== null ? [$grant] : []);
            $product->loadMissing(['settings', 'plans.prices']);
            [$planId, $price, $currency] = self::paidPlanChoice(self::planArrays($product), null, (string) config('settings.default_currency', ''))
                ?? throw new DisplayException($product->name . ' has no price');
            $service = Service::create(['user_id' => $user->id, 'product_id' => $product->id, 'plan_id' => $planId, 'price' => $price, 'quantity' => 1,
                'currency_code' => $currency, 'status' => 'active', 'expires_at' => $due]);
            $service = $service->fresh(['product.settings', 'product.server', 'properties', 'user', 'plan']);
            try {
                $this->forServiceServer($service)->adminLinkUser($service, (int) $row['user']['id'], \Paymenter\Extensions\Servers\Wizarr\Admin\PaywarrInventory::services());
                $settings = ExtensionHelper::settingsToArray($service->product->settings);
                if (count($this->forServiceServer($service)->chosenServerIds($settings, ExtensionHelper::getServiceProperties($service))) > 1) {
                    $this->forServiceServer($service)->upgradeServer($service, $settings, ExtensionHelper::getServiceProperties($service)); // invitations for the other servers
                }
            } catch (Throwable $e) {
                $service->delete();

                throw $e;
            }
        }
        $invoice = $this->paidInvoice($service);
        $title = self::t('Your free access becomes a subscription');
        $text = self::t('Your access continues as :product at :price a period. Pay the invoice by :date to keep your user and everything you have watched; unpaid, the access stops then.', [
            'product' => (string) $service->product->name, 'price' => number_format((float) $service->price, 2) . ' ' . $service->currency_code, 'date' => date(self::t('M j, Y'), $now + $days * 86400)]);
        try {
            $url = route('invoices.show', $invoice);
        } catch (Throwable $e) {
            $url = $this->accountUrl();
        }
        try {
            $this->forServiceServer($service)->notifyCustomer($service, $title, $text, [self::t('Pay the invoice'), $url]);
        } catch (Throwable $e) {
            Log::warning('Wizarr: customer of service ' . $service->id . ' not told about the move to paid: ' . $e->getMessage());
        }
        self::logAdmin($service->user, (int) $service->id, 'Moved from free to paid: ' . $service->product->name . ', invoice #' . $invoice->id . ' due ' . date('M j, Y', $now + $days * 86400), 'Paywarr');
        $this->forgetCustomerCaches($service);

        return 'Moved ' . $row['name'] . ' to ' . $service->product->name . ' (invoice #' . $invoice->id . ', due ' . date('M j', $now + $days * 86400) . ')';
    }

    /** The first invoice, as Paymenter's own renewal makes it (its mail goes out too). */
    private function paidInvoice(Service $service)
    {
        $invoice = $service->invoices()->make(['user_id' => $service->user_id, 'status' => 'pending', 'due_at' => $service->expires_at, 'currency_code' => $service->currency_code]);
        $invoice->save();
        $invoice->items()->create(['reference_id' => $service->id, 'reference_type' => Service::class, 'price' => $service->price, 'quantity' => $service->quantity, 'description' => $service->description]);

        return $invoice->refresh();
    }

    /** No account to bill: a mail with the shop, and the access goes at the deadline. */
    private function offerPaid(array $row, int $days, int $now): string
    {
        if ($row['email'] === '') {
            throw new DisplayException('No mail address and no account');
        }
        $title = self::t('Your free access becomes a subscription');
        $text = self::t('Your free access ends on :date. To keep watching, create an account with this mail address and buy a subscription in our shop before then.', ['date' => date(self::t('M j, Y'), $now + $days * 86400)]);
        $html = $this->paragraphs($text) . '<p style="margin:16px 0 0"><a href="' . e($this->shopUrl()) . '" style="display:inline-block;padding:10px 16px;border-radius:10px;background:#7c3aed;color:#fff;text-decoration:none;font-weight:700">' . e(self::t('Go to the shop')) . '</a></p>';
        if (!$this->sendMail($row['email'], $title, $this->mailLayout($title, $html))) {
            throw new DisplayException('The mail was not sent');
        }
        $offers = self::storeGet('paid_offers', []);
        $offers[$row['key']] = ['until' => $now + $days * 86400, 'at' => $now];
        self::storePut('paid_offers', $offers);

        return 'Offered ' . $row['name'] . ' a subscription by mail; the access goes ' . date('M j', $now + $days * 86400) . ' without one';
    }

    /**
     * Hourly: an offer past its deadline whose user still has no subscription
     * loses the access, as free access ends. $rows and $act are for tests.
     *
     * @return string[] what was done
     */
    public function runPaidOffers(?array $rows = null, ?int $now = null, ?callable $act = null): array
    {
        $now ??= time();
        $offers = self::storeGet('paid_offers', []);
        if (!$offers) {
            return [];
        }
        if ($rows === null) {
            try {
                $services = \Paymenter\Extensions\Servers\Wizarr\Admin\PaywarrInventory::services();
                $rows = $this->freeMembers($this->adminInventory(\App\Models\Server::where('extension', 'Wizarr')->with('settings')->get(), $services), []);
            } catch (Throwable $e) {
                Log::warning('Wizarr: offers to pay not checked: ' . $e->getMessage());

                return [];
            }
        }
        $act ??= fn (string $action, array $row) => $this->freeAct($action, $row);
        $done = [];
        foreach ($offers as $key => $offer) {
            if (!isset($rows[$key])) {
                unset($offers[$key]); // has a subscription now, or is gone

                continue;
            }
            if ($offer['until'] > $now) {
                continue;
            }
            try {
                $act('remove', $rows[$key]);
                $done[] = 'Removed ' . $rows[$key]['name'] . ' (no subscription bought by the deadline)';
                unset($offers[$key]);
            } catch (Throwable $e) {
                Log::warning('Wizarr: ' . $rows[$key]['name'] . ' not removed after the offer: ' . $e->getMessage());
            }
        }
        self::storePut('paid_offers', $offers);
        foreach ($done as $what) {
            self::logFree($what);
        }

        return $done;
    }
}
