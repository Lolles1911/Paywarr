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

/** "Report a problem": a fault in a movie or show, as a support ticket. Part of Wizarr (Paywarr); uses its constants and helpers. */
trait Reports
{
    /* ------------------------------------------------------------------
     | Problem reports: a movie or show with a fault, as a support ticket
     * ----------------------------------------------------------------- */

    /** What the customer can report, in their words; staff read the key. */
    /** What "Report a problem" adds to the Requests tab's look: steps, the problem choices, fields. */
    private const REPORT_CSS = '#paywarr-report{max-width:56rem}'
        . '#paywarr-report .pr-steps{display:flex;flex-wrap:wrap;gap:.5rem;margin:0 0 1.5rem;padding:0;list-style:none;font-size:.85rem}'
        . '#paywarr-report .pr-steps li{display:flex;gap:.45rem;align-items:center;padding:.35rem .8rem .35rem .4rem;border-radius:9999px;border:1px solid hsl(var(--color-neutral));opacity:.65}'
        . '#paywarr-report .pr-steps li b{display:inline-flex;width:1.4rem;height:1.4rem;border-radius:9999px;align-items:center;justify-content:center;background:hsl(var(--color-neutral));font-size:.75rem}'
        . '#paywarr-report .pr-steps li.pr-on{opacity:1;border-color:hsl(var(--color-primary))}#paywarr-report .pr-steps li.pr-on b{background:hsl(var(--color-primary));color:hsl(var(--color-background))}'
        . '#paywarr-report a.pr-tile{display:block;color:inherit;text-decoration:none}#paywarr-report a.pr-tile .pr-cover{transition:transform .15s,box-shadow .15s}'
        . '#paywarr-report a.pr-tile:hover .pr-cover,#paywarr-report a.pr-tile:focus-visible .pr-cover{transform:translateY(-3px);box-shadow:0 0 0 2px hsl(var(--color-primary)),0 10px 26px rgb(0 0 0 / .35)}'
        . '#paywarr-report a.pr-tile:hover .pr-title{color:hsl(var(--color-primary))}'
        . '#paywarr-report .pr-form{display:flex;flex-direction:column;gap:1.25rem}#paywarr-report .pr-label{display:block;font-weight:700;font-size:.9rem;margin-bottom:.5rem}'
        . '#paywarr-report .pr-choices{display:grid;grid-template-columns:repeat(auto-fill,minmax(14rem,1fr));gap:.5rem}'
        . '#paywarr-report .pr-choice{position:relative;display:block;cursor:pointer}#paywarr-report .pr-choice input{position:absolute;opacity:0;pointer-events:none}'
        . '#paywarr-report .pr-choice>span{display:block;height:100%;padding:.7rem .9rem;border-radius:.75rem;border:1px solid hsl(var(--color-neutral));font-size:.9rem;line-height:1.35}'
        . '#paywarr-report .pr-choice input:checked+span{border-color:hsl(var(--color-primary));background:hsl(var(--color-primary) / .12);box-shadow:inset 0 0 0 1px hsl(var(--color-primary))}'
        . '#paywarr-report .pr-choice input:focus-visible+span{outline:2px solid hsl(var(--color-primary));outline-offset:2px}'
        . '#paywarr-report .pr-fields{display:grid;grid-template-columns:1fr 1fr;gap:1rem}'
        . '#paywarr-report .pr-input{width:100%;padding:.65rem .9rem;border-radius:.75rem;border:1px solid hsl(var(--color-neutral));background:hsl(var(--color-background));color:inherit;font:inherit}'
        . '#paywarr-report .pr-input:focus{outline:none;border-color:hsl(var(--color-primary))}#paywarr-report textarea.pr-input{resize:vertical;min-height:6rem}'
        . '#paywarr-report .pr-show{justify-content:space-between}#paywarr-report .pr-show>div{display:flex;gap:1rem;align-items:center;min-width:0}'
        . '#paywarr-report .pr-link{color:hsl(var(--color-primary));font-size:.9rem;white-space:nowrap}#paywarr-report .pr-link:hover{text-decoration:underline}'
        . '@media (max-width:640px){#paywarr-report .pr-grid{grid-template-columns:repeat(3,minmax(0,1fr));gap:.7rem}#paywarr-report .pr-title{font-size:.85rem}'
        . '#paywarr-report .pr-show{flex-wrap:wrap;gap:.75rem}#paywarr-report .pr-card{padding:1rem}}';

