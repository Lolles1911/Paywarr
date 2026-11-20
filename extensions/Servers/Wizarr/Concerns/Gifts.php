<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Gift cards: a product with "Gift card: months" is bought like any other,
 * and gives a one-time code (a Paymenter coupon) worth what was paid, spread
 * over that many billing periods of a Paywarr subscription. The buyer gets
 * the code, and can send it to a friend from their subscription. Part of
 * Wizarr (Paywarr).
 */
trait Gifts
{
    public const PROP_GIFT_CODE = 'gift_code';

    public const PROP_GIFT_COUPON = 'gift_coupon_id';

    private const PROP_GIFT_EXPIRES = 'gift_expires_at';

    private const PROP_GIFT_SENT_TO = 'gift_sent_to';

    /** A gift card product: gives a code instead of access. */
    public function isGift(array $productSettings): bool
    {
        return (int) ($productSettings['gift_months'] ?? 0) > 0;
    }

    /**
     * Paid: the code. One use, valid a year, on the subscriptions of your
     * Paywarr servers (not trials, top-ups or gift cards). Run again (a
     * retried job), it gives back the code it made.
     */
    public function createGift(Service $service, array $settings, ?callable $makeCoupon = null): array
    {
        $properties = ExtensionHelper::getServiceProperties($service);
        $code = (string) ($properties[self::PROP_GIFT_CODE] ?? '');
        if ($code === '') {
            $months = max(1, (int) $settings['gift_months']);
            $code = 'GIFT-' . $this->giftChars(4) . '-' . $this->giftChars(4);
            $paid = (float) ($service->price ?? 0) * max(1, (int) ($service->quantity ?? 1));
            $expires = time() + 365 * 86400;
            $makeCoupon ??= fn (array $coupon, array $products) => $this->makeCoupon($coupon, $products);
            $id = $makeCoupon([
                'code' => $code,
                // Worth what the buyer paid, a part per period: a monthly plan is free that many
                // months, a yearly one gets the same amount off.
                'type' => 'fixed',
                'value' => round($paid / $months, 2),
                'applies_to' => 'price',
                'recurring' => $months,
                'max_uses' => 1,
                'max_uses_per_user' => 1,
                'starts_at' => date('Y-m-d H:i:s'),
                'expires_at' => date('Y-m-d H:i:s', $expires),
            ], $this->giftableProductIds());
            $this->setProperty($service, self::PROP_GIFT_CODE, 'Gift code', $code);
            $this->setProperty($service, self::PROP_GIFT_COUPON, 'Gift coupon ID', (string) $id);
            $this->setProperty($service, self::PROP_GIFT_EXPIRES, 'Gift code valid until', (string) $expires);
            $this->notifyCustomer($service, self::t('Your gift code'),
                self::t('Thank you! Your gift code is :code. It gives :months of a subscription and can be used once, until :date. Send it to a friend from your subscription, or give it yourself: it is entered under "Coupon" in the cart.',
                    ['code' => $code, 'months' => $months === 1 ? self::t('1 month') : self::t(':count months', ['count' => $months]), 'date' => date(self::t('M j, Y'), $expires)]),
                [self::t('Send it to a friend'), $this->giftPageUrl($service)], discord: false);
            Log::info('Wizarr: gift card service ' . $service->id . ' made code ' . $code . '.');
        }

        return ['invite_url' => '', 'invite_code' => '', 'invite_valid_for' => '', 'login_url' => '', 'gift_code' => $code];
    }

    /** Suspended, refunded or cancelled: an unused code stops working (back again if unsuspended). */
    public function setGiftActive(Service $service, bool $active): void
    {
        $properties = ExtensionHelper::getServiceProperties($service);
        $id = (int) ($properties[self::PROP_GIFT_COUPON] ?? 0);
        if ($id === 0 || !class_exists(\App\Models\Coupon::class)) {
            return;
        }
        try {
            $coupon = \App\Models\Coupon::find($id);
            if ($coupon) {
                $coupon->expires_at = $active ? date('Y-m-d H:i:s', (int) ($properties[self::PROP_GIFT_EXPIRES] ?? time() + 365 * 86400)) : date('Y-m-d H:i:s');
                $coupon->save();
            }
        } catch (Throwable $e) {
            Log::warning('Wizarr: gift code of service ' . $service->id . ' not ' . ($active ? 'reopened' : 'closed') . ': ' . $e->getMessage());
        }
    }

