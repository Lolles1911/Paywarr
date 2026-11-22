<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * "My <site name>" (/wizarr/me): everything Paywarr offers the customer on one
 * page, as cards: where to watch, their subscriptions, the service status,
 * the movie night, the challenge, the friend pass, "Invite a friend" and the
 * links to Requests, stats, help and Report a problem. Keeps the menu short.
 * Part of Wizarr (Paywarr).
 */
trait Hub
{
    private function siteName(): string
    {
        return (string) ((function_exists('config') ? (config('settings.company_name') ?: config('app.name')) : '') ?: 'Paywarr');
    }

    private function hubUrl(): string
    {
        try {
            return route('extensions.wizarr.hub');
        } catch (Throwable $e) {
            return url('/wizarr/me');
        }
    }

    /** A card: a title (with an emoji), its body, an optional button [label, url] and how wide (2: both columns). */
    private function hubCard(string $icon, string $title, string $body, ?array $button = null, int $span = 1, string $accent = ''): string
    {
        return '<section class="bg-background-secondary wh-card" style="border-radius:1.1rem;padding:1.2rem 1.3rem;display:flex;flex-direction:column;gap:.6rem;'
            . ($span > 1 ? 'grid-column:1/-1;' : '') . ($accent !== '' ? 'border:1px solid ' . $accent . ';' : '') . '">'
            . '<h2 style="font-size:1.05rem;font-weight:700;display:flex;gap:.5rem;align-items:center;margin:0"><span aria-hidden="true">' . $icon . '</span>' . e($title) . '</h2>'
            . '<div style="opacity:.9;flex:1">' . $body . '</div>'
            . ($button ? '<a href="' . e($button[1]) . '" class="' . (($button[2] ?? '') === 'primary' ? 'bg-primary text-white' : 'border border-neutral') . ' font-semibold rounded-lg px-4 py-2" style="align-self:flex-start;text-align:center">' . e($button[0]) . '</a>' : '')
            . '</section>';
    }

