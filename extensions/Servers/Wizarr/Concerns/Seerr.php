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

/** Seerr as a paid add-on: accounts, permissions, request packages. Part of Wizarr (Paywarr); uses its constants and helpers. */
trait Seerr
{
    /* ------------------------------------------------------------------
     | Seerr add-on
     * ----------------------------------------------------------------- */

    /** Bought as an option on the subscription, or included in the product. */
    public function wantsSeerr(array $productSettings, array $properties): bool
    {
        return $this->seerrPlan($productSettings, $properties) !== null;
    }

    /**
     * What the requests option's value means: null for no Seerr, otherwise
     * the request limit ([limit, days], null = Seerr's defaults, limit 0 =
     * no limit):
     *
     *   no / nej / 0      no Seerr
     *   yes / ja / 1      Seerr, with Seerr's default limits
     *   5                 5 movies and 5 TV seasons a week
     *   10/30             10 of each per 30 days
     *   unlimited         no limit
     *
     * @return array{limit: ?int, days: ?int}|null
     */
    public function seerrPlan(array $productSettings, array $properties): ?array
    {
        $value = strtolower(trim((string) self::requestsChoice($properties)));
        $default = ['limit' => null, 'days' => null];

        if (in_array($value, ['ja', 'yes', 'y', '1', 'true', 'on'], true)) {
            return $default;
        }
        if (in_array($value, ['unlimited', 'ubegrænset', 'ubegraenset'], true)) {
            return ['limit' => 0, 'days' => null];
        }
        if (preg_match('#^(\d+)\s*(?:/\s*(\d+))?$#', $value, $match) && (int) $match[1] > 0) {
            return ['limit' => (int) $match[1], 'days' => isset($match[2]) && (int) $match[2] > 0 ? (int) $match[2] : 7];
        }

        // No (or no option): only if the product includes requests, or the server gives them to everyone.
        return $this->truthy($productSettings['seerr_included'] ?? false) || $this->config('requests_page_for') === 'all' ? $default : null;
    }

    /**
     * The plan with the extra requests bought and not expired on top. Only a
     * plan with a limit can grow; "unlimited" and Seerr's/Silo's defaults stay.
     */
    public function withExtraRequests(?array $plan, array $properties, ?int $now = null): ?array
    {
        $extra = $this->extraRequests($properties, $now);
        if ($plan === null || $extra === 0 || (int) $plan['limit'] <= 0) {
            return $plan;
        }

        return ['limit' => (int) $plan['limit'] + $extra, 'days' => $plan['days']];
    }

    /** Extra requests on a subscription that have not expired yet. */
    public function extraRequests(array $properties, ?int $now = null): int
    {
        $now ??= time();
        $sum = 0;
        foreach ($this->extraRequestList($properties) as $entry) {
            if ((int) $entry['until'] > $now) {
                $sum += (int) $entry['n'];
            }
        }

        return $sum;
    }

    /** @return array<int, array{n: int, until: int, from: int}> */
    private function extraRequestList(array $properties): array
    {
        $list = json_decode((string) ($properties[self::PROP_EXTRA_REQUESTS] ?? ''), true);

        return is_array($list) ? array_values(array_filter($list, fn ($e) => is_array($e) && isset($e['n'], $e['until'], $e['from']))) : [];
    }

    /** The customer's choice in the requests option ("requests", or "seerr" as it was called before). */
    public static function requestsChoice(array $properties): ?string
    {
        foreach ([self::REQUESTS_OPTION, self::SEERR_OPTION] as $option) {
            if (isset($properties[$option]) && trim((string) $properties[$option]) !== '') {
                return (string) $properties[$option];
            }
        }

        return null;
    }

    /** "5/7", "0/" or "default": the plan as stored on the service. */
    private function quotaKey(array $plan): string
    {
        return $plan['limit'] === null ? 'default' : $plan['limit'] . '/' . ($plan['days'] ?? '');
    }

