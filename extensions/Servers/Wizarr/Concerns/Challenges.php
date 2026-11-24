<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Challenges (Paywarr → Challenges), e.g. "Watch 10 movies in December":
 * progress from Tracearr and Silo, shown to the customer (/wizarr/challenge
 * and the dashboard) and as a leaderboard in Discord. At the end everyone who
 * made it gets the prize: a free month, a Discord role and/or credit. Part of
 * Wizarr (Paywarr).
 */
trait Challenges
{
    public const CHALLENGE_METRICS = ['movies' => 'Movies watched', 'episodes' => 'Episodes watched', 'hours' => 'Hours watched'];

    public static function challenges(): array
    {
        return self::storeGet('challenges', []);
    }

    private static function putChallenges(array $all): void
    {
        self::storePut('challenges', $all);
    }

    public static function challengeStatus(array $c, ?int $now = null): string
    {
        $now ??= time();

        return match (true) {
            !empty($c['awarded']) => 'done',
            $now >= $c['ends'] => 'ending',
            $now < $c['starts'] => 'planned',
            default => 'running',
        };
    }

    public function createChallenge(array $data): array
    {
        $c = ['id' => bin2hex(random_bytes(4)), 'title' => trim((string) $data['title']), 'metric' => (string) $data['metric'], 'goal' => max(1, (int) $data['goal']),
            'starts' => (int) $data['starts'], 'ends' => (int) $data['ends'], 'month' => !empty($data['month']), 'role' => trim((string) ($data['role'] ?? '')),
            'credit' => max(0, (float) ($data['credit'] ?? 0)), 'announce' => !empty($data['announce']), 'announced' => null, 'awarded' => null,
            'progress' => [], 'progress_at' => null, 'winners' => []];
        if (!isset(self::CHALLENGE_METRICS[$c['metric']]) || $c['ends'] <= $c['starts']) {
            throw new \App\Exceptions\DisplayException('Choose what counts, and an end after the start.');
        }
        $all = self::challenges();
        $all[$c['id']] = $c;
        self::putChallenges($all);

        return $c;
    }

    public function deleteChallenge(string $id): void
    {
        $all = self::challenges();
        unset($all[$id]);
        self::putChallenges($all);
    }

    /**
     * Progress per user from plays: each play is [account ("type|username" or
     * "silo|<id>"), kind (movie, episode), key (the title, for counting each
     * once), ms, at]. A play counts after 2 minutes.
     *
     * @return array<int, float> user ID => movies, episodes or hours
     */
    public static function challengeProgress(array $challenge, array $plays, array $userByAccount): array
    {
        $seen = [];
        $progress = [];
        foreach ($plays as [$account, $kind, $key, $ms, $at]) {
            $user = $userByAccount[$account] ?? null;
            if ($user === null || $at < $challenge['starts'] || $at >= $challenge['ends'] || $ms < 120000) {
                continue;
            }
            if ($challenge['metric'] === 'hours') {
                $progress[$user] = ($progress[$user] ?? 0) + $ms / 3600000;
            } elseif (($challenge['metric'] === 'movies' && $kind === 'movie') || ($challenge['metric'] === 'episodes' && $kind === 'episode')) {
                if (!isset($seen[$user][$key])) {
                    $seen[$user][$key] = true;
                    $progress[$user] = ($progress[$user] ?? 0) + 1;
                }
            }
        }
        $progress = array_map(fn ($v) => round($v, 1), $progress);
        arsort($progress);

        return $progress;
    }

