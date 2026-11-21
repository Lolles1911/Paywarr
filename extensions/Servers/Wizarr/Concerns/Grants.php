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

/** Grants, invitations, and finding the users behind them. Part of Wizarr (Paywarr); uses its constants and helpers. */
trait Grants
{
    /* ------------------------------------------------------------------
     | Grants: one invitation, and the user(s) it made, per media server
     * ----------------------------------------------------------------- */

    /*
     * A subscription has one grant per media server it gives access to, each
     * with its own invitation: one invitation for several servers does not
     * work well in Wizarr. A grant's properties carry the server ID as suffix
     * ("wizarr_invite_code:4"). Subscriptions from before 1.11 have a single
     * grant without suffix (key ""), which may cover several servers; it is
     * kept as it is.
     *
     * The single-invitation code below works on "the current grant": key()
     * names its properties and grantView() shows its values under the plain
     * names, so withGrant() can run that code once per server.
     */

    private string $grant = '';

    /** @var array<int, array{name: string, type: string, url: string}>|null */
    private ?array $serverInfo = null;

    /** The property name for the current grant. */
    private function key(string $prop): string
    {
        return $this->grant === '' || !in_array($prop, self::GRANT_PROPS, true) ? $prop : $prop . ':' . $this->grant;
    }

    /** $properties with the current grant's values under the plain names. Idempotent. */
    private function grantView(array $properties): array
    {
        if ($this->grant === '') {
            return $properties;
        }
        foreach (self::GRANT_PROPS as $prop) {
            $properties[$prop] = $properties[$prop . ':' . $this->grant] ?? null;
        }

        return $properties;
    }

    /** Runs $fn with $grant as the current grant. */
    private function withGrant(string $grant, callable $fn): mixed
    {
        $before = $this->grant;
        $this->grant = $grant;
        try {
            return $fn();
        } finally {
            $this->grant = $before;
        }
    }

    /**
     * The grants a service has: "" for a pre-1.11 one, then server IDs, in
     * a fixed order (the client area's buttons are numbered by it).
     *
     * @return string[]
     */
    public function grantKeys(array $properties): array
    {
        $keys = [];
        foreach ($properties as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            foreach (self::GRANT_PROPS as $prop) {
                if ($prop === self::PROP_SERVER_LABEL || $prop === self::PROP_SERVER_URL) {
                    continue; // a label alone is no grant
                }
                if ($name === $prop) {
                    $keys[''] = true;
                } elseif (str_starts_with((string) $name, $prop . ':') && preg_match('/^\d+(\.\d+)?$/', substr((string) $name, strlen($prop) + 1))) {
                    // "4" a server; "4.2" an extra user on it.
                    $keys[substr((string) $name, strlen($prop) + 1)] = true;
                }
            }
        }
        $keys = array_map('strval', array_keys($keys));
        usort($keys, fn ($a, $b) => ($a === '' ? [-1, 0] : [(int) $a, self::seatOf($a)]) <=> ($b === '' ? [-1, 0] : [(int) $b, self::seatOf($b)]));

        return $keys;
    }

    /** Which user on the server a grant is: 1 for the subscription's own, 2 and up for extra users ("4.2"). */
    public static function seatOf(string $grant): int
    {
        return str_contains($grant, '.') ? max(1, (int) substr($grant, strpos($grant, '.') + 1)) : 1;
    }

    /** " · user 2" for an extra user, '' otherwise. */
    private function seatSuffix(): string
    {
        $seat = self::seatOf($this->grant);

        return $seat > 1 ? ' · ' . self::t('user :n', ['n' => $seat]) : '';
    }

    /** How many extra users the subscription has: the "extra_users" option, up to the product's "extra_users_max". */
    public function extraUsers(array $settings, array $properties): int
    {
        $max = (int) ($settings['extra_users_max'] ?? 0);

        return $max < 1 ? 0 : max(0, min($max, (int) ($properties[self::EXTRA_USERS_OPTION] ?? 0)));
    }

    /** True when the service has at least one invitation or user. */
    private function hasGrants(array $properties): bool
    {
        return $this->grantKeys($properties) !== [];
    }