    /** The client area of a gift card: the code, whether it was used, and "Send it to a friend". */
    private function giftActions(Service $service, array $properties): array
    {
        $code = (string) ($properties[self::PROP_GIFT_CODE] ?? '');
        if ($code === '') {
            return [$this->statusField(self::t('Your gift code is being made'))];
        }
        $used = $this->giftUsed($properties);
        $expires = (int) ($properties[self::PROP_GIFT_EXPIRES] ?? 0);
        $actions = [
            ['type' => 'text', 'label' => self::t('Gift code'), 'text' => $code],
            $this->statusField($used ? self::t('Used') : ($expires && $expires < time() ? self::t('Expired') : self::t('Not used yet, valid until :date', ['date' => date(self::t('M j, Y'), $expires)]))),
        ];
        if (($sent = (string) ($properties[self::PROP_GIFT_SENT_TO] ?? '')) !== '') {
            $actions[] = ['type' => 'text', 'label' => self::t('Sent to'), 'text' => $sent];
        }
        if (!$used && $service->status === 'active') {
            $actions[] = ['type' => 'button', 'label' => self::t('Send it to a friend'), 'url' => $this->giftPageUrl($service)];
        }

        return $actions;
    }

    /** GET and POST /wizarr/gift/{service}: send the code to a friend. */
    public function giftPage($service)
    {
        $service = Service::with(['product.settings', 'properties', 'user'])->find((int) $service);
        if (!$service || (int) $service->user_id !== (int) Auth::id() || !$this->isGift(ExtensionHelper::settingsToArray($service->product->settings ?? []))) {
            abort(404);
        }
        $properties = ExtensionHelper::getServiceProperties($service);
        $notice = '';
        $error = '';
        if (request()->isMethod('post')) {
            try {
                $this->sendGift($service, (string) request()->input('email'), (string) request()->input('name'), (string) request()->input('message'));
                $notice = self::t('Sent! Your friend gets the code by email.');
                $properties = ExtensionHelper::getServiceProperties($service->fresh() ?? $service);
            } catch (DisplayException $e) {
                $error = $e->getMessage();
            }
        }
        $e = fn ($v) => e((string) $v);
        $code = (string) ($properties[self::PROP_GIFT_CODE] ?? '');
        $html = '<div class="container mt-14" style="max-width:40rem"><h1 style="font-size:1.8rem;font-weight:800;margin-bottom:.4rem">' . $e(self::t('Send it to a friend')) . '</h1>'
            . '<p style="opacity:.75;margin-bottom:1.4rem">' . $e(self::t('Your friend gets the code :code by email, with how to use it.', ['code' => $code])) . '</p>'
            . ($notice !== '' ? '<p style="padding:.8rem 1rem;border-radius:.7rem;background:rgba(34,197,94,.12);margin-bottom:1rem">' . $e($notice) . '</p>' : '')
            . ($error !== '' ? '<p style="padding:.8rem 1rem;border-radius:.7rem;background:rgba(239,68,68,.12);margin-bottom:1rem">' . $e($error) . '</p>' : '')
            . '<form method="post" style="display:grid;gap:.9rem">' . csrf_field()
            . '<label style="display:grid;gap:.3rem"><span style="font-weight:600">' . $e(self::t('Their email')) . '</span><input type="email" name="email" required maxlength="190" class="bg-background-secondary border border-neutral rounded-lg px-3 py-2"></label>'
            . '<label style="display:grid;gap:.3rem"><span style="font-weight:600">' . $e(self::t('Their name (optional)')) . '</span><input type="text" name="name" maxlength="80" class="bg-background-secondary border border-neutral rounded-lg px-3 py-2"></label>'
            . '<label style="display:grid;gap:.3rem"><span style="font-weight:600">' . $e(self::t('A message from you (optional)')) . '</span><textarea name="message" rows="4" maxlength="500" class="bg-background-secondary border border-neutral rounded-lg px-3 py-2"></textarea></label>'
            . '<button type="submit" class="bg-primary text-white font-semibold rounded-lg px-4 py-2" style="justify-self:start">' . $e(self::t('Send the gift')) . '</button></form></div>';

        return view('layouts.app', ['slot' => new HtmlString($html), 'title' => self::t('Send it to a friend')]);
    }

