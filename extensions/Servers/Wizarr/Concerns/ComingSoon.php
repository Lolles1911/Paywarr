<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Wizarr\ArrClient;
use Throwable;

/**
 * "Coming soon" (/coming-soon): new episodes (Sonarr) and movies (Radarr)
 * due on the server in the next weeks, with posters; for customers, and for
 * visitors when "Coming soon for visitors" is on. Part of Wizarr (Paywarr).
 */
trait ComingSoon
{
    public function comingSoonConfigured(): bool
    {
        $sonarr = $this->anyServer('sonarr_url');
        $radarr = $this->anyServer('radarr_url');

        return trim((string) ($sonarr->config('sonarr_api_key') ?? '')) !== '' || trim((string) ($radarr->config('radarr_api_key') ?? '')) !== '';
    }

    /** Episodes from Sonarr's calendar: one entry per show and day. */
    public static function comingFromSonarr(array $episodes, int $now): array
    {
        $items = [];
        foreach ($episodes as $ep) {
            $at = strtotime((string) ($ep['airDateUtc'] ?? '')) ?: null;
            if ($at === null || $at < $now - 6 * 3600 || !empty($ep['hasFile'])) {
                continue;
            }
            $series = $ep['series'] ?? [];
            $show = (string) ($series['title'] ?? '');
            $key = 'tv|' . $show . '|' . date('Y-m-d', $at);
            $items[$key] ??= ['type' => 'show', 'title' => $show, 'at' => $at, 'season' => (int) ($ep['seasonNumber'] ?? 0), 'episodes' => [], 'premiere' => false,
                'poster' => self::arrPoster($series['images'] ?? [])];
            $items[$key]['episodes'][] = (int) ($ep['episodeNumber'] ?? 0);
            $items[$key]['premiere'] = $items[$key]['premiere'] || (int) ($ep['episodeNumber'] ?? 0) === 1;
            $items[$key]['at'] = min($items[$key]['at'], $at);
        }

        return array_values($items);
    }

    /** Movies from Radarr's calendar, on the day they can be watched at home (digital, else disc). */
    public static function comingFromRadarr(array $movies, int $now): array
    {
        $items = [];
        foreach ($movies as $movie) {
            $at = strtotime((string) ($movie['digitalRelease'] ?? $movie['physicalRelease'] ?? '')) ?: null;
            if ($at === null || $at < $now - 86400 || !empty($movie['hasFile'])) {
                continue;
            }
            $items[] = ['type' => 'movie', 'title' => (string) ($movie['title'] ?? ''), 'year' => (int) ($movie['year'] ?? 0), 'at' => $at, 'poster' => self::arrPoster($movie['images'] ?? [])];
        }

        return $items;
    }

    private static function arrPoster(array $images): string
    {
        foreach ($images as $image) {
            if (($image['coverType'] ?? '') === 'poster' && preg_match('~^https?://~', (string) ($image['remoteUrl'] ?? ''))) {
                return (string) $image['remoteUrl'];
            }
        }

        return '';
    }

    /** What's coming in the next $days days, soonest first (kept 30 minutes). */
    public function comingSoon(int $days = 30): array
    {
        return Cache::remember('paywarr:coming:' . $days, 1800, function () use ($days) {
            $now = time();
            $items = [];
            $sonarr = $this->anyServer('sonarr_url');
            if (($url = trim((string) ($sonarr->config('sonarr_url') ?? ''))) !== '' && ($key = trim((string) ($sonarr->config('sonarr_api_key') ?? ''))) !== '') {
                try {
                    $items = [...$items, ...self::comingFromSonarr((new ArrClient($url, $key))->calendar($now - 6 * 3600, $now + $days * 86400), $now)];
                } catch (Throwable $e) {
                    Log::info('Wizarr: Sonarr calendar not read: ' . $e->getMessage());
                }
            }
            $radarr = $this->anyServer('radarr_url');
            if (($url = trim((string) ($radarr->config('radarr_url') ?? ''))) !== '' && ($key = trim((string) ($radarr->config('radarr_api_key') ?? ''))) !== '') {
                try {
                    $items = [...$items, ...self::comingFromRadarr((new ArrClient($url, $key))->calendar($now - 86400, $now + $days * 86400), $now)];
                } catch (Throwable $e) {
                    Log::info('Wizarr: Radarr calendar not read: ' . $e->getMessage());
                }
            }
            usort($items, fn ($a, $b) => $a['at'] <=> $b['at']);

            return $items;
        });
    }

    public function comingSoonForVisitors(): bool
    {
        return $this->truthy($this->anyServer('coming_soon')->config('coming_soon') ?? false);
    }

    private function comingUrl(): string
    {
        return $this->routeOr('extensions.wizarr.coming', '/coming-soon');
    }

    /** "S2 E1–E3", "S1 E1 · new show". */
    private static function episodeLabel(array $item): string
    {
        sort($item['episodes']);
        $eps = count($item['episodes']) > 1 ? 'E' . reset($item['episodes']) . '–E' . end($item['episodes']) : 'E' . $item['episodes'][0];

        return 'S' . $item['season'] . ' ' . $eps . ($item['premiere'] ? ' · ' . ($item['season'] === 1 ? self::t('New show') : self::t('New season')) : '');
    }