    /** Wizarr's media servers: ID => name and type. Asked once per instance. */
    private function serverInfo(): array
    {
        if ($this->serverInfo === null) {
            // Filled only once Wizarr has answered: after a failed call the
            // next one asks again instead of getting "no servers".
            $info = [];
            foreach ($this->client()->servers() as $server) {
                $info[(int) $server['id']] = [
                    'name' => (string) ($server['name'] ?? ''),
                    'type' => strtolower((string) ($server['server_type'] ?? '')),
                    // "External URL" in Wizarr: where customers open this server.
                    'url' => $this->webAddress((string) ($server['external_url'] ?? '')),
                ];
            }
            $this->serverInfo = $info;
        }

        return $this->serverInfo;
    }

    /**
     * The servers this subscription should have: the customer's choice in
     * the "media_server" option; without one, what it had so far; for a new
     * subscription, every server on the product.
     *
     * @return int[]
     */
    public function chosenServerIds(array $settings, array $properties): array
    {
        $product = $this->idList($settings['server_ids'] ?? null);
        $choice = strtolower(trim((string) ($properties[self::MEDIA_OPTION] ?? '')));
        if ($choice === '') {
            $recorded = $this->idList((string) ($properties[self::PROP_SERVERS] ?? ''));
            if ($recorded) {
                return array_values(array_intersect($recorded, $product)) ?: $recorded;
            }

            return $product;
        }
        // Nothing to choose with one server (always so on Silo): the choice is
        // left alone, so a media server option left on the product can't stop an order.
        if (in_array($choice, ['all', 'both', 'begge', 'alle'], true) || count($product) <= 1) {
            return $product;
        }

        $wanted = array_filter(array_map('trim', preg_split('/[,;+\s]+/', $choice)));
        $info = $this->serverInfo();
        $chosen = [];
        foreach ($product as $id) {
            $server = $info[$id] ?? ['name' => '', 'type' => ''];
            if (in_array((string) $id, $wanted, true) || in_array($server['type'], $wanted, true) || in_array(strtolower($server['name']), $wanted, true)) {
                $chosen[] = $id;
            }
        }
        if (!$chosen) {
            throw new WizarrException('The chosen media server "' . $choice . '" matches none of the servers on this product. '
                . 'Its environment variable must be a server type (jellyfin, emby, plex), name or ID.');
        }

        return $chosen;
    }

    /**
     * The servers the current grant covers: its own for a per-server grant;
     * for a pre-1.11 grant, the servers its invitation or users are on.
     *
     * @return int[]
     */
    private function grantServerIds(array $settings, array $properties): array
    {
        if ($this->grant !== '') {
            return [(int) $this->grant];
        }
        $properties = $this->grantView($properties);
        $byName = [];
        foreach ($this->serverInfo() as $id => $server) {
            $byName[$server['name']] = $id;
        }
        $names = [];
        $invitation = $this->findInvitation($properties);
        foreach ((array) ($invitation['server_names'] ?? []) as $name) {
            $names[] = (string) $name;
        }
        foreach ($this->resolveUsers($properties, $invitation) as $user) {
            $names[] = (string) ($user['server'] ?? '');
        }
        $ids = array_values(array_unique(array_filter(array_map(fn ($n) => $byName[$n] ?? null, $names))));

        return $ids ?: $this->idList($settings['server_ids'] ?? null);
    }

    /** "Emby" for a per-server grant; "" for a pre-1.11 one. */
    private function grantLabel(array $properties): string
    {
        $label = (string) ($this->grantView($properties)[self::PROP_SERVER_LABEL] ?? '');

        return ($label !== '' ? $label : ($this->grant === '' ? '' : 'server ' . (int) $this->grant)) . $this->seatSuffix();
    }

    /**
     * Where the customer watches on the current grant's server: the server's
     * "External URL" in Wizarr. A subscription from before 1.11, or a server
     * without one on a single-server product, uses "Where customers watch".
     */
    private function watchUrl(array $settings, array $properties = [], ?Service $service = null): string
    {
        $productUrl = $this->webAddress((string) ($settings['login_url'] ?? ''));
        if ($this->grant === '') {
            return $productUrl;
        }
        // Wizarr's current "External URL", so an address added or changed
        // there shows up; the last one seen is kept for when Wizarr is down.
        $stored = (string) ($this->grantView($properties)[self::PROP_SERVER_URL] ?? '');
        try {
            $url = $this->serverUrls()[(int) $this->grant] ?? '';
            if ($url !== '' && $url !== $stored && $service) {
                $this->setProperty($service, $this->key(self::PROP_SERVER_URL), 'Wizarr media server address (' . $this->grantServerName() . ')', $url);
            }
        } catch (Throwable $e) {
            $url = $stored === '-' ? '' : $this->webAddress($stored);
        }
        if ($url !== '') {
            return $url;
        }

        return count($this->idList($settings['server_ids'] ?? null)) <= 1 ? $productUrl : '';
    }

