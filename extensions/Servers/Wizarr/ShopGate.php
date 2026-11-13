<?php

namespace Paymenter\Extensions\Servers\Wizarr;

use Closure;
use Illuminate\Http\Request;

/**
 * Keeps the shop (categories, products, cart) for members of your Discord.
 *
 * Added to the "web" middleware group by Wizarr::boot() when "Only Discord
 * members can see the shop" is on. Every other page - home, login, account,
 * the Discord page itself, admin - is left alone.
 */
class ShopGate
{
    /** The booted extension, with the server's settings. Set by Wizarr::boot(). */
    public static ?Wizarr $extension = null;

    public const GATED_ROUTES = ['category.show', 'products.show', 'products.checkout', 'cart'];

    public function handle(Request $request, Closure $next)
    {
        if (self::$extension === null || !$request->routeIs(...self::GATED_ROUTES)) {
            return $next($request);
        }

        return match (self::$extension->shopAccessForCurrentUser()) {
            'allow' => $next($request),
            'login' => redirect()->guest(route('login')),
            'connect' => $this->toDiscordPage(Wizarr::t('Connect your Discord account to see the subscriptions.')),
            'join' => $this->toDiscordPage(Wizarr::t('The subscriptions are for members of our Discord only. Join the server, then check again.')),
            default => $this->toDiscordPage(Wizarr::t("We can't check your Discord membership right now. Please try again shortly.")),
        };
    }

    private function toDiscordPage(string $message)
    {
        return redirect()->route('extensions.wizarr.discord')->with('notification', ['message' => $message, 'type' => 'error']);
    }
}
