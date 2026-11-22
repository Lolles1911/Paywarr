<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Events\Service\Created as ServiceCreated;
use App\Events\InvoiceItem\Creating as InvoiceItemCreating;
use App\Events\InvoiceTransaction\Updated as TransactionUpdated;
use App\Events\ServiceUpgrade\Updated as ServiceUpgradeUpdated;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\UnsuspendJob;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Models\Property;
use App\Models\Service;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use App\Actions\Auth\Login as LoginAction;
use Filament\Notifications\Notification as AdminNotification;
use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Discord\Provider as DiscordProvider;
use Throwable;
use Paymenter\Extensions\Servers\Wizarr\DiscordClient;
use Paymenter\Extensions\Servers\Wizarr\DiscordException;
use Paymenter\Extensions\Servers\Wizarr\SeerrClient;
use Paymenter\Extensions\Servers\Wizarr\SeerrException;
use Paymenter\Extensions\Servers\Wizarr\ShopGate;
use Paymenter\Extensions\Servers\Wizarr\SiloClient;
use Paymenter\Extensions\Servers\Wizarr\Wizarr;
use Paymenter\Extensions\Servers\Wizarr\WizarrClient;
use Paymenter\Extensions\Servers\Wizarr\WizarrException;

/** The Requests tab and extra requests (top-ups). Part of Wizarr (Paywarr); uses its constants and helpers. */
trait Requests
{
    /* ------------------------------------------------------------------
     | The Requests tab: search, request, discover, my requests
     * ----------------------------------------------------------------- */

    /**
     * The customer's libraries to request in: one per active subscription with
     * requests. 'user' is their Seerr user or Silo account; null while it is
     * being set up (no media server user yet, or not in Seerr yet).
     *
     * @return array<int, array{service: Service, ext: static, kind: string, user: ?int, label: string}>
     */
    /**
     * The top bar's "Open Silo" (or "Open Jellyfin"): the first "Open" button
     * of the customer's active subscriptions, as their client area shows it.
     *
     * @return array{label: string, url: string}|null
     */
    public function navWatchLink($user, ?iterable $services = null): ?array
    {
        return $this->navWatchLinks($user, $services)[0] ?? null;
    }

    /**
     * Every "Open ..." button of the customer's active subscriptions (Open
     * Plex, Open Jellyfin, Open Emby, Open Silo), each once, for the top bar.
     * Kept for 10 minutes, as building them may ask Wizarr or Silo.
     *
     * @return array<int, array{label: string, url: string}>
     */
    public function navWatchLinks($user, ?iterable $services = null): array
    {
        if (!$user) {
            return [];
        }
        $find = function () use ($user, $services) {
            $services ??= Service::where('user_id', $user->id)->where('status', 'active')->with(['product.server', 'properties'])->orderBy('id')->get();
            $links = [];
            foreach ($services as $service) {
                if (($service->status ?? '') !== 'active' || $service->product?->server?->extension !== 'Wizarr') {
                    continue;
                }
                try {
                    $settings = ExtensionHelper::settingsToArray($service->product->settings);
                    $actions = $this->forServiceServer($service)->getActions($service, $settings, ExtensionHelper::getServiceProperties($service));
                } catch (Throwable $e) {
                    continue;
                }
                // The "Open ..." buttons; not "Create your user" or Seerr's "Request movies and shows".
                $open = strtok(self::t('Open the media library'), ' ');
                foreach ($actions as $action) {
                    $label = (string) ($action['label'] ?? '');
                    if (($action['type'] ?? '') === 'button' && !empty($action['url']) && str_starts_with($label, $open . ' ')) {
                        if ($label === self::t('Open the media library')) {
                            $label = ($name = $this->mediaServerName($service)) !== '' ? self::t('Open :server', ['server' => $name]) : self::t('Watch');
                        }
                        $url = (string) $action['url'];
                        if (!in_array($url, array_column($links, 'url'), true) && !in_array($label, array_column($links, 'label'), true)) {
                            $links[] = ['label' => $label, 'url' => $url];
                        }
                    }
                }
            }

            return $links;
        };
        if ($services !== null) {
            return $find();
        }
        // A customer whose user isn't created yet gets the link within a minute of creating it.
        $key = 'wizarr:nav-watch:' . $user->id;
        $links = Cache::get($key);
        if (!is_array($links) || isset($links['url'])) { // isset: one link, as kept before 1.29.1
            $links = $find();
            Cache::put($key, $links, $links ? 600 : 60);
        }

        return $links;
    }

