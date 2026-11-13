<?php

namespace Paymenter\Extensions\Servers\Wizarr;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin client for the Wizarr REST API.
 *
 * Written against Wizarr's own route definitions (app/blueprints/api/api_routes.py,
 * v2026.10). Every call is authenticated with the X-API-Key header. Your own
 * instance documents the same API at /api/docs/.
 */
class WizarrClient
{
    /** How often a call that is safe to repeat is tried before giving up. */
    private const ATTEMPTS = 3;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeout = 20,
        private readonly int $retryDelayMs = 750,
    ) {}

    public function status(): array
    {
        return $this->request('get', '/api/status');
    }

    /** @return array<int, array<string, mixed>> */
    public function servers(): array
    {
        return $this->request('get', '/api/servers')['servers'] ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public function libraries(): array
    {
        return $this->request('get', '/api/libraries')['libraries'] ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public function invitations(): array
    {
        return $this->request('get', '/api/invitations')['invitations'] ?? [];
    }

    /**
     * Rows of id, username, email, server (name), server_type, expires.
     *
     * @return array<int, array<string, mixed>>
     */
    public function users(): array
    {
        return $this->request('get', '/api/users')['users'] ?? [];
    }

    /**
     * Creates an invitation and returns Wizarr's "invitation" object
     * (id, code, url, expires, duration, unlimited, ...).
     *
     * Not retried: if the connection drops after Wizarr handled the request,
     * a second attempt would hand out a second invitation.
     */
    public function createInvitation(array $payload): array
    {
        $invitation = $this->request('post', '/api/invitations', $payload, retry: false)['invitation'] ?? null;

        if (!is_array($invitation) || empty($invitation['code'])) {
            throw new WizarrException('Wizarr did not return an invitation code.');
        }

        return $invitation;
    }

    public function deleteInvitation(int $id): void
    {
        $this->request('delete', '/api/invitations/' . $id);
    }

    public function deleteUser(int $id): void
    {
        $this->request('delete', '/api/users/' . $id);
    }

    /**
     * Returns Wizarr's message. Wizarr answers 200 in three different cases:
     * "... disabled successfully", "... deleted (disable not supported by server)",
     * so the caller has to read the text to know what happened.
     */
    public function disableUser(int $id): string
    {
        return (string) ($this->request('post', '/api/users/' . $id . '/disable')['message'] ?? '');
    }

    /**
     * Returns Wizarr's message: "... enabled successfully" or
     * "Enable failed or not supported for user ..." - both with HTTP 200.
     */
    public function enableUser(int $id): string
    {
        return (string) ($this->request('post', '/api/users/' . $id . '/enable')['message'] ?? '');
    }

    /**
     * Creates a password reset link for one Wizarr user and returns Wizarr's
     * answer: code, url ("/reset/<code>"), expires_at. The link is valid for
     * 24 hours, and creating one invalidates the user's earlier links, so a
     * repeat after a lost answer still leaves exactly one working link.
     */
    public function createPasswordReset(int $userId): array
    {
        $answer = $this->request('post', '/api/users/' . $userId . '/reset-password');

        if (empty($answer['code'])) {
            throw new WizarrException('Wizarr did not return a password reset code.');
        }

        return $answer;
    }

    private function request(string $method, string $path, array $data = [], bool $retry = true): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $attempts = $retry ? self::ATTEMPTS : 1;
        $response = null;
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $pending = Http::withHeaders([
                    'X-API-Key' => $this->apiKey,
                    'Accept' => 'application/json',
                ])->timeout($this->timeout);

                $response = match ($method) {
                    'get' => $pending->get($url),
                    'delete' => $pending->delete($url),
                    'post' => $pending->post($url, $data),
                };
                $lastError = null;
            } catch (Throwable $e) {
                // Connection refused, DNS failure, timeout.
                $response = null;
                $lastError = $e->getMessage();
            }

            // Anything below 500 is a real answer; only outages are worth repeating.
            if ($response !== null && $response->status() < 500) {
                break;
            }
            if ($attempt < $attempts) {
                usleep($this->retryDelayMs * 1000);
            }
        }

        if ($response === null) {
            throw new WizarrException('Could not reach Wizarr at ' . $url . ': ' . $lastError);
        }

        $status = $response->status();
        $body = $response->json();

        if ($status === 401) {
            throw new WizarrException('Wizarr rejected the API key (401). Check the API key on the server in Paymenter.', 401);
        }

        if ($status >= 400) {
            $detail = is_array($body) ? ($body['error'] ?? $body['message'] ?? null) : null;
            $detail = is_string($detail) && $detail !== '' ? $detail : substr((string) $response->body(), 0, 300);

            throw new WizarrException('Wizarr answered ' . $status . ' for ' . strtoupper($method) . ' ' . $path . ': ' . $detail, $status);
        }

        if (!is_array($body)) {
            throw new WizarrException(
                'Wizarr answered ' . $status . ' for ' . $path . ' with something that is not JSON. '
                . 'Is the host pointing at Wizarr itself and not at a login page or reverse proxy?'
            );
        }

        return $body;
    }
}
