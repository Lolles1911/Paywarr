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
 * Keeping customers: they pause their subscription themselves (a holiday)
 * instead of cancelling; cancelled customers get a code to come back; and
 * every customer has a code for friends that earns them free months. Each is
 * off until switched on in the Wizarr server's settings. Part of Wizarr
 * (Paywarr).
 */
trait Loyalty
{
    /** User: timestamps of the customer's own pauses (JSON). */
    public const PROP_SELF_PAUSES = 'paywarr_self_pauses';

    /** User: when the last "come back" code was sent. */
    public const PROP_WINBACK_AT = 'paywarr_winback_at';

    /** User: the customer's own code for friends, and its coupon. */
    public const PROP_REFERRAL_CODE = 'paywarr_referral_code';

    public const PROP_REFERRAL_COUPON = 'paywarr_referral_coupon';

    /** User: friends who paid with the code (JSON list of service IDs). */
    public const PROP_REFERRALS = 'paywarr_referrals';

    /** Service (the friend's): the referrer was rewarded for it. */
    public const PROP_REFERRAL_REWARDED = 'referral_rewarded';

    /** The invoice-paid listener is registered once, however many Wizarr servers there are. */
    private static bool $referralListener = false;

    /**
     * This server's settings, or else the first Wizarr server's where $key
     * is set: for things that are the same for every server (codes for
     * friends, come-back codes, "New on the server").
     */
    public function anyServer(string $key): static
    {
        if (trim((string) ($this->config($key) ?? '')) !== '') {
            return $this;
        }
        try {
            foreach (\App\Models\Server::where('extension', 'Wizarr')->with('settings')->get() as $server) {
                $settings = ExtensionHelper::settingsToArray($server->settings);
                if (trim((string) ($settings[$key] ?? '')) !== '') {
                    return new static($settings);
                }
            }
        } catch (Throwable $e) {
            // This one then.
        }

        return $this;
    }

    /* ---------------- pausing ---------------- */

    /** How many pauses a year (0: off) and how long each may be, from the server's settings. */
    public function pauseRules(): array
    {
        return ['per_year' => max(0, (int) ($this->config('pause_per_year') ?? 0)), 'max_days' => max(1, min(90, (int) ($this->config('pause_max_days') ?: 30)))];
    }

    /** The customer's pauses in the last 365 days. */
    private function pausesThisYear($user, int $now): array
    {
        $list = json_decode((string) ($this->propertyValue($user, self::PROP_SELF_PAUSES) ?? '[]'), true) ?: [];

        return array_values(array_filter($list, fn ($at) => (int) $at > $now - 365 * 86400));
    }

    /** Whether this subscription may be paused by its customer now; null when it may, otherwise why not. */
    public function pauseRefusal(Service $service, array $settings, int $now): ?string
    {
        $rules = $this->pauseRules();
        if ($rules['per_year'] === 0 || $this->isTrial($settings) || $this->isGift($settings) || $this->isTopUp($settings)) {
            return self::t('This subscription can\'t be paused.');
        }
        if ($service->status !== 'active') {
            return self::t('Only an active subscription can be paused.');
        }
        if (count($this->pausesThisYear($service->user, $now)) >= $rules['per_year']) {
            return self::t('You have used your pauses for this year.');
        }

        return null;
    }

    /** The customer pauses for $days days: access off, renewal moved by as much. */
    public function customerPause(Service $service, int $days, ?int $now = null): int
    {
        $now ??= time();
        $settings = ExtensionHelper::settingsToArray($service->product->settings);
        if (($refusal = $this->pauseRefusal($service, $settings, $now)) !== null) {
            throw new DisplayException($refusal);
        }
        $max = $this->pauseRules()['max_days'];
        if ($days < 1 || $days > $max) {
            throw new DisplayException(self::t('A pause can be 1 to :days days.', ['days' => $max]));
        }
        $until = $now + $days * 86400;
        $this->adminPause($service, $until, $now);
        $pauses = $this->pausesThisYear($service->user, $now);
        $pauses[] = $now;
        $service->user->properties()->updateOrCreate(['key' => self::PROP_SELF_PAUSES], ['name' => 'Paused by the customer', 'value' => json_encode($pauses)]);
        self::logAdmin($service->user, (int) $service->id, 'Paused by the customer until ' . date('M j, Y', $until), 'customer');
        $this->notifyMail($service, 'paused', ['product' => (string) ($service->product->name ?? ''), 'date' => date(self::t('M j, Y'), $until), 'days' => $days], $this->pausePageUrl($service));

        return $until;
    }

