<?php

namespace Paymenter\Extensions\Servers\Wizarr;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * The calendar of Sonarr (episodes) or Radarr (movies), API v3, for "Coming
 * soon". Read only.
 */
class ArrClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeout = 15,
    ) {}

    /** GET /api/v3/calendar between two times; Sonarr with the series. */
    public function calendar(int $from, int $to): array
    {
        $url = rtrim($this->baseUrl, '/') . '/api/v3/calendar';
        try {
            $response = Http::withHeaders(['X-Api-Key' => $this->apiKey, 'Accept' => 'application/json'])->timeout($this->timeout)
                ->get($url, ['start' => gmdate('Y-m-d\TH:i:s\Z', $from), 'end' => gmdate('Y-m-d\TH:i:s\Z', $to), 'includeSeries' => 'true', 'unmonitored' => 'false']);
        } catch (Throwable $e) {
            throw new RuntimeException('Could not reach ' . $url . ': ' . $e->getMessage());
        }
        if (!$response->successful()) {
            throw new RuntimeException($url . ' answered ' . $response->status() . ($response->status() === 401 ? ' (check the API key)' : '') . '.');
        }

        return is_array($response->json()) ? $response->json() : [];
    }
}
