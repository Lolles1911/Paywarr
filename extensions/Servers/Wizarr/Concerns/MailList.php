<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use Illuminate\Support\Facades\Cache;
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
}