    /**
     * Brings the customer's Seerr account in line with their subscription.
     *
     *   active + add-on    -> account exists (imported once the Jellyfin user
     *                         exists), permissions as before a suspension
     *   suspended + add-on -> account kept, all permissions taken away until
     *                         paid; Seerr keeps people logged in for 30 days
     *   ended / no add-on  -> account deleted (Seerr deletes their requests)
     *
     * Only accounts this extension imported are ever changed or deleted. A
     * customer who already had a Seerr account is left alone.
     *
     * @param  string  $state  'active', 'suspended' or 'ended'
     */
    public function syncSeerr(Service $service, array $productSettings, array $properties, string $state): void
    {
        if ($this->isSilo()) {
            $this->syncSiloRequests($service, $productSettings, $properties, $state);

            return;
        }
        if (!$this->seerrConfigured()) {
            return;
        }
        $instance = (string) ($properties[self::PROP_SEERR_INSTANCE] ?? '') ?: '1';
        $managed = (int) ($properties[self::PROP_SEERR_USER] ?? 0);

        if ($state === 'ended' || !$this->wantsSeerr($productSettings, $properties)) {
            if ($managed && in_array($instance, $this->seerrInstances(), true)) {
                $this->seerr($instance)->deleteUser($managed);
            }
            if ($managed) {
                $this->forgetSeerrAccount($service);
            }

            return;
        }

        // That Seerr was removed from the settings, or the account in Seerr by hand.
        if ($managed && (!in_array($instance, $this->seerrInstances(), true) || !$this->seerr($instance)->userExists($managed))) {
            $managed = 0;
        }
        // The customer's media servers changed, and another Seerr serves them now.
        if ($managed && $state === 'active') {
            $target = $this->seerrForUsers($this->allUsers($properties));
            if ($target !== null && $target[0] !== $instance) {
                $this->seerr($instance)->deleteUser($managed);
                $managed = 0;
                Log::info('Wizarr: service ' . $service->id . ' moves to another Seerr (its media servers changed).');
            }
        }
        if (!$managed && (int) ($properties[self::PROP_SEERR_USER] ?? 0)) {
            $this->forgetSeerrAccount($service);
            $properties[self::PROP_SEERR_SAVED_PERMISSIONS] = null;
            $properties[self::PROP_SEERR_QUOTA] = null;
        }

        if (!$managed) {
            if ($state === 'suspended') {
                return; // never create access for someone who has not paid
            }
            // Each Seerr is connected to one media server: the customer's user
            // on the first server that has a Seerr gets the account.
            $users = $this->allUsers($properties);
            $target = $this->seerrForUsers($users);
            if ($target === null) {
                return; // no user yet, or none on a server any Seerr is connected to
            }
            [$instance, $type] = $target;
            $mine = array_values(array_filter($users, fn ($user) => $user['type'] === $type));
            $managed = $type === 'plex'
                ? $this->importPlexUser($this->seerr($instance), $mine)
                : $this->importJellyfinUser($this->seerr($instance), $mine);
            if ($managed === null) {
                return;
            }
            $this->setProperty($service, self::PROP_SEERR_USER, 'Seerr user ID', (string) $managed);
            $this->setProperty($service, self::PROP_SEERR_INSTANCE, 'Seerr instance', $instance === '1' ? null : $instance);
        }
        $seerr = $this->seerr($instance);

        $saved = $properties[self::PROP_SEERR_SAVED_PERMISSIONS] ?? null;
        if ($state === 'suspended' && ($saved === null || $saved === '')) {
            $current = $seerr->permissions($managed);
            if ($current !== 0) {
                $this->setProperty($service, self::PROP_SEERR_SAVED_PERMISSIONS, 'Seerr permissions before suspension', (string) $current);
                $seerr->setPermissions($managed, 0);
            }
        } elseif ($state === 'active' && $saved !== null && $saved !== '') {
            $seerr->setPermissions($managed, (int) $saved);
            $this->setProperty($service, self::PROP_SEERR_SAVED_PERMISSIONS, '', null);
        }

        // The request limit the customer bought, sent only when it changes.
        if ($state === 'active') {
            $plan = $this->withExtraRequests($this->seerrPlan($productSettings, $properties), $properties);
            $key = $this->quotaKey($plan);
            // Nothing stored means Seerr's defaults, as a freshly imported user has.
            $stored = (string) ($properties[self::PROP_SEERR_QUOTA] ?? '');
            if (($stored === '' ? 'default' : $stored) !== $key) {
                $seerr->setQuota($managed, $plan['limit'], $plan['days']);
                $this->setProperty($service, self::PROP_SEERR_QUOTA, 'Seerr request limit', $key);
            }
        }
    }

