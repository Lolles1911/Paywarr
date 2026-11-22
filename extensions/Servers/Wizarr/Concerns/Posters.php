<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Posters: "Continue watching", "New on the server" and "Popular now" on the
 * customer's My page, and for visitors (with "Show the selection to
 * visitors") a wall of posters without titles on the front page and on
 * /catalog. From Tracearr (Plex, Jellyfin, Emby) and Silo. The images are
 * served by Paywarr (/wizarr/poster/{key}), only those it has listed, so
 * Tracearr and the media servers are never exposed. Part of Wizarr.
 */
trait Posters
{
    /** Tracearr's image proxy path for a title from /recently-added; null without an ID. */
    public static function addedPosterPath(array $row): ?string
    {
        $id = (string) ($row['rating_key'] ?? '');
        $server = (string) ($row['server_id'] ?? '');
        if ($id === '' || $server === '') {
            return null;
        }
        $path = strtolower((string) ($row['server_type'] ?? '')) === 'plex' ? '/library/metadata/' . $id . '/thumb' : '/Items/' . $id . '/Images/Primary';

        return '/api/v1/images/proxy?' . http_build_query(['server' => $server, 'url' => $path, 'width' => 300, 'height' => 450, 'fallback' => 'poster']);
    }

    public static function posterKey(string $path): string
    {
        return substr(hash('sha256', $path), 0, 16);
    }

    /** Remembers the posters that may be served; returns the items with their key. */
    private static function allowPosters(array $items): array
    {
        $allowed = Cache::get('paywarr:posters', []);
        foreach ($items as &$item) {
            $item['key'] = self::posterKey($item['path']);
            $allowed[$item['key']] = $item['path'];
        }
        Cache::put('paywarr:posters', array_slice($allowed, -2000, null, true), 3 * 86400);

        return $items;
    }

    /**
     * The most watched titles of the last 7 days (each title once, by how
     * many people watched it), from Tracearr's history.
     */
    public static function popularTitles(array $plays, int $limit = 20): array
    {
        $titles = [];
        foreach ($plays as $p) {
            $poster = (string) ($p['poster_url'] ?? '');
            $title = (string) (($p['media_type'] ?? '') === 'episode' ? ($p['show_title'] ?? '') : ($p['media_title'] ?? ''));
            if ($title === '' || !str_starts_with($poster, '/api/v1/images/proxy?') || ($p['media_type'] ?? '') === 'track') {
                continue;
            }
            $key = strtolower($title . '|' . ($p['server_type'] ?? ''));
            $titles[$key] ??= ['title' => $title, 'year' => ($p['media_type'] ?? '') === 'movie' ? ($p['year'] ?? null) : null, 'type' => ($p['media_type'] ?? '') === 'episode' ? 'show' : 'movie',
                'server_type' => strtolower((string) ($p['server_type'] ?? '')), 'path' => $poster, 'people' => []];
            $titles[$key]['people'][strtolower((string) ($p['user']['username'] ?? ''))] = true;
        }
        uasort($titles, fn ($a, $b) => count($b['people']) <=> count($a['people']));

        return array_values(array_map(fn ($t) => array_diff_key($t, ['people' => 1]) + ['count' => count($t['people'])], array_slice($titles, 0, $limit)));
    }

    /** Half-watched titles of the customer's plays: the latest per title, between 3% and 90%. */
    public static function continueTitles(array $plays, int $limit = 12): array
    {
        $titles = [];
        foreach ($plays as $p) {
            $percent = (float) ($p['percent'] ?? 0);
            $key = strtolower(($p['show'] ?? '') . '|' . $p['title']);
            if (isset($titles[$key]) || $p['path'] === '') {
                continue;
            }
            $titles[$key] = $percent >= 3 && $percent <= 90 && empty($p['watched']) ? $p : false;
        }

        return array_slice(array_values(array_filter($titles)), 0, $limit);
    }