    public const REPORT_PROBLEMS = [
        'video' => 'Picture (stutters, wrong quality, won\'t play)',
        'audio' => 'Sound (missing, out of sync, wrong language)',
        'subtitles' => 'Subtitles (missing, wrong, out of sync)',
        'episode' => 'Wrong or missing episode',
        'other' => 'Something else',
    ];

    /**
     * Where this customer can report from: one entry per active subscription,
     * on Silo (its catalog) or with Seerr (titles on the server). Without a
     * Seerr, a Wizarr server has nothing to search, so it isn't one.
     *
     * @return array<int, array{service: Service, ext: static, kind: string}>
     */
    public function reportSources($user, ?iterable $services = null): array
    {
        return $this->librarySources($user, $services, false);
    }

    /** As reportSources(), only subscriptions with requests (the "requests" option, "Requests included", or everyone). */
    private function librarySources($user, ?iterable $services, bool $withRequests): array
    {
        if (!$user) {
            return [];
        }
        $services ??= Service::where('user_id', $user->id)->where('status', 'active')->with(['product.server', 'properties'])->get();
        $sources = [];
        foreach ($services as $service) {
            if (($service->status ?? '') !== 'active' || $service->product?->server?->extension !== 'Wizarr') {
                continue;
            }
            $ext = $this->forServiceServer($service);
            if ($withRequests && $ext->seerrPlan(ExtensionHelper::settingsToArray($service->product->settings), ExtensionHelper::getServiceProperties($service)) === null) {
                continue;
            }
            if ($ext->isSilo()) {
                $sources[] = ['service' => $service, 'ext' => $ext, 'kind' => 'silo'];
            } elseif ($ext->seerrConfigured()) {
                $sources[] = ['service' => $service, 'ext' => $ext, 'kind' => 'seerr'];
            }
        }

        return $sources;
    }

    /**
     * Titles matching $query in the customer's libraries: key (what the form
     * sends back), title, year, kind (movie|show), poster (an address), server.
     *
     * @param  callable(int, string): string  $siloPoster  address of a Silo poster, by service and content ID
     */
    public function reportSearch(array $sources, string $query, ?callable $siloPoster = null): array
    {
        $query = trim(mb_substr($query, 0, 100));
        if (mb_strlen($query) < 2) {
            return [];
        }
        $siloPoster ??= fn (int $service, string $item) => route('extensions.wizarr.report.poster', ['service' => $service]) . '?item=' . rawurlencode($item);
        $results = [];
        $asked = [];
        foreach ($sources as $source) {
            $id = (int) $source['service']->id;
            $label = $source['kind'] === 'silo' ? 'Silo' : 'Seerr';
            // Two subscriptions on the same library: ask it once.
            $where = $source['kind'] . '|' . ($source['kind'] === 'silo' ? $source['ext']->config('host') : $source['ext']->seerrInstanceOf($source['service']));
            if (isset($asked[$where])) {
                continue;
            }
            $asked[$where] = true;
            try {
                if ($source['kind'] === 'silo') {
                    foreach ($source['ext']->client()->searchCatalog($query) as $item) {
                        $results[] = ['key' => $id . ':silo:' . $item['id'], 'title' => $item['title'], 'year' => $item['year'], 'kind' => $item['type'] === 'series' ? 'show' : 'movie',
                            'poster' => $siloPoster($id, $item['id']), 'server' => $label];
                    }
                } else {
                    foreach ($source['ext']->seerr($source['ext']->seerrInstanceOf($source['service']))->searchAvailable($query) as $item) {
                        $results[] = ['key' => $id . ':' . $item['type'] . ':' . $item['id'], 'title' => $item['title'], 'year' => $item['year'], 'kind' => $item['type'] === 'tv' ? 'show' : 'movie',
                            'poster' => $item['posterPath'] ? 'https://image.tmdb.org/t/p/w185' . $item['posterPath'] : null, 'server' => $label];
                    }
                }
            } catch (Throwable $e) {
                Log::warning('Wizarr: problem report search failed for service ' . $id . ': ' . $e->getMessage());
            }
        }

        return array_slice($results, 0, 24);
    }

