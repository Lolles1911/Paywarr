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

/** Trial products: the clock, the end, the take-over by a purchase. Part of Wizarr (Paywarr); uses its constants and helpers. */
trait Trials
{
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
}
