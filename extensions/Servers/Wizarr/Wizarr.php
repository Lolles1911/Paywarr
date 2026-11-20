<?php

namespace Paymenter\Extensions\Servers\Wizarr;

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

/**
 * Sells access to a media server through Wizarr.
 *
 * Paymenter decides *when* (paid, overdue, cancelled); this class turns that
 * into Wizarr calls:
 *
 *   first invoice paid   -> createServer    -> single-use invitation, link mailed to the customer
 *   overdue              -> suspendServer   -> user disabled (or unused invitation withdrawn)
 *   paid while suspended -> unsuspendServer -> user enabled again, or a fresh invitation
 *   cancelled / expired  -> terminateServer -> user and invitation deleted
 *
 * The client area also shows the customer's username and lets them reset
 * their media server password without contacting you.
 *
 * Two safety features run alongside:
 *   - a suspension or termination that failed (Wizarr or the media server
 *     was down) is retried every hour until it goes through;
 *   - a product can require membership of your Discord server: customers
 *     connect their Discord account, and checkout refuses non-members.
 *
 * A renewal paid on time calls nothing: Paymenter just moves the due date, and
 * the Wizarr user keeps running. That is why invitations are created without an
 * expiry on the *user* unless the product says otherwise.
 */
#[ExtensionMeta(
    name: 'Wizarr',
    description: 'Invites paying customers to Plex, Jellyfin or Emby through Wizarr, and removes access again when they stop paying.',
    version: '1.23.0',
    author: 'Paywarr',
    url: 'https://github.com/Lolles1911/Paywarr',
)]
class Wizarr extends Server
{
    /** Wizarr only accepts these lifetimes (in days) for an invitation link. */
    private const LINK_LIFETIMES = [1, 7, 30];

    private const PROP_INVITATION_ID = 'wizarr_invitation_id';

    private const PROP_INVITE_CODE = 'wizarr_invite_code';

    private const PROP_INVITE_URL = 'wizarr_invite_url';

    private const PROP_INVITE_EXPIRES = 'wizarr_invite_expires';

    /** How long after activation the banner says "being prepared" before giving up. */
    private const PREPARING_FOR_SECONDS = 900;

    private const PROP_USERS = 'wizarr_users';

    /** Media servers where Wizarr can actually change a password. */
    private const RESETTABLE_SERVERS = ['jellyfin', 'emby', 'silo'];

    /** Set while a suspension/termination is under way; left behind when it fails. */
    private const PROP_PENDING = 'wizarr_pending';

    /** On the Paymenter user: their verified Discord account. */
    private const PROP_DISCORD_ID = 'discord_id';

    private const PROP_DISCORD_NAME = 'discord_username';

    /** Trial products: when the trial ends / ended, and the paid service it became. */
    private const PROP_TRIAL_ENDS = 'trial_ends_at';

    private const PROP_TRIAL_ENDED = 'trial_ended_at';

    private const PROP_TRIAL_CONVERTED = 'trial_converted_to';

    /** A trial user nobody bought for is deleted this long after the trial ended. */
    private const TRIAL_KEEP_SECONDS = 30 * 86400;

    /** The "your trial is over" box shows this long after the end. */
    private const TRIAL_OVER_BANNER_SECONDS = 14 * 86400;

    /** On the service: the Seerr account this extension imported for it. */
    private const PROP_SEERR_USER = 'seerr_user_id';

    /** On the service, while suspended: the Seerr permissions to give back. */
    private const PROP_SEERR_SAVED_PERMISSIONS = 'seerr_saved_permissions';

    /** Which Seerr ("1", "2" or "3") the customer's account is on; missing means the first. */
    private const PROP_SEERR_INSTANCE = 'seerr_instance';

    /** The request limit last set in Seerr ("5/7", "0/" = no limit, "default"), so it is only sent when it changes. */
    private const PROP_SEERR_QUOTA = 'seerr_quota';

    /** Extra requests bought for a subscription: JSON list of {n, until, from (the top-up service)}. */
    private const PROP_EXTRA_REQUESTS = 'extra_requests';

    /** On a top-up service: the subscription it added to, how many, and until when. */
    private const PROP_TOPUP_TARGET = 'topup_target';

    private const PROP_TOPUP_UNTIL = 'topup_until';

    /** On Silo: what its own requests are set to ("off", "default", "10/30", "0/"). */
    private const PROP_SILO_REQUESTS = 'silo_requests';

    /**
     * Paymenter configurable option (its environment variable) that buys
     * requests: Seerr on a Wizarr server, Silo's own on a Silo server.
     */
    public const REQUESTS_OPTION = 'requests';

    /** The same option's name before 1.18.1; still read. */
    public const SEERR_OPTION = 'seerr';

    /**
     * Environment variable of the configurable option where the customer picks
     * the media server(s): values like "jellyfin", "emby" or "jellyfin,emby"
     * (server types, names or Wizarr server IDs). Without it: every server
     * selected on the product.
     */
    public const MEDIA_OPTION = 'media_server';

    /** The servers this subscription has ("1,4"), so a server added to the product later is not handed out for free. */
    private const PROP_SERVERS = 'wizarr_servers';

    /** Per-server label ("Emby"), stored when its invitation is made. */
    private const PROP_SERVER_LABEL = 'wizarr_server';

    /** Per-server "External URL" from Wizarr ("-" when it has none), stored so page views need no call. */
    private const PROP_SERVER_URL = 'wizarr_server_url';

    /** How long the servers' addresses from Wizarr are kept (seconds). */
    private const SERVER_URLS_SECONDS = 600;

    /** Properties that exist once per media server (grant), see key(). */
    private const GRANT_PROPS = ['wizarr_invitation_id', 'wizarr_invite_code', 'wizarr_invite_url', 'wizarr_invite_expires', 'wizarr_users', 'wizarr_server', 'wizarr_server_url'];

    /** How many servers can have their own buttons in the client area. */
    private const MAX_GRANT_BUTTONS = 4;

    /** On the Paymenter user: Discord roles this extension gave them (and may take back). */
    private const PROP_DISCORD_ROLES = 'discord_roles_granted';

    /** On a subscription: when the customer was first seen outside the Discord (a Unix time), and that Paywarr suspended it for that. */
    public const PROP_DISCORD_LEFT = 'discord_left_at';

    public const PROP_DISCORD_SUSPENDED = 'discord_suspended';

    /** Trial days given in admin (Paywarr → Customers): the subscription is a trial of that many days. */
    public const PROP_TRIAL_DAYS = 'trial_days_given';

    /** When the trial started, when set in admin (shown there only). */
    public const PROP_TRIAL_STARTED = 'trial_started_at';

    /** An old trial that no longer counts: the customer may take a trial on that server again. */
    public const PROP_TRIAL_FORGIVEN = 'trial_forgiven';

    /** Set in admin: the customer may get a new invitation link once, even after using theirs. */
    public const PROP_NEW_LINK = 'wizarr_new_link_allowed';

    /** When the customer last asked for a new link themselves ("Problems? Get a new link"). */
    private const PROP_RELINKED = 'wizarr_relinked_at';

    /** How often a customer may ask for a new link of an unused invitation. */
    private const RELINK_SECONDS = 3600;

    /** How long a "member / not member" answer is reused while browsing the shop. */
    private const MEMBERSHIP_CACHE_SECONDS = 600;

    private WizarrClient|SiloClient|null $client = null;

