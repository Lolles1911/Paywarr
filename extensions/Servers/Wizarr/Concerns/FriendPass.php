<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Friend pass: a paying customer gives a friend a week, once a month. The
 * friend gets an invitation to the customer's media server by mail; the week
 * starts when they create their user. A day before the end they are told,
 * and at the end the user is removed, with a link to the shop, unless the
 * friend has bought a subscription by then: then the user moves onto it,
 * history and all. On with "Friend pass" on the Wizarr server. Part of
 * Wizarr (Paywarr).
 */
trait FriendPass
{
    public const PASS_DAYS = 7;

    public const PASS_EVERY_DAYS = 30;

    public static function friendPasses(): array
    {
        return self::storeGet('friend_passes', []);
    }

    private function passOn(): bool
    {
        return $this->truthy($this->anyServer('friend_pass')->config('friend_pass') ?? false);
    }

    /** The subscription a customer gives passes from (active, paid, not a trial, gift card or top-up), or null. */
    public function passService($user): ?Service
    {
        if (!$user) {
            return null;
        }
        foreach (Service::where('user_id', $user->id)->where('status', 'active')->whereHas('product.server', fn ($q) => $q->where('extension', 'Wizarr'))
            ->with(['product.settings', 'product.server', 'properties'])->orderBy('id')->get() as $service) {
            $settings = ExtensionHelper::settingsToArray($service->product->settings ?? []);
            if ((float) $service->price > 0 && !$this->isTrial($this->trialSettings($settings, ExtensionHelper::getServiceProperties($service)))
                && !$this->isGift($settings) && !$this->isTopUp($settings)) {
                return $service;
            }
        }

        return null;
    }

    /** When the customer may give the next pass (null: now). */
    public static function nextPassAt(int $userId, ?int $now = null, ?array $passes = null): ?int
    {
        $now ??= time();
        $last = max([0, ...array_column(array_filter($passes ?? self::friendPasses(), fn ($p) => $p['by'] === $userId), 'created')]);
        $next = $last + self::PASS_EVERY_DAYS * 86400;

        return $last > 0 && $next > $now ? $next : null;
    }

    /** Gives a pass; the friend gets the invitation by mail. */
    public function givePass($user, string $email, string $name, ?int $now = null): array
    {
        $now ??= time();
        $email = strtolower(trim($email));
        $name = trim($name);
        if (!$this->passOn() || !($service = $this->passService($user))) {
            throw new DisplayException(self::t('Friend passes are for paying customers.'));
        }
        if (($next = self::nextPassAt((int) $user->id, $now)) !== null) {
            throw new DisplayException(self::t('You can give the next pass on :date.', ['date' => date(self::t('M j, Y'), $next)]));
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $email === strtolower((string) $user->email)) {
            throw new DisplayException(self::t('Write your friend\'s mail address.'));
        }
        if (array_filter(self::friendPasses(), fn ($p) => $p['email'] === $email)) {
            throw new DisplayException(self::t('Your friend has had a pass already.'));
        }
        $friend = User::where('email', $email)->first();
        if ($friend && $this->hasSubscription($friend)) {
            throw new DisplayException(self::t('Your friend is a customer already.'));
        }
        $ext = $this->forServiceServer($service);
        $settings = ExtensionHelper::settingsToArray($service->product->settings);
        $grant = $ext->isSilo() ? '' : (string) ($ext->chosenServerIds($settings, ExtensionHelper::getServiceProperties($service))[0] ?? '');
        $invitation = $ext->client()->createInvitation(array_filter([
            'server_ids' => $grant !== '' ? [(int) $grant] : null, 'duration' => 'unlimited', 'unlimited' => false, 'expires_in_days' => self::PASS_DAYS,
        ], fn ($v) => $v !== null));
        $url = $ext->absoluteUrl($invitation['url'] ?? null, (string) $invitation['code']);
        $pass = ['id' => bin2hex(random_bytes(4)), 'by' => (int) $user->id, 'service' => (int) $service->id, 'email' => $email, 'name' => $name, 'grant' => $grant,
            'invitation' => (string) ($invitation['id'] ?? ''), 'code' => (string) $invitation['code'], 'created' => $now, 'claimed' => null, 'user' => null,
            'ends' => null, 'warned' => null, 'done' => null, 'outcome' => null];
        $all = self::friendPasses();
        $all[$pass['id']] = $pass;
        self::storePut('friend_passes', $all);

        $giver = trim((string) $user->first_name) ?: (string) $user->email;
        $title = self::t(':name gave you a week of movies and shows', ['name' => $giver]);
        $body = self::t('Hi :friend! :name gave you a free week on our media server. Create your user with the button; the week starts then. The link works for :days days.', [
            'friend' => $name !== '' ? $name : self::t('there'), 'name' => $giver, 'days' => self::PASS_DAYS]);
        $this->sendMail($email, $title, $this->mailLayout($title, $this->paragraphs($body), [self::t('Create your user'), $url]));
        self::logAdmin($user, (int) $service->id, 'Friend pass given to ' . $email, 'customer');

        return $pass;
    }

