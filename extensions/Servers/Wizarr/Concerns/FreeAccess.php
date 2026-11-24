<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Helpers\ExtensionHelper;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Free access that ends when it isn't used (Paywarr → Free access): free
 * subscriptions (price 0, or a product with "Free access") and users on the
 * media servers without a subscription. Who watched nothing for "days" days
 * is warned (mail, and Discord for customers), and "warn_days" later their
 * access goes: Plex's share is removed, Jellyfin, Emby and Silo users are
 * switched off and deleted "delete_days" after that, and a free subscription
 * is cancelled. New users are left alone for "grace_days"; exempt users
 * always. The rules are set on the page. Part of Wizarr (Paywarr).
 */
trait FreeAccess
{
    public static function freeRules(): array
    {
        return self::storeGet('free_rules', []) + ['enabled' => false, 'days' => 30, 'warn_days' => 7, 'grace_days' => 14, 'delete_days' => 30, 'paid_product' => null, 'pay_days' => 7, 'paid_discount' => 0, 'pay_remind_days' => 2];
    }

    public static function setFreeRules(array $rules): void
    {
        self::storePut('free_rules', [
            'enabled' => (bool) ($rules['enabled'] ?? false),
            'days' => max(1, (int) ($rules['days'] ?? 30)),
            'warn_days' => max(0, (int) ($rules['warn_days'] ?? 7)),
            'grace_days' => max(0, (int) ($rules['grace_days'] ?? 14)),
            'delete_days' => max(1, (int) ($rules['delete_days'] ?? 30)),
            'paid_product' => !empty($rules['paid_product']) ? (int) $rules['paid_product'] : null,
            'pay_days' => max(1, (int) ($rules['pay_days'] ?? 7)),
            'paid_discount' => max(0, min(100, (int) ($rules['paid_discount'] ?? 0))),
            'pay_remind_days' => max(0, (int) ($rules['pay_remind_days'] ?? 2)),
        ]);
    }

    public static function freeExempt(): array
    {
        return self::storeGet('free_exempt', []);
    }

    /** Exempt from free access; a user without a subscription is then also kept by the clean-up (and the other way round). */
    public static function setFreeExempt(string $key, bool $exempt): void
    {
        $list = self::freeExempt();
        if ($exempt) {
            $list[$key] = time();
        } else {
            unset($list[$key]);
        }
        self::storePut('free_exempt', $list);
        if (str_starts_with($key, 'user:')) {
            self::setCleanupKeep(substr($key, 5), $exempt);
        }
    }

    public static function freeLog(): array
    {
        return self::storeGet('free_log', []);
    }

    public static function logFreeAll(array $what): void
    {
        foreach ($what as $line) {
            self::logFree($line);
        }
    }

    public static function logFree(string $what): void
    {
        $log = self::freeLog();
        array_unshift($log, ['at' => time(), 'what' => $what]);
        self::storePut('free_log', array_slice($log, 0, 200));
    }

    /** Not a trial, gift card or top-up product. */
    public function plainProduct(array $settings): bool
    {
        return !$this->isTrial($settings) && !$this->isGift($settings) && !$this->isTopUp($settings);
    }

    /** A free subscription: price 0 (not a trial, gift card or top-up), or a product with "Free access". */
    public function isFreeSubscription(Service $service, array $settings): bool
    {
        if ($this->isTrial($settings) || $this->isGift($settings) || $this->isTopUp($settings)) {
            return false;
        }

        return $this->truthy($settings['free_access'] ?? false) || (float) ($service->price ?? 1) <= 0;
    }

