<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Models\Service;
use Throwable;

/**
 * Paywarr → Mail: every automatic mail in one list (with your own words or
 * switched off), a short log of what was sent, and the design (colour, logo,
 * a line at the bottom). Part of Wizarr (Paywarr).
 */
trait MailCenter
{
    /**
     * The automatic mails. "make" gives [title, text, button] from the mail's
     * values; mails with "html" have a body of their own (only on/off and the
     * subject can change). "vars" are the {placeholders} with example values.
     */
    public static function mailCatalog(): array
    {
        return [
            'welcome' => ['name' => 'Welcome', 'kind' => 'essential', 'html' => true,
                'about' => 'When a subscription opens: the link to create the user, getting started, apps and Discord ("Welcome mail" on the server).'],
            'user_waiting' => ['name' => 'User not created yet', 'kind' => 'reminders', 'vars' => ['product' => 'Plus'],
                'about' => 'Two days after, when the customer hasn\'t used the link ("Remind" on the server).',
                'make' => fn ($v) => [self::t('Your user is waiting'), self::t('You haven\'t created your user for :product yet. Your personal link is ready; it only takes a minute.', $v), self::t('Create your user')]],
            'trial_ending' => ['name' => 'Trial ends soon', 'kind' => 'reminders', 'vars' => ['product' => 'Plus', 'date' => 'Dec 1, 2026'],
                'about' => 'Two days before a trial ends.',
                'make' => fn ($v) => [self::t('Your trial ends soon'), self::t('Your trial of :product ends on :date. Continue with a subscription and keep your user and everything you have watched.', $v), self::t('See subscriptions')]],
            'renewal' => ['name' => 'Renews soon', 'kind' => 'reminders', 'vars' => ['product' => 'Plus', 'date' => 'Dec 1, 2026'],
                'about' => 'Three days before renewal, when the invoice is unpaid and not paid automatically.',
                'make' => fn ($v) => [self::t('Your subscription renews soon'), self::t(':product renews on :date. Pay the invoice before then to keep watching without a break.', $v), self::t('Pay the invoice')]],
            'cart' => ['name' => 'Unpaid order', 'kind' => 'reminders', 'vars' => ['product' => 'Plus'],
                'about' => 'An order not paid yet ("Unpaid orders" on the server).',
                'make' => fn ($v) => [self::t('Forgot something?'), self::t('Your order of :product is waiting for payment. Pay the invoice and your access is ready a moment later.', $v), self::t('Pay the invoice')]],
            'still_watching' => ['name' => 'Free access: still watching?', 'kind' => 'reminders', 'vars' => ['days' => '30', 'date' => 'Dec 1, 2026'],
                'about' => 'Free access not used for a while, before it ends (Free access rules).',
                'make' => fn ($v) => [self::t('Still watching?'), self::t('You haven\'t watched anything for :days days. Your free access ends on :date unless you watch something before then; just start anything and it stays.', $v), self::t('Start watching')]],
            'moved_to_paid' => ['name' => 'Free access becomes paid', 'kind' => 'essential', 'vars' => ['product' => 'Plus', 'price' => '49.00 DKK', 'date' => 'Dec 1, 2026', 'percent' => '20'],
                'about' => 'When you move a free user to a paid subscription. {percent} is the welcome discount (empty: none).',
                'make' => fn ($v) => [self::t('Your free access becomes a subscription'), self::t('Your access continues as :product at :price a period. Pay the invoice by :date to keep your user and everything you have watched; unpaid, the access stops then.', $v)
                    . (!in_array((string) ($v['percent'] ?? ''), ['', '0'], true) ? ' ' . self::t('As a welcome, your first invoice is :percent% off.', $v) : ''), self::t('Pay the invoice')]],
            'pay_soon' => ['name' => 'Moved to paid: access stops soon', 'kind' => 'essential', 'vars' => ['product' => 'Plus', 'count' => '3'],
                'about' => 'Before the access of a moved user stops, when the invoice is unpaid.',
                'make' => fn ($v) => [self::t('Your access stops soon'), ($v['count'] ?? '') === '1' ? self::t('Pay the invoice for :product by tomorrow to keep watching.', $v) : self::t('Pay the invoice for :product within :count days to keep watching.', $v), self::t('Pay the invoice')]],
            'paused' => ['name' => 'Paused', 'kind' => 'essential', 'vars' => ['product' => 'Plus', 'date' => 'Dec 1, 2026', 'days' => '14'],
                'about' => 'When the customer pauses their subscription.',
                'make' => fn ($v) => [self::t('Your subscription is paused'), self::t('Your subscription to :product is paused until :date. Your user and everything you have watched are kept, and your renewal date moves :days days. Want to watch before then? You can resume at any time.', $v), self::t('Resume now')]],
            'streams' => ['name' => 'Too many streams', 'kind' => 'essential', 'vars' => ['product' => 'Plus', 'count' => '2', 'n' => '3', '_stopped' => '1'],
                'about' => 'More streams at once than the subscription allows (at most once a day).',
                'make' => fn ($v) => [self::t('Too many streams at once'), ($v['_stopped'] ?? '') !== ''
                    ? self::t(':product allows :count stream(s) at the same time, and :n were playing, so the newest was stopped. Is someone else using your account? Change your password, or choose a subscription with more streams.', $v)
                    : self::t(':product allows :count stream(s) at the same time, and :n were playing. Is someone else using your account? Change your password, or choose a subscription with more streams.', $v), self::t('See subscriptions')]],
            'discord_left' => ['name' => 'Left the Discord', 'kind' => 'essential', 'vars' => ['service' => 'Plus', 'hours' => '24'],
                'about' => 'A customer left your Discord (when the Discord is required).',
                'make' => fn ($v) => [self::t('You have left our Discord'), self::t('Your subscription :service needs you on our Discord. Join again within :hours hours, or your access will be paused.', $v), self::t('Open the Discord page')]],
            'discord_paused' => ['name' => 'Paused: not on the Discord', 'kind' => 'essential', 'vars' => ['service' => 'Plus'],
                'about' => 'Access paused because they didn\'t come back to the Discord.',
                'make' => fn ($v) => [self::t('Your access is paused'), self::t('You are no longer on our Discord, so :service is paused. Join our Discord again and it opens by itself.', $v), self::t('Open the Discord page')]],
            'discord_back' => ['name' => 'Back on the Discord', 'kind' => 'essential', 'vars' => ['service' => 'Plus'],
                'about' => 'Access open again after joining the Discord.',
                'make' => fn ($v) => [self::t('Your access is back'), self::t('Welcome back on our Discord. :service is open again.', $v), self::t('Open the Discord page')]],
            'gift_code' => ['name' => 'Gift code bought', 'kind' => 'essential', 'vars' => ['code' => 'GIFT-AB12-CD34', 'months' => '1 month', 'date' => 'Dec 1, 2027'],
                'about' => 'After buying a gift card: the code.',
                'make' => fn ($v) => [self::t('Your gift code'), self::t('Thank you! Your gift code is :code. It gives :months of a subscription and can be used once, until :date. Send it to a friend from your subscription, or give it yourself: it is entered under "Coupon" in the cart.', $v), self::t('Send it to a friend')]],
            'friend_joined' => ['name' => 'A friend joined', 'kind' => 'essential', 'vars' => ['months' => '1 month', 'product' => 'Plus', 'date' => 'Jan 1, 2027'],
                'about' => 'A friend bought with the customer\'s invite code; they got free time.',
                'make' => fn ($v) => [self::t('A friend joined: you got :months', $v), self::t('Thank you for bringing a friend! :product now renews on :date.', $v), self::t('Invite another friend')]],
            'pass_ends' => ['name' => 'Friend pass ends tomorrow', 'kind' => 'essential', 'vars' => ['code' => '', 'percent' => '', 'date' => ''],
                'about' => 'To the friend, the day before their free week ends. {code}: a running campaign\'s code, if any.',
                'make' => fn ($v) => [self::t('Your free week ends tomorrow'), self::passOffer($v), self::t('See subscriptions')]],
            'pass_over' => ['name' => 'Friend pass is over', 'kind' => 'essential', 'vars' => ['code' => '', 'percent' => '', 'date' => ''],
                'about' => 'To the friend, when the free week ended without buying.',
                'make' => fn ($v) => [self::t('Your free week is over'), self::passOffer($v), self::t('See subscriptions')]],
            'inactive' => ['name' => 'Watched nothing for a while', 'kind' => 'reminders', 'vars' => ['name' => 'Anna', 'product' => 'Plus', 'days' => '14', 'titles' => 'Dune, Severance, The Bear'],
                'about' => 'A paying customer who watched nothing for a while, at most once a month ("Remind customers who watched nothing" in Paywarr Stats, or "Send a reminder" under At risk). {titles}: a few new titles.',
                'make' => fn ($v) => [self::t('We miss you'), (($v['name'] ?? '') !== '' ? self::t('Hi :name,', $v) . "\n\n" : '')
                    . self::t('You haven\'t watched anything on :product for :days days. Is something not working? Tell us with "Report a problem".', $v)
                    . (($v['titles'] ?? '') !== '' ? "\n\n" . self::t('New since then: :titles. Something for tonight?', $v) : ''), self::t('Start watching')]],
            'extra_days' => ['name' => 'Extra days', 'kind' => 'essential', 'vars' => ['days' => '7', 'product' => 'Plus', 'date' => 'Dec 8, 2026'],
                'about' => 'When you give extra days under Customers (several at once, "Tell them").',
                'make' => fn ($v) => [self::t('You got :days extra days', $v), self::t(':product now runs until :date, at no cost. Enjoy!', $v), self::t('See your subscription')]],
            'price_change' => ['name' => 'Price change', 'kind' => 'essential', 'vars' => ['product' => 'Plus', 'old' => '49,00 DKK', 'new' => '59,00 DKK', 'date' => 'Jan 1, 2027'],
                'about' => 'When you change a price under Price changes: to everyone on that plan, at least 30 days before.',
                'make' => fn ($v) => [self::t('New price for :product from :date', $v), self::t(':product will cost :new a period from :date (now :old). It applies to your renewals from that date; you don\'t need to do anything. If you would rather stop or change subscription, you can do that before then.', $v), self::t('See your subscription')]],
            'waitlist' => ['name' => 'A seat is free (waiting list)', 'kind' => 'essential', 'vars' => ['product' => 'Plus', 'hours' => '48'],
                'about' => 'To the next on the waiting list when a full media server gets a free seat (Capacity).',
                'make' => fn ($v) => [self::t('A seat is free for you'), self::t('Good news: a seat is free on :product. Order soon: the shop is open to everyone again, and after :hours hours we tell the next on the waiting list.', $v), self::t('Order now')]],
            'party_invite' => ['name' => 'Movie night invitation', 'kind' => 'events', 'vars' => ['title' => 'Dune', 'date' => 'Dec 5, 20:00', 'about' => ''],
                'about' => 'When you plan a movie night with "Announce". {about}: your text for the night.',
                'make' => fn ($v) => [self::t('Movie night: :title', $v), self::t('Movie night: :title on :date. Watch along with everyone; press "I\'m coming" and we remind you an hour before.', $v)
                    . (($v['about'] ?? '') !== '' ? "\n\n" . $v['about'] : ''), self::t('I\'m coming')]],
            'party_reminder' => ['name' => 'Movie night in an hour', 'kind' => 'events', 'vars' => ['title' => 'Dune', 'time' => '20:00'],
                'about' => 'An hour before, to those coming.',
                'make' => fn ($v) => [self::t('Movie night in an hour'), self::t(':title starts at :time. Open your media server a little before, and press play at :time.', $v), self::t('Open movie night')]],
            'challenge_won' => ['name' => 'Challenge won', 'kind' => 'events', 'vars' => ['title' => 'Ten films in November', 'prize' => '7 days free'],
                'about' => 'A customer reached a challenge\'s goal.',
                'make' => fn ($v) => [self::t('You made it: :title', $v), self::t('Congratulations! You reached the goal and get :prize.', $v), self::t('See the challenge')]],
            'anniversary' => ['name' => 'Anniversary', 'kind' => 'stats', 'vars' => ['name' => 'Anna', 'period' => '1 year', 'gift' => ''],
                'about' => 'Every N months as a paying customer. {gift}: the gift sentence, if any.',
                'make' => fn ($v) => [self::t('Thank you for :period with us', $v), (($v['name'] ?? '') !== '' ? self::t('Hi :name,', $v) . "\n\n" : '')
                    . self::t('Today it is :period since you joined. Thank you for watching with us!', $v) . (($v['gift'] ?? '') !== '' ? "\n\n" . $v['gift'] : ''), self::t('See your subscription')]],
            'survey' => ['name' => 'How are we doing? (0-10)', 'kind' => 'stats', 'html' => true,
                'about' => 'The satisfaction survey after a month and every half year ("Satisfaction survey" on the server).'],
            'why' => ['name' => 'Why did you stop?', 'kind' => 'stats', 'html' => true,
                'about' => 'After cancelling: one-click reasons.'],
            'winback' => ['name' => 'We\'d love to have you back', 'kind' => 'news', 'vars' => ['percent' => '25', 'code' => 'BACK-AB12-CD34', 'date' => 'Dec 31, 2026'],
                'about' => 'A come-back code some time after a subscription ended ("Win-back" on the server).',
                'make' => fn ($v) => [self::t('We\'d love to have you back'), self::t('Your user and everything you have watched are still here. Come back with :percent% off your first month: use the code :code in the cart, until :date.', $v), self::t('See subscriptions')]],
            'news' => ['name' => 'New on the server (weekly)', 'kind' => 'news', 'html' => true,
                'about' => 'The weekly list of new movies and shows ("New on the server" on the server).'],
        ];
    }

