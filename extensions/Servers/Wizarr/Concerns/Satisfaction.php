<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * How happy customers are: after a month as a paying customer, and every
 * half year after, a mail asks "How likely are you to recommend us?" (0-10,
 * one click, then a comment); and a customer who cancels is asked why (one
 * click). Answers on Paywarr → Satisfaction. On with "survey" on the Wizarr
 * server. Part of Wizarr (Paywarr).
 */
trait Satisfaction
{
    public const CANCEL_REASONS = ['price', 'content', 'quality', 'not_using', 'other'];

    public static function cancelReasonLabel(string $reason): string
    {
        return match ($reason) {
            'price' => self::t('Too expensive'),
            'content' => self::t('Missing movies or shows'),
            'quality' => self::t('Buffering or quality'),
            'not_using' => self::t('I don\'t watch enough'),
            default => self::t('Something else'),
        };
    }

    /**
     * Daily: the surveys due. $services and $cancellations are for tests.
     *
     * @return array{nps: int[], why: int[]} users asked, cancellations asked
     */
    public function sendSurveys(?iterable $services = null, ?iterable $cancellations = null, ?int $now = null): array
    {
        if (($ext = $this->anyServer('survey')) !== $this) {
            return $ext->sendSurveys($services, $cancellations, $now);
        }
        $now ??= time();
        $result = ['nps' => [], 'why' => []];
        if (!$this->truthy($this->config('survey') ?? false)) {
            return $result;
        }
        $services ??= Service::where('status', 'active')->whereHas('product.server', fn ($q) => $q->where('extension', 'Wizarr'))
            ->with(['product.settings', 'product.server', 'user'])->get();
        $byUser = [];
        foreach ($services as $service) {
            $settings = ExtensionHelper::settingsToArray($service->product->settings ?? []);
            if ($service->status !== 'active' || !$service->user || $this->isTrial($settings) || $this->isGift($settings) || $this->isTopUp($settings) || (float) ($service->price ?? 0) <= 0) {
                continue;
            }
            $byUser[$service->user_id][] = $service;
        }
        foreach ($byUser as $userId => $list) {
            $user = $list[0]->user;
            $since = min(array_map(fn ($s) => $this->timestampOf($s->created_at ?? null) ?? $now, $list));
            $asked = (int) ($this->propertyValue($user, 'paywarr_nps_asked') ?? 0);
            if ($now - $since < 30 * 86400 || ($asked > 0 && $now - $asked < 182 * 86400)) {
                continue;
            }
            try {
                $this->mailSurvey($user, $now);
                $user->properties()->updateOrCreate(['key' => 'paywarr_nps_asked'], ['name' => 'Asked how happy', 'value' => (string) $now]);
                $result['nps'][] = (int) $userId;
            } catch (Throwable $e) {
                Log::warning('Wizarr: survey to user ' . $userId . ' not sent: ' . $e->getMessage());
            }
        }

        if ($cancellations === null) {
            try {
                $cancellations = \App\Models\ServiceCancellation::with(['service.user', 'service.product.server'])->where('created_at', '>=', date('Y-m-d H:i:s', $now - 7 * 86400))->get();
            } catch (Throwable $e) {
                $cancellations = [];
            }
        }
        $askedWhy = self::storeGet('survey_why_asked', []);
        foreach ($cancellations as $cancellation) {
            $service = $cancellation->service;
            if (!$service || !$service->user || ($service->product?->server?->extension ?? null) !== 'Wizarr' || isset($askedWhy[$cancellation->id])) {
                continue;
            }
            try {
                $this->mailWhy($cancellation);
                $askedWhy[$cancellation->id] = $now;
                $result['why'][] = (int) $cancellation->id;
            } catch (Throwable $e) {
                Log::warning('Wizarr: cancellation ' . $cancellation->id . ' not asked why: ' . $e->getMessage());
            }
        }
        self::storePut('survey_why_asked', array_filter($askedWhy, fn ($at) => $at > $now - 60 * 86400));

        return $result;
    }

    private function mailSurvey($user, int $now): void
    {
        $title = self::t('How are we doing?');
        $cells = '';
        for ($score = 0; $score <= 10; $score++) {
            $url = URL::signedRoute('extensions.wizarr.nps', ['user' => $user->id, 'score' => $score, 'at' => $now]);
            $cells .= '<a href="' . e($url) . '" style="display:inline-block;width:34px;line-height:34px;margin:2px;text-align:center;border-radius:8px;text-decoration:none;font-weight:700;color:#fff;background:'
                . ($score <= 6 ? '#ef4444' : ($score <= 8 ? '#f59e0b' : '#22c55e')) . '">' . $score . '</a>';
        }
        $body = '<p style="margin:0 0 12px">' . e(self::t('How likely are you to recommend us to a friend? Click a number; it takes a second.')) . '</p>'
            . '<div style="margin:12px 0">' . $cells . '</div><div style="display:flex;justify-content:space-between;color:#6b6680;font-size:12px;max-width:430px"><span>' . e(self::t('Not likely')) . '</span><span>' . e(self::t('Very likely')) . '</span></div>';
        $this->sendMail((string) $user->email, $title, $this->mailLayout($title, $body), (int) $user->id);
    }