    /** "Silo", the server's name in Wizarr ("Plex"), or the customer's user's type ("Jellyfin"); "" when unknown. */
    private function mediaServerName(Service $service): string
    {
        $ext = $this->forServiceServer($service);
        if ($ext->isSilo()) {
            return 'Silo';
        }
        $properties = ExtensionHelper::getServiceProperties($service);
        $grant = (string) ($ext->grantKeys($properties)[0] ?? '');
        $name = trim(preg_replace('/^server \d+$/', '', $ext->withGrant($grant, fn () => $ext->grantLabel($properties))));
        if ($name !== '') {
            return $name;
        }
        foreach ($this->streamAccounts($properties) as $account) {
            $type = explode('|', $account)[0];
            if ($type !== '') {
                return ucfirst($type);
            }
        }

        return '';
    }

    /**
     * "Create your user" links of the customer's active subscriptions whose
     * user isn't made yet, each once, for the top bar. Kept a minute, so it
     * turns into "Open" soon after.
     *
     * @return array<int, array{label: string, url: string}>
     */
    public function navInviteLinks($user): array
    {
        if (!$user) {
            return [];
        }

        return Cache::remember('wizarr:nav-invite:' . $user->id, 60, function () use ($user) {
            $links = [];
            $create = self::t('Create your user');
            foreach (Service::where('user_id', $user->id)->where('status', 'active')->with(['product.server', 'product.settings', 'properties'])->orderBy('id')->get() as $service) {
                if ($service->product?->server?->extension !== 'Wizarr') {
                    continue;
                }
                try {
                    $actions = $this->forServiceServer($service)->getActions($service, ExtensionHelper::settingsToArray($service->product->settings), ExtensionHelper::getServiceProperties($service));
                } catch (Throwable $e) {
                    continue;
                }
                foreach ($actions as $action) {
                    $label = (string) ($action['label'] ?? '');
                    if (($action['type'] ?? '') === 'button' && !empty($action['url']) && str_starts_with($label, $create) && !in_array($action['url'], array_column($links, 'url'), true)) {
                        $links[] = ['label' => $label, 'url' => (string) $action['url']];
                    }
                }
            }

            return $links;
        });
    }

    public function requestsSources($user, ?iterable $services = null): array
    {
        $sources = [];
        foreach ($this->librarySources($user, $services, true) as $source) {
            $properties = ExtensionHelper::getServiceProperties($source['service']);
            if ($source['kind'] === 'silo') {
                $silo = array_values(array_filter($source['ext']->allUsers($properties, resolve: false), fn ($u) => $u['type'] === 'silo'));
                $account = $silo[0]['id'] ?? null;
            } else {
                $account = ((int) ($properties[self::PROP_SEERR_USER] ?? 0)) ?: null;
            }
            $sources[] = $source + ['user' => $account, 'label' => $source['kind'] === 'silo' ? 'Silo' : (string) ($source['service']->product->name ?? 'Seerr')];
        }

        return $sources;
    }

    /**
     * Only the quota of one requestsSources() source (used, limit, unlimited
     * per part), for Paywarr Stats' overview; null when the customer has no
     * user there yet. Throws when Silo or Seerr can't answer.
     */
    public function requestQuotaOf(array $source): ?array
    {
        if ($source['user'] === null) {
            return null;
        }
        if ($source['kind'] === 'silo') {
            return $source['ext']->client()->requestQuota((int) $source['user']);
        }

        return $source['ext']->seerr($source['ext']->seerrInstanceOf($source['service']))->requestQuota((int) $source['user']);
    }

    /**
     * What the tab shows for one library: quota, search results or a show's
     * seasons, discovery rows, the customer's own requests. Each part that
     * can't be read is left empty, so the rest still shows.
     */
    public function requestsData(array $source, string $query = '', ?int $show = null): array
    {
        $data = ['label' => $source['label'], 'service' => (int) $source['service']->id, 'kind' => $source['kind'], 'ready' => $source['user'] !== null,
            'quota' => null, 'results' => [], 'rows' => [], 'mine' => [], 'show' => null, 'seasons' => [], 'query' => $query, 'errors' => 0,
            'more' => trim((string) $source['ext']->config('more_requests_url')),
            // Silo only lets an account request for itself (an API key can't act as one), so on Silo the
            // Request button opens the title in Silo, where the customer is signed in.
            'silo_url' => $source['kind'] === 'silo' ? $source['ext']->webAddress((string) $source['ext']->config('public_url')) : ''];
        if ($source['user'] === null) {
            return $data;
        }
        $user = (int) $source['user'];
        $read = function (callable $fn, $empty) use (&$data) {
            try {
                return $fn();
            } catch (Throwable $e) {
                $data['errors']++;
                Log::warning('Wizarr: Requests tab: ' . $e->getMessage());

                return $empty;
            }
        };
        if ($source['kind'] === 'silo') {
            $client = $source['ext']->client();
            $data['quota'] = $read(fn () => $client->requestQuota($user), null);
            $data['mine'] = $read(fn () => $client->myRequests($user), []);
            $show = null; // seasons are chosen in Silo
            if (mb_strlen(trim($query)) >= 2) {
                $data['results'] = $read(fn () => $client->requestSearch(trim(mb_substr($query, 0, 100))), []);
            } else {
                $data['rows'] = $read(fn () => $client->requestDiscover(), []);
            }
        } else {
            $seerr = $source['ext']->seerr($source['ext']->seerrInstanceOf($source['service']));
            $data['quota'] = $read(fn () => $seerr->requestQuota($user), null);
            $data['mine'] = $read(fn () => $seerr->myRequests($user), []);
            if ($show !== null) {
                $data['show'] = $read(fn () => $seerr->requestSeasons($user, $show), null);
            } elseif (mb_strlen(trim($query)) >= 2) {
                $data['results'] = $read(fn () => $seerr->requestSearch($user, trim(mb_substr($query, 0, 100))), []);
            } else {
                $data['rows'] = $read(fn () => $seerr->requestDiscover($user), []);
            }
        }
        if ($show !== null) {
            $data['show'] = ['tmdb' => $show] + ($data['show'] ?? ['title' => '', 'year' => null, 'poster' => null, 'seasons' => []]);
            $data['seasons'] = $data['show']['seasons'];
        }

        return $data;
    }