    /** A Jellyfin or Emby user into Seerr; null when they already had an account there. */
    private function importJellyfinUser(SeerrClient $seerr, array $users): ?int
    {
        $usernames = array_column($users, 'username');
        $match = null;
        foreach ($seerr->mediaServerUsers() as $candidate) {
            foreach ($usernames as $name) {
                if ($match === null && strcasecmp($candidate['username'], $name) === 0) {
                    $match = $candidate;
                }
            }
        }
        if ($match === null) {
            throw new SeerrException('Seerr does not list a user called "' . $usernames[0] . '". Is Seerr connected to the same Jellyfin or Emby?');
        }
        if ($seerr->userIdForJellyfinUser($match['id']) !== null) {
            Log::info('Wizarr: ' . $match['username'] . ' already had a Seerr account; it is left as it is and not managed by Paymenter.');

            return null;
        }

        return $seerr->importJellyfinUser($match['id']);
    }

    /**
     * A Plex user into Seerr, found by e-mail or username. Seerr only lists
     * Plex users without an account, who have accepted the share: anyone else
     * is left alone (and tried again next hour).
     */
    private function importPlexUser(SeerrClient $seerr, array $users): ?int
    {
        $wanted = [];
        foreach ($users as $user) {
            $wanted[] = strtolower($user['username']);
            if (($user['email'] ?? '') !== '') {
                $wanted[] = strtolower($user['email']);
            }
        }
        foreach ($seerr->plexUsersToImport() as $candidate) {
            if (in_array(strtolower($candidate['email']), $wanted, true) || in_array(strtolower($candidate['username']), $wanted, true)) {
                return $seerr->importPlexUser($candidate['id']);
            }
        }
        Log::info('Wizarr: Seerr has no Plex user to import for ' . ($users[0]['username'] ?? '?') . ' (they already have an account, or have not accepted the share yet).');

        return null;
    }

    private function forgetSeerrAccount(Service $service): void
    {
        foreach ([self::PROP_SEERR_USER, self::PROP_SEERR_SAVED_PERMISSIONS, self::PROP_SEERR_QUOTA, self::PROP_SEERR_INSTANCE] as $prop) {
            $this->setProperty($service, $prop, '', null);
        }
    }

    /**
     * Which Seerr serves these users: [instance, media server type], for the
     * first of Jellyfin, Emby and Plex they have that a Seerr is connected to.
     */
    private function seerrForUsers(array $users): ?array
    {
        $types = array_unique(array_column($users, 'type'));
        foreach (['jellyfin', 'emby', 'plex'] as $type) {
            if (!in_array($type, $types, true)) {
                continue;
            }
            foreach ($this->seerrInstances() as $n) {
                if ($this->seerrType($n) === $type) {
                    return [$n, $type];
                }
            }
        }

        return null;
    }

    /** The configured Seerr instances: "1" (the first fields), "2", "3". */
    private function seerrInstances(): array
    {
        return array_values(array_filter(['1', '2', '3'], fn ($n) => trim((string) $this->config($this->seerrPrefix($n) . 'url')) !== ''
            && trim((string) $this->config($this->seerrPrefix($n) . 'api_key')) !== ''));
    }

    private function seerrPrefix(string $n): string
    {
        return $n === '1' ? 'seerr_' : 'seerr' . $n . '_';
    }

