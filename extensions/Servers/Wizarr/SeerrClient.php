<?php

namespace Paymenter\Extensions\Servers\Wizarr;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The few Seerr calls the add-on needs. Written against Seerr v3.5.1
 * (server/routes/user, server/routes/settings, server/middleware/auth.ts).
 *
 * The API key acts as Seerr's owner account (user 1), which may import,
 * change and delete users.
 */
class SeerrClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeout = 15,
    ) {}

    /** GET /api/v1/status: Seerr's version. */
    public function version(): string
    {
        return (string) ($this->call('get', '/status')['version'] ?? '?');
    }

    /**
     * Jellyfin (or Emby) users as Seerr sees them.
     *
     * @return array<int, array{id: string, username: string}>
     */
    public function mediaServerUsers(): array
    {
        return array_map(
            fn ($u) => ['id' => (string) $u['id'], 'username' => (string) $u['username']],
            $this->call('get', '/settings/jellyfin/users') ?? [],
        );
    }

    /** Seerr's user ID for a Jellyfin user, or null if not imported. */
    public function userIdForJellyfinUser(string $jellyfinUserId): ?int
    {
        $user = $this->call('get', '/user/jellyfin/' . rawurlencode($jellyfinUserId), notFound: true);

        return isset($user['id']) ? (int) $user['id'] : null;
    }

    /** Imports one Jellyfin user and returns the new Seerr user ID. */
    public function importJellyfinUser(string $jellyfinUserId): int
    {
        $created = $this->call('post', '/user/import-from-jellyfin', ['jellyfinUserIds' => [$jellyfinUserId]]) ?? [];
        if (isset($created[0]['id'])) {
            return (int) $created[0]['id'];
        }

        // Seerr returns only users it created; already there means someone else did.
        $existing = $this->userIdForJellyfinUser($jellyfinUserId);
        if ($existing !== null) {
            return $existing;
        }

        throw new SeerrException('Seerr did not import the Jellyfin user ' . $jellyfinUserId . '.');
    }

    public function userExists(int $id): bool
    {
        return $this->call('get', '/user/' . $id, notFound: true) !== null;
    }

    public function permissions(int $id): int
    {
        return (int) ($this->call('get', '/user/' . $id . '/settings/permissions')['permissions'] ?? 0);
    }

    public function setPermissions(int $id, int $permissions): void
    {
        $this->call('post', '/user/' . $id . '/settings/permissions', ['permissions' => $permissions]);
    }

    /**
     * Plex users who can be imported: shared with the owner's Plex server and
     * without a Seerr account yet (Seerr leaves out the ones that have one).
     *
     * @return array<int, array{id: string, username: string, email: string}>
     */
    public function plexUsersToImport(): array
    {
        $users = [];
        foreach ($this->call('get', '/settings/plex/users') ?? [] as $user) {
            $users[] = ['id' => (string) ($user['id'] ?? ''), 'username' => (string) ($user['username'] ?? ''), 'email' => (string) ($user['email'] ?? '')];
        }

        return $users;
    }

    /** Imports one Plex user; their new Seerr user ID, or null when none was created. */
    public function importPlexUser(string $plexId): ?int
    {
        $created = ($this->call('post', '/user/import-from-plex', ['plexIds' => [$plexId]]) ?? [])['createdUsers'] ?? [];

        return isset($created[0]['id']) ? (int) $created[0]['id'] : null;
    }

    /** The media server Seerr is connected to: 'plex', 'jellyfin', 'emby', or null when none is set up. */
    public function mediaServerType(): ?string
    {
        $type = (int) (($this->call('get', '/settings/main') ?? [])['mediaServerType'] ?? 0);

        return [1 => 'plex', 2 => 'jellyfin', 3 => 'emby'][$type] ?? null;
    }

    /**
     * The user's own request limits: [limit, days], each null when Seerr's
     * defaults apply. Movies and TV are set alike, so the movie values are read.
     *
     * @return array{0: ?int, 1: ?int}
     */
    public function quota(int $id): array
    {
        $settings = $this->call('get', '/user/' . $id . '/settings/main') ?? [];

        return [
            isset($settings['movieQuotaLimit']) ? (int) $settings['movieQuotaLimit'] : null,
            isset($settings['movieQuotaDays']) ? (int) $settings['movieQuotaDays'] : null,
        ];
    }

    /**
     * Sets how many movies and how many TV seasons the user may request per
     * $days days. null = Seerr's defaults; limit 0 = no limit.
     *
     * Seerr saves this together with the user's name, e-mail and other
     * settings, and empties whatever is not sent, so those are read first and
     * sent back unchanged.
     */
    public function setQuota(int $id, ?int $limit, ?int $days): void
    {
        $current = $this->call('get', '/user/' . $id . '/settings/main') ?? [];
        $keep = array_intersect_key($current, array_flip([
            'username', 'email', 'locale', 'discoverRegion', 'streamingRegion', 'originalLanguage', 'watchlistSyncMovies', 'watchlistSyncTv',
        ]));
        $this->call('post', '/user/' . $id . '/settings/main', $keep + [
            'movieQuotaLimit' => $limit,
            'movieQuotaDays' => $days,
            'tvQuotaLimit' => $limit,
            'tvQuotaDays' => $days,
        ]);
    }

    /** Deletes the user and, as Seerr does, their requests. Gone already is fine. */
    public function deleteUser(int $id): void
    {
        $this->call('delete', '/user/' . $id, notFound: true);
    }

    private function call(string $method, string $path, ?array $data = null, bool $notFound = false): ?array
    {
        $url = rtrim($this->baseUrl, '/') . '/api/v1' . $path;
        try {
            $request = Http::withHeaders(['X-Api-Key' => $this->apiKey, 'Accept' => 'application/json'])->timeout($this->timeout);
            $response = match ($method) {
                'get' => $request->get($url),
                'post' => $request->post($url, $data ?? []),
                'delete' => $request->delete($url),
            };
        } catch (Throwable $e) {
            throw new SeerrException('Could not reach Seerr at ' . $url . ': ' . $e->getMessage());
        }

        $status = $response->status();
        $body = $response->json();

        if ($status >= 200 && $status < 300) {
            return is_array($body) ? $body : [];
        }
        if ($status === 404 && $notFound) {
            return null;
        }

        $message = is_array($body) ? (string) ($body['message'] ?? '') : '';
        throw new SeerrException(match (true) {
            $status === 401 || $status === 403 && $message === '' => 'Seerr rejected the API key (' . $status . '). Check "Seerr API key" on the Wizarr server.',
            stripos($message, 'csrf') !== false => 'Seerr\'s "CSRF Protection" blocks API calls. Turn it off in Seerr: Settings -> Network.',
            default => 'Seerr answered ' . $status . ' for ' . strtoupper($method) . ' ' . $path . ($message !== '' ? ': ' . $message : '') . '.',
        });
    }
}
