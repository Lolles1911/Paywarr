<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Models\Service;
use Throwable;

/**
 * Paywarr's own welcome mail, instead of Paymenter's "Service activated":
 * the link(s) to create the user, three steps to get going, where to watch
 * (apps per media server), the Discord, and your own words. Also sent, as
 * "Your new link", when an invitation is made again. Part of Wizarr (Paywarr).
 */
trait Welcome
{
    /** Where to get the apps, per media server type. */
    private const APP_PAGES = [
        'jellyfin' => 'https://jellyfin.org/downloads/clients/',
        'emby' => 'https://emby.media/download.html',
        'plex' => 'https://www.plex.tv/apps-devices/',
    ];

    /** Whether this server sends Paywarr's welcome mail (setting "welcome_mail"). */
    public function welcomeMailOn(): bool
    {
        return $this->truthy($this->config('welcome_mail') ?? false) && !self::mailIsOff('welcome');
    }

    /**
     * Sends the welcome mail for the invitations in $data (mailDataFor()).
     * $again: a new link for an existing subscription, not the first one.
     */
    public function sendWelcomeMail(Service $service, array $settings, array $data, bool $again = false): bool
    {
        $user = $service->user;
        $invites = array_values(array_filter($data['invites'] ?? [], fn ($invite) => ($invite['url'] ?? '') !== '' && ($invite['code'] ?? '') !== ''));
        if (!$user || !$invites) {
            return false;
        }
        $product = (string) ($service->product->name ?? '');
        $name = trim((string) ($user->first_name ?? ''));
        $title = $again ? self::t('Your new link for :product', ['product' => $product]) : self::t('Welcome to :product', ['product' => $product]);
        $p = fn (string $text) => '<p style="margin:0 0 12px">' . $text . '</p>';
        $button = fn (string $label, string $url) => '<p style="margin:14px 0"><a href="' . e($url) . '" style="display:inline-block;background:#7c3aed;color:#ffffff;text-decoration:none;font-weight:600;padding:12px 22px;border-radius:10px">' . e($label) . '</a></p>';

        $html = $p(e($name !== '' ? self::t('Hi :name,', ['name' => $name]) : self::t('Hi,')));
        $html .= $p(e($again ? self::t('Here is your new link. The old one no longer works.') : self::t('Your access is ready. Create your user with the link below; it works once.')));
        $own = trim((string) ($this->config('welcome_text') ?? ''));
        if ($own !== '' && !$again) {
            $html .= '<div style="margin:0 0 14px;padding:12px 14px;background:#f4f2f8;border-radius:10px">' . nl2br(e($own)) . '</div>';
        }
        foreach ($invites as $invite) {
            $label = (string) ($invite['label'] ?? $invite['server']);
            $html .= $button($label !== '' ? self::t('Create your user on :server', ['server' => $label]) : self::t('Create your user'), $invite['url']);
        }
        if (($data['invite_valid_for'] ?? '') !== '') {
            $html .= $p('<span style="color:#6b6680;font-size:13px">' . e(self::t('The link works for :time and only once.', ['time' => $data['invite_valid_for']])) . '</span>');
        }

        // Three steps.
        $watch = trim((string) ($data['login_url'] ?? ''));
        $account = $this->accountUrl();
        $html .= '<h2 style="font-size:16px;margin:22px 0 8px">' . e(self::t('Getting started')) . '</h2><ol style="margin:0 0 12px;padding-left:20px">'
            . '<li style="margin-bottom:6px">' . e(self::t('Create your user with the button above.')) . '</li>'
            . '<li style="margin-bottom:6px">' . ($watch !== ''
                ? self::t('Watch in the browser at :link, or in the app.', ['link' => '<a href="' . e($watch) . '">' . e(preg_replace('~^https?://~', '', rtrim($watch, '/'))) . '</a>'])
                : e(self::t('Log in in the app or the browser with your new user.'))) . '</li>'
            . '<li>' . self::t('Under your account at :link you find your subscription, Requests and "Report a problem".', ['link' => '<a href="' . e($account) . '">' . e(preg_replace('~^https?://~', '', rtrim($account, '/'))) . '</a>']) . '</li></ol>';

        // Apps, per kind of media server in this subscription.
        $types = [];
        foreach ($invites as $invite) {
            $types[] = $this->serverTypeNamed((string) $invite['server']);
        }
        $apps = '';
        foreach (array_unique(array_filter($types)) as $type) {
            $apps .= '<li style="margin-bottom:6px">' . match ($type) {
                'silo' => e(self::t('Silo: in the browser on your computer, phone or TV.')),
                'plex' => self::t('Plex: accept the invitation with your Plex account, then use the Plex app (:link).', ['link' => '<a href="' . self::APP_PAGES['plex'] . '">plex.tv/apps-devices</a>']),
                default => self::t(':server: the app for TV, phone and computer (:link), or the browser.', ['server' => ucfirst($type), 'link' => '<a href="' . (self::APP_PAGES[$type] ?? self::APP_PAGES['jellyfin']) . '">' . e(preg_replace('~^https://(www\.)?~', '', rtrim(self::APP_PAGES[$type] ?? self::APP_PAGES['jellyfin'], '/'))) . '</a>']),
            } . '</li>';
        }
        if ($apps !== '') {
            $html .= '<h2 style="font-size:16px;margin:22px 0 8px">' . e(self::t('Where to watch')) . '</h2><ul style="margin:0 0 12px;padding-left:20px">' . $apps . '</ul>';
        }

        $discord = trim((string) ($this->config('discord_invite_url') ?? ''));
        if ($discord !== '') {
            $html .= '<h2 style="font-size:16px;margin:22px 0 8px">' . e(self::t('Our Discord')) . '</h2>'
                . $p(e(self::t('News, help and other viewers are on our Discord.'))) . $button(self::t('Join our Discord'), $discord);
        }

        $title = self::mailSubject('welcome', $title);

        return $this->sendMail((string) $user->email, $title, $this->mailLayout($title, $html), (int) $user->id, 'essential', 'welcome');
    }

    /** "jellyfin", "emby", "plex" or "silo" for a media server's name in Wizarr ('' when unknown). */
    private function serverTypeNamed(string $name): string
    {
        if ($this->isSilo()) {
            return 'silo';
        }
        try {
            foreach ($this->serverInfo() as $server) {
                if ($name === '' || $server['name'] === $name) {
                    return $server['type'];
                }
            }
        } catch (Throwable $e) {
            // No apps list then.
        }

        return '';
    }

    /** The customer's account in Paymenter. */
    private function accountUrl(): string
    {
        try {
            return route('services');
        } catch (Throwable $e) {
            return url('/');
        }
    }
}
