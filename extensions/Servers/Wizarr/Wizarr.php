<?php

namespace Paymenter\Extensions\Servers\Wizarr;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Events\Service\Created as ServiceCreated;
use App\Events\InvoiceItem\Creating as InvoiceItemCreating;
use App\Events\InvoiceTransaction\Updated as TransactionUpdated;
use App\Events\Invoice\Paid as InvoicePaid;
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
    version: '1.29.1',
    author: 'Paywarr',
    url: 'https://github.com/Lolles1911/Paywarr',
)]
class Wizarr extends Server
{
    use Concerns\ClientArea;
    use Concerns\CustomerLifecycle;
    use Concerns\Trials;
    use Concerns\Seerr;
    use Concerns\Requests;
    use Concerns\Reports;
    use Concerns\Health;
    use Concerns\Discord;
    use Concerns\Grants;
    use Concerns\Admin;
    use Concerns\Notifications;
    use Concerns\Welcome;
    use Concerns\Gifts;
    use Concerns\Reminders;
    use Concerns\Loyalty;
    use Concerns\Streams;
    use Concerns\Commands;
    use Concerns\News;
    use Concerns\Store;
    use Concerns\Broadcast;
    use Concerns\Status;
    use Concerns\Anniversary;
    use Concerns\MonthlyReport;
    use Concerns\Cleanup;
    use Concerns\FreeAccess;
    use Concerns\Help;
    use Concerns\Checklist;
    use Concerns\SwitchServer;
    use Concerns\CartReminder;
    use Concerns\Satisfaction;

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

    /** The configurable option for extra users (0-3), as "media_server" and "requests". */
    public const EXTRA_USERS_OPTION = 'extra_users';

    /** Per-server "External URL" from Wizarr ("-" when it has none), stored so page views need no call. */
    private const PROP_SERVER_URL = 'wizarr_server_url';

    /** How long the servers' addresses from Wizarr are kept (seconds). */
    private const SERVER_URLS_SECONDS = 600;

    /** Properties that exist once per media server (grant), see key(). */
    private const GRANT_PROPS = ['wizarr_invitation_id', 'wizarr_invite_code', 'wizarr_invite_url', 'wizarr_invite_expires', 'wizarr_users', 'wizarr_server', 'wizarr_server_url'];

    /** How many servers can have their own buttons in the client area. */
    private const MAX_GRANT_BUTTONS = 8;

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

    /** The link was used but no user was made (e.g. a Plex share that failed); for the admin page's filter. */
    public const PROP_LINK_FAILED = 'wizarr_link_failed';

    /** Libraries chosen for this customer in admin: used for their next invitation instead of the product's. */
    public const PROP_LIBRARIES = 'library_ids_given';

    /** Paused in admin: since when, until when (empty: until resumed), and the paid time left then (seconds). */
    public const PROP_PAUSED = 'paywarr_paused_at';

    public const PROP_PAUSED_UNTIL = 'paywarr_paused_until';

    private const PROP_PAUSED_LEFT = 'paywarr_paused_left';

    /** What was done to the customer in admin, newest first (kept on the customer, last 40). */
    public const PROP_LOG = 'paywarr_log';

