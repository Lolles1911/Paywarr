<?php

namespace Paymenter\Extensions\Servers\Wizarr;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Tracearr's public API (a "trr_pub_" key): what is streaming now, stopping a
 * stream, Tracearr's own rule breaks (violations, e.g. sharing from two
 * places), and what was added to the libraries. Tracearr watches Plex,
 * Jellyfin and Emby; Silo is not in it.
 */
class TracearrClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeout = 10,
    ) {}

    /** The media servers Tracearr watches: server_name, server_type, status ("up", "down", "unknown"), historical. */
    public function servers(): array
    {
        return $this->call('get', '/api/v2/public/servers')['data'] ?? [];
    }

    /** Every stream now: id, server_type, server_name, username, media_title, show_title, started_at, device, product, player. */
    public function streams(): array
    {
        return $this->call('get', '/api/v2/public/streams')['data'] ?? [];
    }

    /** Stops a stream. Plex shows $reason on the screen; Jellyfin and Emby just stop. */
    public function terminate(string $sessionId, string $reason): void
    {
        $this->call('post', '/api/v1/public/streams/' . rawurlencode($sessionId) . '/terminate', ['reason' => mb_substr($reason, 0, 500)]);
    }

    /** Every play since $since, newest first, up to $max: server_type, user.username, media_type, media_title, show_title, duration_ms, watched, started_at. */
    public function history(int $since, int $max = 3000): array
    {
        $rows = [];
        $cursor = null;
        do {
            $page = $this->call('get', '/api/v2/public/history', array_filter(['pageSize' => 100, 'since' => gmdate('Y-m-d\TH:i:s\Z', $since), 'cursor' => $cursor]));
            foreach ($page['data'] ?? [] as $row) {
                $rows[] = $row;
            }
            $cursor = $page['meta']['nextCursor'] ?? null;
        } while ($cursor !== null && count($rows) < $max);

        return $rows;
    }

    /** Tracearr's rule breaks since $since, newest first (up to $max): rule.name, user.username, server.type, created_at, severity. */
    public function violations(int $since, int $max = 500): array
    {
        $rows = [];
        $cursor = null;
        do {
            $page = $this->call('get', '/api/v2/public/violations', array_filter(['pageSize' => 100, 'since' => gmdate('Y-m-d\TH:i:s\Z', $since), 'cursor' => $cursor]));
            foreach ($page['data'] ?? [] as $row) {
                $rows[] = $row;
            }
            $cursor = $page['meta']['nextCursor'] ?? null;
        } while ($cursor !== null && count($rows) < $max);

        return $rows;
    }

    /** Movies and shows added since $since, newest first (up to $max): title, year, media_type, added_at, server_type. */
    public function recentlyAdded(int $since, int $max = 300): array
    {
        $rows = [];
        $cursor = null;
        do {
            $page = $this->call('get', '/api/v2/public/recently-added', array_filter(['pageSize' => 100, 'cursor' => $cursor]));
            foreach ($page['data'] ?? [] as $row) {
                if ((strtotime((string) ($row['added_at'] ?? '')) ?: 0) < $since) {
                    return $rows; // newest first: the rest is older
                }
                if (empty($row['removed_at']) && in_array($row['media_type'] ?? '', ['movie', 'show'], true)) {
                    $rows[] = $row;
                }
            }
            $cursor = $page['meta']['nextCursor'] ?? null;
        } while ($cursor !== null && count($rows) < $max);

        return $rows;
    }

    /**
     * A poster through Tracearr's image proxy (/api/v1/images/proxy, which
     * needs no key). Only that path is fetched, so this cannot reach anything
     * else on Tracearr's network.
     *
     * @return array{body: string, type: string}|null  null when it is not an image
     */
    public function poster(string $path): ?array
    {
        if (!str_starts_with($path, '/api/v1/images/proxy?')) {
            return null;
        }
        try {
            $response = Http::withHeaders(['Accept' => 'image/*'])->timeout($this->timeout)->get(rtrim($this->baseUrl, '/') . $path);
        } catch (Throwable $e) {
            return null;
        }
        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        return $response->status() === 200 && str_starts_with($type, 'image/') && $type !== 'image/svg+xml'
            ? ['body' => $response->body(), 'type' => $type] : null;
    }

    private function call(string $method, string $path, array $data = []): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        try {
            $request = Http::withHeaders(['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json'])->timeout($this->timeout);
            $response = $method === 'post' ? $request->post($url, $data) : $request->get($url . ($data ? '?' . http_build_query($data) : ''));
        } catch (Throwable $e) {
            throw new WizarrException('Could not reach Tracearr at ' . $url . ': ' . $e->getMessage());
        }
        $status = $response->status();
        if ($status === 401 || $status === 403) {
            throw new WizarrException('Tracearr rejected the API key (' . $status . '). Make one in Tracearr under Settings -> Data & API; it starts with "trr_pub_".');
        }
        if ($status < 200 || $status >= 300) {
            throw new WizarrException('Tracearr answered ' . $status . ' for ' . strtoupper($method) . ' ' . $path . '.');
        }
        $body = $response->json();

        return is_array($body) ? $body : [];
    }
}
