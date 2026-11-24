<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Helpers\ExtensionHelper;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Wizarr\SiloClient;
use Throwable;

/**
 * Seats per media server (Paywarr → Capacity): a maximum of users, counted
 * with unused invitations and unpaid orders. A full server sets the stock of
 * its products to 0 (Paymenter then shows "out of stock"), and visitors can
 * join a waiting list; when seats open, the next in line get a mail. Part of
 * Wizarr (Paywarr).
 */
trait Capacity
{
    /** Hours a mailed seat waits for the person before the next in line gets it. */
    public const WAITLIST_HOLD_HOURS = 48;

    /** [backend key|server ID => max users]. */
    public static function capacityLimits(): array
    {
        return self::storeGet('capacity', []);
    }

    public static function setCapacity(string $key, ?int $max): void
    {
        $all = self::capacityLimits();
        if ($max === null || $max < 0) {
            unset($all[$key]);
        } else {
            $all[$key] = $max;
        }
        self::storePut('capacity', $all);
    }

    /** The media server IDs a product gives access to on its backend. */
    private function productMediaServers($product, array $backend): array
    {
        if ($backend['kind'] === 'silo') {
            return [SiloClient::SERVER_ID];
        }
        $ids = $this->idList(ExtensionHelper::settingsToArray($product->settings ?? [])['server_ids'] ?? null);

        return $ids ?: array_map(fn ($s) => (int) $s['id'], $backend['servers'] ?? []);
    }

    /**
     * One row per media server: users, unused invitations, unpaid orders,
     * the maximum and what is free, and the products that use it.
     */
    public function capacityRows(array $inventory, iterable $products, iterable $pending, ?array $limits = null, ?int $now = null): array
    {
        $now ??= time();
        $limits ??= self::capacityLimits();
        $pendingByProduct = [];
        foreach ($pending as $service) {
            $pendingByProduct[(int) $service->product_id] = ($pendingByProduct[(int) $service->product_id] ?? 0) + 1;
        }
        $rows = [];
        foreach ($inventory as $backend) {
            if (empty($backend['ok'])) {
                continue;
            }
            foreach ($backend['servers'] as $server) {
                $open = 0;
                foreach ($backend['invitations'] ?? [] as $invitation) {
                    $expires = $invitation['expires'] !== '' ? strtotime($invitation['expires']) : false;
                    if ($invitation['used_by'] === '' && !in_array(strtolower($invitation['status']), ['used', 'expired'], true) && ($expires === false || $expires > $now)
                        && (!$invitation['servers'] || in_array($server['name'], $invitation['servers'], true))) {
                        $open++;
                    }
                }
                $key = $backend['key'] . '|' . $server['id'];
                $rows[$key] = ['key' => $key, 'backend' => $backend['host'], 'server_id' => (int) $server['id'], 'name' => $server['name'], 'type' => $server['type'],
                    'users' => (int) $server['users'], 'invitations' => $open, 'pending' => 0, 'products' => [], 'max' => isset($limits[$key]) ? (int) $limits[$key] : null];
            }
        }
        foreach ($products as $product) {
            foreach ($inventory as $backend) {
                if (empty($backend['ok']) || !isset($backend['paymenter'][(int) $product->server_id])) {
                    continue;
                }
                foreach ($this->productMediaServers($product, $backend) as $id) {
                    $key = $backend['key'] . '|' . $id;
                    if (isset($rows[$key])) {
                        $rows[$key]['products'][(int) $product->id] = (string) $product->name;
                        $rows[$key]['pending'] += $pendingByProduct[(int) $product->id] ?? 0;
                    }
                }
            }
        }
        foreach ($rows as &$row) {
            $row['used'] = $row['users'] + $row['invitations'] + $row['pending'];
            $row['free'] = $row['max'] === null ? null : max(0, $row['max'] - $row['used']);
        }

        return $rows;
    }

    /** Product ID => free seats (the fewest of its servers with a maximum); products without one are left out. */
    public static function productFreeSeats(array $rows): array
    {
        $free = [];
        foreach ($rows as $row) {
            if ($row['free'] === null) {
                continue;
            }
            foreach (array_keys($row['products']) as $productId) {
                $free[$productId] = min($free[$productId] ?? PHP_INT_MAX, $row['free']);
            }
        }

        return $free;
    }

