<?php

namespace Paymenter\Extensions\Servers\Wizarr;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Events\Service\Created as ServiceCreated;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Models\Property;
use App\Models\Service;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
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
    version: '1.9.0',
    author: 'Paywarr',
    url: 'https://github.com/wizarrrr/wizarr',
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
    private const RESETTABLE_SERVERS = ['jellyfin', 'emby'];

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

    /** Paymenter configurable option (its environment variable) that buys the Seerr add-on. */
    public const SEERR_OPTION = 'seerr';

    /** On the Paymenter user: Discord roles this extension gave them (and may take back). */
    private const PROP_DISCORD_ROLES = 'discord_roles_granted';

    /** How long a "member / not member" answer is reused while browsing the shop. */
    private const MEMBERSHIP_CACHE_SECONDS = 600;

    private ?WizarrClient $client = null;

    /* ------------------------------------------------------------------
     | Configuration
     * ----------------------------------------------------------------- */

    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'host',
                'label' => 'Wizarr URL (internal)',
                'type' => 'text',
                'required' => true,
                'placeholder' => 'http://192.168.1.10:5690',
                'description' => 'How Paymenter reaches Wizarr. On Unraid: http://<server IP>:<Wizarr port>. Never shown to customers.',
            ],
            [
                'name' => 'public_url',
                'label' => 'Wizarr URL (public)',
                'type' => 'text',
                'required' => true,
                'placeholder' => 'https://join.example.com',
                'description' => 'The address your customers open. Wizarr only returns /j/<code>; this turns it into a link.',
            ],
            [
                'name' => 'api_key',
                'label' => 'API key',
                'type' => 'password',
                'required' => true,
                'encrypted' => true,
                'description' => 'Wizarr -> Settings -> API Keys.',
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

    public function testConfig(): bool|string
    {
        try {
            $client = $this->client();
            $client->status();

            $verified = array_filter($client->servers(), fn ($server) => !empty($server['verified']));
            if (empty($verified)) {
                return 'Connected, but Wizarr has no verified media server yet. Add and verify one in Wizarr first.';
            }

            if ($this->seerrConfigured()) {
                $seerr = $this->seerr();
                $seerr->version();
                $seerr->mediaServerUsers(); // needs the owner's key and a working Jellyfin connection in Seerr
            } elseif (trim((string) $this->config('seerr_url')) !== '' || trim((string) $this->config('seerr_api_key')) !== '') {
                return 'Wizarr works, but the Seerr settings are incomplete: fill in the URL and the API key, or neither.';
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
                'description' => 'Which server(s) this product gives access to.',
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
                'description' => 'Optional. Shown as a button in the client area once the user exists.',
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
                'name' => 'seerr_included',
                'label' => 'Seerr included',
                'type' => 'checkbox',
                'description' => 'Every subscription on this product gets Seerr. Leave off to sell it as an add-on instead '
                    . '(a configurable option with environment variable "seerr", see the README).',
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
                $this->retryPending();
                $this->reconcileDiscordRoles();
                $this->reconcileSeerr();
                $this->processTrials();
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
        // Already has a user (a retried job, or the user came over from a trial).
        if (!empty($properties[self::PROP_USERS])) {
            return $this->accessMailData($settings);
        }

        // Bought after a trial: keep the same media server user instead of
        // inviting them again, so their history and "continue watching" stay.
        if (!$this->isTrial($settings) && $this->adoptTrialUserQuietly($service)) {
            $this->syncRolesQuietly($service, active: true);
            $this->syncSeerrQuietly($service, 'active');

            return $this->accessMailData($settings);
        }

        $invitation = $this->findInvitation($properties);

        // "Create" pressed twice, or a retried job: hand back what exists
        // instead of minting a second seat for one payment.
        if ($invitation && ($invitation['status'] ?? '') === 'pending') {
            return $this->mailData($invitation, $settings);
        }
        if ($invitation && ($invitation['status'] ?? '') === 'used') {
            $this->rememberUsers($service, $this->resolveUsers($properties, $invitation));
            $this->syncSeerrQuietly($service, 'active');

            return $this->mailData($invitation, $settings, alreadyUsed: true);
        }

        $data = $this->mailData($this->issueInvitation($service, $settings), $settings);
        $this->syncRolesQuietly($service, active: true);
        // Usually nothing yet: Seerr follows once the customer has created their user.
        $this->syncSeerrQuietly($service, 'active');

        return $data;
    }

    /**
     * Called when an invoice is overdue. Access is paused, not destroyed, where
     * the media server can do that (Jellyfin, Emby). Plex has no "disabled"
     * state: Wizarr removes the share, and unsuspend has to invite again.
     */
    public function suspendServer(Service $service, $settings, $properties)
    {
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
        $invitation = $this->findInvitation($properties);

        // Never redeemed: withdraw the link so it cannot be used while suspended.
        if ($invitation && ($invitation['status'] ?? '') !== 'used') {
            $this->deleteInvitationQuietly((int) $invitation['id']);
            $this->forgetInvitation($service);

            return [];
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

        return [];
    }

    /**
     * Called when a suspended service is paid. If the user can simply be
     * switched back on, that is all. Otherwise the customer gets a new
     * invitation - Paymenter sends no mail on unsuspend, so we do.
     */
    public function unsuspendServer(Service $service, $settings, $properties)
    {
        // Paid again: an unfinished suspension must not be retried any more.
        $this->setProperty($service, self::PROP_PENDING, '', null);
        $this->syncRolesQuietly($service, active: true);

        $result = $this->unsuspendNow($service, $settings, $properties);
        $this->syncSeerrQuietly($service, 'active');

        return $result;
    }

    private function unsuspendNow(Service $service, array $settings, array $properties): bool
    {
        $invitation = $this->findInvitation($properties);

        if ($invitation && ($invitation['status'] ?? '') === 'pending') {
            return true; // an unused, still valid link is all the customer needs
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
            return true;
        }

        if ($invitation) {
            $this->deleteInvitationQuietly((int) $invitation['id']);
        }
        $fresh = $this->issueInvitation($service, $settings);
        $this->notifyNewInvitation($service, $this->mailData($fresh, $settings));

        return true;
    }

    /**
     * Called on cancellation, or when a suspended service stays unpaid.
     */
    public function terminateServer(Service $service, $settings, $properties)
    {
        $this->setProperty($service, self::PROP_PENDING, 'Wizarr: unfinished action', 'terminate');
        $result = $this->terminateNow($service, $properties);
        $this->setProperty($service, self::PROP_PENDING, '', null);
        $this->syncRolesQuietly($service, active: false);
        $this->syncSeerrQuietly($service, 'ended');

        return $result;
    }

    /**
     * Called after a paid upgrade or downgrade: another product or other
     * options (such as the Seerr add-on). Access on the media server is
     * unchanged; the Discord role and Seerr follow the new choice.
     */
    public function upgradeServer(Service $service, $settings, $properties)
    {
        $this->syncRolesQuietly($service, active: true);
        $this->syncSeerrQuietly($service, 'active');

        return true;
    }

    private function terminateNow(Service $service, array $properties): array
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

        return [];
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
        $loginUrl = trim((string) ($settings['login_url'] ?? ''));
        $watch = $loginUrl === '' ? [] : [[
            'type' => 'button',
            'label' => self::t('Open the media library'),
            'url' => $loginUrl,
            'target' => '_blank',
        ]];

        if (!empty($properties[self::PROP_USERS])) {
            // A trial whose user was recorded before its clock existed (or before
            // the hourly run): start the clock now, so the end date shows at once.
            if ($this->isTrial($settings) && empty($properties[self::PROP_TRIAL_ENDS])) {
                $this->processTrialQuietly($service);
                $properties = ExtensionHelper::getServiceProperties($service);
            }

            return $this->activeActions($this->parseUsers((string) $properties[self::PROP_USERS]), $watch, $properties);
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
                // Only bookkeeping; the page should render either way.
            }

            return $this->activeActions($users, $watch, $properties);
        }

        if ($status === 'pending') {
            return [
                $this->statusField(self::t('Waiting for you to create your user')),
                $this->inviteButton($this->absoluteUrl($invitation['url'] ?? null, (string) $invitation['code'])),
            ];
        }

        // Expired before it was used, or removed in Wizarr.
        return [
            $this->statusField(self::t('Your invitation link has expired')),
            ['type' => 'button', 'label' => self::t('Get a new invitation link'), 'function' => 'renewInvitation'],
        ];
    }

    /**
     * Button handler: Paymenter redirects the customer to whatever URL this returns.
     */
    public function renewInvitation(Service $service, $settings, $properties): string
    {
        $invitation = $this->findInvitation($properties);
        $status = $invitation['status'] ?? 'missing';

        if ($status === 'pending') {
            return $this->absoluteUrl($invitation['url'] ?? null, (string) $invitation['code']);
        }

        // Already has a user: a new link would be a second seat on one subscription.
        if ($status === 'used' || !empty($properties[self::PROP_USERS])) {
            $loginUrl = trim((string) ($settings['login_url'] ?? ''));

            return $loginUrl !== '' ? $loginUrl : rtrim((string) $this->config('public_url'), '/');
        }

        if ($invitation) {
            $this->deleteInvitationQuietly((int) $invitation['id']);
        }

        return $this->absoluteUrl(null, (string) $this->issueInvitation($service, $settings)['code']);
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

        if (!empty($properties[self::PROP_USERS])) {
            // Has a user. Only a running trial still has something to say.
            $trialEnds = strtotime((string) ($properties[self::PROP_TRIAL_ENDS] ?? '')) ?: null;
            if ($trialEnds !== null && $trialEnds > $now) {
                return ['state' => 'trial', 'key' => 'service-' . $service->id, 'inviteUrl' => '', 'remaining' => $this->remainingText($trialEnds - $now)];
            }

            return null;
        }

        $url = (string) ($properties[self::PROP_INVITE_URL] ?? '');
        $key = 'service-' . $service->id;

        if ($url === '') {
            // Paid, but the queue has not created the invitation yet. Only for a
            // while: if it never comes, the banner should not spin forever.
            $since = $service->updated_at?->getTimestamp();
            if ($since !== null && $now - $since <= self::PREPARING_FOR_SECONDS) {
                return ['state' => 'preparing', 'key' => $key, 'inviteUrl' => ''];
            }

            return null;
        }

        $expires = (string) ($properties[self::PROP_INVITE_EXPIRES] ?? '');
        if ($expires !== '' && ($ts = strtotime($expires)) !== false && $ts <= $now) {
            return ['state' => 'expired', 'key' => $key, 'inviteUrl' => ''];
        }

        return ['state' => 'ready', 'key' => $key, 'inviteUrl' => $url];
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
        if ($stored === null || in_array($stored['state'], ['preparing', 'trial'], true)) {
            return $stored ?? ['state' => 'none', 'key' => 'service-' . $service->id, 'inviteUrl' => ''];
        }

        try {
            $invitation = $this->findInvitation($properties);
            $status = $invitation['status'] ?? 'missing';

            if ($status === 'used') {
                $this->rememberUsers($service, $this->resolveUsers($properties, $invitation));
                $this->syncSeerrQuietly($service, 'active');
                $this->processTrialQuietly($service);

                return ['state' => 'done', 'key' => $stored['key'], 'inviteUrl' => ''];
            }
            if ($status === 'pending') {
                return ['state' => 'ready', 'key' => $stored['key'], 'inviteUrl' => $this->absoluteUrl($invitation['url'] ?? null, (string) $invitation['code'])];
            }

            return ['state' => 'expired', 'key' => $stored['key'], 'inviteUrl' => ''];
        } catch (Throwable $e) {
            return $stored; // Wizarr unreachable: keep showing what we know
        }
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
#wizarr-banner[data-state="preparing"] [data-when~="preparing"],#wizarr-banner[data-state="ready"] [data-when~="ready"],#wizarr-banner[data-state="expired"] [data-when~="expired"],#wizarr-banner[data-state="trial_over"] [data-when~="trial_over"],#wizarr-banner[data-state="trial"] [data-when~="trial"]{display:block}
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
            . '<div data-when="trial"><p class="wb-title"><span>' . $e(self::t('Trial')) . ': <span data-remaining>' . $e($state['remaining'] ?? '') . '</span></span></p>'
            . '<p class="wb-text">' . $e(self::t('Continue with a subscription and keep your user and everything you have watched.')) . '</p></div>'
            . '<div data-when="trial_over"><p class="wb-title">' . $e(self::t('Your trial has ended')) . '</p>'
            . '<p class="wb-text">' . $e(self::t('Continue with a subscription – you keep your user and everything you have watched.')) . '</p></div>'
            . <<<'HTML'
<div class="wb-actions">
  <span data-when="ready"><a class="wb-btn" data-invite target="_blank" rel="noopener" href="
HTML
            . $e($state['inviteUrl'] ?? '') . '">' . $e(self::t('Create your user')) . '</a></span>'
            . '<span data-when="expired"><a class="wb-btn" href="' . $e($state['serviceUrl'] ?? '') . '">' . $e(self::t('Get a new link')) . '</a></span>'
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
     | Trial products
     * ----------------------------------------------------------------- */

    public function isTrial(array $productSettings): bool
    {
        return (int) ($productSettings['trial_days'] ?? 0) > 0;
    }

    /**
     * Who may start a trial: only customers who never had one and never
     * subscribed. $history: 'trial' or 'paid' for each of their other
     * Wizarr subscriptions, whatever their status.
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
        if (!$this->isTrial($productSettings) || !empty($properties[self::PROP_TRIAL_CONVERTED])) {
            return 'none';
        }
        $now ??= time();
        $users = $this->parseUsers((string) ($properties[self::PROP_USERS] ?? ''));

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

            foreach ($users as $user) {
                $message = $this->client()->disableUser($user['id']);
                if (stripos($message, 'deleted') !== false) {
                    // The server could not disable them, so Wizarr deleted them.
                    $this->rememberUsers($service, []);
                }
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
                $this->rememberUsers($service, []);

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
            if ($other->id === $service->id || $other->product?->server?->extension !== 'Wizarr') {
                continue;
            }
            if (!$this->isTrial(ExtensionHelper::settingsToArray($other->product->settings))) {
                continue;
            }
            $props = ExtensionHelper::getServiceProperties($other);
            if (!empty($props[self::PROP_TRIAL_CONVERTED]) || empty($props[self::PROP_USERS])) {
                continue;
            }

            $users = $this->resolveUsers($props, null); // checks they still exist
            if (!$users) {
                continue;
            }
            foreach ($users as $user) {
                if (stripos($this->client()->enableUser($user['id']), 'enabled successfully') === false) {
                    return false; // cannot be switched back on (Plex): invite as usual
                }
            }

            $this->rememberUsers($service, $users);
            $this->setProperty($other, self::PROP_TRIAL_CONVERTED, 'Trial became service', (string) $service->id);
            $this->setProperty($other, self::PROP_USERS, '', null); // never delete them from the trial again
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
    public function processTrials(): void
    {
        $services = Service::whereIn('status', ['active', 'cancelled'])
            ->whereHas('product.server', fn ($query) => $query->where('extension', 'Wizarr'))
            ->with(['product.settings'])
            ->get();
        foreach ($services as $service) {
            if (!$service->product) {
                continue;
            }
            $productSettings = ExtensionHelper::settingsToArray($service->product->settings);
            if (!$this->isTrial($productSettings)) {
                continue;
            }
            try {
                $this->processTrial($service, $productSettings, ExtensionHelper::getServiceProperties($service));
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
            $productSettings = ExtensionHelper::settingsToArray($service->product->settings);
            if ($this->isTrial($productSettings)) {
                $this->processTrial($service, $productSettings, ExtensionHelper::getServiceProperties($service));
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
        if ($this->truthy($productSettings['seerr_included'] ?? false)) {
            return true;
        }

        return in_array(strtolower(trim((string) ($properties[self::SEERR_OPTION] ?? ''))), ['ja', 'yes', 'y', '1', 'true', 'on'], true);
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
        if (!$this->seerrConfigured()) {
            return;
        }
        $seerr = $this->seerr();
        $managed = (int) ($properties[self::PROP_SEERR_USER] ?? 0);

        if ($state === 'ended' || !$this->wantsSeerr($productSettings, $properties)) {
            if ($managed) {
                $seerr->deleteUser($managed);
                $this->setProperty($service, self::PROP_SEERR_USER, '', null);
                $this->setProperty($service, self::PROP_SEERR_SAVED_PERMISSIONS, '', null);
            }

            return;
        }

        if ($managed && !$seerr->userExists($managed)) {
            $managed = 0; // removed in Seerr by hand: import again
            $this->setProperty($service, self::PROP_SEERR_SAVED_PERMISSIONS, '', null);
            $properties[self::PROP_SEERR_SAVED_PERMISSIONS] = null;
        }

        if (!$managed) {
            if ($state === 'suspended') {
                return; // never create access for someone who has not paid
            }
            $username = null;
            foreach ($this->resolveUsers($properties, $this->findInvitation($properties)) as $user) {
                if (in_array($user['type'], ['jellyfin', 'emby'], true)) {
                    $username = $user['username'];

                    break;
                }
            }
            if ($username === null) {
                return; // the customer has not created their user yet
            }

            $jellyfinUser = null;
            foreach ($seerr->mediaServerUsers() as $candidate) {
                if (strcasecmp($candidate['username'], $username) === 0) {
                    $jellyfinUser = $candidate;

                    break;
                }
            }
            if ($jellyfinUser === null) {
                throw new SeerrException('Seerr does not list a Jellyfin user called "' . $username . '". Is Seerr connected to the same Jellyfin?');
            }

            if ($seerr->userIdForJellyfinUser($jellyfinUser['id']) !== null) {
                Log::info('Wizarr: ' . $username . ' already had a Seerr account; it is left as it is and not managed by Paymenter.');

                return;
            }

            $managed = $seerr->importJellyfinUser($jellyfinUser['id']);
            $this->setProperty($service, self::PROP_SEERR_USER, 'Seerr user ID', (string) $managed);
        }

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
    }

    /** Seerr must never break a payment: log it and let the hourly run try again. */
    private function syncSeerrQuietly(Service $service, string $state): void
    {
        if (!$this->seerrConfigured() || !$service->product) {
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
    public function reconcileSeerr(): void
    {
        if (!$this->seerrConfigured()) {
            return;
        }
        $services = Service::whereIn('status', ['active', 'suspended', 'cancelled'])
            ->whereHas('product.server', fn ($query) => $query->where('extension', 'Wizarr'))
            ->get();
        foreach ($services as $service) {
            $state = ['active' => 'active', 'suspended' => 'suspended', 'cancelled' => 'ended'][$service->status];
            $this->syncSeerrQuietly($service, $state);
        }
    }

    private function seerrConfigured(): bool
    {
        return trim((string) $this->config('seerr_url')) !== '' && trim((string) $this->config('seerr_api_key')) !== '';
    }

    private ?SeerrClient $seerrClient = null;

    private function seerr(): SeerrClient
    {
        return $this->seerrClient ??= new SeerrClient(trim((string) $this->config('seerr_url')), trim((string) $this->config('seerr_api_key')));
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
                Log::warning('Wizarr: ' . $pending . ' of service ' . $service->id . ' still fails, will retry: ' . $e->getMessage());
            }
        }

        return ['retried' => $retried, 'failed' => $failed];
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
            $history = [];
            foreach ($service->user->services()->with(['product.settings', 'product.server'])->get() as $other) {
                if ($other->id !== $service->id && $other->product?->server?->extension === 'Wizarr') {
                    $history[] = $this->isTrial(ExtensionHelper::settingsToArray($other->product->settings)) ? 'trial' : 'paid';
                }
            }
            $refusal = $this->trialRefusal($history);
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

        return [$first !== '' ? $first : 'Discord', isset($parts[1]) ? mb_substr($parts[1], 0, 255) : null];
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
     | Invitations
     * ----------------------------------------------------------------- */

    private function issueInvitation(Service $service, array $settings): array
    {
        $serverIds = $this->idList($settings['server_ids'] ?? null);
        if (!$serverIds) {
            throw new WizarrException('This product has no media server selected. Edit the product and choose one.');
        }

        // A trial's end is handled here (disable, keep for a purchase). Letting
        // Wizarr expire the user could delete it, so trials never get a duration.
        $accessDays = $this->isTrial($settings) ? 0 : (int) ($settings['access_days'] ?? 0);

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
        if ($libraryIds) {
            $payload['library_ids'] = $libraryIds;
        }

        $invitation = $this->client()->createInvitation($payload);
        $invitation['url'] = $this->absoluteUrl($invitation['url'] ?? null, (string) $invitation['code']);

        $this->setProperty($service, self::PROP_INVITATION_ID, 'Wizarr invitation ID', (string) ($invitation['id'] ?? ''));
        $this->setProperty($service, self::PROP_INVITE_CODE, 'Wizarr invite code', (string) $invitation['code']);
        $this->setProperty($service, self::PROP_INVITE_URL, 'Wizarr invite link', $invitation['url']);
        $this->setProperty($service, self::PROP_INVITE_EXPIRES, 'Wizarr invite link expires', $invitation['expires'] ?? null);
        $this->rememberUsers($service, []);

        return $invitation;
    }

    /**
     * The invitation this service owns, as Wizarr sees it right now, or null
     * when it no longer exists.
     */
    private function findInvitation(array $properties): ?array
    {
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
                return $invitation;
            }
        }

        return null;
    }

    private function forgetInvitation(Service $service): void
    {
        $this->setProperty($service, self::PROP_INVITATION_ID, '', null);
        $this->setProperty($service, self::PROP_INVITE_CODE, '', null);
        $this->setProperty($service, self::PROP_INVITE_URL, '', null);
        $this->setProperty($service, self::PROP_INVITE_EXPIRES, '', null);
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

        $this->setProperty($service, self::PROP_USERS, 'Wizarr users', $encoded === '' ? null : $encoded);
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
     | Small helpers
     * ----------------------------------------------------------------- */

    private function client(): WizarrClient
    {
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
    private function activeActions(array $users, array $watch, array $properties = []): array
    {
        $actions = [$this->statusField(self::t('Active'))];

        $endsAt = strtotime((string) ($properties[self::PROP_TRIAL_ENDS] ?? '')) ?: null;
        if ($endsAt !== null) {
            $actions[] = ['type' => 'text', 'label' => self::t('Trial'), 'text' => self::t(':remaining – ends :date', ['remaining' => $this->remainingText($endsAt - time()), 'date' => date(self::t('M j, Y \a\t H:i'), $endsAt)])];
            $watch[] = ['type' => 'button', 'label' => self::t('Continue with a subscription'), 'url' => $this->shopUrl()];
        }

        $seerrUrl = trim((string) $this->config('seerr_public_url'));
        if (!empty($properties[self::PROP_SEERR_USER]) && $seerrUrl !== '') {
            $watch[] = ['type' => 'button', 'label' => self::t('Request movies and shows'), 'url' => $seerrUrl, 'target' => '_blank'];
        }

        // One invitation can create the same name on several servers.
        $names = array_values(array_unique(array_column($users, 'username')));
        if ($names) {
            $actions[] = ['type' => 'text', 'label' => self::t('Username'), 'text' => implode(', ', $names)];
        }

        foreach ($users as $user) {
            // An unknown type (stored by 1.0.0) is offered; resetPassword checks it live.
            if ($user['type'] === '' || in_array($user['type'], self::RESETTABLE_SERVERS, true)) {
                $actions[] = ['type' => 'button', 'label' => self::t('Reset password'), 'function' => 'resetPassword'];

                break;
            }
        }

        return array_merge($actions, $watch);
    }

    private function statusField(string $text): array
    {
        return ['type' => 'text', 'label' => 'Status', 'text' => $text];
    }

    private function inviteButton(string $url): array
    {
        return ['type' => 'button', 'label' => self::t('Create your user'), 'url' => $url, 'target' => '_blank'];
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
