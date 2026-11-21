<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * The "Help" page in the client area: guides per device for the media
 * servers the customer has, frequently asked questions, a search, and "Didn't
 * help?" to Report a problem or a ticket. The texts are Paywarr's own until
 * you edit them under Paywarr → Help page. Part of Wizarr (Paywarr).
 */
trait Help
{
    /** The built-in guides: title, types (jellyfin, emby, plex, silo), text (an empty line between steps). */
    public static function defaultGuides(): array
    {
        return [
            ['title' => self::t('Smart TV (Samsung, LG, Android TV)'), 'types' => ['jellyfin', 'emby'], 'text' => self::t("Open the TV's app store and install the Jellyfin (or Emby) app.\n\nStart it and enter the server address from your welcome mail.\n\nLog in with the user you created.")],
            ['title' => self::t('Apple TV, iPhone and iPad'), 'types' => ['jellyfin', 'emby'], 'text' => self::t("Install Swiftfin or Infuse (Jellyfin) or Emby from the App Store.\n\nAdd the server with the address from your welcome mail.\n\nLog in with your user.")],
            ['title' => self::t('Android phone and tablet'), 'types' => ['jellyfin', 'emby'], 'text' => self::t("Install Jellyfin (or Emby) from Google Play.\n\nEnter the server address and log in.")],
            ['title' => self::t('Computer and browser'), 'types' => ['jellyfin', 'emby', 'silo'], 'text' => self::t("Open the address from your welcome mail in your browser (Chrome, Edge, Safari, Firefox) and log in. Nothing to install.")],
            ['title' => self::t('Plex on any device'), 'types' => ['plex'], 'text' => self::t("Accept the invitation with your Plex account (the link in your welcome mail).\n\nInstall the Plex app on your TV, phone or computer, or use app.plex.tv in the browser.\n\nLog in with the same Plex account: our libraries show up by themselves.")],
            ['title' => self::t('Silo on TV and phone'), 'types' => ['silo'], 'text' => self::t("Open Silo's address in the browser on your TV, phone or computer, and log in.\n\nOn a phone: share or menu, then \"Add to home screen\" to use it like an app.")],
        ];
    }

    public static function defaultFaq(): array
    {
        return [
            ['question' => self::t('I forgot my password'), 'answer' => self::t('Open your subscription and use "Reset password" (Jellyfin, Emby, Silo). Plex uses your own Plex account; reset it at plex.tv.')],
            ['question' => self::t('It buffers or stops'), 'answer' => self::t('Try a lower quality in the player\'s settings, or a wired connection. If it keeps happening with one movie or show, use "Report a problem".')],
            ['question' => self::t('How do I turn on subtitles?'), 'answer' => self::t('Start the movie or episode, open the player\'s menu (the speech bubble or the three dots) and choose subtitles.')],
            ['question' => self::t('How do I ask for a movie or show?'), 'answer' => self::t('Use Requests in the menu (if your subscription has requests): search, and press Request.')],
            ['question' => self::t('Can I pause instead of cancelling?'), 'answer' => self::t('If pausing is offered, "Pause subscription" is on your subscription: your user and history are kept, and the renewal moves.')],
            ['question' => self::t('How do I cancel?'), 'answer' => self::t('Open your subscription and choose Cancel. Your access lasts until the end of the period you paid for.')],
        ];
    }

    public static function helpContent(): array
    {
        $stored = self::storeGet('help', []);

        return ['guides' => $stored['guides'] ?? self::defaultGuides(), 'faq' => $stored['faq'] ?? self::defaultFaq(), 'intro' => (string) ($stored['intro'] ?? '')];
    }

    /** Whether the customer has a Paywarr subscription (active or suspended); for the menu items. Kept 5 minutes. */
    public function hasSubscription($user): bool
    {
        if (!$user) {
            return false;
        }

        return (bool) \Illuminate\Support\Facades\Cache::remember('wizarr:has-subscription:' . $user->id, 300, function () use ($user) {
            foreach (Service::where('user_id', $user->id)->whereHas('product.server', fn ($q) => $q->where('extension', 'Wizarr'))->with('product.server')->get() as $service) {
                if (in_array($service->status, ['active', 'suspended'], true) && ($service->product?->server?->extension ?? null) === 'Wizarr') {
                    return true;
                }
            }

            return false;
        });
    }

