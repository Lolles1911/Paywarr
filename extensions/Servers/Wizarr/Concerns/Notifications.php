<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

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
    public function notifyCustomer(Service $service, string $title, string $text, ?array $button = null, ?string $html = null, bool $discord = true): array
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
        if ($this->sendMail((string) $user->email, $title, $this->mailLayout($title, $html ?? $this->paragraphs($text), $button), (int) $user->id)) {
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
        $buttonHtml = $button
            ? '<p style="margin:28px 0"><a href="' . e($button[1]) . '" style="display:inline-block;background:#7c3aed;color:#ffffff;text-decoration:none;font-weight:600;padding:12px 22px;border-radius:10px">' . e($button[0]) . '</a></p>'
            : '';

        return '<!doctype html><html><body style="margin:0;background:#f4f2f8;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f1a2e">'
            . '<div style="max-width:560px;margin:0 auto;padding:32px 20px">'
            . '<div style="font-weight:800;font-size:18px;color:#7c3aed;margin-bottom:18px">' . $site . '</div>'
            . '<div style="background:#ffffff;border-radius:16px;padding:28px 26px;line-height:1.55;font-size:15px">'
            . '<h1 style="font-size:21px;margin:0 0 14px">' . e($title) . '</h1>' . $body . $buttonHtml . '</div>'
            . ($footer !== '' ? '<div style="color:#6b6680;font-size:12px;margin-top:16px;line-height:1.5">' . $footer . '</div>' : '')
            . '</div></body></html>';
    }

    /** Plain text as mail paragraphs. */
    private function paragraphs(string $text): string
    {
        return implode('', array_map(fn ($p) => '<p style="margin:0 0 12px">' . nl2br(e(trim($p))) . '</p>', array_filter(preg_split('/\n\s*\n/', trim($text)))));
    }
}
