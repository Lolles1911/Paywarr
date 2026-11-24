<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * The mail list: news and offers (campaigns, movie nights and challenges,
 * "New on the server", messages from admin, "we'd love to have you back") go
 * by mail only to customers who ticked "Send me news and offers by mail",
 * when they made their account (also at checkout) or under Account. It is a
 * Paymenter customer field Paywarr makes once. Everyone starts off the list;
 * mails about their own subscription always go. Every list mail has a link
 * to leave the list. Part of Wizarr (Paywarr).
 */
trait MailList
{
    public const PROP_MAIL_LIST = 'paywarr_mail_list';

    /** Customer: the kinds of mail they turned off ("events,reminders"). */
    public const PROP_MAIL_OFF = 'paywarr_mail_off';

    /** Where sendMail() puts the line to unsubscribe (mailLayout() leaves it). */
    public const MAIL_FOOTER_SLOT = '<!--paywarr-unsubscribe-->';

    /**
     * Mail kinds a customer can turn off (essential ones always go): news
     * and events only to the mail list; reminders and stats unless turned off.
     */
    public const MAIL_KINDS = ['news', 'events', 'reminders', 'stats'];

    /** Makes the customer field once (Admin → Configuration → Custom Properties, where you can rename it). */
    public static function ensureMailListField(): void
    {
        try {
            if (Cache::get('paywarr:mail-list-field')) {
                return;
            }
            \App\Models\CustomProperty::firstOrCreate(['key' => self::PROP_MAIL_LIST], [
                'name' => self::t('Send me news and offers by mail'),
                'description' => self::t('Offers, movie nights and what\'s new on the server. You can stop any time under Account.'),
                'type' => 'checkbox', 'model' => 'App\Models\User', 'validation' => null, 'allowed_values' => null,
                'non_editable' => false, 'required' => false, 'show_on_invoice' => false,
            ]);
            Cache::forever('paywarr:mail-list-field', true);
        } catch (Throwable $e) {
            Log::warning('Wizarr: the mail list field was not made: ' . $e->getMessage());
        }
    }