    /**
     * Each server's "External URL" in Wizarr, by server ID. Kept for
     * SERVER_URLS_SECONDS, so page views don't ask Wizarr every time.
     *
     * @return array<int, string>
     */
    private function serverUrls(): array
    {
        // One list per Wizarr or Silo: their server IDs overlap (Silo's is always 1).
        $key = 'paywarr:server-urls:' . md5($this->backendKey());
        $urls = Cache::get($key);
        if (!is_array($urls)) {
            $urls = array_map(fn ($server) => $server['url'], $this->serverInfo());
            Cache::put($key, $urls, self::SERVER_URLS_SECONDS);
        }

        return $urls;
    }

    /** "emby.example.com" becomes "https://emby.example.com"; empty stays empty. */
    public function webAddress(string $url): string
    {
        $url = trim($url);

        return $url === '' || preg_match('~^[a-z][a-z0-9+.-]*://~i', $url) ? $url : 'https://' . ltrim($url, '/');
    }

    /** The current per-server grant's server name, from Wizarr. */
    private function grantServerName(): string
    {
        try {
            return $this->serverInfo()[(int) $this->grant]['name'] ?? ('server ' . $this->grant);
        } catch (Throwable $e) {
            return 'server ' . $this->grant;
        }
    }

    /**
     * Brings the grants in line with the servers chosen: a new invitation for
     * each server that has none, and the user(s) removed from a server that
     * is no longer chosen. Used at purchase and after an upgrade/downgrade.
     *
     * @return array<string, array> the invitations made or found, per grant
     */
    public function syncGrants(Service $service, array $settings, array $properties): array
    {
        // What exists, per server.
        $covered = $this->coveredServers($settings, $properties);

        $explicit = trim((string) ($properties[self::MEDIA_OPTION] ?? '')) !== '' || trim((string) ($properties[self::PROP_SERVERS] ?? '')) !== '';
        // A subscription from before 1.11, with no choice made: it keeps the
        // servers it has, even if the product has more by now.
        $wanted = !$explicit && $covered ? array_keys($covered) : $this->chosenServerIds($settings, $properties);
        if (!$wanted) {
            throw new WizarrException('This product has no media server selected. Edit the product and choose one.');
        }
        $this->setProperty($service, self::PROP_SERVERS, 'Wizarr media servers', implode(',', $wanted));

        // Servers no longer chosen.
        foreach ($covered as $id => $grant) {
            if (in_array($id, $wanted, true)) {
                continue;
            }
            $this->withGrant($grant, function () use ($service, $properties, $id, $wanted, $covered, $grant) {
                $keep = array_filter(array_keys($covered, $grant, true), fn ($other) => in_array($other, $wanted, true));
                if ($grant !== '' || !$keep) {
                    $this->terminateNow($service, $properties);
                } else {
                    $this->removeServerFromGrant($service, $properties, $id);
                }
            });
            Log::info('Wizarr: service ' . $service->id . ' no longer has media server ' . $id . '; its user there was removed.');
        }

        // Servers chosen: an invitation for each that has none, or the one it has.
        $ensure = function () use ($service, $settings, $properties) {
            $view = $this->grantView($properties);
            $invitation = $this->findInvitation($view);
            if ($invitation && ($invitation['status'] ?? '') === 'used') {
                $this->rememberUsers($service, $this->resolveUsers($view, $invitation));
            }
            if ($invitation || !empty($view[self::PROP_USERS])) {
                return ($invitation ?? []) + ['has_user' => !empty($view[self::PROP_USERS]) || ($invitation['status'] ?? '') === 'used'];
            }

            return $this->issueInvitation($service, $settings);
        };
        $invitations = [];
        foreach ($wanted as $id) {
            $grant = $covered[$id] ?? (string) $id;
            if (isset($invitations[$grant])) {
                continue;
            }
            $invitations[$grant] = $this->withGrant($grant, $ensure);
        }

        // Extra users: one more invitation per chosen server for each; those no longer bought lose their user.
        $extra = $this->extraUsers($settings, $properties);
        foreach ($this->grantKeys($properties) as $grant) {
            if (self::seatOf($grant) > 1 && (!in_array((int) $grant, $wanted, true) || self::seatOf($grant) > $extra + 1)) {
                $this->withGrant($grant, fn () => $this->terminateNow($service, $properties));
                Log::info('Wizarr: service ' . $service->id . ' no longer has extra user ' . $grant . '; its user there was removed.');
            }
        }
        foreach ($wanted as $id) {
            for ($seat = 2; $seat <= $extra + 1; $seat++) {
                $invitations[$id . '.' . $seat] = $this->withGrant($id . '.' . $seat, $ensure);
            }
        }

        return $invitations;
    }

