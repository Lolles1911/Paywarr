<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * "Save with yearly" (/wizarr/yearly): a customer paying monthly switches to
 * the product's yearly plan (made in Paymenter, at a lower price). It takes
 * effect at the next renewal: the next invoice is for a year. Until it is
 * paid they can keep monthly. Part of Wizarr (Paywarr).
 */
trait Yearly
{
    public const PROP_YEARLY_FROM = 'paywarr_yearly_from';

    /** Periods of a plan in a year (12 for monthly), or 0 when it isn't shorter than a year. */
    public static function periodsPerYear(string $unit, int $period): int
    {
        $period = max(1, $period);

        return match ($unit) {
            'month' => $period < 12 && 12 % $period === 0 ? intdiv(12, $period) : 0,
            'week' => $period < 52 ? intdiv(52, $period) : 0,
            default => 0,
        };
    }

    /** What the customer saves a year, and in percent (0 when yearly isn't cheaper). */
    public static function yearlySaving(float $perPeriod, int $periods, float $yearly): array
    {
        $now = round($perPeriod * $periods, 2);
        $save = round($now - $yearly, 2);

        return ['now' => $now, 'save' => max(0, $save), 'percent' => $now > 0 && $save > 0 ? (int) floor($save / $now * 100) : 0];
    }