    /** Mails the code to a friend: from whom, what it gives, how to use it. At most 5 an hour. */
    public function sendGift(Service $service, string $email, string $name = '', string $message = ''): void
    {
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DisplayException(self::t('That email address doesn\'t look right.'));
        }
        $properties = ExtensionHelper::getServiceProperties($service);
        $code = (string) ($properties[self::PROP_GIFT_CODE] ?? '');
        if ($code === '' || $this->giftUsed($properties) || $service->status !== 'active') {
            throw new DisplayException(self::t('This gift code can\'t be sent any more.'));
        }
        $key = 'wizarr-gift:' . $service->id;
        if (class_exists(RateLimiter::class) && RateLimiter::tooManyAttempts($key, 5)) {
            throw new DisplayException(self::t('You have sent it a few times now. Please try again in an hour.'));
        }
        if (class_exists(RateLimiter::class)) {
            RateLimiter::hit($key, 3600);
        }
        $from = trim(($service->user->first_name ?? '') . ' ' . ($service->user->last_name ?? '')) ?: (string) $service->user->email;
        $months = (int) (ExtensionHelper::settingsToArray($service->product->settings)['gift_months'] ?? 1);
        $title = self::t(':name gave you a gift', ['name' => $from]);
        $body = '<p style="margin:0 0 12px">' . e(trim($name) !== '' ? self::t('Hi :name,', ['name' => trim($name)]) : self::t('Hi,')) . '</p>'
            . '<p style="margin:0 0 12px">' . e(self::t(':from gave you :months of streaming.', ['from' => $from, 'months' => $months === 1 ? self::t('1 month') : self::t(':count months', ['count' => $months])])) . '</p>'
            . (trim($message) !== '' ? '<div style="margin:0 0 14px;padding:12px 14px;background:#f4f2f8;border-radius:10px">' . nl2br(e(mb_substr(trim($message), 0, 500))) . '</div>' : '')
            . '<p style="margin:0 0 6px">' . e(self::t('Your code:')) . '</p>'
            . '<p style="margin:0 0 16px;font-size:22px;font-weight:800;letter-spacing:.06em">' . e($code) . '</p>'
            . '<p style="margin:0 0 12px">' . e(self::t('Choose a subscription, and enter the code under "Coupon" in the cart. It can be used once, until :date.', ['date' => date(self::t('M j, Y'), (int) ($properties[self::PROP_GIFT_EXPIRES] ?? time()))])) . '</p>';
        if (!$this->sendMail($email, $title, $this->mailLayout($title, $body, [self::t('Choose a subscription'), $this->shopUrl()]))) {
            throw new DisplayException(self::t('The email couldn\'t be sent right now. Please try again shortly.'));
        }
        $this->setProperty($service, self::PROP_GIFT_SENT_TO, 'Gift sent to', $email);
        Log::info('Wizarr: gift code of service ' . $service->id . ' sent to a friend.');
    }

    /** Letters and digits that can't be mistaken for each other (no 0/O, 1/I/L). */
    private function giftChars(int $n): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < $n; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }

    private function giftUsed(array $properties): bool
    {
        $id = (int) ($properties[self::PROP_GIFT_COUPON] ?? 0);
        try {
            return $id > 0 && class_exists(\App\Models\Service::class) && method_exists(\App\Models\Service::class, 'query')
                && \App\Models\Service::query()->where('coupon_id', $id)->exists();
        } catch (Throwable $e) {
            return false;
        }
    }

    private function giftPageUrl(Service $service): string
    {
        try {
            return route('extensions.wizarr.gift', ['service' => $service->id]);
        } catch (Throwable $e) {
            return '/wizarr/gift/' . $service->id;
        }
    }

    /** Products a gift code works on: subscriptions of the Paywarr servers. */
    private function giftableProductIds(): array
    {
        $ids = [];
        try {
            foreach (\App\Models\Product::whereHas('server', fn ($q) => $q->where('extension', 'Wizarr'))->with('settings')->get() as $product) {
                $settings = ExtensionHelper::settingsToArray($product->settings);
                if (!$this->isTrial($settings) && !$this->isTopUp($settings) && !$this->isGift($settings)) {
                    $ids[] = (int) $product->id;
                }
            }
        } catch (Throwable $e) {
            Log::warning('Wizarr: the products a gift code works on could not be read: ' . $e->getMessage());
        }

        return $ids;
    }

    private function makeCoupon(array $fields, array $products): int
    {
        $coupon = \App\Models\Coupon::create($fields);
        if ($products) {
            $coupon->products()->sync($products);
        }

        return (int) $coupon->id;
    }
}
