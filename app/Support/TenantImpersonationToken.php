<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Signs a short-lived cross-domain handoff: 站长 clicks "进入分站后台" on the
 * central admin, lands on the tenant's own subdomain already logged in as
 * that 分站's admin. Laravel's signed-route helpers bind the host into the
 * signature, which breaks across a central -> tenant-subdomain redirect —
 * so this signs just the claims (host-independent) instead.
 */
class TenantImpersonationToken
{
    private const TTL_SECONDS = 120;

    public static function url(string $host, string $tenantId, int $targetUserId, int $superAdminId): string
    {
        $expires = now()->addSeconds(self::TTL_SECONDS)->timestamp;

        $query = http_build_query([
            'tenant' => $tenantId,
            'user' => $targetUserId,
            'super_admin' => $superAdminId,
            'expires' => $expires,
            'signature' => self::sign($tenantId, $targetUserId, $superAdminId, $expires),
        ]);

        return "https://{$host}/__enter-tenant-admin?{$query}";
    }

    /** @return array{tenantId: string, userId: int, superAdminId: int}|null */
    public static function verify(Request $request): ?array
    {
        $tenantId = (string) $request->query('tenant', '');
        $userId = (int) $request->query('user', 0);
        $superAdminId = (int) $request->query('super_admin', 0);
        $expires = (int) $request->query('expires', 0);
        $signature = (string) $request->query('signature', '');

        if ($tenantId === '' || $userId <= 0 || $superAdminId <= 0 || $signature === '') {
            return null;
        }

        if ($expires < now()->timestamp) {
            return null;
        }

        if (! hash_equals(self::sign($tenantId, $userId, $superAdminId, $expires), $signature)) {
            return null;
        }

        return ['tenantId' => $tenantId, 'userId' => $userId, 'superAdminId' => $superAdminId];
    }

    private static function sign(string $tenantId, int $userId, int $superAdminId, int $expires): string
    {
        return hash_hmac('sha256', "{$tenantId}|{$userId}|{$superAdminId}|{$expires}", config('app.key'));
    }
}