    /**
     * Everyone with free access: key => kind (subscription or user), name,
     * email, accounts ("type|username"), since, backend (for users), user
     * (media server user row), service (for subscriptions).
     */
    public function freeMembers(array $inventory, iterable $services): array
    {
        $members = [];
        foreach ($services as $service) {
            if ($service->status !== 'active' || ($service->product?->server?->extension ?? null) !== 'Wizarr') {
                continue;
            }
            if (!$this->isFreeSubscription($service, ExtensionHelper::settingsToArray($service->product->settings ?? []))) {
                continue;
            }
            $properties = ExtensionHelper::getServiceProperties($service);
            $accounts = [];
            foreach ($properties as $key => $value) {
                if ($key === self::PROP_USERS || str_starts_with((string) $key, self::PROP_USERS . ':')) {
                    foreach ($this->parseUsers((string) $value) as $user) {
                        $accounts[] = ['type' => strtolower($user['type']), 'username' => $user['username'], 'id' => (string) $user['id']];
                    }
                }
            }
            $members['service:' . $service->id] = [
                'kind' => 'subscription', 'service' => $service, 'backend' => null, 'user' => null,
                'name' => trim(($service->user->first_name ?? '') . ' ' . ($service->user->last_name ?? '')) ?: (string) ($service->user->email ?? '?'),
                'email' => (string) ($service->user->email ?? ''), 'product' => (string) ($service->product->name ?? ''),
                'accounts' => $accounts, 'since' => $this->timestampOf($service->created_at ?? null),
            ];
        }
        foreach ($inventory as $backend) {
            if (empty($backend['ok'])) {
                continue;
            }
            foreach ($backend['users'] as $user) {
                if ($user['owner'] !== null) {
                    continue;
                }
                $members['user:' . $backend['key'] . '|' . $user['id']] = [
                    'kind' => 'user', 'service' => null, 'backend' => $backend, 'user' => $user,
                    'name' => $user['username'], 'email' => (string) $user['email'], 'product' => (string) $user['server'],
                    'accounts' => [['type' => $user['type'] !== '' ? $user['type'] : ($backend['kind'] === 'silo' ? 'silo' : ''), 'username' => $user['username'], 'id' => (string) $user['id']]],
                    'since' => strtotime((string) $user['created']) ?: null,
                ];
            }
        }

        return $members;
    }

    /**
     * The last play per account ("type|username", Silo "silo|<id>"): from
     * Tracearr's history of the last $days days and Silo's own; kept an hour.
     */
    public function lastPlays(array $members, int $days): array
    {
        return Cache::remember('paywarr:free:last:' . $days, 3600, function () use ($members, $days) {
            $last = [];
            $tracearr = $this->tracearrOrAny();
            if ($tracearr !== null) {
                foreach ($tracearr->history(time() - $days * 86400, 50000) as $play) {
                    if ((int) ($play['duration_ms'] ?? 0) < 120000 && empty($play['watched'])) {
                        continue;
                    }
                    $key = strtolower((string) ($play['server_type'] ?? '')) . '|' . strtolower((string) ($play['user']['username'] ?? ''));
                    $last[$key] = max($last[$key] ?? 0, strtotime((string) ($play['started_at'] ?? '')) ?: 0);
                }
            }
            foreach ($members as $member) {
                foreach ($member['accounts'] as $account) {
                    if ($account['type'] !== 'silo' || !ctype_digit($account['id'])) {
                        continue;
                    }
                    try {
                        $backend = $member['backend'] ?? null;
                        $ext = $backend ? $this->instanceForServer((int) $backend['server_id']) : $this->forServiceServer($member['service']);
                        $play = $ext->client()->watchHistory((int) $account['id'], 1)[0] ?? null;
                        if ($play) {
                            $last['silo|' . $account['id']] = strtotime((string) ($play['started_at'] ?? '')) ?: 0;
                        }
                    } catch (Throwable $e) {
                        // Unknown then; a missing play is not a reason to remove anyone.
                        $last['silo|' . $account['id']] = PHP_INT_MAX;
                    }
                }
            }

            return $last;
        });
    }

    /** The last time any of the member's accounts played something (null: nothing in the window). */
    public function memberLastPlay(array $member, array $lastPlays): ?int
    {
        $best = null;
        foreach ($member['accounts'] as $account) {
            $key = $account['type'] === 'silo' ? 'silo|' . $account['id'] : $account['type'] . '|' . strtolower($account['username']);
            if (isset($lastPlays[$key]) && $lastPlays[$key] > 0) {
                $best = max($best ?? 0, $lastPlays[$key]);
            }
        }

        return $best;
    }