    private function mailWhy($cancellation): void
    {
        $user = $cancellation->service->user;
        $title = self::t('Sorry to see you go');
        $buttons = '';
        foreach (self::CANCEL_REASONS as $reason) {
            $url = URL::signedRoute('extensions.wizarr.why', ['cancellation' => $cancellation->id, 'reason' => $reason]);
            $buttons .= '<a href="' . e($url) . '" style="display:block;margin:6px 0;padding:10px 14px;border-radius:10px;border:1px solid #d9d4e6;text-decoration:none;color:#1f1a2e">' . e(self::cancelReasonLabel($reason)) . '</a>';
        }
        $body = '<p style="margin:0 0 12px">' . e(self::t('Thank you for watching with us. Would you tell us why you stop? One click:')) . '</p>' . $buttons;
        $this->sendMail((string) $user->email, $title, $this->mailLayout($title, $body), (int) $user->id);
    }

    /** GET and POST /wizarr/nps/{user}/{score} (signed): the score, then a comment. */
    public function npsAnswer($user, $score)
    {
        $user = User::find((int) $user);
        $score = max(0, min(10, (int) $score));
        $at = (int) request()->query('at', 0);
        if (!$user || $at <= 0) {
            abort(404);
        }
        $answers = self::storeGet('nps', []);
        $key = $user->id . '-' . $at;
        $answers[$key] = ['user' => (int) $user->id, 'score' => $score, 'at' => time(), 'comment' => (string) ($answers[$key]['comment'] ?? '')];
        $thanks = '';
        if (request()->isMethod('post')) {
            $answers[$key]['comment'] = mb_substr(trim((string) request()->input('comment')), 0, 1000);
            $thanks = self::t('Thank you! We read every answer.');
        }
        self::storePut('nps', array_slice($answers, -500, null, true));
        $e = fn ($v) => e((string) $v);
        $html = '<p style="margin-bottom:1rem">' . $e(self::t('Thank you, you gave us :score.', ['score' => $score])) . '</p>'
            . ($thanks === '' ? '<form method="post" style="display:grid;gap:.8rem">' . csrf_field()
                . '<label style="display:grid;gap:.3rem"><span style="font-weight:600">' . $e($score >= 9 ? self::t('What do you like most?') : self::t('What could we do better?')) . '</span>'
                . '<textarea name="comment" rows="4" maxlength="1000" class="bg-background-secondary border border-neutral rounded-lg px-3 py-2"></textarea></label>'
                . '<button type="submit" class="bg-primary text-white font-semibold rounded-lg px-4 py-2" style="justify-self:start">' . $e(self::t('Send')) . '</button></form>' : '');

        return view('layouts.app', ['slot' => new HtmlString($this->pageFrame(self::t('How are we doing?'), $html, $thanks)), 'title' => self::t('How are we doing?')]);
    }

    /** GET /wizarr/why/{cancellation}/{reason} (signed). */
    public function whyAnswer($cancellation, $reason)
    {
        $reason = in_array($reason, self::CANCEL_REASONS, true) ? $reason : 'other';
        $answers = self::storeGet('cancel_reasons', []);
        $answers[(int) $cancellation] = ['reason' => $reason, 'at' => time()];
        self::storePut('cancel_reasons', array_slice($answers, -500, null, true));

        return view('layouts.app', ['slot' => new HtmlString($this->pageFrame(self::t('Thank you'), '<p>' . e(self::t('Thank you for telling us. You are always welcome back.')) . '</p>')), 'title' => self::t('Thank you')]);
    }

    /** NPS over the answers given since $since: score (-100..100), promoters, passives, detractors, count. */
    public static function npsScore(array $answers, int $since = 0): array
    {
        $scores = array_column(array_filter($answers, fn ($a) => $a['at'] >= $since), 'score');
        $n = count($scores);
        $promoters = count(array_filter($scores, fn ($s) => $s >= 9));
        $detractors = count(array_filter($scores, fn ($s) => $s <= 6));

        return ['score' => $n ? (int) round(($promoters - $detractors) / $n * 100) : null, 'promoters' => $promoters, 'passives' => $n - $promoters - $detractors, 'detractors' => $detractors, 'count' => $n];
    }
}
