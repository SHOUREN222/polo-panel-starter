<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 系统设置 → 安全设置 → 安全模式: once enabled, only requests whose Host
 * header matches the configured 站点URL are served — everything else
 * (direct-IP access, a stale/incorrect DNS record pointing here, a scraper
 * hitting the origin behind a CDN) gets a flat 403. A no-op when site_url
 * isn't configured, since that would otherwise 403 every request including
 * the admin's own — this is a deliberate footgun guard, not a real bypass.
 */
class EnsureSafeModeDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $enabled = Setting::getBool('safe_mode_enabled', false);
        } catch (\Throwable) {
            $enabled = false;
        }

        if (! $enabled) {
            return $next($request);
        }

        $siteUrl = Setting::get('site_url', '');
        $allowedHost = $siteUrl ? parse_url($siteUrl, PHP_URL_HOST) : null;

        if ($allowedHost === null || $request->getHost() === $allowedHost) {
            return $next($request);
        }

        abort(403);
    }
}
