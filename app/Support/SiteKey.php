<?php

namespace App\Support;

/**
 * Scope a rate-limit key to the site it belongs to.
 *
 * Unlike the Cache facade, RateLimiter resolves its store once — before
 * tenancy is initialized — so it never picks up the per-tenant cache prefix and
 * every site shares one set of counters. Keys built from an email or a user id
 * then collide across sites where they refer to different people entirely:
 * failed logins against 主站's me@qq.com would lock out an unrelated 分站
 * account of the same address, and user ids collide by definition since each
 * 分站 numbers its own from 1.
 *
 * 主站 keys are returned untouched, so existing counters keep working and the
 * main site's behaviour is unchanged.
 */
class SiteKey
{
    public static function scope(string $key): string
    {
        return ($tenant = tenant())
            ? 'site:'.$tenant->getTenantKey().':'.$key
            : $key;
    }
}