    /** Every account of every active customer: account => user ID, and user ID => [service, ...]. */
    private function challengeAccounts(): array
    {
        $byAccount = [];
        $silo = [];
        foreach (Service::whereIn('status', ['active', 'suspended'])->whereHas('product.server', fn ($q) => $q->where('extension', 'Wizarr'))
            ->with(['product.server', 'properties'])->get() as $service) {
            $properties = ExtensionHelper::getServiceProperties($service);
            foreach ($this->streamAccounts($properties) as $account) {
                $byAccount[$account] = (int) $service->user_id;
            }
            foreach ($properties as $key => $value) {
                if ($key === self::PROP_USERS || str_starts_with((string) $key, self::PROP_USERS . ':')) {
                    foreach ($this->parseUsers((string) $value) as $user) {
                        if (strtolower($user['type']) === 'silo') {
                            $byAccount['silo|' . $user['id']] = (int) $service->user_id;
                            $silo[] = [$service, (int) $user['id']];
                        }
                    }
                }
            }
        }

        return [$byAccount, $silo];
    }

    /** The plays since $since, from Tracearr and Silo, as challengeProgress() takes them. */
    private function challengePlays(int $since, array $silo): array
    {
        $plays = [];
        if (($tracearr = $this->tracearrOrAny()) !== null) {
            foreach ($tracearr->history($since, 20000) as $p) {
                $type = (string) ($p['media_type'] ?? '');
                $plays[] = [strtolower((string) ($p['server_type'] ?? '')) . '|' . strtolower((string) ($p['user']['username'] ?? '')), $type,
                    $type === 'episode' ? ($p['show_title'] ?? '') . '|' . ($p['media_title'] ?? '') : (string) ($p['media_title'] ?? ''),
                    !empty($p['watched']) ? max(120000, (int) ($p['duration_ms'] ?? 0)) : (int) ($p['duration_ms'] ?? 0), strtotime((string) ($p['started_at'] ?? '')) ?: 0];
            }
        }
        foreach ($silo as [$service, $id]) {
            try {
                foreach ($this->forServiceServer($service)->client()->watchHistory($id, 200) as $p) {
                    $started = strtotime((string) ($p['started_at'] ?? '')) ?: 0;
                    if ($started < $since) {
                        continue;
                    }
                    $seconds = !empty($p['measured']) && isset($p['played_seconds']) ? (float) $p['played_seconds'] : max(0, (strtotime((string) ($p['ended_at'] ?? '')) ?: $started) - $started);
                    $type = (string) ($p['media_type'] ?? '');
                    $plays[] = ['silo|' . $id, $type, $type === 'episode' ? ($p['series_title'] ?? '') . '|' . ($p['media_title'] ?? '') : (string) ($p['media_title'] ?? ''),
                        !empty($p['completed']) ? max(120000, (int) ($seconds * 1000)) : (int) ($seconds * 1000), $started];
                }
            } catch (Throwable $e) {
                // That account's plays are missing this time.
            }
        }

        return $plays;
    }

    /**
     * Hourly: the progress of running challenges, the start announced, and
     * at the end the prizes given and the leaderboard posted.
     */
    public function runChallenges(?int $now = null): array
    {
        $now ??= time();
        $all = self::challenges();
        $busy = array_filter($all, fn ($c) => in_array(self::challengeStatus($c, $now), ['running', 'ending'], true));
        if (!$busy) {
            return [];
        }
        $done = [];
        try {
            [$byAccount, $silo] = $this->challengeAccounts();
            $plays = $this->challengePlays(min(array_column($busy, 'starts')), $silo);
        } catch (Throwable $e) {
            Log::warning('Wizarr: challenges not updated: ' . $e->getMessage());

            return [];
        }
        foreach ($busy as $id => $c) {
            $all[$id]['progress'] = self::challengeProgress($c, $plays, $byAccount);
            $all[$id]['progress_at'] = $now;
            if ($c['announce'] && !$c['announced']) {
                $all[$id]['announced'] = $now;
                $this->announce('🏆 **' . $c['title'] . '**' . "\n" . $this->challengeGoalText($c) . "\n" . self::t('Prize: :prize', ['prize' => $this->prizeText($c)]) . "\n" . $this->challengeUrl());
            }
            if (self::challengeStatus($c, $now) === 'ending') {
                $all[$id]['winners'] = $this->awardChallenge($all[$id]);
                $all[$id]['awarded'] = $now;
                $this->announce('🏆 **' . $c['title'] . '**: ' . self::t(':count made it!', ['count' => count($all[$id]['winners'])]) . "\n" . $this->leaderboardText($all[$id]));
                $done[] = $id;
            }
            self::putChallenges($all); // after each, so a prize is never given twice
        }

        return $done;
    }