    /**
     * Places a request for the customer in the library of $serviceId (one of
     * theirs): a movie, or a show's seasons ("all" or numbers). Seerr's and
     * Silo's own rules decide (limit, permission, approval); a refusal comes
     * back in the customer's words.
     */
    public function placeRequest(array $sources, int $serviceId, string $type, int $tmdb, array|string $seasons = 'all'): void
    {
        $source = null;
        foreach ($sources as $candidate) {
            if ((int) $candidate['service']->id === $serviceId) {
                $source = $candidate;
            }
        }
        if ($source === null || $source['user'] === null || !in_array($type, ['movie', 'tv'], true) || $tmdb <= 0) {
            throw new DisplayException(self::t('That can\'t be requested right now.'));
        }
        if (is_array($seasons)) {
            $seasons = array_values(array_unique(array_filter(array_map('intval', $seasons), fn ($n) => $n > 0 && $n < 1000)));
            if (!$seasons) {
                throw new DisplayException(self::t('Choose at least one season.'));
            }
        }
        if ($source['kind'] === 'silo') {
            // Requested in Silo itself (see requestsData()).
            throw new DisplayException(self::t('That can\'t be requested right now.'));
        }
        try {
            $source['ext']->seerr($source['ext']->seerrInstanceOf($source['service']))->createRequest((int) $source['user'], $type, $tmdb, $seasons);
        } catch (SeerrException|WizarrException $e) {
            $status = $e instanceof SeerrException ? $e->status : $e->status;
            Log::info('Wizarr: request by service ' . $serviceId . ' refused: ' . $e->getMessage());
            throw new DisplayException(match (true) {
                $status === 409 => self::t('That has already been requested.'),
                $status === 403 || $status === 429 => self::t('You can\'t request more right now: your limit is used, or requests are off for your account.'),
                default => self::t('The request couldn\'t be sent right now. Please try again shortly.'),
            });
        }
    }

    /** GET /wizarr/requests */
    public function requestsPage()
    {
        $sources = $this->requestsSources(Auth::user());
        abort_unless($sources !== [], 404);
        // Requests set up for a customer who just got them (no Seerr account yet).
        foreach ($sources as $i => $source) {
            if ($source['user'] === null) {
                $source['ext']->syncSeerrQuietly($source['service'], 'active');
            }
        }
        $sources = $this->requestsSources(Auth::user());
        $chosen = $sources[0];
        foreach ($sources as $source) {
            if ((int) $source['service']->id === (int) request()->query('library', 0)) {
                $chosen = $source;
            }
        }
        $show = request()->query('show');
        $data = $this->requestsData($chosen, (string) request()->query('q', ''), ctype_digit((string) $show) ? (int) $show : null);
        $data['libraries'] = array_map(fn ($s) => ['service' => (int) $s['service']->id, 'label' => $s['label']], $sources);
        $data['notice'] = session('wizarr_requests_notice');
        $data['error'] = session('wizarr_requests_error');

        return view('layouts.app', ['slot' => new HtmlString($this->requestsHtml($data, route('extensions.wizarr.requests'), csrf_token())), 'title' => self::t('Requests')]);
    }

