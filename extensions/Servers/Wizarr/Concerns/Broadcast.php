<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A message from you to customers (Paywarr → Message customers, or one
 * customer from their card): by mail, private Discord message, under the
 * bell in Paymenter, and/or as one post in the announcements channel. To
 * everyone active, or those on one server, product or status. Part of Wizarr
 * (Paywarr).
 */
trait Broadcast
{
    /**
     * Who gets it, one entry per customer: user ID => the first matching
     * subscription. $to: "all", "server:<id>", "product:<id>",
     * "status:active|trial|suspended|cancelled" or "user:<id>".
     */
    public function broadcastRecipients(string $to, ?iterable $services = null): array
    {
        $services ??= Service::whereHas('product.server', fn ($query) => $query->where('extension', 'Wizarr'))
            ->with(['product.server', 'product.settings', 'properties', 'user'])->get();
        [$kind, $value] = array_pad(explode(':', $to, 2), 2, '');
        $byUser = [];
        foreach ($services as $service) {
            $settings = ExtensionHelper::settingsToArray($service->product->settings ?? []);
            if ($this->isGift($settings) || $this->isTopUp($settings) || !$service->user) {
                continue;
            }
            $properties = ExtensionHelper::getServiceProperties($service);
            $trial = $this->isTrial($this->trialSettings($settings, $properties)) && empty($properties[self::PROP_TRIAL_CONVERTED]);
            $match = match ($kind) {
                'all' => $service->status === 'active',
                'server' => $service->status === 'active' && (string) ($service->product->server->id ?? '') === $value,
                'product' => $service->status === 'active' && (string) ($service->product->id ?? $service->product_id ?? '') === $value,
                'status' => match ($value) {
                    'trial' => $service->status === 'active' && $trial,
                    'active' => $service->status === 'active' && !$trial,
                    default => $service->status === $value,
                },
                'user' => (string) $service->user_id === $value,
                default => false,
            };
            if ($match) {
                $byUser[$service->user_id] ??= $service;
            }
        }
        // Cancelled: only those with nothing running any more.
        if ($kind === 'status' && $value === 'cancelled') {
            foreach ($services as $service) {
                if (isset($byUser[$service->user_id]) && in_array($service->status, ['active', 'suspended', 'pending'], true)) {
                    unset($byUser[$service->user_id]);
                }
            }
        }

        return $byUser;
    }

    /**
     * Sends it. $channels: any of "mail", "discord", "app", "channel".
     *
     * @return array{recipients: int, mail: int, discord: int, app: int, channel: bool}
     */
    /** Mail goes only to customers on the mail list (news and offers); "not on the list" counts the others. */
    public function broadcast(string $to, string $subject, string $text, ?array $button, array $channels, string $by = '', ?iterable $services = null, bool $marketing = true): array
    {
        $result = ['recipients' => 0, 'mail' => 0, 'discord' => 0, 'app' => 0, 'channel' => false, 'not_on_list' => 0];
        $recipients = $to === 'channel' ? [] : $this->broadcastRecipients($to, $services);
        $result['recipients'] = count($recipients);
        $html = $this->mailLayout($subject, $this->paragraphs($text), $button);
        foreach ($recipients as $userId => $service) {
            $user = $service->user;
            if (in_array('app', $channels, true)) {
                try {
                    \App\Models\Notification::create(['user_id' => $user->id, 'title' => $subject, 'body' => $text, 'url' => $button[1] ?? null]);
                    $result['app']++;
                } catch (Throwable $e) {
                    // The others still go.
                }
            }
            if (in_array('mail', $channels, true)) {
                if ($marketing && !$this->onMailList($user)) {
                    $result['not_on_list']++;
                } elseif ($this->sendMail((string) $user->email, $subject, $marketing ? $this->mailLayout($subject, $this->paragraphs($text), $button, $this->mailListFooter($user)) : $html, (int) $user->id)) {
                    $result['mail']++;
                }
            }
            if (in_array('discord', $channels, true) && $this->forServiceServer($service)->sendDiscordMessage($user, $subject, $text, $button)) {
                $result['discord']++;
            }
            self::logAdmin($user, (int) $service->id, 'Message: ' . $subject, $by);
        }
        if (in_array('channel', $channels, true)) {
            $result['channel'] = $this->announce('**' . $subject . "**\n" . $text . ($button ? "\n" . $button[0] . ': ' . $button[1] : ''));
        }
        $log = self::storeGet('broadcasts', []);
        array_unshift($log, ['at' => time(), 'subject' => $subject, 'to' => $to, 'by' => $by] + $result);
        self::storePut('broadcasts', array_slice($log, 0, 30));
        Log::info('Wizarr: message "' . $subject . '" sent to ' . $result['recipients'] . ' customer(s).');

        return $result;
    }
}