    /**
     * Each member's state now: active, new, exempt, idle (due a warning),
     * warned, removed (switched off, to be deleted), with the dates.
     */
    public function freeRows(array $members, array $lastPlays, ?int $now = null): array
    {
        $now ??= time();
        $rules = self::freeRules();
        $state = self::storeGet('free_state', []);
        $exempt = self::freeExempt();
        $offers = self::storeGet('paid_offers', []);
        foreach (array_keys(self::cleanupKeep()) as $kept) {
            $exempt['user:' . $kept] = true;
        }
        $rows = [];
        foreach ($members as $key => $member) {
            $last = $this->memberLastPlay($member, $lastPlays);
            $since = $member['since'] ?? null;
            $idleFrom = $last ?? $since ?? $now;
            $s = $state[$key] ?? [];
            $status = match (true) {
                isset($exempt[$key]) => 'exempt',
                isset($offers[$key]) => 'offered',
                !empty($s['disabled_at']) => 'removed',
                $since !== null && $now - $since < $rules['grace_days'] * 86400 => 'new',
                !empty($s['warned_at']) && (int) $s['warned_at'] > $idleFrom => 'warned',
                $now - $idleFrom >= $rules['days'] * 86400 => 'idle',
                default => 'active',
            };
            $rows[$key] = $member + [
                'key' => $key, 'last' => $last, 'idle_days' => intdiv(max(0, $now - $idleFrom), 86400), 'status' => $status,
                'warned_at' => $s['warned_at'] ?? null, 'disabled_at' => $s['disabled_at'] ?? null,
                'goes_at' => match ($status) {
                    'warned' => (int) $s['warned_at'] + $rules['warn_days'] * 86400,
                    'active' => $idleFrom + ($rules['days'] + $rules['warn_days']) * 86400,
                    'removed' => (int) $s['disabled_at'] + $rules['delete_days'] * 86400,
                    'offered' => (int) $offers[$key]['until'],
                    default => null,
                },
            ];
        }

        return $rows;
    }

