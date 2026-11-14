<?php

namespace Paymenter\Extensions\Others\PaywarrStats;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Tracearr's public API v2 (/api/v2/public), read only. A key is made in
 * Tracearr under Settings and starts with "trr_pub_".
 */
class TracearrClient
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private int $timeout = 10,
    ) {}

    /**
     * Every Tracearr identity with its accounts on each media server.
     *
     * @return array<int, array{id: string, accounts: array<int, array{server_type: string, username: string, removed_at: ?string}>}>
     */
    public function users(): array
    {
        $users = [];
        $cursor = null;
        do {
            $page = $this->call('/users', array_filter(['pageSize' => 100, 'cursor' => $cursor]));
            foreach ($page['data'] ?? [] as $user) {
                $users[] = $user;
            }
            $cursor = $page['meta']['nextCursor'] ?? null;
        } while ($cursor !== null && count($users) < 10000);

        return $users;
    }

    /** Plays and watch time (last 7 days, last 30 days, all time) and top genres. */
    public function userStats(string $id): array
    {
        return $this->call('/users/' . rawurlencode($id) . '/stats');
    }

    /** The latest plays, newest first. */
    public function userHistory(string $id, int $pageSize = 10): array
    {
        return $this->call('/users/' . rawurlencode($id) . '/history', ['pageSize' => $pageSize])['data'] ?? [];
    }

    private function call(string $path, array $query = []): array
    {
        $url = rtrim($this->baseUrl, '/') . '/api/v2/public' . $path;
        try {
            $response = Http::withHeaders(['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json'])
                ->timeout($this->timeout)
                ->get($url . ($query ? '?' . http_build_query($query) : ''));
        } catch (Throwable $e) {
            throw new TracearrException('Could not reach Tracearr at ' . $url . ': ' . $e->getMessage());
        }

        $status = $response->status();
        if ($status === 401 || $status === 403) {
            throw new TracearrException('Tracearr rejected the API key (' . $status . '). Make a public API key in Tracearr under Settings; it starts with "trr_pub_".');
        }
        if ($status < 200 || $status >= 300) {
            throw new TracearrException('Tracearr answered ' . $status . ' for GET ' . $path . '.');
        }
        $body = $response->json();

        return is_array($body) ? $body : [];
    }
}
