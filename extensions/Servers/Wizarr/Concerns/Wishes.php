<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

/**
 * Votes on requests: a title someone requested and that isn't on the server
 * yet gets "I want it too" for other customers; "Most wanted" on top of
 * Requests and in admin (Paywarr → Most wanted). A request counts as a vote.
 * Part of Wizarr (Paywarr).
 */
trait Wishes
{
    /** ['movie:123' => ['type', 'tmdb', 'title', 'year', 'poster', 'voters' => [user => time], 'at', 'done']]. */
    public static function wishes(): array
    {
        return self::storeGet('wishes', []);
    }

    public static function wishKey(string $type, int $tmdb): string
    {
        return ($type === 'tv' ? 'tv' : 'movie') . ':' . $tmdb;
    }

    /** A vote (or the request itself) for a title; returns its votes. */
    public static function voteWish(int $userId, string $type, int $tmdb, string $title = '', ?int $year = null, string $poster = '', ?int $now = null): int
    {
        if ($tmdb <= 0 || !in_array($type, ['movie', 'tv'], true)) {
            return 0;
        }
        $now ??= time();
        $all = self::wishes();
        $key = self::wishKey($type, $tmdb);
        $wish = $all[$key] ?? ['type' => $type, 'tmdb' => $tmdb, 'title' => '', 'year' => null, 'poster' => '', 'voters' => [], 'at' => $now, 'done' => null];
        $wish['title'] = $wish['title'] ?: mb_substr(trim($title), 0, 120);
        $wish['year'] = $wish['year'] ?: $year;
        $wish['poster'] = $wish['poster'] ?: (preg_match('~^https://~', $poster) ? mb_substr($poster, 0, 300) : '');
        $wish['voters'][(string) $userId] ??= $now;
        $all[$key] = $wish;
        // Done ones go after 30 days, open ones after 180.
        $all = array_filter($all, fn ($w) => $w['done'] ? $w['done'] > $now - 30 * 86400 : max($w['voters'] ?: [$w['at']]) > $now - 180 * 86400);
        self::storePut('wishes', $all);

        return count($wish['voters']);
    }

    /** Titles now on the server: done (no more votes). */
    public static function wishesAvailable(array $keys, ?int $now = null): void
    {
        $all = self::wishes();
        $changed = false;
        foreach ($keys as $key) {
            if (isset($all[$key]) && !$all[$key]['done']) {
                $all[$key]['done'] = $now ?? time();
                $changed = true;
            }
        }
        if ($changed) {
            self::storePut('wishes', $all);
        }
    }

    /** Open wishes, most votes first. */
    public static function mostWanted(int $limit = 10, ?array $all = null): array
    {
        $open = array_filter($all ?? self::wishes(), fn ($w) => !$w['done']);
        uasort($open, fn ($a, $b) => [count($b['voters']), max($b['voters'] ?: [0])] <=> [count($a['voters']), max($a['voters'] ?: [0])]);

        return array_slice($open, 0, $limit, true);
    }
}
