<?php

namespace Paymenter\Extensions\Servers\Wizarr;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Silo (https://github.com/Silo-Server/silo-server) in place of Wizarr.
 *
 * Silo has invitations and account management of its own, so Paywarr talks
 * to it directly. This class answers with the same methods and the same
 * shapes as WizarrClient, so the rest of the extension needs no Silo branch:
 * Silo is one "server" (ID 1, type "silo"), its invitations and accounts
 * look like Wizarr's.
 *
 * Written against Silo's native API v2 (contracts/api/v2/openapi.json and
 * docs/invitations-api.md, docs/admin-users-api.md, November 2026). Silo is
 * not at 1.0 yet; its v2 contract may still change until it locks.
 * Authentication: "Authorization: Bearer sa_..." with an API key of an admin
 * account. A key scoped to admin:users is not enough: invitations, password
 * reset links and libraries are outside that scope.
 */
class SiloClient
{
    /** The one "server" Silo is, in Wizarr's terms. */
    public const SERVER_ID = 1;

    private const ATTEMPTS = 3;

    /** Largest page Silo hands out for invitations and accounts. */
    private const PAGE = 200;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $publicUrl = '',
        private readonly string $name = 'Silo',
        private readonly int $timeout = 20,
        private readonly int $retryDelayMs = 750,
    ) {}

    /** A call that needs the key and admin rights, so a wrong key shows up here. */
    public function status(): array
    {
        return $this->request('get', '/api/v2/admin/invitations/capabilities');
    }

    /** @return array<int, array<string, mixed>> */
    public function servers(): array
    {
        return [[
            'id' => self::SERVER_ID,
            'name' => $this->name,
            'server_type' => 'silo',
            'verified' => true,
            'external_url' => $this->publicUrl,
        ]];
    }

    /** @return array<int, array<string, mixed>> */
    public function libraries(): array
    {
        return array_map(fn ($library) => [
            'id' => (int) $library['id'],
            'name' => (string) ($library['name'] ?? ''),
            'server_id' => self::SERVER_ID,
            'server_name' => $this->name,
        ], $this->request('get', '/api/v2/libraries')['items'] ?? []);
    }

    /**
     * Pending, used and expired invitations, as Wizarr lists them. A revoked
     * one is left out: for Paywarr it no longer exists. Silo never lists the
     * link again after creating it, so "url" is absent here.
     *
     * @return array<int, array<string, mixed>>
     */
    public function invitations(): array
    {
        $rows = [];
        foreach ($this->pages('/api/v2/admin/invitations') as $invitation) {
            $status = match ($invitation['status'] ?? '') {
                'pending' => 'pending',
                'accepted' => 'used',
                'expired' => 'expired',
                default => null,
            };
            if ($status === null) {
                continue;
            }
            $accepted = (string) ($invitation['accepted_user_id'] ?? '');
            $rows[] = [
                'id' => (int) $invitation['id'],
                'code' => self::code($invitation),
                'status' => $status,
                // Read by Wizarr::resolveUsers() like Wizarr's own "<User 12>".
                'used_by' => $status === 'used' && ctype_digit($accepted) ? '<User ' . $accepted . '>' : null,
                'server_names' => [$this->name],
                'expires' => $invitation['expires_at'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * Rows of id, username, email, server (name), server_type, as Wizarr's.
     * Administrators are left out: Paywarr never touches them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function users(): array
    {
        $rows = [];
        foreach ($this->pages('/api/v2/admin/users') as $account) {
            if (($account['role'] ?? '') === 'admin') {
                continue;
            }
            $rows[] = [
                'id' => (int) $account['id'],
                'username' => (string) ($account['username'] ?? ''),
                'email' => (string) ($account['email'] ?? ''),
                'server' => $this->name,
                'server_type' => 'silo',
                'enabled' => (bool) ($account['enabled'] ?? true),
            ];
        }

        return $rows;
    }

    /**
     * A one-time link invitation for a new, ordinary account. Of Wizarr's
     * payload only library_ids applies: how long the link is valid is a Silo
     * setting, and Silo users have no expiry of their own.
     *
     * Not retried: a second attempt after a lost answer would make a second
     * invitation.
     */
    public function createInvitation(array $payload): array
    {
        // Wizarr ends a user after "duration" days; Silo accounts never end on
        // their own, so such a product would give access for good.
        if (($payload['duration'] ?? 'unlimited') !== 'unlimited') {
            throw new WizarrException('Silo accounts cannot expire on their own. Leave "Access expires after (days)" empty on products for Silo.');
        }
        $body = ['delivery' => 'link', 'role' => 'user', 'note' => 'Paymenter'];
        if (!empty($payload['library_ids'])) {
            $body['library_ids'] = array_map('strval', array_values($payload['library_ids']));
        }

        $answer = $this->request('post', '/api/v2/admin/invitations', $body, retry: false);
        $invitation = $answer['invitation'] ?? null;
        if (!is_array($invitation) || empty($invitation['id']) || empty($answer['claim_url'])) {
            throw new WizarrException('Silo did not return an invitation link.');
        }

        return [
            'id' => (int) $invitation['id'],
            'code' => self::code($invitation),
            'url' => (string) $answer['claim_url'],
            'expires' => $invitation['expires_at'] ?? null,
            'status' => 'pending',
        ];
    }

    /**
     * Revokes the link. An accepted invitation cannot be revoked (Silo answers
     * 409); there is nothing left to withdraw then, so that is not an error.
     */
    public function deleteInvitation(int $id): void
    {
        try {
            $this->request('delete', '/api/v2/admin/invitations/' . $id);
        } catch (WizarrException $e) {
            if ($e->status !== 409) {
                throw $e;
            }
        }
    }

    public function deleteUser(int $id): void
    {
        $this->guarded('delete', $id);
    }

    /** Answers in Wizarr's words, which the extension reads. */
    public function disableUser(int $id): string
    {
        $this->guarded('put', $id, ['enabled' => false]);

        return 'User disabled successfully';
    }

    public function enableUser(int $id): string
    {
        $this->guarded('put', $id, ['enabled' => true]);

        return 'User enabled successfully';
    }

    /** Silo's own requests on or off for this account (an account override). */
    public function setRequestsAllowed(int $id, bool $allowed): void
    {
        $this->guarded('put', $id, ['requests_allowed' => $allowed]);
    }

    /**
     * The account's request limit: null for Silo's default (its group's),
     * 0 for no limit, otherwise $limit requests every $days days.
     */
    public function setRequestLimit(int $id, ?int $limit, ?int $days): void
    {
        $body = match (true) {
            $limit === null => ['limit_mode' => 'inherit', 'max_requests' => null, 'window_days' => null, 'approval_mode' => 'inherit'],
            $limit === 0 => ['limit_mode' => 'unlimited', 'max_requests' => null, 'window_days' => null, 'approval_mode' => 'inherit'],
            default => ['limit_mode' => 'custom', 'max_requests' => $limit, 'window_days' => max(1, (int) ($days ?: 7)), 'approval_mode' => 'inherit'],
        };
        $this->guardedPath('put', '/api/v2/admin/request-users/' . $id . '/limit', $body, $id);
    }

    /**
     * A link where the customer chooses a new password, valid for 24 hours.
     * A new link replaces the account's earlier one.
     */
    public function createPasswordReset(int $userId): array
    {
        $answer = $this->request('post', '/api/v2/admin/users/' . $userId . '/password-reset', ['delivery' => 'link'], retry: false);
        if (empty($answer['reset_url'])) {
            throw new WizarrException('Silo did not return a password reset link.');
        }

        return ['code' => 'silo', 'url' => (string) $answer['reset_url'], 'expires_at' => $answer['expires_at'] ?? null];
    }

    /**
     * Movies and shows in Silo's catalog matching $query: [id (content ID),
     * title, year, type ("movie"|"series")]. Read as the key owner's primary
     * profile; empty when that profile has a PIN.
     */
    public function searchCatalog(string $query, int $limit = 12): array
    {
        $profile = $this->profileId();
        if ($profile === '') {
            return [];
        }
        $answer = $this->request('get', '/api/v2/catalog?' . http_build_query(['q' => $query, 'type' => 'video', 'limit' => $limit, 'image_size' => 'small', 'skip_total' => 'true']),
            headers: ['X-Profile-Id' => $profile]);
        $found = [];
        foreach ($answer['items'] ?? [] as $item) {
            if (in_array($item['type'] ?? '', ['movie', 'series'], true) && ($item['content_id'] ?? '') !== '') {
                $found[] = ['id' => (string) $item['content_id'], 'title' => (string) ($item['title'] ?? ''), 'year' => isset($item['year']) ? (int) $item['year'] : null, 'type' => (string) $item['type']];
            }
        }

        return $found;
    }

    /** One catalog item (title, year, type, poster_url ...), or null. */
    public function catalogItem(string $contentId): ?array
    {
        $profile = $this->profileId();
        if ($profile === '' || !preg_match('/^[A-Za-z0-9:._-]{1,200}$/', $contentId)) {
            return null;
        }
        try {
            return $this->request('get', '/api/v2/catalog/items/' . rawurlencode($contentId) . '?image_size=small', headers: ['X-Profile-Id' => $profile]);
        } catch (WizarrException $e) {
            if ($e->isNotFound()) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * An item's poster as image bytes and type, or null. A signed Silo route
     * (relative) is fetched with the key; object storage without it.
     *
     * @return array{body: string, type: string}|null
     */
    public function poster(string $contentId): ?array
    {
        $item = $this->catalogItem($contentId);
        $url = (string) ($item['series_poster_url'] ?? '') ?: (string) ($item['poster_url'] ?? '');
        $own = false;
        if (str_starts_with($url, '/')) {
            $url = rtrim($this->baseUrl, '/') . $url;
            $own = true;
        } elseif (!preg_match('~^https?://~i', $url)) {
            return null;
        }
        try {
            $response = Http::withHeaders(['Accept' => 'image/*'] + ($own ? ['Authorization' => 'Bearer ' . $this->apiKey] : []))->timeout($this->timeout)->get($url);
        } catch (Throwable $e) {
            return null;
        }
        $type = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));

        return $response->status() === 200 && str_starts_with($type, 'image/') && $type !== 'image/svg+xml'
            ? ['body' => $response->body(), 'type' => $type] : null;
    }

    /* ------------------------------------------------------------------
     | The Requests tab
     * ----------------------------------------------------------------- */

    /** How much the account may still request (Silo counts requests, movies and series alike). */
    public function requestQuota(int $id): array
    {
        $usage = $this->request('get', '/api/v2/admin/request-users/' . $id . '/usage');
        if (!empty($usage['unlimited'])) {
            return ['unlimited' => true, 'parts' => [], 'allowed' => !empty($usage['allowed'])];
        }

        return ['unlimited' => false, 'allowed' => !empty($usage['allowed']), 'parts' => [[
            'label' => 'requests', 'used' => (int) ($usage['used'] ?? 0), 'limit' => (int) ($usage['max_requests'] ?? 0),
            'remaining' => max(0, (int) ($usage['remaining'] ?? 0)), 'days' => (int) ($usage['window_days'] ?? 0),
        ]]];
    }

    /** Movies and series matching $query, with their state in Silo. */
    public function requestSearch(string $query): array
    {
        $answer = $this->request('get', '/api/v2/requests/search?' . http_build_query(['q' => $query, 'page' => 1]), headers: $this->asOwner());

        return array_map(fn ($result) => $this->requestRow($result), array_values(array_filter($answer['results'] ?? [], fn ($r) => in_array($r['media_type'] ?? '', ['movie', 'series'], true))));
    }

    /** Silo's discovery rows. */
    public function requestDiscover(): array
    {
        $rows = [];
        foreach (['trending_movies', 'trending_series', 'popular_movies', 'popular_series', 'upcoming_movies'] as $section) {
            try {
                $items = array_map(fn ($result) => $this->requestRow($result), $this->request('get', '/api/v2/requests/discover/' . $section . '?page=1', headers: $this->asOwner())['results'] ?? []);
            } catch (WizarrException $e) {
                $items = [];
            }
            if ($items) {
                $rows[] = ['key' => $section, 'items' => array_slice($items, 0, 20)];
            }
        }

        return $rows;
    }

    /** The account's own requests, newest first. */
    public function myRequests(int $id, int $count = 20): array
    {
        $answer = $this->request('get', '/api/v2/admin/requests?' . http_build_query(['requested_by_user_id' => $id, 'limit' => $count]));
        $mine = [];
        foreach ($answer['items'] ?? [] as $request) {
            if ((string) ($request['requested_by_user_id'] ?? $id) !== (string) $id) {
                continue; // only theirs, whatever the filter did
            }
            $mine[] = [
                'type' => ($request['media_type'] ?? '') === 'series' ? 'tv' : 'movie', 'tmdb' => (int) ($request['tmdb_id'] ?? 0),
                'title' => (string) ($request['title'] ?? ''), 'year' => isset($request['year']) ? (int) $request['year'] : null,
                'poster' => self::posterUrl($request['poster_path'] ?? null),
                'date' => (string) ($request['created_at'] ?? ''),
                'seasons' => array_values(array_map('intval', $request['seasons'] ?? [])),
                'status' => match (true) {
                    in_array($request['outcome'] ?? 'active', ['declined', 'cancelled', 'failed'], true) => (string) $request['outcome'],
                    ($request['state'] ?? '') === 'available' => 'available',
                    in_array($request['state'] ?? '', ['partially_available', 'partial'], true) => 'partial',
                    in_array($request['state'] ?? '', ['processing', 'downloading', 'queued'], true) => 'processing',
                    ($request['state'] ?? '') === 'approved' => 'approved',
                    default => 'pending',
                },
            ];
        }

        return $mine;
    }

    /** Silo's request result as the tab shows it. */
    private function requestRow(array $result): array
    {
        $state = ($result['availability'] ?? '') === 'available' ? 'available' : match ($result['request']['state'] ?? '') {
            '' => 'none',
            'partially_available', 'partial' => 'partial',
            'processing', 'downloading', 'queued' => 'processing',
            'available' => 'available',
            default => 'requested',
        };

        return ['type' => ($result['media_type'] ?? '') === 'series' ? 'tv' : 'movie', 'tmdb' => (int) ($result['tmdb_id'] ?? 0),
            'title' => (string) ($result['title'] ?? ''), 'year' => isset($result['year']) ? (int) $result['year'] : null,
            'poster' => self::posterUrl($result['poster_path'] ?? null), 'state' => $state];
    }

    /** A TMDB poster path as an address; null for anything that isn't one. */
    public static function posterUrl(?string $path): ?string
    {
        return $path !== null && preg_match('~^/[A-Za-z0-9._-]+$~', $path) ? 'https://image.tmdb.org/t/p/w300' . $path : null;
    }

    /** Headers for reads that act as the key owner's primary profile. */
    private function asOwner(): array
    {
        $profile = $this->profileId();

        return $profile === '' ? [] : ['X-Profile-Id' => $profile];
    }

    private ?string $profileId = null;

    /** The key owner's primary profile, which catalog reads act as; "" when it has a PIN. */
    private function profileId(): string
    {
        if ($this->profileId === null) {
            $this->profileId = '';
            foreach ($this->request('get', '/api/v2/profiles')['items'] ?? [] as $profile) {
                if (!empty($profile['is_primary']) && empty($profile['has_pin'])) {
                    $this->profileId = (string) $profile['id'];
                }
            }
        }

        return $this->profileId;
    }

    /**
     * Something that tells this invitation apart from a later one with the
     * same ID. Silo keeps the claim token to itself, so it is made from the
     * ID and the creation time, which both appear in every listing.
     */
    private static function code(array $invitation): string
    {
        return 'silo-' . substr(hash('sha256', $invitation['id'] . '|' . ($invitation['created_at'] ?? '')), 0, 12);
    }

    /**
     * An account change with Silo's edit guard: read the account's ETag, then
     * write with If-Match. A stale tag (someone else changed the account in
     * between) is read again once.
     */
    private function guarded(string $method, int $id, array $body = []): void
    {
        $this->guardedPath($method, '/api/v2/admin/users/' . $id, $body, $id);
    }

    /**
     * $path's resource, changed under Silo's edit guard. With $account, the
     * account is read first and an administrator refused.
     */
    private function guardedPath(string $method, string $path, array $body, ?int $account = null): void
    {
        for ($attempt = 1; ; $attempt++) {
            if ($account !== null && $path !== '/api/v2/admin/users/' . $account) {
                [$owner] = $this->requestWithEtag('/api/v2/admin/users/' . $account);
                if (($owner['role'] ?? '') === 'admin') {
                    throw new WizarrException('Paywarr does not change Silo administrators (account ' . $account . ').', 403);
                }
            }
            [$current, $etag] = $this->requestWithEtag($path);
            if ($path === '/api/v2/admin/users/' . $account && ($current['role'] ?? '') === 'admin') {
                throw new WizarrException('Paywarr does not change Silo administrators (account ' . $account . ').', 403);
            }
            try {
                $this->request($method, $path, $body, retry: false, headers: ['If-Match' => $etag]);

                return;
            } catch (WizarrException $e) {
                if ($e->status !== 412 || $attempt >= 2) {
                    throw $e;
                }
            }
        }
    }

    /** @return array{0: array, 1: string} the account and its ETag */
    private function requestWithEtag(string $path): array
    {
        $etag = '';
        $body = $this->request('get', $path, onResponse: function ($response) use (&$etag) {
            $etag = (string) $response->header('ETag');
        });
        if ($etag === '') {
            throw new WizarrException('Silo sent no ETag for ' . $path . ', so the account cannot be changed safely.');
        }

        return [$body, $etag];
    }

    /** Every item of a cursor-paged collection. */
    private function pages(string $path): array
    {
        $items = [];
        $cursor = null;
        do {
            $query = ['limit' => self::PAGE] + ($cursor !== null ? ['cursor' => $cursor] : []);
            $page = $this->request('get', $path . '?' . http_build_query($query));
            foreach ($page['items'] ?? [] as $item) {
                $items[] = $item;
            }
            $cursor = !empty($page['page']['has_more']) ? ($page['page']['next_cursor'] ?? null) : null;
        } while ($cursor !== null && count($items) < 100000);

        return $items;
    }

    private function request(string $method, string $path, array $data = [], bool $retry = true, array $headers = [], ?callable $onResponse = null): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $attempts = $retry ? self::ATTEMPTS : 1;
        $response = null;
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                // Headers given win: an impersonation session's own Authorization replaces the key's.
                $pending = Http::withHeaders($headers + [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Accept' => 'application/json',
                ])->timeout($this->timeout);

                $response = match ($method) {
                    'get' => $pending->get($url),
                    'delete' => $pending->delete($url),
                    'post' => $pending->post($url, $data),
                    // No body at all: Silo's actions without input refuse even "[]".
                    'post-empty' => $pending->send('POST', $url),
                    'put' => $pending->put($url, $data),
                };
                $lastError = null;
            } catch (Throwable $e) {
                $response = null;
                $lastError = $e->getMessage();
            }

            if ($response !== null && $response->status() < 500) {
                break;
            }
            if ($attempt < $attempts) {
                usleep($this->retryDelayMs * 1000);
            }
        }

        if ($response === null) {
            throw new WizarrException('Could not reach Silo at ' . $url . ': ' . $lastError);
        }

        $status = $response->status();
        $body = $response->json();

        if ($status === 401) {
            throw new WizarrException('Silo rejected the API key (401). Check the API key on the server in Paymenter.', 401);
        }
        if ($status === 403) {
            throw new WizarrException('Silo refused ' . strtoupper($method) . ' ' . $path . ' (403). The API key must belong to an admin account and have full access, not only the admin:users scope.', 403);
        }
        if ($status >= 400) {
            // Problem Details: title, detail, code.
            $detail = is_array($body) ? ($body['detail'] ?? $body['title'] ?? $body['message'] ?? null) : null;
            $detail = is_string($detail) && $detail !== '' ? $detail : substr((string) $response->body(), 0, 300);

            throw new WizarrException('Silo answered ' . $status . ' for ' . strtoupper($method) . ' ' . $path . ': ' . $detail, $status);
        }
        if ($onResponse) {
            $onResponse($response);
        }
        if ($status === 204) {
            return [];
        }
        if (!is_array($body)) {
            throw new WizarrException(
                'Silo answered ' . $status . ' for ' . $path . ' with something that is not JSON. '
                . 'Is the address Silo itself (e.g. port 8090), not the Jellyfin-compatible port or a login page?'
            );
        }

        return $body;
    }
}