    /** The customer resumes early. */
    public function customerResume(Service $service, ?int $now = null): void
    {
        $this->adminResume($service, $now);
        self::logAdmin($service->user, (int) $service->id, 'Resumed by the customer', 'customer');
    }

    /** GET and POST /wizarr/pause/{service}. */
    public function pausePage($service)
    {
        $service = Service::with(['product.settings', 'product.server', 'properties', 'user'])->find((int) $service);
        if (!$service || (int) $service->user_id !== (int) Auth::id() || ($service->product?->server?->extension ?? null) !== 'Wizarr') {
            abort(404);
        }
        $ext = $this->forServiceServer($service);
        $notice = '';
        $error = '';
        if (request()->isMethod('post')) {
            try {
                if (request()->input('action') === 'resume') {
                    $ext->customerResume($service);
                    $notice = self::t('Welcome back! Your access is on again.');
                } else {
                    $until = $ext->customerPause($service, (int) request()->input('days'));
                    $notice = self::t('Paused until :date.', ['date' => date(self::t('M j, Y'), $until)]);
                }
                $service = $service->fresh(['product.settings', 'product.server', 'properties', 'user']) ?? $service;
            } catch (DisplayException $e) {
                $error = $e->getMessage();
            }
        }

        return view('layouts.app', ['slot' => new HtmlString($ext->pageFrame(self::t('Pause your subscription'), $ext->pauseBody($service, time()), $notice, $error)), 'title' => self::t('Pause your subscription')]);
    }

    private function pauseBody(Service $service, int $now): string
    {
        $e = fn ($v) => e((string) $v);
        $properties = ExtensionHelper::getServiceProperties($service);
        $product = (string) ($service->product->name ?? '');
        if (!empty($properties[self::PROP_PAUSED])) {
            $until = (int) ($properties[self::PROP_PAUSED_UNTIL] ?? 0);

            return '<p style="margin-bottom:1rem">' . $e($until ? self::t(':product is paused until :date.', ['product' => $product, 'date' => date(self::t('M j, Y'), $until)]) : self::t(':product is paused.', ['product' => $product])) . '</p>'
                . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="resume">'
                . '<button type="submit" class="bg-primary text-white font-semibold rounded-lg px-4 py-2">' . $e(self::t('Resume now')) . '</button></form>';
        }
        $refusal = $this->pauseRefusal($service, ExtensionHelper::settingsToArray($service->product->settings), $now);
        if ($refusal !== null) {
            return '<p>' . $e($refusal) . '</p>';
        }
        $rules = $this->pauseRules();
        $left = $rules['per_year'] - count($this->pausesThisYear($service->user, $now));
        $options = '';
        foreach (array_unique(array_filter([7, 14, 21, 30, 60, 90, $rules['max_days']], fn ($d) => $d <= $rules['max_days'])) as $days) {
            $options .= '<option value="' . $days . '"' . ($days === min(14, $rules['max_days']) ? ' selected' : '') . '>' . $e(self::t(':count days', ['count' => $days])) . ' · ' . $e(self::t('until :date', ['date' => date(self::t('M j, Y'), $now + $days * 86400)])) . '</option>';
        }

        return '<p style="margin-bottom:.6rem">' . $e(self::t('Going away, or busy for a while? Pause :product instead of cancelling. Your user and everything you have watched are kept, and your renewal date moves as many days as the pause.', ['product' => $product])) . '</p>'
            . '<p style="opacity:.7;margin-bottom:1.2rem">' . $e($left === 1 ? self::t('You can pause once more this year.') : self::t('You can pause :count more times this year.', ['count' => $left])) . '</p>'
            . '<form method="post" style="display:grid;gap:.9rem">' . csrf_field() . '<input type="hidden" name="action" value="pause">'
            . '<label style="display:grid;gap:.3rem"><span style="font-weight:600">' . $e(self::t('For how long?')) . '</span><select name="days" class="bg-background-secondary border border-neutral rounded-lg px-3 py-2">' . $options . '</select></label>'
            . '<button type="submit" class="bg-primary text-white font-semibold rounded-lg px-4 py-2" style="justify-self:start">' . $e(self::t('Pause')) . '</button></form>';
    }