    /**
     * The chosen title, looked up again where it lives (never taken from the
     * form): title, year, kind, where (for staff), service. Null when the key
     * isn't one of this customer's libraries or the title is gone.
     */
    public function reportTitle(array $sources, string $key): ?array
    {
        if (!preg_match('/^(\d+):(silo|movie|tv):([A-Za-z0-9:._-]{1,200})$/', $key, $m)) {
            return null;
        }
        foreach ($sources as $source) {
            if ((int) $source['service']->id !== (int) $m[1] || ($source['kind'] === 'silo') !== ($m[2] === 'silo')) {
                continue;
            }
            if ($m[2] === 'silo') {
                $item = $source['ext']->client()->catalogItem($m[3]);
                if (!$item || !in_array($item['type'] ?? '', ['movie', 'series'], true)) {
                    return null;
                }

                return ['title' => (string) $item['title'], 'year' => isset($item['year']) ? (int) $item['year'] : null, 'kind' => $item['type'] === 'series' ? 'show' : 'movie',
                    'where' => 'Silo, catalog item ' . $m[3], 'service' => $source['service'], 'silo_item' => $m[3], 'poster' => null];
            }
            if (!ctype_digit($m[3])) {
                return null;
            }
            $item = $source['ext']->seerr($source['ext']->seerrInstanceOf($source['service']))->title($m[2], (int) $m[3]);

            return $item === null ? null : ['title' => $item['title'], 'year' => $item['year'], 'kind' => $m[2] === 'tv' ? 'show' : 'movie',
                'where' => 'Seerr, TMDB ' . $m[2] . ' ' . $m[3], 'service' => $source['service'],
                'poster' => $item['posterPath'] ? 'https://image.tmdb.org/t/p/w300' . $item['posterPath'] : null];
        }

        return null;
    }

    /**
     * The ticket for a report: subject and message for staff, the subscription
     * it is about. Refuses (DisplayException, in the customer's language) a
     * title that isn't theirs, an unknown problem or a too long text.
     *
     * @return array{service_id: int, subject: string, message: string}
     */
    public function reportTicket(array $sources, array $input): array
    {
        $title = $this->reportTitle($sources, (string) ($input['pick'] ?? ''));
        if ($title === null) {
            throw new DisplayException(self::t('Choose the movie or show again; that one can\'t be found.'));
        }
        $problem = (string) ($input['problem'] ?? '');
        if (!isset(self::REPORT_PROBLEMS[$problem])) {
            throw new DisplayException(self::t('Choose what the problem is.'));
        }
        $text = trim(str_replace("\r\n", "\n", (string) ($input['description'] ?? '')));
        if (mb_strlen($text) > 2000) {
            throw new DisplayException(self::t('The description can be at most 2000 characters.'));
        }
        $number = fn ($value) => is_numeric($value) && (int) $value >= 0 && (int) $value <= 9999 ? (int) $value : null;
        $season = $title['kind'] === 'show' ? $number($input['season'] ?? null) : null;
        $episode = $title['kind'] === 'show' ? $number($input['episode'] ?? null) : null;

        $service = $title['service'];
        $name = $title['title'] . ($title['year'] ? ' (' . $title['year'] . ')' : '');
        $users = array_map(fn ($user) => $user['username'] . ' (' . ($user['type'] ?: '?') . ')', $this->allUsers(ExtensionHelper::getServiceProperties($service), resolve: false));
        $lines = [
            'Problem report from the client area.',
            '',
            'Title: ' . $name . ', ' . $title['kind'],
            'Where: ' . $title['where'],
        ];
        if ($season !== null || $episode !== null) {
            $lines[] = trim(($season !== null ? 'Season ' . $season : '') . ($season !== null && $episode !== null ? ', ' : '') . ($episode !== null ? 'episode ' . $episode : ''));
        }
        $lines[] = 'Problem: ' . self::REPORT_PROBLEMS[$problem];
        $lines[] = 'Subscription: #' . $service->id . ($service->product?->name ? ' ' . $service->product->name : '');
        if ($users) {
            $lines[] = 'Media server user: ' . implode(', ', $users);
        }
        $lines[] = '';
        $lines[] = 'The customer wrote:';
        $lines[] = $text !== '' ? $text : '(nothing)';

        return [
            'service_id' => (int) $service->id,
            'subject' => mb_substr('Problem: ' . $name . ($season !== null || $episode !== null ? ' S' . ($season ?? '?') . 'E' . ($episode ?? '?') : '') . ' – ' . explode(' (', self::REPORT_PROBLEMS[$problem])[0], 0, 250),
            'message' => implode("\n", $lines),
        ];
    }