    /** Gives the prizes to those who reached the goal; returns their user IDs. */
    private function awardChallenge(array $c): array
    {
        $winners = [];
        foreach ($c['progress'] as $userId => $value) {
            if ($value < $c['goal']) {
                continue;
            }
            $service = Service::with(['product.server', 'user'])->where('user_id', $userId)->whereIn('status', ['active', 'suspended'])
                ->whereHas('product.server', fn ($q) => $q->where('extension', 'Wizarr'))->orderBy('id')->first();
            if (!$service || !$service->user) {
                continue;
            }
            $winners[] = (int) $userId;
            $given = [];
            try {
                if ($c['month'] && $service->expires_at) {
                    $service->update(['expires_at' => \Illuminate\Support\Carbon::parse($service->expires_at)->addMonth()]);
                    $given[] = self::t('a free month');
                }
                if ($c['credit'] > 0) {
                    $credit = \App\Models\Credit::firstOrCreate(['user_id' => $userId, 'currency_code' => $service->currency_code], ['amount' => 0]);
                    $credit->increment('amount', $c['credit']);
                    $given[] = number_format($c['credit'], 2) . ' ' . $service->currency_code . ' ' . self::t('in credit');
                }
                if ($c['role'] !== '' && ($discordId = $this->propertyValue($service->user, self::PROP_DISCORD_ID)) !== null) {
                    $ext = $this->anyServer('discord_guild_id');
                    $ext->discord()->addRole((string) $ext->config('discord_guild_id'), $discordId, $c['role']);
                    $given[] = self::t('a role on Discord');
                }
                self::logAdmin($service->user, (int) $service->id, 'Challenge "' . $c['title'] . '" won: ' . implode(', ', $given), 'Paywarr');
                $this->forServiceServer($service)->notifyMail($service, 'challenge_won', ['title' => $c['title'], 'prize' => implode(', ', $given) ?: $this->prizeText($c)], $this->challengeUrl());
            } catch (Throwable $e) {
                Log::warning('Wizarr: challenge prize for user ' . $userId . ' not (all) given: ' . $e->getMessage());
            }
        }

        return $winners;
    }

    public function challengeGoalText(array $c): string
    {
        return match ($c['metric']) {
            'movies' => self::t('Watch :goal movies between :from and :to.', ['goal' => $c['goal'], 'from' => date(self::t('M j'), $c['starts']), 'to' => date(self::t('M j'), $c['ends'] - 1)]),
            'episodes' => self::t('Watch :goal episodes between :from and :to.', ['goal' => $c['goal'], 'from' => date(self::t('M j'), $c['starts']), 'to' => date(self::t('M j'), $c['ends'] - 1)]),
            default => self::t('Watch :goal hours between :from and :to.', ['goal' => $c['goal'], 'from' => date(self::t('M j'), $c['starts']), 'to' => date(self::t('M j'), $c['ends'] - 1)]),
        };
    }

    public function prizeText(array $c): string
    {
        $prizes = [];
        if ($c['month']) {
            $prizes[] = self::t('a free month');
        }
        if ($c['credit'] > 0) {
            $prizes[] = number_format($c['credit'], 2) . ' ' . self::t('in credit');
        }
        if ($c['role'] !== '') {
            $prizes[] = self::t('a role on Discord');
        }

        return implode(', ', $prizes) ?: self::t('the honour');
    }