    /**
     * Which grant covers which server.
     *
     * @return array<int, string> server ID => grant
     */
    private function coveredServers(array $settings, array $properties): array
    {
        $covered = [];
        foreach ($this->grantKeys($properties) as $grant) {
            if (self::seatOf($grant) > 1) {
                continue; // an extra user: syncGrants() handles those apart
            }
            foreach ($this->withGrant($grant, fn () => $this->grantServerIds($settings, $properties)) as $id) {
                $covered[$id] = $grant;
            }
        }

        return $covered;
    }

    /** A pre-1.11 grant that covered several servers loses one of them. */
    private function removeServerFromGrant(Service $service, array $properties, int $serverId): void
    {
        $properties = $this->grantView($properties);
        $name = $this->serverInfo()[$serverId]['name'] ?? null;
        $keep = [];
        foreach ($this->resolveUsers($properties, $this->findInvitation($properties)) as $user) {
            if ($name !== null && ($user['server'] ?? null) === $name) {
                $this->deleteUserQuietly($user['id']);
            } else {
                $keep[] = $user;
            }
        }
        $this->rememberUsers($service, $keep);
    }

    /**
     * Every user of the service, all grants together.
     *
     * @return array<int, array{id: int, username: string, type: string}>
     */
    private function allUsers(array $properties, bool $resolve = true): array
    {
        $users = [];
        foreach ($this->grantKeys($properties) as $grant) {
            $users = array_merge($users, $this->withGrant($grant, function () use ($properties, $resolve) {
                $view = $this->grantView($properties);

                return $resolve
                    ? $this->resolveUsers($view, $this->findInvitation($view))
                    : $this->parseUsers((string) ($view[self::PROP_USERS] ?? ''));
            }));
        }

        return $users;
    }

    /* ------------------------------------------------------------------
     | Invitations
     * ----------------------------------------------------------------- */

    private function issueInvitation(Service $service, array $settings): array
    {
        // A per-server grant invites to its own server only.
        $serverIds = $this->grant === '' ? $this->idList($settings['server_ids'] ?? null) : [(int) $this->grant];
        if (!$serverIds) {
            throw new WizarrException('This product has no media server selected. Edit the product and choose one.');
        }

        // A trial's end is handled here (disable, keep for a purchase). Letting
        // Wizarr expire the user could delete it, so trials never get a duration.
        $accessDays = $this->isTrial($this->trialSettings($settings, ExtensionHelper::getServiceProperties($service))) ? 0 : (int) ($settings['access_days'] ?? 0);

        $payload = [
            'server_ids' => $serverIds,
            // How long the *user* lives. Subscriptions leave this open and let
            // Paymenter end the access.
            'duration' => $accessDays > 0 ? (string) $accessDays : 'unlimited',
            // How many people may redeem the link. Wizarr's API default is
            // true, which would turn one subscription into an open door.
            'unlimited' => false,
            'allow_downloads' => $this->truthy($settings['allow_downloads'] ?? false),
            'allow_live_tv' => $this->truthy($settings['allow_live_tv'] ?? false),
        ];

        $lifetime = $this->linkLifetime($settings);
        if ($lifetime !== null) {
            $payload['expires_in_days'] = $lifetime;
        }

        $given = (string) (ExtensionHelper::getServiceProperties($service)[self::PROP_LIBRARIES] ?? '');
        $libraryIds = $this->idList($given !== '' ? $given : ($settings['library_ids'] ?? null));
        if ($libraryIds && $this->grant !== '') {
            // The product lists libraries of all its servers; this invitation
            // only takes this server's. None of them chosen: the server's default.
            $own = [];
            foreach ($this->client()->libraries() as $library) {
                if ((int) ($library['server_id'] ?? 0) === (int) $this->grant) {
                    $own[] = (int) $library['id'];
                }
            }
            $libraryIds = array_values(array_intersect($libraryIds, $own));
        }
        if ($libraryIds) {
            $payload['library_ids'] = $libraryIds;
        }

        $invitation = $this->client()->createInvitation($payload);
        $invitation['url'] = $this->absoluteUrl($invitation['url'] ?? null, (string) $invitation['code']);
        $this->setProperty($service, self::PROP_LINK_FAILED, '', null);

        $suffix = $this->grant === '' ? '' : ' (' . $this->grantServerName() . ')';
        $this->setProperty($service, $this->key(self::PROP_INVITATION_ID), 'Wizarr invitation ID' . $suffix, (string) ($invitation['id'] ?? ''));
        $this->setProperty($service, $this->key(self::PROP_INVITE_CODE), 'Wizarr invite code' . $suffix, (string) $invitation['code']);
        $this->setProperty($service, $this->key(self::PROP_INVITE_URL), 'Wizarr invite link' . $suffix, $invitation['url']);
        $this->setProperty($service, $this->key(self::PROP_INVITE_EXPIRES), 'Wizarr invite link expires' . $suffix, $invitation['expires'] ?? null);
        if ($this->grant !== '') {
            $this->setProperty($service, $this->key(self::PROP_SERVER_LABEL), 'Wizarr media server' . $suffix, $this->grantServerName());
            $this->setProperty($service, $this->key(self::PROP_SERVER_URL), 'Wizarr media server address' . $suffix, ($this->serverInfo()[(int) $this->grant]['url'] ?? '') ?: '-');
        }
        $this->rememberUsers($service, []);

        return $invitation;
    }