    /** POST /wizarr/requests */
    public function requestsSubmit()
    {
        $sources = $this->requestsSources(Auth::user());
        abort_unless($sources !== [], 404);
        $library = (int) request()->input('library', 0);
        $back = fn () => redirect()->route('extensions.wizarr.requests', array_filter(['library' => $library, 'q' => (string) request()->input('q', '')]));
        $key = 'wizarr-request:' . Auth::id();
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 10)) {
            return $back()->with('wizarr_requests_error', self::t('Too many requests at once. Please wait a minute.'));
        }
        \Illuminate\Support\Facades\RateLimiter::hit($key, 60);
        $seasons = request()->input('seasons', 'all');
        try {
            $this->placeRequest($sources, $library, (string) request()->input('type', ''), (int) request()->input('tmdb', 0), is_array($seasons) ? $seasons : 'all');
        } catch (DisplayException $e) {
            return $back()->with('wizarr_requests_error', $e->getMessage());
        }

        try {
            Auth::user()?->properties()->updateOrCreate(['key' => 'paywarr_first_request'], ['name' => 'Made a request', 'value' => (string) time()]);
        } catch (Throwable $e) {
            // Only for "Getting started".
        }

        return $back()->with('wizarr_requests_notice', self::t('Requested. You can follow it under "My requests".'));
    }

    /** The tab's page body. Styled with the theme's colour variables. */
    public function requestsHtml(array $data, string $base = '/wizarr/requests', string $csrf = ''): string
    {
        $e = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $url = fn (array $query = []) => $base . ($query ? '?' . http_build_query(array_filter($query, fn ($v) => $v !== null && $v !== '')) : '');
        $library = $data['service'];
        $html = '<div id="paywarr-requests" class="container mt-14"><style>' . $this->requestsCss() . '</style>'
            . '<header class="pr-head"><div><h1>' . $e(self::t('Requests')) . '</h1><p class="pr-muted">' . $e(self::t('Find a movie or show and ask for it to be added.')) . '</p></div>';

        // Quota
        $quota = $data['quota'];
        if ($quota !== null) {
            $html .= '<div class="pr-quota">';
            if (!empty($quota['unlimited'])) {
                $html .= '<span class="pr-pill">' . $e(self::t('Unlimited requests')) . '</span>';
            } else {
                $out = false;
                foreach ($quota['parts'] as $part) {
                    $label = ['movies' => self::t('Movies'), 'tv' => self::t('TV seasons'), 'requests' => self::t('Requests')][$part['label']] ?? $part['label'];
                    $html .= '<div class="pr-meter"><div class="pr-small"><b>' . $e($part['remaining']) . '</b> ' . $e(self::t('of :limit :what left', ['limit' => $part['limit'], 'what' => mb_strtolower($label)]))
                        . ($part['days'] ? ' · ' . $e(self::t(':days days', ['days' => $part['days']])) : '') . '</div>'
                        . '<span class="pr-bar"><span style="width:' . ($part['limit'] > 0 ? (int) round(100 * min(1, $part['used'] / $part['limit'])) : 0) . '%"></span></span></div>';
                    $out = $out || $part['remaining'] === 0;
                }
                if ($out && $data['more'] !== '') {
                    $html .= '<a class="pr-button" href="' . $e($data['more']) . '">' . $e(self::t('Buy more requests')) . '</a>';
                }
            }
            $html .= '</div>';
        }
        $html .= '</header>';

        if (count($data['libraries'] ?? []) > 1) {
            $html .= '<nav class="pr-tabs">';
            foreach ($data['libraries'] as $lib) {
                $html .= '<a href="' . $e($url(['library' => $lib['service']])) . '"' . ($lib['service'] === $library ? ' class="pr-active"' : '') . '>' . $e($lib['label']) . '</a>';
            }
            $html .= '</nav>';
        }
        foreach (['notice' => 'pr-ok', 'error' => 'pr-error'] as $key => $class) {
            if (!empty($data[$key])) {
                $html .= '<p class="pr-flash ' . $class . '">' . $e($data[$key]) . '</p>';
            }
        }
        if (!$data['ready']) {
            return $html . '<p class="pr-card">' . $e(self::t('Your requests are being set up. Once you have created your user, they are ready within a few minutes.')) . '</p></div>';
        }
        if ($data['errors'] > 0 && !$data['results'] && !$data['rows'] && !$data['mine'] && !$data['seasons']) {
            $html .= '<p class="pr-card">' . $e(self::t('Requests can\'t be loaded right now. Please try again later.')) . '</p>';
        }

        $html .= '<form class="pr-search" method="GET" action="' . $e($base) . '"><input type="hidden" name="library" value="' . $e($library) . '">'
            . '<input type="search" name="q" value="' . $e($data['query']) . '" minlength="2" maxlength="100" placeholder="' . $e(self::t('Search for a movie or show')) . '">'
            . '<button type="submit">' . $e(self::t('Search')) . '</button></form>'
            . ($data['kind'] === 'silo' ? '<p class="pr-muted pr-tiny">' . $e(self::t('"Request in Silo" opens the title in Silo, where you request it with your own account.')) . '</p>' : '');

        $badge = fn (string $state) => match ($state) {
            'available' => '<span class="pr-badge pr-b-ok">' . $e(self::t('Available')) . '</span>',
            'partial' => '<span class="pr-badge pr-b-ok">' . $e(self::t('Partly available')) . '</span>',
            'processing' => '<span class="pr-badge pr-b-info">' . $e(self::t('Processing')) . '</span>',
            'requested', 'pending' => '<span class="pr-badge pr-b-info">' . $e(self::t('Requested')) . '</span>',
            'approved' => '<span class="pr-badge pr-b-info">' . $e(self::t('Approved')) . '</span>',
            'declined' => '<span class="pr-badge pr-b-bad">' . $e(self::t('Declined')) . '</span>',
            'failed', 'cancelled' => '<span class="pr-badge pr-b-bad">' . $e(self::t('Not added')) . '</span>',
            default => '',
        };
        $hidden = '<input type="hidden" name="_token" value="' . $e($csrf) . '"><input type="hidden" name="library" value="' . $e($library) . '"><input type="hidden" name="q" value="' . $e($data['query']) . '">';
        $card = function (array $item) use ($e, $badge, $hidden, $base, $url, $library, $data): string {
            $cover = '<span class="pr-cover"><span class="pr-letter">' . $e(mb_strtoupper(mb_substr($item['title'] ?: '?', 0, 1))) . '</span>'
                . ($item['poster'] ? '<img loading="lazy" alt="" referrerpolicy="no-referrer" src="' . $e($item['poster']) . '" onerror="this.remove()">' : '') . $badge($item['state']) . '</span>';
            $action = '';
            if ($data['kind'] === 'silo') {
                if ($data['silo_url'] !== '' && ($item['state'] === 'none' || ($item['type'] === 'tv' && $item['state'] === 'partial'))) {
                    $action = '<a class="pr-button pr-small-button" target="_blank" rel="noopener" href="' . $e(rtrim($data['silo_url'], '/') . '/title/' . ($item['type'] === 'tv' ? 'series' : 'movie') . '/' . (int) $item['tmdb']) . '">' . $e(self::t('Request in Silo')) . '</a>';
                }
            } elseif (in_array($item['state'], ['none'], true) || ($item['type'] === 'tv' && $item['state'] === 'partial')) {
                $action = $item['type'] === 'tv'
                    ? '<a class="pr-button pr-small-button" href="' . $e($url(['library' => $library, 'show' => $item['tmdb'], 'q' => $data['query']])) . '">' . $e(self::t('Request')) . '</a>'
                    : '<form method="POST" action="' . $e($base) . '">' . $hidden . '<input type="hidden" name="type" value="movie"><input type="hidden" name="tmdb" value="' . $e($item['tmdb']) . '">'
                        . '<button class="pr-button pr-small-button" type="submit">' . $e(self::t('Request')) . '</button></form>';
            }

            return '<div class="pr-tile">' . $cover . '<div class="pr-title pr-clip" title="' . $e($item['title']) . '">' . $e($item['title']) . '</div>'
                . '<div class="pr-muted pr-tiny">' . $e(implode(' · ', array_filter([$item['year'], $item['type'] === 'tv' ? self::t('Show') : self::t('Movie')]))) . '</div>' . $action . '</div>';
        };

        // A show's seasons
        if ($data['show'] !== null) {
            $show = $data['show'];
            $html .= '<section class="pr-card pr-seasons"><div class="pr-show"><span class="pr-thumb pr-thumb-big"><span class="pr-letter">' . $e(mb_strtoupper(mb_substr($show['title'] ?: '?', 0, 1))) . '</span>'
                . ($show['poster'] ? '<img alt="" referrerpolicy="no-referrer" src="' . $e($show['poster']) . '" onerror="this.remove()">' : '') . '</span>'
                . '<div><div class="pr-muted pr-tiny">' . $e(self::t('Show')) . '</div><div class="pr-title pr-big">' . $e($show['title']) . ($show['year'] ? ' <span class="pr-muted">(' . $e($show['year']) . ')</span>' : '') . '</div>'
                . '<h2>' . $e(self::t('Which seasons?')) . '</h2></div></div>';
            if (!$data['seasons']) {
                $html .= '<p class="pr-muted">' . $e(self::t('The seasons can\'t be loaded right now.')) . '</p>';
            } else {
                $html .= '<form method="POST" action="' . $e($base) . '">' . $hidden . '<input type="hidden" name="type" value="tv"><input type="hidden" name="tmdb" value="' . $e($show['tmdb']) . '"><div class="pr-season-list">';
                foreach ($data['seasons'] as $season) {
                    $free = $season['state'] === 'none' || $season['state'] === 'partial';
                    // TMDB's own name only when it is more than "Season 3" (in any language).
                    $special = $season['name'] !== '' && !preg_match('/^\D*\s' . (int) $season['number'] . '$/u', $season['name']) ? $season['name'] : '';
                    $html .= '<label class="pr-season' . ($free ? '' : ' pr-done') . '"><input type="checkbox" name="seasons[]" value="' . $e($season['number']) . '"' . ($free ? '' : ' disabled') . '>'
                        . '<span>' . $e(self::t('Season :n', ['n' => $season['number']])) . ($special !== '' ? ' <span class="pr-muted">– ' . $e($special) . '</span>' : '')
                        . '<span class="pr-muted pr-tiny"> · ' . $e(self::t(':n episodes', ['n' => $season['episodes']])) . '</span></span>' . $badge($season['state']) . '</label>';
                }
                $html .= '</div><div class="pr-row"><button class="pr-button" type="submit">' . $e(self::t('Request the chosen seasons')) . '</button>'
                    . '<a class="pr-muted" href="' . $e($url(['library' => $library, 'q' => $data['query']])) . '">' . $e(self::t('Back')) . '</a></div></form>';
            }
            $html .= '</section>';
        } elseif ($data['query'] !== '') {
            $html .= '<h2 class="pr-h">' . $e(self::t('Results for ":q"', ['q' => $data['query']])) . '</h2>';
            $html .= $data['results'] ? '<div class="pr-grid">' . implode('', array_map($card, $data['results'])) . '</div>'
                : '<p class="pr-muted">' . $e(self::t('Nothing found. Try another spelling, or the original title.')) . '</p>';
        } else {
            $names = ['trending' => self::t('Trending'), 'trending_movies' => self::t('Trending movies'), 'trending_series' => self::t('Trending shows'),
                'popular_movies' => self::t('Popular movies'), 'popular_series' => self::t('Popular shows'), 'upcoming_movies' => self::t('Coming soon')];
            foreach ($data['rows'] as $row) {
                $html .= '<h2 class="pr-h">' . $e($names[$row['key']] ?? $row['key']) . '</h2><div class="pr-row-scroll">' . implode('', array_map($card, $row['items'])) . '</div>';
            }
        }

        // My requests
        $html .= '<h2 class="pr-h" id="mine">' . $e(self::t('My requests')) . '</h2>';
        if (!$data['mine']) {
            $html .= '<p class="pr-muted">' . $e(self::t('You haven\'t requested anything yet.')) . '</p>';
        } else {
            $html .= '<div class="pr-card pr-list">';
            foreach ($data['mine'] as $request) {
                $when = ($ts = strtotime($request['date'])) ? date(self::t('M j, Y'), $ts) : '';
                $what = $request['type'] === 'tv' ? match (count($request['seasons'])) {
                    0 => self::t('All seasons'),
                    1 => self::t('Season :n', ['n' => $request['seasons'][0]]),
                    default => self::t('Seasons :list', ['list' => implode(', ', $request['seasons'])]),
                } : self::t('Movie');
                $html .= '<div class="pr-line"><span class="pr-thumb"><span class="pr-letter">' . $e(mb_strtoupper(mb_substr($request['title'] ?: '?', 0, 1))) . '</span>'
                    . ($request['poster'] ? '<img loading="lazy" alt="" referrerpolicy="no-referrer" src="' . $e($request['poster']) . '" onerror="this.remove()">' : '') . '</span>'
                    . '<div class="pr-grow"><div class="pr-title">' . $e($request['title']) . ($request['year'] ? ' <span class="pr-muted">(' . $e($request['year']) . ')</span>' : '') . '</div>'
                    . '<div class="pr-muted pr-tiny">' . $e(implode(' · ', array_filter([$what, $when]))) . '</div></div>' . $badge($request['status']) . '</div>';
            }
            $html .= '</div>';
        }

        return $html . '</div>';
    }

    /** The Requests tab's look, also used by "Report a problem" (under another ID). */
    public function requestsCss(string $c = '#paywarr-requests'): string
    {

        return "$c{max-width:72rem;margin:0 auto;padding:0 1rem 3rem;color:hsl(var(--color-base))}$c *{box-sizing:border-box}$c svg,$c img{display:block}"
            . "$c h1{font-size:1.9rem;font-weight:800;margin:0}$c h2{font-size:1.05rem;font-weight:700;margin:0 0 .8rem}$c .pr-h{margin:2rem 0 .9rem;font-size:1.15rem}"
            . "$c .pr-muted{color:hsl(var(--color-muted, var(--color-base)))}$c .pr-small{font-size:.9rem}$c .pr-tiny{font-size:.75rem}"
            . "$c .pr-head{display:flex;flex-wrap:wrap;gap:1rem 2rem;align-items:flex-end;justify-content:space-between;margin-bottom:1.25rem}"
            . "$c .pr-quota{display:flex;flex-wrap:wrap;gap:1rem;align-items:center}$c .pr-meter{min-width:13rem}$c .pr-meter .pr-bar{margin-top:.35rem}"
            . "$c .pr-bar{display:block;height:.45rem;border-radius:9999px;background:hsl(var(--color-neutral) / .6);overflow:hidden}$c .pr-bar span{display:block;height:100%;background:hsl(var(--color-primary))}"
            . "$c .pr-pill{padding:.35rem .8rem;border-radius:9999px;border:1px solid hsl(var(--color-neutral));font-size:.85rem}"
            . "$c .pr-card{background:hsl(var(--color-background-secondary));border:1px solid hsl(var(--color-neutral));border-radius:1rem;padding:1.1rem 1.3rem}"
            . "$c .pr-tabs{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem}$c .pr-tabs a{padding:.4rem .9rem;border-radius:9999px;border:1px solid hsl(var(--color-neutral));font-size:.9rem}"
            . "$c .pr-tabs a.pr-active{background:hsl(var(--color-primary));border-color:hsl(var(--color-primary));color:hsl(var(--color-background))}"
            . "$c .pr-flash{border-radius:.75rem;padding:.75rem 1rem;margin:0 0 1rem;border:1px solid}$c .pr-ok{border-color:hsl(142 60% 45%)}$c .pr-error{border-color:hsl(0 70% 55%)}"
            . "$c .pr-search{display:flex;gap:.6rem;margin:0 0 .5rem}$c .pr-search input{flex:1;min-width:0;padding:.7rem 1rem;border-radius:.75rem;border:1px solid hsl(var(--color-neutral));background:hsl(var(--color-background-secondary));color:inherit}"
            . "$c .pr-search button,$c .pr-button{display:inline-block;padding:.65rem 1.1rem;border-radius:.75rem;border:0;background:hsl(var(--color-primary));color:hsl(var(--color-background));font-weight:700;cursor:pointer;text-align:center}"
            . "$c .pr-small-button{width:100%;margin-top:.45rem;padding:.45rem .6rem;font-size:.85rem}"
            . "$c .pr-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(9rem,1fr));gap:1.1rem}"
            . "$c .pr-row-scroll{display:grid;grid-auto-flow:column;grid-auto-columns:minmax(8.5rem,9.5rem);gap:1rem;overflow-x:auto;padding-bottom:.6rem;scroll-snap-type:x mandatory}"
            . "$c .pr-tile{min-width:0;scroll-snap-align:start}$c .pr-title{font-weight:600}$c .pr-clip{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}"
            . "$c .pr-cover,$c .pr-thumb{position:relative;display:block;overflow:hidden;border-radius:.6rem;background:linear-gradient(160deg,hsl(var(--color-primary) / .35),hsl(var(--color-neutral)))}"
            . "$c .pr-cover{aspect-ratio:2/3;margin-bottom:.5rem;box-shadow:0 8px 22px rgb(0 0 0 / .25)}$c .pr-thumb{flex:none;width:3rem;aspect-ratio:2/3}"
            . "$c .pr-cover img,$c .pr-thumb img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}"
            . "$c .pr-letter{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:1.5rem;font-weight:800;opacity:.6}"
            . "$c .pr-cover .pr-badge{position:absolute;top:.4rem;left:.4rem}"
            . "$c .pr-badge{display:inline-block;padding:.1rem .5rem;border-radius:9999px;font-size:.7rem;font-weight:700;background:hsl(var(--color-background) / .85);border:1px solid currentColor;white-space:nowrap}"
            . "$c .pr-b-ok{color:hsl(142 60% 50%)}$c .pr-b-info{color:hsl(var(--color-primary))}$c .pr-b-bad{color:hsl(0 70% 60%)}"
            . "$c .pr-list{padding:.3rem 1.2rem}$c .pr-line{display:flex;gap:.9rem;align-items:center;padding:.7rem 0;border-bottom:1px solid hsl(var(--color-neutral))}$c .pr-line:last-child{border-bottom:0}$c .pr-grow{flex:1;min-width:0}"
            . "$c .pr-season-list{display:grid;gap:.5rem;margin-bottom:1rem}$c .pr-season{display:flex;gap:.7rem;align-items:center;padding:.6rem .8rem;border:1px solid hsl(var(--color-neutral));border-radius:.6rem}$c .pr-season span:first-of-type{flex:1}$c .pr-done{opacity:.6}"
            . "$c .pr-row{display:flex;gap:1rem;align-items:center}$c .pr-show{display:flex;gap:1rem;align-items:center;margin-bottom:1rem}$c .pr-show h2{margin:.5rem 0 0}$c .pr-thumb-big{width:4.5rem}$c .pr-big{font-size:1.3rem}"
            . '@media (max-width:640px){' . "$c .pr-grid{grid-template-columns:repeat(auto-fill,minmax(7rem,1fr));gap:.8rem}$c .pr-meter{min-width:0;flex:1}" . '}';
    }

    /* ------------------------------------------------------------------
     | Extra requests: a top-up product adds to a request package
     * ----------------------------------------------------------------- */

    public function isTopUp(array $productSettings): bool
    {
        return (int) ($productSettings['extra_requests'] ?? 0) > 0;
    }

    /**
     * The subscription a top-up adds to: the same customer's active
     * subscription with a request package that has a limit. Null when there
     * is none (a package "unlimited", or Seerr's/Silo's default, can't grow).
     */
    public function topUpTarget(Service $topUp, ?iterable $services = null): ?Service
    {
        $services ??= Service::where('user_id', $topUp->user_id)->where('status', 'active')->with(['product.server', 'properties'])->get();
        foreach ($services as $service) {
            if ((int) $service->id === (int) $topUp->id || ($service->status ?? '') !== 'active' || $service->product?->server?->extension !== 'Wizarr') {
                continue;
            }
            $ext = $this->forServiceServer($service);
            $productSettings = ExtensionHelper::settingsToArray($service->product->settings);
            if ($ext->isTopUp($productSettings)) {
                continue;
            }
            $plan = $ext->seerrPlan($productSettings, ExtensionHelper::getServiceProperties($service));
            if ($plan !== null && (int) $plan['limit'] > 0) {
                return $service;
            }
        }

        return null;
    }

    /**
     * Adds a paid top-up to the customer's package, valid for the package's
     * period from now (once: run again, it changes nothing), and sends the new
     * limit to Seerr or Silo. Returns how many requests it added.
     */
    public function applyTopUp(Service $topUp, array $settings, ?iterable $services = null, ?int $now = null): int
    {
        $now ??= time();
        $mine = ExtensionHelper::getServiceProperties($topUp);
        $n = (int) $settings['extra_requests'] * max(1, (int) ($topUp->quantity ?? 1));
        $target = null;
        if (!empty($mine[self::PROP_TOPUP_TARGET])) {
            foreach ($services ?? Service::where('id', (int) $mine[self::PROP_TOPUP_TARGET])->with(['product.server', 'properties'])->get() as $candidate) {
                if ((int) $candidate->id === (int) $mine[self::PROP_TOPUP_TARGET]) {
                    $target = $candidate;
                }
            }
        }
        $target ??= $this->topUpTarget($topUp, $services);
        if ($target === null) {
            throw new DisplayException(self::t('Extra requests add to a subscription with a request package. You need one first.'));
        }
        $ext = $this->forServiceServer($target);
        $properties = ExtensionHelper::getServiceProperties($target);
        $plan = $ext->seerrPlan(ExtensionHelper::settingsToArray($target->product->settings), $properties);
        $until = (int) (strtotime((string) ($mine[self::PROP_TOPUP_UNTIL] ?? '')) ?: $now + 86400 * (int) ($plan['days'] ?? 30));

        $list = array_values(array_filter($ext->extraRequestList($properties), fn ($e) => (int) $e['from'] !== (int) $topUp->id && (int) $e['until'] > $now));
        if ($until > $now) {
            $list[] = ['n' => $n, 'until' => $until, 'from' => (int) $topUp->id];
        }
        $this->setProperty($target, self::PROP_EXTRA_REQUESTS, 'Extra requests', $list ? json_encode($list) : null);
        $this->setProperty($topUp, self::PROP_TOPUP_TARGET, 'Adds requests to', (string) $target->id);
        $this->setProperty($topUp, self::PROP_TOPUP_UNTIL, 'Extra requests until', date(DATE_ATOM, $until));
        $ext->syncSeerrQuietly($target, 'active');

        return $n;
    }

    /** A refunded or ended top-up: its requests come off the package again. */
    private function withdrawTopUp(Service $topUp): void
    {
        $targetId = (int) (ExtensionHelper::getServiceProperties($topUp)[self::PROP_TOPUP_TARGET] ?? 0);
        if ($targetId === 0) {
            return;
        }
        foreach (Service::where('id', $targetId)->with(['product.server', 'properties'])->get() as $target) {
            $ext = $this->forServiceServer($target);
            $list = array_values(array_filter($ext->extraRequestList(ExtensionHelper::getServiceProperties($target)), fn ($e) => (int) $e['from'] !== (int) $topUp->id));
            $this->setProperty($target, self::PROP_EXTRA_REQUESTS, 'Extra requests', $list ? json_encode($list) : null);
            $ext->syncSeerrQuietly($target, ($target->status ?? '') === 'active' ? 'active' : 'suspended');
        }
    }

    /** The client area of a top-up: how many, for which subscription, until when. */
    private function topUpActions(array $properties): array
    {
        $until = strtotime((string) ($properties[self::PROP_TOPUP_UNTIL] ?? '')) ?: null;
        if ($until === null) {
            return [['type' => 'text', 'label' => self::t('Extra requests'), 'text' => self::t('Being added to your request package.')]];
        }

        return [['type' => 'text', 'label' => self::t('Extra requests'), 'text' => $until > time()
            ? self::t('Added to subscription #:id, until :date', ['id' => $properties[self::PROP_TOPUP_TARGET] ?? '?', 'date' => date(self::t('M j, Y'), $until)])
            : self::t('Expired :date', ['date' => date(self::t('M j, Y'), $until)])]];
    }
}