    private function pausePageUrl(Service $service): string
    {
        try {
            return route('extensions.wizarr.pause', ['service' => $service->id]);
        } catch (Throwable $e) {
            return '/wizarr/pause/' . $service->id;
        }
    }

    /** A page in the client area's look: a heading, a notice or error, and the body. */
    public function pageFrame(string $title, string $body, string $notice = '', string $error = ''): string
    {
        $e = fn ($v) => e((string) $v);

        return '<div class="container mt-14" style="max-width:40rem"><h1 style="font-size:1.8rem;font-weight:800;margin-bottom:.8rem">' . $e($title) . '</h1>'
            . ($notice !== '' ? '<p style="padding:.8rem 1rem;border-radius:.7rem;background:rgba(34,197,94,.12);margin-bottom:1rem">' . $e($notice) . '</p>' : '')
            . ($error !== '' ? '<p style="padding:.8rem 1rem;border-radius:.7rem;background:rgba(239,68,68,.12);margin-bottom:1rem">' . $e($error) . '</p>' : '')
            . $body . '</div>';
    }

    /* ---------------- come back ---------------- */

    /**
     * Daily: customers whose subscription was cancelled "winback_days" ago
     * (and who have none running) get one code for a discount on coming
     * back, at most once a year. Trials are left alone (they have their own
     * reminder).
     *
     * @return int[] the users a code was sent to
     */
    public function sendWinbacks(?iterable $services = null, ?int $now = null, ?callable $makeCoupon = null): array
    {
        $now ??= time();
        if (($ext = $this->anyServer('winback_days')) !== $this) {
            return $ext->sendWinbacks($services, $now, $makeCoupon);
        }
        $days = (int) ($this->config('winback_days') ?? 0);
        $percent = (int) ($this->config('winback_percent') ?? 0);
        if ($days < 1 || $percent < 1) {
            return [];
        }
        $services ??= Service::whereIn('status', ['cancelled', 'active', 'suspended', 'pending'])
            ->whereHas('product.server', fn ($query) => $query->where('extension', 'Wizarr'))
            ->with(['product.settings', 'product.server', 'user', 'cancellation'])->get();
        $byUser = [];
        foreach ($services as $service) {
            $byUser[$service->user_id][] = $service;
        }
        $sent = [];
        foreach ($byUser as $userId => $list) {
            if (array_filter($list, fn ($s) => in_array($s->status, ['active', 'suspended', 'pending'], true))) {
                continue; // still a customer
            }
            $last = null;
            foreach ($list as $service) {
                $settings = ExtensionHelper::settingsToArray($service->product->settings);
                if ($this->isTrial($settings) || $this->isGift($settings) || $this->isTopUp($settings)) {
                    continue;
                }
                $when = $service->cancellation->created_at ?? $service->updated_at ?? null;
                $at = $when instanceof \DateTimeInterface ? $when->getTimestamp() : (strtotime((string) $when) ?: null);
                if ($at !== null && ($last === null || $at > $last[1])) {
                    $last = [$service, $at];
                }
            }
            // Cancelled between $days and $days + 7 days ago: an old cancellation isn't dug up.
            if ($last === null || $now - $last[1] < $days * 86400 || $now - $last[1] > ($days + 7) * 86400) {
                continue;
            }
            $user = $last[0]->user;
            $before = (int) ($this->propertyValue($user, self::PROP_WINBACK_AT) ?? 0);
            if ($before > $now - 365 * 86400) {
                continue;
            }
            try {
                $valid = max(1, (int) ($this->config('winback_valid_days') ?: 30));
                $code = 'BACK-' . $this->giftChars(4) . '-' . $this->giftChars(4);
                ($makeCoupon ?? fn (array $coupon, array $products) => $this->makeCoupon($coupon, $products))([
                    'code' => $code, 'type' => 'percentage', 'value' => min(100, $percent), 'applies_to' => 'price', 'recurring' => 1,
                    'max_uses' => 1, 'max_uses_per_user' => 1, 'starts_at' => date('Y-m-d H:i:s', $now), 'expires_at' => date('Y-m-d H:i:s', $now + $valid * 86400),
                ], $this->giftableProductIds());
                $user->properties()->updateOrCreate(['key' => self::PROP_WINBACK_AT], ['name' => 'Come-back code sent', 'value' => (string) $now]);
                $this->notifyMail($last[0], 'winback', ['percent' => min(100, $percent), 'code' => $code, 'date' => date(self::t('M j, Y'), $now + $valid * 86400)], $this->shopUrl());
                self::logAdmin($user, (int) $last[0]->id, 'Come-back code ' . $code . ' sent (' . $percent . '% off)', 'Paywarr');
                $sent[] = $userId;
            } catch (Throwable $e) {
                Log::warning('Wizarr: come-back code for user ' . $userId . ' not sent, will retry tomorrow: ' . $e->getMessage());
            }
        }

        return $sent;
    }