    /**
     * Daily (from the hourly run), when switched on. $members, $lastPlays and
     * $act are for tests (act: fn (string $action, array $row)).
     *
     * @return string[] what was done
     */
    public function runFreeAccess(?array $members = null, ?array $lastPlays = null, ?int $now = null, ?callable $act = null): array
    {
        $now ??= time();
        $rules = self::freeRules();
        if (!$rules['enabled']) {
            return [];
        }
        if ($members === null) {
            if (Cache::get('paywarr:free:ran') === date('Y-m-d', $now)) {
                return [];
            }
            Cache::put('paywarr:free:ran', date('Y-m-d', $now), 2 * 86400);
            try {
                $members = $this->freeMembers(
                    $this->adminInventory(Server::where('extension', 'Wizarr')->with('settings')->get(), \Paymenter\Extensions\Servers\Wizarr\Admin\PaywarrInventory::services()),
                    \Paymenter\Extensions\Servers\Wizarr\Admin\PaywarrInventory::services());
                $lastPlays = $this->lastPlays($members, max(90, $rules['days'] + $rules['warn_days'] + 7));
            } catch (Throwable $e) {
                Log::warning('Wizarr: free access not checked: ' . $e->getMessage());

                return [];
            }
        }
        $act ??= fn (string $action, array $row) => $this->freeAct($action, $row);
        $state = self::storeGet('free_state', []);
        $done = [];
        foreach ($this->freeRows($members, $lastPlays ?? [], $now) as $key => $row) {
            try {
                if ($row['status'] === 'idle') {
                    $warned = $rules['warn_days'] > 0 && $act('warn', $row);
                    $state[$key] = ['warned_at' => $now] + ($state[$key] ?? []);
                    if ($warned) {
                        $done[] = 'Warned ' . $row['name'] . ' (nothing watched for ' . $row['idle_days'] . ' days)';
                    } elseif ($rules['warn_days'] === 0 || $row['email'] === '') {
                        $state[$key]['warned_at'] = $now - $rules['warn_days'] * 86400; // no way to warn: goes now
                        $row['status'] = 'warned';
                        $row['goes_at'] = $now;
                    }
                }
                if ($row['status'] === 'warned' && $row['goes_at'] !== null && $row['goes_at'] <= $now) {
                    $act('remove', $row);
                    if ($row['kind'] === 'subscription' || ($row['user']['type'] ?? '') === 'plex') {
                        unset($state[$key]);
                        $done[] = 'Removed ' . $row['name'] . ($row['kind'] === 'subscription' ? ' (free subscription cancelled)' : ' (Plex share removed)');
                    } else {
                        $state[$key] = ['disabled_at' => $now] + ($state[$key] ?? []);
                        $done[] = 'Switched off ' . $row['name'] . ' on ' . $row['product'];
                    }
                } elseif ($row['status'] === 'removed' && $row['goes_at'] !== null && $row['goes_at'] <= $now) {
                    $act('delete', $row);
                    unset($state[$key]);
                    $done[] = 'Deleted ' . $row['name'] . ' on ' . $row['product'] . ' (switched off ' . $rules['delete_days'] . ' days ago)';
                } elseif ($row['status'] === 'active' && isset($state[$key]['warned_at'])) {
                    unset($state[$key]); // watched again
                }
            } catch (Throwable $e) {
                Log::warning('Wizarr: free access of ' . $row['name'] . ' not handled: ' . $e->getMessage());
            }
        }
        self::storePut('free_state', array_intersect_key($state, $members));
        foreach ($done as $what) {
            self::logFree($what);
        }

        return $done;
    }

    /** Warns, removes or deletes; returns whether a warning reached them. */
    public function freeAct(string $action, array $row): bool
    {
        $rules = self::freeRules();
        if ($action === 'warn') {
            $vars = ['days' => $row['idle_days'], 'date' => date(self::t('M j, Y'), time() + $rules['warn_days'] * 86400)];
            if ($row['kind'] === 'subscription') {
                return $this->forServiceServer($row['service'])->notifyMail($row['service'], 'still_watching', $vars, $this->accountUrl()) !== [];
            }
            $parts = self::mailParts('still_watching', $vars);

            return $parts && $row['email'] !== '' && $this->sendMail($row['email'], $parts['title'], $this->mailLayout($parts['title'], $this->paragraphs($parts['text'])), null, 'essential', 'still_watching');
        }
        if ($row['kind'] === 'subscription') {
            $service = $row['service'];
            ExtensionHelper::terminateServer($service);
            $service->status = 'cancelled';
            $service->save();
            self::logAdmin($service->user, (int) $service->id, 'Free access ended: nothing watched for ' . $row['idle_days'] . ' days', 'Paywarr');

            return true;
        }
        $ext = $this->instanceForServer((int) $row['backend']['server_id']);
        $ext->adminUserAction((int) $row['user']['id'], $action === 'delete' || ($row['user']['type'] ?? '') === 'plex' ? 'delete' : 'disable');

        return true;
    }

    /** Gives $user a free subscription on $product (a product with a 0 price, or with "Free access"). */
    public function giveFreeAccess($user, $product): Service
    {
        $plan = $product->plans()->orderBy('id')->first();
        $service = Service::create(['user_id' => $user->id, 'product_id' => $product->id, 'plan_id' => $plan?->id, 'price' => 0, 'quantity' => 1,
            'currency_code' => (string) ($plan?->prices()->value('currency_code') ?: config('settings.default_currency', 'USD')), 'status' => 'active',
            'expires_at' => $plan && ($plan->type ?? '') !== 'free' && ($plan->type ?? '') !== 'one-time' ? now()->addYears(10) : null]);
        ExtensionHelper::createServer($service->fresh());

        return $service;
    }
}