    /**
     * The offer for a subscription: ['plan', 'yearly', 'now', 'save', 'percent'],
     * or null (not monthly, paid automatically, no cheaper yearly plan).
     */
    public function yearlyOffer(Service $service): ?array
    {
        try {
            $plan = $service->plan;
            if ($service->status !== 'active' || !empty($service->subscription_id) || !$plan || $plan->type !== 'recurring') {
                return null;
            }
            $periods = self::periodsPerYear((string) $plan->billing_unit, (int) $plan->billing_period);
            if ($periods === 0) {
                return null;
            }
            $yearlyPlan = $service->product->plans()->where('type', 'recurring')->get()
                ->first(fn ($p) => ($p->billing_unit === 'year' && (int) $p->billing_period === 1) || ($p->billing_unit === 'month' && (int) $p->billing_period === 12));
            if (!$yearlyPlan) {
                return null;
            }
            $copy = clone $service;
            $copy->plan_id = $yearlyPlan->id;
            $copy->setRelation('plan', $yearlyPlan);
            $yearly = (float) $copy->calculatePrice();
            $saving = self::yearlySaving((float) $service->price, $periods, $yearly);

            return $saving['save'] > 0 ? ['plan' => $yearlyPlan, 'yearly' => $yearly] + $saving : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Switches to the yearly plan; the next (or the waiting) renewal invoice is for a year. */
    public function switchToYearly(Service $service, array $offer): void
    {
        $this->setProperty($service, self::PROP_YEARLY_FROM, 'Monthly before "Save with yearly"', $service->plan_id . '|' . $service->price . '|' . time());
        $service->plan_id = $offer['plan']->id;
        $service->setRelation('plan', $offer['plan']);
        $service->price = number_format($offer['yearly'], 2, '.', '');
        $service->save();
        $this->repriceWaitingInvoice($service);
        self::logAdmin($service->user, (int) $service->id, 'Switched to yearly (' . $service->price . ' ' . $service->currency_code . ')', 'customer');
    }

    /** Back to monthly, while the yearly invoice isn't paid. */
    public function keepMonthly(Service $service): bool
    {
        $from = (string) ($this->propertyValue($service, self::PROP_YEARLY_FROM) ?? '');
        if (!str_contains($from, '|')) {
            return false;
        }
        [$planId, $price] = explode('|', $from . '|', 3);
        $service->plan_id = (int) $planId;
        $service->unsetRelation('plan');
        $service->price = $price;
        $service->save();
        $this->repriceWaitingInvoice($service);
        $this->setProperty($service, self::PROP_YEARLY_FROM, '', null);
        self::logAdmin($service->user, (int) $service->id, 'Kept monthly after all', 'customer');

        return true;
    }

    /** A renewal invoice already waiting: its line gets the new price and text. */
    private function repriceWaitingInvoice(Service $service): void
    {
        try {
            foreach ($service->invoices()->where('status', 'pending')->get() as $invoice) {
                foreach ($invoice->items()->where('reference_type', Service::class)->where('reference_id', $service->id)->get() as $item) {
                    $item->update(['price' => $service->price, 'description' => $service->fresh()->description]);
                }
            }
        } catch (Throwable $e) {
            Log::warning('Wizarr: waiting invoice of service ' . $service->id . ' not changed to the new plan: ' . $e->getMessage());
        }
    }

    /** Hourly: a yearly invoice paid since the switch makes it final. */
    public function finishYearlySwitches(): int
    {
        $done = 0;
        try {
            foreach (Service::whereHas('properties', fn ($q) => $q->where('key', self::PROP_YEARLY_FROM))->with('properties')->get() as $service) {
                $at = (int) (explode('|', (string) $this->propertyValue($service, self::PROP_YEARLY_FROM) . '||')[2] ?: 0);
                if ($service->invoices()->where('status', 'paid')->where('updated_at', '>=', date('Y-m-d H:i:s', $at))->exists()) {
                    $this->setProperty($service, self::PROP_YEARLY_FROM, '', null);
                    $done++;
                }
            }
        } catch (Throwable $e) {
            Log::info('Wizarr: yearly switches not checked: ' . $e->getMessage());
        }

        return $done;
    }

    /** Whether a switch can still be undone: switched, and no paid invoice since. */
    private function yearlyPending(Service $service): bool
    {
        return str_contains((string) ($this->propertyValue($service, self::PROP_YEARLY_FROM) ?? ''), '|');
    }

    /** The customer's offers: [service, offer|null, pending]. */
    public function yearlyChoices($user): array
    {
        $rows = [];
        foreach (Service::where('user_id', $user->id)->where('status', 'active')->whereHas('product.server', fn ($q) => $q->where('extension', 'Wizarr'))->with(['product', 'plan', 'properties'])->get() as $service) {
            $pending = $this->yearlyPending($service);
            $offer = $pending ? null : $this->yearlyOffer($service);
            if ($pending || $offer) {
                $rows[] = ['service' => $service, 'offer' => $offer, 'pending' => $pending];
            }
        }

        return $rows;
    }

    private function money(float $amount, Service $service): string
    {
        return number_format($amount, 2, ',', '.') . ' ' . $service->currency_code;
    }

    /** GET and POST /wizarr/yearly */
    public function yearlyPage()
    {
        $user = Auth::user();
        $notice = $error = '';
        if (request()->isMethod('post')) {
            $service = Service::where('user_id', $user->id)->find((int) request()->input('service'));
            if ($service && request()->input('do') === 'switch' && ($offer = $this->yearlyOffer($service))) {
                $this->switchToYearly($service, $offer);
                $notice = self::t('Done: from your next renewal you pay for a year at a time.');
            } elseif ($service && request()->input('do') === 'undo' && $this->keepMonthly($service)) {
                $notice = self::t('You keep paying monthly.');
            } else {
                $error = self::t('That didn\'t work. Please try again.');
            }
        }
        $e = fn ($v) => e((string) $v);
        $html = '';
        foreach ($this->yearlyChoices($user) as $row) {
            $service = $row['service'];
            $renews = $service->expires_at ? date(self::t('M j, Y'), strtotime((string) $service->expires_at)) : '';
            $html .= '<div class="bg-background-secondary" style="border-radius:1rem;padding:1.2rem 1.4rem;margin-bottom:1rem"><div style="font-weight:800;font-size:1.2rem">' . $e($service->product->name ?? '') . '</div>';
            if ($row['pending']) {
                $html .= '<p style="margin:.4rem 0 .8rem">' . $e(self::t('Switches to yearly at your next renewal (:date): :price for a year.', ['date' => $renews, 'price' => $this->money((float) $service->price, $service)])) . '</p>'
                    . '<form method="post">' . csrf_field() . '<input type="hidden" name="service" value="' . (int) $service->id . '"><input type="hidden" name="do" value="undo">'
                    . '<button type="submit" class="border border-neutral rounded-lg px-4 py-2">' . $e(self::t('Keep paying monthly')) . '</button></form>';
            } else {
                $o = $row['offer'];
                $html .= '<div style="font-size:1.6rem;font-weight:800;margin:.5rem 0 .1rem">' . $e(self::t('Save :percent%', ['percent' => $o['percent']])) . '</div>'
                    . '<p style="opacity:.85;margin-bottom:.9rem">' . $e(self::t(':yearly for a year instead of :now (:price a period). You save :save.', [
                        'yearly' => $this->money($o['yearly'], $service), 'now' => $this->money($o['now'], $service), 'price' => $this->money((float) $service->price, $service), 'save' => $this->money($o['save'], $service)])) . '</p>'
                    . '<p style="opacity:.7;font-size:.9rem;margin-bottom:.9rem">' . $e(self::t('Nothing changes now: from your next renewal (:date) you pay for a year at a time. You can change your mind until that invoice is paid.', ['date' => $renews])) . '</p>'
                    . '<form method="post">' . csrf_field() . '<input type="hidden" name="service" value="' . (int) $service->id . '"><input type="hidden" name="do" value="switch">'
                    . '<button type="submit" class="bg-primary text-white font-semibold rounded-lg px-4 py-2">' . $e(self::t('Switch to yearly')) . '</button></form>';
            }
            $html .= '</div>';
        }
        if ($html === '') {
            $html = '<p style="opacity:.8">' . $e(self::t('You have no subscription that can switch to yearly right now.')) . '</p>';
        }

        return view('layouts.app', ['slot' => new HtmlString($this->pageFrame(self::t('Save with yearly'), $html, $notice, $error)), 'title' => self::t('Save with yearly')]);
    }
}
