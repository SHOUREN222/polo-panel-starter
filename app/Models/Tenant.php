<?php

namespace App\Models;

use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * A 分站 (agent sub-site). Each tenant gets its own SQLite database
 * (HasDatabase) and one or more subdomains (HasDomains). Tenant-specific
 * columns (subdomain branding, 代理管理员, wholesale credit) will be added as
 * the multi-tenant build proceeds — stancl stores arbitrary attributes in the
 * `data` JSON column via VirtualColumn, so extra fields need no schema change
 * until we choose to promote them to real columns.
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;
    use HasDomains;

    /**
     * A stable, never-reused ordinal for the shared node protocol (see
     * App\Support\NodeUserId). Deriving this from `max(existing ordinals)`
     * would let a deleted tenant's ordinal get handed to the next tenant
     * created afterward, colliding their global user ids on the node side.
     * Persisted centrally instead, so it only ever increases.
     */
    public static function nextNodeOrdinal(): int
    {
        return (new Setting)->getConnection()->transaction(function () {
            $row = Setting::where('key', 'next_tenant_node_ordinal')->lockForUpdate()->first();

            $next = $row
                ? (int) $row->value + 1
                // First call ever: seed from whatever's already assigned, so
                // this doesn't hand out an ordinal already in use.
                : (int) (self::all()->max('node_ordinal') ?? 0) + 1;

            Setting::updateOrCreate(['key' => 'next_tenant_node_ordinal'], ['value' => (string) $next]);

            return $next;
        });
    }
}
