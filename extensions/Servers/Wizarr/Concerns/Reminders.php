<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reminders, by mail and a private Discord message (hourly, each once):
 * a trial ends in two days, a link not used after two days, and a renewal
 * in three days with the invoice unpaid. Plus a watch on Paymenter's queue:
 * if it stands still, Checks and the dashboard say so. Part of Wizarr
 * (Paywarr).
 */
trait Reminders
{
    private const PROP_REMINDED_TRIAL = 'reminded_trial';

    private const PROP_REMINDED_LINK = 'reminded_link';

    private const PROP_REMINDED_RENEWAL = 'reminded_renewal';

    /**
     * Hourly. Each reminder is sent once per trial end, per link and per
     * renewal date, and only when switched on for the server.
     *
     * @return array<int, string[]> service ID => reminders sent
     */
    public function sendReminders(?iterable $services = null, ?int $now = null, ?callable $invoiceFor = null): array
    {
        $now ??= time();
        $services ??= Service::where('status', 'active')
            ->whereHas('product.server', fn ($query) => $query->where('extension', 'Wizarr'))
            ->with(['product.server', 'product.settings', 'properties', 'user'])->get();
        $invoiceFor ??= fn (Service $service) => method_exists($service, 'invoices') ? $service->invoices()->where('status', 'pending')->orderBy('due_at')->first() : null;
        $sent = [];
        foreach ($services as $service) {
            if (($service->status ?? '') !== 'active' || ($service->product?->server?->extension ?? null) !== 'Wizarr') {
                continue;
            }
            try {
                $ext = $this->forServiceServer($service);
                $done = $ext->remindersFor($service, $now, $invoiceFor);
                if ($done) {
                    $sent[$service->id] = $done;
                }
            } catch (Throwable $e) {
                Log::warning('Wizarr: reminders for service ' . $service->id . ' not sent, will retry: ' . $e->getMessage());
            }
        }

        return $sent;
    }

    private function remindersFor(Service $service, int $now, callable $invoiceFor): array
    {
        $settings = ExtensionHelper::settingsToArray($service->product->settings);
        if ($this->isTopUp($settings) || $this->isGift($settings)) {
            return [];
        }
        $properties = ExtensionHelper::getServiceProperties($service);
        $trial = $this->trialSettings($settings, $properties);
        $product = (string) ($service->product->name ?? '');
        $done = [];

        // The trial ends within two days.
        $ends = strtotime((string) ($properties[self::PROP_TRIAL_ENDS] ?? '')) ?: null;
        if ($this->truthy($this->config('remind_trial') ?? false) && $this->isTrial($trial) && $ends !== null && $ends > $now && $ends - $now <= 2 * 86400
            && empty($properties[self::PROP_TRIAL_CONVERTED]) && ($properties[self::PROP_REMINDED_TRIAL] ?? '') !== (string) $ends) {
            $this->notifyMail($service, 'trial_ending', ['product' => $product, 'date' => date(self::t('M j, Y'), $ends)], $this->shopUrl());
            $this->setProperty($service, self::PROP_REMINDED_TRIAL, 'Reminded: trial ends', (string) $ends);
            $done[] = 'trial';
        }

        // A link not used after two days.
        if ($this->truthy($this->config('remind_link') ?? false)) {
            foreach ($this->grantKeys($properties) as $grant) {
                $link = $this->withGrant($grant, function () use ($properties, $settings, $service, $now) {
                    $view = $this->grantView($properties);
                    $url = (string) ($view[self::PROP_INVITE_URL] ?? '');
                    if ($url === '' || !empty($view[self::PROP_USERS]) || !empty($properties[self::PROP_PAUSED])) {
                        return null;
                    }
                    $expires = strtotime((string) ($view[self::PROP_INVITE_EXPIRES] ?? '')) ?: null;
                    if ($expires !== null && $expires <= $now) {
                        return null; // expired: "Get a new invitation link" covers it
                    }
                    $lifetime = $this->linkLifetime($settings);
                    $made = $expires !== null && $lifetime !== null ? $expires - $lifetime * 86400 : ($service->updated_at?->getTimestamp() ?? $now);
                    if ($now - $made < 2 * 86400 || ($properties[self::PROP_REMINDED_LINK] ?? '') === (string) ($view[self::PROP_INVITE_CODE] ?? $url)) {
                        return null;
                    }

                    return ['url' => $url, 'code' => (string) ($view[self::PROP_INVITE_CODE] ?? $url), 'server' => $this->grantLabel($properties)];
                });
                if ($link !== null) {
                    $this->notifyMail($service, 'user_waiting', ['product' => $link['server'] !== '' ? $link['server'] : $product], $link['url']);
                    $this->setProperty($service, self::PROP_REMINDED_LINK, 'Reminded: unused link', $link['code']);
                    $done[] = 'link';

                    break;
                }
            }
        }

        // Renews within three days, the invoice unpaid, and not paid automatically.
        $expires = $service->expires_at ? strtotime((string) $service->expires_at) : null;
        if ($this->truthy($this->config('remind_renewal') ?? false) && !$this->isTrial($trial) && $expires !== null && $expires > $now && $expires - $now <= 3 * 86400
            && empty($service->subscription_id) && ($properties[self::PROP_REMINDED_RENEWAL] ?? '') !== date('Y-m-d', $expires)) {
            $invoice = $invoiceFor($service);
            if ($invoice) {
                $this->notifyMail($service, 'renewal', ['product' => $product, 'date' => date(self::t('M j, Y'), $expires)], $this->invoiceUrl($invoice));
                $this->setProperty($service, self::PROP_REMINDED_RENEWAL, 'Reminded: renewal', date('Y-m-d', $expires));
                $done[] = 'renewal';
            }
        }
        if ($done) {
            Log::info('Wizarr: service ' . $service->id . ' reminded: ' . implode(', ', $done) . '.');
        }

        return $done;
    }

    private function invoiceUrl($invoice): string
    {
        try {
            return route('invoices.show', $invoice);
        } catch (Throwable $e) {
            return $this->accountUrl();
        }
    }

    /* ---------------- Paymenter's queue ---------------- */

    /**
     * Hourly: a tiny job through Paymenter's queue. When it hasn't run a
     * quarter of an hour later, the queue stands still (queueStuckSince()).
     * While one is waiting, no second one is sent.
     */
    public function queueHeartbeat(?int $now = null): void
    {
        $now ??= time();
        if (!function_exists('dispatch') || (function_exists('config') && config('queue.default') === 'sync')) {
            return;
        }
        $sent = (int) Cache::get('paywarr:queue:sent', 0);
        $ran = (int) Cache::get('paywarr:queue:ran', 0);
        if ($sent > 0 && $ran < $sent) {
            return; // the last one hasn't run yet
        }
        Cache::put('paywarr:queue:sent', $now, 7 * 86400);
        dispatch(new \Paymenter\Extensions\Servers\Wizarr\Jobs\QueueHeartbeat);
    }

    /** Since when Paymenter's queue stands still (a job sent and not run for 15 minutes), or null. */
    public static function queueStuckSince(?int $now = null): ?int
    {
        $now ??= time();
        $sent = (int) Cache::get('paywarr:queue:sent', 0);
        $ran = (int) Cache::get('paywarr:queue:ran', 0);

        return $sent > 0 && $ran < $sent && $now - $sent >= 900 ? $sent : null;
    }
}