    /** GET /coming-soon */
    public function comingPage()
    {
        if (!$this->comingSoonConfigured() || (!auth()->check() && !$this->comingSoonForVisitors())) {
            abort(404);
        }
        $e = fn ($v) => e((string) $v);
        $byDay = [];
        foreach ($this->comingSoon(30) as $item) {
            $byDay[date('Y-m-d', $item['at'])][] = $item;
        }
        $html = '<div class="container mt-14" style="max-width:72rem"><h1 style="font-size:2rem;font-weight:800;margin-bottom:.3rem">' . $e(self::t('Coming soon')) . '</h1>'
            . '<p style="opacity:.75;margin-bottom:1.5rem">' . $e(self::t('New episodes and movies on their way to the server in the next 30 days. Dates can move a little.')) . '</p>';
        if (!$byDay) {
            $html .= '<p style="opacity:.8">' . $e(self::t('Nothing planned right now. Have a look again soon.')) . '</p>';
        }
        foreach ($byDay as $day => $items) {
            $ts = strtotime($day);
            try {
                $date = \Carbon\Carbon::createFromTimestamp($ts)->locale(app()->getLocale())->translatedFormat(self::t('l, M j'));
            } catch (Throwable $e) {
                $date = date('l, M j', $ts);
            }
            $label = $day === date('Y-m-d') ? self::t('Today') : ($day === date('Y-m-d', strtotime('+1 day')) ? self::t('Tomorrow') : ucfirst($date));
            $html .= '<h2 style="font-size:1.15rem;font-weight:700;margin:1.6rem 0 .7rem">' . $e($label) . '</h2><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(8.5rem,1fr));gap:.9rem">';
            foreach ($items as $item) {
                $sub = $item['type'] === 'movie' ? self::t('Movie') . ($item['year'] ? ' · ' . $item['year'] : '') : self::episodeLabel($item);
                $html .= '<div>' . ($item['poster'] !== ''
                        ? '<img src="' . $e($item['poster']) . '" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.style.visibility=\'hidden\'" style="width:100%;aspect-ratio:2/3;object-fit:cover;border-radius:.8rem;background:rgba(127,127,127,.18)">'
                        : '<div style="width:100%;aspect-ratio:2/3;border-radius:.8rem;background:rgba(127,127,127,.18)"></div>')
                    . '<div style="font-weight:600;margin-top:.4rem;line-height:1.25">' . $e($item['title']) . '</div><div style="opacity:.7;font-size:.85rem">' . $e($sub) . '</div></div>';
            }
            $html .= '</div>';
        }
        if (!auth()->check()) {
            $html .= '<div style="text-align:center;margin:2.5rem 0"><a href="' . $e($this->shopUrl()) . '" class="bg-primary text-white font-semibold rounded-lg px-5 py-3">' . $e(self::t('See subscriptions')) . '</a></div>';
        }
        $html .= '</div>';

        return view('layouts.app', ['slot' => new HtmlString($html), 'title' => self::t('Coming soon')]);
    }

    /** On the front page, for visitors (when on): the next two weeks as a row of posters. */
    public function renderComingSoon(): array
    {
        try {
            if (!$this->comingSoonConfigured() || !$this->comingSoonForVisitors()) {
                return [];
            }
            $items = array_values(array_filter($this->comingSoon(30), fn ($i) => $i['poster'] !== '' && $i['at'] < time() + 14 * 86400));
            if (!$items) {
                return [];
            }
        } catch (Throwable $e) {
            return [];
        }
        $cards = '';
        foreach (array_slice($items, 0, 20) as $item) {
            $cards .= '<div style="flex:0 0 9rem;width:9rem;scroll-snap-align:start"><img src="' . e($item['poster']) . '" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.parentNode.remove()" style="width:100%;aspect-ratio:2/3;object-fit:cover;border-radius:.8rem;background:rgba(127,127,127,.18)">'
                . '<div style="font-size:.85rem;opacity:.8;margin-top:.3rem">' . e(date(self::t('M j'), $item['at'])) . '</div></div>';
        }

        return ['view' => new HtmlString('<section class="container" style="margin:3rem auto">'
            . '<div style="display:flex;justify-content:space-between;align-items:flex-end;gap:1rem;flex-wrap:wrap;margin-bottom:1rem"><div><h2 style="font-size:1.8rem;font-weight:800">' . e(self::t('Coming soon')) . '</h2>'
            . '<p style="opacity:.75">' . e(self::t('New episodes and movies on their way in the next two weeks.')) . '</p></div>'
            . '<a href="' . e($this->comingUrl()) . '" class="border border-neutral rounded-lg px-4 py-2 font-semibold">' . e(self::t('See the calendar')) . '</a></div>'
            . '<div style="display:flex;gap:.8rem;overflow-x:auto;scroll-snap-type:x mandatory;padding-bottom:.6rem">' . $cards . '</div></section>')];
    }
}
