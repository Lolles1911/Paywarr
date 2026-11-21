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

/** What the Paywarr pages in admin do. Part of Wizarr (Paywarr); uses its constants and helpers. */
trait Admin
{
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
            'gift' => ($properties[self::PROP_GIFT_CODE] ?? '') !== '' ? ['code' => (string) $properties[self::PROP_GIFT_CODE], 'used' => $this->giftUsed($properties), 'sent_to' => (string) ($properties['gift_sent_to'] ?? '')] : null,
            'paused' => !empty($properties[self::PROP_PAUSED]) ? ['since' => (int) $properties[self::PROP_PAUSED], 'until' => (int) ($properties[self::PROP_PAUSED_UNTIL] ?? 0) ?: null] : null,
            'libraries' => $this->idList((string) ($properties[self::PROP_LIBRARIES] ?? '')),
            'servers' => $this->idList((string) ($properties[self::PROP_SERVERS] ?? '')) ?: array_map('intval', array_filter($this->grantKeys($properties))),
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

    /**
     * After the media server changed (a new Jellyfin, users gone): every
     * server of the subscription where the customer's user no longer exists
     * gets a new invitation, mailed when $mail. A server that is gone from
     * Wizarr is dropped, and the product's media servers take its place.
     * Users that still exist are left alone. Returns the new links.
     */
    public function adminReinvite(Service $service, bool $mail = true): array
    {
        $settings = ExtensionHelper::settingsToArray($service->product->settings);
        $properties = ExtensionHelper::getServiceProperties($service);
        $existing = array_map(fn ($server) => (int) $server['id'], $this->client()->servers());

        // First only look: which servers are gone, and where is the user gone.
        $gone = [];
        $missing = [];
        foreach ($this->grantKeys($properties) as $grant) {
            if ($grant !== '' && !in_array((int) $grant, $existing, true)) {
                $gone[] = $grant;

                continue;
            }
            $this->withGrant($grant, function () use ($service, $properties, $grant, &$missing) {
                $view = $this->grantView($properties);
                if (!$this->parseUsers((string) ($view[self::PROP_USERS] ?? ''))) {
                    return; // no user yet: the invitation it has stays
                }
                $invitation = $this->findInvitation($view);
                $users = $this->resolveUsers($view, $invitation);
                if ($users) {
                    $this->rememberUsers($service, $users); // e.g. found again under a new id
                } else {
                    $missing[$grant] = $invitation;
                }
            });
        }
        if (!$gone && !$missing) {
            return [];
        }
        $keep = array_values(array_intersect($this->idList((string) ($properties[self::PROP_SERVERS] ?? '')), $existing));
        if (!$keep && !array_intersect($this->idList($settings['server_ids'] ?? null), $existing) && trim((string) ($properties[self::MEDIA_OPTION] ?? '')) === '') {
            throw new DisplayException('Product "' . ($service->product->name ?? '') . '" uses a media server that no longer exists in Wizarr. Choose the new one on the product first (Paywarr → Libraries per product, or edit the product). Nothing was changed.');
        }

        // Then change: forget the gone servers and users, and invite again.
        foreach ($gone as $grant) {
            foreach (self::GRANT_PROPS as $prop) {
                $this->setProperty($service, $prop . ':' . $grant, '', null);
            }
        }
        foreach ($missing as $grant => $invitation) {
            $this->withGrant((string) $grant, function () use ($service, $invitation) {
                if ($invitation) {
                    $this->deleteInvitationQuietly((int) $invitation['id']);
                }
                $this->rememberUsers($service, []);
                foreach ([self::PROP_INVITATION_ID, self::PROP_INVITE_CODE, self::PROP_INVITE_URL, self::PROP_INVITE_EXPIRES] as $prop) {
                    $this->setProperty($service, $this->key($prop), '', null);
                }
            });
        }
        $this->setProperty($service, self::PROP_SERVERS, 'Wizarr media servers', $keep ? implode(',', $keep) : null);
        $made = array_filter($this->syncGrants($service, $settings, ExtensionHelper::getServiceProperties($service)),
            fn ($invitation) => ($invitation['code'] ?? '') !== '' && empty($invitation['has_user']));
        if ($made && $mail) {
            $this->notifyNewInvitation($service, $this->mailDataFor($made, $settings));
        }
        $this->forgetCustomerCaches($service);
        Log::info('Wizarr: service ' . $service->id . ' re-invited after its media server user was gone (' . count($made) . ' new invitation(s)).');

        return array_values(array_map(fn ($invitation) => (string) ($invitation['url'] ?? ''), $made));
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
        $missing = [];
        foreach ($this->grantKeys($properties) as $grant) {
            $this->withGrant($grant, function () use ($service, $properties, &$found, &$spent, &$missing) {
                $view = $this->grantView($properties);
                $recorded = $this->parseUsers((string) ($view[self::PROP_USERS] ?? ''));
                $invitation = $this->findInvitation($view);
                $users = $this->resolveUsers($view, $invitation);
                $server = $this->grantLabel($properties) ?: 'the media server';
                if ($users) {
                    $this->rememberUsers($service, $users);
                } elseif ($recorded) {
                    // Known, but Wizarr no longer has that user (with that id and name): kept, and said so.
                    $missing[] = implode(', ', array_column($recorded, 'username')) . ' on ' . $server;
                } elseif (($invitation['status'] ?? '') === 'used') {
                    $spent[] = $server;
                    $this->markLinkFailed($service, $properties);
                }
                foreach ($users as $user) {
                    $found[] = $user['username'];
                }
            });
        }
        if (!$spent && !empty($properties[self::PROP_LINK_FAILED])) {
            $this->setProperty($service, self::PROP_LINK_FAILED, '', null); // marked before, not so now
        }
        $this->processTrialQuietly($service);
        $status = (string) $service->status;
        $this->syncRolesQuietly($service, active: $status === 'active');
        $this->syncSeerrQuietly($service, $status === 'active' ? 'active' : ($status === 'suspended' ? 'suspended' : 'ended'));
        $this->forgetCustomerCaches($service);
        if ($missing && !$found) {
            throw new DisplayException('The user ' . implode('; ', $missing) . ' is no longer in Wizarr (deleted, or renamed or re-imported there). Check Wizarr\'s users; if it is gone (e.g. a new media server), use "Re-invite missing users". Nothing was changed.');
        }
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
    public function adminResetUser(Service $service, bool $mail = true, bool $deleteOnServer = false): array
    {
        $properties = ExtensionHelper::getServiceProperties($service);
        foreach ($this->grantKeys($properties) as $grant) {
            $this->withGrant($grant, function () use ($service, $properties, $deleteOnServer) {
                if ($deleteOnServer) {
                    foreach ($this->parseUsers((string) ($this->grantView($properties)[self::PROP_USERS] ?? '')) as $user) {
                        $this->deleteUserQuietly($user['id']);
                    }
                }
                $this->rememberUsers($service, []);
            });
        }
        Log::info('Wizarr: the media server user(s) of service ' . $service->id . ' were ' . ($deleteOnServer ? 'deleted' : 'forgotten') . ' in admin.');

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

    /**
     * Links a user that already exists on the media server (e.g. a friend's
     * Jellyfin account from before) to this subscription, instead of an
     * invitation. Refused when another subscription has that user, unless
     * $force.
     */
    public function adminLinkUser(Service $service, int $userId, iterable $services = [], bool $force = false): string
    {
        $user = null;
        foreach ($this->client()->users() as $row) {
            if ((int) $row['id'] === $userId) {
                $user = $this->userRow($row);
            }
        }
        if ($user === null) {
            throw new DisplayException('That user no longer exists on the media server.');
        }
        $grant = null;
        foreach ($this->client()->servers() as $server) {
            if ((string) ($server['name'] ?? '') === $user['server']) {
                $grant = (string) $server['id'];
            }
        }
        $properties = ExtensionHelper::getServiceProperties($service);
        $grants = $this->grantKeys($properties);
        if ($grant === null || (!in_array($grant, $grants, true) && !($grants === [''] || $grants === []))) {
            throw new DisplayException('That user is on ' . ($user['server'] ?: 'another server') . ', which this subscription does not include.');
        }
        if (!$force) {
            foreach ($this->adminOwners($services)['users'] as $id => $owner) {
                if ($id === $userId && (int) $owner['service'] !== (int) $service->id) {
                    throw new DisplayException('That user belongs to subscription #' . $owner['service'] . ' (' . $owner['customer'] . ').');
                }
            }
        }
        $grant = in_array($grant, $grants, true) ? $grant : ($grants[0] ?? '');
        $this->withGrant($grant, function () use ($service, $properties, $user) {
            $invitation = $this->findInvitation($this->grantView($properties));
            if ($invitation && ($invitation['status'] ?? '') === 'pending') {
                $this->deleteInvitationQuietly((int) $invitation['id']); // the link is no longer needed
            }
            $this->rememberUsers($service, [$user]);
            if ($service->status === 'active') {
                $this->client()->enableUser($user['id']);
            }
        });
        $this->processTrialQuietly($service);
        $this->syncSeerrQuietly($service, $service->status === 'active' ? 'active' : 'suspended');
        $this->forgetCustomerCaches($service);
        Log::info('Wizarr: media server user ' . $user['username'] . ' linked to service ' . $service->id . ' in admin.');

        return $user['username'] . ' (' . $user['server'] . ')';
    }

    /** Media server users: switch off, back on, or delete (Paywarr → Users). */
    public function adminUserAction(int $userId, string $action): string
    {
        return match ($action) {
            'disable' => $this->client()->disableUser($userId),
            'enable' => $this->client()->enableUser($userId),
            'delete' => ($this->deleteUserQuietly($userId) ?? 'Deleted.'),
            default => throw new DisplayException('Unknown action.'),
        };
    }

    /** Deletes an invitation (Paywarr → Invitations). */
    public function adminDeleteInvitation(int $id): void
    {
        $this->deleteInvitationQuietly($id);
    }

    /** "wizarr|https://wizarr.example.com": two Paymenter servers on the same Wizarr or Silo share it. */
    public function backendKey(): string
    {
        return ($this->isSilo() ? 'silo' : 'wizarr') . '|' . strtolower(rtrim(trim((string) $this->config('host')), '/'));
    }

    /**
     * Which subscription has each media server user and each invitation of
     * this Wizarr or Silo, from what the subscriptions store.
     *
     * @return array{users: array<int, array>, invitations: array<string, array>}
     */
    public function adminOwners(iterable $services): array
    {
        $users = [];
        $invitations = [];
        foreach ($services as $service) {
            if (($service->product?->server?->extension ?? null) !== 'Wizarr') {
                continue;
            }
            $ext = $this->forServiceServer($service);
            if ($ext->backendKey() !== $this->backendKey()) {
                continue;
            }
            $properties = ExtensionHelper::getServiceProperties($service);
            $owner = [
                'service' => (int) $service->id,
                'customer' => trim(($service->user->first_name ?? '') . ' ' . ($service->user->last_name ?? '')) ?: (string) ($service->user->email ?? ''),
                'email' => (string) ($service->user->email ?? ''),
                'product' => (string) ($service->product->name ?? ''),
                'status' => (string) $service->status,
            ];
            foreach ($ext->grantKeys($properties) as $grant) {
                $ext->withGrant($grant, function () use ($ext, $properties, $owner, &$users, &$invitations) {
                    $view = $ext->grantView($properties);
                    foreach ($ext->parseUsers((string) ($view[self::PROP_USERS] ?? '')) as $user) {
                        $users[$user['id']] = $owner;
                    }
                    if (($view[self::PROP_INVITATION_ID] ?? '') !== '') {
                        $invitations['id:' . $view[self::PROP_INVITATION_ID]] = $owner;
                    }
                    if (($view[self::PROP_INVITE_CODE] ?? '') !== '') {
                        $invitations['code:' . strtoupper((string) $view[self::PROP_INVITE_CODE])] = $owner;
                    }
                });
            }
        }

        return ['users' => $users, 'invitations' => $invitations];
    }

    /**
     * Everything the Paywarr pages show, per Wizarr or Silo behind the
     * Paymenter servers: whether it answers, its media servers, libraries,
     * users and invitations, each user and invitation with the subscription
     * it belongs to (or none).
     */
    public function adminInventory(iterable $servers, iterable $services): array
    {
        $services = is_array($services) ? $services : iterator_to_array($services, false);
        $backends = [];
        foreach ($servers as $server) {
            $ext = new static(ExtensionHelper::settingsToArray($server->settings));
            $key = $ext->backendKey();
            $backends[$key] ??= ['key' => $key, 'kind' => $ext->isSilo() ? 'silo' : 'wizarr', 'host' => rtrim((string) $ext->config('host'), '/'),
                'paymenter' => [], 'server_id' => (int) $server->id, 'ext' => $ext];
            $backends[$key]['paymenter'][(int) $server->id] = (string) $server->name;
        }

        $result = [];
        foreach ($backends as $key => $backend) {
            $ext = $backend['ext'];
            unset($backend['ext']);
            $started = microtime(true);
            try {
                $client = $ext->client();
                $client->status();
                $mediaServers = $client->servers();
                $libraries = $client->libraries();
                $users = $client->users();
                $invitations = $client->invitations();
                $backend += ['ok' => true, 'error' => '', 'ms' => (int) round(1000 * (microtime(true) - $started))];
            } catch (Throwable $e) {
                $result[] = $backend + ['ok' => false, 'error' => $e->getMessage(), 'ms' => null, 'servers' => [], 'libraries' => [], 'users' => [], 'invitations' => []];

                continue;
            }
            $owners = $ext->adminOwners($services);
            $perServer = [];
            foreach ($users as $user) {
                $perServer[(string) ($user['server'] ?? '')]['users'] = ($perServer[(string) ($user['server'] ?? '')]['users'] ?? 0) + 1;
            }
            foreach ($libraries as $library) {
                $perServer[(string) ($library['server_name'] ?? '')]['libraries'] = ($perServer[(string) ($library['server_name'] ?? '')]['libraries'] ?? 0) + 1;
            }
            $backend['servers'] = array_map(fn ($server) => [
                'id' => (int) $server['id'],
                'name' => (string) ($server['name'] ?? ''),
                'type' => strtolower((string) ($server['server_type'] ?? '')),
                'verified' => !empty($server['verified']),
                'url' => (string) ($server['external_url'] ?? ''),
                'users' => $perServer[(string) ($server['name'] ?? '')]['users'] ?? 0,
                'libraries' => $perServer[(string) ($server['name'] ?? '')]['libraries'] ?? 0,
            ], $mediaServers);
            $backend['libraries'] = array_map(fn ($library) => ['id' => (int) $library['id'], 'name' => (string) ($library['name'] ?? ''),
                'server_id' => (int) ($library['server_id'] ?? 0), 'server' => (string) ($library['server_name'] ?? '')], $libraries);
            $backend['users'] = array_map(fn ($user) => [
                'id' => (int) $user['id'],
                'username' => (string) ($user['username'] ?? ''),
                'email' => (string) ($user['email'] ?? ''),
                'server' => (string) ($user['server'] ?? ''),
                'type' => strtolower((string) ($user['server_type'] ?? '')),
                'created' => (string) ($user['created_at'] ?? ''),
                'expires' => (string) ($user['expires'] ?? ''),
                'disabled' => array_key_exists('is_disabled', $user) ? (bool) $user['is_disabled'] : (array_key_exists('enabled', $user) ? !$user['enabled'] : null),
                'owner' => $owners['users'][(int) $user['id']] ?? null,
            ], $users);
            $backend['invitations'] = array_map(fn ($invitation) => [
                'id' => (int) $invitation['id'],
                'code' => (string) ($invitation['code'] ?? ''),
                'status' => (string) ($invitation['status'] ?? ''),
                'servers' => array_values(array_map('strval', (array) ($invitation['server_names'] ?? []))),
                'expires' => (string) ($invitation['expires'] ?? ''),
                'used_by' => (string) ($invitation['used_by'] ?? ''),
                'owner' => $owners['invitations']['id:' . $invitation['id']] ?? $owners['invitations']['code:' . strtoupper((string) ($invitation['code'] ?? ''))] ?? null,
            ], $invitations);
            $result[] = $backend;
        }

        return $result;
    }

    /** The admin dashboard's Paywarr line: problems and servers not answering, with links. Empty when all is well. */
    public function dashboardNotice(): HtmlString|string
    {
        try {
            $problems = \Paymenter\Extensions\Servers\Wizarr\Admin\Pages\PaywarrCustomers::problemCount();
            $down = \Paymenter\Extensions\Servers\Wizarr\Admin\PaywarrInventory::downCount();
            $queue = self::queueStuckSince();
        } catch (Throwable $e) {
            return '';
        }
        if ($problems === 0 && $down === 0 && $queue === null) {
            return '';
        }
        $parts = [];
        if ($queue !== null) {
            $parts[] = '<a href="' . e(url('admin/paywarr-checks')) . '" style="font-weight:600;text-decoration:underline">Paymenter\'s queue stands still since ' . e(date('M j, H:i', $queue)) . '</a>';
        }
        if ($down > 0) {
            $parts[] = '<a href="' . e(url('admin/paywarr-status')) . '" style="font-weight:600;text-decoration:underline">' . $down . ' Wizarr/Silo not answering</a>';
        }
        if ($problems > 0) {
            $parts[] = '<a href="' . e(url('admin/paywarr-customers')) . '" style="font-weight:600;text-decoration:underline">' . $problems . ' customer(s) to look at</a>';
        }

        return new HtmlString('<div style="margin-bottom:1rem;padding:.75rem 1rem;border-radius:.75rem;border:1px solid rgb(245 158 11 / .5);background:rgb(245 158 11 / .1);font-size:.875rem">'
            . '<strong>Paywarr:</strong> ' . implode(' · ', $parts) . ' · <a href="' . e(url('admin/paywarr-checks')) . '" style="text-decoration:underline">Checks</a></div>');
    }

    /** Remembers that the link was used without a user, for the admin page's "Problems" filter. */
    private function markLinkFailed(Service $service, array $properties): void
    {
        if (empty($properties[self::PROP_LINK_FAILED])) {
            $this->setProperty($service, self::PROP_LINK_FAILED, 'Wizarr: link used, no user made', (string) time());
        }
    }

    /**
     * A password reset link for each media server where the customer has a
     * user that can have one (Jellyfin, Emby, Silo), mailed to them when
     * $mail. Returns the links per server.
     */
    public function adminPasswordReset(Service $service, bool $mail = true): array
    {
        $properties = ExtensionHelper::getServiceProperties($service);
        $links = [];
        foreach ($this->grantKeys($properties) as $grant) {
            $this->withGrant($grant, function () use ($service, $properties, &$links) {
                $view = $this->grantView($properties);
                $users = $this->parseUsers((string) ($view[self::PROP_USERS] ?? ''));
                if ($users && array_filter($users, fn ($u) => $u['type'] === '' || in_array($u['type'], self::RESETTABLE_SERVERS, true))) {
                    $links[$this->grantLabel($properties) ?: 'media server'] = $this->resetGrantPassword($service, $view);
                }
            });
        }
        if (!$links) {
            throw new DisplayException('The customer has no user that can get a password link here (Plex passwords are the customer\'s own Plex account).');
        }
        if ($mail && $service->user) {
            $html = '<p>' . e(self::t('Choose a new password with the link below. It works once.')) . '</p>';
            foreach ($links as $server => $url) {
                $html .= '<p><a href="' . e($url) . '">' . e(self::t('New password for :server', ['server' => $server])) . '</a></p>';
            }
            try {
                \Illuminate\Support\Facades\Mail::html($html, fn ($m) => $m->to($service->user->email)->subject(self::t('Choose a new password')));
            } catch (Throwable $e) {
                Log::warning('Wizarr: password link for service ' . $service->id . ' could not be mailed: ' . $e->getMessage());
            }
        }
        Log::info('Wizarr: password reset link for service ' . $service->id . ' made in admin.');

        return $links;
    }

    /**
     * Moves the subscription to other media servers of the same Wizarr, e.g.
     * from Jellyfin to Plex: the new ones get an invitation (mailed), the
     * user on servers taken away is removed, as at an upgrade.
     */
    public function adminChangeServers(Service $service, array $serverIds): void
    {
        $settings = ExtensionHelper::settingsToArray($service->product->settings);
        $properties = ExtensionHelper::getServiceProperties($service);
        if (trim((string) ($properties[self::MEDIA_OPTION] ?? '')) !== '') {
            throw new DisplayException('This subscription\'s media server comes from the option "media_server". Change that option on the service in Paymenter instead.');
        }
        $ids = array_values(array_unique(array_map('intval', $serverIds)));
        if (!$ids) {
            throw new DisplayException('Choose at least one media server.');
        }
        $known = array_map(fn ($server) => (int) $server['id'], $this->client()->servers());
        if (array_diff($ids, $known)) {
            throw new DisplayException('A chosen media server no longer exists in Wizarr.');
        }
        $this->setProperty($service, self::PROP_SERVERS, 'Wizarr media servers', implode(',', $ids));
        $this->upgradeServer($service, $settings, ExtensionHelper::getServiceProperties($service));
        $this->forgetCustomerCaches($service);
        Log::info('Wizarr: service ' . $service->id . ' moved to media server(s) ' . implode(',', $ids) . ' in admin.');
    }

    /** Libraries for this customer's next invitation, instead of the product's; [] goes back to the product's. */
    public function adminSetLibraries(Service $service, array $libraryIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $libraryIds))));
        $this->setProperty($service, self::PROP_LIBRARIES, 'Libraries chosen in admin', $ids ? implode(',', $ids) : null);
    }

    /**
     * Pauses the subscription, e.g. for a holiday: the access is suspended
     * as when unpaid, without notification, and the paid time left is kept.
     * It resumes by itself at $until (or when resumed by hand).
     */
    public function adminPause(Service $service, ?int $until = null, ?int $now = null): void
    {
        $now ??= time();
        if ($service->status !== 'active') {
            throw new DisplayException('Only an active subscription can be paused.');
        }
        $expires = $service->expires_at ? strtotime((string) $service->expires_at) : null;
        $left = $expires !== null ? max(0, $expires - $now) : null;
        $this->setProperty($service, self::PROP_PAUSED, 'Paused in admin', (string) $now);
        $this->setProperty($service, self::PROP_PAUSED_UNTIL, 'Paused until', $until ? (string) $until : null);
        $this->setProperty($service, self::PROP_PAUSED_LEFT, 'Paid time left when paused (seconds)', $left === null ? null : (string) $left);
        $service->status = 'suspended';
        if ($left !== null) {
            // No invoice and no termination while paused: Paymenter only looks at expires_at.
            $service->expires_at = date('Y-m-d H:i:s', ($until ?? $now + 365 * 86400) + $left);
        }
        if (method_exists($service, 'save')) {
            $service->save();
        }
        $this->suspendServer($service, ExtensionHelper::settingsToArray($service->product->settings), ExtensionHelper::getServiceProperties($service));
        $this->forgetCustomerCaches($service);
        Log::info('Wizarr: service ' . $service->id . ' paused in admin' . ($until ? ' until ' . date(DATE_ATOM, $until) : '') . '.');
    }

    /** Ends a pause: access back, with the paid time that was left. */
    public function adminResume(Service $service, ?int $now = null): void
    {
        $now ??= time();
        $properties = ExtensionHelper::getServiceProperties($service);
        if (empty($properties[self::PROP_PAUSED])) {
            throw new DisplayException('This subscription is not paused.');
        }
        $left = ($properties[self::PROP_PAUSED_LEFT] ?? '') !== '' ? (int) $properties[self::PROP_PAUSED_LEFT] : null;
        $service->status = 'active';
        if ($left !== null) {
            $service->expires_at = date('Y-m-d H:i:s', $now + $left);
        }
        if (method_exists($service, 'save')) {
            $service->save();
        }
        foreach ([self::PROP_PAUSED, self::PROP_PAUSED_UNTIL, self::PROP_PAUSED_LEFT] as $prop) {
            $this->setProperty($service, $prop, '', null);
        }
        $this->unsuspendServer($service, ExtensionHelper::settingsToArray($service->product->settings), ExtensionHelper::getServiceProperties($service));
        $this->forgetCustomerCaches($service);
        Log::info('Wizarr: service ' . $service->id . ' resumed.');
    }

    /** Hourly: pauses whose end has come are resumed. */
    public function resumePausedServices(?iterable $services = null, ?int $now = null): array
    {
        $now ??= time();
        $services ??= Service::where('status', 'suspended')
            ->whereHas('properties', fn ($query) => $query->where('key', self::PROP_PAUSED_UNTIL))
            ->with(['product.server', 'product.settings', 'properties'])->get();
        $resumed = [];
        foreach ($services as $service) {
            $until = (int) (ExtensionHelper::getServiceProperties($service)[self::PROP_PAUSED_UNTIL] ?? 0);
            if ($until > 0 && $until <= $now && $service->status === 'suspended') {
                try {
                    $this->forServiceServer($service)->adminResume($service, $now);
                    $resumed[] = $service->id;
                } catch (Throwable $e) {
                    Log::warning('Wizarr: paused service ' . $service->id . ' not resumed, will retry: ' . $e->getMessage());
                }
            }
        }

        return $resumed;
    }

    /** Notes what was done to a customer in admin (Paywarr → Customers shows it). */
    public static function logAdmin($user, ?int $serviceId, string $what, string $by = ''): void
    {
        if (!$user || !method_exists($user, 'properties')) {
            return;
        }
        $log = json_decode((string) ($user->properties()->where('key', self::PROP_LOG)->value('value') ?? '[]'), true) ?: [];
        // Links (invitations, password resets) are not kept: they are as good as a key while they work.
        $what = (string) preg_replace('~https?://\S+~', '[link]', $what);
        array_unshift($log, ['at' => time(), 'by' => $by, 'service' => $serviceId, 'what' => mb_substr($what, 0, 300)]);
        $user->properties()->updateOrCreate(['key' => self::PROP_LOG], ['name' => 'Paywarr log', 'value' => json_encode(array_slice($log, 0, 40))]);
    }

    /**
     * What might be wrong in the setup, worst first: rows of level (error,
     * warning), what, detail and where to fix it ("server:<id>",
     * "product:<id>", "page:<slug>").
     */
    /**
     * The "media_server" option on a product: pointless with one media server
     * (always so on Silo; the choice is left alone then), and wrong when one
     * of its choices matches none of the product's servers.
     */
    private function checkMediaOption($product, array $settings, array $backend, callable $add): void
    {
        $option = null;
        try {
            foreach ($product->configOptions ?? [] as $candidate) {
                if (($candidate->env_variable ?? '') === self::MEDIA_OPTION) {
                    $option = $candidate;
                }
            }
        } catch (Throwable $e) {
            return;
        }
        if ($option === null) {
            return;
        }
        $ids = $backend['kind'] === 'silo' ? [SiloClient::SERVER_ID] : $this->idList($settings['server_ids'] ?? null);
        if (count($ids) <= 1) {
            $add('warning', 'Product "' . $product->name . '" asks for a media server it doesn\'t need',
                'It has one media server' . ($backend['kind'] === 'silo' ? ' (Silo)' : '') . ', so the choice "' . ($option->name ?? self::MEDIA_OPTION) . '" does nothing. Remove the product from that option under Config Options.', 'option:' . $option->id);

            return;
        }
        $servers = [];
        foreach ($backend['servers'] as $media) {
            if (in_array((int) $media['id'], $ids, true)) {
                $servers[] = $media;
            }
        }
        $wrong = [];
        foreach ($option->children ?? [] as $child) {
            $choice = strtolower(trim((string) ($child->env_variable ?? '')));
            if ($choice === '' || in_array($choice, ['all', 'both', 'begge', 'alle'], true)) {
                continue;
            }
            foreach (array_filter(array_map('trim', preg_split('/[,;+\s]+/', $choice))) as $part) {
                $known = false;
                foreach ($servers as $media) {
                    $known = $known || $part === (string) $media['id'] || $part === strtolower((string) ($media['type'] ?? '')) || $part === strtolower((string) $media['name']);
                }
                if (!$known) {
                    $wrong[] = ($child->name ?? $choice) . ' (' . $part . ')';
                }
            }
        }
        if ($wrong) {
            $add('error', 'Product "' . $product->name . '" offers a media server it doesn\'t have',
                'Orders with ' . implode(', ', array_unique($wrong)) . ' can\'t get an invitation. Fix the environment variable of that choice under Config Options, or add the server to the product.', 'option:' . $option->id);
        }
    }

    public function adminHealth(array $inventory, iterable $servers, iterable $products, iterable $services): array
    {
        $rows = [];
        $add = function (string $level, string $what, string $detail, string $where = '') use (&$rows) {
            $rows[] = compact('level', 'what', 'detail', 'where');
        };
        $byServer = [];
        foreach ($inventory as $backend) {
            foreach ($backend['paymenter'] as $id => $name) {
                $byServer[$id] = $backend;
            }
            $label = ($backend['kind'] === 'silo' ? 'Silo' : 'Wizarr') . ' at ' . $backend['host'];
            if (!$backend['ok']) {
                $add('error', $label . ' does not answer', $backend['error'], 'page:paywarr-status');

                continue;
            }
            foreach ($backend['servers'] as $media) {
                if (!$media['verified']) {
                    $add('warning', 'Media server "' . $media['name'] . '" is not verified', 'Wizarr can\'t make invitations for it. Check it in Wizarr.', 'page:paywarr-status');
                }
            }
        }
        foreach ($servers as $server) {
            $config = ExtensionHelper::settingsToArray($server->settings);
            if (trim((string) ($config['public_url'] ?? '')) === '' && ($config['backend'] ?? 'wizarr') !== 'silo') {
                $add('warning', 'Server "' . $server->name . '" has no public address', 'Invitation links then use Wizarr\'s own address, which customers may not reach.', 'server:' . $server->id);
            }
            // The invite link alone is fine (the welcome mail's Discord button); the other four go together.
            $discord = array_filter(['discord_guild_id', 'discord_client_id', 'discord_client_secret', 'discord_bot_token'], fn ($k) => trim((string) ($config[$k] ?? '')) !== '');
            if ($discord && count($discord) < 4) {
                $add('warning', 'Discord is half set up on "' . $server->name . '"', count($discord) . ' of the 4 Discord fields (server ID, client ID, client secret, bot token) are filled in; Discord is off until all are.', 'server:' . $server->id);
            }
        }
        foreach ($products as $product) {
            $settings = ExtensionHelper::settingsToArray($product->settings);
            $backend = $byServer[(int) $product->server_id] ?? null;
            if ($backend === null || !$backend['ok'] || $this->isTopUp($settings) || $this->isGift($settings)) {
                continue;
            }
            if ($backend['kind'] === 'wizarr') {
                $chosen = $this->idList($settings['server_ids'] ?? null);
                $known = array_column($backend['servers'], 'id');
                if (!$chosen) {
                    $add('error', 'Product "' . $product->name . '" has no media server', 'Orders of it can\'t get an invitation. Choose one on the product.', 'product:' . $product->id);
                } elseif (array_diff($chosen, $known)) {
                    $add('error', 'Product "' . $product->name . '" uses a media server that is gone', 'Choose its media servers again on the product.', 'product:' . $product->id);
                }
            }
            $this->checkMediaOption($product, $settings, $backend, $add);
            $libraries = $this->idList($settings['library_ids'] ?? null);
            if ($libraries && array_diff($libraries, array_column($backend['libraries'], 'id'))) {
                $add('warning', 'Product "' . $product->name . '" has libraries that are gone', 'New customers get fewer libraries than you meant. Choose them again under Libraries per product.', 'page:paywarr-libraries');
            }
            if ($this->isTrial($settings) && (int) ($product->per_user_limit ?? 0) === 0) {
                $add('warning', 'Trial product "' . $product->name . '" has no "Per user limit"', 'Paywarr allows one trial per server, but a limit of 1 on the product is extra safety.', 'product:' . $product->id);
            }
        }
        if (($since = self::queueStuckSince()) !== null) {
            $add('error', 'Paymenter\'s queue stands still', 'Since ' . date('M j, H:i', $since) . ' nothing in the queue has run: invitations and mails wait. On the server: systemctl restart paymenter (see journalctl -u paymenter for why it stopped).', '');
        }
        $stuck = 0;
        $failed = 0;
        foreach ($services as $service) {
            $properties = ExtensionHelper::getServiceProperties($service);
            if (!empty($properties[self::PROP_LINK_FAILED])) {
                $failed++;
            }
            $updated = $service->updated_at ? strtotime((string) $service->updated_at) : null;
            if ($service->status === 'active' && !$this->hasGrants($properties) && $updated !== null && time() - $updated > 3600) {
                $stuck++;
            }
        }
        if ($stuck) {
            $add('error', $stuck . ' active subscription(s) without an invitation', 'Paid, but no invitation was made (the queue, or Wizarr was down). Use "New invitation" on them.', 'page:paywarr-customers');
        }
        if ($failed) {
            $add('warning', $failed . ' customer(s) whose link was used without a user', 'E.g. a Plex share that failed. See Customers → Problems.', 'page:paywarr-customers');
        }
        usort($rows, fn ($a, $b) => ($a['level'] === 'error' ? 0 : 1) <=> ($b['level'] === 'error' ? 0 : 1));

        return $rows;
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
        foreach (['wizarr:nav-watch:', 'wizarr:can-request:', 'wizarr:can-report:', 'wizarr:can-refer:', 'wizarr:has-subscription:', 'paywarr-stats:has-subscription:'] as $key) {
            Cache::forget($key . $user);
        }
    }
}
