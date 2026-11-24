<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Models\Service;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Forgot something?": an order of a Paywarr subscription whose first
 * invoice is still unpaid "cart_reminder_hours" hours later gets one
 * friendly mail with the link to pay; with "cart_reminder_again_hours" a
 * second one later. Orders older than a week are left alone. Part of Wizarr
 * (Paywarr).
 */
trait CartReminder
{
    private const PROP_CART_REMINDED = 'cart_reminded';

    /**
     * Hourly. $invoiceFor is for tests: fn (Service) => the unpaid invoice or null.
     *
     * @return int[] the services reminded
     */
    public function sendCartReminders(?iterable $services = null, ?int $now = null, ?callable $invoiceFor = null): array
    {
        if (($ext = $this->anyServer('cart_reminder_hours')) !== $this) {
            return $ext->sendCartReminders($services, $now, $invoiceFor);
        }
        $now ??= time();
        $first = (int) ($this->config('cart_reminder_hours') ?? 0);
        if ($first < 1) {
            return [];
        }
        $again = (int) ($this->config('cart_reminder_again_hours') ?? 0);
        $services ??= Service::where('status', 'pending')->whereHas('product.server', fn ($q) => $q->where('extension', 'Wizarr'))
            ->with(['product.server', 'product.settings', 'properties', 'user'])->get();
        $invoiceFor ??= fn (Service $service) => method_exists($service, 'invoices') ? $service->invoices()->where('status', 'pending')->orderBy('id')->first() : null;
        $sent = [];
        foreach ($services as $service) {
            if ($service->status !== 'pending' || !$service->user) {
                continue;
            }
            $created = $this->timestampOf($service->created_at ?? null);
            if ($created === null || $now - $created > 7 * 86400) {
                continue;
            }
            $count = (int) ($this->propertyValue($service, self::PROP_CART_REMINDED) ?? 0);
            $due = match ($count) {
                0 => $now - $created >= $first * 3600,
                1 => $again > $first && $now - $created >= $again * 3600,
                default => false,
            };
            if (!$due) {
                continue;
            }
            try {
                $invoice = $invoiceFor($service);
                if (!$invoice) {
                    continue;
                }
                $product = (string) ($service->product->name ?? '');
                $this->notifyCustomer($service, self::t('Forgot something?'),
                    self::t('Your order of :product is waiting for payment. Pay the invoice and your access is ready a moment later.', ['product' => $product]),
                    [self::t('Pay the invoice'), $this->invoiceUrl($invoice)], discord: false, kind: 'reminders');
                $this->setProperty($service, self::PROP_CART_REMINDED, 'Reminded of the unpaid order', (string) ($count + 1));
                $sent[] = (int) $service->id;
            } catch (Throwable $e) {
                Log::warning('Wizarr: order ' . $service->id . ' not reminded: ' . $e->getMessage());
            }
        }

        return $sent;
    }
}