    public static function mailListValue(?string $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    /** Whether the customer is on the mail list. */
    public function onMailList($user): bool
    {
        return $user !== null && self::mailListValue($this->propertyValue($user, self::PROP_MAIL_LIST));
    }

    /** Whether the customer gets mails of $kind. */
    public function wantsMail($user, string $kind): bool
    {
        if ($kind === 'essential' || !in_array($kind, self::MAIL_KINDS, true)) {
            return true;
        }
        if (in_array($kind, ['news', 'events'], true) && !$this->onMailList($user)) {
            return false;
        }

        return !in_array($kind, self::mailOff($user, fn ($key) => $this->propertyValue($user, $key)), true);
    }

    /** The kinds a customer turned off. */
    public static function mailOff($user, callable $read): array
    {
        return array_values(array_filter(explode(',', (string) ($read(self::PROP_MAIL_OFF) ?? ''))));
    }

    public static function mailKindLabel(string $kind): string
    {
        return match ($kind) {
            'news' => self::t('news and offers'),
            'events' => self::t('movie nights and challenges'),
            'reminders' => self::t('reminders'),
            default => self::t('stats and surveys'),
        };
    }

    /** One click: no more mails of $kind (signed; no login). $now: for the mail program's own button. */
    public function unsubscribeUrl($user, string $kind, bool $now = false): ?string
    {
        try {
            return URL::signedRoute($now ? 'extensions.wizarr.mail.off.now' : 'extensions.wizarr.mail.off', ['user' => $user->id, 'kind' => $kind]);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function unsubscribeFooter(string $kind, string $url): string
    {
        return '<div style="margin-bottom:8px">' . e(self::t('You get this because you get :kind from us.', ['kind' => self::mailKindLabel($kind)]))
            . ' <a href="' . e($url) . '" style="color:#8f87a8">' . e(self::t('Unsubscribe from :kind', ['kind' => self::mailKindLabel($kind)])) . '</a></div>';
    }

    /** Turns a kind on or off for a customer. */
    public static function setMailKind($user, string $kind, bool $on): void
    {
        if ($kind === 'news') {
            $user->properties()->updateOrCreate(['key' => self::PROP_MAIL_LIST], ['name' => self::t('Send me news and offers by mail'), 'value' => $on ? '1' : '0']);

            return;
        }
        $off = self::mailOff($user, fn ($key) => $user->properties()->where('key', $key)->value('value'));
        $off = $on ? array_values(array_diff($off, [$kind])) : array_values(array_unique([...$off, $kind]));
        $user->properties()->updateOrCreate(['key' => self::PROP_MAIL_OFF], ['name' => 'Mails turned off', 'value' => implode(',', $off)]);
        if ($on && $kind === 'events') {
            $user->properties()->updateOrCreate(['key' => self::PROP_MAIL_LIST], ['name' => self::t('Send me news and offers by mail'), 'value' => '1']);
        }
    }

    /**
     * GET and POST /wizarr/mail/off/{user}/{kind} (signed): off, with a button
     * to turn it back on. A POST without a session is the mail program's own
     * one-click button.
     */
    public function mailOffPage($user, $kind)
    {
        $user = \App\Models\User::find((int) $user);
        if (!$user || !in_array($kind, self::MAIL_KINDS, true)) {
            abort(404);
        }
        $on = request()->isMethod('post') && request()->input('on') === '1';
        $form = URL::signedRoute('extensions.wizarr.mail.off.web', ['user' => $user->id, 'kind' => $kind]);
        $was = $this->wantsMail($user, $kind);
        self::setMailKind($user, $kind, $on);
        if ($was && !$on) {
            self::countMail('mail_unsubs', $kind);
        }
        if (request()->isMethod('post') && !request()->has('on')) {
            return response('OK');
        }
        $e = fn ($v) => e((string) $v);
        $label = self::mailKindLabel($kind);
        $html = '<p style="margin-bottom:1rem">' . $e($on ? self::t('Done: you get :kind by mail again.', ['kind' => $label]) : self::t('Done: no more :kind by mail. Mails about your access and subscription still come.', ['kind' => $label])) . '</p>'
            . '<form method="post" action="' . $e($form) . '">' . csrf_field() . '<input type="hidden" name="on" value="' . ($on ? '0' : '1') . '">'
            . '<button type="submit" class="border border-neutral rounded-lg px-4 py-2">' . $e($on ? self::t('Unsubscribe again') : self::t('Oops, turn it back on')) . '</button></form>';

        return view('layouts.app', ['slot' => new HtmlString($this->pageFrame(self::t('Mail settings'), $html)), 'title' => self::t('Mail settings')]);
    }

    /**
     * At checkout: "Send me news and offers by mail" above the Checkout
     * button, for signed-in customers not on the list (new customers tick
     * it when they make their account). Paymenter's cart has no place for
     * it, so a small script puts it there; ticking saves it at once.
     */
    public function renderCheckoutOptIn(): array
    {
        try {
            if (!Auth::check() || !request()->routeIs('cart') || $this->onMailList(Auth::user())) {
                return [];
            }
            $url = route('extensions.wizarr.mail-list');
        } catch (Throwable $e) {
            return [];
        }
        $label = e(self::t('Send me news and offers by mail'));
        $hint = e(self::t('Offers, movie nights and what\'s new on the server. You can stop any time under Account.'));

        return ['view' => new HtmlString('<template id="paywarr-optin"><label data-paywarr-optin style="display:flex;gap:.6rem;align-items:flex-start;margin:.2rem 0 .8rem;cursor:pointer">'
            . '<input type="checkbox" class="form-checkbox size-4 text-primary rounded" style="margin-top:.2rem">'
            . '<span><span style="font-size:.9rem">' . $label . '</span><br><span style="font-size:.8rem;opacity:.65">' . $hint . '</span></span></label></template>'
            . '<script>(function(){function put(){var b=Array.prototype.find.call(document.querySelectorAll("button"),function(x){return x.getAttribute("wire:click")==="checkout"}),t=document.getElementById("paywarr-optin");'
            . 'if(!b||!t||document.querySelector("[data-paywarr-optin]"))return;var box=t.content.firstElementChild.cloneNode(true),at=b.closest("div")||b;at.parentNode.insertBefore(box,at);box.querySelector("input").checked=!!window.paywarrOptIn;'
            . 'box.querySelector("input").addEventListener("change",function(e){window.paywarrOptIn=e.target.checked;var m=document.querySelector(\'meta[name="csrf-token"]\');'
            . 'fetch(' . json_encode($url) . ',{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json","Accept":"application/json","X-CSRF-TOKEN":m?m.content:""},body:JSON.stringify({on:e.target.checked})});});}'
            . 'put();document.addEventListener("livewire:navigated",put);if(!window.paywarrOptInWatch){window.paywarrOptInWatch=new MutationObserver(function(){put()});window.paywarrOptInWatch.observe(document.body,{childList:true,subtree:true});}})();</script>')];
    }

    /** POST /wizarr/mail-list: on or off the list (the checkbox at checkout). */
    public function mailListToggle()
    {
        $on = (bool) request()->input('on');
        Auth::user()->properties()->updateOrCreate(['key' => self::PROP_MAIL_LIST], ['name' => self::t('Send me news and offers by mail'), 'value' => $on ? '1' : '0']);

        return response()->json(['on' => $on]);
    }

    /**
     * Customers who made their account before the mail list existed are asked
     * once, in the client area: "News and offers by mail?" Yes / No thanks.
     * Not by mail (asking for consent by mail is marketing in itself).
     */
    public function renderMailListAsk(): array
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return [];
            }
            // From when accounts get the question at sign-up; older ones are asked here.
            $since = (int) Cache::rememberForever('paywarr:mail-list-since', fn () => self::storeGet('mail_list_since') ?: tap(time(), fn ($t) => self::storePut('mail_list_since', $t)));
            if (request()->routeIs('cart') || request()->is('admin*')
                || $this->propertyValue($user, self::PROP_MAIL_LIST) !== null
                || ($this->timestampOf($user->created_at ?? null) ?? time()) >= $since) {
                return [];
            }
            $url = route('extensions.wizarr.mail-list');
        } catch (Throwable $e) {
            return [];
        }
        $e = fn ($v) => e((string) $v);

        return ['view' => new HtmlString('<div id="paywarr-ask" class="bg-background-secondary" role="dialog" aria-labelledby="paywarr-ask-title" style="position:fixed;left:1rem;bottom:1rem;z-index:60;max-width:22rem;width:calc(100% - 2rem);box-sizing:border-box;border-radius:1rem;padding:1.1rem 1.2rem;border:1px solid rgba(127,127,127,.3);box-shadow:0 12px 40px rgba(0,0,0,.45)">'
            . '<div id="paywarr-ask-title" style="font-weight:700;margin-bottom:.3rem">' . $e(self::t('News and offers by mail?')) . '</div>'
            . '<div style="font-size:.9rem;opacity:.8;margin-bottom:.8rem">' . $e(self::t('Offers, movie nights and what\'s new on the server. You can stop any time under Account.')) . '</div>'
            . '<div style="display:flex;gap:.5rem;flex-wrap:wrap"><button type="button" data-on="1" class="bg-primary text-white font-semibold rounded-lg px-4 py-2">' . $e(self::t('Yes please')) . '</button>'
            . '<button type="button" data-on="0" class="border border-neutral rounded-lg px-4 py-2">' . $e(self::t('No thanks')) . '</button></div></div>'
            . '<script>(function(){var box=document.getElementById("paywarr-ask");if(!box)return;box.querySelectorAll("button[data-on]").forEach(function(b){b.addEventListener("click",function(){'
            . 'var m=document.querySelector(\'meta[name="csrf-token"]\');fetch(' . json_encode($url) . ',{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json","Accept":"application/json","X-CSRF-TOKEN":m?m.content:""},body:JSON.stringify({on:b.dataset.on==="1"})});'
            . 'box.innerHTML="<div style=\\"font-weight:600\\">"+' . json_encode(self::t('Thank you!')) . '+"</div>";setTimeout(function(){box.remove()},1500);});});})();</script>')];
    }
}