    /** The media server a Seerr is connected to, asked once an hour. */
    private function seerrType(string $n): ?string
    {
        $url = trim((string) $this->config($this->seerrPrefix($n) . 'url'));

        return Cache::remember('wizarr:seerr-media-type:' . md5($url), 3600, fn () => $this->seerr($n)->mediaServerType());
    }

    /** Seerr must never break a payment: log it and let the hourly run try again. */
    private function syncSeerrQuietly(Service $service, string $state): void
    {
        if ((!$this->seerrConfigured() && !$this->isSilo()) || !$service->product) {
            return;
        }
        try {
            $this->syncSeerr(
                $service,
                ExtensionHelper::settingsToArray($service->product->settings),
                ExtensionHelper::getServiceProperties($service),
                $state,
            );
        } catch (Throwable $e) {
            Log::warning('Wizarr: Seerr for service ' . $service->id . ' not updated, will retry: ' . $e->getMessage());
        }
    }

    /** Hourly: every subscription on a Wizarr product. */
    public function reconcileSeerr(?iterable $services = null): void
    {
        $services ??= Service::whereIn('status', ['active', 'suspended', 'cancelled'])
            ->whereHas('product.server', fn ($query) => $query->where('extension', 'Wizarr'))
            ->get();
        foreach ($services as $service) {
            $state = ['active' => 'active', 'suspended' => 'suspended', 'cancelled' => 'ended'][$service->status];
            // Each subscription with its own server's settings: Wizarr's Seerr, or Silo's requests.
            $this->forServiceServer($service)->syncSeerrQuietly($service, $state);
        }
    }

    /** @var array<int, static> */
    private array $serverInstances = [];

    /**
     * The extension with the settings of the server a service's product is on.
     * Paymenter boots one instance, with the first Wizarr server's settings,
     * for the hourly run; a shop with a Wizarr and a Silo server needs each
     * subscription handled with its own server's.
     */
    private function forServiceServer(Service $service): static
    {
        $server = $service->product?->server;
        if (!$server || !isset($server->id)) {
            return $this;
        }

        return $this->serverInstances[(int) $server->id] ??= new static(ExtensionHelper::settingsToArray($server->settings));
    }

    private function isSilo(): bool
    {
        return $this->config('backend') === 'silo';
    }

    /**
     * On Silo the requests option sells Silo's own requests: allowed with the
     * package's limit while active, not allowed otherwise. Sent only when
     * that changes. The account itself goes when the subscription ends.
     */
    private function syncSiloRequests(Service $service, array $productSettings, array $properties, string $state): void
    {
        if ($state === 'ended') {
            $this->setProperty($service, self::PROP_SILO_REQUESTS, '', null);

            return;
        }
        $users = array_values(array_filter($this->allUsers($properties, resolve: false), fn ($user) => $user['type'] === 'silo'));
        if (!$users) {
            $users = array_values(array_filter($this->allUsers($properties), fn ($user) => $user['type'] === 'silo'));
        }
        if (!$users) {
            return; // no account yet
        }
        $plan = $state === 'active' ? $this->withExtraRequests($this->seerrPlan($productSettings, $properties), $properties) : null;
        $key = $plan === null ? 'off' : $this->quotaKey($plan);
        if ((string) ($properties[self::PROP_SILO_REQUESTS] ?? '') === $key) {
            return;
        }
        foreach ($users as $user) {
            $this->client()->setRequestsAllowed($user['id'], $plan !== null);
            if ($plan !== null) {
                $this->client()->setRequestLimit($user['id'], $plan['limit'], $plan['days']);
            }
        }
        $this->setProperty($service, self::PROP_SILO_REQUESTS, 'Silo requests', $key);
    }

    private static bool $seerrGuardListener = false;

