<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Customers change their media server themselves ("Change media server"):
 * free to a choice of the same price on their product (the "media_server"
 * option, or the product's servers), at most once a month; a dearer choice
 * goes through Paymenter's own upgrade. The old user stays until the new
 * one is made, or 7 days. Part of Wizarr (Paywarr).
 */
trait SwitchServer
{
    /** Service: the servers kept while moving ("1,4"), and when the move began. */
    public const PROP_SWITCH_KEEP = 'switch_keep';

    private const PROP_SWITCH_AT = 'switched_at';

    /**
     * What the customer may choose: value => [label, price (null: unknown), current, free].
     * Empty when there is nothing to choose.
     */
    public function switchChoices(Service $service): array
    {
        $settings = ExtensionHelper::settingsToArray($service->product->settings ?? []);
        if ($this->isTrial($settings) || $this->isGift($settings) || $this->isTopUp($settings) || $service->status !== 'active') {
            return [];
        }
        $properties = ExtensionHelper::getServiceProperties($service);
        $choices = [];
        $config = $this->mediaOptionConfig($service);
        if ($config !== null) {
            [$option, $current] = $config;
            $currentPrice = $this->optionValuePrice($current, $service);
            foreach ($option->children ?? [] as $child) {
                $price = $this->optionValuePrice($child, $service);
                $choices['option:' . $child->id] = [(string) $child->name, $price, (int) $child->id === (int) $current->id,
                    $price !== null && $currentPrice !== null && abs($price - $currentPrice) < 0.005];
            }

            return count($choices) > 1 ? $choices : [];
        }
        if (trim((string) ($properties[self::MEDIA_OPTION] ?? '')) !== '') {
            return []; // chosen with the option, which can't be read here
        }
        $product = $this->idList($settings['server_ids'] ?? null);
        if (count($product) < 2) {
            return [];
        }
        $have = $this->idList((string) ($properties[self::PROP_SERVERS] ?? '')) ?: $product;
        if (count($have) >= count($product)) {
            return []; // has every server already
        }
        try {
            $info = $this->serverInfo();
        } catch (Throwable $e) {
            return [];
        }
        foreach ($product as $id) {
            $choices['server:' . $id] = [(string) ($info[$id]['name'] ?? 'server ' . $id), null, in_array($id, $have, true), true];
        }

        return $choices;
    }

    /** Whether there is anything to choose, without asking Wizarr (for the button on every page view). */
    public function canSwitch(Service $service, array $settings, array $properties): bool
    {
        if ($this->isTrial($settings) || $this->isGift($settings) || $this->isTopUp($settings) || $service->status !== 'active') {
            return false;
        }
        if (trim((string) ($properties[self::MEDIA_OPTION] ?? '')) !== '') {
            $config = $this->mediaOptionConfig($service);

            return $config !== null && count($config[0]->children ?? []) > 1;
        }
        $product = $this->idList($settings['server_ids'] ?? null);
        $have = $this->idList((string) ($properties[self::PROP_SERVERS] ?? '')) ?: $product;

        return count($product) > 1 && count($have) < count($product);
    }

    /** The service's "media_server" option and its chosen value, or null. */
    private function mediaOptionConfig(Service $service): ?array
    {
        try {
            foreach ($service->configs()->with(['configOption.children', 'configValue'])->get() as $config) {
                if (($config->configOption->env_variable ?? '') === self::MEDIA_OPTION && $config->configValue) {
                    return [$config->configOption, $config->configValue, $config];
                }
            }
        } catch (Throwable $e) {
            return null;
        }

        return null;
    }

    /** A value's price for the service's billing period and currency; null when it can't be told. */
    private function optionValuePrice($value, Service $service): ?float
    {
        try {
            $plan = $service->plan;
            $match = $value->plans()->where('billing_period', $plan->billing_period ?? 1)->where('billing_unit', $plan->billing_unit ?? 'month')->first()
                ?? $value->plans()->first();
            if (!$match) {
                return 0.0; // a value without a plan costs nothing
            }
            if (($match->type ?? '') === 'free') {
                return 0.0;
            }
            $price = $match->prices()->where('currency_code', $service->currency_code)->value('price');

            return $price === null ? null : (float) $price;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function switchRefusal(Service $service, ?int $now = null): ?string
    {
        $now ??= time();
        $properties = ExtensionHelper::getServiceProperties($service);
        if (!empty($properties[self::PROP_SWITCH_KEEP])) {
            return self::t('You are moving already: your new user first.');
        }
        if ($now - (int) ($properties[self::PROP_SWITCH_AT] ?? 0) < 30 * 86400) {
            return self::t('You can change your media server once a month.');
        }

        return null;
    }

    /** Moves the subscription to $choice (a key of switchChoices()). */
    public function switchServer(Service $service, string $choice, ?int $now = null): void
    {
        $now ??= time();
        $choices = $this->switchChoices($service);
        if (!isset($choices[$choice]) || $choices[$choice][2]) {
            throw new DisplayException(self::t('Choose another media server.'));
        }
        if (!$choices[$choice][3]) {
            throw new DisplayException(self::t('That one costs more: use Upgrade on your subscription.'));
        }
        if (($refusal = $this->switchRefusal($service, $now)) !== null) {
            throw new DisplayException($refusal);
        }
        $settings = ExtensionHelper::settingsToArray($service->product->settings);
        $before = $this->chosenServerIds($settings, ExtensionHelper::getServiceProperties($service));
        $this->setProperty($service, self::PROP_SWITCH_KEEP, 'Moving: keeps the old server until the new user exists', implode(',', $before) . '|' . $now);
        $this->setProperty($service, self::PROP_SWITCH_AT, 'Changed media server', (string) $now);
        [$kind, $value] = explode(':', $choice, 2);
        if ($kind === 'option') {
            $this->mediaOptionConfig($service)[2]->update(['config_value_id' => (int) $value]);
            $service->unsetRelation('configs');
        } else {
            $this->setProperty($service, self::PROP_SERVERS, 'Wizarr media servers', $value);
        }
        $this->upgradeServer($service, $settings, ExtensionHelper::getServiceProperties($service));
        self::logAdmin($service->user, (int) $service->id, 'Changed media server to ' . $choices[$choice][0] . ' (customer)', 'customer');
        $this->forgetCustomerCaches($service);
    }

    /**
     * Hourly: a move ends when the new user exists or after 7 days; the
     * old server's user is removed then.
     */
    public function finishSwitches(?iterable $services = null, ?int $now = null): array
    {
        $now ??= time();
        $services ??= Service::whereIn('status', ['active', 'suspended'])->whereHas('properties', fn ($q) => $q->where('key', self::PROP_SWITCH_KEEP))
            ->with(['product.server', 'product.settings', 'properties', 'user'])->get();
        $done = [];
        foreach ($services as $service) {
            $properties = ExtensionHelper::getServiceProperties($service);
            $keep = (string) ($properties[self::PROP_SWITCH_KEEP] ?? '');
            if ($keep === '') {
                continue;
            }
            [$old, $at] = array_pad(explode('|', $keep, 2), 2, '0');
            $ext = $this->forServiceServer($service);
            $settings = ExtensionHelper::settingsToArray($service->product->settings);
            $properties[self::PROP_SWITCH_KEEP] = '';
            $new = array_diff($ext->chosenServerIds($settings, $properties), $ext->idList($old));
            $ready = $new && !array_filter($new, fn ($id) => empty($properties[self::PROP_USERS . ':' . $id]));
            if (!$ready && $now - (int) $at < 7 * 86400) {
                continue;
            }
            try {
                $this->setProperty($service, self::PROP_SWITCH_KEEP, '', null);
                $ext->upgradeServer($service, $settings, ExtensionHelper::getServiceProperties($service));
                $done[] = (int) $service->id;
            } catch (Throwable $e) {
                Log::warning('Wizarr: the move of service ' . $service->id . ' not finished, will retry: ' . $e->getMessage());
            }
        }

        return $done;
    }

    /** GET and POST /wizarr/switch/{service}. */
    public function switchPage($service)
    {
        $service = Service::with(['product.settings', 'product.server', 'properties', 'user', 'plan'])->find((int) $service);
        if (!$service || (int) $service->user_id !== (int) Auth::id() || ($service->product?->server?->extension ?? null) !== 'Wizarr') {
            abort(404);
        }
        $ext = $this->forServiceServer($service);
        $notice = '';
        $error = '';
        if (request()->isMethod('post')) {
            try {
                $ext->switchServer($service, (string) request()->input('choice'));
                $notice = self::t('Done! Your new invitation is on its way by mail, and on your subscription. Your old user stays until you have made the new one (at most 7 days).');
                $service = $service->fresh(['product.settings', 'product.server', 'properties', 'user', 'plan']) ?? $service;
            } catch (DisplayException $e) {
                $error = $e->getMessage();
            }
        }
        $e = fn ($v) => e((string) $v);
        $choices = $ext->switchChoices($service);
        $refusal = $ext->switchRefusal($service);
        $html = '<p style="margin-bottom:1rem">' . $e(self::t('Move :product to another media server. You get a new invitation; your old user stays until you have made the new one.', ['product' => (string) ($service->product->name ?? '')])) . '</p>';
        if (!$choices) {
            $html .= '<p>' . $e(self::t('There is no other media server to choose on this subscription.')) . '</p>';
        } else {
            $html .= '<form method="post" style="display:grid;gap:.6rem">' . csrf_field();
            foreach ($choices as $key => [$label, $price, $current, $free]) {
                $html .= '<label class="bg-background-secondary" style="display:flex;gap:.7rem;align-items:center;padding:.8rem 1rem;border-radius:.8rem;' . ($current || !$free ? 'opacity:.6' : '') . '">'
                    . '<input type="radio" name="choice" value="' . $e($key) . '"' . ($current || !$free || $refusal ? ' disabled' : '') . '>'
                    . '<span style="flex:1;font-weight:600">' . $e($label) . '</span><span style="opacity:.75;font-size:.9rem">'
                    . $e($current ? self::t('Now') : ($free ? self::t('Same price') : self::t('Costs more: use Upgrade'))) . '</span></label>';
            }
            $html .= ($refusal ? '<p style="opacity:.75">' . $e($refusal) . '</p>' : '<button type="submit" class="bg-primary text-white font-semibold rounded-lg px-4 py-2" style="justify-self:start">' . $e(self::t('Change')) . '</button>') . '</form>';
            try {
                $html .= '<p style="margin-top:1rem;opacity:.8"><a style="text-decoration:underline" href="' . $e(route('services.upgrade', $service)) . '">' . $e(self::t('Upgrade to another product')) . '</a></p>';
            } catch (Throwable $ex) {
                // No upgrade page.
            }
        }

        return view('layouts.app', ['slot' => new HtmlString($this->pageFrame(self::t('Change media server'), $html, $notice, $error)), 'title' => self::t('Change media server')]);
    }

    private function switchPageUrl(Service $service): string
    {
        try {
            return route('extensions.wizarr.switch', ['service' => $service->id]);
        } catch (Throwable $e) {
            return '/wizarr/switch/' . $service->id;
        }
    }
}
