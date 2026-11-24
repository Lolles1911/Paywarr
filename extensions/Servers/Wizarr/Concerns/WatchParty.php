<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Movie nights (Paywarr → Movie nights): you plan a title at a time, for
 * every customer or some products. They are invited by mail and Discord,
 * press "I'm coming", and are reminded an hour before. Everyone starts it at
 * the same time on their own server; a Discord thread is for talking along.
 * The page is /wizarr/movie-night. Part of Wizarr (Paywarr).
 */
trait WatchParty
{
    public static function watchParties(): array
    {
        return self::storeGet('watch_parties', []);
    }

    private static function putWatchParty(array $party): void
    {
        $all = self::watchParties();
        $all[$party['id']] = $party;
        // Ended more than 30 days ago: gone.
        self::storePut('watch_parties', array_filter($all, fn ($p) => $p['at'] > time() - 30 * 86400));
    }

    /** Upcoming (or started within the last 3 hours) parties for a customer on $productIds; [] products = everyone. */
    public static function watchPartiesFor(array $productIds, ?int $now = null, ?array $parties = null): array
    {
        $now ??= time();
        $list = array_filter($parties ?? self::watchParties(), fn ($p) => $p['at'] > $now - 3 * 3600
            && (!$p['products'] || array_intersect($p['products'], $productIds)));
        uasort($list, fn ($a, $b) => $a['at'] <=> $b['at']);

        return $list;
    }

