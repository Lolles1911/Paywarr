<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * "New on the server": once a week (setting "news_day", from 17:00) the
 * movies and shows added in the last 7 days, from Tracearr, posted in the
 * Discord announcements channel and, with "news_mail", mailed to active
 * customers who haven't said no thanks. Part of Wizarr (Paywarr).
 */
trait News
{
    /** User: doesn't want the weekly mail. */
    public const PROP_NEWS_OFF = 'paywarr_news_off';

    /**
     * Hourly: sends when it is the day, from 17:00, and not yet this week.
     *
     * @return array{discord: bool, mails: int}|null  null when nothing was due
     */
    public function sendNewsIfDue(?int $now = null, ?array $items = null, ?iterable $recipients = null): ?array
    {
        $now ??= time();
        if (($ext = $this->anyServer('news_day')) !== $this) {
            return $ext->sendNewsIfDue($now, $items, $recipients);
        }
        $day = (int) ($this->config('news_day') ?? 0);
        if ($day < 1 || (int) date('N', $now) !== $day || (int) date('G', $now) < 17 || Cache::get('paywarr:news:sent') === date('Y-m-d', $now)) {
            return null;
        }
        Cache::put('paywarr:news:sent', date('Y-m-d', $now), 8 * 86400);
        if ($items === null) {
            $tracearr = $this->tracearrOrAny();
            if ($tracearr === null) {
                Log::info('Wizarr: "New on the server" needs Tracearr on a Wizarr server.');

                return null;
            }
            try {
                $items = $tracearr->recentlyAdded($now - 7 * 86400);
            } catch (Throwable $e) {
                Log::warning('Wizarr: "New on the server" not sent, Tracearr: ' . $e->getMessage());
                Cache::forget('paywarr:news:sent');

                return null;
            }
        }
        $lists = $this->newsLists($items);
        if (!$lists['movie'] && !$lists['show']) {
            Log::info('Wizarr: "New on the server": nothing was added this week.');

            return ['discord' => false, 'mails' => 0];
        }

        return ['discord' => $this->postNews($lists), 'mails' => $this->truthy($this->config('news_mail') ?? false) ? $this->mailNews($lists, $recipients) : 0];
    }

    /** A post in the announcements channel (e.g. Paywarr Stats' year in review); false when it can't be posted. */
    public function announce(string $text): bool
    {
        if (($ext = $this->anyServer('announce_channel_id')) !== $this) {
            return $ext->announce($text);
        }
        $channel = trim((string) ($this->config('announce_channel_id') ?? ''));
        if ($channel === '' || !$this->discordConfigured()) {
            return false;
        }
        try {
            $this->discord()->postMessage($channel, $text);

            return true;
        } catch (Throwable $e) {
            Log::warning('Wizarr: announcement not posted in Discord: ' . $e->getMessage());

            return false;
        }
    }

    /** Titles (with year), movies and shows apart, each once, newest first. */
    public function newsLists(array $items): array
    {
        $lists = ['movie' => [], 'show' => []];
        foreach ($items as $item) {
            $type = (string) ($item['media_type'] ?? '');
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '' || !isset($lists[$type])) {
                continue;
            }
            $label = $title . (!empty($item['year']) ? ' (' . (int) $item['year'] . ')' : '');
            $lists[$type][strtolower($label)] = $label;
        }

        return ['movie' => array_values($lists['movie']), 'show' => array_values($lists['show'])];
    }

    private function postNews(array $lists): bool
    {
        $channel = trim((string) ($this->config('announce_channel_id') ?? ''));
        if ($channel === '' || !$this->discordConfigured()) {
            return false;
        }
        $text = '**' . self::t('New on the server this week') . "**\n";
        foreach (['movie' => self::t('Movies'), 'show' => self::t('Shows')] as $type => $heading) {
            if ($lists[$type]) {
                $shown = array_slice($lists[$type], 0, 25);
                $text .= "\n__" . $heading . "__\n" . implode("\n", array_map(fn ($t) => '• ' . $t, $shown))
                    . (count($lists[$type]) > 25 ? "\n" . self::t('… and :count more', ['count' => count($lists[$type]) - 25]) : '') . "\n";
            }
        }
        return $this->announce($text);
    }

    /** Mails active customers; returns how many. */
    private function mailNews(array $lists, ?iterable $recipients = null): int
    {
        $recipients ??= User::whereHas('services', fn ($query) => $query->where('status', 'active')->whereHas('product.server', fn ($q) => $q->where('extension', 'Wizarr')))
            ->whereDoesntHave('properties', fn ($query) => $query->where('key', self::PROP_NEWS_OFF))->get();
        $title = self::t('New on the server this week');
        $section = function (string $heading, array $titles) {
            return $titles ? '<h2 style="font-size:16px;margin:18px 0 8px">' . e($heading) . '</h2><ul style="margin:0 0 12px;padding-left:20px">'
                . implode('', array_map(fn ($t) => '<li style="margin-bottom:4px">' . e($t) . '</li>', array_slice($titles, 0, 40))) . '</ul>' : '';
        };
        $body = '<p style="margin:0 0 12px">' . e(self::t('This is what was added this week. Enjoy!')) . '</p>'
            . $section(self::t('Movies'), $lists['movie']) . $section(self::t('Shows'), $lists['show']);
        $sent = 0;
        foreach ($recipients as $user) {
            try {
                $off = URL::signedRoute('extensions.wizarr.news.off', ['user' => $user->id]);
            } catch (Throwable $e) {
                $off = '';
            }
            $footer = $off !== '' ? e(self::t('You get this because you have a subscription with us.')) . ' <a href="' . e($off) . '">' . e(self::t('Stop these mails')) . '</a>' : '';
            if ($this->sendMail((string) $user->email, $title, $this->mailLayout($title, $body, [self::t('Start watching'), $this->accountUrl()], $footer), (int) $user->id)) {
                $sent++;
            }
        }
        Log::info('Wizarr: "New on the server" mailed to ' . $sent . ' customer(s).');

        return $sent;
    }

    /** GET /wizarr/news/off/{user} (signed): no more weekly mails. */
    public function newsUnsubscribe($user)
    {
        $user = User::find((int) $user);
        if (!$user) {
            abort(404);
        }
        $user->properties()->updateOrCreate(['key' => self::PROP_NEWS_OFF], ['name' => 'No "New on the server" mails', 'value' => (string) time()]);

        return view('layouts.app', ['slot' => new HtmlString($this->pageFrame(self::t('Done'), '<p>' . e(self::t('You won\'t get "New on the server" mails any more.')) . '</p>')), 'title' => self::t('Done')]);
    }
}