    /** Hourly: a used pass starts its week; a day before the end the friend is told; at the end the user goes, unless they bought. */
    public function runFriendPasses(?int $now = null): array
    {
        $now ??= time();
        $all = self::friendPasses();
        $done = [];
        foreach ($all as $id => $pass) {
            if ($pass['done']) {
                continue;
            }
            try {
                $service = Service::with(['product.settings', 'product.server', 'properties', 'user'])->find($pass['service']);
                $ext = $service ? $this->forServiceServer($service) : $this;
                if (!$pass['claimed']) {
                    $invitation = null;
                    foreach ($ext->client()->invitations() as $row) {
                        if ((string) ($row['code'] ?? '') === $pass['code']) {
                            $invitation = $row;
                        }
                    }
                    $users = $invitation ? $ext->resolveUsers([self::PROP_INVITATION_ID => $pass['invitation'], self::PROP_INVITE_CODE => $pass['code']], $invitation) : [];
                    if ($users) {
                        $all[$id] = ['claimed' => $now, 'ends' => $now + self::PASS_DAYS * 86400, 'user' => (int) $users[0]['id']] + $pass;
                        $done[] = 'started ' . $pass['email'];
                    } elseif ($now - $pass['created'] > (self::PASS_DAYS + 1) * 86400) {
                        if ($invitation && ctype_digit((string) ($invitation['id'] ?? ''))) {
                            $ext->deleteInvitationQuietly((int) $invitation['id']);
                        }
                        $all[$id] = ['done' => $now, 'outcome' => 'unused'] + $pass;
                    }
                    continue;
                }
                if (!$pass['warned'] && $pass['ends'] - $now <= 86400 && $pass['ends'] > $now) {
                    $all[$id]['warned'] = $now;
                    $title = self::t('Your free week ends tomorrow');
                    $this->sendMail($pass['email'], $title, $this->mailLayout($title, $this->paragraphs($this->passOfferText()), [self::t('See subscriptions'), $this->shopUrl()]));
                }
                if ($now >= $pass['ends']) {
                    $friend = User::where('email', $pass['email'])->first();
                    $bought = $friend ? Service::where('user_id', $friend->id)->where('status', 'active')->whereHas('product.server', fn ($q) => $q->where('extension', 'Wizarr'))
                        ->with(['product.settings', 'product.server', 'properties', 'user'])->orderByDesc('id')->first() : null;
                    if ($bought) {
                        try {
                            $this->forServiceServer($bought)->adminLinkUser($bought, (int) $pass['user'], \Paymenter\Extensions\Servers\Wizarr\Admin\PaywarrInventory::services());
                        } catch (Throwable $e) {
                            Log::info('Wizarr: friend pass user not moved onto the new subscription (it keeps its own): ' . $e->getMessage());
                        }
                        $all[$id] = ['done' => $now, 'outcome' => 'bought'] + $all[$id];
                    } else {
                        $ext->adminUserAction((int) $pass['user'], 'delete');
                        $title = self::t('Your free week is over');
                        $this->sendMail($pass['email'], $title, $this->mailLayout($title, $this->paragraphs($this->passOfferText()), [self::t('See subscriptions'), $this->shopUrl()]));
                        $all[$id] = ['done' => $now, 'outcome' => 'ended'] + $all[$id];
                    }
                    $done[] = $all[$id]['outcome'] . ' ' . $pass['email'];
                }
            } catch (Throwable $e) {
                Log::warning('Wizarr: friend pass ' . $id . ' not handled: ' . $e->getMessage());
            }
        }
        // Kept a year, for "once per friend" and the monthly limit.
        self::storePut('friend_passes', array_filter($all, fn ($p) => $p['created'] > $now - 365 * 86400));

        return $done;
    }