    /** GET /wizarr/me */
    public function hubPage()
    {
        $user = Auth::user();
        $e = fn ($v) => e((string) $v);
        $cards = '';

        // Where to watch.
        $links = [];
        try {
            $links = $this->navWatchLinks($user);
        } catch (Throwable $ex) {
            // Shown without.
        }
        $watch = $links ? '<div style="display:flex;gap:.6rem;flex-wrap:wrap">' . implode('', array_map(fn ($l) => '<a href="' . $e($l['url']) . '" target="_blank" class="bg-primary text-white font-semibold rounded-lg px-4 py-3" style="flex:1 1 10rem;text-align:center">▶ ' . $e($l['label']) . '</a>', $links)) . '</div>'
            : '<p>' . $e(self::t('Your access is being prepared, or your user isn\'t created yet. Open your subscription to see how far it is.')) . '</p>';
        $cards .= $this->hubCard('🎬', self::t('Watch'), $watch, $links ? null : [self::t('Your subscriptions'), $this->routeOr('services', '/services')], 2);

        // Subscriptions.
        $rows = '';
        foreach (Service::where('user_id', $user->id)->whereIn('status', ['active', 'suspended'])->whereHas('product.server', fn ($q) => $q->where('extension', 'Wizarr'))->with('product')->orderBy('id')->get() as $service) {
            $expires = $this->timestampOf($service->expires_at ?? null);
            $rows .= '<a href="' . $e(route('services.show', $service)) . '" style="display:flex;justify-content:space-between;gap:1rem;padding:.55rem 0;border-top:1px solid rgba(127,127,127,.2)">'
                . '<span style="font-weight:600">' . $e($service->product->name ?? '') . '</span><span style="opacity:.75;text-align:right">'
                . $e($service->status === 'suspended' ? self::t('Paused or suspended') : ($expires ? self::t('Renews :date', ['date' => date(self::t('M j, Y'), $expires)]) : self::t('Active'))) . '</span></a>';
        }
        $cards .= $this->hubCard('📦', self::t('Your subscriptions'), $rows !== '' ? $rows : '<p>' . $e(self::t('No subscription yet.')) . '</p>', [self::t('See all'), $this->routeOr('services', '/services')]);

        // Service status.
        try {
            $notices = $this->noticesFor($user);
        } catch (Throwable $ex) {
            $notices = [];
        }
        $status = $notices ? implode('', array_map(fn ($n) => '<p><strong>' . $e($n['title']) . '</strong><br><span style="opacity:.85">' . $e(mb_substr($n['text'], 0, 160)) . '</span></p>', array_slice($notices, 0, 2)))
            : '<p style="display:flex;gap:.5rem;align-items:center"><span style="width:.7rem;height:.7rem;border-radius:1rem;background:#22c55e;display:inline-block"></span>' . $e(self::t('Everything is running.')) . '</p>';
        $cards .= $this->hubCard($notices ? '⚠️' : '✅', self::t('Service status'), $status, [self::t('Status'), $this->statusPageUrl()], 1, $notices ? 'rgba(239,68,68,.5)' : '');

        // Movie night.
        $party = array_values(self::watchPartiesFor($this->partyProductIds($user)))[0] ?? null;
        if ($party) {
            $going = isset($party['going'][$user->id]);
            $cards .= $this->hubCard('🍿', self::t('Movie night: :title', ['title' => $party['title']]),
                '<p>' . $e(date(self::t('M j, H:i'), $party['at'])) . ' · ' . $e(count($party['going']) === 1 ? self::t('1 is coming') : self::t(':count are coming', ['count' => count($party['going'])])) . '</p>'
                . ($going ? '<p style="font-weight:600">' . $e(self::t('You\'re coming')) . '</p>' : ''),
                [$going ? self::t('Open movie night') : self::t('I\'m coming'), $this->partyUrl(), $going ? '' : 'primary'], 1, 'rgba(219,39,119,.45)');
        }

        // Challenge.
        $challenge = array_values(array_filter(self::visibleChallenges(), fn ($c) => self::challengeStatus($c) === 'running'))[0] ?? null;
        if ($challenge) {
            $mine = (float) ($challenge['progress'][$user->id] ?? 0);
            $pct = min(100, (int) round($mine / max(1, $challenge['goal']) * 100));
            $cards .= $this->hubCard('🏆', $challenge['title'],
                '<p>' . $e($this->challengeGoalText($challenge)) . '</p><div style="display:flex;justify-content:space-between;font-weight:600;margin-top:.4rem"><span>' . $e(self::t('You: :value of :goal', ['value' => $mine, 'goal' => $challenge['goal']])) . '</span><span>' . $pct . '%</span></div>'
                . '<div style="height:.55rem;border-radius:1rem;background:rgba(127,127,127,.25);margin-top:.3rem;overflow:hidden"><div style="height:100%;width:' . $pct . '%;background:linear-gradient(90deg,#7c3aed,#db2777)"></div></div>',
                [self::t('See the challenge'), $this->challengeUrl()], 1, 'rgba(124,58,237,.45)');
        }

        // Friend pass.
        try {
            if ($this->passOn() && $this->passService($user)) {
                $next = self::nextPassAt((int) $user->id);
                $cards .= $this->hubCard('🎟️', self::t('Friend pass'), '<p>' . $e($next === null ? self::t('Give a friend a free week on our media server, once a month.') : self::t('You can give the next pass on :date.', ['date' => date(self::t('M j, Y'), $next)])) . '</p>',
                    [$next === null ? self::t('Give a week') : self::t('Your passes'), $this->passUrl()]);
            }
        } catch (Throwable $ex) {
            // Without the card.
        }

        // Invite a friend.
        try {
            if ($this->canRefer($user)) {
                $ext = $this->anyServer('referral_percent');
                $cards .= $this->hubCard('🎁', self::t('Invite a friend'), '<p>' . $e(self::t('Give a friend :percent% off their first month. When they have paid, you get :months for free.', ['percent' => (int) $ext->config('referral_percent'),
                    'months' => max(1, (int) ($ext->config('referral_reward_months') ?: 1)) === 1 ? self::t('1 month') : self::t(':count months', ['count' => (int) $ext->config('referral_reward_months')])])) . '</p>'
                    . '<p style="font-size:1.3rem;font-weight:800;letter-spacing:.06em;margin-top:.3rem">' . $e($ext->referralCode($user)) . '</p>',
                    [self::t('Send it by email'), $this->routeOr('extensions.wizarr.invite', '/wizarr/invite')]);
            }
        } catch (Throwable $ex) {
            // Without the card.
        }

        // Shortcuts.
        $shortcuts = [];
        if (Cache::remember('wizarr:can-request:' . $user->id, 300, fn () => $this->librarySources($user, null, true) !== [])) {
            $shortcuts[] = ['🔎', self::t('Requests'), $this->routeOr('extensions.wizarr.requests', '/wizarr/requests')];
        }
        try {
            $shortcuts[] = ['📊', self::t('My stats'), route('extensions.paywarr-stats')];
        } catch (Throwable $ex) {
            // Paywarr Stats isn't installed.
        }
        $shortcuts[] = ['❓', self::t('Help'), $this->routeOr('extensions.wizarr.help', '/wizarr/help')];
        if (Cache::remember('wizarr:can-report:' . $user->id, 300, fn () => $this->reportSources($user) !== [])) {
            $shortcuts[] = ['🛠️', self::t('Report a problem'), $this->routeOr('extensions.wizarr.report', '/wizarr/report')];
        }
        if ($this->discordConfigured()) {
            $shortcuts[] = ['💬', 'Discord', $this->routeOr('extensions.wizarr.discord', '/wizarr/discord')];
        }
        $cards .= '<nav style="grid-column:1/-1;display:grid;grid-template-columns:repeat(auto-fit,minmax(9rem,1fr));gap:.7rem">'
            . implode('', array_map(fn ($s) => '<a href="' . $e($s[2]) . '" class="bg-background-secondary" style="border-radius:1rem;padding:.9rem 1rem;display:flex;gap:.6rem;align-items:center;font-weight:600"><span aria-hidden="true" style="font-size:1.3rem">' . $s[0] . '</span>' . $e($s[1]) . '</a>', $shortcuts))
            . '</nav>';

        $html = '<div class="container mt-14" style="max-width:64rem"><h1 style="font-size:1.8rem;font-weight:800;margin-bottom:.3rem">' . $e(self::t('My :name', ['name' => $this->siteName()])) . '</h1>'
            . '<p style="opacity:.75;margin-bottom:1.2rem">' . $e(self::t('Hi :name! Everything for your subscription in one place.', ['name' => trim((string) $user->first_name) ?: $user->email])) . '</p>'
            . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,19rem),1fr));gap:1rem;margin-bottom:2rem">' . $cards . '</div></div>';

        return view('layouts.app', ['slot' => new HtmlString($html), 'title' => self::t('My :name', ['name' => $this->siteName()])]);
    }
}
