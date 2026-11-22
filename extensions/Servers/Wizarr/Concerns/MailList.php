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

    /** The line under a list mail, with the link to leave the list. */
    public function mailListFooter($user): string
    {
        try {
            $off = URL::signedRoute('extensions.wizarr.news.off', ['user' => $user->id]);
        } catch (Throwable $e) {
            return e(self::t('You get this because you are on our mail list. You can leave it under Account.'));
        }

        return e(self::t('You get this because you are on our mail list.')) . ' <a href="' . e($off) . '" style="color:#8f87a8">' . e(self::t('Leave the mail list')) . '</a>';
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
}
