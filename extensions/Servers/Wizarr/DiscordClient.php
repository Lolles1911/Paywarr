<?php

namespace Paymenter\Extensions\Servers\Wizarr;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Asks Discord, as your bot, whether a user is a member of your server.
 *
 * Uses GET /guilds/{guild}/members/{user}. The bot only has to be in the
 * server; it needs no permissions. Discord does not say clearly whether this
 * call needs the "Server Members Intent", so the setup guide turns it on.
 */
class DiscordClient
{
    // JSON error codes from Discord's docs (topics/opcodes-and-status-codes).
    private const UNKNOWN_GUILD = 10004;

    private const UNKNOWN_MEMBER = 10007;

    private const UNKNOWN_USER = 10013;

    private const MISSING_ACCESS = 50001;

    private const UNKNOWN_ROLE = 10011;

    private const MISSING_PERMISSIONS = 50013;

    public function __construct(
        private readonly string $botToken,
        private readonly string $baseUrl = 'https://discord.com/api/v10',
        private readonly int $timeout = 10,
    ) {}

    /**
     * The member's role IDs, or null when they are not in the server.
     *
     * @return string[]|null
     */
    public function memberRoles(string $guildId, string $userId): ?array
    {
        $response = $this->call('get', '/guilds/' . $this->id($guildId) . '/members/' . $this->id($userId));
        if ($response === null) {
            return null;
        }

        return array_map('strval', $response['roles'] ?? []);
    }

    /** PUT /guilds/{guild}/members/{user}/roles/{role}. Needs "Manage Roles". */
    public function addRole(string $guildId, string $userId, string $roleId): void
    {
        $this->call('put', '/guilds/' . $this->id($guildId) . '/members/' . $this->id($userId) . '/roles/' . $this->id($roleId), notMember: 'throw');
    }

    /** DELETE /guilds/{guild}/members/{user}/roles/{role}. Not a member: nothing to remove. */
    public function removeRole(string $guildId, string $userId, string $roleId): void
    {
        $this->call('delete', '/guilds/' . $this->id($guildId) . '/members/' . $this->id($userId) . '/roles/' . $this->id($roleId));
    }

    /**
     * Roles a bot can hand out: not @everyone (its ID is the server's) and not
     * roles that belong to an integration.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function assignableRoles(string $guildId): array
    {
        $roles = $this->call('get', '/guilds/' . $this->id($guildId) . '/roles') ?? [];
        $out = [];
        foreach ($roles as $role) {
            if ((string) $role['id'] === $guildId || !empty($role['managed'])) {
                continue;
            }
            $out[] = ['id' => (string) $role['id'], 'name' => (string) $role['name'], 'position' => (int) ($role['position'] ?? 0)];
        }
        usort($out, fn ($a, $b) => $b['position'] <=> $a['position']);

        return array_map(fn ($r) => ['id' => $r['id'], 'name' => $r['name']], $out);
    }

    private function id(string $id): string
    {
        if (!ctype_digit($id)) {
            throw new DiscordException('Discord IDs are numbers; got "' . $id . '".');
        }

        return $id;
    }

    /**
     * One authenticated call. Returns the decoded body (or [] for 204), null
     * when Discord says the member or user does not exist, and throws for
     * everything else.
     */
    /**
     * A private message from the bot to a member (reminders, warnings). The
     * member must share a server with the bot, and may have closed their DMs:
     * then Discord answers 50007 and this throws.
     */
    public function sendDirectMessage(string $userId, string $content): void
    {
        $channel = $this->call('post', '/users/@me/channels', body: ['recipient_id' => $this->id($userId)]);
        $this->call('post', '/channels/' . $this->id((string) ($channel['id'] ?? '')) . '/messages', body: ['content' => mb_substr($content, 0, 2000)]);
    }

    /** A message in a channel of your server (the bot needs "Send Messages" there). */
    public function postMessage(string $channelId, string $content): void
    {
        $this->call('post', '/channels/' . $this->id($channelId) . '/messages', notMember: 'throw', body: ['content' => mb_substr($content, 0, 2000)]);
    }