    /**
     * Every five minutes: the stock of products on servers with a maximum,
     * back to unlimited when the maximum is removed, and the waiting list.
     *
     * @return array<int, int> product ID => free seats
     */
    public function runCapacity(?array $rows = null, ?int $now = null): array
    {
        $now ??= time();
        if ($rows === null) {
            if (!self::capacityLimits() && !self::storeGet('capacity_managed', [])) {
                return [];
            }
            try {
                $rows = $this->capacityRows(\Paymenter\Extensions\Servers\Wizarr\Admin\PaywarrInventory::get(), self::wizarrProducts(),
                    Service::where('status', 'pending')->whereHas('product.server', fn ($q) => $q->where('extension', 'Wizarr'))->get(), null, $now);
            } catch (Throwable $e) {
                Log::info('Wizarr: capacity not checked: ' . $e->getMessage());

                return [];
            }
        }
        $free = self::productFreeSeats($rows);
        $managed = self::storeGet('capacity_managed', []);
        foreach (array_unique([...array_keys($free), ...$managed]) as $productId) {
            try {
                $product = Product::find($productId);
                if (!$product) {
                    continue;
                }
                $stock = array_key_exists($productId, $free) ? $free[$productId] : null;
                if ($product->stock !== $stock) {
                    $product->stock = $stock;
                    $product->save();
                }
            } catch (Throwable $e) {
                Log::warning('Wizarr: stock of product ' . $productId . ' not set: ' . $e->getMessage());
            }
        }
        self::storePut('capacity_managed', array_map('intval', array_keys($free)));
        $this->runWaitlist($free, $now);

        return $free;
    }

    private static function wizarrProducts(): iterable
    {
        return Product::whereHas('server', fn ($q) => $q->where('extension', 'Wizarr'))->with(['settings', 'server'])->get();
    }

    /* ---------------------------------------------------------- waiting list */

    /** [id => ['id', 'product', 'email', 'user', 'at', 'mailed']]. */
    public static function waitlist(): array
    {
        return self::storeGet('waitlist', []);
    }

    /** Puts someone on the list for a product (once per address); returns their place. */
    public static function joinWaitlist(int $productId, string $email, ?int $userId = null, ?int $now = null): int
    {
        $email = strtolower(trim($email));
        $all = self::waitlist();
        foreach ($all as $entry) {
            if ($entry['product'] === $productId && $entry['email'] === $email) {
                return self::waitlistPlace($all, $entry['id']);
            }
        }
        $id = bin2hex(random_bytes(5));
        $all[$id] = ['id' => $id, 'product' => $productId, 'email' => $email, 'user' => $userId, 'at' => $now ?? time(), 'mailed' => null];
        self::storePut('waitlist', $all);

        return self::waitlistPlace($all, $id);
    }

    public static function leaveWaitlist(string $id): void
    {
        $all = self::waitlist();
        unset($all[$id]);
        self::storePut('waitlist', $all);
    }

    private static function waitlistPlace(array $all, string $id): int
    {
        $entry = $all[$id];
        $before = array_filter($all, fn ($e) => $e['product'] === $entry['product'] && $e['mailed'] === null && ($e['at'] < $entry['at'] || ($e['at'] === $entry['at'] && $e['id'] < $id)));

        return count($before) + 1;
    }

    /**
     * Who to mail now: per product with free seats, the next in line, minus
     * seats still held for those mailed within WAITLIST_HOLD_HOURS. Those
     * mailed longer ago lose their turn.
     *
     * @return array{mail: string[], drop: string[]}
     */
    public static function waitlistDue(array $all, array $free, int $now): array
    {
        $mail = $drop = [];
        $byProduct = [];
        foreach ($all as $entry) {
            if ($entry['mailed'] !== null && $entry['mailed'] < $now - self::WAITLIST_HOLD_HOURS * 3600) {
                $drop[] = $entry['id'];
            } else {
                $byProduct[$entry['product']][] = $entry;
            }
        }
        foreach ($byProduct as $productId => $entries) {
            $seats = (int) ($free[$productId] ?? 0);
            if ($seats <= 0) {
                continue;
            }
            $held = count(array_filter($entries, fn ($e) => $e['mailed'] !== null));
            $waiting = array_values(array_filter($entries, fn ($e) => $e['mailed'] === null));
            usort($waiting, fn ($a, $b) => [$a['at'], $a['id']] <=> [$b['at'], $b['id']]);
            foreach (array_slice($waiting, 0, max(0, $seats - $held)) as $entry) {
                $mail[] = $entry['id'];
            }
        }

        return ['mail' => $mail, 'drop' => $drop];
    }