    /* ------------------------------------------------------------------
     | Configuration
     * ----------------------------------------------------------------- */

    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'backend',
                'label' => 'Connects to',
                'type' => 'select',
                'default' => 'wizarr',
                'options' => [
                    ['value' => 'wizarr', 'label' => 'Wizarr (Plex, Jellyfin, Emby)'],
                    ['value' => 'silo', 'label' => 'Silo (directly, without Wizarr)'],
                ],
                'description' => 'Silo has invitations of its own, so Paywarr talks to it directly. One server in Paymenter is one or the other; add a second server for both.',
            ],
            [
                'name' => 'host',
                'label' => 'Wizarr or Silo URL (internal)',
                'type' => 'text',
                'required' => true,
                'placeholder' => 'http://192.168.1.10:5690',
                'description' => 'How Paymenter reaches Wizarr (e.g. port 5690) or Silo (its own port, e.g. 8090; not the Jellyfin-compatible 8096). Never shown to customers.',
            ],
            [
                'name' => 'public_url',
                'label' => 'Wizarr or Silo URL (public)',
                'type' => 'text',
                'required' => true,
                'placeholder' => 'https://join.example.com',
                'description' => 'The address your customers open. Wizarr: it turns Wizarr\'s /j/<code> into a link. Silo: where customers watch (the "Open Silo" button); Silo makes the invitation links itself, from its own public address.',
            ],
            [
                'name' => 'api_key',
                'label' => 'API key',
                'type' => 'password',
                'required' => true,
                'encrypted' => true,
                'description' => 'Wizarr -> Settings -> API Keys. Silo -> Admin -> API Keys, with Full access (a key with only admin:users cannot make invitations).',
            ],
            [
                'name' => 'refund_action',
                'label' => 'When an invoice is refunded in full',
                'type' => 'select',
                'default' => 'suspend',
                'options' => [
                    ['value' => 'suspend', 'label' => 'Suspend the access'],
                    ['value' => 'nothing', 'label' => 'Do nothing'],
                ],
                'description' => 'Refunds made with the Refund button on an invoice in Paymenter. Partial refunds never change anything.',
            ],
            [
                'name' => 'alert_webhook',
                'label' => 'Alerts: Discord webhook',
                'type' => 'password',
                'required' => false,
                'encrypted' => true,
                'description' => 'Optional. Paywarr posts here when something needs you: suspensions that keep failing, Wizarr down, invitations stuck in the queue. Discord: channel settings -> Integrations -> Webhooks.',
            ],
            [
                'name' => 'requests_page_for',
                'label' => 'Requests: who may request',
                'type' => 'select',
                'default' => 'buyers',
                'options' => [
                    ['value' => 'buyers', 'label' => 'Customers who bought requests (the "requests" option, or "Requests included")'],
                    ['value' => 'all', 'label' => 'Every active customer on this server'],
                ],
                'description' => 'Who gets the Requests tab in the client area, and requests in Seerr or Silo. "Every active customer" gives requests with the default limit to all subscriptions here.',
            ],
            [
                'name' => 'more_requests_url',
                'label' => 'Requests: link to buy more',
                'type' => 'text',
                'required' => false,
                'placeholder' => 'https://shop.example.com/products/extras/extra-requests',
                'description' => 'Optional. The page of your extra requests product (see "Extra requests" in the README). Shown on the Requests tab as "Buy more requests".',
            ],
            [
                'name' => 'report_department',
                'label' => 'Problem reports: ticket department',
                'type' => 'text',
                'required' => false,
                'description' => 'Optional. Customers with requests can report a problem with a movie or show; it becomes a support ticket in this department. Empty: the first department.',
            ],
            [
                'name' => 'heartbeat_url',
                'label' => 'Alerts: heartbeat URL',
                'type' => 'text',
                'required' => false,
                'placeholder' => 'https://status.example.com/api/push/abc123',
                'description' => 'Optional. Opened after every hourly run, e.g. an Uptime Kuma push monitor. It warns you when the hourly run stops (scheduler or server down).',
            ],

            // Optional: Seerr as a paid add-on.
            [
                'name' => 'seerr_url',
                'label' => 'Seerr URL (internal)',
                'type' => 'text',
                'required' => false,
                'placeholder' => 'http://192.168.1.10:5055',
                'description' => 'Optional. Only for selling Seerr as an add-on. See the README.',
            ],
            [
                'name' => 'seerr_public_url',
                'label' => 'Seerr URL (public)',
                'type' => 'text',
                'required' => false,
                'placeholder' => 'https://requests.example.com',
                'description' => 'Shown as a button to customers who have the add-on.',
            ],
            [
                'name' => 'seerr_api_key',
                'label' => 'Seerr API key',
                'type' => 'password',
                'required' => false,
                'encrypted' => true,
                'description' => 'Seerr -> Settings -> General -> API Key.',
            ],
            ...$this->extraSeerrFields(),

            // Optional: Discord membership. All five are needed for it to work.
            [
                'name' => 'discord_guild_id',
                'label' => 'Discord server ID',
                'type' => 'text',
                'required' => false,
                'description' => 'Optional. Only needed if a product requires Discord membership. See the README.',
            ],
            [
                'name' => 'discord_invite_url',
                'label' => 'Discord invite link',
                'type' => 'text',
                'required' => false,
                'placeholder' => 'https://discord.gg/...',
                'description' => 'Shown to customers who are not members yet.',
            ],
            [
                'name' => 'discord_gate_shop',
                'label' => 'Only Discord members can see the shop',
                'type' => 'checkbox',
                'description' => 'Categories, products and the cart need a logged-in customer who is a member of your Discord. The front page stays public.',
            ],
            [
                'name' => 'discord_suspend_leavers',
                'label' => 'Suspend customers who leave the Discord',
                'type' => 'checkbox',
                'description' => 'For products with "Requires Discord membership". A customer who has connected Discord and is no longer on the server gets a warning; still gone after the hours below, the subscription is suspended. Back on the server, it opens again by itself (unless an invoice is overdue). Customers who never connected Discord are left alone.',
            ],
            [
                'name' => 'discord_grace_hours',
                'label' => 'Hours to rejoin before the suspension',
                'type' => 'text',
                'default' => '24',
                'placeholder' => '24',
                'description' => 'From the warning. 0 suspends at once. Checked every hour.',
            ],
            [
                'name' => 'discord_client_id',
                'label' => 'Discord application: Client ID',
                'type' => 'text',
                'required' => false,
            ],
            [
                'name' => 'discord_client_secret',
                'label' => 'Discord application: Client Secret',
                'type' => 'password',
                'required' => false,
                'encrypted' => true,
            ],
            [
                'name' => 'discord_bot_token',
                'label' => 'Discord bot token',
                'type' => 'password',
                'required' => false,
                'encrypted' => true,
            ],
        ];
    }

    /** Optional Seerr 2 and 3, each connected to another media server. */
    private function extraSeerrFields(): array
    {
        $fields = [];
        foreach (['2', '3'] as $n) {
            $fields[] = ['name' => 'seerr' . $n . '_url', 'label' => 'Seerr ' . $n . ': URL (internal)', 'type' => 'text', 'required' => false,
                'description' => $n === '2' ? 'Optional. Another Seerr, connected to another media server (e.g. Emby or Plex). Paywarr asks each Seerr which one it uses.' : 'Optional. A third Seerr.'];
            $fields[] = ['name' => 'seerr' . $n . '_public_url', 'label' => 'Seerr ' . $n . ': URL (public)', 'type' => 'text', 'required' => false];
            $fields[] = ['name' => 'seerr' . $n . '_api_key', 'label' => 'Seerr ' . $n . ': API key', 'type' => 'password', 'required' => false, 'encrypted' => true];
        }

        return $fields;
    }

    public function testConfig(): bool|string
    {
        try {
            $client = $this->client();
            $client->status();

            $verified = array_filter($client->servers(), fn ($server) => !empty($server['verified']));
            if (empty($verified)) {
                return 'Connected, but Wizarr has no verified media server yet. Add and verify one in Wizarr first.';
            }

            foreach (['1', '2', '3'] as $n) {
                $name = $n === '1' ? 'Seerr' : 'Seerr ' . $n;
                if (!in_array($n, $this->seerrInstances(), true)) {
                    if (trim((string) $this->config($this->seerrPrefix($n) . 'url')) !== '' || trim((string) $this->config($this->seerrPrefix($n) . 'api_key')) !== '') {
                        return 'Wizarr works, but the ' . $name . ' settings are incomplete: fill in the URL and the API key, or neither.';
                    }
                    continue;
                }
                $seerr = $this->seerr($n);
                $seerr->version();
                $type = $seerr->mediaServerType(); // needs the owner's key
                if ($type === null) {
                    return 'Wizarr works, but ' . $name . ' is not connected to a media server yet.';
                }
                $type === 'plex' ? $seerr->plexUsersToImport() : $seerr->mediaServerUsers();
            }

            if ($this->discordConfigured()) {
                // The bot looks itself up in your server: proves the token, the
                // server ID and that the bot was invited, without a real member.
                $discord = $this->discord();
                if (!$discord->isMember((string) $this->config('discord_guild_id'), $discord->botUserId())) {
                    return 'Wizarr works, but the Discord bot is not in your server. Invite it (see the README).';
                }
            } elseif ($this->anyDiscordSetting()) {
                return 'Wizarr works, but the Discord settings are incomplete: fill in all of them, or none.';
            }

            return true;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    public function getProductConfig($values = []): array
    {
        // The product page must keep working when Wizarr is down, so fall
        // back to plain text fields instead of letting the exception escape.
        $servers = [];
        $libraries = [];
        $reachable = true;

        try {
            foreach ($this->client()->servers() as $server) {
                if (!empty($server['verified'])) {
                    $servers[] = [
                        'value' => (string) $server['id'],
                        'label' => $server['name'] . ' (' . ($server['server_type'] ?? '?') . ')',
                    ];
                }
            }
            foreach ($this->client()->libraries() as $library) {
                $libraries[] = [
                    'value' => (string) $library['id'],
                    'label' => ($library['server_name'] ?? '?') . ': ' . $library['name'],
                ];
            }
        } catch (Throwable $e) {
            $reachable = false;
        }

        $serverField = $reachable && $servers
            ? ['type' => 'select', 'multiple' => true, 'database_type' => 'array', 'options' => $servers]
            : ['type' => 'text', 'placeholder' => '1', 'hint' => 'Wizarr could not be reached - type the server ID(s), comma separated.'];

        $libraryField = $reachable && $libraries
            ? ['type' => 'select', 'multiple' => true, 'database_type' => 'array', 'options' => $libraries]
            : ['type' => 'text', 'placeholder' => '2,3'];

        return [
            [
                'name' => 'server_ids',
                'label' => 'Media server',
                'required' => true,
                'description' => 'Which server(s) this product gives access to; each gets its own invitation. '
                    . 'To let the customer choose, add a configurable option with environment variable "media_server" (see the README).',
            ] + $serverField,
            [
                'name' => 'library_ids',
                'label' => 'Libraries',
                'required' => false,
                'description' => 'Leave empty for the default libraries of the server.',
            ] + $libraryField,
            [
                'name' => 'link_lifetime',
                'label' => 'Invitation link is valid for',
                'type' => 'select',
                'required' => true,
                'default' => '7',
                'options' => [
                    ['value' => '1', 'label' => '1 day'],
                    ['value' => '7', 'label' => '7 days'],
                    ['value' => '30', 'label' => '30 days'],
                    ['value' => 'never', 'label' => 'Until used'],
                ],
                'description' => 'How long the customer has to create their user. An expired link can be renewed from the client area.',
            ],
            [
                'name' => 'access_days',
                'label' => 'Access expires after (days)',
                'type' => 'number',
                'required' => false,
                'validation' => 'nullable|integer|min:1',
                'description' => 'LEAVE EMPTY for subscriptions: Paymenter suspends and terminates on its own. '
                    . 'Only fill in for one-time products, where nothing else would ever end the access.',
            ],
            [
                'name' => 'allow_downloads',
                'label' => 'Allow downloads',
                'type' => 'checkbox',
            ],
            [
                'name' => 'allow_live_tv',
                'label' => 'Allow live TV',
                'type' => 'checkbox',
            ],
            [
                'name' => 'login_url',
                'label' => 'Where customers watch',
                'type' => 'text',
                'required' => false,
                'placeholder' => 'https://jellyfin.example.com',
                'description' => 'Optional. Shown as a button in the client area once the user exists. With several media servers, '
                    . 'each server\'s "External URL" in Wizarr is used instead.',
            ],
            [
                'name' => 'trial_days',
                'label' => 'Trial: days',
                'type' => 'number',
                'required' => false,
                'validation' => 'nullable|integer|min:1|max:365',
                'description' => 'Makes this a free trial product. The trial starts when the customer has created their user. '
                    . 'Only for new customers. See the README. Leave empty for normal products.',
            ],
            [
                'name' => 'extra_requests',
                'label' => 'Extra requests (top-up product)',
                'type' => 'number',
                'required' => false,
                'validation' => 'nullable|integer|min:1|max:1000',
                'description' => 'Only for a top-up product: how many extra requests one of it gives (times the quantity bought). It adds to the customer\'s request package for the package\'s period (e.g. 30 days), then expires. No invitation is sent. Leave empty for normal products.',
            ],
            [
                'name' => 'seerr_included',
                'label' => 'Requests included',
                'type' => 'checkbox',
                'description' => 'Every subscription on this product can request movies and shows: in Seerr on a Wizarr server, in Silo itself on a Silo server. '
                    . 'Leave off to sell requests as an add-on instead (a configurable option with environment variable "requests", see the README).',
            ],
            $this->discordRoleField(),
            [
                'name' => 'require_discord',
                'label' => 'Require Discord membership',
                'type' => 'checkbox',
                'description' => 'Only members of your Discord server can buy this product. Needs the Discord settings on the server.',
            ],
        ];
    }

    /* ------------------------------------------------------------------
     | Boot: routes, checkout guard, hourly retry
     * ----------------------------------------------------------------- */

    /**
     * Paymenter calls this on every request and console run, with the first
     * Wizarr server's settings. Only registers things; never calls out.
     */
    public function boot()
    {
        require __DIR__ . '/routes.php';
        View::addNamespace('wizarr', __DIR__ . '/resources/views');

        // Checkout creates the services inside a database transaction; an
        // exception here rolls the whole order back and Paymenter shows a
        // DisplayException's message to the customer.
        Event::listen(ServiceCreated::class, function (ServiceCreated $event) {
            $this->guardCheckout($event->service);
        });

        // A refund with Paymenter's Refund button. Once, however many Wizarr
        // servers there are: each service is handled with its own server's settings.
        if (!self::$refundListener) {
            self::$refundListener = true;
            Event::listen(TransactionUpdated::class, function (TransactionUpdated $event) {
                $this->afterRefund($event->invoiceTransaction);
            });
        }

        // Requests (Seerr) only with a media server Seerr knows: checked when
        // the order's or upgrade's invoice is made, so nothing is charged.
        if (!self::$seerrGuardListener) {
            self::$seerrGuardListener = true;
            Event::listen(InvoiceItemCreating::class, fn (InvoiceItemCreating $event) => $this->guardSeerrForItem($event->invoiceItem));
            Event::listen(ServiceUpgradeUpdated::class, fn (ServiceUpgradeUpdated $event) => $this->guardSeerrForUpgrade($event->serviceUpgrade));
        }

        // "Your access is ready" banner on every page of the client area.
        Event::listen('footer', fn () => $this->renderBanner());

        // Deleting a customer in Paymenter removes their services in the
        // database only, without any hook. Remove their access first.
        User::deleting(fn (User $user) => $this->beforeUserDeleted($user));

        if ($this->discordConfigured() && $this->truthy($this->config('discord_gate_shop'))) {
            ShopGate::$extension = $this;
            app('router')->pushMiddlewareToGroup('web', ShopGate::class);
            // Lets a theme hide the product list on the front page (see "For theme authors" in the README).
            app()->bind('wizarr.shopLocked', fn () => $this->shopAccessForCurrentUser() !== 'allow');
        }

        // "Requests", for customers with requests.
        Event::listen('navigation.dashboard', fn () => [
            'name' => self::t('Requests'),
            'route' => 'extensions.wizarr.requests',
            'icon' => 'ri-movie-2',
            'priority' => 29,
            'condition' => Auth::check() && Cache::remember('wizarr:can-request:' . Auth::id(), 300, fn () => $this->librarySources(Auth::user(), null, true) !== []),
        ]);

        // "Report a problem", for every customer with a subscription to report from.
        Event::listen('navigation.dashboard', fn () => [
            'name' => self::t('Report a problem'),
            'route' => 'extensions.wizarr.report',
            'icon' => 'ri-error-warning',
            'priority' => 30,
            'condition' => Auth::check() && Cache::remember('wizarr:can-report:' . Auth::id(), 300, fn () => $this->reportSources(Auth::user()) !== []),
        ]);

        // The same in the top bar, plus a link to where the customer watches.
        Event::listen('navigation', function () {
            if (!Auth::check()) {
                return [];
            }
            $items = [];
            if (Cache::remember('wizarr:can-request:' . Auth::id(), 300, fn () => $this->librarySources(Auth::user(), null, true) !== [])) {
                $items[] = ['name' => self::t('Requests'), 'url' => route('extensions.wizarr.requests'), 'icon' => 'ri-movie-2', 'priority' => 10];
            }
            if (Cache::remember('wizarr:can-report:' . Auth::id(), 300, fn () => $this->reportSources(Auth::user()) !== [])) {
                $items[] = ['name' => self::t('Report a problem'), 'url' => route('extensions.wizarr.report'), 'icon' => 'ri-error-warning', 'priority' => 12];
            }
            if ($watch = $this->navWatchLink(Auth::user())) {
                $items[] = ['name' => $watch['label'], 'url' => $watch['url'], 'spa' => false, 'icon' => 'ri-play-circle', 'priority' => 13];
            }

            return $items;
        });

        if ($this->discordConfigured()) {
            // Under the login form, and under the sign-up form (themes that offer the
            // "auth.register" hook; Paymenter's default theme does not).
            Event::listen('auth.login', fn () => ['view' => $this->discordLoginButton(label: self::t('Log in or create an account with Discord'))]);
            Event::listen('auth.register', fn () => ['view' => $this->discordLoginButton(label: self::t('Create an account with Discord'))]);

            Event::listen('navigation.account', fn () => [
                'name' => 'Discord',
                'route' => 'extensions.wizarr.discord',
                'priority' => 20,
            ]);
        }

        // callAfterResolving() only exists on service providers, not on the
        // application, so do what it does: hook the scheduler when it is
        // created, or right away if it already exists.
        $register = function (Schedule $schedule) {
            $schedule->call(function () {
                $retry = $this->retryPending();
                $this->reconcileDiscordRoles();
                $this->reconcileDiscordMembership();
                $this->reconcileSeerr();
                $this->processTrials();
                $this->reportHealth($retry);
            })
                ->hourly()
                ->name('wizarr-retry-pending')
                ->withoutOverlapping();
        };
        app()->afterResolving(Schedule::class, $register);
        if (app()->resolved(Schedule::class)) {
            $register(app()->make(Schedule::class));
        }
    }

    /* ------------------------------------------------------------------
     | Lifecycle hooks called by Paymenter
     * ----------------------------------------------------------------- */

    /**
     * Called once the first invoice is paid. The returned array is handed to
     * the "Service activated" mail, so the product's e-mail template can use
     * {{ $invite_url }}, {{ $invite_code }} and {{ $invite_valid_for }}.
     */
    public function createServer(Service $service, $settings, $properties)
    {
        if ($this->isTopUp($settings)) {
            $n = $this->applyTopUp($service, $settings);

            return ['invite_url' => '', 'invite_code' => '', 'invite_valid_for' => '', 'login_url' => '', 'extra_requests' => $n];
        }
        // Bought after a trial: keep the same media server user(s) instead of
        // inviting them again, so their history and "continue watching" stay.
        if (!$this->hasGrants($properties) && !$this->isTrial($settings) && $this->adoptTrialUserQuietly($service)) {
            $properties = ExtensionHelper::getServiceProperties($service);
        }

        // One invitation per chosen server. Run twice (a retried job), it hands
        // back what exists instead of minting a second seat for one payment.
        $invitations = $this->syncGrants($service, $settings, $properties);
        $this->syncRolesQuietly($service, active: true);
        // Usually nothing yet: Seerr follows once the customer has created their user.
        $this->syncSeerrQuietly($service, 'active');

        return $this->mailDataFor($invitations, $settings);
    }

    /**
     * The "Service activated" mail's data. {{ $invite_url }} is the first
     * server's link (all a single-server product needs); {{ $invites }} lists
     * every server's link, each with ['server', 'url', 'code'].
     *
     * @param  array<string, array>  $invitations  per grant, from syncGrants()
     */
    private function mailDataFor(array $invitations, array $settings): array
    {
        $invites = [];
        $first = null;
        foreach ($invitations as $grant => $invitation) {
            $grantSettings = ['login_url' => $this->withGrant((string) $grant, fn () => $this->watchUrl($settings))] + $settings;
            $data = ($invitation['code'] ?? '') === ''
                ? $this->accessMailData($grantSettings)
                : $this->mailData($invitation, $grantSettings, alreadyUsed: !empty($invitation['has_user']));
            $first ??= $data;
            $server = $grant === '' ? '' : $this->withGrant((string) $grant, fn () => $this->grantServerName());
            $invites[] = ['server' => $server, 'url' => $data['invite_url'], 'code' => $data['invite_code']];
        }
        $first ??= $this->accessMailData($settings);

        return $first + [
            'invites' => $invites,
            'invite_urls' => implode("\n", array_map(fn ($i) => ($i['server'] !== '' ? $i['server'] . ': ' : '') . $i['url'], $invites)),
        ];
    }

    /**
     * Called when an invoice is overdue. Access is paused, not destroyed, where
     * the media server can do that (Jellyfin, Emby). Plex has no "disabled"
     * state: Wizarr removes the share, and unsuspend has to invite again.
     */
    public function suspendServer(Service $service, $settings, $properties)
    {
        if ($this->isTopUp($settings)) {
            $this->withdrawTopUp($service); // e.g. refunded

            return true;
        }
        // Left in place if anything below throws, so retryPending() tries again.
        $this->setProperty($service, self::PROP_PENDING, 'Wizarr: unfinished action', 'suspend');
        $result = $this->suspendNow($service, $settings, $properties);
        $this->setProperty($service, self::PROP_PENDING, '', null);
        $this->syncRolesQuietly($service, active: false);
        $this->syncSeerrQuietly($service, 'suspended');

        return $result;
    }

    private function suspendNow(Service $service, array $settings, array $properties): array
    {
        // An unused link is withdrawn below; remember its server, so paying
        // again brings back that server and no other.
        if (trim((string) ($properties[self::PROP_SERVERS] ?? '')) === '' && $this->hasGrants($properties)) {
            $this->setProperty($service, self::PROP_SERVERS, 'Wizarr media servers', implode(',', array_keys($this->coveredServers($settings, $properties))));
        }

        foreach ($this->grantKeys($properties) as $grant) {
            $this->withGrant($grant, fn () => $this->suspendGrant($service, $properties));
        }

        return [];
    }

    private function suspendGrant(Service $service, array $properties): void
    {
        $invitation = $this->findInvitation($properties);

        // Never redeemed: withdraw the link so it cannot be used while suspended.
        if ($invitation && ($invitation['status'] ?? '') !== 'used') {
            $this->deleteInvitationQuietly((int) $invitation['id']);
            $this->forgetInvitation($service);

            return;
        }

        $remaining = [];
        foreach ($this->resolveUsers($properties, $invitation) as $user) {
            $message = $this->client()->disableUser($user['id']);

            // Wizarr deletes the user when the media server cannot disable it.
            if (stripos($message, 'deleted') === false) {
                $remaining[] = $user;
            }
        }
        $this->rememberUsers($service, $remaining);
    }

    /**
     * Called when a suspended service is paid. If the user can simply be
     * switched back on, that is all. Otherwise the customer gets a new
     * invitation - Paymenter sends no mail on unsuspend, so we do.
     */
    public function unsuspendServer(Service $service, $settings, $properties)
    {
        if ($this->isTopUp($settings)) {
            $this->applyTopUp($service, $settings);

            return true;
        }
        // Paid again: an unfinished suspension must not be retried any more.
        $this->setProperty($service, self::PROP_PENDING, '', null);
        $this->syncRolesQuietly($service, active: true);

        $result = $this->unsuspendNow($service, $settings, $properties);
        $this->syncSeerrQuietly($service, 'active');

        return $result;
    }

    private function unsuspendNow(Service $service, array $settings, array $properties): bool
    {
        $before = $this->grantKeys($properties);
        foreach ($before as $grant) {
            $this->withGrant($grant, fn () => $this->unsuspendGrant($service, $settings, $properties));
        }

        // A server whose unused link was withdrawn at suspension (or that never
        // got one) gets a new invitation now.
        $after = ExtensionHelper::getServiceProperties($service);
        foreach ($this->syncGrants($service, $settings, $after) as $grant => $invitation) {
            if (!in_array((string) $grant, $before, true) && ($invitation['code'] ?? '') !== '' && empty($invitation['has_user'])) {
                $this->notifyNewInvitation($service, $this->mailDataFor([$grant => $invitation], $settings));
            }
        }

        return true;
    }

    private function unsuspendGrant(Service $service, array $settings, array $properties): void
    {
        $invitation = $this->findInvitation($properties);

        if ($invitation && ($invitation['status'] ?? '') === 'pending') {
            return; // an unused, still valid link is all the customer needs
        }

        $enabled = [];
        foreach ($this->resolveUsers($properties, $invitation) as $user) {
            $message = $this->client()->enableUser($user['id']);

            if (stripos($message, 'enabled successfully') !== false) {
                $enabled[] = $user;

                continue;
            }

            // Cannot be re-enabled (Plex). Drop the stale record so the new
            // invitation does not collide with it.
            $this->deleteUserQuietly($user['id']);
        }
        $this->rememberUsers($service, $enabled);

        if ($enabled) {
            return;
        }

        if ($invitation) {
            $this->deleteInvitationQuietly((int) $invitation['id']);
        }
        $fresh = $this->issueInvitation($service, $settings);
        $this->notifyNewInvitation($service, $this->mailDataFor([$this->grant => $fresh], $settings));
    }

    /**
     * Called on cancellation, or when a suspended service stays unpaid.
     */
    public function terminateServer(Service $service, $settings, $properties)
    {
        if ($this->isTopUp($settings)) {
            $this->withdrawTopUp($service);

            return true;
        }
        $this->setProperty($service, self::PROP_PENDING, 'Wizarr: unfinished action', 'terminate');
        $result = $this->terminateNow($service, $properties);
        $this->setProperty($service, self::PROP_SERVERS, '', null);
        $this->setProperty($service, self::PROP_PENDING, '', null);
        $this->syncRolesQuietly($service, active: false);
        $this->syncSeerrQuietly($service, 'ended');

        return $result;
    }

    /**
     * Called after a paid upgrade or downgrade: another product or other
     * options (the media servers, the Seerr add-on). The media servers, the
     * Discord role and Seerr follow the new choice.
     */
    public function upgradeServer(Service $service, $settings, $properties)
    {
        // Media servers added get an invitation (mailed); removed ones lose their user.
        $before = $this->grantKeys($properties);
        foreach ($this->syncGrants($service, $settings, $properties) as $grant => $invitation) {
            if (!in_array((string) $grant, $before, true) && ($invitation['code'] ?? '') !== '' && empty($invitation['has_user'])) {
                $this->notifyNewInvitation($service, $this->mailDataFor([$grant => $invitation], $settings));
            }
        }
        $this->syncRolesQuietly($service, active: true);
        $this->syncSeerrQuietly($service, 'active');

        return true;
    }

    private function terminateNow(Service $service, array $properties): array
    {
        // Inside syncGrants() this ends one grant; otherwise all of them.
        $grants = $this->grant !== '' ? [$this->grant] : ($this->grantKeys($properties) ?: ['']);
        foreach ($grants as $grant) {
            $this->withGrant($grant, fn () => $this->terminateGrant($service, $properties));
        }

        return [];
    }

    private function terminateGrant(Service $service, array $properties): void
    {
        $invitation = $this->findInvitation($properties);

        foreach ($this->resolveUsers($properties, $invitation) as $user) {
            $this->deleteUserQuietly($user['id']);
        }

        if ($invitation) {
            $this->deleteInvitationQuietly((int) $invitation['id']);
        }

        $this->forgetInvitation($service);
        $this->rememberUsers($service, []);
        if ($this->grant !== '') {
            $this->setProperty($service, $this->key(self::PROP_SERVER_LABEL), '', null);
            $this->setProperty($service, $this->key(self::PROP_SERVER_URL), '', null);
        }
    }

    /* ------------------------------------------------------------------
     | Client area
     * ----------------------------------------------------------------- */

    /**
     * Paymenter only calls this for active services, on every page view.
     * Wizarr is asked only until the invitation has been redeemed; after that
     * nothing here leaves the box.
     */
    public function getActions(Service $service, $settings, $properties): array
    {
        if ($this->isTopUp($settings)) {
            return $this->topUpActions($properties);
        }
        $grants = $this->grantKeys($properties);
        if (count($grants) > 1) {
            return $this->actionsForGrants($service, $settings, $properties, $grants);
        }

        $url = $this->withGrant($grants[0] ?? '', fn () => $this->watchUrl($settings, $properties, $service));
        $watch = $url === '' ? [] : [[
            'type' => 'button',
            'label' => self::t('Open the media library'),
            'url' => $url,
            'target' => '_blank',
        ]];

        return $this->withGrant($grants[0] ?? '', fn () => $this->actionsForGrant($service, $settings, $this->grantView($properties), $watch));
    }

    /** The client area for a subscription with one media server (one invitation). */
    private function actionsForGrant(Service $service, array $settings, array $properties, array $watch): array
    {
        if (!empty($properties[self::PROP_USERS])) {
            // A trial whose user was recorded before its clock existed (or before
            // the hourly run): start the clock now, so the end date shows at once.
            if ($this->isTrial($this->trialSettings($settings, $properties)) && empty($properties[self::PROP_TRIAL_ENDS])) {
                $this->processTrialQuietly($service);
                $properties = $this->grantView(ExtensionHelper::getServiceProperties($service));
            }

            return array_merge($this->activeActions($this->parseUsers((string) $properties[self::PROP_USERS]), $watch, $properties), $this->grantedLinkButton($properties, 0));
        }

        $storedUrl = (string) ($properties[self::PROP_INVITE_URL] ?? '');

        try {
            $invitation = $this->findInvitation($properties);
        } catch (Throwable $e) {
            // Wizarr is down: still show the link we already have.
            return $storedUrl === '' ? [] : [
                $this->statusField(self::t('Waiting for you to create your user')),
                $this->inviteButton($storedUrl),
            ];
        }

        $status = $invitation['status'] ?? 'missing';

        if ($status === 'used') {
            $users = [];
            try {
                $users = $this->resolveUsers($properties, $invitation);
                $this->rememberUsers($service, $users);
                $this->syncSeerrQuietly($service, 'active'); // user just created: Seerr can follow now
                $this->processTrialQuietly($service);        // ... and a trial's clock can start
            } catch (Throwable $e) {
                return $this->activeActions([], $watch, $properties); // can't tell: as before
            }
            if (!$users) {
                // The link was used but no user was made (e.g. a Plex share that failed).
                return [$this->statusField(self::t('Your user was not created')), ...$this->freshLinkButton($properties, 0)];
            }

            return array_merge($this->activeActions($users, $watch, $properties), $this->grantedLinkButton($properties, 0));
        }

        if ($status === 'pending') {
            return [
                $this->statusField(self::t('Waiting for you to create your user')),
                $this->inviteButton($this->absoluteUrl($invitation['url'] ?? null, (string) $invitation['code'])),
                ...$this->freshLinkButton($properties, 0),
            ];
        }

        // Expired before it was used, or removed in Wizarr.
        return [
            $this->statusField(self::t('Your invitation link has expired')),
            ['type' => 'button', 'label' => self::t('Get a new invitation link'), 'function' => 'renewInvitation'],
        ];
    }

    /**
     * The client area for a subscription with several media servers: what
     * each server's invitation needs, then the usual "active" part for the
     * users that exist. Buttons are numbered by grant (renewInvitation2 ...).
     */
    private function actionsForGrants(Service $service, array $settings, array $properties, array $grants): array
    {
        $users = [];
        $watch = [];
        $pending = [];
        $resets = [];
        foreach (array_slice($grants, 0, self::MAX_GRANT_BUTTONS) as $index => $grant) {
            $number = $index + 1;
            [$grantUsers, $entries] = $this->withGrant($grant, function () use ($service, $properties, $number) {
                $view = $this->grantView($properties);
                $server = $this->grantLabel($properties);
                if (!empty($view[self::PROP_USERS])) {
                    return [$this->parseUsers((string) $view[self::PROP_USERS]), $this->grantedLinkButton($view, $number, $server)];
                }
                try {
                    $invitation = $this->findInvitation($view);
                } catch (Throwable $e) {
                    $url = (string) ($view[self::PROP_INVITE_URL] ?? '');

                    return [[], $url === '' ? [] : [$this->statusField(self::t('Waiting for you to create your user'), $server), $this->inviteButton($url, $server)]];
                }
                $status = $invitation['status'] ?? 'missing';
                if ($status === 'used') {
                    $found = [];
                    try {
                        $found = $this->resolveUsers($view, $invitation);
                        $this->rememberUsers($service, $found);
                        $this->syncSeerrQuietly($service, 'active');
                        $this->processTrialQuietly($service);
                    } catch (Throwable $e) {
                        return [[], []]; // can't tell: show nothing for this server
                    }
                    if (!$found) {
                        return [[], [$this->statusField(self::t('Your user was not created'), $server), ...$this->freshLinkButton($view, $number, $server)]];
                    }

                    return [$found, $this->grantedLinkButton($view, $number, $server)];
                }
                if ($status === 'pending') {
                    return [[], [
                        $this->statusField(self::t('Waiting for you to create your user'), $server),
                        $this->inviteButton($this->absoluteUrl($invitation['url'] ?? null, (string) $invitation['code']), $server),
                        ...$this->freshLinkButton($view, $number, $server),
                    ]];
                }

                return [[], [
                    $this->statusField(self::t('Your invitation link has expired'), $server),
                    ['type' => 'button', 'label' => self::t('Get a new invitation link for :server', ['server' => $server]), 'function' => 'renewInvitation' . $number],
                ]];
            });
            $users = array_merge($users, $grantUsers);
            $pending = array_merge($pending, $entries);
            // "Open Emby" once the user there exists.
            if ($grantUsers) {
                [$url, $label] = $this->withGrant($grant, fn () => [$this->watchUrl($settings, $properties, $service), $this->grantLabel($properties)]);
                if ($url !== '') {
                    $watch[] = ['type' => 'button', 'label' => $label !== '' ? self::t('Open :server', ['server' => $label]) : self::t('Open the media library'), 'url' => $url, 'target' => '_blank'];
                }
            }
            foreach ($grantUsers as $user) {
                if ($user['type'] === '' || in_array($user['type'], self::RESETTABLE_SERVERS, true)) {
                    $label = $this->withGrant($grant, fn () => $this->grantLabel($properties));
                    $resets[] = ['type' => 'button', 'label' => self::t('Reset password on :server', ['server' => $label]), 'function' => 'resetPassword' . $number];

                    break;
                }
            }
        }

        if (!$users) {
            return $pending;
        }
        $properties = ExtensionHelper::getServiceProperties($service);
        $active = $this->activeActions($users, $watch, $properties, $resets);

        // "Active" first, then what the other servers still need.
        return array_merge(array_slice($active, 0, 1), $pending, array_slice($active, 1));
    }

    /** The grant behind button number $number, or null when it is gone. */
    private function grantNumber(array $properties, int $number): ?string
    {
        return $this->grantKeys($properties)[$number - 1] ?? null;
    }

    public function renewInvitation1(Service $service, $settings, $properties): string
    {
        return $this->renewInvitationFor($service, $settings, $properties, 1);
    }

    public function renewInvitation2(Service $service, $settings, $properties): string
    {
        return $this->renewInvitationFor($service, $settings, $properties, 2);
    }

    public function renewInvitation3(Service $service, $settings, $properties): string
    {
        return $this->renewInvitationFor($service, $settings, $properties, 3);
    }

    public function renewInvitation4(Service $service, $settings, $properties): string
    {
        return $this->renewInvitationFor($service, $settings, $properties, 4);
    }

    public function resetPassword1(Service $service, $settings, $properties): string
    {
        return $this->resetPasswordFor($service, $properties, 1);
    }

    public function resetPassword2(Service $service, $settings, $properties): string
    {
        return $this->resetPasswordFor($service, $properties, 2);
    }

    public function resetPassword3(Service $service, $settings, $properties): string
    {
        return $this->resetPasswordFor($service, $properties, 3);
    }

    public function resetPassword4(Service $service, $settings, $properties): string
    {
        return $this->resetPasswordFor($service, $properties, 4);
    }

    private function renewInvitationFor(Service $service, array $settings, array $properties, int $number): string
    {
        $grant = $this->grantNumber($properties, $number);
        if ($grant === null) {
            throw new DisplayException(self::t('Please reload the page and try again.'));
        }

        return $this->withGrant($grant, fn () => $this->renewGrant($service, $settings, $properties));
    }

    private function resetPasswordFor(Service $service, array $properties, int $number): string
    {
        $grant = $this->grantNumber($properties, $number);
        if ($grant === null) {
            throw new DisplayException(self::t('Please reload the page and try again.'));
        }

        return $this->withGrant($grant, fn () => $this->resetGrantPassword($service, $properties));
    }

    /**
     * Button handler: Paymenter redirects the customer to whatever URL this returns.
     */
    public function renewInvitation(Service $service, $settings, $properties): string
    {
        $grants = $this->grantKeys($properties);
        if (!$grants) {
            // Nothing made yet (the queue is behind): make it now.
            $invitations = $this->syncGrants($service, $settings, $properties);

            return $this->mailDataFor($invitations, $settings)['invite_url'];
        }

        return $this->withGrant($grants[0], fn () => $this->renewGrant($service, $settings, $properties));
    }

    /**
     * "Problems? Get a new link" by an unused invitation: a customer whose
     * account creation failed (a Plex share, a typo) can start over without
     * writing to you. At most once an hour.
     */
    private function freshLinkButton(array $properties, int $number, string $server = ''): array
    {
        if (!empty($properties[self::PROP_NEW_LINK])) {
            return $this->grantedLinkButton($properties, $number, $server);
        }
        if (time() - (int) ($properties[self::PROP_RELINKED] ?? 0) < self::RELINK_SECONDS) {
            return [];
        }

        return [['type' => 'button', 'function' => 'freshInvitation' . ($number ?: ''),
            'label' => $server !== '' ? self::t('Problems? Get a new link for :server', ['server' => $server]) : self::t('Problems? Get a new link')]];
    }

    /** "Get a new invitation link" after the invitation was used, when allowed in admin. */
    private function grantedLinkButton(array $properties, int $number, string $server = ''): array
    {
        if (empty($properties[self::PROP_NEW_LINK])) {
            return [];
        }

        return [['type' => 'button', 'function' => 'freshInvitation' . ($number ?: ''),
            'label' => $server !== '' ? self::t('Get a new invitation link for :server', ['server' => $server]) : self::t('Get a new invitation link')]];
    }

    public function freshInvitation(Service $service, $settings, $properties): string
    {
        return $this->freshInvitationFor($service, $settings, $properties, 0);
    }

    public function freshInvitation1(Service $service, $settings, $properties): string
    {
        return $this->freshInvitationFor($service, $settings, $properties, 1);
    }

    public function freshInvitation2(Service $service, $settings, $properties): string
    {
        return $this->freshInvitationFor($service, $settings, $properties, 2);
    }

    public function freshInvitation3(Service $service, $settings, $properties): string
    {
        return $this->freshInvitationFor($service, $settings, $properties, 3);
    }

    public function freshInvitation4(Service $service, $settings, $properties): string
    {
        return $this->freshInvitationFor($service, $settings, $properties, 4);
    }

    /**
     * Button handler: a new link instead of an unused one (once an hour), or,
     * when allowed in admin, instead of a used one: the customer starts over.
     */
    private function freshInvitationFor(Service $service, array $settings, array $properties, int $number): string
    {
        $grant = $number === 0 ? ($this->grantKeys($properties)[0] ?? null) : $this->grantNumber($properties, $number);
        if ($grant === null) {
            return $this->renewInvitation($service, $settings, $properties);
        }
        $allowed = !empty($properties[self::PROP_NEW_LINK]);
        if (!$allowed && time() - (int) ($properties[self::PROP_RELINKED] ?? 0) < self::RELINK_SECONDS) {
            throw new DisplayException(self::t('You can get a new link once an hour. Try again later, or write to us.'));
        }

        return $this->withGrant($grant, function () use ($service, $settings, $properties, $allowed) {
            $view = $this->grantView($properties);
            $invitation = $this->findInvitation($view);
            $status = $invitation['status'] ?? 'missing';
            // Used, but no user was made (a failed Plex share): starting over takes no second seat.
            $failed = $status === 'used' && empty($view[self::PROP_USERS]) && !$this->resolveUsers($view, $invitation);
            if (!$allowed && (($status !== 'pending' && !$failed) || !empty($view[self::PROP_USERS]))) {
                return $this->renewGrant($service, $settings, $properties); // used: the login page; gone: a new one, as always
            }
            if ($invitation) {
                $this->deleteInvitationQuietly((int) $invitation['id']);
            }
            $fresh = $this->issueInvitation($service, $settings); // forgets the user(s) of this server too
            $this->setProperty($service, self::PROP_RELINKED, 'Wizarr: customer asked for a new link', (string) time());
            if ($allowed) {
                $this->setProperty($service, self::PROP_NEW_LINK, '', null);
            }
            Log::info('Wizarr: service ' . $service->id . ' got a new invitation link' . ($allowed ? ' (allowed in admin)' : ' (asked by the customer)') . '.');

            return (string) $fresh['url'];
        });
    }

    private function renewGrant(Service $service, array $settings, array $properties): string
    {
        $properties = $this->grantView($properties);
        $invitation = $this->findInvitation($properties);
        $status = $invitation['status'] ?? 'missing';

        if ($status === 'pending') {
            return $this->absoluteUrl($invitation['url'] ?? null, (string) $invitation['code']);
        }

        // Already has a user: a new link would be a second seat on one subscription.
        if ($status === 'used' || !empty($properties[self::PROP_USERS])) {
            $loginUrl = $this->watchUrl($settings, $properties, $service);

            return $loginUrl !== '' ? $loginUrl : rtrim((string) $this->config('public_url'), '/');
        }

        if ($invitation) {
            $this->deleteInvitationQuietly((int) $invitation['id']);
        }

        // issueInvitation() returns the full link (Wizarr's made absolute, or Silo's own).
        return (string) $this->issueInvitation($service, $settings)['url'];
    }

    /**
     * Button handler: sends the customer to Wizarr's own page where they pick
     * a new password, which Wizarr then sets on Jellyfin/Emby.
     *
     * Paymenter only lets this run for an active service and only when the
     * button was on the page, so a suspended customer cannot use it to get
     * back in. A DisplayException is shown to the customer as a notice.
     */
    public function resetPassword(Service $service, $settings, $properties): string
    {
        // The first server where a password can be reset.
        foreach ($this->grantKeys($properties) as $grant) {
            $types = array_column($this->withGrant($grant, fn () => $this->parseUsers((string) ($this->grantView($properties)[self::PROP_USERS] ?? ''))), 'type');
            if (!$types || array_intersect($types, array_merge(self::RESETTABLE_SERVERS, ['']))) {
                return $this->withGrant($grant, fn () => $this->resetGrantPassword($service, $properties));
            }
        }

        return $this->resetGrantPassword($service, $properties);
    }

    private function resetGrantPassword(Service $service, array $properties): string
    {
        try {
            $users = $this->resolveUsers($properties, $this->findInvitation($properties));
        } catch (Throwable $e) {
            Log::warning('Wizarr: password reset for service ' . $service->id . ' failed: ' . $e->getMessage());

            throw new DisplayException(self::t('We can\'t reach the media server right now. Please try again shortly.'));
        }

        $user = null;
        foreach ($users as $candidate) {
            if (in_array($candidate['type'], self::RESETTABLE_SERVERS, true)) {
                $user = $candidate;

                break;
            }
        }

        if ($user === null) {
            throw new DisplayException(self::t('The password can\'t be reset from here. Contact us and we\'ll help.'));
        }

        try {
            $reset = $this->client()->createPasswordReset($user['id']);
        } catch (Throwable $e) {
            Log::warning('Wizarr: password reset for service ' . $service->id . ' failed: ' . $e->getMessage());

            throw new DisplayException(self::t('We couldn\'t create a reset link right now. Please try again shortly.'));
        }

        return $this->absoluteUrl($reset['url'] ?? '/reset/' . $reset['code'], (string) $reset['code']);
    }

    /* ------------------------------------------------------------------
     | "Your access is ready" banner
     * ----------------------------------------------------------------- */

    /**
     * What the banner should say for one service, from what is stored on it
     * alone - no call to Wizarr, so every page stays fast.
     *
     * @return array{state: string, key: string, inviteUrl: string}|null  null: no banner
     */
    public function bannerState(Service $service, array $properties, ?int $now = null): ?array
    {
        $now ??= time();
        $key = 'service-' . $service->id;
        $grants = $this->grantKeys($properties);

        if (!$grants) {
            // Paid, but the queue has not created the invitation yet. Only for a
            // while: if it never comes, the banner should not spin forever.
            $since = $service->updated_at?->getTimestamp();
            if ($since !== null && $now - $since <= self::PREPARING_FOR_SECONDS) {
                return ['state' => 'preparing', 'key' => $key, 'inviteUrl' => ''];
            }

            return null;
        }

        // The first server whose user is not created yet.
        foreach ($grants as $grant) {
            $state = $this->withGrant($grant, function () use ($properties, $now, $key, $grants) {
                $view = $this->grantView($properties);
                if (!empty($view[self::PROP_USERS])) {
                    return null;
                }
                if (!empty($properties[self::PROP_NEW_LINK])) {
                    return ['state' => 'failed', 'key' => $key . ($this->grant === '' ? '' : '-' . $this->grant), 'inviteUrl' => ''];
                }
                $url = (string) ($view[self::PROP_INVITE_URL] ?? '');
                if ($url === '') {
                    return null;
                }
                $extra = count($grants) > 1 ? ['grant' => $this->grant, 'server' => $this->grantLabel($properties)] : [];
                $key .= $this->grant === '' ? '' : '-' . $this->grant;
                $expires = (string) ($view[self::PROP_INVITE_EXPIRES] ?? '');
                if ($expires !== '' && ($ts = strtotime($expires)) !== false && $ts <= $now) {
                    return ['state' => 'expired', 'key' => $key, 'inviteUrl' => ''] + $extra;
                }

                return ['state' => 'ready', 'key' => $key, 'inviteUrl' => $url] + $extra;
            });
            if ($state !== null) {
                return $state;
            }
        }

        // Every user exists. Only a running trial still has something to say.
        $trialEnds = strtotime((string) ($properties[self::PROP_TRIAL_ENDS] ?? '')) ?: null;
        if ($trialEnds !== null && $trialEnds > $now) {
            return ['state' => 'trial', 'key' => $key, 'inviteUrl' => '', 'remaining' => $this->remainingText($trialEnds - $now)];
        }

        return null;
    }

    /**
     * The same answer, but checked with Wizarr: has the link been used in the
     * meantime, or has it expired? Used by the banner's background check.
     *
     * @return array{state: string, key: string, inviteUrl: string}
     */
    public function liveBannerState(Service $service, array $properties): array
    {
        $stored = $this->bannerState($service, $properties);
        if ($stored === null || in_array($stored['state'], ['preparing', 'trial', 'failed'], true)) {
            return $stored ?? ['state' => 'none', 'key' => 'service-' . $service->id, 'inviteUrl' => ''];
        }

        $grant = (string) ($stored['grant'] ?? ($this->grantKeys($properties)[0] ?? ''));
        try {
            $live = $this->withGrant($grant, function () use ($service, $properties, $stored) {
                $invitation = $this->findInvitation($properties);
                $status = $invitation['status'] ?? 'missing';

                if ($status === 'used') {
                    $users = $this->resolveUsers($properties, $invitation);
                    if (!$users) {
                        return ['state' => 'failed', 'inviteUrl' => ''] + $stored; // used, but no user was made
                    }
                    $this->rememberUsers($service, $users);
                    $this->syncSeerrQuietly($service, 'active');
                    $this->processTrialQuietly($service);

                    return null;
                }
                if ($status === 'pending') {
                    return ['state' => 'ready', 'inviteUrl' => $this->absoluteUrl($invitation['url'] ?? null, (string) $invitation['code'])] + $stored;
                }

                return ['state' => 'expired', 'inviteUrl' => ''] + $stored;
            });
        } catch (Throwable $e) {
            return $stored; // Wizarr unreachable: keep showing what we know
        }

        if ($live !== null) {
            return $live;
        }

        // This server's user is created: another server may still be waiting.
        $next = $this->bannerState($service, ExtensionHelper::getServiceProperties($service));
        if ($next !== null && in_array($next['state'], ['ready', 'expired'], true)) {
            return $next;
        }

        return ['state' => 'done', 'key' => $stored['key'], 'inviteUrl' => ''];
    }

    /** "footer" hook: the banner for the logged-in customer, if any. */
    private function renderBanner(): array
    {
        if (!Auth::check()) {
            return [];
        }

        try {
            $services = Service::where('user_id', Auth::id())
                ->where('status', 'active')
                ->with(['properties', 'product.server'])
                ->get();

            $hasAccess = false;
            foreach ($services as $service) {
                if ($service->product?->server?->extension !== 'Wizarr') {
                    continue;
                }
                $hasAccess = true;
                $state = $this->bannerState($service, $service->properties->pluck('value', 'key')->all());
                if ($state !== null) {
                    return ['view' => $this->bannerHtml($state + [
                        'serviceUrl' => route('services.show', $service),
                        'statusUrl' => route('extensions.wizarr.status'),
                        'shopUrl' => $this->shopUrl(),
                    ])];
                }
            }

            $over = $hasAccess ? null : $this->trialOverState(Auth::user());
            if ($over !== null) {
                return ['view' => $this->bannerHtml($over + [
                    'serviceUrl' => '',
                    'statusUrl' => route('extensions.wizarr.status'),
                    'shopUrl' => $this->shopUrl(),
                ])];
            }
        } catch (Throwable $e) {
            Log::warning('Wizarr: could not render the access banner: ' . $e->getMessage());
        }

        return [];
    }

    /** GET /wizarr/status - polled by the banner. */
    public function bannerStatus()
    {
        $services = Service::where('user_id', Auth::id())
            ->where('status', 'active')
            ->with(['properties', 'product.server'])
            ->get();

        foreach ($services as $service) {
            $server = $service->product?->server;
            if ($server?->extension !== 'Wizarr') {
                continue;
            }
            // That service's own server settings, not the first server's.
            $extension = new static(ExtensionHelper::settingsToArray($server->settings));
            $state = $extension->liveBannerState($service, $service->properties->pluck('value', 'key')->all());
            if (!in_array($state['state'], ['none', 'done'], true)) {
                return response()->json($state);
            }
        }

        $hasAccess = $services->contains(fn ($s) => $s->product?->server?->extension === 'Wizarr');
        $over = $hasAccess ? null : $this->trialOverState(Auth::user());

        return response()->json($over ?? ['state' => 'done']);
    }

    /**
     * The banner: plain HTML, CSS scoped to #wizarr-banner and a small
     * script. Colours come from the theme's CSS variables, so it fits the
     * default theme and custom themes without depending on their Tailwind builds.
     */
    public function bannerHtml(array $state): string
    {
        $e = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        return '<div id="wizarr-banner" role="status" aria-live="polite"'
            . ' data-state="' . $e($state['state']) . '"'
            . ' data-key="' . $e($state['key']) . '"'
            . ' data-status-url="' . $e($state['statusUrl'] ?? '') . '">'
            . <<<'HTML'
<style>
#wizarr-banner{position:fixed;z-index:40;left:1rem;right:1rem;bottom:1rem;max-width:26rem;margin-left:auto;background:hsl(var(--color-background-secondary));color:hsl(var(--color-base));border:1px solid hsl(var(--color-neutral));border-radius:.875rem;box-shadow:0 14px 36px rgb(0 0 0 / .28);padding:1rem 1.125rem;line-height:1.4}
@media (min-width:768px){#wizarr-banner{left:auto;right:1.5rem;bottom:1.5rem;width:26rem}}
#wizarr-banner[hidden]{display:none}
#wizarr-banner [data-when]{display:none}
#wizarr-banner[data-state="preparing"] [data-when~="preparing"],#wizarr-banner[data-state="ready"] [data-when~="ready"],#wizarr-banner[data-state="expired"] [data-when~="expired"],#wizarr-banner[data-state="failed"] [data-when~="failed"],#wizarr-banner[data-state="trial_over"] [data-when~="trial_over"],#wizarr-banner[data-state="trial"] [data-when~="trial"]{display:block}
#wizarr-banner .wb-title{font-weight:700;font-size:1rem;margin:0 1.75rem .25rem 0;display:flex;align-items:center;gap:.5rem}
#wizarr-banner .wb-text{font-size:.875rem;color:hsl(var(--color-muted));margin:0}
#wizarr-banner .wb-actions{display:flex;gap:.25rem .75rem;flex-wrap:wrap;align-items:center;margin-top:.75rem}
#wizarr-banner .wb-btn{display:inline-block;padding:.55rem .95rem;border-radius:.5rem;font-weight:600;font-size:.875rem;text-decoration:none;background:hsl(var(--color-primary));color:hsl(var(--color-on-primary, 0 0% 100%))}
#wizarr-banner .wb-btn:hover{filter:brightness(1.08)}
#wizarr-banner .wb-link{font-size:.875rem;font-weight:600;color:hsl(var(--color-primary));text-decoration:none}
#wizarr-banner .wb-link:hover{text-decoration:underline}
#wizarr-banner .wb-close{position:absolute;top:.5rem;right:.5rem;width:2rem;height:2rem;border:0;background:none;color:hsl(var(--color-muted));font-size:1.25rem;line-height:1;cursor:pointer;border-radius:.375rem}
#wizarr-banner .wb-close:hover{color:hsl(var(--color-base));background:hsl(var(--color-neutral) / .5)}
#wizarr-banner .wb-spin{width:1rem;height:1rem;border-radius:9999px;border:2px solid hsl(var(--color-neutral));border-top-color:hsl(var(--color-primary));animation:wb-spin .8s linear infinite;flex:none}
@keyframes wb-spin{to{transform:rotate(360deg)}}
@media (prefers-reduced-motion:reduce){#wizarr-banner .wb-spin{animation-duration:2.4s}}
</style>
HTML
            . '<button type="button" class="wb-close" data-close aria-label="' . $e(self::t('Hide')) . '">&times;</button>'
            . '<div data-when="preparing"><p class="wb-title"><span class="wb-spin" aria-hidden="true"></span>' . $e(self::t('Your access is being prepared')) . '</p>'
            . '<p class="wb-text">' . $e(self::t('Thanks for your payment! It usually only takes a moment – this box updates itself.')) . '</p></div>'
            . '<div data-when="ready"><p class="wb-title">' . $e(self::t('Your access is ready')) . ' 🎉</p>'
            . '<p class="wb-text">' . $e(self::t('Create your user with your personal link. The link can only be used once.')) . '</p></div>'
            . '<div data-when="expired"><p class="wb-title">' . $e(self::t('Your invitation link has expired')) . '</p>'
            . '<p class="wb-text">' . $e(self::t('Get a new link on your subscription.')) . '</p></div>'
            . '<div data-when="failed"><p class="wb-title">' . $e(self::t('Your user was not created')) . '</p>'
            . '<p class="wb-text">' . $e(self::t('Get a new link on your subscription and try again.')) . '</p></div>'
            . '<div data-when="trial"><p class="wb-title"><span>' . $e(self::t('Trial')) . ': <span data-remaining>' . $e($state['remaining'] ?? '') . '</span></span></p>'
            . '<p class="wb-text">' . $e(self::t('Continue with a subscription and keep your user and everything you have watched.')) . '</p></div>'
            . '<div data-when="trial_over"><p class="wb-title">' . $e(self::t('Your trial has ended')) . '</p>'
            . '<p class="wb-text">' . $e(self::t('Continue with a subscription – you keep your user and everything you have watched.')) . '</p></div>'
            . <<<'HTML'
<div class="wb-actions">
  <span data-when="ready"><a class="wb-btn" data-invite target="_blank" rel="noopener" href="
HTML
            . $e($state['inviteUrl'] ?? '') . '">' . $e(($state['server'] ?? '') !== '' ? self::t('Create your user on :server', ['server' => $state['server']]) : self::t('Create your user')) . '</a></span>'
            . '<span data-when="expired failed"><a class="wb-btn" href="' . $e($state['serviceUrl'] ?? '') . '">' . $e(self::t('Get a new link')) . '</a></span>'
            . '<span data-when="preparing ready"><a class="wb-link" href="' . $e($state['serviceUrl'] ?? '') . '">' . $e(self::t('View subscription')) . '</a></span>'
            . '<span data-when="trial trial_over"><a class="wb-btn" href="' . $e($state['shopUrl'] ?? '') . '">' . $e(self::t('See subscriptions')) . '</a></span>'
            . <<<'HTML'
</div>
<script>
(() => {
  const el = document.getElementById('wizarr-banner');
  if (!el) return;
  clearTimeout(window.__wizarrBannerTimer);

  // Closing hides this message for the rest of the visit. A different
  // message (e.g. "expired" after "ready") shows again.
  const hiddenKey = () => el.dataset.key + ':' + el.dataset.state;
  const isHidden = () => { try { return sessionStorage.getItem('wizarr-banner-hidden') === hiddenKey(); } catch (e) { return false; } };
  el.hidden = isHidden();

  el.querySelector('[data-close]').addEventListener('click', () => {
    try { sessionStorage.setItem('wizarr-banner-hidden', hiddenKey()); } catch (e) {}
    el.hidden = true;
  });

  let tries = 0;
  const check = async () => {
    tries++;
    try {
      const response = await fetch(el.dataset.statusUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      if (response.ok) {
        const status = await response.json();
        if (status.state === 'done' || status.state === 'none') { el.remove(); return; }
        el.dataset.state = status.state;
        el.dataset.key = status.key || el.dataset.key;
        if (status.inviteUrl) el.querySelector('[data-invite]').href = status.inviteUrl;
        if (status.remaining) el.querySelector('[data-remaining]').textContent = status.remaining;
        el.hidden = isHidden();
      }
    } catch (e) {}
    // Keep asking only while the invitation is being created, for up to ~3 minutes.
    if (el.dataset.state === 'preparing' && tries < 60) {
      window.__wizarrBannerTimer = setTimeout(check, 3000);
    }
  };
  check();
})();
</script>
</div>
HTML;
    }

    /* ------------------------------------------------------------------
     | Customer deleted in Paymenter
     * ----------------------------------------------------------------- */

    /**
     * Ends every Wizarr subscription of a customer who is about to be deleted:
     * media server user, invitation, Seerr account and Discord roles.
     *
     * @param  callable|null  $terminate  for tests; defaults to Paymenter's ExtensionHelper
     * @return string[] what failed; empty when all access is gone
     */
    public function removeAccessForUser(User $user, ?callable $terminate = null): array
    {
        $terminate ??= fn (Service $service) => ExtensionHelper::terminateServer($service);
        $failed = [];

        foreach ($user->services()->with(['product.server'])->get() as $service) {
            if ($service->product?->server?->extension !== 'Wizarr') {
                continue;
            }
            try {
                $terminate($service);
            } catch (Throwable $e) {
                $failed[] = self::t('subscription #:id', ['id' => $service->id]) . ': ' . $e->getMessage();
            }
        }

        // The services above were ended one by one while the others still
        // counted as active; now take back every role we gave.
        if (!$failed && $this->discordConfigured()) {
            $discordId = $this->propertyValue($user, self::PROP_DISCORD_ID);
            if ($discordId) {
                try {
                    $this->syncDiscordRoles($discordId, [], $this->grantedRoles($user));
                } catch (Throwable $e) {
                    Log::warning('Wizarr: Discord roles of deleted user ' . $user->id . ' not taken back: ' . $e->getMessage());
                }
            }
        }

        return $failed;
    }

    /** User "deleting" event: false keeps the customer, and the admin sees why. */
    private function beforeUserDeleted(User $user): ?bool
    {
        $failed = $this->removeAccessForUser($user);
        if (!$failed) {
            return null; // go ahead
        }

        Log::warning('Wizarr: user ' . $user->id . ' was not deleted, access could not be removed: ' . implode('; ', $failed));
        if (class_exists(AdminNotification::class)) {
            AdminNotification::make()
                ->danger()
                ->persistent()
                ->title(self::t('The user was not deleted'))
                ->body(self::t("The access in Wizarr/Jellyfin couldn't be removed (:reasons). Try again when Wizarr answers.", ['reasons' => implode('; ', $failed)]))
                ->send();
        }

        return false;
    }

    /* ------------------------------------------------------------------
     | Refunds
     * ----------------------------------------------------------------- */

    private static bool $refundListener = false;

    /**
     * After Paymenter's Refund button: when the invoice has been refunded in
     * full, suspend the Wizarr services it paid for, like an overdue invoice
     * would. Partial refunds (a discount, a double charge) change nothing.
     * Refunds and chargebacks made directly at PayPal or Stripe never reach
     * Paymenter, so they are not seen here.
     *
     * @param  callable|null  $suspend  for tests; defaults to suspendForRefund()
     * @return int[] the services that were suspended
     */
    public function afterRefund($transaction, ?callable $suspend = null): array
    {
        if (!method_exists($transaction, 'wasChanged') || !$transaction->wasChanged('refunded_amount')) {
            return [];
        }
        $invoice = $transaction->invoice;
        if (!$invoice || !$this->fullyRefunded($invoice->transactions)) {
            return [];
        }

        $suspend ??= fn (Service $service) => $this->suspendForRefund($service);
        $suspended = [];
        foreach ($invoice->items as $item) {
            // Upgrades (e.g. adding Seerr) reference a ServiceUpgrade: left alone.
            if ($item->reference_type !== Service::class || !($service = $item->reference)) {
                continue;
            }
            $server = $service->product?->server;
            if ($server?->extension !== 'Wizarr' || $service->status !== Service::STATUS_ACTIVE) {
                continue;
            }
            // That service's own server decides.
            $settings = ExtensionHelper::settingsToArray($server->settings);
            if (($settings['refund_action'] ?? 'suspend') === 'nothing') {
                continue;
            }
            $suspend($service);
            $suspended[] = $service->id;
        }

        return $suspended;
    }

    /** True when money came in and all of it went back. */
    public function fullyRefunded(iterable $transactions): bool
    {
        $paid = 0.0;
        $kept = 0.0;
        foreach ($transactions as $transaction) {
            $status = $transaction->status instanceof \BackedEnum ? $transaction->status->value : $transaction->status;
            if ($status !== 'succeeded') {
                continue;
            }
            $paid += (float) $transaction->amount;
            $kept += (float) $transaction->amount - (float) $transaction->refunded_amount;
        }

        return $paid > 0 && $kept < 0.005;
    }

    /**
     * What Paymenter's own overdue run does, once the refund is committed
     * (the Refund button runs inside a database transaction). No "suspended"
     * mail: that template is written for unpaid invoices.
     */
    private function suspendForRefund(Service $service): void
    {
        DB::afterCommit(function () use ($service) {
            $service->update(['status' => Service::STATUS_SUSPENDED]);
            SuspendJob::dispatch($service, false);
            Log::info('Wizarr: service ' . $service->id . ' suspended because its invoice was refunded in full.');
        });
    }

    /* ------------------------------------------------------------------
     | Trial products
     * ----------------------------------------------------------------- */

    public function isTrial(array $productSettings): bool
    {
        return (int) ($productSettings['trial_days'] ?? 0) > 0;
    }

    /** The product's settings, with trial days given to this subscription in admin applied. */
    public function trialSettings(array $productSettings, array $properties): array
    {
        $days = (int) ($properties[self::PROP_TRIAL_DAYS] ?? 0);
        if ($days > 0) {
            $productSettings['trial_days'] = $days;
        }

        return $productSettings;
    }

    /**
     * The customer's other subscriptions on the same server as $service, as
     * trialRefusal() takes them: one trial per server, so a customer can try
     * both Silo and Jellyfin, but not the same one twice.
     *
     * @return string[] 'trial' or 'paid' for each
     */
    public function trialHistory(Service $service, iterable $others): array
    {
        $server = $this->serverIdOf($service);
        $history = [];
        foreach ($others as $other) {
            if ($other->id === $service->id || $other->product?->server?->extension !== 'Wizarr' || $this->serverIdOf($other) !== $server) {
                continue;
            }
            $props = ExtensionHelper::getServiceProperties($other);
            if (!empty($props[self::PROP_TRIAL_FORGIVEN])) {
                continue; // forgiven in admin: may try again
            }
            $history[] = $this->isTrial($this->trialSettings(ExtensionHelper::settingsToArray($other->product->settings), $props)) ? 'trial' : 'paid';
        }

        return $history;
    }

    private function serverIdOf(Service $service): ?int
    {
        $id = $service->product?->server_id ?? $service->product?->server?->id ?? null;

        return $id === null ? null : (int) $id;
    }

    /**
     * Who may start a trial: only customers who never had one and never
     * subscribed on this server. $history: 'trial' or 'paid' for each of
     * their other subscriptions there, whatever their status (trialHistory()).
     */
    public function trialRefusal(array $history): ?string
    {
        if (in_array('paid', $history, true)) {
            return self::t('The trial is for new customers only.');
        }
        if (in_array('trial', $history, true)) {
            return self::t('You have already had a trial.');
        }

        return null;
    }

    /**
     * Moves a trial along. Called hourly and right after the customer creates
     * their user.
     *
     *   user created         -> the clock starts: ends = user's creation + days
     *   end reached          -> user disabled (not deleted), trial closed
     *   30 days after the end, not bought -> user deleted
     *
     * @return string what happened: 'none', 'started', 'ended' or 'cleaned'
     */
    public function processTrial(Service $service, array $productSettings, array $properties, ?int $now = null): string
    {
        $productSettings = $this->trialSettings($productSettings, $properties);
        if (!$this->isTrial($productSettings) || !empty($properties[self::PROP_TRIAL_CONVERTED])) {
            return 'none';
        }
        $now ??= time();
        $users = $this->allUsers($properties, resolve: false);

        if ($service->status === 'active') {
            if (!$users) {
                return 'none'; // not started: the customer has not created their user
            }

            $ends = strtotime((string) ($properties[self::PROP_TRIAL_ENDS] ?? '')) ?: null;
            if ($ends === null) {
                $created = null;
                foreach ($this->client()->users() as $row) {
                    if ((int) $row['id'] === $users[0]['id'] && !empty($row['created_at'])) {
                        $created = strtotime((string) $row['created_at']) ?: null;
                    }
                }
                $ends = ($created ?? $now) + (int) $productSettings['trial_days'] * 86400;
                $this->setProperty($service, self::PROP_TRIAL_ENDS, 'Trial ends', date(DATE_ATOM, $ends));
                if ($ends > $now) {
                    return 'started';
                }
            }

            if ($ends > $now) {
                return 'none';
            }

            foreach ($this->grantKeys($properties) as $grant) {
                $this->withGrant($grant, function () use ($service, $properties) {
                    foreach ($this->parseUsers((string) ($this->grantView($properties)[self::PROP_USERS] ?? '')) as $user) {
                        $message = $this->client()->disableUser($user['id']);
                        if (stripos($message, 'deleted') !== false) {
                            // The server could not disable them, so Wizarr deleted them.
                            $this->rememberUsers($service, []);
                        }
                    }
                });
            }
            $this->setProperty($service, self::PROP_TRIAL_ENDED, 'Trial ended', date(DATE_ATOM, $now));
            $this->closeService($service);

            return 'ended';
        }

        if ($service->status === 'cancelled' && $users) {
            $ended = strtotime((string) ($properties[self::PROP_TRIAL_ENDED] ?? '')) ?: null;
            if ($ended !== null && $now - $ended >= self::TRIAL_KEEP_SECONDS) {
                foreach ($users as $user) {
                    $this->deleteUserQuietly($user['id']);
                }
                foreach ($this->grantKeys($properties) as $grant) {
                    $this->withGrant($grant, fn () => $this->rememberUsers($service, []));
                }

                return 'cleaned';
            }
        }

        return 'none';
    }

    /**
     * When a customer buys a normal product after (or during) a trial: their
     * trial's media server user is switched back on and moved over.
     *
     * @return bool true when a user was adopted
     */
    public function adoptTrialUser(Service $service): bool
    {
        foreach ($service->user->services()->with(['product.settings', 'product.server'])->get() as $other) {
            if ($other->id === $service->id || $other->product?->server?->extension !== 'Wizarr' || $this->serverIdOf($other) !== $this->serverIdOf($service)) {
                continue; // a trial on another server (e.g. Silo) stays its own
            }
            $props = ExtensionHelper::getServiceProperties($other);
            if (!$this->isTrial($this->trialSettings(ExtensionHelper::settingsToArray($other->product->settings), $props))) {
                continue;
            }
            if (!empty($props[self::PROP_TRIAL_CONVERTED]) || !$this->allUsers($props, resolve: false)) {
                continue;
            }

            // Per server: the trial's users, checked that they still exist.
            $byGrant = [];
            foreach ($this->grantKeys($props) as $grant) {
                $found = $this->withGrant($grant, fn () => $this->resolveUsers($props, null));
                if ($found) {
                    $byGrant[$grant] = $found;
                }
            }
            if (!$byGrant) {
                continue;
            }
            foreach (array_merge(...array_values($byGrant)) as $user) {
                if (stripos($this->client()->enableUser($user['id']), 'enabled successfully') === false) {
                    return false; // cannot be switched back on (Plex): invite as usual
                }
            }

            foreach ($byGrant as $grant => $found) {
                $this->withGrant((string) $grant, function () use ($service, $other, $found, $props) {
                    $this->rememberUsers($service, $found);
                    $label = (string) ($this->grantView($props)[self::PROP_SERVER_LABEL] ?? '');
                    if ($this->grant !== '' && $label !== '') {
                        $this->setProperty($service, $this->key(self::PROP_SERVER_LABEL), 'Wizarr media server (' . $label . ')', $label);
                    }
                    $address = (string) ($this->grantView($props)[self::PROP_SERVER_URL] ?? '');
                    if ($this->grant !== '' && $address !== '') {
                        $this->setProperty($service, $this->key(self::PROP_SERVER_URL), 'Wizarr media server address (' . $label . ')', $address);
                    }
                    $this->rememberUsers($other, []); // never delete them from the trial again
                });
            }
            $this->setProperty($other, self::PROP_TRIAL_CONVERTED, 'Trial became service', (string) $service->id);
            if ($other->status === 'active') {
                $this->closeService($other);
            }

            return true;
        }

        return false;
    }

    private function adoptTrialUserQuietly(Service $service): bool
    {
        try {
            return $this->adoptTrialUser($service);
        } catch (Throwable $e) {
            Log::warning('Wizarr: could not move the trial user to service ' . $service->id . ', inviting instead: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Ends a service in Paymenter without the termination hook, which would
     * delete the media server user. Mirrors what Paymenter does on cancel.
     */
    protected function closeService(Service $service): void
    {
        $service->status = 'cancelled';
        if (method_exists($service, 'save')) {
            $service->save();
        }
        $product = $service->product;
        if ($product && ($product->stock ?? null) !== null && method_exists($product, 'increment')) {
            $product->increment('stock', $service->quantity ?? 1);
        }
        $this->syncRolesQuietly($service, active: false);
        $this->syncSeerrQuietly($service, 'ended');
    }

    /** Hourly: every subscription on a trial product. */
    public function processTrials(?iterable $services = null): void
    {
        $services ??= Service::whereIn('status', ['active', 'cancelled'])
            ->whereHas('product.server', fn ($query) => $query->where('extension', 'Wizarr'))
            ->with(['product.settings'])
            ->get();
        foreach ($services as $service) {
            if (!$service->product) {
                continue;
            }
            $properties = ExtensionHelper::getServiceProperties($service);
            $productSettings = $this->trialSettings(ExtensionHelper::settingsToArray($service->product->settings), $properties);
            if (!$this->isTrial($productSettings)) {
                continue;
            }
            try {
                $this->forServiceServer($service)->processTrial($service, $productSettings, $properties);
            } catch (Throwable $e) {
                Log::warning('Wizarr: trial ' . $service->id . ' not processed, will retry: ' . $e->getMessage());
            }
        }
    }

    private function processTrialQuietly(Service $service): void
    {
        if (!$service->product) {
            return;
        }
        try {
            $properties = ExtensionHelper::getServiceProperties($service);
            $productSettings = $this->trialSettings(ExtensionHelper::settingsToArray($service->product->settings), $properties);
            if ($this->isTrial($productSettings)) {
                $this->processTrial($service, $productSettings, $properties);
            }
        } catch (Throwable $e) {
            Log::warning('Wizarr: trial ' . $service->id . ' not processed, will retry: ' . $e->getMessage());
        }
    }

    /**
     * The "your trial is over" box: a trial that ended in the last two weeks
     * and was not followed by a purchase.
     */
    public function trialOverState($user, ?int $now = null): ?array
    {
        $now ??= time();
        foreach ($user->services()->with(['product.settings', 'product.server'])->get() as $service) {
            if ($service->status !== 'cancelled' || $service->product?->server?->extension !== 'Wizarr') {
                continue;
            }
            $props = ExtensionHelper::getServiceProperties($service);
            $ended = strtotime((string) ($props[self::PROP_TRIAL_ENDED] ?? '')) ?: null;
            if ($ended !== null && empty($props[self::PROP_TRIAL_CONVERTED]) && $now - $ended < self::TRIAL_OVER_BANNER_SECONDS) {
                return ['state' => 'trial_over', 'key' => 'trial-' . $service->id, 'inviteUrl' => ''];
            }
        }

        return null;
    }

    /** "5 days left", "1 day left", "8 hours left", "less than an hour left" (translated). */
    public function remainingText(int $seconds): string
    {
        if ($seconds <= 0) {
            return self::t('ended');
        }
        if ($seconds >= 36 * 3600) {
            return self::t(':count days left', ['count' => (int) round($seconds / 86400)]);
        }
        if ($seconds >= 24 * 3600) {
            return self::t('1 day left');
        }
        if ($seconds >= 3600) {
            $hours = (int) floor($seconds / 3600);

            return $hours === 1 ? self::t('1 hour left') : self::t(':count hours left', ['count' => $hours]);
        }

        return self::t('less than an hour left');
    }

    private function shopUrl(): string
    {
        try {
            return route('home') . '#subscriptions';
        } catch (Throwable $e) {
            return '/#subscriptions';
        }
    }

    /** Mail data for a service that starts with an existing user: where to watch. */
    private function accessMailData(array $settings): array
    {
        $loginUrl = trim((string) ($settings['login_url'] ?? ''));

        return ['invite_url' => $loginUrl, 'invite_code' => '', 'invite_valid_for' => '', 'login_url' => $loginUrl];
    }

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
     * Kept for 10 minutes, as building it may ask Wizarr or Silo.
     *
     * @return array{label: string, url: string}|null
     */
    public function navWatchLink($user, ?iterable $services = null): ?array
    {
        if (!$user) {
            return null;
        }
        $find = function () use ($user, $services) {
            $services ??= Service::where('user_id', $user->id)->where('status', 'active')->with(['product.server', 'properties'])->orderBy('id')->get();
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
                            $label = $this->forServiceServer($service)->isSilo() ? self::t('Open :server', ['server' => 'Silo']) : self::t('Watch');
                        }

                        return ['label' => $label, 'url' => (string) $action['url']];
                    }
                }
            }

            return [];
        };
        if ($services !== null) {
            return $find() ?: null;
        }
        // A customer whose user isn't created yet gets the link within a minute of creating it.
        $key = 'wizarr:nav-watch:' . $user->id;
        $link = Cache::get($key);
        if (!is_array($link)) {
            $link = $find();
            Cache::put($key, $link, $link ? 600 : 60);
        }

        return $link ?: null;
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

    /* ------------------------------------------------------------------
     | Safety net: finish suspensions and terminations that failed
     * ----------------------------------------------------------------- */

    /**
     * Runs every hour. Paymenter marks a service suspended or cancelled even
     * when the call to Wizarr failed, and never tries again - so the customer
     * would keep watching. suspendServer()/terminateServer() leave a marker
     * while they work; whatever still carries one is retried here.
     *
     * Only services with a marker are touched, so a healthy service never
     * gets repeated calls.
     *
     * @param  iterable<Service>|null  $services  for tests; defaults to the database
     * @param  callable|null  $run  for tests; defaults to Paymenter's ExtensionHelper
     * @return array{retried: int, failed: int}
     */
    public function retryPending(?iterable $services = null, ?callable $run = null): array
    {
        $services ??= Service::whereHas('properties', fn ($query) => $query->where('key', self::PROP_PENDING))->get();
        $run ??= fn (string $action, Service $service) => $action === 'suspend'
            ? ExtensionHelper::suspendServer($service)
            : ExtensionHelper::terminateServer($service);

        $retried = 0;
        $failed = 0;
        $errors = [];

        foreach ($services as $service) {
            $pending = $this->propertyValue($service, self::PROP_PENDING);

            // Must still match what Paymenter wants: paid again means leave it.
            $wanted = match ($service->status) {
                'suspended' => 'suspend',
                'cancelled' => 'terminate',
                default => null,
            };

            if ($wanted === null || $pending !== $wanted) {
                $this->setProperty($service, self::PROP_PENDING, '', null);

                continue;
            }

            try {
                $run($pending, $service);
                $retried++;
                Log::info('Wizarr: finished the ' . $pending . ' of service ' . $service->id . ' on retry.');
            } catch (Throwable $e) {
                $failed++;
                $errors[$service->id] = $e->getMessage();
                Log::warning('Wizarr: ' . $pending . ' of service ' . $service->id . ' still fails, will retry: ' . $e->getMessage());
            }
        }

        return ['retried' => $retried, 'failed' => $failed, 'errors' => $errors];
    }

    /* ------------------------------------------------------------------
     | Alerts
     * ----------------------------------------------------------------- */

    /** The same alert is repeated once a day while it lasts, not every hour. */
    private const ALERT_REPEAT_SECONDS = 86400;

    /**
     * What needs a person, from the hourly run. Empty when all is well.
     *
     * @param  array{failed?: int, errors?: array<int, string>}  $retry  retryPending()'s result
     * @param  iterable<Service>|null  $active  active services; defaults to all of them
     * @return string[]
     */
    public function healthProblems(array $retry, ?iterable $active = null, ?int $now = null): array
    {
        $now ??= time();
        $problems = [];

        $errors = $retry['errors'] ?? [];
        if ($errors) {
            $problems[] = count($errors) . ' suspension(s) or cancellation(s) keep failing (service ' . $this->serviceList(array_keys($errors))
                . '), so those customers still have access. Last error: ' . $this->shorten((string) end($errors));
        }

        try {
            $this->client()->status();
        } catch (Throwable $e) {
            $problems[] = 'Wizarr cannot be reached: ' . $this->shorten($e->getMessage());
        }

        $active ??= Service::where('status', Service::STATUS_ACTIVE)->with(['properties', 'product.server'])->get();
        $stuck = [];
        foreach ($active as $service) {
            if ($service->product?->server?->extension !== 'Wizarr') {
                continue;
            }
            $properties = ExtensionHelper::getServiceProperties($service);
            $since = $service->updated_at?->getTimestamp();
            if (!$this->hasGrants($properties) && $since !== null && $now - $since > self::PREPARING_FOR_SECONDS) {
                $stuck[] = $service->id;
            }
        }
        if ($stuck) {
            $problems[] = count($stuck) . ' paid subscription(s) have had no invitation for over 15 minutes (service ' . $this->serviceList($stuck)
                . '). Is Paymenter\'s queue worker running? The reason is usually in Admin -> Other -> Failed Jobs, or in the log.';
        }

        return $problems;
    }

    /**
     * Posts problems to the Discord webhook (once a day while they last, at
     * once when they change, and once more when they are gone) and opens the
     * heartbeat URL. Never throws: alerts must not break the hourly run.
     *
     * @param  callable|null  $send  for tests: fn (string $method, string $url, array $body)
     * @return string|null  what was posted, if anything
     */
    public function reportHealth(array $retry, ?iterable $active = null, ?callable $send = null, ?int $now = null): ?string
    {
        $now ??= time();
        $send ??= function (string $method, string $url, array $body = []) {
            $request = \Illuminate\Support\Facades\Http::withHeaders(['User-Agent' => 'Paywarr'])->timeout(10);
            $response = $method === 'post' ? $request->post($url, $body) : $request->get($url);
            if (method_exists($response, 'failed') && $response->failed()) {
                throw new \RuntimeException('HTTP ' . $response->status());
            }
        };
        $posted = null;

        try {
            $problems = $this->healthProblems($retry, $active, $now);
            $webhook = trim((string) $this->config('alert_webhook'));
            $last = Cache::get('wizarr:alert');
            $digest = $problems ? md5(implode("\n", array_map(fn ($p) => preg_replace('/\d+/', '#', $p), $problems))) : null;

            if ($problems && (!$last || $last['digest'] !== $digest || $now - $last['at'] >= self::ALERT_REPEAT_SECONDS)) {
                $posted = "**Paywarr needs a look**\n- " . implode("\n- ", $problems);
                Cache::put('wizarr:alert', ['digest' => $digest, 'at' => $now], 2 * self::ALERT_REPEAT_SECONDS);
            } elseif (!$problems && $last) {
                $posted = '**Paywarr: all good again.** The problems reported earlier are gone.';
                Cache::forget('wizarr:alert');
            }

            if ($posted !== null && $webhook !== '') {
                // No pings: a customer's name in a message must not mention anyone.
                $send('post', $webhook, ['content' => $posted, 'username' => 'Paywarr', 'allowed_mentions' => ['parse' => []]]);
            }
        } catch (Throwable $e) {
            Log::warning('Wizarr: could not send the alert: ' . $e->getMessage());
        }

        $heartbeat = trim((string) $this->config('heartbeat_url'));
        if ($heartbeat !== '') {
            try {
                $send('get', $heartbeat, []);
            } catch (Throwable $e) {
                Log::warning('Wizarr: heartbeat URL did not answer: ' . $e->getMessage());
            }
        }

        return $posted;
    }

    /** "#3, #7, #9 and 2 more" */
    private function serviceList(array $ids): string
    {
        $shown = array_map(fn ($id) => '#' . $id, array_slice($ids, 0, 5));
        $more = count($ids) - count($shown);

        return implode(', ', $shown) . ($more > 0 ? ' and ' . $more . ' more' : '');
    }

    private function shorten(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > 300 ? mb_substr($text, 0, 297) . '...' : $text;
    }

    /* ------------------------------------------------------------------
     | Discord membership
     * ----------------------------------------------------------------- */

    /** Service Created listener: the checkout guard. */
    private function guardCheckout(Service $service): void
    {
        $product = $service->product;
        $server = $product?->server;

        // Only this extension's products, and only the customer's own checkout:
        // an admin creating a service by hand is never blocked.
        if (!$server || $server->extension !== 'Wizarr' || !Auth::check() || (int) Auth::id() !== (int) $service->user_id) {
            return;
        }

        $productSettings = ExtensionHelper::settingsToArray($product->settings);

        if ($this->isTrial($productSettings)) {
            $refusal = $this->trialRefusal($this->trialHistory($service, $service->user->services()->with(['product.settings', 'product.server'])->get()));
            if ($refusal !== null) {
                throw new DisplayException($refusal);
            }
        }

        $this->checkPurchaseAllowed(
            ExtensionHelper::settingsToArray($server->settings),
            $productSettings,
            $this->propertyValue($service->user, self::PROP_DISCORD_ID),
        );
    }

    /**
     * Throws a DisplayException - shown to the customer, order rolled back -
     * unless the purchase may go ahead.
     *
     * Fails closed: if Discord cannot be asked, the purchase waits. The
     * requirement is yours, so an outage should not quietly skip it.
     */
    public function checkPurchaseAllowed(array $serverSettings, array $productSettings, ?string $discordId): void
    {
        if (!$this->truthy($productSettings['require_discord'] ?? false)) {
            return;
        }

        if (!$this->discordConfigured($serverSettings)) {
            Log::warning('Wizarr: a product requires Discord membership, but the Discord settings on the server are incomplete.');

            throw new DisplayException(self::t('This product is temporarily unavailable. Please contact us.'));
        }

        if (!$discordId) {
            throw new DisplayException(self::t('Connect your Discord account before you buy. Go to Account → Discord.'));
        }

        try {
            $member = $this->discord($serverSettings)->isMember((string) $serverSettings['discord_guild_id'], $discordId);
        } catch (DiscordException $e) {
            Log::warning('Wizarr: Discord membership check failed: ' . $e->getMessage());

            throw new DisplayException(self::t('We can\'t check your Discord membership right now. Please try again shortly.'));
        }

        if (!$member) {
            throw new DisplayException(self::t('You need to be a member of our Discord to buy. Go to Account → Discord to join, then try again.'));
        }
    }

    /** GET /wizarr/discord - the customer's Discord status page. */
    public function discordPage()
    {
        abort_unless($this->discordConfigured(), 404);

        $user = Auth::user();
        $discordId = $this->propertyValue($user, self::PROP_DISCORD_ID);
        $member = null;
        $error = false;

        if ($discordId) {
            try {
                $member = $this->discord()->isMember((string) $this->config('discord_guild_id'), $discordId);
                // "I have joined - check again" must open the shop straight away.
                Cache::put('wizarr:discord-member:' . $discordId, $member, self::MEMBERSHIP_CACHE_SECONDS);
                // Back on the server: a subscription suspended for leaving opens at once.
                if ($member) {
                    $this->reconcileDiscordMembership(Service::where('user_id', $user->id)->whereIn('status', ['active', 'suspended'])->with(['product.server', 'properties'])->get());
                }
            } catch (DiscordException $e) {
                Log::warning('Wizarr: Discord membership check failed: ' . $e->getMessage());
                $error = true;
            }
        }

        $content = view('wizarr::discord', [
            'connected' => (bool) $discordId,
            'name' => $this->propertyValue($user, self::PROP_DISCORD_NAME),
            'member' => $member,
            'error' => $error,
            'inviteUrl' => trim((string) $this->config('discord_invite_url')),
            't' => fn (string $text, array $replace = []) => self::t($text, $replace),
        ])->render();

        return view('layouts.app', ['slot' => new HtmlString($content), 'title' => 'Discord']);
    }

    /** GET /wizarr/discord/connect - off to Discord to log in. */
    public function discordConnect()
    {
        abort_unless($this->discordConfigured(), 404);

        Session::put('wizarr.discord.intent', 'link');

        // "identify" only: the ID and name, not the e-mail address or server list.
        return $this->discordOAuth()->setScopes(['identify'])->redirect();
    }

    /** GET /wizarr/discord/callback - back from Discord. */
    public function discordCallback()
    {
        abort_unless($this->discordConfigured(), 404);
        $intent = Session::pull('wizarr.discord.intent', 'link');

        try {
            $account = $this->discordOAuth()->user();
        } catch (Throwable $e) {
            return Auth::check()
                ? $this->backToDiscordPage(self::t('The Discord login was cancelled. Please try again.'), 'error')
                : $this->backToLogin(self::t('The Discord login was cancelled. Please try again.'));
        }

        if (!Auth::check()) {
            return $intent === 'login' ? $this->finishDiscordSignIn($account) : $this->backToLogin(self::t('Please log in first.'));
        }

        $discordId = (string) $account->getId();
        $user = Auth::user();

        // One Discord account, one customer: otherwise a single member could
        // buy through any number of Paymenter accounts. Paymenter keeps a
        // deleted user's properties, so only count users that still exist.
        $takenBy = Property::where('key', self::PROP_DISCORD_ID)
            ->where('value', $discordId)
            ->where('model_type', User::class)
            ->where('model_id', '!=', $user->id)
            ->whereIn('model_id', User::query()->select('id'))
            ->exists();
        if ($takenBy) {
            return $this->backToDiscordPage(self::t('That Discord account is already connected to another user.'), 'error');
        }

        $name = (string) ($account->getNickname() ?: $account->getName() ?: $discordId);
        $user->properties()->updateOrCreate(['key' => self::PROP_DISCORD_ID], ['name' => 'Discord ID', 'value' => $discordId]);
        $user->properties()->updateOrCreate(['key' => self::PROP_DISCORD_NAME], ['name' => 'Discord', 'value' => $name]);
        Cache::forget('wizarr:discord-member:' . $discordId);
        // A Discord hiccup must not turn a successful link into an error page;
        // the hourly run gives the roles later.
        try {
            $this->syncRolesForUser($user);
        } catch (Throwable $e) {
            Log::warning('Wizarr: Discord roles for user ' . $user->id . ' not updated after linking, will retry: ' . $e->getMessage());
        }

        return $this->backToDiscordPage(self::t('Discord connected as :name.', ['name' => $name]), 'success');
    }

    /** POST /wizarr/discord/disconnect */
    public function discordDisconnect()
    {
        $user = Auth::user();
        $discordId = $this->propertyValue($user, self::PROP_DISCORD_ID);
        if ($discordId) {
            // Take back the roles we gave this Discord account before forgetting it.
            try {
                $this->syncDiscordRoles($discordId, [], $this->grantedRoles($user));
            } catch (Throwable $e) {
                Log::warning('Wizarr: could not remove Discord roles on disconnect: ' . $e->getMessage());
            }
        }
        $user->properties()->whereIn('key', [self::PROP_DISCORD_ID, self::PROP_DISCORD_NAME, self::PROP_DISCORD_ROLES])->delete();

        return $this->backToDiscordPage(self::t('Your Discord account is no longer connected.'), 'success');
    }

    /* ---------------- Shop gate ---------------- */

    /**
     * Who may see the shop: 'allow', or where to send them first ('login',
     * 'connect', 'join'), or 'unavailable' when Discord cannot be asked.
     * Staff (any admin role) always get in.
     */
    public function shopAccess(bool $loggedIn, bool $isStaff, ?string $discordId, callable $isMember): string
    {
        if (!$loggedIn) {
            return 'login';
        }
        if ($isStaff) {
            return 'allow';
        }
        if (!$discordId) {
            return 'connect';
        }

        try {
            return $isMember($discordId) ? 'allow' : 'join';
        } catch (DiscordException $e) {
            Log::warning('Wizarr: Discord membership check for the shop failed: ' . $e->getMessage());

            return 'unavailable';
        }
    }

    public function shopAccessForCurrentUser(): string
    {
        $user = Auth::user();

        return $this->shopAccess(
            $user !== null,
            $user !== null && $user->role_id !== null,
            $user ? $this->propertyValue($user, self::PROP_DISCORD_ID) : null,
            fn (string $id) => Cache::remember(
                'wizarr:discord-member:' . $id,
                self::MEMBERSHIP_CACHE_SECONDS,
                fn () => $this->discord()->isMember((string) $this->config('discord_guild_id'), $id),
            ),
        );
    }

    /* ---------------- Discord roles ---------------- */

    /**
     * Product setting: which role an active subscription gives. A list of
     * the server's roles when the bot can read them, a plain field otherwise.
     */
    private function discordRoleField(): array
    {
        $field = [
            'name' => 'discord_role_id',
            'label' => 'Discord role while active',
            'required' => false,
            'description' => 'Given while the subscription is active, taken back when it is suspended or ends. '
                . 'The bot needs "Manage Roles" and must be above this role.',
        ];

        try {
            if ($this->discordConfigured()) {
                $options = [['value' => '', 'label' => '— None —']];
                foreach ($this->discord()->assignableRoles((string) $this->config('discord_guild_id')) as $role) {
                    $options[] = ['value' => $role['id'], 'label' => $role['name']];
                }

                return $field + ['type' => 'select', 'options' => $options];
            }
        } catch (Throwable $e) {
            // fall through to a plain field
        }

        return $field + ['type' => 'text', 'placeholder' => 'Role ID (needs the Discord settings on the server)'];
    }

    /**
     * Gives the member the roles in $wanted and takes back roles this
     * extension gave earlier that are no longer wanted. Roles you gave by
     * hand are never removed: a role they already had is not recorded as ours.
     *
     * @param  string[]  $wanted  roles their active subscriptions give
     * @param  string[]  $grantedBefore  roles this extension gave them earlier
     * @return string[] roles this extension has given them now (store this)
     */
    public function syncDiscordRoles(string $discordId, array $wanted, array $grantedBefore, ?array $serverSettings = null): array
    {
        $guild = (string) (($serverSettings ?? [])['discord_guild_id'] ?? $this->config('discord_guild_id'));
        $discord = $this->discord($serverSettings);
        $wanted = array_values(array_unique(array_filter(array_map('strval', $wanted))));

        $current = $discord->memberRoles($guild, $discordId);
        if ($current === null) {
            return []; // left the server: Discord dropped their roles already
        }

        $granted = [];
        foreach ($wanted as $role) {
            if (in_array($role, $current, true)) {
                if (in_array($role, $grantedBefore, true)) {
                    $granted[] = $role; // still ours
                }

                continue; // given by hand: leave it, and never take it back
            }
            try {
                $discord->addRole($guild, $discordId, $role);
                $granted[] = $role;
            } catch (DiscordException $e) {
                Log::warning('Wizarr: could not give Discord role ' . $role . ': ' . $e->getMessage());
            }
        }

        foreach ($grantedBefore as $role) {
            if (in_array($role, $wanted, true) || !in_array($role, $current, true)) {
                continue;
            }
            try {
                $discord->removeRole($guild, $discordId, $role);
            } catch (DiscordException $e) {
                $granted[] = $role; // still there; try again next hour
                Log::warning('Wizarr: could not take back Discord role ' . $role . ': ' . $e->getMessage());
            }
        }

        return array_values(array_unique($granted));
    }

    /**
     * Brings one customer's roles in line with their subscriptions.
     * $changed/$active: a hook runs before Paymenter saves the new status,
     * so the service being changed counts as $active whatever the database says.
     */
    public function syncRolesForUser(User $user, ?Service $changed = null, ?bool $active = null): void
    {
        if (!$this->discordConfigured()) {
            return;
        }
        $discordId = $this->propertyValue($user, self::PROP_DISCORD_ID);
        if (!$discordId) {
            return;
        }

        $wanted = [];
        foreach ($user->services()->with(['product.settings', 'product.server'])->get() as $service) {
            if ($service->product?->server?->extension !== 'Wizarr') {
                continue;
            }
            $isActive = ($changed !== null && $service->id === $changed->id) ? (bool) $active : $service->status === 'active';
            $role = (string) (ExtensionHelper::settingsToArray($service->product->settings)['discord_role_id'] ?? '');
            if ($isActive && $role !== '') {
                $wanted[] = $role;
            }
        }

        $granted = $this->syncDiscordRoles($discordId, $wanted, $this->grantedRoles($user));
        $user->properties()->updateOrCreate(
            ['key' => self::PROP_DISCORD_ROLES],
            ['name' => 'Discord roles given by Wizarr', 'value' => implode(',', $granted)],
        );
    }

    /** Roles must never break a payment flow: log and let the hourly run fix it. */
    private function syncRolesQuietly(Service $service, bool $active): void
    {
        try {
            if ($service->user instanceof User) {
                $this->syncRolesForUser($service->user, $service, $active);
            }
        } catch (Throwable $e) {
            Log::warning('Wizarr: Discord roles for service ' . $service->id . ' not updated, will retry: ' . $e->getMessage());
        }
    }

    /** Hourly: every customer with a connected Discord account. */
    /**
     * The hourly check of Discord membership, for subscriptions to products
     * with "Requires Discord membership" on a server with "Suspend customers
     * who leave the Discord": warns a customer who left, suspends after the
     * grace hours, and opens the subscription again once they are back
     * (if no invoice is overdue). A Discord error is never read as "left".
     *
     * @param  callable|null  $isMember  fn (array $serverSettings, string $discordId): bool, may throw DiscordException
     * @param  callable|null  $suspend  fn (Service)
     * @param  callable|null  $unsuspend  fn (Service)
     * @param  callable|null  $notify  fn (User, string $kind, array $replace) - kind: warning, suspended, reopened
     * @return array<int, string> what happened, by subscription: warned, suspended, reopened, cleared
     */
    public function reconcileDiscordMembership(?iterable $services = null, ?int $now = null, ?callable $isMember = null, ?callable $suspend = null, ?callable $unsuspend = null, ?callable $notify = null): array
    {
        $now ??= time();
        $services ??= Service::whereIn('status', ['active', 'suspended'])->with(['product.server', 'properties'])->get();
        $isMember ??= fn (array $serverSettings, string $id) => $this->discord($serverSettings)->isMember((string) $serverSettings['discord_guild_id'], $id);
        $suspend ??= function (Service $service) {
            $service->update(['status' => Service::STATUS_SUSPENDED]);
            SuspendJob::dispatch($service, true);
        };
        $unsuspend ??= function (Service $service) {
            $service->update(['status' => 'active']);
            UnsuspendJob::dispatch($service);
        };
        $notify ??= fn ($user, string $kind, array $replace) => $this->notifyDiscordMembership($user, $kind, $replace);

        $known = [];
        $done = [];
        foreach ($services as $service) {
            if (!in_array($service->status ?? '', ['active', 'suspended'], true) || $service->product?->server?->extension !== 'Wizarr') {
                continue;
            }
            $serverSettings = ExtensionHelper::settingsToArray($service->product->server->settings);
            if (!$this->truthy(ExtensionHelper::settingsToArray($service->product->settings)['require_discord'] ?? false)
                || !$this->truthy($serverSettings['discord_suspend_leavers'] ?? false)
                || !$this->discordConfigured($serverSettings)) {
                continue;
            }
            $discordId = $service->user ? $this->propertyValue($service->user, self::PROP_DISCORD_ID) : null;
            if (!$discordId) {
                continue; // never connected Discord: left alone
            }
            $key = $serverSettings['discord_guild_id'] . '|' . $discordId;
            try {
                $member = $known[$key] ??= (bool) $isMember($serverSettings, $discordId);
            } catch (Throwable $e) {
                Log::warning('Wizarr: Discord membership of service ' . $service->id . ' not checked: ' . $e->getMessage());

                continue;
            }

            $properties = ExtensionHelper::getServiceProperties($service);
            $leftAt = (int) ($properties[self::PROP_DISCORD_LEFT] ?? 0);
            $suspendedByUs = !empty($properties[self::PROP_DISCORD_SUSPENDED]);
            $replace = ['service' => $service->product->name ?? ('#' . $service->id), 'hours' => $this->graceHours($serverSettings)];

            if ($member) {
                if ($leftAt) {
                    $this->setProperty($service, self::PROP_DISCORD_LEFT, 'Discord: left at', null);
                    $done[$service->id] = 'cleared';
                }
                if ($suspendedByUs) {
                    $this->setProperty($service, self::PROP_DISCORD_SUSPENDED, 'Discord: suspended for leaving', null);
                    if ($service->status === 'suspended' && !$this->hasOverdueInvoice($service)) {
                        $unsuspend($service);
                        $notify($service->user, 'reopened', $replace);
                        Log::info('Wizarr: service ' . $service->id . ' opened again: the customer is back on the Discord.');
                        $done[$service->id] = 'reopened';
                    }
                }

                continue;
            }

            if ($service->status !== 'active') {
                continue;
            }
            $grace = $this->graceHours($serverSettings) * 3600;
            if (!$leftAt && $grace > 0) {
                $this->setProperty($service, self::PROP_DISCORD_LEFT, 'Discord: left at', (string) $now);
                $notify($service->user, 'warning', $replace);
                Log::info('Wizarr: service ' . $service->id . ': the customer left the Discord; warned.');
                $done[$service->id] = 'warned';

                continue;
            }
            if ($now - ($leftAt ?: $now) >= $grace) {
                $this->setProperty($service, self::PROP_DISCORD_LEFT, 'Discord: left at', null);
                $this->setProperty($service, self::PROP_DISCORD_SUSPENDED, 'Discord: suspended for leaving', '1');
                $suspend($service);
                $notify($service->user, 'suspended', $replace);
                Log::info('Wizarr: service ' . $service->id . ' suspended: the customer left the Discord.');
                $done[$service->id] = 'suspended';
            }
        }

        return $done;
    }

    private function graceHours(array $serverSettings): int
    {
        $hours = trim((string) ($serverSettings['discord_grace_hours'] ?? ''));

        return $hours === '' || !ctype_digit($hours) ? 24 : (int) $hours;
    }

    private function hasOverdueInvoice(Service $service): bool
    {
        try {
            return method_exists($service, 'invoices') && $service->invoices()->where('status', 'pending')->where('due_at', '<', now())->exists();
        } catch (Throwable $e) {
            return true; // unsure: leave it suspended
        }
    }

    /** Tells the customer, in Paymenter (the bell) and by mail. */
    private function notifyDiscordMembership($user, string $kind, array $replace): void
    {
        [$title, $body] = match ($kind) {
            'warning' => [self::t('You have left our Discord'), self::t('Your subscription :service needs you on our Discord. Join again within :hours hours, or your access will be paused.', $replace)],
            'suspended' => [self::t('Your access is paused'), self::t('You are no longer on our Discord, so :service is paused. Join our Discord again and it opens by itself.', $replace)],
            default => [self::t('Your access is back'), self::t('Welcome back on our Discord. :service is open again.', $replace)],
        };
        $url = function_exists('route') ? route('extensions.wizarr.discord') : null;
        try {
            \App\Models\Notification::create(['user_id' => $user->id, 'title' => $title, 'body' => $body, 'url' => $url]);
        } catch (Throwable $e) {
            Log::warning('Wizarr: notification for user ' . $user->id . ' not created: ' . $e->getMessage());
        }
        try {
            $html = '<p>' . e($body) . '</p>' . ($url ? '<p><a href="' . e($url) . '">' . e(self::t('Open the Discord page')) . '</a></p>' : '');
            \Illuminate\Support\Facades\Mail::html($html, fn ($mail) => $mail->to($user->email)->subject($title));
        } catch (Throwable $e) {
            Log::warning('Wizarr: mail to user ' . $user->id . ' not sent: ' . $e->getMessage());
        }
    }

    public function reconcileDiscordRoles(): void
    {
        if (!$this->discordConfigured()) {
            return;
        }
        // Links left behind by deleted users would block that Discord account forever.
        Property::where('model_type', User::class)
            ->whereIn('key', [self::PROP_DISCORD_ID, self::PROP_DISCORD_NAME, self::PROP_DISCORD_ROLES])
            ->whereNotIn('model_id', User::query()->select('id'))
            ->delete();

        foreach (User::whereHas('properties', fn ($query) => $query->where('key', self::PROP_DISCORD_ID))->get() as $user) {
            try {
                $this->syncRolesForUser($user);
            } catch (Throwable $e) {
                Log::warning('Wizarr: Discord roles for user ' . $user->id . ' not updated: ' . $e->getMessage());
            }
        }
    }

    /** @return string[] */
    private function grantedRoles($user): array
    {
        return array_values(array_filter(explode(',', (string) $this->propertyValue($user, self::PROP_DISCORD_ROLES))));
    }

    /* ---------------- Log ind med Discord ---------------- */

    /** GET /wizarr/discord/login (guests): off to Discord, asking for the e-mail too. */
    public function discordLogin()
    {
        abort_unless($this->discordConfigured(), 404);
        Session::put('wizarr.discord.intent', 'login');

        return $this->discordOAuth()->setScopes(['identify', 'email'])->redirect();
    }

    /**
     * What to do with someone who just logged in with Discord.
     *
     * @return array{0: string, 1?: string} ['login'], ['create'] or ['refuse', message]
     */
    public function discordSignInDecision(bool $alreadyLinked, ?string $email, bool $emailVerified, bool $emailTaken, bool $registrationOpen): array
    {
        if ($alreadyLinked) {
            return ['login'];
        }
        if (!$registrationOpen) {
            return ['refuse', self::t('New accounts can\'t be created right now. If you have an account, log in with your email and password.')];
        }
        if (!$email || !$emailVerified) {
            return ['refuse', self::t('Your Discord account has no verified email. Verify it in Discord, or create an account with email and password.')];
        }
        if ($emailTaken) {
            // Never link by e-mail: whoever controls a Discord account would get
            // into the Paymenter account that happens to share its address.
            return ['refuse', self::t('An account with your email already exists. Log in with your password, then connect Discord under Account → Discord.')];
        }

        return ['create'];
    }

    /** Splits a Discord display name into Paymenter's first and last name. */
    public function namesFromDiscord(string $displayName): array
    {
        $parts = preg_split('/\s+/u', trim($displayName), 2) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 255);

        // Never null: Paymenter's Account page can't open for a user without a last name.
        return [$first !== '' ? $first : 'Discord', isset($parts[1]) ? mb_substr($parts[1], 0, 255) : ''];
    }

    private function finishDiscordSignIn($account)
    {
        $discordId = (string) $account->getId();
        $raw = is_array($account->user ?? null) ? $account->user : [];
        $email = $account->getEmail() ? strtolower(trim((string) $account->getEmail())) : null;

        $user = $this->linkedUser($discordId);
        [$action, $message] = $this->discordSignInDecision(
            $user !== null,
            $email,
            (bool) ($raw['verified'] ?? false),
            $email !== null && User::where('email', $email)->exists(),
            !config('settings.registration_disabled'),
        ) + [1 => ''];

        if ($action === 'refuse') {
            return $this->backToLogin($message);
        }

        if ($action === 'create') {
            [$first, $last] = $this->namesFromDiscord((string) ($raw['global_name'] ?? '') ?: (string) $account->getNickname());
            $user = User::create([
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                // Never typed by anyone; "Forgot password" sets a real one if wanted.
                'password' => Hash::make(Str::random(48)),
            ]);
            // Discord has verified the address; asking again would only add a step.
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->properties()->updateOrCreate(['key' => self::PROP_DISCORD_ID], ['name' => 'Discord ID', 'value' => $discordId]);
            $user->properties()->updateOrCreate(['key' => self::PROP_DISCORD_NAME], ['name' => 'Discord', 'value' => (string) ($account->getNickname() ?: $discordId)]);
            Log::info('Wizarr: created Paymenter user ' . $user->id . ' from Discord account ' . $discordId . '.');
            try {
                $this->syncRolesForUser($user);
            } catch (Throwable $e) {
                Log::warning('Wizarr: Discord roles for new user ' . $user->id . ' not updated, will retry: ' . $e->getMessage());
            }
        }

        // Same as Paymenter's own social login, two-factor included.
        if ($user->tfa_secret) {
            Session::put('2fa', ['user_id' => $user->id, 'remember' => true, 'expires' => now()->addMinutes(5)]);

            return redirect()->route('2fa');
        }
        (new LoginAction)->execute($user, true);

        return redirect()->intended(route('home'));
    }

    /** The existing Paymenter user linked to this Discord account, if any. */
    private function linkedUser(string $discordId): ?User
    {
        $property = Property::where('key', self::PROP_DISCORD_ID)
            ->where('value', $discordId)
            ->where('model_type', User::class)
            ->whereIn('model_id', User::query()->select('id'))
            ->first();

        return $property ? User::find($property->model_id) : null;
    }

    private function backToLogin(string $message)
    {
        return redirect()->route('login')->with('notification', ['message' => $message, 'type' => 'error']);
    }

    /** "auth.login" hook: the button under the login form. */
    public function discordLoginButton(?string $url = null, ?string $tosUrl = null, ?string $label = null): string
    {
        $label ??= self::t('Log in or create an account with Discord');
        $url ??= route('extensions.wizarr.discord.login');
        $tosUrl ??= (string) config('settings.tos');
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $tos = $tosUrl !== ''
            ? '<p style="margin:.5rem 0 0;font-size:.75rem;text-align:center;color:hsl(var(--color-muted))">'
                . $e(self::t('By creating an account with Discord, you accept our')) . ' '
                . '<a href="' . $e($tosUrl) . '" target="_blank" rel="noopener" style="color:hsl(var(--color-primary))">' . $e(self::t('terms')) . '</a>.</p>'
            : '';

        return '<div id="wizarr-discord-login" style="margin-top:1rem">'
            . '<div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1rem;font-size:.8rem;color:hsl(var(--color-muted))">'
            . '<span style="flex:1;height:1px;background:hsl(var(--color-neutral))"></span>' . $e(self::t('or')) . '<span style="flex:1;height:1px;background:hsl(var(--color-neutral))"></span></div>'
            . '<a href="' . $e($url) . '" style="box-sizing:border-box;display:flex;align-items:center;justify-content:center;gap:.5rem;width:100%;padding:.625rem 1rem;'
            . 'border-radius:.375rem;background:#4752C4;color:#fff;font-weight:600;font-size:.875rem;text-decoration:none">'
            . '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.317 4.37a19.79 19.79 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.74 19.74 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028c.462-.63.874-1.295 1.226-1.994a.076.076 0 0 0-.041-.106 13.1 13.1 0 0 1-1.872-.892.077.077 0 0 1-.008-.128c.126-.094.252-.192.372-.291a.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.099.246.198.373.292a.077.077 0 0 1-.006.127 12.3 12.3 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.84 19.84 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/></svg>'
            . $e($label) . '</a>'
            . $tos
            . '</div>';
    }

    private function backToDiscordPage(string $message, string $type)
    {
        return redirect()->route('extensions.wizarr.discord')->with('notification', ['message' => $message, 'type' => $type]);
    }

    private function discordOAuth()
    {
        return Socialite::buildProvider(DiscordProvider::class, [
            'client_id' => (string) $this->config('discord_client_id'),
            'client_secret' => (string) $this->config('discord_client_secret'),
            'redirect' => '/wizarr/discord/callback',
        ]);
    }

    private const DISCORD_SETTINGS = ['discord_guild_id', 'discord_client_id', 'discord_client_secret', 'discord_bot_token'];

    /** All settings Discord needs are filled in. Reads $settings, or this server's own. */
    private function discordConfigured(?array $settings = null): bool
    {
        foreach (self::DISCORD_SETTINGS as $key) {
            $value = $settings === null ? $this->config($key) : ($settings[$key] ?? null);
            if (trim((string) $value) === '') {
                return false;
            }
        }

        return true;
    }

    private function anyDiscordSetting(): bool
    {
        foreach (self::DISCORD_SETTINGS as $key) {
            if (trim((string) $this->config($key)) !== '') {
                return true;
            }
        }

        return false;
    }

    private ?DiscordClient $discordClient = null;

    private function discord(?array $settings = null): DiscordClient
    {
        if ($settings !== null) {
            return new DiscordClient((string) $settings['discord_bot_token'], $this->discordApiUrl());
        }

        return $this->discordClient ??= new DiscordClient((string) $this->config('discord_bot_token'), $this->discordApiUrl());
    }

    /** Overridable so the tests can point at a stub. */
    protected function discordApiUrl(): string
    {
        return 'https://discord.com/api/v10';
    }

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
                } elseif (str_starts_with((string) $name, $prop . ':') && ctype_digit(substr((string) $name, strlen($prop) + 1))) {
                    $keys[substr((string) $name, strlen($prop) + 1)] = true;
                }
            }
        }
        $keys = array_map('strval', array_keys($keys));
        usort($keys, fn ($a, $b) => ($a === '' ? -1 : (int) $a) <=> ($b === '' ? -1 : (int) $b));

        return $keys;
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
        if (in_array($choice, ['all', 'both', 'begge', 'alle'], true) || !$product) {
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

        return $label !== '' ? $label : ($this->grant === '' ? '' : 'server ' . $this->grant);
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
        $urls = Cache::get('paywarr:server-urls');
        if (!is_array($urls)) {
            $urls = array_map(fn ($server) => $server['url'], $this->serverInfo());
            Cache::put('paywarr:server-urls', $urls, self::SERVER_URLS_SECONDS);
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
        $invitations = [];
        foreach ($wanted as $id) {
            $grant = $covered[$id] ?? (string) $id;
            if (isset($invitations[$grant])) {
                continue;
            }
            $invitations[$grant] = $this->withGrant($grant, function () use ($service, $settings, $properties) {
                $view = $this->grantView($properties);
                $invitation = $this->findInvitation($view);
                if ($invitation && ($invitation['status'] ?? '') === 'used') {
                    $this->rememberUsers($service, $this->resolveUsers($view, $invitation));
                }
                if ($invitation || !empty($view[self::PROP_USERS])) {
                    return ($invitation ?? []) + ['has_user' => !empty($view[self::PROP_USERS]) || ($invitation['status'] ?? '') === 'used'];
                }

                return $this->issueInvitation($service, $settings);
            });
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

        $libraryIds = $this->idList($settings['library_ids'] ?? null);
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
            foreach ($all as $user) {
                if ((int) $user['id'] === $known['id'] && (string) $user['username'] === $known['username']) {
                    $found[$known['id']] = $this->userRow($user);
                }
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

    /* ------------------------------------------------------------------
     | Admin: Paywarr → Customers
     * ----------------------------------------------------------------- */

    /** This extension, set up for the server of $service. */
    public function forService(Service $service): static
    {
        return $this->forServiceServer($service);
    }

    /**
     * What the admin page shows for a subscription, from what is stored (no
     * calls to Wizarr or Silo): per media server the user(s) or the
     * invitation, the trial, Discord and whether a new link is allowed.
     */
    public function adminSummary(Service $service): array
    {
        $properties = ExtensionHelper::getServiceProperties($service);
        $settings = $this->trialSettings(ExtensionHelper::settingsToArray($service->product?->settings ?? []), $properties);
        $grants = [];
        foreach ($this->grantKeys($properties) as $grant) {
            $grants[] = $this->withGrant($grant, function () use ($properties) {
                $view = $this->grantView($properties);

                return [
                    'server' => $this->grantLabel($properties),
                    'users' => array_column($this->parseUsers((string) ($view[self::PROP_USERS] ?? '')), 'username'),
                    'invite_url' => (string) ($view[self::PROP_INVITE_URL] ?? ''),
                    'invite_expires' => (string) ($view[self::PROP_INVITE_EXPIRES] ?? ''),
                ];
            });
        }
        $trialDays = (int) ($settings['trial_days'] ?? 0);
        $time = fn ($value) => $value ? (strtotime((string) $value) ?: (ctype_digit((string) $value) ? (int) $value : null)) : null;

        return [
            'grants' => $grants,
            'trial' => $trialDays > 0 ? [
                'days' => $trialDays,
                'given' => (int) ($properties[self::PROP_TRIAL_DAYS] ?? 0) > 0,
                'started' => $time($properties[self::PROP_TRIAL_STARTED] ?? null),
                'ends' => $time($properties[self::PROP_TRIAL_ENDS] ?? null),
                'ended' => $time($properties[self::PROP_TRIAL_ENDED] ?? null),
                'converted' => !empty($properties[self::PROP_TRIAL_CONVERTED]),
                'forgiven' => !empty($properties[self::PROP_TRIAL_FORGIVEN]),
            ] : null,
            'new_link' => !empty($properties[self::PROP_NEW_LINK]),
            'discord' => $service->user ? (string) ($this->propertyValue($service->user, self::PROP_DISCORD_NAME) ?? '') : '',
            'discord_id' => $service->user ? (string) ($this->propertyValue($service->user, self::PROP_DISCORD_ID) ?? '') : '',
            'discord_left' => (int) ($properties[self::PROP_DISCORD_LEFT] ?? 0) ?: null,
        ];
    }

    /**
     * A new invitation for every media server of the subscription where the
     * customer has no user yet (the old link stops working), mailed to the
     * customer when $mail. Returns the new links.
     */
    public function adminNewInvitation(Service $service, bool $mail = true): array
    {
        $settings = ExtensionHelper::settingsToArray($service->product->settings);
        $properties = ExtensionHelper::getServiceProperties($service);
        $made = [];
        $grants = $this->grantKeys($properties);
        if (!$grants) {
            $made = array_filter($this->syncGrants($service, $settings, $properties), fn ($i) => ($i['code'] ?? '') !== '' && empty($i['has_user']));
        }
        foreach ($grants as $grant) {
            $fresh = $this->withGrant($grant, function () use ($service, $settings, $properties) {
                $view = $this->grantView($properties);
                if (!empty($view[self::PROP_USERS])) {
                    return null; // has a user: "Reset user" first
                }
                $invitation = $this->findInvitation($view);
                if ($invitation) {
                    $this->deleteInvitationQuietly((int) $invitation['id']);
                }

                return $this->issueInvitation($service, $settings);
            });
            if ($fresh !== null) {
                $made[$grant] = $fresh;
            }
        }
        if (!$made) {
            throw new DisplayException(self::t('The customer already has a user on every server. Use "Reset user" first.'));
        }
        if ($mail) {
            $this->notifyNewInvitation($service, $this->mailDataFor($made, $settings));
        }
        $this->forgetCustomerCaches($service);
        Log::info('Wizarr: new invitation for service ' . $service->id . ' made in admin' . ($mail ? ' and mailed.' : '.'));

        return array_values(array_map(fn ($i) => (string) ($i['url'] ?? ''), $made));
    }

    /** The customer may get a new link once (on their subscription), even after using theirs. */
    public function adminAllowNewLink(Service $service, bool $allowed = true): void
    {
        $this->setProperty($service, self::PROP_NEW_LINK, 'Wizarr: new link allowed in admin', $allowed ? '1' : null);
    }

    /**
     * Asks Wizarr or Silo again: records the customer's user(s), moves a
     * trial along, and brings the Discord role and Seerr in line with the
     * subscription's status. Returns the usernames found.
     */
    public function adminSync(Service $service): array
    {
        $properties = ExtensionHelper::getServiceProperties($service);
        $found = [];
        $spent = [];
        foreach ($this->grantKeys($properties) as $grant) {
            $this->withGrant($grant, function () use ($service, $properties, &$found, &$spent) {
                $view = $this->grantView($properties);
                $invitation = $this->findInvitation($view);
                $users = $this->resolveUsers($view, $invitation);
                if ($users) {
                    $this->rememberUsers($service, $users);
                } elseif (($invitation['status'] ?? '') === 'used') {
                    $spent[] = $this->grantLabel($properties) ?: 'the media server';
                }
                foreach ($users as $user) {
                    $found[] = $user['username'];
                }
            });
        }
        $this->processTrialQuietly($service);
        $status = (string) $service->status;
        $this->syncRolesQuietly($service, active: $status === 'active');
        $this->syncSeerrQuietly($service, $status === 'active' ? 'active' : ($status === 'suspended' ? 'suspended' : 'ended'));
        $this->forgetCustomerCaches($service);
        if ($spent && !$found) {
            throw new DisplayException('The link on ' . implode(', ', $spent) . ' was used, but no user was made (e.g. a Plex share that failed). Use "Allow a new link" or "New invitation".');
        }

        return $found;
    }

    /**
     * Forgets the customer's media server user(s), so they start over with a
     * new invitation (mailed when $mail). The user stays on the media
     * server; remove it there if it should go.
     */
    public function adminResetUser(Service $service, bool $mail = true): array
    {
        $properties = ExtensionHelper::getServiceProperties($service);
        foreach ($this->grantKeys($properties) as $grant) {
            $this->withGrant($grant, fn () => $this->rememberUsers($service, []));
        }
        Log::info('Wizarr: the media server user(s) of service ' . $service->id . ' were forgotten in admin.');

        return $this->adminNewInvitation($service, $mail);
    }

    /**
     * Sets the trial's start (shown only) and end. An end in the past ends
     * the trial now; a later end reopens a trial that had ended.
     *
     * @return string 'set', 'ended' or 'reopened'
     */
    public function adminSetTrial(Service $service, ?int $start, int $end, ?int $now = null): string
    {
        $now ??= time();
        $properties = ExtensionHelper::getServiceProperties($service);
        $settings = $this->trialSettings(ExtensionHelper::settingsToArray($service->product->settings), $properties);
        if (!$this->isTrial($settings)) {
            throw new DisplayException(self::t('This subscription is not a trial. Give it trial days first.'));
        }
        if ($start !== null) {
            $this->setProperty($service, self::PROP_TRIAL_STARTED, 'Trial started', date(DATE_ATOM, $start));
        }
        $this->setProperty($service, self::PROP_TRIAL_ENDS, 'Trial ends', date(DATE_ATOM, $end));
        $this->setProperty($service, self::PROP_TRIAL_CONVERTED, '', null);

        if ($end > $now && !empty($properties[self::PROP_TRIAL_ENDED])) {
            $this->reopenTrial($service, $settings, ExtensionHelper::getServiceProperties($service));
            $result = 'reopened';
        } elseif ($end <= $now && $service->status === 'active') {
            $result = $this->processTrial($service, $settings, ExtensionHelper::getServiceProperties($service), $now) === 'ended' ? 'ended' : 'set';
        } else {
            $result = 'set';
        }
        $this->forgetCustomerCaches($service);
        Log::info('Wizarr: trial of service ' . $service->id . ' set in admin to end ' . date(DATE_ATOM, $end) . ' (' . $result . ').');

        return $result;
    }

    /** Adds $days to the trial, from its end (or from now, when it has ended). */
    public function adminExtendTrial(Service $service, int $days, ?int $now = null): string
    {
        $now ??= time();
        $properties = ExtensionHelper::getServiceProperties($service);
        $ends = strtotime((string) ($properties[self::PROP_TRIAL_ENDS] ?? '')) ?: $now;

        return $this->adminSetTrial($service, null, max($ends, $now) + max(1, $days) * 86400, $now);
    }

    /** Starts the trial again, with its full number of days from now. */
    public function adminRestartTrial(Service $service, ?int $now = null): string
    {
        $now ??= time();
        $settings = $this->trialSettings(ExtensionHelper::settingsToArray($service->product->settings), ExtensionHelper::getServiceProperties($service));

        return $this->adminSetTrial($service, $now, $now + (int) ($settings['trial_days'] ?? 0) * 86400, $now);
    }

    /** Ends the trial now: the user is disabled, as at a normal end. */
    public function adminEndTrial(Service $service, ?int $now = null): string
    {
        $now ??= time();

        return $this->adminSetTrial($service, null, $now, $now);
    }

    /** This trial no longer counts: the customer may take a trial on this server again. */
    public function adminAllowNewTrial(Service $service, bool $allowed = true): void
    {
        $this->setProperty($service, self::PROP_TRIAL_FORGIVEN, 'Trial forgiven in admin', $allowed ? '1' : null);
    }

    /**
     * Makes the subscription a trial of $days days, e.g. a free week for a
     * friend. Its clock starts now if the customer has a user, else when
     * they create it. At the end it closes, as any trial.
     */
    public function adminGiveTrialDays(Service $service, int $days, ?int $now = null): string
    {
        $now ??= time();
        $days = max(1, $days);
        $this->setProperty($service, self::PROP_TRIAL_DAYS, 'Trial days given in admin', (string) $days);
        $this->setProperty($service, self::PROP_TRIAL_CONVERTED, '', null);
        $properties = ExtensionHelper::getServiceProperties($service);
        if ($this->allUsers($properties, resolve: false)) {
            return $this->adminSetTrial($service, $now, $now + $days * 86400, $now);
        }
        $this->setProperty($service, self::PROP_TRIAL_ENDS, '', null);

        return 'waiting';
    }

    /** A trial that had ended is open again: users switched back on (or a new invitation on Plex). */
    private function reopenTrial(Service $service, array $settings, array $properties): void
    {
        $this->setProperty($service, self::PROP_TRIAL_ENDED, '', null);
        foreach ($this->grantKeys($properties) as $grant) {
            $this->withGrant($grant, fn () => $this->unsuspendGrant($service, $settings, $properties));
        }
        if ($service->status !== 'active') {
            $service->status = 'active';
            if (method_exists($service, 'save')) {
                $service->save();
            }
            $product = $service->product;
            if ($product && ($product->stock ?? null) !== null && method_exists($product, 'decrement')) {
                $product->decrement('stock', $service->quantity ?? 1);
            }
        }
        $this->syncRolesQuietly($service, active: true);
        $this->syncSeerrQuietly($service, 'active');
    }

    /** The customer's cached menu links and box, so a change shows at once. */
    private function forgetCustomerCaches(Service $service): void
    {
        $user = $service->user_id ?? $service->user?->id ?? null;
        if ($user === null) {
            return;
        }
        foreach (['wizarr:nav-watch:', 'wizarr:can-request:', 'wizarr:can-report:'] as $key) {
            Cache::forget($key . $user);
        }
    }

    /* ------------------------------------------------------------------
     | Small helpers
     * ----------------------------------------------------------------- */

    private function client(): WizarrClient|SiloClient
    {
        if ($this->config('backend') === 'silo') {
            return $this->client ??= new SiloClient(
                (string) $this->config('host'),
                (string) $this->config('api_key'),
                (string) $this->config('public_url'),
            );
        }

        return $this->client ??= new WizarrClient(
            (string) $this->config('host'),
            (string) $this->config('api_key'),
        );
    }

    private function deleteUserQuietly(int $id): void
    {
        try {
            $this->client()->deleteUser($id);
        } catch (WizarrException $e) {
            if (!$e->isNotFound()) {
                throw $e;
            }
        }
    }

    private function deleteInvitationQuietly(int $id): void
    {
        try {
            $this->client()->deleteInvitation($id);
        } catch (WizarrException $e) {
            if (!$e->isNotFound()) {
                throw $e;
            }
        }
    }

    private function notifyNewInvitation(Service $service, array $data): void
    {
        // The invitation exists whether or not the mail goes out, and the link
        // is in the client area too, so a mail problem must not fail the job.
        try {
            NotificationHelper::serverCreatedNotification($service->user, $service, $data);
        } catch (Throwable $e) {
            Log::warning('Wizarr: new invitation for service ' . $service->id . ' could not be mailed: ' . $e->getMessage());
        }
    }

    private function mailData(array $invitation, array $settings, bool $alreadyUsed = false): array
    {
        $lifetime = $this->linkLifetime($settings);
        $loginUrl = trim((string) ($settings['login_url'] ?? ''));

        return [
            'invite_url' => $alreadyUsed && $loginUrl !== ''
                ? $loginUrl
                : $this->absoluteUrl($invitation['url'] ?? null, (string) ($invitation['code'] ?? '')),
            'invite_code' => (string) ($invitation['code'] ?? ''),
            'invite_valid_for' => $lifetime === null ? '' : ($lifetime === 1 ? self::t('1 day') : self::t(':count days', ['count' => $lifetime])),
            'login_url' => $loginUrl,
        ];
    }

    private function linkLifetime(array $settings): ?int
    {
        $raw = $settings['link_lifetime'] ?? 7;
        if ($raw === 'never') {
            return null;
        }

        // Anything Wizarr would not accept falls back to a week rather than
        // silently becoming a link that never expires.
        return in_array((int) $raw, self::LINK_LIFETIMES, true) ? (int) $raw : 7;
    }

    /**
     * Product settings arrive as an array (multi-select), a JSON string, or
     * "1,2" typed by hand, depending on how the field was rendered.
     *
     * @return array<int, int>
     */
    private function idList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : preg_split('/[\s,;]+/', $value);
        }
        if (is_int($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $item) {
            if (is_scalar($item) && ctype_digit((string) $item) && (int) $item > 0) {
                $ids[] = (int) $item;
            }
        }

        return array_values(array_unique($ids));
    }

    private function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
    }

    /**
     * Client area for a customer who has a user. Built from what is stored on
     * the service, so it costs no call to Wizarr.
     *
     * @param  array<int, array{id: int, username: string, type: string}>  $users
     */
    private function activeActions(array $users, array $watch, array $properties = [], ?array $resetButtons = null): array
    {
        $actions = [$this->statusField(self::t('Active'))];

        $endsAt = strtotime((string) ($properties[self::PROP_TRIAL_ENDS] ?? '')) ?: null;
        if ($endsAt !== null) {
            $actions[] = ['type' => 'text', 'label' => self::t('Trial'), 'text' => self::t(':remaining – ends :date', ['remaining' => $this->remainingText($endsAt - time()), 'date' => date(self::t('M j, Y \a\t H:i'), $endsAt)])];
            $watch[] = ['type' => 'button', 'label' => self::t('Continue with a subscription'), 'url' => $this->shopUrl()];
        }

        $quota = (string) ($properties[self::PROP_SEERR_QUOTA] ?? '');
        if (!empty($properties[self::PROP_SEERR_USER]) && $quota !== '' && $quota !== 'default') {
            [$limit, $days] = array_pad(explode('/', $quota, 2), 2, '');
            $actions[] = ['type' => 'text', 'label' => self::t('Requests'), 'text' => (int) $limit === 0
                ? self::t('Unlimited')
                : self::t(':count movies and :count TV seasons every :days days', ['count' => (int) $limit, 'days' => (int) $days])];
        }

        // Silo's own requests, with the package bought.
        $silo = (string) ($properties[self::PROP_SILO_REQUESTS] ?? '');
        if ($silo !== '' && $silo !== 'off') {
            [$limit, $days] = array_pad(explode('/', $silo, 2), 2, '');
            $actions[] = ['type' => 'text', 'label' => self::t('Requests'), 'text' => match (true) {
                $silo === 'default' => self::t('Included, in Silo'),
                (int) $limit === 0 => self::t('Unlimited'),
                default => self::t(':count requests every :days days, in Silo', ['count' => (int) $limit, 'days' => (int) $days]),
            }];
        }

        $seerrUrl = trim((string) $this->config($this->seerrPrefix((string) ($properties[self::PROP_SEERR_INSTANCE] ?? '') ?: '1') . 'public_url'));
        if (!empty($properties[self::PROP_SEERR_USER]) && $seerrUrl !== '') {
            $watch[] = ['type' => 'button', 'label' => self::t('Request movies and shows'), 'url' => $seerrUrl, 'target' => '_blank'];
        }

        // One invitation can create the same name on several servers.
        $names = array_values(array_unique(array_column($users, 'username')));
        if ($names) {
            $actions[] = ['type' => 'text', 'label' => self::t('Username'), 'text' => implode(', ', $names)];
        }

        if ($resetButtons !== null) {
            $actions = array_merge($actions, $resetButtons);
        } else {
            foreach ($users as $user) {
                // An unknown type (stored by 1.0.0) is offered; resetPassword checks it live.
                if ($user['type'] === '' || in_array($user['type'], self::RESETTABLE_SERVERS, true)) {
                    $actions[] = ['type' => 'button', 'label' => self::t('Reset password'), 'function' => 'resetPassword'];

                    break;
                }
            }
        }

        return array_merge($actions, $watch);
    }

    /** "Status: ..." or, with several servers, "Emby: ...". */
    private function statusField(string $text, string $server = ''): array
    {
        return ['type' => 'text', 'label' => $server !== '' ? $server : 'Status', 'text' => $text];
    }

    private function inviteButton(string $url, string $server = ''): array
    {
        $label = $server !== '' ? self::t('Create your user on :server', ['server' => $server]) : self::t('Create your user');

        return ['type' => 'button', 'label' => $label, 'url' => $url, 'target' => '_blank'];
    }

    /* ------------------------------------------------------------------
     | Translations
     * ----------------------------------------------------------------- */

    /** @var array<string, array<string, string>> loaded lang/<locale>.json files */
    private static array $translations = [];

    /**
     * The text in the language Paymenter is using for this request (the
     * visitor's language switch, else the site's default language), from
     * lang/<locale>.json next to this file. Falls back to the English text,
     * so a language without a file shows English. :name placeholders are
     * filled in from $replace.
     */
    public static function t(string $text, array $replace = []): string
    {
        $locale = function_exists('app') ? (string) app()->getLocale() : 'en';
        foreach (array_unique([$locale, strtolower(strtok($locale, '_-') ?: $locale)]) as $candidate) {
            if (!preg_match('/^[A-Za-z_-]{2,10}$/', $candidate)) {
                continue;
            }
            if (!array_key_exists($candidate, self::$translations)) {
                $file = __DIR__ . '/lang/' . $candidate . '.json';
                $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
                self::$translations[$candidate] = is_array($decoded) ? $decoded : [];
            }
            if (isset(self::$translations[$candidate][$text]) && self::$translations[$candidate][$text] !== '') {
                $text = (string) self::$translations[$candidate][$text];

                break;
            }
        }

        // One pass (strtr tries the longest name first), so a value that
        // itself contains ":something", like a Discord name, is left alone.
        $pairs = [];
        foreach ($replace as $name => $value) {
            $pairs[':' . $name] = (string) $value;
        }

        return $pairs ? strtr($text, $pairs) : $text;
    }

    /** One property of a service or user, or null. */
    private function propertyValue($model, string $key): ?string
    {
        $value = $model->properties()->where('key', $key)->value('value');

        return $value === null ? null : (string) $value;
    }

    private function setProperty(Service $service, string $key, string $name, ?string $value): void
    {
        if ($value === null || $value === '') {
            $service->properties()->where('key', $key)->delete();
        } else {
            $service->properties()->updateOrCreate(['key' => $key], ['name' => $name, 'value' => $value]);
        }

        // Paymenter reads $service->properties once per request; drop that copy
        // so the next read (ExtensionHelper::getServiceProperties) sees this write.
        if (method_exists($service, 'unsetRelation')) {
            $service->unsetRelation('properties');
        }
    }
}