    /** The friend pass offer, with a running campaign's code. */
    private static function passOffer(array $v): string
    {
        return self::t('We hope you enjoyed it! Keep your user and everything you have watched: buy a subscription with this mail address before the week ends.')
            . (($v['code'] ?? '') !== '' ? "\n\n" . self::t(':percent% off a new subscription with the code :code, until :date.', $v) : '');
    }

    /** Your choices per mail: [id => ['off' => bool, 'subject' => '', 'text' => '']]. */
    public static function mailChoices(): array
    {
        return self::storeGet('mail_texts', []);
    }

    public static function setMailChoice(string $id, array $choice): void
    {
        $all = self::mailChoices();
        $choice = ['off' => !empty($choice['off']), 'subject' => trim((string) ($choice['subject'] ?? '')), 'text' => trim((string) ($choice['text'] ?? ''))];
        if (!$choice['off'] && $choice['subject'] === '' && $choice['text'] === '') {
            unset($all[$id]);
        } else {
            $all[$id] = $choice;
        }
        self::storePut('mail_texts', $all);
    }

    public static function mailIsOff(string $id): bool
    {
        return $id !== '' && !empty(self::mailChoices()[$id]['off']);
    }

    /** {name} → value; values that start with "_" are not placeholders. */
    public static function fillMailText(string $text, array $vars): string
    {
        $map = [];
        foreach ($vars as $key => $value) {
            if (!str_starts_with((string) $key, '_')) {
                $map['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($text, $map);
    }

    /**
     * The mail $id with these values: ['title', 'text', 'button', 'kind'], in
     * your own words where you wrote some; null when you switched it off.
     */
    public static function mailParts(string $id, array $vars, ?array $choices = null): ?array
    {
        $entry = self::mailCatalog()[$id] ?? null;
        $choice = ($choices ?? self::mailChoices())[$id] ?? [];
        if (!$entry || !isset($entry['make']) || !empty($choice['off'])) {
            return null;
        }
        [$title, $text, $button] = ($entry['make'])($vars);
        if (($choice['subject'] ?? '') !== '') {
            $title = self::fillMailText($choice['subject'], $vars);
        }
        if (($choice['text'] ?? '') !== '') {
            $text = self::fillMailText($choice['text'], $vars);
        }

        return ['title' => $title, 'text' => $text, 'button' => $button, 'kind' => $entry['kind']];
    }

    /** The default words with {placeholders}, to start your own from. */
    public static function mailDefaultWords(string $id): array
    {
        $entry = self::mailCatalog()[$id] ?? [];
        if (!isset($entry['make'])) {
            return ['', ''];
        }
        $vars = [];
        foreach ($entry['vars'] ?? [] as $key => $sample) {
            $vars[$key] = str_starts_with($key, '_') ? $sample : '{' . $key . '}';
        }
        [$title, $text] = ($entry['make'])($vars);

        return [$title, $text];
    }

    /** Tells the customer of $service with automatic mail $id (the bell, mail, Discord); [] when switched off. */
    public function notifyMail(Service $service, string $id, array $vars, ?string $url = null, bool $discord = true): array
    {
        $parts = self::mailParts($id, $vars);
        if (!$parts) {
            return [];
        }

        return $this->notifyCustomer($service, $parts['title'], $parts['text'], $url !== null && $parts['button'] !== '' ? [$parts['button'], $url] : null,
            null, $discord, false, $parts['kind'], $id);
    }

    /** Your subject for an HTML mail, else $title. */
    public static function mailSubject(string $id, string $title): string
    {
        $subject = trim((string) (self::mailChoices()[$id]['subject'] ?? ''));

        return $subject !== '' ? $subject : $title;
    }

    /** Notes a sent mail: the last 300, and a count per day for 90 days. */
    public static function logMail(string $to, string $subject, string $kind, string $id, ?int $userId, ?int $logId = null, ?int $now = null): void
    {
        $now ??= time();
        try {
            $log = self::storeGet('mail_log', []);
            array_unshift($log, ['at' => $now, 'to' => mb_substr($to, 0, 80), 'user' => $userId, 'subject' => mb_substr($subject, 0, 90), 'kind' => $kind, 'mail' => $id, 'log' => $logId]);
            self::storePut('mail_log', array_slice($log, 0, 300));
            self::countMail('mail_counts', $kind, $now);
            if ($id !== '') {
                self::countMail('mail_counts_by_mail', $id, $now);
            }
            if ($id !== '' && $logId) {
                $last = self::storeGet('mail_last', []);
                $last[$id] = $logId;
                self::storePut('mail_last', $last);
            }
        } catch (Throwable $e) {
            // Only for you; the mail went.
        }
    }

    /** +1 for $key today in the counts under $store (90 days kept). */
    public static function countMail(string $store, string $key, ?int $now = null): void
    {
        $now ??= time();
        $counts = self::storeGet($store, []);
        $day = date('Y-m-d', $now);
        $counts[$day][$key] = ($counts[$day][$key] ?? 0) + 1;
        $cut = date('Y-m-d', $now - 90 * 86400);
        self::storePut($store, array_filter($counts, fn ($d) => $d >= $cut, ARRAY_FILTER_USE_KEY));
    }

    /** Sums of $store's counts per key over the last $days days. */
    public static function mailCountsSince(string $store, int $days, ?int $now = null): array
    {
        $now ??= time();
        $cut = date('Y-m-d', $now - ($days - 1) * 86400);
        $sum = [];
        foreach (self::storeGet($store, []) as $day => $keys) {
            if ($day >= $cut) {
                foreach ($keys as $key => $n) {
                    $sum[$key] = ($sum[$key] ?? 0) + $n;
                }
            }
        }

        return $sum;
    }

    /** The design: colour, logo (paymenter|name), a line at the bottom. */
    public static function mailDesign(): array
    {
        return self::storeGet('mail_design', []) + ['colour' => '', 'logo' => 'paymenter', 'footer' => ''];
    }

    /** An example of mail $id: your words, and the line to unsubscribe when it has one. */
    public function mailExample(string $id): ?array
    {
        $entry = self::mailCatalog()[$id] ?? null;
        if (!$entry) {
            return null;
        }
        if (!empty($entry['html'])) {
            $logId = self::storeGet('mail_last', [])[$id] ?? null;
            $row = $logId && class_exists(\App\Models\EmailLog::class) ? \App\Models\EmailLog::find($logId) : null;

            return $row ? ['subject' => (string) $row->subject, 'html' => (string) $row->body] : null;
        }
        $choices = self::mailChoices();
        $choices[$id] = ['off' => false] + ($choices[$id] ?? []);
        $parts = self::mailParts($id, $entry['vars'] ?? [], $choices);
        $html = $this->mailLayout($parts['title'], $this->paragraphs($parts['text']), $parts['button'] !== '' ? [$parts['button'], url('/')] : null);
        $footer = $entry['kind'] !== 'essential' ? $this->unsubscribeFooter($entry['kind'], url('/')) : '';

        return ['subject' => $parts['title'], 'html' => str_replace(self::MAIL_FOOTER_SLOT, $footer, $html)];
    }
}
