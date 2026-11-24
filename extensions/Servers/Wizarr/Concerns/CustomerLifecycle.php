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

/** A customer deleted in Paymenter, and refunds. Part of Wizarr (Paywarr); uses its constants and helpers. */
trait CustomerLifecycle
{
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
            $settings = self::serverSettings($server);
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
}