    /** The media server types of the customer's subscriptions ([] = show every guide). */
    private function customerTypes($user): array
    {
        if (!$user) {
            return [];
        }
        $types = [];
        foreach (Service::where('user_id', $user->id)->whereHas('product.server', fn ($q) => $q->where('extension', 'Wizarr'))->with(['product.server', 'properties'])->get() as $service) {
            if (!in_array($service->status, ['active', 'suspended'], true)) {
                continue;
            }
            foreach ($this->streamAccounts(ExtensionHelper::getServiceProperties($service)) as $account) {
                $types[] = explode('|', $account)[0];
            }
            if ((ExtensionHelper::settingsToArray($service->product->server->settings ?? [])['backend'] ?? '') === 'silo') {
                $types[] = 'silo';
            }
            try {
                $ext = $this->forServiceServer($service);
                foreach ($ext->grantKeys(ExtensionHelper::getServiceProperties($service)) as $grant) {
                    if ($grant !== '' && ($type = $ext->serverInfo()[(int) $grant]['type'] ?? '') !== '') {
                        $types[] = $type;
                    }
                }
            } catch (Throwable $e) {
                // The users' types are enough.
            }
        }

        return array_values(array_unique($types));
    }

    /** GET /wizarr/help */
    public function helpPage()
    {
        $content = self::helpContent();
        $types = $this->customerTypes(Auth::user());
        Auth::user()?->properties()->updateOrCreate(['key' => 'paywarr_help_seen'], ['name' => 'Help page seen', 'value' => (string) time()]);
        $guides = array_values(array_filter($content['guides'], fn ($g) => !$types || !($g['types'] ?? []) || array_intersect($g['types'], $types)));
        $e = fn ($v) => e((string) $v);
        $para = fn (string $text) => implode('', array_map(fn ($p) => '<p style="margin:.3rem 0">' . nl2br($e(trim($p))) . '</p>', array_filter(preg_split('/\n\s*\n/', trim($text)))));
        $item = fn (string $title, string $body, string $kind) => '<details class="wh-item bg-background-secondary" data-text="' . $e(mb_strtolower($title . ' ' . $body)) . '" style="border-radius:.8rem;padding:.8rem 1rem;margin-bottom:.5rem">'
            . '<summary style="cursor:pointer;font-weight:600">' . $e($title) . '</summary><div style="opacity:.85;margin-top:.4rem">' . $para($body) . '</div></details>';
        $html = ($content['intro'] !== '' ? $para($content['intro']) : '')
            . '<input id="wh-search" type="search" placeholder="' . $e(self::t('Search the help')) . '" class="bg-background-secondary border border-neutral rounded-lg px-3 py-2" style="width:100%;margin:.4rem 0 1.2rem">';
        if ($guides) {
            $html .= '<h2 class="wh-head" style="font-size:1.2rem;font-weight:700;margin:.6rem 0">' . $e(self::t('Getting started on your devices')) . '</h2>';
            foreach ($guides as $guide) {
                $html .= $item((string) $guide['title'], (string) $guide['text'], 'guide');
            }
        }
        if ($content['faq']) {
            $html .= '<h2 class="wh-head" style="font-size:1.2rem;font-weight:700;margin:1.2rem 0 .6rem">' . $e(self::t('Frequently asked questions')) . '</h2>';
            foreach ($content['faq'] as $faq) {
                $html .= $item((string) $faq['question'], (string) $faq['answer'], 'faq');
            }
        }
        $html .= '<p id="wh-none" style="display:none;opacity:.7">' . $e(self::t('Nothing found.')) . '</p>';
        $report = $this->routeOr('extensions.wizarr.report', '/wizarr/report');
        $tickets = $this->routeOr('tickets.create', '/tickets/create');
        $html .= '<div class="bg-background-secondary" style="border-radius:.9rem;padding:1rem 1.2rem;margin-top:1.4rem"><div style="font-weight:700;margin-bottom:.3rem">' . $e(self::t('Didn\'t help?')) . '</div>'
            . '<div style="display:flex;gap:.6rem;flex-wrap:wrap"><a class="bg-primary text-white font-semibold rounded-lg px-4 py-2" href="' . $e($report) . '">' . $e(self::t('Report a problem')) . '</a>'
            . '<a class="border border-neutral rounded-lg px-4 py-2" href="' . $e($tickets) . '">' . $e(self::t('Write to us')) . '</a></div></div>'
            . '<script>(function(){var s=document.getElementById("wh-search");if(!s)return;s.addEventListener("input",function(){var q=s.value.trim().toLowerCase(),n=0;'
            . 'document.querySelectorAll(".wh-item").forEach(function(i){var hit=!q||i.dataset.text.indexOf(q)>=0;i.style.display=hit?"":"none";if(hit)n++;if(q&&hit)i.open=true;});'
            . 'document.querySelectorAll(".wh-head").forEach(function(h){h.style.display=q?"none":"";});document.getElementById("wh-none").style.display=n?"none":"";});})();</script>';

        return view('layouts.app', ['slot' => new HtmlString($this->pageFrame(self::t('Help'), $html)), 'title' => self::t('Help')]);
    }

    private function routeOr(string $name, string $path): string
    {
        try {
            return route($name);
        } catch (Throwable $e) {
            return url($path);
        }
    }
}