    /** Mails the next in line; drops those who bought or let their turn pass. */
    public function runWaitlist(array $free, ?int $now = null): array
    {
        $now ??= time();
        $all = self::waitlist();
        if (!$all) {
            return [];
        }
        // Bought in the meantime: off the list.
        foreach ($all as $id => $entry) {
            $user = User::where('email', $entry['email'])->first();
            if ($user && Service::where('user_id', $user->id)->where('product_id', $entry['product'])->whereIn('status', ['active', 'pending'])->exists()) {
                unset($all[$id]);
            }
        }
        $due = self::waitlistDue($all, $free, $now);
        foreach ($due['drop'] as $id) {
            unset($all[$id]);
        }
        $sent = [];
        foreach ($due['mail'] as $id) {
            if ($this->mailWaitlistSeat($all[$id])) {
                $all[$id]['mailed'] = $now;
                $sent[] = $id;
            }
        }
        self::storePut('waitlist', $all);

        return $sent;
    }

    /** "A seat is free for you" with the product's checkout. */
    public function mailWaitlistSeat(array $entry): bool
    {
        $product = Product::with('category')->find($entry['product']);
        if (!$product) {
            return false;
        }
        $parts = self::mailParts('waitlist', ['product' => (string) $product->name, 'hours' => (string) self::WAITLIST_HOLD_HOURS]);
        if (!$parts) {
            return false; // switched off under Automatic mails
        }
        try {
            $url = route('products.checkout', ['category' => $product->category->slug, 'product' => $product->slug]);
        } catch (Throwable $e) {
            $url = $this->shopUrl();
        }

        return $this->sendMail($entry['email'], $parts['title'], $this->mailLayout($parts['title'], $this->paragraphs($parts['text']), [$parts['button'], $url]), $entry['user'], 'essential', 'waitlist');
    }

    /** POST /wizarr/waitlist: joins the list for a sold-out product. */
    public function waitlistJoin()
    {
        $product = Product::find((int) request()->input('product'));
        $email = Auth::check() ? (string) Auth::user()->email : trim((string) request()->input('email'));
        $key = 'wizarr-waitlist:' . request()->ip();
        if (!$product || !filter_var($email, FILTER_VALIDATE_EMAIL) || (class_exists(RateLimiter::class) && RateLimiter::tooManyAttempts($key, 5))) {
            return back()->with('paywarr_waitlist', self::t('That email address doesn\'t look right.'));
        }
        if (class_exists(RateLimiter::class)) {
            RateLimiter::hit($key, 3600);
        }
        $place = self::joinWaitlist((int) $product->id, $email, Auth::id());

        return back()->with('paywarr_waitlist', self::t('You are on the waiting list (number :place). We mail you when there is a seat for you.', ['place' => $place]));
    }

    /** On a sold-out product's page: the waiting list form, under "out of stock". */
    public function renderWaitlist(): array
    {
        try {
            if (!request()->routeIs('products.show', 'products.checkout')) {
                return [];
            }
            $product = request()->route('product');
            if (is_string($product)) {
                $product = Product::where('slug', $product)->first();
            }
            if (!$product instanceof Product || $product->stock !== 0 || !in_array((int) $product->id, self::storeGet('capacity_managed', []), true)) {
                return [];
            }
        } catch (Throwable $e) {
            return [];
        }
        $e = fn ($v) => e((string) $v);
        $done = (string) session('paywarr_waitlist', '');
        $html = '<div id="paywarr-waitlist" class="bg-background-secondary border border-neutral" style="border-radius:1rem;padding:1.1rem 1.3rem;margin:1rem 0;max-width:40rem">'
            . '<div style="font-weight:700;font-size:1.1rem;margin-bottom:.3rem">' . $e(self::t('Sold out right now')) . '</div>';
        if ($done !== '') {
            $html .= '<div style="opacity:.9">' . $e($done) . '</div>';
        } else {
            $html .= '<div style="opacity:.8;margin-bottom:.7rem">' . $e(self::t('Join the waiting list and we mail you, in turn, when a seat opens.')) . '</div>'
                . '<form method="post" action="' . $e(url('/wizarr/waitlist')) . '" style="display:flex;gap:.5rem;flex-wrap:wrap">' . csrf_field()
                . '<input type="hidden" name="product" value="' . (int) $product->id . '">'
                . (Auth::check() ? '' : '<input type="email" name="email" required placeholder="' . $e(self::t('Your email')) . '" class="bg-background border border-neutral rounded-lg px-3 py-2" style="flex:1;min-width:14rem">')
                . '<button type="submit" class="bg-primary text-white font-semibold rounded-lg px-4 py-2">' . $e(self::t('Join the waiting list')) . '</button></form>';
        }
        $html .= '</div><script>(function(){var b=document.getElementById("paywarr-waitlist");if(!b)return;var t=document.querySelector("main h1, main h2");'
            . 'if(t&&t.parentNode){t.parentNode.insertBefore(b,t.nextSibling);}else{(document.querySelector("main")||document.body).prepend(b);}})();</script>';

        return ['view' => new HtmlString($html)];
    }
}