    private function call(string $method, string $path, string $notMember = 'null', array $body = []): ?array
    {
        try {
            $request = Http::withHeaders([
                'Authorization' => 'Bot ' . $this->botToken,
                'Accept' => 'application/json',
            ])->timeout($this->timeout);
            $url = rtrim($this->baseUrl, '/') . $path;
            $response = match ($method) {
                'get' => $request->get($url),
                'put' => $request->put($url),
                'delete' => $request->delete($url),
                'post' => $request->post($url, $body),
            };
        } catch (Throwable $e) {
            throw new DiscordException('Could not reach Discord: ' . $e->getMessage());
        }

        $status = $response->status();
        $body = $response->json();
        $code = is_array($body) ? (int) ($body['code'] ?? 0) : 0;

        if ($status >= 200 && $status < 300) {
            return is_array($body) ? $body : [];
        }
        if ($status === 404 && in_array($code, [self::UNKNOWN_MEMBER, self::UNKNOWN_USER], true) && $notMember === 'null') {
            return null;
        }

        $said = is_array($body) && isset($body['message']) ? ' Discord said: "' . $body['message'] . '".' : '';

        throw new DiscordException(match (true) {
            $status === 401 => 'Discord rejected the bot token (401). Check "Discord bot token" on the Wizarr server in Paymenter.',
            $code === self::UNKNOWN_GUILD => 'Discord does not know that server ID (10004). Check "Discord server ID".',
            $code === self::UNKNOWN_MEMBER => 'That person is not a member of your Discord server (10007).',
            $code === self::UNKNOWN_ROLE => 'Discord does not know that role (10011). Pick the role again on the product.',
            // Seen live: giving a role without "Manage Roles" answers 50001, not 50013.
            $code === self::MISSING_PERMISSIONS || ($status === 403 && str_contains($path, '/roles/') && $method !== 'get') => 'The bot may not give or take that role ('
                . ($code ?: $status) . '). Give the bot\'s own role "Manage Roles", '
                . 'and drag it above the roles it should hand out (Server settings -> Roles).',
            $code === self::MISSING_ACCESS || $status === 403 => 'The bot has no access to the server (' . ($code ?: $status) . '). '
                . 'Invite the bot to your server, and turn on "Server Members Intent" under Bot in the Discord developer portal.',
            $status === 429 => 'Discord is rate limiting the bot (429). Try again in a moment.',
            default => 'Discord answered ' . $status . ($code ? ' (code ' . $code . ')' : '') . '.',
        } . $said . ' [' . strtoupper($method) . ' ' . $path . ']');
    }

    /** The bot's own user ID (GET /users/@me). Proves the token works. */
    public function botUserId(): string
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bot ' . $this->botToken,
                'Accept' => 'application/json',
            ])->timeout($this->timeout)->get(rtrim($this->baseUrl, '/') . '/users/@me');
        } catch (Throwable $e) {
            throw new DiscordException('Could not reach Discord: ' . $e->getMessage());
        }

        if ($response->status() === 401) {
            throw new DiscordException('Discord rejected the bot token (401). Check "Discord bot token" on the Wizarr server in Paymenter.');
        }

        $id = $response->json()['id'] ?? null;
        if ($response->status() !== 200 || !is_string($id) || !ctype_digit($id)) {
            throw new DiscordException('Discord answered ' . $response->status() . ' when asked who the bot is.');
        }

        return $id;
    }

    /**
     * True when the user is in the server, false when Discord says they are
     * not. Anything else throws, so an outage is never read as "not a member".
     */
    public function isMember(string $guildId, string $userId): bool
    {
        if (!ctype_digit($guildId) || !ctype_digit($userId)) {
            throw new DiscordException('The Discord server ID and user ID must be numbers.');
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bot ' . $this->botToken,
                'Accept' => 'application/json',
            ])->timeout($this->timeout)->get(rtrim($this->baseUrl, '/') . '/guilds/' . $guildId . '/members/' . $userId);
        } catch (Throwable $e) {
            throw new DiscordException('Could not reach Discord: ' . $e->getMessage());
        }

        $status = $response->status();
        $body = $response->json();
        $code = is_array($body) ? (int) ($body['code'] ?? 0) : 0;

        if ($status === 200) {
            return true;
        }

        if ($status === 404 && in_array($code, [self::UNKNOWN_MEMBER, self::UNKNOWN_USER], true)) {
            return false;
        }

        throw new DiscordException(match (true) {
            $status === 401 => 'Discord rejected the bot token (401). Check "Discord bot token" on the Wizarr server in Paymenter.',
            $code === self::UNKNOWN_GUILD => 'Discord does not know that server ID (10004). Check "Discord server ID".',
            $code === self::MISSING_ACCESS || $status === 403 => 'The bot has no access to the server (' . ($code ?: $status) . '). '
                . 'Invite the bot to your server, and turn on "Server Members Intent" under Bot in the Discord developer portal.',
            $status === 429 => 'Discord is rate limiting the bot (429). Try again in a moment.',
            default => 'Discord answered ' . $status . ($code ? ' (code ' . $code . ')' : '') . '.',
        });
    }
}
