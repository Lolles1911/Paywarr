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

/** The client area and the "Your access is ready" box. Part of Wizarr (Paywarr); uses its constants and helpers. */
trait ClientArea
{
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
        if ($this->isGift($settings)) {
            return $this->giftActions($service, $properties);
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
                $this->markLinkFailed($service, $properties);

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
                        $this->markLinkFailed($service, $view);

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
        if (!empty($properties[self::PROP_GIFT_CODE]) || !empty($properties[self::PROP_TOPUP_TARGET])) {
            return null; // a gift card or a top-up has no access of its own
        }
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
                        $this->markLinkFailed($service, $properties);

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
}