    /** The roles the bot can give: ID => name ([] without Discord). */
    public function discordRoleOptions(): array
    {
        if (!$this->discordConfigured()) {
            return [];
        }
        $options = [];
        foreach ($this->discord()->assignableRoles((string) $this->config('discord_guild_id')) as $role) {
            $options[$role['id']] = $role['name'];
        }

        return $options;
    }

    /** The top 10, first names only. */
    private function leaderboardText(array $c): string
    {
        $lines = [];
        $users = \App\Models\User::whereIn('id', array_keys(array_slice($c['progress'], 0, 10, true)) ?: [0])->get()->keyBy('id');
        $i = 0;
        foreach (array_slice($c['progress'], 0, 10, true) as $userId => $value) {
            $name = trim((string) ($users[$userId]->first_name ?? '')) ?: self::t('Someone');
            $lines[] = (++$i) . '. ' . $name . ' – ' . $value . ($value >= $c['goal'] ? ' ✅' : '');
        }

        return implode("\n", $lines);
    }

    private function challengeUrl(): string
    {
        try {
            return route('extensions.wizarr.challenge');
        } catch (Throwable $e) {
            return url('/wizarr/challenge');
        }
    }

    /** Challenges a customer sees: running ones, and those ended in the last 14 days. */
    public static function visibleChallenges(?int $now = null): array
    {
        $now ??= time();

        return array_filter(self::challenges(), fn ($c) => $c['starts'] <= $now && $c['ends'] > $now - 14 * 86400);
    }

    /** GET /wizarr/challenge */
    public function challengePage()
    {
        $user = Auth::user();
        $e = fn ($v) => e((string) $v);
        $html = '';
        $list = self::visibleChallenges();
        if (!$list) {
            $html .= '<p style="opacity:.8">' . $e(self::t('No challenge is running right now. We tell you when the next one starts.')) . '</p>';
        }
        foreach ($list as $c) {
            $mine = (float) ($c['progress'][$user->id] ?? 0);
            $pct = min(100, (int) round($mine / max(1, $c['goal']) * 100));
            $html .= '<div class="bg-background-secondary" style="border-radius:1rem;padding:1.2rem 1.4rem;margin-bottom:1rem">'
                . '<div style="font-size:1.3rem;font-weight:800">🏆 ' . $e($c['title']) . '</div>'
                . '<p style="opacity:.85;margin:.2rem 0 .8rem">' . $e($this->challengeGoalText($c)) . ' ' . $e(self::t('Prize: :prize', ['prize' => $this->prizeText($c)])) . '</p>'
                . '<div style="display:flex;justify-content:space-between;font-weight:600"><span>' . $e(self::t('You: :value of :goal', ['value' => $mine, 'goal' => $c['goal']])) . '</span><span>' . $pct . '%</span></div>'
                . '<div style="height:.7rem;border-radius:1rem;background:rgba(127,127,127,.25);margin:.4rem 0 1rem;overflow:hidden"><div style="height:100%;width:' . $pct . '%;background:linear-gradient(90deg,#7c3aed,#db2777)"></div></div>'
                . ($mine >= $c['goal'] ? '<p style="font-weight:700;margin-bottom:.8rem">' . $e(!empty($c['awarded']) ? self::t('You made it, and the prize is yours!') : self::t('You made it! The prize comes when the challenge ends.')) . '</p>' : '')
                . '<div style="font-weight:700;margin-bottom:.3rem">' . $e(self::t('Leaderboard')) . '</div><div style="white-space:pre-line;opacity:.9">' . $e($this->leaderboardText($c) ?: self::t('Nobody yet. Be the first!')) . '</div>'
                . ($c['progress_at'] ? '<div style="opacity:.6;font-size:.85rem;margin-top:.6rem">' . $e(self::t('Updated :time', ['time' => date('H:i', (int) $c['progress_at'])])) . '</div>' : '')
                . '</div>';
        }

        return view('layouts.app', ['slot' => new HtmlString($this->pageFrame(self::t('Challenge'), $html)), 'title' => self::t('Challenge')]);
    }
}