    /**
     * The invitation this service owns, as Wizarr sees it right now, or null
     * when it no longer exists.
     */
    private function findInvitation(array $properties): ?array
    {
        $properties = $this->grantView($properties);
        $id = (string) ($properties[self::PROP_INVITATION_ID] ?? '');
        $code = (string) ($properties[self::PROP_INVITE_CODE] ?? '');

        if ($id === '' && $code === '') {
            return null;
        }

        foreach ($this->client()->invitations() as $invitation) {
            // The code is checked too: SQLite may hand a deleted row's id to a
            // later invitation, and that one belongs to somebody else.
            $sameId = $id !== '' && (string) ($invitation['id'] ?? '') === $id;
            $sameCode = $code !== '' && (string) ($invitation['code'] ?? '') === $code;

            if (($sameId && ($code === '' || $sameCode)) || ($id === '' && $sameCode)) {
                // Silo shows the link only once, when it is made; it was stored then.
                if (empty($invitation['url']) && !empty($properties[self::PROP_INVITE_URL])) {
                    $invitation['url'] = (string) $properties[self::PROP_INVITE_URL];
                }

                return $invitation;
            }
        }

        return null;
    }

    private function forgetInvitation(Service $service): void
    {
        $this->setProperty($service, $this->key(self::PROP_INVITATION_ID), '', null);
        $this->setProperty($service, $this->key(self::PROP_INVITE_CODE), '', null);
        $this->setProperty($service, $this->key(self::PROP_INVITE_URL), '', null);
        $this->setProperty($service, $this->key(self::PROP_INVITE_EXPIRES), '', null);
        if ($this->grant !== '' && !$this->parseUsers((string) ($this->grantView(ExtensionHelper::getServiceProperties($service))[self::PROP_USERS] ?? ''))) {
            $this->setProperty($service, $this->key(self::PROP_SERVER_LABEL), '', null);
            $this->setProperty($service, $this->key(self::PROP_SERVER_URL), '', null);
        }
    }