    /**
     * Refuses Requests (Seerr) for a choice of media servers that Seerr is
     * not connected to: Seerr only imports users of its own media server, so
     * the customer would pay for something they cannot use. If Seerr or
     * Wizarr cannot be asked, the purchase goes ahead (and is logged).
     *
     * @param  string|null  $seerrType  for tests; asked from Seerr otherwise
     */
    public function checkSeerrServer(array $productSettings, array $properties, string|array|null $seerrTypes = null): void
    {
        // Silo has its own requests: they always fit.
        if ($this->isSilo() || !$this->seerrConfigured() || !$this->wantsSeerr($productSettings, $properties)) {
            return;
        }
        try {
            $seerrTypes = $seerrTypes === null ? array_map(fn ($n) => $this->seerrType($n), $this->seerrInstances()) : (array) $seerrTypes;
            if (in_array(null, $seerrTypes, true)) {
                return; // a Seerr without a media server: nothing to check against
            }
            $types = array_map(fn ($id) => $this->serverInfo()[$id]['type'] ?? '', $this->chosenServerIds($productSettings, $properties));
        } catch (Throwable $e) {
            Log::warning('Wizarr: could not check that Requests fit the chosen media server; the purchase goes ahead: ' . $e->getMessage());

            return;
        }
        if (!array_intersect($seerrTypes, $types)) {
            $names = array_map(fn ($type) => ['jellyfin' => 'Jellyfin', 'emby' => 'Emby', 'plex' => 'Plex'][$type] ?? $type, array_values(array_unique($seerrTypes)));
            $name = count($names) > 1 ? implode(', ', array_slice($names, 0, -1)) . ' ' . self::t('or') . ' ' . end($names) : $names[0];

            throw new DisplayException(self::t('Requests need :server. Choose a media server with :server, or remove Requests.', ['server' => $name]));
        }
    }

    /** InvoiceItem "creating": an order's line, after its options are saved. */
    public function guardSeerrForItem($item): void
    {
        if (($item->reference_type ?? null) !== Service::class || !($service = $item->reference)) {
            return;
        }
        $server = $service->product?->server;
        if ($server?->extension !== 'Wizarr') {
            return;
        }
        $extension = new static(ExtensionHelper::settingsToArray($server->settings));
        $productSettings = ExtensionHelper::settingsToArray($service->product->settings);
        if ($extension->isTopUp($productSettings)) {
            if ($extension->topUpTarget($service) === null) {
                throw new DisplayException(self::t('Extra requests add to a subscription with a request package. You need one first.'));
            }

            return;
        }
        $extension->checkSeerrServer($productSettings, ExtensionHelper::getServiceProperties($service));
    }

    /**
     * ServiceUpgrade "updated", after the new options are saved: when its
     * invoice is attached, or when a free change is completed at once. Never
     * when a paid upgrade completes after payment.
     */
    public function guardSeerrForUpgrade($upgrade): void
    {
        $invoiceAttached = $upgrade->status === 'pending' && $upgrade->wasChanged('invoice_id');
        $freeCompleted = $upgrade->status === 'completed' && $upgrade->wasChanged('status') && empty($upgrade->invoice_id);
        $server = $upgrade->product?->server;
        if ((!$invoiceAttached && !$freeCompleted) || $server?->extension !== 'Wizarr') {
            return;
        }

        // The service's options, with the upgrade's new choices on top.
        $properties = ExtensionHelper::getServiceProperties($upgrade->service);
        foreach ($upgrade->configs as $config) {
            $properties[$config->configOption->env_variable] = $config->configValue->env_variable ?? $config->configValue->name;
        }
        (new static(ExtensionHelper::settingsToArray($server->settings)))
            ->checkSeerrServer(ExtensionHelper::settingsToArray($upgrade->product->settings), $properties);
    }

    private function seerrConfigured(): bool
    {
        return $this->seerrInstances() !== [];
    }

    /** @var array<string, SeerrClient> */
    private array $seerrClients = [];

    private function seerr(string $n = '1'): SeerrClient
    {
        return $this->seerrClients[$n] ??= new SeerrClient(
            trim((string) $this->config($this->seerrPrefix($n) . 'url')),
            trim((string) $this->config($this->seerrPrefix($n) . 'api_key')),
        );
    }
}
