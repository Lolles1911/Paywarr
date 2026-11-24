<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Clean-up of the media servers: a user that belongs to no subscription for
 * "cleanup_disable_days" days is switched off, and "cleanup_delete_days"
 * days after that deleted; expired invitations that belong to nobody are
 * removed a week after they expired. Users you keep (Paywarr → Media server
 * users → Keep) are never touched. Everything done is logged. Part of Wizarr
 * (Paywarr).
 */
trait Cleanup
{
    public static function cleanupKeep(): array
    {
        return self::storeGet('cleanup_keep', []);
    }

    public static function setCleanupKeep(string $key, bool $keep): void
    {
        $list = self::cleanupKeep();
        if ($keep) {
            $list[$key] = time();
        } else {
            unset($list[$key]);
        }
        self::storePut('cleanup_keep', $list);
    }

    /** Users without a subscription, as followed: "backend|id" => username, server, first_seen, disabled_at. */
    public static function cleanupState(): array
    {
        return self::storeGet('cleanup_state', []);
    }

    public static function cleanupLog(): array
    {
        return self::storeGet('cleanup_log', []);
    }

    private static function logCleanup(string $what): void
    {
        $log = self::cleanupLog();
        array_unshift($log, ['at' => time(), 'what' => $what]);
        self::storePut('cleanup_log', array_slice($log, 0, 100));
    }

    /**
     * Daily (from the hourly run). $inventory and $act are for tests
     * (adminInventory() and fn (backend, action, id)).
     *
     * @return string[] what was done
     */
    public function runCleanup(?array $inventory = null, ?int $now = null, ?callable $act = null): array
    {
        $now ??= time();
        if ($inventory === null) {
            if (Cache::get('paywarr:cleanup:ran') === date('Y-m-d', $now)) {
                return [];
            }
            Cache::put('paywarr:cleanup:ran', date('Y-m-d', $now), 2 * 86400);
            try {
                $inventory = $this->adminInventory(Server::where('extension', 'Wizarr')->with('settings')->get(),
                    \Paymenter\Extensions\Servers\Wizarr\Admin\PaywarrInventory::services());
            } catch (Throwable $e) {
                Log::warning('Wizarr: clean-up skipped: ' . $e->getMessage());

                return [];
            }
        }
        $state = self::cleanupState();
        $keep = self::cleanupKeep();
        $testing = $act !== null;
        $act ??= fn (array $b, string $action, int $id) => $action === 'invitation' ? $this->instanceForServer((int) $b['server_id'])->adminDeleteInvitation($id) : $this->instanceForServer((int) $b['server_id'])->adminUserAction($id, $action);
        $done = [];
        $seen = [];
        foreach ($inventory as $backend) {
            if (empty($backend['ok'])) {
                // Can't see its users: what is followed stays as it is.
                foreach ($state as $key => $row) {
                    if (str_starts_with($key, $backend['key'] . '|')) {
                        $seen[$key] = true;
                    }
                }

                continue;
            }
            $ext = $testing ? $this : $this->instanceForServer((int) $backend['server_id']);
            $disableDays = (int) ($ext->config('cleanup_disable_days') ?? 0);
            $deleteDays = (int) ($ext->config('cleanup_delete_days') ?? 0);
            if ($disableDays < 1) {
                continue;
            }
            foreach ($backend['users'] as $user) {
                $key = $backend['key'] . '|' . $user['id'];
                if ($user['owner'] !== null || isset($keep[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $row = $state[$key] ?? ['username' => $user['username'], 'server' => $user['server'], 'first_seen' => $now, 'disabled_at' => null];
                $label = $user['username'] . ' on ' . $user['server'];
                try {
                    if ($row['disabled_at'] === null && $now - $row['first_seen'] >= $disableDays * 86400) {
                        if ($user['disabled'] !== true && $user['type'] !== 'plex') {
                            $act($backend, 'disable', (int) $user['id']);
                            $done[] = 'Switched off ' . $label . ' (no subscription for ' . $disableDays . ' days)';
                        }
                        $row['disabled_at'] = $now;
                    } elseif ($row['disabled_at'] !== null && $deleteDays > 0 && $now - $row['disabled_at'] >= $deleteDays * 86400) {
                        $act($backend, 'delete', (int) $user['id']);
                        $done[] = 'Deleted ' . $label . ' (switched off ' . $deleteDays . ' days ago)';
                        unset($state[$key], $seen[$key]);

                        continue;
                    }
                } catch (Throwable $e) {
                    Log::warning('Wizarr: clean-up of ' . $label . ' not done: ' . $e->getMessage());
                }
                $state[$key] = $row;
            }
            foreach ($backend['invitations'] as $invitation) {
                $expires = strtotime((string) $invitation['expires']) ?: null;
                if ($invitation['owner'] === null && $invitation['status'] === 'expired' && $expires !== null && $now - $expires >= 7 * 86400) {
                    try {
                        $act($backend, 'invitation', (int) $invitation['id']);
                        $done[] = 'Removed expired invitation ' . $invitation['code'];
                    } catch (Throwable $e) {
                        Log::info('Wizarr: expired invitation ' . $invitation['code'] . ' not removed: ' . $e->getMessage());
                    }
                }
            }
        }
        // Users that got a subscription (or are kept, or gone) are no longer followed.
        $state = array_intersect_key($state, $seen);
        self::storePut('cleanup_state', $state);
        foreach ($done as $what) {
            self::logCleanup($what);
        }
        if ($done) {
            Log::info('Wizarr: clean-up: ' . implode('; ', $done) . '.');
        }

        return $done;
    }

    private function instanceForServer(int $serverId): static
    {
        $server = Server::with('settings')->find($serverId);

        return $server ? new static(\App\Helpers\self::serverSettings($server)) : $this;
    }
}