    /** A note about the customer, kept on the customer (Paywarr → Customers). */
    public const PROP_NOTE = 'paywarr_note';

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
                'name' => 'welcome_mail',
                'label' => 'Send Paywarr\'s welcome mail',
                'type' => 'checkbox',
                'description' => 'A welcome mail with the link, three steps to get going, where to watch (the apps for Jellyfin, Emby or Plex) and your Discord, also as "Your new link" when an invitation is made again. Paymenter still sends its own "Service activated" mail: turn that template\'s mail off, or empty the products\' Email template, so customers don\'t get two.',
            ],
            [
                'name' => 'welcome_text',
                'label' => 'Your own words in the welcome mail',
                'type' => 'textarea',
                'required' => false,
                'description' => 'Optional. Shown in the welcome mail above the link, e.g. a greeting or house rules.',
            ],
            [
                'name' => 'remind_trial',
                'label' => 'Remind: the trial ends in 2 days',
                'type' => 'checkbox',
                'description' => 'By mail, and as a private Discord message to customers who connected Discord. Once per trial.',
            ],
            [
                'name' => 'remind_link',
                'label' => 'Remind: user not created 2 days after the link',
                'type' => 'checkbox',
                'description' => 'With the link again. Once per link.',
            ],
            [
                'name' => 'remind_renewal',
                'label' => 'Remind: renews in 3 days and the invoice is unpaid',
                'type' => 'checkbox',
                'description' => 'Not for subscriptions paid automatically (card or PayPal subscription). Paymenter\'s own invoice mail goes 7 days before, as always.',
            ],
            [
                'name' => 'pause_per_year',
                'label' => 'Customers may pause: times a year',
                'type' => 'number',
                'required' => false,
                'validation' => 'nullable|integer|min:0|max:12',
                'description' => 'Optional. "Pause" on the subscription, instead of cancelling (a holiday): access off, the renewal moves as many days. Empty or 0: off.',
            ],
            [
                'name' => 'pause_max_days',
                'label' => 'Longest pause (days)',
                'type' => 'number',
                'required' => false,
                'default' => 30,
                'validation' => 'nullable|integer|min:1|max:90',
            ],
            [
                'name' => 'winback_days',
                'label' => 'Come-back code: days after cancelling',
                'type' => 'number',
                'required' => false,
                'validation' => 'nullable|integer|min:1|max:365',
                'description' => 'Optional. This many days after a subscription was cancelled (and none is running), the customer gets one code by mail and Discord, at most once a year. Empty: off.',
            ],
            [
                'name' => 'winback_percent',
                'label' => 'Come-back code: % off the first month',
                'type' => 'number',
                'required' => false,
                'validation' => 'nullable|integer|min:1|max:100',
            ],
            [
                'name' => 'winback_valid_days',
                'label' => 'Come-back code: valid for (days)',
                'type' => 'number',
                'required' => false,
                'default' => 30,
                'validation' => 'nullable|integer|min:1|max:365',
            ],
            [
                'name' => 'referral_percent',
                'label' => 'Invite a friend: % off the friend\'s first month',
                'type' => 'number',
                'required' => false,
                'validation' => 'nullable|integer|min:1|max:100',
                'description' => 'Optional. Every customer gets their own code for friends ("Invite a friend" in the menu). Empty: off.',
            ],
            [
                'name' => 'referral_reward_months',
                'label' => 'Invite a friend: free months when the friend has paid',
                'type' => 'number',
                'required' => false,
                'default' => 1,
                'validation' => 'nullable|integer|min:1|max:12',
                'description' => 'Added to the customer\'s subscription once the friend\'s first invoice is paid.',
            ],
            [
                'name' => 'news_day',
                'label' => 'New on the server: weekly on',
                'type' => 'select',
                'default' => '',
                'options' => [
                    ['value' => '', 'label' => 'Off'],
                    ['value' => '1', 'label' => 'Monday'], ['value' => '2', 'label' => 'Tuesday'], ['value' => '3', 'label' => 'Wednesday'],
                    ['value' => '4', 'label' => 'Thursday'], ['value' => '5', 'label' => 'Friday'], ['value' => '6', 'label' => 'Saturday'], ['value' => '7', 'label' => 'Sunday'],
                ],
                'description' => 'The movies and shows added that week, from Tracearr (and Silo), at 17:00. Posted in the announcements channel and/or mailed (below).',
            ],
            [
                'name' => 'news_mail',
                'label' => 'New on the server: mail it to customers too',
                'type' => 'checkbox',
                'description' => 'To active customers, with a link to stop getting it.',
            ],
            [
                'name' => 'announce_channel_id',
                'label' => 'Discord: announcements channel ID',
                'type' => 'text',
                'required' => false,
                'description' => 'Optional. Where the bot posts "New on the server" and the year in review (Paywarr Stats). Right-click the channel -> Copy Channel ID. The bot needs to be allowed to post there.',
            ],
            [
                'name' => 'tracearr_url',
                'label' => 'Tracearr URL (internal)',
                'type' => 'text',
                'required' => false,
                'placeholder' => 'http://tracearr:3000',
                'description' => 'Optional. For "Max streams at once" on products, account sharing on the customer card, and "New on the server". Paywarr Stats uses it too, when it has none of its own.',
            ],
            [
                'name' => 'tracearr_api_key',
                'label' => 'Tracearr API key',
                'type' => 'password',
                'required' => false,
                'encrypted' => true,
                'description' => 'Tracearr: Settings -> Data & API -> API -> Generate key (starts with trr_pub_).',
            ],
            [
                'name' => 'streams_over_limit',
                'label' => 'Too many streams at once',
                'type' => 'select',
                'default' => 'stop',
                'options' => [
                    ['value' => 'stop', 'label' => 'Stop the newest, with a message on the screen, and warn the customer'],
                    ['value' => 'warn', 'label' => 'Only warn the customer'],
                ],
                'description' => 'For products with "Max streams at once". Checked every minute through Tracearr.',
            ],
            [
                'name' => 'anniversary_months',
                'label' => 'Anniversary: every (months)',
                'type' => 'number',
                'required' => false,
                'validation' => 'nullable|integer|min:1|max:60',
                'description' => 'Optional. E.g. 12: every year as a paying customer, a thank-you by mail and Discord, with the gift below. Empty: off.',
            ],
            [
                'name' => 'anniversary_reward',
                'label' => 'Anniversary: gift',
                'type' => 'select',
                'default' => 'days',
                'options' => [
                    ['value' => 'days', 'label' => 'Free days added to the subscription'],
                    ['value' => 'coupon', 'label' => 'A discount code (% off a period, valid 60 days)'],
                    ['value' => 'thanks', 'label' => 'Only a thank-you'],
                ],
            ],
            [
                'name' => 'anniversary_amount',
                'label' => 'Anniversary: days, or % off',
                'type' => 'number',
                'required' => false,
                'validation' => 'nullable|integer|min:1|max:100',
                'description' => 'E.g. 7 (days) or 20 (%).',
            ],
            [
                'name' => 'cart_reminder_hours',
                'label' => 'Unpaid order: remind after (hours)',
                'type' => 'number',
                'required' => false,
                'validation' => 'nullable|integer|min:1|max:168',
                'description' => 'Optional. An order whose first invoice is still unpaid gets a mail with the link to pay. Empty: off.',
            ],
            [
                'name' => 'cart_reminder_again_hours',
                'label' => 'Unpaid order: remind again after (hours)',
                'type' => 'number',
                'required' => false,
                'validation' => 'nullable|integer|min:2|max:168',
                'description' => 'Optional. A second reminder, counted from the order. Empty: only one.',
            ],
            [
                'name' => 'survey',
                'label' => 'Satisfaction survey',
                'type' => 'checkbox',
                'description' => 'After a month as a paying customer and every half year after: "How likely are you to recommend us?" (0-10, one click) by mail; and customers who cancel are asked why. Answers under Paywarr -> Satisfaction.',
            ],
            [
                'name' => 'cleanup_disable_days',
                'label' => 'Clean-up: switch off users without a subscription after (days)',
                'type' => 'number',
                'required' => false,
                'validation' => 'nullable|integer|min:1|max:365',
                'description' => 'Optional. Users on the media servers that belong to no subscription (left over, made by hand) are switched off after this many days, checked daily. Plex users can\'t be switched off; they are only deleted. Mark users to keep under Paywarr -> Media server users. Empty: off.',
            ],
            [
                'name' => 'cleanup_delete_days',
                'label' => 'Clean-up: delete them after (more days)',
                'type' => 'number',
                'required' => false,
                'validation' => 'nullable|integer|min:1|max:365',
                'description' => 'Optional. Switched-off users are deleted this many days later. Empty: never deleted.',
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
            [
                'name' => 'discord_public_key',
                'label' => 'Discord application: Public key (for commands)',
                'type' => 'text',
                'required' => false,
                'description' => 'Optional. Lets customers use /status, /newlink, /stats and /password with the bot. Discord developer portal -> your application -> General Information -> Public Key; '
                    . 'and there, set "Interactions Endpoint URL" to https://your-paymenter/wizarr/discord/interactions. The commands are registered on your server within the hour.',
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
                'name' => 'gift_months',
                'label' => 'Gift card: months',
                'type' => 'number',
                'required' => false,
                'validation' => 'nullable|integer|min:1|max:24',
                'description' => 'Makes this a gift card: the buyer gets a one-time code worth what they paid, spread over this many billing periods of a subscription (with monthly plans: that many months free). No invitation is sent. Leave empty for normal products.',
            ],
            [
                'name' => 'max_streams',
                'label' => 'Max streams at once',
                'type' => 'number',
                'required' => false,
                'validation' => 'nullable|integer|min:1|max:20',
                'description' => 'Optional. More at the same time on one subscription are stopped or warned about (the server\'s "Too many streams at once"). Needs Tracearr on the server. Empty: no limit.',
            ],
            [
                'name' => 'free_access',
                'label' => 'Free access',
                'type' => 'checkbox',
                'description' => 'A free product: its subscriptions end when they aren\'t used (the rules are under Paywarr -> Free access), and you can give it to customers from there. Give it a free plan (or a price of 0). Subscriptions with a price of 0 count as free too.',
            ],
            [
                'name' => 'extra_users_max',
                'label' => 'Extra users: at most',
                'type' => 'number',
                'required' => false,
                'validation' => 'nullable|integer|min:1|max:7',
                'description' => 'Optional. With a configurable option "extra_users" (values 0, 1, 2, …) customers buy extra users (e.g. for family): each gets their own invitation on every media server of the subscription, and follows it (paused, suspended, cancelled). Empty: no extra users.',
            ],
            [
                'name' => 'extra_users_share_streams',
                'label' => 'Extra users share "Max streams at once"',
                'type' => 'checkbox',
                'description' => 'On: all users of a subscription share the limit. Off: each user has the limit.',
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

        // A line on top of the admin dashboard when customers or servers need a look.
        if (class_exists(\Filament\Support\Facades\FilamentView::class) && class_exists(\App\Admin\Pages\Dashboard::class)) {
            \Filament\Support\Facades\FilamentView::registerRenderHook(
                \Filament\View\PanelsRenderHook::PAGE_START,
                fn () => $this->dashboardNotice(),
                scopes: \App\Admin\Pages\Dashboard::class,
            );
        }

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

        // A friend's first payment with a customer's code: the customer's reward (Loyalty).
        if (!self::$referralListener) {
            self::$referralListener = true;
            Event::listen(InvoicePaid::class, function (InvoicePaid $event) {
                try {
                    $this->rewardReferrals($event->invoice);
                } catch (Throwable $e) {
                    Log::warning('Wizarr: referral rewards for invoice ' . ($event->invoice->id ?? '?') . ' not given: ' . $e->getMessage());
                }
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
        // A server down or planned maintenance: a bar on top, for the customers it concerns.
        Event::listen('footer', fn () => $this->renderStatusBar());

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

        // "Invite a friend", when on and the customer has a paid subscription.
        Event::listen('navigation.dashboard', fn () => [
            'name' => self::t('Invite a friend'),
            'route' => 'extensions.wizarr.invite',
            'icon' => 'ri-gift-2',
            'priority' => 31,
            'condition' => Auth::check() && Cache::remember('wizarr:can-refer:' . Auth::id(), 300, fn () => $this->canRefer(Auth::user())),
        ]);

        // "Help": guides per device and questions; and "Getting started" on the dashboard.
        Event::listen('navigation.dashboard', fn () => [
            'name' => self::t('Help'),
            'route' => 'extensions.wizarr.help',
            'icon' => 'ri-question',
            'priority' => 32,
            'condition' => Auth::check() && $this->hasSubscription(Auth::user()),
        ]);
        Event::listen('pages.dashboard', fn () => $this->renderChecklist());

        // "Service status": every media server, up or down, and your notices.
        Event::listen('navigation.dashboard', fn () => [
            'name' => self::t('Service status'),
            'route' => 'extensions.wizarr.status-page',
            'icon' => 'ri-pulse',
            'priority' => 33,
            'condition' => Auth::check() && $this->hasSubscription(Auth::user()),
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
                $this->resumePausedServices();
                $this->sendReminders();
                $this->sendWinbacks();
                $this->sendAnniversaries();
                $this->makeMonthlyReportIfDue();
                $this->runCleanup();
                $this->runFreeAccess();
                $this->finishSwitches();
                $this->sendCartReminders();
                $this->sendSurveys();
                $this->sendNewsIfDue();
                $this->registerDiscordCommandsIfNeeded();
                $this->queueHeartbeat();
                $this->reconcileSeerr();
                $this->processTrials();
                $this->reportHealth($retry);
            })
                ->hourly()
                ->name('wizarr-retry-pending')
                ->withoutOverlapping();
            // Max streams at once (products with a limit, through Tracearr).
            $schedule->call(function () {
                $this->enforceStreamLimits();
                $this->monitorStatus();
            })
                ->everyMinute()
                ->name('wizarr-stream-limits')
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
        $this->forgetCustomerCaches($service);
        if ($this->isTopUp($settings)) {
            $n = $this->applyTopUp($service, $settings);

            return ['invite_url' => '', 'invite_code' => '', 'invite_valid_for' => '', 'login_url' => '', 'extra_requests' => $n];
        }
        if ($this->isGift($settings)) {
            return $this->createGift($service, $settings);
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

        $data = $this->mailDataFor($invitations, $settings);
        if ($this->welcomeMailOn() && array_filter($invitations, fn ($i) => ($i['code'] ?? '') !== '' && empty($i['has_user']))) {
            $this->sendWelcomeMail($service, $settings, $data);
        }

        return $data;
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
            $invites[] = ['server' => $server, 'label' => $server . ($grant === '' ? '' : $this->withGrant((string) $grant, fn () => $this->seatSuffix())), 'url' => $data['invite_url'], 'code' => $data['invite_code']];
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
        if ($this->isGift($settings)) {
            $this->setGiftActive($service, false);

            return true;
        }
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
        if ($this->isGift($settings)) {
            $this->setGiftActive($service, true);

            return true;
        }
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
        $this->forgetCustomerCaches($service);
        if ($this->isGift($settings)) {
            $this->setGiftActive($service, false);

            return true;
        }
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
        if ($this->isGift($settings)) {
            return true;
        }
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
            if ($this->welcomeMailOn()) {
                $this->sendWelcomeMail($service, ExtensionHelper::settingsToArray($service->product->settings ?? []), $data, again: true);

                return;
            }
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
