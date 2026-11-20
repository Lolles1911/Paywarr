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

/** Discord: membership, the shop gate, roles, logging in, leavers. Part of Wizarr (Paywarr); uses its constants and helpers. */
trait Discord
{
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
}