    /** The Seerr a customer's account is on (their media servers decide); "1" before they have one. */
    private function seerrInstanceOf(Service $service): string
    {
        $stored = (string) (ExtensionHelper::getServiceProperties($service)[self::PROP_SEERR_INSTANCE] ?? '');

        return $stored !== '' && in_array($stored, $this->seerrInstances(), true) ? $stored : ($this->seerrInstances()[0] ?? '1');
    }

    /** GET /wizarr/report: search, choose, describe. */
    public function reportPage()
    {
        $user = Auth::user();
        $sources = $this->reportSources($user);
        abort_unless($sources !== [], 404);
        $query = (string) request()->query('q', '');
        $pick = (string) request()->query('pick', '');
        $chosen = $pick !== '' ? $this->reportTitle($sources, $pick) : null;
        if ($chosen !== null && isset($chosen['silo_item'])) {
            $chosen['poster'] = route('extensions.wizarr.report.poster', ['service' => $chosen['service']->id]) . '?item=' . rawurlencode($chosen['silo_item']);
        }

        $content = view('wizarr::report', [
            'css' => $this->requestsCss('#paywarr-report') . self::REPORT_CSS,
            'query' => $query,
            'results' => $chosen === null ? $this->reportSearch($sources, $query) : [],
            'pick' => $chosen === null ? '' : $pick,
            'chosen' => $chosen,
            'problems' => array_map(fn ($label) => self::t($label), self::REPORT_PROBLEMS),
            'error' => session('wizarr_report_error'),
            't' => fn (string $text, array $replace = []) => self::t($text, $replace),
        ])->render();

        return view('layouts.app', ['slot' => new HtmlString($content), 'title' => self::t('Report a problem')]);
    }

    /** POST /wizarr/report: the ticket. */
    public function reportSubmit()
    {
        $user = Auth::user();
        $sources = $this->reportSources($user);
        abort_unless($sources !== [], 404);
        $back = fn (string $message) => redirect()->route('extensions.wizarr.report', ['pick' => (string) request()->input('pick', '')])->with('wizarr_report_error', $message);

        // As Paymenter's own "new ticket": one a half minute; and ten a day.
        $key = 'wizarr-report:' . $user->id;
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 1) || \Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key . ':day', 10)) {
            return $back(self::t('Too many reports right now. Please try again in a little while.'));
        }
        try {
            $ticket = $this->reportTicket($sources, request()->only(['pick', 'problem', 'season', 'episode', 'description']));
        } catch (DisplayException $e) {
            return $back($e->getMessage());
        }
        \Illuminate\Support\Facades\RateLimiter::hit($key, 30);
        \Illuminate\Support\Facades\RateLimiter::hit($key . ':day', 86400);

        $departments = array_values((array) config('settings.ticket_departments'));
        $wanted = trim((string) $sources[0]['ext']->config('report_department'));
        $created = \App\Models\Ticket::create([
            'user_id' => $user->id,
            'department' => in_array($wanted, $departments, true) ? $wanted : ($departments[0] ?? null),
            'service_id' => $ticket['service_id'],
            'subject' => $ticket['subject'],
            'priority' => 'medium',
        ]);
        $created->messages()->create(['user_id' => $user->id, 'message' => $ticket['message']]);

        return redirect()->route('tickets.show', $created);
    }

    /** GET /wizarr/report/poster/{service}?item=...: a Silo poster, for the search results. */
    public function reportPoster($service)
    {
        $item = (string) request()->query('item', '');
        foreach ($this->reportSources(Auth::user()) as $source) {
            if ($source['kind'] === 'silo' && (int) $source['service']->id === (int) $service && preg_match('/^[A-Za-z0-9:._-]{1,200}$/', $item)) {
                $image = $source['ext']->client()->poster($item);
                if ($image) {
                    return response($image['body'], 200, ['Content-Type' => $image['type'], 'Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
                }
            }
        }
        abort(404);
    }
}
