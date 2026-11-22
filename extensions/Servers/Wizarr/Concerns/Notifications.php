<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Servers\Wizarr\DiscordException;
use Throwable;

/**
 * Telling a customer something: by mail (in a simple, branded layout, kept in
 * Paymenter's email log), under the bell in Paymenter, and as a private
 * Discord message from the bot when they have connected Discord. Part of
 * Wizarr (Paywarr).
 */
trait Notifications
{
    /**
     * Tells the customer of $service. $text is plain text (the mail shows it
     * as paragraphs); $html, when given, is the mail's body instead. $button:
     * [label, url]. Each channel that fails is logged; the others still go.
     *
     * @return string[] the channels used: mail, app, discord
     */
    /**
     * $marketing: news and offers. The mail goes only to customers on the
     * mail list (with a link to leave it); the bell and Discord as always.
     */
    public function notifyCustomer(Service $service, string $title, string $text, ?array $button = null, ?string $html = null, bool $discord = true, bool $marketing = false): array
    {
        $user = $service->user;
        if (!$user) {
            return [];
        }
        $used = [];
        try {
            \App\Models\Notification::create(['user_id' => $user->id, 'title' => $title, 'body' => $text, 'url' => $button[1] ?? null]);
            $used[] = 'app';
        } catch (Throwable $e) {
            Log::warning('Wizarr: notification for user ' . $user->id . ' not created: ' . $e->getMessage());
        }
        if ((!$marketing || $this->onMailList($user))
            && $this->sendMail((string) $user->email, $title, $this->mailLayout($title, $html ?? $this->paragraphs($text), $button, $marketing ? $this->mailListFooter($user) : ''), (int) $user->id)) {
            $used[] = 'mail';
        }
        if ($discord && $this->sendDiscordMessage($user, $title, $text, $button)) {
            $used[] = 'discord';
        }

        return $used;
    }

    /** Sends a mail now (not through the queue), and notes it in Paymenter's email log. */
    public function sendMail(string $to, string $subject, string $html, ?int $userId = null): bool
    {
        if ($to === '' || (function_exists('config') && config('settings.mail_disable'))) {
            return false;
        }
        try {
            if (class_exists(\App\Models\EmailLog::class)) {
                \App\Models\EmailLog::create(['user_id' => $userId, 'subject' => $subject, 'to' => $to, 'body' => $html]);
            }
        } catch (Throwable $e) {
            // The log is only for you; the mail still goes.
        }
        try {
            \Illuminate\Support\Facades\Mail::html($html, fn ($mail) => $mail->to($to)->subject($subject));

            return true;
        } catch (Throwable $e) {
            Log::warning('Wizarr: mail "' . $subject . '" to ' . $to . ' not sent: ' . $e->getMessage());

            return false;
        }
    }

    /** A private Discord message from the bot, for a customer who connected Discord; false when it can't be sent. */
    private function sendDiscordMessage($user, string $title, string $text, ?array $button): bool
    {
        $id = $this->propertyValue($user, self::PROP_DISCORD_ID);
        if (!$id || !$this->discordConfigured()) {
            return false;
        }
        try {
            $this->discord()->sendDirectMessage($id, '**' . $title . "**\n" . $text . ($button ? "\n" . $button[0] . ': ' . $button[1] : ''));

            return true;
        } catch (DiscordException $e) {
            // Closed DMs, or not on the server any more: the mail still reached them.
            Log::info('Wizarr: Discord message to user ' . $user->id . ' not sent: ' . $e->getMessage());

            return false;
        }
    }

    /** The mail around a message: the site's name, a title, the body and one button. */
    public function mailLayout(string $title, string $body, ?array $button = null, string $footer = ''): string
    {
        $site = e((string) ((function_exists('config') ? (config('settings.company_name') ?: config('app.name')) : '') ?: 'Paywarr'));
        $accent = self::mailAccent();
        $logo = '';
        try {
            $file = (string) (config('settings.logo_dark') ?: config('settings.logo') ?: '');
            if ($file !== '') {
                $logo = '<img src="' . e(url(\Illuminate\Support\Facades\Storage::url($file))) . '" alt="' . $site . '" height="32" style="height:32px;width:auto;border:0;display:block">';
            }
        } catch (Throwable $e) {
            // The name then.
        }
        $buttonHtml = $button
            ? '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:26px 0 6px"><tr><td style="border-radius:12px;background:' . $accent . '">'
                . '<a href="' . e($button[1]) . '" style="display:inline-block;padding:13px 24px;color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;border-radius:12px">' . e($button[0]) . '</a></td></tr></table>'
            : '';
        $links = [];
        foreach ([['extensions.wizarr.hub', '/wizarr/me', self::t('My :name', ['name' => html_entity_decode($site)])], ['extensions.wizarr.help', '/wizarr/help', self::t('Help')], ['extensions.wizarr.status-page', '/status', self::t('Status')]] as [$route, $path, $label]) {
            try {
                $href = function_exists('route') ? route($route) : $path;
            } catch (Throwable $e) {
                $href = function_exists('url') ? url($path) : $path;
            }
            $links[] = '<a href="' . e($href) . '" style="color:#8f87a8;text-decoration:underline">' . e($label) . '</a>';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"></head>'
            . '<body style="margin:0;padding:0;background:#efedf5;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f1a2e">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . e($title) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#efedf5"><tr><td align="center" style="padding:28px 14px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px">'
            . '<tr><td style="background:#14101f;border-radius:18px 18px 0 0;padding:20px 26px;border-bottom:3px solid ' . $accent . '">'
            . ($logo !== '' ? $logo : '<span style="font-weight:800;font-size:20px;color:#ffffff">' . $site . '</span>') . '</td></tr>'
            . '<tr><td style="background:#ffffff;border-radius:0 0 18px 18px;padding:28px 26px 30px;line-height:1.6;font-size:15px">'
            . '<h1 style="font-size:22px;line-height:1.3;margin:0 0 14px;color:#14101f">' . e($title) . '</h1>' . $body . $buttonHtml . '</td></tr>'
            . '<tr><td style="padding:18px 8px 0;color:#8f87a8;font-size:12px;line-height:1.6;text-align:center">'
            . ($footer !== '' ? '<div style="margin-bottom:8px">' . $footer . '</div>' : '')
            . implode(' &nbsp;·&nbsp; ', $links) . '<br>' . $site . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /** The colour of buttons and the line under the logo in mails: "Mail colour" on the Wizarr server, else violet. */
    public static function mailAccent(): string
    {
        try {
            foreach (\App\Models\Server::where('extension', 'Wizarr')->with('settings')->get() as $server) {
                $colour = trim((string) (ExtensionHelper::settingsToArray($server->settings)['mail_colour'] ?? ''));
                if (preg_match('/^#[0-9a-fA-F]{6}$/', $colour)) {
                    return $colour;
                }
            }
        } catch (Throwable $e) {
            // The default.
        }

        return '#7c3aed';
    }

    /** Plain text as mail paragraphs. */
    private function paragraphs(string $text): string
    {
        return implode('', array_map(fn ($p) => '<p style="margin:0 0 12px">' . nl2br(e(trim($p))) . '</p>', array_filter(preg_split('/\n\s*\n/', trim($text)))));
    }
}
