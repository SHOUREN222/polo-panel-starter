<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Run something once per site — 主站 first, then inside every 分站.
 *
 * Scheduled commands and other console entry points start in central context,
 * so anything reading a per-tenant table (subscriptions, users, orders) sees
 * only 主站's rows. Without sweeping the tenants, a 分站's customers silently
 * fall outside expiry reminders, quota enforcement and anything else the
 * scheduler drives.
 *
 * 主站 is always processed first and outside any tenant context, so its
 * behaviour is unchanged from before 分站 existed.
 */
class EachSite
{
    /**
     * @param  \Closure(?Tenant):void  $callback  receives null for 主站
     */
    public static function run(\Closure $callback): void
    {
        $callback(null);

        foreach (Tenant::all() as $tenant) {
            // One 分站's failure must not stop the rest — a scheduled sweep
            // that dies halfway leaves later sites unprocessed until the next
            // run, and for quota enforcement that means unmetered usage.
            try {
                $tenant->run(fn () => $callback($tenant));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