    /* ---------------- refer a friend ---------------- */

    /** On when "referral_percent" is set. */
    public function referralOn(): bool
    {
        return (int) ($this->anyServer('referral_percent')->config('referral_percent') ?? 0) > 0;
    }

    /** The customer's code for friends, made the first time it is asked for. */
    public function referralCode($user, ?callable $makeCoupon = null): string
    {
        if (($ext = $this->anyServer('referral_percent')) !== $this) {
            return $ext->referralCode($user, $makeCoupon);
        }
        $code = (string) ($this->propertyValue($user, self::PROP_REFERRAL_CODE) ?? '');
        if ($code !== '') {
            // A changed "% off" or product list applies to codes made before too.
            $this->syncReferralCoupon($user);

            return $code;
        }
        $name = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($user->first_name ?? '')) ?: 'FRIEND');
        $code = substr($name, 0, 8) . '-' . $this->giftChars(4);
        $id = ($makeCoupon ?? fn (array $coupon, array $products) => $this->makeCoupon($coupon, $products))([
            // Once per friend, as many friends as they like, the friend's first period.
            'code' => $code, 'type' => 'percentage', 'value' => min(100, (int) $this->config('referral_percent')), 'applies_to' => 'price', 'recurring' => 1,
            'max_uses' => null, 'max_uses_per_user' => 1, 'starts_at' => date('Y-m-d H:i:s'), 'expires_at' => null,
        ], $this->referralProductIds());
        $user->properties()->updateOrCreate(['key' => self::PROP_REFERRAL_CODE], ['name' => 'Code for friends', 'value' => $code]);
        $user->properties()->updateOrCreate(['key' => self::PROP_REFERRAL_COUPON], ['name' => 'Code for friends: coupon ID', 'value' => (string) $id]);

        return $code;
    }

    /** The setting's field: the subscription products to choose from (a list without the database). */
    private function referralProductField(): array
    {
        try {
            $options = [];
            foreach (\App\Models\Product::whereIn('id', $this->giftableProductIds() ?: [0])->with('category')->orderBy('name')->get() as $product) {
                $options[] = ['value' => (string) $product->id, 'label' => $product->name . ' (' . trim(($product->category?->name ?? '') . ' #' . $product->id) . ')'];
            }
            if ($options) {
                return ['type' => 'select', 'multiple' => true, 'database_type' => 'array', 'options' => $options];
            }
        } catch (Throwable $e) {
            // A plain field then.
        }

        return ['type' => 'text', 'placeholder' => '2,5', 'hint' => 'Product IDs, comma separated.'];
    }

    /** The products a friend's code is on: the chosen ones that can be (or every subscription). */
    public function referralProductIds(): array
    {
        $all = $this->giftableProductIds();
        $chosen = $this->idList($this->config('referral_products') ?? null);

        return $chosen ? array_values(array_intersect($chosen, $all)) : $all;
    }

    /** Keeps a code's percent and products as the settings say (they may have changed since it was made). */
    private function syncReferralCoupon($user): void
    {
        try {
            $coupon = \App\Models\Coupon::with('products')->find((int) ($this->propertyValue($user, self::PROP_REFERRAL_COUPON) ?? 0));
            if (!$coupon) {
                return;
            }
            $percent = min(100, (int) $this->config('referral_percent'));
            if ((int) $coupon->value !== $percent) {
                $coupon->update(['value' => $percent]);
            }
            $want = $this->referralProductIds();
            $have = $coupon->products->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            sort($want);
            if ($want !== $have) {
                $coupon->products()->sync($want);
            }
        } catch (Throwable $e) {
            // The code as it is.
        }
    }

    /** Whom a coupon belongs to as their code for friends: the Paymenter user ID, or null. */
    public function referrerOfCoupon(?int $couponId): ?int
    {
        if (!$couponId || !class_exists(\App\Models\Property::class)) {
            return null;
        }
        try {
            $owner = \App\Models\Property::where('key', self::PROP_REFERRAL_COUPON)->where('value', (string) $couponId)
                ->where('model_type', \App\Models\User::class)->value('model_id');
        } catch (Throwable $e) {
            return null;
        }

        return $owner === null ? null : (int) $owner;
    }

    /** At checkout: one's own code for friends can't be used. */
    public function guardReferral(Service $service): void
    {
        $owner = $this->referrerOfCoupon((int) ($service->coupon_id ?? 0));
        if ($owner !== null && $owner === (int) $service->user_id) {
            throw new DisplayException(self::t('That is your own code for friends; give it to a friend instead.'));
        }
    }

    /**
     * An invoice was paid: a friend's first payment with someone's code
     * gives that someone "referral_reward_months" months on their active
     * subscription. Once per friend's subscription.
     *
     * @return int[] the referrers rewarded
     */
    public function rewardReferrals($invoice): array
    {
        if (($ext = $this->anyServer('referral_percent')) !== $this) {
            return $ext->rewardReferrals($invoice);
        }
        $rewarded = [];
        foreach ($invoice->items ?? [] as $item) {
            $service = ($item->reference_type ?? null) === Service::class ? Service::with(['user', 'properties', 'product.server', 'coupon'])->find($item->reference_id) : null;
            if (!$service || ($service->product?->server?->extension ?? null) !== 'Wizarr') {
                continue;
            }
            $referrer = $this->referrerOfCoupon((int) ($service->coupon_id ?? 0));
            if ($referrer === null || $referrer === (int) $service->user_id || !empty(ExtensionHelper::getServiceProperties($service)[self::PROP_REFERRAL_REWARDED])) {
                continue;
            }
            try {
                $months = $this->rewardReferrer($referrer, $service);
                if ($months > 0) {
                    $this->setProperty($service, self::PROP_REFERRAL_REWARDED, 'Referrer rewarded', (string) $referrer);
                    $rewarded[] = $referrer;
                }
            } catch (Throwable $e) {
                Log::warning('Wizarr: the referrer of service ' . $service->id . ' was not rewarded: ' . $e->getMessage());
            }
        }

        return $rewarded;
    }

    /** Adds the reward to the referrer's active subscription (the one that renews first). */
    private function rewardReferrer(int $userId, Service $friendService): int
    {
        $months = max(1, (int) ($this->config('referral_reward_months') ?: 1));
        $candidates = [];
        foreach (Service::where('user_id', $userId)->where('status', 'active')
            ->whereHas('product.server', fn ($query) => $query->where('extension', 'Wizarr'))
            ->with(['product.settings', 'user'])->get() as $service) {
            $settings = ExtensionHelper::settingsToArray($service->product->settings);
            if ($service->expires_at && !$this->isTrial($settings) && !$this->isGift($settings) && !$this->isTopUp($settings)) {
                $candidates[] = $service;
            }
        }
        usort($candidates, fn ($a, $b) => strtotime((string) $a->expires_at) <=> strtotime((string) $b->expires_at));
        $target = $candidates[0] ?? null;
        if (!$target) {
            Log::info('Wizarr: user ' . $userId . ' brought a friend but has no active subscription to add months to.');

            return 0;
        }
        $expires = strtotime((string) $target->expires_at);
        $target->expires_at = date('Y-m-d H:i:s', strtotime('+' . $months . ' months', $expires));
        if (method_exists($target, 'save')) {
            $target->save();
        }
        $list = json_decode((string) ($this->propertyValue($target->user, self::PROP_REFERRALS) ?? '[]'), true) ?: [];
        $list[] = (int) $friendService->id;
        $target->user->properties()->updateOrCreate(['key' => self::PROP_REFERRALS], ['name' => 'Friends who joined', 'value' => json_encode(array_values(array_unique($list)))]);
        self::logAdmin($target->user, (int) $target->id, 'A friend joined with their code: ' . $months . ' month(s) added, now renews ' . date('M j, Y', strtotime((string) $target->expires_at)), 'Paywarr');
        $this->notifyMail($target, 'friend_joined', ['months' => $months === 1 ? self::t('1 month') : self::t(':count months', ['count' => $months]),
            'product' => (string) ($target->product->name ?? ''), 'date' => date(self::t('M j, Y'), strtotime((string) $target->expires_at))], $this->referralPageUrl());

        return $months;
    }

    /** Whether the customer may see "Invite a friend": the feature is on and they have an active paid subscription. */
    public function canRefer($user): bool
    {
        if (!$user || !$this->referralOn()) {
            return false;
        }
        foreach (Service::where('user_id', $user->id)->where('status', 'active')->whereHas('product.server', fn ($q) => $q->where('extension', 'Wizarr'))->with('product.settings')->get() as $service) {
            $settings = ExtensionHelper::settingsToArray($service->product->settings);
            if (!$this->isTrial($settings) && !$this->isGift($settings) && !$this->isTopUp($settings)) {
                return true;
            }
        }

        return false;
    }

    /** GET and POST /wizarr/invite: the customer's code, and sending it to a friend. */
    public function referralPage()
    {
        if (($ext = $this->anyServer('referral_percent')) !== $this) {
            return $ext->referralPage();
        }
        $user = Auth::user();
        if (!$this->canRefer($user)) {
            abort(404);
        }
        $notice = '';
        $error = '';
        $code = $this->referralCode($user);
        if (request()->isMethod('post')) {
            try {
                $this->sendReferral($user, (string) request()->input('email'), (string) request()->input('name'));
                $notice = self::t('Sent! Your friend gets your code by email.');
            } catch (DisplayException $e) {
                $error = $e->getMessage();
            }
        }
        $e = fn ($v) => e((string) $v);
        $percent = (int) $this->config('referral_percent');
        $months = max(1, (int) ($this->config('referral_reward_months') ?: 1));
        $joined = count(json_decode((string) ($this->propertyValue($user, self::PROP_REFERRALS) ?? '[]'), true) ?: []);
        $body = '<p style="margin-bottom:1rem">' . $e(self::t('Give a friend :percent% off their first month. When they have paid, you get :months for free.', ['percent' => $percent, 'months' => $months === 1 ? self::t('1 month') : self::t(':count months', ['count' => $months])])) . '</p>'
            . '<div style="padding:1rem 1.2rem;border-radius:.9rem;border:1px dashed currentColor;margin-bottom:1rem;display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">'
            . '<div><div style="opacity:.7;font-size:.85rem">' . $e(self::t('Your code')) . '</div><div style="font-size:1.6rem;font-weight:800;letter-spacing:.06em">' . $e($code) . '</div></div>'
            . '<div style="opacity:.8">' . $e($joined === 1 ? self::t('1 friend has joined') : self::t(':count friends have joined', ['count' => $joined])) . '</div></div>'
            . '<div style="display:flex;gap:.5rem;margin-bottom:.5rem"><input id="paywarr-ref-link" readonly value="' . $e($this->referralUrl($code)) . '" class="bg-background-secondary border border-neutral rounded-lg px-3 py-2" style="flex:1;min-width:0" onclick="this.select()">'
            . '<button type="button" class="border border-neutral rounded-lg px-3 py-2" onclick="var i=document.getElementById(\'paywarr-ref-link\');i.select();try{navigator.clipboard.writeText(i.value)}catch(e){document.execCommand(\'copy\')}this.textContent=' . $e(json_encode(self::t('Copied!'))) . '">' . $e(self::t('Copy link')) . '</button></div>'
            . '<p style="opacity:.75;margin-bottom:1.4rem">' . $e(self::t('Share the link: your friend\'s discount is added in the cart by itself. Or they enter the code under "Coupon". It works once per friend, on a subscription.')) . '</p>'
            . '<h2 style="font-size:1.2rem;font-weight:700;margin-bottom:.6rem">' . $e(self::t('Send it by email')) . '</h2>'
            . '<form method="post" style="display:grid;gap:.9rem">' . csrf_field()
            . '<label style="display:grid;gap:.3rem"><span style="font-weight:600">' . $e(self::t('Their email')) . '</span><input type="email" name="email" required maxlength="190" class="bg-background-secondary border border-neutral rounded-lg px-3 py-2"></label>'
            . '<label style="display:grid;gap:.3rem"><span style="font-weight:600">' . $e(self::t('Their name (optional)')) . '</span><input type="text" name="name" maxlength="80" class="bg-background-secondary border border-neutral rounded-lg px-3 py-2"></label>'
            . '<button type="submit" class="bg-primary text-white font-semibold rounded-lg px-4 py-2" style="justify-self:start">' . $e(self::t('Send')) . '</button></form>';

        return view('layouts.app', ['slot' => new HtmlString($this->pageFrame(self::t('Invite a friend'), $body, $notice, $error)), 'title' => self::t('Invite a friend')]);
    }

    /** The link to share: the friend lands in the shop and the code is added in the cart. */
    public function referralUrl(string $code): string
    {
        try {
            return route('extensions.wizarr.join', ['code' => $code]);
        } catch (Throwable $e) {
            return url('/wizarr/join/' . rawurlencode($code));
        }
    }

    /** GET /wizarr/join/{code}: remembers a friend's code, then the shop. */
    public function joinPage($code)
    {
        $code = strtoupper(trim((string) $code));
        try {
            $coupon = \App\Models\Coupon::where('code', $code)->first();
            if ($coupon && ($owner = $this->referrerOfCoupon((int) $coupon->id)) !== null) {
                session(['paywarr_ref' => $code, 'paywarr_ref_percent' => (int) $coupon->value]);
                if (($url = $this->referrerProductUrl($owner, $coupon->products->pluck('id')->map(fn ($id) => (int) $id)->all())) !== null) {
                    return redirect()->to($url);
                }
            }
        } catch (Throwable $e) {
            // The shop anyway.
        }

        return redirect()->to($this->shopUrl());
    }

    /** The page of the product the customer has, when the friend's code is on it and it is in the shop. */
    private function referrerProductUrl(int $userId, array $products): ?string
    {
        foreach (Service::where('user_id', $userId)->where('status', 'active')->whereHas('product.server', fn ($q) => $q->where('extension', 'Wizarr'))
            ->with('product.category')->orderBy('id')->get() as $service) {
            $product = $service->product;
            if ($product && !$product->hidden && $product->category && (!$products || in_array((int) $product->id, $products, true))) {
                try {
                    return route('products.show', ['category' => $product->category, 'product' => $product]);
                } catch (Throwable $e) {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * After a friend's link: a note on every page until the code is in the
     * cart; on the cart page the code is put in "Coupon" and applied (once).
     */
    public function renderReferralNote(): array
    {
        try {
            $code = (string) session('paywarr_ref', '');
            if ($code === '') {
                return [];
            }
            $onCart = request()->routeIs('cart');
            if ($onCart && (string) (\App\Classes\Cart::get()->coupon?->code ?? '') === $code) {
                session()->forget(['paywarr_ref', 'paywarr_ref_percent']);

                return [];
            }
        } catch (Throwable $e) {
            return [];
        }
        $text = self::t('A friend invited you: :percent% off your first month with the code :code. It is added in the cart by itself.', ['percent' => (int) session('paywarr_ref_percent', 0), 'code' => $code]);
        $html = '<div id="paywarr-ref" style="background:linear-gradient(90deg,#0f766e,#7c3aed);color:#fff;padding:.7rem 1rem;margin:1rem auto;border-radius:.8rem;max-width:72rem;width:calc(100% - 2rem);box-sizing:border-box;font-size:.95rem;text-align:center">🎁 ' . e($text) . '</div>'
            . '<script>(function(){var b=document.getElementById("paywarr-ref");if(b){var t=document.querySelector("main")||document.body;t.insertBefore(b,t.firstChild);}})();</script>';
        if ($onCart) {
            // Paymenter's cart: fill "Coupon" and press Apply, once per visit.
            $html .= '<script>(function(){var code=' . json_encode($code) . ';function go(){try{if(sessionStorage.getItem("paywarr-ref-tried")===code)return;}catch(e){}'
                . 'var i=document.querySelector(\'input[name="coupon"]\'),a=Array.prototype.find.call(document.querySelectorAll("button"),function(x){return x.getAttribute("wire:click")==="applyCoupon"});'
                . 'if(!i||!a||!window.Livewire)return false;try{sessionStorage.setItem("paywarr-ref-tried",code)}catch(e){}i.value=code;i.dispatchEvent(new Event("input",{bubbles:true}));setTimeout(function(){a.click()},300);return true;}'
                . 'var n=0;(function wait(){if(go()===false&&++n<20)setTimeout(wait,400);})();document.addEventListener("livewire:navigated",function(){setTimeout(go,600)});})();</script>';
        }

        return ['view' => new HtmlString($html)];
    }

    /** Mails the code to a friend. At most 5 an hour. */
    public function sendReferral($user, string $email, string $name = ''): void
    {
        if (($ext = $this->anyServer('referral_percent')) !== $this) {
            $ext->sendReferral($user, $email, $name);

            return;
        }
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DisplayException(self::t('That email address doesn\'t look right.'));
        }
        if (strcasecmp($email, (string) $user->email) === 0) {
            throw new DisplayException(self::t('That is your own email address.'));
        }
        $key = 'wizarr-refer:' . $user->id;
        if (class_exists(RateLimiter::class) && RateLimiter::tooManyAttempts($key, 5)) {
            throw new DisplayException(self::t('You have sent it a few times now. Please try again in an hour.'));
        }
        if (class_exists(RateLimiter::class)) {
            RateLimiter::hit($key, 3600);
        }
        $from = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: (string) $user->email;
        $code = $this->referralCode($user);
        $title = self::t(':name invites you', ['name' => $from]);
        $body = '<p style="margin:0 0 12px">' . e(trim($name) !== '' ? self::t('Hi :name,', ['name' => trim($name)]) : self::t('Hi,')) . '</p>'
            . '<p style="margin:0 0 12px">' . e(self::t(':from watches with us and thinks you would like it too. With their code you get :percent% off your first month:', ['from' => $from, 'percent' => (int) $this->config('referral_percent')])) . '</p>'
            . '<p style="margin:0 0 16px;font-size:22px;font-weight:800;letter-spacing:.06em">' . e($code) . '</p>'
            . '<p style="margin:0 0 12px">' . e(self::t('Choose a subscription with the button: the code is added in the cart by itself.')) . '</p>';
        if (!$this->sendMail($email, $title, $this->mailLayout($title, $body, [self::t('Choose a subscription'), $this->referralUrl($code)]))) {
            throw new DisplayException(self::t('The email couldn\'t be sent right now. Please try again shortly.'));
        }
        Log::info('Wizarr: user ' . $user->id . ' sent their code for friends.');
    }

    private function referralPageUrl(): string
    {
        try {
            return route('extensions.wizarr.invite');
        } catch (Throwable $e) {
            return '/wizarr/invite';
        }
    }
}
