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

/** The safety net for failed suspensions, and alerts. Part of Wizarr (Paywarr); uses its constants and helpers. */
trait Health
{
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
        // Media servers down (Service status), with Tracearr's reason when it gives one.
        foreach (self::storeGet('status_servers', []) as $server) {
            if (empty($server['up'])) {
                $problems[] = ($server['name'] ?? 'A server') . ' is down' . (!empty($server['since']) ? ' since ' . date('M j, H:i', (int) $server['since']) : '')
                    . (($server['reason'] ?? '') !== '' ? ': ' . $this->shorten((string) $server['reason']) : '') . '.';
            }
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
}
