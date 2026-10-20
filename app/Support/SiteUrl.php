<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Base URL of the site currently being served.
 *
 * Needed because the obvious options are both wrong inside a 分站:
 * `Setting::get('site_url')` is a central setting pointing at the platform, and
 * `url('/')` falls back to APP_URL whenever there's no request to read a host
 * from — which is exactly the case in queued mail and console commands. Either
 * way an agent's customer would be sent to the platform's front page.
 *
 * A 分站's address is derivable from its own registered subdomain, so nothing
 * has to be configured per agent.
 */
class SiteUrl
{
    public static function current(): string
    {
        if ($tenant = tenant()) {
            $domain = $tenant->domains()->first()?->domain;
            $parent = self::parentDomain();

            if ($domain && $parent) {
                return 'https://'.$domain.'.'.$parent;
            }
        }

        return rtrim((string) (Setting::get('site_url') ?: url('/')), '/');
    }

    /** The registrable domain tenant subdomains hang off. */
    public static function parentDomain(): ?string
    {
        $domain = strtolower(trim((string) config('tenancy.parent_domain', '')));

        return $domain !== '' ? $domain : null;
    }

    public static function tenantHost(string $label): ?string
    {
        $parent = self::parentDomain();

        return $parent ? strtolower($label).'.'.$parent : null;
    }
}
