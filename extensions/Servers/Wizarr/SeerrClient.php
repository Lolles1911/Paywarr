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
    /**
     * Movies and shows matching $query that are on the media server
     * (available, or partly for a show): [type ("movie"|"tv"), id (TMDB),
     * title, year, posterPath].
     */
    public function searchAvailable(string $query): array
    {
        $answer = $this->call('get', '/search?' . http_build_query(['query' => $query, 'page' => 1], '', '&', PHP_QUERY_RFC3986)) ?? [];
        $found = [];
        foreach ($answer['results'] ?? [] as $result) {
            $type = $result['mediaType'] ?? '';
            if (!in_array($type, ['movie', 'tv'], true) || !in_array((int) ($result['mediaInfo']['status'] ?? 0), [4, 5], true)) {
                continue;
            }
            $found[] = $this->titleRow($type, $result);
        }

        return $found;
    }

    /** One movie or show by its TMDB ID, as searchAvailable() lists it; null when Seerr doesn't know it. */
    public function title(string $type, int $id): ?array
    {
        if (!in_array($type, ['movie', 'tv'], true)) {
            return null;
        }
        $answer = $this->call('get', '/' . $type . '/' . $id, notFound: true);

        return $answer === null ? null : $this->titleRow($type, $answer);
    }

    private function titleRow(string $type, array $item): array
    {
        $date = (string) ($item['releaseDate'] ?? $item['firstAirDate'] ?? '');

        return [
            'type' => $type,
            'id' => (int) ($item['id'] ?? 0),
            'title' => (string) ($item['title'] ?? $item['name'] ?? ''),
            'year' => preg_match('/^\d{4}/', $date, $m) ? (int) $m[0] : null,
            'posterPath' => preg_match('~^/[A-Za-z0-9._-]+$~', (string) ($item['posterPath'] ?? '')) ? (string) $item['posterPath'] : null,
        ];
    }

    /* ------------------------------------------------------------------
     | The Requests tab: everything as the customer (X-API-User)
     * ----------------------------------------------------------------- */

    /**
     * How much the user may still request: one part for movies, one for TV
     * seasons (Seerr counts them apart). Unlimited when Seerr sets no limit.
     */
    public function requestQuota(int $user): array
    {
        $quota = $this->call('get', '/user/' . $user . '/quota') ?? [];
        $parts = [];
        foreach (['movie' => 'movies', 'tv' => 'tv'] as $key => $label) {
            $part = $quota[$key] ?? [];
            if (!empty($part['limit'])) {
                $parts[] = ['label' => $label, 'used' => (int) ($part['used'] ?? 0), 'limit' => (int) $part['limit'],
                    'remaining' => max(0, (int) ($part['remaining'] ?? ((int) $part['limit'] - (int) ($part['used'] ?? 0)))), 'days' => (int) ($part['days'] ?? 0)];
            }
        }

        return ['unlimited' => $parts === [], 'parts' => $parts];
    }

    /** Movies and shows matching $query, with their state on the server. */
    public function requestSearch(int $user, string $query): array
    {
        $answer = $this->call('get', '/search?' . http_build_query(['query' => $query, 'page' => 1], '', '&', PHP_QUERY_RFC3986), asUser: $user) ?? [];

        return $this->requestRows($answer['results'] ?? []);
    }

    /** Seerr's discovery rows: trending, popular movies and shows, upcoming movies. */
    public function requestDiscover(int $user): array
    {
        $rows = [];
        foreach (['trending' => '/discover/trending', 'popular_movies' => '/discover/movies', 'popular_series' => '/discover/tv', 'upcoming_movies' => '/discover/movies/upcoming'] as $key => $path) {
            try {
                $items = $this->requestRows(($this->call('get', $path . '?page=1', asUser: $user) ?? [])['results'] ?? []);
            } catch (SeerrException $e) {
                $items = [];
            }
            if ($items) {
                $rows[] = ['key' => $key, 'items' => array_slice($items, 0, 20)];
            }
        }

        return $rows;
    }

    /** A show: title, year, poster, and its seasons (no specials): number, name, episodes, state. */
    public function requestSeasons(int $user, int $tmdb): array
    {
        $show = $this->call('get', '/tv/' . $tmdb, notFound: true, asUser: $user);
        if ($show === null) {
            return ['title' => '', 'year' => null, 'poster' => null, 'seasons' => []];
        }
        $row = $this->titleRow('tv', $show);
        $states = [];
        foreach ($show['mediaInfo']['seasons'] ?? [] as $season) {
            $states[(int) $season['seasonNumber']] = $this->stateOf((int) ($season['status'] ?? 1));
        }
        foreach ($show['mediaInfo']['requests'] ?? [] as $request) {
            if (in_array((int) ($request['status'] ?? 0), [1, 2], true)) {
                foreach ($request['seasons'] ?? [] as $season) {
                    $number = (int) $season['seasonNumber'];
                    if (($states[$number] ?? 'none') === 'none') {
                        $states[$number] = 'requested';
                    }
                }
            }
        }
        $seasons = [];
        foreach ($show['seasons'] ?? [] as $season) {
            $number = (int) ($season['seasonNumber'] ?? 0);
            if ($number > 0) {
                $seasons[] = ['number' => $number, 'name' => (string) ($season['name'] ?? ''), 'episodes' => (int) ($season['episodeCount'] ?? 0), 'state' => $states[$number] ?? 'none'];
            }
        }

        return ['title' => $row['title'], 'year' => $row['year'], 'poster' => $row['posterPath'] ? self::poster($row['posterPath']) : null, 'seasons' => $seasons];
    }

    /** The user's own requests, newest first, with titles and posters. */
    public function myRequests(int $user, int $count = 20): array
    {
        $answer = $this->call('get', '/request?' . http_build_query(['take' => $count, 'skip' => 0, 'filter' => 'all', 'sort' => 'added', 'requestedBy' => $user]), asUser: $user) ?? [];
        $mine = [];
        foreach ($answer['results'] ?? [] as $request) {
            $type = (string) ($request['media']['mediaType'] ?? $request['type'] ?? '');
            $tmdb = (int) ($request['media']['tmdbId'] ?? 0);
            if (!in_array($type, ['movie', 'tv'], true) || $tmdb === 0) {
                continue;
            }
            $title = $this->titleCached($type, $tmdb);
            $media = (int) ($request['media']['status'] ?? 1);
            $mine[] = [
                'type' => $type, 'tmdb' => $tmdb,
                'title' => $title['title'] ?? '#' . $tmdb, 'year' => $title['year'] ?? null,
                'poster' => isset($title['posterPath']) ? self::poster($title['posterPath']) : null,
                'date' => (string) ($request['createdAt'] ?? ''),
                'seasons' => array_values(array_map(fn ($s) => (int) $s['seasonNumber'], $request['seasons'] ?? [])),
                'status' => match (true) {
                    (int) ($request['status'] ?? 0) === 3 => 'declined',
                    (int) ($request['status'] ?? 0) === 4 => 'failed',
                    $media === 5 => 'available',
                    $media === 4 => 'partial',
                    (int) ($request['status'] ?? 0) === 1 => 'pending',
                    $media === 3 => 'processing',
                    default => 'approved',
                },
            ];
        }

        return $mine;
    }

    /**
     * Requests a movie, or a show's seasons ("all", or numbers), as the user.
     * Seerr's own rules apply: their permission, limit and auto-approval.
     */
    public function createRequest(int $user, string $type, int $tmdb, array|string $seasons = 'all'): void
    {
        $body = ['mediaType' => $type, 'mediaId' => $tmdb, 'is4k' => false];
        if ($type === 'tv') {
            $body['seasons'] = $seasons;
        }
        $this->call('post', '/request', $body, asUser: $user);
    }

    /** @var array<string, ?array> */
    private array $titles = [];

    private function titleCached(string $type, int $tmdb): ?array
    {
        return $this->titles[$type . $tmdb] ??= $this->title($type, $tmdb);
    }

    /** Seerr's results as the tab shows them: type, tmdb, title, year, poster, state. */
    private function requestRows(array $results): array
    {
        $rows = [];
        foreach ($results as $result) {
            $type = $result['mediaType'] ?? '';
            if (!in_array($type, ['movie', 'tv'], true)) {
                continue;
            }
            $row = $this->titleRow($type, $result);
            $state = $this->stateOf((int) ($result['mediaInfo']['status'] ?? 1));
            if ($state === 'none' && !empty($result['mediaInfo']['requests'])) {
                $state = 'requested';
            }
            $rows[] = ['type' => $type, 'tmdb' => $row['id'], 'title' => $row['title'], 'year' => $row['year'],
                'poster' => $row['posterPath'] ? self::poster($row['posterPath']) : null, 'state' => $state];
        }

        return $rows;
    }

    /** Seerr's media status (constants/media.ts) as the tab names it. */
    private function stateOf(int $status): string
    {
        return match ($status) {
            5 => 'available',
            4 => 'partial',
            3 => 'processing',
            2 => 'requested',
            default => 'none',
        };
    }

    /** A TMDB poster path as an address (the path is checked by titleRow()). */
    public static function poster(string $path): string
    {
        return 'https://image.tmdb.org/t/p/w300' . $path;
    }

    public function deleteUser(int $id): void
    {
        $this->call('delete', '/user/' . $id, notFound: true);
    }

    /**
     * @param  int|null  $asUser  act as this Seerr user (X-API-User): their permissions,
     *                            limits and name, as Seerr's middleware/auth.ts allows the API key
     */
    private function call(string $method, string $path, ?array $data = null, bool $notFound = false, ?int $asUser = null): ?array
    {
        $url = rtrim($this->baseUrl, '/') . '/api/v1' . $path;
        try {
            $request = Http::withHeaders(['X-Api-Key' => $this->apiKey, 'Accept' => 'application/json'] + ($asUser !== null ? ['X-API-User' => (string) $asUser] : []))->timeout($this->timeout);
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
        throw new SeerrException(status: $status, seerrMessage: $message, message: match (true) {
            $status === 401 || $status === 403 && $message === '' => 'Seerr rejected the API key (' . $status . '). Check "Seerr API key" on the Wizarr server.',
            stripos($message, 'csrf') !== false => 'Seerr\'s "CSRF Protection" blocks API calls. Turn it off in Seerr: Settings -> Network.',
            default => 'Seerr answered ' . $status . ' for ' . strtoupper($method) . ' ' . $path . ($message !== '' ? ': ' . $message : '') . '.',
        });
    }
}
