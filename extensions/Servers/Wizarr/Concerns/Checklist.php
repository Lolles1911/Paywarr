<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * "Getting started" on the client dashboard for new customers: create your
 * user, watch something, connect Discord, make a request. Gone when done,
 * or closed, or after 30 days. Part of Wizarr (Paywarr).
 */
trait Checklist
{
    /**
     * The steps for this customer: key => [label, done, url]; null when the
     * list is not shown (no subscription, done, or older than 30 days).
     */
    public function checklistFor($user, ?int $now = null): ?array
    {
        $now ??= time();
        if (!$user || $this->propertyValue($user, 'paywarr_checklist_closed') !== null) {
            return null;
        }
        $services = [];
        foreach (Service::where('user_id', $user->id)->whereHas('product.server', fn ($q) => $q->where('extension', 'Wizarr'))->with(['product.server', 'product.settings', 'properties'])->get() as $service) {
            $settings = ExtensionHelper::settingsToArray($service->product->settings ?? []);
            if (in_array($service->status, ['active', 'suspended'], true) && !$this->isGift($settings) && !$this->isTopUp($settings)) {
                $services[] = $service;
            }
        }
        if (!$services) {
            return null;
        }
        $first = min(array_map(fn ($s) => $this->timestampOf($s->created_at ?? null) ?? $now, $services));
        if ($now - $first > 30 * 86400) {
            return null;
        }
        $hasUser = false;
        $accounts = [];
        foreach ($services as $service) {
            $properties = ExtensionHelper::getServiceProperties($service);
            foreach ($properties as $key => $value) {
                if (($key === self::PROP_USERS || str_starts_with((string) $key, self::PROP_USERS . ':')) && $value !== '') {
                    $hasUser = true;
                }
            }
            $accounts = array_merge($accounts, $this->streamAccounts($properties));
        }
        $watched = $hasUser && Cache::remember('paywarr:checklist:watched:' . $user->id, 900, fn () => $this->hasWatched($user, $accounts, $services));
        $steps = [
            'user' => [self::t('Create your user'), $hasUser, $this->routeOr('services', '/services')],
            'watch' => [self::t('Watch something'), $watched, $this->navWatchLink($user)['url'] ?? $this->routeOr('services', '/services')],
        ];
        if ($this->discordConfigured()) {
            $steps['discord'] = [self::t('Connect Discord'), $this->propertyValue($user, self::PROP_DISCORD_ID) !== null, $this->routeOr('extensions.wizarr.discord', '/wizarr/discord')];
        }
        try {
            if ($this->librarySources($user, null, true) !== []) {
                $steps['request'] = [self::t('Ask for a movie or show'), $this->propertyValue($user, 'paywarr_first_request') !== null, $this->routeOr('extensions.wizarr.requests', '/wizarr/requests')];
            }
        } catch (Throwable $e) {
            // Without that step then.
        }
        $steps['help'] = [self::t('Get the app for your TV or phone'), $this->propertyValue($user, 'paywarr_help_seen') !== null, $this->routeOr('extensions.wizarr.help', '/wizarr/help')];

        return count(array_filter(array_column($steps, 1))) === count($steps) ? null : $steps;
    }

    /** Whether any of the accounts played something (Tracearr, or Silo's own history). */
    private function hasWatched($user, array $accounts, array $services): bool
    {
        try {
            $tracearr = $this->tracearrOrAny();
            if ($tracearr !== null && $accounts) {
                foreach ($tracearr->history(time() - 31 * 86400, 2000) as $play) {
                    if (in_array(strtolower(($play['server_type'] ?? '') . '|' . ($play['user']['username'] ?? '')), $accounts, true)) {
                        return true;
                    }
                }
            }
            foreach ($services as $service) {
                $ext = $this->forServiceServer($service);
                if ($ext->isSilo()) {
                    foreach ($ext->parseUsers((string) (ExtensionHelper::getServiceProperties($service)[self::PROP_USERS . ':1'] ?? '')) as $account) {
                        if ($ext->client()->watchHistory((int) $account['id'], 1)) {
                            return true;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            return false;
        }

        return false;
    }

    /** The card on the dashboard (hook "pages.dashboard"). */
    public function renderChecklist(): array
    {
        if (!Auth::check()) {
            return [];
        }
        try {
            $steps = $this->checklistFor(Auth::user());
        } catch (Throwable $e) {
            return [];
        }
        if ($steps === null) {
            return [];
        }
        $done = count(array_filter(array_column($steps, 1)));
        $e = fn ($v) => e((string) $v);
        $rows = '';
        foreach ($steps as [$label, $ok, $url]) {
            $rows .= '<a href="' . $e($url) . '" style="display:flex;gap:.7rem;align-items:center;padding:.55rem 0;border-top:1px solid hsl(var(--color-neutral)/.5);' . ($ok ? 'opacity:.6;text-decoration:line-through' : '') . '">'
                . '<span style="width:1.4rem;height:1.4rem;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;font-size:.8rem;'
                . ($ok ? 'background:hsl(var(--color-primary));color:#fff">✓' : 'border:2px solid hsl(var(--color-neutral))">') . '</span><span>' . $e($label) . '</span></a>';
        }
        $close = $this->routeOr('extensions.wizarr.checklist.close', '/wizarr/checklist/close');

        return ['view' => new HtmlString('<div class="bg-background-secondary border border-neutral" style="border-radius:1rem;padding:1.1rem 1.3rem;margin-top:1.5rem">'
            . '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem"><div><div style="font-weight:700;font-size:1.1rem">' . $e(self::t('Getting started')) . '</div>'
            . '<div style="opacity:.7;font-size:.9rem">' . $e(self::t(':done of :all done', ['done' => $done, 'all' => count($steps)])) . '</div></div>'
            . '<form method="post" action="' . $e($close) . '">' . csrf_field() . '<button type="submit" style="opacity:.6;font-size:.85rem">' . $e(self::t('Hide')) . '</button></form></div>'
            . '<div style="height:.4rem;border-radius:999px;background:hsl(var(--color-neutral)/.6);margin:.4rem 0 .6rem"><div style="height:100%;border-radius:999px;background:hsl(var(--color-primary));width:' . (int) round($done / max(1, count($steps)) * 100) . '%"></div></div>'
            . $rows . '</div>')];
    }

    /** POST /wizarr/checklist/close */
    public function closeChecklist()
    {
        Auth::user()?->properties()->updateOrCreate(['key' => 'paywarr_checklist_closed'], ['name' => 'Getting started hidden', 'value' => (string) time()]);

        return redirect()->back();
    }
}