    /** Wizarr returns a relative path (/j/<code>), or nothing at all. */
    private function absoluteUrl(?string $url, string $code): string
    {
        if ($url && preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $path = $url ?: '/j/' . $code;

        return rtrim((string) $this->config('public_url'), '/') . '/' . ltrim($path, '/');
    }

    /* ------------------------------------------------------------------
     | Finding the Wizarr user(s) behind a service
     * ----------------------------------------------------------------- */

    /**
     * Who redeemed this service's invitation.
     *
     * Deliberately narrow. It follows the invitation's own "used_by", then the
     * users this extension recorded earlier, and nothing else. It does not
     * guess by e-mail address: a customer may already have had an account
     * before subscribing, and disabling the wrong person is worse than
     * leaving one alone.
     *
     * @return array<int, array{id: int, username: string, type: string}>
     */
    private function resolveUsers(array $properties, ?array $invitation): array
    {
        $properties = $this->grantView($properties);
        $usedBy = is_array($invitation) ? trim((string) ($invitation['used_by'] ?? '')) : '';
        $recorded = $this->parseUsers((string) ($properties[self::PROP_USERS] ?? ''));

        if ($usedBy === '' && !$recorded) {
            return [];
        }

        $all = $this->client()->users();
        $found = [];

        if ($usedBy !== '' && strcasecmp($usedBy, 'None') !== 0) {
            $username = null;

            // Wizarr documents used_by as a username but currently serialises
            // the user object itself, which arrives as "<User 12>". Accept both.
            if (preg_match('/^<User (\d+)>$/', $usedBy, $match)) {
                foreach ($all as $user) {
                    if ((int) $user['id'] === (int) $match[1]) {
                        $found[(int) $user['id']] = $this->userRow($user);
                        $username = (string) $user['username'];
                    }
                }
            } else {
                $username = $usedBy;
            }

            // One invitation can create the same username on several servers.
            $servers = array_map('strval', (array) ($invitation['server_names'] ?? []));
            if ($username !== null) {
                foreach ($all as $user) {
                    $onInvitedServer = !$servers || in_array((string) ($user['server'] ?? ''), $servers, true);
                    if ((string) $user['username'] === $username && $onInvitedServer) {
                        $found[(int) $user['id']] = $this->userRow($user);
                    }
                }
            }
        }

        // Recorded users are only trusted when id AND username still match;
        // an id on its own may have been reused for someone else.
        foreach ($recorded as $known) {
            $matched = false;
            foreach ($all as $user) {
                if ((int) $user['id'] === $known['id'] && (string) $user['username'] === $known['username']) {
                    $found[$known['id']] = $this->userRow($user);
                    $matched = true;
                }
            }
            if ($matched) {
                continue;
            }
            // Wizarr re-imported its users (new ids): the same name on the same
            // server, if exactly one, is the same account there.
            $server = $this->grant === '' ? null : $this->grantServerName();
            $same = array_values(array_filter($all, fn ($user) => strcasecmp((string) $user['username'], $known['username']) === 0
                && ($server === null || (string) ($user['server'] ?? '') === $server)
                && ($known['type'] === '' || strtolower((string) ($user['server_type'] ?? '')) === $known['type'])));
            if (count($same) === 1) {
                $found[(int) $same[0]['id']] = $this->userRow($same[0]);
            }
        }

        return array_values($found);
    }

    /** @return array{id: int, username: string, type: string} */
    private function userRow(array $wizarrUser): array
    {
        return [
            'id' => (int) $wizarrUser['id'],
            'username' => (string) $wizarrUser['username'],
            'type' => strtolower((string) ($wizarrUser['server_type'] ?? '')),
            // Not stored; tells which server a user is on.
            'server' => (string) ($wizarrUser['server'] ?? ''),
        ];
    }

    /**
     * Stored as "id:username:type,..." on the service. Records written by
     * version 1.0.0 have no type; that reads back as "" (unknown).
     *
     * @param  array<int, array{id: int, username: string, type: string}>  $users
     */
    private function rememberUsers(Service $service, array $users): void
    {
        $encoded = implode(',', array_map(
            fn ($user) => $user['id'] . ':' . rawurlencode($user['username']) . ':' . rawurlencode($user['type'] ?? ''),
            $users,
        ));

        $label = $this->grant === '' ? 'Wizarr users' : 'Wizarr users (' . $this->grantServerName() . ')';
        $this->setProperty($service, $this->key(self::PROP_USERS), $label, $encoded === '' ? null : $encoded);
        if ($encoded !== '' && !empty(ExtensionHelper::getServiceProperties($service)[self::PROP_LINK_FAILED])) {
            $this->setProperty($service, self::PROP_LINK_FAILED, '', null);
        }
    }

    /** @return array<int, array{id: int, username: string, type: string}> */
    private function parseUsers(string $encoded): array
    {
        $users = [];
        foreach (array_filter(explode(',', $encoded)) as $entry) {
            [$id, $username, $type] = array_pad(explode(':', $entry, 3), 3, '');
            if (ctype_digit($id) && $username !== '') {
                $users[] = ['id' => (int) $id, 'username' => rawurldecode($username), 'type' => rawurldecode($type)];
            }
        }

        return $users;
    }
}