    private function passOfferText(): string
    {
        $text = self::t('We hope you enjoyed it! Keep your user and everything you have watched: buy a subscription with this mail address before the week ends.');
        if (($campaign = self::activeCampaign()) && $campaign['code'] !== '') {
            $text .= "\n\n" . self::t(':percent% off a new subscription with the code :code, until :date.', ['percent' => $campaign['percent'], 'code' => $campaign['code'], 'date' => date(self::t('M j, Y'), $campaign['ends'])]);
        }

        return $text;
    }

    private function passUrl(): string
    {
        try {
            return route('extensions.wizarr.pass');
        } catch (Throwable $e) {
            return url('/wizarr/friend-pass');
        }
    }

    /** GET and POST /wizarr/friend-pass */
    public function passPage()
    {
        $user = Auth::user();
        $notice = '';
        $error = '';
        if (request()->isMethod('post')) {
            try {
                $pass = $this->givePass($user, (string) request()->input('email'), (string) request()->input('name'));
                $notice = self::t('Sent! :email has the invitation now.', ['email' => $pass['email']]);
            } catch (DisplayException $e) {
                $error = $e->getMessage();
            } catch (Throwable $e) {
                report($e);
                $error = self::t('The pass could not be made right now. Try again later.');
            }
        }
        $e = fn ($v) => e((string) $v);
        $html = '<p style="margin-bottom:1rem">' . $e(self::t('Give a friend a free week on our media server, once a month. They get an invitation by mail; the week starts when they create their user.')) . '</p>';
        if (!$this->passOn() || !$this->passService($user)) {
            $html .= '<p style="opacity:.8">' . $e(self::t('Friend passes are for paying customers.')) . '</p>';
        } elseif (($next = self::nextPassAt((int) $user->id)) !== null) {
            $html .= '<p class="bg-background-secondary" style="padding:.9rem 1.1rem;border-radius:.8rem">' . $e(self::t('You can give the next pass on :date.', ['date' => date(self::t('M j, Y'), $next)])) . '</p>';
        } else {
            $html .= '<form method="post" style="display:grid;gap:.8rem">' . csrf_field()
                . '<label style="display:grid;gap:.3rem"><span style="font-weight:600">' . $e(self::t('Your friend\'s name')) . '</span><input name="name" maxlength="60" class="bg-background-secondary border border-neutral rounded-lg px-3 py-2"></label>'
                . '<label style="display:grid;gap:.3rem"><span style="font-weight:600">' . $e(self::t('Their mail address')) . '</span><input name="email" type="email" required class="bg-background-secondary border border-neutral rounded-lg px-3 py-2"></label>'
                . '<button type="submit" class="bg-primary text-white font-semibold rounded-lg px-4 py-2" style="justify-self:start">' . $e(self::t('Give a week')) . '</button></form>';
        }
        $mine = array_filter(self::friendPasses(), fn ($p) => $p['by'] === (int) $user->id);
        if ($mine) {
            $html .= '<h2 style="font-size:1.2rem;font-weight:700;margin:1.4rem 0 .6rem">' . $e(self::t('Your passes')) . '</h2>';
            foreach (array_reverse($mine) as $p) {
                $state = match (true) {
                    $p['outcome'] === 'bought' => self::t('Became a customer 🎉'),
                    $p['outcome'] === 'ended' => self::t('The week is over'),
                    $p['outcome'] === 'unused' => self::t('Not used'),
                    (bool) $p['claimed'] => self::t('Watching until :date', ['date' => date(self::t('M j'), $p['ends'])]),
                    default => self::t('Invitation sent'),
                };
                $html .= '<div class="bg-background-secondary" style="display:flex;justify-content:space-between;gap:1rem;padding:.7rem 1rem;border-radius:.7rem;margin-bottom:.4rem">'
                    . '<span>' . $e($p['name'] !== '' ? $p['name'] . ' (' . $p['email'] . ')' : $p['email']) . '</span><span style="opacity:.8">' . $e($state) . '</span></div>';
            }
        }

        return view('layouts.app', ['slot' => new HtmlString($this->pageFrame(self::t('Friend pass'), $html, $notice, $error)), 'title' => self::t('Friend pass')]);
    }
}