    /** New and popular titles for everyone, kept an hour. */
    public function shelves(): array
    {
        return Cache::remember('paywarr:shelves', 3600, function () {
            $new = [];
            $popular = [];
            $tracearr = $this->tracearrOrAny();
            if ($tracearr !== null) {
                try {
                    foreach ($tracearr->recentlyAdded(time() - 45 * 86400, 120) as $row) {
                        if (($path = self::addedPosterPath($row)) !== null) {
                            $new[] = ['title' => (string) ($row['title'] ?? ''), 'year' => $row['year'] ?? null, 'type' => (string) $row['media_type'],
                                'server_type' => strtolower((string) ($row['server_type'] ?? '')), 'path' => $path];
                        }
                    }
                } catch (Throwable $e) {
                    // Without new titles.
                }
                try {
                    $popular = self::popularTitles($tracearr->history(time() - 7 * 86400, 3000));
                } catch (Throwable $e) {
                    // Without popular titles.
                }
            }

            return ['new' => self::allowPosters($new), 'popular' => self::allowPosters($popular)];
        });
    }

    /** The customer's half-watched titles (Tracearr and Silo), kept 10 minutes. */
    public function continueWatching($user): array
    {
        return Cache::remember('paywarr:continue:' . $user->id, 600, function () use ($user) {
            $plays = [];
            $accounts = [];
            $silo = [];
            foreach (Service::where('user_id', $user->id)->whereIn('status', ['active', 'suspended'])->whereHas('product.server', fn ($q) => $q->where('extension', 'Wizarr'))
                ->with(['product.server', 'properties'])->get() as $service) {
                $properties = ExtensionHelper::getServiceProperties($service);
                foreach ($this->streamAccounts($properties) as $account) {
                    $accounts[$account] = true;
                }
                foreach ($properties as $key => $value) {
                    if ($key === self::PROP_USERS || str_starts_with((string) $key, self::PROP_USERS . ':')) {
                        foreach ($this->parseUsers((string) $value) as $u) {
                            if (strtolower($u['type']) === 'silo') {
                                $silo[] = [$service, (int) $u['id']];
                            }
                        }
                    }
                }
            }
            if ($accounts && ($tracearr = $this->tracearrOrAny()) !== null) {
                try {
                    foreach ($tracearr->history(time() - 30 * 86400, 3000) as $p) {
                        $account = strtolower((string) ($p['server_type'] ?? '')) . '|' . strtolower((string) ($p['user']['username'] ?? ''));
                        if (!isset($accounts[$account])) {
                            continue;
                        }
                        $poster = (string) ($p['poster_url'] ?? '');
                        $plays[] = ['title' => (string) ($p['media_title'] ?? ''), 'show' => (string) ($p['show_title'] ?? ''), 'type' => (string) ($p['media_type'] ?? ''),
                            'server_type' => strtolower((string) ($p['server_type'] ?? '')), 'percent' => (float) ($p['percent_complete'] ?? 0), 'watched' => !empty($p['watched']),
                            'path' => str_starts_with($poster, '/api/v1/images/proxy?') ? $poster : '', 'at' => strtotime((string) ($p['started_at'] ?? '')) ?: 0];
                    }
                } catch (Throwable $e) {
                    // Without Tracearr's plays.
                }
            }
            foreach ($silo as [$service, $id]) {
                try {
                    foreach ($this->forServiceServer($service)->client()->watchHistory($id, 50) as $p) {
                        $duration = (float) ($p['duration_seconds'] ?? 0);
                        $item = (string) ($p['media_item_id'] ?? '');
                        $plays[] = ['title' => (string) ($p['media_title'] ?? ''), 'show' => (string) ($p['series_title'] ?? ''), 'type' => (string) ($p['media_type'] ?? ''), 'server_type' => 'silo',
                            'percent' => $duration > 0 ? 100 * min(1, (float) ($p['watched_seconds'] ?? 0) / $duration) : 0, 'watched' => !empty($p['completed']),
                            'path' => $item !== '' ? 'silo:' . (int) $service->product->server_id . ':' . $item : '', 'at' => strtotime((string) ($p['started_at'] ?? '')) ?: 0];
                    }
                } catch (Throwable $e) {
                    // Without that account.
                }
            }
            usort($plays, fn ($a, $b) => $b['at'] <=> $a['at']);

            return self::allowPosters(self::continueTitles($plays));
        });
    }