    /** The customer's active Paywarr subscriptions' product IDs (for who is invited). */
    private function partyProductIds($user): array
    {
        if (!$user) {
            return [];
        }

        return Service::where('user_id', $user->id)->where('status', 'active')->whereHas('product.server', fn ($q) => $q->where('extension', 'Wizarr'))
            ->pluck('product_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Plans a movie night; invites by mail and Discord, posts in the announcements channel with a thread. */
    public function createWatchParty(array $data, ?int $now = null): array
    {
        $now ??= time();
        $party = ['id' => bin2hex(random_bytes(4)), 'title' => trim((string) $data['title']), 'text' => trim((string) ($data['text'] ?? '')),
            'link' => trim((string) ($data['link'] ?? '')), 'at' => (int) $data['at'], 'products' => array_values(array_map('intval', (array) ($data['products'] ?? []))),
            'going' => [], 'reminded' => null, 'thread' => '', 'invited' => 0];
        if (!empty($data['announce'])) {
            $vars = ['title' => $party['title'], 'date' => date(self::t('M j, H:i'), $party['at']), 'about' => $party['text']];
            foreach ($this->partyRecipients($party) as $service) {
                try {
                    if ($this->forServiceServer($service)->notifyMail($service, 'party_invite', $vars, $this->partyUrl())) {
                        $party['invited']++;
                    }
                } catch (Throwable $e) {
                    Log::warning('Wizarr: movie night invitation to service ' . $service->id . ' not sent: ' . $e->getMessage());
                }
            }
            $party['thread'] = $this->partyThread($party);
        }
        self::putWatchParty($party);

        return $party;
    }

    /** One subscription per customer invited. */
    private function partyRecipients(array $party): array
    {
        $byUser = [];
        foreach ($this->broadcastRecipients('all') as $userId => $service) {
            if (!$party['products'] || in_array((int) $service->product_id, $party['products'], true)) {
                $byUser[$userId] = $service;
            }
        }

        return $byUser;
    }

    /** The post in the announcements channel and its thread; the thread's ID or "". */
    private function partyThread(array $party): string
    {
        if (($ext = $this->anyServer('announce_channel_id')) !== $this) {
            return $ext->partyThread($party);
        }
        $channel = trim((string) ($this->config('announce_channel_id') ?? ''));
        if ($channel === '' || !$this->discordConfigured()) {
            return '';
        }
        try {
            $message = $this->discord()->postMessage($channel, '🍿 **' . self::t('Movie night: :title', ['title' => $party['title']]) . '**' . "\n"
                . date(self::t('M j, H:i'), $party['at']) . ($party['text'] !== '' ? "\n" . $party['text'] : '') . "\n" . $this->partyUrl());

            return $this->discord()->startThread($channel, (string) ($message['id'] ?? ''), $party['title']);
        } catch (Throwable $e) {
            Log::warning('Wizarr: movie night not posted in Discord: ' . $e->getMessage());

            return '';
        }
    }

    /** "I'm coming" (or not). */
    public static function setGoing(string $partyId, int $userId, bool $going): void
    {
        $all = self::watchParties();
        if (!isset($all[$partyId])) {
            return;
        }
        if ($going) {
            $all[$partyId]['going'][$userId] = time();
        } else {
            unset($all[$partyId]['going'][$userId]);
        }
        self::storePut('watch_parties', $all);
    }

    public function deleteWatchParty(string $id): void
    {
        $all = self::watchParties();
        unset($all[$id]);
        self::storePut('watch_parties', $all);
    }

    /** The parties to remind now: starting within the hour, not reminded yet. */
    public static function partiesToRemind(array $parties, int $now): array
    {
        return array_keys(array_filter($parties, fn ($p) => empty($p['reminded']) && $p['at'] > $now && $p['at'] - $now <= 3600));
    }

    /** Every minute: an hour before, those coming are reminded (mail and Discord). */
    public function remindWatchParties(?int $now = null): array
    {
        $now ??= time();
        $all = self::watchParties();
        $due = self::partiesToRemind($all, $now);
        if (!$due) {
            return [];
        }
        $sent = [];
        foreach ($due as $id) {
            $all[$id]['reminded'] = $now;
            self::storePut('watch_parties', $all); // first, so a slow send isn't repeated
            $party = $all[$id];
            foreach (array_keys($party['going']) as $userId) {
                $service = Service::with(['product.server', 'user'])->where('user_id', $userId)->where('status', 'active')
                    ->whereHas('product.server', fn ($q) => $q->where('extension', 'Wizarr'))->first();
                if (!$service) {
                    continue;
                }
                try {
                    $this->forServiceServer($service)->notifyMail($service, 'party_reminder', ['title' => $party['title'], 'time' => date('H:i', $party['at'])], $this->partyUrl());
                    $sent[] = $userId;
                } catch (Throwable $e) {
                    Log::warning('Wizarr: movie night reminder to user ' . $userId . ' not sent: ' . $e->getMessage());
                }
            }
        }

        return $sent;
    }

    private function partyUrl(): string
    {
        try {
            return route('extensions.wizarr.party');
        } catch (Throwable $e) {
            return url('/wizarr/movie-night');
        }
    }

    private function threadUrl(string $thread): string
    {
        $guild = (string) ($this->anyServer('discord_guild_id')->config('discord_guild_id') ?? '');

        return $thread !== '' && $guild !== '' ? 'https://discord.com/channels/' . $guild . '/' . $thread : '';
    }

    /** GET and POST /wizarr/movie-night */
    public function partyPage()
    {
        $user = Auth::user();
        if (request()->isMethod('post')) {
            self::setGoing((string) request()->input('party'), (int) $user->id, request()->input('going') === '1');
        }
        $parties = self::watchPartiesFor($this->partyProductIds($user));
        $e = fn ($v) => e((string) $v);
        $html = '';
        if (!$parties) {
            $html .= '<p style="opacity:.8">' . $e(self::t('No movie night is planned right now. We tell you when there is one.')) . '</p>';
        }
        $links = $this->navWatchLinks($user);
        foreach ($parties as $party) {
            $going = isset($party['going'][$user->id]);
            $html .= '<div class="bg-background-secondary" style="border-radius:1rem;padding:1.2rem 1.4rem;margin-bottom:1rem">'
                . '<div style="font-size:1.3rem;font-weight:800">' . $e($party['title']) . '</div>'
                . '<div style="opacity:.8;margin:.2rem 0 .6rem">' . $e(date(self::t('M j, H:i'), $party['at'])) . ' · <span data-at="' . (int) $party['at'] . '" style="font-variant-numeric:tabular-nums"></span> · '
                . $e(count($party['going']) === 1 ? self::t('1 is coming') : self::t(':count are coming', ['count' => count($party['going'])])) . '</div>'
                . ($party['text'] !== '' ? '<p style="white-space:pre-line;margin-bottom:.8rem">' . $e($party['text']) . '</p>' : '')
                . '<div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center">'
                . '<form method="post" style="display:inline">' . csrf_field() . '<input type="hidden" name="party" value="' . $e($party['id']) . '"><input type="hidden" name="going" value="' . ($going ? '0' : '1') . '">'
                . '<button type="submit" class="' . ($going ? 'border border-neutral' : 'bg-primary text-white') . ' font-semibold rounded-lg px-4 py-2">' . $e($going ? self::t('✓ You\'re coming (cancel)') : self::t('I\'m coming')) . '</button></form>';
            if ($party['link'] !== '') {
                $html .= '<a class="border border-neutral rounded-lg px-4 py-2" target="_blank" href="' . $e($party['link']) . '">' . $e(self::t('Open :title', ['title' => $party['title']])) . '</a>';
            }
            foreach ($links as $link) {
                $html .= '<a class="border border-neutral rounded-lg px-4 py-2" target="_blank" href="' . $e($link['url']) . '">' . $e($link['label']) . '</a>';
            }
            if (($thread = $this->threadUrl($party['thread'])) !== '') {
                $html .= '<a class="border border-neutral rounded-lg px-4 py-2" target="_blank" href="' . $e($thread) . '">' . $e(self::t('Talk along on Discord')) . '</a>';
            }
            $html .= '</div></div>';
        }
        $html .= '<script>(function(){var f=' . json_encode(self::t('in :d d :h h :m min')) . ',now=' . json_encode(self::t('Now!')) . ';function tick(){document.querySelectorAll("[data-at]").forEach(function(c){var s=(+c.dataset.at*1000-Date.now())/1000|0;'
            . 'c.textContent=s<=0?now:f.replace(":d",s/86400|0).replace(":h",(s%86400)/3600|0).replace(":m",(s%3600)/60|0);});}tick();setInterval(tick,30000);})();</script>';

        return view('layouts.app', ['slot' => new HtmlString($this->pageFrame(self::t('Movie night'), $html)), 'title' => self::t('Movie night')]);
    }
}
