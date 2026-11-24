<?php

namespace Paymenter\Extensions\Servers\Wizarr;

use Closure;
use Illuminate\Http\Request;

/**
 * Checks the signature of a link in a mail (unsubscribe, the survey, why
 * they cancelled). Laravel's own "signed" signs the whole address, so a
 * reverse proxy that turns https into http, another host name, or a mail
 * program that adds tracking (?utm_source=...) makes a good link fail with
 * 403. This accepts the path-only signature Paywarr uses now, and the full
 * one of links mailed before, both without tracking parameters. A link that
 * still fails gets a short page instead of an error.
 */
class SignedLink
{
    /** Added by mail programs and newsletters; never part of Paywarr's links. */
    public const IGNORE = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'mc_cid', 'mc_eid', '_hsenc', '_hsmi'];

    public function handle(Request $request, Closure $next)
    {
        if ($request->hasValidSignatureWhileIgnoring(self::IGNORE, false) || $request->hasValidSignatureWhileIgnoring(self::IGNORE, true) || self::validForSiteAddress($request)) {
            return $next($request);
        }

        return response(Wizarr::badLinkPage(), 403);
    }

    /**
     * Links mailed before 1.37.2 were signed with the site's address (APP_URL,
     * as the scheduler sees it). Behind a proxy the request can arrive as
     * http or under another name, so check them against APP_URL itself, with
     * http and https.
     */
    public static function validForSiteAddress(Request $request): bool
    {
        $base = rtrim((string) config('app.url'), '/');
        $signature = $request->query('signature');
        if ($base === '' || !is_string($signature)) {
            return false;
        }
        $query = implode('&', array_filter(explode('&', (string) $request->server->get('QUERY_STRING')),
            fn ($p) => !in_array(explode('=', $p, 2)[0], ['signature', ...self::IGNORE], true)));
        $path = '/' . ltrim($request->path(), '/');
        $keys = array_filter([config('app.key'), ...(array) config('app.previous_keys', [])]);
        foreach (array_unique([$base, preg_replace('~^http://~', 'https://', $base), preg_replace('~^https://~', 'http://', $base)]) as $root) {
            $original = rtrim(rtrim($root . $path, '/') . '?' . $query, '?');
            foreach ($keys as $key) {
                if (hash_equals(hash_hmac('sha256', $original, (string) $key), $signature)) {
                    return true;
                }
            }
        }

        return false;
    }
}