    /** GET /wizarr/poster/{key}: an image Paywarr listed, kept a day. */
    public function posterImage($key)
    {
        $path = Cache::get('paywarr:posters', [])[(string) $key] ?? null;
        if (!is_string($path)) {
            abort(404);
        }
        $image = Cache::remember('paywarr:poster:' . $key, 86400, function () use ($path) {
            if (str_starts_with($path, 'silo:')) {
                [, $server, $item] = explode(':', $path, 3);
                $found = $this->instanceForServer((int) $server)->client()->poster($item);
            } else {
                $found = $this->tracearrOrAny()?->poster($path);
            }

            return $found ? ['type' => $found['type'], 'body' => base64_encode($found['body'])] : false;
        });
        if (!$image) {
            abort(404);
        }

        return response(base64_decode($image['body']), 200, ['Content-Type' => $image['type'], 'Cache-Control' => 'public, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function posterSrc(string $key): string
    {
        try {
            return route('extensions.wizarr.poster', ['key' => $key]);
        } catch (Throwable $e) {
            return url('/wizarr/poster/' . $key);
        }
    }

    /**
     * A row of posters that scrolls sideways. $titles: show the title (and a
     * progress bar for "Continue watching"); $links: server type => URL.
     */
    private function posterRow(string $title, array $items, bool $titles = true, array $links = []): string
    {
        if (!$items) {
            return '';
        }
        $e = fn ($v) => e((string) $v);
        $html = '<section style="grid-column:1/-1"><h2 style="font-size:1.15rem;font-weight:700;margin:.4rem 0 .7rem">' . $e($title) . '</h2>'
            . '<div style="display:flex;gap:.8rem;overflow-x:auto;scroll-snap-type:x mandatory;padding-bottom:.5rem;-webkit-overflow-scrolling:touch">';
        foreach ($items as $item) {
            $url = $links[$item['server_type'] ?? ''] ?? '';
            $label = $item['type'] === 'episode' && ($item['show'] ?? '') !== '' ? $item['show'] : $item['title'];
            $card = '<img src="' . $e($this->posterSrc($item['key'])) . '" alt="' . ($titles ? $e($label) : '') . '" loading="lazy" style="width:100%;aspect-ratio:2/3;object-fit:cover;border-radius:.7rem;background:rgba(127,127,127,.18);display:block">'
                . (isset($item['percent']) ? '<div style="height:4px;border-radius:4px;background:rgba(127,127,127,.3);margin-top:.35rem;overflow:hidden"><div style="height:100%;width:' . (int) $item['percent'] . '%;background:#db2777"></div></div>' : '')
                . ($titles ? '<div style="font-size:.85rem;font-weight:600;margin-top:.35rem;line-height:1.25;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical">' . $e($label) . '</div>' : '');
            $html .= '<' . ($url !== '' ? 'a href="' . $e($url) . '" target="_blank"' : 'div') . ' style="flex:0 0 8.5rem;scroll-snap-align:start">' . $card . '</' . ($url !== '' ? 'a' : 'div') . '>';
        }

        return $html . '</div></section>';
    }

    /** The three rows for the My page. */
    private function hubPosterRows($user, array $openLinks): string
    {
        $links = [];
        foreach ($openLinks as $link) {
            foreach (['plex', 'jellyfin', 'emby', 'silo'] as $type) {
                if (stripos($link['label'], $type) !== false) {
                    $links[$type] ??= $link['url'];
                }
            }
        }
        if (count($openLinks) === 1) {
            $links += array_fill_keys(['plex', 'jellyfin', 'emby', 'silo'], $openLinks[0]['url']);
        }
        try {
            $shelves = $this->shelves();
            $continue = $this->continueWatching($user);
        } catch (Throwable $e) {
            return '';
        }

        return $this->posterRow(self::t('Continue watching'), $continue, true, $links)
            . $this->posterRow(self::t('New on the server'), array_slice($shelves['new'], 0, 20), true, $links)
            . $this->posterRow(self::t('Popular now'), array_slice($shelves['popular'], 0, 20), true, $links);
    }

    public function showcaseOn(): bool
    {
        return $this->truthy($this->anyServer('showcase')->config('showcase') ?? false);
    }

    /** Posters for visitors: new and popular together, each once, without titles. */
    private function showcasePosters(int $limit): array
    {
        $shelves = $this->shelves();
        $seen = [];
        $out = [];
        foreach (array_merge($shelves['popular'], $shelves['new']) as $item) {
            if (!isset($seen[$item['key']])) {
                $seen[$item['key']] = true;
                $out[] = $item;
            }
        }

        return array_slice($out, 0, $limit);
    }

    private function posterGrid(array $items, string $min = '7.5rem'): string
    {
        return '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(' . $min . ',1fr));gap:.7rem">'
            . implode('', array_map(fn ($i) => '<img src="' . e($this->posterSrc($i['key'])) . '" alt="" loading="lazy" style="width:100%;aspect-ratio:2/3;object-fit:cover;border-radius:.7rem;background:rgba(127,127,127,.18)">', $items)) . '</div>';
    }

    private function catalogUrl(): string
    {
        try {
            return route('extensions.wizarr.catalog');
        } catch (Throwable $e) {
            return url('/catalog');
        }
    }

    /** The front page section (the "pages.home" hook). */
    public function renderShowcase(): array
    {
        try {
            if (!$this->showcaseOn() || !($posters = $this->showcasePosters(24))) {
                return [];
            }
        } catch (Throwable $e) {
            return [];
        }

        return ['view' => new HtmlString('<section class="container" style="margin:3rem auto">'
            . '<div style="display:flex;justify-content:space-between;align-items:flex-end;gap:1rem;flex-wrap:wrap;margin-bottom:1rem"><div><h2 style="font-size:1.8rem;font-weight:800">' . e(self::t('Something for everyone')) . '</h2>'
            . '<p style="opacity:.75">' . e(self::t('New movies and shows every week. Here is a taste of what\'s on.')) . '</p></div>'
            . '<a href="' . e($this->catalogUrl()) . '" class="border border-neutral rounded-lg px-4 py-2 font-semibold">' . e(self::t('See the whole selection')) . '</a></div>'
            . '<div style="display:flex;gap:.8rem;overflow-x:auto;scroll-snap-type:x mandatory;padding-bottom:.6rem;-webkit-overflow-scrolling:touch">'
            . implode('', array_map(fn ($i) => '<img src="' . e($this->posterSrc($i['key'])) . '" alt="" loading="lazy" style="flex:0 0 9rem;width:9rem;aspect-ratio:2/3;object-fit:cover;border-radius:.8rem;scroll-snap-align:start;background:rgba(127,127,127,.18)">', $posters))
            . '</div></section>')];
    }

    /** GET /catalog: walls of posters for visitors, movies and shows apart. */
    public function catalogPage()
    {
        if (!$this->showcaseOn()) {
            abort(404);
        }
        $all = $this->showcasePosters(200);
        $movies = array_values(array_filter($all, fn ($i) => $i['type'] === 'movie'));
        $shows = array_values(array_filter($all, fn ($i) => $i['type'] !== 'movie'));
        $e = fn ($v) => e((string) $v);
        $html = '<div class="container mt-14" style="max-width:72rem"><h1 style="font-size:2rem;font-weight:800;margin-bottom:.3rem">' . $e(self::t('The selection')) . '</h1>'
            . '<p style="opacity:.75;margin-bottom:1.5rem">' . $e(self::t('A taste of what\'s new and popular right now. Subscribers see it all, with search, on their own apps.')) . '</p>';
        foreach ([[self::t('Movies'), $movies], [self::t('Shows'), $shows]] as [$label, $items]) {
            if ($items) {
                $html .= '<h2 style="font-size:1.3rem;font-weight:700;margin:1.4rem 0 .8rem">' . $e($label) . '</h2>' . $this->posterGrid($items);
            }
        }
        $html .= '<div style="text-align:center;margin:2.5rem 0"><a href="' . $e($this->shopUrl()) . '" class="bg-primary text-white font-semibold rounded-lg px-5 py-3">' . $e(self::t('See subscriptions')) . '</a></div></div>';

        return view('layouts.app', ['slot' => new HtmlString($html), 'title' => self::t('The selection')]);
    }
}
